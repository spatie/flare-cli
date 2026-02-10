## Codebase Patterns
- The `/api/me` endpoint returns a flat JSON object `{id, name, email, teams}` — NOT wrapped in a `data` key
- Tests for LoginCommand use `Http::fake()` with the real API response structure and `expectsQuestion()` for secret prompts
- Commit only story-specific files — other modified files (README, progress.md from other PRDs) may be dirty from prior iterations

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
