<?php
/**
 * Release monitoring cron run orchestrator.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Post\Publish_Workflow;
use GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Orchestrates the release-check cron run.
 *
 * On each run, iterates all tracked (non-paused) repos, fetches their latest
 * GitHub release, compares against stored state, and queues new releases for
 * AI generation. After detection, the queue is processed in the same run by
 * firing the ghrp_process_release action for each entry.
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
		// The lock is taken with a bare INSERT on the unique option_name index
		// (see acquire_lock()) so exactly one concurrent worker wins. add_option()
		// cannot be used here: its existence check is cache-based and its write is
		// an upsert, so two workers that both pass the check each believe they
		// acquired it — the race that produced duplicate posts. The stored value
		// is the acquisition timestamp, so a lock abandoned by a hard crash (which
		// skips the finally below) is reclaimed after 10 minutes.
		$lock_key     = Cache_Keys::cron_lock();
		$now          = time();
		$lock_max_age = 10 * MINUTE_IN_SECONDS;

		if ( ! $this->acquire_lock( $lock_key, $now ) ) {
			// A lock exists. Clear it only if it is older than the max age, then
			// try once more. The reclaim is a conditional DELETE (atomic), so two
			// workers racing to reclaim an abandoned lock cannot both revive it.
			$this->reclaim_stale_lock( $lock_key, $now - $lock_max_age );
			if ( ! $this->acquire_lock( $lock_key, $now ) ) {
				return; // another worker holds a fresh lock; bail.
			}
		}

		// Wrap the run body in try/finally so any uncaught exception from
		// HTTP, AI provider, image sideload, or third-party action/filter
		// listeners still releases the lock — without this, the cron would
		// be silently blocked for up to 10 minutes after a single failure.
		try {
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

				$include_prereleases = ! empty( $repo['include_prereleases'] );
				/**
				 * Filters the tag patterns applied to a repository's releases.
				 *
				 * The primary way to set patterns is the Packages picker in the
				 * admin; this filter is the code-level override for dynamic or
				 * uncommon needs (unrecognized tag schemes, per-environment
				 * rules). Return a comma-separated list of fnmatch globs, or
				 * an empty string for no filtering.
				 *
				 * @param string $tag_patterns Stored comma-separated patterns.
				 * @param string $identifier   Repository identifier (owner/repo).
				 * @param array  $repo         Full repository configuration.
				 */
				$tag_patterns = (string) apply_filters( 'ghrp_repo_tag_patterns', (string) ( $repo['tag_patterns'] ?? '' ), $identifier, $repo );

				// Monorepos with patterns are monitored per package stream: a
				// single repo-wide cursor drops sibling releases published
				// between checks (coordinated releases are the norm in
				// monorepos), and cross-package version comparison is
				// meaningless. One candidate per selected package, each with
				// its own last-seen cursor.
				if ( Tag_Pattern_Matcher::has_patterns( $tag_patterns ) ) {
					$releases = $this->api_client->fetch_releases( $identifier, $include_prereleases, $tag_patterns );

					if ( is_wp_error( $releases ) ) {
						if ( 'github_rate_limit_exhausted' === $releases->get_error_code() ) {
							$this->log( $identifier, 'rate limit exhausted — stopping run' );
							break;
						}
						$this->log( $identifier, 'error: ' . $releases->get_error_message() );
						Publish_Workflow::record_error( $identifier, '', $releases->get_error_message() );
						$this->state->update_last_checked( $identifier );
						continue;
					}

					if ( empty( $releases ) ) {
						$this->log( $identifier, 'no releases match the configured tag patterns' );
						$this->state->update_last_checked( $identifier );
						continue;
					}

					$this->check_package_streams( $identifier, $releases );
					$this->state->update_last_checked( $identifier );
					continue;
				}

				$release = $this->api_client->fetch_latest_eligible_release( $identifier, $include_prereleases, $tag_patterns );

				if ( is_wp_error( $release ) ) {
					if ( 'github_rate_limit_exhausted' === $release->get_error_code() ) {
						// API_Client already scheduled the retry event. Stop the run.
						$this->log( $identifier, 'rate limit exhausted — stopping run' );
						break;
					}

					$this->log( $identifier, 'error: ' . $release->get_error_message() );
					Publish_Workflow::record_error( $identifier, '', $release->get_error_message() );
					$this->state->update_last_checked( $identifier );
					continue;
				}

				if ( null === $release ) {
					$this->log( $identifier, 'no releases found' );
					$this->state->update_last_checked( $identifier );
					continue;
				}

				// The default all-packages mode has no patterns, but a monorepo
				// is still a monorepo: one repo-wide cursor drops sibling
				// releases (peer review P1). Topology routing (round 4):
				// - is_monorepo=true is durable and authoritative;
				// - a package-shaped latest tag triggers immediate inspection;
				// - is_monorepo=false is only trusted while FRESH — repos can
				// become monorepos behind a plain latest tag, so a stale or
				// never-made determination re-inspects the full list. The
				// weekly re-check bounds the extra API cost for true
				// single-package repos to one list call per week.
				$repo_state     = $this->state->get_state( $identifier );
				$topology_stale = ( time() - $repo_state['topology_checked_at'] ) > WEEK_IN_SECONDS;

				if ( $repo_state['is_monorepo'] || $topology_stale || null !== Tag_Pattern_Matcher::derive_package( $release->tag ) ) {
					$releases = $this->api_client->fetch_releases( $identifier, $include_prereleases, '' );
					if ( is_array( $releases ) && ! empty( $releases ) ) {
						$is_monorepo = $repo_state['is_monorepo'];
						if ( ! $is_monorepo ) {
							$is_monorepo = $this->comparator->is_multi_stream( $releases );
							$this->state->set_monorepo( $identifier, $is_monorepo );
						}
						if ( $is_monorepo ) {
							$this->check_package_streams( $identifier, $releases );
							$this->state->update_last_checked( $identifier );
							continue;
						}
						// Confirmed single-package — fall through to the
						// single-cursor path with the latest already fetched.
					}
					// List fetch failed — fall through to the single-cursor
					// path rather than skipping the repo entirely.
				}

				if ( $this->comparator->is_newer( $release, $repo_state ) ) {
					$this->queue->enqueue( $identifier, $release );
					$this->log( $identifier, 'new release found: ' . $release->tag );
				} else {
					$this->log( $identifier, 'no new release (last seen: ' . $repo_state['last_seen_tag'] . ')' );
				}

				$this->state->update_last_checked( $identifier );
			}

			$this->process_queue();
		} finally {
			delete_option( $lock_key );
		}
	}

	/**
	 * Atomically acquires the cron lock.
	 *
	 * Uses a bare INSERT on the unique option_name index: it fails when the row
	 * already exists, so exactly one concurrent worker gets a truthy result — a
	 * real cross-process mutex. add_option() cannot be used here because its
	 * existence check is cache-based and its write is an upsert, so two workers
	 * can both "succeed" and both proceed.
	 *
	 * @param string $lock_key Option name used as the lock.
	 * @param int    $now      Current timestamp, stored as the lock value.
	 * @return bool True if this worker acquired the lock.
	 */
	private function acquire_lock( string $lock_key, int $now ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$lock_key,
				(string) $now
			)
		);

		// Keep the options cache consistent with the direct write.
		wp_cache_delete( $lock_key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return (bool) $inserted;
	}

	/**
	 * Deletes the cron lock only if it is older than the given cutoff.
	 *
	 * A conditional, atomic DELETE so two workers that both observe an abandoned
	 * lock cannot both reclaim it and proceed.
	 *
	 * @param string $lock_key   Option name used as the lock.
	 * @param int    $older_than Unix timestamp; a lock acquired before this is cleared.
	 * @return void
	 */
	private function reclaim_stale_lock( string $lock_key, int $older_than ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d",
				$lock_key,
				$older_than
			)
		);

		wp_cache_delete( $lock_key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Processes all queued release entries in the current run.
	 *
	 * Fires the ghrp_process_release action for each entry. DOM-05/06 hooks
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
			do_action( 'ghrp_process_release', $entry, [] );

			// Check whether a post was created (BR-001).
			$post = self::find_post( $identifier, $tag );

			if ( $post instanceof \WP_Post ) {
				$this->state->update_last_seen( $identifier, $tag, $entry['published_at'] ?? '' );
				// Package streams keep their own cursor (see
				// check_package_streams()); advance it here, after creation,
				// for the same crash-resilience as the repo-wide cursor.
				$parsed = Tag_Pattern_Matcher::derive_package( $tag );
				$stream = null === $parsed ? '' : $parsed['package'];
				$this->state->update_package_seen( $identifier, $stream, $tag, $entry['published_at'] ?? '' );
				$this->log( $identifier, 'post created for tag ' . $tag . ' (ID ' . $post->ID . ')' );
			} else {
				$this->log( $identifier, 'action fired for tag ' . $tag . ' — no post created yet' );
				Publish_Workflow::record_error(
					$identifier,
					$tag,
					__( 'AI generation or post creation failed for this release.', 'auto-release-posts-for-github' )
				);
			}
		}
	}

	/**
	 * Checks each package stream of a patterned monorepo for a new release.
	 *
	 * Releases are grouped by package (unclassifiable tags share a default
	 * stream). Within each group the newest release is chosen by version
	 * (the comparator normalizes package tags to their embedded versions),
	 * then compared against that package's own cursor. The first streamed
	 * run seeds every cursor without generating (no upgrade burst); a
	 * stream whose newest release already has a post advances its cursor
	 * instead of re-enqueueing (replay guard). Cursors otherwise advance in
	 * process_queue() only after a post is actually created, so failures
	 * never skip releases.
	 *
	 * @param string    $identifier Repository identifier.
	 * @param Release[] $releases   Pattern-matching releases, newest first.
	 * @return void
	 */
	private function check_package_streams( string $identifier, array $releases ): void {
		// The single shared selection routine — onboarding baselines and
		// latest-release selection use the same one (round 4).
		$winners = $this->comparator->select_stream_winners( $releases );

		$state         = $this->state->get_state( $identifier );
		$package_state = $state['packages'];

		// One-time migration seeding is ONLY for true legacy repositories —
		// tracked before this feature existed. Two explicit markers decide
		// (round 6): a repo with a stream baseline is already migrated, and a
		// repo whose tracking began under this version (tracking_started_at)
		// is never legacy even when discovery failed at add time — its
		// current releases appeared after tracking began, so empty cursors
		// mean "generate", not "historical". Without this, a transient
		// onboarding failure made the repo permanently legacy-looking and a
		// later second stream's first release was silently seeded away.
		$seed_only = 0 === $state['streams_baseline_at'] && 0 === $state['tracking_started_at'];
		$seeds     = [];

		foreach ( $winners as $package => $candidate ) {
			$cursor = [
				'last_seen_tag'          => (string) ( $package_state[ $package ]['last_seen_tag'] ?? '' ),
				'last_seen_published_at' => (string) ( $package_state[ $package ]['last_seen_published_at'] ?? '' ),
				'last_checked_at'        => 0,
			];

			$label = '' === $package ? 'default stream' : $package;

			if ( $seed_only ) {
				$seeds[ (string) $package ] = [
					'last_seen_tag'          => $candidate->tag,
					'last_seen_published_at' => $candidate->published_at,
				];
				$this->log( $identifier, 'stream cursor seeded (' . $label . '): ' . $candidate->tag );
				continue;
			}

			if ( ! $this->comparator->is_newer( $candidate, $cursor ) ) {
				$this->log( $identifier, 'no new release (' . $label . ', last seen: ' . $cursor['last_seen_tag'] . ')' );
				continue;
			}

			// Replay guard (peer review P1): a post may already exist for this
			// release — manually generated, or predating the stream cursors.
			// Re-enqueueing would burn an AI call and re-fire the publish
			// workflow with cron context, which can silently publish a draft
			// that was created for review. Advance the cursor instead.
			if ( self::find_post( $identifier, $candidate->tag ) instanceof \WP_Post ) {
				$this->state->update_package_seen( $identifier, (string) $package, $candidate->tag, $candidate->published_at );
				$this->log( $identifier, 'existing post found (' . $label . '): ' . $candidate->tag . ' — cursor advanced, not re-enqueued' );
				continue;
			}

			$this->queue->enqueue( $identifier, $candidate );
			$this->log( $identifier, 'new release found (' . $label . '): ' . $candidate->tag );
		}

		if ( $seed_only ) {
			$this->state->seed_streams( $identifier, $seeds );
		}
	}

	/**
	 * Request-scoped cache for find_post() results.
	 *
	 * Keyed by "identifier:tag". Stores both positive (WP_Post) and negative
	 * (null) results so a repeat lookup in the same request avoids the
	 * meta_query join. Callers that mutate state for a key (post insert,
	 * delete) must invalidate via forget_post() to prevent stale reads.
	 *
	 * @var array<string, \WP_Post|null>
	 */
	private static array $find_post_cache = [];

	/**
	 * Finds an existing WordPress post for a given repo + tag combination.
	 *
	 * Used by both the cron pipeline and the manual trigger AJAX for
	 * deduplication (BR-003). Checks all non-auto-draft post statuses
	 * including trash, consistent with AC-003 from PRD-04.2.01.
	 *
	 * Results are cached for the duration of the request. The same (identifier,
	 * tag) pair is queried twice per cron tick — once by Post_Creator before
	 * insertion, once by Release_Monitor after — so caching halves the work.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @param string $tag        Release tag string.
	 * @return \WP_Post|null First matching post, or null if none found.
	 */
	public static function find_post( string $identifier, string $tag ): ?\WP_Post {
		$cache_key = $identifier . ':' . $tag;
		if ( array_key_exists( $cache_key, self::$find_post_cache ) ) {
			return self::$find_post_cache[ $cache_key ];
		}

		// Order by ID descending so the most recently inserted post wins
		// when multiple posts share the same (identifier, tag) — possible
		// when manual "Generate post" runs against a release that already
		// had a trashed predecessor (Post_Creator's bypass_idempotency path
		// inserts a fresh draft alongside the trashed one). Ordering by
		// post_date alone would return the wrong post when an older release
		// is backdated, since the trashed predecessor could have a more
		// recent post_date than the freshly-inserted backdated draft.
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
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

		$result                              = ! empty( $posts ) ? $posts[0] : null;
		self::$find_post_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Invalidates the cached find_post() entry for a repo + tag.
	 *
	 * Call this after inserting, deleting, or trashing a post so subsequent
	 * lookups in the same request observe the change.
	 *
	 * @param string $identifier Repository identifier.
	 * @param string $tag        Release tag.
	 * @return void
	 */
	public static function forget_post( string $identifier, string $tag ): void {
		unset( self::$find_post_cache[ $identifier . ':' . $tag ] );
	}

	/**
	 * Clears the entire find_post() cache. Intended for tests.
	 *
	 * @internal
	 * @return void
	 */
	public static function reset_find_post_cache(): void {
		self::$find_post_cache = [];
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
				'[auto-release-posts-for-github] %s — %s',
				$identifier,
				$message
			)
		);
	}
}
