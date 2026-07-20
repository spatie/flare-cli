<?php

use App\Services\CredentialStore;
use App\Services\OAuth\TokenRecord;
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
    cleanupFlareHome($this->tempDir);
});

it('links to API access when the token is missing a scope or grant', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response(['message' => "Token is missing the 'write' scope."], 403),
    ]);

    $this->artisan('list-projects')
        ->expectsOutputToContain("Token is missing the 'write' scope.")
        ->expectsOutputToContain('https://flareapp.io/account/connected-apps')
        ->assertExitCode(1);
});

it('does not suggest re-login for other permission errors', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response(['message' => 'This action is unauthorized.'], 403),
    ]);

    $this->artisan('list-projects')
        ->doesntExpectOutputToContain('account/connected-apps')
        ->assertExitCode(1);
});

it('directs revoked connections to login again', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response([
            'message' => 'Token has no resource authorization.',
        ], 403),
    ]);

    $this->artisan('list-projects')
        ->expectsOutputToContain('This Flare connection has been revoked.')
        ->expectsOutputToContain('Run `flare login`')
        ->assertExitCode(1);
});

it('distinguishes a token issued for the wrong resource', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response([
            'message' => 'Token was issued for a different resource.',
        ], 403),
    ]);

    $this->artisan('list-projects')
        ->expectsOutputToContain('issued for a different Flare resource')
        ->expectsOutputToContain('active API profile')
        ->assertExitCode(1);
});

it('distinguishes an invalid refresh grant and does not replay it', function () {
    $this->store->setRecord(TokenRecord::fromArray([
        'access_token' => 'expired-access',
        'refresh_token' => 'single-use-refresh',
        'expires_at' => time() - 1,
        'scopes' => ['read'],
        'client_id' => 'client-uuid',
        'obtained_at' => time() - 100,
        'issuer' => 'https://flareapp.io',
        'api_base_url' => 'https://flareapp.io/api',
    ]));

    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
        'https://flareapp.io/oauth/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid.',
        ], 400),
        'flareapp.io/api/*' => Http::response(['message' => 'Unauthenticated.'], 401),
    ]);

    $this->artisan('list-projects')
        ->expectsOutputToContain('could not be refreshed')
        ->expectsOutputToContain('Run `flare login`')
        ->assertExitCode(1);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'flareapp.io/api'));
    expect($this->store->getRecord()?->refreshPending)->toBeTrue();
});
