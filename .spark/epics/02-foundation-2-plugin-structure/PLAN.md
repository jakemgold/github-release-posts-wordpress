---
epic: 02-foundation/2-plugin-structure
prd: PRD-02.2.01
created: 2026-03-21
status: ready-for-execution
depends-on: 02-foundation/1-scaffold-config
---

# Epic Plan: 02-foundation/2-plugin-structure

## Overview

**Epic:** Plugin Structure (EPC-02.2)
**Goal:** Runtime architecture — Plugin singleton, activation/deactivation/uninstall lifecycle, clean bootstrap pattern all feature classes attach to.
**PRDs:** PRD-02.2.01
**Requirements:** 4 user stories, 17 acceptance criteria

## Current State

| File | State | Action |
|------|-------|--------|
| `changelog-to-blog-post.php` | Exists — bootstraps Plugin via `plugins_loaded` | No changes needed for bootstrap wiring |
| `includes/classes/Plugin.php` | Exists — singleton with `setup()`/`init()`/`i18n()` | Add `init()` component wiring pattern; register activation/deactivation hooks |
| `uninstall.php` | Missing | Create |
| Activation handler | Missing | Add to `Plugin.php` or dedicated `Activator` class |
| Deactivation handler | Missing | Add to `Plugin.php` or dedicated `Activator` class |
| Default option constants | Missing | Define all default option keys and values |

**Note:** The existing `Plugin.php` already satisfies AC-001 (plugins_loaded), AC-002 (singleton), AC-004 (i18n on init). Tasks below complete the remaining gaps.

---

## Tasks

### Task 1: Define option key constants and defaults

**PRD:** PRD-02.2.01
**Implements:** US-002 (AC-006) — prerequisite for activation handler
**Complexity:** Low
**Dependencies:** Epic 02-foundation/1-scaffold-config complete

**Steps:**

1. Create `includes/classes/Plugin_Constants.php` (or define in `Plugin.php`) to centralise all option key strings and their default values. Keys needed (based on all approved PRDs):
   - `changelog_to_blog_post_repositories` → `[]` (empty array)
   - `changelog_to_blog_post_ai_provider` → `''`
   - `changelog_to_blog_post_ai_api_keys` → `[]` (encrypted, per-provider)
   - `changelog_to_blog_post_default_post_status` → `'draft'`
   - `changelog_to_blog_post_default_category` → `0`
   - `changelog_to_blog_post_default_tags` → `[]`
   - `changelog_to_blog_post_check_interval` → `'daily'`
   - `changelog_to_blog_post_notification_email` → `''` (falls back to admin email at use time)
   - `changelog_to_blog_post_notification_email_secondary` → `''`
   - `changelog_to_blog_post_notification_trigger` → `'draft'`
   - `changelog_to_blog_post_notifications_enabled` → `true`
2. Keep keys and defaults as class constants or a static method returning an array — no hardcoded strings scattered across the codebase.

**Verification:**

- [ ] AC-006 (partial): Default values for all plugin settings are defined in one place

**Files to create/modify:**

- `includes/classes/Plugin.php` — add `get_default_options()` static method, or
- `includes/classes/Plugin_Constants.php` — dedicated constants class

---

### Task 2: Implement activation handler

**PRD:** PRD-02.2.01
**Implements:** US-002 (AC-006, AC-007, AC-008, AC-009)
**Complexity:** Medium
**Dependencies:** Task 1

**Steps:**

1. Create `includes/classes/Activator.php` with a static `activate()` method.
2. Register it from the main plugin file using `register_activation_hook( __FILE__, [ 'TenUp\ChangelogToBlogPost\Activator', 'activate' ] )`.
3. In `activate()`:
   - Check `current_user_can( 'manage_options' )` — return early if false (AC-009).
   - Call `add_option()` for each default from Task 1 (AC-006). Use `add_option` not `update_option` to preserve existing values on reactivation (BR-003).
   - Clear any existing plugin cron event (`wp_clear_scheduled_hook`) then register a fresh one with `wp_schedule_event` using the stored or default interval (AC-007, AC-008).
4. Write a PHPUnit/WP_Mock unit test for `Activator::activate()` covering: defaults written, cron registered, capability check, stale cron cleared on reactivation.

**Verification:**

- [ ] AC-006: `add_option()` called for all settings with defaults; existing values preserved on reactivation
- [ ] AC-007: Cron event registered on activation
- [ ] AC-008: Stale cron event cleared before registering fresh one
- [ ] AC-009: Returns early without side effects if current user lacks `manage_options`

**Files to create:**

- `includes/classes/Activator.php` — activation handler
- `tests/php/unit/ActivatorTest.php` — unit tests

**Files to modify:**

- `changelog-to-blog-post.php` — add `register_activation_hook` call

---

### Task 3: Implement deactivation handler

**PRD:** PRD-02.2.01
**Implements:** US-003 (AC-010, AC-011, AC-012)
**Complexity:** Low
**Dependencies:** Task 2 (cron hook name must be defined)

**Steps:**

1. Add a static `deactivate()` method to `Activator.php` (or create `Deactivator.php`).
2. Register it from the main plugin file using `register_deactivation_hook`.
3. In `deactivate()`:
   - Clear the recurring cron event with `wp_clear_scheduled_hook` (AC-010).
   - Clear the one-time rate-limit retry cron event with `wp_clear_scheduled_hook` (AC-011).
   - Do NOT delete options or post data (AC-012).
4. Write unit test for deactivation: verify cron hooks cleared, options untouched.

**Verification:**

- [ ] AC-010: Recurring cron event cleared on deactivation
- [ ] AC-011: Retry cron event cleared on deactivation
- [ ] AC-012: No options or post data deleted on deactivation

**Files to modify:**

- `includes/classes/Activator.php` — add `deactivate()` method
- `changelog-to-blog-post.php` — add `register_deactivation_hook` call
- `tests/php/unit/ActivatorTest.php` — add deactivation tests

---

### Task 4: Create uninstall.php

**PRD:** PRD-02.2.01
**Implements:** US-004 (AC-013, AC-014, AC-015, AC-016, AC-017)
**Complexity:** Medium
**Dependencies:** Task 1 (option key list), Task 2 (cron hook names)

**Steps:**

1. Create `uninstall.php` in the plugin root (WordPress calls this on plugin deletion from the Plugins screen).
2. Start with the standard guard: `if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }`.
3. Delete all plugin options using `delete_option()` for each key defined in Task 1 (AC-013).
4. Delete all plugin post meta from all posts using `delete_post_meta_by_key()` for each meta key:
   - `_changelog_source_repo`
   - `_changelog_release_tag`
   - `_changelog_release_url`
   - `_changelog_generated_by`
   (AC-014)
5. Clear any remaining plugin cron events (AC-015).
6. Delete plugin transients using `delete_transient()` — use a naming convention (e.g., `changelog_to_blog_post_gh_*`, `changelog_to_blog_post_ai_*`) and consider `$wpdb->query` with a `LIKE` pattern if wildcard deletion is needed (AC-016).
7. Do NOT delete any `post` records — only meta (AC-017).
8. Write unit tests for uninstall (test the logic as a function or via direct include in test).

**Verification:**

- [ ] AC-013: All plugin options deleted from `wp_options`
- [ ] AC-014: All plugin post meta deleted from all posts
- [ ] AC-015: All cron events cleared
- [ ] AC-016: All plugin transients deleted
- [ ] AC-017: No WordPress posts deleted

**Files to create:**

- `uninstall.php` — uninstall handler
- `tests/php/unit/UninstallTest.php` — unit tests

---

### Task 5: Complete Plugin singleton init() pattern

**PRD:** PRD-02.2.01
**Implements:** US-001 (AC-003)
**Complexity:** Low
**Dependencies:** Task 1

**Steps:**

1. Update `Plugin::init()` to instantiate feature classes in a clear, documented pattern. At this stage (no feature classes yet), document the pattern with a comment:
   ```php
   // Feature classes are instantiated here. Example:
   // ( new \TenUp\ChangelogToBlogPost\Feature\MyFeature() )->setup();
   ```
2. Ensure `Plugin::init()` is the sole place feature classes are instantiated — no class should call `new AnotherFeature()` internally (BR-002). Add a doc block comment to that effect.
3. Write a unit test for the singleton: verify `get_instance()` called twice returns the same object.

**Verification:**

- [ ] AC-002: Singleton returns same instance on repeated calls
- [ ] AC-003: `init()` is the single documented instantiation point for feature classes

**Files to modify:**

- `includes/classes/Plugin.php` — update `init()` with pattern comment and docblock
- `tests/php/unit/PluginTest.php` — singleton + bootstrap tests

---

## Plan Summary

| # | Task | Implements | Complexity | Dependencies |
|---|------|------------|------------|--------------|
| 1 | Define option key constants and defaults | AC-006 (partial) | Low | Scaffold epic |
| 2 | Implement activation handler | AC-006–009 | Medium | Task 1 |
| 3 | Implement deactivation handler | AC-010–012 | Low | Task 2 |
| 4 | Create uninstall.php | AC-013–017 | Medium | Tasks 1, 2 |
| 5 | Complete Plugin singleton init() pattern | AC-002, AC-003 | Low | Task 1 |

**Total:** 5 tasks, 1 PRD, 17 acceptance criteria
**Estimated complexity:** Low–Medium — mostly boilerplate lifecycle code with unit tests

## AC Coverage

| AC | Task | Description |
|----|------|-------------|
| AC-001 | — | Already satisfied — `plugins_loaded` hook in main file ✓ |
| AC-002 | 5 | Singleton returns same instance |
| AC-003 | 5 | `init()` is sole instantiation point |
| AC-004 | — | Already satisfied — `i18n()` hooked to `init` ✓ |
| AC-005 | 2, 3 | Verified by running activation/deactivation without PHP errors |
| AC-006 | 1, 2 | Default options written via `add_option` on activation |
| AC-007 | 2 | Cron event registered on activation |
| AC-008 | 2 | Stale cron cleared before fresh registration |
| AC-009 | 2 | Capability check in activation handler |
| AC-010 | 3 | Recurring cron cleared on deactivation |
| AC-011 | 3 | Retry cron cleared on deactivation |
| AC-012 | 3 | Options and posts untouched on deactivation |
| AC-013 | 4 | All plugin options deleted on uninstall |
| AC-014 | 4 | All plugin post meta deleted on uninstall |
| AC-015 | 4 | Cron events cleared on uninstall |
| AC-016 | 4 | Plugin transients deleted on uninstall |
| AC-017 | 4 | Generated posts retained on uninstall |

## New Files Created

| File | Purpose |
|------|---------|
| `includes/classes/Activator.php` | Activation + deactivation handlers |
| `uninstall.php` | Uninstall cleanup |
| `tests/php/unit/ActivatorTest.php` | Activation/deactivation unit tests |
| `tests/php/unit/UninstallTest.php` | Uninstall unit tests |
| `tests/php/unit/PluginTest.php` | Singleton unit tests |
