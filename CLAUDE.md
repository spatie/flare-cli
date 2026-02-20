# Flare CLI

## Project overview

A standalone CLI tool for [Flare](https://flareapp.io) built on Laravel Zero. Uses `spatie/laravel-openapi-cli` to auto-generate artisan commands from the Flare OpenAPI spec.

- **Package**: `spatie/flare-cli`
- **Binary**: `flare`
- **Install**: `composer global require spatie/flare-cli`

## Architecture

- **Laravel Zero 12** — PHP CLI micro-framework
- **spatie/laravel-openapi-cli** — reads `resources/openapi/flare-api.yaml` and registers one command per API endpoint using `operationId`-based naming with `flare:` prefix
- **CredentialStore** (`app/Services/CredentialStore.php`) — reads/writes API token to `~/.flare/config.json`
- **LoginCommand / LogoutCommand** — custom commands (not from OpenAPI spec) for auth flow

## Key files

- `flare` — CLI entry point (the binary)
- `app/Providers/AppServiceProvider.php` — registers CredentialStore singleton and OpenApiCli
- `app/Services/CredentialStore.php` — credential persistence to `~/.flare/config.json`
- `app/Commands/LoginCommand.php` — `flare login`
- `app/Commands/LogoutCommand.php` — `flare logout`
- `resources/openapi/flare-api.yaml` — bundled Flare API spec
- `config/app.php` — providers list (must manually register `OpenApiCliServiceProvider`)
- `box.json` — PHAR build config (must include `resources` directory)

## Development setup

The `spatie/laravel-openapi-cli` package is loaded via a Composer path repository pointing to `../laravel-openapi-cli` with symlink. Run `composer update` after cloning both repos side by side.

## AI agent skill

When updating the AI agent skill (e.g. filters, sorts, available commands), always verify against the actual `flare` build (`php flare list`, `php flare <command> --help`) to avoid documenting incorrect flags, filter names, or sort options.

## Important notes

- Laravel Zero disables package auto-discovery. Any package service providers must be registered manually in `config/app.php`.
- The `.auth()` callable (not `.bearer()`) is used for dynamic credential resolution from `CredentialStore`.
- The `resources/` directory must be in `box.json` `directories` for the spec to be bundled in the PHAR.
- `resource_path()` resolves to `phar://` paths when running inside a PHAR — this works for reading but not writing.

## Coding standards

Follow PSR-12. Use Laravel/Pint for formatting. Tests use Pest.
