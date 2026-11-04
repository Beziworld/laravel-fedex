<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Exceptions;

/**
 * Raised when the FedEx OAuth endpoint does not issue an access token.
 */
final class AuthenticationException extends FedexException
{
    /**
     * @param list<array{code: ?string, message: string}> $errors
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    /**
     * @param list<array{code: ?string, message: string}> $errors
     */
    public static function rejected(int $status, array $errors): self
    {
        $summary = implode('; ', array_map(static fn(array $error): string => ($error['code'] !== null ? $error['code'] . ' ' : '') . $error['message'], $errors));

        return new self(sprintf('FedEx OAuth authentication failed (HTTP %d)%s', $status, $summary !== '' ? ': ' . $summary : '.'), $status, $errors);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return list<array{code: ?string, message: string}>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
