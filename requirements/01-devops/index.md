---
title: "DOM-01: DevOps"
---

# Domain: DevOps

**Code:** DOM-01
**Description:** Local development environment and deployment tooling for the plugin.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Covers local development setup using Local by Flywheel and any build/test tooling needed to work on the plugin. CI/CD is deferred but the epic is scaffolded for future use.

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-01.1 | local-setup | Local WP environment, plugin symlinking, npm/composer setup | 0 | planned |

## Domain Boundaries

**In Scope:**
- Local environment configuration
- Build tooling (npm scripts, composer scripts)
- Test runner setup (PHPUnit, Jest)

**Out of Scope:**
- Plugin settings UI (DOM-03)
- WordPress.org submission process (DOM-02)

## Cross-Domain Dependencies

| Depends On | For |
|------------|-----|
| DOM-02 Foundation | Plugin structure must exist before local setup is meaningful |

---

_Managed by Spark_
