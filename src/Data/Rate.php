<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Price of one FedEx service for a shipment.
 *
 * total is the account (discounted) price when FedEx returned one,
 * otherwise the first rate returned; listTotal is the list price.
 */
final readonly class Rate
{
    /**
     * @param array<string, Money> $totals Net charge per rate type (ACCOUNT, LIST, PREFERRED, ...).
     * @param list<string> $messages
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $serviceType,
        public ?string $serviceName,
        public ?string $packagingType,
        public Money $total,
        public ?Money $listTotal,
        public array $totals,
        public ?CarbonImmutable $deliveryAt,
        public ?string $transitTime,
        public array $messages,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data A rateReplyDetails item.
     */
    public static function fromArray(array $data): ?self
    {
        $service = Payload::string($data, 'serviceType');
        $totals = [];

        foreach (Payload::items($data, 'ratedShipmentDetails') as $detail) {
            $amount = Payload::string($detail, 'totalNetCharge');
            $currency = Payload::string($detail, 'shipmentRateDetail.currency') ?? Payload::string($detail, 'currency');

            if ($amount !== null && $currency !== null && is_numeric($amount)) {
                $totals[Payload::string($detail, 'rateType') ?? (string) count($totals)] ??= Money::of($amount, $currency);
            }
        }

        if ($service === null || $totals === []) {
            return null;
        }

        $delivery = Payload::string($data, 'operationalDetail.deliveryDate') ?? Payload::string($data, 'commit.dateDetail.dayFormat');
        $messages = [];

        foreach (Payload::items($data, 'customerMessages') as $message) {
            $text = Payload::string($message, 'message');

            if ($text !== null) {
                $messages[] = $text;
            }
        }

        try {
            $deliveryAt = $delivery !== null ? CarbonImmutable::parse($delivery) : null;
        } catch (Throwable) {
            $deliveryAt = null;
        }

        return new self(
            serviceType: $service,
            serviceName: Payload::string($data, 'serviceName'),
            packagingType: Payload::string($data, 'packagingType'),
            total: $totals['ACCOUNT'] ?? $totals['PREFERRED'] ?? $totals['INCENTIVE'] ?? reset($totals),
            listTotal: $totals['LIST'] ?? null,
            totals: $totals,
            deliveryAt: $deliveryAt,
            transitTime: Payload::string($data, 'operationalDetail.transitTime') ?? Payload::string($data, 'commit.transitDays.description'),
            messages: $messages,
            raw: $data,
        );
    }
}
