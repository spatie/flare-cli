<?php

use App\Services\OAuth\DeviceAuthorization;
use App\Services\OAuth\DeviceLoginFlow;
use App\Services\OAuth\DevicePollResult;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\OAuthHttpClient;
use App\Services\OAuth\TokenRecord;

function deviceAuth(int $interval = 5, int $expiresIn = 600): DeviceAuthorization
{
    return DeviceAuthorization::fromArray([
        'device_code' => 'dev-code',
        'user_code' => 'ABCD-EFGH',
        'verification_uri' => 'https://passport-oauth.test/oauth/device',
        'expires_in' => $expiresIn,
        'interval' => $interval,
    ]);
}

function deviceTokenRecord(): TokenRecord
{
    return TokenRecord::fromArray([
        'access_token' => 'device-access',
        'refresh_token' => 'device-refresh',
        'expires_at' => time() + 1296000,
        'scopes' => ['read'],
        'client_id' => 'client-uuid',
        'obtained_at' => time(),
    ]);
}

it('polls until tokens arrive and returns the TokenRecord', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('requestDeviceCode')->once()->andReturn(deviceAuth(interval: 5));
    $client->shouldReceive('pollDeviceCode')
        ->times(3)
        ->andReturn(
            DevicePollResult::error('authorization_pending'),
            DevicePollResult::error('authorization_pending'),
            DevicePollResult::success(deviceTokenRecord()),
        );

    $sleepCalls = [];
    $sleeper = function (int $seconds) use (&$sleepCalls) {
        $sleepCalls[] = $seconds;
    };

    $announced = false;
    $announce = function (DeviceAuthorization $auth) use (&$announced) {
        $announced = true;
        expect($auth->userCode)->toBe('ABCD-EFGH');
    };

    $flow = new DeviceLoginFlow($client, ['read']);
    $record = $flow->run($announce, $sleeper);

    expect($announced)->toBeTrue();
    expect($record->accessToken)->toBe('device-access');
    expect($sleepCalls)->toBe([5, 5, 5]);
});

it('increases the polling interval on slow_down', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('requestDeviceCode')->once()->andReturn(deviceAuth(interval: 5));
    $client->shouldReceive('pollDeviceCode')
        ->times(3)
        ->andReturn(
            DevicePollResult::error('slow_down'),
            DevicePollResult::error('authorization_pending'),
            DevicePollResult::success(deviceTokenRecord()),
        );

    $sleepCalls = [];
    $sleeper = function (int $seconds) use (&$sleepCalls) {
        $sleepCalls[] = $seconds;
    };

    (new DeviceLoginFlow($client, ['read']))->run(fn () => null, $sleeper);

    expect($sleepCalls)->toBe([5, 10, 10]);
});

it('throws on a fatal device-flow error', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('requestDeviceCode')->once()->andReturn(deviceAuth());
    $client->shouldReceive('pollDeviceCode')
        ->once()
        ->andReturn(DevicePollResult::error('access_denied', 'user denied'));

    expect(fn () => (new DeviceLoginFlow($client, ['read']))->run(
        fn () => null,
        fn () => null,
    ))->toThrow(OAuthException::class, 'access_denied');
});

it('throws when the device code expires before tokens arrive', function () {
    $client = Mockery::mock(OAuthHttpClient::class);
    $client->shouldReceive('requestDeviceCode')->once()->andReturn(deviceAuth(interval: 1, expiresIn: 2));
    $client->shouldReceive('pollDeviceCode')->andReturn(DevicePollResult::error('authorization_pending'));

    $now = 1_000_000;
    $clock = function () use (&$now) {
        return $now;
    };
    $sleeper = function (int $seconds) use (&$now) {
        $now += $seconds;
    };

    expect(fn () => (new DeviceLoginFlow($client, ['read']))->run(
        fn () => null,
        Closure::fromCallable($sleeper),
        Closure::fromCallable($clock),
    ))->toThrow(OAuthException::class, 'expired');
});
