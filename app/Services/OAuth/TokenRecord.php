<?php

namespace App\Services\OAuth;

use InvalidArgumentException;

final class TokenRecord
{
    public const TYPE = 'oauth';

    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
        public readonly array $scopes,
        public readonly string $clientId,
        public readonly int $obtainedAt,
        public readonly ?string $issuer = null,
        public readonly ?string $apiBaseUrl = null,
        public readonly bool $refreshPending = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['access_token', 'refresh_token', 'expires_at', 'client_id', 'obtained_at'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Missing OAuth record field: {$key}");
            }
        }

        $scopes = $data['scopes'] ?? [];

        if (! is_array($scopes)) {
            $scopes = [];
        }

        return new self(
            accessToken: (string) $data['access_token'],
            refreshToken: (string) $data['refresh_token'],
            expiresAt: (int) $data['expires_at'],
            scopes: array_values(array_filter($scopes, 'is_string')),
            clientId: (string) $data['client_id'],
            obtainedAt: (int) $data['obtained_at'],
            issuer: isset($data['issuer']) && is_string($data['issuer']) ? $data['issuer'] : null,
            apiBaseUrl: isset($data['api_base_url']) && is_string($data['api_base_url']) ? $data['api_base_url'] : null,
            refreshPending: ($data['refresh_pending'] ?? false) === true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $record = [
            'type' => self::TYPE,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scopes' => $this->scopes,
            'client_id' => $this->clientId,
            'obtained_at' => $this->obtainedAt,
        ];

        if ($this->issuer !== null) {
            $record['issuer'] = $this->issuer;
        }

        if ($this->apiBaseUrl !== null) {
            $record['api_base_url'] = $this->apiBaseUrl;
        }

        if ($this->refreshPending) {
            $record['refresh_pending'] = true;
        }

        return $record;
    }

    public function isExpiringWithin(int $seconds, ?int $now = null): bool
    {
        return ($now ?? time()) >= ($this->expiresAt - $seconds);
    }

    public function withRefreshed(
        string $accessToken,
        string $refreshToken,
        int $expiresAt,
        int $obtainedAt,
    ): self {
        return $this->with([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt,
            'obtained_at' => $obtainedAt,
            'refresh_pending' => false,
        ]);
    }

    public function withProfile(string $issuer, string $apiBaseUrl): self
    {
        return $this->with([
            'issuer' => $issuer,
            'api_base_url' => $apiBaseUrl,
        ]);
    }

    public function markRefreshPending(): self
    {
        return $this->with(['refresh_pending' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function with(array $overrides): self
    {
        return self::fromArray([...$this->toArray(), ...$overrides]);
    }

    public static function looksLikeRecord(mixed $data): bool
    {
        return is_array($data) && ($data['type'] ?? null) === self::TYPE;
    }
}
