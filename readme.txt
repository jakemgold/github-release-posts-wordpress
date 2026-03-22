=== Changelog to Blog Post ===

Contributors:      10up
Tags:              changelog, github, releases, blog post, ai
Requires at least: 6.4
Tested up to:      6.9
Requires PHP:      8.2
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically convert GitHub plugin release changelogs into WordPress blog posts using AI.

== Description ==

Changelog to Blog Post monitors GitHub releases for any number of tracked plugins and uses AI to generate human-readable blog posts from their changelogs. Posts can be automatically published or held as drafts for review, with email notifications to the site owner when new posts are ready.

**Features:**

* Monitor multiple GitHub repositories for new releases
* AI-powered post generation with three provider options:
  * **WordPress AI Services** (recommended) — use your existing AI Services configuration
  * **OpenAI** — direct API key connection
  * **Anthropic** — direct API key connection
* Significance-aware content — patch, minor, major, and security releases get tailored tone and structure
* Configurable publish/draft workflow with per-repository overrides
* Per-repository post defaults (category, tags, post status)
* Custom prompt instructions to guide AI writing style, tone, and voice
* Email notifications on draft creation, publication, or both
* Source attribution in the block editor — see which GitHub release generated each post
* Idempotency — the same release never creates duplicate posts
* WordPress.org plugin page link support for download CTAs

**For developers:**

* Extensible via filter hooks at every stage of the pipeline
* Register custom AI providers via the `ctbp_register_ai_providers` filter
* Override significance classification, prompt content, post terms, and post status per-release
* All prompt templates defined in code, versioned with the plugin

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/changelog-to-blog-post/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Tools → Changelog to Blog Post** to configure your AI provider and add repositories.

== Frequently Asked Questions ==

= Which AI providers are supported? =

WordPress AI Services (recommended for most users), OpenAI, and Anthropic are supported in v1. WordPress AI Services lets you configure your preferred AI provider once in Settings → AI Services and use it across all compatible plugins. Additional providers can be registered via the `ctbp_register_ai_providers` filter hook.

= Do I need a GitHub API key? =

No. The plugin uses the public GitHub API for public repositories. An optional Personal Access Token (PAT) can be configured to increase the API rate limit from 60 to 5,000 requests per hour.

= How often does the plugin check for new releases? =

By default, the plugin checks daily via WP-Cron. Developers can change this to hourly, twice daily, or weekly using the `ctbp_check_frequency` filter.

= Can I customize the AI-generated content? =

Yes, in two ways. Site owners can enter custom prompt instructions in the Settings tab to guide the AI's writing style, tone, and voice. Developers can use filter hooks (`ctbp_prompt`, `ctbp_prompt_title_guidance`, `ctbp_prompt_content_guidance`) for full control over the prompt sent to the AI.

== Screenshots ==

1. Plugin settings — Repositories tab
2. Plugin settings — Settings tab
3. Example generated blog post

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
