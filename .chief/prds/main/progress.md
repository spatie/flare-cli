## Codebase Patterns
- Binary entry point is `flare` at project root (not `flare-cli`)
- `spatie/laravel-openapi-cli` uses `*@dev` constraint since the path repo resolves to `dev-main`
- Laravel Zero app name is configured in `config/app.php` under `'name'`
- Quality checks: `./vendor/bin/pint --test` for lint, `./vendor/bin/pest` for tests
- `CredentialStore` is registered as a singleton in `AppServiceProvider::register()` — use `app(CredentialStore::class)` or inject via constructor
- Run `composer update` from `/Users/alex/Projects/flare-cli` (not the `.chief` subdirectory)
- Laravel Zero doesn't include `illuminate/http` by default — install via `php flare app:install` → `http`
- Commands extend `LaravelZero\Framework\Commands\Command` and use constructor injection for services
- The Flare API token validation endpoint is `GET https://flareapp.io/api/me` with Bearer token
- `$this->secret()` hides user input (for tokens/passwords)
- OpenAPI spec lives at `resources/openapi/flare-api.yaml` — use `resource_path('openapi/flare-api.yaml')` to reference it
- `box.json` `directories` array must include `"resources"` for PHAR bundling
- `OpenApiCli::register(specPath, prefix)` is called in `AppServiceProvider::boot()` — chain `.useOperationIds()` and `.auth()` for config
- `OpenApiCliServiceProvider` must be manually registered in `config/app.php` providers array
- The spec's `servers[0].url` is `https://flareapp.io/api` — no need to override with `.baseUrl()`
- The auth callable `fn () => app(CredentialStore::class)->getToken()` evaluates lazily per-request
- `.banner(callable)` receives the `$command` instance — use `$command->line()` for output, supports ANSI escape codes for colors
- Banner only renders in `{prefix}:list`, not individual endpoint commands (handled by openapi-cli package)
- `ARTISAN_BINARY` must be defined in the `flare` binary before autoload — global-ray's auto_prepend PHAR corrupts `$_SERVER['SCRIPT_FILENAME']`
- Box (PHAR builder) does NOT follow symlinks — use `"symlink": false` in the path repository config so files are mirrored
- `box.json` must have `"main": "flare"` explicitly set for PHAR builds
- The `/builds` directory is gitignored — PHAR output goes to `builds/flare`

---

## 2026-02-10 - US-001
- What was implemented: Project scaffolding and dependency setup
- Files changed:
  - `composer.json` — updated name, description, keywords, homepage, authors, PHP ^8.4, added spatie/laravel-openapi-cli dependency, added path repository for local dev, renamed bin to `flare`
  - `composer.lock` — regenerated with new dependencies
  - `config/app.php` — name changed from `Flare-cli` to `Flare`
  - `flare-cli` → `flare` — renamed binary entry point
  - Deleted: `app/Commands/InspireCommand.php`, `tests/Feature/InspireCommandTest.php`
- **Learnings for future iterations:**
  - The path repository for `../laravel-openapi-cli` resolves to `dev-main`, so need `*@dev` stability flag (not just `*`) since minimum-stability is `stable`
  - `spatie/laravel-package-tools` is pulled in transitively by laravel-openapi-cli — no manual require needed
  - The unit example test (`tests/Unit/ExampleTest.php`) still exists — don't delete it unless asked
---

## 2026-02-10 - US-002
- What was implemented: Credential storage service for persisting Flare API tokens
- Files changed:
  - `app/Services/CredentialStore.php` — new class with `getToken()`, `setToken()`, `flush()` methods, stores to `~/.flare/config.json`
  - `app/Providers/AppServiceProvider.php` — registered `CredentialStore` as singleton
- **Learnings for future iterations:**
  - `CredentialStore` uses `$_SERVER['HOME']` with `$_SERVER['USERPROFILE']` fallback for Windows compatibility
  - The `flush()` method writes `{}` (empty JSON object) rather than deleting the file
  - JSON is written with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` for readability
  - For tests, the config path is hardcoded in the constructor — tests will need to use a temp directory override or mock
---

## 2026-02-10 - US-003
- What was implemented: Login command (`flare login`) that prompts for API token, validates via Flare API, and stores on success
- Files changed:
  - `app/Commands/LoginCommand.php` — new command with token prompt, HTTP validation, credential storage
  - `composer.json` / `composer.lock` — added `illuminate/http` via Laravel Zero's `app:install http`
- **Learnings for future iterations:**
  - Laravel Zero doesn't include `illuminate/http` by default — must install via `app:install http` component
  - `Http::withToken($token)->get(url)` sets the Bearer token header
  - `ConnectionException` is caught for network errors — it's at `Illuminate\Http\Client\ConnectionException`
  - The `$this->secret()` method from `InteractsWithIO` hides terminal input
  - Commands can inject services via method injection in `handle()` (e.g., `handle(CredentialStore $credentials)`)
---

## 2026-02-10 - US-004
- What was implemented: Logout command (`flare logout`) that clears stored credentials
- Files changed:
  - `app/Commands/LogoutCommand.php` — new command that calls `CredentialStore::flush()` and displays confirmation
- **Learnings for future iterations:**
  - Simple commands that just delegate to a service are very straightforward — follow the LoginCommand pattern with method injection in `handle()`
  - The logout command doesn't need HTTP or any validation — just flush and confirm
---

## 2026-02-10 - US-005
- What was implemented: Bundled the Flare OpenAPI spec YAML into the project and configured PHAR build to include it
- Files changed:
  - `resources/openapi/flare-api.yaml` — copied from `/Users/alex/Projects/flareapp.io/public/downloads/flare-api.yaml` (1852 lines, full Flare API spec)
  - `box.json` — added `"resources"` to `directories` array for PHAR bundling
- **Learnings for future iterations:**
  - The spec file is ~49KB / 1852 lines of OpenAPI 3.1.0 YAML
  - `resource_path('openapi/flare-api.yaml')` resolves correctly in development; in a PHAR it resolves to `phar://path/resources/openapi/flare-api.yaml` (read-only but works for spec reading)
  - Can't easily boot the Laravel Zero app from a raw `php -r` script due to missing `files` binding — use the actual `php flare` binary for testing
  - Only commit files relevant to the current story — don't include unrelated changes (e.g. README.md modifications)
---

## 2026-02-10 - US-006
- What was implemented: OpenAPI command registration — all Flare API endpoints auto-registered as artisan commands
- Files changed:
  - `config/app.php` — added `Spatie\OpenApiCli\OpenApiCliServiceProvider::class` to providers array
  - `app/Providers/AppServiceProvider.php` — added `OpenApiCli::register()` call in `boot()` with `useOperationIds()` and `auth()` config
- **Learnings for future iterations:**
  - The spec already defines `servers[0].url` as `https://flareapp.io/api`, so no `.baseUrl()` override needed
  - `OpenApiCli::register()` takes `specPath` and `prefix` — returns `CommandConfiguration` for fluent chaining
  - The auth callable receives no arguments and must return a string token — `fn () => app(CredentialStore::class)->getToken()`
  - The `flare:list` command is auto-generated by the openapi-cli package as the list command for the prefix
  - All 15 endpoint commands + the list command registered successfully from the spec's operationIds
---

## 2026-02-10 - US-007
- What was implemented: Branded ASCII "FLARE" banner with orange-to-yellow gradient for `flare:list` command
- Files changed:
  - `app/Providers/AppServiceProvider.php` — added `.banner()` callable with ASCII art, ANSI 24-bit color gradient, and tagline
- **Learnings for future iterations:**
  - `.banner()` callable form receives the `$command` instance — call `$command->line()` to output
  - ANSI 24-bit (truecolor) escape codes work: `\e[38;2;R;G;Bm` for foreground color, `\e[0m` to reset
  - The banner is only displayed in `{prefix}:list`, not in individual endpoint commands — this is handled by the openapi-cli package automatically
  - The tagline uses `✦` (U+2726) — the four-pointed star character specified in the acceptance criteria
---

## 2026-02-10 - US-008
- What was implemented: PHAR build and distribution setup
- Files changed:
  - `flare` — added `define('ARTISAN_BINARY', basename($_SERVER['argv'][0]))` before autoload to prevent global-ray PHAR from corrupting the binary name
  - `box.json` — added `"main": "flare"` to explicitly set the PHAR entry point
  - `composer.json` — changed path repository `symlink` option from `true` to `false` so Box can include openapi-cli package files (Box does not follow symlinks)
  - `composer.lock` — updated to reflect symlink option change
  - `.gitignore` — added `/builds` to ignore PHAR build output
- **Learnings for future iterations:**
  - Spatie's global-ray package (`auto_prepend_file` in php.ini) loads a PHAR at script startup, which overwrites `$_SERVER['SCRIPT_FILENAME']` to point to `phar://box-auto-generated-alias-xxx.phar/index.php`. This causes Laravel Zero's `ARTISAN_BINARY` detection (which uses `basename($_SERVER['SCRIPT_FILENAME'])`) to return `index.php` instead of `flare`. Fix: define `ARTISAN_BINARY` at the top of the binary using `$_SERVER['argv'][0]` instead.
  - Box (humbug/box) does NOT support symlinks in any form — `directories`, `files`, or `finder` all skip symlinked paths. For path repositories, set `"symlink": false` in `composer.json` so Composer mirrors files instead of symlinking.
  - Box requires `phar.readonly=Off` to build PHARs. Its restart mechanism (which sets `phar.readonly=0` via temp ini) can cause issues when `--config` flags aren't properly forwarded. Setting `"main"` explicitly in `box.json` helps avoid Box's default `index.php` fallback.
  - The `app:build` command does `composer install --no-dev` during build, then restores after. Dev dependencies are excluded from the PHAR automatically.
  - The built PHAR is ~24MB (compressed with GZ), contains ~7180 files, and correctly bundles the OpenAPI spec, all app code, and vendor dependencies.
  - Only commit the specific files for the story — `README.md` changes were pre-existing and should not be included.
---
