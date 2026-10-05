<?php
/**
 * Tests for Post_Creator.
 *
 * @package GitHubReleasePosts\Tests\Post
 */

namespace GitHubReleasePosts\Tests\Post;

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\GitHub\Release_Monitor;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Post\Post_Creator;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GitHubReleasePosts\Post\Post_Creator
 */
class Post_CreatorTest extends TestCase {

	private Post_Creator $creator;
	private Repository_Settings $repo_settings;
	private Global_Settings $global_settings;

	public function setUp(): void {
		parent::setUp();

		$this->repo_settings = \Mockery::mock( Repository_Settings::class );
		// The package-naming gate resolves effective patterns through the settings service.
		$this->repo_settings->shouldReceive( 'get_effective_tag_patterns' )->andReturn( '' )->byDefault();
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
		$this->repo_settings->shouldReceive( 'get_display_name' )
			->andReturnUsing( function ( $identifier ) {
				if ( 'owner/my-plugin' === $identifier ) {
					return 'My Plugin';
				}
				$parts = explode( '/', $identifier );
				return ucwords( str_replace( [ '-', '_' ], ' ', end( $parts ) ) );
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

		// The package-naming gate reads per-repo release state
		// (Release_State::get_state → get_option with an array default).
		\WP_Mock::userFunction( 'get_option' )
			->with( \WP_Mock\Functions::type( 'string' ), [] )
			->andReturn( [] )
			->byDefault();

		// Post writes go through wp_slash(); pass through by default so the
		// argument assertions below stay readable. The slashing test installs
		// the real behavior.
		\WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( static fn( $value ) => $value )->byDefault();

		// find_post() derives its status list from the registry.
		\WP_Mock::userFunction( 'get_post_stati' )->andReturn(
			[
				'publish' => 'publish',
				'draft'   => 'draft',
				'pending' => 'pending',
				'private' => 'private',
				'trash'   => 'trash',
			]
		)->byDefault();

		// resolve_author() calls get_userdata() and get_users().
		\WP_Mock::userFunction( 'get_userdata' )->andReturn( false )->byDefault();
		\WP_Mock::userFunction( 'get_users' )->andReturn( [ 1 ] )->byDefault();

		// set_featured_image() calls apply_filters() and set_post_thumbnail().
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( function () {
			$args = func_get_args();
			return $args[1] ?? '';
		} )->byDefault();
		\WP_Mock::userFunction( 'set_post_thumbnail' )->andReturn( true )->byDefault();

		// KSES is applied at the save boundary; in unit tests we pass through
		// so callers can assert on the pre-KSES content. Real KSES behavior is
		// validated by WordPress core itself in integration.
		\WP_Mock::userFunction( 'wp_kses_post' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		// Block attributes are serialized with core's helper; this mirrors its
		// implementation so the block markup asserted below is what WordPress
		// itself produces.
		\WP_Mock::userFunction( 'serialize_block_attributes' )->andReturnUsing(
			static fn( array $attrs ): string => strtr(
				(string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				[
					'\\\\' => '\\u005c',
					'--'   => '\\u002d\\u002d',
					'<'    => '\\u003c',
					'>'    => '\\u003e',
					'&'    => '\\u0026',
					'\\"'  => '\\u0022',
				]
			)
		)->byDefault();
	}

	public function tearDown(): void {
		\WP_Query::reset_mock();
		Release_Monitor::reset_find_post_cache();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// resolve_allowed_image_url() — SSRF: every redirect hop must stay on-list
	// -------------------------------------------------------------------------

	public function test_resolve_allowed_image_url_returns_url_when_not_redirected(): void {
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		\WP_Mock::userFunction( 'wp_safe_remote_head' )->andReturn( [ 'code' => 200 ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['code'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturnUsing( fn( $r, $h ) => $r['location'] ?? '' );

		$url = 'https://raw.githubusercontent.com/owner/repo/main/a.png';

		$this->assertSame( $url, $this->invoke_resolve_allowed_image_url( $url ) );
	}

	public function test_resolve_allowed_image_url_rejects_redirect_to_disallowed_host(): void {
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );
		\WP_Mock::userFunction( 'wp_safe_remote_head' )->andReturn(
			[ 'code' => 302, 'location' => 'http://169.254.169.254/latest/meta-data/' ]
		);
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['code'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturnUsing( fn( $r, $h ) => $r['location'] ?? '' );

		// Allowed *.github.io origin that redirects to the cloud-metadata IP.
		$result = $this->invoke_resolve_allowed_image_url( 'https://attacker.github.io/x.png' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ghrp_sideload_host_not_allowed', $result->get_error_code() );
	}

	public function test_resolve_allowed_image_url_follows_allowed_redirect(): void {
		\WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing( fn( $u, $c = -1 ) => parse_url( $u, $c ) );

		$responses = [
			[ 'code' => 301, 'location' => 'https://raw.githubusercontent.com/owner/repo/main/a.png' ],
			[ 'code' => 200 ],
		];
		$index = 0;
		\WP_Mock::userFunction( 'wp_safe_remote_head' )->andReturnUsing(
			function () use ( &$index, $responses ) {
				return $responses[ $index++ ];
			}
		);
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturnUsing( fn( $r ) => $r['code'] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturnUsing( fn( $r, $h ) => $r['location'] ?? '' );

		// github.com/.../raw/... legitimately 302s to raw.githubusercontent.com —
		// both on the allow-list, so it resolves rather than being rejected.
		$result = $this->invoke_resolve_allowed_image_url( 'https://github.com/owner/repo/raw/main/a.png' );

		$this->assertSame( 'https://raw.githubusercontent.com/owner/repo/main/a.png', $result );
	}

	/**
	 * Invokes the private resolve_allowed_image_url() with the default allow-list.
	 *
	 * @param string $url Initial image URL.
	 * @return string|\WP_Error
	 */
	private function invoke_resolve_allowed_image_url( string $url ) {
		$method = new \ReflectionMethod( Post_Creator::class, 'resolve_allowed_image_url' );
		return $method->invoke( $this->creator, $url, [ 'github.com', 'githubusercontent.com', 'github.io' ], 15 );
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

	/**
	 * wp_insert_post() unslashes everything it is handed, so content must be
	 * slashed first or every backslash the AI wrote (namespaces, regexes,
	 * Windows paths in code samples) is stripped on save.
	 */
	public function test_handle_slashes_content_before_insert(): void {
		$post = new GeneratedPost(
			title:         'Namespaced',
			content:       '<p>Use <code>Foo\\Bar::run()</code>.</p>',
			provider_slug: 'wp_ai_client',
		);
		$data = $this->make_release_data();

		$this->mock_wp_query_no_results();
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		// Real wp_slash() semantics: addslashes on every string, recursively.
		$slash = null;
		$slash = static function ( $value ) use ( &$slash ) {
			if ( is_array( $value ) ) {
				return array_map( $slash, $value );
			}
			return is_string( $value ) ? addslashes( $value ) : $value;
		};
		\WP_Mock::userFunction( 'wp_slash' )->andReturnUsing( $slash );

		\WP_Mock::userFunction( 'wp_insert_post' )
			->once()
			->with(
				\Mockery::on( function ( $args ) {
					// The single backslash the AI wrote must arrive doubled.
					return str_contains( $args['post_content'], 'Foo\\\\Bar::run()' );
				} ),
				true
			)
			->andReturn( 42 );

		$this->creator->handle( $post, $data, [] );
		$this->assertConditionsMet();
	}

	/**
	 * The pre-insert idempotency check must hit the database even when the
	 * request-scoped find_post() memo already says "no post": that memo may
	 * predate a minute-long AI call during which another worker inserted.
	 */
	public function test_handle_requeries_existing_post_despite_stale_memo(): void {
		$post = $this->make_generated_post();
		$data = $this->make_release_data();

		// First call primes the memo with "no post"; handle() must query again.
		\WP_Mock::userFunction( 'get_posts' )->times( 2 )->andReturn( [] );
		Release_Monitor::find_post( 'owner/my-plugin', 'v1.2.0' );

		\WP_Mock::userFunction( 'wp_insert_post' )->andReturn( 42 );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

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

		// And the creation hooks must NOT replay against someone else's post:
		// Publish_Workflow would apply THIS request's status to it (a cron run
		// publishing a review draft, or a manual run un-publishing a live post).
		$fired = false;
		\WP_Mock::onAction( 'ghrp_post_created' )
			->with( 55, $post, $data, [] )
			->perform(
				function () use ( &$fired ) {
					$fired = true;
				}
			);

		$this->creator->handle( $post, $data, [] );

		$this->assertFalse( $fired, 'ghrp_post_created must not fire for a pre-existing post' );
		$this->assertConditionsMet();
	}

	/**
	 * The race the fresh pre-insert lookup exists for: the memo said "no post"
	 * before the AI call, another request inserted meanwhile. The fresh lookup
	 * finds it, and this request must stand down completely — no insert, no
	 * creation hooks against the winner's post.
	 */
	public function test_handle_stands_down_when_fresh_lookup_finds_a_post(): void {
		$post   = $this->make_generated_post();
		$data   = $this->make_release_data();
		$winner = new \WP_Post( (object) [ 'ID' => 73, 'post_status' => 'draft' ] );

		// Memo primed with "no post"; the fresh lookup then sees the winner.
		\WP_Mock::userFunction( 'get_posts' )->times( 2 )->andReturnValues( [ [], [ $winner ] ] );
		Release_Monitor::find_post( 'owner/my-plugin', 'v1.2.0' );

		\WP_Mock::userFunction( 'wp_insert_post' )->never();

		$fired = false;
		\WP_Mock::onAction( 'ghrp_post_created' )
			->with( 73, $post, $data, [] )
			->perform(
				function () use ( &$fired ) {
					$fired = true;
				}
			);

		$this->creator->handle( $post, $data, [] );

		$this->assertFalse( $fired );
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
		\WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing( function ( $args ) {
				$this->assertContains( 'trash', $args['post_status'], 'must search trashed posts (AC-006)' );
				$this->assertContains( 'draft', $args['post_status'] );
				$this->assertContains( 'publish', $args['post_status'] );
				return [];
			} );

		$result = $this->creator->find_existing_post( 'owner/repo', 'v1.0.0' );
		$this->assertNull( $result );
	}

	public function test_find_existing_post_returns_id_when_post_exists(): void {
		$post              = new \WP_Post( (object) [] );
		$post->ID          = 42;
		$post->post_status = 'draft';

		\WP_Mock::userFunction( 'get_posts' )->once()->andReturn( [ $post ] );

		$this->assertSame( 42, $this->creator->find_existing_post( 'owner/repo', 'v1.0.0' ) );
	}

	public function test_find_existing_post_cached_within_request(): void {
		$post              = new \WP_Post( (object) [] );
		$post->ID          = 42;
		$post->post_status = 'draft';

		// get_posts should run exactly once across two calls with the same args.
		\WP_Mock::userFunction( 'get_posts' )->once()->andReturn( [ $post ] );

		$first  = $this->creator->find_existing_post( 'owner/repo', 'v1.0.0' );
		$second = $this->creator->find_existing_post( 'owner/repo', 'v1.0.0' );

		$this->assertSame( 42, $first );
		$this->assertSame( 42, $second );
	}

	// -------------------------------------------------------------------------
	// build_title() — static helper shared with the editor regenerate handler
	// -------------------------------------------------------------------------

	public function test_build_title_full_format_prefixes_display_name_and_tag(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'Restricted Site Access',
			'v7.6.1',
			'Elementor fatal error fixed',
			'full',
			'10up/restricted-site-access'
		);

		$this->assertSame( 'Restricted Site Access v7.6.1 — Elementor fatal error fixed', $result );
	}

	/**
	 * Monorepo package tags render as short package name + bare version in
	 * the full format, instead of the raw "@scope/name@x.y.z" tag.
	 */
	public function test_build_title_full_format_package_tag(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'HeadstartWP',
			'@headstartwp/core@1.6.1',
			'Faster data fetching',
			'full',
			'10up/headstartwp',
			true
		);

		$this->assertSame( 'HeadstartWP core 1.6.1 — Faster data fetching', $result );
	}

	/**
	 * Pinned compatibility (peer review round 2): an npm-style tag on a repo
	 * WITHOUT patterns renders verbatim — package display is opt-in for every
	 * tag style, so existing single-package repos are truly unchanged.
	 */
	public function test_build_title_npm_tag_without_patterns_is_unchanged(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'My Package',
			'mypackage@1.2.3',
			'A subtitle',
			'full',
			'acme/mypackage'
		);

		$this->assertSame( 'My Package mypackage@1.2.3 — A subtitle', $result );
	}

	/**
	 * The version-only format keeps the package name for monorepo tags —
	 * "Version 1.6.1" alone is ambiguous across packages — and capitalizes
	 * it, since it leads the title. Trailing .0 patch versions are trimmed
	 * as with plain tags.
	 */
	public function test_build_title_version_format_package_tag(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'HeadstartWP',
			'@headstartwp/next@1.5.0',
			'Simplified routing',
			'version',
			'10up/headstartwp',
			true
		);

		$this->assertSame( 'Next 1.5 — Simplified routing', $result );
	}

	/**
	 * Back-compat (peer review P2): a single-package repo tagging
	 * "my-plugin-v1.2.3" keeps its pre-1.2 title verbatim — dash-style tags
	 * only render as packages when the repo has patterns configured.
	 */
	/**
	 * The shared slug builder produces package-aware slugs with patterns and
	 * legacy slugs without — creation and regeneration use the same builder.
	 */
	public function test_build_release_slug_shapes(): void {
		\WP_Mock::userFunction( 'sanitize_title' )->andReturnUsing(
			static fn( $raw ) => strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $raw ) ) )
		);

		$this->assertSame(
			'headstartwp-core-1-6-1-faster-fetching',
			Post_Creator::build_release_slug( 'HeadstartWP', '@headstartwp/core@1.6.1', 'faster-fetching', true )
		);
		$this->assertSame(
			'my-plugin-1-2-3-nice-things',
			Post_Creator::build_release_slug( 'My Plugin', 'v1.2.3', 'nice-things', false )
		);
	}

	public function test_build_title_dash_tag_without_patterns_is_unchanged(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'My Plugin',
			'my-plugin-v1.2.3',
			'A subtitle',
			'full',
			'acme/my-plugin'
		);

		$this->assertSame( 'My Plugin my-plugin-v1.2.3 — A subtitle', $result );
	}

	/**
	 * With patterns configured, dash-style tags render as packages.
	 */
	public function test_build_title_dash_tag_with_patterns_renders_package(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'Acme Suite',
			'admin-v2.1.0',
			'A subtitle',
			'full',
			'acme/suite',
			true
		);

		$this->assertSame( 'Acme Suite admin 2.1 — A subtitle', $result );
	}

	public function test_build_title_version_format_uses_version_prefix(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'Restricted Site Access',
			'v7.6.1',
			'Elementor fatal error fixed',
			'version',
			'10up/restricted-site-access'
		);

		$this->assertSame( 'Version 7.6.1 — Elementor fatal error fixed', $result );
	}

	/**
	 * Regression: rest_regenerate_post previously hardcoded the 'full' format,
	 * doubling the project name and version when the site had 'none' selected.
	 */
	public function test_build_title_none_format_returns_ai_title_verbatim(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing( fn( $tag, $value ) => $value )->byDefault();

		$result = Post_Creator::build_title(
			'Restricted Site Access',
			'v7.6.1',
			'Elementor fatal error fixed in Restricted Site Access 7.6.1',
			'none',
			'10up/restricted-site-access'
		);

		$this->assertSame(
			'Elementor fatal error fixed in Restricted Site Access 7.6.1',
			$result,
			'none format must not prepend anything to the AI-supplied title'
		);
	}

	// -------------------------------------------------------------------------
	// convert_html_to_blocks() — figure / image extraction
	// -------------------------------------------------------------------------

	/**
	 * A horizontal rule becomes a complete, self-contained separator block —
	 * the paragraph after it must survive as its own block. (The old output
	 * was a bare opener with no closer that swallowed the rest of the post.)
	 */
	public function test_convert_html_to_blocks_hr_is_a_complete_separator_block(): void {
		$result = Post_Creator::convert_html_to_blocks( '<p>Intro</p><hr><p>After the rule.</p>' );

		$this->assertStringContainsString( "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->", $result );
		$this->assertStringContainsString( "<p>After the rule.</p>\n<!-- /wp:paragraph -->", $result );
		$this->assertSame( 3, substr_count( $result, '<!-- wp:' ) );
	}

	/**
	 * A `>` inside a quoted attribute value must not end the tag early: the
	 * image keeps its src and alt, and the following paragraph survives.
	 */
	public function test_convert_html_to_blocks_keeps_img_with_gt_in_quoted_attribute(): void {
		$result = Post_Creator::convert_html_to_blocks(
			'<img alt="Before > After" src="https://github.com/acme/repo/screenshot.png" /><p>Next paragraph</p>'
		);

		// esc_attr() is a passthrough in the unit environment, so the alt text
		// appears verbatim here; what matters is that it is still one attribute.
		$this->assertStringContainsString( '<img src="https://github.com/acme/repo/screenshot.png" alt="Before > After" />', $result );
		$this->assertStringContainsString( "<p>Next paragraph</p>\n<!-- /wp:paragraph -->", $result );
		$this->assertSame( 2, substr_count( $result, '<!-- wp:' ) );
	}

	/**
	 * A self-closing <br/> inside a paragraph is not a block boundary.
	 */
	public function test_convert_html_to_blocks_keeps_br_inside_paragraph(): void {
		$result = Post_Creator::convert_html_to_blocks( '<p>Line one<br/>Line two</p>' );

		$this->assertStringContainsString( '<p>Line one<br/>Line two</p>', $result );
		$this->assertSame( 1, substr_count( $result, '<!-- wp:paragraph -->' ) );
	}

	/**
	 * A bare (non-self-closed) <img> is one element; the paragraph after it
	 * is not swallowed into the image block.
	 */
	public function test_convert_html_to_blocks_bare_img_does_not_swallow_next_paragraph(): void {
		$result = Post_Creator::convert_html_to_blocks( '<img src="https://example.com/a.png" alt="A"><p>Next paragraph</p>' );

		$this->assertStringContainsString( '<!-- wp:image', $result );
		$this->assertStringContainsString( "<p>Next paragraph</p>\n<!-- /wp:paragraph -->", $result );
	}

	public function test_convert_html_to_blocks_wraps_paragraph(): void {
		\WP_Mock::userFunction( 'get_option' )->andReturn( false )->byDefault();

		$result = Post_Creator::convert_html_to_blocks( '<p>Hello world.</p>' );
		$this->assertSame( "<!-- wp:paragraph -->\n<p>Hello world.</p>\n<!-- /wp:paragraph -->", $result );
	}

	public function test_convert_html_to_blocks_extracts_figure_with_img(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input = '<figure><img src="https://example.com/a.png" alt="An image"></figure>';

		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<!-- wp:image {"sizeSlug":"full"} -->', $result );
		$this->assertStringContainsString( '<figure class="wp-block-image size-full">', $result );
		$this->assertStringContainsString( 'src="https://example.com/a.png"', $result );
		$this->assertStringContainsString( 'alt="An image"', $result );
	}

	public function test_convert_html_to_blocks_preserves_figcaption(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input = '<figure><img src="https://example.com/b.jpg" alt=""><figcaption>A caption.</figcaption></figure>';

		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<figcaption class="wp-element-caption">A caption.</figcaption>', $result );
	}

	public function test_convert_html_to_blocks_unwraps_p_around_figure(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input = '<p><figure><img src="https://example.com/c.png" alt=""></figure></p>';

		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<!-- wp:image', $result );
		$this->assertStringNotContainsString( '<!-- wp:paragraph -->', $result );
	}

	public function test_convert_html_to_blocks_handles_standalone_img(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input = '<img src="https://example.com/d.gif" alt="bare img">';

		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<!-- wp:image', $result );
		$this->assertStringContainsString( 'src="https://example.com/d.gif"', $result );
	}

	public function test_convert_html_to_blocks_handles_figure_without_img(): void {
		// A <figure> with no <img> should not pretend to be an image block.
		$input  = '<figure><blockquote>Some quote</blockquote></figure>';
		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<!-- wp:html -->', $result );
		$this->assertStringNotContainsString( '<!-- wp:image', $result );
	}

	public function test_convert_html_to_blocks_handles_attributes_before_src(): void {
		// Attribute order varies — regex that assumed `src` comes first would miss this.
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input  = '<figure><img alt="alt first" width="200" src="https://example.com/f.png"></figure>';
		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( 'src="https://example.com/f.png"', $result );
		$this->assertStringContainsString( 'alt="alt first"', $result );
	}

	public function test_convert_html_to_blocks_handles_single_quoted_attributes(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input  = "<figure><img src='https://example.com/g.png' alt='single quotes'></figure>";
		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( 'src="https://example.com/g.png"', $result );
		$this->assertStringContainsString( 'alt="single quotes"', $result );
	}

	public function test_convert_html_to_blocks_preserves_figcaption_inner_markup(): void {
		// DOMDocument preserves inline markup inside <figcaption>; the old
		// regex captured raw inner text but with nested elements intact.
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input  = '<figure><img src="https://example.com/h.png" alt=""><figcaption>Bold <strong>word</strong> caption.</figcaption></figure>';
		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<strong>word</strong>', $result );
	}

	public function test_convert_html_to_blocks_handles_mixed_content(): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$input = '<h2>Title</h2><p>Para.</p><figure><img src="https://example.com/e.png" alt=""></figure><p>Done.</p>';

		$result = Post_Creator::convert_html_to_blocks( $input );

		$this->assertStringContainsString( '<!-- wp:heading -->', $result );
		$this->assertStringContainsString( '<!-- wp:image', $result );
		// Two paragraph blocks expected.
		$this->assertSame( 2, substr_count( $result, '<!-- wp:paragraph -->' ) );
	}

	// -------------------------------------------------------------------------
	// is_host_allowed() — SSRF defense for sideloaded images
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider host_allowlist_cases
	 */
	public function test_is_host_allowed( string $host, bool $expected, string $why ): void {
		$allowed = [ 'github.com', 'githubusercontent.com', 'github.io' ];
		$this->assertSame(
			$expected,
			Post_Creator::is_host_allowed( $host, $allowed ),
			$why
		);
	}

	public function host_allowlist_cases(): array {
		return [
			// Allowed.
			'exact match'                       => [ 'github.com', true, 'exact match should pass' ],
			'subdomain of github.com'           => [ 'raw.github.com', true, 'real subdomain should pass' ],
			'githubusercontent subdomain'       => [ 'raw.githubusercontent.com', true, 'real subdomain should pass' ],
			'pages subdomain'                   => [ 'user.github.io', true, 'github.io subdomain should pass' ],

			// Rejected — these are the bypass attempts the reviewer flagged.
			'lookalike with hyphen prefix'      => [ 'malicious-github.com', false, 'hyphen-prefixed lookalike must not match (no leading dot)' ],
			'lookalike as suffix'               => [ 'evilgithub.com', false, 'suffix-only lookalike must not match' ],
			'attacker domain ending in target'  => [ 'github.com.evil.com', false, 'attacker-controlled parent domain must not match' ],
			'unrelated domain'                  => [ 'example.com', false, 'unrelated host should be rejected' ],
			'empty host'                        => [ '', false, 'empty host must be rejected' ],
		];
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
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] )->byDefault();
	}

	private function mock_wp_query_with_result( int $post_id ): void {
		$post              = new \WP_Post( (object) [] );
		$post->ID          = $post_id;
		$post->post_status = 'draft';
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ $post ] )->byDefault();
	}

	// -------------------------------------------------------------------------
	// convert_html_to_blocks() — core block markup
	// -------------------------------------------------------------------------

	/**
	 * Headings carry the class the editor saves; without it every heading
	 * loaded through a deprecation and kept the bare markup on the front end.
	 */
	public function test_convert_html_to_blocks_emits_core_heading_markup(): void {
		$this->assertSame(
			"<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Details</h3>\n<!-- /wp:heading -->",
			Post_Creator::convert_html_to_blocks( '<h3>Details</h3>' )
		);
	}

	/**
	 * A flat list comes out as list-item blocks, byte-for-byte what the
	 * editor serializes; inline markup and line breaks the model wrote stay.
	 */
	public function test_convert_html_to_blocks_builds_flat_lists(): void {
		$this->assertSame(
			"<!-- wp:list -->\n<ul class=\"wp-block-list\"><!-- wp:list-item -->\n<li>One <code>a</code></li>\n<!-- /wp:list-item -->\n\n<!-- wp:list-item -->\n<li>A<br><br>B</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->",
			Post_Creator::convert_html_to_blocks( "<ul>\n<li>One <code>a</code></li>\n<li>A<br><br>B</li>\n</ul>" )
		);
	}

	public function test_convert_html_to_blocks_ordered_list_keeps_start_and_reversed(): void {
		$result = Post_Creator::convert_html_to_blocks( '<ol reversed start="3"><li>C</li></ol>' );

		$this->assertStringStartsWith( "<!-- wp:list {\"ordered\":true,\"start\":3,\"reversed\":true} -->\n<ol reversed start=\"3\" class=\"wp-block-list\">", $result );
	}

	/**
	 * A quote holds paragraph blocks; it used to end at the first </p>,
	 * leaving "</blockquote>" behind as a broken paragraph.
	 */
	public function test_convert_html_to_blocks_quote_holds_paragraph_blocks(): void {
		$this->assertSame(
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Quoted.</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->",
			Post_Creator::convert_html_to_blocks( '<blockquote><p>Quoted.</p></blockquote>' )
		);
		$this->assertStringContainsString(
			"<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>Bare text.</p>",
			Post_Creator::convert_html_to_blocks( '<blockquote>Bare text.</blockquote>' )
		);
	}

	/**
	 * Code blocks carry the class the editor requires (every one used to be
	 * "invalid content"), a language class on <code> is dropped, and a bare
	 * <pre> becomes a preformatted block.
	 */
	public function test_convert_html_to_blocks_code_and_preformatted_blocks(): void {
		$this->assertSame(
			"<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>echo 1;</code></pre>\n<!-- /wp:code -->",
			Post_Creator::convert_html_to_blocks( '<pre><code class="language-php">echo 1;</code></pre>' )
		);
		$this->assertSame(
			"<!-- wp:preformatted -->\n<pre class=\"wp-block-preformatted\">Plain text</pre>\n<!-- /wp:preformatted -->",
			Post_Creator::convert_html_to_blocks( '<pre>Plain text</pre>' )
		);
	}

	/**
	 * An element the model never closed ends where the next block starts,
	 * and is closed there.
	 */
	public function test_convert_html_to_blocks_closes_an_unclosed_paragraph(): void {
		$result = Post_Creator::convert_html_to_blocks( '<p>Never closed<h2>Next</h2>' );

		$this->assertStringContainsString( "<p>Never closed</p>\n<!-- /wp:paragraph -->", $result );
		$this->assertStringContainsString( '<h2 class="wp-block-heading">Next</h2>', $result );
	}

	public function test_convert_html_to_blocks_table_keeps_auto_layout(): void {
		$this->assertStringStartsWith(
			"<!-- wp:table {\"hasFixedLayout\":false} -->\n<figure class=\"wp-block-table\"><table>",
			Post_Creator::convert_html_to_blocks( '<table><tbody><tr><td>A</td></tr></tbody></table>' )
		);
	}

	/**
	 * Block markup written by the model is converted once, not wrapped again.
	 */
	public function test_convert_html_to_blocks_ignores_block_comments_from_the_model(): void {
		$result = Post_Creator::convert_html_to_blocks( "<!-- wp:paragraph -->\n<p>Already blocks.</p>\n<!-- /wp:paragraph -->" );

		$this->assertSame( "<!-- wp:paragraph -->\n<p>Already blocks.</p>\n<!-- /wp:paragraph -->", $result );
	}

	/**
	 * Markup with no native block equivalent as written stays one HTML
	 * block, exactly as written, rather than being reshaped: each element is
	 * still matched to its own closing tag (a nested list used to end at the
	 * inner </ul>, leaving "</li></ul>" behind as a broken paragraph), and a
	 * figure inside it is restored in place.
	 *
	 * @dataProvider unrepresentable_markup_provider
	 */
	public function test_convert_html_to_blocks_keeps_unrepresentable_markup_as_html( string $html ): void {
		\WP_Mock::userFunction( 'esc_url' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( fn( $v ) => $v )->byDefault();

		$this->assertSame( "<!-- wp:html -->\n{$html}\n<!-- /wp:html -->", Post_Creator::convert_html_to_blocks( $html ) );
	}

	public static function unrepresentable_markup_provider(): array {
		return [
			'nested list'                => [ "<ul>\n<li>Parent<ul><li>Child</li></ul></li>\n<li>Next</li>\n</ul>" ],
			'text after a nested list'   => [ '<ul><li>First<ul><li>Second</li></ul>Third</li></ul>' ],
			'paragraphs inside an item'  => [ '<ol><li><p>One.</p><p>Two.</p></li><li>Done.</li></ol>' ],
			'paragraph between text'     => [ '<ul><li>Before<p>Inside</p>After</li></ul>' ],
			'code block inside an item'  => [ "<ol><li>Run:\n<pre><code>wp cache flush</code></pre></li><li>Done.</li></ol>" ],
			'quote inside an item'       => [ '<ul><li>Note:<blockquote><p>Quoted.</p></blockquote></li></ul>' ],
			'figure inside an item'      => [ '<ul><li>Screenshot: <figure><img src="https://example.com/a.png" alt=""></figure></li></ul>' ],
			'unclosed items'             => [ '<ul><li>One<li>Two</ul>' ],
			'numbering set per item'     => [ '<ol><li value="3">Three</li><li value="7">Seven</li></ol>' ],
			'list style'                 => [ '<ol type="a"><li>A</li><li>B</li></ol>' ],
			'quote holding a list'       => [ '<blockquote><p>Note:</p><ul><li>One</li></ul></blockquote>' ],
			'nested quote'               => [ '<blockquote><blockquote><p>Inner.</p></blockquote></blockquote>' ],
			'quote with a citation'      => [ '<blockquote><p>Quoted.</p><cite>Someone</cite></blockquote>' ],
			'paragraph around a list'    => [ '<p><ul><li>Item</li></ul></p>' ],
			'paragraph around a figure'  => [ '<p>See <figure><img src="https://example.com/b.png" alt=""></figure></p>' ],
		];
	}

	/**
	 * A heading's id is kept as its anchor, so in-post links to it work. It
	 * is read the way a browser reads it: any characters, references decoded,
	 * and never "id=" text inside another attribute.
	 *
	 * @dataProvider heading_anchor_provider
	 */
	public function test_convert_html_to_blocks_keeps_heading_anchors( string $html, string $comment, string $id, string $text ): void {
		// Escape as core does (WP_Mock's default esc_attr() passes through).
		\WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8', false ) );

		$this->assertSame(
			"<!-- wp:heading {$comment} -->\n<h2 id=\"{$id}\" class=\"wp-block-heading\">{$text}</h2>\n<!-- /wp:heading -->",
			Post_Creator::convert_html_to_blocks( $html )
		);
	}

	public static function heading_anchor_provider(): array {
		return [
			'simple id'                    => [ '<h2 id="migration">Migration</h2>', '{"anchor":"migration"}', 'migration', 'Migration' ],
			'symbols'                      => [ '<h2 id="c++">C++ support</h2>', '{"anchor":"c++"}', 'c++', 'C++ support' ],
			'non-ASCII'                    => [ '<h2 id="café">Café integration</h2>', '{"anchor":"café"}', 'café', 'Café integration' ],
			'character reference'          => [ '<h2 id="migr&#97;tion">Migration</h2>', '{"anchor":"migration"}', 'migration', 'Migration' ],
			'brackets'                     => [ '<h2 id="config[api]">API config</h2>', '{"anchor":"config[api]"}', 'config[api]', 'API config' ],
			'id text in another attribute' => [ '<h2 title="An id=example attribute" id="actual">Details</h2>', '{"anchor":"actual"}', 'actual', 'Details' ],
			'quote and markup characters'  => [ "<h2 id='say\"hi&lt;&amp;'>Odd</h2>", '{"anchor":"say\\u0022hi\\u003c\\u0026"}', 'say&quot;hi&lt;&amp;', 'Odd' ],
		];
	}

	public function test_convert_html_to_blocks_heading_anchor_keeps_the_level(): void {
		$this->assertSame(
			"<!-- wp:heading {\"level\":3,\"anchor\":\"step-2\"} -->\n<h3 id=\"step-2\" class=\"wp-block-heading\">Step 2</h3>\n<!-- /wp:heading -->",
			Post_Creator::convert_html_to_blocks( '<h3 id="step-2">Step 2</h3>' )
		);
	}

	/**
	 * Deeply nested quotes and lists are a single HTML block: nothing is
	 * recursed into, so depth costs no more than length.
	 */
	public function test_convert_html_to_blocks_keeps_deep_nesting_as_one_html_block(): void {
		$quotes = str_repeat( '<blockquote>', 2000 ) . 'Deep' . str_repeat( '</blockquote>', 2000 );
		$this->assertSame( "<!-- wp:html -->\n{$quotes}\n<!-- /wp:html -->", Post_Creator::convert_html_to_blocks( $quotes ) );

		$lists = str_repeat( '<ul><li>Level', 2000 ) . str_repeat( '</li></ul>', 2000 );
		$this->assertSame( "<!-- wp:html -->\n{$lists}\n<!-- /wp:html -->", Post_Creator::convert_html_to_blocks( $lists ) );
	}

	/**
	 * Many unclosed elements convert in linear time: each one used to rescan
	 * the rest of the content for a closing tag that did not exist.
	 */
	public function test_convert_html_to_blocks_handles_many_unclosed_paragraphs(): void {
		$started = microtime( true );
		$result  = Post_Creator::convert_html_to_blocks( str_repeat( '<p>x', 5000 ) );

		$this->assertSame( 5000, substr_count( $result, '<!-- wp:paragraph -->' ) );
		$this->assertLessThan( 2.0, microtime( true ) - $started );
	}

	// -------------------------------------------------------------------------
	// neutralize_ai_html()
	// -------------------------------------------------------------------------

	/**
	 * A "<" that does not open an HTML element is text. KSES deletes from
	 * such a "<" to the next ">", which turned "Requires PHP < 8.2 or
	 * WordPress >= 6.0" into "Requires PHP = 6.0".
	 */
	public function test_neutralize_ai_html_encodes_a_stray_less_than(): void {
		$this->assertSame(
			'<p>Requires PHP &lt; 8.2 or WordPress >= 6.0; returns <code>array&lt;int, string></code>, <code>Vec&lt;T></code>, <code>&lt;?php</code>.</p>',
			Post_Creator::neutralize_ai_html( '<p>Requires PHP < 8.2 or WordPress >= 6.0; returns <code>array<int, string></code>, <code>Vec<T></code>, <code><?php</code>.</p>' )
		);
	}

	/**
	 * Comments are dropped (a smuggled block delimiter would render as a
	 * live dynamic block), an unterminated "<!--" is shown as text rather
	 * than swallowing the rest, and "[" is encoded so a quoted shortcode
	 * cannot run.
	 */
	public function test_neutralize_ai_html_drops_comments_and_disarms_shortcodes(): void {
		$this->assertSame(
			'<p>Intro</p><p>Use &#91;gallery ids="1,2"] here.</p>',
			Post_Creator::neutralize_ai_html( '<p>Intro</p><!-- wp:rss {"feedURL":"https://attacker.example/feed"} /--><p>Use [gallery ids="1,2"] here.</p>' )
		);
		$this->assertSame( '<p>A</p>&lt;!-- dangling <p>B</p>', Post_Creator::neutralize_ai_html( '<p>A</p><!-- dangling <p>B</p>' ) );
	}
}
