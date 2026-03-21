<?php
/**
 * Tests for Prompt_Builder.
 *
 * @package ChangelogToBlogPost\Tests\AI
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI;

use TenUp\ChangelogToBlogPost\AI\Prompt_Builder;
use TenUp\ChangelogToBlogPost\AI\Release_Significance;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \TenUp\ChangelogToBlogPost\AI\Prompt_Builder
 */
class Prompt_BuilderTest extends TestCase {

	private Prompt_Builder $builder;
	private Repository_Settings $repo_settings;
	private Release_Significance $significance;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		$this->significance  = \Mockery::mock( Release_Significance::class );
		$this->builder       = new Prompt_Builder( $this->repo_settings, $this->significance );
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_filter(): void {
		\WP_Mock::expectFilterAdded( 'ctbp_generate_prompt', [ $this->builder, 'build' ], 10, 2 );
		$this->builder->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// resolve_download_link() — priority: custom_url > wporg_slug > html_url
	// -------------------------------------------------------------------------

	public function test_resolve_download_link_prefers_custom_url(): void {
		$config = [
			'custom_url'  => 'https://example.com/download',
			'wporg_slug'  => 'my-plugin',
		];
		$data = $this->make_release_data();

		$result = $this->builder->resolve_download_link( $config, $data );
		$this->assertSame( 'https://example.com/download', $result );
	}

	public function test_resolve_download_link_falls_back_to_wporg(): void {
		$config = [ 'wporg_slug' => 'my-plugin' ];
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

		$this->repo_settings->shouldReceive( 'get_repositories' )
			->once()
			->andReturn( [
				[
					'identifier'   => 'owner/repo',
					'display_name' => 'My Plugin',
					'wporg_slug'   => 'my-plugin',
				],
			] );

		$this->significance->shouldReceive( 'classify' )
			->once()
			->with( $data )
			->andReturn( 'minor' );

		// Sub-filters pass through.
		\WP_Mock::onFilter( 'ctbp_prompt_title_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt_content_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt' )->reply( false );

		$result = $this->builder->build( '', $data );

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'My Plugin', $result );
		$this->assertStringContainsString( 'v1.2.0', $result );
		$this->assertStringContainsString( 'Minor release', $result );
		$this->assertStringContainsString( 'wordpress.org/plugins/my-plugin/', $result );
	}

	public function test_build_derives_display_name_when_not_configured(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repositories' )
			->once()
			->andReturn( [] ); // No matching repo config.

		$this->significance->shouldReceive( 'classify' )
			->once()
			->andReturn( 'patch' );

		\WP_Mock::onFilter( 'ctbp_prompt_title_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt_content_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt' )->reply( false );

		$result = $this->builder->build( '', $data );

		// "owner/repo" → derive from "repo" → "Repo".
		$this->assertStringContainsString( 'Repo', $result );
	}

	public function test_build_includes_image_placeholders_when_no_images(): void {
		$data = $this->make_release_data( 'No images in this release body.' );

		$this->repo_settings->shouldReceive( 'get_repositories' )->andReturn( [] );
		$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );

		\WP_Mock::onFilter( 'ctbp_prompt_title_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt_content_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt' )->reply( false );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'Image suggestion', $result );
	}

	public function test_build_includes_actual_images_when_present(): void {
		$body = 'New feature: ![screenshot](https://example.com/new.png)';
		$data = $this->make_release_data( $body );

		$this->repo_settings->shouldReceive( 'get_repositories' )->andReturn( [] );
		$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' );

		\WP_Mock::onFilter( 'ctbp_prompt_title_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt_content_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt' )->reply( false );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'https://example.com/new.png', $result );
		$this->assertStringNotContainsString( 'Image suggestion', $result );
	}

	public function test_build_response_format_instructions(): void {
		$data = $this->make_release_data();

		$this->repo_settings->shouldReceive( 'get_repositories' )->andReturn( [] );
		$this->significance->shouldReceive( 'classify' )->andReturn( 'patch' );

		\WP_Mock::onFilter( 'ctbp_prompt_title_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt_content_guidance' )->reply( false );
		\WP_Mock::onFilter( 'ctbp_prompt' )->reply( false );

		$result = $this->builder->build( '', $data );

		$this->assertStringContainsString( 'Line 1: Your subtitle ONLY', $result );
		$this->assertStringContainsString( 'Do NOT use Markdown', $result );
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
