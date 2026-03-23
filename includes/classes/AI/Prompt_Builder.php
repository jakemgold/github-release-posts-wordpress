<?php
/**
 * Builds the AI prompt for release-to-blog-post generation.
 *
 * @package ChangelogToBlogPost\AI
 */

namespace TenUp\ChangelogToBlogPost\AI;

use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Hooks into ctbp_generate_prompt and returns a fully constructed prompt
 * string tailored to the release content and per-repo configuration.
 *
 * The prompt uses an editorial hierarchy that lets the AI decide what's
 * most newsworthy rather than forcing a single significance label.
 *
 * Prompt template version: 2.0 (introduced in plugin v1.1.0)
 *
 * @see AI_Processor — fires ctbp_generate_prompt with ReleaseData as 2nd arg.
 */
class Prompt_Builder {

	/**
	 * Constructor.
	 *
	 * @param Repository_Settings  $repo_settings   Per-repo configuration service.
	 * @param Release_Significance $significance    Release significance classifier.
	 * @param Global_Settings      $global_settings Global settings (custom prompt instructions).
	 */
	public function __construct(
		private readonly Repository_Settings $repo_settings,
		private readonly Release_Significance $significance,
		private readonly Global_Settings $global_settings,
	) {}

	/**
	 * Registers the ctbp_generate_prompt filter.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( 'ctbp_generate_prompt', [ $this, 'build' ], 10, 2 );
	}

	/**
	 * Builds the complete prompt string for a release.
	 *
	 * Hooked to the ctbp_generate_prompt filter.
	 *
	 * @param string      $existing_prompt Unused default (empty string from AI_Processor).
	 * @param ReleaseData $data    Structured release data.
	 * @return string The fully assembled prompt.
	 */
	public function build( string $existing_prompt, ReleaseData $data ): string {
		$config        = $this->get_repo_config( $data->identifier );
		$display_name  = $config['display_name'] ?? $this->derive_display_name( $data->identifier );
		$significance  = $this->significance->classify( $data );
		$download_link = $this->resolve_download_link( $config, $data );
		$project_link  = $this->resolve_project_link( $config, $data );
		$changelog_url = $data->html_url;

		/**
		 * Filters the release body before it is included in the prompt.
		 *
		 * Used by Release_Enricher to append linked PR/issue context.
		 *
		 * @param string      $body Release body text.
		 * @param ReleaseData $data Release data.
		 */
		$body = (string) apply_filters( 'ctbp_release_body', $data->body, $data );

		// Truncate very large release bodies to avoid exceeding AI token limits.
		// 50,000 chars ≈ 12,500 tokens, leaving room for prompt instructions.
		$max_body_length = (int) apply_filters( 'ctbp_max_release_body_length', 50000 );
		if ( strlen( $body ) > $max_body_length ) {
			$body = substr( $body, 0, $max_body_length ) . "\n\n[Release notes truncated due to length.]";
		}

		$images = $this->extract_images( $body );

		$audience_level   = $this->global_settings->get_audience_level();
		$title_guidance   = $this->build_title_guidance( $display_name, $data->tag );
		$content_guidance = $this->build_content_guidance( $images, $project_link, $changelog_url, $display_name, $audience_level );

		/**
		 * Filters the title guidance portion of the prompt.
		 *
		 * @param string $title_guidance   Default title guidance string.
		 * @param string $significance     Classified significance ('patch', 'minor', 'major', 'security').
		 * @param ReleaseData $data        Release data.
		 */
		$title_guidance = (string) apply_filters( 'ctbp_prompt_title_guidance', $title_guidance, $significance, $data );

		/**
		 * Filters the content guidance portion of the prompt.
		 *
		 * @param string $content_guidance Default content guidance string.
		 * @param string $significance     Classified significance.
		 * @param ReleaseData $data        Release data.
		 */
		$content_guidance = (string) apply_filters( 'ctbp_prompt_content_guidance', $content_guidance, $significance, $data );

		$custom_instructions = trim( $this->global_settings->get_custom_prompt_instructions() );

		$prompt = $this->assemble_prompt(
			display_name:         $display_name,
			tag:                  $data->tag,
			significance:         $significance,
			body:                 $body,
			title_guidance:       $title_guidance,
			content_guidance:     $content_guidance,
			custom_instructions:  $custom_instructions,
		);

		/**
		 * Filters the complete prompt string sent to the AI provider.
		 *
		 * Allows full override of the prompt for advanced customisation.
		 *
		 * @param string      $prompt       The assembled prompt string.
		 * @param ReleaseData $data         Release data.
		 * @param string      $significance Classified significance.
		 */
		return (string) apply_filters( 'ctbp_prompt', $prompt, $data, $significance );
	}

	// -------------------------------------------------------------------------
	// Title guidance
	// -------------------------------------------------------------------------

	/**
	 * Builds instructions for the post title subtitle.
	 *
	 * The full title will be "{display_name} {tag} — {subtitle}" where the
	 * subtitle is the one line the AI must write. The AI decides what's most
	 * compelling based on the changelog content.
	 *
	 * @param string $display_name Plugin display name.
	 * @param string $tag          Release tag.
	 * @return string
	 */
	private function build_title_guidance( string $display_name, string $tag ): string {
		$prefix = "{$display_name} {$tag} — ";

		return <<<EOT
The post title will be automatically formatted as: "{$prefix}[your subtitle here]"
Write ONLY the subtitle — do NOT include the plugin name or version number.
Lead with whatever is most compelling and newsworthy in the release. If there are new user-facing features, highlight those. If the release is purely bug fixes or a security patch with nothing else notable, say so plainly. Keep it under 12 words. Be specific — avoid generic subtitles like "various improvements and fixes".
EOT;
	}

	// -------------------------------------------------------------------------
	// Content guidance
	// -------------------------------------------------------------------------

	/**
	 * Builds the content structure and tone instructions.
	 *
	 * Uses an editorial hierarchy that prioritises what matters most to readers
	 * and adapts technical depth to the configured audience level.
	 *
	 * @param string[] $images         Image URLs extracted from the release body.
	 * @param string   $download_link  Resolved download / changelog link (custom URL or WP.org or GitHub).
	 * @param string   $changelog_url  GitHub release URL (always points to the full changelog).
	 * @param string   $display_name   Plugin display name for link text.
	 * @param string   $audience_level One of: 'general', 'mixed', 'developer', 'engineering'.
	 * @return string
	 */
	private function build_content_guidance( array $images, string $download_link, string $changelog_url, string $display_name = '', string $audience_level = 'mixed' ): string {
		$audience_instructions = $this->build_audience_instructions( $audience_level );

		// Build link and CTA instructions.
		$link_instructions  = "LINKS:\n";
		$link_instructions .= "- The first mention of the project name (\"{$display_name}\") in the body should be linked to: {$download_link}\n";

		if ( $download_link !== $changelog_url ) {
			$link_instructions .= <<<EOT
- End with TWO closing calls to action:
  1. A CTA to see the full release notes, linking to: {$changelog_url}
     e.g. "See the full release notes on GitHub."
  2. A CTA to learn more or download, linking to: {$download_link}
     e.g. "Learn more and download {$display_name}."
EOT;
		} else {
			$link_instructions .= <<<EOT
- End with a closing call to action linking to the release notes: {$changelog_url}
  e.g. "See the full release notes and download {$display_name} on GitHub."
EOT;
		}

		$structure = <<<EOT
EDITORIAL PRIORITIES (most important first):
1. New end-user features and capabilities — these are the headline story when present. What can people DO now that they couldn't before?
2. Significant technical improvements, performance gains, or developer-facing changes — the second most important story.
3. Bug fixes and security patches — note them clearly but don't lead with them unless the release contains nothing else. A release with new features AND a security fix should lead with the features and mention the security fix in context.

{$audience_instructions}

{$link_instructions}

FORMATTING RULES FOR LISTS:
- Keep bullet points to ONE sentence each. If a bullet needs more detail, use a <strong>bold label</strong> followed by a single explanatory sentence.
- Example: <li><strong>Usage monitoring</strong> — Track AI feature usage across your site with a new dashboard widget.</li>
- Do NOT use multi-sentence or multi-line bullet items. Do NOT nest bullets inside bullets.
- Prefer short, scannable lists over long, dense ones.

LENGTH: Scale content to match the release substance. A single-fix release may be one or two paragraphs. A feature-rich release may use up to approximately 7 paragraphs. Do not pad thin releases to reach a minimum length, and do not truncate rich ones.
EOT;

		$structure .= "\n\n" . $this->build_image_instructions( $images );

		return $structure;
	}

	/**
	 * Builds audience-specific content structure and tone instructions.
	 *
	 * @param string $audience_level One of: 'general', 'mixed', 'developer', 'engineering'.
	 * @return string
	 */
	private function build_audience_instructions( string $audience_level ): string {
		return match ( $audience_level ) {
			'general' => <<<'EOT'
TARGET AUDIENCE: WordPress site owners and managers who are NOT developers.

CONTENT STRUCTURE:
1. Opening paragraph: a plain-language summary that leads with the most newsworthy change. Explain what changed in terms of what the user will experience (required).
2. Feature highlights: describe new features in user-centric terms — what problem they solve and how to use them. Focus on outcomes, not implementation (required when applicable).
3. Other changes: bug fixes, security patches, minor improvements — summarise briefly in plain language (include when applicable).
4. Do NOT include a developer notes section. Omit all technical details such as hook names, API changes, function signatures, or code examples.

TONE: Write as if explaining the update to a smart colleague who manages WordPress sites but does not write code. Be clear, practical, and conversational. Never use developer jargon — translate everything into user-facing impact.
EOT
,

			'mixed' => <<<'EOT'
TARGET AUDIENCE: A mixed readership of WordPress site owners AND developers.

CONTENT STRUCTURE:
1. Opening paragraph: a plain-language summary for non-technical readers that leads with the most newsworthy change (required).
2. Feature highlights: describe new features in user-centric terms — what problem they solve and how to use them (required when applicable).
3. Other changes: bug fixes, security patches, minor improvements — summarise briefly without over-emphasising (include when applicable).
4. Developer notes (optional): if the release body contains API changes, hooks, deprecations, or database changes, add a clearly labelled section with an <h3> heading titled "Developer improvements" or "For developers" that covers those details. Omit this section entirely if no developer-relevant content is present.

TONE: Write the main body at the level of a sophisticated WordPress site owner or manager. Be clear, conversational, and jargon-free in the main sections. Reserve technical language for the developer notes section only.
EOT
,

			'developer' => <<<'EOT'
TARGET AUDIENCE: WordPress developers and plugin builders.

CONTENT STRUCTURE:
1. Opening paragraph: a concise summary that leads with the most significant change — technical details are welcome here (required).
2. Feature and improvement details: describe what changed and how it works. Include relevant technical context such as new hooks, changed behaviour, or performance characteristics (required when applicable).
3. Other changes: bug fixes, security patches — include technical details of what was fixed and why (include when applicable).
4. Breaking changes or migration notes: if present, call these out prominently with clear upgrade instructions.

TONE: Write for an audience that is comfortable with WordPress development concepts — hooks, filters, REST API, custom post types, etc. Be direct and specific. You can reference function names, hooks, and technical concepts without explanation, but keep the writing clear and well-structured.
EOT
,

			'engineering' => <<<'EOT'
TARGET AUDIENCE: Engineering teams working with WordPress at scale.

CONTENT STRUCTURE:
1. Opening paragraph: a technical summary of the release, leading with the most architecturally significant change (required).
2. Detailed changes: cover all notable changes with full technical depth — new hooks with signatures, API changes with before/after examples, database schema changes, performance implications, and deprecation paths (required).
3. Bug fixes and security: include root cause context where available — what was broken, how it was fixed, and any edge cases to be aware of.
4. Breaking changes: if present, provide specific migration steps with code examples.
5. Infrastructure notes: dependency updates, minimum version changes, build system changes, or CI/CD implications.

TONE: Write as a detailed technical changelog narrative. Assume the reader understands WordPress internals, PHP development patterns, and software architecture. Be precise and thorough. Code examples and hook signatures are encouraged where they add clarity.
EOT
,

			default => $this->build_audience_instructions( 'mixed' ),
		};
	}

	/**
	 * Builds image handling instructions for the prompt.
	 *
	 * If real images are present, instructs the AI to include them contextually.
	 * If no images exist, instructs the AI to suggest placeholder positions.
	 *
	 * @param string[] $images Extracted image URLs.
	 * @return string
	 */
	private function build_image_instructions( array $images ): string {
		if ( ! empty( $images ) ) {
			$image_list = implode( "\n", array_map( static fn( $url ) => "- {$url}", $images ) );
			return "IMAGES: The release body contains the following images. Reference them contextually in the post by including the EXACT original URL in an <img> tag (the plugin will automatically download and import them into WordPress). Place them near the content they illustrate. Use a <figure> wrapper with an appropriate <figcaption>:\n{$image_list}";
		}

		return 'IMAGES: No images are present in the release body. Do not include any image placeholders or suggestions.';
	}

	// -------------------------------------------------------------------------
	// Prompt assembly
	// -------------------------------------------------------------------------

	/**
	 * Assembles the full prompt string from its components.
	 *
	 * @param string $display_name       Plugin display name.
	 * @param string $tag                Release tag.
	 * @param string $significance       Classified significance level.
	 * @param string $body               Release body text.
	 * @param string $title_guidance     Title guidance instructions.
	 * @param string $content_guidance   Content guidance instructions.
	 * @param string $custom_instructions Custom prompt instructions from settings.
	 * @return string
	 */
	private function assemble_prompt(
		string $display_name,
		string $tag,
		string $significance,
		string $body,
		string $title_guidance,
		string $content_guidance,
		string $custom_instructions = '',
	): string {
		$significance_label = match ( $significance ) {
			'patch'    => 'Patch (maintenance / bug fixes)',
			'minor'    => 'Minor (new features or improvements)',
			'major'    => 'Major (significant new capability or breaking change)',
			'security' => 'Contains security fix (but may also contain other changes — read the full changelog)',
			default    => 'Release',
		};

		$prompt = <<<EOT
You are writing a blog post about a WordPress plugin update for the plugin's users.

RELEASE INFORMATION:
Plugin: {$display_name}
Version: {$tag}
Version hint: {$significance_label}

Release body (raw changelog — may contain Markdown, developer jargon, or GitHub references):
---
{$body}
---

IMPORTANT: The "version hint" above is a rough classification based on version numbering. Do NOT rely on it as the sole indicator of what the release contains. Read the full changelog carefully and make your own editorial judgment about what is most newsworthy. A release flagged as "security" may also introduce major features; a "patch" release may contain important improvements. Let the actual content drive your coverage.

TITLE INSTRUCTIONS:
{$title_guidance}

{$content_guidance}

RESPONSE FORMAT:
- Line 1: Your subtitle ONLY (not the full title — just the subtitle portion).
- Line 2: Blank line.
- Line 3 onwards: The post body formatted as HTML.

Use HTML tags for formatting: <p>, <ul>, <li>, <ol>, <strong>, <em>, <h2>, <h3>.
Do NOT use Markdown. Do NOT include an <h1> or full post title in the body.
Do NOT mention that this post was AI-generated.
EOT;

		if ( '' !== $custom_instructions ) {
			$prompt .= "\n\nADDITIONAL INSTRUCTIONS FROM THE SITE OWNER:\n{$custom_instructions}";
		}

		return $prompt;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns the per-repo configuration for a given identifier.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return array<string, mixed> Repo config array, or empty array if not found.
	 */
	private function get_repo_config( string $identifier ): array {
		return $this->repo_settings->get_repository( $identifier );
	}

	/**
	 * Resolves the download / changelog link for a release.
	 *
	 * Uses the unified plugin_link field: if it looks like a URL, use it
	 * directly; if it's a plain string, treat it as a WP.org slug.
	 * Falls back to the GitHub release URL (AC-019).
	 *
	 * @param array<string, mixed> $config Repo configuration.
	 * @param ReleaseData          $data   Release data.
	 * @return string
	 */
	public function resolve_download_link( array $config, ReleaseData $data ): string {
		$link = $config['plugin_link'] ?? '';

		if ( ! empty( $link ) ) {
			if ( Repository_Settings::is_url( $link ) ) {
				return (string) $link;
			}
			// Plain string — treat as WP.org slug.
			return 'https://wordpress.org/plugins/' . rawurlencode( (string) $link ) . '/';
		}

		return $data->html_url;
	}

	/**
	 * Resolves the project homepage link for a release.
	 *
	 * Same as resolve_download_link but falls back to the GitHub repo
	 * homepage (not the specific release URL) when no project link is set.
	 *
	 * @param array<string, mixed> $config Repo configuration.
	 * @param ReleaseData          $data   Release data.
	 * @return string
	 */
	public function resolve_project_link( array $config, ReleaseData $data ): string {
		$link = $config['plugin_link'] ?? '';

		if ( ! empty( $link ) ) {
			if ( Repository_Settings::is_url( $link ) ) {
				return (string) $link;
			}
			return 'https://wordpress.org/plugins/' . rawurlencode( (string) $link ) . '/';
		}

		return 'https://github.com/' . $data->identifier;
	}

	/**
	 * Extracts image URLs from a Markdown release body.
	 *
	 * Matches both `![alt](url)` and raw `<img src="url">` patterns.
	 *
	 * @param string $body Raw release body.
	 * @return string[] Array of image URLs.
	 */
	public function extract_images( string $body ): array {
		$urls = [];

		// Markdown image syntax: ![alt text](url).
		if ( preg_match_all( '/!\[.*?\]\((https?:\/\/[^)]+)\)/', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// HTML img tags: <img src="url" ...>.
		if ( preg_match_all( '/<img[^>]+src=["\']?(https?:\/\/[^"\'>\s]+)["\']?/i', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Derives a display name from a repo identifier when no display name is configured.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return string
	 */
	private function derive_display_name( string $identifier ): string {
		$parts = explode( '/', $identifier );
		$name  = end( $parts );
		return ucwords( str_replace( [ '-', '_' ], ' ', $name ) );
	}
}
