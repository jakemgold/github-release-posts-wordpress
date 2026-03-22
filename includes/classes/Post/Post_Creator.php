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

		$title   = $this->build_title( $data->identifier, $data->tag, $post->title );
		$post_id = wp_insert_post(
			[
				'post_title'   => $title,
				'post_content' => $post->content,
				'post_status'  => 'draft',
				'post_type'    => 'post',
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[CTBP] Post creation failed for %s@%s: %s',
					$data->identifier,
					$data->tag,
					$post_id->get_error_message()
				) );
			}
			return;
		}

		$this->store_meta( $post_id, $data, $post->provider_slug );

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
		$query = new \WP_Query( [
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
		] );

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
		return "{$display_name} {$tag} — {$subtitle}";
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
