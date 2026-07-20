<?php

namespace App\Services\OAuth;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class OAuthMetadata
{
    public function __construct(
        public readonly string $issuer,
        public readonly string $authorizationEndpoint,
        public readonly string $tokenEndpoint,
        public readonly ?string $deviceAuthorizationEndpoint,
        public readonly string $revocationEndpoint,
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function fromArray(array $metadata): self
    {
        foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'revocation_endpoint'] as $key) {
            if (! self::isAbsoluteUrl($metadata[$key] ?? null)) {
                throw new InvalidArgumentException("OAuth metadata is missing a valid {$key}.");
            }
        }

        $deviceEndpoint = $metadata['device_authorization_endpoint'] ?? null;

        if ($deviceEndpoint !== null && ! self::isAbsoluteUrl($deviceEndpoint)) {
            throw new InvalidArgumentException('OAuth metadata contains an invalid device_authorization_endpoint.');
        }

        return new self(
            issuer: rtrim($metadata['issuer'], '/'),
            authorizationEndpoint: $metadata['authorization_endpoint'],
            tokenEndpoint: $metadata['token_endpoint'],
            deviceAuthorizationEndpoint: $deviceEndpoint,
            revocationEndpoint: $metadata['revocation_endpoint'],
        );
    }

    private static function isAbsoluteUrl(mixed $value): bool
    {
        return is_string($value) && Str::isUrl($value, ['http', 'https']);
    }
}
