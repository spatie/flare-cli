<?php

use App\Providers\AppServiceProvider;
use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use GuzzleHttp\Psr7\Response;
use Spatie\OpenApiCli\OpenApiCli;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

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

    OpenApiCli::clearRegistrations();
    app()->forgetInstance(CredentialStore::class);
    app()->forgetInstance(FlareUrlResolver::class);
    (new AppServiceProvider($this->app))->boot();

    cleanupFlareHome($this->tempDir);
});

it('registers the OpenAPI commands with the active base URL and auth context', function () {
    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api/');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api/';

    $store = new CredentialStore(new FlareUrlResolver);
    $store->setToken('staging-token');

    OpenApiCli::clearRegistrations();
    app()->forgetInstance(CredentialStore::class);
    app()->forgetInstance(FlareUrlResolver::class);
    $this->app->instance(CredentialStore::class, $store);
    $this->app->instance(FlareUrlResolver::class, new FlareUrlResolver);

    (new AppServiceProvider($this->app))->boot();

    $registration = OpenApiCli::getRegistrations()[0];

    expect($registration->getSpecPath())->toBe('https://flareapp.io/downloads/flare-api.yaml');
    expect($registration->getBaseUrl())->toBe('https://staging.flareapp.io/api');

    $store->setToken('late-staging-token');

    expect(($registration->getAuthCallable())())->toBe('late-staging-token');
});

it('retries on 401 by calling forceRefresh and skips other status codes', function () {
    $store = Mockery::mock(CredentialStore::class)->makePartial();
    $store->shouldReceive('forceRefresh')->once()->andReturn(true);

    OpenApiCli::clearRegistrations();
    app()->forgetInstance(CredentialStore::class);
    $this->app->instance(CredentialStore::class, $store);

    (new AppServiceProvider($this->app))->boot();

    $registration = OpenApiCli::getRegistrations()[0];
    $retry = $registration->getRetryCallable();

    expect($retry)->not->toBeNull();
    expect($registration->getRetryMaxRetries())->toBe(1);

    $response500 = new Illuminate\Http\Client\Response(
        new Response(500, [], ''),
    );
    $response401 = new Illuminate\Http\Client\Response(
        new Response(401, [], ''),
    );

    expect($retry($response500))->toBeFalse();
    expect($retry($response401))->toBeTrue();
});

it('does not retry on 401 when forceRefresh fails', function () {
    $store = Mockery::mock(CredentialStore::class)->makePartial();
    $store->shouldReceive('forceRefresh')->once()->andReturn(false);

    OpenApiCli::clearRegistrations();
    app()->forgetInstance(CredentialStore::class);
    $this->app->instance(CredentialStore::class, $store);

    (new AppServiceProvider($this->app))->boot();

    $retry = OpenApiCli::getRegistrations()[0]->getRetryCallable();

    $response401 = new Illuminate\Http\Client\Response(
        new Response(401, [], ''),
    );

    expect($retry($response401))->toBeFalse();
});
