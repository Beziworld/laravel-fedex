<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Support\Payload;

/**
 * Outcome of a shipment cancellation.
 */
final readonly class CancelResult
{
    /**
     * @param list<string> $alerts
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $trackingNumber,
        public bool $cancelled,
        public ?string $message,
        public array $alerts,
        public array $raw,
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(string $trackingNumber, array $data): self
    {
        $output = Payload::array($data, 'output');

        return new self(
            trackingNumber: $trackingNumber,
            cancelled: ($output['cancelledShipment'] ?? false) === true,
            message: Payload::string($output, 'message'),
            alerts: ShipmentResult::alerts(Payload::items($output, 'alerts')),
            raw: $data,
        );
    }
}
