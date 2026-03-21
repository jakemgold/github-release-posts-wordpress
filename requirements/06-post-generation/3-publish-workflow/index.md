---
title: "EPC-06.3: Publish Workflow"
---

# Epic: Publish Workflow

**Code:** EPC-06.3
**Domain:** Post Generation (DOM-06)
**Description:** Draft vs. publish status control, post status transitions, and admin-facing visibility of generated posts.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Controls whether generated posts land as drafts or are published immediately, based on per-repo settings with global fallback. Displays a summarizing admin notice after each cron run with edit links to generated posts. Triggers the notification pipeline after status is set.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-06.3.01 | publish-workflow | draft | Post status (draft/publish) from settings, post date = current time, admin notice summary after cron run, filter hook for per-release status override |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--06-post-generation--3-publish-workflow.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Post status: `draft` or `publish` based on per-repo setting with global fallback
- Post date: current time (not backdated to release date)
- Admin notice after cron run: posts drafted/published with edit links, errors flagged
- Filter hook: allow overriding status per release
- Trigger notification pipeline after status is set

**Out of Scope:**
- Email notification (DOM-07 — triggered by this layer)
- Scheduled/future publishing (out of scope for v1)

## Success Criteria

- [ ] Draft setting produces `draft` posts, publish setting produces `publish` posts
- [ ] Admin notice links directly to generated posts for editing
- [ ] Filter hook allows per-release status override

---

_Managed by Spark_
