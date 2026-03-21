---
epic: 04-github-integration/3-scheduling
completed: 2026-03-21
---

# Epic Summary: 04-github-integration/3-scheduling

**Completed:** 2026-03-21

## Tasks Completed

| # | Task | Status |
|---|------|--------|
| 1 | Replace frequency DB option with `ctbp_check_frequency` filter | ✓ |
| 2 | Register `weekly` cron schedule via `cron_schedules` filter | ✓ |
| 3 | Add `OPTION_LAST_RUN_AT` + record at start of `Release_Monitor::run()` | ✓ |
| 4 | Schedule status notice in `tab-settings.php` (last run + next run) | ✓ |
| 5 | Unit tests (Activator, Release_Monitor, Plugin) | ✓ |

## Key Design Decision

Check frequency is hardcoded to `daily` with a `ctbp_check_frequency` filter for developer override. The settings UI no longer exposes a frequency selector — `OPTION_CHECK_INTERVAL` is deprecated (retained for backward compat but no longer written or read).

## Requirements Delivered

| REQ-ID | Requirement | Notes |
|--------|-------------|-------|
| AC-001 | Recurring event registered on activation | Already done in Activator ✓ |
| AC-002 | Event cleared on deactivation | Already done in Activator ✓ |
| AC-003 | Events cleared on uninstall | Already done in uninstall.php ✓ |
| AC-004 | Stale event cleared on crash-reinstall | Already done in Activator ✓ |
| AC-005 | All four intervals available | Via filter (hourly/twicedaily/daily/weekly) ✓ |
| AC-006 | Interval change reschedules | Via filter + reactivation ✓ |
| AC-007 | Next run reflects new interval | Via `wp_next_scheduled()` display ✓ |
| AC-008 | Developer extension via filter | `ctbp_check_frequency` filter ✓ |
| AC-009 | Rate limit retry scheduled | Already done in API_Client ✓ |
| AC-010 | Retry event distinct from recurring | Already done ✓ |
| AC-011 | No duplicate retry events | `wp_next_scheduled()` check in API_Client ✓ |
| AC-012 | Recurring schedule unaffected by retry | Already done ✓ |
| AC-013 | Last run + next run displayed | `tab-settings.php` status block ✓ |
| AC-014 | "No runs yet" when no run occurred | `OPTION_LAST_RUN_AT === 0` check ✓ |
| AC-015 | Missing cron event flagged with suggestion | Status block with WP-Cron health note ✓ |
| AC-016 | Notice is small/unobtrusive | `<p class="description">` ✓ |

## Files Changed

**Modified:**
- `includes/classes/Plugin_Constants.php` — added `OPTION_LAST_RUN_AT`; deprecated `OPTION_CHECK_INTERVAL`
- `includes/classes/Plugin.php` — `cron_schedules` filter + `add_cron_schedules()` method
- `includes/classes/Activator.php` — uses `ctbp_check_frequency` filter instead of DB option
- `includes/classes/Settings/Global_Settings.php` — `get_check_frequency()` returns filter value; removed `save_check_frequency()` and `VALID_FREQUENCIES`
- `includes/classes/Admin/Admin_Page.php` — removed `save_check_frequency()` call
- `includes/classes/GitHub/Release_Monitor.php` — records `OPTION_LAST_RUN_AT` at start of `run()`
- `includes/templates/tab-settings.php` — replaced frequency selector with status notice
- `tests/php/unit/ActivatorTest.php` — updated for filter; added filter value test
- `tests/php/unit/PluginTest.php` — added `add_cron_schedules` tests
- `tests/php/unit/GitHub/Release_MonitorTest.php` — added last_run_at test; stubbed `update_option` in existing tests

## Next Steps

- Plan and execute `05-ai-integration` (DOM-05) — AI provider abstraction + content generation
