<?php
/**
 * Tests for Tag_Pattern_Matcher.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\Tag_Pattern_Matcher;
use WP_Mock\Tools\TestCase;

/**
 * Pure matcher — no WordPress functions involved.
 *
 * Fixture tags mirror the real 10up/headstartwp release stream (the motivating
 * monorepo case): several npm packages release from one repository, and only
 * core/next releases should become posts.
 */
class Tag_Pattern_MatcherTest extends TestCase {

	private const HEADSTARTWP_PATTERNS = '@headstartwp/core@*, @headstartwp/next@*';

	private const FIXTURE_STABLE_TAGS = [
		'@headstartwp/core@1.6.1',
		'@headstartwp/next@1.5.1',
		'@headstartwp/core@1.6.0',
		'@headstartwp/next@1.5.0',
		'@headstartwp/epio-search@1.0.0',
		'@headstartwp/core@1.5.0',
		'@headstartwp/block-primitives@0.1.0',
		'@10up/next-redis-cache-provider@2.0.0',
	];

	/**
	 * An empty or blank pattern string matches every tag (feature is opt-in;
	 * unset patterns must preserve current behavior exactly).
	 */
	public function test_empty_and_blank_patterns_match_everything(): void {
		foreach ( self::FIXTURE_STABLE_TAGS as $tag ) {
			$this->assertTrue( Tag_Pattern_Matcher::matches( $tag, '' ) );
			$this->assertTrue( Tag_Pattern_Matcher::matches( $tag, '   ' ) );
			$this->assertTrue( Tag_Pattern_Matcher::matches( $tag, ' , , ' ) );
		}
	}

	/**
	 * The motivating case: core+next patterns select exactly the core/next
	 * tags and exclude the utility packages.
	 */
	public function test_headstartwp_patterns_select_core_and_next_only(): void {
		$selected = array_values(
			array_filter(
				self::FIXTURE_STABLE_TAGS,
				static fn( string $tag ): bool => Tag_Pattern_Matcher::matches( $tag, self::HEADSTARTWP_PATTERNS )
			)
		);

		$this->assertSame(
			[
				'@headstartwp/core@1.6.1',
				'@headstartwp/next@1.5.1',
				'@headstartwp/core@1.6.0',
				'@headstartwp/next@1.5.0',
				'@headstartwp/core@1.5.0',
			],
			$selected
		);
	}

	/**
	 * A pattern for one package must not leak into similarly named packages:
	 * `@headstartwp/next@*` matches neither epio-search nor the @10up-scoped
	 * `next-redis-cache-provider`.
	 */
	public function test_patterns_do_not_match_other_packages(): void {
		$this->assertFalse( Tag_Pattern_Matcher::matches( '@10up/next-redis-cache-provider@2.0.0', '@headstartwp/next@*' ) );
		$this->assertFalse( Tag_Pattern_Matcher::matches( '@headstartwp/epio-search@1.0.0', self::HEADSTARTWP_PATTERNS ) );
	}

	/**
	 * fnmatch has NO brace expansion — a `{core,next}` pattern matches nothing.
	 * Pinned so nobody "improves" the format without implementing expansion.
	 */
	public function test_brace_syntax_is_not_supported(): void {
		$this->assertFalse( Tag_Pattern_Matcher::matches( '@headstartwp/core@1.6.1', '@headstartwp/{core,next}@*' ) );
	}

	/**
	 * `?` matches exactly one character; matching is case-sensitive.
	 */
	public function test_question_mark_and_case_sensitivity(): void {
		$this->assertTrue( Tag_Pattern_Matcher::matches( 'v1.2.3', 'v1.2.?' ) );
		$this->assertFalse( Tag_Pattern_Matcher::matches( 'v1.2.34', 'v1.2.?' ) );
		$this->assertFalse( Tag_Pattern_Matcher::matches( '@HeadstartWP/core@1.0.0', '@headstartwp/core@*' ) );
	}

	/**
	 * parse() trims whitespace around patterns and drops empty segments.
	 */
	public function test_parse_trims_and_drops_empty_segments(): void {
		$this->assertSame(
			[ 'a@*', 'b@*' ],
			Tag_Pattern_Matcher::parse( ' a@* ,  , b@*, ' )
		);
		$this->assertSame( [], Tag_Pattern_Matcher::parse( '' ) );
		$this->assertSame( [], Tag_Pattern_Matcher::parse( ' , ,, ' ) );
	}

	/**
	 * has_patterns() reflects whether any usable pattern exists.
	 */
	public function test_has_patterns(): void {
		$this->assertFalse( Tag_Pattern_Matcher::has_patterns( '' ) );
		$this->assertFalse( Tag_Pattern_Matcher::has_patterns( ' , ' ) );
		$this->assertTrue( Tag_Pattern_Matcher::has_patterns( 'v*' ) );
	}
}
