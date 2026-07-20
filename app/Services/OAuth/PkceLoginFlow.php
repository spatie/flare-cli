<?php

namespace App\Services\OAuth;

class PkceLoginFlow
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        private readonly OAuthHttpClient $client,
        private readonly OAuthEndpoints $endpoints,
        private readonly string $clientId,
        private readonly array $scopes,
    ) {}

    /**
     * @param  callable(string): void  $openBrowser
     * @param  callable(string): void  $log
     */
    public function run(
        callable $openBrowser,
        callable $log,
        ?LocalCallbackServer $server = null,
        int $timeoutSeconds = 120,
        ?string $connectionName = null,
    ): TokenRecord {
        $verifier = PkceCodes::verifier();
        $challenge = PkceCodes::challenge($verifier);
        $state = PkceCodes::state();

        $server ??= new LocalCallbackServer;

        try {
            $url = $this->buildAuthorizationUrl($server->redirectUri, $challenge, $state, $connectionName);

            $log("Open this URL to continue: {$url}");
            $openBrowser($url);

            $params = $server->awaitCallback($timeoutSeconds);

            if (isset($params['error'])) {
                $description = $params['error_description'] ?? '';

                throw new OAuthException(
                    "Authorization was denied: {$params['error']}".($description !== '' ? " — {$description}" : ''),
                    errorCode: $params['error'],
                    errorDescription: $description !== '' ? $description : null,
                );
            }

            if (! isset($params['code'], $params['state'])) {
                throw new OAuthException('OAuth callback was missing code or state.');
            }

            if (! hash_equals($state, $params['state'])) {
                throw new OAuthException('OAuth callback state did not match. Aborting login.');
            }

            return $this->client->exchangeCode(
                code: $params['code'],
                codeVerifier: $verifier,
                redirectUri: $server->redirectUri,
                requestedScopes: $this->scopes,
            );
        } finally {
            $server->close();
        }
    }

    private function buildAuthorizationUrl(
        string $redirectUri,
        string $challenge,
        string $state,
        ?string $connectionName,
    ): string {
        $parameters = [
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'resource' => $this->endpoints->resource(),
        ];

        if ($connectionName !== null) {
            $parameters['connection_name'] = $connectionName;
        }

        return $this->endpoints->authorize().'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public static function defaultBrowserOpener(string $url): void
    {
        $escaped = escapeshellarg($url);

        match (PHP_OS_FAMILY) {
            'Darwin' => exec("open {$escaped} > /dev/null 2>&1 &"),
            'Windows' => exec("start \"\" {$escaped} > nul 2>&1"),
            default => exec("xdg-open {$escaped} > /dev/null 2>&1 &"),
        };
    }
}
