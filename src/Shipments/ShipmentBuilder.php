<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Shipments;

use Beziworld\Fedex\Data\Customs;
use Beziworld\Fedex\Data\CustomsItem;
use Beziworld\Fedex\Data\Money;
use Beziworld\Fedex\Data\Parcel;
use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Data\Rate;
use Beziworld\Fedex\Data\ShipmentResult;
use Beziworld\Fedex\Enums\LabelFormat;
use Beziworld\Fedex\Enums\Packaging;
use Beziworld\Fedex\Enums\Service;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\FedexClient;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Fluent builder of a FedEx shipment, used both to create the shipment and to rate it.
 *
 * Units follow the connection configuration: kilograms and centimetres for
 * "metric", pounds and inches for "imperial". The shipper defaults to the
 * address configured for the connection and pays with its account number.
 */
final class ShipmentBuilder
{
    private ?Party $shipper = null;

    private ?Party $recipient = null;

    private ?string $service = null;

    private string $packaging = 'YOUR_PACKAGING';

    private string $pickupType = 'DROPOFF_AT_FEDEX_LOCATION';

    /** @var list<Parcel> */
    private array $parcels = [];

    private ?LabelFormat $labelFormat = null;

    private ?string $labelStock = null;

    private bool $labelsAsUrls = false;

    private ?DateTimeInterface $shipDate = null;

    /** @var list<string> */
    private array $shipmentServices = [];

    private ?string $signature = null;

    private ?Money $codAmount = null;

    private string $codCollectionType = 'ANY';

    private ?string $holdAtLocationId = null;

    private ?Customs $customs = null;

    /** @var array<string, mixed>|null */
    private ?array $payor = null;

    private string $paymentType = 'SENDER';

    private ?string $preferredCurrency = null;

    /** @var array<string, mixed> */
    private array $extra = [];

    public function __construct(private readonly FedexClient $client) {}

    /**
     * Shipper and owner of the paying account; defaults to the configured shipper.
     */
    public function shipper(Party $shipper): self
    {
        $this->shipper = $shipper;

        return $this;
    }

    public function to(Party $recipient): self
    {
        $this->recipient = $recipient;

        return $this;
    }

    /**
     * Service type; required for shipments, optional for rating (all services are rated without it).
     */
    public function service(Service|string $service): self
    {
        $this->service = $service instanceof Service ? $service->value : $service;

        return $this;
    }

    public function packaging(Packaging|string $packaging): self
    {
        $this->packaging = $packaging instanceof Packaging ? $packaging->value : $packaging;

        return $this;
    }

    /**
     * DROPOFF_AT_FEDEX_LOCATION (default), CONTACT_FEDEX_TO_SCHEDULE or USE_SCHEDULED_PICKUP.
     */
    public function pickupType(string $pickupType): self
    {
        $this->pickupType = $pickupType;

        return $this;
    }

    public function parcel(Parcel $parcel): self
    {
        $this->parcels[] = $parcel;

        return $this;
    }

    /**
     * @param list<Parcel> $parcels
     */
    public function parcels(array $parcels): self
    {
        $this->parcels = $parcels;

        return $this;
    }

    /**
     * Label format and stock; the stock defaults to 4x6 inch paper or thermal stock.
     */
    public function labelFormat(LabelFormat $format, ?string $stock = null): self
    {
        $this->labelFormat = $format;
        $this->labelStock = $stock;

        return $this;
    }

    /**
     * Requests label links (valid for 12 hours) instead of the encoded files.
     */
    public function labelsAsUrls(bool $enabled = true): self
    {
        $this->labelsAsUrls = $enabled;

        return $this;
    }

    public function shipDate(DateTimeInterface $date): self
    {
        $this->shipDate = $date;

        return $this;
    }

    public function saturdayDelivery(bool $enabled = true): self
    {
        return $this->shipmentService('SATURDAY_DELIVERY', $enabled);
    }

    /**
     * Adds a shipment special service type, e.g. ELECTRONIC_TRADE_DOCUMENTS.
     */
    public function shipmentService(string $type, bool $enabled = true): self
    {
        $this->shipmentServices = array_values(array_diff($this->shipmentServices, [$type]));

        if ($enabled) {
            $this->shipmentServices[] = $type;
        }

        return $this;
    }

    /**
     * Signature option of every package: NO_SIGNATURE_REQUIRED, INDIRECT, DIRECT or ADULT.
     */
    public function signature(string $option): self
    {
        $this->signature = $option;

        return $this;
    }

    /**
     * Collect on delivery (shipment level).
     *
     * @param string $collectionType ANY, CASH, GUARANTEED_FUNDS, COMPANY_CHECK or PERSONAL_CHECK.
     */
    public function cashOnDelivery(Money $amount, string $collectionType = 'ANY'): self
    {
        $this->codAmount = $amount;
        $this->codCollectionType = $collectionType;

        return $this;
    }

    /**
     * Holds the shipment at a FedEx location (see FedexClient::locations()) for collection.
     */
    public function holdAtLocation(string $locationId): self
    {
        $this->holdAtLocationId = $locationId;

        return $this;
    }

    public function customs(Customs $customs): self
    {
        $this->customs = $customs;

        return $this;
    }

    /**
     * Bills transportation to the recipient's FedEx account.
     */
    public function billRecipient(string $accountNumber): self
    {
        $this->paymentType = 'RECIPIENT';
        $this->payor = ['responsibleParty' => ['accountNumber' => ['value' => $accountNumber]]];

        return $this;
    }

    /**
     * Bills transportation to a third-party FedEx account.
     */
    public function billThirdParty(string $accountNumber, ?Party $party = null): self
    {
        $this->paymentType = 'THIRD_PARTY';
        $this->payor = ['responsibleParty' => ($party?->toFedex() ?? []) + ['accountNumber' => ['value' => $accountNumber]]];

        return $this;
    }

    public function preferredCurrency(string $currency): self
    {
        $this->preferredCurrency = strtoupper($currency);

        return $this;
    }

    /**
     * Additional elements merged recursively into requestedShipment, for options without a dedicated method.
     *
     * @param array<string, mixed> $requestedShipment
     */
    public function with(array $requestedShipment): self
    {
        $this->extra = array_replace_recursive($this->extra, $requestedShipment);

        return $this;
    }

    /**
     * Creates the shipment. The request is never retried automatically.
     */
    public function create(): ShipmentResult
    {
        return $this->client->createShipment($this);
    }

    /**
     * Validates the shipment without creating it; returns the FedEx alerts.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        return $this->client->validateShipment($this);
    }

    /**
     * Rates of the selected service, or of every available service when none is selected.
     *
     * @return list<Rate>
     */
    public function rates(): array
    {
        return $this->client->rates($this);
    }

    /**
     * Body of POST /ship/v1/shipments.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = $this->client->config();
        $format = $this->labelFormat ?? $config->labelFormat;
        $shipment = $this->requestedShipment(false);
        $shipment['labelSpecification'] = [
            'imageType' => $format->value,
            'labelStockType' => $this->labelStock ?? $config->labelStock ?? $format->defaultStock(),
        ];

        if ($this->customs?->generateInvoice === true) {
            $shipment['shippingDocumentSpecification'] = [
                'shippingDocumentTypes' => ['COMMERCIAL_INVOICE'],
                'commercialInvoiceDetail' => ['documentFormat' => ['stockType' => 'PAPER_LETTER', 'docType' => 'PDF']],
            ];
        }

        return [
            'labelResponseOptions' => $this->labelsAsUrls ? 'URL_ONLY' : 'LABEL',
            'accountNumber' => ['value' => $config->requireAccountNumber()],
            'requestedShipment' => array_replace_recursive($shipment, $this->extra),
        ];
    }

    /**
     * Body of POST /rate/v1/rates/quotes.
     *
     * @return array<string, mixed>
     */
    public function toRateArray(): array
    {
        return [
            'accountNumber' => ['value' => $this->client->config()->requireAccountNumber()],
            'requestedShipment' => array_replace_recursive($this->requestedShipment(true) + ['rateRequestType' => ['ACCOUNT', 'LIST']], $this->extra),
            'rateRequestControlParameters' => ['returnTransitTimes' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestedShipment(bool $forRating): array
    {
        $shipper = $this->shipper ?? $this->client->config()->requireShipper();
        $recipient = $this->recipient ?? throw InvalidConfiguration::invalid('The FedEx shipment has no recipient; call to().');

        if ($this->parcels === []) {
            throw InvalidConfiguration::invalid('The FedEx shipment has no parcels; call parcel().');
        }

        if (! $forRating && $this->service === null) {
            throw InvalidConfiguration::invalid('The FedEx shipment has no service type; call service().');
        }

        $date = CarbonImmutable::instance($this->shipDate ?? CarbonImmutable::now())->format('Y-m-d');
        $services = $this->shipmentServices;

        if ($this->codAmount !== null) {
            $services[] = 'COD';
        }

        if ($this->holdAtLocationId !== null) {
            $services[] = 'HOLD_AT_LOCATION';
        }

        return Payload::clean([
            'shipper' => $shipper->toFedex(),
            $forRating ? 'recipient' : 'recipients' => $forRating ? $recipient->toFedex() : [$recipient->toFedex()],
            $forRating ? 'shipDateStamp' : 'shipDatestamp' => $date,
            'pickupType' => $this->pickupType,
            'serviceType' => $this->service,
            'packagingType' => $this->packaging,
            'preferredCurrency' => $this->preferredCurrency,
            'totalPackageCount' => count($this->parcels),
            'shippingChargesPayment' => $forRating ? null : Payload::clean(['paymentType' => $this->paymentType, 'payor' => $this->payor]),
            'shipmentSpecialServices' => $services === [] ? null : Payload::clean([
                'specialServiceTypes' => array_values(array_unique($services)),
                'shipmentCODDetail' => $this->codAmount !== null ? ['codCollectionAmount' => $this->codAmount->toFedex(), 'codCollectionType' => $this->codCollectionType] : null,
                'holdAtLocationDetail' => $this->holdAtLocationId !== null ? ['locationId' => $this->holdAtLocationId] : null,
            ]),
            'customsClearanceDetail' => $this->customs !== null ? $this->customsDetail($this->customs, $forRating) : null,
            'requestedPackageLineItems' => array_map(fn(Parcel $parcel): array => $this->package($parcel, $forRating), $this->parcels),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customsDetail(Customs $customs, bool $forRating): array
    {
        $weightUnit = $this->client->config()->weightUnit();

        return Payload::clean([
            'dutiesPayment' => Payload::clean([
                'paymentType' => $customs->dutiesPaidBy,
                'payor' => $customs->dutiesAccountNumber !== null ? ['responsibleParty' => ['accountNumber' => ['value' => $customs->dutiesAccountNumber]]] : null,
            ]),
            'isDocumentOnly' => $customs->documentsOnly ? true : null,
            'totalCustomsValue' => $customs->total()->toFedex(),
            'commercialInvoice' => $forRating ? null : Payload::clean([
                'shipmentPurpose' => $customs->purpose,
                'termsOfSale' => $customs->termsOfSale,
                'customerReferences' => $customs->invoiceNumber !== null ? [['customerReferenceType' => 'INVOICE_NUMBER', 'value' => $customs->invoiceNumber]] : null,
                'comments' => $customs->comments !== null ? [$customs->comments] : null,
            ]),
            'commodities' => array_map(static fn(CustomsItem $item): array => Payload::clean([
                'description' => $item->description,
                'countryOfManufacture' => strtoupper($item->originCountry),
                'quantity' => $item->quantity,
                'quantityUnits' => $item->unit,
                'unitPrice' => ['amount' => (float) $item->unitValue, 'currency' => strtoupper($customs->currency)],
                'customsValue' => ['amount' => $item->customsValue(), 'currency' => strtoupper($customs->currency)],
                'weight' => ['units' => $weightUnit, 'value' => round($item->weight, 1)],
                'harmonizedCode' => $item->hsCode,
                'partNumber' => $item->partNumber,
            ]), $customs->items),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function package(Parcel $parcel, bool $forRating): array
    {
        $config = $this->client->config();
        $whole = static fn(?float $value): int => (int) ceil((float) $value);

        return Payload::clean([
            'weight' => ['units' => $config->weightUnit(), 'value' => round($parcel->weight, 1)],
            'dimensions' => $parcel->hasDimensions() ? [
                'length' => $whole($parcel->length),
                'width' => $whole($parcel->width),
                'height' => $whole($parcel->height),
                'units' => $config->dimensionUnit(),
            ] : null,
            'declaredValue' => $parcel->declaredValue?->toFedex(),
            'itemDescription' => $parcel->description,
            'customerReferences' => $parcel->reference !== null && ! $forRating ? [['customerReferenceType' => 'CUSTOMER_REFERENCE', 'value' => $parcel->reference]] : null,
            'packageSpecialServices' => $this->signature !== null ? ['specialServiceTypes' => ['SIGNATURE_OPTION'], 'signatureOptionType' => $this->signature] : null,
        ]);
    }
}
