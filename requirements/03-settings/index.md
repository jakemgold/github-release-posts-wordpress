---
title: "DOM-03: Settings"
---

# Domain: Settings

**Code:** DOM-03
**Description:** Admin UI for configuring all plugin behavior — tracked repositories, AI service credentials, post defaults, and notification preferences.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

The settings domain owns all WordPress admin screens for the plugin. It provides site owners with a clear interface to add/remove tracked GitHub repositories, choose an AI provider, configure post defaults (category, tags, publish vs. draft), and set notification email addresses.

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-03.1 | admin-ui | Settings page layout, navigation, admin assets | 0 | planned |
| EPC-03.2 | plugin-configuration | All individual settings fields, validation, storage | 0 | planned |

## Domain Boundaries

**In Scope:**
- WordPress Settings API or custom admin page
- Tracked repositories list (add/remove GitHub repo URLs)
- AI provider selection and API key storage (encrypted)
- Post defaults: category, tags, post status (draft/publish)
- Notification email address(es)
- Check frequency / schedule interval

**Out of Scope:**
- Actual GitHub API calls (DOM-04)
- AI API calls (DOM-05)
- Post creation logic (DOM-06)

## Cross-Domain Dependencies

| Depended On By | For |
|----------------|-----|
| DOM-04 | GitHub repo list, check schedule |
| DOM-05 | AI provider selection, API key |
| DOM-06 | Post status default, category/tag defaults |
| DOM-07 | Notification email addresses |

---

_Managed by Spark_
