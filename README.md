# Flare CLI

A command-line tool for [Flare](https://flareapp.io) — interact with the Flare API from your terminal.

## Installation

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

## Development

### Running tests

```bash
./vendor/bin/pest
```

### Building the PHAR

```bash
php flare app:build flare
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
