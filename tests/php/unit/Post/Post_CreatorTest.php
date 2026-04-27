<?php
/**
 * Tests for Post_Creator.
 *
 * @package GitHubReleasePosts\Tests\Post
 */

namespace Jakemgold\GitHubReleasePosts\Tests\Post;

use Jakemgold\GitHubReleasePosts\AI\GeneratedPost;
use Jakemgold\GitHubReleasePosts\AI\ReleaseData;
use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Post\Post_Creator;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \Jakemgold\GitHubReleasePosts\Post\Post_Creator
 */
class Post_CreatorTest extends TestCase {

	private Post_Creator $creator;
	private Repository_Settings $repo_settings;
	private Global_Settings $global_settings;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'owner/my-plugin' )
			->andReturn( [
				'identifier'   => 'owner/my-plugin',
				'display_name' => 'My Plugin',
			] )
			->byDefault();
		$this->repo_settings->shouldReceive( 'derive_display_name' )
			->andReturnUsing( function ( $name ) {
				return ucwords( str_replace( [ '-', '_' ], ' ', $name ) );
			} )
			->byDefault();

		$this->global_settings = \Mockery::mock( Global_Settings::class );
		$this->global_settings->shouldReceive( 'get_title_format' )->andReturn( 'full' )->byDefault();

		$this->creator = new Post_Creator( $this->repo_settings, $this->global_settings );

		// Stub get_post for sideload_images — return a post with no remote images.
		\WP_Mock::userFunction( 'get_post' )->andReturnUsing( function ( $id ) {
			$post               = new \stdClass();
			$post->ID           = $id;
			$post->post_content = '<!-- wp:paragraph --><p>Post body here.</p><!-- /wp:paragraph -->';
			return $post;
		} )->byDefault();

		// build_disclosure_block() checks the AI disclosure option.
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_DISCLOSURE, false )
			->andReturn( false )
			->byDefault();

		// resolve_author() calls get_userdata() and get_users().
		\WP_Mock::userFunction( 'get_userdata' )->andReturn( false )->byDefault();
		\WP_Mock::userFunction( 'get_users' )->andReturn( [ 1 ] )->byDefault();

		// set_featured_image() calls apply_filters() and set_post_thumbnail().
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} )->byDefault();
		\WP_Mock::userFunction( 'set_post_thumbnail' )->andReturn( true )->byDefault();
	}

	public function tearDown(): void {
		\WP_Query::reset_mock();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_action(): void {
		\WP_Mock::expectActionAdded( 'ghrp_post_generated', [ $this->creator, 'handle' ], 10, 3 );
		$this->creator->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — successful post creation
	// -------------------------------------------------------------------------

	public function test_handle_creates_post_with_correct_args(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		// No existing post found.
		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					return $args['post_title'] === 'My Plugin v1.2 — Improved performance and stability'
						&& str_contains( $args['post_content'], 'wp:paragraph' )
						&& str_contains( $args['post_content'], 'Post body here.' )
						&& $args['post_status'] === 'draft'
						&& $args['post_type'] === 'post';
				} ),
				true
			)
			->andReturn( 42 );

		// Meta storage.
		\WP_Mock::userFunction( 'update_post_meta' )->times( 4 )->andReturn( true );

		// Idempotency check returns no results.
		$this->mock_wp_query_no_results();

		// Expect ghrp_post_created action.
		\WP_Mock::expectAction( 'ghrp_post_created', 42, $post, $data, [] );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_stores_all_meta_keys(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		\WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 42 );

		$this->mock_wp_query_no_results();

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, Plugin_Constants::META_SOURCE_REPO, 'owner/my-plugin' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, Plugin_Constants::META_RELEASE_TAG, 'v1.2.0' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, Plugin_Constants::META_RELEASE_URL, 'https://github.com/owner/my-plugin/releases/tag/v1.2.0' );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, Plugin_Constants::META_GENERATED_BY, 'wp_ai_client' );

		\WP_Mock::expectAction( 'ghrp_post_created', 42, $post, $data, [] );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_fires_ghrp_post_created_on_success(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		\WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 99 );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		\WP_Mock::expectAction( 'ghrp_post_created', 99, $post, $data, [] );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — idempotency
	// -------------------------------------------------------------------------

	public function test_handle_skips_creation_when_existing_post_found(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		// Existing post found.
		$this->mock_wp_query_with_result( 55 );

		// wp_insert_post should NOT be called.
		\WP_Mock::userFunction( 'wp_insert_post' )->never();

		// But ghrp_post_created should still fire with the existing ID.
		\WP_Mock::expectAction( 'ghrp_post_created', 55, $post, $data, [] );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_bypasses_idempotency_when_context_flag_set(): void {
		$post    = $this->make_generated_post();
		$data    = $this->make_release_data();
		$context = [ 'bypass_idempotency' => true ];

		// WP_Query should NOT be constructed for idempotency check.
		// wp_insert_post SHOULD be called.
		\WP_Mock::userFunction( 'wp_insert_post' )->once()->andReturn( 77 );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		\WP_Mock::expectAction( 'ghrp_post_created', 77, $post, $data, $context );

		$this->creator->handle( $post, $data, $context );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — failure
	// -------------------------------------------------------------------------

	public function test_handle_does_not_fire_action_on_failure(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		$wp_error = \Mockery::mock( 'WP_Error' );
		$wp_error->shouldReceive( 'get_error_message' )->andReturn( 'Insert failed' );

		\WP_Mock::userFunction( 'wp_insert_post' )->andReturn( $wp_error );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( function ( $val ) use ( $wp_error ) {
			return $val === $wp_error;
		} );

		$this->mock_wp_query_no_results();

		// ghrp_post_created should NOT fire.
		\WP_Mock::userFunction( 'do_action' )
			->with( 'ghrp_post_created', \Mockery::any(), \Mockery::any(), \Mockery::any(), \Mockery::any() )
			->never();

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// handle() — title building
	// -------------------------------------------------------------------------

	public function test_handle_uses_full_title_format_by_default(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					return 'My Plugin v1.2 — Improved performance and stability' === $args['post_title'];
				} ),
				true
			)
			->andReturn( 1 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_uses_version_only_title_format(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		$this->global_settings->shouldReceive( 'get_title_format' )->andReturn( 'version' );

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					return 'Version 1.2 — Improved performance and stability' === $args['post_title'];
				} ),
				true
			)
			->andReturn( 1 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_honors_post_date_context_for_backdating(): void {
		$post    = $this->make_generated_post();
		$data    = $this->make_release_data();
		$context = [
			'post_date'     => '2024-06-15 13:00:00',
			'post_date_gmt' => '2024-06-15 13:00:00',
		];

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) use ( $context ) {
					return ( $args['post_date'] ?? '' ) === $context['post_date']
						&& ( $args['post_date_gmt'] ?? '' ) === $context['post_date_gmt'];
				} ),
				true
			)
			->andReturn( 1 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		$this->creator->handle( $post, $data, $context );
		$this->assertConditionsMet();
	}

	public function test_handle_omits_post_date_when_not_in_context(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					return ! isset( $args['post_date'] ) && ! isset( $args['post_date_gmt'] );
				} ),
				true
			)
			->andReturn( 1 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_uses_no_prefix_title_format(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		$this->global_settings->shouldReceive( 'get_title_format' )->andReturn( 'none' );

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					return 'Improved performance and stability' === $args['post_title'];
				} ),
				true
			)
			->andReturn( 1 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	public function test_handle_derives_display_name_when_not_configured(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data( 'unknown-org/cool-widget' );

		// No matching repo config.
		$this->repo_settings->shouldReceive( 'get_repository' )
			->with( 'unknown-org/cool-widget' )
			->andReturn( [] );

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					// "cool-widget" → "Cool Widget"
					return str_starts_with( $args['post_title'], 'Cool Widget v1.2' );
				} ),
				true
			)
			->andReturn( 10 );

		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );
		$this->mock_wp_query_no_results();

		\WP_Mock::expectAction( 'ghrp_post_created', 10, $post, $data, [] );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// find_existing_post()
	// -------------------------------------------------------------------------

	public function test_find_existing_post_checks_all_post_statuses(): void {
		\WP_Query::$mock_posts = [];

		$result = $this->creator->find_existing_post( 'owner/repo', 'v1.0.0' );
		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_generated_post(): GeneratedPost {
		return new GeneratedPost(
			title:         'Improved performance and stability',
			content:       '<p>Post body here.</p>',
			provider_slug: 'wp_ai_client',
		);
	}

	private function make_release_data( string $identifier = 'owner/my-plugin' ): ReleaseData {
		return new ReleaseData(
			identifier:   $identifier,
			tag:          'v1.2.0',
			name:         'v1.2.0',
			body:         'Bug fixes.',
			html_url:     'https://github.com/owner/my-plugin/releases/tag/v1.2.0',
			published_at: '2025-01-15T12:00:00Z',
			assets:       [],
		);
	}

	private function mock_wp_query_no_results(): void {
		\WP_Query::$mock_posts = [];
	}

	private function mock_wp_query_with_result( int $post_id ): void {
		\WP_Query::$mock_posts = [ $post_id ];
	}
}
