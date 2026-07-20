<?php

namespace App\Services\OAuth;

use App\Services\FlareUrlResolver;

final class OAuthEndpoints
{
    public function __construct(
        private readonly FlareUrlResolver $urlResolver,
        private readonly OAuthDiscovery $discovery,
    ) {}

    public function authorize(): string
    {
        return $this->metadata()->authorizationEndpoint;
    }

    public function token(): string
    {
        return $this->metadata()->tokenEndpoint;
    }

    public function deviceCode(): string
    {
        return $this->metadata()->deviceAuthorizationEndpoint
            ?? throw new OAuthException('The OAuth server did not provide a device authorization endpoint.');
    }

    public function revocation(): string
    {
        return $this->metadata()->revocationEndpoint;
    }

    public function issuer(): string
    {
        return $this->metadata()->issuer;
    }

    public function resource(): string
    {
        return $this->urlResolver->getApiBaseUrl();
    }

    public function metadata(): OAuthMetadata
    {
        return $this->discovery->metadata($this->urlResolver->getAppUrl());
    }
}
