<?php
/**
 * Tests for Prompt_Builder.
 *
 * @package GitHubReleasePosts\Tests\AI
 */

namespace Jakemgold\GitHubReleasePosts\Tests\AI;

use Jakemgold\GitHubReleasePosts\AI\Prompt_Builder;
use Jakemgold\GitHubReleasePosts\AI\Release_Significance;
use Jakemgold\GitHubReleasePosts\AI\ReleaseData;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \Jakemgold\GitHubReleasePosts\AI\Prompt_Builder
 */
class Prompt_BuilderTest extends TestCase {

	private Prompt_Builder $builder;
	private Repository_Settings $repo_settings;
	private Release_Significance $significance;
	private Global_Settings $global_settings;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings   = \Mockery::mock( Repository_Settings::class );
		$this->significance    = \Mockery::mock( Release_Significance::class );
		$this->global_settings = \Mockery::mock( Global_Settings::class );
		$this->global_settings->shouldReceive( 'get_custom_prompt_instructions' )->andReturn( '' )->byDefault();
		$this->global_settings->shouldReceive( 'get_audience_level' )->andReturn( 'mixed' )->byDefault();
		$this->builder = new Prompt_Builder( $this->repo_settings, $this->significance, $this->global_settings );
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_filter(): void {
		\WP_Mock::expectFilterAdded( 'ghrp_generate_prompt', [ $this->builder, 'build' ], 10, 2 );
		$this->builder->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// resolve_download_link() — plugin_link: URL vs slug vs fallback
	// -------------------------------------------------------------------------

	public function test_resolve_download_link_uses_url(): void {
		$config = [ 'plugin_link' => 'https://example.com/download' ];
		$data   = $this->make_release_data();

		$result = $this->builder->resolve_download_link( $config, $data );
		$this->assertSame( 'https://example.com/download', $result );
	}

	public function test_resolve_download_link_uses_slug_as_wporg_url(): void {
		$config = [ 'plugin_link' => 'my-plugin' ];
		$data   = $this->make_release_data();

		$result = $this->builder->resolve_download_link( $config, $data );
		$this->assertSame( 'https://wordpress.org/plugins/my-plugin/', $result );
	}

	public function test_resolve_download_link_falls_back_to_html_url(): void {
		$config = [];
		$data   = $this->make_release_data();

		$result = $this->builder->resolve_download_link( $config, $data );
		$this->assertSame( 'https://github.com/owner/repo/releases/tag/v1.2.0', $result );
	}

	// -------------------------------------------------------------------------
	// extract_images()
	// -------------------------------------------------------------------------

	public function test_extract_images_finds_markdown_images(): void {
		$body   = 'Check this out: ![screenshot](https://example.com/img.png) and text.';
		$result = $this->builder->extract_images( $body );
		$this->assertSame( [ 'https://example.com/img.png' ], $result );
	}

	public function test_extract_images_finds_html_img_tags(): void {
		$body   = '<p>Look: <img src="https://example.com/shot.jpg" alt="demo"></p>';
		$result = $this->builder->extract_images( $body );
		$this->assertSame( [ 'https://example.com/shot.jpg' ], $result );
	}

	public function test_extract_images_deduplicates(): void {
		$body = '![a](https://example.com/img.png) and <img src="https://example.com/img.png">';
		$result = $this->builder->extract_images( $body );
		$this->assertSame( [ 'https://example.com/img.png' ], $result );
	}

	public function test_extract_images_returns_empty_when_none(): void {
		$result = $this->builder->extract_images( 'No images here.' );
		$this->assertSame( [], $result );
	}

	public function test_extract_images_finds_multiple(): void {
		$body = '![a](https://example.com/1.png) ![b](https://example.com/2.png)';
		$result = $this->builder->extract_images( $body );
		$this->assertCount( 2, $result );
		$this->assertContains( 'https://example.com/1.png', $result );
		$this->assertContains( 'https://example.com/2.png', $result );
	}

	// -------------------------------------------------------------------------
	// build() — full prompt assembly
	// -------------------------------------------------------------------------

	public function test_build_returns_string_with_release_info(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->once()
			->andReturn( [
				'identifier'   => 'owner/repo',
				'display_name' => 'My Plugin',
				'plugin_link'  => 'my-plugin',
			] );

		$this->significance->shouldReceive( 'classify' )
			->once()
			->with( $data )
			->andReturn( 'minor' );

		// Let sub-filters pass through (WP_Mock default behavior).

		$result = $this->builder->build( '', $data );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'My Plugin', $result );
		$this->assertStringContainsString( 'v1.2.0', $result );
		$this->assertStringContainsString( 'Minor', $result );
		$this->assertStringContainsString( 'wordpress.org/plugins/my-plugin/', $result );
	}

	public function test_build_derives_display_name_when_not_configured(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->once()
			->andReturn( [] ); // No matching repo config.

$this->significance->shouldReceive( 'classify' )
			->once()
			->andReturn( 'patch' );

		// Let sub-filters pass through (WP_Mock default behavior).

		$result = $this->builder->build( '', $data );

		// "owner/repo" → derive from "repo" → "Repo".
		$this->assertStringContainsString( 'Repo', $result );
	}

	public function test_build_includes_image_placeholders_when_no_images(): void {
		$data = $this->make_release_data( 'No images in this release body.' );

		$this->repo_settings->shouldReceive( 'get_repository' )->andReturn( [] );
$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );

		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'Do not include any image placeholders or suggestions.', $result );
	}

	public function test_build_includes_actual_images_when_present(): void {
		$body = 'New feature: ![screenshot](https://example.com/new.png)';
		$data = $this->make_release_data( $body );

		$this->repo_settings->shouldReceive( 'get_repository' )->andReturn( [] );
$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );

		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'https://example.com/new.png', $result );
		$this->assertStringNotContainsString( 'Image suggestion', $result );
	}

	public function test_build_response_format_instructions(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repository' )->andReturn( [] );
$this->significance->shouldReceive( 'classify' )->andReturn( 'patch' );

		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'Line 1: Your subtitle ONLY', $result );
		$this->assertStringContainsString( 'Do NOT use Markdown', $result );
	}

	public function test_build_includes_custom_instructions_when_set(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repository' )->andReturn( [] );
$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );
		$this->global_settings->shouldReceive( 'get_custom_prompt_instructions' )
			->andReturn( 'Write in a friendly, conversational tone. Our audience is non-technical.' );

		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'ADDITIONAL INSTRUCTIONS FROM THE SITE OWNER', $result );
		$this->assertStringContainsString( 'friendly, conversational tone', $result );
	}

	public function test_build_omits_custom_instructions_when_empty(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repository' )->andReturn( [] );
$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );

		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} );

		$result = $this->builder->build( '', $data );

		$this->assertStringNotContainsString( 'ADDITIONAL INSTRUCTIONS', $result );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_release_data( string $body = 'Bug fixes and improvements.' ): ReleaseData {
		return new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.2.0',
			name:         'v1.2.0',
			body:         $body,
			html_url:     'https://github.com/owner/repo/releases/tag/v1.2.0',
			published_at: '2025-01-15T12:00:00Z',
			assets:       [],
		);
	}
}
