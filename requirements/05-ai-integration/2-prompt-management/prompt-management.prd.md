---
title: "PRD-05.2.01: Prompt Management"
code: PRD-05.2.01
epic: EPC-05.2
domain: DOM-05
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-05.2.01: Prompt Management

**Epic:** Prompt Management (EPC-05.2) — AI Integration (DOM-05)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

A raw GitHub release is a developer artifact — version tags, bullet-point changelogs, and PR references. Turning that into a blog post a site owner's audience will actually read requires carefully constructed AI prompts that know the audience, understand the significance of the release, enforce a consistent structure, and produce appropriately-scaled output. Without deliberate prompt design, AI-generated posts risk being too technical, too long, too short, or tonally inconsistent across releases.

---

## Target Users

- **Site visitors / plugin users** — read the generated posts; benefit from clear, human-friendly update summaries
- **Site owners** — want posts that feel written, not auto-generated; want developer details present but not dominant
- **Developers (secondary audience within posts)** — get a dedicated section for technical changes without those details cluttering the main narrative
- **Plugin developers / agencies** — can customise prompts via filter hooks without modifying plugin code

---

## Overview

Defines the full prompt strategy sent to AI providers: a significance classifier that categorises each release as patch, minor, major, or security; per-significance prompt guidance that shapes tone and depth; title generation rules that instruct the AI to write a compelling subtitle (appended programmatically to the plugin name and version); a content structure that serves general site owners first and developers second; image handling for release body images and draft-mode placeholders; and a download link resolution strategy that respects per-repo configuration. All prompt templates are defined in code, versioned with the plugin, and overridable via filter hooks.

---

## User Stories & Acceptance Criteria

### US-001: Release significance is classified before prompting

As the plugin pipeline, I want each release to be classified by significance before any prompt is built, so that the AI receives the right tonal guidance for the content it generates.

**Acceptance Criteria:**

- [ ] **AC-001:** A release with a semver patch increment (x.x.N) is classified as `patch`.
- [ ] **AC-002:** A release with a semver minor increment (x.N.x) is classified as `minor`.
- [ ] **AC-003:** A release with a semver major increment (N.x.x) is classified as `major`.
- [ ] **AC-004:** A release whose tag or body contains security-related keywords (e.g. "security", "vulnerability", "CVE", "XSS", "injection") is classified as `security`, regardless of its semver level.
- [ ] **AC-005:** A release whose tag cannot be parsed as semver (e.g. `2024.03.1`, `release-20260101`) is classified as `minor` by default, and a debug log entry is written noting the unparseable tag.
- [ ] **AC-006:** Leading `v` prefixes on tags (e.g. `v2.1.0`) are stripped before semver parsing.
- [ ] **AC-007:** The significance classification is passed to the AI as explicit guidance — it is not inferred by the AI from the raw tag.
- [ ] **AC-008:** A filter hook allows developers to override the significance classification for a given release, receiving the auto-classified value, release tag, and release body as arguments.

---

### US-002: AI generates a compelling, significance-aware post title

As a site owner, I want each generated post to have a title that clearly identifies the plugin and version, and gives readers a meaningful sense of what the update contains — not a generic label.

**Acceptance Criteria:**

- [ ] **AC-008:** The generated post title always takes the format `[Plugin Name] [Version] — [AI-generated subtitle]`, where the plugin name and version are prepended programmatically and the subtitle is AI-generated.
- [ ] **AC-009:** For `patch` releases, the AI is instructed to write a brief, functional subtitle (e.g. "Bug fixes and stability improvements").
- [ ] **AC-010:** For `minor` releases, the AI is instructed to highlight one or two notable improvements in plain language.
- [ ] **AC-011:** For `major` releases, the AI is instructed to lead with the headline new feature or change in a compelling but plain-language way.
- [ ] **AC-012:** For `security` releases, the AI is instructed to always begin the subtitle with "Security update" regardless of other content.
- [ ] **AC-013:** The plugin name used in the title comes from the per-repo configuration, not the GitHub repo name (which may differ).

---

### US-003: Generated post content serves a mixed audience

As a site owner, I want the generated post to be readable by my general audience, while still including useful technical detail for developer readers — clearly separated so neither audience is confused.

**Acceptance Criteria:**

- [ ] **AC-014:** The post opens with a plain-language introduction accessible to non-technical site owners.
- [ ] **AC-015:** The main body summarises what's new in plain language, without requiring knowledge of code, APIs, or developer concepts.
- [ ] **AC-016:** If the release contains developer-relevant changes (API changes, hooks, deprecations, database changes), they appear in a clearly labelled section (e.g. "For developers…") after the main content.
- [ ] **AC-017:** The developer section is omitted entirely from the prompt output if no developer-relevant content is detected in the release body.
- [ ] **AC-018:** Post length scales with release substance: a security patch or single-fix release may be a single paragraph; a feature-rich release may use up to approximately 7 paragraphs. The AI is instructed not to pad content to reach a minimum length.

---

### US-004: Post content includes a download / changelog link

As a site owner's reader, I want the post to include a clear link to download the update or read the full changelog, so I can take action after reading.

**Acceptance Criteria:**

- [ ] **AC-019:** Every generated post includes a call-to-action link resolved in the following priority order: (1) custom URL configured per repo in settings, (2) WordPress.org plugin page if a WP.org slug is configured for the repo, (3) the GitHub release URL as the fallback.
- [ ] **AC-020:** The link is included naturally within the post content (not appended as raw metadata), phrased contextually (e.g. "Download the update from WordPress.org" or "See the full release notes on GitHub").
- [ ] **AC-021:** When a WordPress.org plugin slug is saved in per-repo settings, the plugin validates it against the WordPress.org API at save time and displays an inline error if the slug does not resolve to a live plugin page.

---

### US-005: Images from the release body are included

As a site owner, I want relevant images from the release notes to appear in the generated post, so updates with screenshots or diagrams include them automatically.

**Acceptance Criteria:**

- [ ] **AC-021:** If the GitHub release body contains embedded images (markdown image syntax), those image URLs are extracted and included in the generated post content.
- [ ] **AC-022:** Images are placed contextually within the post rather than appended at the end.
- [ ] **AC-023:** If no images are present in the release body, no image placeholders appear in published posts.

---

### US-006: Draft posts include image placeholders

As a site owner using draft mode, I want the generated draft to suggest where images could be added, so I know where to insert screenshots before publishing.

**Acceptance Criteria:**

- [ ] **AC-024:** When the post status is set to `draft`, the generated content includes placeholder blocks at contextually appropriate positions, indicating where a screenshot or image could be inserted.
- [ ] **AC-025:** Placeholders are clearly marked as suggestions (e.g. `[Image suggestion: screenshot of new feature X]`) and do not appear in published posts.
- [ ] **AC-026:** Placeholders are only inserted when no real images are available from the release body — if real images exist, they are used instead.

---

### US-007: Prompts are customisable via filter hooks

As a developer or agency, I want to override the prompt templates without modifying plugin files, so I can customise generated content for a specific site's voice or audience.

**Acceptance Criteria:**

- [ ] **AC-027:** A filter hook allows overriding the full prompt string sent to the AI provider, receiving the default prompt, release data, and significance classification as arguments.
- [ ] **AC-028:** Separate filter hooks allow overriding the title guidance prompt and the content guidance prompt independently.
- [ ] **AC-029:** All filter hooks are documented with `@param` and `@return` docblocks and covered by unit tests that assert the filter fires with the correct arguments.

---

### US-008: Prompt templates are versioned in code

As a plugin maintainer, I want prompt templates stored in code rather than the database, so changes are tracked in version control and deployed consistently.

**Acceptance Criteria:**

- [ ] **AC-030:** All prompt template strings are defined in a single, clearly-labelled location in code — not stored in the database or as user-editable options.
- [ ] **AC-031:** Updating a prompt template for a new plugin release requires changing only the relevant template definition — no database migrations or option updates.
- [ ] **AC-032:** Prompt templates include a version comment so it is easy to identify which plugin version introduced a given prompt change via `git blame`.

---

## Business Rules

- **BR-001:** Security significance always overrides semver-derived significance — a patch-level tag with a CVE mention is always classified as `security`.
- **BR-002:** The plugin name and version in the post title are always prepended programmatically — the AI is explicitly instructed not to include them in its subtitle output, to prevent duplication.
- **BR-003:** The AI is always instructed to write in plain English accessible to a non-technical audience as its primary register. Technical developer content is always secondary and clearly labelled.
- **BR-004:** Post length is always content-driven. The AI is instructed not to pad thin releases and not to truncate rich ones, subject to the ~7 paragraph soft ceiling.
- **BR-005:** Images sourced from the release body are included by URL reference only — the plugin does not download, re-host, or store image files.
- **BR-006:** Prompt templates are never exposed in the admin UI as editable fields. Customisation is via code (filter hooks) only, to prevent accidental breakage by non-developers.

---

## Out of Scope

- Sending the constructed prompt to an AI provider (PRD-05.1.01, PRD-05.1.02)
- Post creation in WordPress (DOM-06)
- Per-repo settings fields for custom URL, WP.org slug, and plugin display name (DOM-03)
- Fetching images from linked GitHub issues or pull requests (v2 enhancement)

---

## Dependencies

| Depends On | For |
|------------|-----|
| EPC-04.1 GitHub API Client | Release body and tag passed as classifier input |
| DOM-03 Settings | Per-repo: custom download URL, WordPress.org slug, display name |
| PRD-05.1.01 AI Provider Interface | Receives the fully constructed prompt string |

| Depended On By | For |
|----------------|-----|
| PRD-05.1.02 AI Provider Implementations | Prompt string passed into each connector |

---

## Open Questions

None — all questions resolved.

_(Resolved: significance classifier exposes a filter hook for developer overrides; WordPress.org slug is validated against the WP.org API at settings-save time before being stored.)_

---

_Managed by Spark_
