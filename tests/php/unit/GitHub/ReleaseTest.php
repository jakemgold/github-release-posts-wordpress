<?php
/**
 * Tests for GitHub\Release value object.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace Jakemgold\GitHubReleasePosts\Tests\GitHub;

use Jakemgold\GitHubReleasePosts\GitHub\Release;
use WP_Mock\Tools\TestCase;

/**
 * Verifies that Release::from_api_response() maps all GitHub API fields correctly.
 */
class ReleaseTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// AC-001: from_api_response maps all required fields
	// -------------------------------------------------------------------------

	/**
	 * from_api_response() maps all GitHub API fields to Release properties.
	 *
	 * @covers Release::from_api_response
	 */
	public function test_from_api_response_maps_all_fields(): void {
		$data = [
			'tag_name'     => 'v2.3.1',
			'name'         => 'Version 2.3.1',
			'body'         => '## Changelog\n\n- Fixed a bug',
			'published_at' => '2026-03-21T12:00:00Z',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v2.3.1',
			'assets'       => [
				[ 'name' => 'plugin.zip', 'browser_download_url' => 'https://example.com/plugin.zip' ],
			],
		];

		$release = Release::from_api_response( $data );

		$this->assertSame( 'v2.3.1', $release->tag );
		$this->assertSame( 'Version 2.3.1', $release->name );
		$this->assertSame( '## Changelog\n\n- Fixed a bug', $release->body );
		$this->assertSame( '2026-03-21T12:00:00Z', $release->published_at );
		$this->assertSame( 'https://github.com/owner/repo/releases/tag/v2.3.1', $release->html_url );
		$this->assertCount( 1, $release->assets );
	}

	/**
	 * When 'name' is empty, from_api_response() falls back to tag_name.
	 *
	 * @covers Release::from_api_response
	 */
	public function test_from_api_response_falls_back_name_to_tag_name(): void {
		$data = [
			'tag_name'     => 'v1.0.0',
			'name'         => '',
			'body'         => '',
			'published_at' => '2026-01-01T00:00:00Z',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v1.0.0',
			'assets'       => [],
		];

		$release = Release::from_api_response( $data );

		$this->assertSame( 'v1.0.0', $release->name, 'Name should fall back to tag_name when empty' );
	}

	/**
	 * Missing optional fields default to empty values without errors.
	 *
	 * @covers Release::from_api_response
	 */
	public function test_from_api_response_defaults_missing_optional_fields(): void {
		$data = [
			'tag_name'     => 'v0.1.0',
			'published_at' => '2026-01-01T00:00:00Z',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v0.1.0',
		];

		$release = Release::from_api_response( $data );

		$this->assertSame( '', $release->body );
		$this->assertSame( [], $release->assets );
	}
}
