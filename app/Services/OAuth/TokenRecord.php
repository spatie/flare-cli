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
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => self::TYPE,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'expires_at' => $this->expiresAt,
            'scopes' => $this->scopes,
            'client_id' => $this->clientId,
            'obtained_at' => $this->obtainedAt,
        ];
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
        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            scopes: $this->scopes,
            clientId: $this->clientId,
            obtainedAt: $obtainedAt,
        );
    }

    public static function looksLikeRecord(mixed $data): bool
    {
        return is_array($data) && ($data['type'] ?? null) === self::TYPE;
    }
}
