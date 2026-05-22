<?php

namespace App\Services\OAuth;

final class DeviceAuthorization
{
    public function __construct(
        public readonly string $deviceCode,
        public readonly string $userCode,
        public readonly string $verificationUri,
        public readonly ?string $verificationUriComplete,
        public readonly int $expiresIn,
        public readonly int $interval,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            deviceCode: (string) ($data['device_code'] ?? ''),
            userCode: (string) ($data['user_code'] ?? ''),
            verificationUri: (string) ($data['verification_uri'] ?? ''),
            verificationUriComplete: isset($data['verification_uri_complete'])
                ? (string) $data['verification_uri_complete']
                : null,
            expiresIn: (int) ($data['expires_in'] ?? 600),
            interval: max(1, (int) ($data['interval'] ?? 5)),
        );
    }
}
