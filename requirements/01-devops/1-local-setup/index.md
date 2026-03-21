---
title: "EPC-01.1: Local Setup"
---

# Epic: Local Setup

**Code:** EPC-01.1
**Domain:** DevOps (DOM-01)
**Description:** Configure Local by Flywheel environment, install dependencies, and establish developer workflow.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Sets up a functional local WordPress installation with the plugin active, npm and Composer dependencies installed, and test runners operational.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-01.1.01 | local-setup | draft | Local by Flywheel site, plugin placement, composer install + npm install, PHPUnit + Jest verification, README setup docs |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--01-devops--1-local-setup.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Local by Flywheel site setup (PHP 8.0+, WP 6.4+)
- Plugin placement in wp-content/plugins/changelog-to-blog-post/
- `npm install` and `composer install` workflows
- PHPUnit and Jest test runner verification
- README.md with setup instructions

**Out of Scope:**
- CI/CD pipeline (deferred — no CI for v1)
- Production deployment

## Dependencies

| Depends On | Epic/Feature | For |
|------------|--------------|-----|
| PRD-02.1.01 | scaffold-config | Plugin must be scaffolded before local setup steps are valid |

## Success Criteria

- [ ] Developer can clone repo and have a running local environment in under 15 minutes
- [ ] `npm start`, `npm run build`, and `composer test` all work correctly

---

_Managed by Spark_
