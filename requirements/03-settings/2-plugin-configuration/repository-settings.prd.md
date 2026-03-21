---
title: "PRD-03.2.01: Repository Settings"
code: PRD-03.2.01
epic: EPC-03.2
domain: DOM-03
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-03.2.01: Repository Settings

**Epic:** Plugin Configuration (EPC-03.2) — Settings (DOM-03)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

Site owners need a place to manage which GitHub repositories the plugin monitors — adding new repos, removing ones they no longer want tracked, and configuring per-repo behavior such as how generated posts are categorized and what status they're given. Configuration must be simple enough for non-developers while offering enough control for power users.

---

## Target Users

- **Site owners / plugin developers** — add and manage the repos they want blog posts generated for; configure per-repo post settings to match their site's taxonomy
- **Plugin maintainers** — need to pause monitoring temporarily without removing a repo entirely

---

## Overview

Provides a repository management table within the plugin settings page where site owners can add GitHub repositories to track, remove repos they no longer want, and configure per-repo options: display name, WordPress.org plugin slug, a custom download URL, post defaults (category, tags, publish status), and a pause toggle. The plugin automatically derives a display name from the GitHub repo name but allows override. Per-repo post defaults fall back to the global defaults defined in PRD-03.2.02.

---

## User Stories & Acceptance Criteria

### US-001: Add a repository to track

As a site owner, I want to add a GitHub repository so the plugin begins monitoring it for new releases.

**Acceptance Criteria:**

- [ ] **AC-001:** The settings page includes an "Add repository" control that accepts a GitHub repository in `owner/repo` format or as a full GitHub URL (e.g., `https://github.com/owner/repo`). Both formats are normalized to `owner/repo` for storage.
- [ ] **AC-002:** Attempting to save a repo that is not in a valid format shows a validation error without saving.
- [ ] **AC-003:** Attempting to add a duplicate repository (already in the list) shows a validation error and does not create a duplicate entry.
- [ ] **AC-004:** After a new repo is successfully added, the onboarding preview draft behavior defined in PRD-04.2.01 is triggered.
- [ ] **AC-005:** The settings page enforces a default maximum of 25 tracked repositories in the UI, with an error message if the limit is reached. The limit can be raised by developers via a filter hook.

---

### US-002: View and manage tracked repositories

As a site owner, I want to see all my tracked repositories at a glance so I can understand what the plugin is monitoring and make changes.

**Acceptance Criteria:**

- [ ] **AC-006:** Tracked repositories are displayed in a table with columns for: display name, repo identifier (`owner/repo`), pause status, and a remove action.
- [ ] **AC-007:** Each row in the table has an expand/edit control that reveals the per-repo configuration fields (US-003).
- [ ] **AC-008:** A "Remove" action removes the repository from tracking. The site owner is shown a confirmation before removal.
- [ ] **AC-009:** Removing a repository does not delete any previously generated posts.

---

### US-003: Configure per-repo settings

As a site owner, I want to configure display name, WordPress.org slug, post defaults, and a pause toggle for each tracked repository individually.

**Acceptance Criteria:**

- [ ] **AC-010:** Each repo has a display name field. The plugin auto-derives a default display name from the GitHub repo name by converting hyphens/underscores to spaces and applying title case (e.g., `my-awesome-plugin` → `My Awesome Plugin`). The field is pre-populated with the derived name and can be overridden.
- [ ] **AC-011:** Each repo has an optional WordPress.org plugin slug field. When provided, the plugin uses this slug to construct the WordPress.org plugin page URL as the primary download link in generated posts. The slug is validated against the WordPress.org API when saved; if the slug returns no result, a warning is shown (but saving is not blocked).
- [ ] **AC-012:** Each repo has an optional custom download URL field. When provided, this URL takes priority over the WordPress.org plugin page URL for the download link in generated posts.
- [ ] **AC-013:** Each repo has per-repo post default fields: default category (single, with global fallback), default tags (multi-select, with global fallback), and default post status (draft / publish, with global fallback). When a per-repo value is not set, the global default defined in PRD-03.2.02 is used.
- [ ] **AC-014:** Each repo has a pause toggle. When paused, the plugin skips release checks for that repo during cron runs without removing it from the list. The paused state is visually indicated in the repository table.

---

### US-004: Trigger an on-demand post generation for a specific repo

As a site owner, I want to manually trigger post generation for a specific repo to test or catch up on a release without waiting for the next scheduled run.

**Acceptance Criteria:**

- [ ] **AC-015:** Each repo row includes a "Generate draft now" button that triggers an immediate on-demand draft generation for the latest release of that repo.
- [ ] **AC-016:** "Generate draft now" always generates a draft regardless of the repo's default post status setting.
- [ ] **AC-017:** "Generate draft now" does not update the last-seen release tag for the repo — it does not affect the automatic deduplication state.
- [ ] **AC-018:** If a post for the same release already exists when "Generate draft now" is triggered, the site owner is presented with the conflict resolution options defined in PRD-04.2.01 (replace, add alongside, cancel).
- [ ] **AC-019:** "Generate draft now" does not trigger notification emails.

---

## Business Rules

- **BR-001:** Per-repo display name is purely cosmetic — it affects how the repo is labeled in the admin UI and in notification emails, but the underlying `owner/repo` identifier remains unchanged.
- **BR-002:** Per-repo post defaults override global defaults when set. An unset per-repo default inherits from the global default, never from another repo.
- **BR-003:** Pausing a repo does not affect any pending retry events already scheduled for that repo (e.g., a rate-limit retry event remains scheduled).
- **BR-004:** The WP.org slug validation check is a best-effort warning only — an invalid slug does not prevent saving, allowing for not-yet-published plugins or future slugs.
- **BR-005:** The custom download URL, if set, is stored as-is and used verbatim in generated posts. Basic URL format validation is applied at save time.

---

## Out of Scope

- Per-post overrides at generation time (DOM-06)
- Email notification template and triggers (DOM-07)
- Scheduling configuration and cron interval (covered in PRD-03.2.02 and PRD-04.3.01)
- AI provider selection (covered in PRD-03.2.02)

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-03.2.02 Global Settings | Global post defaults used as fallback when per-repo values are not set |
| PRD-04.2.01 Release Monitoring | "Generate draft now" triggers the monitoring pipeline; onboarding preview draft on first repo add |
| PRD-04.3.01 Cron Scheduling | Pause toggle affects whether cron runs process this repo |

| Depended On By | For |
|----------------|-----|
| PRD-04.1.01 GitHub API Client | Repository list drives which repos are polled |
| PRD-04.2.01 Release Monitoring | Per-repo state (last-seen tag, pause toggle, WP.org slug, display name) read at monitoring time |
| DOM-06 Post Generation | Per-repo post defaults (category, tags, status, custom URL) read at post creation time |

---

## Open Questions

None.

---

_Managed by Spark_
