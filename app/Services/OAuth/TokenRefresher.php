<?php

namespace App\Services\OAuth;

class TokenRefresher
{
    public function __construct(
        private readonly OAuthHttpClient $client,
        private readonly int $thresholdSeconds = 60,
    ) {}

    public function shouldRefresh(TokenRecord $record, ?int $now = null): bool
    {
        return $record->isExpiringWithin($this->thresholdSeconds, $now);
    }

    public function refresh(TokenRecord $record): TokenRecord
    {
        return $this->client->refresh($record);
    }
}
