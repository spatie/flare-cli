<?php

use App\Services\FlareUrlResolver;

beforeEach(function () {
    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);
});

afterEach(function () {
    if ($this->originalBaseUrl === null) {
        putenv('FLARE_BASE_URL');
        unset($_SERVER['FLARE_BASE_URL']);

        return;
    }

    putenv("FLARE_BASE_URL={$this->originalBaseUrl}");
    $_SERVER['FLARE_BASE_URL'] = $this->originalBaseUrl;
});

it('uses the production URLs when FLARE_BASE_URL is unset', function () {
    $resolver = new FlareUrlResolver;

    expect($resolver->getApiBaseUrl())->toBe('https://flareapp.io/api');
    expect($resolver->getHostKey())->toBe('flareapp.io');
    expect($resolver->getAppUrl())->toBe('https://flareapp.io');
});

it('normalizes the configured base URL and host key', function () {
    putenv('FLARE_BASE_URL=https://Ingress-Staging.Flareapp.io/api/');
    $_SERVER['FLARE_BASE_URL'] = 'https://Ingress-Staging.Flareapp.io/api/';

    $resolver = new FlareUrlResolver;

    expect($resolver->getApiBaseUrl())->toBe('https://staging.flareapp.io/api');
    expect($resolver->getHostKey())->toBe('staging.flareapp.io');
    expect($resolver->getAppUrl())->toBe('https://staging.flareapp.io');
});

it('normalizes an origin to the default api path', function () {
    putenv('FLARE_BASE_URL=https://self-hosted.test/');
    $_SERVER['FLARE_BASE_URL'] = 'https://self-hosted.test/';

    expect((new FlareUrlResolver)->getApiBaseUrl())->toBe('https://self-hosted.test/api');
});

it('preserves a non-default api path as a distinct base URL', function () {
    putenv('FLARE_BASE_URL=https://self-hosted.test/custom/api/');
    $_SERVER['FLARE_BASE_URL'] = 'https://self-hosted.test/custom/api/';

    $resolver = new FlareUrlResolver;

    expect($resolver->getApiBaseUrl())->toBe('https://self-hosted.test/custom/api');
    expect($resolver->getAppUrl())->toBe('https://self-hosted.test/custom');
});
