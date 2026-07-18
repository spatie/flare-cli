<?php

namespace App\Services\OAuth;

class TokenRefresher
{
    public function __construct(
        private readonly OAuthHttpClient $client,
        private readonly int $thresholdSeconds = 60,
    ) {}

    public function refreshIfNeeded(TokenRecord $record, ?int $now = null): TokenRecord
    {
        if (! $record->isExpiringWithin($this->thresholdSeconds, $now)) {
            return $record;
        }

        return $this->client->refresh($record);
    }

    public function refresh(TokenRecord $record): TokenRecord
    {
        return $this->client->refresh($record);
    }
}
