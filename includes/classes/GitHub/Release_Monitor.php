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
 * Every repository is monitored the same way: one bounded snapshot of its
 * most recent releases, projected to the eligible view, grouped into streams
 * (package streams plus the '' default stream for plain repo-wide tags), one
 * head per stream compared against that stream's own cursor. An ordinary
 * single-package repository is simply a repository with one stream — there
 * is no separate monorepo path and no persisted topology.
 *
 * Before normal monitoring, each repo passes through explicit lifecycle
 * transitions decided by stored markers (never inferred):
 *
 *  1. `onboarding_pending`      → rerun the full onboarding matrix (same
 *                                 rules as add-time; see Onboarding_Handler).
 *  2. stream_state_version == 0 → upgrade from the released pre-stream
 *                                 plugin: baseline current heads, generate
 *                                 nothing (no backfill burst).
 *  3. policy hash changed       → eligibility settings changed: rebaseline
 *                                 current heads forward-only, generate
 *                                 nothing. Manual Generate Draft remains the
 *                                 explicit backfill tool.
 *
 * After detection, the queue is processed in the same run by firing the
 * ghrp_process_release action for each entry. Also provides the static
 * find_post() deduplication helper used by both the cron pipeline and the
 * manual trigger AJAX.
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
				 * an empty string for no filtering. The returned value must be
				 * deterministic — it participates in the stored eligibility
				 * policy hash, and a value that changes on every run would
				 * rebaseline (and therefore never post) on every run.
				 *
				 * @param string $tag_patterns Stored comma-separated patterns.
				 * @param string $identifier   Repository identifier (owner/repo).
				 * @param array  $repo         Full repository configuration.
				 */
				$tag_patterns = (string) apply_filters( 'ghrp_repo_tag_patterns', (string) ( $repo['tag_patterns'] ?? '' ), $identifier, $repo );

				$snapshot = $this->api_client->fetch_release_snapshot( $identifier );

				if ( is_wp_error( $snapshot ) ) {
					if ( 'github_rate_limit_exhausted' === $snapshot->get_error_code() ) {
						// API_Client already scheduled the retry event. Stop the run.
						$this->log( $identifier, 'rate limit exhausted — stopping run' );
						break;
					}

					$this->log( $identifier, 'error: ' . $snapshot->get_error_message() );
					Publish_Workflow::record_error( $identifier, '', $snapshot->get_error_message() );
					$this->state->update_last_checked( $identifier );
					continue;
				}

				$state       = $this->state->get_state( $identifier );
				$policy_hash = Release_Selector::policy_hash( $include_prereleases, $tag_patterns );
				$eligible    = Release_Selector::monitoring_projection( $snapshot, $include_prereleases, $tag_patterns );

				// Display-only observation, never read by monitoring: once a
				// repository is seen releasing 2+ recognized packages, package
				// naming (titles, slugs, admin labels) engages without
				// requiring a stored package selection. Also how repositories
				// tracked before this feature pick up package naming — their
				// first scan observes it. The guard keeps this a one-time
				// write (the marker is sticky; see mark_multi_package()).
				if ( ! $state['multi_package_observed'] && Tag_Pattern_Matcher::build_packages_payload( $snapshot )['multi_package'] ) {
					$this->state->mark_multi_package( $identifier );
				}

				if ( $state['onboarding_pending'] ) {
					// Transition: retry of a failed add. Rerun the SAME
					// onboarding matrix the add path uses, so a repository
					// whose first scan failed behaves exactly like one whose
					// first scan succeeded — at most one initial post, never
					// a one-post-per-stream burst of its existing history.
					$streams = $this->retry_onboarding( $identifier, $snapshot, $eligible, $policy_hash );
					// The initial release (if any) was deliberately left
					// without a cursor: the stream check below enqueues it,
					// or advances past it if a post already exists.
				} elseif ( Release_State::STREAM_STATE_VERSION !== $state['stream_state_version'] ) {
					// Transition: upgrade from the released pre-stream plugin.
					// Baseline every current eligible head and generate
					// nothing — the released behavior tracked only the latest
					// release, so current heads are history, not news.
					$this->state->complete_baseline( $identifier, $this->cursors_for_heads( $eligible ), $policy_hash );
					$this->log( $identifier, 'upgrade: stream baseline established, no backfill' );
					$this->state->update_last_checked( $identifier );
					continue;
				} elseif ( $policy_hash !== $state['policy_hash'] ) {
					// Transition: eligibility policy changed (pre-release
					// setting or tag patterns). Forward-only: current heads
					// under the NEW policy become the baseline and nothing is
					// generated this scan — newly eligible historical releases
					// are the admin's call via Generate Draft. This also
					// prevents a cursor written under the old policy (e.g. a
					// pre-release version) from blocking a lower-versioned
					// release that is now the eligible head.
					$this->state->complete_baseline( $identifier, $this->cursors_for_heads( $eligible ), $policy_hash );
					$this->log( $identifier, 'eligibility policy changed: rebaselined current stream heads, no backfill' );
					$this->state->update_last_checked( $identifier );
					continue;
				} else {
					$streams = $state['streams'];
				}

				$this->check_streams( $identifier, $eligible, $streams );
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
	 * Reruns the onboarding matrix for a repository whose add-time snapshot
	 * failed (transition 2 in the lifecycle).
	 *
	 * Identical decision rules to Onboarding_Handler::handle_add(), driven by
	 * the snapshot this cron run already fetched. The one difference is the
	 * delivery of the package-choice nudge: there is no admin looking at a
	 * redirect, so it is deferred to a site-wide notice rendered on the next
	 * settings-screen load.
	 *
	 * @param string    $identifier  Repository identifier.
	 * @param Release[] $snapshot    Raw snapshot (discovery view).
	 * @param Release[] $eligible    Monitoring-projection releases.
	 * @param string    $policy_hash Current eligibility policy hash.
	 * @return array<string, array{last_seen_tag: string, last_seen_published_at: string}> The baselined stream cursors.
	 */
	private function retry_onboarding( string $identifier, array $snapshot, array $eligible, string $policy_hash ): array {
		$packages_payload = Tag_Pattern_Matcher::build_packages_payload( $snapshot );
		$ui_choice        = (bool) $packages_payload['multi_package'];

		$plan = Release_Selector::onboarding_plan( $eligible, $ui_choice );
		$this->state->complete_baseline( $identifier, $plan['cursors'], $policy_hash );

		// Warm the package chooser cache while the snapshot is in hand.
		set_transient( Cache_Keys::repo_packages( $identifier ), $packages_payload, 15 * MINUTE_IN_SECONDS );

		if ( null === $plan['initial'] && $ui_choice && ! empty( $eligible ) ) {
			$this->defer_package_notice( $identifier, count( $packages_payload['packages'] ) );
		}

		$this->log( $identifier, 'onboarding completed on retry — ' . count( $plan['cursors'] ) . ' stream(s) baselined' );

		return $plan['cursors'];
	}

	/**
	 * Stores the package-choice nudge for a repository so the next load of
	 * the plugin's settings screen can display it. Keyed by identifier so a
	 * repeated retry overwrites rather than stacks.
	 *
	 * @param string $identifier    Repository identifier.
	 * @param int    $package_count Number of recognized packages.
	 * @return void
	 */
	private function defer_package_notice( string $identifier, int $package_count ): void {
		$notices = get_transient( Cache_Keys::deferred_notices() );
		if ( ! is_array( $notices ) ) {
			$notices = [];
		}

		$notices[ $identifier ] = [
			'type'    => 'info',
			'message' => sprintf(
				/* translators: 1: repository identifier, 2: number of packages detected */
				__( '%1$s releases %2$d different packages — by default, every release gets a post. Edit the repository to choose which packages.', 'auto-release-posts-for-github' ),
				$identifier,
				$package_count
			),
		];

		set_transient( Cache_Keys::deferred_notices(), $notices, WEEK_IN_SECONDS );
	}

	/**
	 * Builds baseline cursors from the current eligible stream heads.
	 *
	 * @param Release[] $eligible Monitoring-projection releases.
	 * @return array<string, array{last_seen_tag: string, last_seen_published_at: string}>
	 */
	private function cursors_for_heads( array $eligible ): array {
		$cursors = [];
		foreach ( $this->comparator->select_stream_winners( $eligible ) as $stream => $winner ) {
			$cursors[ (string) $stream ] = [
				'last_seen_tag'          => $winner->tag,
				'last_seen_published_at' => $winner->published_at,
			];
		}

		return $cursors;
	}

	/**
	 * Compares each eligible stream head against its stream cursor and
	 * enqueues the new ones.
	 *
	 * One head per stream per scan: if a package published several versions
	 * between scans, only its newest eligible version is generated (a
	 * documented limit). A missing cursor means the head is new — the repo
	 * was baselined with that stream deliberately omitted (pending initial
	 * generation) or the stream appeared after the baseline. Cursors advance
	 * in process_queue() only after a post is actually created, so failures
	 * never skip releases.
	 *
	 * @param string                              $identifier Repository identifier.
	 * @param Release[]                           $eligible   Monitoring-projection releases.
	 * @param array<string, array<string, mixed>> $streams    Stream cursors keyed by package.
	 * @return void
	 */
	private function check_streams( string $identifier, array $eligible, array $streams ): void {
		if ( empty( $eligible ) ) {
			$this->log( $identifier, 'no eligible releases' );
			return;
		}

		$winners = $this->comparator->select_stream_winners( $eligible );

		foreach ( $winners as $stream => $candidate ) {
			$cursor = [
				'last_seen_tag'          => (string) ( $streams[ $stream ]['last_seen_tag'] ?? '' ),
				'last_seen_published_at' => (string) ( $streams[ $stream ]['last_seen_published_at'] ?? '' ),
				'last_checked_at'        => 0,
			];

			$label = '' === $stream ? 'default stream' : $stream;

			if ( ! $this->comparator->is_newer( $candidate, $cursor ) ) {
				$this->log( $identifier, 'no new release (' . $label . ', last seen: ' . $cursor['last_seen_tag'] . ')' );
				continue;
			}

			// Replay guard: a post may already exist for this release —
			// manually generated, or created by the client-side onboarding
			// auto-trigger. Re-enqueueing would burn an AI call and re-fire
			// the publish workflow with cron context, which can silently
			// publish a draft that was created for review. Advance the
			// cursor instead.
			if ( self::find_post( $identifier, $candidate->tag ) instanceof \WP_Post ) {
				$this->state->update_stream_seen( $identifier, (string) $stream, $candidate->tag, $candidate->published_at );
				$this->log( $identifier, 'existing post found (' . $label . '): ' . $candidate->tag . ' — cursor advanced, not re-enqueued' );
				continue;
			}

			$this->queue->enqueue( $identifier, $candidate );
			$this->log( $identifier, 'new release found (' . $label . '): ' . $candidate->tag );
		}
	}

	/**
	 * Processes all queued release entries in the current run.
	 *
	 * Fires the ghrp_process_release action for each entry. DOM-05/06 hooks
	 * here to perform AI generation and post creation. After the action fires,
	 * checks whether a post was created and, if so, advances the stream cursor
	 * and the repo-wide display cursor (BR-001: only post-creation advances
	 * cursors).
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
				// The stream cursor is what monitoring reads; advance it here,
				// after creation, so a failed generation never skips a release.
				$parsed = Tag_Pattern_Matcher::derive_package( $tag );
				$stream = null === $parsed ? '' : $parsed['package'];
				$this->state->update_stream_seen( $identifier, $stream, $tag, $entry['published_at'] ?? '' );
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
