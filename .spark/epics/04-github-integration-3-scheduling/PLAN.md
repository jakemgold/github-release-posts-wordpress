---
epic: 04-github-integration/3-scheduling
created: 2026-03-21
status: ready-for-execution
---

# Epic Plan: 04-github-integration/3-scheduling

## Overview

**Epic:** Scheduling (EPC-04.3)
**Goal:** Cron registration, developer-filter frequency, weekly schedule support, last-run tracking, schedule status notice.
**PRDs:** cron-scheduling.prd.md
**Requirements:** 4 user stories, 16 acceptance criteria, 4 business rules

## Codebase Context

- `Activator::activate()` + `register_cron_event()` — already registers CRON_HOOK_RELEASE_CHECK using `get_option(OPTION_CHECK_INTERVAL, 'daily')` — needs to switch to filter
- `Activator::deactivate()` — already clears both cron events (AC-002 ✓)
- `uninstall.php` — already clears both cron events (AC-003 ✓)
- `Global_Settings::get_check_frequency()` / `save_check_frequency()` — reads/writes OPTION_CHECK_INTERVAL; `save_check_frequency()` will be removed
- `Admin_Page::handle_settings_save()` — calls `save_check_frequency()`; call to be removed
- `tab-settings.php` — has frequency `<select>` UI; to be removed; already shows next-run time but no last-run
- `Plugin_Constants::OPTION_CHECK_INTERVAL` — in get_defaults(); to be removed from defaults
- `Release_Monitor::run()` — entry point for every cron run; no last-run recording yet
- `Plugin::setup()` — where `cron_schedules` filter should be added

## Tasks

### Task 1: Replace frequency DB option with `ctbp_check_frequency` filter

**Implements:** AC-008 (developer extension), AC-006/007 (rescheduling still works via filter)
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. In `Plugin_Constants::get_defaults()` — remove `OPTION_CHECK_INTERVAL => 'daily'` entry
2. In `Global_Settings::get_check_frequency()` — replace `get_option(OPTION_CHECK_INTERVAL, 'daily')` with `(string) apply_filters('ctbp_check_frequency', 'daily')`
3. Remove `Global_Settings::save_check_frequency()` method entirely
4. Remove `VALID_FREQUENCIES` constant (no longer used for saving; doc note is sufficient)
5. In `Activator::register_cron_event()` — replace `get_option(OPTION_CHECK_INTERVAL, 'daily')` with `(string) apply_filters('ctbp_check_frequency', 'daily')`
6. In `Admin_Page::handle_settings_save()` — remove the `save_check_frequency()` call
7. Remove the frequency `<select>` fieldset from `tab-settings.php`

**Verification:**
- [ ] AC-005: All four intervals still work (hourly, twicedaily, daily, weekly) via filter
- [ ] AC-006: Interval change via filter takes effect on next activation/reschedule
- [ ] AC-008: Developer can override via `add_filter('ctbp_check_frequency', fn() => 'hourly')`

**Files to modify:**
- `includes/classes/Plugin_Constants.php`
- `includes/classes/Settings/Global_Settings.php`
- `includes/classes/Activator.php`
- `includes/classes/Admin/Admin_Page.php`
- `includes/templates/tab-settings.php`

---

### Task 2: Register `weekly` cron schedule

**Implements:** AC-008 (weekly interval works when filtered)
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. In `Plugin::setup()`, add:
   ```php
   add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
   ```
2. Add public method `add_cron_schedules( array $schedules ): array` to `Plugin`:
   - Adds `'weekly' => ['interval' => WEEK_IN_SECONDS, 'display' => __('Once Weekly', 'changelog-to-blog-post')]`
   - Only adds if `'weekly'` key not already present (defensive)
   - Returns `$schedules`

**Verification:**
- [ ] AC-008: `wp_get_schedules()` includes `weekly` after plugin loads

**Files to modify:**
- `includes/classes/Plugin.php`

---

### Task 3: Add `OPTION_LAST_RUN_AT` + record at cron start

**Implements:** BR-004, AC-013, AC-014
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. In `Plugin_Constants` — add:
   ```php
   const OPTION_LAST_RUN_AT = 'changelog_to_blog_post_last_run_at';
   ```
2. Add to `get_defaults()`: `self::OPTION_LAST_RUN_AT => 0`
3. At the top of `Release_Monitor::run()`, before the repo loop — add:
   ```php
   update_option( Plugin_Constants::OPTION_LAST_RUN_AT, time(), false );
   ```

**Verification:**
- [ ] BR-004: `OPTION_LAST_RUN_AT` is set at the start of each run, not end
- [ ] AC-013: Last run time is available for display
- [ ] AC-014: Default value of 0 means "no runs yet"

**Files to modify:**
- `includes/classes/Plugin_Constants.php`
- `includes/classes/GitHub/Release_Monitor.php`

---

### Task 4: Schedule status notice in `tab-settings.php`

**Implements:** AC-013, AC-014, AC-015, AC-016
**Complexity:** Low
**Dependencies:** Task 3

**Steps:**

1. In the Check Frequency section of `tab-settings.php`, replace the existing next-run description with a compact status block showing:
   - **Last run:** human-readable time ago, or "No runs yet" if `OPTION_LAST_RUN_AT === 0` (AC-014)
   - **Next run:** human-readable time from now, or a note flagging the missing event with a WP-Cron health suggestion (AC-015)
2. Style as `<p class="description">` — small/unobtrusive (AC-016)

**Verification:**
- [ ] AC-013: Both last run and next run timestamps shown
- [ ] AC-014: "No runs yet" shown when `OPTION_LAST_RUN_AT` is 0
- [ ] AC-015: Missing next run flags clearly with WP-Cron health suggestion
- [ ] AC-016: Notice is `description`-class, does not compete with form fields

**Files to modify:**
- `includes/templates/tab-settings.php`

---

### Task 5: Unit tests

**Complexity:** Low
**Dependencies:** Tasks 1–4

**Steps:**

1. `tests/php/unit/ActivatorTest.php` (new or update):
   - `test_register_cron_event_uses_filter_value()` — filter returns `'hourly'`, verify `wp_schedule_event` called with `'hourly'`
   - `test_ctbp_check_frequency_filter_defaults_to_daily()` — no filter registered, verify default is `'daily'`

2. `tests/php/unit/GitHub/Release_MonitorTest.php` (update):
   - `test_run_records_last_run_at_before_processing_repos()` — verify `update_option(OPTION_LAST_RUN_AT, ...)` called before repo loop

3. `tests/php/unit/PluginTest.php` (new):
   - `test_add_cron_schedules_registers_weekly()` — verify `weekly` key added with correct interval

**Files to create/modify:**
- `tests/php/unit/ActivatorTest.php`
- `tests/php/unit/GitHub/Release_MonitorTest.php`
- `tests/php/unit/PluginTest.php`
