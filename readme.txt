=== GitHub Release Posts ===

Contributors:      jakemgold, 10up
Tags:              github, releases, blog post, ai, automation
Requires at least: 7.0
Tested up to:      7.0
Requires PHP:      8.2
Stable tag:        0.9.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically generate blog posts from GitHub releases using AI.

== Description ==

GitHub Release Posts monitors GitHub repositories for new releases and uses AI to research each release and generate a human-readable blog post about it. Posts can be automatically published or held as drafts for review, with email notifications when new posts are ready.

**How it works:**

1. **Monitor** — Add any GitHub repository and the plugin checks for new releases daily via WP-Cron.
2. **Generate** — When a new release is detected, the AI researches the release and writes a blog post tailored to your audience.
3. **Publish** — Posts are created as drafts for review, or published automatically based on your per-repository settings.

You can also generate a post on demand at any time from the Repositories tab.

**Features:**

* Monitor multiple GitHub repositories for new releases
* AI-powered post generation via WordPress Connectors — works with Anthropic, OpenAI, Google, and any other configured connector
* Significance-aware content — patch, minor, major, and security releases get tailored tone and structure
* Choose the research depth — Standard reviews release notes, linked issues and PRs, metadata, and the README; Deep adds commit messages and file changes since the last release
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
* Customize preferred AI models via the `ghrp_wp_ai_client_model_preferences` filter
* Override significance classification, prompt content, post terms, and post status per-release
* All prompt templates defined in code, versioned with the plugin

**Requirements:**

* WordPress 7.0 or later
* PHP 8.2 or later
* At least one AI connector configured under Settings → Connectors (Anthropic, OpenAI, or Google recommended)

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/github-release-posts/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Tools → Release Posts** to configure your AI provider and add repositories.

== Frequently Asked Questions ==

= Which AI providers are supported? =

The plugin uses WordPress Connectors (built into WordPress 7.0+) to communicate with AI providers. Any connector installed on your site will work. We recommend Anthropic (Claude Opus 4.7), OpenAI (GPT-5.5), or Google (Gemini 2.5 Pro) for best results. Configure your connector under Settings → Connectors.

= Do I need a GitHub API key? =

No. The plugin uses the public GitHub API for public repositories. An optional Personal Access Token (PAT) can be configured to increase the API rate limit from 60 to 5,000 requests per hour.

= Does it work with private repositories? =

Yes. Add a GitHub Personal Access Token with the `repo` scope in the Settings tab, and the plugin can access releases from your private repositories.

= How often does the plugin check for new releases? =

By default, the plugin checks daily via WP-Cron. Developers can change this to hourly, twice daily, or weekly using the `ghrp_check_frequency` filter.

= Can I customize the AI-generated content? =

Yes, in two ways. Site owners can enter custom prompt instructions in the Settings tab to guide the AI's writing style, tone, and voice. Developers can use filter hooks (`ghrp_prompt`, `ghrp_prompt_title_guidance`, `ghrp_prompt_content_guidance`) for full control over the prompt sent to the AI.

= Can I edit or regenerate a post after it's created? =

Yes. Generated posts are standard WordPress posts and can be edited normally in the block editor. A Release Attribution panel in the editor sidebar lets you regenerate the content with optional feedback — for example, "make it shorter" or "emphasize the security fix."

= What shows up in the block editor for generated posts? =

A Release Attribution panel appears in the document sidebar, showing which GitHub release the post was generated from with a link to the release notes. From this panel you can also regenerate the post content with feedback.

== Screenshots ==

1. Repositories tab — monitor multiple GitHub repos with last post version, status, and one-click post generation.
2. Settings tab — view your AI connector status and configure audience level, custom prompt instructions, and notifications.
3. Inline editing — per-repo settings including name, project link, post status, author, categories, tags, and featured image, following the familiar WordPress Quick Edit pattern.
4. Generated post in the block editor — AI-written content with embedded images, plus the GitHub Release sidebar panel for source attribution and regeneration.

== Changelog ==

= 0.9.0 =
* Plugin renamed and rebranded to **GitHub Release Posts**.
* Folder, slug, text domain, PHP namespace, hooks, options, REST routes, and CSS/JS prefixes all updated to match the new name.
* Plugin slug: `github-release-posts` (folder + text domain + WP.org slug).
* PHP namespace: `Jakemgold\GitHubReleasePosts`.
* Hook/option/transient prefix: `ghrp_*` (was `ctbp_*`).
* REST namespace: `ghrp/v1` (was `ctbp/v1`).
* No automatic migration from prior pre-release versions — uninstall and reinstall on a clean site.

= 0.8.1 =
* New: Deep research depth — optionally fetches commit messages and file change summaries between releases for richer AI context. Useful when release notes are sparse.
* New: Research Depth setting (Standard / Deep) in the Post Creation section of Settings.
* Tweak: Post Audience changed from a dropdown to radio buttons for clearer scanning.
* Tweak: Post Creation section now appears before GitHub on the Settings tab.
* Tweak: Cleaner page header copy on Tools → Release Posts.
* Tweak: Updated readme phrasing — describes researching releases, not just reading notes.

= 0.8.0 =
* Requires WordPress 7.0+ with Connectors API.
* AI generation via WordPress Connectors — supports Anthropic, OpenAI, Google, and any configured connector.
* Preferred model list with automatic fallback (Claude Opus 4.7, GPT-5.5, Gemini 2.5 Pro).
* Connector status panel replaces manual provider/API key configuration.
* Improved notification emails with contextual subject lines and post titles.
* Test notification email feature.

== Upgrade Notice ==

= 0.9.0 =
Plugin renamed to GitHub Release Posts. Hooks, options, REST routes, and CSS prefixes all changed to `ghrp_*` / `ghrp-`. No migration from earlier pre-release versions — uninstall the old plugin and install fresh.

= 0.8.1 =
Adds optional Deep research mode (commit history and file changes) and minor settings UI polish.

= 0.8.0 =
Pre-release. Requires WordPress 7.0 RC or later.
