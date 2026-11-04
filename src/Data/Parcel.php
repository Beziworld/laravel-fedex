<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

/**
 * One package of a shipment.
 *
 * Weight and dimensions are expressed in the unit system of the connection
 * (kilograms and centimetres for "metric", pounds and inches for "imperial");
 * FedEx accepts whole numbers for dimensions, so they are rounded up.
 */
final readonly class Parcel
{
    public function __construct(
        public float $weight,
        public ?float $length = null,
        public ?float $width = null,
        public ?float $height = null,
        public ?string $description = null,
        public ?string $reference = null,
        public ?Money $declaredValue = null,
    ) {}

    public function hasDimensions(): bool
    {
        return $this->length !== null && $this->width !== null && $this->height !== null;
    }
}
