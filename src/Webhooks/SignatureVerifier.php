<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Webhooks;

use Beziworld\Fedex\Exceptions\WebhookSignatureException;

/**
 * Verifies the fdx-signature header of FedEx shipment visibility webhooks.
 *
 * FedEx signs the payload with HMAC-SHA256 using the security token chosen
 * when the webhook was created in the developer portal and sends the
 * Base64-encoded digest. The digest is computed over the raw request body.
 */
final class SignatureVerifier
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $securityToken,
    ) {}

    public function sign(string $payload): string
    {
        return base64_encode(hash_hmac('sha256', $payload, $this->securityToken, true));
    }

    public function isValid(string $payload, ?string $signature): bool
    {
        return $signature !== null && $signature !== '' && hash_equals($this->sign($payload), trim($signature));
    }

    /**
     * @throws WebhookSignatureException When the signature is missing or wrong.
     */
    public function verify(string $payload, ?string $signature): void
    {
        if ($signature === null || $signature === '') {
            throw WebhookSignatureException::missing();
        }

        if (! $this->isValid($payload, $signature)) {
            throw WebhookSignatureException::invalid();
        }
    }
}
