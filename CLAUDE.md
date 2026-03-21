# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**Changelog to Blog Post** — a WordPress plugin that converts changelog entries into WordPress blog posts. Built with the 10up engineering stack.

- PHP namespace: `TenUp\ChangelogToBlogPost`
- Text domain: `changelog-to-blog-post`
- PHP minimum: 8.0 | WP minimum: 6.4

## Commands

### JavaScript (via @10up/scripts)
```bash
npm start          # dev build with watch
npm run build      # production build
npm run lint:js    # ESLint
npm run lint:css   # Stylelint
npm run lint       # both linters
npm test           # Jest unit tests
```

### PHP
```bash
composer install
composer test                  # PHPUnit (all suites)
./vendor/bin/phpunit --filter TestClassName   # single test class
./vendor/bin/phpunit --filter test_method_name  # single test
```

## Architecture

### Entry point
`changelog-to-blog-post.php` — defines constants (`CHANGELOG_TO_BLOG_POST_VERSION`, `_URL`, `_PATH`, `_INC`), loads the Composer autoloader, and bootstraps the plugin via `plugins_loaded`.

### PHP classes (`includes/classes/`)
- `Plugin.php` — singleton that hooks `init` and `i18n`. Add new feature classes here and instantiate them from `Plugin::init()`.
- All classes use PSR-4 autoloading under `TenUp\ChangelogToBlogPost\`.

### Assets (`assets/`)
- Source files live in `assets/js/<context>/index.js` and `assets/css/<context>/style.css` where `<context>` is `admin` or `frontend`.
- `@10up/scripts` handles bundling — no webpack config needed unless overriding defaults.
- Built files output to the same directories with `.min.js` / `.min.css` suffixes.

### Tests (`tests/php/`)
- Bootstrap: `tests/php/bootstrap.php` (WP_Mock).
- Unit tests: `tests/php/unit/` — suffix files with `Test.php`.

## Spark Planning System

This project uses Spark documentation-driven development.

| Path | Purpose |
|------|---------|
| `requirements/` | PRDs organized by domain/epic/feature |
| `requirements/index.md` | Root requirements index — start here |
| `.spark/planning/STATE.md` | Project state and session continuity |
| `.spark/STATUS.md` | Master status dashboard |
| `.spark/config.json` | Spark configuration |

**Workflow:** `/spark-eng:big-picture` → `/spark-eng:refine-epic` → `/spark-eng:plan-epic` → `/spark-eng:execute-epic`

Settings: research agents enabled before PRDs, auto-validation after each epic.
