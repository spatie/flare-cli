## Codebase Patterns
- The `/api/me` endpoint returns a flat JSON object `{id, name, email, teams}` — NOT wrapped in a `data` key
- Tests for LoginCommand use `Http::fake()` with the real API response structure and `expectsQuestion()` for secret prompts
- Commit only story-specific files — other modified files (README, progress.md from other PRDs) may be dirty from prior iterations
- Banner gradient array: `[49, 43, 37, 99, 135, 93]` (256-color ANSI) — used for foreground on ASCII art and background on tagline
- `BannerTest` only checks for `flareapp.io` — resilient to art/color changes

---

## 2026-02-10 - US-001
- What was implemented: Fixed "Logged in as unknown" bug by changing `$response->json('data.name', 'unknown')` to `$response->json('email', 'unknown')` in `LoginCommand.php`
- Files changed:
  - `app/Commands/LoginCommand.php` — fixed JSON path from `data.name` to `email`, renamed variable from `$name` to `$email`
  - `tests/Feature/LoginCommandTest.php` — updated HTTP fake response to match real API structure (flat object, not `data`-wrapped), updated expected output from "Logged in as Alex" to "Logged in as alex@spatie.be"
- **Learnings for future iterations:**
  - The Flare `/api/me` API response is a flat JSON object `{id, name, email, teams}` — not wrapped in a `data` key
  - The previous test was also wrong (used `data.name` wrapper) — always check that test fixtures match the real API response structure
---

## 2026-02-10 - US-002
- What was implemented: Updated the FLARE ASCII banner to use Flare's branded gradient color theme
- Files changed:
  - `app/Providers/AppServiceProvider.php` — replaced RGB color gradient with 256-color ANSI codes [49, 43, 37, 99, 135, 93], updated ASCII art to wider-spaced version, added styled tagline with background color, added blank lines before/after art and after tagline
- **Learnings for future iterations:**
  - The banner is rendered via `.banner()` callback in `AppServiceProvider` — `$command->line()` is the output mechanism
  - 256-color ANSI: foreground is `\e[38;5;{code}m`, background is `\e[48;5;{code}m`, bold is `\e[1m`, reset is `\e[0m`
  - The existing `BannerTest` only checks for `flareapp.io` in output — it's resilient to color/art changes
  - US-003 will extract this banner logic into a reusable `RendersBanner` trait — keep the art/gradient arrays easy to extract
---
