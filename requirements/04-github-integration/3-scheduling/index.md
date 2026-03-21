---
title: "EPC-04.3: Scheduling"
---

# Epic: Scheduling

**Code:** EPC-04.3
**Domain:** GitHub Integration (DOM-04)
**Description:** WP-Cron job registration, custom interval schedules, and the manual "Run Now" trigger.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Registers a WP-Cron event that fires the release monitoring process on a site-owner-configurable interval (hourly, twice daily, daily, weekly). Also exposes a manual trigger from the settings page for immediate on-demand checks.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-04.3.01 | cron-scheduling | draft | WP-Cron registration, intervals, rescheduling, rate limit retry, schedule status notice |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--04-github-integration--3-scheduling.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- Custom WP-Cron schedule intervals (hourly, twice_daily, daily, weekly)
- `wp_schedule_event` registration on activation, `wp_clear_scheduled_hook` on deactivation
- Rescheduling when interval setting changes
- Manual trigger action (AJAX or form POST from settings page)
- Admin notice confirming manual run completed

**Out of Scope:**
- What happens during the cron run (EPC-04.2, DOM-05, DOM-06)

## Success Criteria

- [ ] Cron event cleared on plugin deactivation (no orphaned events)
- [ ] Interval change in settings reschedules the next run
- [ ] Manual trigger works and provides feedback in admin

---

_Managed by Spark_
