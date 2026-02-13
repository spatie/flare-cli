# Workflows

Detailed workflows for common Flare CLI tasks.

## Error triage

Systematically work through open errors, categorize them, and take action.

### Step 1: Get an overview

```bash
# List open errors, most recent first
flare list-project-errors --project-id=123 --filter-status=open --sort=-last_seen_at --page-size=30
```

Present results as a table: ID, exception class, message (truncated to ~60 chars), occurrence count, last seen.

### Step 2: Categorize by exception class

Group the errors by `exception_class`. Common patterns:
- **Same class, different messages** — likely the same root cause. Fix one and resolve the group.
- **High occurrence count** — prioritize these; they affect users most.
- **`latest_occurrence_has_solutions: true`** — check solutions first, they often have the fix.

### Step 3: Take action on each error

For each error, decide:

| Situation | Action |
|---|---|
| Bug is fixed in code | `flare resolve-error --error-id=ID` |
| Known issue, not worth notifications | `flare snooze-error --error-id=ID --field snooze_type=snooze_forever` |
| Will fix next sprint | `flare snooze-error --error-id=ID --field snooze_type=snooze_until --field snooze_until=YYYY-MM-DDT00:00:00Z` |
| Noisy but want to know if it spikes | `flare snooze-error --error-id=ID --field snooze_type=snooze_number_of_occurrences --field snooze_number_of_occurrences=100` |
| Need more info | Fetch occurrence details (see debugging workflow below) |

### Step 4: Paginate through remaining errors

```bash
# Next page
flare list-project-errors --project-id=123 --filter-status=open --sort=-last_seen_at --page-number=2 --page-size=30
```

Repeat until all pages are triaged. Use `meta.last_page` from the response to know when you're done.

---

## Debug an error with local code

Use occurrence data to pinpoint the bug in the user's local codebase.

### Step 1: Get the latest occurrence

```bash
flare list-error-occurrences --error-id=456 --sort=-received_at --page-size=1
```

Or if you have a specific occurrence ID:

```bash
flare get-error-occurrence --occurrence-id=789
```

### Step 2: Find application frames

From the occurrence's `frames` array, filter for frames where `application_frame` is `true`. These are the user's code — not vendor or framework code.

Each application frame has:
- `relative_file` — path relative to the project root (e.g., `app/Http/Controllers/OrderController.php`)
- `line_number` — the exact line where execution was at this point in the stack
- `class` and `method` — the class and method name
- `code_snippet` — a few lines of code around the error line (from the production server)

### Step 3: Read local source files

For each application frame, read the local file at the reported path and line number. The code may have changed since the error occurred — compare the `code_snippet` from the occurrence with the local file to check.

If `application_version` is set on the occurrence, you can also check git to see what changed:

```bash
git diff <application_version> -- <relative_file>
```

### Step 4: Use context for clues

**Attributes** (`attributes[]`) provide request and environment context:
- `group: "request"` — URL, method, IP, user agent
- `group: "user"` — authenticated user info
- `group: "environment"` — PHP version, server, OS
- `group: "context"` — custom context added by the application

**Events** (`events[]`) show the execution trail leading up to the error:
- Database queries executed
- Log messages written
- Jobs dispatched
- Cache hits/misses
- Events and listeners fired

Events are chronological. Walk through them to understand what happened before the crash.

### Step 5: Check solutions

If `solutions[]` is non-empty, these are AI-generated or provider-supplied fix suggestions. Each solution has:
- `title` — what the solution is
- `description` — how to apply it
- `links[]` — relevant documentation URLs

Present solutions prominently — they're often the fastest path to a fix.

### Step 6: Present findings

Summarize for the user:
1. **Error**: exception class + message
2. **Location**: file:line in their code (link to the application frame)
3. **Context**: relevant attributes (request URL, user, etc.)
4. **Trail**: key events leading to the error
5. **Solutions**: any available solutions
6. **Flare link**: `latest_occurrence_url_on_flare` for the full dashboard view

---

## Interpreting occurrence data

### Frames

The `frames` array is the full stack trace, ordered from the throw point (index 0) to the entry point (last index).

- **`application_frame: true`** — this is code in the user's project (not vendor). Always focus on these.
- **`application_frame: false`** — framework/library code. Useful for understanding the call path but usually not the source of the bug.
- **`code_snippet`** — array of source lines from the production server. Line numbers in the snippet correspond to the actual file.

### Attributes

Attributes are key-value context grouped by category:

| Group | Contains |
|---|---|
| `request` | URL, method, IP, headers, body |
| `user` | Authenticated user details |
| `environment` | PHP version, server software, OS |
| `context` | Custom context added via `Flare::context()` |
| `session` | Session data |
| `cookies` | Cookie values |
| `headers` | HTTP headers |

### Events

Events are a chronological log of what happened during the request/job:

| Event type | What it tells you |
|---|---|
| Query | SQL queries with bindings and timing |
| Log | Application log messages |
| Job | Queued jobs dispatched |
| Cache | Cache gets, puts, misses |
| Event | Laravel events fired |
| View | Blade views rendered |

### Solutions

Flare's solution providers suggest fixes based on the error type. A solution includes:
- `title` — short description (e.g., "Add the missing method")
- `description` — detailed instructions
- `links` — documentation references
- `is_runnable` — whether it can be auto-applied (via Flare dashboard only, not CLI)

---

## Snooze strategies

Choose the right snooze type based on the situation:

| Strategy | When to use | Command |
|---|---|---|
| **Forever** | Known issue you'll never fix, or acceptable behavior | `--field snooze_type=snooze_forever` |
| **Until date** | Will be fixed by a specific deadline or release date | `--field snooze_type=snooze_until --field snooze_until=2025-06-01T00:00:00Z` |
| **N occurrences** | Tolerable at low volume, but want to know if it spikes | `--field snooze_type=snooze_number_of_occurrences --field snooze_number_of_occurrences=100` |
| **Application version** | Will be fixed in the next deploy | `--field snooze_type=snooze_application_version` |

To unsnooze any error:

```bash
flare unsnooze-error --error-id=456
```

---

## Set up Flare in a Laravel project

### Step 1: Install the package

```bash
composer require spatie/laravel-flare
```

This installs Flare's error reporter and auto-registers the service provider.

### Step 2: Get a Flare API key

If you already know the project ID:

```bash
# List projects to find the API key
flare list-projects --filter-id=123
```

The response includes `api_key` — that's what you need.

If you need a new project:

```bash
# First, find your team ID
flare get-authenticated-user
# Note the team ID from the teams array

# Create the project
flare create-project --field name="My App" --field team_id=1 --field stage=production --field technology=Laravel
```

The response includes the `api_key`.

### Step 3: Configure the environment

Add the API key to your `.env`:

```
FLARE_KEY=your-api-key-here
```

The `spatie/laravel-flare` package reads `FLARE_KEY` automatically.

### Step 4: Verify

Trigger a test error and check that it appears:

```bash
# Check error count (use a recent date range)
flare get-project-error-count --project-id=123 --start-date=2025-01-01T00:00:00Z --end-date=2025-12-31T23:59:59Z

# Or list recent errors
flare list-project-errors --project-id=123 --sort=-last_seen_at --page-size=5
```

### For non-Laravel PHP projects

Use `spatie/flare-client-php` instead:

```bash
composer require spatie/flare-client-php
```

See the [Flare docs](https://flareapp.io/docs/general/projects) for framework-specific setup instructions.
