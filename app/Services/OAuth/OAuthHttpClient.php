<?php

namespace App\Services\OAuth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OAuthHttpClient
{
    public function __construct(
        private readonly OAuthEndpoints $endpoints,
        private readonly string $clientId,
    ) {}

    /**
     * @param  array<int, string>  $requestedScopes
     */
    public function exchangeCode(
        string $code,
        string $codeVerifier,
        string $redirectUri,
        array $requestedScopes,
    ): TokenRecord {
        $response = $this->postForm($this->endpoints->token(), [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'resource' => $this->endpoints->resource(),
        ]);

        return $this->recordFromTokenResponse($response, $requestedScopes);
    }

    public function refresh(TokenRecord $record): TokenRecord
    {
        $response = $this->postForm($this->endpoints->token(), [
            'grant_type' => 'refresh_token',
            'client_id' => $this->clientId,
            'refresh_token' => $record->refreshToken,
            'resource' => $this->endpoints->resource(),
        ]);

        return $this->recordFromTokenResponse($response, $record->scopes, fallbackRefreshToken: $record->refreshToken);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function requestDeviceCode(array $scopes, ?string $connectionName = null): DeviceAuthorization
    {
        $form = [
            'client_id' => $this->clientId,
            'scope' => implode(' ', $scopes),
            'resource' => $this->endpoints->resource(),
        ];

        if ($connectionName !== null) {
            $form['connection_name'] = $connectionName;
        }

        $response = $this->postForm($this->endpoints->deviceCode(), $form);

        return DeviceAuthorization::fromArray($response);
    }

    public function revokeToken(string $revocationEndpoint, string $refreshToken): void
    {
        $this->postForm($revocationEndpoint, [
            'token' => $refreshToken,
            'token_type_hint' => 'refresh_token',
        ]);
    }

    /**
     * @param  array<int, string>  $requestedScopes
     */
    public function pollDeviceCode(string $deviceCode, array $requestedScopes): DevicePollResult
    {
        $response = $this->rawPostForm($this->endpoints->token(), [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            'client_id' => $this->clientId,
            'device_code' => $deviceCode,
            'resource' => $this->endpoints->resource(),
        ]);

        if ($response->successful()) {
            return DevicePollResult::success(
                $this->recordFromTokenResponse($response->json() ?? [], $requestedScopes),
            );
        }

        $body = $response->json() ?? [];
        $error = is_string($body['error'] ?? null) ? $body['error'] : 'invalid_request';
        $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : null;

        return DevicePollResult::error($error, $description);
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function postForm(string $url, array $form): array
    {
        $response = $this->rawPostForm($url, $form);

        if (! $response->successful()) {
            $body = $response->json() ?? [];
            $error = is_string($body['error'] ?? null) ? $body['error'] : null;
            $description = is_string($body['error_description'] ?? null) ? $body['error_description'] : null;

            throw new OAuthException(
                message: "OAuth request to {$url} failed (HTTP {$response->status()})"
                    .($error !== null ? ": {$error}" : '')
                    .($description !== null ? " — {$description}" : ''),
                errorCode: $error,
                errorDescription: $description,
            );
        }

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, string>  $form
     */
    private function rawPostForm(string $url, array $form): Response
    {
        try {
            return Http::asForm()->acceptJson()->post($url, $form);
        } catch (ConnectionException $e) {
            throw new OAuthException("Could not connect to {$url}: {$e->getMessage()}");
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string>  $requestedScopes
     */
    private function recordFromTokenResponse(
        array $response,
        array $requestedScopes,
        ?string $fallbackRefreshToken = null,
    ): TokenRecord {
        if (! isset($response['access_token']) || ! is_string($response['access_token'])) {
            throw new OAuthException('Token response did not include an access_token.');
        }

        $now = time();
        $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : 0;

        $refreshToken = $response['refresh_token'] ?? $fallbackRefreshToken;

        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new OAuthException('Token response did not include a refresh_token.');
        }

        $scopes = $requestedScopes;

        if (isset($response['scope']) && is_string($response['scope']) && $response['scope'] !== '') {
            $scopes = array_values(array_filter(
                explode(' ', $response['scope']),
                static fn (string $scope): bool => $scope !== '',
            ));
        }

        return TokenRecord::fromArray([
            'access_token' => $response['access_token'],
            'refresh_token' => $refreshToken,
            'expires_at' => $now + $expiresIn,
            'scopes' => $scopes,
            'client_id' => $this->clientId,
            'obtained_at' => $now,
            'issuer' => $this->endpoints->issuer(),
            'api_base_url' => $this->endpoints->resource(),
        ]);
    }
}
