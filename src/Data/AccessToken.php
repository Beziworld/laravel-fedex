<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Carbon\CarbonImmutable;

/**
 * OAuth2 bearer token issued by the client credentials grant.
 */
final readonly class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $accessToken,
        public CarbonImmutable $expiresAt,
    ) {}

    /**
     * @param array<array-key, mixed> $data Token endpoint response or a cached token.
     */
    public static function fromArray(array $data): self
    {
        $expiresAt = match (true) {
            is_string($data['expires_at'] ?? null) => CarbonImmutable::parse($data['expires_at']),
            is_numeric($data['expires_in'] ?? null) => CarbonImmutable::now()->addSeconds((int) $data['expires_in']),
            default => CarbonImmutable::now()->addHour(),
        };

        return new self(is_string($data['access_token'] ?? null) ? $data['access_token'] : '', $expiresAt);
    }

    /**
     * @return array{access_token: string, expires_at: string}
     */
    public function toArray(): array
    {
        return ['access_token' => $this->accessToken, 'expires_at' => $this->expiresAt->toIso8601String()];
    }

    public function isValid(int $marginSeconds = 120): bool
    {
        return $this->accessToken !== '' && $this->expiresAt->isAfter(CarbonImmutable::now()->addSeconds($marginSeconds));
    }
}
