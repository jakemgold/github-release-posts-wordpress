---
title: "EPC-02.2: Plugin Structure"
---

# Epic: Plugin Structure

**Code:** EPC-02.2
**Domain:** Foundation (DOM-02)
**Description:** Core Plugin singleton, hook registration architecture, and lifecycle handlers (activate/deactivate/uninstall).

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Defines the runtime architecture of the plugin — how feature classes are instantiated, how hooks are registered, and what happens at each lifecycle event. This is the structural backbone all feature domains attach to.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-02.2.01 | plugin-structure | draft | Plugin singleton, plugins_loaded bootstrap, activation (default options + cron registration), deactivation (cron cleanup), uninstall (options + meta + transient removal) |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--02-foundation--2-plugin-structure.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- `Plugin.php` singleton with `setup()` / `init()` / `i18n()` methods
- Activation: write default option values (add_option, not update_option), register cron event
- Deactivation: clear all plugin-registered cron events
- Uninstall: remove all plugin options, post meta, and transients (retain generated posts)
- Single instantiation point for all feature classes

**Out of Scope:**
- Feature-specific hook callbacks (belong to feature domains)
- No custom database tables — all storage is wp_options and post meta

## Success Criteria

- [ ] Plugin activates and deactivates without PHP errors
- [ ] Uninstall removes all plugin data cleanly (options, meta, transients — not posts)
- [ ] All feature classes instantiated from a single entry point

---

_Managed by Spark_
