<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

/**
 * One commodity of an international shipment.
 */
final readonly class CustomsItem
{
    /**
     * @param string $unitValue Price of a single unit, as a decimal string, in the customs currency.
     * @param float $weight Total weight of the commodity line in the connection unit system.
     * @param string $unit Quantity units, e.g. PCS.
     */
    public function __construct(
        public string $description,
        public int $quantity,
        public string $unitValue,
        public float $weight,
        public string $originCountry,
        public ?string $hsCode = null,
        public ?string $partNumber = null,
        public string $unit = 'PCS',
    ) {}

    public function customsValue(): float
    {
        return round($this->quantity * (float) $this->unitValue, 2);
    }
}
