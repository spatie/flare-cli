<?php

use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

    $this->store = new CredentialStore;
    $this->app->instance(CredentialStore::class, $this->store);
});

afterEach(function () {
    $configFile = $this->tempDir.'/.flare/config.json';
    if (file_exists($configFile)) {
        unlink($configFile);
    }
    if (is_dir($this->tempDir.'/.flare')) {
        rmdir($this->tempDir.'/.flare');
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('stores credentials on successful login', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response([
            'data' => ['name' => 'Alex'],
        ]),
    ]);

    $this->artisan('login')
        ->expectsQuestion('Enter your Flare API token', 'valid-token-123')
        ->expectsOutput('Logged in as Alex')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBe('valid-token-123');
});

it('shows error and does not store token on invalid token', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->artisan('login')
        ->expectsQuestion('Enter your Flare API token', 'invalid-token')
        ->expectsOutput('Invalid API token.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});

it('shows connection error on network failure', function () {
    Http::fake([
        'flareapp.io/api/me' => function () {
            throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
        },
    ]);

    $this->artisan('login')
        ->expectsQuestion('Enter your Flare API token', 'some-token')
        ->expectsOutput('Could not connect to Flare. Please check your internet connection.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});
