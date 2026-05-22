<?php

use App\Services\OAuth\OAuthHttpClient;
use App\Services\OAuth\TokenRecord;
use App\Services\OAuth\TokenRefresher;

function refresherRecord(int $expiresAt, int $obtainedAt = 1_000_000): TokenRecord
{
    return TokenRecord::fromArray([
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'expires_at' => $expiresAt,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => $obtainedAt,
    ]);
}

it('returns the same record when expiry is outside the threshold', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldNotReceive('refresh');

    $now = 1_000_000;
    $record = refresherRecord(expiresAt: $now + 3600);

    $refresher = new TokenRefresher($client, thresholdSeconds: 60);

    expect($refresher->refreshIfNeeded($record, $now))->toBe($record);
});

it('refreshes when expiry is within the threshold', function () {
    $now = 1_000_000;
    $stale = refresherRecord(expiresAt: $now + 30);
    $fresh = TokenRecord::fromArray([
        'access_token' => 'new-access',
        'refresh_token' => 'new-refresh',
        'expires_at' => $now + 1296000,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => $now,
    ]);

    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('refresh')->once()->with($stale)->andReturn($fresh);

    $refresher = new TokenRefresher($client, thresholdSeconds: 60);

    expect($refresher->refreshIfNeeded($stale, $now))->toBe($fresh);
});

it('refreshes unconditionally via refresh()', function () {
    $original = refresherRecord(expiresAt: time() + 99999);
    $fresh = refresherRecord(expiresAt: time() + 99999);

    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('refresh')->once()->andReturn($fresh);

    expect((new TokenRefresher($client))->refresh($original))->toBe($fresh);
});
