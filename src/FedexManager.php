<?php

declare(strict_types=1);

namespace Beziworld\Fedex;

use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Resolves FedEx clients for any number of accounts.
 *
 * Named connections are defined in config/fedex.php in the same way as
 * database connections; options outside "connections" are shared defaults.
 * Clients for credentials stored at runtime (for example per tenant) are
 * created with using(). Calls made directly on the manager go to the
 * default connection.
 *
 * @mixin FedexClient
 */
final class FedexManager
{
    /** @var array<string, FedexClient> */
    private array $clients = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Config $config,
        private readonly CacheFactory $cache,
        private readonly ?Dispatcher $events = null,
    ) {}

    /**
     * Client of a configured connection; the default connection when no name is given.
     *
     * @throws InvalidConfiguration When the connection is not defined or incomplete.
     */
    public function connection(?string $name = null): FedexClient
    {
        $name ??= $this->getDefaultConnection();

        return $this->clients[$name] ??= $this->make($this->configFor($name), $name);
    }

    /**
     * Client for credentials provided at runtime, merged with the shared options.
     *
     * @param FedexConfig|array<string, mixed> $config Keys as in a connection: client_id, client_secret, account_number, shipper, ...
     */
    public function using(FedexConfig|array $config, string $name = 'runtime'): FedexClient
    {
        return $this->make($config instanceof FedexConfig ? $config : FedexConfig::fromArray(self::merge($this->shared(), $config)), $name);
    }

    public function getDefaultConnection(): string
    {
        $default = $this->config->get('fedex.default');

        return is_string($default) && $default !== '' ? $default : 'default';
    }

    /**
     * Names of the configured connections.
     *
     * @return list<string>
     */
    public function connectionNames(): array
    {
        $connections = $this->config->get('fedex.connections');

        return is_array($connections) && $connections !== [] ? array_map(strval(...), array_keys($connections)) : [$this->getDefaultConnection()];
    }

    /**
     * Forgets resolved clients, for example after the configuration changed.
     */
    public function purge(?string $name = null): void
    {
        if ($name === null) {
            $this->clients = [];

            return;
        }

        unset($this->clients[$name]);
    }

    /**
     * @param array<array-key, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $callable = [$this->connection(), $method];

        if (! is_callable($callable)) {
            throw new \BadMethodCallException(sprintf('Method %s::%s does not exist.', FedexClient::class, $method));
        }

        return $callable(...$arguments);
    }

    private function make(FedexConfig $config, string $name): FedexClient
    {
        return new FedexClient($config, $this->http, $this->cache->store($config->cacheStore), $this->events, $name);
    }

    private function configFor(string $name): FedexConfig
    {
        $connections = $this->config->get('fedex.connections');

        if (! is_array($connections) || $connections === []) {
            if ($name !== $this->getDefaultConnection()) {
                throw InvalidConfiguration::unknownConnection($name);
            }

            return FedexConfig::fromArray($this->shared());
        }

        $connection = $connections[$name] ?? null;

        if (! is_array($connection)) {
            throw InvalidConfiguration::unknownConnection($name);
        }

        return FedexConfig::fromArray(self::merge($this->shared(), $connection));
    }

    /**
     * Applies connection options over the shared ones; empty values (unset environment variables) do not override.
     *
     * @param array<string, mixed> $shared
     * @param array<array-key, mixed> $connection
     * @return array<string, mixed>
     */
    private static function merge(array $shared, array $connection): array
    {
        foreach ($connection as $key => $value) {
            if ($value !== null && $value !== '') {
                $shared[(string) $key] = $value;
            }
        }

        return $shared;
    }

    /**
     * Options shared by every connection: everything outside "default" and "connections".
     *
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $config = $this->config->get('fedex', []);
        $config = is_array($config) ? $config : [];
        unset($config['default'], $config['connections']);

        /** @var array<string, mixed> $config */
        return $config;
    }
}
