<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Data;

use Beziworld\Fedex\Exceptions\ApiException;
use Beziworld\Fedex\Support\Payload;

/**
 * Label or shipping document returned by the Ship API.
 *
 * content holds the decoded file when labels were requested inline
 * (labelResponseOptions LABEL); url is set when they were requested as
 * links (URL_ONLY), which FedEx keeps for 12 hours.
 */
final readonly class Label
{
    public function __construct(
        public ?string $trackingNumber,
        public string $type,
        public string $format,
        public ?string $content,
        public ?string $url = null,
    ) {}

    /**
     * @param array<array-key, mixed> $data A packageDocuments or shipmentDocuments item.
     *
     * @throws ApiException When the encoded document is not valid base64.
     */
    public static function fromArray(array $data, ?string $trackingNumber = null): self
    {
        $encoded = Payload::string($data, 'encodedLabel');

        return new self(
            trackingNumber: Payload::string($data, 'trackingNumber') ?? $trackingNumber,
            type: Payload::string($data, 'contentType') ?? 'LABEL',
            format: strtoupper(Payload::string($data, 'docType') ?? 'PDF'),
            content: $encoded !== null ? (Payload::decode($encoded) ?? throw ApiException::malformed('document is not valid base64')) : null,
            url: Payload::string($data, 'url'),
        );
    }

    public function extension(): string
    {
        return match ($this->format) {
            'ZPLII' => 'zpl',
            'EPL2' => 'epl',
            default => strtolower($this->format),
        };
    }

    public function base64(): ?string
    {
        return $this->content !== null ? base64_encode($this->content) : null;
    }
}
