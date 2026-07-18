<?php

use App\Services\OAuth\LocalCallbackServer;

it('binds to a free loopback port and exposes a /callback redirect URI', function () {
    $server = new LocalCallbackServer;

    try {
        expect($server->port)->toBeGreaterThan(0);
        expect($server->redirectUri)->toMatch('#^http://127\.0\.0\.1:\d+/callback$#');
    } finally {
        $server->close();
    }
});

it('parses code and state from a real HTTP GET request', function () {
    $server = new LocalCallbackServer;

    try {
        $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
        expect($client)->not->toBeFalse();

        fwrite(
            $client,
            "GET /callback?code=auth-code-123&state=state-xyz HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n",
        );

        $params = $server->awaitCallback(5);
        $response = stream_get_contents($client);
        fclose($client);

        expect($params['code'])->toBe('auth-code-123');
        expect($params['state'])->toBe('state-xyz');
        expect($response)->toContain('HTTP/1.1 200 OK');
        expect($response)->toContain("You're logged in to Flare.");
    } finally {
        $server->close();
    }
});

it('returns OAuth error params verbatim and renders the failure page', function () {
    $server = new LocalCallbackServer;

    try {
        $client = stream_socket_client("tcp://127.0.0.1:{$server->port}");
        fwrite(
            $client,
            "GET /callback?error=access_denied&error_description=user+said+no HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n",
        );

        $params = $server->awaitCallback(5);
        $response = stream_get_contents($client);
        fclose($client);

        expect($params['error'])->toBe('access_denied');
        expect($params['error_description'])->toBe('user said no');
        expect($response)->toContain('Authentication failed.');
    } finally {
        $server->close();
    }
});
