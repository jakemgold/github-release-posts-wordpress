<?php
/**
 * Creates WordPress posts from AI-generated content.
 *
 * @package ChangelogToBlogPost\Post
 */

namespace TenUp\ChangelogToBlogPost\Post;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Hooks into ctbp_post_generated and creates a WordPress post with source
 * attribution meta. Ensures idempotency: the same repo + tag combination
 * never produces duplicate posts.
 */
class Post_Creator {

	/**
	 * Constructor.
	 *
	 * @param Repository_Settings $repo_settings Per-repo configuration (display name lookup).
	 */
	public function __construct(
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Registers the ctbp_post_generated action.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ctbp_post_generated', [ $this, 'handle' ], 10, 3 );
	}

	/**
	 * Creates a WordPress post from AI-generated content.
	 *
	 * Checks idempotency first — if a post already exists for the given
	 * repo + tag, fires ctbp_post_created with the existing post ID and
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
				do_action( 'ctbp_post_created', $existing_id, $post, $data, $context );
				return;
			}
		}

		$title          = $this->build_title( $data->identifier, $data->tag, $post->title );
		$block_content  = $this->convert_html_to_blocks( $post->content );
		$block_content .= $this->build_disclosure_block( $data );
		$author_id      = $this->resolve_author( $data->identifier );
		$post_id        = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $block_content,
				'post_status'  => 'draft',
				'post_type'    => 'post',
				'post_author'  => $author_id,
			],
			true
		);

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

		// Sideload remote images into the WordPress media library.
		$this->sideload_images( $post_id );

		// Set featured image from per-repo config if configured.
		$this->set_featured_image( $post_id, $data->identifier );

		/** This action is documented above. */
		do_action( 'ctbp_post_created', $post_id, $post, $data, $context );
	}

	/**
	 * Finds an existing post for the given repo + tag combination.
	 *
	 * Checks all post statuses including trash (AC-006).
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @param string $tag        Release tag.
	 * @return int|null Post ID if found, null otherwise.
	 */
	public function find_existing_post( string $identifier, string $tag ): ?int {
		$query = new \WP_Query(
			[
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'   => Plugin_Constants::META_SOURCE_REPO,
						'value' => $identifier,
					],
					[
						'key'   => Plugin_Constants::META_RELEASE_TAG,
						'value' => $tag,
					],
				],
			]
		);

		$posts = $query->posts;
		return ! empty( $posts ) ? (int) $posts[0] : null;
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
	private function build_disclosure_block( ReleaseData $data ): string {
		if ( ! get_option( Plugin_Constants::OPTION_AI_DISCLOSURE, false ) ) {
			return '';
		}

		$text = __( 'This post was generated from release notes with the help of AI using GitHub Release Posts plugin for WordPress.', 'changelog-to-blog-post' );

		/**
		 * Filters the AI disclosure text appended to generated posts.
		 *
		 * Return an empty string to suppress the disclosure for a specific post.
		 *
		 * @param string      $text The disclosure text.
		 * @param int         $post_id WordPress post ID (0 during initial creation).
		 * @param ReleaseData $data    Release data.
		 */
		$text = (string) apply_filters( 'ctbp_ai_disclosure_text', $text, 0, $data );

		if ( '' === $text ) {
			return '';
		}

		return "\n\n" . '<!-- wp:paragraph {"fontSize":"small","className":"ctbp-ai-disclosure"} -->' . "\n"
			. '<p class="has-small-font-size ctbp-ai-disclosure"><em>' . esc_html( $text ) . '</em></p>' . "\n"
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
		$attachment_id = (int) apply_filters( 'ctbp_post_featured_image', $attachment_id, $post_id, $identifier );

		if ( $attachment_id > 0 ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}
	}

	/**
	 * Builds the full post title: "{Display Name} {tag} — {subtitle}".
	 *
	 * Looks up the display name from per-repo configuration; falls back to
	 * deriving it from the repository slug.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @param string $tag        Release tag.
	 * @param string $subtitle   AI-generated subtitle.
	 * @return string Full post title.
	 */
	private function build_title( string $identifier, string $tag, string $subtitle ): string {
		$display_name = $this->resolve_display_name( $identifier );
		$tag          = self::format_version_tag( $tag );
		return "{$display_name} {$tag} — {$subtitle}";
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

			// Restore figure placeholders.
			if ( isset( $figure_placeholders[ $part ] ) ) {
				$blocks[] = self::wrap_in_block( 'figure', $figure_placeholders[ $part ] );
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
	 * back to a generic HTML block.
	 *
	 * @param string $html The full <figure> HTML element.
	 * @return string Block-wrapped markup.
	 */
	private static function wrap_figure_block( string $html ): string {
		// Only treat as an image block if the figure contains an <img>.
		if ( ! preg_match( '/<img\s/i', $html ) ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		// Extract src and alt from the <img> tag.
		$src = '';
		$alt = '';
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $src_match ) ) {
			$src = $src_match[1];
		}
		if ( preg_match( '/<img[^>]+alt=["\']([^"\']*)["\']/', $html, $alt_match ) ) {
			$alt = $alt_match[1];
		}

		if ( '' === $src ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		// Extract figcaption if present.
		$caption = '';
		if ( preg_match( '/<figcaption[^>]*>(.*?)<\/figcaption>/si', $html, $cap_match ) ) {
			$caption = trim( $cap_match[1] );
		}

		// Rebuild the block with exact markup Gutenberg expects.
		$figure = '<figure class="wp-block-image size-full">'
			. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" />';

		if ( '' !== $caption ) {
			$figure .= '<figcaption class="wp-element-caption">' . $caption . '</figcaption>';
		}

		$figure .= '</figure>';

		return "<!-- wp:image {\"sizeSlug\":\"full\"} -->\n{$figure}\n<!-- /wp:image -->";
	}

	/**
	 * Wraps a standalone <img> element as a Gutenberg image block.
	 *
	 * @param string $html The <img> HTML element.
	 * @return string Block-wrapped markup.
	 */
	private static function wrap_img_block( string $html ): string {
		$src = '';
		$alt = '';
		if ( preg_match( '/src=["\']([^"\']+)["\']/i', $html, $src_match ) ) {
			$src = $src_match[1];
		}
		if ( preg_match( '/alt=["\']([^"\']*)["\']/', $html, $alt_match ) ) {
			$alt = $alt_match[1];
		}

		if ( '' === $src ) {
			return "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->";
		}

		$figure = '<figure class="wp-block-image size-full">'
			. '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" />'
			. '</figure>';

		return "<!-- wp:image {\"sizeSlug\":\"full\"} -->\n{$figure}\n<!-- /wp:image -->";
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
			'ctbp_sideload_allowed_domains',
			[
				'github.com',
				'githubusercontent.com',
				'github.io',
			]
		);

		foreach ( $urls as $remote_url ) {
			// Skip URLs already pointing to this site.
			if ( str_starts_with( $remote_url, $site_url ) ) {
				continue;
			}

			// Only sideload from allowed domains to prevent SSRF.
			$host    = wp_parse_url( $remote_url, PHP_URL_HOST );
			$allowed = false;
			foreach ( $allowed_domains as $domain ) {
				if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				continue;
			}

			++$total;
			$attachment_id = media_sideload_image( $remote_url, $post_id, '', 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				++$failed;
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
				continue;
			}

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

		// Update the post with local image URLs.
		if ( $content !== $post->post_content ) {
			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => $content,
				]
			);
		}

		if ( $failed > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[CTBP] Image sideload: %d of %d images failed for post %d.',
					$failed,
					$total,
					$post_id
				)
			);
		}
	}

	/**
	 * Resolves the display name for a repository.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return string Display name.
	 */
	private function resolve_display_name( string $identifier ): string {
		$config = $this->repo_settings->get_repository( $identifier );

		if ( ! empty( $config['display_name'] ) ) {
			return (string) $config['display_name'];
		}

		$parts = explode( '/', $identifier );
		return $this->repo_settings->derive_display_name( end( $parts ) );
	}
}
