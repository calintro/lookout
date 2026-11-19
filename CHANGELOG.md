# Changelog

All notable changes to Lookout are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-11-19

### Added

- Telegram alert channels. Under Settings → Notifications, Telegram is a channel type next to webhook, Slack and Discord. A channel has a bot token, a chat ID (a numeric ID or `@channelname`), an optional forum topic ID and the events to send (`issue.new`, `issue.reopened`). Channels can be edited, enabled, disabled and deleted. **Send test** sends a test message right away and shows Telegram's error text if it fails.
  - The NightOwl agent cannot send to Telegram, so these channels are stored in Lookout's `SettingStore` (key `monitoring.telegram_channels`), not in `nightowl_alert_channels`. The settings API lists both kinds of channel together. New endpoints: `POST settings/telegram`, `PATCH|DELETE settings/telegram/{id}` and `POST settings/telegram/{id}/test`.
  - The bot token is never sent back to the browser: the API shows only its last four characters. When a channel is edited, an empty token field keeps the stored token.
  - `lookout:telegram-notify` runs every minute from the package schedule, with `withoutOverlapping()` and `onOneServer()`. It sends issues the agent created or reopened since a watermark kept in the setting store. The first run sends no history. Each event is sent once per channel. One run sends at most 20 messages per channel (`--limit`), then one "and N more" message. Only issues of the app's own environment are sent unless `LOOKOUT_TELEGRAM_ENVIRONMENT=*`.
  - Messages use the Bot API's `sendMessage` with HTML: app and environment, new or reopened, issue number, type, exception class or target, message, location, occurrences, users affected, and a link to the issue in Lookout. Requests time out after 5 seconds and are not retried. A failure is logged without the token, and delivery to the other channels goes on. The request is sent with Nightwatch recording paused, so the token in the URL is not stored in the outgoing-request telemetry.
  - Config: `telegram.enabled` (`LOOKOUT_TELEGRAM_ENABLED`), `telegram.api_base` (`LOOKOUT_TELEGRAM_API_BASE`, for a proxy) and `telegram.environment` (`LOOKOUT_TELEGRAM_ENVIRONMENT`).

## [0.1.3] - 2026-11-19

### Security

- Lookout's own requests no longer land in the telemetry it shows. At boot it appends `{path}`, `{path}/*` and the MCP path to `nightowl.ignore_routes`, keeping the app's patterns. New MCP tokens, the shared token in `X-Api-Token`, webhook URLs and secrets could otherwise be read by every viewer on the request pages. `ignore_own_routes` (`LOOKOUT_IGNORE_OWN_ROUTES`, on by default) turns it off.
- The MCP server answers 404 while Lookout is disabled, as the web app and the docs already did.
- Failed MCP token checks are limited per IP before the token lookup (`mcp.failed_per_minute`, 30): above that, 429 with `Retry-After`. Successful requests are not counted. A shared `LOOKOUT_MCP_TOKEN` shorter than 32 characters logs a warning once a day.
- Alert channels: changing a webhook's URL drops its stored signing secret unless a new one is sent. Slack URLs must start with `https://hooks.slack.com/`, Discord URLs with `https://discord.com/api/webhooks/` or `https://discordapp.com/api/webhooks/`. Generic webhooks may not point at `localhost` or a literal private, loopback, link-local or reserved IP (names are not resolved).
- The Lookout page is sent with `X-Frame-Options: DENY` and `Content-Security-Policy: frame-ancestors 'none'`.
- The SPA escapes the period label, issue status and priority, and HTTP method classes too (defence in depth; none was exploitable).

### Changed

- The assignee list checks at most the first 2,000 users by name against the gate when no `assignableUsersUsing()` scope is set, and returns at most 200.
- Logs can be paged through the first 10,000 entries; deeper pages answer 422.
- The MCP page no longer claims tokens can ignore or reopen issues: the only write tool is `resolve_issue`.
- README: a Security section (own-route exclusion, Nightwatch redaction when running in parallel, one access level for reading and settings, what viewers see).

## [0.1.2] - 2026-11-19

### Changed

- Fonts are bundled: Inter and Geist Mono (variable, latin, latin-ext and cyrillic subsets, SIL OFL 1.1) ship in `dist/fonts` and are served from the package next to `app.js`. The dashboard no longer loads anything from Google Fonts.

## [0.1.1] - 2026-11-19

### Added

- `mcp.middleware`: the middleware after the MCP token check. `null` (the default) keeps `ThrottleRequests` with `mcp.rate_limiter`, so existing installs are unchanged; a list replaces it, for apps with their own limiter.
- `mcp.tokens_connection` (`LOOKOUT_MCP_TOKENS_CONNECTION`): the connection of the MCP tokens table. On another connection than the users table the table gets an index on `user_id` and no foreign key, and token owners are still loaded from the users connection.
- `mcp.user_id_type` (`bigint` or `int`): the type of `user_id` in the tokens table. `null` reads `users.id` when the migration runs.
- README screenshots, a comparison with similar tools, CI on PHP 8.3 to 8.5 with Laravel 12 and 13, contributing and security guides, issue templates.

### Fixed

- The MCP tokens migration failed on MySQL apps whose `users.id` is `INT UNSIGNED`: `user_id` was always `BIGINT`, and MySQL refused the foreign key. It now follows `users.id`.
- Issue numbers show as `#123`. They carried a hard-coded `AF-` prefix from the app Lookout was extracted from.

## [0.1.0] - 2026-11-19

First release, extracted from the monitoring app of [AssetFeed](https://assetfeed.com/search).

### Added

- Monitoring UI over NightOwl telemetry:
  - dashboard;
  - requests and routes with per-request traces;
  - jobs, commands and scheduled tasks;
  - queries with N+1 detection;
  - exceptions, logs, mail, notifications, cache and outgoing HTTP;
  - a per-user view.
- Issues: status, priority, assignee, description, comments, bulk actions, activity history, and `lookout:auto-resolve` (alias `nightowl:auto-resolve`), scheduled daily.
- Settings for NightOwl performance thresholds and alert channels (webhook, Slack, Discord).
- Access through the `viewLookout` gate, with an optional Nova mode that reuses Nova's middleware and adds a sidebar link (`Lookout\Nova\LookoutMenu`).
- MCP server (`/mcp/lookout`, needs `laravel/mcp`) with six tools:
  - `list_issues`, `get_issue`, `slow_routes`, `slow_queries`, `failed_jobs`, `resolve_issue`;
  - personal tokens, read-only unless allowed to resolve;
  - a shared token for automation;
  - a rate limit per credential.
- A prebuilt SPA served from the package (no npm), publishable with `--tag=lookout-assets`.
- Everything app-specific is configurable: path, domain, connection, user model, assignee scope, setting store, branding, MCP names, token table and prefix.
