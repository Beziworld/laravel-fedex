<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;

/**
 * Pickup availability for one carrier and day.
 */
final readonly class PickupOption
{
    /**
     * @param list<string> $readyTimes
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $carrier,
        public bool $available,
        public ?string $pickupDate,
        public ?string $cutOffTime,
        public array $readyTimes,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data An options item.
     */
    public static function fromArray(array $data): self
    {
        $times = [];

        foreach (Payload::array($data, 'readyTimeOptions') as $time) {
            if (is_scalar($time)) {
                $times[] = (string) $time;
            }
        }

        return new self(
            carrier: Payload::string($data, 'carrier'),
            available: ($data['available'] ?? false) === true,
            pickupDate: Payload::string($data, 'pickupDate'),
            cutOffTime: Payload::string($data, 'cutOffTime'),
            readyTimes: $times,
            raw: $data,
        );
    }
}
