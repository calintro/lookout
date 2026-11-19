# Changelog

All notable changes to Lookout are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Issue numbers show as `#123`. They carried a hard-coded `AF-` prefix from the app Lookout was extracted from.

### Added

- README screenshots, a comparison with similar tools, CI on PHP 8.3 to 8.5 with Laravel 12 and 13, contributing and security guides, issue templates.

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
