---
epic: 04-github-integration/1-api-client
completed: 2026-03-21
---

# Epic Summary: 04-github-integration/1-api-client

**Completed:** 2026-03-21

## Tasks Completed

| # | Task | Commit | Status |
|---|------|--------|--------|
| 1 | GitHub PAT: constant, settings, admin UI | 6f8215e | ✓ |
| 2 | `GitHub\Release` value object | 1be1379 | ✓ |
| 3–5 | `GitHub\API_Client`: fetch, auth, caching, rate limit | 0ecb481 | ✓ |
| 6 | Unit tests (`ReleaseTest`, `API_ClientTest`) | 09193c0 | ✓ |

## Requirements Delivered

| REQ-ID | PRD | Requirement | Verified |
|--------|-----|-------------|---------|
| US-001 | github-api-client.prd | Fetch latest release for a public repo | ✓ |
| US-002 | github-api-client.prd | Authenticate with a Personal Access Token | ✓ |
| US-003 | github-api-client.prd | Handle rate limit exhaustion gracefully | ✓ |
| US-004 | github-api-client.prd | Repository count limit (enforced at settings layer) | ✓ |

## Acceptance Criteria

| AC-ID | Criterion | Status |
|-------|-----------|--------|
| AC-001 | Valid public `owner/repo` returns Release with all fields | ✓ pass |
| AC-002 | Full GitHub URL normalised to `owner/repo` | ✓ pass |
| AC-003 | No releases → null (not WP_Error) | ✓ pass |
| AC-004 | Nonexistent/private repo → WP_Error | ✓ pass |
| AC-005 | Responses cached in 15-min transient; no duplicate HTTP calls | ✓ pass |
| AC-006 | PAT configured → `Authorization: Bearer` header included | ✓ pass |
| AC-007 | No PAT → no Authorization header | ✓ pass |
| AC-008 | PAT value never in logs or error messages | ✓ pass |
| AC-009 | `X-RateLimit-Remaining` inspected and stored after each response | ✓ pass |
| AC-010 | Exhaustion schedules one-time retry cron event | ✓ pass |
| AC-011 | Rate limit exhaustion logged as warning, never fatal | ✓ pass |
| AC-012 | Resume from incomplete repos (caller responsibility — EPC-04.2) | deferred |
| AC-013 | Max 25 repos enforced in admin UI | ✓ (Repository_Settings) |
| AC-014 | `ctbp_max_repositories` filter documented | ✓ (Repository_Settings) |
| AC-015 | UI prevents adding repos at limit | ✓ (Repository_Settings) |

> AC-012 note: the API_Client signals exhaustion via `WP_Error('github_rate_limit_exhausted')`. The Release Monitoring epic (EPC-04.2) is responsible for stopping its loop and persisting unprocessed repos for the retry run.

## Files Changed

**Created:**
- `includes/classes/GitHub/Release.php` — immutable Release value object
- `includes/classes/GitHub/API_Client.php` — HTTP client (fetch, auth, cache, rate limit)
- `tests/php/unit/GitHub/ReleaseTest.php` — 3 unit tests
- `tests/php/unit/GitHub/API_ClientTest.php` — 11 unit tests covering all ACs

**Modified:**
- `includes/classes/Plugin_Constants.php` — added `OPTION_GITHUB_PAT`, `TRANSIENT_RELEASE_PREFIX`, `TRANSIENT_RATE_LIMIT_REMAINING`
- `includes/classes/Settings/Global_Settings.php` — added `get_github_pat()`, `save_github_pat()`, `get_masked_github_pat()`
- `includes/templates/tab-settings.php` — added GitHub PAT field

## Next Steps

- Run `/spark-eng:verify-epic 04-github-integration/1-api-client` for full verification
- Continue to: `/spark-eng:execute-epic 04-github-integration/2-release-monitoring`
