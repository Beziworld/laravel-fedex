# Laravel FedEx

[![tests](https://github.com/beziworld/laravel-fedex/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-fedex/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-fedex.svg)](https://packagist.org/packages/beziworld/laravel-fedex)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-fedex.svg)](LICENSE.md)

[FedEx](https://developer.fedex.com) REST API client for Laravel:

- OAuth 2.0 (client, compatible provider and parent-child credentials) with tokens cached under a lock
- typed shipment builder: shipper, recipient, packages with weight and dimensions, references, declared value,
  Saturday delivery, COD, hold at location, signature options, third-party or recipient billing
- international shipments with commodities, duties payer and a FedEx-generated commercial invoice
- labels in PDF, PNG, ZPLII or EPL2 decoded from base64 (or as links), shipment validation, cancel, asynchronous results
- rates of one or every service with account and list prices and delivery dates
- tracking with typed events and normalised statuses (up to 30 numbers per call), tracking by reference,
  signature proof of delivery, e-mail notifications
- shipment visibility webhooks: `fdx-signature` verification, a ready route and a Laravel event
- address validation, location search, pickup availability, scheduling and cancellation
- several FedEx accounts (named connections and `Fedex::using()`), Laravel events, `fedex:check` command

[Polska wersja dokumentacji](README.pl.md)

## Why this package

| Package | API | Laravel 12/13 | Notes |
|---|---|---|---|
| `jeremy-dunn/php-fedex-api-wrapper` | legacy SOAP Web Services | no | abandoned, last release 2022 |
| `whatarmy/fedex-rest`, `shipstream/fedex-rest-sdk` | REST | not Laravel packages | SDKs without Laravel integration |
| `ktscript/fedex-rest`, `smart-dato/fedex-sdk` | REST | yes | young packages |
| `ivanmitrikeski/laravel-shipping` | multi-carrier | yes | requires `ext-soap`, focused on rates |

This package targets the current REST APIs (Ship, Rate, Track, Address Validation, Location Search, Pickup) and adds
a typed builder, decoded labels and documents, tracking webhooks with signature verification, multiple accounts,
token caching that respects the FedEx token throttling, the Laravel HTTP client (`Http::fake()` works in your tests)
and a strict no-retry policy for writes.

## Installation

```bash
composer require beziworld/laravel-fedex
php artisan vendor:publish --tag=fedex-config   # optional
```

```dotenv
FEDEX_CLIENT_ID=...            # API key of the project on developer.fedex.com
FEDEX_CLIENT_SECRET=...        # secret key
FEDEX_ACCOUNT_NUMBER=740561073 # nine-digit FedEx account
FEDEX_SANDBOX=true             # apis-sandbox.fedex.com (predefined responses)

FEDEX_SHIPPER_NAME="Jan Nowak"
FEDEX_SHIPPER_COMPANY="ACME Sp. z o.o."
FEDEX_SHIPPER_PHONE=500600700
FEDEX_SHIPPER_EMAIL=shop@example.com
FEDEX_SHIPPER_ADDRESS="ul. Prosta 1"
FEDEX_SHIPPER_CITY=Warszawa
FEDEX_SHIPPER_POSTAL_CODE=00-001
FEDEX_SHIPPER_COUNTRY=PL
FEDEX_SHIPPER_TAX_ID=PL5250000000   # VAT/EORI, sent as a TIN (BUSINESS_UNION by default)

FEDEX_UNITS=metric             # metric (KG, CM) or imperial (LB, IN)
FEDEX_LABEL_FORMAT=PDF         # PDF, PNG, ZPLII or EPL2
FEDEX_CACHE_STORE=redis        # share tokens between processes
```

Check the credentials (requests a token, no other call): `php artisan fedex:check`.

## Create a shipment

```php
use Beziworld\Fedex\Data\Money;
use Beziworld\Fedex\Data\Parcel;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Enums\LabelFormat;
use Beziworld\Fedex\Enums\Service;
use Beziworld\Fedex\Facades\Fedex;

$shipment = Fedex::shipment()
    ->to(new Party('Max Mustermann', 'Potsdamer Str. 1', 'Berlin', '10785', 'DE', phone: '49301234', email: 'max@example.com'))
    ->service(Service::InternationalPriority)
    ->parcel(new Parcel(weight: 2.5, length: 30, width: 20, height: 10, reference: 'ORDER-1'))
    ->parcel(new Parcel(weight: 1.2, declaredValue: Money::of(200, 'EUR')))
    ->labelFormat(LabelFormat::Zpl)
    ->create();

$shipment->masterTrackingNumber;   // 794953535000
$shipment->trackingNumbers;        // one per package
$shipment->label()->content;       // decoded label (PDF, PNG or printer commands)
$shipment->netCharge;              // Money, when FedEx returns the shipment rating

Fedex::cancelShipment($shipment->masterTrackingNumber);
```

Other builder options: `shipper()`, `packaging()`, `pickupType()`, `shipDate()`, `saturdayDelivery()`,
`shipmentService('ELECTRONIC_TRADE_DOCUMENTS')`, `signature('DIRECT')`, `cashOnDelivery(Money)`,
`holdAtLocation($locationId)`, `billRecipient()`, `billThirdParty()`, `preferredCurrency()`, `labelsAsUrls()` and
`with([...])` for any other element of `requestedShipment`. `validate()` checks the shipment without creating it;
`toArray()` returns the request body. Shipments with more than 40 packages are processed asynchronously when the body
(`$builder->toArray()` plus `'processingOptionType' => 'ALLOW_ASYNCHRONOUS'`) is passed to `Fedex::createShipment()`:
`$result->jobId` and `Fedex::shipmentResults($jobId)`.

### International shipments

```php
use Beziworld\Fedex\Data\Customs;
use Beziworld\Fedex\Data\CustomsItem;

Fedex::shipment()
    ->to(new Party('John Smith', '5th Avenue 1', 'New York', '10001', 'US', phone: '2125550100', stateCode: 'NY'))
    ->service(Service::InternationalPriority)
    ->parcel(new Parcel(1.0))
    ->customs(new Customs(
        items: [new CustomsItem('Cotton T-shirt', quantity: 3, unitValue: '12.00', weight: 0.6, originCountry: 'PL', hsCode: '610910')],
        currency: 'USD',
        dutiesPaidBy: 'RECIPIENT',
        termsOfSale: 'DAP',
        invoiceNumber: 'FV/1/2026',
    ))
    ->create()
    ->document('COMMERCIAL_INVOICE')?->content;   // PDF generated by FedEx
```

## Rates

```php
$rates = Fedex::shipment()->to($recipient)->parcel(new Parcel(3))->rates();   // every service, cheapest first

$rates[0]->serviceType;   // FEDEX_REGIONAL_ECONOMY
$rates[0]->total;         // account price (Money) when returned, otherwise the first rate
$rates[0]->listTotal;     // list price
$rates[0]->deliveryAt;    // CarbonImmutable, when FedEx returns it
```

## Tracking

```php
$package = Fedex::track('794953535000');

$package->status;        // TrackingStatus::Delivered, OutForDelivery, InTransit, Exception, PickedUp, LabelCreated, ...
$package->deliveredAt;
foreach ($package->events as $event) {   // newest first
    $event->occurredAt; $event->eventType; $event->description; $event->city;
}

Fedex::trackMany(['794953535000', '123456789012']);   // per-number failures in $package->error
Fedex::trackByReference('ORDER-1', now()->subWeek(), now());
$pdf = Fedex::proofOfDelivery('794953535000');
Fedex::notifyByEmail('794953535000', ['customer@example.com'], 'ACME', 'shop@example.com');
```

### Tracking webhooks

Create a shipment visibility webhook in the FedEx developer portal with the URL `https://your-app/fedex/webhook` and a
security token (26 to 100 characters with upper case, lower case and digits), then:

```dotenv
FEDEX_WEBHOOK_ENABLED=true
FEDEX_WEBHOOK_TOKEN=YourSecurityToken1234567890
```

```php
use Beziworld\Fedex\Events\TrackingWebhookReceived;

Event::listen(function (TrackingWebhookReceived $webhook) {
    $webhook->event->trackingNumber;
    $webhook->event->status();      // TrackingStatus
    $webhook->event->raw;           // full payload
});
```

The route verifies the `fdx-signature` header (Base64 HMAC-SHA256 of the raw body with the security token, as in
the FedEx sample code) and answers 401 when it does not match. Exclude the path from CSRF verification if your
application applies it to this route. `SignatureVerifier` can also be used in your own controller.

## Addresses, locations and pickups

```php
use Beziworld\Fedex\Data\PickupRequest;

$address = Fedex::validateAddress(new Party('x', '7372 Parkridge Blvd', 'Irving', '75063', 'US', phone: '1', stateCode: 'TX'));
$address?->isResolved(); $address?->classification;   // RESIDENTIAL, BUSINESS, MIXED, UNKNOWN

$locations = Fedex::locations($party, radius: 5, limit: 10);
$locations = Fedex::locationsNear(52.23, 21.01, 'PL', types: ['FEDEX_OFFICE']);

Fedex::pickupAvailability(date: now()->addDay());
$pickup = Fedex::schedulePickup(new PickupRequest(readyAt: now()->addDay()->setTime(9, 0), closeTime: '17:00', carrier: 'FDXE', packageCount: 2));
Fedex::cancelPickup($pickup);   // keeps the code, date, carrier and location
```

## Multiple accounts

```php
Fedex::connection('us')->shipment()->...;   // config/fedex.php connections
Fedex::using(['client_id' => $tenant->fedex_key, 'client_secret' => $tenant->fedex_secret, 'account_number' => $tenant->fedex_account])->track('794953535000');
```

## Events

`ShipmentCreated`, `ShipmentCancelled`, `PickupScheduled` and `TrackingWebhookReceived` (namespace
`Beziworld\Fedex\Events`).

## Errors

| Exception | When |
|---|---|
| `ApiException` | FedEx rejected the request or could not be reached: `errorCode()` (e.g. `TRACKING.TRACKINGNUMBER.NOTFOUND`), `errors()`, `httpStatus()`, `transactionId()`, `isNotFound()`, `isRateLimited()` with `retryAfter()` |
| `AuthenticationException` | the OAuth endpoint refused the credentials (`NOT.AUTHORIZED.ERROR`) |
| `WebhookSignatureException` | a webhook without a valid `fdx-signature` |
| `InvalidConfiguration` | missing credentials, account number, shipper, recipient, service or parcels |

All extend `FedexException`. Read-only calls are retried on connection errors and HTTP 5xx (`FEDEX_RETRIES`);
shipments, cancellations, pickups and notification subscriptions are never retried automatically. A request rejected
with HTTP 401 is repeated once with a new token. FedEx blocks an IP for ten minutes (HTTP 403) when tokens are requested
too often, so tokens are cached and requested under a cache lock.

## Development

```bash
composer install
composer ci   # pint --test, phpstan (level 8), pest
```

## Author

Created and maintained by **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Issues: [GitHub issues](https://github.com/beziworld/laravel-fedex/issues)

Need an integration? Get in touch through [beziworld.eu](https://beziworld.eu).

### Other packages by the author

[laravel-ups](https://github.com/beziworld/laravel-ups) ·
[laravel-dhl-express](https://github.com/beziworld/laravel-dhl-express) ·
[laravel-dpd](https://github.com/beziworld/laravel-dpd) ·
[laravel-inpost](https://github.com/beziworld/laravel-inpost) ·
[laravel-furgonetka](https://github.com/beziworld/laravel-furgonetka)

## License

MIT. See [LICENSE.md](LICENSE.md). This package is not affiliated with, endorsed or sponsored by FedEx Corporation.
FedEx is a registered trademark of Federal Express Corporation.
