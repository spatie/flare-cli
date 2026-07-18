<?php

use App\Services\FlareUrlResolver;
use App\Services\OAuth\DevicePollResult;
use App\Services\OAuth\OAuthEndpoints;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\OAuthHttpClient;
use App\Services\OAuth\TokenRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('FLARE_BASE_URL=https://passport-oauth.test/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://passport-oauth.test/api';

    $this->client = new OAuthHttpClient(
        new OAuthEndpoints(new FlareUrlResolver),
        clientId: 'client-uuid',
    );
});

afterEach(function () {
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);
});

it('exchanges an authorization code for a TokenRecord', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'access_token' => 'new-access',
            'refresh_token' => 'new-refresh',
            'expires_in' => 1296000,
            'token_type' => 'Bearer',
        ]),
    ]);

    $record = $this->client->exchangeCode(
        code: 'auth-code',
        codeVerifier: 'verifier-string',
        redirectUri: 'http://127.0.0.1:54321/callback',
        requestedScopes: ['read', 'write'],
    );

    expect($record)->toBeInstanceOf(TokenRecord::class);
    expect($record->accessToken)->toBe('new-access');
    expect($record->refreshToken)->toBe('new-refresh');
    expect($record->scopes)->toBe(['read', 'write']);
    expect($record->clientId)->toBe('client-uuid');
    expect($record->expiresAt)->toBeGreaterThan(time());

    Http::assertSent(function ($request) {
        return $request->url() === 'https://passport-oauth.test/oauth/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['client_id'] === 'client-uuid'
            && $request['code'] === 'auth-code'
            && $request['code_verifier'] === 'verifier-string'
            && $request['redirect_uri'] === 'http://127.0.0.1:54321/callback'
            && ! isset($request['client_secret']);
    });
});

it('refreshes an access token and preserves rotated refresh tokens', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'access_token' => 'refreshed-access',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 1296000,
        ]),
    ]);

    $original = TokenRecord::fromArray([
        'access_token' => 'old',
        'refresh_token' => 'old-refresh',
        'expires_at' => time() - 100,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => time() - 200,
    ]);

    $refreshed = $this->client->refresh($original);

    expect($refreshed->accessToken)->toBe('refreshed-access');
    expect($refreshed->refreshToken)->toBe('rotated-refresh');
    expect($refreshed->scopes)->toBe(['read', 'write']);

    Http::assertSent(fn ($request) => $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh');
});

it('keeps the existing refresh token when the response does not rotate it', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'access_token' => 'refreshed-access',
            'expires_in' => 1296000,
        ]),
    ]);

    $original = TokenRecord::fromArray([
        'access_token' => 'old',
        'refresh_token' => 'sticky-refresh',
        'expires_at' => time() - 100,
        'scopes' => ['read'],
        'client_id' => 'client-uuid',
        'obtained_at' => time() - 200,
    ]);

    $refreshed = $this->client->refresh($original);

    expect($refreshed->refreshToken)->toBe('sticky-refresh');
});

it('throws an OAuthException with the server error code on a 400 token response', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid.',
        ], 400),
    ]);

    $record = TokenRecord::fromArray([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_at' => time(),
        'scopes' => [],
        'client_id' => 'client-uuid',
        'obtained_at' => time(),
    ]);

    try {
        $this->client->refresh($record);
        $this->fail('Expected OAuthException was not thrown');
    } catch (OAuthException $e) {
        expect($e->errorCode)->toBe('invalid_grant');
        expect($e->errorDescription)->toBe('The refresh token is invalid.');
    }
});

it('requests a device code and parses the response', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/device/code' => Http::response([
            'device_code' => 'dev-code-123',
            'user_code' => 'ABCD-EFGH',
            'verification_uri' => 'https://passport-oauth.test/oauth/device',
            'verification_uri_complete' => 'https://passport-oauth.test/oauth/device?code=ABCD-EFGH',
            'expires_in' => 600,
            'interval' => 5,
        ]),
    ]);

    $auth = $this->client->requestDeviceCode(['read', 'write']);

    expect($auth->deviceCode)->toBe('dev-code-123');
    expect($auth->userCode)->toBe('ABCD-EFGH');
    expect($auth->verificationUri)->toBe('https://passport-oauth.test/oauth/device');
    expect($auth->interval)->toBe(5);
    expect($auth->expiresIn)->toBe(600);

    Http::assertSent(fn ($request) => $request['client_id'] === 'client-uuid'
        && $request['scope'] === 'read write');
});

it('polls the token endpoint and returns success when tokens arrive', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'access_token' => 'device-access',
            'refresh_token' => 'device-refresh',
            'expires_in' => 1296000,
        ]),
    ]);

    $result = $this->client->pollDeviceCode('dev-code-123', ['read']);

    expect($result)->toBeInstanceOf(DevicePollResult::class);
    expect($result->isPending())->toBeFalse();
    expect($result->record?->accessToken)->toBe('device-access');
});

it('returns a pending poll result on authorization_pending', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'error' => 'authorization_pending',
        ], 400),
    ]);

    $result = $this->client->pollDeviceCode('dev-code-123', ['read']);

    expect($result->isPending())->toBeTrue();
    expect($result->isFatal())->toBeFalse();
});

it('returns a slow_down poll result distinctly from pending', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'error' => 'slow_down',
        ], 400),
    ]);

    $result = $this->client->pollDeviceCode('dev-code-123', ['read']);

    expect($result->isSlowDown())->toBeTrue();
    expect($result->isPending())->toBeFalse();
    expect($result->isFatal())->toBeFalse();
});

it('flags fatal device-flow errors', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'error' => 'access_denied',
            'error_description' => 'The user denied the request.',
        ], 400),
    ]);

    $result = $this->client->pollDeviceCode('dev-code-123', ['read']);

    expect($result->isFatal())->toBeTrue();
    expect($result->error)->toBe('access_denied');
    expect($result->errorDescription)->toBe('The user denied the request.');
});
