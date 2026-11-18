# Lookout

An open-source, self-hosted alternative to Laravel Nightwatch: a monitoring UI for your Laravel app that keeps the data on your own servers.

Lookout reads the telemetry the [NightOwl agent](https://github.com/lemed99/nightowl-agent) (`nightowl/agent`, MIT) writes to your PostgreSQL. It shows requests, jobs, queries, exceptions and the rest in one dashboard. Errors are grouped into issues you can triage, and coding agents can read them through an MCP server.

## Screenshots

> Screenshots are coming. Until then, `composer require` it and open `/lookout`.

## Features

- **Dashboard.** Requests, errors, latency (avg / p95) and throughput over 1 hour to 30 days, or a custom range, per environment.
- **Requests and routes.** Each route with its slowest and failing calls. A request opens as a trace with its queries, exceptions, logs, cache events, mail and outgoing HTTP calls.
- **Jobs, commands and scheduled tasks.** Attempts, failures, releases and durations, plus every run of a command or task.
- **Queries.** The slowest and most frequent statements, with N+1 detection.
- **Exceptions and logs.** Exceptions are grouped by fingerprint. Logs can be filtered by level, text and user.
- **Mail, notifications, cache and outgoing HTTP.** Volumes, failures and timings.
- **Users.** What one user did: requests, exceptions and logs.
- **Issues.** Exceptions and slow executions grouped into issues:
  - status (open, resolved, ignored), priority, assignee, description and comments;
  - bulk actions and an activity history;
  - automatic resolve after 7, 14 or 30 days without a new occurrence.
- **Settings.** Performance thresholds per route, job, query and other types. Alert channels: webhook (HMAC-signed), Slack, Discord. Both are read by the NightOwl agent.
- **MCP server.** Lets coding agents (Claude Code, Codex, Cursor, VS Code) list and read issues with stack traces, find slow routes, slow queries and failed jobs, and resolve issues if you allow it.
- **No build step.** The SPA ships prebuilt. Your app needs no npm.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- PostgreSQL for the NightOwl database
- [`nightowl/agent`](https://github.com/lemed99/nightowl-agent) 2.4+, installed and running. It is a dependency of Lookout. NightOwl builds on the `laravel/nightwatch` instrumentation and stores what it captures in your database.
- Optional: `laravel/mcp` for the MCP server, `laravel/nova` 5 for Nova mode.

## Installation

```bash
composer require nikolai-vysotskyi/lookout
```

1. **Set up NightOwl** as its documentation describes: a PostgreSQL database, `NIGHTOWL_ENABLED=true`, `NIGHTOWL_DB_*`, then `php artisan nightowl:migrate` and a running `php artisan nightowl:agent`. Schedule `nightowl:prune` so its retention settings apply.

2. **Publish the config** (optional, the defaults work) and run the migration. It adds one table, `lookout_mcp_tokens`:

   ```bash
   php artisan vendor:publish --tag=lookout-config
   php artisan migrate
   ```

3. **Define who may open Lookout.** Without this gate nobody gets in.

   ```php
   // app/Providers/AppServiceProvider.php
   use Illuminate\Support\Facades\Gate;

   public function boot(): void
   {
       Gate::define('viewLookout', fn ($user) => $user->is_admin);
   }
   ```

4. **Open `/lookout`.** Lookout is on while `nightowl.enabled` is on. It answers 404 otherwise.

### Reading connection

Lookout reads through the `nightowl_read` connection. If `config/database.php` does not define it, Lookout builds one from `nightowl.database`. Define it yourself to use a separate, less privileged role. That role needs:

- `SELECT` on the NightOwl tables;
- `UPDATE` on `nightowl_issues` and `INSERT` on `nightowl_issue_activity` and `nightowl_issue_comments`, for issue edits;
- writes on `nightowl_settings` and `nightowl_alert_channels`, for the Settings page.

### Nova mode

If your admins already sign in to Laravel Nova, Lookout can sit behind Nova's own stack:

```php
// config/lookout.php
'auth' => 'nova',
```

In Nova mode:

- Lookout uses `nova.api_middleware` on `nova.domain`, so it goes through Nova's login and gate.
- Guests are sent to the Nova login.
- The user menu links back to Nova and signs out of Nova.
- The `viewLookout` gate still decides who gets in.

To add a sidebar link:

```php
use Lookout\Nova\LookoutMenu;

Nova::mainMenu(fn () => [
    // ...
    LookoutMenu::make(), // or LookoutMenu::make('Monitoring', 'lightning-bolt')
]);
```

### Assets

Lookout serves its prebuilt `app.js` and `app.css` from the package at `/{path}/assets/*`, behind the same access check. To serve them from `public/` instead:

```bash
php artisan vendor:publish --tag=lookout-assets
```

The published copy in `public/vendor/lookout` wins while it exists. Re-publish after each update.

## Configuration

All keys live in `config/lookout.php`.

| Key | Default | What it does |
|-----|---------|--------------|
| `enabled` | `null` | `null` follows `nightowl.enabled`. `false` closes Lookout (404) and pauses its schedule. |
| `path` | `lookout` | URL prefix: `/{path}`, `/{path}/api/*`, `/{path}/assets/*`. |
| `domain` | `null` | Restricts Lookout to one domain. In Nova mode, `nova.domain` is used. |
| `auth` | `gate` | `gate` uses `middleware` plus the gate. `nova` uses Nova's API stack plus the gate. |
| `middleware` | `['web', 'auth']` | The stack in `gate` mode. |
| `gate` | `viewLookout` | The ability checked on every page and API call. |
| `connection` | `nightowl_read` | The connection that reads the NightOwl database. |
| `user_model` | `null` | Model behind assignees, comments and tokens. `null` means `auth.providers.users.model`. |
| `title` | `Lookout` | Browser tab title. |
| `brand` | `Lookout` | Name in the sidebar. `null` shows `app.name`. |
| `home`, `logout_url`, `login_url` | `null` | User menu links outside Nova mode. |
| `storage_prefix` | `lookout-` | localStorage prefix for theme, period and environment. |
| `auto_resolve.schedule`, `auto_resolve.at` | `true`, `03:40` | Daily `lookout:auto-resolve` while Lookout is enabled. |
| `mcp.*` | see below | The MCP server. |

To keep auto-resolve days next to your other settings, bind your own `Lookout\Contracts\SettingStore`. The default store uses `nightowl_settings`.

To narrow who issues can be assigned to, set a scope. The default is every user the gate lets in:

```php
use Lookout\Lookout;

Lookout::assignableUsersUsing(fn ($query) => $query->where('role', 'admin'));
```

## MCP server

With `laravel/mcp` installed, Lookout serves an MCP server at `/mcp/lookout`. It has these tools:

| Tool | What it does |
|------|--------------|
| `list_issues` | Issues by status, type and environment, the most recently seen first. |
| `get_issue` | One issue with its counts and the stack trace of its latest occurrence. |
| `slow_routes` | Routes by p95, average, calls or error rate over a window. |
| `slow_queries` | Queries by p95, total time, average or calls. |
| `failed_jobs` | Jobs with failures over a window. |
| `resolve_issue` | Marks an issue resolved. This is the only tool that writes. |

**Access.** Each user creates personal tokens on the MCP page in Lookout. The page also shows ready-to-paste snippets for Claude Code, Codex, Cursor and VS Code.

- A token acts as its owner while the owner passes `mcp.gate`. `null` means the app's gate.
- A token is read-only unless its owner allows "can resolve".
- `LOOKOUT_MCP_TOKEN` with `LOOKOUT_MCP_USER_ID` is a shared token for automation. It acts as that user and may resolve.
- Other credentials of your app (API keys, OAuth tokens) are refused.
- Requests are limited per credential (`mcp.per_minute`, 600 by default).

| Key | Default |
|-----|---------|
| `mcp.enabled` | `true` |
| `mcp.path` | `mcp/lookout` |
| `mcp.gate` | `null` (the app's gate) |
| `mcp.token`, `mcp.user_id` | `LOOKOUT_MCP_TOKEN`, `LOOKOUT_MCP_USER_ID` |
| `mcp.per_minute`, `mcp.rate_limiter` | `600`, `lookout-mcp` |
| `mcp.tokens_table`, `mcp.token_prefix` | `lookout_mcp_tokens`, `lookout_` (12 characters at most) |
| `mcp.server_name`, `mcp.client_name`, `mcp.app_name` | derived from `app.name` |

## Lookout vs Laravel Nightwatch

| | Lookout | Laravel Nightwatch |
|---|---|---|
| Hosting | Self-hosted, inside your app | SaaS |
| Where the data lives | Your PostgreSQL | The Nightwatch service |
| Event volume | No quota from Lookout. Limited by your database and NightOwl retention. | Per plan |
| Price | Free, MIT | Per plan |
| Source code | Open (MIT) | See Nightwatch |
| MCP server for AI agents | Built in, with personal tokens | See Nightwatch docs |
| Issue workflow | Status, priority, assignee, comments, bulk actions, auto-resolve | See Nightwatch docs |
| You run | PostgreSQL and the NightOwl agent | The Nightwatch agent |

Both capture with the `laravel/nightwatch` instrumentation: NightOwl builds on it. Pick Nightwatch if you want a managed service. Pick Lookout if the data must stay on your servers or you want no per-event bill.

## Development

```bash
composer install
composer test        # Pest on Orchestra Testbench
sh bin/build.sh      # rebuilds dist/ from resources/js and resources/css
```

The SPA is plain JavaScript split into `resources/js/01..09-*.js`. `bin/build.sh` concatenates the files into `dist/app.js`, so no bundler is needed. Commit `dist/` together with the sources: a test fails if they differ.

## Supported by

<a href="https://assetfeed.com/search">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="docs/images/sponsors/assetfeed-dark.svg" />
    <img src="docs/images/sponsors/assetfeed-light.svg" alt="AssetFeed" height="56" />
  </picture>
</a>

Built by the team behind [AssetFeed](https://assetfeed.com/search).

## License

MIT. See [LICENSE](LICENSE).

---

Lookout is not affiliated with or endorsed by Laravel. Laravel and Laravel Nightwatch are trademarks of their respective owners.
