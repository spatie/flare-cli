# Changelog

All notable changes to `flare-cli` will be documented in this file.

## 1.3.1 - 2026-07-20

- Account links now point at the split pages: personal access tokens under `/account/personal-access-tokens`, CLI and MCP connections under `/account/connected-apps` (spatie/flareapp.io#2515)

## 1.3.0 - 2026-07-20

### What's new

- `flare login` now discovers the server's OAuth endpoints via RFC 8414 metadata instead of assuming hardcoded paths
- `flare logout` revokes the OAuth connection remotely before removing local credentials, and `--local-only` skips revocation when Flare is unreachable
- `flare login --name` suggests an editable connection name on the consent screen
- Credentials are stored as issuer and API-base profiles, so production, staging, and self-hosted logins can coexist
- `~/.flare` is now created with `0700`/`0600` permissions and the config file is replaced atomically
- A token refresh that fails because the connection was revoked aborts with a clear re-login hint instead of sending a doomed request

**Full Changelog**: https://github.com/spatie/flare-cli/compare/1.2.0...1.3.0

## 1.2.0 - 2026-07-18

### What's new

- `flare login` now uses OAuth: browser-based PKCE flow by default, `--device` for headless terminals, `--token` to paste a personal access token (#28)
- Tokens are refreshed automatically and can be scoped to specific teams and projects during the consent flow
- Friendly error message with a re-login hint when a command fails due to missing scopes or team/project grants
- `flare login --token` now properly rejects invalid tokens instead of reporting a successful login (#40)
- `flare logout --all` logs out of every configured host

**Full Changelog**: https://github.com/spatie/flare-cli/compare/1.1.0...1.2.0

## 1.1.0 - 2026-04-07

### What's Changed

* feat: add host-scoped auth contexts by @AlexVanderbist in https://github.com/spatie/flare-cli/pull/16

**Full Changelog**: https://github.com/spatie/flare-cli/compare/1.0.2...1.1.0

## 1.0.2 - 2026-02-24

### What's Changed

* chore(deps): Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/spatie/flare-cli/pull/1
* chore(deps): Bump stefanzweifel/git-auto-commit-action from 5 to 7 by @dependabot[bot] in https://github.com/spatie/flare-cli/pull/3

**Full Changelog**: https://github.com/spatie/flare-cli/compare/1.0.1...1.0.2

## 1.0.1 - 2026-02-20

- bugfixes

**Full Changelog**: https://github.com/spatie/flare-cli/compare/1.0.0...1.0.1

## 1.0.0 - 2026-02-19

- Use remote Flare API spec (cached)
- Add performance monitoring endpoints

**Full Changelog**: https://github.com/spatie/flare-cli/compare/0.0.2...1.0.0

## 0.0.2 - 2026-02-19

### What's Changed

* Added command to install the coding agent skill
* chore(deps-dev): Bump pestphp/pest from 4.3.2 to 4.4.1 by @dependabot[bot] in https://github.com/spatie/flare-cli/pull/5
* chore(deps-dev): Bump phpstan/phpstan-phpunit from 2.0.15 to 2.0.16 by @dependabot[bot] in https://github.com/spatie/flare-cli/pull/6

**Full Changelog**: https://github.com/spatie/flare-cli/compare/0.0.1...0.0.2

## 0.0.1 - 2026-02-12

**Full Changelog**: https://github.com/spatie/flare-cli/commits/0.0.1
