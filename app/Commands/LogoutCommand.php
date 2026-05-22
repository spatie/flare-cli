<?php

namespace App\Commands;

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use LaravelZero\Framework\Commands\Command;

class LogoutCommand extends Command
{
    protected $signature = 'logout {--all : Remove credentials for every configured host}';

    protected $description = 'Clear your stored Flare credentials';

    public function handle(CredentialStore $credentials, FlareUrlResolver $urlResolver): int
    {
        if ($this->option('all')) {
            $hosts = $credentials->getConfiguredHosts();

            if ($hosts === []) {
                $this->info('No stored credentials to remove.');

                return self::SUCCESS;
            }

            $credentials->flushAll();

            $this->info('Removed credentials for: '.implode(', ', $hosts).'.');

            return self::SUCCESS;
        }

        $credentials->flush();

        $this->info("Logged out of {$urlResolver->getHostKey()} successfully.");

        return self::SUCCESS;
    }
}
