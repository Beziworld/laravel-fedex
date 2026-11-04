<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Enums\TrackingStatus;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * Tracking event pushed by a FedEx shipment visibility webhook.
 *
 * The webhook payload differs from the Track API response: the current
 * event is in scanEvent and the package in trackResult.trackingInfo.
 */
final readonly class WebhookEvent
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $trackingNumber,
        public ?string $eventCode,
        public ?string $description,
        public ?CarbonImmutable $occurredAt,
        public ?string $city,
        public ?string $countryCode,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data Decoded webhook body.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            trackingNumber: Payload::string($data, 'trackResult.trackingInfo.currentTrackingInfo.trackingNumber'),
            eventCode: Payload::string($data, 'scanEvent.eventCode'),
            description: Payload::string($data, 'scanEvent.eventDescription'),
            occurredAt: TrackingEvent::parseDate(Payload::string($data, 'scanEvent.eventCreateTime')),
            city: Payload::string($data, 'scanEvent.location.locationContactAndAddress.address.city'),
            countryCode: Payload::string($data, 'scanEvent.location.locationContactAndAddress.address.countryCode'),
            raw: $data,
        );
    }

    public function status(): TrackingStatus
    {
        return TrackingStatus::fromCode($this->eventCode);
    }
}
