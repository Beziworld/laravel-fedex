<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Enums\TrackingStatus;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * One scan event of a package; occurredAt keeps the UTC offset sent by FedEx.
 */
final readonly class TrackingEvent
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?CarbonImmutable $occurredAt,
        public ?string $eventType,
        public ?string $statusCode,
        public ?string $description,
        public ?string $exceptionCode,
        public ?string $exceptionDescription,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $locationType,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data A scanEvents item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            occurredAt: self::parseDate(Payload::string($data, 'date')),
            eventType: Payload::string($data, 'eventType'),
            statusCode: Payload::string($data, 'derivedStatusCode'),
            description: Payload::string($data, 'eventDescription') ?? Payload::string($data, 'derivedStatus'),
            exceptionCode: Payload::string($data, 'exceptionCode'),
            exceptionDescription: Payload::string($data, 'exceptionDescription'),
            city: Payload::string($data, 'scanLocation.city'),
            stateCode: Payload::string($data, 'scanLocation.stateOrProvinceCode'),
            postalCode: Payload::string($data, 'scanLocation.postalCode'),
            countryCode: Payload::string($data, 'scanLocation.countryCode'),
            locationType: Payload::string($data, 'locationType'),
            raw: $data,
        );
    }

    public function status(): TrackingStatus
    {
        return TrackingStatus::fromCode($this->statusCode ?? $this->eventType);
    }

    public function isDelivered(): bool
    {
        return $this->status() === TrackingStatus::Delivered;
    }

    public static function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
