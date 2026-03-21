---
epic: 04-github-integration/2-release-monitoring
created: 2026-03-21
status: complete
---

# Epic Plan: 04-github-integration/2-release-monitoring

## Overview

**Epic:** Release Monitoring (EPC-04.2)
**Goal:** Per-repo state tracking, release comparison, onboarding preview draft, manual trigger with conflict resolution, pause toggle, and debug logging.
**PRDs:** release-monitoring.prd.md
**Requirements:** 8 user stories, 34 acceptance criteria, 5 business rules

## Codebase Context

- `GitHub\API_Client` — fetch_latest_release() built in EPC-04.1
- `Plugin_Constants` — META_SOURCE_REPO, META_RELEASE_TAG for deduplication; CRON_HOOK_RELEASE_CHECK, CRON_HOOK_RATE_LIMIT_RETRY for scheduling
- `Repository_Settings` — get_repositories(), update_repository() with paused field
- `Admin_Page` — ajax_generate_draft_now() stub; remove/add repo flows; set_admin_error() pattern
- `tab-repositories.php` — .ctbp-generate-draft button and pause checkbox already in template
- `admin-page.php` — notice transient pattern; needs parallel notice (not just error) transient
- `assets/js/admin/index.js` — ctbpAjax() helper; existing <dialog> pattern to reuse for conflict
- New classes go in `includes/classes/GitHub/`
- PHP 8.2 minimum — readonly, true types, match expressions all available

## Tasks

### Task 1: Constants

**Complexity:** Low
**Dependencies:** None

**Steps:**

1. Add `OPTION_REPO_STATE_PREFIX = 'ctbp_repo_state_'` to `Plugin_Constants`
2. Add `OPTION_RELEASE_QUEUE = 'ctbp_release_queue'` to `Plugin_Constants`
3. Add both to `get_defaults()` with empty defaults (`[]` for queue, not needed for state prefix since each repo gets its own key)

**Files to modify:**
- `includes/classes/Plugin_Constants.php`

---

### Task 2: `Release_State` + `Release_Queue` service classes

**Implements:** US-001 (AC-001–004), US-007 (AC-028–031)
**Complexity:** Low
**Dependencies:** Task 1

**Release_State steps:**

1. Create `includes/classes/GitHub/Release_State.php`
2. Option key per repo: `Plugin_Constants::OPTION_REPO_STATE_PREFIX . md5($identifier)`
3. State shape: `['last_seen_tag' => '', 'last_seen_published_at' => '', 'last_checked_at' => 0]`
4. `get_state(string $identifier): array` — returns state array with defaults
5. `update_last_seen(string $identifier, string $tag, string $published_at): void`
6. `update_last_checked(string $identifier): void` — sets last_checked_at to time()
7. `clear_state(string $identifier): void` — delete_option() for this repo (AC-003, AC-004)

**Release_Queue steps:**

1. Create `includes/classes/GitHub/Release_Queue.php`
2. Backed by `get_option(OPTION_RELEASE_QUEUE, [])` / `update_option()`
3. Entry shape: `['identifier' => '', 'tag' => '', 'name' => '', 'body' => '', 'html_url' => '', 'published_at' => '', 'assets' => []]`
4. `enqueue(string $identifier, Release $release): void` — append entry (AC-028, AC-030)
5. `dequeue_all(): array` — return all entries and clear the option (AC-031)
6. Static `from_release(string $identifier, Release $release): array` — builds entry array

**Verification:**
- [ ] AC-001: last_seen_tag and last_checked_at stored per repo
- [ ] AC-002: state persists; cleared only by clear_state()
- [ ] AC-003: clear_state() removes the option entirely
- [ ] AC-004: re-add results in empty state (clear_state on remove = fresh on re-add)
- [ ] AC-028: enqueue() adds entry to option
- [ ] AC-030: queue entry has all required fields

**Files to create:**
- `includes/classes/GitHub/Release_State.php`
- `includes/classes/GitHub/Release_Queue.php`

---

### Task 3: `Version_Comparator`

**Implements:** US-002 (AC-005–009)
**Complexity:** Medium
**Dependencies:** None

**Steps:**

1. Create `includes/classes/GitHub/Version_Comparator.php`
2. `is_newer(Release $candidate, array $state): bool`
   - If `$state['last_seen_tag']` is empty: return true (new repo, always process)
   - If candidate tag === last_seen_tag: return false (same release)
   - If both tags are semver-ish (`is_semver()`): use `version_compare()` after stripping leading `v` (BR-005)
   - Otherwise: compare `$candidate->published_at` vs `$state['last_seen_published_at']` as ISO 8601 strings (lexicographic comparison is valid for ISO 8601)
3. `is_semver(string $tag): bool` — strips leading `v`, checks `preg_match('/^\d+\.\d+(\.\d+)?/', ...)`
4. Pre-releases and draft releases are already excluded by `/releases/latest` endpoint (AC-009 — no additional filtering needed, document this)

**Verification:**
- [ ] AC-005: newer tag → is_newer() returns true
- [ ] AC-006: semver comparison (v2.0.0 > v1.9.9, v1.10.0 > v1.9.0)
- [ ] AC-007: non-semver falls back to published_at date
- [ ] AC-008: empty last_seen_tag → always true
- [ ] AC-009: pre-releases never returned by API (endpoint behaviour, no extra code needed)

**Files to create:**
- `includes/classes/GitHub/Version_Comparator.php`

---

### Task 4: `Release_Monitor` — cron run loop

**Implements:** US-001 (AC-002), US-002 (AC-005–009), US-006 (AC-024–027), US-007 (AC-028–031), US-008 (AC-032–034)
**Complexity:** High
**Dependencies:** Tasks 2, 3

**Steps:**

1. Create `includes/classes/GitHub/Release_Monitor.php`
2. Constructor: `__construct(private readonly API_Client $api_client, private readonly Release_State $state, private readonly Version_Comparator $comparator, private readonly Release_Queue $queue, private readonly Repository_Settings $repo_settings)`
3. Public `run(): void`:
   a. Get all repos from `$this->repo_settings->get_repositories()`
   b. For each repo:
      - If `$repo['paused']`: log "skipped — paused" (AC-025); `continue`
      - Call `$this->api_client->fetch_latest_release($identifier)`
      - If WP_Error with code `github_rate_limit_exhausted`: log, stop loop (remaining repos not processed; AC-012 from EPC-04.1 — the retry event was already scheduled by API_Client)
      - If other WP_Error: log error, `update_last_checked`, `continue`
      - If null: log "no releases found", `update_last_checked`, `continue`
      - Get state via `$this->state->get_state($identifier)`
      - If `$this->comparator->is_newer($release, $state)`:
        - `$this->queue->enqueue($identifier, $release)`
        - Log "new release found: {tag}" (AC-033)
      - `update_last_checked($identifier)` always on success
   c. Process queue: call `$this->process_queue()`

4. Private `process_queue(): void`:
   - Call `$this->queue->dequeue_all()` (AC-029: same run)
   - For each entry: `do_action('ctbp_process_release', $entry)` (DOM-05/06 hooks here)
   - After action fires, check if post was created: `self::find_post($entry['identifier'], $entry['tag'])`
   - If post exists: `$this->state->update_last_seen($identifier, $tag, $published_at)` (BR-001: only cron updates last_seen)
   - Log outcome (AC-032)

5. Public static `find_post(string $identifier, string $tag): ?\WP_Post`:
   - `get_posts()` query with `META_SOURCE_REPO == $identifier` AND `META_RELEASE_TAG == $tag`
   - `post_status => ['publish', 'draft', 'pending', 'private', 'trash']`
   - Returns first match or null (BR-003: deduplication check)

6. Logging: all log entries via `$this->log()` private method — only writes when `WP_DEBUG && WP_DEBUG_LOG` (AC-034)

**Verification:**
- [ ] AC-002: processed release never requeued (last_seen_tag updated after processing)
- [ ] AC-024: paused toggle respected (already in repo data from settings)
- [ ] AC-025: paused repos skipped — no API call
- [ ] AC-026: pause retains last_seen_tag (clear_state not called on pause)
- [ ] AC-027: re-enable resumes from last_seen_tag (no retroactive processing)
- [ ] AC-029: queue processed in same run
- [ ] AC-031: queue cleared after dequeue_all()
- [ ] AC-032: log entry per repo per run
- [ ] AC-033: new release log includes tag
- [ ] AC-034: logging gated on WP_DEBUG + WP_DEBUG_LOG

**Files to create:**
- `includes/classes/GitHub/Release_Monitor.php`

---

### Task 5: Onboarding handler + Admin_Page wiring

**Implements:** US-003 (AC-010–014), US-004 (AC-015–019), US-005 (AC-020–023), US-006 (AC-024)
**Complexity:** High
**Dependencies:** Tasks 2, 4

**Onboarding_Handler steps:**

1. Create `includes/classes/GitHub/Onboarding_Handler.php`
2. Constructor: `__construct(private readonly API_Client $api_client, private readonly Release_State $state)`
3. `trigger(string $identifier): array` — returns `['type' => 'success'|'warning', 'message' => '...', 'post_url' => string|null]`
   - Fetch latest release
   - If WP_Error or null: return warning message directing to manual trigger (AC-014)
   - Fire `do_action('ctbp_process_release', Release_Queue::from_release($identifier, $release), ['force_draft' => true, 'onboarding' => true])`
   - Record last_seen_tag regardless of generation outcome (BR-001 exception: onboarding sets initial state)
   - Check for created post via `Release_Monitor::find_post($identifier, $release->tag)`
   - If found: return success with post edit URL (AC-013)
   - If not found: return warning (AI not yet configured) (AC-014)

**Admin_Page wiring steps:**

1. In `handle_repositories_save()`, after successful `add_repository()`:
   - Instantiate `Onboarding_Handler` and call `trigger($identifier)`
   - Store result in `set_admin_notice()` transient
2. In `handle_repositories_save()`, after successful `remove_repository()`:
   - Call `(new Release_State())->clear_state($identifier)` (AC-003)
3. Add `set_admin_notice(string $type, string $message): void` private method (stores `ctbp_admin_notice_{user_id}` transient)
4. Add `save_github_pat` call to `handle_settings_save()` (gap from EPC-04.1)
5. Fully implement `ajax_generate_draft_now()`:
   - Validate `repo` from POST
   - Instantiate `API_Client`, fetch latest release
   - If WP_Error/null: return error JSON
   - Check `Release_Monitor::find_post($identifier, $release->tag)` for conflict (BR-003)
   - If conflict: return `{conflict: true, post: {id, title, status, edit_url}}` (AC-020, AC-021)
   - If no conflict: fire `do_action('ctbp_process_release', ..., ['force_draft' => true, 'manual' => true])`, check for created post, return result (AC-015, AC-016, AC-017, AC-018, AC-019)
6. Add `ajax_resolve_conflict()` method:
   - Actions: 'replace' — `wp_delete_post($post_id, true)` then fire action; 'alongside' — fire action only; 'cancel' — return success no-op (AC-022, AC-023)
7. Register `ctbp_resolve_conflict` AJAX action in `register_ajax_actions()`
8. Update `admin-page.php` to read and display `ctbp_admin_notice_{user_id}` transient (alongside existing error transient)

**Verification:**
- [ ] AC-010: onboarding triggers on repo add
- [ ] AC-011: onboarding post always draft
- [ ] AC-012: last_seen_tag set after onboarding
- [ ] AC-013: admin notice with post link on success
- [ ] AC-014: admin notice directs to manual trigger on failure
- [ ] AC-015: Generate draft now button triggers AJAX
- [ ] AC-016: manual trigger always creates draft
- [ ] AC-017: success notice with post link
- [ ] AC-018: no email on manual trigger
- [ ] AC-019: manual trigger does not update last_seen_tag
- [ ] AC-020: conflict detected when post exists for same tag
- [ ] AC-021: conflict response includes existing post details
- [ ] AC-022: replace deletes existing post before generating
- [ ] AC-023: alongside generates new draft without affecting existing

**Files to create:**
- `includes/classes/GitHub/Onboarding_Handler.php`

**Files to modify:**
- `includes/classes/Admin/Admin_Page.php`
- `includes/templates/admin-page.php`

---

### Task 6: Conflict dialog — template + JS

**Implements:** US-005 (AC-020–023) UI layer
**Complexity:** Medium
**Dependencies:** None (UI only)

**Steps:**

1. Add conflict `<dialog>` to `tab-repositories.php` — same pattern as `#ctbp-remove-dialog`:
   - Shows existing post title, status, date, and edit link
   - Three buttons: Replace, Add alongside, Cancel
   - Hidden inputs: `ctbp_conflict_repo`, `ctbp_conflict_tag`, `ctbp_conflict_post_id`
2. Update `assets/js/admin/index.js` generate-draft handler:
   - On `conflict: true` response: populate and open conflict dialog (instead of alert)
   - Wire Replace/Alongside/Cancel buttons to call `ctbp_resolve_conflict` AJAX
   - On resolve success: show success alert with post edit link
3. Add i18n strings to `wp_localize_script` in `Admin_Page::enqueue_assets()`:
   - `conflictTitle`, `conflictReplace`, `conflictAlongside`, `conflictCancel`, `generating`, `draftCreated`

**Verification:**
- [ ] AC-020: conflict dialog appears (not alert) when post exists
- [ ] AC-021: dialog shows post title, status, date, edit link
- [ ] AC-022: Replace button triggers delete + regenerate
- [ ] AC-023: Add alongside creates second draft without touching existing

**Files to modify:**
- `includes/templates/tab-repositories.php`
- `assets/js/admin/index.js`
- `includes/classes/Admin/Admin_Page.php` (i18n strings)

---

### Task 7: Hook `Release_Monitor` into `Plugin.php`

**Implements:** US-007 (AC-028–031) wiring
**Complexity:** Low
**Dependencies:** Task 4

**Steps:**

1. In `Plugin::init()`, instantiate and register `Release_Monitor`:
   ```php
   $monitor = new \TenUp\ChangelogToBlogPost\GitHub\Release_Monitor(
       new \TenUp\ChangelogToBlogPost\GitHub\API_Client( new \TenUp\ChangelogToBlogPost\Settings\Global_Settings() ),
       new \TenUp\ChangelogToBlogPost\GitHub\Release_State(),
       new \TenUp\ChangelogToBlogPost\GitHub\Version_Comparator(),
       new \TenUp\ChangelogToBlogPost\GitHub\Release_Queue(),
       new \TenUp\ChangelogToBlogPost\Settings\Repository_Settings(),
   );
   add_action( Plugin_Constants::CRON_HOOK_RELEASE_CHECK, [ $monitor, 'run' ] );
   add_action( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY, [ $monitor, 'run' ] );
   ```

**Files to modify:**
- `includes/classes/Plugin.php`

---

### Task 8: Unit tests

**Complexity:** Medium
**Dependencies:** Tasks 1–7

**Steps:**

1. `tests/php/unit/GitHub/VersionComparatorTest.php`:
   - `test_is_newer_returns_true_for_empty_last_seen()` — AC-008
   - `test_is_newer_semver_major_bump()` — AC-006
   - `test_is_newer_semver_minor_bump()` — AC-006
   - `test_is_newer_semver_patch_bump()` — AC-006
   - `test_is_newer_semver_strips_leading_v()` — BR-005
   - `test_is_newer_returns_false_for_same_tag()` — AC-005
   - `test_is_newer_non_semver_falls_back_to_date()` — AC-007
   - `test_is_newer_non_semver_older_date_returns_false()` — AC-007

2. `tests/php/unit/GitHub/Release_StateTest.php`:
   - `test_get_state_returns_defaults_when_not_set()` — AC-001
   - `test_update_last_seen_persists_tag_and_date()` — AC-001
   - `test_clear_state_removes_option()` — AC-003

3. `tests/php/unit/GitHub/Release_MonitorTest.php`:
   - `test_paused_repo_skipped()` — AC-025
   - `test_new_release_enqueued()` — AC-028
   - `test_same_release_not_enqueued()` — AC-002
   - `test_rate_limit_stops_loop()` — AC-010 (from EPC-04.1 perspective)
   - `test_last_checked_updated_on_no_change()` — AC-001

**Files to create:**
- `tests/php/unit/GitHub/VersionComparatorTest.php`
- `tests/php/unit/GitHub/Release_StateTest.php`
- `tests/php/unit/GitHub/Release_MonitorTest.php`
