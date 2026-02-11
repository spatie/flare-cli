# Flare CLI

## Overview

Flare CLI is a standalone command-line tool for [Flare](https://flareapp.io), Spatie's error tracking and performance monitoring service. It lets developers interact with the Flare REST API from the terminal — listing projects and errors, resolving/snoozing errors, viewing error occurrences, and managing teams.

The CLI is built on **Laravel Zero** and uses **spatie/laravel-openapi-cli** to auto-generate artisan commands from the Flare OpenAPI spec. Users install it globally via Composer and authenticate with their personal Flare API token.

**Who it's for:** Developers who use Flare and want to triage errors, check project status, or automate Flare workflows from the command line or CI scripts.

**How it fits in:** This is separate from `spatie/laravel-flare` (the error reporter SDK). The CLI consumes the Flare API as an external client using a user's API token, not a project key.

## User Stories

### US-001: Project scaffolding and dependency setup
**Priority:** 1
**Description:** As a developer, I want the Laravel Zero project properly configured with the correct package identity, dependencies, and local development setup so that I can build and test the CLI.

**Acceptance Criteria:**
- [ ] `composer.json` updated: `name` set to `spatie/flare-cli`, description, keywords, authors, homepage reflect Flare/Spatie
- [ ] PHP requirement raised to `^8.4` (to match `spatie/laravel-openapi-cli` requirement)
- [ ] `composer.json` has a `repositories` entry with `type: path` pointing to `../laravel-openapi-cli` with `symlink: true`
- [ ] `spatie/laravel-openapi-cli: *` added to `require`
- [ ] `spatie/laravel-package-tools` is pulled in transitively (no manual require needed)
- [ ] `composer update` succeeds and `vendor/spatie/laravel-openapi-cli` is a symlink to the local package
- [ ] The `InspireCommand` example command is removed
- [ ] The example test `InspireCommandTest` is removed
- [ ] `config/app.php` updated: `name` set to `'Flare'`
- [ ] Binary entry point renamed from `flare-cli` to `flare` (file at project root), `bin` field in `composer.json` updated to `["flare"]`

### US-002: Credential storage
**Priority:** 2
**Description:** As a developer, I want a credential storage service that reads and writes my Flare API token to `~/.flare/config.json` so that the CLI can persist my authentication across sessions.

**Acceptance Criteria:**
- [ ] A `CredentialStore` class exists at `app/Services/CredentialStore.php`
- [ ] It stores credentials in `~/.flare/config.json` (using `$_SERVER['HOME']` with `$_SERVER['USERPROFILE']` fallback for Windows)
- [ ] `ensureConfigDirectoryExists()` creates `~/.flare/` with `0755` permissions if it doesn't exist
- [ ] `getToken(): ?string` reads the stored API token (returns null if no file or no token key)
- [ ] `setToken(string $token): void` writes the token to the JSON file
- [ ] `flush(): void` clears all stored credentials (writes `{}` to the file)
- [ ] The class is registered as a singleton in `AppServiceProvider`
- [ ] The JSON file uses pretty-print formatting for human readability

**Implementation notes:**

Follow the forge-cli `ConfigRepository` pattern. The class should be simple:

```php
namespace App\Services;

class CredentialStore
{
    protected array $config = [];

    public function __construct()
    {
        $this->ensureConfigDirectoryExists();
        if (file_exists($this->path())) {
            $this->config = json_decode(file_get_contents($this->path()), true) ?: [];
        }
    }

    public function getToken(): ?string
    {
        return $this->config['token'] ?? null;
    }

    public function setToken(string $token): void
    {
        $this->config['token'] = $token;
        file_put_contents($this->path(), json_encode($this->config, JSON_PRETTY_PRINT));
    }

    public function flush(): void
    {
        $this->config = [];
        file_put_contents($this->path(), json_encode($this->config, JSON_PRETTY_PRINT));
    }

    public function path(): string
    {
        return $this->directory() . '/config.json';
    }

    public function directory(): string
    {
        return ($_SERVER['HOME'] ?? $_SERVER['USERPROFILE']) . '/.flare';
    }

    protected function ensureConfigDirectoryExists(): void
    {
        $dir = $this->directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
```

### US-003: Login command
**Priority:** 3
**Description:** As a developer, I want to run `flare login` to store my Flare API token so that I can authenticate all subsequent API commands.

**Acceptance Criteria:**
- [ ] A `LoginCommand` exists at `app/Commands/LoginCommand.php`
- [ ] Command signature is `login`
- [ ] Prompts the user to paste their API token (using `$this->secret()` to hide input)
- [ ] Validates the token by calling `GET https://flareapp.io/api/me` with the provided token as a Bearer token
- [ ] On success: stores the token via `CredentialStore::setToken()`, displays "Logged in as {name}" using the name from the API response
- [ ] On failure (non-2xx response): shows an error message ("Invalid API token."), does NOT store the token
- [ ] On network error: shows a connection error message
- [ ] Uses Laravel's `Http` facade for the validation request

**Implementation notes:**

```php
namespace App\Commands;

use App\Services\CredentialStore;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class LoginCommand extends Command
{
    protected $signature = 'login';
    protected $description = 'Authenticate with Flare';

    public function handle(CredentialStore $credentials): int
    {
        $token = $this->secret('Your Flare API token (from https://flareapp.io/settings/api-tokens)');

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->get('https://flareapp.io/api/me');
        } catch (\Exception $e) {
            $this->error('Could not connect to Flare. Please check your internet connection.');
            return self::FAILURE;
        }

        if (! $response->successful()) {
            $this->error('Invalid API token.');
            return self::FAILURE;
        }

        $credentials->setToken($token);
        $name = $response->json('name');
        $this->info("Logged in as {$name}");

        return self::SUCCESS;
    }
}
```

### US-004: Logout command
**Priority:** 3
**Description:** As a developer, I want to run `flare logout` to clear my stored credentials.

**Acceptance Criteria:**
- [ ] A `LogoutCommand` exists at `app/Commands/LogoutCommand.php`
- [ ] Command signature is `logout`
- [ ] Calls `CredentialStore::flush()` to clear stored credentials
- [ ] Displays a confirmation message: "Logged out successfully."

### US-005: OpenAPI spec bundling
**Priority:** 4
**Description:** As a developer, I want the Flare OpenAPI spec YAML file bundled inside the application so that the openapi-cli package can read it at runtime (including from within a PHAR).

**Acceptance Criteria:**
- [ ] The Flare OpenAPI spec exists at `resources/openapi/flare-api.yaml`
- [ ] The spec is a copy of the file from `/Users/alex/Projects/flareapp.io/public/downloads/flare-api.yaml`
- [ ] `box.json` updated to include `"resources"` in the `directories` array (required for PHAR bundling)
- [ ] `resource_path('openapi/flare-api.yaml')` resolves correctly both in development and inside a PHAR

### US-006: OpenAPI command registration
**Priority:** 5
**Description:** As a developer, I want all Flare API endpoints automatically registered as artisan commands so that I can interact with every endpoint from the CLI.

**Acceptance Criteria:**
- [ ] `Spatie\OpenApiCli\OpenApiCliServiceProvider` is manually registered in `config/app.php` providers array (Laravel Zero disables package auto-discovery)
- [ ] In `AppServiceProvider::boot()`, `OpenApiCli::register()` is called with the spec path, `'flare'` prefix, base URL, operation ID mode, auth callable, and banner
- [ ] Uses `.useOperationIds()` so commands are named from `operationId` fields (e.g., `flare:list-projects`, `flare:resolve-error`)
- [ ] Uses `.auth()` with a closure that reads the token from `CredentialStore` — this evaluates lazily per-request
- [ ] Running `flare flare:list` shows all available API commands
- [ ] The following commands are registered (from the spec's operationIds):

| Command | Method | Description |
|---------|--------|-------------|
| `flare:get-authenticated-user` | GET | Get the authenticated user |
| `flare:list-projects` | GET | Get all projects |
| `flare:create-project` | POST | Create a new project |
| `flare:delete-project` | DELETE | Delete a project |
| `flare:list-project-errors` | GET | Get all errors within a project |
| `flare:get-project-error-count` | GET | Get error count in period |
| `flare:get-project-error-occurrence-count` | GET | Get error occurrence count in period |
| `flare:list-error-occurrences` | GET | Get all occurrences for an error |
| `flare:get-error-occurrence` | GET | Get an error occurrence |
| `flare:resolve-error` | POST | Resolve an error |
| `flare:unresolve-error` | POST | Reopen an error |
| `flare:snooze-error` | POST | Snooze an error |
| `flare:unsnooze-error` | POST | Unsnooze an error |
| `flare:get-team` | GET | Get a team |
| `flare:remove-team-user` | DELETE | Remove a user from a team |
| `flare:list` | — | List all available API commands |

**Implementation notes:**

The `.auth()` method accepts a callable that is invoked per-request (see `EndpointCommand::applyAuthentication()`). This is the correct hook for dynamic credential resolution. The `.bearer()` method only accepts a static string.

```php
// In AppServiceProvider::boot()

use Spatie\OpenApiCli\Facades\OpenApiCli;

OpenApiCli::register(resource_path('openapi/flare-api.yaml'), 'flare')
    ->baseUrl('https://flareapp.io/api')
    ->useOperationIds()
    ->auth(function () {
        $token = app(CredentialStore::class)->getToken();

        if ($token === null) {
            throw new \RuntimeException(
                "No API key found. Run `flare login` to authenticate.\n\n"
                . "  You can create an API token at: https://flareapp.io/settings/api-tokens"
            );
        }

        return $token;
    })
    ->banner(function ($command) {
        // See US-007 for banner implementation
    });
```

When the auth callable throws, Laravel's exception handler will display the error message and exit with a non-zero code. This handles the "unauthenticated usage" case centrally for all API commands without duplicating logic.

### US-007: Branded banner for `flare:list`
**Priority:** 6
**Description:** As a developer, I want to see a branded Flare ASCII art banner when I run `flare flare:list` so that the CLI feels polished and professional.

**Acceptance Criteria:**
- [ ] The `flare:list` command displays an ASCII "FLARE" banner with a gradient color scheme before the endpoint list
- [ ] A tagline bar is displayed below the banner: `✦ Catch errors. Fix slowdowns. :: flareapp.io ✦`
- [ ] The banner uses the callable form of `.banner()` (receives the `$command` instance)
- [ ] The banner only appears in the `flare:list` output, not individual endpoint commands (this is handled by the openapi-cli package automatically)

**Implementation notes:**

The banner callable is passed to `.banner()` during registration (US-006). Use ANSI 256-color escape codes:

```php
->banner(function ($command) {
    $lines = [
        '  ███████╗ ██╗       █████╗  ██████╗  ███████╗',
        '  ██╔════╝ ██║      ██╔══██╗ ██╔══██╗ ██╔════╝',
        '  █████╗   ██║      ███████║ ██████╔╝ █████╗  ',
        '  ██╔══╝   ██║      ██╔══██║ ██╔══██╗ ██╔══╝  ',
        '  ██║      ███████╗ ██║  ██║ ██║  ██║ ███████╗',
        '  ╚═╝      ╚══════╝ ╚═╝  ╚═╝ ╚═╝  ╚═╝ ╚══════╝',
    ];

    $gradient = [49, 43, 37, 99, 135, 93];

    $command->newLine();
    foreach ($lines as $index => $line) {
        $command->getOutput()->writeln("\e[38;5;{$gradient[$index]}m{$line}\e[0m");
    }
    $command->newLine();

    $tagline = ' ✦ Catch errors. Fix slowdowns. :: flareapp.io ✦ ';
    $primary = $gradient[0];
    $command->getOutput()->writeln("\e[48;5;{$primary}m\e[30m\e[1m{$tagline}\e[0m");
    $command->newLine();
})
```

### US-008: PHAR build and distribution setup
**Priority:** 7
**Description:** As a maintainer, I want the project configured for PHAR building and Composer global distribution so that users can install the CLI with `composer global require spatie/flare-cli`.

**Acceptance Criteria:**
- [ ] `box.json` includes `"resources"` in `directories` array (for the OpenAPI spec YAML)
- [ ] Running `php flare app:build flare` produces a working PHAR in `builds/flare`
- [ ] The built PHAR can execute `login`, `logout`, `flare:list`, and API commands correctly
- [ ] For Packagist distribution: `composer.json` should eventually have `laravel-zero/framework` in `require-dev` (not `require`) and `bin` pointing to `["builds/flare"]` — but this change is only for the release branch/process, not for development
- [ ] The `repositories` path entry (for local openapi-cli dev) should be removed before publishing to Packagist

**Implementation notes:**

The distribution pattern follows `laravel/forge-cli` exactly:

- During development: `laravel-zero/framework` is in `require`, `bin` points to `flare`
- For release: move `laravel-zero/framework` to `require-dev`, build the PHAR, change `bin` to `["builds/flare"]`

The `box.json` should be:

```json
{
    "chmod": "0755",
    "directories": [
        "app",
        "bootstrap",
        "config",
        "resources",
        "vendor"
    ],
    "files": [
        "composer.json"
    ],
    "exclude-composer-files": false,
    "compression": "GZ",
    "compactors": [
        "KevinGH\\Box\\Compactor\\Php",
        "KevinGH\\Box\\Compactor\\Json"
    ]
}
```

### US-009: Tests
**Priority:** 8
**Description:** As a developer, I want integration tests covering the core CLI functionality so that I can catch regressions.

**Acceptance Criteria:**
- [ ] Tests use Pest (already configured in the project)
- [ ] `tests/Feature/LoginCommandTest.php`: test successful login stores credentials, test invalid token shows error and doesn't store, test network error shows connection message
- [ ] `tests/Feature/LogoutCommandTest.php`: test logout clears stored credentials and shows confirmation
- [ ] `tests/Feature/CredentialStoreTest.php`: test read/write/flush cycle using a temp directory (override the path in tests)
- [ ] `tests/Feature/UnauthenticatedCommandTest.php`: test that running an API command without stored credentials shows the "run `flare login`" message and exits with non-zero code
- [ ] `tests/Feature/CommandRegistrationTest.php`: smoke test that key commands are registered and discoverable (e.g., `flare:list-projects`, `flare:resolve-error`, `flare:list` exist as artisan commands)
- [ ] `tests/Feature/BannerTest.php`: test that `flare:list` output contains expected banner text elements (e.g., the tagline string)
- [ ] All tests use `Http::fake()` for API calls
- [ ] Tests that touch credential storage use a temp directory (not the real `~/.flare`)

**Implementation notes:**

For credential store tests, make the directory configurable or bind a test double:

```php
// In test setup
$tempDir = sys_get_temp_dir() . '/flare-cli-test-' . uniqid();
mkdir($tempDir, 0755, true);
$store = new CredentialStore($tempDir . '/config.json');
$this->app->instance(CredentialStore::class, $store);
```

This means `CredentialStore` should accept an optional path override in its constructor to support testing.

## Architecture

### Project structure

```
flare-cli/
├── app/
│   ├── Commands/
│   │   ├── LoginCommand.php          # flare login
│   │   └── LogoutCommand.php         # flare logout
│   ├── Providers/
│   │   └── AppServiceProvider.php    # Registers CredentialStore + OpenApiCli
│   └── Services/
│       └── CredentialStore.php       # ~/.flare/config.json read/write
├── bootstrap/
│   ├── app.php
│   └── providers.php
├── config/
│   ├── app.php                       # App name, version, providers
│   └── commands.php                  # Command paths, hidden commands
├── resources/
│   └── openapi/
│       └── flare-api.yaml            # Bundled Flare OpenAPI spec
├── tests/
│   ├── Feature/
│   │   ├── LoginCommandTest.php
│   │   ├── LogoutCommandTest.php
│   │   ├── CredentialStoreTest.php
│   │   ├── UnauthenticatedCommandTest.php
│   │   ├── CommandRegistrationTest.php
│   │   └── BannerTest.php
│   ├── Pest.php
│   └── TestCase.php
├── box.json
├── composer.json
├── flare                             # CLI entry point (renamed from flare-cli)
├── phpunit.xml.dist
├── CLAUDE.md
└── README.md
```

### How the pieces fit together

```
User runs: flare flare:list-projects --page-number=2

    ┌─────────────────────────────────────────────────┐
    │  flare (entry point)                            │
    │  └─ Laravel Zero Kernel boots the app           │
    │     └─ AppServiceProvider::boot()               │
    │        └─ OpenApiCli::register(spec, 'flare')   │
    │           └─ .auth(closure)                     │
    │           └─ .useOperationIds()                 │
    │           └─ .banner(closure)                   │
    │                                                 │
    │  OpenApiCliServiceProvider::packageBooted()     │
    │  └─ Parses flare-api.yaml                       │
    │  └─ Registers EndpointCommand per operation     │
    │  └─ Registers ListCommand for flare:list        │
    └─────────────────────────────────────────────────┘
                        │
                        ▼
    ┌─────────────────────────────────────────────────┐
    │  EndpointCommand::handle()                      │
    │  └─ applyAuthentication()                       │
    │     └─ calls .auth() closure                    │
    │        └─ CredentialStore::getToken()            │
    │           └─ reads ~/.flare/config.json          │
    │              └─ returns token or throws          │
    │  └─ Builds URL from spec path + options         │
    │  └─ Sends HTTP request via Laravel Http facade  │
    │  └─ Outputs formatted JSON response             │
    └─────────────────────────────────────────────────┘
```

### Key design decisions

1. **`.auth()` closure over `.bearer()` string**: The `bearer()` method on `CommandConfiguration` accepts a static string. Using `.auth(callable)` instead allows lazy evaluation per-request — the token is read from disk only when a command runs, and missing credentials produce a clear error message via a thrown exception.

2. **Manual service provider registration**: Laravel Zero disables Composer package auto-discovery (`PackageManifest::manifest = []`). The `OpenApiCliServiceProvider` must be listed explicitly in `config/app.php`'s `providers` array.

3. **Spec bundled in `resources/`**: The YAML spec lives at `resources/openapi/flare-api.yaml` and is accessed via `resource_path()`. This works in development and inside PHARs (resolves to `phar://` path). The `resources` directory must be added to `box.json` `directories`.

4. **Credential storage in `~/.flare/`**: Follows the established pattern from `laravel/forge-cli` (`~/.forge/config.json`). A dedicated directory allows future expansion (e.g., storing multiple profiles, cache).

## Configuration

### `composer.json` (development)

```json
{
    "name": "spatie/flare-cli",
    "description": "Interact with Flare from the command line.",
    "keywords": ["flare", "error-tracking", "cli", "spatie"],
    "homepage": "https://flareapp.io",
    "type": "project",
    "license": "MIT",
    "authors": [
        {
            "name": "Spatie",
            "email": "info@spatie.be"
        }
    ],
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-openapi-cli",
            "options": {
                "symlink": true
            }
        }
    ],
    "require": {
        "php": "^8.4",
        "laravel-zero/framework": "^12.0.2",
        "spatie/laravel-openapi-cli": "*"
    },
    "require-dev": {
        "laravel/pint": "^1.25.1",
        "mockery/mockery": "^1.6.12",
        "pestphp/pest": "^3.8.4|^4.1.2"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "config": {
        "preferred-install": "dist",
        "sort-packages": true,
        "optimize-autoloader": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "bin": ["flare"]
}
```

### `config/app.php`

```php
<?php

return [
    'name' => 'Flare',
    'version' => app('git.version'),
    'env' => 'development',
    'providers' => [
        App\Providers\AppServiceProvider::class,
        Spatie\OpenApiCli\OpenApiCliServiceProvider::class,
    ],
];
```

### `box.json`

```json
{
    "chmod": "0755",
    "directories": [
        "app",
        "bootstrap",
        "config",
        "resources",
        "vendor"
    ],
    "files": [
        "composer.json"
    ],
    "exclude-composer-files": false,
    "compression": "GZ",
    "compactors": [
        "KevinGH\\Box\\Compactor\\Php",
        "KevinGH\\Box\\Compactor\\Json"
    ]
}
```

## Future considerations (out of scope)

- **Self-update command**: Laravel Zero has a built-in updater component. Could enable `flare self-update` to download the latest PHAR from GitHub Releases.
- **Standalone binary via PHPacker**: Distribute a native binary (no PHP required) using PHPacker.
- **Output formatting**: Table output for list commands, colored status indicators, relative timestamps.
- **Shell completions**: Bash/Zsh/Fish completion scripts for command and option names.
- **Multiple profiles**: Support multiple Flare accounts/tokens with named profiles.
