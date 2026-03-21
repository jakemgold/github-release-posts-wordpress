---
title: "DOM-04: GitHub Integration"
---

# Domain: GitHub Integration

**Code:** DOM-04
**Description:** GitHub Releases API client, new-release detection, and the WP-Cron scheduling that drives automatic checks.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Handles all communication with the GitHub Releases API. Polls tracked repositories on a configurable schedule, compares against previously-seen releases, and surfaces new releases to the AI generation pipeline. Also stores per-repo state (last checked, last seen version) to prevent duplicate posts.

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-04.1 | api-client | GitHub Releases API wrapper, auth (optional PAT), rate limit handling | 0 | planned |
| EPC-04.2 | release-monitoring | New release detection, deduplication, per-repo state storage | 0 | planned |
| EPC-04.3 | scheduling | WP-Cron job registration, custom intervals, manual trigger | 0 | planned |

## Domain Boundaries

**In Scope:**
- GitHub REST API v3 `/releases` endpoint
- Optional GitHub Personal Access Token for higher rate limits
- Per-repo "last seen release" state in wp_options or custom table
- WP-Cron event registration and custom schedule intervals
- Rate limit awareness and backoff

**Out of Scope:**
- Parsing changelog content for AI (DOM-05)
- WordPress post creation (DOM-06)
- Plugin settings that configure which repos to check (DOM-03)

## Cross-Domain Dependencies

| Depends On | For |
|------------|-----|
| DOM-03 | Repo list, check interval, optional GitHub PAT |

| Depended On By | For |
|----------------|-----|
| DOM-05 | Release data (tag, body, URL) passed to AI generation |

---

_Managed by Spark_
