<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;

/**
 * Address as resolved by FedEx address validation.
 */
final readonly class ResolvedAddress
{
    /**
     * @param list<string> $streetLines
     * @param array<string, string> $attributes FedEx attributes such as Resolved, DPV, POBox ("true"/"false").
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public array $streetLines,
        public ?string $city,
        public ?string $stateCode,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?string $classification,
        public array $attributes,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data A resolvedAddresses item.
     */
    public static function fromArray(array $data): self
    {
        $lines = [];

        foreach (Payload::array($data, 'streetLinesToken') as $line) {
            if (is_scalar($line)) {
                $lines[] = (string) $line;
            }
        }

        $attributes = [];

        foreach (Payload::array($data, 'attributes') as $key => $value) {
            if (is_scalar($value)) {
                $attributes[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return new self(
            streetLines: $lines,
            city: Payload::string($data, 'city'),
            stateCode: Payload::string($data, 'stateOrProvinceCode'),
            postalCode: Payload::string($data, 'postalCode'),
            countryCode: Payload::string($data, 'countryCode'),
            classification: Payload::string($data, 'classification'),
            attributes: $attributes,
            raw: $data,
        );
    }

    public function isResolved(): bool
    {
        return strtolower($this->attributes['Resolved'] ?? '') === 'true';
    }

    public function isResidential(): bool
    {
        return $this->classification === 'RESIDENTIAL';
    }
}
