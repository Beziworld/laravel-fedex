<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Support\Payload;
use Carbon\CarbonImmutable;

/**
 * Scheduled pickup; keep the confirmation code, date, carrier and location to cancel it.
 */
final readonly class PickupConfirmation
{
    /**
     * @param list<string> $alerts
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $confirmationCode,
        public ?string $location,
        public string $carrier,
        public CarbonImmutable $scheduledDate,
        public array $alerts,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $carrier, CarbonImmutable $scheduledDate): self
    {
        $output = Payload::array($data, 'output');

        return new self(
            confirmationCode: Payload::string($output, 'pickupConfirmationCode') ?? throw ApiException::malformed('pickupConfirmationCode is missing'),
            location: Payload::string($output, 'location'),
            carrier: $carrier,
            scheduledDate: $scheduledDate,
            alerts: ShipmentResult::alerts(Payload::items($output, 'alerts')),
            raw: $data,
        );
    }
}
