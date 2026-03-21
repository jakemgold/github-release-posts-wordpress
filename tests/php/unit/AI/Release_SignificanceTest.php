<?php
/**
 * Tests for Release_Significance.
 *
 * @package ChangelogToBlogPost\Tests\AI
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI;

use TenUp\ChangelogToBlogPost\AI\Release_Significance;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use WP_Mock\Tools\TestCase;

/**
 * @covers \TenUp\ChangelogToBlogPost\AI\Release_Significance
 */
class Release_SignificanceTest extends TestCase {

	private Release_Significance $significance;

	public function setUp(): void {
		parent::setUp();
		$this->significance = new Release_Significance();
	}

	// -------------------------------------------------------------------------
	// parse_semver()
	// -------------------------------------------------------------------------

	public function test_parse_semver_full_version(): void {
		$result = $this->significance->parse_semver( '1.2.3' );
		$this->assertSame( [ 'major' => 1, 'minor' => 2, 'patch' => 3 ], $result );
	}

	public function test_parse_semver_strips_lowercase_v(): void {
		$result = $this->significance->parse_semver( 'v2.0.0' );
		$this->assertSame( [ 'major' => 2, 'minor' => 0, 'patch' => 0 ], $result );
	}

	public function test_parse_semver_strips_uppercase_v(): void {
		$result = $this->significance->parse_semver( 'V3.1.0' );
		$this->assertSame( [ 'major' => 3, 'minor' => 1, 'patch' => 0 ], $result );
	}

	public function test_parse_semver_major_only(): void {
		$result = $this->significance->parse_semver( '2' );
		$this->assertSame( [ 'major' => 2, 'minor' => 0, 'patch' => 0 ], $result );
	}

	public function test_parse_semver_major_minor_only(): void {
		$result = $this->significance->parse_semver( '1.5' );
		$this->assertSame( [ 'major' => 1, 'minor' => 5, 'patch' => 0 ], $result );
	}

	public function test_parse_semver_returns_null_for_non_numeric(): void {
		$result = $this->significance->parse_semver( 'not-a-version' );
		$this->assertNull( $result );
	}

	public function test_parse_semver_returns_null_for_empty_string(): void {
		$result = $this->significance->parse_semver( '' );
		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// classify() — semver-based classification
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider semver_classification_provider
	 */
	public function test_classify_by_semver( string $tag, string $expected ): void {
		$data = $this->make_release_data( $tag, 'No security content here.' );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( $expected, $tag, 'No security content here.' )
			->reply( $expected );

		$result = $this->significance->classify( $data );
		$this->assertSame( $expected, $result );
	}

	public static function semver_classification_provider(): array {
		return [
			'patch release'           => [ 'v1.2.3', 'patch' ],
			'patch release no v'      => [ '0.5.1', 'patch' ],
			'minor release'           => [ 'v1.5.0', 'minor' ],
			'minor release no v'      => [ '2.1.0', 'minor' ],
			'major release'           => [ 'v2.0.0', 'major' ],
			'major release no v'      => [ '3.0.0', 'major' ],
		];
	}

	public function test_classify_falls_back_to_minor_for_non_semver(): void {
		$data = $this->make_release_data( 'release-2024', 'Some content.' );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'minor', 'release-2024', 'Some content.' )
			->reply( 'minor' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'minor', $result );
	}

	// -------------------------------------------------------------------------
	// classify() — security keyword detection
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider security_keyword_provider
	 */
	public function test_classify_security_keyword_in_body( string $keyword ): void {
		$body = "This release fixes a {$keyword} issue.";
		$data = $this->make_release_data( 'v1.2.3', $body );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'security', 'v1.2.3', $body )
			->reply( 'security' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'security', $result );
	}

	/**
	 * @dataProvider security_keyword_provider
	 */
	public function test_classify_security_keyword_in_tag( string $keyword ): void {
		$tag  = "v1.0.0-{$keyword}";
		$data = $this->make_release_data( $tag, 'Normal release body.' );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'security', $tag, 'Normal release body.' )
			->reply( 'security' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'security', $result );
	}

	public static function security_keyword_provider(): array {
		return [
			'security'              => [ 'security' ],
			'vulnerability'         => [ 'vulnerability' ],
			'cve'                   => [ 'cve' ],
			'xss'                   => [ 'xss' ],
			'injection'             => [ 'injection' ],
			'csrf'                  => [ 'csrf' ],
			'rce'                   => [ 'rce' ],
		];
	}

	public function test_classify_security_is_case_insensitive(): void {
		$data = $this->make_release_data( 'v1.2.3', 'SECURITY fix applied.' );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'security', 'v1.2.3', 'SECURITY fix applied.' )
			->reply( 'security' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'security', $result );
	}

	public function test_classify_security_overrides_major( ): void {
		// v2.0.0 would normally be 'major', but security keyword wins.
		$data = $this->make_release_data( 'v2.0.0', 'Fixes a critical vulnerability.' );

		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'security', 'v2.0.0', 'Fixes a critical vulnerability.' )
			->reply( 'security' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'security', $result );
	}

	// -------------------------------------------------------------------------
	// classify() — filter override
	// -------------------------------------------------------------------------

	public function test_classify_filter_can_override(): void {
		$data = $this->make_release_data( 'v1.2.3', 'Normal patch.' );

		// Filter overrides 'patch' → 'minor'.
		\WP_Mock::onFilter( 'ctbp_release_significance' )
			->with( 'patch', 'v1.2.3', 'Normal patch.' )
			->reply( 'minor' );

		$result = $this->significance->classify( $data );
		$this->assertSame( 'minor', $result );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_release_data( string $tag, string $body ): ReleaseData {
		return new ReleaseData(
			identifier:   'owner/repo',
			tag:          $tag,
			name:         "Release {$tag}",
			body:         $body,
			html_url:     'https://github.com/owner/repo/releases/tag/' . $tag,
			published_at: '2025-01-01T00:00:00Z',
			assets:       [],
		);
	}
}
