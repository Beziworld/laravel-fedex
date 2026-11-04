<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Events;

use Beziworld\Fedex\Data\WebhookEvent;

/**
 * Dispatched after a FedEx tracking webhook with a valid signature has been received; connection is the webhook connection name.
 */
final readonly class TrackingWebhookReceived
{
    public function __construct(
        public string $connection,
        public WebhookEvent $event,
    ) {}
}
