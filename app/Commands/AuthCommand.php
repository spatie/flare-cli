<?php

namespace App\Commands;

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use LaravelZero\Framework\Commands\Command;

class AuthCommand extends Command
{
    protected $signature = 'auth';

    protected $description = 'Show the active Flare authentication context';

    public function handle(CredentialStore $credentials, FlareUrlResolver $urlResolver): int
    {
        $profiles = $credentials->getConfiguredProfiles();
        $apiBaseUrl = $urlResolver->getApiBaseUrl();
        $activeProfile = array_find(
            $profiles,
            fn (array $profile): bool => $profile['active'] && $profile['api_base_url'] === $apiBaseUrl,
        );

        $this->line("Active API base URL: {$apiBaseUrl}");
        $this->line('Active context: '.($activeProfile !== null ? 'configured' : 'missing'));

        if ($activeProfile !== null) {
            $this->line('Credential type: '.$activeProfile['type']);
            $this->line('OAuth issuer: '.($activeProfile['issuer'] ?? 'not applicable'));
        } else {
            $this->line("Run `flare login` to authenticate against {$apiBaseUrl}.");
        }

        $this->newLine();
        $this->line('Stored auth contexts:');

        if ($profiles === []) {
            $this->line('- none');

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            $suffix = $profile['active'] ? ' (active)' : '';
            $issuer = $profile['issuer'] !== null ? ", issuer {$profile['issuer']}" : '';

            $this->line("- {$profile['api_base_url']} ({$profile['type']}{$issuer}){$suffix}");
        }

        return self::SUCCESS;
    }
}
