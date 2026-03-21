---
title: "DOM-02: Foundation"
---

# Domain: Foundation

**Code:** DOM-02
**Description:** Plugin scaffold configuration and core architecture — namespacing, autoloading, constants, and WordPress.org distribution requirements.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Establishes the structural foundation of the plugin: PHP namespace, text domain, plugin header, autoloading, and the patterns all other domains build on. Also covers WordPress.org compliance (readme.txt, assets, coding standards).

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-02.1 | scaffold-config | Plugin header, constants, autoloading, coding standards config | 0 | planned |
| EPC-02.2 | plugin-structure | Core Plugin class, hook registration patterns, uninstall handling | 0 | planned |

## Domain Boundaries

**In Scope:**
- Plugin file header and WordPress.org readme.txt
- PHP namespace `TenUp\ChangelogToBlogPost`
- PSR-4 autoloading via Composer
- PHPCS / WPCS configuration
- Plugin activation, deactivation, uninstall hooks
- Core singleton bootstrap

**Out of Scope:**
- Feature-specific classes (each feature domain owns its own classes)
- Admin settings UI (DOM-03)

## Cross-Domain Dependencies

| Depended On By | For |
|----------------|-----|
| All domains | Autoloading, constants, hook registration patterns |

---

_Managed by Spark_
