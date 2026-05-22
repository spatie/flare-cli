<?php

use App\Services\FlareUrlResolver;
use App\Services\OAuth\LocalCallbackServer;
use App\Services\OAuth\OAuthEndpoints;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\OAuthHttpClient;
use App\Services\OAuth\PkceLoginFlow;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    putenv('FLARE_BASE_URL=https://passport-oauth.test/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://passport-oauth.test/api';

    $this->endpoints = new OAuthEndpoints(new FlareUrlResolver);
    $this->httpClient = new OAuthHttpClient($this->endpoints, 'client-uuid');
});

afterEach(function () {
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);
});

it('runs the full PKCE flow and returns a TokenRecord', function () {
    Http::fake([
        'https://passport-oauth.test/oauth/token' => Http::response([
            'access_token' => 'pkce-access',
            'refresh_token' => 'pkce-refresh',
            'expires_in' => 1296000,
        ]),
    ]);

    $server = new LocalCallbackServer;
    $sentUrl = null;

    $browser = function (string $url) use ($server, &$sentUrl) {
        $sentUrl = $url;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
        fwrite(
            $client,
            "GET /callback?code=fake-code&state={$params['state']} HTTP/1.1\r\n"
            ."Host: 127.0.0.1\r\n\r\n",
        );
        fclose($client);
    };

    $flow = new PkceLoginFlow($this->httpClient, $this->endpoints, 'client-uuid', ['read', 'write']);

    $record = $flow->run($browser, fn () => null, server: $server, timeoutSeconds: 5);

    expect($record->accessToken)->toBe('pkce-access');
    expect($record->scopes)->toBe(['read', 'write']);

    expect($sentUrl)->toStartWith('https://passport-oauth.test/oauth/authorize?');
    expect($sentUrl)->toContain('response_type=code');
    expect($sentUrl)->toContain('code_challenge_method=S256');
    expect($sentUrl)->toContain('scope=read%20write');
    expect($sentUrl)->toContain('client_id=client-uuid');

    Http::assertSent(fn ($request) => $request->url() === 'https://passport-oauth.test/oauth/token'
        && $request['code'] === 'fake-code'
        && $request['grant_type'] === 'authorization_code');
});

it('aborts and never exchanges when the callback state does not match', function () {
    Http::fake();

    $server = new LocalCallbackServer;

    $browser = function () use ($server) {
        $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
        fwrite(
            $client,
            "GET /callback?code=fake-code&state=WRONG-STATE HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n",
        );
        fclose($client);
    };

    $flow = new PkceLoginFlow($this->httpClient, $this->endpoints, 'client-uuid', ['read']);

    expect(fn () => $flow->run($browser, fn () => null, server: $server, timeoutSeconds: 5))
        ->toThrow(OAuthException::class, 'state did not match');

    Http::assertNothingSent();
});

it('surfaces OAuth provider errors returned via the callback', function () {
    Http::fake();

    $server = new LocalCallbackServer;

    $browser = function () use ($server) {
        $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
        fwrite(
            $client,
            "GET /callback?error=access_denied&error_description=user+said+no HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n",
        );
        fclose($client);
    };

    $flow = new PkceLoginFlow($this->httpClient, $this->endpoints, 'client-uuid', ['read']);

    expect(fn () => $flow->run($browser, fn () => null, server: $server, timeoutSeconds: 5))
        ->toThrow(OAuthException::class, 'access_denied');

    Http::assertNothingSent();
});
