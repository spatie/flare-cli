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

it('does not need a refresh when expiry is outside the threshold', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldNotReceive('refresh');

    $now = 1_000_000;
    $record = refresherRecord(expiresAt: $now + 3600);

    $refresher = new TokenRefresher($client, thresholdSeconds: 60);

    expect($refresher->shouldRefresh($record, $now))->toBeFalse();
});

it('needs a refresh when expiry is within the threshold', function () {
    $now = 1_000_000;
    $stale = refresherRecord(expiresAt: $now + 30);

    $refresher = new TokenRefresher(Mockery::mock(OAuthHttpClient::class), thresholdSeconds: 60);

    expect($refresher->shouldRefresh($stale, $now))->toBeTrue();
});

it('refreshes unconditionally via refresh()', function () {
    $original = refresherRecord(expiresAt: time() + 99999);
    $fresh = refresherRecord(expiresAt: time() + 99999);

    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('refresh')->once()->andReturn($fresh);

    expect((new TokenRefresher($client))->refresh($original))->toBe($fresh);
});
