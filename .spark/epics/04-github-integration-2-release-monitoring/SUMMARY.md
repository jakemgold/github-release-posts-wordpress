---
epic: 04-github-integration/2-release-monitoring
completed: 2026-03-21
---

# Epic Summary: 04-github-integration/2-release-monitoring

**Completed:** 2026-03-21

## Tasks Completed

| # | Task | Status |
|---|------|--------|
| 1 | Constants (OPTION_REPO_STATE_PREFIX, OPTION_RELEASE_QUEUE) | ✓ |
| 2 | Release_State + Release_Queue service classes | ✓ |
| 3 | Version_Comparator (semver + date fallback) | ✓ |
| 4 | Release_Monitor — cron run loop + find_post() | ✓ |
| 5 | Onboarding_Handler + Admin_Page wiring | ✓ |
| 6 | Conflict dialog (tab-repositories.php + JS) | ✓ |
| 7 | Hook Release_Monitor into Plugin.php | ✓ |
| 8 | Unit tests (VersionComparator, Release_State, Release_Monitor) | ✓ |

## Requirements Delivered

| REQ-ID | Requirement | Verified |
|--------|-------------|----------|
| US-001 | Per-repo state tracking (last_seen_tag, last_checked_at) | ✓ |
| US-002 | Version comparison (semver + ISO date fallback) | ✓ |
| US-003 | Onboarding preview draft on repo add | ✓ |
| US-004 | Manual "Generate draft now" trigger | ✓ |
| US-005 | Conflict resolution (replace / alongside / cancel) | ✓ |
| US-006 | Pause/resume monitoring per repo | ✓ |
| US-007 | In-process release queue | ✓ |
| US-008 | Debug logging (WP_DEBUG + WP_DEBUG_LOG gated) | ✓ |

## Files Changed

**Created:**
- `includes/classes/GitHub/Release_State.php`
- `includes/classes/GitHub/Release_Queue.php`
- `includes/classes/GitHub/Version_Comparator.php`
- `includes/classes/GitHub/Release_Monitor.php`
- `includes/classes/GitHub/Onboarding_Handler.php`
- `tests/php/unit/GitHub/VersionComparatorTest.php`
- `tests/php/unit/GitHub/Release_StateTest.php`
- `tests/php/unit/GitHub/Release_MonitorTest.php`

**Modified:**
- `includes/classes/Plugin_Constants.php` — added repo state + queue constants
- `includes/classes/Admin/Admin_Page.php` — full generate/conflict AJAX, onboarding wiring, set_admin_notice()
- `includes/classes/Plugin.php` — Release_Monitor hooked to both cron actions
- `includes/templates/admin-page.php` — displays ctbp_admin_notice transient
- `includes/templates/tab-repositories.php` — conflict resolution <dialog>
- `assets/js/admin/index.js` — full conflict dialog + generate-draft flow

## Integration Points

- `do_action('ctbp_process_release', $entry, $context)` — all generation paths (cron, manual, onboarding) fire this action. DOM-05 (AI) and DOM-06 (Post Generation) hook here.
- `Release_Monitor::find_post()` — shared static deduplication helper used by cron pipeline and manual AJAX.
- `ctbp_admin_notice_{user_id}` transient — typed notice (success/warning) displayed on next page load after onboarding.

## Next Steps

- Plan and execute `04-github-integration/3-scheduling` (cron registration and frequency management)
- Then `05-ai-integration` (DOM-05) and `06-post-generation` (DOM-06)
