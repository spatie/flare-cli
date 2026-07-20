<?php

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\DeviceAuthorization;
use App\Services\OAuth\DeviceLoginFlow;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\PkceLoginFlow;
use App\Services\OAuth\TokenRecord;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    $this->store = new CredentialStore(new FlareUrlResolver);
    $this->app->instance(CredentialStore::class, $this->store);
});

afterEach(function () {
    if ($this->originalBaseUrl === null) {
        putenv('FLARE_BASE_URL');
        unset($_SERVER['FLARE_BASE_URL']);
    } else {
        putenv("FLARE_BASE_URL={$this->originalBaseUrl}");
        $_SERVER['FLARE_BASE_URL'] = $this->originalBaseUrl;
    }

    cleanupFlareHome($this->tempDir);
});

function loginRecord(array $overrides = []): TokenRecord
{
    return TokenRecord::fromArray(array_merge([
        'access_token' => 'pkce-access',
        'refresh_token' => 'pkce-refresh',
        'expires_at' => time() + 1296000,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => time(),
    ], $overrides));
}

it('stores credentials on successful login with --token', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response([
            'id' => 20,
            'name' => 'Alex',
            'email' => 'alex@spatie.be',
            'teams' => [],
        ]),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'valid-token-123')
        ->expectsOutputToContain('Successfully logged in as alex@spatie.be')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBe('valid-token-123');
});

it('rejects a --token when /me responds with a non-JSON page', function () {
    // An invalid token used to get redirected to the HTML login page, which the
    // HTTP client follows to a 200 — and login reported success as "unknown".
    Http::fake([
        'flareapp.io/api/me' => Http::response('<!DOCTYPE html><html><body>Log in</body></html>', 200, [
            'Content-Type' => 'text/html',
        ]),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'invalid-token')
        ->expectsOutput('Invalid API token.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});

it('sends an Accept json header when validating a --token', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'valid-token-123')
        ->assertExitCode(0);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://flareapp.io/api/me'
            && $request->hasHeader('Accept', 'application/json');
    });
});

it('shows error and does not store token on invalid --token input', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'invalid-token')
        ->expectsOutput('Invalid API token.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});

it('validates the --token against the active base URL', function () {
    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api/');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api/';

    Http::fake([
        'staging.flareapp.io/api/me' => Http::response([
            'email' => 'alex+staging@spatie.be',
        ]),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'staging-token-123')
        ->expectsOutputToContain('https://staging.flareapp.io/api')
        ->expectsOutputToContain('https://staging.flareapp.io/account/api-access')
        ->expectsOutputToContain('Successfully logged in as alex+staging@spatie.be')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBe('staging-token-123');
});

it('shows connection error on --token network failure', function () {
    Http::fake([
        'flareapp.io/api/me' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'some-token')
        ->expectsOutput('Could not connect to Flare. Please check your internet connection.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});

it('warns when --token replaces an existing OAuth record', function () {
    $this->store->setRecord(loginRecord());

    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $this->artisan('login --token')
        ->expectsQuestion('Enter your Flare API token', 'replacement-pat')
        ->expectsOutputToContain('A browser-based OAuth session already exists')
        ->expectsOutputToContain('Successfully logged in as alex@spatie.be')
        ->assertExitCode(0);

    expect($this->store->getToken())->toBe('replacement-pat');
    expect($this->store->getRecord())->toBeNull();
});

it('completes the PKCE browser flow and stores the OAuth record', function () {
    $record = loginRecord(['access_token' => 'browser-access']);

    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $flow = Mockery::mock(PkceLoginFlow::class);
    $flow->shouldReceive('run')->once()->andReturn($record);
    $this->app->instance(PkceLoginFlow::class, $flow);

    $this->artisan('login')
        ->expectsOutputToContain('Opening your browser')
        ->expectsOutputToContain('Successfully logged in as alex@spatie.be')
        ->assertExitCode(0);

    expect($this->store->getRecord()?->accessToken)->toBe('browser-access');
});

it('reports email as unknown if /me fails after a successful PKCE exchange', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response([], 500),
    ]);

    $flow = Mockery::mock(PkceLoginFlow::class);
    $flow->shouldReceive('run')->once()->andReturn(loginRecord());
    $this->app->instance(PkceLoginFlow::class, $flow);

    $this->artisan('login')
        ->expectsOutputToContain('Successfully logged in as unknown')
        ->assertExitCode(0);

    expect($this->store->getRecord())->not->toBeNull();
});

it('shows the OAuth error and does not store a record when PKCE fails', function () {
    $flow = Mockery::mock(PkceLoginFlow::class);
    $flow->shouldReceive('run')->once()->andThrow(new OAuthException('state did not match'));
    $this->app->instance(PkceLoginFlow::class, $flow);

    $this->artisan('login')
        ->expectsOutputToContain('state did not match')
        ->assertExitCode(1);

    expect($this->store->getRecord())->toBeNull();
});

it('completes the device code flow and stores the OAuth record', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $device = Mockery::mock(DeviceLoginFlow::class);
    $device->shouldReceive('run')
        ->once()
        ->andReturnUsing(function ($announce) {
            $announce(DeviceAuthorization::fromArray([
                'device_code' => 'dev-code',
                'user_code' => 'ABCD-EFGH',
                'verification_uri' => 'https://flareapp.io/oauth/device',
                'expires_in' => 600,
                'interval' => 5,
            ]));

            return loginRecord(['access_token' => 'device-access']);
        });
    $this->app->instance(DeviceLoginFlow::class, $device);

    $this->artisan('login --device')
        ->expectsOutputToContain('ABCD-EFGH')
        ->expectsOutputToContain('https://flareapp.io/oauth/device')
        ->expectsOutputToContain('Successfully logged in as alex@spatie.be')
        ->assertExitCode(0);

    expect($this->store->getRecord()?->accessToken)->toBe('device-access');
});

it('reports the error and does not store a record when device flow fails', function () {
    $device = Mockery::mock(DeviceLoginFlow::class);
    $device->shouldReceive('run')->once()->andThrow(new OAuthException('access_denied'));
    $this->app->instance(DeviceLoginFlow::class, $device);

    $this->artisan('login --device')
        ->expectsOutputToContain('access_denied')
        ->assertExitCode(1);

    expect($this->store->getRecord())->toBeNull();
});

it('falls back to device flow when the terminal is non-interactive', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $device = Mockery::mock(DeviceLoginFlow::class);
    $device->shouldReceive('run')->once()->andReturn(loginRecord(['access_token' => 'fallback-access']));
    $this->app->instance(DeviceLoginFlow::class, $device);

    $pkce = Mockery::mock(PkceLoginFlow::class);
    $pkce->shouldNotReceive('run');
    $this->app->instance(PkceLoginFlow::class, $pkce);

    $this->artisan('login --no-interaction')
        ->expectsOutputToContain('Non-interactive terminal detected')
        ->expectsOutputToContain('Successfully logged in')
        ->assertExitCode(0);

    expect($this->store->getRecord()?->accessToken)->toBe('fallback-access');
});

it('passes --name to the browser OAuth flow', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $flow = Mockery::mock(PkceLoginFlow::class);
    $flow->shouldReceive('run')
        ->once()
        ->withArgs(fn (...$arguments): bool => $arguments[4] === 'Alex Laptop')
        ->andReturn(loginRecord());
    $this->app->instance(PkceLoginFlow::class, $flow);

    $this->artisan('login --name="Alex Laptop"')
        ->assertExitCode(0);
});

it('passes --name to the device OAuth flow', function () {
    Http::fake([
        'flareapp.io/api/me' => Http::response(['email' => 'alex@spatie.be']),
    ]);

    $device = Mockery::mock(DeviceLoginFlow::class);
    $device->shouldReceive('run')
        ->once()
        ->withArgs(fn (...$arguments): bool => $arguments[3] === 'Build Server')
        ->andReturn(loginRecord());
    $this->app->instance(DeviceLoginFlow::class, $device);

    $this->artisan('login --device --name="Build Server"')
        ->assertExitCode(0);
});

it('rejects --name with personal token login', function () {
    $this->artisan('login --token --name="Not applicable"')
        ->expectsOutput('The --name option is only available for OAuth login.')
        ->assertExitCode(1);

    expect($this->store->getToken())->toBeNull();
});
