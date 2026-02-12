# Flare CLI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/flare-cli.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-cli)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/spatie/flare-cli/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/flare-cli/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/spatie/flare-cli/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/spatie/flare-cli/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/flare-cli.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-cli)

A command-line tool for [Flare](https://flareapp.io) — interact with the Flare API from your terminal.

## Installation

```bash
composer global require spatie/flare-cli
```

Make sure Composer's global bin directory is in your `PATH`. You can find the path with:

```bash
composer global config bin-dir --absolute
```

## Usage

### Authentication

```bash
# Log in with your Flare API token
flare login

# Log out
flare logout
```

Get your API token at [flareapp.io/settings/api-tokens](https://flareapp.io/settings/api-tokens).

### Commands

```bash
# List all available commands
flare list

# List projects
flare list-projects

# List errors for a project
flare list-project-errors --project-id=123

# Resolve an error
flare resolve-error --error-id=456

# Create a project
flare create-project --field name="My App" --field team_id=1 --field stage=production --field technology=Laravel
```

Every Flare API endpoint has a corresponding command. Run `flare list` to see them all.

## Testing

```bash
composer test
```

## Releasing a new version

1. **Build the PHAR**:

    ```bash
    php flare app:build flare --build-version=1.x.x
    ```

    This bakes the version into `builds/flare`. If you omit `--build-version`, it will prompt you (defaulting to the latest git tag).

2. **Commit and push**:

    ```bash
    git add builds/flare
    git commit -m "Release v1.x.x"
    git push origin main
    ```

3. **Create a release** in the GitHub UI — this creates the tag, triggers Packagist, and automatically updates the changelog.

Users install or update with `composer global require spatie/flare-cli`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](https://github.com/spatie/.github/blob/main/CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Alex Vanderbist](https://github.com/alexvanderbist)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
