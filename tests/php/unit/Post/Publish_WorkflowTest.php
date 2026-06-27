<?php
/**
 * Tests for Publish_Workflow.
 *
 * @package GitHubReleasePosts\Tests\Post
 */

namespace GitHubReleasePosts\Tests\Post;

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\Post\Publish_Workflow;
use GitHubReleasePosts\Settings\Repository_Settings;
use GitHubReleasePosts\Tests\Post_Status_Defaults;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GitHubReleasePosts\Post\Publish_Workflow
 */
class Publish_WorkflowTest extends TestCase {

	use Post_Status_Defaults;

	private Publish_Workflow $workflow;
	private Repository_Settings $repo_settings;

	public function setUp(): void {
		parent::setUp();
		$this->install_post_status_defaults();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		$this->workflow      = new Publish_Workflow( $this->repo_settings );

		// Mock current_time for tests that resolve to 'publish' status.
		\WP_Mock::userFunction( 'current_time' )->andReturn( '2025-01-15 12:00:00' );

		// Default: the post being processed is not in trash. Individual tests
		// covering the trash-skip behavior override this with their own mock.
		\WP_Mock::userFunction( 'get_post_status' )->andReturn( 'draft' )->byDefault();
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_hooks(): void {
		\WP_Mock::expectActionAdded( 'ghrp_post_created', [ $this->workflow, 'handle' ], 20, 4 );
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
	// handle() — fires ghrp_post_status_set
	// -------------------------------------------------------------------------

	public function test_handle_fires_ghrp_post_status_set(): void {
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/repo' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_update_post' );
		$this->stub_result_recording();

		$data = $this->make_data();
		\WP_Mock::expectAction( 'ghrp_post_status_set', 42, 'draft', $data, [] );

		$this->workflow->handle( 42, $this->make_post(), $data, [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — respects trashed posts
	// -------------------------------------------------------------------------

	public function test_handle_skips_trashed_posts(): void {
		// Existing post is in trash — the workflow must not touch it.
		\WP_Mock::userFunction( 'get_post_status' )->with( 42 )->andReturn( 'trash' );

		// repo_settings should never be consulted, no wp_update_post call,
		// no ghrp_post_status_set fired.
		$this->repo_settings->shouldNotReceive( 'get_repository' );
		\WP_Mock::userFunction( 'wp_update_post' )->never();

		$this->workflow->handle( 42, $this->make_post(), $this->make_data(), [] );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// display_admin_notice()
	// -------------------------------------------------------------------------

	/**
	 * Regression: the "draft created" notice link must resolve the post edit URL
	 * at display time (from post_id), not from a value recorded during cron —
	 * where get_edit_post_link() returns null and the link fell back to "#".
	 */
	public function test_display_admin_notice_resolves_edit_link_at_display_time(): void {
		$this->stub_notice_environment(
			[
				'drafted'   => [ [ 'post_id' => 42, 'identifier' => 'owner/repo', 'tag' => 'v1.0.0' ] ],
				'published' => [],
				'errors'    => [],
			]
		);

		// Display-time resolution succeeds because the admin has edit caps.
		\WP_Mock::userFunction( 'get_edit_post_link' )
			->with( 42, 'raw' )
			->andReturn( 'https://example.com/wp-admin/post.php?post=42&action=edit' );

		ob_start();
		$this->workflow->display_admin_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'post.php?post=42', $output, 'Link should use the display-time edit URL.' );
		$this->assertStringContainsString( 'owner/repo v1.0.0', $output );
		$this->assertStringNotContainsString( 'href="#"', $output, 'Link must not fall back to a dead "#".' );
	}

	/**
	 * If the edit URL can't be resolved even at display time (e.g. the post was
	 * deleted), the entry renders as plain text rather than a dead "#" link.
	 */
	public function test_display_admin_notice_falls_back_to_plain_text_without_url(): void {
		$this->stub_notice_environment(
			[
				'drafted'   => [ [ 'post_id' => 99, 'identifier' => 'owner/repo', 'tag' => 'v2.0.0' ] ],
				'published' => [],
				'errors'    => [],
			]
		);

		\WP_Mock::userFunction( 'get_edit_post_link' )->with( 99, 'raw' )->andReturn( null );

		ob_start();
		$this->workflow->display_admin_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'owner/repo v2.0.0', $output );
		$this->assertStringNotContainsString( '<a ', $output, 'No anchor should be rendered without a URL.' );
	}

	/**
	 * Stubs the WordPress functions display_admin_notice() needs, primed with a
	 * given cron-results transient on this plugin's admin screen.
	 *
	 * @param array $results The cron-results transient payload.
	 */
	private function stub_notice_environment( array $results ): void {
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_options' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_screen' )
			->andReturn( (object) [ 'id' => 'tools_page_github-release-posts' ] );
		\WP_Mock::userFunction( 'get_transient' )
			->with( Cache_Keys::cron_results() )
			->andReturn( $results );
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'esc_html' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( '_n' )->andReturnUsing( fn( $s, $p, $n ) => 1 === $n ? $s : $p );
		\WP_Mock::userFunction( 'delete_transient' )->andReturn( true );
	}

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
			->with( Cache_Keys::cron_results() )
			->andReturn( false );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
	}
}
