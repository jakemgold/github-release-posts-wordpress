---
title: "PRD-04.1.01: GitHub API Client"
code: PRD-04.1.01
epic: EPC-04.1
domain: DOM-04
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-04.1.01: GitHub API Client

**Epic:** API Client (EPC-04.1) — GitHub Integration (DOM-04)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

The plugin needs to fetch the latest release for any tracked public GitHub repository in order to determine whether a new version has been released since the last check. This requires a reliable, testable HTTP layer that handles authentication, errors, and rate limiting gracefully — and surfaces structured release data to the rest of the pipeline.

---

## Target Users

- **The plugin pipeline** — the primary consumer; this is an internal service component
- **Site owners (indirectly)** — benefit from reliable data fetching that never breaks their site

---

## Overview

A focused HTTP client that fetches the latest release from the GitHub Releases API for a given public repository. Accepts repository input in either `owner/repo` or full GitHub URL format, normalizes to a canonical internal format, and returns a structured release data object. Handles optional Personal Access Token authentication, inspects rate limit headers, and responds to exhaustion by scheduling a retry rather than failing silently.

---

## User Stories & Acceptance Criteria

### US-001: Fetch latest release for a public repo

As the plugin pipeline, I want to retrieve the latest release for a tracked repository so that I can determine whether a new version has been published.

**Acceptance Criteria:**

- [ ] **AC-001:** Given a valid public `owner/repo`, the client returns structured release data including: release tag, release name, release body (changelog text), publication date, and the GitHub release URL.
- [ ] **AC-002:** Given a full GitHub URL (`https://github.com/owner/repo`), the client normalizes it to `owner/repo` and fetches successfully.
- [ ] **AC-003:** If the repository has no releases, the client returns a clear non-error indicator (e.g. empty/null result) rather than a failure.
- [ ] **AC-004:** If the repository does not exist or is private, the client returns a `WP_Error` with a descriptive message.
- [ ] **AC-005:** Successful API responses are cached in a short-lived transient (15 minutes) keyed by `owner/repo`, so a cron firing twice in quick succession does not result in duplicate API calls.

---

### US-002: Authenticate with a Personal Access Token

As a site owner with many tracked repos, I want to provide a GitHub Personal Access Token so that my rate limit is raised from 60 to 5,000 requests per hour.

**Acceptance Criteria:**

- [ ] **AC-006:** When a PAT is configured in plugin settings, all API requests include it as a Bearer token in the Authorization header.
- [ ] **AC-007:** When no PAT is configured, requests are made unauthenticated.
- [ ] **AC-008:** The PAT is never exposed in page source, log output, or error messages — only the fact that one is/isn't configured.

---

### US-003: Handle rate limit exhaustion gracefully

As a site owner, I want the plugin to recover automatically if it hits the GitHub API rate limit, without generating errors or breaking my site.

**Acceptance Criteria:**

- [ ] **AC-009:** After each API response, the client inspects rate limit headers and records the remaining request count.
- [ ] **AC-010:** If the rate limit is exhausted mid-run, the client stops making further requests for that cron run, logs a warning, and schedules a one-hour retry event.
- [ ] **AC-011:** Rate limit exhaustion is never surfaced as a fatal error — the site continues to function normally.
- [ ] **AC-012:** When the retry event fires, the run resumes from where it left off (repos not yet checked in the previous run).

---

### US-004: Repository count limit

As a plugin developer targeting WordPress.org, I want to cap the number of tracked repositories so that unauthenticated users stay within safe API limits.

**Acceptance Criteria:**

- [ ] **AC-013:** The plugin enforces a maximum of 25 tracked repositories in the admin UI.
- [ ] **AC-014:** A developer can override the repository limit using a documented filter hook, with the filter receiving the current limit as its argument.
- [ ] **AC-015:** When the limit is reached, the UI prevents adding more repos and displays a clear explanation with guidance to add a PAT for higher limits.

---

## Business Rules

- **BR-001:** Only public GitHub repositories are supported. Requests for private repositories are rejected at input validation, not at the API layer.
- **BR-002:** Input normalization must handle both `owner/repo` and `https://github.com/owner/repo` (with or without trailing slash). Any other format is rejected with a validation error.
- **BR-003:** The client always uses the `/releases/latest` endpoint — never fetches a paginated list of releases. The plugin is forward-looking; historical releases are not processed.
- **BR-004:** All HTTP requests use WordPress's HTTP API (`wp_remote_get`). Direct use of cURL or other HTTP libraries is not permitted.
- **BR-005:** Failures always return `WP_Error` — the client never throws exceptions.
- **BR-006:** Pre-releases and draft releases are never returned. The `/releases/latest` endpoint natively excludes these; the client relies on this behavior and does not implement additional filtering.

---

## Out of Scope

- Fetching release lists or release history (only latest release, per BR-003)
- Private repository support
- Webhook-based release detection (polling only)
- Storing or persisting release data (responsibility of EPC-04.2)
- Scheduling or triggering checks (responsibility of EPC-04.3)
- Parsing or interpreting release content for AI (responsibility of DOM-05)

---

## Dependencies

| Depends On | For |
|------------|-----|
| DOM-03 Settings | PAT value (if configured), repository list, repo count limit |
| EPC-04.3 Scheduling | Retry event scheduling on rate limit exhaustion |

| Depended On By | For |
|----------------|-----|
| EPC-04.2 Release Monitoring | Receives structured release data from this client |

---

## Open Questions

None — all questions resolved.

---

_Managed by Spark_
