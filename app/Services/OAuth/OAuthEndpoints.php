<?php

namespace App\Services\OAuth;

use App\Services\FlareUrlResolver;

final class OAuthEndpoints
{
    public function __construct(
        private readonly FlareUrlResolver $urlResolver,
    ) {}

    public function authorize(): string
    {
        return $this->base().'/oauth/authorize';
    }

    public function token(): string
    {
        return $this->base().'/oauth/token';
    }

    public function deviceCode(): string
    {
        return $this->base().'/oauth/device/code';
    }

    public function deviceVerification(): string
    {
        return $this->base().'/oauth/device';
    }

    private function base(): string
    {
        return rtrim($this->urlResolver->getAppUrl(), '/');
    }
}
