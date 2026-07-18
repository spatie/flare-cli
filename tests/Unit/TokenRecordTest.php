<?php

use App\Services\OAuth\TokenRecord;

it('round-trips through fromArray and toArray', function () {
    $data = [
        'type' => 'oauth',
        'access_token' => 'access-abc',
        'refresh_token' => 'refresh-xyz',
        'expires_at' => 1_700_000_000,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => 1_699_999_000,
    ];

    expect(TokenRecord::fromArray($data)->toArray())->toBe($data);
});

it('coerces non-string scope values away', function () {
    $record = TokenRecord::fromArray([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_at' => 100,
        'scopes' => ['read', 42, null, 'write'],
        'client_id' => 'c',
        'obtained_at' => 50,
    ]);

    expect($record->scopes)->toBe(['read', 'write']);
});

it('throws when required fields are missing', function () {
    TokenRecord::fromArray(['access_token' => 'a']);
})->throws(InvalidArgumentException::class);

it('detects expiry within a threshold', function () {
    $now = 1_000_000;
    $record = TokenRecord::fromArray([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_at' => $now + 30,
        'scopes' => [],
        'client_id' => 'c',
        'obtained_at' => $now,
    ]);

    expect($record->isExpiringWithin(60, $now))->toBeTrue();
    expect($record->isExpiringWithin(10, $now))->toBeFalse();
});

it('preserves immutable fields across refresh', function () {
    $original = TokenRecord::fromArray([
        'access_token' => 'old-access',
        'refresh_token' => 'old-refresh',
        'expires_at' => 100,
        'scopes' => ['read'],
        'client_id' => 'client-id',
        'obtained_at' => 50,
    ]);

    $refreshed = $original->withRefreshed(
        accessToken: 'new-access',
        refreshToken: 'new-refresh',
        expiresAt: 200,
        obtainedAt: 150,
    );

    expect($refreshed->accessToken)->toBe('new-access');
    expect($refreshed->refreshToken)->toBe('new-refresh');
    expect($refreshed->expiresAt)->toBe(200);
    expect($refreshed->obtainedAt)->toBe(150);
    expect($refreshed->scopes)->toBe(['read']);
    expect($refreshed->clientId)->toBe('client-id');
});

it('identifies OAuth-shaped arrays', function () {
    expect(TokenRecord::looksLikeRecord(['type' => 'oauth']))->toBeTrue();
    expect(TokenRecord::looksLikeRecord(['type' => 'other']))->toBeFalse();
    expect(TokenRecord::looksLikeRecord('plain-string'))->toBeFalse();
    expect(TokenRecord::looksLikeRecord(null))->toBeFalse();
    expect(TokenRecord::looksLikeRecord([]))->toBeFalse();
});
