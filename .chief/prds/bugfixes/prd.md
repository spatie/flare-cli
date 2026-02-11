# Flare CLI — Bugfixes & Banner Improvements

## Overview
Fix the login command showing "Logged in as unknown" instead of the user's email, update the banner to use Flare's branded gradient color theme, and display the banner on the login command and default `flare` output (in addition to `flare:list`).

## User Stories

### US-001: Fix "Logged in as unknown" bug
**Priority:** 1
**Description:** As a user, I want to see my email after logging in so that I know which account is authenticated.

**Root cause:** `LoginCommand.php:41` reads `$response->json('data.name', 'unknown')` but the `/api/me` response is NOT wrapped in a `data` key. The actual response structure is:
```json
{"id": 20, "name": "Alex", "email": "alex@spatie.be", "teams": [...]}
```

**Acceptance Criteria:**
- [ ] Change `$response->json('data.name', 'unknown')` to `$response->json('email', 'unknown')` in `LoginCommand.php`
- [ ] After successful login, message reads `Logged in as alex@spatie.be` (showing email, not name)
- [ ] The fallback remains `'unknown'` for edge cases where email is missing

### US-002: Update banner to Flare color theme
**Priority:** 2
**Description:** As a user, I want the FLARE ASCII banner to use the branded gradient color theme so that it matches Flare's identity.

**Details:** Replace the current RGB color gradient and ASCII art in `AppServiceProvider.php` with the new design using 256-color ANSI codes.

New ASCII art (with wider spacing):
```
  ███████╗ ██╗       █████╗  ██████╗  ███████╗
  ██╔════╝ ██║      ██╔══██╗ ██╔══██╗ ██╔════╝
  █████╗   ██║      ███████║ ██████╔╝ █████╗
  ██╔══╝   ██║      ██╔══██║ ██╔══██╗ ██╔══╝
  ██║      ███████╗ ██║  ██║ ██║  ██║ ███████╗
  ╚═╝      ╚══════╝ ╚═╝  ╚═╝ ╚═╝  ╚═╝ ╚══════╝
```

New gradient (256-color ANSI): `[49, 43, 37, 99, 135, 93]`

Format: `\e[38;5;{$gradient[$index]}m{$line}\e[0m`

Tagline with background color:
```
 ✦ Catch errors. Fix slowdowns. :: flareapp.io ✦
```
Format: `\e[48;5;{$primary}m\e[30m\e[1m{$tagline}\e[0m` where `$primary = $gradient[0]` (49)

**Acceptance Criteria:**
- [ ] ASCII art updated to the wider-spaced version
- [ ] Gradient uses 256-color ANSI codes `[49, 43, 37, 99, 135, 93]` with `\e[38;5;...m` format
- [ ] Tagline has background color using `\e[48;5;49m\e[30m\e[1m` (bold black text on gradient[0] background)
- [ ] Blank line before and after the ASCII art, blank line after the tagline

### US-003: Extract banner to reusable trait
**Priority:** 3
**Description:** As a developer, I want the banner rendering logic in a reusable trait so it can be used across multiple commands and the custom Describer without duplication.

**Acceptance Criteria:**
- [ ] Create `app/Concerns/RendersBanner.php` trait with a `renderBanner(OutputInterface $output)` method
- [ ] The method writes the ASCII art, gradient, and tagline to the given `$output`
- [ ] Uses `$output->writeln()` directly (works with both Command output and Describer OutputInterface)

### US-004: Show banner on login command
**Priority:** 4
**Description:** As a user, I want to see the Flare banner when I run `flare login` so that the CLI feels branded and polished.

**Acceptance Criteria:**
- [ ] `LoginCommand` uses the `RendersBanner` trait
- [ ] Banner is displayed at the top of the login command output (before the token prompt)
- [ ] Banner renders identically to the `flare:list` banner

### US-005: Show banner on default `flare` output
**Priority:** 5
**Description:** As a user, I want to see the Flare banner when I run just `flare` (no subcommand) so that the default help screen is branded.

**Implementation approach:** Create a custom `FlareDescriber` class that extends `NunoMaduro\LaravelConsoleSummary\Describer`, overrides `describeTitle()` to render the banner before the standard title/usage/commands output, and re-bind the `DescriberContract` singleton in `AppServiceProvider::boot()`.

**Acceptance Criteria:**
- [ ] Create `app/Services/FlareDescriber.php` extending `Describer`
- [ ] Override `describeTitle()` to render the banner first, then call parent
- [ ] In `AppServiceProvider::boot()`, re-bind `DescriberContract` to `FlareDescriber`
- [ ] Running `flare` (no args) shows the branded banner above the command list
- [ ] The standard title, usage, and command list still render below the banner

### US-006: Update banner in OpenApiCli registration
**Priority:** 6
**Description:** As a developer, I want the `.banner()` callback in `AppServiceProvider` to use the shared `RendersBanner` trait so that all three banner locations stay in sync.

**Acceptance Criteria:**
- [ ] The `.banner()` callback in `AppServiceProvider` delegates to the shared banner rendering logic from `RendersBanner`
- [ ] `flare:list` banner output is identical to login and default `flare` output
