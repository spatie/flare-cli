<?php

namespace App\Commands;

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\OAuthRevoker;
use LaravelZero\Framework\Commands\Command;

class LogoutCommand extends Command
{
    protected $signature = 'logout
                            {--all : Remove credentials for every configured profile}
                            {--local-only : Remove local credentials without revoking OAuth connections}';

    protected $description = 'Revoke Flare OAuth connections and remove stored credentials';

    public function handle(
        CredentialStore $credentials,
        FlareUrlResolver $urlResolver,
        OAuthRevoker $revoker,
    ): int {
        $profiles = $credentials->getConfiguredProfiles();

        if ($profiles === []) {
            $this->info('No stored credentials to remove.');

            return self::SUCCESS;
        }

        if ($this->option('local-only')) {
            return $this->removeLocally($credentials, $urlResolver);
        }

        $oauthProfiles = $credentials->getOAuthProfiles();

        if (! $this->option('all')) {
            $oauthProfiles = array_values(array_filter(
                $oauthProfiles,
                fn (array $profile): bool => $profile['active'],
            ));
        }

        if ($this->option('all')) {
            $credentials->forgetNonOAuthProfiles();
        } elseif ($oauthProfiles === []) {
            $credentials->flush();
        }

        $failed = [];

        foreach ($oauthProfiles as $profile) {
            try {
                $revoker->revoke($profile['record'], $profile['api_base_url'], $profile['issuer']);
                $credentials->forgetProfile($profile['key']);
                $this->info("Revoked and removed {$profile['api_base_url']}.");
            } catch (OAuthException $exception) {
                $failed[] = $profile;
                $this->error("Could not revoke {$profile['api_base_url']}: {$exception->getMessage()}");
            }
        }

        if ($failed === []) {
            $this->reportSuccess($urlResolver);

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->line('Local credentials were retained. Run `flare logout --local-only` to remove them without server revocation.');

            return self::FAILURE;
        }

        $count = count($failed);
        $label = $count === 1 ? 'this credential' : "these {$count} credentials";

        if (! $this->confirm("Remove {$label} locally anyway?", false)) {
            $this->line('Local credentials were retained.');

            return self::FAILURE;
        }

        foreach ($failed as $profile) {
            $credentials->forgetProfile($profile['key']);
        }

        $this->warn('Removed local credentials, but one or more remote OAuth connections may still be active.');

        return self::SUCCESS;
    }

    private function removeLocally(CredentialStore $credentials, FlareUrlResolver $urlResolver): int
    {
        if ($this->option('all')) {
            $credentials->flushAll();
            $this->info('Removed all local Flare credentials without remote revocation.');

            return self::SUCCESS;
        }

        $credentials->flush();
        $this->info("Removed local credentials for {$urlResolver->getApiBaseUrl()} without remote revocation.");

        return self::SUCCESS;
    }

    private function reportSuccess(FlareUrlResolver $urlResolver): void
    {
        if ($this->option('all')) {
            $this->info('Logged out of every configured Flare profile successfully.');

            return;
        }

        $this->info("Logged out of {$urlResolver->getApiBaseUrl()} successfully.");
    }
}
