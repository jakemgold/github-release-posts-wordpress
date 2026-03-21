---
title: "EPC-04.2: Release Monitoring"
---

# Epic: Release Monitoring

**Code:** EPC-04.2
**Domain:** GitHub Integration (DOM-04)
**Description:** New release detection logic, per-repo state tracking, and deduplication to prevent multiple posts for the same release.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

On each scheduled check, compares the latest GitHub release for each tracked repo against the last-processed release tag stored in the database. If a newer release is found, it is queued for AI generation. Tracks state per-repo to avoid reprocessing.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-04.2.01 | release-monitoring | draft | State tracking, comparison logic, queue, onboarding preview, manual trigger, conflict resolution |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--04-github-integration--2-release-monitoring.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- Per-repo state storage: last seen tag, last checked timestamp (wp_options or custom table)
- Comparison logic: semver-aware "is this release newer?"
- Draft/prerelease filtering (option: skip prereleases)
- Queue of new releases to process (transient or option-based)
- Logging/audit trail of checks and found releases

**Out of Scope:**
- API calls (EPC-04.1)
- Scheduling (EPC-04.3)
- Post generation (DOM-05, DOM-06)

## Success Criteria

- [ ] No duplicate posts generated for the same release tag
- [ ] Prereleases skipped when option is enabled
- [ ] State persists correctly across cron runs

---

_Managed by Spark_
