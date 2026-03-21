---
title: "PRD-06.1.01: Post Creation"
code: PRD-06.1.01
epic: EPC-06.1
domain: DOM-06
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-06.1.01: Post Creation

**Epic:** Post Creation (EPC-06.1) — Post Generation (DOM-06)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

When a new GitHub release is detected, the plugin needs to create a WordPress post from the AI-generated content and permanently record the relationship between that post and its source release. Without reliable idempotency and source attribution, the same release could generate duplicate posts, and there would be no way to trace which post came from which GitHub release.

---

## Target Users

- **The plugin pipeline** — calls this layer after AI content generation completes; expects a post ID back or a clear failure signal
- **Site owners** — benefit from knowing posts are never accidentally duplicated and that each post is traceable back to its source release
- **Plugin maintainers** — need post meta to support conflict resolution (PRD-04.2.01) and future admin tooling

---

## Overview

Provides the core post creation function that wraps `wp_insert_post`. Before inserting, checks whether a post already exists for the given repository and release tag combination using stored post meta. If a post already exists, returns the existing post ID without creating a duplicate. On successful creation, stores source attribution meta on the post. Logs failures with enough context to debug. Returns the post ID to downstream pipeline steps (taxonomy assignment, publish workflow, notifications).

---

## User Stories & Acceptance Criteria

### US-001: Create a post from generated content

As the plugin pipeline, I want to create a WordPress post from AI-generated content so that site visitors can read about a new release.

**Acceptance Criteria:**

- [ ] **AC-001:** A WordPress post is created using the AI-generated title and content, with the post status provided by the publish workflow layer (EPC-06.3).
- [ ] **AC-002:** The post's publication date is set to the current time — it is not backdated to the GitHub release date.
- [ ] **AC-003:** On successful creation, the function returns the new post ID.
- [ ] **AC-004:** On failure (e.g., `wp_insert_post` returns a `WP_Error`), the error is logged via `WP_DEBUG_LOG` with the repo identifier, release tag, and error message. The failure is returned as a `WP_Error` to the caller.

---

### US-002: Prevent duplicate posts for the same release

As a site owner, I want the plugin to never create two posts for the same GitHub release, even if the cron runs overlap or a manual trigger fires while a scheduled run is in progress.

**Acceptance Criteria:**

- [ ] **AC-005:** Before creating a post, the plugin queries for an existing WordPress post with matching source repo and release tag meta. If one is found, the existing post ID is returned and no new post is created.
- [ ] **AC-006:** The duplicate check covers all post statuses — draft, publish, trash, and any other status — so a trashed post also prevents re-creation.
- [ ] **AC-007:** The conflict resolution flow in PRD-04.2.01 ("Generate draft now" with existing post) operates at a higher level and explicitly bypasses or overrides this idempotency check when the site owner chooses to replace or add alongside.

---

### US-003: Store source attribution on every generated post

As a plugin maintainer or site owner, I want every generated post to carry metadata linking it back to its source GitHub release so I can always tell where a post came from.

**Acceptance Criteria:**

- [ ] **AC-008:** The following post meta is stored on every successfully created post:
  - Source repository identifier (`owner/repo`)
  - Release tag (the exact tag string from the GitHub API, e.g., `v2.3.1`)
  - Release URL (the GitHub release page URL)
  - AI provider slug used to generate the content (e.g., `openai`, `anthropic`, `classifai`)
- [ ] **AC-009:** Post meta is stored immediately after `wp_insert_post` succeeds, before control returns to the caller.
- [ ] **AC-010:** The meta keys are consistent and documented so that external code (themes, other plugins) can query posts by source repo or release tag.

---

## Business Rules

- **BR-001:** The idempotency check is keyed on the combination of source repo identifier and release tag. A new tag for the same repo is a distinct release and will produce a new post.
- **BR-002:** Post creation is purely structural — it does not assign taxonomy terms (EPC-06.2) or set the final publish status (EPC-06.3). Those steps happen downstream using the returned post ID.
- **BR-003:** This layer does not generate content — it receives fully prepared title and content strings from the AI integration layer (DOM-05) and passes them to `wp_insert_post`.

---

## Out of Scope

- Taxonomy assignment (EPC-06.2)
- Publish/draft status determination (EPC-06.3)
- AI content generation (DOM-05)
- Conflict resolution UI for manual triggers (PRD-04.2.01)
- Scheduled/future publishing

---

## Dependencies

| Depends On | For |
|------------|-----|
| DOM-05 AI Integration | Provides the generated title and content strings |
| PRD-04.2.01 Release Monitoring | Provides the repo identifier, release tag, and release URL; handles conflict resolution before calling this layer |

| Depended On By | For |
|----------------|-----|
| EPC-06.2 Taxonomy Assignment | Receives the post ID returned by this layer |
| EPC-06.3 Publish Workflow | Receives the post ID returned by this layer |
| DOM-07 Notifications | Receives the post ID returned by this layer |

---

## Open Questions

None.

---

_Managed by Spark_
