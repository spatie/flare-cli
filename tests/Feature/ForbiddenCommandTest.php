<?php

use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

    $this->store = new CredentialStore;
    $this->store->setToken('test-api-token-123');
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

it('suggests re-login when the token is missing a scope or grant', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response(['message' => "Token is missing the 'write' scope."], 403),
    ]);

    $this->artisan('list-projects')
        ->expectsOutputToContain("Token is missing the 'write' scope.")
        ->expectsOutputToContain('Run `flare login` to re-authenticate')
        ->assertExitCode(1);
});

it('does not suggest re-login for other permission errors', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response(['message' => 'This action is unauthorized.'], 403),
    ]);

    $this->artisan('list-projects')
        ->doesntExpectOutputToContain('Run `flare login` to re-authenticate')
        ->assertExitCode(1);
});
