<?php

use App\Services\OAuth\OAuthDiscovery;
use App\Services\OAuth\OAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('discovers and caches valid OAuth metadata', function () {
    Http::fake([
        'https://passport-oauth.test/.well-known/oauth-authorization-server' => Http::response(oauthMetadata()),
    ]);

    $discovery = new OAuthDiscovery;

    expect($discovery->metadata('https://passport-oauth.test')->issuer)->toBe('https://passport-oauth.test');
    expect($discovery->metadata('https://passport-oauth.test')->revocationEndpoint)->toBe('https://passport-oauth.test/oauth/revoke');

    Http::assertSentCount(1);
});

it('rejects incomplete OAuth metadata without hardcoded fallback', function () {
    Http::fake([
        'https://passport-oauth.test/.well-known/oauth-authorization-server' => Http::response([
            'issuer' => 'https://passport-oauth.test',
        ]),
    ]);

    expect(fn () => (new OAuthDiscovery)->metadata('https://passport-oauth.test'))
        ->toThrow(OAuthException::class, 'missing a valid authorization_endpoint');
});

it('reports discovery connection failures', function () {
    Http::fake([
        'https://passport-oauth.test/.well-known/oauth-authorization-server' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    expect(fn () => (new OAuthDiscovery)->metadata('https://passport-oauth.test'))
        ->toThrow(OAuthException::class, 'Could not discover Flare OAuth endpoints');
});
