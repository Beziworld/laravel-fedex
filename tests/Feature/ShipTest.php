<?php

declare(strict_types=1);

use Beziworld\Fedex\Data\Parcel;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Events\ShipmentCancelled;
use Beziworld\Fedex\Events\ShipmentCreated;
use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Facades\Fedex;
use Beziworld\Fedex\Shipments\ShipmentBuilder;
use Beziworld\Fedex\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function fedexShipment(): ShipmentBuilder
{
    return Fedex::shipment()
        ->to(new Party('Max Mustermann', 'Potsdamer Str. 1', 'Berlin', '10785', 'DE', '49301234'))
        ->service('FEDEX_INTERNATIONAL_PRIORITY')
        ->parcel(new Parcel(1.5))
        ->parcel(new Parcel(1.5));
}

it('creates a shipment and decodes labels and the commercial invoice', function (): void {
    Event::fake([ShipmentCreated::class]);
    $this->fakeFedex([TestCase::API . '/ship/v1/shipments' => Http::response(TestCase::fixture('ship'))]);

    $result = fedexShipment()->create();

    expect($result->masterTrackingNumber)->toBe('794953535000')
        ->and($result->trackingNumbers)->toBe(['794953535000', '794953535011'])
        ->and($result->labels)->toHaveCount(2)
        ->and($result->label('794953535011')?->content)->toBe('%PDF-1.7 label')
        ->and($result->document('COMMERCIAL_INVOICE')?->content)->toBe('%PDF-1.7 invoice')
        ->and($result->document('CERTIFICATE_OF_ORIGIN'))->toBeNull()
        ->and($result->netCharge?->amount)->toBe('104.80')
        ->and($result->serviceName)->toBe('FedEx International Priority')
        ->and($result->isAsynchronous())->toBeFalse()
        ->and($result->alerts)->toBe(['SHIP.RECIPIENT.POSTALCITY.MISMATCH: Recipient postal-city combination mismatch.']);

    Http::assertSent(fn(Request $request): bool => $request->url() === TestCase::API . '/ship/v1/shipments'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer token-1')
        && $request->hasHeader('x-locale', 'en_US')
        && $request->hasHeader('x-customer-transaction-id')
        && count($request->data()['requestedShipment']['requestedPackageLineItems'] ?? []) === 2);
    Event::assertDispatched(ShipmentCreated::class, fn(ShipmentCreated $event): bool => $event->shipment->masterTrackingNumber === '794953535000');
});

it('never retries a shipment and exposes the FedEx error', function (): void {
    $this->fakeFedex([TestCase::API . '/ship/v1/shipments' => Http::response([
        'transactionId' => 'tx-500',
        'errors' => [['code' => 'INTERNAL.SERVER.ERROR', 'message' => 'We encountered an unexpected error.']],
    ], 500)]);

    try {
        fedexShipment()->create();
        $this->fail('Expected an exception.');
    } catch (ApiException $exception) {
        expect($exception->errorCode())->toBe('INTERNAL.SERVER.ERROR')
            ->and($exception->transactionId())->toBe('tx-500')
            ->and($exception->httpStatus())->toBe(500);
    }

    Http::assertSentCount(2);
});

it('returns the job id of an asynchronous shipment and fetches its results', function (): void {
    $this->fakeFedex([
        TestCase::API . '/ship/v1/shipments' => Http::response(['transactionId' => 't', 'output' => ['jobId' => 'job-123']]),
        TestCase::API . '/ship/v1/shipments/results' => Http::response(TestCase::fixture('ship')),
    ]);

    $pending = fedexShipment()->create();
    $result = Fedex::shipmentResults('job-123');

    expect($pending->isAsynchronous())->toBeTrue()
        ->and($pending->jobId)->toBe('job-123')
        ->and($result->trackingNumbers)->toHaveCount(2);

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/results') && $request->data() === ['accountNumber' => ['value' => '740561073'], 'jobId' => 'job-123']);
});

it('validates a shipment without creating it', function (): void {
    $this->fakeFedex([TestCase::API . '/ship/v1/shipments/packages/validate' => Http::response(['output' => ['alerts' => [['code' => 'SHIPMENT.VALIDATION.SUCCESS', 'alertType' => 'NOTE', 'message' => 'Shipment Validated Successfully']]]])]);

    expect(fedexShipment()->validate())->toBe(['SHIPMENT.VALIDATION.SUCCESS: Shipment Validated Successfully']);
});

it('cancels a shipment', function (): void {
    Event::fake([ShipmentCancelled::class]);
    $this->fakeFedex([TestCase::API . '/ship/v1/shipments/cancel' => Http::response(['transactionId' => 't', 'output' => ['cancelledShipment' => true, 'cancelledHistory' => true, 'message' => 'Shipment is successfully cancelled']])]);

    $result = Fedex::cancelShipment('794953535000');

    expect($result->cancelled)->toBeTrue()->and($result->message)->toBe('Shipment is successfully cancelled');

    Http::assertSent(fn(Request $request): bool => $request->method() === 'PUT' && $request->data() === [
        'accountNumber' => ['value' => '740561073'],
        'trackingNumber' => '794953535000',
        'senderCountryCode' => 'PL',
        'deletionControl' => 'DELETE_ALL_PACKAGES',
    ]);
    Event::assertDispatched(ShipmentCancelled::class);
});

it('rates every available service, cheapest first', function (): void {
    $this->fakeFedex([TestCase::API . '/rate/v1/rates/quotes' => Http::response(TestCase::fixture('rates'))]);

    $rates = Fedex::shipment()->to(new Party('Max', 'Potsdamer Str. 1', 'Berlin', '10785', 'DE', '1'))->parcel(new Parcel(3))->rates();

    expect($rates)->toHaveCount(2)
        ->and($rates[0]->serviceType)->toBe('FEDEX_REGIONAL_ECONOMY')
        ->and($rates[0]->total->amount)->toBe('60.25')
        ->and($rates[0]->messages)->toBe(['Rate does not include duties and taxes'])
        ->and($rates[1]->transitTime)->toBe('TWO_DAYS');
});

it('retries a rate request after a service outage', function (): void {
    $this->fakeFedex([TestCase::API . '/rate/v1/rates/quotes' => Http::sequence()->push(['errors' => [['code' => 'SERVICE.UNAVAILABLE.ERROR', 'message' => 'down']]], 503)->push(TestCase::fixture('rates'))]);

    expect(Fedex::rates(['accountNumber' => ['value' => '740561073']]))->toHaveCount(2);

    Http::assertSentCount(3);
});
