<?php

namespace App\Services\OAuth;

use RuntimeException;

class LocalCallbackServer
{
    /** @var resource|null */
    private $server;

    public readonly int $port;

    public readonly string $redirectUri;

    public function __construct()
    {
        $errno = 0;
        $errstr = '';

        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Could not bind local OAuth callback socket: {$errstr}");
        }

        $this->server = $server;

        $name = stream_socket_get_name($server, false);

        if ($name === false) {
            $this->close();

            throw new RuntimeException('Could not read the bound socket port.');
        }

        $parts = explode(':', $name);
        $this->port = (int) end($parts);
        $this->redirectUri = "http://127.0.0.1:{$this->port}/callback";
    }

    /**
     * Wait for the OAuth provider to redirect the user's browser to our
     * callback URL. Returns the parsed query parameters.
     *
     * @return array<string, string>
     */
    public function awaitCallback(int $timeoutSeconds = 120): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $remaining = max(1, (int) ceil($deadline - microtime(true)));
            $reads = [$this->server];
            $writes = $exc = null;

            $ready = @stream_select($reads, $writes, $exc, $remaining);

            if ($ready === false || $ready === 0) {
                continue;
            }

            $client = @stream_socket_accept($this->server, 1);

            if ($client === false) {
                continue;
            }

            $params = $this->readRequest($client);

            $this->writeResponse($client, isset($params['code']));

            fclose($client);

            if (isset($params['code']) || isset($params['error'])) {
                return $params;
            }
        }

        throw new RuntimeException('Timed out waiting for the OAuth callback.');
    }

    public function close(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
        }

        $this->server = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param  resource  $client
     * @return array<string, string>
     */
    private function readRequest($client): array
    {
        stream_set_timeout($client, 2);

        $request = '';
        $deadline = microtime(true) + 2.0;

        while (microtime(true) < $deadline) {
            $chunk = fread($client, 4096);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $request .= $chunk;

            if (str_contains($request, "\r\n\r\n")) {
                break;
            }
        }

        $firstLine = strtok($request, "\r\n");

        if ($firstLine === false || ! preg_match('#^GET\s+/[^\s?]*(?:\?([^\s]*))?\s+HTTP/#', $firstLine, $matches)) {
            return [];
        }

        $params = [];

        if (isset($matches[1])) {
            parse_str($matches[1], $params);
        }

        return array_map(fn ($v) => is_string($v) ? $v : '', $params);
    }

    /**
     * @param  resource  $client
     */
    private function writeResponse($client, bool $success): void
    {
        $body = $success ? self::SUCCESS_HTML : self::ERROR_HTML;

        fwrite(
            $client,
            "HTTP/1.1 200 OK\r\n"
                ."Content-Type: text/html; charset=utf-8\r\n"
                .'Content-Length: '.strlen($body)."\r\n"
                ."Connection: close\r\n"
                ."\r\n"
                .$body,
        );
    }

    private const SUCCESS_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Flare CLI — Authentication successful</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fff; color: #1d1f21; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { max-width: 440px; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #626465; font-size: 0.95rem; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>You're logged in to Flare.</h1>
        <p>You can close this tab and return to your terminal.</p>
    </div>
</body>
</html>
HTML;

    private const ERROR_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Flare CLI — Authentication failed</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #fff; color: #1d1f21; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { max-width: 440px; padding: 2rem; text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #626465; font-size: 0.95rem; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Authentication failed.</h1>
        <p>Something went wrong. Check your terminal for details.</p>
    </div>
</body>
</html>
HTML;
}
