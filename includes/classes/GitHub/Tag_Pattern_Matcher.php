<?php
/**
 * Tag pattern matching for monorepo release filtering.
 *
 * @package GitHubReleasePosts\GitHub
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Matches release tags against a comma-separated list of glob patterns.
 *
 * Monorepos cut releases for many packages from one repository
 * (e.g. `@headstartwp/core@1.6.1` next to `@10up/next-redis-cache-provider@2.0.0`);
 * per-repo tag patterns let a site publish posts for only a subset of them.
 *
 * Patterns use fnmatch() glob semantics (`*` and `?`), NOT brace expansion —
 * `{core,next}` is not supported by PHP's fnmatch(), which is exactly why the
 * format is a comma-separated list of simple globs instead of one compound
 * pattern. Matching is case-sensitive, as git tags are.
 */
final class Tag_Pattern_Matcher {

	/**
	 * Parses a comma-separated pattern string into a clean pattern list.
	 *
	 * @param string $patterns Comma-separated glob patterns.
	 * @return string[] Trimmed, non-empty patterns.
	 */
	public static function parse( string $patterns ): array {
		return array_values(
			array_filter(
				array_map( 'trim', explode( ',', $patterns ) ),
				static fn( string $pattern ): bool => '' !== $pattern
			)
		);
	}

	/**
	 * Whether the pattern string contains any usable pattern.
	 *
	 * @param string $patterns Comma-separated glob patterns.
	 * @return bool
	 */
	public static function has_patterns( string $patterns ): bool {
		return [] !== self::parse( $patterns );
	}

	/**
	 * Derives a package name and matching pattern from a monorepo-style tag.
	 *
	 * Recognized tag shapes (in priority order):
	 *   - `pkg@1.2.3` / `@scope/pkg@1.2.3`  → pattern `pkg@*` (npm/changesets)
	 *   - `pkg-v1.2.3`                       → pattern `pkg-v[0-9]*`
	 *   - `pkg-1.2.3`                        → pattern `pkg-[0-9]*`
	 *
	 * The dash-style patterns use a `[0-9]` bracket class (fnmatch supports
	 * them) so `admin-*` can't swallow a sibling `admin-utils` package.
	 * Returns null for single-package tags (`v1.2.3`, `1.2.3`) or shapes we
	 * can't classify — callers treat those repos as single-package.
	 *
	 * @param string $tag Release tag name.
	 * @return array{package: string, pattern: string, version: string}|null
	 */
	public static function derive_package( string $tag ): ?array {
		$tag = trim( $tag );

		// npm / changesets style: name@version, optionally @scope/name@version.
		if ( preg_match( '/^(?<pkg>@?[^@\s]+)@(?<ver>[0-9][^@\s]*)$/', $tag, $m ) ) {
			return [
				'package' => $m['pkg'],
				'pattern' => $m['pkg'] . '@*',
				'version' => $m['ver'],
			];
		}

		// name-v1.2.3 style.
		if ( preg_match( '/^(?<pkg>[^\s]+)-v(?<ver>[0-9][\w.\-]*)$/', $tag, $m ) ) {
			return [
				'package' => $m['pkg'],
				'pattern' => $m['pkg'] . '-v[0-9]*',
				'version' => $m['ver'],
			];
		}

		// name-1.2.3 style (version needs at least one dot to avoid matching
		// hyphenated names that merely end in a number, e.g. "html5-2").
		if ( preg_match( '/^(?<pkg>[^\s]+?)-(?<ver>[0-9]+(?:\.[0-9]+)+[\w.\-]*)$/', $tag, $m ) ) {
			return [
				'package' => $m['pkg'],
				'pattern' => $m['pkg'] . '-[0-9]*',
				'version' => $m['ver'],
			];
		}

		return null;
	}

	/**
	 * Returns the short display name for a package: the last path segment,
	 * so `@headstartwp/core` reads as `core`. Callers that show short names
	 * side by side are responsible for collision handling.
	 *
	 * @param string $package Full package name.
	 * @return string
	 */
	public static function short_name( string $package ): string {
		$pos = strrpos( $package, '/' );
		return false === $pos ? $package : substr( $package, $pos + 1 );
	}

	/**
	 * Builds the package-picker payload from a release list.
	 *
	 * Shared by the /repos/packages REST endpoint and the onboarding cache
	 * warm so both derive identical data. Releases arrive newest-first from
	 * the GitHub API, so the first tag seen for a package is its latest.
	 *
	 * @param Release[] $releases Releases, newest first.
	 * @return array{multi_package: bool, packages: array<int, array{package: string, pattern: string, count: int, latest_tag: string}>}
	 */
	public static function build_packages_payload( array $releases ): array {
		$packages = [];
		foreach ( $releases as $release ) {
			if ( ! $release instanceof Release ) {
				continue;
			}
			$derived = self::derive_package( $release->tag );
			if ( null === $derived ) {
				continue;
			}
			$key = $derived['package'];
			if ( ! isset( $packages[ $key ] ) ) {
				$packages[ $key ] = [
					'package'    => $key,
					'pattern'    => $derived['pattern'],
					'count'      => 0,
					'latest_tag' => $release->tag,
				];
			}
			++$packages[ $key ]['count'];
		}

		return [
			'multi_package' => count( $packages ) >= 2,
			'packages'      => array_values( $packages ),
		];
	}

	/**
	 * Whether a release tag is eligible under the given pattern string.
	 *
	 * An empty/blank pattern string matches every tag — the feature is opt-in
	 * and unset patterns must preserve current behavior exactly.
	 *
	 * @param string $tag      Release tag name (e.g. '@headstartwp/core@1.6.1').
	 * @param string $patterns Comma-separated glob patterns.
	 * @return bool
	 */
	public static function matches( string $tag, string $patterns ): bool {
		$parsed = self::parse( $patterns );
		if ( [] === $parsed ) {
			return true;
		}

		foreach ( $parsed as $pattern ) {
			if ( fnmatch( $pattern, $tag ) ) {
				return true;
			}
		}

		return false;
	}
}
