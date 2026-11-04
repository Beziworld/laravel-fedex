<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Events;

use Beziworld\Fedex\Data\ShipmentResult;

/**
 * Dispatched after a shipment has been created through the given connection.
 */
final readonly class ShipmentCreated
{
    public function __construct(
        public string $connection,
        public ShipmentResult $shipment,
    ) {}
}
