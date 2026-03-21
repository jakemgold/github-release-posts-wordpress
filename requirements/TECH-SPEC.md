---
title: "Technical Specification"
order: 1
---

# Technical Specification

**Project:** Changelog to Blog Post (WordPress Plugin)
**Platform:** WordPress Plugin — WordPress.org distribution
**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Document Purpose

Defines the technical implementation approach for the plugin. Establishes standards, tooling, architectural patterns, data storage, security, and testing strategy that all domains must follow.

**Prerequisite reading:** [Requirements Index](index.md)

---

## Big Picture Summary

| Aspect | Value |
|--------|-------|
| Platform | WordPress Plugin (PHP 8.0+, WP 6.4+) |
| Distribution | WordPress.org free plugin repository |
| Domains | 7 |
| Compliance | None |
| Performance Tier | Standard (all external calls run in background via WP-Cron) |
| Launch Target | After v1 feature complete |

---

## Environments

| Environment | Purpose | Setup |
|-------------|---------|-------|
| Local | Primary development | Local by Flywheel |
| Staging | Pre-release testing | A separate WordPress install with the plugin active |
| Production | End-user sites | Any standard WordPress hosting |

The plugin has no server-side infrastructure requirements beyond a standard WordPress installation. It ships to users via WordPress.org and runs entirely within their existing WordPress environment.

---

## Code Standards & Tooling

### PHP

| Item | Choice | Notes |
|------|--------|-------|
| Standard | WordPress Coding Standards (WPCS) | Required for WordPress.org review |
| Ruleset | `WordPress`, `WordPress-Extra`, `WordPress-Docs` | Full WPCS suite |
| Tool | PHP_CodeSniffer (PHPCS) | Run via `composer lint` |
| Minimum version | PHP 8.0 | Enables typed properties, named args, match expressions |

**WPCS key requirements enforced:**
- All inputs sanitized (`sanitize_text_field`, `absint`, `wp_kses_post`, etc.)
- All outputs escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`, etc.)
- All form submissions verified with nonces
- All direct database queries use `$wpdb->prepare()`
- All strings internationalized with `__()`, `_e()`, etc.

### Static Analysis

| Item | Choice |
|------|--------|
| Tool | PHPStan |
| Level | 5 |
| Run via | `composer analyse` |

PHPStan level 5 catches: undefined variables, incorrect argument types, impossible conditions, and dead code — without requiring exhaustive type annotations on every line.

### JavaScript & CSS

| Item | Choice |
|------|--------|
| Build tooling | `@10up/scripts` |
| Linting | ESLint (pre-configured by @10up/scripts) |
| CSS linting | Stylelint (pre-configured by @10up/scripts) |
| Test runner | Jest (via `@10up/scripts`) |

Source files live in `assets/js/<context>/index.js` and `assets/css/<context>/style.css`. No custom Webpack config required.

### Composer Scripts

```json
{
  "lint":     "phpcs",
  "lint-fix": "phpcbf",
  "analyse":  "phpstan analyse --level=5",
  "test":     "phpunit"
}
```

---

## Architecture: Execution Pipeline

The full execution pipeline runs inside a WP-Cron job. It never executes on a page load.

```
WP-Cron fires (configured interval)
  │
  ├─► For each tracked repo (from settings):
  │     ├─► GitHub API Client → fetch latest release
  │     ├─► Release Monitor → compare to last-seen tag
  │     └─► If new release found:
  │           ├─► AI Provider (via interface) → GeneratedPost
  │           ├─► Post Creator → wp_insert_post (with idempotency check)
  │           ├─► Taxonomy Assigner → categories + tags
  │           └─► Queue for notification
  │
  └─► Notification → single batched wp_mail (if any new posts)
```

**Manual trigger** (from settings page) runs the same pipeline synchronously via an admin AJAX action.

---

## Architecture: AI Provider Interface

The AI layer is built around a PHP interface to allow swapping providers with zero changes to consuming code.

```php
namespace TenUp\ChangelogToBlogPost\AI;

interface AIProviderInterface {
    public function generate_post( ReleaseData $data ): GeneratedPost|WP_Error;
    public function is_available(): bool;
    public function get_provider_slug(): string;
}
```

**Implementations (v1):**
- `OpenAIConnector` — Chat Completions API
- `WordPressAIConnector` — WordPress AI API (stubbed, activate when stable)
- `ClassifAIConnector` — 10up ClassifAI plugin (instantiated only if ClassifAI is active)

**Provider factory** reads the configured provider slug from settings and returns the correct implementation. Adding a new provider = new class implementing the interface + registration in the factory.

---

## Architecture: Value Objects

Two immutable value objects carry data through the pipeline:

### `ReleaseData`

```php
class ReleaseData {
    public string $repo;          // "owner/repo"
    public string $tag;           // "v2.1.0"
    public string $name;          // Release title from GitHub
    public string $body;          // Markdown changelog body
    public string $html_url;      // GitHub release URL
    public string $published_at;  // ISO 8601 date string
    public string $significance;  // "patch" | "minor" | "major" (classified by pipeline)
}
```

### `GeneratedPost`

```php
class GeneratedPost {
    public string $title;    // AI-generated post title
    public string $content;  // AI-generated post body (HTML)
}
```

---

## Data Storage

The plugin uses only WordPress-native storage mechanisms — no custom database tables.

| Data | Storage | Key / Format |
|------|---------|--------------|
| Plugin settings | `wp_options` | `changelog_to_blog_post_settings` (serialized array) |
| Per-repo state (last seen tag, last checked) | `wp_options` | `changelog_to_blog_post_repo_{hash}` |
| API keys (GitHub PAT, OpenAI key) | `wp_options` | Encrypted before storage (see Security) |
| Generated post → release link | Post meta | `_changelog_source_repo`, `_changelog_release_tag`, `_changelog_release_url`, `_changelog_generated_by` |
| AI response cache | Transient | `ctbp_ai_{release_hash}` (1 hour TTL) |
| Scheduled cron event | WP-Cron | `changelog_to_blog_post_check_releases` |

**No custom tables.** Run history is written to `debug.log` only (not stored in the database).

---

## Security

### API Key Encryption

API keys (GitHub Personal Access Token, OpenAI API key) are **never stored in plain text**.

- Encrypt using `sodium_crypto_secretbox` (available in PHP 7.2+ via libsodium)
- Encryption key derived from `wp_salt('secure_auth_key')` + a plugin-specific constant
- Keys are encrypted before `update_option()` and decrypted after `get_option()`
- Keys are **never** output to page source, JS variables, or REST API responses

### WordPress.org Security Requirements

All code must pass WordPress.org automated security review:

- **Inputs:** Every value from `$_GET`, `$_POST`, `$_REQUEST` sanitized before use
- **Outputs:** Every value echoed to HTML escaped with the appropriate function
- **Nonces:** Every form submission and AJAX action verified with `check_admin_referer()` or `check_ajax_referer()`
- **Capabilities:** All admin actions gated on `current_user_can( 'manage_options' )`
- **Database:** All `$wpdb` queries use `$wpdb->prepare()`
- **File access:** No `eval()`, `base64_decode()` on user input, or dynamic `include`/`require`

### External HTTP Requests

All external HTTP calls use `wp_remote_get()` / `wp_remote_post()` (never `curl` directly). Responses are validated before use. Timeouts set to 15 seconds. Errors return `WP_Error`, never throw exceptions.

---

## Testing

### PHP Unit Tests

| Item | Choice |
|------|--------|
| Framework | PHPUnit 9.x |
| WP mocking | WP_Mock (10up/wp_mock) |
| Config | `phpunit.xml.dist` |
| Location | `tests/php/unit/` |
| Naming | `*Test.php` suffix, `test_*` method prefix |

**Coverage targets (v1):**
- `AIProviderInterface` implementations: 100% (critical path)
- `ReleaseMonitor` deduplication logic: 100% (must never create duplicates)
- Settings sanitization/validation: 100% (security requirement)
- Significance classifier: 100% (drives prompt tone)

### JavaScript Unit Tests

Jest via `npm test`. Test files co-located with source or in `assets/js/__tests__/`.

---

## Logging

The plugin uses a lightweight internal logger that writes structured entries to WordPress's debug log.

```
[changelog-to-blog-post] [INFO]  2026-03-20 14:32:00 | Checked owner/repo | Found: v2.1.0 (new)
[changelog-to-blog-post] [INFO]  2026-03-20 14:32:01 | Generated post via openai | Post ID: 42
[changelog-to-blog-post] [ERROR] 2026-03-20 14:32:02 | GitHub API error for owner/repo2 | Rate limit exceeded
```

Logging only writes when `WP_DEBUG` is `true` and `WP_DEBUG_LOG` is `true`. No log output in production by default. Each log entry includes: level, timestamp, repo context, and a human-readable message.

---

## WordPress.org Distribution Requirements

All code must meet WordPress.org plugin review guidelines before submission:

| Requirement | Implementation |
|-------------|----------------|
| GPL v2+ license | Plugin header + `LICENSE` file |
| `readme.txt` | With stable tag, tested-up-to, changelog, screenshots |
| No minified code without source | All build output accompanied by source |
| No external calls on every page load | All API calls in WP-Cron only |
| Proper i18n | All user-facing strings wrapped in `__()` / `_e()` with `changelog-to-blog-post` text domain |
| Unique function/option prefixes | All functions, hooks, options prefixed with `changelog_to_blog_post_` or `ctbp_` |
| No direct file inclusion | Use `plugin_dir_path()` for all includes |
| Stable tag kept current | Update `readme.txt` stable tag with each release |

**Submission timing:** After v1 feature complete — all 7 domains implemented and tested.

---

## Filter & Action Hooks (Public API)

The plugin exposes documented hooks for site owner customization. All hooks are prefixed `changelog_to_blog_post_`.

| Hook | Type | Purpose |
|------|------|---------|
| `changelog_to_blog_post_prompt` | filter | Customize AI prompt template |
| `changelog_to_blog_post_post_status` | filter | Override draft/publish per post |
| `changelog_to_blog_post_post_terms` | filter | Override categories/tags per post |
| `changelog_to_blog_post_notification_email` | filter | Customize notification email content |
| `changelog_to_blog_post_before_generate` | action | Fire before AI generation begins |
| `changelog_to_blog_post_after_post_created` | action | Fire after post is inserted (passes post ID + ReleaseData) |

All hooks documented in inline docblocks with `@since`, `@param`, and `@return`.

---

## Architecture Decisions Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| PHP coding standard | WordPress Coding Standards | Required for WP.org review; enforces security patterns |
| Static analysis | PHPStan level 5 | Meaningful coverage without annotation overhead |
| Testing | WP_Mock + PHPUnit | Fast, no Docker required, sufficient for unit coverage |
| Storage | wp_options + post meta only | No custom tables = simpler install/uninstall, no DB migrations |
| API key storage | Encrypted wp_options (libsodium) | Native PHP encryption, no external dependencies |
| Run history | debug.log only (v1) | Avoids custom table; admin UI log table deferred to v2 |
| AI abstraction | Provider interface | Swap providers without changing consuming code |
| External HTTP | wp_remote_get/post only | WP.org requires use of WP HTTP API |
| Logging | WP_DEBUG_LOG gated | Zero output in production by default |

---

## Open Questions

| # | Question | Owner | Status |
|---|----------|-------|--------|
| 1 | WordPress AI API — stable enough to include in v1 or stub only? | Research needed | Open |
| 2 | Should the OpenAI model be user-configurable (gpt-4o vs. gpt-4o-mini) or hardcoded? | Product decision | Open |
| 3 | Semver significance classifier — fall back gracefully for non-semver tags (e.g. `2024.03.1`)? | Engineering | Open |

---

_Generated by Spark | Last updated: 2026-03-20_
