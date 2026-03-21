---
epic: 03-settings/2-plugin-configuration
created: 2026-03-21T00:00:00Z
status: complete
---

# Epic Plan: 03-settings/2-plugin-configuration

## Overview

**Epic:** 03-settings/2-plugin-configuration
**Goal:** Implement repository management (add/remove/configure tracked repos) and global settings (AI provider + encrypted API keys, post defaults, notification prefs, check frequency) with full save/load round-trips, libsodium API key encryption, and WP.org slug validation.
**PRDs:** PRD-03.2.01 (Repository Settings), PRD-03.2.02 (Global Settings)
**Requirements:** 8 user stories, 39 acceptance criteria
**Prerequisite:** EPC-03.1 (admin-ui) must be executed first.

---

## Tasks

### Task 1: Repository_Settings class

**PRD:** PRD-03.2.01
**Implements:** US-001, US-002, US-003
**Complexity:** Medium
**Dependencies:** None (reads/writes `Plugin_Constants` options)

**Steps:**

1. Create `includes/classes/Settings/Repository_Settings.php` with class `TenUp\ChangelogToBlogPost\Settings\Repository_Settings`
2. Add constant `MAX_REPOSITORIES = 25` (filterable)
3. Implement `get_repositories(): array` — reads `Plugin_Constants::OPTION_REPOSITORIES` via `get_option()`, returns empty array if not set
4. Implement `save_repositories( array $repos ): bool` — validates structure, calls `update_option( Plugin_Constants::OPTION_REPOSITORIES, $repos )`
5. Implement `normalize_identifier( string $input ): string` — strips `https://github.com/` prefix if present, trims slashes, validates `owner/repo` pattern via regex; throws `\InvalidArgumentException` on invalid format
6. Implement `derive_display_name( string $repo_name ): string` — converts hyphens and underscores to spaces, applies `ucwords()`; e.g., `my-awesome-plugin` → `My Awesome Plugin`
7. Implement `add_repository( string $input ): array` — returns `['success' => bool, 'error' => string|null, 'repos' => array]`; steps: normalize, check duplicate, check limit (applying `apply_filters( 'ctbp_max_repositories', self::MAX_REPOSITORIES )`), append new repo object `['identifier' => $id, 'display_name' => $this->derive_display_name($name), 'paused' => false, 'wporg_slug' => '', 'custom_url' => '', 'post_status' => '', 'category' => 0, 'tags' => []]`, save, return result
8. Implement `remove_repository( string $identifier ): bool` — filter out by identifier, save
9. Implement `update_repository( string $identifier, array $config ): bool` — find by identifier, merge sanitized `$config` fields (`display_name`, `paused`, `wporg_slug`, `custom_url`, `post_status`, `category`, `tags`), save
10. Implement `validate_wporg_slug( string $slug ): array` — calls `wp_remote_get( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug ) )`, returns `['valid' => bool, 'warning' => string|null]`; treats HTTP errors and `'error'` key in response body as "not found warning" (not blocking error)

**Verification:**

- [ ] AC-001: `normalize_identifier()` handles both formats
- [ ] AC-002: Invalid format returns error
- [ ] AC-003: Duplicate detection returns error
- [ ] AC-005: `MAX_REPOSITORIES` limit enforced with filter hook
- [ ] AC-009: `remove_repository()` only removes the entry, leaves option values for other repos
- [ ] AC-010: `derive_display_name()` converts hyphens/underscores + title case
- [ ] AC-011: WP.org validation is warning-only (non-blocking)
- [ ] AC-014: `paused` field stored per-repo

**Files to create/modify:**

- `includes/classes/Settings/Repository_Settings.php` — new

---

### Task 2: Repositories tab template

**PRD:** PRD-03.2.01
**Implements:** US-001, US-002, US-003, US-004
**Complexity:** High
**Dependencies:** Task 1

**Steps:**

1. Replace the placeholder in `includes/templates/tab-repositories.php` with real content
2. At top of template, instantiate `$repo_settings = new \TenUp\ChangelogToBlogPost\Settings\Repository_Settings()` and `$repos = $repo_settings->get_repositories()`
3. Output repo count and limit warning if at or near limit
4. Render `<table class="ctbp-repo-table widefat">` with `<thead>` columns: Display Name, Repository, Status, Actions
5. For each `$repo` in `$repos`, render a `<tr>` with:
   - Display name cell
   - Identifier cell (`owner/repo`)
   - Status cell: "Active" or "Paused" badge with `aria-label`
   - Actions cell: "Edit" toggle button and "Remove" button (remove opens a `<dialog>` confirmation)
6. After each repo `<tr>`, render a hidden `<tr class="ctbp-repo-edit-row" hidden>` containing the per-repo config fieldset:
   - Display name `<input type="text" name="repos[{i}][display_name]">`
   - WP.org slug `<input type="text" name="repos[{i}][wporg_slug]">` with a "Validate" button that fires `ctbpAjax('ctbp_validate_wporg_slug', ...)`
   - Custom URL `<input type="url" name="repos[{i}][custom_url]">`
   - Post status `<select name="repos[{i}][post_status]">` (Use global / Draft / Publish)
   - Category `<select name="repos[{i}][category]">` populated via `wp_dropdown_categories()` output with a "Use global" option
   - Tags `<input type="text" name="repos[{i}][tags]">` (comma-separated)
   - Pause toggle `<input type="checkbox" name="repos[{i}][paused]">`
   - "Generate draft now" button: `<button type="button" class="button ctbp-generate-draft" data-repo="{identifier}">Generate draft now</button>`
7. After table, render "Add repository" fieldset: `<input type="text" name="ctbp_new_repo" placeholder="owner/repo or GitHub URL">` with an `<input type="submit" name="ctbp_add_repo" class="button">` button (uses the same form POST)
8. All `<input>` elements have associated `<label>` elements for AC-015 compliance
9. Each `name` field uses array notation so the full repository array is posted as `$_POST['repos']`

**Verification:**

- [ ] AC-006: Table with display name, identifier, pause status, remove action
- [ ] AC-007: Edit row revealed by expand control
- [ ] AC-008: Remove button with confirmation dialog
- [ ] AC-010: Display name pre-populated, overridable
- [ ] AC-012: Custom URL field present
- [ ] AC-013: Per-repo post defaults (category, tags, status) with "Use global" option
- [ ] AC-014: Pause toggle visible in edit row
- [ ] AC-015: "Generate draft now" button per row
- [ ] AC-016: "Generate draft now" generates draft regardless of status (enforced by AJAX handler)

**Files to create/modify:**

- `includes/templates/tab-repositories.php` — replace placeholder

---

### Task 3: Global_Settings class

**PRD:** PRD-03.2.02
**Implements:** US-001, US-002, US-003, US-004
**Complexity:** High
**Dependencies:** None (reads/writes `Plugin_Constants` options)

**Steps:**

1. Create `includes/classes/Settings/Global_Settings.php` with class `TenUp\ChangelogToBlogPost\Settings\Global_Settings`
2. Add `SUPPORTED_PROVIDERS = ['openai', 'anthropic', 'gemini', 'classifai', 'wordpress_ai']` constant
3. Implement `get_ai_provider(): string` — reads `Plugin_Constants::OPTION_AI_PROVIDER`, returns stored value or `''`
4. Implement `save_ai_provider( string $provider ): bool` — validates against `SUPPORTED_PROVIDERS`, calls `update_option()`
5. Implement `get_api_keys(): array` — reads `Plugin_Constants::OPTION_AI_API_KEYS`, decrypts all values using `$this->decrypt( $encrypted )`, returns `['openai' => '...', 'anthropic' => '...', 'gemini' => '...']`
6. Implement `save_api_keys( array $keys ): bool` — for each key, skip if the submitted value is the masked placeholder `'••••••••'` (meaning unchanged), otherwise encrypt non-empty values and store; calls `update_option()`
7. Implement `encrypt( string $plaintext ): string` — uses `sodium_crypto_secretbox()` with a key derived from `AUTH_KEY` WordPress constant via `sodium_crypto_generichash()` truncated to `SODIUM_CRYPTO_SECRETBOX_KEYBYTES`; returns base64-encoded `nonce . ciphertext`
8. Implement `decrypt( string $encoded ): string` — reverses `encrypt()`; returns empty string on failure (corrupt/missing data)
9. Implement `get_masked_key( string $provider ): string` — returns `'••••••••'` if a key exists for the provider, `''` otherwise (never returns the actual key)
10. Implement `get_post_defaults(): array` — reads `OPTION_DEFAULT_POST_STATUS`, `OPTION_DEFAULT_CATEGORY`, `OPTION_DEFAULT_TAGS`; returns structured array with defaults `['post_status' => 'draft', 'category' => 0, 'tags' => []]`
11. Implement `save_post_defaults( array $defaults ): bool` — sanitizes and saves each option individually
12. Implement `get_notification_settings(): array` — reads `OPTION_NOTIFICATION_EMAIL`, `OPTION_NOTIFICATION_EMAIL_SECONDARY`, `OPTION_NOTIFICATION_TRIGGER`, `OPTION_NOTIFICATIONS_ENABLED`
13. Implement `save_notification_settings( array $data ): array` — validates both emails via `is_email()`, returns `['saved' => bool, 'errors' => array]`; only saves if both emails valid (or secondary is empty)
14. Implement `get_check_frequency(): string` — reads `OPTION_CHECK_INTERVAL`, returns stored value or `'daily'`
15. Implement `save_check_frequency( string $frequency ): bool` — validates against `['hourly', 'twicedaily', 'daily', 'weekly']`, calls `update_option()`, then reschedules cron by calling `wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )` and `wp_schedule_event( time(), $frequency, Plugin_Constants::CRON_HOOK_RELEASE_CHECK )`

**Verification:**

- [ ] AC-001: Provider selector backed by supported list
- [ ] AC-002: Only one provider active at a time
- [ ] AC-003: API key field shown for key-based providers only
- [ ] AC-004: `get_masked_key()` never returns actual key value
- [ ] AC-005: Encryption via libsodium; decryption only at use-point
- [ ] AC-006: No API key field for delegation providers (enforced by JS + template)
- [ ] AC-008: Global defaults include category, tags, status
- [ ] AC-009: Default status is `'draft'` on first activation (set in `Plugin_Constants::get_defaults()`)
- [ ] AC-013: Primary email pre-populated from `get_option('admin_email')`
- [ ] AC-016: Invalid email blocks save
- [ ] AC-018: Frequency validated against four allowed values
- [ ] AC-019: `save_check_frequency()` immediately reschedules cron

**Files to create/modify:**

- `includes/classes/Settings/Global_Settings.php` — new

---

### Task 4: Settings tab template

**PRD:** PRD-03.2.02
**Implements:** US-001, US-002, US-003, US-004
**Complexity:** Medium
**Dependencies:** Task 3

**Steps:**

1. Replace placeholder in `includes/templates/tab-settings.php` with real content
2. At top, instantiate `$global = new \TenUp\ChangelogToBlogPost\Settings\Global_Settings()` and read all current values
3. **AI Provider section** (`<fieldset>`):
   - `<select name="ctbp_ai_provider" id="ctbp_ai_provider">` with options for each supported provider (labels: OpenAI, Anthropic, Gemini, ClassifAI, WordPress AI API)
   - Per key-based provider: `<div class="ctbp-api-key-row" data-provider="{slug}">` containing `<label>`, `<input type="password" name="ctbp_api_key_{slug}" value="<?php echo esc_attr( $global->get_masked_key( $slug ) ); ?>" autocomplete="new-password">`; shown/hidden by JS based on selected provider
   - For delegation providers: `<div class="ctbp-provider-note" data-provider="{slug}">` with notice text, shown/hidden by JS
   - "Test connection" `<button type="button" id="ctbp-test-connection" class="button">` (fires `ctbpAjax('ctbp_test_ai_connection', ...)`); shown only after save (not on initial page load with no provider)
4. **Global post defaults section** (`<fieldset>`):
   - Post status: `<select name="ctbp_default_post_status">` with Draft / Publish options
   - Category: `wp_dropdown_categories( [ 'name' => 'ctbp_default_category', 'show_option_none' => __('None', ...), ... ] )`
   - Tags: `<input type="text" name="ctbp_default_tags" value="{comma-separated tag names}">` with description
5. **Notifications section** (`<fieldset>`):
   - Enable toggle: `<input type="checkbox" name="ctbp_notifications_enabled">`
   - Primary email: `<input type="email" name="ctbp_notification_email" value="...">` pre-populated from `get_option('admin_email')` if empty
   - Secondary email: `<input type="email" name="ctbp_notification_email_secondary" value="...">`
   - Trigger: `<select name="ctbp_notification_trigger">` with "When draft is created / When post is published / Both"
   - If trigger is "published" but default status is "draft", render a `<p class="description notice-warning">` warning (AC-017)
6. **Check frequency section** (`<fieldset>`):
   - `<select name="ctbp_check_frequency">` with Hourly / Twice Daily / Daily / Weekly
   - Status notice showing last run and next scheduled time from `wp_next_scheduled( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )`
7. All fields have associated `<label for="...">` elements

**Verification:**

- [ ] AC-001: Provider selector present
- [ ] AC-003: API key field conditional on provider type
- [ ] AC-004: Key field uses `type="password"`, shows masked placeholder when key exists
- [ ] AC-006: Notice shown for delegation providers
- [ ] AC-007: "Test connection" button present (stub fires AJAX)
- [ ] AC-008: Category, tags, status fields present
- [ ] AC-013: Primary email pre-populated
- [ ] AC-014: Secondary email field present
- [ ] AC-015: Trigger selector with three options
- [ ] AC-017: Warning when trigger/status mismatch
- [ ] AC-018: Frequency selector with four options
- [ ] AC-020: Next check status notice

**Files to create/modify:**

- `includes/templates/tab-settings.php` — replace placeholder

---

### Task 5: Form save handlers and Admin_Page integration

**PRD:** PRD-03.2.01, PRD-03.2.02
**Implements:** US-001, US-002, US-003, US-004 (both PRDs)
**Complexity:** Medium
**Dependencies:** Tasks 1, 2, 3, 4

**Steps:**

1. Replace the stub `handle_repositories_save()` in `Admin_Page`:
   - Re-verify nonce `ctbp_save_repositories` + `manage_options`
   - If `$_POST['ctbp_add_repo']` is set: call `$repo_settings->add_repository( sanitize_text_field( $_POST['ctbp_new_repo'] ) )`; on error set transient with error message
   - Otherwise: iterate `$_POST['repos']` (array), sanitize each field (status with `sanitize_key`, category with `absint`, tags with `array_map( 'sanitize_text_field', ... )`, display name with `sanitize_text_field`, URL with `esc_url_raw`), call `$repo_settings->update_repository( $identifier, $config )` for each
   - If `$_POST['ctbp_remove_repo']` is set: call `$repo_settings->remove_repository( sanitize_text_field( $_POST['ctbp_remove_repo'] ) )` (with nonce)
   - On success: `wp_safe_redirect( add_query_arg( [ 'tab' => 'repositories', 'saved' => '1' ], $this->get_page_url() ) ); exit;`
   - On error: set transient `ctbp_admin_errors_{current_user_id}` with error array, redirect back

2. Replace stub `handle_settings_save()` in `Admin_Page`:
   - Re-verify nonce `ctbp_save_settings` + `manage_options`
   - Save AI provider: `$global->save_ai_provider( sanitize_key( $_POST['ctbp_ai_provider'] ?? '' ) )`
   - Save API keys: collect `ctbp_api_key_openai`, `ctbp_api_key_anthropic`, `ctbp_api_key_gemini` from POST (raw, not sanitized before encrypt), call `$global->save_api_keys( $keys )`
   - Save post defaults: call `$global->save_post_defaults()`
   - Save notification settings: call `$global->save_notification_settings()`; on email validation error set error transient instead of redirecting with `?saved=1`
   - Save check frequency: call `$global->save_check_frequency( sanitize_key( $_POST['ctbp_check_frequency'] ?? 'daily' ) )`
   - On all success: redirect with `?saved=1&tab=settings`

3. Add `get_page_url(): string` helper to `Admin_Page` that returns `admin_url( 'tools.php?page=changelog-to-blog-post' )`

4. Instantiate `Repository_Settings` and `Global_Settings` as properties of `Admin_Page` (set in constructor) to avoid repeated instantiation

**Verification:**

- [ ] AC-001, AC-002: Add repo validation flows to error display
- [ ] AC-003, AC-005, AC-011, AC-012, AC-013: Per-repo config saved correctly
- [ ] AC-004: Encrypted key not stored in plaintext
- [ ] AC-016: Email validation errors displayed inline
- [ ] AC-019: Frequency change triggers cron reschedule

**Files to create/modify:**

- `includes/classes/Admin/Admin_Page.php` — replace stub handlers, add constructor properties

---

### Task 6: Admin JS for provider/key field visibility

**PRD:** PRD-03.2.02
**Implements:** US-001
**Complexity:** Low
**Dependencies:** Tasks 3, 4

**Steps:**

1. Add to `assets/js/admin/index.js`:
   - On page load and on `#ctbp_ai_provider` change: hide all `.ctbp-api-key-row` and `.ctbp-provider-note` divs, then show the one matching `data-provider` of selected value
   - On `#ctbp_ai_provider` change: also show/hide `#ctbp-test-connection` button (show only if a provider is selected and a key exists)
2. Add JS for repo edit row toggle: clicking "Edit" button finds the sibling `.ctbp-repo-edit-row` and toggles `hidden`; updates button text between "Edit" and "Done"
3. Add JS for WP.org slug validation: on click of "Validate" button adjacent to `[name*="wporg_slug"]`, fire `ctbpAjax('ctbp_validate_wporg_slug', { slug: inputVal }, ...)` and show inline success/warning message

**Verification:**

- [ ] AC-003: API key field shown only for key-based provider
- [ ] AC-006: Delegation provider notice shown instead of key field
- [ ] AC-007: Edit row expands/collapses
- [ ] AC-011: WP.org slug validated with inline feedback

**Files to create/modify:**

- `assets/js/admin/index.js` — provider visibility, repo row toggle, slug validation

---

### Task 7: Unit tests

**PRD:** PRD-03.2.01, PRD-03.2.02
**Implements:** all user stories
**Complexity:** Medium
**Dependencies:** Tasks 1, 3, 5

**Steps:**

1. Create `tests/php/unit/Settings/Repository_SettingsTest.php`:
   - `test_normalize_identifier_strips_github_url()` — `https://github.com/owner/repo` → `owner/repo`
   - `test_normalize_identifier_rejects_invalid_format()` — throws `InvalidArgumentException`
   - `test_add_repository_rejects_duplicate()` — adds same repo twice, second returns error
   - `test_add_repository_enforces_limit()` — mock 25 repos in option, expect error on add
   - `test_derive_display_name()` — `my-awesome-plugin` → `My Awesome Plugin`
   - `test_remove_repository_does_not_affect_other_repos()` — remove one, others remain

2. Create `tests/php/unit/Settings/Global_SettingsTest.php`:
   - `test_encrypt_decrypt_round_trip()` — encrypt then decrypt returns original string
   - `test_get_masked_key_returns_placeholder_not_actual_key()` — mock encrypted key in option, assert return value is `'••••••••'`
   - `test_save_api_key_skips_masked_placeholder()` — submit `'••••••••'`, assert `update_option` NOT called for that key
   - `test_save_notification_settings_rejects_invalid_email()` — invalid primary email returns error, not saved
   - `test_save_check_frequency_reschedules_cron()` — expect `wp_clear_scheduled_hook` and `wp_schedule_event` called
   - `test_get_post_defaults_returns_sensible_defaults()` — empty options return `['post_status' => 'draft', ...]`

**Verification:**

- [ ] All 12 test cases pass

**Files to create/modify:**

- `tests/php/unit/Settings/Repository_SettingsTest.php` — new
- `tests/php/unit/Settings/Global_SettingsTest.php` — new

---

## Acceptance Criteria Coverage

### PRD-03.2.01 (Repository Settings)

| AC | Task |
|----|------|
| AC-001 (normalize identifier) | Task 1 |
| AC-002 (invalid format error) | Task 1 |
| AC-003 (duplicate error) | Task 1 |
| AC-004 (onboarding preview stub) | Task 5 (hook point provided) |
| AC-005 (25 repo limit + filter) | Task 1 |
| AC-006 (table columns) | Task 2 |
| AC-007 (expand/edit row) | Task 2 |
| AC-008 (remove with confirmation) | Task 2 |
| AC-009 (remove doesn't delete posts) | Task 1 |
| AC-010 (display name derivation) | Task 1 |
| AC-011 (WP.org slug validation) | Tasks 1, 6 |
| AC-012 (custom URL field) | Tasks 1, 2 |
| AC-013 (per-repo post defaults) | Tasks 1, 2 |
| AC-014 (pause toggle) | Tasks 1, 2 |
| AC-015 (generate draft now button) | Task 2 |
| AC-016 (generate draft = draft always) | Task 5 stub comment |
| AC-017 (generate draft skips dedup) | Task 5 stub comment |
| AC-018 (conflict resolution hook) | Task 5 stub comment |
| AC-019 (generate draft no notification) | Task 5 stub comment |

### PRD-03.2.02 (Global Settings)

| AC | Task |
|----|------|
| AC-001 (provider selector) | Tasks 3, 4 |
| AC-002 (one provider active) | Tasks 3, 5 |
| AC-003 (API key field conditional) | Tasks 3, 4, 6 |
| AC-004 (key masked in UI) | Tasks 3, 4 |
| AC-005 (libsodium encryption) | Task 3 |
| AC-006 (delegation provider notice) | Tasks 4, 6 |
| AC-007 (test connection button) | Tasks 4, 6 |
| AC-008 (global post defaults) | Tasks 3, 4 |
| AC-009 (default status = draft) | Task 3 |
| AC-010 (per-repo takes precedence) | Task 3 — documented contract |
| AC-011 (no category = Uncategorized) | Task 3 |
| AC-012 (generate draft uses fallback) | Task 3 — documented contract |
| AC-013 (primary email pre-populated) | Tasks 3, 4 |
| AC-014 (secondary email optional) | Tasks 3, 4 |
| AC-015 (notification trigger selector) | Tasks 3, 4 |
| AC-016 (email validation blocks save) | Tasks 3, 5 |
| AC-017 (warning on trigger/status mismatch) | Task 4 |
| AC-018 (four frequency options) | Tasks 3, 4 |
| AC-019 (frequency change reschedules cron) | Tasks 3, 5 |
| AC-020 (next check status notice) | Task 4 |
