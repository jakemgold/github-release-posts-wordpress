<?php
/**
 * Release version comparison logic.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @param Release                                                                            $candidate The release fetched from GitHub.
	 * @param array{last_seen_tag: string, last_seen_published_at: string, last_checked_at: int} $state     The stored repo state.
	 * @return bool
	 */
	public function is_newer( Release $candidate, array $state ): bool {
		$last_tag = $state['last_seen_tag'];

		// No last-seen tag means the repo is newly added — always treat as new (AC-008).
		if ( '' === $last_tag ) {
			return true;
		}

		// Same tag — not new.
		if ( $last_tag === $candidate->tag ) {
			return false;
		}

		// Package tags ("@acme/core@1.9.6") never parse as semver directly, so
		// they used to fall through to date comparison — letting a later-dated
		// backport beat a higher version, the exact case the semver branch
		// exists to prevent. Normalize to the embedded version — but ONLY when
		// both tags belong to the SAME package: comparing core@2.0.0 against
		// utils@100.0.0 by version number is meaningless and would suppress
		// lower-versioned packages indefinitely. Across different packages
		// (or package vs. plain tag) fall through to release chronology.
		$candidate_pkg = Tag_Pattern_Matcher::derive_package( $candidate->tag );
		$last_pkg      = Tag_Pattern_Matcher::derive_package( $last_tag );

		$candidate_tag = $candidate->tag;
		$last_tag_norm = $last_tag;
		if ( null !== $candidate_pkg && null !== $last_pkg && $candidate_pkg['package'] === $last_pkg['package'] ) {
			$candidate_tag = $candidate_pkg['version'];
			$last_tag_norm = $last_pkg['version'];
		} elseif ( null !== $candidate_pkg || null !== $last_pkg ) {
			// Mixed or cross-package: force the chronology branch below.
			$candidate_tag = '';
			$last_tag_norm = '';
		}

		// Both semver — use version_compare (AC-006, BR-005).
		if ( $this->is_semver( $candidate_tag ) && $this->is_semver( $last_tag_norm ) ) {
			return version_compare(
				$this->strip_v( $candidate_tag ),
				$this->strip_v( $last_tag_norm ),
				'>'
			);
		}

		// Non-semver — compare ISO 8601 publication dates (AC-007).
		// ISO 8601 strings sort correctly as strings (lexicographic order).
		$candidate_date = $candidate->published_at;
		$last_date      = $state['last_seen_published_at'];

		if ( '' === $candidate_date || '' === $last_date ) {
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
