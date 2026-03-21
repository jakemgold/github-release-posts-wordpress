---
epic: 04-github-integration/1-api-client
created: 2026-03-21
status: ready-for-execution
---

# Epic Plan: 04-github-integration/1-api-client

## Overview

**Epic:** API Client (EPC-04.1)
**Goal:** Thin, testable PHP HTTP client wrapping `wp_remote_get()` for the GitHub Releases API — handles optional PAT authentication, response parsing, transient caching, and rate limit awareness.
**PRDs:** github-api-client.prd.md
**Requirements:** 4 user stories, 15 acceptance criteria, 6 business rules

## Codebase Context

- `Plugin_Constants` — centralises all option keys and cron hook names; no GitHub PAT constant yet
- `Global_Settings` — AI API keys encrypted with libsodium; same pattern will apply to GitHub PAT
- `Repository_Settings::normalize_identifier()` — already normalises `owner/repo` and full GitHub URLs; API_Client will call this directly
- `Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY` — already defined; used by rate limit retry scheduling
- Namespace: `TenUp\ChangelogToBlogPost\GitHub`
- New files go in `includes/classes/GitHub/`
- Tests go in `tests/php/unit/GitHub/`

---

## Tasks

### Task 1: GitHub PAT — constant, settings getter/setter, admin UI

**PRD:** github-api-client.prd.md
**Implements:** US-002 — AC-006, AC-007, AC-008
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. Add `OPTION_GITHUB_PAT` constant to `Plugin_Constants` (encrypted string, default `''`)
2. Add `''` entry for `OPTION_GITHUB_PAT` in `Plugin_Constants::get_defaults()`
3. Add `get_github_pat(): string` to `Global_Settings` — decrypts stored value via existing `decrypt()`
4. Add `save_github_pat(string $pat): bool` to `Global_Settings` — encrypts via existing `encrypt()`, allows clearing with empty string
5. Add `get_masked_github_pat(): string` to `Global_Settings` — returns `MASKED_PLACEHOLDER` if set, empty string otherwise
6. Add GitHub Personal Access Token field to `includes/templates/tab-settings.php` — password input, same masked-placeholder pattern as AI API key fields; include description explaining the rate limit benefit (60 → 5,000 req/hr)

**Verification:**

- [ ] AC-006: Requests include `Authorization: Bearer {token}` when PAT is set
- [ ] AC-007: Requests have no Authorization header when PAT is empty
- [ ] AC-008: PAT value never appears in page source, log output, or error messages

**Files to modify:**

- `includes/classes/Plugin_Constants.php` — add constant + default
- `includes/classes/Settings/Global_Settings.php` — add 3 PAT methods
- `includes/templates/tab-settings.php` — add PAT field to GitHub section

---

### Task 2: `GitHub\Release` value object

**PRD:** github-api-client.prd.md
**Implements:** US-001 — AC-001 (data shape)
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. Create `includes/classes/GitHub/Release.php` in namespace `TenUp\ChangelogToBlogPost\GitHub`
2. Immutable readonly class with properties: `string $tag`, `string $name`, `string $body`, `string $published_at`, `string $html_url`, `array $assets`
3. Add static factory `from_api_response(array $data): self` — maps GitHub API response fields:
   - `tag_name` → `$tag`
   - `name` → `$name` (fallback to `tag_name` if empty)
   - `body` → `$body` (fallback to `''`)
   - `published_at` → `$published_at`
   - `html_url` → `$html_url`
   - `assets` → `$assets` (array of asset objects, default `[]`)

**Verification:**

- [ ] AC-001: Returned object carries tag, name, body, published_at, html_url, assets

**Files to create:**

- `includes/classes/GitHub/Release.php`

---

### Task 3: `GitHub\API_Client` — fetch, auth, error handling

**PRD:** github-api-client.prd.md
**Implements:** US-001 (AC-001–004), US-002 (AC-006–008)
**Complexity:** Medium
**Dependencies:** Tasks 1, 2

**Steps:**

1. Create `includes/classes/GitHub/API_Client.php` in namespace `TenUp\ChangelogToBlogPost\GitHub`
2. Constructor: `__construct(private Global_Settings $settings)` — receives settings via dependency injection
3. Add private `normalize_identifier(string $input): string` — delegates to `Repository_Settings::normalize_identifier()` (reuses existing logic, throws `\InvalidArgumentException` on invalid input)
4. Add public `fetch_latest_release(string $identifier): Release|\WP_Error|null`:
   - Normalize `$identifier` via `normalize_identifier()`; return `WP_Error` on `InvalidArgumentException`
   - Build request URL: `https://api.github.com/repos/{owner}/{repo}/releases/latest`
   - Build request args:
     - `User-Agent: changelog-to-blog-post/` + `CHANGELOG_TO_BLOG_POST_VERSION`
     - `Accept: application/vnd.github+json`
     - `X-GitHub-Api-Version: 2022-11-28`
     - If PAT configured: `Authorization: Bearer {token}` (token fetched via `$this->settings->get_github_pat()`)
     - `timeout: 15`
   - Check transient cache (Task 4 adds this — leave hook point)
   - Call `wp_remote_get($url, $args)`
   - If `is_wp_error($response)`: return the `WP_Error` directly (BR-005)
   - Extract HTTP status code via `wp_remote_retrieve_response_code()`
   - HTTP 404: return `null` (repo has no releases or does not exist — private repos rejected upstream per BR-001)
   - HTTP 403: return `WP_Error('github_forbidden', ...)` — private/auth issue
   - HTTP 200: parse JSON body, return `Release::from_api_response($data)` or `WP_Error` on JSON decode failure
   - Other 4xx/5xx: return `WP_Error('github_http_error', "GitHub API returned HTTP {$code}")`
5. PAT must never be included in any WP_Error message (AC-008) — ensure error messages reference only "a token" or "Authorization" conceptually

**Verification:**

- [ ] AC-001: Valid public `owner/repo` returns `Release` with all fields
- [ ] AC-002: Full GitHub URL input normalised and fetched successfully
- [ ] AC-003: Repo with no releases returns `null` (not `WP_Error`)
- [ ] AC-004: Nonexistent/private repo returns `WP_Error` with descriptive message
- [ ] AC-006: PAT present → `Authorization: Bearer` header included
- [ ] AC-007: No PAT → no `Authorization` header
- [ ] AC-008: PAT value never appears in error messages or logs

**Files to create:**

- `includes/classes/GitHub/API_Client.php`

---

### Task 4: Transient caching

**PRD:** github-api-client.prd.md
**Implements:** US-001 — AC-005
**Complexity:** Low
**Dependencies:** Task 3

**Steps:**

1. Add `TRANSIENT_RELEASE_PREFIX = 'ctbp_rel_'` constant to `Plugin_Constants`
2. In `API_Client::fetch_latest_release()`, before the HTTP call:
   - Compute cache key: `Plugin_Constants::TRANSIENT_RELEASE_PREFIX . md5($normalised_identifier)`
   - Call `get_transient($cache_key)` — if result is a `Release` instance, return it immediately
3. After a successful HTTP 200 parse:
   - Call `set_transient($cache_key, $release, 15 * MINUTE_IN_SECONDS)`
4. Do NOT cache `null` or `WP_Error` results — always re-fetch errors

**Verification:**

- [ ] AC-005: Second call within 15 min returns cached result without additional HTTP request

**Files to modify:**

- `includes/classes/Plugin_Constants.php` — add `TRANSIENT_RELEASE_PREFIX`
- `includes/classes/GitHub/API_Client.php` — add cache check + set

---

### Task 5: Rate limit detection + retry scheduling

**PRD:** github-api-client.prd.md
**Implements:** US-003 — AC-009, AC-010, AC-011
**Complexity:** Medium
**Dependencies:** Task 3

**Steps:**

1. Add `TRANSIENT_RATE_LIMIT_REMAINING = 'ctbp_rate_limit_remaining'` constant to `Plugin_Constants`
2. In `API_Client::fetch_latest_release()`, after any successful HTTP response (200 or 404):
   - Extract `X-RateLimit-Remaining` header via `wp_remote_retrieve_header($response, 'x-ratelimit-remaining')`
   - If header is present: store value in transient `TRANSIENT_RATE_LIMIT_REMAINING` with 1-hour TTL
3. After storing the rate limit value, check if it equals `'0'`:
   - If exhausted: `error_log('[changelog-to-blog-post] GitHub rate limit exhausted. Scheduling retry.')` (AC-011 — not fatal)
   - Schedule one-time retry: `wp_schedule_single_event(time() + HOUR_IN_SECONDS, Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY)` — only if not already scheduled (AC-010)
   - Return `WP_Error('github_rate_limit_exhausted', ...)` so the calling layer (EPC-04.2) can stop processing further repos
4. Note: AC-012 (resume from where it left off) is the responsibility of EPC-04.2 Release Monitoring — it receives the `rate_limit_exhausted` WP_Error code and stops the loop, persisting the unprocessed repos for the retry run

**Verification:**

- [ ] AC-009: `X-RateLimit-Remaining` inspected after each response
- [ ] AC-010: Exhaustion triggers one-time retry event scheduling
- [ ] AC-011: Rate limit exhaustion logged as warning, never throws or crashes

**Files to modify:**

- `includes/classes/Plugin_Constants.php` — add `TRANSIENT_RATE_LIMIT_REMAINING`
- `includes/classes/GitHub/API_Client.php` — add rate limit inspection + retry scheduling

---

### Task 6: Unit tests

**PRD:** github-api-client.prd.md
**Implements:** Verification of all 15 ACs
**Complexity:** Medium
**Dependencies:** Tasks 1–5

**Steps:**

1. Create `tests/php/unit/GitHub/ReleaseTest.php`:
   - `test_from_api_response_maps_all_fields()` — AC-001
   - `test_from_api_response_falls_back_name_to_tag_name()` — name field robustness
   - `test_from_api_response_defaults_empty_body_and_assets()` — edge case

2. Create `tests/php/unit/GitHub/API_ClientTest.php` — use `WP_Mock`:
   - `test_returns_release_for_valid_repo()` — AC-001: mocked 200 response → Release
   - `test_normalises_full_github_url()` — AC-002: full URL input, mock same endpoint
   - `test_returns_null_for_404()` — AC-003: mocked 404 → null
   - `test_returns_wp_error_for_403()` — AC-004: mocked 403 → WP_Error
   - `test_uses_cached_transient()` — AC-005: set_transient on first call, get_transient returns it on second, no second HTTP call
   - `test_includes_authorization_header_when_pat_set()` — AC-006: mock Global_Settings returning PAT, assert header arg
   - `test_no_authorization_header_when_pat_empty()` — AC-007: mock returns empty string
   - `test_pat_not_in_error_messages()` — AC-008: error messages don't contain the PAT value
   - `test_stores_rate_limit_remaining_header()` — AC-009: mocked response with rate limit header
   - `test_schedules_retry_on_rate_limit_exhaustion()` — AC-010: mocked `X-RateLimit-Remaining: 0` → `wp_schedule_single_event` called
   - `test_rate_limit_exhaustion_returns_wp_error_not_exception()` — AC-011: returns WP_Error, no exception thrown

**Verification:**

- [ ] All 15 ACs have corresponding test coverage
- [ ] Tests use WP_Mock (no live HTTP calls)

**Files to create:**

- `tests/php/unit/GitHub/ReleaseTest.php`
- `tests/php/unit/GitHub/API_ClientTest.php`
