<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Tests;

use Beziworld\Fedex\FedexServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const string API = 'https://apis-sandbox.fedex.com';

    public const string WEBHOOK_TOKEN = 'Abcdefghijklmnopqrstuvwxyz0123';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [FedexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('fedex.retry_delay', 0);
        $app['config']->set('fedex.webhook.enabled', true);
        $app['config']->set('fedex.webhook.security_token', self::WEBHOOK_TOKEN);
        $app['config']->set('fedex.connections.default', [
            'client_id' => 'api-key',
            'client_secret' => 'secret-key',
            'account_number' => '740561073',
            'sandbox' => true,
            'shipper' => [
                'name' => 'Jan Nowak',
                'company' => 'ACME Sp. z o.o.',
                'phone' => '500600700',
                'email' => 'shop@example.com',
                'address' => 'ul. Prosta 1',
                'city' => 'Warszawa',
                'postal_code' => '00-001',
                'country_code' => 'PL',
                'tax_id' => 'PL5250000000',
                'tax_id_type' => 'BUSINESS_UNION',
            ],
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'), true);

        return is_array($data) ? $data : [];
    }

    /**
     * Fakes the OAuth endpoint together with the given API stubs.
     *
     * @param array<string, mixed> $stubs
     */
    protected function fakeFedex(array $stubs = []): void
    {
        Http::fake([self::API . '/oauth/token' => Http::response(['access_token' => 'token-1', 'token_type' => 'bearer', 'expires_in' => 3599, 'scope' => 'CXS'])] + $stubs);
    }
}
