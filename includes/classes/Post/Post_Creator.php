<?php
/**
 * Creates WordPress posts from AI-generated content.
 *
 * @package GitHubReleasePosts\Post
 */

namespace GitHubReleasePosts\Post;

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\GitHub\Release_Monitor;
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
	 * repo + tag, fires ghrp_post_created with the existing post ID and
	 * returns without creating a duplicate.
	 *
	 * @param GeneratedPost $post    Generated post data (subtitle + HTML body).
	 * @param ReleaseData   $data    Source release data.
	 * @param array         $context Generation context flags.
	 * @return void
	 */
	public function handle( GeneratedPost $post, ReleaseData $data, array $context ): void {
		$bypass = ! empty( $context['bypass_idempotency'] );

		if ( ! $bypass ) {
			$existing_id = $this->find_existing_post( $data->identifier, $data->tag );

			if ( null !== $existing_id ) {
				/**
				 * Fires when a post has been created (or already exists) for a release.
				 *
				 * @param int           $post_id  The WordPress post ID.
				 * @param GeneratedPost $post     The generated post data.
				 * @param ReleaseData   $data     The source release data.
				 * @param array         $context  Generation context flags.
				 */
				do_action( 'ghrp_post_created', $existing_id, $post, $data, $context );
				return;
			}
		}

		$title          = self::build_title(
			$this->repo_settings->get_display_name( $data->identifier ),
			$data->tag,
			$post->title,
			$this->global_settings->get_title_format(),
			$data->identifier
		);
		$block_content  = $this->convert_html_to_blocks( $post->content );
		$block_content .= $this->build_disclosure_block( $data );
		$author_id      = $this->resolve_author( $data->identifier );
		$slug           = $this->build_slug( $data->identifier, $data->tag, $post->slug_keywords );

		$insert_args = [
			'post_title'   => $title,
			'post_content' => $block_content,
			'post_status'  => 'draft',
			'post_type'    => 'post',
			'post_author'  => $author_id,
		];

		if ( '' !== $post->excerpt ) {
			$insert_args['post_excerpt'] = $post->excerpt;
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

		$post_id = wp_insert_post( $insert_args, true );

		if ( is_wp_error( $post_id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'[CTBP] Post creation failed for %s@%s: %s',
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

		/** This action is documented above. */
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

		$text = __( 'This post was generated from release notes with the help of AI using GitHub Release Posts plugin for WordPress.', 'github-release-posts' );

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

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
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
	 * @param string $identifier   Repository identifier — passed through to the filter.
	 * @return string Full post title.
	 */
	public static function build_title( string $display_name, string $tag, string $ai_title, string $format, string $identifier ): string {
		$tag = self::format_version_tag( $tag );

		$title = match ( $format ) {
			'none'    => $ai_title,
			'version' => 'Version ' . ltrim( $tag, 'vV' ) . ' — ' . $ai_title,
			default   => "{$display_name} {$tag} — {$ai_title}",
		};

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
	private function build_slug( string $identifier, string $tag, string $slug_keywords ): string {
		if ( '' === $slug_keywords ) {
			return '';
		}

		$display_name = $this->repo_settings->get_display_name( $identifier );

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
	 * Converts HTML content into Gutenberg block markup.
	 *
	 * Wraps top-level HTML elements in their corresponding block comments
	 * so WordPress treats the post as native block editor content.
	 *
	 * @param string $html Raw HTML content from the AI provider.
	 * @return string Block-formatted content.
	 */
	public static function convert_html_to_blocks( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		// Strip any <p> wrappers around <figure> elements — AI models sometimes
		// nest block-level elements inside <p> tags, which breaks splitting.
		$html = preg_replace( '%<p>\s*(<figure[\s>].*?</figure>)\s*</p>%si', '$1', $html );

		// Extract <figure> blocks first (they can contain nested elements
		// like <figcaption> that confuse the simpler tag-based splitter).
		$figure_placeholders = [];
		$html                = preg_replace_callback(
			'%<figure[\s>].*?</figure>%si',
			function ( $matches ) use ( &$figure_placeholders ) {
				$key                         = '<!--CTBP_FIGURE_' . count( $figure_placeholders ) . '-->';
				$figure_placeholders[ $key ] = $matches[0];
				return $key;
			},
			$html
		);

		// Split remaining HTML into top-level elements.
		$pattern = '%(<(?:p|ul|ol|h[1-6]|blockquote|img|hr|pre|table)[\s>].*?(?:</(?:p|ul|ol|h[1-6]|blockquote|pre|table)>|/>))%si';
		$parts   = preg_split( $pattern, $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

		if ( empty( $parts ) ) {
			return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->";
		}

		$blocks = [];

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			// Restore figure placeholders. A single part may contain multiple
			// adjacent placeholders (e.g. two <figure> elements with no content
			// between them), so split on each one individually.
			if ( ! empty( $figure_placeholders ) && str_contains( $part, '<!--CTBP_FIGURE_' ) ) {
				$sub_parts = preg_split(
					'/(<!--CTBP_FIGURE_\d+-->)/',
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

			if ( preg_match( '/^<(p|ul|ol|h[1-6]|blockquote|img|hr|pre|table)[\s>]/i', $part, $tag_match ) ) {
				$tag      = strtolower( $tag_match[1] );
				$blocks[] = self::wrap_in_block( $tag, $part );
			} else {
				// Leftover text — wrap as paragraph.
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . $part . "</p>\n<!-- /wp:paragraph -->";
			}
		}

		return implode( "\n\n", $blocks );
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
			'p'          => "<!-- wp:paragraph -->\n{$html}\n<!-- /wp:paragraph -->",
			'ul'         => "<!-- wp:list -->\n{$html}\n<!-- /wp:list -->",
			'ol'         => "<!-- wp:list {\"ordered\":true} -->\n{$html}\n<!-- /wp:list -->",
			'h1'         => "<!-- wp:heading {\"level\":1} -->\n{$html}\n<!-- /wp:heading -->",
			'h2'         => "<!-- wp:heading -->\n{$html}\n<!-- /wp:heading -->",
			'h3'         => "<!-- wp:heading {\"level\":3} -->\n{$html}\n<!-- /wp:heading -->",
			'h4'         => "<!-- wp:heading {\"level\":4} -->\n{$html}\n<!-- /wp:heading -->",
			'h5'         => "<!-- wp:heading {\"level\":5} -->\n{$html}\n<!-- /wp:heading -->",
			'h6'         => "<!-- wp:heading {\"level\":6} -->\n{$html}\n<!-- /wp:heading -->",
			'blockquote' => "<!-- wp:quote -->\n{$html}\n<!-- /wp:quote -->",
			'figure'     => self::wrap_figure_block( $html ),
			'img'        => self::wrap_img_block( $html ),
			'hr'         => '<!-- wp:separator -->',
			'pre'        => "<!-- wp:code -->\n{$html}\n<!-- /wp:code -->",
			'table'      => "<!-- wp:table -->\n<figure class=\"wp-block-table\">{$html}</figure>\n<!-- /wp:table -->",
			default      => "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->",
		};
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
			$figure .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
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
			if ( ! is_string( $host ) || ! self::is_host_allowed( $host, $allowed_domains ) ) {
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
			$attachment_id = media_sideload_image( $remote_url, $post_id, '', 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				++$failed;
				++$consec_failures;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[CTBP] Image sideload failed for %s: %s',
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

		// Update the post with local image URLs.
		if ( $content !== $post->post_content ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $content,
				]
			);
		}

		if ( ( $failed > 0 || '' !== $bail_reason ) && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[CTBP] Image sideload: %d of %d images failed for post %d%s.',
					$failed,
					$total,
					$post_id,
					'' !== $bail_reason ? ' (stopped early: ' . $bail_reason . ')' : ''
				)
			);
		}
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
