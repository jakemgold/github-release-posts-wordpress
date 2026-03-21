---
title: "PRD-03.1.01: Admin Page"
code: PRD-03.1.01
epic: EPC-03.1
domain: DOM-03
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-03.1.01: Admin Page

**Epic:** Admin UI (EPC-03.1) — Settings (DOM-03)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

The plugin needs a dedicated WordPress admin page where site owners can manage all configuration — tracked repositories, AI provider credentials, post defaults, notifications, and schedule. The page must be discoverable, secure, accessible, and provide a clear enough structure that site owners can find what they need without reading documentation.

---

## Target Users

- **Site owners / plugin developers** — the only users who should ever reach this page; they need to configure the plugin quickly and confidently
- **Plugin maintainers** — need a page structure that can accommodate additional settings sections in future versions without requiring a redesign

---

## Overview

Registers a submenu page under the WordPress **Tools** menu. The page uses a tabbed layout with two tabs: **Repositories** (the tracked repo table and per-repo configuration from PRD-03.2.01) and **Settings** (global AI, post defaults, notifications, and schedule from PRD-03.2.02). Enqueues admin-only CSS and JS. All access is gated to users with `manage_options` capability. The page shell handles nonce generation and verification for all form submissions within it.

---

## User Stories & Acceptance Criteria

### US-001: Access the plugin settings page

As a site owner with admin access, I want to find and open the plugin settings page so I can configure it.

**Acceptance Criteria:**

- [ ] **AC-001:** A "Changelog to Blog Post" submenu item appears under the WordPress **Tools** menu for users with the `manage_options` capability.
- [ ] **AC-002:** Users without `manage_options` cannot access the page — direct URL access returns a WordPress permissions error.
- [ ] **AC-003:** The page title in the browser tab and the `<h1>` heading identify the plugin by name.

---

### US-002: Navigate between the two setting areas

As a site owner, I want a clear way to switch between managing repositories and configuring global settings without losing my place.

**Acceptance Criteria:**

- [ ] **AC-004:** The page presents two tabs: **Repositories** and **Settings**. The active tab is visually distinguished.
- [ ] **AC-005:** Tab state is reflected in the URL (e.g., via a `tab` query parameter) so that linking directly to a specific tab works and the correct tab is active on page load.
- [ ] **AC-006:** Navigating between tabs does not submit any forms or lose unsaved changes in the current tab (a confirmation prompt may be shown if there are unsaved changes).

---

### US-003: Submit and receive feedback on settings changes

As a site owner, I want to save my changes and know immediately whether they were saved successfully or if there was a problem.

**Acceptance Criteria:**

- [ ] **AC-007:** Each tab contains its own save/submit action. Saving one tab does not affect unsaved changes in the other tab.
- [ ] **AC-008:** On successful save, a WordPress admin notice confirms the settings were saved.
- [ ] **AC-009:** On validation failure, inline error messages appear adjacent to the relevant fields. A summary notice at the top of the form lists the errors.
- [ ] **AC-010:** All form submissions include a nonce that is verified server-side before any data is processed. An invalid or missing nonce results in a WordPress nonce error and no data is saved.

---

### US-004: Admin assets load only on the plugin page

As a site owner, I want the plugin's admin interface to work correctly with interactive elements (dynamic repo table, AJAX actions), and I don't want the plugin's scripts or styles loading on unrelated admin pages.

**Acceptance Criteria:**

- [ ] **AC-011:** Admin CSS and JS are enqueued only on the plugin's settings page, not globally across wp-admin.
- [ ] **AC-012:** The JS layer supports the interactive behaviors required by PRD-03.2.01 and PRD-03.2.02: dynamic repo table rows (add/remove/expand), "Generate draft now" AJAX trigger, and AI provider "Test connection" AJAX trigger.
- [ ] **AC-013:** AJAX endpoints are registered with nonce verification and `manage_options` capability checks, consistent with AC-010.
- [ ] **AC-014:** The page remains fully functional with JavaScript disabled — form submission, saving, and basic field display work without JS (JS enhances but does not gate core functionality).

---

### US-005: Page meets accessibility requirements

As a site owner using assistive technology, I want the settings page to be navigable and operable so I can configure the plugin without barriers.

**Acceptance Criteria:**

- [ ] **AC-015:** The page meets WCAG 2.2 AA for admin screens — all form fields have associated labels, error messages are programmatically associated with their fields, tab navigation follows a logical order, and interactive controls are keyboard-operable.
- [ ] **AC-016:** The tabbed navigation uses appropriate ARIA roles and attributes (`role="tablist"`, `role="tab"`, `role="tabpanel"`, `aria-selected`, `aria-controls`) so screen readers can understand and navigate the tab structure.

---

## Business Rules

- **BR-001:** The page is gated to `manage_options` capability only. No role below Administrator (in a standard single-site installation) should be able to reach it.
- **BR-002:** The plugin does not add a top-level admin menu item — it uses the Tools submenu to keep wp-admin uncluttered.
- **BR-003:** The page shell owns nonce generation and verification for all forms and AJAX calls within it. Individual settings sections do not manage their own nonces.

---

## Out of Scope

- Individual settings fields and their validation logic (PRD-03.2.01, PRD-03.2.02)
- Email notification templates and sending (DOM-07)
- Any public-facing frontend output — this page is wp-admin only

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-03.2.01 Repository Settings | Content of the Repositories tab |
| PRD-03.2.02 Global Settings | Content of the Settings tab |

| Depended On By | For |
|----------------|-----|
| PRD-03.2.01 Repository Settings | Page shell, nonce, and AJAX infrastructure used by repo table interactions |
| PRD-03.2.02 Global Settings | Page shell, nonce, and tab routing used by global settings form |

---

## Open Questions

None.

---

_Managed by Spark_
