<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Enums\TrackingStatus;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * Tracking state of one package with its scan events, newest first.
 *
 * FedEx reports failures for single numbers inside a successful response;
 * they are exposed through error() instead of an exception when several
 * numbers are tracked at once.
 */
final readonly class TrackedPackage
{
    /**
     * @param list<TrackingEvent> $events
     * @param array{code: ?string, message: string}|null $error
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $trackingNumber,
        public TrackingStatus $status,
        public ?string $statusCode,
        public ?string $statusDescription,
        public ?string $serviceName,
        public ?CarbonImmutable $deliveredAt,
        public ?CarbonImmutable $estimatedDelivery,
        public ?string $receivedBy,
        public array $events,
        public ?array $error,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data A trackResults item.
     */
    public static function fromArray(array $data, string $trackingNumber = ''): self
    {
        $events = array_map(TrackingEvent::fromArray(...), Payload::items($data, 'scanEvents'));
        usort($events, static fn(TrackingEvent $a, TrackingEvent $b): int => ($b->occurredAt?->getTimestamp() ?? 0) <=> ($a->occurredAt?->getTimestamp() ?? 0));

        $dates = [];

        foreach (Payload::items($data, 'dateAndTimes') as $date) {
            $type = Payload::string($date, 'type');

            if ($type !== null) {
                $dates[$type] = TrackingEvent::parseDate(Payload::string($date, 'dateTime'));
            }
        }

        $code = Payload::string($data, 'latestStatusDetail.derivedCode') ?? Payload::string($data, 'latestStatusDetail.code');
        $error = Payload::array($data, 'error');

        return new self(
            trackingNumber: Payload::string($data, 'trackingNumberInfo.trackingNumber') ?? $trackingNumber,
            status: TrackingStatus::fromCode($code),
            statusCode: $code,
            statusDescription: Payload::string($data, 'latestStatusDetail.statusByLocale') ?? Payload::string($data, 'latestStatusDetail.description'),
            serviceName: Payload::string($data, 'serviceDetail.description'),
            deliveredAt: $dates['ACTUAL_DELIVERY'] ?? null,
            estimatedDelivery: $dates['ESTIMATED_DELIVERY'] ?? TrackingEvent::parseDate(Payload::string($data, 'estimatedDeliveryTimeWindow.window.ends')),
            receivedBy: Payload::string($data, 'deliveryDetails.receivedByName') ?? Payload::string($data, 'deliveryDetails.signedByName'),
            events: $events,
            error: $error !== [] ? ['code' => Payload::string($error, 'code'), 'message' => Payload::string($error, 'message') ?? 'Tracking failed'] : null,
            raw: $data,
        );
    }

    public function isDelivered(): bool
    {
        return $this->status === TrackingStatus::Delivered;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function latestEvent(): ?TrackingEvent
    {
        return $this->events[0] ?? null;
    }
}
