---
title: "PRD-06.2.01: Taxonomy Assignment"
code: PRD-06.2.01
epic: EPC-06.2
domain: DOM-06
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-06.2.01: Taxonomy Assignment

**Epic:** Taxonomy Assignment (EPC-06.2) — Post Generation (DOM-06)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

Generated posts need to be organized within the site's existing taxonomy structure so they appear in the right category archives, carry the right tags, and integrate naturally with the rest of the site's content. Without this step, every generated post lands uncategorized and untagged, making it hard for site visitors to find and for site owners to manage.

---

## Target Users

- **The plugin pipeline** — calls this layer with a post ID after post creation; expects taxonomy terms to be applied or a clear failure signal
- **Site owners** — want generated posts to appear in the categories and with the tags they configured, without manual post-by-post editing
- **Developers** — want a filter hook to override terms on a per-post basis for custom workflows

---

## Overview

Reads the category and tag defaults from plugin settings — first checking for per-repo overrides, falling back to global defaults — and applies them to the given post ID using standard WordPress term assignment functions. Handles missing or deleted terms gracefully by logging a warning and continuing rather than failing. Exposes a filter hook for developers to modify the terms applied to any individual post.

---

## User Stories & Acceptance Criteria

### US-001: Apply configured categories and tags to a generated post

As the plugin pipeline, I want taxonomy terms applied to a post immediately after it is created, so that the post is correctly organized from the moment it exists.

**Acceptance Criteria:**

- [ ] **AC-001:** After a post is created, the configured default category is applied to the post using WordPress category assignment.
- [ ] **AC-002:** After a post is created, all configured default tags are applied to the post using WordPress tag assignment.
- [ ] **AC-003:** Term assignment uses the post ID returned by the post creation layer (PRD-06.1.01). It does not re-query for the post.
- [ ] **AC-004:** If no default category is configured (globally or per-repo), the post is left in WordPress's default "Uncategorized" category. No error is raised.
- [ ] **AC-005:** If no default tags are configured, no tags are applied. No error is raised.

---

### US-002: Per-repo defaults take precedence over global defaults

As a site owner, I want posts for each tracked repository to use that repo's configured category and tags when set, so that posts from different plugins can be organized differently.

**Acceptance Criteria:**

- [ ] **AC-006:** Before applying terms, the plugin reads per-repo category and tag settings (PRD-03.2.01). If a per-repo value is set, it is used. If not, the global default (PRD-03.2.02) is used as fallback.
- [ ] **AC-007:** Per-repo and global defaults operate independently for category and tags — a repo can override tags while inheriting the global category, or vice versa.

---

### US-003: Handle missing or deleted terms gracefully

As a site owner, I want the plugin to continue creating posts even if a configured category or tag has been deleted from WordPress, rather than failing silently or halting the pipeline.

**Acceptance Criteria:**

- [ ] **AC-008:** Before applying a category or tag, the plugin verifies the term exists in WordPress. If a term no longer exists, a warning is logged via `WP_DEBUG_LOG` identifying the missing term and the repo it was configured for.
- [ ] **AC-009:** A missing term does not prevent the post from being created or other valid terms from being applied — the pipeline continues with the available terms.
- [ ] **AC-010:** Missing terms do not trigger an admin-visible error notice — this is a configuration issue the site owner resolves by updating their settings.

---

### US-004: Allow per-post term overrides via filter

As a developer, I want to programmatically modify which terms are applied to any generated post, so I can implement custom categorization logic without modifying the plugin.

**Acceptance Criteria:**

- [ ] **AC-011:** A filter hook is applied to the resolved terms array before they are assigned to the post. The hook receives the terms array and the post ID, and returns the (potentially modified) terms array.
- [ ] **AC-012:** A developer can use the filter to add terms, remove terms, or replace the entire terms array for a specific post.

---

## Business Rules

- **BR-001:** Taxonomy assignment always runs after post creation (EPC-06.1) and before the publish workflow (EPC-06.3). A post always has its terms set before its final status is applied.
- **BR-002:** This layer only assigns terms — it does not create new categories or tags. If a configured term does not exist in WordPress, it is skipped (with a warning log), not created.
- **BR-003:** Term assignment is applied to the post regardless of whether the post will be drafted or published — the publish workflow step that follows determines visibility, not taxonomy assignment.

---

## Out of Scope

- Creating or managing WordPress categories and tags (site owner's responsibility via wp-admin)
- Custom taxonomy support beyond standard categories and tags (v1 is categories + tags only)
- Taxonomy assignment for onboarding preview drafts — these follow the same pipeline, so the same assignment applies

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-06.1.01 Post Creation | Provides the post ID to assign terms to |
| PRD-03.2.01 Repository Settings | Per-repo category and tag defaults |
| PRD-03.2.02 Global Settings | Global category and tag defaults (fallback) |

| Depended On By | For |
|----------------|-----|
| EPC-06.3 Publish Workflow | Taxonomy is assigned before final post status is set |

---

## Open Questions

None.

---

_Managed by Spark_
