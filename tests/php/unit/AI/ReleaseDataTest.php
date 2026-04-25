<?php
/**
 * Tests for AI\ReleaseData value object.
 *
 * @package GitHubReleasePosts\Tests\AI
 */

namespace Jakemgold\GitHubReleasePosts\Tests\AI;

use Jakemgold\GitHubReleasePosts\AI\ReleaseData;
use WP_Mock\Tools\TestCase;

class ReleaseDataTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_constructor_sets_all_properties(): void {
		$data = new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.2.3',
			name:         'Release 1.2.3',
			body:         '## Changes',
			html_url:     'https://github.com/owner/repo/releases/tag/v1.2.3',
			published_at: '2026-01-01T00:00:00Z',
			assets:       [ [ 'name' => 'release.zip' ] ],
		);

		$this->assertSame( 'owner/repo', $data->identifier );
		$this->assertSame( 'v1.2.3', $data->tag );
		$this->assertSame( 'Release 1.2.3', $data->name );
		$this->assertSame( '## Changes', $data->body );
		$this->assertSame( 'https://github.com/owner/repo/releases/tag/v1.2.3', $data->html_url );
		$this->assertSame( '2026-01-01T00:00:00Z', $data->published_at );
		$this->assertCount( 1, $data->assets );
	}

	public function test_from_entry_maps_all_fields(): void {
		$entry = [
			'identifier'   => 'owner/repo',
			'tag'          => 'v2.0.0',
			'name'         => 'v2.0.0',
			'body'         => 'body text',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v2.0.0',
			'published_at' => '2026-03-21T00:00:00Z',
			'assets'       => [],
		];

		$data = ReleaseData::from_entry( $entry );

		$this->assertSame( 'owner/repo', $data->identifier );
		$this->assertSame( 'v2.0.0', $data->tag );
		$this->assertSame( 'body text', $data->body );
	}

	public function test_from_entry_handles_missing_fields_gracefully(): void {
		$data = ReleaseData::from_entry( [] );

		$this->assertSame( '', $data->identifier );
		$this->assertSame( '', $data->tag );
		$this->assertSame( [], $data->assets );
	}

	public function test_assets_defaults_to_empty_array(): void {
		$data = new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.0.0',
			name:         'v1.0.0',
			body:         '',
			html_url:     '',
			published_at: '',
		);

		$this->assertSame( [], $data->assets );
	}
}
