<?php

use App\Services\CredentialStore;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Override HOME so CredentialStore uses temp directory
    $_SERVER['HOME'] = $this->tempDir;

    $this->store = new CredentialStore;
});

afterEach(function () {
    // Clean up temp directory
    $configFile = $this->tempDir.'/.flare/config.json';
    if (file_exists($configFile)) {
        unlink($configFile);
    }
    if (is_dir($this->tempDir.'/.flare')) {
        rmdir($this->tempDir.'/.flare');
    }
    if (is_dir($this->tempDir)) {
        rmdir($this->tempDir);
    }
});

it('returns null when no config file exists', function () {
    expect($this->store->getToken())->toBeNull();
});

it('stores and retrieves a token', function () {
    $this->store->setToken('test-api-token-123');

    expect($this->store->getToken())->toBe('test-api-token-123');
});

it('overwrites an existing token', function () {
    $this->store->setToken('first-token');
    $this->store->setToken('second-token');

    expect($this->store->getToken())->toBe('second-token');
});

it('flushes stored credentials', function () {
    $this->store->setToken('test-token');
    $this->store->flush();

    expect($this->store->getToken())->toBeNull();
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
    expect(json_decode($contents, true))->toBe(['token' => 'test-token']);
});
