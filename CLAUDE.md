# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

**GitHub Release Posts** — a WordPress plugin that monitors GitHub repositories for new releases and uses AI to automatically generate blog posts. Built with the 10up engineering stack.

- PHP namespace: `TenUp\ChangelogToBlogPost`
- Text domain: `changelog-to-blog-post`
- Plugin slug (files/options): `changelog-to-blog-post` (unchanged from original name)
- PHP minimum: 8.2 | WP minimum: 6.9 | WP tested up to: 6.9
- Block editor required — plugin disables generation when Classic Editor is active

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
composer test                                    # PHPUnit (all suites)
php vendor/bin/phpunit --filter TestClassName     # single test class
php vendor/bin/phpunit --filter test_method_name  # single test
vendor/bin/phpcs --standard=phpcs.xml.dist includes/  # WPCS lint
vendor/bin/phpcbf --standard=phpcs.xml.dist includes/ # WPCS auto-fix
```

### Deploy to Local WP test site
```bash
npm run build && composer install --no-dev --optimize-autoloader
rsync -av --delete \
  --include='changelog-to-blog-post.php' --include='uninstall.php' --include='readme.txt' \
  --include='includes/***' --include='dist/***' --include='assets/css/***' \
  --include='assets/js/***' --include='assets/' --include='languages/***' \
  --include='vendor/***' --exclude='*' \
  ./ ~/Local\ Sites/plugin-tester/app/public/wp-content/plugins/changelog-to-blog-post/
```

## Architecture

### Entry point
`changelog-to-blog-post.php` — defines constants (`CHANGELOG_TO_BLOG_POST_VERSION`, `_URL`, `_PATH`, `_INC`), loads the Composer autoloader, and bootstraps the plugin via `plugins_loaded`.

### PHP classes (`includes/classes/`)
- `Plugin.php` — singleton that hooks `init` and `i18n`. Admin_Page only loads on `is_admin()`. Shared instances of Global_Settings, Repository_Settings, and API_Client are reused across the pipeline.
- `Admin/Admin_Page.php` — settings page (Tools → Release Posts), REST API endpoints, help tabs, block editor asset enqueuing.
- `Admin/Settings_Page.php` — WordPress Settings API registration for the Settings tab (GitHub, AI Provider, Notifications sections).
- `Admin/Repository_List_Table.php` — WP_List_Table subclass for the Repositories tab. Uses WP Quick Edit clone pattern (template in hidden table, JS clones on edit). Batch-preloads last post data.
- `Settings/Repository_Settings.php` — CRUD for the repositories array (`ctbp_repositories` option). In-memory cache. Migration for legacy `wporg_slug`/`custom_url` → `plugin_link`.
- `Settings/Global_Settings.php` — reads/writes global options. Handles API key encryption via libsodium.
- `AI/Prompt_Builder.php` — builds the full AI prompt. Prompts are intentionally English-only.
- `AI/AI_Processor.php` — orchestrates AI generation. Caches responses, tracks failures, sends failure emails after 3 consecutive failures.
- `Post/Post_Creator.php` — creates WordPress posts from AI output. Converts HTML to Gutenberg blocks, sideloads images (GitHub domains only), sets featured image and author, appends AI disclosure.
- `Post/Publish_Workflow.php` — sets final post status, records cron results.
- `Post/Taxonomy_Assigner.php` — applies categories and tags from per-repo config.
- `GitHub/Release_Monitor.php` — cron handler. Transient-based concurrency lock. Checks repos for new releases, queues them for AI generation.
- All classes use PSR-4 autoloading under `TenUp\ChangelogToBlogPost\`.

### Key design decisions
- **Per-repo settings only** — no global post defaults. Each repo has its own status, categories, tags, author, featured image, and project link.
- **All options autoload=false** — zero front-end memory footprint.
- **Regeneration creates revisions** — no replace/duplicate pattern. Both the repo table "Generate post" button and the editor "Regenerate" button use the same `/releases/regenerate` REST endpoint.
- **AI connectors**: OpenAI (o3), Anthropic (claude-opus-4-6), WordPress AI Services. 120s timeout with timeout detection.
- **Image blocks rebuilt from scratch** — AI HTML is parsed for src/alt/figcaption, then reconstructed as exact Gutenberg `wp:image` block markup to pass validation.

### Assets (`assets/`)
- Source: `assets/js/admin/index.js`, `assets/js/editor/index.js`, `assets/css/admin/style.css`
- `@10up/scripts` handles bundling. Built files output to `dist/`.
- Editor script only loads on posts with plugin meta (not all editor screens).
- `wp_enqueue_media()` loaded on settings page for featured image picker.

### Tests (`tests/php/`)
- Bootstrap: `tests/php/bootstrap.php` (WP_Mock).
- Unit tests: `tests/php/unit/` — suffix files with `Test.php`.
- 207 tests, all passing. 0 WPCS violations.

### REST API endpoints (`ctbp/v1/`)
- `POST /releases/generate-draft` — generates a new post or returns conflict data
- `POST /releases/regenerate` — updates existing post as revision (also used by editor panel)
- `GET /ai/test-connection` — tests AI provider with optional unsaved provider/key override
- `GET /wporg/validate` — validates a plugin link (URL format or WP.org slug API check)

### Filters
- `ctbp_default_post_status`, `ctbp_default_categories`, `ctbp_default_tags` — defaults when creating a repo
- `ctbp_post_status_options` — post status dropdown choices (draft/pending/publish)
- `ctbp_post_status` — override status per-release before it's applied
- `ctbp_post_terms` — override categories/tags per-release
- `ctbp_post_featured_image` — override featured image per-release
- `ctbp_ai_disclosure_text` — customize or suppress the AI disclosure note
- `ctbp_max_release_body_length` — truncation threshold for large release bodies (default 50000)
- `ctbp_sideload_allowed_domains` — domains allowed for image sideloading (default: github.com, githubusercontent.com, github.io)
- `ctbp_check_frequency` — WP-Cron schedule (default: daily)
- `ctbp_register_ai_providers` — register custom AI provider connectors
- `ctbp_openai_model`, `ctbp_anthropic_model` — override AI model IDs
- `ctbp_generate_prompt`, `ctbp_prompt_title_guidance`, `ctbp_prompt_content_guidance` — prompt customization
- `ctbp_release_body` — filter release body before prompt building (used by Release_Enricher)
