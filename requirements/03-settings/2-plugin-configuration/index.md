---
title: "EPC-03.2: Plugin Configuration"
---

# Epic: Plugin Configuration

**Code:** EPC-03.2
**Domain:** Settings (DOM-03)
**Description:** All individual settings fields — tracked repos, AI provider, post defaults, notification preferences, and schedule configuration.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Implements every configurable option the plugin exposes: the list of GitHub repositories to monitor, the AI service to use (and its credentials), defaults for generated posts (categories, tags, publish/draft), notification email addresses, and the check frequency.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-03.2.01 | repository-settings | draft | Per-repo table: add/remove, display name (auto-derived + override), WP.org slug (validated), custom URL, post defaults, pause toggle, Generate draft now |
| PRD-03.2.02 | global-settings | draft | AI provider selector + encrypted API key, global post defaults (fallback), notification emails + trigger, check frequency interval |

## Refinement Session

**Status:** Complete (2 of 2 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--03-settings--2-plugin-configuration.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- Tracked repositories: add/remove GitHub repo (owner/repo format or full URL), per-repo enable/disable
- AI provider: selector, API key input (encrypted at rest using libsodium)
- Post defaults: default category, default tags (multi-select), default post status (draft / publish)
- Notification settings: primary email (defaults to admin email), optional alternate email, notification trigger
- Check frequency: interval selector (hourly, twice daily, daily, weekly)
- Per-repo "Generate draft now" trigger

**Out of Scope:**
- Per-post overrides (DOM-06)
- Email template content (DOM-07)

## Success Criteria

- [ ] All settings sanitized and validated before saving
- [ ] API keys stored encrypted, never exposed in page source
- [ ] Settings saved confirmation notice displayed
- [ ] All fields pass WPCS sanitization/escaping rules

---

_Managed by Spark_
