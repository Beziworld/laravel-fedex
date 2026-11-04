<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

/**
 * Monetary amount as a decimal string with its ISO 4217 currency.
 */
final readonly class Money
{
    public function __construct(
        public string $amount,
        public string $currency,
    ) {}

    public static function of(float|int|string $amount, string $currency): self
    {
        return new self(number_format((float) $amount, 2, '.', ''), strtoupper($currency));
    }

    /**
     * Reads a FedEx {amount, currency} object.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromFedex(array $data): ?self
    {
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;

        return is_numeric($amount) && is_string($currency) && $currency !== '' ? self::of($amount, $currency) : null;
    }

    /**
     * @return array{amount: float, currency: string}
     */
    public function toFedex(): array
    {
        return ['amount' => (float) $this->amount, 'currency' => $this->currency];
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }
}
