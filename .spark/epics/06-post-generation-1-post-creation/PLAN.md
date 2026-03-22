---
epic: 06-post-generation/1-post-creation
created: 2026-03-21
status: ready-for-execution
---

# Epic Plan: 06-post-generation/1-post-creation

## Overview

**Epic:** 06-post-generation/1-post-creation
**Goal:** Create WordPress posts from AI-generated content with idempotency and source attribution
**PRDs:** post-creation.prd.md
**Requirements:** 3 user stories, 10 acceptance criteria, 3 business rules

## Integration Point

`AI_Processor` fires `ctbp_post_generated` with `(GeneratedPost $post, ReleaseData $data, array $context)` on successful AI generation. `Post_Creator` hooks into this action.

## Tasks

### Task 1: Post_Creator class

**PRD:** post-creation.prd.md
**Implements:** US-001, US-002, US-003
**Complexity:** Medium
**Dependencies:** None

**Steps:**

1. Create `includes/classes/Post/Post_Creator.php` in namespace `TenUp\ChangelogToBlogPost\Post`
2. Constructor: no dependencies needed (uses WP functions directly)
3. `setup()`: `add_action('ctbp_post_generated', [$this, 'handle'], 10, 3)`
4. `handle(GeneratedPost $post, ReleaseData $data, array $context): void`
   - Call `find_existing_post()` for idempotency check
   - If existing post found AND `$context['bypass_idempotency']` is not true, fire `ctbp_post_created` with existing post ID and return
   - Build `wp_insert_post` args: title from Prompt_Builder format (`{display_name} {tag} — {subtitle}`), content, status = `'draft'` (EPC-06.3 will update), date = current time
   - Call `wp_insert_post()` — on WP_Error, log and return
   - Call `store_meta()` with post ID, ReleaseData, and provider slug
   - Fire `do_action('ctbp_post_created', $post_id, $post, $data, $context)`
5. `find_existing_post(string $identifier, string $tag): ?int`
   - `WP_Query` with `meta_query` on `_ctbp_source_repo` = identifier AND `_ctbp_release_tag` = tag
   - `post_status => 'any'` (covers draft, publish, trash — AC-006)
   - `posts_per_page => 1`, `fields => 'ids'`
   - Return post ID or null
6. `store_meta(int $post_id, ReleaseData $data, string $provider_slug): void`
   - `update_post_meta` for all 4 meta keys from Plugin_Constants

**Verification:**

- [ ] AC-001: Post created with correct title and content
- [ ] AC-002: Post date is current time, not release date
- [ ] AC-003: Returns post ID on success (via action)
- [ ] AC-004: Failures logged with repo, tag, error message
- [ ] AC-005: Duplicate check queries by meta before inserting
- [ ] AC-006: Duplicate check uses post_status => 'any'
- [ ] AC-007: bypass_idempotency context flag skips check
- [ ] AC-008: All 4 meta keys stored
- [ ] AC-009: Meta stored immediately after insert
- [ ] AC-010: Uses constants from Plugin_Constants for meta keys

**Files to create/modify:**

- `includes/classes/Post/Post_Creator.php` — Core class

---

### Task 2: Wire Post_Creator into Plugin::init()

**PRD:** post-creation.prd.md
**Implements:** BR-002 (structural wiring)
**Complexity:** Low
**Dependencies:** Task 1

**Steps:**

1. Add `use` import for `Post\Post_Creator` in Plugin.php
2. Instantiate and call `setup()` in `Plugin::init()`

**Verification:**

- [ ] Post_Creator hooks registered on plugins_loaded

**Files to modify:**

- `includes/classes/Plugin.php` — Add Post_Creator wiring

---

### Task 3: Unit tests for Post_Creator

**PRD:** post-creation.prd.md
**Implements:** All ACs
**Complexity:** Medium
**Dependencies:** Task 1

**Steps:**

1. Create `tests/php/unit/Post/Post_CreatorTest.php`
2. Test cases:
   - `test_setup_registers_action` — verifies ctbp_post_generated hook
   - `test_handle_creates_post_with_correct_args` — wp_insert_post called with title, content, draft status, current date
   - `test_handle_stores_all_meta_keys` — 4 update_post_meta calls verified
   - `test_handle_skips_creation_when_existing_post_found` — idempotency
   - `test_handle_checks_all_post_statuses` — post_status => 'any' in query
   - `test_handle_bypasses_idempotency_when_context_flag_set` — bypass_idempotency
   - `test_handle_logs_error_on_wp_insert_post_failure` — WP_Error path
   - `test_handle_fires_ctbp_post_created_on_success` — action fired with correct args
   - `test_handle_does_not_fire_action_on_failure` — no action on error

**Verification:**

- [ ] All AC coverage via unit tests

**Files to create:**

- `tests/php/unit/Post/Post_CreatorTest.php` — Unit tests
