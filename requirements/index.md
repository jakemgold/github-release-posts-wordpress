---
title: "Requirements Index"
---

# Requirements Index

**Project:** Changelog to Blog Post (WordPress Plugin)
**Platform:** WordPress (plugin, targeting WordPress.org distribution)
**Created:** 2026-03-20 | **Updated:** 2026-03-20

---

## Overview

| Metric | Value |
|--------|-------|
| Total Domains | 7 |
| Total Epics | 12 |
| Total PRDs | 0 |
| Approved | 0 |

## Architecture Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Project type | WordPress plugin | Free distribution via WordPress.org |
| PHP namespace | `TenUp\ChangelogToBlogPost` | 10up standard |
| Text domain | `changelog-to-blog-post` | Matches plugin slug |
| PHP minimum | 8.0 | Modern PHP features, WP 6.4+ requirement |
| WP minimum | 6.4 | Required for stable AI API hooks |
| Build tooling | `@10up/scripts` | 10up standard JavaScript/CSS pipeline |
| AI abstraction | Provider interface pattern | Supports multiple AI backends via swappable connectors |
| Scheduling | WP-Cron | Standard WP background processing |
| Post storage | Native `wp_posts` + post meta | No custom tables for post data |

## Browser / Device / Accessibility

| Requirement | Value |
|-------------|-------|
| Context | WordPress admin screens only (no public frontend) |
| Browsers | Modern evergreen (admin UI only) |
| Accessibility | WCAG 2.2 AA |

## Compliance & Regulatory

None — plugin does not collect PII beyond what the site owner optionally configures (email address for notifications, API keys stored encrypted).

## Distribution

**Target:** WordPress.org free plugin repository
- Must pass WordPress.org automated and manual review
- Must follow WordPress Coding Standards (WPCS)
- Must include `readme.txt` with tested-up-to, changelog, description

## Performance

| Target | Value |
|--------|-------|
| Performance tier | Standard |
| Background processing | WP-Cron (non-blocking) |
| API calls | Async via cron, never on page load |

## Third-Party Integrations

| Service | Purpose | Direction |
|---------|---------|-----------|
| GitHub REST API v3 | Fetch releases | Pull |
| OpenAI Chat Completions | Generate post content | Push/Pull |
| WordPress AI API | Generate post content (when stable) | Push/Pull |
| ClassifAI (10up plugin) | Generate post content (if active) | Push/Pull |
| `wp_mail` | Notification emails | Push |

## Timeline

Flexible — no hard launch date.

---

## Domains

### DOM-01: DevOps

Local development environment and build tooling.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-01.1 | local-setup | Local WP environment, plugin symlinking, npm/composer setup | planned |

→ [View domain](01-devops/index.md)

---

### DOM-02: Foundation

Plugin scaffold, namespacing, autoloading, WordPress.org compliance.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-02.1 | scaffold-config | Plugin header, constants, autoloading, coding standards | planned |
| EPC-02.2 | plugin-structure | Core Plugin class, hook patterns, activation/deactivation/uninstall | planned |

→ [View domain](02-foundation/index.md)

---

### DOM-03: Settings

All admin configuration UI for the plugin.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-03.1 | admin-ui | Settings page layout, navigation, admin assets | planned |
| EPC-03.2 | plugin-configuration | Tracked repos, AI provider, post defaults, notifications, schedule | planned |

→ [View domain](03-settings/index.md)

---

### DOM-04: GitHub Integration

GitHub Releases API client, release detection, WP-Cron scheduling.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-04.1 | api-client | GitHub API wrapper, auth, rate limit handling | planned |
| EPC-04.2 | release-monitoring | New release detection, deduplication, per-repo state | planned |
| EPC-04.3 | scheduling | WP-Cron registration, custom intervals, manual trigger | planned |

→ [View domain](04-github-integration/index.md)

---

### DOM-05: AI Integration

AI provider connectors, prompt engineering, content generation pipeline.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-05.1 | service-connectors | Provider interface + OpenAI / WordPress AI / ClassifAI implementations | planned |
| EPC-05.2 | prompt-management | Prompt templates, significance classification, title rules | planned |

→ [View domain](05-ai-integration/index.md)

---

### DOM-06: Post Generation

WordPress post creation, taxonomy assignment, publish/draft workflow.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-06.1 | post-creation | wp_insert_post, idempotency, source metadata | planned |
| EPC-06.2 | taxonomy-assignment | Category and tag assignment from settings | planned |
| EPC-06.3 | publish-workflow | Draft vs. publish logic, admin notices, status filters | planned |

→ [View domain](06-post-generation/index.md)

---

### DOM-07: Notifications

Email notifications to site owners when posts are ready.

| Code | Epic | Description | Status |
|------|------|-------------|--------|
| EPC-07.1 | email-notifications | Batched wp_mail notifications with post links | planned |

→ [View domain](07-notifications/index.md)

---

## Project Context

**Core Value:** A WordPress plugin for plugin-focused microsites and product sites that monitors GitHub releases, uses AI to draft human-readable blog posts summarizing updates, and publishes or drafts them automatically — keeping plugin blogs current with zero manual effort.

**Target Users:**
- WordPress site owners running plugin-focused microsites or product sites
- Plugin developers who maintain blogs about their own plugins
- Agencies managing multiple plugin product sites

**Existing Site:** New build — no migration.

---

## Key Decisions Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-03-20 | Provider interface for AI | Allows swapping OpenAI → WordPress AI API → ClassifAI without changing consuming code |
| 2026-03-20 | WP-Cron for scheduling | Native WP, no server-level cron setup required for WordPress.org distribution |
| 2026-03-20 | Batched email notifications | One email per cron run avoids spamming site owners when multiple releases are found |
| 2026-03-20 | Semver-aware significance classification | Drives prompt tone: patches get brief summaries, major releases get fuller posts |
| 2026-03-20 | WordPress.org as distribution target | WPCS compliance, readme.txt, no external dependencies that violate review guidelines |

---

_Managed by Spark_
