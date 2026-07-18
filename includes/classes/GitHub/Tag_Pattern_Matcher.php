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
