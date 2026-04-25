<?php
/**
 * Tests for Publish_Workflow.
 *
 * @package ChangelogToBlogPost\Tests\Post
 */

namespace TenUp\ChangelogToBlogPost\Tests\Post;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Post\Publish_Workflow;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \TenUp\ChangelogToBlogPost\Post\Publish_Workflow
 */
class Publish_WorkflowTest extends TestCase {

	private Publish_Workflow $workflow;
	private Repository_Settings $repo_settings;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		$this->workflow      = new Publish_Workflow( $this->repo_settings );

		// Mock current_time for tests that resolve to 'publish' status.
		\WP_Mock::userFunction( 'current_time' )->andReturn( '2025-01-15 12:00:00' );
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_hooks(): void {
		\WP_Mock::expectActionAdded( 'ctbp_post_created', [ $this->workflow, 'handle' ], 20, 4 );
		\WP_Mock::expectActionAdded( 'admin_notices', [ $this->workflow, 'display_admin_notice' ] );
		$this->workflow->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — status resolution
	// -------------------------------------------------------------------------

	public function test_handle_uses_repo_post_status(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return $args['ID'] === 42 && $args['post_status'] === 'publish';
		} ) );

		$this->stub_result_recording();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );

		// Verify wp_update_post was called with correct status.
		$this->assertConditionsMet();
	}

	public function test_handle_defaults_to_draft_when_repo_status_empty(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'post_status' => '' ] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return $args['post_status'] === 'draft';
		} ) );

		$this->stub_result_recording();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	public function test_handle_defaults_to_draft(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return $args['post_status'] === 'draft';
		} ) );

		$this->stub_result_recording();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — force_draft context flag (AC-004)
	// -------------------------------------------------------------------------

	public function test_handle_force_draft_overrides_publish_setting(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return $args['post_status'] === 'draft';
		} ) );

		$this->stub_result_recording();

		$context = [ 'force_draft' => true ];

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), $context );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — publish sets post date (AC-002)
	// -------------------------------------------------------------------------

	public function test_handle_sets_post_date_on_publish(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [ 'identifier' => 'owner/repo', 'post_status' => 'publish' ] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return isset( $args['post_date'] ) && isset( $args['post_date_gmt'] );
		} ) );

		$this->stub_result_recording();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	public function test_handle_does_not_set_post_date_on_draft(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_update_post' )->once()->with( \Mockery::on( function ( $args ) {
			return ! isset( $args['post_date'] ) && ! isset( $args['post_date_gmt'] );
		} ) );

		$this->stub_result_recording();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — fires ctbp_post_status_set
	// -------------------------------------------------------------------------

	public function test_handle_fires_ctbp_post_status_set(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_update_post' );
		$this->stub_result_recording();

		$data = $this->make_data();
		\WP_Mock::expectAction( 'ctbp_post_status_set', 42, 'draft', $data, [] );

		$this->workflow->handle( 42, $this->make_post(), $data, [] );
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

	private function stub_result_recording(): void {
		\WP_Mock::userFunction( 'get_transient' )
			->with( Publish_Workflow::TRANSIENT_CRON_RESULTS )
			->andReturn( false );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
	}
}
