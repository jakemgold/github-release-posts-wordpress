---
title: "DOM-06: Post Generation"
---

# Domain: Post Generation

**Code:** DOM-06
**Description:** WordPress post creation from AI-generated content, taxonomy assignment, and the publish/draft workflow.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Takes the `GeneratedPost` output from the AI domain and creates a real WordPress post — applying the configured categories and tags, setting the correct post status (draft or publish), and storing metadata linking the post back to its source release. Ensures idempotency: running the pipeline twice for the same release never creates two posts.

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-06.1 | post-creation | `wp_insert_post` wrapper, idempotency, source metadata | 0 | planned |
| EPC-06.2 | taxonomy-assignment | Apply configured categories and tags to generated posts | 0 | planned |
| EPC-06.3 | publish-workflow | Draft vs. publish logic, post status transitions, edit link in admin | 0 | planned |

## Domain Boundaries

**In Scope:**
- Creating WordPress posts via `wp_insert_post`
- Post meta: `_changelog_source_repo`, `_changelog_release_tag`, `_changelog_release_url`
- Category and tag assignment from settings defaults
- Draft / publish status based on settings
- Idempotency check: skip if post already exists for this repo+tag

**Out of Scope:**
- AI content generation (DOM-05)
- Email notifications (DOM-07)
- Plugin settings (DOM-03)

## Cross-Domain Dependencies

| Depends On | For |
|------------|-----|
| DOM-05 | `GeneratedPost` (title + content) |
| DOM-03 | Default category, tags, post status |

| Depended On By | For |
|----------------|-----|
| DOM-07 | Post ID and URL for notification email |

---

_Managed by Spark_
