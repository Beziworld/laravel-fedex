<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Console;

use Beziworld\Fedex\Exceptions\FedexException;
use Beziworld\Fedex\FedexManager;
use Illuminate\Console\Command;

/**
 * Verifies the OAuth credentials of one or every configured connection without side effects.
 */
final class CheckCommand extends Command
{
    /** @var string */
    protected $signature = 'fedex:check {connection? : Connection name; every connection when omitted}';

    /** @var string */
    protected $description = 'Request a FedEx OAuth token for the configured connections';

    public function handle(FedexManager $manager): int
    {
        $argument = $this->argument('connection');
        $names = is_string($argument) && $argument !== '' ? [$argument] : $manager->connectionNames();
        $failed = false;

        foreach ($names as $name) {
            try {
                $client = $manager->connection($name);
                $client->forgetToken();
                $client->accessToken();
                $this->components->info(sprintf('%s: token issued by %s.', $name, $client->config()->baseUrl()));
            } catch (FedexException $exception) {
                $failed = true;
                $this->components->error(sprintf('%s: %s', $name, $exception->getMessage()));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
