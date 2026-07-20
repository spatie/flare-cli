<?php

use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir().'/flare-cli-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);
    $_SERVER['HOME'] = $this->tempDir;

    $this->store = new CredentialStore;
    $this->app->instance(CredentialStore::class, $this->store);
});

afterEach(function () {
    cleanupFlareHome($this->tempDir);
});

it('shows an error when running an API command without credentials', function () {
    Http::fake([
        'flareapp.io/api/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    $this->artisan('list-projects')
        ->assertExitCode(1);
});
