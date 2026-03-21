---
title: "PRD-04.3.01: Cron Scheduling"
code: PRD-04.3.01
epic: EPC-04.3
domain: DOM-04
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-04.3.01: Cron Scheduling

**Epic:** Scheduling (EPC-04.3) — GitHub Integration (DOM-04)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

The plugin's release monitoring pipeline needs to run automatically at a cadence the site owner controls — without requiring server-level cron configuration. It also needs to recover cleanly from GitHub rate limit exhaustion mid-run without disrupting its normal schedule. Site owners on low-traffic WordPress sites need confidence that the cron is actually firing, since WP-Cron is request-triggered and can silently stop on quiet sites.

---

## Target Users

- **Site owners** — configure check frequency, see at a glance whether the schedule is active and when the next check will run
- **The plugin pipeline** — relies on a correctly registered and maintained cron event to fire release monitoring
- **Plugin maintainers** — need clean activation/deactivation lifecycle with no orphaned cron events

---

## Overview

Registers a recurring WP-Cron event on plugin activation using a site-owner-configurable interval. Clears the event cleanly on deactivation. Reschedules automatically when the interval setting is changed. Registers a separate one-time retry event when the GitHub API rate limit is exhausted mid-run. Displays a subtle status notice on the settings page showing the last run timestamp and next scheduled run time.

---

## User Stories & Acceptance Criteria

### US-001: Recurring cron event is registered and maintained

As the plugin, I want a WP-Cron event registered on activation and cleared on deactivation so that release monitoring runs automatically without server configuration and leaves no trace when the plugin is deactivated.

**Acceptance Criteria:**

- [ ] **AC-001:** On plugin activation, a recurring WP-Cron event is registered using the interval configured in settings (defaulting to daily if no setting exists yet).
- [ ] **AC-002:** On plugin deactivation, the recurring cron event is cleared — no orphaned events remain in the WP-Cron queue.
- [ ] **AC-003:** On plugin uninstall, all plugin-registered cron events (recurring and any pending retry events) are cleared.
- [ ] **AC-004:** If the plugin is activated on a site where a stale cron event already exists (e.g. after a crash-reinstall), the stale event is cleared and a fresh one registered.

---

### US-002: Check interval is configurable and rescheduling is automatic

As a site owner, I want to choose how often the plugin checks for new releases, and have that change take effect immediately without manual intervention.

**Acceptance Criteria:**

- [ ] **AC-005:** The available intervals are: hourly, twice daily, daily, and weekly.
- [ ] **AC-006:** When the interval setting is saved, any existing recurring event is cleared and a new one registered with the updated interval — the change takes effect without reactivating the plugin.
- [ ] **AC-007:** The next scheduled run time reflects the new interval immediately after the setting is saved.
- [ ] **AC-008:** Custom intervals can be registered by developers via WordPress's `cron_schedules` filter without plugin modification.

---

### US-003: Rate limit exhaustion triggers a one-time retry event

As a site owner, I want the plugin to automatically retry release checks after a GitHub API rate limit block without disrupting the normal recurring schedule.

**Acceptance Criteria:**

- [ ] **AC-009:** When the GitHub API client signals rate limit exhaustion (as defined in PRD-04.1.01), a separate one-time WP-Cron event is scheduled to fire one hour later.
- [ ] **AC-010:** The one-time retry event is distinct from the recurring event — it fires the pipeline for repos not yet checked in the interrupted run, then clears itself.
- [ ] **AC-011:** If a retry event is already pending (e.g. rate limit hit again before the retry fires), a duplicate retry event is not registered.
- [ ] **AC-012:** The recurring schedule continues unaffected — the retry event does not shift or cancel the next regular run.

---

### US-004: Schedule status is visible on the settings page

As a site owner, I want to see at a glance when the plugin last ran and when it will run next, so I can confirm the schedule is active — particularly on low-traffic sites where WP-Cron may not fire reliably.

**Acceptance Criteria:**

- [ ] **AC-013:** The settings page displays a subtle status notice showing: the timestamp of the last completed cron run, and the scheduled time of the next run.
- [ ] **AC-014:** If no run has ever occurred (freshly activated plugin), the notice reads "No runs yet" for last run and shows the next scheduled time.
- [ ] **AC-015:** If no next run is scheduled (e.g. cron event missing — can happen on some hosts), the notice flags this clearly with a short explanation and a suggestion to check WP-Cron health.
- [ ] **AC-016:** The status notice is informational only — small, unobtrusive, and does not compete visually with the primary settings fields.

---

## Business Rules

- **BR-001:** The plugin registers exactly one recurring cron event at any time — never duplicates.
- **BR-002:** Per-repo manual triggers (defined in PRD-04.2.01) operate independently of the cron schedule — they do not fire the cron event and are not affected by it.
- **BR-003:** The retry event fires only the remaining unprocessed repos from the interrupted run, not the full repo list, to avoid redundant API calls.
- **BR-004:** Last run timestamp is recorded at the start of each cron execution, not on completion, so a run that fails partway through still updates the "last run" display.

---

## Out of Scope

- What the cron event does when it fires (EPC-04.2, DOM-05, DOM-06)
- Per-repo "Generate draft now" manual trigger (PRD-04.2.01)
- A global "Run all repos now" admin action — per-repo triggers are sufficient
- WP-Cron reliability tooling (Action Scheduler, custom cron solutions) — out of scope for v1; site owners needing guaranteed cron should use a real server cron pointed at `wp-cron.php`

---

## Dependencies

| Depends On | For |
|------------|-----|
| DOM-03 Settings | Configured interval read at registration and re-registration time |
| PRD-04.1.01 GitHub API Client | Signals rate limit exhaustion, which triggers retry event registration |

| Depended On By | For |
|----------------|-----|
| EPC-04.2 Release Monitoring | Cron event fires the monitoring pipeline |

---

## Open Questions

None.

---

_Managed by Spark_
