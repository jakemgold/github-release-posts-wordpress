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
 * string tailored to the release's significance, audience, and per-repo
 * configuration.
 *
 * All template strings are defined in this class. To update a template for
 * a new plugin release, edit the relevant constant or method below.
 *
 * Prompt template version: 1.0 (introduced in plugin v1.0.0)
 *
 * @see AI_Processor — fires ctbp_generate_prompt with ReleaseData as 2nd arg.
 */
class Prompt_Builder {

	/**
	 * @param Repository_Settings $repo_settings   Per-repo configuration service.
	 * @param Release_Significance $significance   Release significance classifier.
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
	 * @param string      $default Unused default (empty string from AI_Processor).
	 * @param ReleaseData $data    Structured release data.
	 * @return string The fully assembled prompt.
	 */
	public function build( string $default, ReleaseData $data ): string {
		$config        = $this->get_repo_config( $data->identifier );
		$display_name  = $config['display_name'] ?? $this->derive_display_name( $data->identifier );
		$significance  = $this->significance->classify( $data );
		$download_link = $this->resolve_download_link( $config, $data );
		$images        = $this->extract_images( $data->body );

		$title_guidance   = $this->build_title_guidance( $significance, $display_name, $data->tag );
		$content_guidance = $this->build_content_guidance( $significance, $images, $download_link );

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
			body:                 $data->body,
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
	 * Builds per-significance instructions for the post title subtitle.
	 *
	 * The full title will be "{display_name} {tag} — {subtitle}" where the
	 * subtitle is the one line the AI must write. Prepending is programmatic
	 * so the AI is instructed not to include the plugin name or version (BR-002).
	 *
	 * @param string $significance Classified significance.
	 * @param string $display_name Plugin display name.
	 * @param string $tag          Release tag.
	 * @return string
	 */
	private function build_title_guidance( string $significance, string $display_name, string $tag ): string {
		$prefix = "{$display_name} {$tag} — ";

		$guidance = match ( $significance ) {
			'patch'    => "Write a brief, functional subtitle (e.g. \"Bug fixes and stability improvements\"). Keep it under 10 words. Do not mention specific bug names.",
			'minor'    => "Highlight one or two notable improvements in plain language. Be specific but concise. Keep it under 12 words.",
			'major'    => "Lead with the headline new capability or change in a compelling but plain-language way. Keep it under 12 words.",
			'security' => "Begin the subtitle with \"Security update\" followed by a brief, non-alarming description of what was fixed. Keep it under 12 words.",
			default    => "Write a clear, descriptive subtitle summarising the most important change. Keep it under 12 words.",
		};

		return "The post title will be automatically formatted as: \"{$prefix}[your subtitle here]\"\n" .
			"Write ONLY the subtitle — do NOT include the plugin name or version number.\n" .
			$guidance;
	}

	// -------------------------------------------------------------------------
	// Content guidance
	// -------------------------------------------------------------------------

	/**
	 * Builds the content structure and tone instructions.
	 *
	 * @param string   $significance  Classified significance.
	 * @param string[] $images        Image URLs extracted from the release body.
	 * @param string   $download_link Resolved download / changelog link.
	 * @return string
	 */
	private function build_content_guidance( string $significance, array $images, string $download_link ): string {
		$tone = match ( $significance ) {
			'patch'    => "This is a maintenance release. Keep the tone practical and reassuring. A single clear paragraph is often enough.",
			'minor'    => "This release adds improvements. Write a short intro paragraph followed by a brief summary of what's new.",
			'major'    => "This is a significant release. Open with an engaging summary of what's changed and why it matters to the reader.",
			'security' => "This is a security release. Open with clear, calm language explaining that a security issue has been resolved. Avoid alarming language. Do not include technical exploit details.",
			default    => "Write a clear summary of what changed in this release.",
		};

		$structure = <<<EOT
CONTENT STRUCTURE:
1. Opening paragraph: plain-language summary of the release for non-technical site owners (required).
2. What's new: briefly summarise the main changes in plain language without developer jargon (required).
3. Developer notes (optional): if the release body contains API changes, hooks, deprecations, or database changes, add a clearly labelled section titled "For developers:" or "Developer notes:" that covers those details. Omit this section entirely if no developer-relevant content is present.
4. Call to action: include a natural, contextual sentence linking to the update or changelog using this URL: {$download_link}
   Phrase it contextually, for example: "Download the update from WordPress.org" or "See the full release notes on GitHub".

TONE: {$tone}

LENGTH: Scale content to match the release substance. A security patch or single-fix release may be one paragraph. A feature-rich release may use up to approximately 7 paragraphs. Do not pad thin releases to reach a minimum length, and do not truncate rich ones.
EOT;

		$structure .= "\n\n" . $this->build_image_instructions( $images );

		return $structure;
	}

	/**
	 * Builds image handling instructions for the prompt.
	 *
	 * If real images are present, instructs the AI to include them contextually.
	 * If no images exist, instructs the AI to suggest placeholder positions (AC-024–AC-026).
	 *
	 * @param string[] $images Extracted image URLs.
	 * @return string
	 */
	private function build_image_instructions( array $images ): string {
		if ( ! empty( $images ) ) {
			$image_list = implode( "\n", array_map( static fn( $url ) => "- {$url}", $images ) );
			return "IMAGES: The release body contains the following images. Include them contextually within the post using HTML <img> tags (not Markdown). Place them near the content they illustrate:\n{$image_list}";
		}

		return "IMAGES: No images are present in the release body. At one or two contextually appropriate points in the post, include a placeholder in the format: [Image suggestion: brief description of what screenshot would be useful here]. These placeholders help the site owner know where to add screenshots before publishing. Do NOT include placeholders in a security-only release.";
	}

	// -------------------------------------------------------------------------
	// Prompt assembly
	// -------------------------------------------------------------------------

	/**
	 * Assembles the full prompt string from its components.
	 *
	 * @param string $display_name
	 * @param string $tag
	 * @param string $significance
	 * @param string $body
	 * @param string $title_guidance
	 * @param string $content_guidance
	 * @param string $custom_instructions
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
			'patch'    => 'Patch release (maintenance / bug fixes)',
			'minor'    => 'Minor release (new features or improvements)',
			'major'    => 'Major release (significant new capability or breaking change)',
			'security' => 'Security release (vulnerability or security hardening)',
			default    => 'Release',
		};

		$prompt = <<<EOT
You are writing a blog post about a WordPress plugin update for the plugin's users.

RELEASE INFORMATION:
Plugin: {$display_name}
Version: {$tag}
Significance: {$significance_label}

Release body (raw changelog — may contain Markdown, developer jargon, or GitHub references):
---
{$body}
---

TITLE INSTRUCTIONS:
{$title_guidance}

{$content_guidance}

RESPONSE FORMAT:
- Line 1: Your subtitle ONLY (not the full title — just the subtitle portion).
- Line 2: Blank line.
- Line 3 onwards: The post body formatted as HTML.

Use HTML tags for formatting: <p>, <ul>, <li>, <ol>, <strong>, <em>.
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
	 * @param string $identifier owner/repo
	 * @return array<string, mixed> Repo config array, or empty array if not found.
	 */
	private function get_repo_config( string $identifier ): array {
		return $this->repo_settings->get_repository( $identifier );
	}

	/**
	 * Resolves the download / changelog link for a release.
	 *
	 * Priority: custom URL > WordPress.org > GitHub release URL (AC-019).
	 *
	 * @param array<string, mixed> $config Repo configuration.
	 * @param ReleaseData          $data   Release data.
	 * @return string
	 */
	public function resolve_download_link( array $config, ReleaseData $data ): string {
		if ( ! empty( $config['custom_url'] ) ) {
			return (string) $config['custom_url'];
		}

		if ( ! empty( $config['wporg_slug'] ) ) {
			return 'https://wordpress.org/plugins/' . rawurlencode( (string) $config['wporg_slug'] ) . '/';
		}

		return $data->html_url;
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

		// Markdown image syntax: ![alt text](url)
		if ( preg_match_all( '/!\[.*?\]\((https?:\/\/[^)]+)\)/', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		// HTML img tags: <img src="url" ...>
		if ( preg_match_all( '/<img[^>]+src=["\']?(https?:\/\/[^"\'>\s]+)["\']?/i', $body, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Derives a display name from a repo identifier when no display name is configured.
	 *
	 * @param string $identifier owner/repo
	 * @return string
	 */
	private function derive_display_name( string $identifier ): string {
		$parts = explode( '/', $identifier );
		$name  = end( $parts );
		return ucwords( str_replace( [ '-', '_' ], ' ', $name ) );
	}
}
