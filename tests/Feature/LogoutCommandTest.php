<?php

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    $this->store = new CredentialStore(new FlareUrlResolver);
    $this->app->instance(CredentialStore::class, $this->store);
});

afterEach(function () {
    if ($this->originalBaseUrl === null) {
        putenv('FLARE_BASE_URL');
        unset($_SERVER['FLARE_BASE_URL']);
    } else {
        putenv("FLARE_BASE_URL={$this->originalBaseUrl}");
        $_SERVER['FLARE_BASE_URL'] = $this->originalBaseUrl;
    }

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

it('clears stored credentials and shows confirmation', function () {
    $this->store->setToken('existing-token');

    $this->artisan('logout')
        ->expectsOutputToContain('flareapp.io')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBeNull();
});

it('only clears the active host credentials', function () {
    $this->store->setToken('production-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setToken('staging-token');
    $this->app->instance(CredentialStore::class, $stagingStore);

    $this->artisan('logout')
        ->expectsOutputToContain('staging.flareapp.io')
        ->assertExitCode(0);

    expect($stagingStore->getToken())->toBeNull();

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    expect((new CredentialStore(new FlareUrlResolver))->getToken())->toBe('production-token');
});

it('clears every stored host with --all', function () {
    $this->store->setToken('production-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setToken('staging-token');
    $this->app->instance(CredentialStore::class, $stagingStore);

    $this->artisan('logout --all')
        ->expectsOutput('Removed credentials for: flareapp.io, staging.flareapp.io.')
        ->assertExitCode(0);

    expect($stagingStore->getToken())->toBeNull();

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    expect((new CredentialStore(new FlareUrlResolver))->getToken())->toBeNull();
});

it('reports nothing to remove on logout --all when no credentials are stored', function () {
    $this->artisan('logout --all')
        ->expectsOutput('No stored credentials to remove.')
        ->assertExitCode(0);
});
