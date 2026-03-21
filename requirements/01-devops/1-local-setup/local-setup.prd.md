---
title: "PRD-01.1.01: Local Setup"
code: PRD-01.1.01
epic: EPC-01.1
domain: DOM-01
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-01.1.01: Local Setup

**Epic:** Local Setup (EPC-01.1) — DevOps (DOM-01)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

A developer joining the project needs to go from a fresh clone to a fully operational local environment — WordPress running, plugin active, dependencies installed, and all test runners working — as quickly as possible and without undocumented steps. Without clear setup documentation and a consistent local environment approach, onboarding takes longer than it should and "works on my machine" issues arise.

---

## Target Users

- **Plugin developers** — need a fast, reproducible path from clone to running local environment
- **Plugin maintainers** — need to verify their setup produces the same test results as CI (when CI is added in a future phase)

---

## Overview

Defines the local development environment setup using Local by Flywheel: a WordPress site configured to host the plugin, the plugin placed in (or symlinked to) `wp-content/plugins/`, npm and Composer dependencies installed, and PHPUnit and Jest test runners verified. The deliverable is a `README.md` with setup instructions and a confirmed working local environment.

---

## User Stories & Acceptance Criteria

### US-001: Developer can set up a local environment from a fresh clone

As a developer, I want clear, accurate setup instructions so I can have a working local environment without prior knowledge of the project's specific tooling choices.

**Acceptance Criteria:**

- [ ] **AC-001:** A `README.md` exists in the repository root with local setup instructions covering: prerequisites (Local by Flywheel, Node.js via nvm, Composer), site creation steps, plugin installation steps, and dependency installation steps.
- [ ] **AC-002:** Following the README instructions on a clean machine results in a working local environment in under 15 minutes.
- [ ] **AC-003:** The README specifies the required Node.js version (per `.nvmrc`) and how to switch to it using nvm (`nvm use`).

---

### US-002: WordPress is running locally with the plugin active

As a developer, I want a local WordPress installation with the plugin active so I can develop and manually test plugin behavior.

**Acceptance Criteria:**

- [ ] **AC-004:** A Local by Flywheel site is configured with PHP 8.0+ and WordPress 6.4+.
- [ ] **AC-005:** The plugin repository is placed in or symlinked to the `wp-content/plugins/changelog-to-blog-post/` directory of the Local site.
- [ ] **AC-006:** The plugin can be activated from the WordPress admin Plugins list without errors.
- [ ] **AC-007:** WP_DEBUG and WP_DEBUG_LOG are enabled in the local `wp-config.php` so that plugin debug output appears in `wp-content/debug.log`.

---

### US-003: PHP and JavaScript dependencies are installed and tooling works

As a developer, I want all dependencies installed and all development commands working so I can build assets, run linters, and run tests.

**Acceptance Criteria:**

- [ ] **AC-008:** Running `composer install` from the plugin root installs all PHP dependencies (WP_Mock, PHPUnit) without errors.
- [ ] **AC-009:** Running `npm install` installs all JavaScript dependencies (`@10up/scripts` and its dependencies) without errors.
- [ ] **AC-010:** `npm run build` completes without errors and produces built asset files.
- [ ] **AC-011:** `npm run lint` completes without errors against the plugin source.
- [ ] **AC-012:** `composer test` (PHPUnit) runs the test suite and exits cleanly.
- [ ] **AC-013:** `npm test` (Jest) runs and exits cleanly.

---

### US-004: Developer knows how to run a subset of tests

As a developer, I want to know the commands to run a single test class or method, so I can iterate quickly during development without running the full test suite.

**Acceptance Criteria:**

- [ ] **AC-014:** The README documents how to run a single PHPUnit test class (`./vendor/bin/phpunit --filter ClassName`) and a single test method (`./vendor/bin/phpunit --filter test_method_name`).
- [ ] **AC-015:** The README documents how to run a single Jest test file or describe block.

---

## Business Rules

- **BR-001:** Local by Flywheel is the standard local environment for this project. Developers using alternative environments (Lando, DDEV, etc.) are responsible for adapting the setup steps themselves — the documented path is Local only.
- **BR-002:** The plugin directory name in `wp-content/plugins/` must be `changelog-to-blog-post` to match the plugin slug used in option keys, text domain, and hook names.
- **BR-003:** WP_DEBUG_LOG must be enabled locally — the plugin uses `WP_DEBUG_LOG` for all debug and error output and developers need visibility into it during development.

---

## Out of Scope

- CI/CD pipeline configuration (deferred — no CI provider for v1)
- Production or staging deployment
- Windows-specific setup instructions (macOS is the primary developer platform)

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-02.1.01 Scaffold Config | Plugin must be scaffolded (plugin file, composer.json, package.json) before local setup steps are valid |

---

## Open Questions

None.

---

_Managed by Spark_
