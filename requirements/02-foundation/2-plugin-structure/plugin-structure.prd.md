---
title: "PRD-02.2.01: Plugin Structure"
code: PRD-02.2.01
epic: EPC-02.2
domain: DOM-02
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-02.2.01: Plugin Structure

**Epic:** Plugin Structure (EPC-02.2) — Foundation (DOM-02)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

The plugin needs a runtime architecture that cleanly bootstraps all feature classes, manages WordPress lifecycle events (activation, deactivation, uninstall), and provides a single predictable entry point that other developers can understand and extend. Without a consistent architectural pattern, feature classes scatter their hook registrations and the plugin becomes difficult to reason about or test.

---

## Target Users

- **Plugin developers / maintainers** — need a clear, consistent pattern for where to add new feature classes and how hooks are registered
- **WordPress** — needs activation, deactivation, and uninstall hooks to manage the plugin's lifecycle correctly
- **Site owners** — benefit from clean activation/deactivation (no orphaned data or cron events) and a complete uninstall that leaves no trace

---

## Overview

Defines the runtime architecture of the plugin: a `Plugin` singleton that bootstraps on `plugins_loaded`, instantiates feature classes, and registers WordPress hooks. Implements activation, deactivation, and uninstall handlers. Activation writes default option values. Deactivation clears scheduled cron events. Uninstall removes all plugin options and post meta. All feature classes follow a consistent instantiation pattern through the singleton.

---

## User Stories & Acceptance Criteria

### US-001: Plugin bootstraps cleanly on load

As WordPress, I want the plugin to initialize all its components in a predictable, hook-safe order so that it does not interfere with other plugins or cause errors on load.

**Acceptance Criteria:**

- [ ] **AC-001:** The main plugin file hooks the plugin bootstrap to `plugins_loaded` — no feature logic runs before WordPress's plugin loading phase is complete.
- [ ] **AC-002:** A `Plugin` singleton class manages bootstrapping. It is instantiated once; subsequent calls return the same instance.
- [ ] **AC-003:** All feature classes are instantiated from a single method in the `Plugin` class. Adding a new feature class requires adding it in exactly one place.
- [ ] **AC-004:** Internationalization (text domain loading) is hooked to `init` from the `Plugin` singleton.
- [ ] **AC-005:** The plugin activates and deactivates without PHP errors or warnings on PHP 8.0+ and WordPress 6.4+.

---

### US-002: Activation writes default settings and registers the cron event

As a site owner, I want the plugin to be ready to use immediately after activation — with sensible defaults in place and the scheduled check already registered — without any manual configuration step.

**Acceptance Criteria:**

- [ ] **AC-006:** The activation hook writes default option values for all plugin settings that have defined defaults (e.g., default post status = draft, default check interval = daily, notifications enabled = true). Existing option values are not overwritten if the plugin is reactivated.
- [ ] **AC-007:** The activation hook registers the recurring WP-Cron event as defined in PRD-04.3.01, using the configured (or default) interval.
- [ ] **AC-008:** If a stale cron event already exists at activation time (e.g., after a crash-reinstall), it is cleared and a fresh one registered.
- [ ] **AC-009:** The activation hook requires the `manage_options` capability. Activation by a user without this capability produces no side effects.

---

### US-003: Deactivation clears scheduled events

As a site owner, I want deactivating the plugin to cleanly remove any scheduled background tasks so the plugin leaves no footprint in the WP-Cron queue.

**Acceptance Criteria:**

- [ ] **AC-010:** The deactivation hook clears the recurring cron event registered by this plugin.
- [ ] **AC-011:** Any pending one-time retry cron events (e.g., rate-limit retry from PRD-04.3.01) are also cleared on deactivation.
- [ ] **AC-012:** Deactivation does not delete any plugin settings or generated posts — it only removes scheduled events.

---

### US-004: Uninstall removes all plugin data

As a site owner, I want uninstalling the plugin to remove everything it stored — settings, transients, and post meta — so the database is left clean.

**Acceptance Criteria:**

- [ ] **AC-013:** An uninstall handler (via `uninstall.php` or `register_uninstall_hook`) removes all plugin options from `wp_options`.
- [ ] **AC-014:** The uninstall handler removes all post meta keys written by the plugin (source repo, release tag, release URL, AI provider slug) from all posts.
- [ ] **AC-015:** The uninstall handler clears any remaining plugin-registered cron events.
- [ ] **AC-016:** The uninstall handler deletes any transients written by the plugin (e.g., GitHub API response cache, AI response cache).
- [ ] **AC-017:** Uninstall does not delete WordPress posts that were generated by the plugin — site owners retain their content.

---

## Business Rules

- **BR-001:** No custom database tables are created or dropped at any lifecycle stage. All plugin data lives in `wp_options` and post meta.
- **BR-002:** The `Plugin` singleton is the only place feature classes are instantiated. Feature classes do not instantiate each other.
- **BR-003:** Activation defaults are written with `add_option` (not `update_option`) so existing values are preserved on reactivation — this is critical for sites that deactivate/reactivate during troubleshooting.

---

## Out of Scope

- Feature-specific hook callbacks (belong to their respective domain epics)
- Settings UI (EPC-03.1, EPC-03.2)
- Any database schema beyond `wp_options` and post meta

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-02.1.01 Scaffold Config | Namespace, autoloading, and constants must exist before Plugin class can be defined |

| Depended On By | For |
|----------------|-----|
| All feature epics | Bootstrap entry point and lifecycle hooks used by all feature classes |

---

## Open Questions

None.

---

_Managed by Spark_
