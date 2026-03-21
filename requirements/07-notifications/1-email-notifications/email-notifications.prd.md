---
title: "PRD-07.1.01: Email Notifications"
code: PRD-07.1.01
epic: EPC-07.1
domain: DOM-07
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-07.1.01: Email Notifications

**Epic:** Email Notifications (EPC-07.1) — Notifications (DOM-07)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

When the plugin creates new release posts — either automatically via cron or triggered by a manual run — site owners need to know about it without actively monitoring their site. A timely email summary tells them what was created, whether it needs review, and where to find it, so they can respond to new releases without remembering to check.

---

## Target Users

- **Site owners** — want to be notified when release posts are ready so they can review drafts or confirm publications went live; want control over when and where notifications arrive
- **The plugin pipeline** — calls this layer after the publish workflow completes; needs a reliable, non-blocking notification send

---

## Overview

After a cron run (or manual pipeline trigger) produces one or more new posts, sends a single batched summary email to the configured recipient(s) via `wp_mail`. The email lists every new post from that run, identifying the plugin name, release version, significance level, the post status (draft or published), a direct link to the WordPress post, and a link to the GitHub release. Respects the notification trigger preference set in global settings — firing on draft creation, publication, or both. Exposes a filter hook for customizing email content. Does not send if no posts were created in the run.

---

## User Stories & Acceptance Criteria

### US-001: Receive a batched summary email after a cron run produces new posts

As a site owner, I want a single email summarizing everything the plugin created in a run, so I'm not flooded with one email per post.

**Acceptance Criteria:**

- [ ] **AC-001:** A single email is sent per cron run, regardless of how many posts were created in that run. Multiple posts appear as a list within the same email.
- [ ] **AC-002:** The email is only sent if at least one post was created during the run. If the cron fires but finds no new releases, no email is sent.
- [ ] **AC-003:** The email is sent via `wp_mail` as a multipart message with both plain text and HTML parts.
- [ ] **AC-004:** The email subject line identifies the site by name and indicates new posts are ready (e.g., "New plugin update post(s) ready — [Site Name]").
- [ ] **AC-005:** Each post entry in the email body includes: the plugin display name, the release version/tag, the release significance level (patch/minor/major/security), the post status (Draft or Published), a direct link to the WordPress post (edit link if draft, view link if published), and a link to the GitHub release page.

---

### US-002: Control when notification emails are sent

As a site owner, I want to choose whether I'm notified when a draft is created, when a post is published, or both, so that notifications match my review workflow.

**Acceptance Criteria:**

- [ ] **AC-006:** The notification trigger preference configured in global settings (PRD-03.2.02) determines when emails are sent:
  - "When draft is created" — email fires after any post is created as a draft
  - "When post is published" — email fires only after posts are published (not for drafts)
  - "Both" — email fires in both cases
- [ ] **AC-007:** If a cron run produces a mix of draft and published posts (possible when per-repo defaults differ), and the trigger is set to "Both", a single email covering all posts is sent.
- [ ] **AC-008:** "Generate draft now" (PRD-03.2.01) does not trigger a notification email regardless of the trigger preference setting.

---

### US-003: Notifications reach the right recipients

As a site owner, I want notification emails to go to my configured email address(es), not just the default WordPress admin email.

**Acceptance Criteria:**

- [ ] **AC-009:** The email is sent to the primary notification email address configured in global settings (PRD-03.2.02). If no address is configured, the WordPress admin email is used as the default.
- [ ] **AC-010:** If a secondary notification email address is configured, it receives the same email (sent as a second `wp_mail` call or as an additional recipient, per standard `wp_mail` behavior).
- [ ] **AC-011:** If notifications are disabled in settings, no email is sent regardless of the trigger preference or posts created.

---

### US-004: Allow email content customization via filter

As a developer, I want to modify the notification email content programmatically, so I can adapt the format or add information for a specific site's needs.

**Acceptance Criteria:**

- [ ] **AC-012:** A filter hook is applied to the email data (subject, headers, body) before the email is sent. The hook receives the email data array and the array of post entries included in the email.
- [ ] **AC-013:** A developer can use the filter to modify the subject, alter the body, add headers (e.g., reply-to), or suppress the email entirely by returning a falsy value.

---

## Business Rules

- **BR-001:** One email per cron run, never one per post. Batching is non-negotiable to prevent notification fatigue.
- **BR-002:** Email delivery is handled entirely by `wp_mail` — SMTP configuration, deliverability, and service provider are the responsibility of the site's email setup (e.g., an SMTP plugin). The plugin does not integrate with any transactional email service directly.
- **BR-003:** A failed `wp_mail` call (returns false) is logged via `WP_DEBUG_LOG` with the recipient and run context. Notification failure does not affect the posts that were created — the pipeline does not roll back on email failure.
- **BR-004:** Onboarding preview drafts (PRD-04.2.01) do not trigger notification emails. Notifications only fire for posts produced by automated cron runs.

---

## Out of Scope

- Third-party email service integrations (SMTP plugins handle this at the WordPress level)
- Per-user notification preferences or subscriber lists
- Notification for manually published posts (site owner publishes a draft post themselves)
- HTML email template design — the HTML part is functional, not designed

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-06.3.01 Publish Workflow | Triggers notification after post status is set; provides post IDs and statuses |
| PRD-03.2.02 Global Settings | Notification email address(es), trigger preference, notifications enabled/disabled |
| PRD-05.2.01 Prompt Management | Significance level classification used in email body |

---

## Open Questions

None.

---

_Managed by Spark_
