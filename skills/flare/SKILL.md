---
name: flare
description: >-
  Manage Flare error tracking using the flare CLI. Use when the user wants to
  list, triage, resolve, snooze, or debug errors; manage projects; check error
  counts; set up Flare in a PHP/Laravel project; or interact with flareapp.io
  from the command line.
license: MIT
metadata:
  author: spatie
  version: "0.0.1"
---

# Flare CLI

The `flare` CLI lets you manage [Flare](https://flareapp.io) error tracking from the terminal. Every Flare API endpoint has a corresponding command.

## Prerequisites

Check that the CLI is installed:

```bash
flare --version
```

If not installed:

```bash
composer global require spatie/flare-cli
```

Ensure Composer's global bin directory is in `PATH`:

```bash
composer global config bin-dir --absolute
```

## Authentication

```bash
# Log in — you'll be prompted for your API token
flare login

# Log out
flare logout
```

Get your API token at https://flareapp.io/settings/api-tokens.

If any command returns a 401 error, the token is invalid or expired. Run `flare login` again.

## Quick command reference

All commands output JSON. See [references/commands.md](references/commands.md) for full parameter details.

### User & team

```bash
# Who am I?
flare get-authenticated-user

# Get team details (includes members and roles)
flare get-team --team-id=1

# Remove a user from a team
flare remove-team-user --team-id=1 --user-id=42
```

### Projects

```bash
# List all projects (paginated)
flare list-projects

# Filter by name or team
flare list-projects --filter-name="My App" --filter-team-id=1

# Include team info
flare list-projects --include=team

# Create a project
flare create-project --field name="My App" --field team_id=1 --field stage=production --field technology=Laravel

# Delete a project
flare delete-project --project-id=123
```

### Errors

```bash
# List errors for a project
flare list-project-errors --project-id=123

# Filter by status, class, file, stage, or level
flare list-project-errors --project-id=123 --filter-status=open --filter-exception-class=RuntimeException

# Sort by most recent
flare list-project-errors --project-id=123 --sort=-last_seen_at

# Get error counts for a date range
flare get-project-error-count --project-id=123 --start-date=2025-01-01T00:00:00Z --end-date=2025-01-31T23:59:59Z

# Get occurrence counts for a date range
flare get-project-error-occurrence-count --project-id=123 --start-date=2025-01-01T00:00:00Z --end-date=2025-01-31T23:59:59Z
```

### Error actions

```bash
# Resolve an error
flare resolve-error --error-id=456

# Reopen a resolved error
flare unresolve-error --error-id=456

# Snooze forever
flare snooze-error --error-id=456 --field snooze_type=snooze_forever

# Snooze until a date
flare snooze-error --error-id=456 --field snooze_type=snooze_until --field snooze_until=2025-06-01T00:00:00Z

# Snooze for N more occurrences
flare snooze-error --error-id=456 --field snooze_type=snooze_number_of_occurrences --field snooze_number_of_occurrences=50

# Unsnooze
flare unsnooze-error --error-id=456
```

### Occurrences

```bash
# List occurrences for an error (includes frames, attributes, events, solutions)
flare list-error-occurrences --error-id=456

# Sort oldest first
flare list-error-occurrences --error-id=456 --sort=received_at

# Get a single occurrence by ID
flare get-error-occurrence --occurrence-id=789
```

### Pagination

All list commands support pagination:

```bash
flare list-project-errors --project-id=123 --page-number=2 --page-size=20
```

Response includes `meta` (current_page, total, last_page) and `links` (next/prev URLs).

## Common workflows

### Error triage

List open errors, categorize by exception class, resolve or snooze in batches. See [references/workflows.md](references/workflows.md) for the full triage workflow.

Quick version:

```bash
# 1. List open errors sorted by most recent
flare list-project-errors --project-id=123 --filter-status=open --sort=-last_seen_at

# 2. Review each error, resolve fixed ones
flare resolve-error --error-id=456

# 3. Snooze noisy but non-critical errors
flare snooze-error --error-id=789 --field snooze_type=snooze_forever
```

### Debug an error with local code

Fetch an occurrence, find application frames, then read the corresponding local files. See [references/workflows.md](references/workflows.md) for detailed steps.

Quick version:

```bash
# 1. Get the latest occurrence
flare list-error-occurrences --error-id=456 --sort=-received_at --page-size=1

# 2. Look at the frames where application_frame=true
# 3. Read the local file at the reported line number
# 4. Check attributes for request context, events for log trail, solutions for fixes
```

### Set up Flare in a project

Install `spatie/laravel-flare`, configure the API key, and verify via CLI. See [references/workflows.md](references/workflows.md) for the full setup guide.

## Output format

All commands return JSON. When presenting results to the user:

- **Errors**: Show as a table with columns: ID, exception class, message (truncated), status, occurrence count, last seen. Always include the `latest_occurrence_url_on_flare` link.
- **Occurrences**: Highlight application frames (where `application_frame` is `true`) — these are the user's code, not vendor code. Show the `relative_file` and `line_number`.
- **Solutions**: If `solutions` is non-empty, always present them prominently — they contain actionable fix suggestions.
- **Attributes**: Group by the `group` field (e.g., request, user, environment) when displaying context.
- **Events**: Show chronologically — they represent the execution trail leading to the error (queries, logs, jobs, etc.).
- **Flare URLs**: Include `latest_occurrence_url_on_flare` so the user can view the full error in the Flare dashboard.
