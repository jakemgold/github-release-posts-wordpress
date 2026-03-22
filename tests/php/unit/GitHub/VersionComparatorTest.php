<?php
/**
 * Tests for GitHub\Version_Comparator.
 *
 * @package ChangelogToBlogPost\Tests\GitHub
 */

namespace TenUp\ChangelogToBlogPost\Tests\GitHub;

use TenUp\ChangelogToBlogPost\GitHub\Release;
use TenUp\ChangelogToBlogPost\GitHub\Version_Comparator;
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
}
