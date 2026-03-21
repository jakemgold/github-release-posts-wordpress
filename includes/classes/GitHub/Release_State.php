<?php
/**
 * Per-repo release state storage.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\GitHub;

use TenUp\ChangelogToBlogPost\Plugin_Constants;

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
	 * @return array{last_seen_tag: string, last_seen_published_at: string, last_checked_at: int}
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
		];
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
		$state                          = $this->get_state( $identifier );
		$state['last_seen_tag']         = $tag;
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
