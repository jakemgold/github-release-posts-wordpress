---
title: "EPC-04.1: API Client"
---

# Epic: API Client

**Code:** EPC-04.1
**Domain:** GitHub Integration (DOM-04)
**Description:** HTTP client wrapper for the GitHub Releases API with authentication, error handling, and rate limit awareness.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

A thin, testable PHP class that wraps `wp_remote_get()` calls to the GitHub REST API. Handles optional Personal Access Token authentication (to raise the rate limit from 60 to 5,000 req/hr), parses API responses, and surfaces structured release data.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-04.1.01 | github-api-client | draft | Full API client — fetch, auth, rate limiting, repo normalization |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--04-github-integration--1-api-client.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- `GET /repos/{owner}/{repo}/releases` — fetch release list
- `GET /repos/{owner}/{repo}/releases/latest` — fetch latest release
- Optional PAT via `Authorization: Bearer` header
- Response parsing into a `Release` value object (tag, name, body, published_at, html_url, assets)
- Rate limit header inspection (`X-RateLimit-Remaining`)
- WP_Error propagation on failure

**Out of Scope:**
- Scheduling (EPC-04.3)
- Storing release state (EPC-04.2)

## Success Criteria

- [ ] Returns structured release data for any public GitHub repo
- [ ] Authenticated requests use PAT when configured
- [ ] Rate limit exhaustion handled gracefully (logged, not fatal)
- [ ] Unit tested with mocked HTTP responses

---

_Managed by Spark_
