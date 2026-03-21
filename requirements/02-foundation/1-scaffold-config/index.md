---
title: "EPC-02.1: Scaffold Config"
---

# Epic: Scaffold Config

**Code:** EPC-02.1
**Domain:** Foundation (DOM-02)
**Description:** Plugin header, constants, namespace, autoloading, and code quality tooling configuration.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-21

---

## Overview

Defines all structural configuration for the plugin — the metadata WordPress and WordPress.org need, the PHP namespace and autoloading strategy, and the linting/standards tooling that enforces code quality.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-02.1.01 | scaffold-config | draft | Plugin header, readme.txt, PHP constants, Composer PSR-4 autoload, PHPCS/PHPStan, @10up/scripts, phpunit.xml.dist |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--02-foundation--1-scaffold-config.json`
**Last Updated:** 2026-03-21

## Epic Scope

**In Scope:**
- Plugin file header (Name, URI, Version, Requires, Author, License, Text Domain)
- `readme.txt` for WordPress.org
- Constants: `CHANGELOG_TO_BLOG_POST_VERSION`, `_PATH`, `_URL`, `_INC`
- Composer PSR-4 autoload under `TenUp\ChangelogToBlogPost\`
- PHPCS with WordPress Coding Standards + PHPStan level 5
- `.nvmrc`, `package.json` with `@10up/scripts`
- `phpunit.xml.dist` configuration

**Out of Scope:**
- Feature PHP classes (belong to respective domains)

## Success Criteria

- [ ] `composer install && composer run phpcs` passes with zero errors
- [ ] `npm install && npm run lint` passes with zero errors
- [ ] Plugin appears correctly in WordPress admin plugin list

---

_Managed by Spark_
