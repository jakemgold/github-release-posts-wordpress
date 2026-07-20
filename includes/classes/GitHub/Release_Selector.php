<?php
/**
 * Pure release-selection helpers: eligibility projection and latest-head choice.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure functions that turn one raw release snapshot into the two views the
 * plugin needs, and derive decisions from them.
 *
 * The snapshot (API_Client::fetch_release_snapshot()) is fetched once and
 * projected twice:
 *
 *  - DISCOVERY: what packages exist, for the Quick Edit package chooser.
 *    Includes pre-releases and ignores tag patterns — the chooser must show
 *    packages the current filter excludes so they can be re-enabled. Built
 *    by Tag_Pattern_Matcher::build_packages_payload().
 *  - MONITORING: which releases are eligible for content, for baselines,
 *    cursors, latest selection, and generation. Built here by
 *    monitoring_projection().
 *
 * Monitoring cursors must NEVER be created from discovery data: a repository
 * with pre-releases excluded would get a cursor pinned at a pre-release
 * version, silently swallowing the next stable release below it.
 */
final class Release_Selector {

	/**
	 * Filters a raw snapshot down to the releases eligible for content under
	 * a repository's policy (pre-release setting + effective tag patterns).
	 *
	 * GitHub drafts are already excluded at fetch time. Order is preserved
	 * (newest first, as GitHub returns them).
	 *
	 * @param Release[] $snapshot            Raw snapshot releases.
	 * @param bool      $include_prereleases When true, pre-releases are eligible.
	 * @param string    $tag_patterns        Comma-separated glob patterns ('' = all).
	 * @return Release[] Eligible releases.
	 */
	public static function monitoring_projection( array $snapshot, bool $include_prereleases, string $tag_patterns ): array {
		$eligible = [];
		foreach ( $snapshot as $release ) {
			if ( ! $release instanceof Release ) {
				continue;
			}
			if ( ! $include_prereleases && $release->prerelease ) {
				continue;
			}
			if ( ! Tag_Pattern_Matcher::matches( $release->tag, $tag_patterns ) ) {
				continue;
			}
			$eligible[] = $release;
		}

		return $eligible;
	}

	/**
	 * Selects the latest eligible release using the shared two-stage rule:
	 * highest version within each package stream, then newest by publication
	 * date among the stream winners.
	 *
	 * A single flat reduction is not valid when packages mix: same-package
	 * comparisons use versions while cross-package ones use chronology, and
	 * combining the two relations in one pass is non-transitive (a January
	 * core@2.0.0 could displace the February release of another package via a
	 * March core backport). Shared by generation, the version picker, and
	 * onboarding so every surface crowns the same release.
	 *
	 * @param Release[] $releases Eligible releases (any order).
	 * @return Release|null Latest eligible release, or null for an empty list.
	 */
	public static function select_latest_head( array $releases ): ?Release {
		if ( empty( $releases ) ) {
			return null;
		}

		$winners = ( new Version_Comparator() )->select_stream_winners( $releases );

		$latest = null;
		foreach ( $winners as $winner ) {
			if ( null === $latest || $winner->published_at > $latest->published_at ) {
				$latest = $winner;
			}
		}

		return $latest;
	}

	/**
	 * Computes the policy hash for a repository's content-eligibility inputs.
	 *
	 * Stored with the stream baseline; when the effective policy changes, the
	 * monitor rebaselines forward-only instead of letting cursors written
	 * under the old policy fight the new one (e.g. a pre-release cursor
	 * blocking a lower-versioned stable release after pre-releases are
	 * turned off).
	 *
	 * Patterns are normalized through Tag_Pattern_Matcher::parse() so
	 * insignificant formatting differences (spacing, empty segments) do not
	 * read as policy changes.
	 *
	 * @param bool   $include_prereleases Repository pre-release setting.
	 * @param string $tag_patterns        Effective tag patterns (after the
	 *                                    ghrp_repo_tag_patterns filter).
	 * @return string
	 */
	public static function policy_hash( bool $include_prereleases, string $tag_patterns ): string {
		return md5( ( $include_prereleases ? '1' : '0' ) . '|' . implode( ',', Tag_Pattern_Matcher::parse( $tag_patterns ) ) );
	}

	/**
	 * Computes the onboarding decision for a repository from its eligible
	 * releases: which stream cursors to baseline and which release (if any)
	 * to generate as the initial post.
	 *
	 * The matrix (shared verbatim by add-time onboarding and the cron retry
	 * of a failed add, so both paths behave identically):
	 *
	 *  - No eligible releases → empty ready baseline, generate nothing;
	 *    the first later release generates through normal monitoring.
	 *  - Package choice available in the UI (2+ recognized packages) →
	 *    baseline every eligible stream head, generate nothing; the admin
	 *    chooses packages first.
	 *  - Otherwise (single or mixed package/plain) → baseline every eligible
	 *    stream head EXCEPT the overall latest release's stream, and generate
	 *    that latest release. Writing the baseline while omitting the initial
	 *    stream's cursor is crash-safe: successful generation advances the
	 *    cursor; failed generation leaves it absent, so cron retries; other
	 *    current streams are already baselined and cannot burst.
	 *
	 * @param Release[] $eligible_releases Monitoring-projection releases.
	 * @param bool      $ui_choice         Whether the Packages chooser will
	 *                                     actually render (2+ recognized
	 *                                     packages in the discovery payload).
	 * @return array{cursors: array<string, array{last_seen_tag: string, last_seen_published_at: string}>, initial: Release|null}
	 */
	public static function onboarding_plan( array $eligible_releases, bool $ui_choice ): array {
		if ( empty( $eligible_releases ) ) {
			return [
				'cursors' => [],
				'initial' => null,
			];
		}

		$winners = ( new Version_Comparator() )->select_stream_winners( $eligible_releases );

		$initial        = null;
		$initial_stream = null;
		if ( ! $ui_choice ) {
			$initial = self::select_latest_head( $eligible_releases );
			if ( null !== $initial ) {
				$parsed         = Tag_Pattern_Matcher::derive_package( $initial->tag );
				$initial_stream = null === $parsed ? '' : $parsed['package'];
			}
		}

		$cursors = [];
		foreach ( $winners as $stream => $winner ) {
			if ( null !== $initial_stream && (string) $stream === (string) $initial_stream ) {
				continue; // Left pending for the initial generation / cron retry.
			}
			$cursors[ (string) $stream ] = [
				'last_seen_tag'          => $winner->tag,
				'last_seen_published_at' => $winner->published_at,
			];
		}

		return [
			'cursors' => $cursors,
			'initial' => $initial,
		];
	}
}
