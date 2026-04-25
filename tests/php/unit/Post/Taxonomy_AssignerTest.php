<?php
/**
 * Tests for Taxonomy_Assigner.
 *
 * @package ChangelogToBlogPost\Tests\Post
 */

namespace TenUp\ChangelogToBlogPost\Tests\Post;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Post\Taxonomy_Assigner;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \TenUp\ChangelogToBlogPost\Post\Taxonomy_Assigner
 */
class Taxonomy_AssignerTest extends TestCase {

	private Taxonomy_Assigner $assigner;
	private Repository_Settings $repo_settings;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		$this->assigner      = new Taxonomy_Assigner( $this->repo_settings );
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_action(): void {
		\WP_Mock::expectActionAdded( 'ctbp_post_created', [ $this->assigner, 'handle' ], 10, 4 );
		$this->assigner->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — category assignment
	// -------------------------------------------------------------------------

	public function test_handle_applies_categories_from_repo_config(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'categories' => [ 5, 8 ], 'tags' => [] ] );

		\WP_Mock::userFunction( 'term_exists' )->with( 5, 'category' )->andReturn( true );
		\WP_Mock::userFunction( 'term_exists' )->with( 8, 'category' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_set_post_categories' )->once()->with( 42, [ 5, 8 ] );

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	public function test_handle_skips_categories_when_none_configured(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_set_post_categories' )->never();

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — tag assignment
	// -------------------------------------------------------------------------

	public function test_handle_applies_tags_from_repo_config(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'categories' => [], 'tags' => [ 3, 7 ] ] );

		\WP_Mock::userFunction( 'term_exists' )->with( 3, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'term_exists' )->with( 7, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_set_post_tags' )->once()->with( 42, [ 3, 7 ] );

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	public function test_handle_skips_tags_when_none_configured(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_set_post_tags' )->never();

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — missing term handling
	// -------------------------------------------------------------------------

	public function test_handle_skips_missing_categories_and_continues(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'categories' => [ 999 ], 'tags' => [ 3 ] ] );

		// Category does not exist.
		\WP_Mock::userFunction( 'term_exists' )->with( 999, 'category' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_set_post_categories' )->never();

		// Tag does exist — should still be applied.
		\WP_Mock::userFunction( 'term_exists' )->with( 3, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_set_post_tags' )->once()->with( 42, [ 3 ] );

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	public function test_handle_skips_missing_tags_and_applies_valid_ones(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'categories' => [], 'tags' => [ 1, 2, 3 ] ] );

		\WP_Mock::userFunction( 'term_exists' )->with( 1, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'term_exists' )->with( 2, 'post_tag' )->andReturn( false ); // Missing.
		\WP_Mock::userFunction( 'term_exists' )->with( 3, 'post_tag' )->andReturn( true );

		// Only valid tags applied.
		\WP_Mock::userFunction( 'wp_set_post_tags' )->once()->with( 42, [ 1, 3 ] );

		$this->assigner->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — filter hook
	// -------------------------------------------------------------------------

	public function test_handle_applies_ctbp_post_terms_filter(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'categories' => [ 5 ], 'tags' => [ 1 ] ] );

		$data = $this->make_data();

		// Filter overrides terms entirely.
		\WP_Mock::onFilter( 'ctbp_post_terms' )
			->with( [ 'categories' => [ 5 ], 'tags' => [ 1 ] ], 42, $data )
			->reply( [ 'categories' => [ 20 ], 'tags' => [ 8, 9 ] ] );

		\WP_Mock::userFunction( 'term_exists' )->with( 20, 'category' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_set_post_categories' )->once()->with( 42, [ 20 ] );

		\WP_Mock::userFunction( 'term_exists' )->with( 8, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'term_exists' )->with( 9, 'post_tag' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_set_post_tags' )->once()->with( 42, [ 8, 9 ] );

		$this->assigner->handle( 42, $this->make_post(), $data, [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_post(): GeneratedPost {
		return new GeneratedPost(
			title:         'Test subtitle',
			content:       '<p>Body</p>',
			provider_slug: 'wp_ai_client',
		);
	}

	private function make_data(): ReleaseData {
		return new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.0.0',
			name:         'v1.0.0',
			body:         'Changes.',
			html_url:     'https://github.com/owner/repo/releases/tag/v1.0.0',
			published_at: '2025-01-01T00:00:00Z',
			assets:       [],
		);
	}
}
