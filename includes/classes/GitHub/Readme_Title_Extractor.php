<?php
/**
 * Extracts a project's display name from the first heading of its README.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

/**
 * Pure-function utility that picks the first H1 out of a README markdown
 * string and strips formatting / decorations so it's usable as a UI label.
 *
 * Used by Repository_Settings::add_repository() to derive a friendly display
 * name from the README's first heading when one can be extracted reliably.
 * On any failure (no heading, decoration-only result, blocklisted generic
 * heading, length out of range) it returns the empty string so the caller
 * can fall back to slug-based derivation.
 */
class Readme_Title_Extractor {

	/**
	 * Minimum acceptable length for an extracted heading (post-sanitization).
	 */
	const MIN_LENGTH = 2;

	/**
	 * Maximum acceptable length for an extracted heading (post-sanitization).
	 *
	 * README H1s longer than this are almost always sentences or descriptions
	 * rather than project names.
	 */
	const MAX_LENGTH = 60;

	/**
	 * Headings that are almost certainly not project names. Matched
	 * case-insensitively after sanitization.
	 *
	 * Kept narrow on purpose — see Repository_Settings::add_repository() for
	 * the wider fallback chain.
	 *
	 * @var string[]
	 */
	private const GENERIC_BLOCKLIST = [
		'readme',
		'documentation',
		'docs',
		'installation',
		'install',
		'getting started',
		'quick start',
		'quickstart',
		'overview',
		'introduction',
		'about',
		'usage',
	];

	/**
	 * Extracts and sanitizes the first H1 from a README markdown string.
	 *
	 * @param string $markdown Raw README content (typically markdown, sometimes mixed with HTML).
	 * @return string Sanitized title, or empty string if nothing usable was found.
	 */
	public static function extract( string $markdown ): string {
		if ( '' === trim( $markdown ) ) {
			return '';
		}

		$raw = self::find_first_heading( $markdown );
		if ( '' === $raw ) {
			return '';
		}

		$sanitized = self::sanitize( $raw );
		if ( ! self::is_acceptable( $sanitized ) ) {
			return '';
		}

		return $sanitized;
	}

	/**
	 * Locates the first H1 candidate in the document, regardless of syntax.
	 *
	 * Checks (in document order) for ATX (`# Title`), setext (`Title\n===`),
	 * and HTML (`<h1>Title</h1>`) forms and returns the one that appears
	 * earliest. Returns the raw heading text — sanitization is a separate step.
	 *
	 * @param string $markdown Raw README content.
	 * @return string Raw heading text, or empty string if none found.
	 */
	private static function find_first_heading( string $markdown ): string {
		$candidates = [];

		// ATX form: a line starting with `# ` (one hash, not two — H1 only).
		// `#` must be at column 0 or after only whitespace; require space after.
		if ( preg_match( '/^[ \t]{0,3}#[ \t]+(.+?)[ \t]*#*[ \t]*$/m', $markdown, $m, PREG_OFFSET_CAPTURE ) ) {
			$candidates[ $m[0][1] ] = $m[1][0];
		}

		// Setext form: a non-empty line followed by a line of `=` characters.
		if ( preg_match( '/^(?!\s*$)(.+)\n[ \t]*=+[ \t]*$/m', $markdown, $m, PREG_OFFSET_CAPTURE ) ) {
			$candidates[ $m[0][1] ] = $m[1][0];
		}

		// HTML form: <h1>...</h1>, possibly with attributes. Case-insensitive,
		// DOTALL so content can span lines.
		if ( preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $markdown, $m, PREG_OFFSET_CAPTURE ) ) {
			$candidates[ $m[0][1] ] = $m[1][0];
		}

		if ( empty( $candidates ) ) {
			return '';
		}

		// The candidate at the smallest offset wins.
		ksort( $candidates );
		return reset( $candidates );
	}

	/**
	 * Strips markdown / HTML decorations from a raw heading.
	 *
	 * Order matters: HTML tags first (so their content stays), then markdown
	 * images (full removal — they're badges), then markdown links (keep text),
	 * then emphasis / code spans, then leading emoji and stray punctuation,
	 * then whitespace.
	 *
	 * @param string $raw Raw heading text.
	 * @return string Sanitized heading text.
	 */
	private static function sanitize( string $raw ): string {
		$text = $raw;

		// Strip Unicode bidirectional control characters (RLO/LRO and friends)
		// and invisible formatting marks. README headings can include these to
		// visually spoof the rendered name — e.g., U+202E flips subsequent
		// characters into right-to-left so `Real‮REVERSE` displays as
		// `RealESREVER`. Plain `esc_html()` doesn't encode them at render time,
		// so the cheapest defense is to drop them at the source.
		$text = preg_replace(
			'/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}\x{FEFF}\x{00AD}]/u',
			'',
			$text
		);

		// Drop HTML tags entirely (keep inner text).
		$text = wp_strip_all_tags( $text );

		// Drop markdown image syntax `![alt](url)` — badges, logos, etc.
		$text = preg_replace( '/!\[[^\]]*\]\([^)]*\)/', '', $text );

		// Convert markdown links `[text](url)` to just `text`.
		$text = preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', $text );

		// Strip code-span backticks: `text` → text.
		$text = preg_replace( '/`([^`]+)`/', '$1', $text );

		// Strip markdown emphasis markers: **bold**, __bold__, *em*, _em_.
		// Done as a two-pass: doubles first, then singles.
		$text = preg_replace( '/(\*\*|__)(.+?)\1/', '$2', $text );
		$text = preg_replace( '/(\*|_)(.+?)\1/', '$2', $text );

		// Strip leading emoji and miscellaneous symbols. Conservative — we only
		// remove characters in well-known symbol Unicode ranges from the front
		// of the string so we don't accidentally chop into the actual name.
		// Includes variation selectors (FE00–FE0F) and zero-width joiner (200D)
		// because emoji like `➡️` are sequences of base char + selector.
		$text = preg_replace(
			'/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{FE00}-\x{FE0F}\x{200D}\s]+/u',
			'',
			$text
		);

		// Collapse any runs of whitespace to single spaces and trim.
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( $text );

		// If the entire content was a markdown image (badge) or whitespace, we
		// may have stripped everything. Empty result is the signal to fall back.
		return $text;
	}

	/**
	 * Decides whether a sanitized heading is acceptable as a display name.
	 *
	 * @param string $candidate Sanitized heading text.
	 * @return bool True if usable, false if generic/empty/out-of-range.
	 */
	private static function is_acceptable( string $candidate ): bool {
		if ( '' === $candidate ) {
			return false;
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $candidate ) : strlen( $candidate );
		if ( $length < self::MIN_LENGTH || $length > self::MAX_LENGTH ) {
			return false;
		}

		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $candidate ) : strtolower( $candidate );
		if ( in_array( $lower, self::GENERIC_BLOCKLIST, true ) ) {
			return false;
		}

		return true;
	}
}
