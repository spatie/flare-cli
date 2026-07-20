<?php

namespace App\Services\OAuth;

use App\Services\FlareUrlResolver;

class OAuthRevoker
{
    public function __construct(
        private readonly OAuthDiscovery $discovery,
        private readonly OAuthHttpClient $client,
    ) {}

    public function revoke(
        TokenRecord $record,
        string $apiBaseUrl,
        ?string $expectedIssuer = null,
    ): void {
        $appUrl = (new FlareUrlResolver($apiBaseUrl))->getAppUrl();
        $metadata = $this->discovery->metadata($appUrl);

        if ($expectedIssuer !== null && rtrim($expectedIssuer, '/') !== $metadata->issuer) {
            throw new OAuthException(
                "The discovered OAuth issuer {$metadata->issuer} does not match the stored profile issuer {$expectedIssuer}.",
            );
        }

        $this->client->revokeToken($metadata->revocationEndpoint, $record->refreshToken);
    }
}
