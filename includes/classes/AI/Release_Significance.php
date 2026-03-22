<?php
/**
 * Classifies the significance of a GitHub release.
 *
 * @package ChangelogToBlogPost\AI
 */

namespace TenUp\ChangelogToBlogPost\AI;

/**
 * Determines whether a release is a patch, minor, major, or security update.
 *
 * Classification is derived from semver parsing of the release tag. Security
 * always overrides other classifications (BR-001). Non-semver tags fall back
 * to 'minor'. A filter hook allows developer override of any classification.
 *
 * Prompt template version: 1.0 (introduced in plugin v1.0.0)
 */
class Release_Significance {

	/**
	 * Security-related keywords that trigger a 'security' classification
	 * when found in the release tag or body.
	 *
	 * @var string[]
	 */
	const SECURITY_KEYWORDS = [
		'security',
		'vulnerability',
		'cve',
		'xss',
		'injection',
		'csrf',
		'rce',
		'authentication bypass',
		'privilege escalation',
		'remote code execution',
	];

	/**
	 * Classifies a release as 'patch', 'minor', 'major', or 'security'.
	 *
	 * Priority:
	 *   1. 'security' — if tag or body contains a security keyword (BR-001).
	 *   2. 'major'    — semver N.0.0 (minor and patch both zero).
	 *   3. 'minor'    — semver x.N.0 (patch is zero, minor is non-zero).
	 *   4. 'patch'    — semver x.x.N (patch is non-zero).
	 *   5. 'minor'    — fallback for unparseable tags (AC-005).
	 *
	 * @param ReleaseData $data Release data.
	 * @return string One of: 'patch', 'minor', 'major', 'security'.
	 */
	public function classify( ReleaseData $data ): string {
		$classification = $this->detect_significance( $data );

		/**
		 * Filters the significance classification for a release.
		 *
		 * Allows developers to override the auto-classified value. The filter
		 * receives the auto-classified value, the release tag, and the release
		 * body as arguments.
		 *
		 * Example — force all releases for a specific repo to 'minor':
		 *
		 *     add_filter( 'ctbp_release_significance', function( $sig, $tag, $body ) {
		 *         return 'minor';
		 *     }, 10, 3 );
		 *
		 * @param string $classification Auto-classified value ('patch', 'minor', 'major', 'security').
		 * @param string $tag            Release tag (e.g. 'v2.1.0').
		 * @param string $body           Raw release body (Markdown).
		 */
		return (string) apply_filters( 'ctbp_release_significance', $classification, $data->tag, $data->body );
	}

	/**
	 * Performs the actual significance detection without the filter.
	 *
	 * @param ReleaseData $data
	 * @return string
	 */
	private function detect_significance( ReleaseData $data ): string {
		// Security always takes priority (BR-001).
		if ( $this->has_security_keyword( $data->tag ) || $this->has_security_keyword( $data->body ) ) {
			return 'security';
		}

		$semver = $this->parse_semver( $data->tag );

		if ( null === $semver ) {
			// Unparseable tag — log and fall back to 'minor' (AC-005).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[CTBP] Release_Significance: could not parse semver from tag "%s" — defaulting to "minor".',
					$data->tag
				) );
			}
			return 'minor';
		}

		[ 'major' => $major, 'minor' => $minor, 'patch' => $patch ] = $semver;

		if ( 0 === $minor && 0 === $patch ) {
			return 'major';
		}

		if ( 0 === $patch ) {
			return 'minor';
		}

		return 'patch';
	}

	/**
	 * Checks whether a string contains any security-related keyword.
	 *
	 * Case-insensitive search.
	 *
	 * @param string $text Text to search.
	 * @return bool
	 */
	private function has_security_keyword( string $text ): bool {
		$lower = strtolower( $text );
		foreach ( self::SECURITY_KEYWORDS as $keyword ) {
			if ( str_contains( $lower, $keyword ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parses a semver-like tag into major, minor, and patch components.
	 *
	 * Strips a leading 'v' or 'V' before parsing. Accepts N, N.N, and N.N.N
	 * formats — missing components default to zero.
	 *
	 * @param string $tag Raw release tag.
	 * @return array{major: int, minor: int, patch: int}|null Parsed components, or null if unparseable.
	 */
	public function parse_semver( string $tag ): ?array {
		// Strip leading v/V (AC-006).
		$tag = ltrim( $tag, 'vV' );

		// Extract the numeric version from the start of the string.
		if ( ! preg_match( '/^(\d+)(?:\.(\d+)(?:\.(\d+))?)?/', $tag, $matches ) ) {
			return null;
		}

		return [
			'major' => (int) $matches[1],
			'minor' => isset( $matches[2] ) ? (int) $matches[2] : 0,
			'patch' => isset( $matches[3] ) ? (int) $matches[3] : 0,
		];
	}
}
