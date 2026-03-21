---
title: "PRD-03.2.02: Global Settings"
code: PRD-03.2.02
epic: EPC-03.2
domain: DOM-03
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-03.2.02: Global Settings

**Epic:** Plugin Configuration (EPC-03.2) — Settings (DOM-03)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

Beyond per-repository configuration, site owners need a single place to configure site-wide defaults that apply across all tracked repositories: which AI service powers post generation, global post category/tag/status defaults, where notifications get sent, and how often the plugin checks for new releases. These global settings reduce repetitive configuration while still allowing per-repo overrides.

---

## Target Users

- **Site owners** — set up the plugin once with sensible global defaults; configure their AI provider; set notification preferences
- **Developers** — access encrypted API key storage and settings filter hooks for programmatic configuration

---

## Overview

Provides the global settings section of the plugin settings page. Covers four areas: AI provider selection and API key management; global post defaults (category, tags, publish status) used as fallback for any repo that hasn't set its own; notification preferences (email address(es) and trigger events); and check frequency (cron interval). All sensitive data (API keys) are encrypted at rest.

---

## User Stories & Acceptance Criteria

### US-001: Configure the active AI provider

As a site owner, I want to select which AI service the plugin uses to generate blog posts and provide my API key, so that post generation works with my preferred provider.

**Acceptance Criteria:**

- [ ] **AC-001:** The settings page includes an AI provider selector. Available options match the providers defined in PRD-05.1.02 (OpenAI, Anthropic, Gemini, ClassifAI, WordPress AI API stub).
- [ ] **AC-002:** Only one AI provider is active at a time. Selecting a new provider and saving replaces the previously active provider.
- [ ] **AC-003:** For providers that require an API key (OpenAI, Anthropic, Gemini), a corresponding API key input field is shown when that provider is selected.
- [ ] **AC-004:** API keys are never exposed in HTML page source. The key field renders as a password input and, when a key is already saved, displays a masked placeholder (e.g., `••••••••`) rather than the actual key value.
- [ ] **AC-005:** API keys are stored encrypted at rest using libsodium as specified in the tech spec. Decryption happens only at the point of use (when making API calls).
- [ ] **AC-006:** For providers that delegate to a plugin (ClassifAI, WordPress AI API), no API key field is shown — a notice explains that credentials are managed within the respective plugin.
- [ ] **AC-007:** A "Test connection" action is available after saving provider settings. It makes a minimal API call to confirm credentials are valid and shows a success or failure notice inline.

---

### US-002: Set global post defaults

As a site owner, I want to configure default post settings that apply to all newly generated posts, so I don't have to configure the same values for every repository.

**Acceptance Criteria:**

- [ ] **AC-008:** Global post defaults include: default category (single WordPress category), default tags (multi-select WordPress tags), and default post status (draft / publish).
- [ ] **AC-009:** The default post status field defaults to "draft" on initial plugin activation.
- [ ] **AC-010:** When a per-repo override is set (PRD-03.2.01), it takes precedence over the global default. When no per-repo override is set, the global default is used.
- [ ] **AC-011:** If no global default category is selected, generated posts are created without a category (WordPress's "Uncategorized" default behavior applies).
- [ ] **AC-012:** Global defaults apply to all automatically generated posts. "Generate draft now" also uses per-repo defaults (with global fallback), but always forces draft status regardless.

---

### US-003: Configure notification preferences

As a site owner, I want to control which email addresses receive notifications and when, so that the right people are informed at the right time.

**Acceptance Criteria:**

- [ ] **AC-013:** A primary notification email field is shown, pre-populated with the WordPress admin email address. It can be changed to any valid email address.
- [ ] **AC-014:** An optional secondary notification email field is available for a second recipient.
- [ ] **AC-015:** A notification trigger selector controls when emails are sent. Options: "When draft is created", "When post is published", "Both". This maps to the notification events defined in DOM-07.
- [ ] **AC-016:** Both email fields validate format on save. An invalid email address prevents saving and shows an inline error.
- [ ] **AC-017:** If the notification trigger is set to "When post is published" but the global (or per-repo) default post status is "draft", a notice warns the site owner that no notifications will be sent automatically until posts are manually published.

---

### US-004: Configure the check frequency

As a site owner, I want to control how often the plugin polls GitHub for new releases, balancing freshness against API rate limits.

**Acceptance Criteria:**

- [ ] **AC-018:** A check frequency selector offers four options: hourly, twice daily, daily, weekly. The default on first activation is daily.
- [ ] **AC-019:** Saving a new frequency immediately reschedules the cron event as defined in PRD-04.3.01. The change takes effect without reactivating the plugin.
- [ ] **AC-020:** A subtle status notice on the settings page shows the last time the scheduled check ran and when the next check is due, as defined in PRD-04.3.01.

---

## Business Rules

- **BR-001:** Only one AI provider is active at any time. Switching providers does not delete previously stored API keys for other providers — they remain encrypted in storage and are re-used if that provider is re-selected.
- **BR-002:** Global post defaults are always defined (the settings page provides them with out-of-the-box values). There is no state where a generated post has no defined status because no default exists.
- **BR-003:** Notification email fields accept a single email address each — multiple addresses in one field (comma-separated) are not supported in v1.
- **BR-004:** The check frequency setting is the single authoritative source for the cron interval. It is read by the cron scheduling layer at registration and re-registration time.

---

## Out of Scope

- Per-repo post defaults and override behavior (PRD-03.2.01)
- Per-repo "Generate draft now" trigger (PRD-03.2.01)
- Email notification templates and content (DOM-07)
- Cron event registration mechanics (PRD-04.3.01)
- Multiple simultaneous AI providers or fallback chains (v2 consideration)

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-05.1.01 AI Provider Interface | Provider list and factory used to populate selector and validate connection |
| PRD-05.1.02 AI Provider Implementations | Provider-specific credential fields and "test connection" behavior |
| PRD-04.3.01 Cron Scheduling | Frequency setting read at cron registration/re-registration; status notice data sourced from cron layer |

| Depended On By | For |
|----------------|-----|
| PRD-03.2.01 Repository Settings | Global defaults used as fallback for unset per-repo values |
| DOM-06 Post Generation | Active AI provider and global post defaults read at generation time |
| DOM-07 Notifications | Notification email addresses and trigger preference read at notification time |

---

## Open Questions

None.

---

_Managed by Spark_
