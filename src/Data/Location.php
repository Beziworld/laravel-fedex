<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;

/**
 * FedEx location (station, FedEx Office, authorised ship center, locker) from Location Search.
 */
final readonly class Location
{
    /**
     * @param list<string> $streetLines
     * @param list<array<array-key, mixed>> $hours storeHours entries as returned by FedEx.
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $name,
        public array $streetLines,
        public ?string $city,
        public ?string $postalCode,
        public ?string $countryCode,
        public ?float $latitude,
        public ?float $longitude,
        public ?float $distance,
        public ?string $distanceUnit,
        public array $hours,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data A locationDetailList item.
     */
    public static function fromArray(array $data): self
    {
        $number = static fn(mixed $value): ?float => is_numeric($value) ? (float) $value : null;
        $lines = [];

        foreach (Payload::array($data, 'contactAndAddress.address.streetLines') as $line) {
            if (is_scalar($line)) {
                $lines[] = (string) $line;
            }
        }

        return new self(
            id: Payload::string($data, 'locationId'),
            type: Payload::string($data, 'locationType'),
            name: Payload::string($data, 'contactAndAddress.contact.companyName') ?? Payload::string($data, 'contactAndAddress.contact.personName'),
            streetLines: $lines,
            city: Payload::string($data, 'contactAndAddress.address.city'),
            postalCode: Payload::string($data, 'contactAndAddress.address.postalCode'),
            countryCode: Payload::string($data, 'contactAndAddress.address.countryCode'),
            latitude: $number(Payload::get($data, 'geoPositionalCoordinates.latitude')),
            longitude: $number(Payload::get($data, 'geoPositionalCoordinates.longitude')),
            distance: $number(Payload::get($data, 'distance.value')),
            distanceUnit: Payload::string($data, 'distance.units'),
            hours: Payload::items($data, 'storeHours'),
            raw: $data,
        );
    }
}
