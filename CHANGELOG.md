# Changelog

All notable changes to Lookout are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
