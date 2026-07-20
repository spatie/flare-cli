<?php

use App\Services\FlareUrlResolver;
use App\Services\OAuth\OAuthDiscovery;
use App\Services\OAuth\OAuthEndpoints;
use App\Services\OAuth\OAuthException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);
});

afterEach(function () {
    if ($this->originalBaseUrl === null) {
        putenv('FLARE_BASE_URL');
        unset($_SERVER['FLARE_BASE_URL']);
    } else {
        putenv("FLARE_BASE_URL={$this->originalBaseUrl}");
        $_SERVER['FLARE_BASE_URL'] = $this->originalBaseUrl;
    }
});

it('builds production endpoints by default', function () {
    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response(
            oauthMetadata('https://flareapp.io'),
        ),
    ]);

    $endpoints = new OAuthEndpoints(new FlareUrlResolver, new OAuthDiscovery);

    expect($endpoints->authorize())->toBe('https://flareapp.io/oauth/authorize');
    expect($endpoints->token())->toBe('https://flareapp.io/oauth/token');
    expect($endpoints->deviceCode())->toBe('https://flareapp.io/oauth/device/code');
    expect($endpoints->revocation())->toBe('https://flareapp.io/oauth/revoke');
});

it('derives endpoints from FLARE_BASE_URL', function () {
    putenv('FLARE_BASE_URL=https://passport-oauth.test/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://passport-oauth.test/api';
    Http::fake([
        'https://passport-oauth.test/.well-known/oauth-authorization-server' => Http::response(oauthMetadata()),
    ]);

    $endpoints = new OAuthEndpoints(new FlareUrlResolver, new OAuthDiscovery);

    expect($endpoints->authorize())->toBe('https://passport-oauth.test/oauth/authorize');
    expect($endpoints->token())->toBe('https://passport-oauth.test/oauth/token');
    expect($endpoints->deviceCode())->toBe('https://passport-oauth.test/oauth/device/code');
});

it('throws when the server does not provide a device authorization endpoint', function () {
    Http::fake([
        'https://flareapp.io/.well-known/oauth-authorization-server' => Http::response([
            ...oauthMetadata('https://flareapp.io'),
            'device_authorization_endpoint' => null,
        ]),
    ]);

    $endpoints = new OAuthEndpoints(new FlareUrlResolver, new OAuthDiscovery);

    expect(fn () => $endpoints->deviceCode())
        ->toThrow(OAuthException::class, 'did not provide a device authorization endpoint');
});
