<?php
/**
 * Tests for GitHub\Version_Comparator.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\Release;
use GitHubReleasePosts\GitHub\Version_Comparator;
use WP_Mock\Tools\TestCase;

/**
 * Covers is_newer() logic: semver comparison, date fallback, and edge cases.
 */
class VersionComparatorTest extends TestCase {

	private Version_Comparator $comparator;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		$this->comparator = new Version_Comparator();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_release( string $tag, string $published_at = '2026-01-01T00:00:00Z' ): Release {
		return new Release(
			tag:          $tag,
			name:         $tag,
			body:         '',
			published_at: $published_at,
			html_url:     'https://github.com/owner/repo/releases/tag/' . $tag,
			assets:       [],
		);
	}

	private function make_state( string $last_seen_tag, string $last_seen_published_at = '' ): array {
		return [
			'last_seen_tag'          => $last_seen_tag,
			'last_seen_published_at' => $last_seen_published_at,
			'last_checked_at'        => 0,
		];
	}

	// -------------------------------------------------------------------------
	// AC-008: empty last_seen_tag — always treat as new
	// -------------------------------------------------------------------------

	/**
	 * No stored tag (newly added repo) always reports as newer (AC-008).
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_true_when_no_last_seen_tag(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( 'v1.0.0' ),
				$this->make_state( '' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// Same tag — not new
	// -------------------------------------------------------------------------

	/**
	 * Identical tag is never treated as newer.
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_false_for_same_tag(): void {
		$this->assertFalse(
			$this->comparator->is_newer(
				$this->make_release( 'v1.2.3' ),
				$this->make_state( 'v1.2.3' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// AC-006, BR-005: semver comparison (both sides semver)
	// -------------------------------------------------------------------------

	/**
	 * Higher semver tag is newer (AC-006).
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_true_for_higher_semver(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( 'v1.2.4' ),
				$this->make_state( 'v1.2.3' )
			)
		);
	}

	/**
	 * Lower semver tag is not newer.
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_false_for_lower_semver(): void {
		$this->assertFalse(
			$this->comparator->is_newer(
				$this->make_release( 'v1.2.2' ),
				$this->make_state( 'v1.2.3' )
			)
		);
	}

	/**
	 * Leading v is stripped before comparison (BR-005).
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_strips_leading_v_before_comparing(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( '2.0.0' ),
				$this->make_state( 'v1.9.9' )
			)
		);
	}

	/**
	 * Major version bump is detected correctly.
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_detects_major_version_bump(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( 'v2.0.0' ),
				$this->make_state( 'v1.99.99' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// AC-007: non-semver fallback to ISO date comparison
	// -------------------------------------------------------------------------

	/**
	 * When tags are non-semver, a later publication date is newer (AC-007).
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_uses_date_for_non_semver_tags(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( '2026-03-22', '2026-03-22T10:00:00Z' ),
				$this->make_state( '2026-03-21', '2026-03-21T10:00:00Z' )
			)
		);
	}

	/**
	 * When tags are non-semver and candidate date is earlier, it is not newer.
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_false_for_older_date(): void {
		$this->assertFalse(
			$this->comparator->is_newer(
				$this->make_release( '2026-03-20', '2026-03-20T10:00:00Z' ),
				$this->make_state( '2026-03-21', '2026-03-21T10:00:00Z' )
			)
		);
	}

	/**
	 * Missing publication dates fall back to true (better to re-process than skip).
	 *
	 * @covers Version_Comparator::is_newer
	 */
	public function test_is_newer_returns_true_when_dates_are_missing(): void {
		$this->assertTrue(
			$this->comparator->is_newer(
				$this->make_release( 'nightly', '' ),
				$this->make_state( 'nightly-old', '' )
			)
		);
	}

	// -------------------------------------------------------------------------
	// is_semver() — tag format detection
	// -------------------------------------------------------------------------

	/**
	 * @covers       Version_Comparator::is_semver
	 * @dataProvider provide_semver_tags
	 */
	public function test_is_semver_accepts_valid_semver( string $tag ): void {
		$this->assertTrue( $this->comparator->is_semver( $tag ), "Expected '$tag' to be semver" );
	}

	public static function provide_semver_tags(): array {
		return [
			[ 'v1.0.0' ],
			[ '1.0.0' ],
			[ 'v1.2' ],
			[ '1.2.3.4' ],
			[ 'v1.2.3-beta.1' ],
			[ '2.0.0+build.123' ],
		];
	}

	/**
	 * @covers       Version_Comparator::is_semver
	 * @dataProvider provide_non_semver_tags
	 */
	public function test_is_semver_rejects_non_semver( string $tag ): void {
		$this->assertFalse( $this->comparator->is_semver( $tag ), "Expected '$tag' to not be semver" );
	}

	public static function provide_non_semver_tags(): array {
		return [
			[ '2026-03-21' ],
			[ 'nightly' ],
			[ 'release-20260321' ],
			[ 'abc123def' ],
			[ '' ],
		];
	}

	/**
	 * Package tags normalize to their embedded versions, so a later-dated
	 * backport within the same package stream does not beat a higher version
	 * (peer review P2 — previously fell through to date comparison).
	 */
	public function test_package_tag_backport_is_not_newer(): void {
		$comparator = new Version_Comparator();

		$backport = new Release(
			tag:          '@acme/core@1.9.6',
			name:         '@acme/core@1.9.6',
			body:         '',
			html_url:     'https://github.com/acme/mono/releases/tag/x',
			published_at: '2026-07-10T00:00:00Z',
			assets:       [],
		);

		$state = [
			'last_seen_tag'          => '@acme/core@2.0.0',
			'last_seen_published_at' => '2026-06-01T00:00:00Z',
			'last_checked_at'        => 0,
		];

		$this->assertFalse( $comparator->is_newer( $backport, $state ) );
	}

	/**
	 * A genuinely newer package version is recognized as newer.
	 */
	public function test_package_tag_higher_version_is_newer(): void {
		$comparator = new Version_Comparator();

		$release = new Release(
			tag:          '@acme/core@2.1.0',
			name:         '@acme/core@2.1.0',
			body:         '',
			html_url:     'https://github.com/acme/mono/releases/tag/x',
			published_at: '2026-07-10T00:00:00Z',
			assets:       [],
		);

		$state = [
			'last_seen_tag'          => '@acme/core@2.0.0',
			'last_seen_published_at' => '2026-06-01T00:00:00Z',
			'last_checked_at'        => 0,
		];

		$this->assertTrue( $comparator->is_newer( $release, $state ) );
	}

	/**
	 * Cross-package comparison must use chronology, not version numbers
	 * (peer review round 2): a later core@2.0.0 is eligible even after
	 * utils@100.0.0, which would otherwise suppress it forever.
	 */
	public function test_cross_package_comparison_uses_chronology(): void {
		$comparator = new Version_Comparator();

		$core = new Release(
			tag:          '@acme/core@2.0.0',
			name:         '@acme/core@2.0.0',
			body:         '',
			html_url:     'https://github.com/acme/mono/releases/tag/x',
			published_at: '2026-07-15T00:00:00Z',
			assets:       [],
		);

		$state = [
			'last_seen_tag'          => '@acme/utils@100.0.0',
			'last_seen_published_at' => '2026-07-01T00:00:00Z',
			'last_checked_at'        => 0,
		];

		$this->assertTrue( $comparator->is_newer( $core, $state ) );
	}

	/**
	 * select_stream_winners(): groups by package including the default
	 * stream, picks by within-stream ordering — never by list position, so a
	 * later-created backport is not a winner.
	 */
	public function test_select_stream_winners(): void {
		$comparator = new Version_Comparator();

		$releases = [
			new Release( tag: '@acme/core@1.9.6', name: '', body: '', html_url: 'https://github.com/x/y/releases/tag/a', published_at: '2026-03-01T00:00:00Z', assets: [] ),
			new Release( tag: 'v9.0.0', name: '', body: '', html_url: 'https://github.com/x/y/releases/tag/b', published_at: '2026-07-01T00:00:00Z', assets: [] ),
			new Release( tag: '@acme/core@2.0.0', name: '', body: '', html_url: 'https://github.com/x/y/releases/tag/c', published_at: '2026-01-01T00:00:00Z', assets: [] ),
		];

		$winners = $comparator->select_stream_winners( $releases );

		$this->assertSame( [ '@acme/core', '' ], array_keys( $winners ) );
		$this->assertSame( '@acme/core@2.0.0', $winners['@acme/core']->tag );
		$this->assertSame( 'v9.0.0', $winners['']->tag );
	}
}
