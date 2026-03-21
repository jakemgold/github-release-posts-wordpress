=== Changelog to Blog Post ===

Contributors:      10up
Tags:              changelog, github, releases, blog post, ai
Requires at least: 6.4
Tested up to:      6.7
Requires PHP:      8.0
Stable tag:        1.0.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Automatically convert GitHub plugin release changelogs into WordPress blog posts using AI.

== Description ==

Changelog to Blog Post monitors GitHub releases for any number of tracked plugins and uses AI to generate human-readable blog posts from their changelogs. Posts can be automatically published or held as drafts for review, with email notifications to the site owner when new posts are ready.

**Features:**

* Monitor multiple GitHub repositories for new releases
* AI-powered post generation (OpenAI, Anthropic, Google Gemini, ClassifAI)
* Configurable publish/draft workflow
* Per-repository post defaults (category, tags, status)
* Email notifications on draft creation or publication
* Significance classification (patch/minor/major/security)
* WP-Cron scheduling with configurable intervals
* WordPress.org plugin page link support

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/changelog-to-blog-post/`, or install via the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to **Tools → Changelog to Blog Post** to configure tracked repositories and AI provider.

== Frequently Asked Questions ==

= Which AI providers are supported? =

OpenAI, Anthropic (Claude), Google Gemini, and 10up ClassifAI are supported in v1. Additional providers can be registered via the `changelog_to_blog_post_register_providers` filter hook.

= Do I need a GitHub API key? =

No. The plugin uses the public GitHub API for public repositories. An optional Personal Access Token (PAT) can be configured to increase the API rate limit.

= How often does the plugin check for new releases? =

You can configure the check interval in plugin settings: hourly, twice daily, daily, or weekly.

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
