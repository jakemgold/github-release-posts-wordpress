---
epic: 02-foundation/1-scaffold-config
prd: PRD-02.1.01
created: 2026-03-21
status: ready-for-execution
---

# Epic Plan: 02-foundation/1-scaffold-config

## Overview

**Epic:** Scaffold Config (EPC-02.1)
**Goal:** Complete structural foundation — plugin header, readme.txt, constants, PSR-4 autoloading, PHPCS, PHPStan, @10up/scripts, PHPUnit config — all passing with zero errors.
**PRDs:** PRD-02.1.01
**Requirements:** 5 user stories, 16 acceptance criteria

## Current State

Several scaffold files already exist from initial project setup. This plan documents what needs to be added or corrected:

| File | State | Action |
|------|-------|--------|
| `changelog-to-blog-post.php` | Exists — 2 gaps | Fix license string; add `defined()` guards to constants |
| `composer.json` | Exists — incomplete | Add PHPCS + PHPStan deps and scripts |
| `package.json` | Exists — complete | No changes needed |
| `phpunit.xml.dist` | Exists — complete | No changes needed |
| `.nvmrc` | Exists — complete | No changes needed |
| `tests/php/bootstrap.php` | Exists | Verify content is correct |
| `readme.txt` | Missing | Create |
| `phpcs.xml.dist` | Missing | Create |
| `phpstan.neon.dist` | Missing | Create |

---

## Tasks

### Task 1: Fix main plugin file

**PRD:** PRD-02.1.01
**Implements:** US-001 (AC-001), US-002 (AC-004, AC-005)
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. Update the `License` header field from `GPL v2 or later` to `GPL-2.0-or-later` (SPDX identifier required by WordPress.org).
2. Wrap each `define()` call with `if ( ! defined( '...' ) )` guards per AC-005.
3. Confirm all required header fields are present: Plugin Name, Plugin URI, Description, Version, Requires at least, Requires PHP, Author, Author URI, License, License URI, Text Domain, Domain Path.
4. Confirm `Domain Path: /languages` directory exists (create empty dir if not).

**Verification:**

- [ ] AC-001: All required plugin header fields present and correct
- [ ] AC-004: All four constants defined (`VERSION`, `PATH`, `URL`, `INC`)
- [ ] AC-005: Each constant wrapped in `if ( ! defined(...) )`

**Files to modify:**

- `changelog-to-blog-post.php` — fix license, add defined() guards

---

### Task 2: Create readme.txt

**PRD:** PRD-02.1.01
**Implements:** US-001 (AC-002)
**Complexity:** Low
**Dependencies:** None

**Steps:**

1. Create `readme.txt` in the plugin root following the [WordPress.org readme standard](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).
2. Include required sections: plugin name header block (with Stable Tag, Requires at least, Tested up to, Requires PHP, License, License URI), short description (≤150 chars), and `== Description ==` section.
3. Set `Stable Tag: 1.0.0`, `Requires at least: 6.4`, `Tested up to: 6.7`, `Requires PHP: 8.0`, `License: GPL-2.0-or-later`.
4. Add placeholder sections for `== Installation ==`, `== Changelog ==`, and `== Upgrade Notice ==` (required by WordPress.org validator).

**Verification:**

- [ ] AC-002: `readme.txt` present, all required fields populated, conforms to WordPress.org standard

**Files to create:**

- `readme.txt` — WordPress.org plugin readme

---

### Task 3: Add PHPCS configuration

**PRD:** PRD-02.1.01
**Implements:** US-004 (AC-009, AC-010)
**Complexity:** Low
**Dependencies:** Task 1 (plugin file must be correct before linting)

**Steps:**

1. Add PHPCS and WordPress Coding Standards to `composer.json` require-dev:
   - `"squizlabs/php_codesniffer": "^3.0"`
   - `"wp-coding-standards/wpcs": "^3.0"`
   - `"phpcsstandards/phpcsutils": "*"` (required by WPCS 3.x)
2. Add Composer scripts in `composer.json`:
   - `"phpcs": "phpcs"`
   - `"phpcbf": "phpcbf"` (auto-fixer, useful companion)
3. Create `phpcs.xml.dist` in plugin root specifying:
   - `WordPress-Core`, `WordPress-Docs`, `WordPress-Extra` rulesets
   - Scan `includes/` and `changelog-to-blog-post.php`
   - Exclude `vendor/` and `node_modules/`
   - PHP minimum version 8.0
   - Text domain `changelog-to-blog-post`
4. Run `composer install` then `composer phpcs` and fix any violations in existing scaffold files.

**Verification:**

- [ ] AC-009: `phpcs.xml.dist` present, WordPress Coding Standards ruleset configured
- [ ] AC-010: `composer phpcs` exits with zero errors on scaffold files

**Files to create/modify:**

- `phpcs.xml.dist` — PHPCS configuration
- `composer.json` — add PHPCS deps + scripts

---

### Task 4: Add PHPStan configuration

**PRD:** PRD-02.1.01
**Implements:** US-004 (AC-011)
**Complexity:** Low
**Dependencies:** Task 1

**Steps:**

1. Add PHPStan and WordPress stubs to `composer.json` require-dev:
   - `"phpstan/phpstan": "^1.0"`
   - `"szepeviktor/phpstan-wordpress": "^1.0"` (WordPress function stubs for PHPStan)
   - `"php-stubs/wordpress-stubs": "^6.0"` (pulled in by phpstan-wordpress)
2. Add Composer script: `"phpstan": "phpstan analyse"`.
3. Create `phpstan.neon.dist` in plugin root:
   - Level 5
   - Paths: `includes/`, `changelog-to-blog-post.php`
   - Include `szepeviktor/phpstan-wordpress` extension
   - `bootstrapFiles` pointing to `vendor/php-stubs/wordpress-stubs/wordpress-stubs.php` if needed
4. Run `composer phpstan` and resolve any issues in existing scaffold files.

**Verification:**

- [ ] AC-011: `phpstan.neon.dist` present, level 5 configured, `composer phpstan` passes with zero errors

**Files to create/modify:**

- `phpstan.neon.dist` — PHPStan configuration
- `composer.json` — add PHPStan deps + script

---

### Task 5: Verify test bootstrap and PHPUnit pass

**PRD:** PRD-02.1.01
**Implements:** US-004 (AC-012, AC-013)
**Complexity:** Low
**Dependencies:** Task 3, Task 4 (composer.json must be complete)

**Steps:**

1. Read `tests/php/bootstrap.php` — verify it initialises WP_Mock correctly and defines the `TenUp\ChangelogToBlogPost\Tests` namespace autoload path.
2. Ensure `phpunit.xml.dist` points correctly to `tests/php/bootstrap.php` and `tests/php/unit/` (already confirmed correct).
3. Create `tests/php/unit/` directory with a `.gitkeep` if no test files exist yet (PHPUnit needs the directory to exist).
4. Run `composer test` and confirm it exits cleanly with no failures (empty test suite passes).

**Verification:**

- [ ] AC-012: `phpunit.xml.dist` points to correct bootstrap and test suite paths
- [ ] AC-013: `composer test` exits cleanly

**Files to check/modify:**

- `tests/php/bootstrap.php` — verify WP_Mock init
- `tests/php/unit/.gitkeep` — ensure directory exists

---

### Task 6: Verify JavaScript tooling passes

**PRD:** PRD-02.1.01
**Implements:** US-005 (AC-014, AC-015, AC-016)
**Complexity:** Low
**Dependencies:** None (independent of PHP tasks)

**Steps:**

1. Confirm `package.json` has all required scripts (`start`, `build`, `lint:js`, `lint:css`, `lint`, `test`) — already confirmed correct.
2. Confirm `.nvmrc` contains `lts/iron` — already confirmed correct.
3. Run `npm install` then `npm run lint` and confirm zero errors (no source files exist yet, so linters should pass on empty scope).
4. If `@10up/scripts` requires entry point configuration, add a minimal `10up-scripts.config.js` or confirm the default convention (`assets/js/admin/index.js`, `assets/css/admin/style.css`) is sufficient as placeholder.

**Verification:**

- [ ] AC-014: `package.json` has all required scripts and `@10up/scripts` dev dependency
- [ ] AC-015: `.nvmrc` specifies `lts/iron`
- [ ] AC-016: `npm run lint` passes with zero errors

**Files to check:**

- `package.json` — already complete
- `.nvmrc` — already complete

---

## Plan Summary

| # | Task | Implements | Complexity | Dependencies |
|---|------|------------|------------|--------------|
| 1 | Fix main plugin file | AC-001, AC-004, AC-005 | Low | — |
| 2 | Create readme.txt | AC-002 | Low | — |
| 3 | Add PHPCS configuration | AC-009, AC-010 | Low | Task 1 |
| 4 | Add PHPStan configuration | AC-011 | Low | Task 1 |
| 5 | Verify PHPUnit bootstrap | AC-012, AC-013 | Low | Tasks 3, 4 |
| 6 | Verify JS tooling | AC-014, AC-015, AC-016 | Low | — |

**Total:** 6 tasks, 1 PRD, 16 acceptance criteria
**Estimated complexity:** Low — mostly configuration files, no feature logic
**Tasks 1, 2, 6 can run in parallel. Tasks 3, 4 can run after Task 1 in parallel. Task 5 runs last.**

## AC Coverage

| AC | Task | Description |
|----|------|-------------|
| AC-001 | 1 | Plugin header complete and correct |
| AC-002 | 2 | readme.txt present and valid |
| AC-003 | 1 | Plugin visible in WP admin (verified manually) |
| AC-004 | 1 | All four constants defined |
| AC-005 | 1 | Constants guarded with defined() |
| AC-006 | — | Already in composer.json ✓ |
| AC-007 | — | Already in main plugin file ✓ |
| AC-008 | — | PSR-4 autoload already configured ✓ |
| AC-009 | 3 | phpcs.xml.dist present |
| AC-010 | 3 | composer phpcs passes |
| AC-011 | 4 | composer phpstan passes at level 5 |
| AC-012 | 5 | phpunit.xml.dist correct |
| AC-013 | 5 | composer test exits cleanly |
| AC-014 | 6 | package.json scripts complete |
| AC-015 | 6 | .nvmrc specifies lts/iron |
| AC-016 | 6 | npm run lint passes |
