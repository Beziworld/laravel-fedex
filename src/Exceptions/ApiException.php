<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Exceptions;

use Throwable;

/**
 * Raised when FedEx rejects a request, returns an unexpected document or cannot be reached.
 *
 * errors() lists the {code, message} pairs of the FedEx error envelope
 * ({"transactionId": ..., "errors": [...]}); errorCode() returns the first
 * code and transactionId() the FedEx transaction id to quote to support.
 */
final class ApiException extends FedexException
{
    /**
     * @param list<array{code: ?string, message: string}> $errors
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $errors = [],
        private readonly ?int $retryAfter = null,
        private readonly ?string $transactionId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $httpStatus, ?int $retryAfter = null): self
    {
        $errors = self::parseErrors($payload);
        $summary = implode('; ', array_map(static fn(array $error): string => ($error['code'] !== null ? $error['code'] . ' ' : '') . $error['message'], $errors));

        return new self(
            sprintf('FedEx request failed (HTTP %d)%s', $httpStatus, $summary !== '' ? ': ' . $summary : '.'),
            $httpStatus,
            $errors,
            $retryAfter,
            is_scalar($payload['transactionId'] ?? null) ? (string) $payload['transactionId'] : null,
        );
    }

    /**
     * Error reported for a single tracking number inside a successful response.
     *
     * @param array<array-key, mixed> $error
     */
    public static function trackingError(string $trackingNumber, array $error): self
    {
        $code = is_scalar($error['code'] ?? null) ? (string) $error['code'] : null;
        $message = is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'Tracking failed';

        return new self(sprintf('FedEx tracking of %s failed: %s', $trackingNumber, trim(($code ?? '') . ' ' . $message)), str_contains((string) $code, 'NOTFOUND') ? 404 : null, [['code' => $code, 'message' => $message]]);
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to FedEx: ' . $previous->getMessage(), previous: $previous);
    }

    public static function malformed(string $what): self
    {
        return new self(sprintf('FedEx returned an unexpected response: %s.', $what));
    }

    /**
     * @param array<array-key, mixed> $payload
     * @return list<array{code: ?string, message: string}>
     */
    public static function parseErrors(array $payload): array
    {
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        $parsed = [];

        foreach ($errors as $error) {
            if (is_array($error)) {
                $parsed[] = [
                    'code' => is_scalar($error['code'] ?? null) ? (string) $error['code'] : null,
                    'message' => is_scalar($error['message'] ?? null) ? (string) $error['message'] : 'Unknown error',
                ];
            }
        }

        return $parsed;
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

    public function errorCode(): ?string
    {
        return $this->errors[0]['code'] ?? null;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404;
    }
}
