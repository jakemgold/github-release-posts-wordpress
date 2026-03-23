<?php
/**
 * Assigns taxonomy terms to generated posts.
 *
 * @package ChangelogToBlogPost\Post
 */

namespace TenUp\ChangelogToBlogPost\Post;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;

use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Hooks into ctbp_post_created and applies the configured category and tags
 * to the post. Uses per-repo settings with global fallback. Validates terms
 * exist before applying and logs warnings for missing ones.
 */
class Taxonomy_Assigner {

	/**
	 * Constructor.
	 *
	 * @param Repository_Settings $repo_settings Per-repo configuration.
	 */
	public function __construct(
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Registers the ctbp_post_created action.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ctbp_post_created', [ $this, 'handle' ], 10, 4 );
	}

	/**
	 * Applies taxonomy terms to a generated post.
	 *
	 * @param int           $post_id WordPress post ID.
	 * @param GeneratedPost $post    Generated post data.
	 * @param ReleaseData   $data    Source release data.
	 * @param array         $context Generation context flags.
	 * @return void
	 */
	public function handle( int $post_id, GeneratedPost $post, ReleaseData $data, array $context ): void {
		$terms = $this->resolve_terms( $data->identifier );

		/**
		 * Filters the taxonomy terms applied to a generated post.
		 *
		 * Allows developers to add, remove, or replace terms for any
		 * individual post.
		 *
		 * @param array       $terms   Resolved terms: ['categories' => int[], 'tags' => int[]].
		 * @param int         $post_id WordPress post ID.
		 * @param ReleaseData $data    Source release data.
		 */
		$terms = (array) apply_filters( 'ctbp_post_terms', $terms, $post_id, $data );

		$this->apply_categories( $post_id, (array) ( $terms['categories'] ?? [] ), $data->identifier );
		$this->apply_tags( $post_id, (array) ( $terms['tags'] ?? [] ), $data->identifier );
	}

	/**
	 * Resolves category and tags for a repository.
	 *
	 * Uses per-repo values directly (AC-007).
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return array{categories: int[], tags: int[]}
	 */
	private function resolve_terms( string $identifier ): array {
		$repo_config = $this->find_repo_config( $identifier );

		$categories = ! empty( $repo_config['categories'] ) ? array_map( 'intval', (array) $repo_config['categories'] ) : [];
		$tags       = ! empty( $repo_config['tags'] ) ? array_map( 'intval', (array) $repo_config['tags'] ) : [];

		return [
			'categories' => $categories,
			'tags'       => $tags,
		];
	}

	/**
	 * Applies categories to the post, skipping any that no longer exist.
	 *
	 * @param int    $post_id    WordPress post ID.
	 * @param int[]  $categories Category term IDs.
	 * @param string $identifier Repo identifier for logging.
	 * @return void
	 */
	private function apply_categories( int $post_id, array $categories, string $identifier ): void {
		if ( empty( $categories ) ) {
			return; // No categories configured — leave WordPress default.
		}

		$valid = [];
		foreach ( $categories as $cat_id ) {
			if ( term_exists( $cat_id, 'category' ) ) {
				$valid[] = $cat_id;
			} else {
				$this->log_missing_term( 'category', $cat_id, $identifier );
			}
		}

		if ( ! empty( $valid ) ) {
			wp_set_post_categories( $post_id, $valid );
		}
	}

	/**
	 * Applies tags to the post, skipping any that no longer exist.
	 *
	 * @param int    $post_id    WordPress post ID.
	 * @param int[]  $tags       Tag term IDs.
	 * @param string $identifier Repo identifier for logging.
	 * @return void
	 */
	private function apply_tags( int $post_id, array $tags, string $identifier ): void {
		if ( empty( $tags ) ) {
			return; // No tags configured (AC-005).
		}

		$valid_tags = [];
		foreach ( $tags as $tag_id ) {
			if ( term_exists( (int) $tag_id, 'post_tag' ) ) {
				$valid_tags[] = (int) $tag_id;
			} else {
				$this->log_missing_term( 'post_tag', (int) $tag_id, $identifier );
			}
		}

		if ( ! empty( $valid_tags ) ) {
			wp_set_post_tags( $post_id, $valid_tags );
		}
	}

	/**
	 * Logs a warning for a missing taxonomy term.
	 *
	 * @param string $taxonomy   Taxonomy name ('category' or 'post_tag').
	 * @param int    $term_id    The missing term ID.
	 * @param string $identifier Repo identifier.
	 * @return void
	 */
	private function log_missing_term( string $taxonomy, int $term_id, string $identifier ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[CTBP] Taxonomy_Assigner: %s term ID %d configured for repo "%s" no longer exists — skipping.',
					$taxonomy,
					$term_id,
					$identifier
				)
			);
		}
	}

	/**
	 * Finds the per-repo configuration for a given identifier.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return array<string, mixed> Repo config, or empty array if not found.
	 */
	private function find_repo_config( string $identifier ): array {
		return $this->repo_settings->get_repository( $identifier );
	}
}
