<?php

use App\Services\FlareUrlResolver;
use App\Services\OAuth\OAuthEndpoints;

beforeEach(function () {
    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);
});

afterEach(function () {
    if ($this->originalBaseUrl === null) {
        putenv('FLARE_BASE_URL');
        unset($_SERVER['FLARE_BASE_URL']);
    } else {
        putenv("FLARE_BASE_URL={$this->originalBaseUrl}");
        $_SERVER['FLARE_BASE_URL'] = $this->originalBaseUrl;
    }
});

it('builds production endpoints by default', function () {
    $endpoints = new OAuthEndpoints(new FlareUrlResolver);

    expect($endpoints->authorize())->toBe('https://flareapp.io/oauth/authorize');
    expect($endpoints->token())->toBe('https://flareapp.io/oauth/token');
    expect($endpoints->deviceCode())->toBe('https://flareapp.io/oauth/device/code');
    expect($endpoints->deviceVerification())->toBe('https://flareapp.io/oauth/device');
});

it('derives endpoints from FLARE_BASE_URL', function () {
    putenv('FLARE_BASE_URL=https://passport-oauth.test/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://passport-oauth.test/api';

    $endpoints = new OAuthEndpoints(new FlareUrlResolver);

    expect($endpoints->authorize())->toBe('https://passport-oauth.test/oauth/authorize');
    expect($endpoints->token())->toBe('https://passport-oauth.test/oauth/token');
    expect($endpoints->deviceCode())->toBe('https://passport-oauth.test/oauth/device/code');
});
