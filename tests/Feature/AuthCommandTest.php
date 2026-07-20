<?php

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\TokenRecord;
use Illuminate\Support\Facades\Artisan;

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

    cleanupFlareHome($this->tempDir);
});

it('shows the active context and all stored auth contexts without leaking tokens', function () {
    $this->store->setToken('production-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setToken('staging-token');
    $this->app->instance(CredentialStore::class, $stagingStore);

    Artisan::call('auth');

    $output = Artisan::output();

    expect($output)->toContain('https://staging.flareapp.io/api');
    expect($output)->toContain('staging.flareapp.io');
    expect($output)->toContain('configured');
    expect($output)->toContain('flareapp.io');
    expect($output)->not->toContain('production-token');
    expect($output)->not->toContain('staging-token');
});

it('reports when the active auth context is missing', function () {
    $this->store->setToken('production-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    Artisan::call('auth');

    $output = Artisan::output();

    expect($output)->toContain('missing');
    expect($output)->toContain('flareapp.io');
    expect($output)->toContain('staging.flareapp.io');
    expect($output)->toContain('Run `flare login` to authenticate against https://staging.flareapp.io/api.');
});

it('shows the active OAuth issuer and credential type', function () {
    $this->store->setRecord(TokenRecord::fromArray([
        'access_token' => 'secret-access',
        'refresh_token' => 'secret-refresh',
        'expires_at' => time() + 99999,
        'scopes' => ['read'],
        'client_id' => 'client-uuid',
        'obtained_at' => time(),
        'issuer' => 'https://flareapp.io',
        'api_base_url' => 'https://flareapp.io/api',
    ]));

    Artisan::call('auth');

    $output = Artisan::output();

    expect($output)->toContain('Credential type: oauth');
    expect($output)->toContain('OAuth issuer: https://flareapp.io');
    expect($output)->not->toContain('secret-access');
    expect($output)->not->toContain('secret-refresh');
});
