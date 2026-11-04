<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Exceptions;

/**
 * Raised when a tracking webhook carries no signature or a signature that does not match the payload.
 */
final class WebhookSignatureException extends FedexException
{
    public static function missing(): self
    {
        return new self('The FedEx webhook request has no fdx-signature header.');
    }

    public static function invalid(): self
    {
        return new self('The FedEx webhook signature does not match the payload.');
    }
}
