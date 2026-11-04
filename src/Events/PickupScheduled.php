<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Events;

use Beziworld\Fedex\Data\PickupConfirmation;

/**
 * Dispatched after a courier pickup has been scheduled through the given connection.
 */
final readonly class PickupScheduled
{
    public function __construct(
        public string $connection,
        public PickupConfirmation $pickup,
    ) {}
}
