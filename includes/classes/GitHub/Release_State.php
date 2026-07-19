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
 * Manages per-repository monitoring state: last seen release tag,
 * last seen publication date, and last checked timestamp.
 *
 * Each repo's state is stored in its own wp_options entry keyed by
 * OPTION_REPO_STATE_PREFIX . md5( $identifier ).
 */
class Release_State {

	/**
	 * Returns the state array for a repository, with defaults for any missing keys.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return array{last_seen_tag: string, last_seen_published_at: string, last_checked_at: int, packages: array<string, array{last_seen_tag: string, last_seen_published_at: string}>, streams_baseline_at: int, is_monorepo: bool, topology_checked_at: int}
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
			// Per-package cursors, keyed by package name — only populated for
			// repos with tag patterns, where one repo-wide cursor would drop
			// sibling releases published between checks.
			'packages'               => (array) ( $stored['packages'] ?? [] ),
			// When stream monitoring was baselined for this repo (0 = never).
			// An explicit marker: cursor-map emptiness is NOT a reliable
			// migration signal (peer review round 3) — plain-tag posts also
			// write a default-stream cursor, and a repo added before its
			// first release has a legitimately empty map.
			'streams_baseline_at'    => (int) ( $stored['streams_baseline_at'] ?? 0 ),
			// Durable monorepo determination, set wherever the full release
			// list is observed — one latest tag is not enough to infer
			// repository topology.
			'is_monorepo'            => (bool) ( $stored['is_monorepo'] ?? false ),
			// When topology was last determined from a full list (0 = never).
			// A false is_monorepo is only trusted while fresh: repositories
			// can BECOME monorepos (peer review round 4), so the monitor
			// re-inspects the list when this goes stale.
			'topology_checked_at'    => (int) ( $stored['topology_checked_at'] ?? 0 ),
		];
	}

	/**
	 * Establishes the stream-monitoring baseline for a repository: merges the
	 * given per-package cursors (possibly none) and stamps the baseline
	 * marker. Called at the moments the code KNOWS current releases are
	 * historical — onboarding, and the one-time migration of repos that
	 * predate stream monitoring.
	 *
	 * @param string                                                                      $identifier Normalised `owner/repo` identifier.
	 * @param array<string, array{last_seen_tag: string, last_seen_published_at: string}> $cursors Initial cursors keyed by package.
	 * @return void
	 */
	public function seed_streams( string $identifier, array $cursors ): void {
		$stored = get_option( $this->option_key( $identifier ), [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$stored['packages']            = array_merge( (array) ( $stored['packages'] ?? [] ), $cursors );
		$stored['streams_baseline_at'] = time();

		update_option( $this->option_key( $identifier ), $stored, false );
	}

	/**
	 * Records whether the repository is a monorepo (releases multiple
	 * packages), as determined from a full release list.
	 *
	 * @param string $identifier   Normalised `owner/repo` identifier.
	 * @param bool   $is_monorepo  Determination.
	 * @return void
	 */
	public function set_monorepo( string $identifier, bool $is_monorepo ): void {
		$stored = get_option( $this->option_key( $identifier ), [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$stored['is_monorepo']         = $is_monorepo;
		$stored['topology_checked_at'] = time();
		update_option( $this->option_key( $identifier ), $stored, false );
	}

	/**
	 * Records the last-seen release for one package stream of a monorepo.
	 *
	 * Mirrors update_last_seen(): only called by the cron pipeline after a
	 * post was actually created, so a failed generation never advances the
	 * cursor past an unprocessed release.
	 *
	 * @param string $identifier   Normalised `owner/repo` identifier.
	 * @param string $package      Package name (from Tag_Pattern_Matcher::derive_package()).
	 * @param string $tag          Release tag string.
	 * @param string $published_at ISO 8601 publication timestamp.
	 * @return void
	 */
	public function update_package_seen( string $identifier, string $package, string $tag, string $published_at ): void {
		$stored = get_option( $this->option_key( $identifier ), [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		$packages             = (array) ( $stored['packages'] ?? [] );
		$packages[ $package ] = [
			'last_seen_tag'          => $tag,
			'last_seen_published_at' => $published_at,
		];
		$stored['packages']   = $packages;

		update_option( $this->option_key( $identifier ), $stored, false );
	}

	/**
	 * Records a newly processed release as the last-seen state for a repo.
	 *
	 * Only called by the cron pipeline (BR-001 — never by manual trigger).
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
		update_option( $this->option_key( $identifier ), $state, false );
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
		update_option( $this->option_key( $identifier ), $state, false );
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
	 * Returns the wp_options key for a given repository identifier.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return string
	 */
	private function option_key( string $identifier ): string {
		return Plugin_Constants::OPTION_REPO_STATE_PREFIX . md5( $identifier );
	}
}
