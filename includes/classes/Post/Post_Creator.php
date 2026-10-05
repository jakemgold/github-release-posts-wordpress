<?php
/**
 * Creates WordPress posts from AI-generated content.
 *
 * @package GitHubReleasePosts\Post
 */

namespace GitHubReleasePosts\Post;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\GitHub\Tag_Pattern_Matcher;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\GitHub\Release_Monitor;
use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Hooks into ghrp_post_generated and creates a WordPress post with source
 * attribution meta. Ensures idempotency: the same repo + tag combination
 * never produces duplicate posts.
 */
class Post_Creator {

	/**
	 * Constructor.
	 *
	 * @param Repository_Settings $repo_settings   Per-repo configuration (display name lookup).
	 * @param Global_Settings     $global_settings Site-wide settings (title format, etc.).
	 */
	public function __construct(
		private readonly Repository_Settings $repo_settings,
		private readonly Global_Settings $global_settings,
	) {}

	/**
	 * Registers the ghrp_post_generated action.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ghrp_post_generated', [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * Creates a WordPress post from AI-generated content.
	 *
	 * Checks idempotency first — if a post already exists for the given
	 * repo + tag, returns without creating a duplicate and WITHOUT firing the
	 * creation hooks: the existing post belongs to whichever request created
	 * it, and replaying this request's context onto it would let a cron run
	 * publish another admin's review draft, or a manual request un-publish a
	 * live post. Callers that need the post (the cron's post-creation check,
	 * the REST response) look it up themselves afterward.
	 *
	 * @param GeneratedPost $post    Generated post data (subtitle + HTML body).
	 * @param ReleaseData   $data    Source release data.
	 * @param array         $context Generation context flags.
	 * @return void
	 */
	public function handle( GeneratedPost $post, ReleaseData $data, array $context ): void {
		$bypass = ! empty( $context['bypass_idempotency'] );

		if ( ! $bypass ) {
			// The request-scoped find_post() memo may have recorded "no post"
			// BEFORE the AI call that led here — a minute or more ago. Another
			// worker (the client-side auto-generate racing the cron, or two
			// admins) can have inserted in the meantime, so the pre-insert
			// idempotency check must always hit the database.
			Release_Monitor::forget_post( $data->identifier, $data->tag );
			$existing_id = $this->find_existing_post( $data->identifier, $data->tag );

			if ( null !== $existing_id ) {
				// Someone else's post. Do not re-run publication, taxonomy, or
				// notification side effects against it — see the method docblock.
				return;
			}
		}

		$title          = self::build_title(
			$this->repo_settings->get_display_name( $data->identifier ),
			$data->tag,
			$post->title,
			$this->global_settings->get_title_format(),
			$data->identifier,
			$this->repo_uses_package_naming( $data->identifier )
		);
		$block_content  = $this->convert_html_to_blocks( $post->content );
		$block_content .= $this->build_disclosure_block( $data );
		$author_id      = $this->resolve_author( $data->identifier );
		$slug           = $this->build_slug( $data->identifier, $data->tag, $post->slug_keywords );

		// Defense-in-depth: KSES the AI-generated content before save. WordPress
		// normally runs wp_filter_post_kses on insert, but users with the
		// `unfiltered_html` capability (default for single-site admins) bypass
		// that filter. AI output is prompt-injectable through release notes
		// from any tracked repository, so we apply the same allowlist here
		// regardless of the current user's capability. `wp_kses_post` preserves
		// HTML comments, so the block-boundary markers (`<!-- wp:image ... -->`)
		// survive intact.
		$insert_args = [
			'post_title'   => wp_strip_all_tags( $title ),
			'post_content' => wp_kses_post( $block_content ),
			'post_status'  => 'draft',
			'post_type'    => 'post',
		];

		// Only set an explicit author when we resolved a real user; never write
		// post_author = 0 (an invalid user) on a site with no eligible admin.
		if ( $author_id > 0 ) {
			$insert_args['post_author'] = $author_id;
		}

		if ( '' !== $post->excerpt ) {
			$insert_args['post_excerpt'] = wp_kses_post( self::neutralize_ai_html( $post->excerpt ) );
		}

		if ( '' !== $slug ) {
			$insert_args['post_name'] = $slug;
		}

		// Honor an explicit post date passed in via context (used when manually
		// generating a post for an older release so the new draft does not appear
		// newer than later releases).
		if ( ! empty( $context['post_date_gmt'] ) ) {
			$insert_args['post_date_gmt'] = (string) $context['post_date_gmt'];
		}
		if ( ! empty( $context['post_date'] ) ) {
			$insert_args['post_date'] = (string) $context['post_date'];
		}

		// wp_insert_post() expects "slashed" input (it unslashes everything it
		// is handed before writing), so raw content would lose every backslash —
		// `namespace Foo\Bar` in a code sample saved as `namespace FooBar`.
		$post_id = wp_insert_post( wp_slash( $insert_args ), true );

		if ( is_wp_error( $post_id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[GHRP] Post creation failed for %s@%s: %s',
						$data->identifier,
						$data->tag,
						$post_id->get_error_message()
					)
				);
			}
			return;
		}

		$this->store_meta( $post_id, $data, $post->provider_slug );

		// Invalidate the find_post() cache so the cron pipeline's
		// post-creation confirmation observes the new post.
		Release_Monitor::forget_post( $data->identifier, $data->tag );

		// Sideload remote images into the WordPress media library.
		$this->sideload_images( $post_id );

		// Set featured image from per-repo config if configured.
		$this->set_featured_image( $post_id, $data->identifier );

		/**
		 * Fires when a post has been created for a release.
		 *
		 * @param int           $post_id  The WordPress post ID.
		 * @param GeneratedPost $post     The generated post data.
		 * @param ReleaseData   $data     The source release data.
		 * @param array         $context  Generation context flags.
		 */
		do_action( 'ghrp_post_created', $post_id, $post, $data, $context );
	}

	/**
	 * Finds an existing post for the given repo + tag combination.
	 *
	 * Checks all post statuses including trash (AC-006). Delegates to
	 * Release_Monitor::find_post so the request-scoped cache is shared
	 * with the cron pipeline's post-insertion confirmation lookup.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @param string $tag        Release tag.
	 * @return int|null Post ID if found, null otherwise.
	 */
	public function find_existing_post( string $identifier, string $tag ): ?int {
		$post = Release_Monitor::find_post( $identifier, $tag );
		return $post instanceof \WP_Post ? (int) $post->ID : null;
	}

	/**
	 * Stores source attribution meta on the post.
	 *
	 * @param int         $post_id       WordPress post ID.
	 * @param ReleaseData $data          Source release data.
	 * @param string      $provider_slug AI provider slug.
	 * @return void
	 */
	private function store_meta( int $post_id, ReleaseData $data, string $provider_slug ): void {
		update_post_meta( $post_id, Plugin_Constants::META_SOURCE_REPO, $data->identifier );
		update_post_meta( $post_id, Plugin_Constants::META_RELEASE_TAG, $data->tag );
		update_post_meta( $post_id, Plugin_Constants::META_RELEASE_URL, $data->html_url );
		update_post_meta( $post_id, Plugin_Constants::META_GENERATED_BY, $provider_slug );
	}

	/**
	 * Builds the AI disclosure paragraph block if enabled.
	 *
	 * Returns an empty string when the disclosure setting is off or
	 * when the filter returns an empty string.
	 *
	 * @param ReleaseData $data Release data.
	 * @return string Block markup or empty string.
	 */
	public static function build_disclosure_block( ReleaseData $data ): string {
		if ( ! get_option( Plugin_Constants::OPTION_AI_DISCLOSURE, false ) ) {
			return '';
		}

		$text = __( 'This post was generated from release notes with the help of AI using the Auto Release Posts for GitHub plugin for WordPress.', 'auto-release-posts-for-github' );

		/**
		 * Filters the AI disclosure text appended to generated posts.
		 *
		 * Return an empty string to suppress the disclosure for a specific post.
		 *
		 * @param string      $text The disclosure text.
		 * @param int         $post_id WordPress post ID (0 during initial creation).
		 * @param ReleaseData $data    Release data.
		 */
		$text = (string) apply_filters( 'ghrp_ai_disclosure_text', $text, 0, $data );

		if ( '' === $text ) {
			return '';
		}

		return "\n\n" . '<!-- wp:paragraph {"fontSize":"small","className":"ghrp-ai-disclosure"} -->' . "\n"
			. '<p class="has-small-font-size ghrp-ai-disclosure"><em>' . esc_html( $text ) . '</em></p>' . "\n"
			. '<!-- /wp:paragraph -->';
	}

	/**
	 * Resolves the post author for a repository.
	 *
	 * Uses the per-repo author if set and valid, otherwise falls back
	 * to the first site administrator.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return int WordPress user ID.
	 */
	private function resolve_author( string $identifier ): int {
		$config    = $this->repo_settings->get_repository( $identifier );
		$author_id = (int) ( $config['author'] ?? 0 );

		// Verify the stored author still exists and can edit posts.
		if ( $author_id > 0 ) {
			$user = get_userdata( $author_id );
			if ( $user && $user->has_cap( 'edit_posts' ) ) {
				return $author_id;
			}
		}

		// Fallback: first user with manage_options.
		$admins = get_users(
			[
				'capability' => 'manage_options',
				'number'     => 1,
				'orderby'    => 'ID',
				'order'      => 'ASC',
				'fields'     => 'ID',
			]
		);

		if ( ! empty( $admins ) ) {
			return (int) $admins[0];
		}

		// Last resort: user ID 1, only if it exists.
		$user_one = get_userdata( 1 );
		return $user_one ? 1 : 0;
	}

	/**
	 * Sets the featured image from the per-repo configuration.
	 *
	 * @param int    $post_id    WordPress post ID.
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return void
	 */
	private function set_featured_image( int $post_id, string $identifier ): void {
		$config        = $this->repo_settings->get_repository( $identifier );
		$attachment_id = (int) ( $config['featured_image'] ?? 0 );

		/**
		 * Filters the featured image attachment ID before it is set.
		 *
		 * Return 0 to skip setting a featured image.
		 *
		 * @param int    $attachment_id Attachment ID (0 = none).
		 * @param int    $post_id      WordPress post ID.
		 * @param string $identifier   Repository identifier.
		 */
		$attachment_id = (int) apply_filters( 'ghrp_post_featured_image', $attachment_id, $post_id, $identifier );

		// Only set the thumbnail when the attachment still exists and is an image —
		// a stale/deleted ID would otherwise point _thumbnail_id at nothing.
		if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * Renders a release tag in its display form.
	 *
	 * Monorepo package tags (e.g. "@headstartwp/core@1.6.1") read as code
	 * dumps when used verbatim — and the 'version' format's v-strip does
	 * nothing to them. When the repo uses package naming and the tag parses
	 * as a package release, this is the short package name + bare version
	 * ("core 1.6.1"); otherwise it is the tag with a trailing ".0" trimmed
	 * ("v1.2.0" → "v1.2"), exactly as before package support.
	 *
	 * Shared by the title/slug builders AND the prompt builder, so the prefix
	 * the AI is told about is the prefix that actually gets saved.
	 *
	 * @param string $tag            Release tag.
	 * @param bool   $package_naming Whether the repo uses package naming.
	 * @return string
	 */
	public static function display_tag( string $tag, bool $package_naming = false ): string {
		$parsed = Tag_Pattern_Matcher::derive_display_package( $tag, $package_naming );
		if ( null !== $parsed ) {
			return Tag_Pattern_Matcher::short_name( $parsed['package'] ) . ' ' . self::format_version_tag( $parsed['version'] );
		}

		return self::format_version_tag( $tag );
	}

	/**
	 * Builds the automatic title prefix for a release under a title format.
	 *
	 *  - 'full'    "{Display Name} {display tag} — "
	 *  - 'version' "Version {version} — " (leading 'v' stripped), or for a
	 *              package release "{Package} {version} — " — "Version 1.6.1"
	 *              alone is ambiguous across packages, so the package name
	 *              leads and is capitalized (npm names are ASCII-safe).
	 *  - 'none'    "" (the AI writes the whole title)
	 *
	 * @param string $display_name   Resolved repository display name.
	 * @param string $tag            Release tag.
	 * @param string $format         Title format: 'full', 'version', or 'none'.
	 * @param bool   $package_naming Whether the repo uses package naming.
	 * @return string Prefix including the trailing " — ", or '' for 'none'.
	 */
	public static function title_prefix( string $display_name, string $tag, string $format, bool $package_naming = false ): string {
		if ( 'none' === $format ) {
			return '';
		}

		$parsed = Tag_Pattern_Matcher::derive_display_package( $tag, $package_naming );
		if ( null !== $parsed ) {
			$package = Tag_Pattern_Matcher::short_name( $parsed['package'] );
			$version = self::format_version_tag( $parsed['version'] );

			return 'version' === $format
				? ucfirst( $package ) . ' ' . ltrim( $version, 'vV' ) . ' — '
				: "{$display_name} {$package} {$version} — ";
		}

		$tag = self::format_version_tag( $tag );

		return 'version' === $format
			? 'Version ' . ltrim( $tag, 'vV' ) . ' — '
			: "{$display_name} {$tag} — ";
	}

	/**
	 * Builds the full post title from already-resolved inputs.
	 *
	 *  - 'full'    "{Display Name} {tag} — {subtitle}"
	 *  - 'version' "Version {tag} — {subtitle}" (leading 'v' stripped)
	 *  - 'none'    "{ai-generated full title}" (no auto-prefix)
	 *
	 * Static so both the cron-pipeline path (Post_Creator::handle) and the
	 * editor "Regenerate" REST handler share the same format-aware assembly
	 * and fire the same ghrp_post_title filter. Callers resolve display name,
	 * format, etc. and pass them in — keeps this function a pure transformation.
	 *
	 * @param string $display_name Resolved repository display name.
	 * @param string $tag          Release tag (e.g. "v1.2.0").
	 * @param string $ai_title     AI-generated subtitle (or full title in 'none' mode).
	 * @param string $format       Title format: 'full', 'version', or 'none'.
	 * @param string $identifier        Repository identifier — passed through to the filter.
	 * @param bool   $package_naming Whether the repo uses package naming (packages chosen or multi-package topology observed)
	 *                                  (gates dash-style package display formatting).
	 * @return string Full post title.
	 */
	public static function build_title( string $display_name, string $tag, string $ai_title, string $format, string $identifier, bool $package_naming = false ): string {
		$prefix = self::title_prefix( $display_name, $tag, $format, $package_naming );
		$title  = '' === $prefix ? $ai_title : $prefix . $ai_title;
		$tag    = self::display_tag( $tag, $package_naming );

		/**
		 * Filters the full post title before it is saved.
		 *
		 * @param string $title       Final title built from the configured format.
		 * @param string $identifier  Repository identifier (owner/repo).
		 * @param string $tag         Formatted release tag.
		 * @param string $ai_title    AI-generated subtitle (or full title in 'none' mode).
		 * @param string $format      Active title format ('full', 'version', or 'none').
		 */
		return (string) apply_filters( 'ghrp_post_title', $title, $identifier, $tag, $ai_title, $format );
	}

	/**
	 * Builds an SEO-friendly post slug from the display name, version tag,
	 * and AI-generated slug keywords.
	 *
	 * Example: "ClassifAI", "v3.8.0", "ai-usage-tracking-security"
	 *       → "classifai-3-8-0-ai-usage-tracking-security"
	 *
	 * @param string $identifier    Repository identifier (owner/repo).
	 * @param string $tag           Release tag.
	 * @param string $slug_keywords AI-generated slug keywords.
	 * @return string Sanitized slug, or empty string if no keywords.
	 */
	/**
	 * Whether title/slug formatting may treat this repo's tags as package
	 * releases: packages chosen (or patterns supplied via filter), or a
	 * multi-package topology observed by the plugin. Single-package repos
	 * using package-shaped tags match neither signal and keep raw naming.
	 *
	 * @param string $identifier Repository identifier.
	 * @return bool
	 */
	private function repo_uses_package_naming( string $identifier ): bool {
		return ( new Release_State() )->uses_package_naming(
			$identifier,
			$this->repo_settings->get_effective_tag_patterns( $identifier )
		);
	}

	/**
	 * Builds an SEO-friendly post slug from the display name, version tag,
	 * and AI-generated slug keywords.
	 *
	 * @param string $identifier    Repository identifier (owner/repo).
	 * @param string $tag           Release tag.
	 * @param string $slug_keywords AI-generated slug keywords.
	 * @return string Sanitized slug, or empty string if no keywords.
	 */
	private function build_slug( string $identifier, string $tag, string $slug_keywords ): string {
		if ( '' === $slug_keywords ) {
			return '';
		}

		return self::build_release_slug(
			$this->repo_settings->get_display_name( $identifier ),
			$tag,
			$slug_keywords,
			$this->repo_uses_package_naming( $identifier )
		);
	}

	/**
	 * Builds the release post slug — shared by initial creation and editor
	 * regeneration so both produce the same URL shape.
	 *
	 * Package tags contribute their short name + bare version so monorepo
	 * slugs come out as "headstartwp-core-1-6-1-…" instead of a mush of the
	 * raw tag's @ and / characters. Package formatting applies only when the
	 * repo uses package naming (see repo_uses_package_naming()).
	 *
	 * @param string $display_name      Repository display name.
	 * @param string $tag               Release tag.
	 * @param string $slug_keywords     AI-generated slug keywords.
	 * @param bool   $package_naming Whether the repo uses package naming (packages chosen or multi-package topology observed).
	 * @return string Sanitized slug.
	 */
	public static function build_release_slug( string $display_name, string $tag, string $slug_keywords, bool $package_naming ): string {
		$parsed = Tag_Pattern_Matcher::derive_display_package( $tag, $package_naming );
		if ( null !== $parsed ) {
			$version  = str_replace( '.', '-', strtolower( $parsed['version'] ) );
			$raw_slug = $display_name . '-' . Tag_Pattern_Matcher::short_name( $parsed['package'] ) . '-' . $version . '-' . $slug_keywords;
			return sanitize_title( $raw_slug );
		}

		// Strip 'v' prefix and dots → hyphens for the version.
		$version = strtolower( ltrim( $tag, 'vV' ) );
		$version = str_replace( '.', '-', $version );

		$raw_slug = $display_name . '-' . $version . '-' . $slug_keywords;
		return sanitize_title( $raw_slug );
	}

	/**
	 * Formats a version tag for display by removing a trailing .0 patch version.
	 *
	 * Examples: "v2.2.0" → "v2.2", "2.2.0" → "2.2", "v3.0.1" → "v3.0.1", "v1.0.0" → "v1.0".
	 *
	 * @param string $tag Raw release tag.
	 * @return string Formatted tag.
	 */
	public static function format_version_tag( string $tag ): string {
		return preg_replace( '/^(v?\d+\.\d+)\.0$/', '$1', $tag );
	}

	/**
	 * Deepest nesting of lists or quotes converted to native blocks; deeper
	 * structures are kept as HTML. Bounds the recursion on hostile input.
	 */
	private const MAX_NESTING = 16;

	/**
	 * Opening tag of an element that becomes a block of its own. Quoted
	 * attribute values are consumed as units, so a `>` inside alt text does
	 * not end the tag early.
	 */
	private const BLOCK_START_TAG = '%<(hr|img|p|ul|ol|h[1-6]|blockquote|pre|table)(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>%i';

	/**
	 * HTML element names. A "<" followed by anything else is text.
	 *
	 * @var string[]
	 */
	private const HTML_ELEMENT_NAMES = [ 'a', 'abbr', 'acronym', 'address', 'area', 'article', 'aside', 'audio', 'b', 'base', 'bdi', 'bdo', 'big', 'blockquote', 'body', 'br', 'button', 'canvas', 'caption', 'center', 'cite', 'code', 'col', 'colgroup', 'data', 'datalist', 'dd', 'del', 'details', 'dfn', 'dialog', 'dir', 'div', 'dl', 'dt', 'em', 'embed', 'fieldset', 'figcaption', 'figure', 'font', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'hgroup', 'hr', 'html', 'i', 'iframe', 'img', 'input', 'ins', 'kbd', 'label', 'legend', 'li', 'link', 'main', 'map', 'mark', 'marquee', 'math', 'menu', 'meta', 'meter', 'nav', 'noscript', 'object', 'ol', 'optgroup', 'option', 'output', 'p', 'param', 'picture', 'pre', 'progress', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'script', 'search', 'section', 'select', 'slot', 'small', 'source', 'span', 'strike', 'strong', 'style', 'sub', 'summary', 'sup', 'svg', 'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr', 'track', 'tt', 'u', 'ul', 'var', 'video', 'wbr' ];

	/**
	 * Neutralizes text in AI-written HTML that WordPress would otherwise
	 * read as markup. Applied to the post body and the excerpt.
	 *
	 * - A "<" that does not open an HTML element is text: "PHP < 8.2",
	 *   "array<int, string>", "<?php". KSES deletes everything from such a
	 *   "<" to the next ">", so "Requires PHP < 8.2 or WordPress >= 6.0" was
	 *   saved as "Requires PHP = 6.0".
	 * - HTML comments are dropped. The model never needs one, and a block
	 *   delimiter smuggled in through release notes (<!-- wp:rss {…} /-->)
	 *   would otherwise render as a live dynamic block with its attributes.
	 * - "[" is encoded, so a shortcode quoted in release notes displays as
	 *   text instead of running on the site.
	 *
	 * @param string $html AI-written HTML or text.
	 * @return string
	 */
	public static function neutralize_ai_html( string $html ): string {
		$html = preg_replace( '/<!--.*?-->/s', '', $html ) ?? $html;
		$html = preg_replace_callback(
			'%</?([a-zA-Z][a-zA-Z0-9-]*)?%',
			static fn( array $lt ): string => in_array( strtolower( $lt[1] ?? '' ), self::HTML_ELEMENT_NAMES, true ) ? $lt[0] : '&lt;' . substr( $lt[0], 1 ),
			$html
		) ?? $html;

		return str_replace( '[', '&#91;', $html );
	}

	/**
	 * Converts HTML content into Gutenberg block markup.
	 *
	 * Wraps top-level HTML elements in their corresponding block comments,
	 * emitting the same markup the block editor itself saves, so the post
	 * opens without "unexpected or invalid content" warnings.
	 *
	 * @param string $html Raw HTML content from the AI provider.
	 * @return string Block-formatted content.
	 */
	public static function convert_html_to_blocks( string $html ): string {
		$html = trim( self::neutralize_ai_html( $html ) );
		if ( '' === $html ) {
			return '';
		}

		// Strip any <p> wrappers around <figure> elements — AI models sometimes
		// nest block-level elements inside <p> tags, which breaks splitting.
		// Fall back to the prior value on a null return (PCRE backtrack/recursion
		// limit on very large input) so a big release can't silently collapse the
		// whole post body to nothing.
		$html = preg_replace( '%<p>\s*(<figure[\s>].*?</figure>)\s*</p>%si', '$1', $html ) ?? $html;

		// Extract <figure> blocks first (they can contain nested elements
		// like <figcaption> that confuse the simpler tag-based splitter).
		$figure_placeholders = [];
		$html                = preg_replace_callback(
			'%<figure[\s>].*?</figure>%si',
			function ( $matches ) use ( &$figure_placeholders ) {
				$key                         = '<!--GHRP_FIGURE_' . count( $figure_placeholders ) . '-->';
				$figure_placeholders[ $key ] = $matches[0];
				return $key;
			},
			$html
		) ?? $html;

		$blocks = self::html_to_blocks( $html, $figure_placeholders );

		// A figure nested inside another element (a list item, say) stays in
		// place as plain markup rather than vanishing as a placeholder comment.
		return strtr( implode( "\n\n", $blocks ), $figure_placeholders );
	}

	/**
	 * Converts an HTML fragment into a list of serialized blocks.
	 *
	 * @param string                $html                HTML fragment.
	 * @param array<string, string> $figure_placeholders Placeholder comment => original <figure> HTML.
	 * @param int                   $depth               Nesting depth (quotes within quotes).
	 * @return string[]
	 */
	private static function html_to_blocks( string $html, array $figure_placeholders, int $depth = 0 ): array {
		$blocks = [];

		foreach ( self::split_top_level( $html ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			if ( preg_match( '/^<(p|ul|ol|h[1-6]|blockquote|img|hr|pre|table)(?=[\s\/>])/i', $part, $tag_match ) ) {
				$tag = strtolower( $tag_match[1] );

				// A paragraph the model wrapped around block-level markup
				// (<p><ul>…</ul></p>) is not a paragraph: convert what it holds.
				if ( 'p' === $tag ) {
					$inner = self::element_inner( $part, 'p' );
					if ( preg_match( '/<(?:p|ul|ol|h[1-6]|blockquote|pre|table)(?=[\s\/>])|<!--GHRP_FIGURE_/i', $inner ) ) {
						array_push( $blocks, ...self::html_to_blocks( $inner, $figure_placeholders, $depth ) );
						continue;
					}
				}

				$blocks[] = 'blockquote' === $tag
					? self::build_quote_block( $part, $figure_placeholders, $depth )
					: self::wrap_in_block( $tag, $part );
				continue;
			}

			// Restore figure placeholders. A single part may contain multiple
			// adjacent placeholders (e.g. two <figure> elements with no content
			// between them), so split on each one individually.
			if ( ! empty( $figure_placeholders ) && str_contains( $part, '<!--GHRP_FIGURE_' ) ) {
				$sub_parts = preg_split(
					'/(<!--GHRP_FIGURE_\d+-->)/',
					$part,
					-1,
					PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
				);

				foreach ( $sub_parts as $sub ) {
					$sub = trim( $sub );
					if ( '' === $sub ) {
						continue;
					}
					if ( isset( $figure_placeholders[ $sub ] ) ) {
						$blocks[] = self::wrap_in_block( 'figure', $figure_placeholders[ $sub ] );
					} else {
						$blocks[] = "<!-- wp:paragraph -->\n<p>" . $sub . "</p>\n<!-- /wp:paragraph -->";
					}
				}
				continue;
			}

			// Leftover text — wrap as paragraph.
			$blocks[] = "<!-- wp:paragraph -->\n<p>" . $part . "</p>\n<!-- /wp:paragraph -->";
		}

		return $blocks;
	}

	/**
	 * Splits HTML into its top-level block elements and the text between them.
	 *
	 * Each container element runs to its own closing tag, counting nested
	 * elements of the same name. (A single regex used to end an element at
	 * the first closing tag of any container, so a nested list, a <p> inside
	 * a <blockquote>, or code inside a list item cut the block in half and
	 * left the rest of it behind as a broken paragraph.) Void elements —
	 * <hr>, <img> — are a single tag.
	 *
	 * @param string $html HTML fragment.
	 * @return string[] Elements and the text between them, in order.
	 */
	private static function split_top_level( string $html ): array {
		$parts      = [];
		$offset     = 0;
		$length     = strlen( $html );
		$last_close = [];

		while ( $offset < $length && preg_match( self::BLOCK_START_TAG, $html, $start, PREG_OFFSET_CAPTURE, $offset ) ) {
			$tag_offset = (int) $start[0][1];
			$tag        = strtolower( $start[1][0] );
			$body_start = $tag_offset + strlen( $start[0][0] );

			if ( $tag_offset > $offset ) {
				$parts[] = substr( $html, $offset, $tag_offset - $offset );
			}

			if ( 'hr' === $tag || 'img' === $tag ) {
				$parts[] = $start[0][0];
				$offset  = $body_start;
				continue;
			}

			// With no closing tag of this name anywhere after the element, skip
			// the scan: repeating it for every unclosed element made a run of
			// them quadratic.
			$last_close[ $tag ] ??= strripos( $html, '</' . $tag );
			$close                = false === $last_close[ $tag ] || $last_close[ $tag ] < $body_start
				? null
				: self::find_closing_tag( $html, $tag, $body_start );

			if ( null === $close ) {
				// Never closed: as an HTML parser would, end it where the next
				// block element starts (or at the end), and close it there.
				$end     = preg_match( self::BLOCK_START_TAG, $html, $next, PREG_OFFSET_CAPTURE, $body_start ) ? (int) $next[0][1] : $length;
				$parts[] = rtrim( substr( $html, $tag_offset, $end - $tag_offset ) ) . '</' . $tag . '>';
				$offset  = $end;
				continue;
			}

			$parts[] = substr( $html, $tag_offset, $close[1] - $tag_offset );
			$offset  = $close[1];
		}

		if ( $offset < $length ) {
			$parts[] = substr( $html, $offset );
		}

		return $parts;
	}

	/**
	 * Finds the closing tag that matches an element's opening tag, counting
	 * nested elements of the same name. A <p> cannot contain another <p>, so
	 * its first closing tag always ends it.
	 *
	 * @param string $html HTML being scanned.
	 * @param string $tag  Lowercase element name.
	 * @param int    $from Offset just past the element's opening tag.
	 * @return array{0: int, 1: int}|null Start and end offsets of the closing
	 *                                    tag, or null when it is never closed.
	 */
	private static function find_closing_tag( string $html, string $tag, int $from ): ?array {
		$pattern = '%<(/?)' . $tag . '(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>%i';
		$depth   = 1;

		while ( preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE, $from ) ) {
			$match_start = (int) $match[0][1];
			$from        = $match_start + strlen( $match[0][0] );

			if ( '/' === $match[1][0] ) {
				--$depth;
				if ( 0 === $depth ) {
					return [ $match_start, $from ];
				}
			} elseif ( 'p' !== $tag ) {
				++$depth;
			}
		}

		return null;
	}

	/**
	 * Returns an element's opening tag.
	 *
	 * @param string $html Element HTML, starting with its opening tag.
	 * @param string $tag  Lowercase element name.
	 * @return string
	 */
	private static function opening_tag( string $html, string $tag ): string {
		return preg_match( '%^<' . $tag . '(?:[^>"\']|"[^"]*"|\'[^\']*\')*>%i', $html, $open ) ? $open[0] : '';
	}

	/**
	 * Returns what lies between an element's opening tag and its final
	 * closing tag.
	 *
	 * @param string $html Element HTML, starting with its opening tag.
	 * @param string $tag  Lowercase element name.
	 * @return string
	 */
	private static function element_inner( string $html, string $tag ): string {
		$inner = substr( $html, strlen( self::opening_tag( $html, $tag ) ) );
		$close = strripos( $inner, '</' . $tag );

		return false === $close ? $inner : substr( $inner, 0, $close );
	}

	/**
	 * Wraps markup that no native block can represent faithfully in an HTML
	 * block, unchanged.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function html_block( string $html ): string {
		return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
	}

	/**
	 * Wraps a single HTML element in its corresponding block comment.
	 *
	 * @param string $tag  The lowercase tag name.
	 * @param string $html The full HTML element.
	 * @return string Block-wrapped markup.
	 */
	private static function wrap_in_block( string $tag, string $html ): string {
		return match ( $tag ) {
			'p'                                => "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->",
			'ul', 'ol'                         => self::build_list_block( $html ) ?? self::html_block( $html ),
			'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => self::build_heading_block( $tag, $html ),
			'blockquote'                       => self::build_quote_block( $html, [] ),
			'figure'                           => self::wrap_figure_block( $html ),
			'img'                              => self::wrap_img_block( $html ),
			// A complete separator block: the bare opener that used to be
			// emitted here had no closer, so the block parser nested every
			// following block inside an invalid separator.
			'hr'                               => "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->",
			'pre'                              => self::build_pre_block( $html ),
			// Auto layout keeps the table as the model wrote it; the block's
			// default (fixed layout) would require a class it does not have.
			'table'                            => "<!-- wp:table {\"hasFixedLayout\":false} -->\n<figure class=\"wp-block-table\">{$html}</figure>\n<!-- /wp:table -->",
			default                            => self::html_block( $html ),
		};
	}

	/**
	 * Builds a core/heading block. An id is kept as the heading's anchor, so
	 * in-post links to it keep working.
	 *
	 * @param string $tag  Heading tag, h1–h6.
	 * @param string $html The full heading element.
	 * @return string
	 */
	private static function build_heading_block( string $tag, string $html ): string {
		$level  = (int) substr( $tag, 1 );
		$anchor = preg_match( '/\sid\s*=\s*(["\']?)([\w:.-]+)\1(?=[\s\/>])/i', self::opening_tag( $html, $tag ), $id ) ? $id[2] : '';

		$attrs = [];
		if ( 2 !== $level ) {
			$attrs[] = '"level":' . $level;
		}
		if ( '' !== $anchor ) {
			$attrs[] = '"anchor":"' . $anchor . '"';
		}

		$comment_attrs = empty( $attrs ) ? '' : ' {' . implode( ',', $attrs ) . '}';
		$id_attr       = '' === $anchor ? '' : ' id="' . $anchor . '"';
		$inner         = trim( self::element_inner( $html, $tag ) );

		return "<!-- wp:heading{$comment_attrs} -->\n<{$tag}{$id_attr} class=\"wp-block-heading\">{$inner}</{$tag}>\n<!-- /wp:heading -->";
	}

	/**
	 * Builds a core/list block: each <li> becomes a core/list-item block, and
	 * a list nested inside an item becomes a list block inside that item —
	 * the shape the editor itself saves.
	 *
	 * Returns null when the list cannot be represented that way without
	 * changing what it says — numbering set per item (<li value>), a list
	 * style (type), text after a nested list, block markup inside an item —
	 * so the caller keeps it as HTML instead of reordering or dropping it.
	 *
	 * @param string $html  The full <ul> or <ol> element.
	 * @param int    $depth Nesting depth.
	 * @return string|null
	 */
	private static function build_list_block( string $html, int $depth = 0 ): ?string {
		$tag  = 0 === stripos( $html, '<ol' ) ? 'ol' : 'ul';
		$open = self::opening_tag( $html, $tag );

		if ( $depth >= self::MAX_NESTING || preg_match( '/\stype\s*=/i', $open ) ) {
			return null;
		}

		$attrs     = [];
		$tag_attrs = '';
		if ( 'ol' === $tag ) {
			$attrs[] = '"ordered":true';
			$start   = preg_match( '/\sstart\s*=\s*["\']?(-?\d+)/i', $open, $start_match ) ? (int) $start_match[1] : null;
			if ( null !== $start ) {
				$attrs[] = '"start":' . $start;
			}
			if ( preg_match( '/\sreversed(?=[\s=\/>])/i', $open ) ) {
				$attrs[]    = '"reversed":true';
				$tag_attrs .= ' reversed';
			}
			if ( null !== $start ) {
				$tag_attrs .= ' start="' . $start . '"';
			}
		}

		$items = [];
		foreach ( self::split_list_items( self::element_inner( $html, $tag ) ) as [ $item_open, $content ] ) {
			if ( preg_match( '/\svalue\s*=/i', $item_open ) ) {
				return null;
			}
			$item = self::build_list_item_block( $content, $depth );
			if ( null === $item ) {
				return null;
			}
			$items[] = $item;
		}

		$comment_attrs = empty( $attrs ) ? '' : ' {' . implode( ',', $attrs ) . '}';

		return "<!-- wp:list{$comment_attrs} -->\n<{$tag}{$tag_attrs} class=\"wp-block-list\">"
			. implode( "\n\n", $items )
			. "</{$tag}>\n<!-- /wp:list -->";
	}

	/**
	 * Splits a list's inner HTML into its items. Stray non-whitespace text
	 * between items becomes an item of its own.
	 *
	 * @param string $inner Inner HTML of a <ul> or <ol>.
	 * @return array<int, array{0: string, 1: string}> Each item's opening tag ('' for stray text) and inner HTML.
	 */
	private static function split_list_items( string $inner ): array {
		$items  = [];
		$offset = 0;
		$length = strlen( $inner );

		while ( $offset < $length && preg_match( '%<li(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>%i', $inner, $open, PREG_OFFSET_CAPTURE, $offset ) ) {
			$stray = trim( substr( $inner, $offset, (int) $open[0][1] - $offset ) );
			if ( '' !== $stray ) {
				$items[] = [ '', $stray ];
			}

			$body_start = (int) $open[0][1] + strlen( $open[0][0] );
			$close      = self::find_closing_tag( $inner, 'li', $body_start );

			if ( null === $close ) {
				// </li> is optional in HTML: the item runs to the next one.
				$end     = preg_match( '%<li(?=[\s/>])%i', $inner, $next, PREG_OFFSET_CAPTURE, $body_start ) ? (int) $next[0][1] : $length;
				$items[] = [ $open[0][0], substr( $inner, $body_start, $end - $body_start ) ];
				$offset  = $end;
				continue;
			}

			$items[] = [ $open[0][0], substr( $inner, $body_start, $close[0] - $body_start ) ];
			$offset  = $close[1];
		}

		$stray = trim( substr( $inner, $offset ) );
		if ( '' !== $stray ) {
			$items[] = [ '', $stray ];
		}

		return $items;
	}

	/**
	 * Builds a core/list-item block from an item's inner HTML: its text, then
	 * any nested lists as inner list blocks. Paragraphs and code blocks in
	 * the text become line-separated inline content, in order.
	 *
	 * Returns null when the item cannot be represented that way without
	 * changing what it says: text after a nested list (a list item saves its
	 * text before its nested lists), or block markup a list item cannot hold.
	 *
	 * @param string $content Inner HTML of an <li>.
	 * @param int    $depth   Nesting depth of the list holding the item.
	 * @return string|null
	 */
	private static function build_list_item_block( string $content, int $depth ): ?string {
		$text   = '';
		$nested = [];
		$offset = 0;
		$length = strlen( $content );

		while ( $offset < $length && preg_match( '%<(ul|ol)(?=[\s/>])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>%i', $content, $open, PREG_OFFSET_CAPTURE, $offset ) ) {
			$list_start = (int) $open[0][1];
			$list_tag   = strtolower( $open[1][0] );
			$before     = substr( $content, $offset, $list_start - $offset );

			if ( ! empty( $nested ) && '' !== trim( $before ) ) {
				return null;
			}
			$text .= $before;

			$close    = self::find_closing_tag( $content, $list_tag, $list_start + strlen( $open[0][0] ) );
			$list_end = null === $close ? $length : $close[1];
			$list     = substr( $content, $list_start, $list_end - $list_start );
			if ( null === $close ) {
				$list .= '</' . $list_tag . '>';
			}

			$block = self::build_list_block( $list, $depth + 1 );
			if ( null === $block ) {
				return null;
			}
			$nested[] = $block;
			$offset   = $list_end;
		}

		$after = substr( $content, $offset );
		if ( ! empty( $nested ) && '' !== trim( $after ) ) {
			return null;
		}
		$text .= $after;

		// Paragraph and code-block boundaries become line breaks, so text on
		// either side never runs together. A placeholder marks them until
		// the end, so line breaks the model wrote itself are left alone.
		$break = "\x1F";
		$text  = preg_replace_callback(
			'%<pre(?:\s[^>]*)?>\s*(?:<code(?:\s[^>]*)?>)?(.*?)(?:</code>)?\s*</pre>%is',
			static fn( array $code ): string => $break . '<code>' . str_replace( "\n", '<br>', trim( $code[1] ) ) . '</code>' . $break,
			$text
		) ?? $text;
		$text  = preg_replace( '%</?p(?:\s[^>]*)?>%i', $break, $text ) ?? $text;

		if ( preg_match( '/<(?:blockquote|table|h[1-6]|div|figure|hr|dl|details|section|article|aside|header|footer|nav|form)(?=[\s\/>])/i', $text ) ) {
			return null;
		}

		$text = preg_replace( '/\s*' . $break . '[\s' . $break . ']*/', $break, trim( $text ) ) ?? $text;
		$text = str_replace( $break, '<br>', trim( $text, $break ) );

		return "<!-- wp:list-item -->\n<li>" . trim( $text ) . implode( "\n\n", $nested ) . "</li>\n<!-- /wp:list-item -->";
	}

	/**
	 * Builds a core/quote block, converting its contents into inner blocks
	 * (bare text becomes a paragraph).
	 *
	 * @param string                $html                The full <blockquote> element.
	 * @param array<string, string> $figure_placeholders Placeholder comment => original <figure> HTML.
	 * @param int                   $depth               Nesting depth.
	 * @return string
	 */
	private static function build_quote_block( string $html, array $figure_placeholders, int $depth = 0 ): string {
		if ( $depth >= self::MAX_NESTING ) {
			return self::html_block( $html );
		}

		$inner_blocks = self::html_to_blocks( self::element_inner( $html, 'blockquote' ), $figure_placeholders, $depth + 1 );

		return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\">" . implode( "\n\n", $inner_blocks ) . "</blockquote>\n<!-- /wp:quote -->";
	}

	/**
	 * Builds a core/code block from <pre><code>, or a core/preformatted block
	 * from a bare <pre>. Attributes on the elements (a language class, say)
	 * are dropped — neither block can store them.
	 *
	 * @param string $html The full <pre> element.
	 * @return string
	 */
	private static function build_pre_block( string $html ): string {
		$inner = self::element_inner( $html, 'pre' );

		if ( preg_match( '%^\s*<code(?:\s[^>]*)?>(.*)</code>\s*$%is', $inner, $code ) ) {
			return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>{$code[1]}</code></pre>\n<!-- /wp:code -->";
		}

		return "<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">{$inner}</pre>\n<!-- /wp:preformatted -->";
	}

	/**
	 * Wraps a <figure> element as a Gutenberg image block.
	 *
	 * Ensures the <figure> has the required `wp-block-image` class and
	 * determines whether it contains an image (wp:image) or should fall
	 * back to a generic HTML block. Uses DOMDocument for attribute and
	 * caption extraction so attribute order, quote style, escaped quotes,
	 * and nested elements inside <figcaption> can't mis-match a regex.
	 *
	 * @param string $html The full <figure> HTML element.
	 * @return string Block-wrapped markup.
	 */
	private static function wrap_figure_block( string $html ): string {
		$figure_node = self::parse_single_element( $html, 'figure' );
		if ( null === $figure_node ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		$img_nodes = $figure_node->getElementsByTagName( 'img' );
		if ( 0 === $img_nodes->length ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		$img = $img_nodes->item( 0 );
		$src = (string) $img->getAttribute( 'src' );
		$alt = (string) $img->getAttribute( 'alt' );

		if ( '' === $src ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		// Extract figcaption inner HTML (preserves nested markup).
		$caption   = '';
		$cap_nodes = $figure_node->getElementsByTagName( 'figcaption' );
		if ( $cap_nodes->length > 0 ) {
			$caption = trim( self::inner_html( $cap_nodes->item( 0 ) ) );
		}

		return self::build_image_block( $src, $alt, $caption );
	}

	/**
	 * Wraps a standalone <img> element as a Gutenberg image block.
	 *
	 * @param string $html The <img> HTML element.
	 * @return string Block-wrapped markup.
	 */
	private static function wrap_img_block( string $html ): string {
		$img_node = self::parse_single_element( $html, 'img' );
		if ( null === $img_node ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		$src = (string) $img_node->getAttribute( 'src' );
		$alt = (string) $img_node->getAttribute( 'alt' );

		if ( '' === $src ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		return self::build_image_block( $src, $alt, '' );
	}

	/**
	 * Builds the canonical wp:image block markup from parsed attributes.
	 *
	 * Rebuilding from scratch (rather than mutating the input HTML) is what
	 * lets us satisfy Gutenberg's block-validation, which compares the saved
	 * markup to a re-rendered serialization byte-for-byte.
	 *
	 * @param string $src     Image URL.
	 * @param string $alt     Alt text (may be empty).
	 * @param string $caption Inner HTML of the figcaption (may be empty).
	 * @return string
	 */
	private static function build_image_block( string $src, string $alt, string $caption ): string {
		$figure = '<figure class="wp-block-image size-full">'
			. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" />';

		if ( '' !== $caption ) {
			// Sanitize to the post allow-list so a caption from AI/release notes
			// can't inject markup, and so the saved markup matches what KSES leaves
			// at insert time (otherwise the block trips Gutenberg's validation).
			$figure .= '<figcaption class="wp-element-caption">' . wp_kses_post( $caption ) . '</figcaption>';
		}

		$figure .= '</figure>';

		return "<!-- wp:image {\"sizeSlug\":\"full\"} -->\n{$figure}\n<!-- /wp:image -->";
	}

	/**
	 * Parses a single-element HTML fragment and returns the matching node.
	 *
	 * Wraps the fragment in a synthetic root so DOMDocument doesn't inject
	 * <html><body>. Returns null if no element of the expected tag is found,
	 * letting callers fall back to a generic HTML block.
	 *
	 * @param string $html        HTML fragment expected to contain a single root element.
	 * @param string $expected_tag Tag name to locate (e.g. 'figure', 'img').
	 * @return \DOMElement|null
	 */
	private static function parse_single_element( string $html, string $expected_tag ): ?\DOMElement {
		$doc      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );

		// Force UTF-8 — DOMDocument defaults to ISO-8859-1.
		$wrapped = '<?xml encoding="UTF-8"?><div>' . $html . '</div>';
		$loaded  = $doc->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$nodes = $doc->getElementsByTagName( $expected_tag );
		if ( 0 === $nodes->length ) {
			return null;
		}

		$node = $nodes->item( 0 );
		return $node instanceof \DOMElement ? $node : null;
	}

	/**
	 * Returns the concatenated inner HTML of a DOM node.
	 *
	 * @param \DOMNode $node Parent node.
	 * @return string
	 */
	private static function inner_html( \DOMNode $node ): string {
		$html = '';
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument native property names.
		foreach ( $node->childNodes as $child ) {
			$html .= $node->ownerDocument->saveHTML( $child );
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		return $html;
	}

	/**
	 * Finds remote images in post content, sideloads them into the media library,
	 * and replaces the remote URLs with local attachment URLs.
	 *
	 * @param int $post_id WordPress post ID to attach media to.
	 * @return void
	 */
	public static function sideload_images( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$content = $post->post_content;

		// Find all <img> tags with remote src URLs.
		if ( ! preg_match_all( '/<img[^>]+src=["\']?(https?:\/\/[^"\'>\s]+)["\']?/i', $content, $matches ) ) {
			return;
		}

		// WordPress media handling functions.
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$urls     = array_unique( $matches[1] );
		$site_url = get_site_url();
		$failed   = 0;
		$total    = 0;

		$allowed_domains = (array) apply_filters(
			'ghrp_sideload_allowed_domains',
			[
				'github.com',
				'githubusercontent.com',
				'github.io',
			]
		);

		// Bounds to keep a single request from runaway sideloading. A release with
		// dozens of screenshots, or a flaky origin, could otherwise exceed PHP
		// max_execution_time. Remaining images are left pointing at remote URLs.
		$max_images          = (int) apply_filters( 'ghrp_max_sideload_images', 20 );
		$time_budget         = (int) apply_filters( 'ghrp_sideload_time_budget', 30 );
		$max_consec_failures = (int) apply_filters( 'ghrp_sideload_max_consecutive_failures', 3 );
		$request_timeout     = (int) apply_filters( 'ghrp_sideload_request_timeout', 15 );

		$started         = microtime( true );
		$consec_failures = 0;
		$bail_reason     = '';

		// Apply a per-image HTTP timeout via http_request_args. WP's default
		// download_url() timeout is 300s, which is too long for a synchronous loop.
		$timeout_filter = function ( $args ) use ( $request_timeout ) {
			$args['timeout'] = $request_timeout;
			// The final URL is pre-resolved and host-validated per hop (see
			// resolve_allowed_image_url), so the download itself must not follow
			// any further redirect off the allow-list.
			$args['redirection'] = 0;
			return $args;
		};
		add_filter( 'http_request_args', $timeout_filter );

		foreach ( $urls as $remote_url ) {
			// Skip URLs already pointing to this site.
			if ( str_starts_with( $remote_url, $site_url ) ) {
				continue;
			}

			// Only sideload from allowed domains to prevent SSRF.
			$host = wp_parse_url( $remote_url, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! self::is_host_allowed( strtolower( $host ), $allowed_domains ) ) {
				continue;
			}

			// Stop if we've hit the image cap.
			if ( $total >= $max_images ) {
				$bail_reason = sprintf( 'image cap (%d) reached', $max_images );
				break;
			}

			// Stop if we've exceeded the time budget.
			if ( ( microtime( true ) - $started ) >= $time_budget ) {
				$bail_reason = sprintf( 'time budget (%ds) exceeded', $time_budget );
				break;
			}

			++$total;

			// Resolve redirects up front, requiring every hop to stay on the
			// allow-list. media_sideload_image()/download_url() otherwise follow
			// redirects with only WordPress's generic private-IP blocking, which
			// leaves the link-local metadata range reachable via a redirect from
			// an allowed *.github.io Pages site. A rejected image simply stays a
			// remote link, like any other image we can't sideload.
			$safe_url = self::resolve_allowed_image_url( $remote_url, $allowed_domains, $request_timeout );
			if ( is_wp_error( $safe_url ) ) {
				++$failed;
				++$consec_failures;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[GHRP] Image skipped (redirect/host not allowed) for %s: %s',
							$remote_url,
							$safe_url->get_error_message()
						)
					);
				}
				if ( $consec_failures >= $max_consec_failures ) {
					$bail_reason = sprintf( '%d consecutive failures', $consec_failures );
					break;
				}
				continue;
			}

			$attachment_id = media_sideload_image( $safe_url, $post_id, '', 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				++$failed;
				++$consec_failures;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[GHRP] Image sideload failed for %s: %s',
							$remote_url,
							$attachment_id->get_error_message()
						)
					);
				}

				// Bail if the origin looks broken — don't burn time on the rest.
				if ( $consec_failures >= $max_consec_failures ) {
					$bail_reason = sprintf( '%d consecutive failures', $consec_failures );
					break;
				}
				continue;
			}

			$consec_failures = 0;

			$local_url = wp_get_attachment_url( $attachment_id );
			if ( $local_url ) {
				$img_class  = 'wp-image-' . $attachment_id;
				$quoted_old = preg_quote( $remote_url, '/' );

				// 1. Update wp:image block comments BEFORE replacing URLs,
				// while we can still match the remote URL in context.
				$content = preg_replace(
					'/(<!-- wp:image)\s*(\{[^}]*\})?\s*(-->)(\s*<figure[^>]*>(?:\s*<a[^>]*>)?\s*<img[^>]*' . $quoted_old . ')/i',
					'$1 {"id":' . $attachment_id . ',"sizeSlug":"full"} $3$4',
					$content
				);

				// 2. Replace the remote URL with the local one everywhere.
				$content = str_replace( $remote_url, $local_url, $content );

				// 3. Add wp-image-{id} class to the <img> tag.
				$quoted_new = preg_quote( $local_url, '/' );
				if ( preg_match( '/<img[^>]*src=["\']' . $quoted_new . '["\'][^>]*class=["\']/', $content ) ) {
					$content = preg_replace(
						'/(<img[^>]*src=["\']' . $quoted_new . '["\'][^>]*class=["\'])([^"\']*)/i',
						'$1$2 ' . $img_class,
						$content
					);
				} else {
					$content = preg_replace(
						'/(<img[^>]*src=["\']' . $quoted_new . '["\'][^>]*)(\s*\/?>)/i',
						'$1 class="' . $img_class . '"$2',
						$content
					);
				}
			}
		}

		remove_filter( 'http_request_args', $timeout_filter );

		// Update the post with local image URLs. Re-apply KSES even though
		// the sideload replacement only rewrites image URLs — defense in depth
		// for the unfiltered_html admin case (see KSES note in create()).
		if ( $content !== $post->post_content ) {
			wp_update_post(
				wp_slash(
					[
						'ID'           => $post_id,
						'post_content' => wp_kses_post( $content ),
					]
				)
			);
		}

		if ( ( $failed > 0 || '' !== $bail_reason ) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[GHRP] Image sideload: %d of %d images failed for post %d%s.',
					$failed,
					$total,
					$post_id,
					'' !== $bail_reason ? ' (stopped early: ' . $bail_reason . ')' : ''
				)
			);
		}
	}

	/**
	 * Resolves an image URL through its redirects, requiring every hop to stay
	 * on the allowed-host list.
	 *
	 * WordPress's download_url()/media_sideload_image() follow redirects but
	 * apply only generic private-IP blocking per hop — which does not cover the
	 * link-local metadata range and would still fetch an off-list host reached
	 * via a redirect from an allowed *.github.io page. This walks the chain with
	 * HEAD requests, rejecting the first hop whose host is not allowed, and
	 * returns the final URL to download (with redirects then disabled by the
	 * http_request_args filter) — or a WP_Error so the caller skips the image and
	 * it stays a remote link.
	 *
	 * @param string $url             Initial image URL.
	 * @param array  $allowed_domains Allowed bare domains.
	 * @param int    $timeout         Per-request timeout in seconds.
	 * @return string|\WP_Error Final allow-listed URL, or WP_Error to skip.
	 */
	private static function resolve_allowed_image_url( string $url, array $allowed_domains, int $timeout ): string|\WP_Error {
		$max_hops = 5;

		for ( $hop = 0; $hop <= $max_hops; $hop++ ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! is_string( $host ) || ! self::is_host_allowed( strtolower( $host ), $allowed_domains ) ) {
				return new \WP_Error(
					'ghrp_sideload_host_not_allowed',
					sprintf( 'Image host not on the allow-list: %s', is_string( $host ) ? $host : '(none)' )
				);
			}

			$response = wp_safe_remote_head(
				$url,
				[
					'timeout'     => $timeout,
					'redirection' => 0,
				]
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				// Not a redirect — the current (allow-listed) URL is the target.
				return $url;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! is_string( $location ) || '' === $location ) {
				return new \WP_Error( 'ghrp_sideload_bad_redirect', 'Redirect response had no Location header.' );
			}

			$url = self::resolve_redirect_url( $location, $url );
		}

		return new \WP_Error( 'ghrp_sideload_too_many_redirects', 'Image exceeded the redirect limit.' );
	}

	/**
	 * Resolves a (possibly relative) redirect Location against the URL it came from.
	 *
	 * @param string $location Location header value.
	 * @param string $base     URL the redirect was returned from.
	 * @return string Absolute URL.
	 */
	private static function resolve_redirect_url( string $location, string $base ): string {
		// Already absolute.
		if ( (bool) preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return $location;
		}

		// Scheme-relative URL (begins with a double slash).
		if ( str_starts_with( $location, '//' ) ) {
			return $parts['scheme'] . ':' . $location;
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . $parts['port'];
		}

		// Root-relative path.
		if ( str_starts_with( $location, '/' ) ) {
			return $origin . $location;
		}

		// Path-relative — resolve against the base directory.
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$slash = strrpos( $path, '/' );
		$dir   = false === $slash ? '/' : substr( $path, 0, $slash + 1 );
		return $origin . $dir . $location;
	}

	/**
	 * Returns true when the given host matches one of the allowed domains.
	 *
	 * A host is allowed when it equals an allowed domain exactly, or is a
	 * subdomain of one. The leading dot in the suffix check is load-bearing:
	 * without it, `malicious-github.com` would be accepted as a match for
	 * `github.com`. Keep this method when refactoring — it documents the
	 * SSRF defense for future readers.
	 *
	 * @param string $host            Hostname from a URL (lower-case recommended).
	 * @param array  $allowed_domains List of bare domains (e.g. `github.com`).
	 * @return bool
	 */
	public static function is_host_allowed( string $host, array $allowed_domains ): bool {
		if ( '' === $host ) {
			return false;
		}
		foreach ( $allowed_domains as $domain ) {
			if ( ! is_string( $domain ) || '' === $domain ) {
				continue;
			}
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return true;
			}
		}
		return false;
	}
}
