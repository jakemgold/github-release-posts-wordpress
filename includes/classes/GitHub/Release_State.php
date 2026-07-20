<?php
/**
 * Per-repo release state storage.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\Plugin_Constants;

/**
 * Manages per-repository monitoring state.
 *
 * The state model is deliberately small and every lifecycle decision is made
 * from an EXPLICIT marker — never inferred from tag shape, cursor-map
 * emptiness, or timestamps:
 *
 *  - `onboarding_pending`   — a newly added repository has not yet produced a
 *                             successful full snapshot; cron reruns the
 *                             onboarding transition until one succeeds.
 *  - `streams_baseline_at`  — a trustworthy snapshot established the stream
 *                             cursors (0 = never).
 *  - `stream_state_version` — schema marker. 0 identifies state written by
 *                             the released pre-stream plugin (or no state at
 *                             all): the upgrade transition baselines it once,
 *                             generating nothing.
 *  - `policy_hash`          — hash of the eligibility policy the baseline was
 *                             built under; a change triggers a forward-only
 *                             rebaseline.
 *  - `streams`              — per-stream cursors keyed by package name
 *                             ('' = the default stream for plain repo-wide
 *                             tags). A missing cursor means the stream's
 *                             current head has not been processed or
 *                             deliberately baselined.
 *
 * The repo-wide `last_seen_*` fields remain for admin display and the
 * deep-research compare fallback; monitoring decisions never read them.
 *
 * Each repo's state is stored in its own wp_options entry keyed by
 * OPTION_REPO_STATE_PREFIX . md5( $identifier ). Writers persist the
 * canonical shape only, so keys from unreleased review iterations of this
 * feature are dropped on the first write.
 */
class Release_State {

	/**
	 * Current stream-state schema version.
	 */
	const STREAM_STATE_VERSION = 1;

	/**
	 * Returns the state array for a repository, with defaults for any missing keys.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return array{last_seen_tag: string, last_seen_published_at: string, last_checked_at: int, stream_state_version: int, onboarding_pending: bool, streams_baseline_at: int, policy_hash: string, streams: array<string, array{last_seen_tag: string, last_seen_published_at: string}>}
	 */
	public function get_state( string $identifier ): array {
		$stored = get_option( $this->option_key( $identifier ), [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return [
			'last_seen_tag'          => (string) ( $stored['last_seen_tag'] ?? '' ),
			'last_seen_published_at' => (string) ( $stored['last_seen_published_at'] ?? '' ),
			'last_checked_at'        => (int) ( $stored['last_checked_at'] ?? 0 ),
			'stream_state_version'   => (int) ( $stored['stream_state_version'] ?? 0 ),
			'onboarding_pending'     => (bool) ( $stored['onboarding_pending'] ?? false ),
			'streams_baseline_at'    => (int) ( $stored['streams_baseline_at'] ?? 0 ),
			'policy_hash'            => (string) ( $stored['policy_hash'] ?? '' ),
			'streams'                => (array) ( $stored['streams'] ?? [] ),
		];
	}

	/**
	 * Marks a newly added repository as awaiting its first successful
	 * onboarding snapshot. Persisted BEFORE the API call, so no failure
	 * sequence can leave the repository in an ambiguous lifecycle state:
	 * until a snapshot succeeds, cron reruns the full onboarding transition
	 * instead of normal monitoring.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return void
	 */
	public function mark_onboarding_pending( string $identifier ): void {
		$state                       = $this->get_state( $identifier );
		$state['onboarding_pending'] = true;
		$this->save( $identifier, $state );
	}

	/**
	 * Completes a stream baseline in one coherent write: replaces the stream
	 * cursors, stamps the baseline moment and policy hash, resolves any
	 * pending onboarding, and marks the schema current.
	 *
	 * Called at the three moments the code KNOWS the current snapshot is
	 * trustworthy: successful onboarding (add-time or cron retry), the
	 * one-time upgrade from the released plugin, and a forward-only policy
	 * rebaseline. A single update_option() means no crash can persist a
	 * partially transitioned state.
	 *
	 * @param string                                                                      $identifier  Normalised `owner/repo` identifier.
	 * @param array<string, array{last_seen_tag: string, last_seen_published_at: string}> $cursors     Stream cursors keyed by package ('' = default stream).
	 * @param string                                                                      $policy_hash Hash of the eligibility policy the cursors were built under.
	 * @return void
	 */
	public function complete_baseline( string $identifier, array $cursors, string $policy_hash ): void {
		$state = $this->get_state( $identifier );

		$state['streams']              = $cursors;
		$state['streams_baseline_at']  = time();
		$state['policy_hash']          = $policy_hash;
		$state['onboarding_pending']   = false;
		$state['stream_state_version'] = self::STREAM_STATE_VERSION;

		$this->save( $identifier, $state );
	}

	/**
	 * Records the last-seen release for one stream.
	 *
	 * Only called after a post was actually created (or an existing matching
	 * post was confirmed), so a failed generation never advances the cursor
	 * past an unprocessed release.
	 *
	 * @param string $identifier   Normalised `owner/repo` identifier.
	 * @param string $stream       Stream key: package name, or '' for the default stream.
	 * @param string $tag          Release tag string.
	 * @param string $published_at ISO 8601 publication timestamp.
	 * @return void
	 */
	public function update_stream_seen( string $identifier, string $stream, string $tag, string $published_at ): void {
		$state = $this->get_state( $identifier );

		$state['streams'][ $stream ] = [
			'last_seen_tag'          => $tag,
			'last_seen_published_at' => $published_at,
		];

		$this->save( $identifier, $state );
	}

	/**
	 * Records a newly processed release as the repo-wide last-seen state.
	 *
	 * Display / compare fallback only — monitoring reads stream cursors.
	 * Only called after a post was actually created (BR-001).
	 *
	 * @param string $identifier   Normalised `owner/repo` identifier.
	 * @param string $tag          Release tag string.
	 * @param string $published_at ISO 8601 publication timestamp.
	 * @return void
	 */
	public function update_last_seen( string $identifier, string $tag, string $published_at ): void {
		$state                           = $this->get_state( $identifier );
		$state['last_seen_tag']          = $tag;
		$state['last_seen_published_at'] = $published_at;
		$this->save( $identifier, $state );
	}

	/**
	 * Updates the last-checked timestamp for a repo to the current time.
	 *
	 * Called after every API check regardless of outcome.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return void
	 */
	public function update_last_checked( string $identifier ): void {
		$state                    = $this->get_state( $identifier );
		$state['last_checked_at'] = time();
		$this->save( $identifier, $state );
	}

	/**
	 * Removes all stored state for a repository.
	 *
	 * Called when a repo is removed from the tracked list. Re-adding the repo
	 * after removal will start with a clean state (AC-003, AC-004).
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return void
	 */
	public function clear_state( string $identifier ): void {
		delete_option( $this->option_key( $identifier ) );
	}

	/**
	 * Persists the canonical state shape for a repository.
	 *
	 * Writing exactly the canonical keys (rather than merging into the raw
	 * stored value) is what drops leftover keys from unreleased iterations
	 * of this feature.
	 *
	 * @param string               $identifier Normalised `owner/repo` identifier.
	 * @param array<string, mixed> $state      Canonical state array.
	 * @return void
	 */
	private function save( string $identifier, array $state ): void {
		update_option( $this->option_key( $identifier ), $state, false );
	}

	/**
	 * Returns the wp_options key for a given repository identifier.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return string
	 */
	private function option_key( string $identifier ): string {
		return Plugin_Constants::OPTION_REPO_STATE_PREFIX . md5( $identifier );
	}
}
