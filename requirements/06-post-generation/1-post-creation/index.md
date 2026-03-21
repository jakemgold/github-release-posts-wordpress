---
title: "EPC-06.1: Post Creation"
---

# Epic: Post Creation

**Code:** EPC-06.1
**Domain:** Post Generation (DOM-06)
**Description:** Core post creation logic — wrapping wp_insert_post, storing source metadata, and ensuring idempotency.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Creates a WordPress post from AI-generated content. Before creating, checks whether a post already exists for the given repo + release tag combination. Stores post meta so the relationship between post and GitHub release is always traceable.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-06.1.01 | post-creation | draft | Idempotency check, wp_insert_post, source attribution meta (repo, tag, release URL, AI provider slug), WP_Error logging |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--06-post-generation--1-post-creation.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Idempotency: query existing posts by source repo + release tag meta before inserting
- `wp_insert_post` call with generated title, content, status
- Post meta: source repo (owner/repo), release tag, release URL, AI provider slug
- Error logging when post creation fails
- Return inserted post ID to downstream (taxonomy, publish workflow, notifications)

**Out of Scope:**
- Taxonomy assignment (EPC-06.2)
- Publish/draft logic (EPC-06.3)

## Success Criteria

- [ ] Running the same release twice never creates duplicate posts
- [ ] Post meta correctly stores all source attribution fields
- [ ] Creation failures logged with enough context to debug

---

_Managed by Spark_
