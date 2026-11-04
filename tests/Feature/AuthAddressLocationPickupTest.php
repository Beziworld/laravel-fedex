<?php

declare(strict_types=1);

use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Data\PickupConfirmation;
use Beziworld\Fedex\Data\PickupRequest;
use Beziworld\Fedex\Events\PickupScheduled;
use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Exceptions\AuthenticationException;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\Facades\Fedex;
use Beziworld\Fedex\FedexClient;
use Beziworld\Fedex\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('requests and caches a client credentials token', function (): void {
    $this->fakeFedex();

    expect(Fedex::accessToken())->toBe('token-1');
    Fedex::purge();
    Fedex::accessToken();

    Http::assertSentCount(1);
    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/oauth/token'
        && $request->body() === 'grant_type=client_credentials&client_id=api-key&client_secret=secret-key');
});

it('sends child credentials for compatible providers', function (): void {
    $this->fakeFedex();

    Fedex::using(['client_id' => 'k', 'client_secret' => 's', 'sandbox' => true, 'grant_type' => 'csp_credentials', 'child_key' => 'ck', 'child_secret' => 'cs'])->accessToken();

    Http::assertSent(fn(Request $request): bool => $request->body() === 'grant_type=csp_credentials&client_id=k&client_secret=s&child_Key=ck&child_secret=cs');
});

it('raises the FedEx OAuth error for rejected credentials', function (): void {
    Http::fake([TestCase::API . '/oauth/token' => Http::response(['transactionId' => 't', 'errors' => [['code' => 'NOT.AUTHORIZED.ERROR', 'message' => 'The given client credentials were not valid. Please modify your request and try again.']]], 401)]);

    expect(fn() => Fedex::accessToken())->toThrow(AuthenticationException::class, 'NOT.AUTHORIZED.ERROR');
});

it('repeats a call once with a new token after HTTP 401', function (): void {
    Http::fake([
        TestCase::API . '/oauth/token' => Http::sequence()->push(['access_token' => 'old', 'expires_in' => 3599])->push(['access_token' => 'new', 'expires_in' => 3599]),
        TestCase::API . '/ship/v1/shipments/cancel' => Http::sequence()
            ->push(['errors' => [['code' => 'NOT.AUTHORIZED.ERROR', 'message' => 'Access token expired. Please modify your request and try again.']]], 401)
            ->push(['output' => ['cancelledShipment' => true]]),
    ]);

    expect(Fedex::cancelShipment('794953535000')->cancelled)->toBeTrue();

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/cancel') && $request->hasHeader('Authorization', 'Bearer new'));
});

it('reports rate limiting', function (): void {
    $this->fakeFedex([TestCase::API . '/track/v1/trackingnumbers' => Http::response(['errors' => [['code' => 'RATE.LIMIT.EXCEEDED', 'message' => 'Rate limit threshold exceeded. Retry after 10 seconds']]], 429, ['Retry-After' => '10'])]);

    try {
        Fedex::track('794953535000');
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->isRateLimited())->toBeTrue()->and($exception->retryAfter())->toBe(10);
    }
});

it('validates addresses', function (): void {
    $this->fakeFedex([TestCase::API . '/address/v1/addresses/resolve' => Http::response(['output' => ['resolvedAddresses' => [[
        'streetLinesToken' => ['7372 PARKRIDGE BLVD', 'APT 286'],
        'city' => 'IRVING',
        'stateOrProvinceCode' => 'TX',
        'countryCode' => 'US',
        'postalCode' => '75063-8659',
        'classification' => 'RESIDENTIAL',
        'attributes' => ['Resolved' => 'true', 'DPV' => 'true', 'POBox' => false],
    ]]]])]);

    $address = Fedex::validateAddress(new Party('x', ['7372 Parkridge Blvd', 'Apt 286'], 'Irving', '75063', 'US', '1', stateCode: 'TX'));

    expect($address?->isResolved())->toBeTrue()
        ->and($address?->isResidential())->toBeTrue()
        ->and($address?->streetLines)->toBe(['7372 PARKRIDGE BLVD', 'APT 286'])
        ->and($address?->attributes['POBox'] ?? null)->toBe('false');

    Http::assertSent(fn(Request $request): bool => ($request->data()['addressesToValidate'][0]['address'] ?? null) === [
        'streetLines' => ['7372 Parkridge Blvd', 'Apt 286'], 'city' => 'Irving', 'stateOrProvinceCode' => 'TX', 'postalCode' => '75063', 'countryCode' => 'US',
    ]);
});

it('searches FedEx locations by address and coordinates', function (): void {
    $this->fakeFedex([TestCase::API . '/location/v1/locations' => Http::response(['output' => ['totalResults' => 1, 'locationDetailList' => [[
        'locationId' => 'WAWA',
        'distance' => ['units' => 'KM', 'value' => 1.2],
        'contactAndAddress' => ['contact' => ['companyName' => 'FedEx Station'], 'address' => ['streetLines' => ['Marynarska 15'], 'city' => 'Warszawa', 'postalCode' => '02-674', 'countryCode' => 'PL']],
        'geoPositionalCoordinates' => ['latitude' => 52.18, 'longitude' => 21.0],
        'locationType' => 'FEDEX_EXPRESS_STATION',
        'storeHours' => [['dayofweek' => 'MON', 'operationalHoursType' => 'OPEN_BY_HOURS']],
    ]]]])]);

    $locations = Fedex::locations(new Party('x', 'Prosta 1', 'Warszawa', '00-001', 'PL', '1'), radius: 5, limit: 3);
    Fedex::locationsNear(52.2, 21.0, 'pl', types: ['FEDEX_OFFICE']);

    expect($locations[0]->id)->toBe('WAWA')
        ->and($locations[0]->name)->toBe('FedEx Station')
        ->and($locations[0]->distance)->toBe(1.2)
        ->and($locations[0]->latitude)->toBe(52.18)
        ->and($locations[0]->hours)->toHaveCount(1);

    Http::assertSent(fn(Request $request): bool => ($request->data()['locationsSummaryRequestControlParameters'] ?? null) === ['distance' => ['units' => 'KM', 'value' => 5], 'maxResults' => 3]);
    Http::assertSent(fn(Request $request): bool => ($request->data()['locationSearchCriterion'] ?? null) === 'GEOGRAPHIC_COORDINATES'
        && ($request->data()['location']['longLat'] ?? null) === ['latitude' => '52.2', 'longitude' => '21']
        && ($request->data()['locationTypes'] ?? null) === ['FEDEX_OFFICE']);
});

it('checks pickup availability, schedules and cancels a pickup', function (): void {
    Event::fake([PickupScheduled::class]);
    $this->fakeFedex([
        TestCase::API . '/pickup/v1/pickups/availabilities' => Http::response(['output' => ['options' => [['carrier' => 'FDXE', 'available' => true, 'pickupDate' => '2026-11-05', 'cutOffTime' => '16:00:00', 'readyTimeOptions' => ['09:00:00', '10:00:00']]]]]),
        TestCase::API . '/pickup/v1/pickups/cancel' => Http::response(['output' => ['pickupConfirmationCode' => 'NQAA97', 'cancelConfirmationMessage' => 'Requested pickup has been cancelled Successfully.']]),
        TestCase::API . '/pickup/v1/pickups' => Http::response(['output' => ['pickupConfirmationCode' => 'NQAA97', 'location' => 'NQAA']]),
    ]);

    $options = Fedex::pickupAvailability(date: CarbonImmutable::parse('2026-11-05'));
    $pickup = Fedex::schedulePickup(new PickupRequest(CarbonImmutable::parse('2026-11-05 09:00', 'Europe/Warsaw'), '17:00', packageCount: 2, totalWeight: 3.0, remarks: 'Gate B'));
    Fedex::cancelPickup($pickup);

    expect($options[0]->available)->toBeTrue()
        ->and($options[0]->readyTimes)->toBe(['09:00:00', '10:00:00'])
        ->and($pickup->confirmationCode)->toBe('NQAA97')
        ->and($pickup->location)->toBe('NQAA');

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/pickups')
        && ($request->data()['originDetail']['readyDateTimestamp'] ?? null) === '2026-11-05T09:00:00+01:00'
        && ($request->data()['originDetail']['customerCloseTime'] ?? null) === '17:00:00'
        && ($request->data()['carrierCode'] ?? null) === 'FDXE'
        && ($request->data()['totalWeight'] ?? null) === ['units' => 'KG', 'value' => 3.0]
        && ($request->data()['associatedAccountNumber'] ?? null) === ['value' => '740561073']);
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/cancel') && $request->data() === [
        'associatedAccountNumber' => ['value' => '740561073'],
        'pickupConfirmationCode' => 'NQAA97',
        'scheduledDate' => '2026-11-05',
        'carrierCode' => 'FDXE',
        'location' => 'NQAA',
    ]);
    Event::assertDispatched(PickupScheduled::class, fn(PickupScheduled $event): bool => $event->pickup instanceof PickupConfirmation);
});

it('resolves named connections, runtime credentials and the check command', function (): void {
    config()->set('fedex.connections.us', ['client_id' => 'us-key', 'client_secret' => 'us-secret', 'account_number' => '111111111']);

    expect(Fedex::connection('us')->config()->accountNumber)->toBe('111111111')
        ->and(Fedex::connection('us')->connectionName())->toBe('us')
        ->and(app(FedexClient::class)->config()->sandbox)->toBeTrue()
        ->and(Fedex::connectionNames())->toBe(['default', 'us'])
        ->and(fn() => Fedex::connection('missing'))->toThrow(InvalidConfiguration::class);

    $this->fakeFedex();
    $this->artisan('fedex:check', ['connection' => 'default'])->expectsOutputToContain('token issued')->assertSuccessful();
});
