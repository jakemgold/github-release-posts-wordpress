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

	/**
	 * derive_package() recognizes the npm/changesets tag style, scoped and not.
	 */
	public function test_derive_package_npm_style(): void {
		$this->assertSame(
			[
				'package' => '@headstartwp/core',
				'pattern' => '@headstartwp/core@*',
				'version' => '1.6.1',
			],
			Tag_Pattern_Matcher::derive_package( '@headstartwp/core@1.6.1' )
		);
		$this->assertSame(
			[
				'package' => 'mypackage',
				'pattern' => 'mypackage@*',
				'version' => '2.0.0-beta.1',
			],
			Tag_Pattern_Matcher::derive_package( 'mypackage@2.0.0-beta.1' )
		);
	}

	/**
	 * derive_package() recognizes dash-separated tag styles, and its bracket
	 * patterns keep sibling packages with a shared name prefix apart.
	 */
	public function test_derive_package_dash_styles(): void {
		$this->assertSame(
			[
				'package' => 'admin',
				'pattern' => 'admin-v[0-9]*',
				'version' => '2.1.0',
			],
			Tag_Pattern_Matcher::derive_package( 'admin-v2.1.0' )
		);
		$this->assertSame(
			[
				'package' => 'admin-utils',
				'pattern' => 'admin-utils-[0-9]*',
				'version' => '1.0.0',
			],
			Tag_Pattern_Matcher::derive_package( 'admin-utils-1.0.0' )
		);

		// The compiled patterns must match their own tags but not the sibling's.
		$this->assertTrue( Tag_Pattern_Matcher::matches( 'admin-v2.1.0', 'admin-v[0-9]*' ) );
		$this->assertFalse( Tag_Pattern_Matcher::matches( 'admin-utils-1.0.0', 'admin-[0-9]*' ) );
		$this->assertTrue( Tag_Pattern_Matcher::matches( 'admin-utils-1.0.0', 'admin-utils-[0-9]*' ) );
	}

	/**
	 * build_packages_payload() aggregates counts and latest tags per package,
	 * newest-first, and flags multi-package repos.
	 */
	public function test_build_packages_payload_aggregates(): void {
		$releases = array_map(
			static fn( string $tag ): \GitHubReleasePosts\GitHub\Release => new \GitHubReleasePosts\GitHub\Release(
				tag:          $tag,
				name:         $tag,
				body:         '',
				html_url:     'https://github.com/10up/headstartwp/releases/tag/' . rawurlencode( $tag ),
				published_at: '2026-01-01T00:00:00Z',
				assets:       [],
			),
			[ '@headstartwp/core@1.6.1', '@headstartwp/next@1.5.1', '@headstartwp/core@1.6.0' ]
		);

		$payload = Tag_Pattern_Matcher::build_packages_payload( $releases );

		$this->assertTrue( $payload['multi_package'] );
		$this->assertSame(
			[
				[
					'package'    => '@headstartwp/core',
					'pattern'    => '@headstartwp/core@*',
					'count'      => 2,
					'latest_tag' => '@headstartwp/core@1.6.1',
				],
				[
					'package'    => '@headstartwp/next',
					'pattern'    => '@headstartwp/next@*',
					'count'      => 1,
					'latest_tag' => '@headstartwp/next@1.5.1',
				],
			],
			$payload['packages']
		);
	}

	/**
	 * Single-package (or unclassifiable) release streams are not flagged as
	 * monorepos.
	 */
	public function test_build_packages_payload_single_package(): void {
		$releases = [
			new \GitHubReleasePosts\GitHub\Release(
				tag:          'v1.2.3',
				name:         'v1.2.3',
				body:         '',
				html_url:     'https://github.com/10up/plugin/releases/tag/v1.2.3',
				published_at: '2026-01-01T00:00:00Z',
				assets:       [],
			),
		];

		$payload = Tag_Pattern_Matcher::build_packages_payload( $releases );

		$this->assertFalse( $payload['multi_package'] );
		$this->assertSame( [], $payload['packages'] );
	}

	/**
	 * short_name() returns the last path segment of a package name.
	 */
	public function test_short_name(): void {
		$this->assertSame( 'core', Tag_Pattern_Matcher::short_name( '@headstartwp/core' ) );
		$this->assertSame( 'admin', Tag_Pattern_Matcher::short_name( 'admin' ) );
		$this->assertSame( 'next-redis-cache-provider', Tag_Pattern_Matcher::short_name( '@10up/next-redis-cache-provider' ) );
	}

	/**
	 * display_label() renders package tags as "short-name version" and leaves
	 * plain tags verbatim (including the full version — no headline .0 trim).
	 */
	public function test_display_label(): void {
		// Package display is opt-in via configured patterns for EVERY tag
		// style (peer review, both rounds): without the flag, all tags render
		// verbatim so existing repositories keep their exact output.
		$this->assertSame( '@headstartwp/core@1.6.1', Tag_Pattern_Matcher::display_label( '@headstartwp/core@1.6.1' ) );
		$this->assertSame( 'core 1.6.1', Tag_Pattern_Matcher::display_label( '@headstartwp/core@1.6.1', true ) );
		$this->assertSame( 'admin-v2.1.0', Tag_Pattern_Matcher::display_label( 'admin-v2.1.0' ) );
		$this->assertSame( 'admin 2.1.0', Tag_Pattern_Matcher::display_label( 'admin-v2.1.0', true ) );
		$this->assertSame( 'v7.6.1', Tag_Pattern_Matcher::display_label( 'v7.6.1' ) );
		$this->assertSame( '1.2.3', Tag_Pattern_Matcher::display_label( '1.2.3', true ) );
	}

	/**
	 * derive_package() returns null for single-package or unclassifiable tags.
	 */
	public function test_derive_package_returns_null_for_single_package_tags(): void {
		$this->assertNull( Tag_Pattern_Matcher::derive_package( 'v1.2.3' ) );
		$this->assertNull( Tag_Pattern_Matcher::derive_package( '1.2.3' ) );
		$this->assertNull( Tag_Pattern_Matcher::derive_package( 'release' ) );
		// Hyphen + bare number is a name, not a version (needs a dot).
		$this->assertNull( Tag_Pattern_Matcher::derive_package( 'html5-2' ) );
	}
}
