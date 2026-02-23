<?php

namespace App\Commands;

use App\Concerns\RendersBanner;
use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    use RendersBanner;

    protected $signature = 'login';

    protected $description = 'Store your Flare API token for authentication';

    public function handle(CredentialStore $credentials): int
    {
        $this->renderBanner($this->output);

        $this->line('You can generate a token at <href=https://flareapp.io/account/api-tokens>https://flareapp.io/account/api-tokens</>');
        $this->newLine();

        $token = $this->secret('Enter your Flare API token');

        if (! $token) {
            $this->error('No token provided.');

            return self::FAILURE;
        }

        try {
            $response = Http::withToken($token)->get('https://flareapp.io/api/me');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->error('Could not connect to Flare. Please check your internet connection.');

            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Invalid API token.');

            return self::FAILURE;
        }

        $credentials->setToken($token);

        $email = $response->json('email', 'unknown');

        $this->newLine();
        $this->info("  🎉 Successfully logged in as {$email}  ");
        $this->newLine();
        $this->line('The Flare CLI comes with a Claude Code skill that allows Claude to manage your errors and performance data.');
        $this->line('Publish it with: <comment>claude skill install spatie/flare-cli</comment>');
        $this->newLine();
        $this->line('Learn more: <href=https://flareapp.io/docs/flare/general/using-the-cli>https://flareapp.io/docs/flare/general/using-the-cli</>');

        return self::SUCCESS;
    }
}
