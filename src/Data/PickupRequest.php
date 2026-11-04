<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use DateTimeInterface;

/**
 * Courier pickup request.
 */
final readonly class PickupRequest
{
    /**
     * @param DateTimeInterface $readyAt When the packages are ready, with its time zone.
     * @param string $closeTime Local closing time of the pickup location, HH:MM.
     * @param string $carrier FDXE (FedEx Express) or FDXG (FedEx Ground).
     * @param string $packageLocation FRONT, REAR, SIDE or NONE.
     */
    public function __construct(
        public DateTimeInterface $readyAt,
        public string $closeTime,
        public string $carrier = 'FDXE',
        public ?Party $address = null,
        public ?int $packageCount = null,
        public ?float $totalWeight = null,
        public string $packageLocation = 'NONE',
        public bool $international = false,
        public ?string $remarks = null,
        public ?string $commodityDescription = null,
    ) {}
}
