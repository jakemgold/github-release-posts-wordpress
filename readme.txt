=== GitHub Release Posts ===

Contributors:      jakemgold, 10up
Tags:              github, releases, blog post, ai, automation
Requires at least: 6.9
Tested up to:      6.9
Requires PHP:      8.2
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate blog posts from GitHub releases using AI.

== Description ==

GitHub Release Posts monitors GitHub repositories for new releases and uses AI to generate human-readable blog posts from release notes. Posts can be automatically published or held as drafts for review, with email notifications when new posts are ready.

**How it works:**

1. **Monitor** — Add any GitHub repository and the plugin checks for new releases daily via WP-Cron.
2. **Generate** — When a new release is detected, the AI reads the release notes and writes a blog post tailored to your audience.
3. **Publish** — Posts are created as drafts for review, or published automatically based on your per-repository settings.

You can also generate a post on demand at any time from the Repositories tab.

**Features:**

* Monitor multiple GitHub repositories for new releases
* AI-powered post generation with three provider options:
  * **WordPress AI Services** (recommended) — use your existing AI Services configuration
  * **OpenAI** — direct API key connection
  * **Anthropic** — direct API key connection
* Significance-aware content — patch, minor, major, and security releases get tailored tone and structure
* SEO-friendly post slugs and excerpts generated automatically by AI
* Configurable publish/draft workflow with per-repository overrides
* Per-repository post defaults (categories, tags, post status)
* Custom prompt instructions to guide AI writing style, tone, and voice
* Regenerate posts with feedback — refine AI output directly from the block editor sidebar
* Email notifications on draft creation, publication, or both
* Source attribution in the block editor — see which GitHub release generated each post
* Idempotency — the same release never creates duplicate posts
* Optional project link support — enter a URL or WordPress.org slug for download CTAs

**For developers:**

* Extensible via filter hooks at every stage of the pipeline
* Register custom AI providers via the `ctbp_register_ai_providers` filter
* Override significance classification, prompt content, post terms, and post status per-release
* All prompt templates defined in code, versioned with the plugin

**Requirements:**

* WordPress 6.9 or later
* PHP 8.2 or later
* An AI provider: WordPress AI Services plugin, an OpenAI API key, or an Anthropic API key

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/changelog-to-blog-post/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Tools → Release Posts** to configure your AI provider and add repositories.

== Frequently Asked Questions ==

= Which AI providers are supported? =

WordPress AI Services (recommended for most users), OpenAI, and Anthropic are supported in v1. WordPress AI Services lets you configure your preferred AI provider once in Settings → AI Services and use it across all compatible plugins. Additional providers can be registered via the `ctbp_register_ai_providers` filter hook.

= Do I need a GitHub API key? =

No. The plugin uses the public GitHub API for public repositories. An optional Personal Access Token (PAT) can be configured to increase the API rate limit from 60 to 5,000 requests per hour.

= Does it work with private repositories? =

Yes. Add a GitHub Personal Access Token with the `repo` scope in the Settings tab, and the plugin can access releases from your private repositories.

= How often does the plugin check for new releases? =

By default, the plugin checks daily via WP-Cron. Developers can change this to hourly, twice daily, or weekly using the `ctbp_check_frequency` filter.

= Can I customize the AI-generated content? =

Yes, in two ways. Site owners can enter custom prompt instructions in the Settings tab to guide the AI's writing style, tone, and voice. Developers can use filter hooks (`ctbp_prompt`, `ctbp_prompt_title_guidance`, `ctbp_prompt_content_guidance`) for full control over the prompt sent to the AI.

= Can I edit or regenerate a post after it's created? =

Yes. Generated posts are standard WordPress posts and can be edited normally in the block editor. A Release Attribution panel in the editor sidebar lets you regenerate the content with optional feedback — for example, "make it shorter" or "emphasize the security fix."

= What shows up in the block editor for generated posts? =

A Release Attribution panel appears in the document sidebar, showing which GitHub release the post was generated from with a link to the release notes. From this panel you can also regenerate the post content with feedback.

== Screenshots ==

1. Repositories tab — monitor multiple GitHub repos with last post version, status, and one-click post generation.
2. Settings tab — configure your AI provider, API key, audience level, custom prompt instructions, and notifications.
3. Inline editing — per-repo settings including name, project link, post status, author, categories, tags, and featured image, following the familiar WordPress Quick Edit pattern.
4. Generated post in the block editor — AI-written content with embedded images, plus the GitHub Release sidebar panel for source attribution and regeneration.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
