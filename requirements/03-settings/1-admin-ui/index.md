---
title: "EPC-03.1: Admin UI"
---

# Epic: Admin UI

**Code:** EPC-03.1
**Domain:** Settings (DOM-03)
**Description:** WordPress admin page structure, navigation, and admin-side assets for the plugin settings.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Creates the admin page under the WordPress Tools menu, enqueues admin CSS/JS, and provides the tabbed layout shell that houses all configuration fields.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-03.1.01 | admin-page | draft | Tools menu registration, two-tab layout (Repositories / Settings), asset enqueueing, nonce handling, AJAX infrastructure, WCAG 2.2 AA |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--03-settings--1-admin-ui.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- Admin menu registration (Tools submenu)
- Settings page template/view with tabbed layout
- Admin asset enqueueing (CSS, JS — plugin page only)
- Two-tab layout: Repositories tab, Settings tab
- Nonce and capability checks (manage_options)
- AJAX endpoint infrastructure for repo table and "Test connection"

**Out of Scope:**
- Individual settings fields (EPC-03.2)

## Success Criteria

- [ ] Settings page accessible at Tools > Changelog to Blog Post
- [ ] Page passes WCAG 2.2 AA for admin screens
- [ ] Non-admin users cannot access the page
- [ ] Admin assets do not load on unrelated wp-admin pages

---

_Managed by Spark_
