<?php

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\TokenRecord;
use Illuminate\Support\Facades\Http;

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

function logoutRecord(
    string $apiBaseUrl = 'https://flareapp.io/api',
    string $workstation = '',
): TokenRecord {
    $resolver = new FlareUrlResolver($apiBaseUrl);
    $identifier = md5($apiBaseUrl.$workstation);

    return TokenRecord::fromArray([
        'access_token' => "access-{$identifier}",
        'refresh_token' => "refresh-{$identifier}",
        'expires_at' => time() + 99999,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => time(),
        'issuer' => rtrim($resolver->getAppUrl(), '/'),
        'api_base_url' => $resolver->getApiBaseUrl(),
    ]);
}

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
        ->expectsOutput('Logged out of every configured Flare profile successfully.')
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

it('revokes the refresh token before removing an OAuth profile', function () {
    $this->store->setRecord(logoutRecord());

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response(),
    ]);

    $this->artisan('logout')
        ->expectsOutputToContain('Revoked and removed https://flareapp.io/api.')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBeNull();

    Http::assertSent(fn ($request) => $request->url() === 'https://flareapp.io/oauth/revoke'
        && $request['token'] === 'refresh-'.md5('https://flareapp.io/api')
        && $request['token_type_hint'] === 'refresh_token');
});

it('retains OAuth credentials after an unconfirmed remote failure', function () {
    $this->store->setRecord(logoutRecord());

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response(['error' => 'server_error'], 500),
    ]);

    $this->artisan('logout')
        ->expectsConfirmation('Remove this credential locally anyway?', 'no')
        ->expectsOutputToContain('Local credentials were retained.')
        ->assertExitCode(1);

    expect($this->store->getRecord())->not->toBeNull();
});

it('does not send a refresh token when the discovered issuer changed', function () {
    $record = logoutRecord()->withProfile('https://unexpected-issuer.test', 'https://flareapp.io/api');
    $this->store->setRecord($record);

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
    ]);

    $this->artisan('logout')
        ->expectsOutputToContain('does not match the stored profile issuer')
        ->expectsConfirmation('Remove this credential locally anyway?', 'no')
        ->assertExitCode(1);

    Http::assertNotSent(
        fn ($request) => $request->url() === 'https://flareapp.io/oauth/revoke',
    );
    expect($this->store->getRecord())->not->toBeNull();
});

it('removes OAuth credentials after confirmed local fallback', function () {
    $this->store->setRecord(logoutRecord());

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response([], 503),
    ]);

    $this->artisan('logout')
        ->expectsConfirmation('Remove this credential locally anyway?', 'yes')
        ->expectsOutputToContain('remote OAuth connections may still be active')
        ->assertExitCode(0);

    expect($this->store->getRecord())->toBeNull();
});

it('retains failed OAuth credentials during non-interactive logout', function () {
    $this->store->setRecord(logoutRecord());

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response([], 503),
    ]);

    $this->artisan('logout --no-interaction')
        ->expectsOutputToContain('Run `flare logout --local-only`')
        ->assertExitCode(1);

    expect($this->store->getRecord())->not->toBeNull();
});

it('supports local-only OAuth cleanup without making HTTP requests', function () {
    $this->store->setRecord(logoutRecord());
    Http::fake();

    $this->artisan('logout --local-only')
        ->expectsOutputToContain('without remote revocation')
        ->assertExitCode(0);

    expect($this->store->getRecord())->toBeNull();
    Http::assertNothingSent();
});

it('best-effort revokes all profiles and asks once for failures', function () {
    $this->store->setRecord(logoutRecord());

    putenv('FLARE_BASE_URL=https://staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setRecord(logoutRecord('https://staging.flareapp.io/api'));
    $this->app->instance(CredentialStore::class, $stagingStore);

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://staging.flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://staging.flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response(),
        'https://staging.flareapp.io/oauth/revoke' => Http::response([], 503),
    ]);

    $this->artisan('logout --all')
        ->expectsConfirmation('Remove this credential locally anyway?', 'yes')
        ->assertExitCode(0);

    expect($stagingStore->getConfiguredProfiles())->toBe([]);
    Http::assertSentCount(4);
});

it('revokes one workstation without affecting another workstation profile', function () {
    $firstStore = $this->store;
    $firstStore->setRecord(logoutRecord(workstation: 'laptop'));

    $secondHome = "{$this->tempDir}/second-workstation";
    mkdir($secondHome, 0755, true);
    $_SERVER['HOME'] = $secondHome;

    $secondStore = new CredentialStore(new FlareUrlResolver);
    $secondStore->setRecord(logoutRecord(workstation: 'desktop'));

    $_SERVER['HOME'] = $this->tempDir;
    $this->app->instance(CredentialStore::class, $firstStore);

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/revoke' => Http::response(),
    ]);

    $this->artisan('logout')->assertExitCode(0);

    expect($firstStore->getRecord())->toBeNull();
    expect($secondStore->getRecord()?->refreshToken)
        ->toBe('refresh-'.md5('https://flareapp.io/apidesktop'));

    cleanupFlareHome($secondHome);
});
