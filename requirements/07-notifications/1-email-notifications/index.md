---
title: "EPC-07.1: Email Notifications"
---

# Epic: Email Notifications

**Code:** EPC-07.1
**Domain:** Notifications (DOM-07)
**Description:** Email composition, template, delivery via wp_mail, and batched notification logic.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

After a cron run produces one or more new posts, sends a single summary email to the configured recipient(s) via `wp_mail`. Lists each new post with plugin name, version, significance level, post status, edit/view link, and GitHub release link.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-07.1.01 | email-notifications | draft | Batched summary email via wp_mail, trigger preference (draft/publish/both), primary + secondary recipients, filter hook, no email on zero posts |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--07-notifications--1-email-notifications.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Multipart email (plain text + HTML) via `wp_mail`
- Subject: "New plugin update post(s) ready — [Site Name]"
- Body: one entry per new post — plugin name, version, significance, post status, edit link, GitHub release link
- Recipient: admin email or settings-configured addresses
- Notification trigger: on draft / on publish / both (from settings)
- Filter hook for customizing email content
- No email if no new posts created; no email for "Generate draft now" or onboarding previews

**Out of Scope:**
- Third-party email services (SMTP plugin handles that at the WP level)
- Per-user notification preferences

## Success Criteria

- [ ] Email received after cron run produces new posts
- [ ] Batched: one email per cron run, not one per post
- [ ] Post link goes directly to WordPress post (edit if draft, view if published)
- [ ] No email sent if no new posts were created

---

_Managed by Spark_
