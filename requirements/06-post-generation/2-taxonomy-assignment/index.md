---
title: "EPC-06.2: Taxonomy Assignment"
---

# Epic: Taxonomy Assignment

**Code:** EPC-06.2
**Domain:** Post Generation (DOM-06)
**Description:** Apply configured default categories and tags to each generated post.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Reads category and tag defaults from plugin settings (per-repo with global fallback) and assigns them to each newly created post. Handles missing terms gracefully and exposes a filter hook for per-post overrides.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-06.2.01 | taxonomy-assignment | draft | Category + tag assignment, per-repo override with global fallback, missing term handling, filter hook |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--06-post-generation--2-taxonomy-assignment.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Read category IDs from plugin settings (per-repo → global fallback), apply to post
- Read tag slugs/IDs from plugin settings (per-repo → global fallback), apply to post
- Graceful handling if a configured category/tag no longer exists (log warning, continue)
- Filter hook: allow overriding terms per post

**Out of Scope:**
- Creating or managing categories/tags (site owner's responsibility)
- Custom taxonomy support beyond standard categories and tags (v1)

## Success Criteria

- [ ] Posts are assigned exactly the categories and tags configured in settings
- [ ] Missing category/tag logged but does not block post creation
- [ ] Filter hook tested and documented

---

_Managed by Spark_
