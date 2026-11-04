# Laravel FedEx

[![tests](https://github.com/beziworld/laravel-fedex/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-fedex/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-fedex.svg)](https://packagist.org/packages/beziworld/laravel-fedex)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-fedex.svg)](LICENSE.md)

Klient REST API [FedEx](https://developer.fedex.com) dla Laravela:

- OAuth 2.0 (dane klienta, compatible provider i parent-child) z tokenami w cache, pobieranymi pod blokadą
- typowany builder przesyłki: nadawca, odbiorca, paczki z wagą i wymiarami, referencje, wartość deklarowana,
  doręczenie w sobotę, pobranie (COD), odbiór w placówce (hold at location), potwierdzenie podpisem, płatnik
  odbiorca lub trzeci
- przesyłki międzynarodowe z towarami, płatnikiem cła i fakturą handlową generowaną przez FedEx
- etykiety PDF, PNG, ZPLII lub EPL2 zdekodowane z base64 (albo jako linki), walidacja przesyłki, anulowanie,
  wyniki przesyłek asynchronicznych
- wycena jednej lub wszystkich usług z ceną z konta i cennikową oraz datą doręczenia
- śledzenie z typowanymi zdarzeniami i znormalizowanym statusem (do 30 numerów w jednym wywołaniu), śledzenie po
  referencji, potwierdzenie doręczenia z podpisem (SPOD), powiadomienia e-mail
- webhooki śledzenia: weryfikacja `fdx-signature`, gotowa trasa i zdarzenie Laravela
- walidacja adresów, wyszukiwarka placówek, dostępność, zamawianie i anulowanie kuriera
- wiele kont FedEx (nazwane połączenia i `Fedex::using()`), zdarzenia Laravela, komenda `fedex:check`

[English documentation](README.md)

## Dlaczego ten pakiet

| Pakiet | API | Laravel 12/13 | Uwagi |
|---|---|---|---|
| `jeremy-dunn/php-fedex-api-wrapper` | stare SOAP Web Services | nie | porzucony, ostatnie wydanie 2022 |
| `whatarmy/fedex-rest`, `shipstream/fedex-rest-sdk` | REST | to nie są pakiety Laravela | SDK bez integracji z Laravelem |
| `ktscript/fedex-rest`, `smart-dato/fedex-sdk` | REST | tak | młode pakiety |
| `ivanmitrikeski/laravel-shipping` | wielu przewoźników | tak | wymaga `ext-soap`, skupiony na wycenie |

Pakiet korzysta z aktualnych API REST (Ship, Rate, Track, Address Validation, Location Search, Pickup) i dokłada
typowany builder, zdekodowane etykiety i dokumenty, webhooki śledzenia z weryfikacją podpisu, wiele kont, cache
tokenów zgodny z limitami FedEx, klienta HTTP Laravela (`Http::fake()` działa w Twoich testach) oraz zasadę „bez
automatycznego ponawiania zapisów".

## Instalacja

```bash
composer require beziworld/laravel-fedex
php artisan vendor:publish --tag=fedex-config   # opcjonalnie
```

```dotenv
FEDEX_CLIENT_ID=...            # klucz API projektu na developer.fedex.com
FEDEX_CLIENT_SECRET=...        # secret key
FEDEX_ACCOUNT_NUMBER=740561073 # dziewięciocyfrowy numer konta FedEx
FEDEX_SANDBOX=true             # apis-sandbox.fedex.com (predefiniowane odpowiedzi)

FEDEX_SHIPPER_NAME="Jan Nowak"
FEDEX_SHIPPER_COMPANY="ACME Sp. z o.o."
FEDEX_SHIPPER_PHONE=500600700
FEDEX_SHIPPER_EMAIL=sklep@example.com
FEDEX_SHIPPER_ADDRESS="ul. Prosta 1"
FEDEX_SHIPPER_CITY=Warszawa
FEDEX_SHIPPER_POSTAL_CODE=00-001
FEDEX_SHIPPER_COUNTRY=PL
FEDEX_SHIPPER_TAX_ID=PL5250000000   # VAT/EORI, wysyłany jako TIN (domyślnie BUSINESS_UNION)

FEDEX_UNITS=metric             # metric (KG, CM) albo imperial (LB, IN)
FEDEX_LABEL_FORMAT=PDF         # PDF, PNG, ZPLII lub EPL2
FEDEX_CACHE_STORE=redis        # wspólne tokeny dla wielu procesów
```

Sprawdzenie danych dostępowych (pobiera tylko token): `php artisan fedex:check`.

## Nadanie przesyłki

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
$shipment->trackingNumbers;        // po jednym na paczkę
$shipment->label()->content;       // zdekodowana etykieta (PDF, PNG albo komendy drukarki)
$shipment->netCharge;              // Money, jeśli FedEx zwrócił wycenę przesyłki

Fedex::cancelShipment($shipment->masterTrackingNumber);
```

Pozostałe opcje buildera: `shipper()`, `packaging()`, `pickupType()`, `shipDate()`, `saturdayDelivery()`,
`shipmentService('ELECTRONIC_TRADE_DOCUMENTS')`, `signature('DIRECT')`, `cashOnDelivery(Money)`,
`holdAtLocation($locationId)`, `billRecipient()`, `billThirdParty()`, `preferredCurrency()`, `labelsAsUrls()` oraz
`with([...])` dla dowolnego innego elementu `requestedShipment`. `validate()` sprawdza przesyłkę bez jej tworzenia,
`toArray()` zwraca treść żądania. Przesyłki z więcej niż 40 paczkami są przetwarzane asynchronicznie, gdy do
`Fedex::createShipment()` trafi treść żądania (`$builder->toArray()` z dodanym
`'processingOptionType' => 'ALLOW_ASYNCHRONOUS'`): `$result->jobId` i `Fedex::shipmentResults($jobId)`.

### Przesyłki międzynarodowe

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
    ->document('COMMERCIAL_INVOICE')?->content;   // PDF wygenerowany przez FedEx
```

## Wycena

```php
$rates = Fedex::shipment()->to($recipient)->parcel(new Parcel(3))->rates();   // wszystkie usługi, od najtańszej

$rates[0]->serviceType;   // FEDEX_REGIONAL_ECONOMY
$rates[0]->total;         // cena z konta (Money), jeśli jest, w przeciwnym razie pierwsza stawka
$rates[0]->listTotal;     // cena cennikowa
$rates[0]->deliveryAt;    // CarbonImmutable, jeśli FedEx ją zwrócił
```

## Śledzenie

```php
$package = Fedex::track('794953535000');

$package->status;        // TrackingStatus::Delivered, OutForDelivery, InTransit, Exception, PickedUp, LabelCreated, ...
$package->deliveredAt;
foreach ($package->events as $event) {   // od najnowszego
    $event->occurredAt; $event->eventType; $event->description; $event->city;
}

Fedex::trackMany(['794953535000', '123456789012']);   // błędy pojedynczych numerów w $package->error
Fedex::trackByReference('ORDER-1', now()->subWeek(), now());
$pdf = Fedex::proofOfDelivery('794953535000');
Fedex::notifyByEmail('794953535000', ['klient@example.com'], 'ACME', 'sklep@example.com');
```

### Webhooki śledzenia

Utwórz webhook (shipment visibility) w portalu FedEx z adresem `https://twoja-aplikacja/fedex/webhook` i tokenem
bezpieczeństwa (26 do 100 znaków, wielkie i małe litery oraz cyfry), a następnie:

```dotenv
FEDEX_WEBHOOK_ENABLED=true
FEDEX_WEBHOOK_TOKEN=TwojTokenBezpieczenstwa1234567
```

```php
use Beziworld\Fedex\Events\TrackingWebhookReceived;

Event::listen(function (TrackingWebhookReceived $webhook) {
    $webhook->event->trackingNumber;
    $webhook->event->status();      // TrackingStatus
    $webhook->event->raw;           // cały payload
});
```

Trasa weryfikuje nagłówek `fdx-signature` (Base64 z HMAC-SHA256 surowej treści żądania z tokenem bezpieczeństwa,
zgodnie z przykładowym kodem FedEx) i odpowiada 401, gdy podpis się nie zgadza. Wyłącz tę ścieżkę z weryfikacji CSRF,
jeśli aplikacja ją tam stosuje. `SignatureVerifier` można też użyć we własnym kontrolerze.

## Adresy, placówki i kurier

```php
use Beziworld\Fedex\Data\PickupRequest;

$address = Fedex::validateAddress(new Party('x', '7372 Parkridge Blvd', 'Irving', '75063', 'US', phone: '1', stateCode: 'TX'));
$address?->isResolved(); $address?->classification;   // RESIDENTIAL, BUSINESS, MIXED, UNKNOWN

$locations = Fedex::locations($party, radius: 5, limit: 10);
$locations = Fedex::locationsNear(52.23, 21.01, 'PL', types: ['FEDEX_OFFICE']);

Fedex::pickupAvailability(date: now()->addDay());
$pickup = Fedex::schedulePickup(new PickupRequest(readyAt: now()->addDay()->setTime(9, 0), closeTime: '17:00', carrier: 'FDXE', packageCount: 2));
Fedex::cancelPickup($pickup);   // obiekt przechowuje kod, datę, przewoźnika i lokalizację
```

## Wiele kont

```php
Fedex::connection('us')->shipment()->...;   // połączenia z config/fedex.php
Fedex::using(['client_id' => $tenant->fedex_key, 'client_secret' => $tenant->fedex_secret, 'account_number' => $tenant->fedex_account])->track('794953535000');
```

## Zdarzenia

`ShipmentCreated`, `ShipmentCancelled`, `PickupScheduled` i `TrackingWebhookReceived` (przestrzeń nazw
`Beziworld\Fedex\Events`).

## Błędy

| Wyjątek | Kiedy |
|---|---|
| `ApiException` | FedEx odrzucił żądanie albo był nieosiągalny: `errorCode()` (np. `TRACKING.TRACKINGNUMBER.NOTFOUND`), `errors()`, `httpStatus()`, `transactionId()`, `isNotFound()`, `isRateLimited()` z `retryAfter()` |
| `AuthenticationException` | endpoint OAuth odrzucił dane dostępowe (`NOT.AUTHORIZED.ERROR`) |
| `WebhookSignatureException` | webhook bez poprawnego `fdx-signature` |
| `InvalidConfiguration` | brak danych dostępowych, numeru konta, nadawcy, odbiorcy, usługi lub paczek |

Wszystkie dziedziczą po `FedexException`. Wywołania tylko do odczytu są ponawiane po błędach połączenia i HTTP 5xx
(`FEDEX_RETRIES`); przesyłki, anulowania, zamówienia kuriera i subskrypcje powiadomień nigdy nie są ponawiane
automatycznie. Żądanie odrzucone kodem HTTP 401 jest powtarzane raz z nowym tokenem. FedEx blokuje adres IP na
dziesięć minut (HTTP 403), gdy tokeny są pobierane zbyt często, dlatego pakiet trzyma je w cache i pobiera pod blokadą.

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8), pest
```

## Autor

Pakiet tworzy i utrzymuje **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia: [GitHub issues](https://github.com/beziworld/laravel-fedex/issues)

Potrzebujesz integracji? Skontaktuj się przez [beziworld.eu](https://beziworld.eu).

### Inne pakiety autora

[laravel-ups](https://github.com/beziworld/laravel-ups) ·
[laravel-dhl-express](https://github.com/beziworld/laravel-dhl-express) ·
[laravel-dpd](https://github.com/beziworld/laravel-dpd) ·
[laravel-inpost](https://github.com/beziworld/laravel-inpost) ·
[laravel-furgonetka](https://github.com/beziworld/laravel-furgonetka)

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z FedEx Corporation ani przez nią wspierany.
FedEx jest zastrzeżonym znakiem towarowym Federal Express Corporation.
