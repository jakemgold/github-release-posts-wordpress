---
title: "PRD-04.2.01: Release Monitoring"
code: PRD-04.2.01
epic: EPC-04.2
domain: DOM-04
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-04.2.01: Release Monitoring

**Epic:** Release Monitoring (EPC-04.2) — GitHub Integration (DOM-04)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

Knowing that a new GitHub release exists is only useful if the plugin can reliably tell whether it has already been processed. Without per-repo state tracking and rigorous deduplication, the same release could generate multiple blog posts across cron runs. Equally, when a site owner first adds a repo or wants to verify their configuration, they need an immediate way to see what the output looks like — without waiting for the next scheduled check and without accidentally auto-publishing.

---

## Target Users

- **The plugin pipeline** — consumes release state to decide what to process each cron run
- **Site owners** — add repos and immediately see a sample output; pause repos without losing configuration; manually trigger posts off-schedule
- **Developers / agencies** — use manual trigger + conflict resolution to test different AI providers or prompt configurations against the same release

---

## Overview

Maintains per-repo state (last seen release tag, last checked timestamp, consecutive failure count, enabled/paused status) and uses it to determine which releases are new on each cron run. Compares releases using semver where possible, falling back to publication date for non-semver tags. Queues newly discovered releases for the AI generation pipeline. On first add, immediately generates a draft post for the latest release as an onboarding preview. Provides a per-repo manual trigger with conflict resolution for cases where a post already exists for the target release.

---

## User Stories & Acceptance Criteria

### US-001: Per-repo state is tracked across cron runs

As the plugin pipeline, I want to know the last release processed for each tracked repo so I can reliably identify new releases without reprocessing old ones.

**Acceptance Criteria:**

- [ ] **AC-001:** For each tracked repo, the plugin stores the last seen release tag and the timestamp of the last successful check.
- [ ] **AC-002:** State persists correctly across cron runs — a release processed in one run is never requeued in a subsequent run.
- [ ] **AC-003:** Removing a repo from the tracked list removes its stored state cleanly.
- [ ] **AC-004:** Re-adding a previously removed repo starts with a clean state (no memory of prior releases).

---

### US-002: New releases are identified correctly

As the plugin pipeline, I want to compare the latest release against the last-seen release to determine whether a new post should be generated.

**Acceptance Criteria:**

- [ ] **AC-005:** A release is considered new if its tag is more recent than the last-seen tag for that repo.
- [ ] **AC-006:** For semver-formatted tags, "more recent" is determined by semver comparison (not string comparison).
- [ ] **AC-007:** For non-semver tags, "more recent" is determined by the release's `published_at` date from GitHub.
- [ ] **AC-008:** A repo with no last-seen tag (newly added) is handled as a special case — see US-003.
- [ ] **AC-009:** Pre-releases and draft releases are never treated as new — consistent with the `/releases/latest` endpoint behaviour established in PRD-04.1.01.

---

### US-003: First-time add generates an onboarding preview draft

As a site owner adding a new repo, I want to immediately see what a generated post looks like so I can verify the plugin is configured correctly before the first real cron run.

**Acceptance Criteria:**

- [ ] **AC-010:** When a repo is added and saved for the first time, the plugin immediately triggers post generation for the current latest release.
- [ ] **AC-011:** This generated post is always created as a draft — the global publish/draft setting does not apply to onboarding preview posts.
- [ ] **AC-012:** The latest release tag is recorded as last-seen after the onboarding draft is generated, so the next cron run does not re-generate a post for it.
- [ ] **AC-013:** An admin notice is displayed after the repo is saved: confirming the draft was created, explaining its purpose (review and publish, or discard to confirm setup), and linking directly to the draft post.
- [ ] **AC-014:** If post generation fails during onboarding (e.g. AI provider not yet configured), the repo is still saved, the failure is noted in the admin notice, and the site owner is directed to use the manual trigger once configuration is complete.

---

### US-004: Manual trigger generates a draft post on demand

As a site owner or developer, I want to trigger post generation for a repo's latest release at any time, so I can force a post off-schedule, test a configuration change, or compare output from different AI providers.

**Acceptance Criteria:**

- [ ] **AC-015:** Each tracked repo in the settings UI has a "Generate draft now" action that triggers immediate post generation for its latest release.
- [ ] **AC-016:** Posts generated via manual trigger are always created as drafts, regardless of the global publish/draft setting.
- [ ] **AC-017:** On successful generation, an admin notice displays a direct link to the new draft post.
- [ ] **AC-018:** Manual trigger generation does not send an email notification — admin notice feedback is sufficient.
- [ ] **AC-019:** Manual trigger does not update the last-seen tag — it is a preview/debug action, not a pipeline event.

---

### US-005: Conflict resolution when a post already exists for a release

As a developer testing AI provider changes, I want control over what happens when I trigger generation for a release that already has a post, so I can compare outputs or replace the existing post without losing work accidentally.

**Acceptance Criteria:**

- [ ] **AC-020:** When a manual trigger targets a release for which a post already exists (draft or published), the plugin does not immediately generate — instead it presents a conflict resolution prompt with three options:
  - **(a) Replace** — delete the existing post and generate a fresh draft
  - **(b) Add alongside** — generate a new draft without affecting the existing post (useful for comparing AI provider outputs)
  - **(c) Cancel** — take no action
- [ ] **AC-021:** The conflict prompt clearly identifies the existing post (title, status, date) and links to it so the site owner can review before deciding.
- [ ] **AC-022:** Option (a) permanently deletes the existing post — this is made clear in the UI before confirmation.
- [ ] **AC-023:** Option (b) creates an additional draft post for the same release tag — the deduplication check during normal cron runs is unaffected (both posts can coexist).

---

### US-006: Repos can be paused without losing configuration

As a site owner, I want to temporarily pause monitoring for a specific repo without removing it, so I can stop post generation while retaining all its settings and history.

**Acceptance Criteria:**

- [ ] **AC-024:** Each tracked repo has an enabled/paused toggle in the settings UI.
- [ ] **AC-025:** Paused repos are skipped entirely during cron runs — no API call is made and no state is updated.
- [ ] **AC-026:** Pausing a repo retains its last-seen tag, last checked timestamp, and all configuration.
- [ ] **AC-027:** Re-enabling a paused repo resumes normal monitoring from the last-seen tag — releases published during the pause are not retroactively processed.

---

### US-007: New releases are queued for processing

As the plugin pipeline, I want newly discovered releases to be queued so they can be processed in order without race conditions between cron events.

**Acceptance Criteria:**

- [ ] **AC-028:** When a new release is detected, it is added to a processing queue rather than processed inline.
- [ ] **AC-029:** The queue is processed in the same cron run in which releases were detected — it is not deferred to a future run unless a rate limit or failure interrupts processing.
- [ ] **AC-030:** Each queue entry stores enough information to process independently: repo identifier, release tag, release body, release URL, and publication date.
- [ ] **AC-031:** Completed queue entries are removed after successful processing; failed entries are removed after logging, not retried within the same run.

---

### US-008: Check activity is logged for debugging

As a developer or site owner, I want a record of what the plugin checked and found, so I can diagnose problems without needing server access.

**Acceptance Criteria:**

- [ ] **AC-032:** Each cron run produces a debug log entry per repo: timestamp, repo, outcome (no new release / new release found / skipped — paused / error).
- [ ] **AC-033:** When a new release is found, the log entry includes the release tag and significance classification.
- [ ] **AC-034:** Log entries are only written when `WP_DEBUG` and `WP_DEBUG_LOG` are both enabled, consistent with the logging approach in the Tech Spec.

---

## Business Rules

- **BR-001:** The last-seen tag is only updated by the normal cron pipeline — never by a manual trigger. Manual triggers are non-destructive to monitoring state.
- **BR-002:** Onboarding preview drafts are indistinguishable from regular generated drafts in the WordPress post list, but carry post meta identifying them as onboarding-generated so the conflict resolution flow can identify them correctly.
- **BR-003:** Deduplication is enforced by checking existing post meta (repo + tag) before any generation attempt — both cron and manual paths must pass this check before proceeding to the conflict resolution flow.
- **BR-004:** A paused repo's last-seen tag is not updated during the pause period. This is intentional: releases published during a pause are silently skipped when monitoring resumes, per AC-027.
- **BR-005:** Semver comparison always strips a leading `v` from tags before parsing (e.g. `v2.1.0` → `2.1.0`), consistent with the significance classifier in PRD-05.2.01.

---

## Out of Scope

- GitHub API calls (PRD-04.1.01)
- Cron scheduling and interval configuration (EPC-04.3)
- AI generation and post creation (DOM-05, DOM-06)
- Per-repo settings fields beyond enabled/paused toggle (DOM-03)
- Email notifications (DOM-07)
- Retroactive processing of releases published before a repo was added, or during a pause period

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-04.1.01 GitHub API Client | Fetches latest release data passed into monitoring logic |
| DOM-03 Settings | Per-repo configuration read; enabled/paused toggle stored |
| DOM-05 AI Integration | Queue entries passed to generation pipeline |

| Depended On By | For |
|----------------|-----|
| EPC-04.3 Scheduling | Cron event triggers the monitoring run |
| DOM-06 Post Generation | Post meta (repo + tag) used for deduplication check |

---

## Open Questions

None.

---

_Managed by Spark_
