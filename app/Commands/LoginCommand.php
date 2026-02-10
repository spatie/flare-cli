<?php

namespace App\Commands;

use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    protected $signature = 'login';

    protected $description = 'Store your Flare API token for authentication';

    public function handle(CredentialStore $credentials): int
    {
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

        $name = $response->json('data.name', 'unknown');
        $this->info("Logged in as {$name}");

        return self::SUCCESS;
    }
}
