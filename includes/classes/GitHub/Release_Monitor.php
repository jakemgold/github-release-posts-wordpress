<?php
/**
 * Release monitoring cron run orchestrator.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\GitHub;

use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Orchestrates the release-check cron run.
 *
 * On each run, iterates all tracked (non-paused) repos, fetches their latest
 * GitHub release, compares against stored state, and queues new releases for
 * AI generation. After detection, the queue is processed in the same run by
 * firing the ctbp_process_release action for each entry.
 *
 * Also provides the static find_post() deduplication helper used by both
 * the cron pipeline and the manual trigger AJAX.
 */
class Release_Monitor {

	/**
	 * Constructor.
	 *
	 * @param API_Client          $api_client    GitHub HTTP client.
	 * @param Release_State       $state         Per-repo state storage.
	 * @param Version_Comparator  $comparator    Release comparison logic.
	 * @param Release_Queue       $queue         In-process release queue.
	 * @param Repository_Settings $repo_settings Tracked repository list.
	 */
	public function __construct(
		private readonly API_Client $api_client,
		private readonly Release_State $state,
		private readonly Version_Comparator $comparator,
		private readonly Release_Queue $queue,
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Executes a full release-check run.
	 *
	 * Called by both CRON_HOOK_RELEASE_CHECK and CRON_HOOK_RATE_LIMIT_RETRY.
	 *
	 * @return void
	 */
	public function run(): void {
		// Block editor must be active — posts are generated as blocks.
		if ( function_exists( 'use_block_editor_for_post_type' ) && ! use_block_editor_for_post_type( 'post' ) ) {
			return;
		}

		// Prevent overlapping cron runs from processing the same releases.
		$lock_key = 'ctbp_cron_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, time(), 10 * MINUTE_IN_SECONDS );

		// Record start time before processing so a partial run still updates the display (BR-004).
		update_option( Plugin_Constants::OPTION_LAST_RUN_AT, time(), false );

		$repos = $this->repo_settings->get_repositories();

		foreach ( $repos as $repo ) {
			$identifier = $repo['identifier'] ?? '';
			if ( '' === $identifier ) {
				continue;
			}

			// Skip paused repos — no API call, no state update (AC-025, BR-004).
			if ( ! empty( $repo['paused'] ) ) {
				$this->log( $identifier, 'skipped — paused' );
				continue;
			}

			$release = $this->api_client->fetch_latest_release( $identifier );

			if ( is_wp_error( $release ) ) {
				if ( 'github_rate_limit_exhausted' === $release->get_error_code() ) {
					// API_Client already scheduled the retry event. Stop the run.
					$this->log( $identifier, 'rate limit exhausted — stopping run' );
					break;
				}

				$this->log( $identifier, 'error: ' . $release->get_error_message() );
				$this->state->update_last_checked( $identifier );
				continue;
			}

			if ( null === $release ) {
				$this->log( $identifier, 'no releases found' );
				$this->state->update_last_checked( $identifier );
				continue;
			}

			$repo_state = $this->state->get_state( $identifier );

			if ( $this->comparator->is_newer( $release, $repo_state ) ) {
				$this->queue->enqueue( $identifier, $release );
				$this->log( $identifier, 'new release found: ' . $release->tag );
			} else {
				$this->log( $identifier, 'no new release (last seen: ' . $repo_state['last_seen_tag'] . ')' );
			}

			$this->state->update_last_checked( $identifier );
		}

		$this->process_queue();

		// Release the concurrency lock.
		delete_transient( $lock_key );
	}

	/**
	 * Processes all queued release entries in the current run.
	 *
	 * Fires the ctbp_process_release action for each entry. DOM-05/06 hooks
	 * here to perform AI generation and post creation. After the action fires,
	 * checks whether a post was created and, if so, updates the last-seen state
	 * (BR-001: only the cron pipeline updates last_seen_tag).
	 *
	 * @return void
	 */
	private function process_queue(): void {
		$entries = $this->queue->dequeue_all();

		foreach ( $entries as $entry ) {
			$identifier = $entry['identifier'] ?? '';
			$tag        = $entry['tag'] ?? '';

			if ( '' === $identifier || '' === $tag ) {
				continue;
			}

			/**
			 * Fires when a new release is ready for AI generation and post creation.
			 *
			 * DOM-05 (AI Integration) and DOM-06 (Post Generation) hook here.
			 *
			 * @param array<string, mixed> $entry   Queue entry with release data.
			 * @param array<string, mixed> $context Additional context flags.
			 */
			do_action( 'ctbp_process_release', $entry, [] );

			// Check whether a post was created (BR-001).
			$post = self::find_post( $identifier, $tag );

			if ( $post instanceof \WP_Post ) {
				$this->state->update_last_seen( $identifier, $tag, $entry['published_at'] ?? '' );
				$this->log( $identifier, 'post created for tag ' . $tag . ' (ID ' . $post->ID . ')' );
			} else {
				$this->log( $identifier, 'action fired for tag ' . $tag . ' — no post created yet' );
			}
		}
	}

	/**
	 * Finds an existing WordPress post for a given repo + tag combination.
	 *
	 * Used by both the cron pipeline and the manual trigger AJAX for
	 * deduplication (BR-003). Checks all non-auto-draft post statuses
	 * including trash, consistent with AC-003 from PRD-04.2.01.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @param string $tag        Release tag string.
	 * @return \WP_Post|null First matching post, or null if none found.
	 */
	public static function find_post( string $identifier, string $tag ): ?\WP_Post {
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
				'posts_per_page' => 1,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					[
						'key'     => Plugin_Constants::META_SOURCE_REPO,
						'value'   => $identifier,
						'compare' => '=',
					],
					[
						'key'     => Plugin_Constants::META_RELEASE_TAG,
						'value'   => $tag,
						'compare' => '=',
					],
				],
			]
		);

		return ! empty( $posts ) ? $posts[0] : null;
	}

	/**
	 * Writes a debug log entry when WP_DEBUG and WP_DEBUG_LOG are both enabled.
	 *
	 * @param string $identifier Repo identifier for context.
	 * @param string $message    Log message.
	 * @return void
	 */
	private function log( string $identifier, string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'[changelog-to-blog-post] %s — %s',
				$identifier,
				$message
			)
		);
	}
}
