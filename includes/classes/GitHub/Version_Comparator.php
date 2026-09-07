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
 * Uses semver comparison when both tags look like semver — version_compare()
 * plus the semver rule that a bare version outranks its own pre-releases (see
 * compare_semver()) — falling back to ISO 8601 publication date comparison
 * for non-semver tags. Leading `v` is stripped before semver parsing (BR-005).
 *
 * Eligibility (drafts, pre-releases, tag patterns) is not this class's
 * concern — callers compare releases that already passed
 * Release_Selector::monitoring_projection().
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

		// Both semver — compare by version (AC-006, BR-005).
		if ( $this->is_semver( $candidate_tag ) && $this->is_semver( $last_tag_norm ) ) {
			return $this->compare_semver( $this->strip_v( $candidate_tag ), $this->strip_v( $last_tag_norm ) ) > 0;
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
	 * Groups releases into package streams and selects each stream's winner.
	 *
	 * The single shared selection routine (peer review round 4): onboarding
	 * baselines, cron monitoring, and latest-release selection must all agree
	 * on stream heads. Unclassifiable tags form the '' (default) stream —
	 * they are monitored too, so they must be represented here even though
	 * the package-picker UI omits them. Winners are chosen by the same
	 * within-stream ordering is_newer() applies (semantic version for one
	 * package, chronology otherwise) — NOT by GitHub's created_at list
	 * order, which crowns later-created backports.
	 *
	 * @param Release[] $releases Releases, any order.
	 * @return array<string, Release> Stream winners keyed by package ('' = default stream).
	 */
	public function select_stream_winners( array $releases ): array {
		$groups = [];
		foreach ( $releases as $release ) {
			if ( ! $release instanceof Release ) {
				continue;
			}
			$parsed           = Tag_Pattern_Matcher::derive_package( $release->tag );
			$key              = null === $parsed ? '' : $parsed['package'];
			$groups[ $key ][] = $release;
		}

		$winners = [];
		foreach ( $groups as $key => $group ) {
			$winner = $group[0];
			foreach ( array_slice( $group, 1 ) as $candidate ) {
				$state = [
					'last_seen_tag'          => $winner->tag,
					'last_seen_published_at' => $winner->published_at,
					'last_checked_at'        => 0,
				];
				if ( $this->is_newer( $candidate, $state ) ) {
					$winner = $candidate;
				}
			}
			$winners[ $key ] = $winner;
		}

		return $winners;
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
	 * Compares two semver-shaped versions.
	 *
	 * PHP's version_compare() does the work, with one correction: it reads a
	 * pre-release suffix that begins with "p" (-pre, -preview, -patch) as
	 * "patch level" and ranks it ABOVE the release, so 2.0.0 was never newer
	 * than 2.0.0-preview.1 and a final release that followed its own preview
	 * was skipped for good. When two versions share a core and exactly one is
	 * a final, the final wins (semver §11.3). Every other pair — two
	 * pre-releases, different cores, build metadata — keeps PHP's ordering
	 * unchanged; this is deliberately not a full semver comparator.
	 *
	 * @param string $a Version without a leading v.
	 * @param string $b Version without a leading v.
	 * @return int -1, 0, or 1, like version_compare().
	 */
	private function compare_semver( string $a, string $b ): int {
		[ $a_core, $a_pre ] = $this->split_prerelease( $a );
		[ $b_core, $b_pre ] = $this->split_prerelease( $b );

		if ( 0 === version_compare( $a_core, $b_core ) && ( '' === $a_pre ) !== ( '' === $b_pre ) ) {
			return '' === $a_pre ? 1 : -1;
		}

		return version_compare( $a, $b );
	}

	/**
	 * Splits a version into its core (1.2.3) and pre-release suffix ('' when
	 * none). Build metadata stays attached to whichever part it follows.
	 *
	 * @param string $version Version without a leading v.
	 * @return array{0: string, 1: string}
	 */
	private function split_prerelease( string $version ): array {
		$parts = explode( '-', $version, 2 );

		return [ $parts[0], $parts[1] ?? '' ];
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
