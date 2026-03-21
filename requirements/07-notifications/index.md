---
title: "DOM-07: Notifications"
---

# Domain: Notifications

**Code:** DOM-07
**Description:** Email notifications to site owners when posts are drafted or published, with post links and release context.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

After each post is created (draft or published), sends an email to the configured notification address(es). The email clearly identifies which plugin was updated, the version, and provides a direct link to the WordPress post. Notification frequency and triggers are configurable.

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-07.1 | email-notifications | Email composition, sending via wp_mail, template, and notification triggers | 0 | planned |

## Domain Boundaries

**In Scope:**
- `wp_mail` based email sending
- Email template: plain text + HTML (multipart)
- Notification triggers: on draft, on publish, or both
- Recipient: admin email or configured alternate email(s)
- Email content: plugin name, version, significance level, post link, GitHub release link
- Batching: single email summarizing multiple new posts from one cron run (not one email per post)

**Out of Scope:**
- Post creation (DOM-06)
- Email address configuration (DOM-03)

## Cross-Domain Dependencies

| Depends On | For |
|------------|-----|
| DOM-06 | Post ID, post URL, release metadata |
| DOM-03 | Recipient email, notification trigger preference |

---

_Managed by Spark_
