---
title: "PRD-02.1.01: Scaffold Config"
code: PRD-02.1.01
epic: EPC-02.1
domain: DOM-02
status: approved
created: 2026-03-21
updated: 2026-03-21
---

# PRD-02.1.01: Scaffold Config

**Epic:** Scaffold Config (EPC-02.1) — Foundation (DOM-02)
**Status:** Approved
**Created:** 2026-03-21

---

## Problem Statement

Before any feature work begins, the plugin needs a complete and correct structural foundation: the metadata WordPress and WordPress.org expect, the PHP namespace and autoloading strategy that all feature classes depend on, and the tooling configuration that enforces code quality throughout development. Without this in place, every subsequent epic starts from an inconsistent base.

---

## Target Users

- **Plugin maintainers / developers** — need a consistent, correctly configured project scaffold to build on
- **WordPress.org reviewers** — the plugin header and readme.txt must meet submission requirements
- **The WordPress runtime** — reads the plugin header to load and identify the plugin correctly

---

## Overview

Establishes all structural configuration for the plugin: the main plugin file header with required WordPress metadata, `readme.txt` for WordPress.org, PHP constants, Composer PSR-4 autoloading under the `TenUp\ChangelogToBlogPost` namespace, PHPCS with WordPress Coding Standards, `@10up/scripts` for JavaScript build and linting, and PHPUnit configuration. This is the configuration layer only — no feature PHP classes are defined here.

---

## User Stories & Acceptance Criteria

### US-001: Plugin is correctly identified by WordPress and WordPress.org

As a WordPress site, I want to read the plugin's metadata from its header so I can display it in the plugin list, enforce version requirements, and handle updates.

**Acceptance Criteria:**

- [ ] **AC-001:** The main plugin file contains a valid WordPress plugin header with: Plugin Name, Plugin URI, Description, Version, Requires at least (6.4), Requires PHP (8.0), Author, Author URI, License (GPL-2.0-or-later), License URI, Text Domain (`changelog-to-blog-post`), and Domain Path.
- [ ] **AC-002:** A `readme.txt` file is present and conforms to the WordPress.org plugin readme standard, including: Plugin Name, Stable Tag, Requires at least, Tested up to, Requires PHP, License, short description, and a Description section.
- [ ] **AC-003:** The plugin appears correctly in the WordPress admin Plugins list with its name, description, version, and author.

---

### US-002: PHP constants are defined and available throughout the plugin

As the plugin codebase, I want reliable path and URL constants available from the moment the plugin loads, so that any class can reference plugin assets, includes, or version without duplicating logic.

**Acceptance Criteria:**

- [ ] **AC-004:** The following constants are defined in the main plugin file before any other plugin code runs:
  - `CHANGELOG_TO_BLOG_POST_VERSION` — the current plugin version string
  - `CHANGELOG_TO_BLOG_POST_PATH` — absolute filesystem path to the plugin directory (trailing slash)
  - `CHANGELOG_TO_BLOG_POST_URL` — full URL to the plugin directory (trailing slash)
  - `CHANGELOG_TO_BLOG_POST_INC` — absolute path to the `includes/` directory (trailing slash)
- [ ] **AC-005:** Constants are defined only if not already defined, guarding against conflicts in unusual loading scenarios.

---

### US-003: PHP classes are autoloaded via Composer PSR-4

As a developer, I want to use any plugin class by its fully-qualified namespace without manually requiring files, so that adding new classes requires no changes to the autoload configuration.

**Acceptance Criteria:**

- [ ] **AC-006:** `composer.json` declares PSR-4 autoloading mapping the `TenUp\ChangelogToBlogPost\` namespace to the `includes/classes/` directory.
- [ ] **AC-007:** The Composer autoloader is loaded from the main plugin file before the plugin bootstraps.
- [ ] **AC-008:** Any class placed in `includes/classes/` following the namespace structure is available without a manual `require` statement.

---

### US-004: PHP code quality tooling is configured and passes

As a developer, I want PHPCS and PHPStan configured so that standards are enforced consistently and statically-detectable errors are caught before code review.

**Acceptance Criteria:**

- [ ] **AC-009:** A PHPCS configuration file is present specifying the WordPress Coding Standards ruleset.
- [ ] **AC-010:** `composer run phpcs` (or equivalent) runs PHPCS against the plugin source and passes with zero errors on the scaffold files.
- [ ] **AC-011:** PHPStan is configured at level 5 and `composer run phpstan` (or equivalent) passes with zero errors on the scaffold files.
- [ ] **AC-012:** A `phpunit.xml.dist` file is present, pointing to the test bootstrap at `tests/php/bootstrap.php` and the test suite at `tests/php/unit/`.
- [ ] **AC-013:** `composer test` runs PHPUnit and exits cleanly (zero test failures) against the scaffold — no feature tests are required at this stage.

---

### US-005: JavaScript tooling is configured and passes

As a developer, I want `@10up/scripts` configured so that JavaScript and CSS can be built, linted, and tested consistently.

**Acceptance Criteria:**

- [ ] **AC-014:** A `package.json` is present with `@10up/scripts` as a dev dependency and the following npm scripts defined: `start` (dev build with watch), `build` (production build), `lint:js` (ESLint), `lint:css` (Stylelint), `lint` (both linters), `test` (Jest).
- [ ] **AC-015:** An `.nvmrc` file specifies the Node.js version (`lts/iron`).
- [ ] **AC-016:** `npm run lint` passes with zero errors on the scaffold (no source JS/CSS files are required at this stage).

---

## Business Rules

- **BR-001:** The text domain must match the plugin slug exactly (`changelog-to-blog-post`) for WordPress.org compatibility and i18n to function correctly.
- **BR-002:** The PHP minimum (8.0) and WordPress minimum (6.4) declared in the plugin header must match what is declared in `readme.txt` and enforced in the plugin structure (PRD-02.2.01).
- **BR-003:** The plugin slug (`changelog-to-blog-post`), snake_case variant (`changelog_to_blog_post`), and PascalCase variant (`ChangelogToBlogPost`) are the canonical identifiers used consistently across all configuration files, class names, hooks, and option keys.

---

## Out of Scope

- Feature PHP classes (belong to their respective domain epics)
- Admin assets (CSS/JS source files beyond scaffold — belong to EPC-03.1)
- WordPress.org submission and asset directory (`assets/`) — post-v1

---

## Dependencies

| Depends On | For |
|------------|-----|
| — | No dependencies — this is the foundation all other epics build on |

| Depended On By | For |
|----------------|-----|
| All epics | Namespace, autoloading, constants, and tooling are prerequisites for all feature work |

---

## Open Questions

None.

---

_Managed by Spark_
