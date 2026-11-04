<?php

declare(strict_types=1);

namespace Beziworld\Fedex;

use Beziworld\Fedex\Data\Party;
use Beziworld\Fedex\Enums\LabelFormat;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\Support\Payload;

/**
 * Validated, immutable configuration of one FedEx connection (project and account).
 */
final readonly class FedexConfig
{
    public const string GRANT_CLIENT = 'client_credentials';

    public const string GRANT_CSP = 'csp_credentials';

    public const string GRANT_PARENT_CHILD = 'client_pc_credentials';

    public const string UNITS_METRIC = 'metric';

    public const string UNITS_IMPERIAL = 'imperial';

    public function __construct(
        public string $clientId,
        #[\SensitiveParameter]
        public string $clientSecret,
        public ?string $accountNumber = null,
        public bool $sandbox = false,
        public string $grantType = self::GRANT_CLIENT,
        public ?string $childKey = null,
        #[\SensitiveParameter]
        public ?string $childSecret = null,
        public ?Party $shipper = null,
        public string $units = self::UNITS_METRIC,
        public LabelFormat $labelFormat = LabelFormat::Pdf,
        public ?string $labelStock = null,
        public string $locale = 'en_US',
        public int $timeout = 30,
        public int $retries = 2,
        public int $retryDelayMs = 500,
        public ?string $cacheStore = null,
        public string $cachePrefix = 'fedex',
    ) {}

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidConfiguration When credentials or options are invalid.
     */
    public static function fromArray(array $config): self
    {
        $grant = Payload::string($config, 'grant_type') ?? self::GRANT_CLIENT;

        if (! in_array($grant, [self::GRANT_CLIENT, self::GRANT_CSP, self::GRANT_PARENT_CHILD], true)) {
            throw InvalidConfiguration::invalid(sprintf('Unknown FedEx grant type "%s"; use client_credentials, csp_credentials or client_pc_credentials.', $grant));
        }

        if ($grant !== self::GRANT_CLIENT && (Payload::string($config, 'child_key') === null || Payload::string($config, 'child_secret') === null)) {
            throw InvalidConfiguration::missing('child key and secret', 'FEDEX_CHILD_KEY and FEDEX_CHILD_SECRET');
        }

        $units = Payload::string($config, 'units') ?? self::UNITS_METRIC;

        if (! in_array($units, [self::UNITS_METRIC, self::UNITS_IMPERIAL], true)) {
            throw InvalidConfiguration::invalid(sprintf('FedEx units must be "metric" or "imperial", "%s" given.', $units));
        }

        $format = Payload::string($config, 'label_format') ?? LabelFormat::Pdf->value;
        $labelFormat = LabelFormat::tryFrom(strtoupper($format)) ?? match (strtoupper($format)) {
            'ZPL' => LabelFormat::Zpl,
            'EPL' => LabelFormat::Epl,
            default => throw InvalidConfiguration::invalid(sprintf('FedEx label format must be PDF, PNG, ZPLII or EPL2, "%s" given.', $format)),
        };

        $shipper = is_array($config['shipper'] ?? null) && Payload::string($config['shipper'], 'name') !== null ? Party::fromArray($config['shipper']) : null;
        $cache = is_array($config['cache'] ?? null) ? $config['cache'] : [];
        $int = static fn(string $key, int $default, int $min): int => is_numeric($config[$key] ?? null) ? max($min, (int) $config[$key]) : $default;

        return new self(
            clientId: Payload::string($config, 'client_id') ?? throw InvalidConfiguration::missing('client_id', 'FEDEX_CLIENT_ID'),
            clientSecret: Payload::string($config, 'client_secret') ?? throw InvalidConfiguration::missing('client_secret', 'FEDEX_CLIENT_SECRET'),
            accountNumber: Payload::string($config, 'account_number'),
            sandbox: filter_var($config['sandbox'] ?? false, FILTER_VALIDATE_BOOL),
            grantType: $grant,
            childKey: Payload::string($config, 'child_key'),
            childSecret: Payload::string($config, 'child_secret'),
            shipper: $shipper,
            units: $units,
            labelFormat: $labelFormat,
            labelStock: Payload::string($config, 'label_stock'),
            locale: Payload::string($config, 'locale') ?? 'en_US',
            timeout: $int('timeout', 30, 1),
            retries: $int('retries', 2, 0),
            retryDelayMs: $int('retry_delay', 500, 0),
            cacheStore: Payload::string($cache, 'store'),
            cachePrefix: Payload::string($cache, 'prefix') ?? 'fedex',
        );
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? 'https://apis-sandbox.fedex.com' : 'https://apis.fedex.com';
    }

    public function requireAccountNumber(): string
    {
        return $this->accountNumber ?? throw InvalidConfiguration::missing('account_number', 'FEDEX_ACCOUNT_NUMBER');
    }

    public function requireShipper(): Party
    {
        return $this->shipper ?? throw InvalidConfiguration::invalid('No FedEx shipper is configured: set FEDEX_SHIPPER_* in the environment or call shipper() on the builder.');
    }

    public function weightUnit(): string
    {
        return $this->units === self::UNITS_METRIC ? 'KG' : 'LB';
    }

    public function dimensionUnit(): string
    {
        return $this->units === self::UNITS_METRIC ? 'CM' : 'IN';
    }
}
