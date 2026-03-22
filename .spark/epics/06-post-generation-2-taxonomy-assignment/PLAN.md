---
epic: 06-post-generation/2-taxonomy-assignment
created: 2026-03-21
status: ready-for-execution
---

# Epic Plan: 06-post-generation/2-taxonomy-assignment

## Overview

**Epic:** 06-post-generation/2-taxonomy-assignment
**Goal:** Apply configured categories and tags to generated posts with per-repo overrides and graceful missing-term handling
**PRDs:** taxonomy-assignment.prd.md
**Requirements:** 4 user stories, 12 acceptance criteria, 3 business rules

## Integration Point

`Post_Creator` fires `ctbp_post_created` with `(int $post_id, GeneratedPost $post, ReleaseData $data, array $context)`. `Taxonomy_Assigner` hooks into this action at priority 10.

## Tasks

### Task 1: Taxonomy_Assigner class

**Implements:** US-001, US-002, US-003, US-004
**Complexity:** Medium
**Dependencies:** None

**Steps:**

1. Create `includes/classes/Post/Taxonomy_Assigner.php`
2. Constructor: `Repository_Settings $repo_settings`, `Global_Settings $global_settings`
3. `setup()`: `add_action('ctbp_post_created', [$this, 'handle'], 10, 4)`
4. `handle(int $post_id, GeneratedPost $post, ReleaseData $data, array $context): void`
   - Call `resolve_terms($data->identifier)` to get category + tags
   - Apply `ctbp_post_terms` filter (AC-011, AC-012)
   - Validate category exists via `term_exists()`, log warning if missing (AC-008)
   - Validate each tag exists, log warnings for missing ones (AC-008)
   - Apply valid category via `wp_set_post_categories()` (AC-001)
   - Apply valid tags via `wp_set_post_tags()` (AC-002)
5. `resolve_terms(string $identifier): array`
   - Look up per-repo config; use repo category if set (non-zero), else global default (AC-006)
   - Look up per-repo tags if set (non-empty), else global default tags (AC-006)
   - Per-repo and global operate independently per field (AC-007)
   - Return `['category' => int, 'tags' => array]`

**Verification:**

- AC-001–AC-012 as listed in PRD

**Files to create:**

- `includes/classes/Post/Taxonomy_Assigner.php`

---

### Task 2: Wire into Plugin::init()

**Complexity:** Low
**Dependencies:** Task 1

**Files to modify:**

- `includes/classes/Plugin.php`

---

### Task 3: Unit tests

**Complexity:** Medium
**Dependencies:** Task 1

**Files to create:**

- `tests/php/unit/Post/Taxonomy_AssignerTest.php`
