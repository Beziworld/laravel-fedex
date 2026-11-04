<?php

declare(strict_types=1);

namespace Beziworld\Fedex;

use Beziworld\Fedex\Console\CheckCommand;
use Beziworld\Fedex\Exceptions\InvalidConfiguration;
use Beziworld\Fedex\Webhooks\SignatureVerifier;
use Beziworld\Fedex\Webhooks\WebhookController;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the FedEx connection manager, the default client and the optional tracking webhook route.
 */
final class FedexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/fedex.php', 'fedex');

        $this->app->singleton(FedexManager::class, static fn(Application $app): FedexManager => new FedexManager(
            $app->make(HttpFactory::class),
            $app->make(Config::class),
            $app->make(CacheFactory::class),
            $app->bound(Dispatcher::class) ? $app->make(Dispatcher::class) : null,
        ));

        $this->app->bind(FedexClient::class, static fn(Application $app): FedexClient => $app->make(FedexManager::class)->connection());
        $this->app->bind(FedexConfig::class, static fn(Application $app): FedexConfig => $app->make(FedexClient::class)->config());

        $this->app->bind(SignatureVerifier::class, static function (Application $app): SignatureVerifier {
            $token = $app->make(Config::class)->get('fedex.webhook.security_token');

            return new SignatureVerifier(is_string($token) && $token !== '' ? $token : throw InvalidConfiguration::missing('webhook security token', 'FEDEX_WEBHOOK_TOKEN'));
        });

        $this->app->bind(WebhookController::class, static function (Application $app): WebhookController {
            $connection = $app->make(Config::class)->get('fedex.webhook.connection');

            return new WebhookController($app->make(SignatureVerifier::class), $app->make(Dispatcher::class), is_string($connection) && $connection !== '' ? $connection : 'default');
        });
    }

    public function boot(): void
    {
        $config = $this->app->make(Config::class);

        if (filter_var($config->get('fedex.webhook.enabled', false), FILTER_VALIDATE_BOOL) && $this->app->bound('router')) {
            $path = $config->get('fedex.webhook.path');
            $middleware = $config->get('fedex.webhook.middleware', []);

            /** @var Router $router */
            $router = $this->app->make('router');
            $router->post(is_string($path) && $path !== '' ? $path : 'fedex/webhook', WebhookController::class)
                ->middleware(is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : [])
                ->name('fedex.webhook');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/fedex.php' => $this->app->configPath('fedex.php')], 'fedex-config');

            $this->commands([CheckCommand::class]);
        }
    }
}
