<?php

declare(strict_types=1);

namespace Beziworld\Fedex;

use Beziworld\Fedex\Data\AccessToken;
use Beziworld\Fedex\Data\CancelResult;
use Beziworld\Fedex\Data\Location;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Data\PickupConfirmation;
use Beziworld\Fedex\Data\PickupOption;
use Beziworld\Fedex\Data\PickupRequest;
use Beziworld\Fedex\Data\Rate;
use Beziworld\Fedex\Data\ResolvedAddress;
use Beziworld\Fedex\Data\ShipmentResult;
use Beziworld\Fedex\Data\TrackedPackage;
use Beziworld\Fedex\Events\PickupScheduled;
use Beziworld\Fedex\Events\ShipmentCancelled;
use Beziworld\Fedex\Events\ShipmentCreated;
use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Exceptions\AuthenticationException;
use Beziworld\Fedex\Shipments\ShipmentBuilder;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * Client of the FedEx REST APIs for one project and account.
 *
 * Access tokens are cached and requested under a cache lock: FedEx
 * penalises a public IP that requests tokens too often with ten minutes of
 * HTTP 403. Read-only calls (rating, tracking, address validation,
 * locations, pickup availability) are retried on connection errors and
 * HTTP 5xx; shipments, cancellations and pickups are sent exactly once. A
 * request rejected with HTTP 401 is repeated once with a new token.
 */
final class FedexClient
{
    private ?AccessToken $token = null;

    public function __construct(
        private readonly FedexConfig $config,
        private readonly HttpFactory $http,
        private readonly Repository $cache,
        private readonly ?Dispatcher $events = null,
        private readonly string $connection = 'default',
    ) {}

    public function config(): FedexConfig
    {
        return $this->config;
    }

    public function connectionName(): string
    {
        return $this->connection;
    }

    /**
     * Starts a shipment builder bound to this account.
     */
    public function shipment(): ShipmentBuilder
    {
        return new ShipmentBuilder($this);
    }

    /**
     * Creates a shipment and returns its tracking numbers, labels and documents.
     *
     * @param ShipmentBuilder|array<string, mixed> $shipment A builder or a complete request body.
     */
    public function createShipment(ShipmentBuilder|array $shipment): ShipmentResult
    {
        $result = ShipmentResult::fromArray($this->json('POST', '/ship/v1/shipments', $shipment instanceof ShipmentBuilder ? $shipment->toArray() : $shipment, write: true));

        $this->events?->dispatch(new ShipmentCreated($this->connection, $result));

        return $result;
    }

    /**
     * Validates a shipment without creating it and returns the alerts.
     *
     * @param ShipmentBuilder|array<string, mixed> $shipment
     * @return list<string>
     */
    public function validateShipment(ShipmentBuilder|array $shipment): array
    {
        $data = $this->json('POST', '/ship/v1/shipments/packages/validate', $shipment instanceof ShipmentBuilder ? $shipment->toArray() : $shipment);

        return ShipmentResult::alerts(Payload::items($data, 'output.alerts'));
    }

    /**
     * Result of an asynchronous shipment (more than 40 packages).
     */
    public function shipmentResults(string $jobId): ShipmentResult
    {
        return ShipmentResult::fromArray($this->json('POST', '/ship/v1/shipments/results', [
            'accountNumber' => ['value' => $this->config->requireAccountNumber()],
            'jobId' => $jobId,
        ]));
    }

    /**
     * Cancels a shipment that has not been handed over yet.
     */
    public function cancelShipment(string $trackingNumber, ?string $senderCountryCode = null): CancelResult
    {
        $result = CancelResult::fromArray($trackingNumber, $this->json('PUT', '/ship/v1/shipments/cancel', Payload::clean([
            'accountNumber' => ['value' => $this->config->requireAccountNumber()],
            'trackingNumber' => $trackingNumber,
            'senderCountryCode' => $senderCountryCode ?? $this->config->shipper?->countryCode,
            'deletionControl' => 'DELETE_ALL_PACKAGES',
        ]), write: true));

        $this->events?->dispatch(new ShipmentCancelled($this->connection, $result));

        return $result;
    }

    /**
     * Rates of the selected service, or of every available service, cheapest first.
     *
     * @param ShipmentBuilder|array<string, mixed> $shipment
     * @return list<Rate>
     */
    public function rates(ShipmentBuilder|array $shipment): array
    {
        $data = $this->json('POST', '/rate/v1/rates/quotes', $shipment instanceof ShipmentBuilder ? $shipment->toRateArray() : $shipment);
        $rates = array_values(array_filter(array_map(Rate::fromArray(...), Payload::items($data, 'output.rateReplyDetails'))));
        usort($rates, static fn(Rate $a, Rate $b): int => (float) $a->total->amount <=> (float) $b->total->amount);

        return $rates;
    }

    /**
     * Tracking state of one package.
     *
     * @throws ApiException When FedEx reports an error for the number (httpStatus() 404 when it is unknown).
     */
    public function track(string $trackingNumber): TrackedPackage
    {
        $package = $this->trackMany([$trackingNumber])[0] ?? throw new ApiException(sprintf('FedEx returned no tracking data for %s.', $trackingNumber), 404);

        if ($package->error !== null) {
            throw ApiException::trackingError($trackingNumber, $package->error);
        }

        return $package;
    }

    /**
     * Tracking state of up to 30 packages; failures for single numbers are reported in TrackedPackage::$error.
     *
     * @param list<string> $trackingNumbers
     * @return list<TrackedPackage>
     */
    public function trackMany(array $trackingNumbers): array
    {
        return $this->trackedPackages($this->json('POST', '/track/v1/trackingnumbers', [
            'includeDetailedScans' => true,
            'trackingInfo' => array_map(static fn(string $number): array => ['trackingNumberInfo' => ['trackingNumber' => $number]], array_slice($trackingNumbers, 0, 30)),
        ]));
    }

    /**
     * Packages shipped with a reference (customer reference, invoice, PO, ...) within a ship date range.
     *
     * @return list<TrackedPackage>
     */
    public function trackByReference(string $reference, DateTimeInterface $from, DateTimeInterface $to, string $type = 'CUSTOMER_REFERENCE', ?string $destinationCountry = null, ?string $destinationPostalCode = null): array
    {
        return $this->trackedPackages($this->json('POST', '/track/v1/referencenumbers', [
            'includeDetailedScans' => true,
            'referencesInformation' => Payload::clean([
                'value' => $reference,
                'type' => $type,
                'accountNumber' => $destinationCountry === null ? $this->config->requireAccountNumber() : $this->config->accountNumber,
                'shipDateBegin' => CarbonImmutable::instance($from)->format('Y-m-d'),
                'shipDateEnd' => CarbonImmutable::instance($to)->format('Y-m-d'),
                'destinationCountryCode' => $destinationCountry !== null ? strtoupper($destinationCountry) : null,
                'destinationPostalCode' => $destinationPostalCode,
            ]),
        ]));
    }

    /**
     * Signature proof of delivery as a decoded PDF (or PNG) document.
     *
     * @param 'PDF'|'PNG' $format
     */
    public function proofOfDelivery(string $trackingNumber, string $format = 'PDF'): string
    {
        $data = $this->json('POST', '/track/v1/trackingdocuments', [
            'trackDocumentDetail' => ['documentType' => 'SIGNATURE_PROOF_OF_DELIVERY', 'documentFormat' => $format],
            'trackDocumentSpecification' => [['trackingNumberInfo' => ['trackingNumber' => $trackingNumber]]],
        ]);
        $documents = Payload::array($data, 'output.document');
        $encoded = is_string($documents[0] ?? null) ? $documents[0] : null;

        return ($encoded !== null ? Payload::decode($encoded) : null) ?? throw ApiException::malformed('no proof of delivery document returned');
    }

    /**
     * Subscribes e-mail recipients to tracking notifications of a package.
     *
     * @param list<string> $emails
     * @param list<string> $events ON_DELIVERY, ON_ESTIMATED_DELIVERY, ON_EXCEPTION, ON_SHIPMENT, ON_TENDER.
     */
    public function notifyByEmail(string $trackingNumber, array $emails, string $senderName, string $senderEmail, array $events = ['ON_DELIVERY', 'ON_EXCEPTION', 'ON_ESTIMATED_DELIVERY'], string $language = 'en'): void
    {
        $this->send('POST', '/track/v1/notifications', [
            'trackingNumberInfo' => ['trackingNumber' => $trackingNumber],
            'senderContactName' => $senderName,
            'senderEMailAddress' => $senderEmail,
            'trackingEventNotificationDetail' => ['trackingNotifications' => array_map(static fn(string $email): array => [
                'notificationEventTypes' => $events,
                'notificationDetail' => [
                    'notificationType' => 'HTML',
                    'emailDetail' => ['emailAddress' => $email],
                    'localization' => ['languageCode' => $language],
                ],
            ], $emails)],
        ], write: true);
    }

    /**
     * Validates and standardises addresses (up to 100).
     *
     * @param list<Party> $addresses
     * @return list<ResolvedAddress>
     */
    public function validateAddresses(array $addresses): array
    {
        $data = $this->json('POST', '/address/v1/addresses/resolve', [
            'addressesToValidate' => array_map(static fn(Party $party): array => ['address' => $party->toFedexAddress()], array_slice($addresses, 0, 100)),
        ]);

        return array_map(ResolvedAddress::fromArray(...), Payload::items($data, 'output.resolvedAddresses'));
    }

    public function validateAddress(Party $address): ?ResolvedAddress
    {
        return $this->validateAddresses([$address])[0] ?? null;
    }

    /**
     * FedEx locations near an address.
     *
     * @param list<string> $types e.g. FEDEX_OFFICE, FEDEX_AUTHORIZED_SHIP_CENTER, FEDEX_SELF_SERVICE_LOCATION.
     * @return list<Location>
     */
    public function locations(Party $near, int $radius = 10, int $limit = 10, array $types = []): array
    {
        return $this->searchLocations(['address' => $near->toFedexAddress()], 'ADDRESS', $radius, $limit, $types);
    }

    /**
     * FedEx locations near coordinates.
     *
     * @param list<string> $types
     * @return list<Location>
     */
    public function locationsNear(float $latitude, float $longitude, string $countryCode, int $radius = 10, int $limit = 10, array $types = []): array
    {
        return $this->searchLocations([
            'address' => ['countryCode' => strtoupper($countryCode)],
            'longLat' => ['latitude' => (string) $latitude, 'longitude' => (string) $longitude],
        ], 'GEOGRAPHIC_COORDINATES', $radius, $limit, $types);
    }

    /**
     * Pickup availability for an address.
     *
     * @return list<PickupOption>
     */
    public function pickupAvailability(?Party $address = null, string $carrier = 'FDXE', ?DateTimeInterface $date = null, bool $international = false): array
    {
        $address ??= $this->config->requireShipper();
        $date = CarbonImmutable::instance($date ?? CarbonImmutable::now());

        $data = $this->json('POST', '/pickup/v1/pickups/availabilities', Payload::clean([
            'pickupAddress' => $address->toFedexAddress(),
            'pickupRequestType' => [$date->isToday() ? 'SAME_DAY' : 'FUTURE_DAY'],
            'dispatchDate' => $date->format('Y-m-d'),
            'carriers' => [$carrier],
            'countryRelationship' => $international ? 'INTERNATIONAL' : 'DOMESTIC',
            'associatedAccountNumber' => $this->config->accountNumber,
        ]));

        return array_map(PickupOption::fromArray(...), Payload::items($data, 'output.options'));
    }

    /**
     * Schedules a courier pickup. The request is never retried automatically.
     */
    public function schedulePickup(PickupRequest $pickup): PickupConfirmation
    {
        $address = $pickup->address ?? $this->config->requireShipper();
        $readyAt = CarbonImmutable::instance($pickup->readyAt);
        $close = preg_match('/^(\d{1,2}):?(\d{2})/', $pickup->closeTime, $match) === 1 ? sprintf('%02d:%s:00', (int) $match[1], $match[2]) : $pickup->closeTime;

        $data = $this->json('POST', '/pickup/v1/pickups', Payload::clean([
            'associatedAccountNumber' => ['value' => $this->config->requireAccountNumber()],
            'originDetail' => [
                'pickupLocation' => ['contact' => $address->toFedexContact(), 'address' => $address->toFedexAddress()],
                'readyDateTimestamp' => $readyAt->toIso8601String(),
                'customerCloseTime' => $close,
                'pickupDateType' => $readyAt->isToday() ? 'SAME_DAY' : 'FUTURE_DAY',
                'packageLocation' => $pickup->packageLocation,
            ],
            'carrierCode' => $pickup->carrier,
            'totalWeight' => $pickup->totalWeight !== null ? ['units' => $this->config->weightUnit(), 'value' => round($pickup->totalWeight, 1)] : null,
            'packageCount' => $pickup->packageCount,
            'countryRelationships' => $pickup->international ? 'INTERNATIONAL' : 'DOMESTIC',
            'remarks' => $pickup->remarks,
            'commodityDescription' => $pickup->commodityDescription,
        ]), write: true);

        $confirmation = PickupConfirmation::fromArray($data, $pickup->carrier, $readyAt->startOfDay());

        $this->events?->dispatch(new PickupScheduled($this->connection, $confirmation));

        return $confirmation;
    }

    /**
     * Cancels a scheduled pickup.
     */
    public function cancelPickup(PickupConfirmation $pickup, ?string $remarks = null): void
    {
        $this->send('PUT', '/pickup/v1/pickups/cancel', Payload::clean([
            'associatedAccountNumber' => ['value' => $this->config->requireAccountNumber()],
            'pickupConfirmationCode' => $pickup->confirmationCode,
            'scheduledDate' => $pickup->scheduledDate->format('Y-m-d'),
            'carrierCode' => $pickup->carrier,
            'location' => $pickup->location,
            'remarks' => $remarks,
        ]), write: true);
    }

    /**
     * Sends any request to the FedEx API and returns the decoded body.
     *
     * @param array<string, mixed> $body
     * @param bool $write true for calls with side effects: they are never retried.
     * @return array<array-key, mixed>
     */
    public function request(string $method, string $path, array $body = [], bool $write = true): array
    {
        return $this->json(strtoupper($method), '/' . ltrim($path, '/'), $body, write: $write);
    }

    /**
     * Returns a valid access token, requesting a new one when the cached token is missing or about to expire.
     *
     * @throws AuthenticationException When FedEx rejects the credentials.
     */
    public function accessToken(): string
    {
        $token = $this->token ?? $this->cachedToken();

        if ($token !== null && $token->isValid()) {
            return ($this->token = $token)->accessToken;
        }

        $store = $this->cache->getStore();

        $issue = function (): string {
            // Another process may have obtained a token while this one waited for the lock.
            $cached = $this->cachedToken();

            if ($cached !== null && $cached->isValid()) {
                return ($this->token = $cached)->accessToken;
            }

            $token = $this->requestToken();
            $this->token = $token;
            $this->cache->put($this->cacheKey(), $token->toArray(), max(60, (int) CarbonImmutable::now()->diffInSeconds($token->expiresAt, false)));

            return $token->accessToken;
        };

        return $store instanceof LockProvider ? $store->lock($this->cacheKey() . ':lock', 30)->block(15, $issue) : $issue();
    }

    public function forgetToken(): void
    {
        $this->token = null;
        $this->cache->forget($this->cacheKey());
    }

    private function requestToken(): AccessToken
    {
        $form = ['grant_type' => $this->config->grantType, 'client_id' => $this->config->clientId, 'client_secret' => $this->config->clientSecret];

        if ($this->config->grantType !== FedexConfig::GRANT_CLIENT) {
            $form += ['child_Key' => (string) $this->config->childKey, 'child_secret' => (string) $this->config->childSecret];
        }

        try {
            $response = $this->http->createPendingRequest()
                ->baseUrl($this->config->baseUrl())
                ->timeout($this->config->timeout)
                ->acceptJson()
                ->asForm()
                ->post('/oauth/token', $form);
        } catch (ConnectionException $exception) {
            throw ApiException::transport($exception);
        }

        $data = $response->json();
        $data = is_array($data) ? $data : [];

        if (! $response->successful() || ! is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            if ($response->status() === 429) {
                throw ApiException::fromResponse($data, 429, self::retryAfter($response));
            }

            throw AuthenticationException::rejected($response->status(), ApiException::parseErrors($data));
        }

        return AccessToken::fromArray($data);
    }

    private function cachedToken(): ?AccessToken
    {
        $cached = $this->cache->get($this->cacheKey());

        return is_array($cached) ? AccessToken::fromArray($cached) : null;
    }

    private function cacheKey(): string
    {
        return sprintf('%s:%s:%s:%s', $this->config->cachePrefix, $this->config->sandbox ? 'sandbox' : 'production', $this->config->clientId, $this->config->childKey ?? '-');
    }

    /**
     * @param array<string, mixed> $location
     * @param list<string> $types
     * @return list<Location>
     */
    private function searchLocations(array $location, string $criterion, int $radius, int $limit, array $types): array
    {
        $data = $this->json('POST', '/location/v1/locations', Payload::clean([
            'location' => $location,
            'locationSearchCriterion' => $criterion,
            'locationsSummaryRequestControlParameters' => [
                'distance' => ['units' => $this->config->units === FedexConfig::UNITS_METRIC ? 'KM' : 'MI', 'value' => $radius],
                'maxResults' => max(1, $limit),
            ],
            'locationTypes' => $types,
        ]));

        return array_map(Location::fromArray(...), Payload::items($data, 'output.locationDetailList'));
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<TrackedPackage>
     */
    private function trackedPackages(array $data): array
    {
        $packages = [];

        foreach (Payload::items($data, 'output.completeTrackResults') as $complete) {
            foreach (Payload::items($complete, 'trackResults') as $result) {
                $packages[] = TrackedPackage::fromArray($result, Payload::string($complete, 'trackingNumber') ?? '');
            }
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<array-key, mixed>
     */
    private function json(string $method, string $path, array $body = [], bool $write = false): array
    {
        $data = $this->send($method, $path, $body, $write)->json();

        return is_array($data) ? $data : [];
    }

    /**
     * Executes a request and converts failures into exceptions.
     *
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $path, array $body = [], bool $write = false): Response
    {
        $attempts = $write ? 1 : $this->config->retries + 1;
        $refreshed = false;

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->api()->send($method, $path, $body === [] ? [] : ['json' => $body]);
            } catch (ConnectionException $exception) {
                if ($attempt < $attempts) {
                    Sleep::usleep($this->config->retryDelayMs * 1000);

                    continue;
                }

                throw ApiException::transport($exception);
            }

            if ($response->successful()) {
                return $response;
            }

            if ($response->status() === 401 && ! $refreshed) {
                // A request rejected for authentication was not processed, so repeating it is safe.
                $refreshed = true;
                $this->forgetToken();
                $attempt--;

                continue;
            }

            if ($response->serverError() && $attempt < $attempts) {
                Sleep::usleep($this->config->retryDelayMs * 1000);

                continue;
            }

            $payload = $response->json();

            throw ApiException::fromResponse(is_array($payload) ? $payload : [], $response->status(), self::retryAfter($response));
        }
    }

    private function api(): PendingRequest
    {
        return $this->http->createPendingRequest()
            ->baseUrl($this->config->baseUrl())
            ->timeout($this->config->timeout)
            ->withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'x-locale' => $this->config->locale,
                'x-customer-transaction-id' => (string) Str::uuid(),
            ]);
    }

    private static function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return is_numeric($value) ? (int) $value : null;
    }
}
