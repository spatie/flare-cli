<?php

use App\Services\CredentialStore;
use App\Services\FlareUrlResolver;
use App\Services\OAuth\OAuthException;
use App\Services\OAuth\TokenRecord;
use App\Services\OAuth\TokenRefresher;

function makeRecord(array $overrides = []): TokenRecord
{
    return TokenRecord::fromArray(array_merge([
        'access_token' => 'access-abc',
        'refresh_token' => 'refresh-xyz',
        'expires_at' => 1_700_000_000,
        'scopes' => ['read', 'write'],
        'client_id' => 'client-uuid',
        'obtained_at' => 1_699_999_000,
    ], $overrides));
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Override HOME so CredentialStore uses temp directory
    $_SERVER['HOME'] = $this->tempDir;

    $this->originalBaseUrl = getenv('FLARE_BASE_URL') ?: null;
    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    $this->resolver = new FlareUrlResolver;
    $this->store = new CredentialStore($this->resolver);
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

it('returns null when no config file exists', function () {
    expect($this->store->getToken())->toBeNull();
});

it('stores and retrieves a token', function () {
    $this->store->setToken('test-api-token-123');

    expect($this->store->getToken())->toBe('test-api-token-123');
});

it('stores tokens per host context', function () {
    $this->store->setToken('first-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setToken('second-token');

    expect($stagingStore->getToken())->toBe('second-token');

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    expect((new CredentialStore(new FlareUrlResolver))->getToken())->toBe('first-token');
});

it('flushes only the active host context', function () {
    $this->store->setToken('production-token');

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);
    $stagingStore->setToken('staging-token');
    $stagingStore->flush();

    expect($stagingStore->getToken())->toBeNull();

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    expect((new CredentialStore(new FlareUrlResolver))->getToken())->toBe('production-token');
});

it('does not create a config file when flushing without stored credentials', function () {
    $this->store->flush();

    expect(file_exists($this->tempDir.'/.flare/config.json'))->toBeFalse();
});

it('creates the config directory if it does not exist', function () {
    $configDir = $this->tempDir.'/.flare';

    expect(is_dir($configDir))->toBeFalse();

    $this->store->setToken('test-token');

    expect(is_dir($configDir))->toBeTrue();
});

it('writes pretty-printed JSON', function () {
    $this->store->setToken('test-token');

    $configFile = $this->tempDir.'/.flare/config.json';
    $contents = file_get_contents($configFile);

    expect($contents)->toContain("\n");
    $config = json_decode($contents, true);
    $profileKey = $config['active_profiles']['https://flareapp.io/api'];

    expect($config['profiles'][$profileKey])->toBe([
        'issuer' => null,
        'api_base_url' => 'https://flareapp.io/api',
        'credential' => 'test-token',
    ]);
});

it('falls back to the legacy production token', function () {
    mkdir($this->tempDir.'/.flare', 0755, true);

    file_put_contents(
        $this->tempDir.'/.flare/config.json',
        json_encode(['token' => 'legacy-production-token'], JSON_PRETTY_PRINT),
    );

    expect($this->store->getToken())->toBe('legacy-production-token');
    expect(array_column($this->store->getConfiguredProfiles(), 'api_base_url'))
        ->toBe(['https://flareapp.io/api']);
});

it('does not apply the legacy production token to other hosts', function () {
    mkdir($this->tempDir.'/.flare', 0755, true);

    file_put_contents(
        $this->tempDir.'/.flare/config.json',
        json_encode(['token' => 'legacy-production-token'], JSON_PRETTY_PRINT),
    );

    putenv('FLARE_BASE_URL=https://ingress-staging.flareapp.io/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://ingress-staging.flareapp.io/api';

    $stagingStore = new CredentialStore(new FlareUrlResolver);

    expect($stagingStore->getToken())->toBeNull();
    expect(array_column($stagingStore->getConfiguredProfiles(), 'api_base_url'))
        ->toBe(['https://flareapp.io/api']);
});

it('stores and retrieves an OAuth record', function () {
    $record = makeRecord();

    $this->store->setRecord($record);

    expect($this->store->getRecord())->toEqual($record);
    expect($this->store->getToken())->toBe('access-abc');
});

it('returns the access token via getToken for OAuth records', function () {
    $this->store->setRecord(makeRecord(['access_token' => 'fresh-access']));

    expect($this->store->getToken())->toBe('fresh-access');
    expect($this->store->getRecord()?->accessToken)->toBe('fresh-access');
});

it('returns null from getRecord when only a legacy string is stored', function () {
    $this->store->setToken('plain-pat-token');

    expect($this->store->getRecord())->toBeNull();
    expect($this->store->getToken())->toBe('plain-pat-token');
});

it('allows a string token and an OAuth record to coexist for different hosts', function () {
    $this->store->setToken('production-pat');

    putenv('FLARE_BASE_URL=https://passport-oauth.test/api');
    $_SERVER['FLARE_BASE_URL'] = 'https://passport-oauth.test/api';

    $oauthStore = new CredentialStore(new FlareUrlResolver);
    $oauthStore->setRecord(makeRecord());

    expect($oauthStore->getRecord()?->accessToken)->toBe('access-abc');
    expect($oauthStore->getToken())->toBe('access-abc');

    putenv('FLARE_BASE_URL');
    unset($_SERVER['FLARE_BASE_URL']);

    $productionStore = new CredentialStore(new FlareUrlResolver);
    expect($productionStore->getToken())->toBe('production-pat');
    expect($productionStore->getRecord())->toBeNull();
    expect(array_column($productionStore->getConfiguredProfiles(), 'api_base_url'))
        ->toBe(['https://flareapp.io/api', 'https://passport-oauth.test/api']);
});

it('replaces an OAuth record when setToken is called for the same host', function () {
    $this->store->setRecord(makeRecord());
    $this->store->setToken('replacing-pat');

    expect($this->store->getRecord())->toBeNull();
    expect($this->store->getToken())->toBe('replacing-pat');
});

it('replaces a string token when setRecord is called for the same host', function () {
    $this->store->setToken('old-pat');
    $this->store->setRecord(makeRecord());

    expect($this->store->getToken())->toBe('access-abc');
    expect($this->store->getRecord())->not->toBeNull();
});

it('flushes an OAuth record', function () {
    $this->store->setRecord(makeRecord());
    $this->store->flush();

    expect($this->store->getRecord())->toBeNull();
    expect($this->store->getToken())->toBeNull();
});

it('ignores entries that are neither strings nor OAuth records', function () {
    mkdir($this->tempDir.'/.flare', 0755, true);

    file_put_contents(
        $this->tempDir.'/.flare/config.json',
        json_encode([
            'tokens' => [
                'flareapp.io' => 'valid-pat',
                'bogus.test' => 42,
                'half-baked.test' => ['type' => 'something-else'],
            ],
        ], JSON_PRETTY_PRINT),
    );

    expect(array_column($this->store->getConfiguredProfiles(), 'api_base_url'))
        ->toBe(['https://flareapp.io/api']);
});

it('returns the stored string verbatim via getAccessToken for legacy tokens', function () {
    $this->store->setToken('legacy-pat');

    expect($this->store->getAccessToken())->toBe('legacy-pat');
});

it('refreshes and writes back when getAccessToken finds a near-expiry OAuth record', function () {
    $stale = makeRecord(['expires_at' => time() + 10, 'access_token' => 'stale']);
    $fresh = makeRecord(['expires_at' => time() + 99999, 'access_token' => 'fresh', 'refresh_token' => 'rotated']);

    $this->store->setRecord($stale);

    $refresher = Mockery::mock(TokenRefresher::class);
    $refresher->shouldReceive('shouldRefresh')
        ->once()
        ->with(Mockery::on(fn (TokenRecord $record): bool => $record->accessToken === 'stale'))
        ->andReturnTrue();
    $refresher->shouldReceive('refresh')
        ->once()
        ->andReturn($fresh);

    app()->instance(TokenRefresher::class, $refresher);

    expect($this->store->getAccessToken())->toBe('fresh');
    expect($this->store->getRecord()?->accessToken)->toBe('fresh');
    expect($this->store->getRecord()?->refreshToken)->toBe('rotated');
});

it('does not write back when the record does not need a refresh', function () {
    $record = makeRecord(['expires_at' => time() + 99999]);
    $this->store->setRecord($record);

    $configPath = $this->tempDir.'/.flare/config.json';
    $originalMtime = filemtime($configPath);

    $refresher = Mockery::mock(TokenRefresher::class);
    $refresher->shouldReceive('shouldRefresh')
        ->once()
        ->with(Mockery::on(fn (TokenRecord $candidate): bool => $candidate->accessToken === $record->accessToken))
        ->andReturnFalse();

    app()->instance(TokenRefresher::class, $refresher);

    clearstatcache();
    sleep(1); // ensure mtime would change if a write occurred
    $this->store->getAccessToken();

    clearstatcache();
    expect(filemtime($configPath))->toBe($originalMtime);
});

it('returns true and persists rotated tokens on forceRefresh success', function () {
    $stale = makeRecord(['access_token' => 'stale']);
    $fresh = makeRecord(['access_token' => 'fresh', 'refresh_token' => 'rotated']);

    $this->store->setRecord($stale);

    $refresher = Mockery::mock(TokenRefresher::class);
    $refresher->shouldReceive('refresh')->once()->andReturn($fresh);

    app()->instance(TokenRefresher::class, $refresher);

    expect($this->store->forceRefresh())->toBeTrue();
    expect($this->store->getRecord()?->accessToken)->toBe('fresh');
});

it('returns false from forceRefresh when no OAuth record is stored', function () {
    $this->store->setToken('legacy-pat');

    expect($this->store->forceRefresh())->toBeFalse();
});

it('returns false from forceRefresh when the refresh call fails', function () {
    $this->store->setRecord(makeRecord());

    $refresher = Mockery::mock(TokenRefresher::class);
    $refresher->shouldReceive('refresh')
        ->once()
        ->andThrow(new OAuthException('refresh failed', 'invalid_grant'));

    app()->instance(TokenRefresher::class, $refresher);

    expect($this->store->forceRefresh())->toBeFalse();
});

it('persists an ambiguous refresh attempt and never replays its token', function () {
    $record = makeRecord(['expires_at' => time() - 1]);
    $this->store->setRecord($record);

    $refresher = Mockery::mock(TokenRefresher::class);
    $refresher->shouldReceive('shouldRefresh')->once()->andReturnTrue();
    $refresher->shouldReceive('refresh')
        ->once()
        ->andThrow(new OAuthException('Connection timed out'));

    app()->instance(TokenRefresher::class, $refresher);

    expect($this->store->getAccessToken())->toBe('access-abc');
    expect($this->store->getRecord()?->refreshPending)->toBeTrue();
    expect($this->store->forceRefresh())->toBeFalse();
});

it('writes secure directory config and lock permissions on POSIX systems', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX permission modes are not available on Windows.');
    }

    $this->store->setToken('secure-token');

    $directory = $this->tempDir.'/.flare';
    $config = "{$directory}/config.json";
    $lock = "{$directory}/config.json.lock";

    clearstatcache();

    expect(fileperms($directory) & 0777)->toBe(0700);
    expect(fileperms($config) & 0777)->toBe(0600);
    expect(fileperms($lock) & 0777)->toBe(0600);
});

it('tightens existing credential permissions when touched', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('POSIX permission modes are not available on Windows.');
    }

    $directory = $this->tempDir.'/.flare';
    mkdir($directory, 0777, true);
    file_put_contents("{$directory}/config.json", json_encode([
        'tokens' => ['flareapp.io' => 'legacy-token'],
    ]));
    chmod($directory, 0777);
    chmod("{$directory}/config.json", 0666);

    $this->store->getToken();

    clearstatcache();

    expect(fileperms($directory) & 0777)->toBe(0700);
    expect(fileperms("{$directory}/config.json") & 0777)->toBe(0600);
});

it('atomically migrates an active legacy OAuth profile', function () {
    $directory = $this->tempDir.'/.flare';
    mkdir($directory, 0755, true);
    file_put_contents("{$directory}/config.json", json_encode([
        'tokens' => [
            'flareapp.io' => makeRecord([
                'expires_at' => time() + 99999,
            ])->toArray(),
        ],
    ]));

    $store = new CredentialStore(new FlareUrlResolver);

    expect($store->getAccessToken())->toBe('access-abc');

    $config = json_decode(file_get_contents("{$directory}/config.json"), true);
    $profileKey = $config['active_profiles']['https://flareapp.io/api'];

    expect($config)->not->toHaveKey('tokens');
    expect($config['profiles'])->toHaveCount(1);
    expect($config['profiles'][$profileKey]['issuer'])->toBe('https://flareapp.io');
    expect($config['profiles'][$profileKey]['credential']['refresh_token'])->toBe('refresh-xyz');
});

it('keeps default and custom API paths in separate profiles', function () {
    $defaultStore = new CredentialStore(new FlareUrlResolver('https://self-hosted.test'));
    $customStore = new CredentialStore(new FlareUrlResolver('https://self-hosted.test/custom/api'));

    $defaultStore->setToken('default-token');
    $customStore->setToken('custom-token');

    expect($defaultStore->getToken())->toBe('default-token');
    expect($customStore->getToken())->toBe('custom-token');
    expect($customStore->getConfiguredProfiles())->toHaveCount(2);
});
