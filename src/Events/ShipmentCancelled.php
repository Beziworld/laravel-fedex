<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Events;

use Beziworld\Fedex\Data\CancelResult;

/**
 * Dispatched after a shipment cancellation has been sent through the given connection.
 */
final readonly class ShipmentCancelled
{
    public function __construct(
        public string $connection,
        public CancelResult $result,
    ) {}
}
