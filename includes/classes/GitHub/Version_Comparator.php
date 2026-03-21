<?php
/**
 * Release version comparison logic.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\GitHub;

/**
 * Determines whether a candidate release is newer than the last-seen release.
 *
 * Uses semver comparison (via version_compare) when both tags look like semver,
 * falling back to ISO 8601 publication date comparison for non-semver tags.
 * Leading `v` is stripped before semver parsing (BR-005).
 *
 * Pre-releases and draft releases are never presented by the /releases/latest
 * endpoint, so no additional filtering is required here (AC-009).
 */
class Version_Comparator {

	/**
	 * Returns true if the candidate release is newer than the stored state.
	 *
	 * @param Release                                                              $candidate The release fetched from GitHub.
	 * @param array{last_seen_tag: string, last_seen_published_at: string, last_checked_at: int} $state     The stored repo state.
	 * @return bool
	 */
	public function is_newer( Release $candidate, array $state ): bool {
		$last_tag = $state['last_seen_tag'] ?? '';

		// No last-seen tag means the repo is newly added — always treat as new (AC-008).
		if ( $last_tag === '' ) {
			return true;
		}

		// Same tag — not new.
		if ( $candidate->tag === $last_tag ) {
			return false;
		}

		// Both semver — use version_compare (AC-006, BR-005).
		if ( $this->is_semver( $candidate->tag ) && $this->is_semver( $last_tag ) ) {
			return version_compare(
				$this->strip_v( $candidate->tag ),
				$this->strip_v( $last_tag ),
				'>'
			);
		}

		// Non-semver — compare ISO 8601 publication dates (AC-007).
		// ISO 8601 strings sort correctly as strings (lexicographic order).
		$candidate_date = $candidate->published_at;
		$last_date      = $state['last_seen_published_at'] ?? '';

		if ( $candidate_date === '' || $last_date === '' ) {
			// Can't compare without dates — treat as new to avoid silently skipping.
			return true;
		}

		return $candidate_date > $last_date;
	}

	/**
	 * Returns true if a tag string looks like a semver version (with optional leading v).
	 *
	 * Accepts formats like: v1.2.3, 1.2.3, v1.2, 1.2.
	 * Rejects pure date strings, hash-like tags, and arbitrary words.
	 *
	 * @param string $tag Release tag.
	 * @return bool
	 */
	public function is_semver( string $tag ): bool {
		return (bool) preg_match( '/^v?\d+\.\d+(\.\d+)?(\.\d+)?(-[a-zA-Z0-9.]+)?(\+[a-zA-Z0-9.]+)?$/', $tag );
	}

	/**
	 * Strips a leading `v` from a version tag before parsing (BR-005).
	 *
	 * @param string $tag Tag string.
	 * @return string
	 */
	private function strip_v( string $tag ): string {
		return ltrim( $tag, 'vV' );
	}
}
