# Flare CLI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/spatie/flare-cli.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-cli)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/spatie/flare-cli/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/spatie/flare-cli/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/spatie/flare-cli/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/spatie/flare-cli/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/spatie/flare-cli.svg?style=flat-square)](https://packagist.org/packages/spatie/flare-cli)

A command-line tool for [Flare](https://flareapp.io) — interact with the Flare API from your terminal.

![Flare CLI](art/screenshot.png)

## Installation

```bash
composer global require spatie/flare-cli
```

Make sure Composer's global bin directory is in your `PATH`. You can find the path with:

```bash
composer global config bin-dir --absolute
```

## Updating

```bash
composer global require spatie/flare-cli
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

Every Flare API endpoint has a corresponding command. Run `flare <command> --help` for details on a specific command.

```bash
flare list-projects
flare create-project --field name="My App" --field team_id=1 --field stage=production --field technology=Laravel
flare delete-project --project-id=<id>

flare list-project-errors --project-id=<id>
flare list-error-occurrences --error-id=<id>
flare get-error-occurrence --occurrence-id=<id>
flare get-project-error-count --project-id=<id> --start-date=<date> --end-date=<date>
flare get-project-error-occurrence-count --project-id=<id> --start-date=<date> --end-date=<date>

flare resolve-error --error-id=<id>
flare unresolve-error --error-id=<id>
flare snooze-error --error-id=<id>
flare unsnooze-error --error-id=<id>

flare get-team --team-id=<id>
flare remove-team-user --team-id=<id> --user-id=<id>
flare get-authenticated-user

flare get-monitoring-summary --project-id=<id>
flare list-monitoring-aggregations --project-id=<id> --type=routes
flare get-monitoring-time-series --project-id=<id> --type=routes
flare get-monitoring-aggregation --type=routes --uuid=<uuid>
flare list-aggregation-traces --type=routes --uuid=<uuid>
flare get-trace --trace-id=<trace-id>
```

## Agent Skill

This repository includes an [agent skill](https://skills.sh) that teaches coding agents how to use the Flare CLI.

### Install

```bash
flare install-skill
```

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
