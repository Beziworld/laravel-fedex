<?php

declare(strict_types=1);

use Beziworld\Fedex\Data\Customs;
use Beziworld\Fedex\Data\CustomsItem;
use Beziworld\Fedex\Data\Money;
use Beziworld\Fedex\Data\Parcel;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Enums\LabelFormat;
use Beziworld\Fedex\Enums\Packaging;
use Beziworld\Fedex\Enums\Service;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\Facades\Fedex;
use Carbon\CarbonImmutable;

function fedexRecipient(): Party
{
    return new Party('Max Mustermann', 'Potsdamer Str. 1', 'Berlin', '10785', 'DE', '49301234', email: 'max@example.com');
}

it('builds a shipment request with the configured shipper and account', function (): void {
    $body = Fedex::shipment()
        ->to(fedexRecipient())
        ->service(Service::InternationalPriority)
        ->parcel(new Parcel(2.25, 30.2, 20, 10, description: 'Books', reference: 'ORDER-1', declaredValue: Money::of(100, 'EUR')))
        ->shipDate(CarbonImmutable::parse('2026-11-05'))
        ->labelFormat(LabelFormat::Zpl)
        ->toArray();

    $shipment = $body['requestedShipment'];

    expect($body['labelResponseOptions'])->toBe('LABEL')
        ->and($body['accountNumber'])->toBe(['value' => '740561073'])
        ->and($shipment['shipper']['contact'])->toBe(['personName' => 'Jan Nowak', 'companyName' => 'ACME Sp. z o.o.', 'phoneNumber' => '500600700', 'emailAddress' => 'shop@example.com'])
        ->and($shipment['shipper']['tins'])->toBe([['number' => 'PL5250000000', 'tinType' => 'BUSINESS_UNION']])
        ->and($shipment['recipients'][0]['address']['countryCode'])->toBe('DE')
        ->and($shipment['shipDatestamp'])->toBe('2026-11-05')
        ->and($shipment['pickupType'])->toBe('DROPOFF_AT_FEDEX_LOCATION')
        ->and($shipment['serviceType'])->toBe('FEDEX_INTERNATIONAL_PRIORITY')
        ->and($shipment['packagingType'])->toBe('YOUR_PACKAGING')
        ->and($shipment['shippingChargesPayment'])->toBe(['paymentType' => 'SENDER'])
        ->and($shipment['labelSpecification'])->toBe(['imageType' => 'ZPLII', 'labelStockType' => 'STOCK_4X6'])
        ->and($shipment['requestedPackageLineItems'][0])->toBe([
            'weight' => ['units' => 'KG', 'value' => 2.3],
            'dimensions' => ['length' => 31, 'width' => 20, 'height' => 10, 'units' => 'CM'],
            'declaredValue' => ['amount' => 100.0, 'currency' => 'EUR'],
            'itemDescription' => 'Books',
            'customerReferences' => [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => 'ORDER-1']],
        ]);
});

it('adds customs, special services, payers and overrides', function (): void {
    $shipment = Fedex::shipment()
        ->to(fedexRecipient())
        ->service('FEDEX_REGIONAL_ECONOMY')
        ->packaging(Packaging::Pak)
        ->pickupType('USE_SCHEDULED_PICKUP')
        ->parcel(new Parcel(1))
        ->customs(new Customs(
            items: [new CustomsItem('Cotton T-shirt', 3, '12.00', 0.6, 'pl', '610910', 'TS-1')],
            currency: 'eur',
            dutiesPaidBy: 'SENDER',
            termsOfSale: 'DDP',
            invoiceNumber: 'FV/1/2026',
        ))
        ->saturdayDelivery()
        ->shipmentService('ELECTRONIC_TRADE_DOCUMENTS')
        ->cashOnDelivery(Money::of(50, 'EUR'))
        ->holdAtLocation('WAWA')
        ->signature('DIRECT')
        ->billThirdParty('123456789')
        ->labelsAsUrls()
        ->with(['emailNotificationDetail' => ['aggregationType' => 'PER_SHIPMENT']])
        ->toArray();

    $requested = $shipment['requestedShipment'];

    expect($shipment['labelResponseOptions'])->toBe('URL_ONLY')
        ->and($requested['packagingType'])->toBe('FEDEX_PAK')
        ->and($requested['pickupType'])->toBe('USE_SCHEDULED_PICKUP')
        ->and($requested['shippingChargesPayment'])->toBe(['paymentType' => 'THIRD_PARTY', 'payor' => ['responsibleParty' => ['accountNumber' => ['value' => '123456789']]]])
        ->and($requested['shipmentSpecialServices'])->toBe([
            'specialServiceTypes' => ['SATURDAY_DELIVERY', 'ELECTRONIC_TRADE_DOCUMENTS', 'COD', 'HOLD_AT_LOCATION'],
            'shipmentCODDetail' => ['codCollectionAmount' => ['amount' => 50.0, 'currency' => 'EUR'], 'codCollectionType' => 'ANY'],
            'holdAtLocationDetail' => ['locationId' => 'WAWA'],
        ])
        ->and($requested['requestedPackageLineItems'][0]['packageSpecialServices'])->toBe(['specialServiceTypes' => ['SIGNATURE_OPTION'], 'signatureOptionType' => 'DIRECT'])
        ->and($requested['customsClearanceDetail']['dutiesPayment'])->toBe(['paymentType' => 'SENDER'])
        ->and($requested['customsClearanceDetail']['totalCustomsValue'])->toBe(['amount' => 36.0, 'currency' => 'EUR'])
        ->and($requested['customsClearanceDetail']['commercialInvoice'])->toBe([
            'shipmentPurpose' => 'SOLD',
            'termsOfSale' => 'DDP',
            'customerReferences' => [['customerReferenceType' => 'INVOICE_NUMBER', 'value' => 'FV/1/2026']],
        ])
        ->and($requested['customsClearanceDetail']['commodities'][0])->toBe([
            'description' => 'Cotton T-shirt',
            'countryOfManufacture' => 'PL',
            'quantity' => 3,
            'quantityUnits' => 'PCS',
            'unitPrice' => ['amount' => 12.0, 'currency' => 'EUR'],
            'customsValue' => ['amount' => 36.0, 'currency' => 'EUR'],
            'weight' => ['units' => 'KG', 'value' => 0.6],
            'harmonizedCode' => '610910',
            'partNumber' => 'TS-1',
        ])
        ->and($requested['shippingDocumentSpecification']['shippingDocumentTypes'])->toBe(['COMMERCIAL_INVOICE'])
        ->and($requested['emailNotificationDetail'])->toBe(['aggregationType' => 'PER_SHIPMENT']);
});

it('builds the rate request with a singular recipient and both rate types', function (): void {
    $body = Fedex::shipment()->to(fedexRecipient())->parcel(new Parcel(1, reference: 'not rated'))->billRecipient('987654321')->toRateArray();
    $requested = $body['requestedShipment'];

    expect($requested)->toHaveKeys(['recipient', 'shipDateStamp'])
        ->and($requested)->not->toHaveKeys(['recipients', 'shipDatestamp', 'serviceType', 'shippingChargesPayment'])
        ->and($requested['rateRequestType'])->toBe(['ACCOUNT', 'LIST'])
        ->and($requested['requestedPackageLineItems'][0])->not->toHaveKey('customerReferences')
        ->and($body['rateRequestControlParameters'])->toBe(['returnTransitTimes' => true]);
});

it('rejects incomplete shipments', function (): void {
    expect(fn() => Fedex::shipment()->parcel(new Parcel(1))->service('FEDEX_GROUND')->toArray())->toThrow(InvalidConfiguration::class, 'recipient')
        ->and(fn() => Fedex::shipment()->to(fedexRecipient())->service('FEDEX_GROUND')->toArray())->toThrow(InvalidConfiguration::class, 'parcels')
        ->and(fn() => Fedex::shipment()->to(fedexRecipient())->parcel(new Parcel(1))->toArray())->toThrow(InvalidConfiguration::class, 'service');
});
