<?php
/**
 * Tests for Admin_Page class.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests\Admin;

use GitHubReleasePosts\Admin\Admin_Page;
use WP_Mock\Tools\TestCase;

/**
 * Tests Admin_Page hook registrations and capability gating.
 */
class Admin_PageTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * setup() registers admin_menu, admin_enqueue_scripts, and other hooks.
	 */
	public function test_setup_registers_hooks(): void {
		$page = new Admin_Page();

		\WP_Mock::expectActionAdded( 'admin_menu', [ $page, 'register_menu_page' ] );
		\WP_Mock::expectActionAdded( 'admin_enqueue_scripts', [ $page, 'enqueue_assets' ] );
		\WP_Mock::expectActionAdded( 'enqueue_block_editor_assets', [ $page, 'enqueue_editor_assets' ] );
		\WP_Mock::expectActionAdded( 'rest_api_init', [ $page, 'register_rest_routes' ] );

		// register_post_meta() is called directly in setup(), not hooked to init.
		\WP_Mock::userFunction( 'register_post_meta' )->andReturn( true );

		// plugin_action_links filter uses plugin_basename.
		\WP_Mock::userFunction( 'plugin_basename' )->andReturn( 'github-release-posts/github-release-posts.php' );

		$page->setup();

		$this->assertConditionsMet();
	}

	/**
	 * enqueue_assets() does nothing when the hook suffix does not match the stored page hook.
	 */
	public function test_enqueue_assets_skips_on_wrong_hook(): void {
		// Neither wp_enqueue_style nor wp_enqueue_script should be called.
		\WP_Mock::userFunction( 'wp_enqueue_style' )->never();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		// The page hook is empty (never set via add_management_page in this test).
		$page = new Admin_Page();
		$page->enqueue_assets( 'edit.php' );

		$this->assertConditionsMet();
	}

	/**
	 * enqueue_assets() enqueues CSS and JS when the hook suffix matches.
	 */
	public function test_enqueue_assets_enqueues_on_correct_hook(): void {
		if ( ! defined( 'GHRP_URL' ) ) {
			define( 'GHRP_URL', 'http://example.com/wp-content/plugins/github-release-posts/' );
		}
		if ( ! defined( 'GHRP_VERSION' ) ) {
			define( 'GHRP_VERSION', '1.0.0' );
		}

		\WP_Mock::userFunction( 'wp_enqueue_style' )->once();
		\WP_Mock::userFunction( 'wp_enqueue_script' )->once();
		\WP_Mock::userFunction( 'wp_localize_script' )->once();
		\WP_Mock::userFunction( 'wp_create_nonce' )->andReturn( 'test_nonce' );
		\WP_Mock::userFunction( 'admin_url' )->andReturn( 'http://example.com/wp-admin/admin-ajax.php' );
		\WP_Mock::userFunction( 'get_rest_url' )->andReturn( 'https://example.com/wp-json/' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_enqueue_media' )->once();

		// Use reflection to set the private page_hook property.
		$page = new Admin_Page();
		$ref  = new \ReflectionProperty( Admin_Page::class, 'page_hook' );
		$ref->setAccessible( true );
		$ref->setValue( $page, 'tools_page_github-release-posts' );

		$page->enqueue_assets( 'tools_page_github-release-posts' );

		$this->assertConditionsMet();
	}

	/**
	 * render_page() calls wp_die() when user lacks manage_options capability.
	 */
	public function test_render_page_dies_without_capability(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( false );

		\WP_Mock::userFunction( 'esc_html__' )->andReturnArg( 0 );

		$died = false;
		\WP_Mock::userFunction( 'wp_die' )->andReturnUsing( function () use ( &$died ) {
			$died = true;
			throw new \RuntimeException( 'wp_die called' );
		} );

		try {
			( new Admin_Page() )->render_page();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		$this->assertTrue( $died, 'wp_die() should have been called' );

		unset( $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * get_page_url() returns the expected admin URL.
	 */
	public function test_get_page_url_returns_tools_page_url(): void {
		\WP_Mock::userFunction( 'admin_url' )
			->with( 'tools.php?page=github-release-posts' )
			->andReturn( 'http://example.com/wp-admin/tools.php?page=github-release-posts' );

		$url = ( new Admin_Page() )->get_page_url();

		$this->assertSame( 'http://example.com/wp-admin/tools.php?page=github-release-posts', $url );
	}

	/**
	 * Registers passthrough stubs for the WordPress sanitization helpers that
	 * sanitize_repo_config() relies on, so each test can focus on the transform.
	 */
	private function stub_sanitizers(): void {
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v )->byDefault();
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => is_string( $v ) ? trim( $v ) : $v )->byDefault();
		\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( fn( $v ) => strtolower( (string) $v ) )->byDefault();
		\WP_Mock::userFunction( 'absint' )->andReturnUsing( fn( $v ) => abs( (int) $v ) )->byDefault();
	}

	/**
	 * Invokes the private sanitize_repo_config() on a fresh Admin_Page.
	 *
	 * @param array $config Raw posted repo config.
	 * @return array Sanitized config.
	 */
	private function invoke_sanitize( array $config ): array {
		$method = new \ReflectionMethod( Admin_Page::class, 'sanitize_repo_config' );
		return $method->invoke( new Admin_Page(), $config );
	}

	/**
	 * Regression for the inline-edit crash: the edit form posts a hidden "0"
	 * fallback as the first categories element. array_filter() drops it but keeps
	 * the original keys, so without array_values() the stored categories become a
	 * non-sequential array ([1=>5, 2=>8]) that wp_json_encode() renders as a JSON
	 * object ({"1":5,"2":8}) — which the inline editor's indexOf() loop chokes on.
	 * sanitize_repo_config() must always return a sequential list.
	 */
	public function test_sanitize_repo_config_normalizes_categories_to_list(): void {
		$this->stub_sanitizers();

		// Mirrors the posted payload: hidden "0" fallback + two checked boxes.
		$result = $this->invoke_sanitize( [ 'categories' => [ '0', '5', '8' ] ] );

		$this->assertSame( [ 5, 8 ], $result['categories'] );
		$this->assertTrue(
			array_is_list( $result['categories'] ),
			'Categories must be a sequential list so they serialize as a JSON array.'
		);
		// The precise property the render side depends on: a JSON array, not an
		// object. Before the fix this was the crash-inducing '{"1":5,"2":8}'.
		// (wp_json_encode delegates to json_encode, identical for an int list.)
		$this->assertSame( '[5,8]', json_encode( $result['categories'] ) );
	}

	/**
	 * Categories sanitize to an empty array when nothing is selected (just the
	 * hidden "0" fallback) or when the key is absent entirely.
	 */
	public function test_sanitize_repo_config_empty_categories(): void {
		$this->stub_sanitizers();

		$only_fallback = $this->invoke_sanitize( [ 'categories' => [ '0' ] ] );
		$this->assertSame( [], $only_fallback['categories'] );

		$missing = $this->invoke_sanitize( [] );
		$this->assertSame( [], $missing['categories'] );
	}

	/**
	 * Extraction guard: the non-category fields keep their sanitized values and
	 * types so the refactor stays behavior-identical to the original save loop.
	 */
	public function test_sanitize_repo_config_preserves_scalar_fields(): void {
		$this->stub_sanitizers();

		$result = $this->invoke_sanitize(
			[
				'display_name'        => '  Cool Plugin  ',
				'author'              => '12',
				'post_status'         => 'Publish',
				'featured_image'      => '34',
				'paused'              => '1',
				'include_prereleases' => '1',
			]
		);

		$this->assertSame( 'Cool Plugin', $result['display_name'] );
		$this->assertSame( 12, $result['author'] );
		$this->assertSame( 'publish', $result['post_status'] );
		$this->assertSame( 34, $result['featured_image'] );
		$this->assertTrue( $result['paused'] );
		$this->assertTrue( $result['include_prereleases'] );

		// Unset booleans default to false.
		$bare = $this->invoke_sanitize( [] );
		$this->assertFalse( $bare['paused'] );
		$this->assertFalse( $bare['include_prereleases'] );
	}

	/**
	 * plugin_link gets URL sanitization only when it looks like a URL; a WP.org
	 * slug passes through untouched (esc_url_raw is never applied to it).
	 */
	public function test_sanitize_repo_config_url_sanitization_branches(): void {
		$this->stub_sanitizers();

		\WP_Mock::userFunction( 'esc_url_raw' )
			->with( 'https://example.com/plugin/' )
			->once()
			->andReturn( 'https://example.com/plugin/[esc]' );

		$url = $this->invoke_sanitize( [ 'plugin_link' => 'https://example.com/plugin/' ] );
		$this->assertSame( 'https://example.com/plugin/[esc]', $url['plugin_link'] );

		// A bare slug is not a URL, so esc_url_raw must not run on it.
		$slug = $this->invoke_sanitize( [ 'plugin_link' => 'my-plugin-slug' ] );
		$this->assertSame( 'my-plugin-slug', $slug['plugin_link'] );
	}

	/**
	 * Tags resolve comma-separated names to term IDs; a name that doesn't exist
	 * yet is created on the fly (like the core post-editor tag box), and the
	 * result is a sequential list of integers in the order typed.
	 */
	public function test_sanitize_repo_config_resolves_and_creates_tags(): void {
		$this->stub_sanitizers();

		// Alpha and Beta already exist; Ghost does not.
		\WP_Mock::userFunction( 'get_term_by' )->andReturnUsing(
			function ( $field, $name ) {
				$map = [
					'Alpha' => 3,
					'Beta'  => 7,
				];
				return isset( $map[ $name ] )
					? new \WP_Term(
						[
							'term_id' => $map[ $name ],
							'name'    => $name,
						]
					)
					: false;
			}
		);

		// The unknown tag is created rather than silently dropped.
		\WP_Mock::userFunction( 'wp_insert_term' )
			->with( 'Ghost', 'post_tag' )
			->once()
			->andReturn(
				[
					'term_id'          => 9,
					'term_taxonomy_id' => 9,
				]
			);

		$result = $this->invoke_sanitize( [ 'tags' => 'Alpha, Ghost, Beta' ] );

		$this->assertSame( [ 3, 9, 7 ], $result['tags'] );
		$this->assertTrue( array_is_list( $result['tags'] ) );
	}

	/**
	 * A tag whose creation fails (wp_insert_term returns a WP_Error) is skipped
	 * rather than aborting the whole save.
	 */
	public function test_sanitize_repo_config_skips_uncreatable_tags(): void {
		$this->stub_sanitizers();

		\WP_Mock::userFunction( 'get_term_by' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_insert_term' )->andReturn( new \WP_Error( 'invalid_term', 'nope' ) );

		$result = $this->invoke_sanitize( [ 'tags' => 'Bad Tag' ] );

		$this->assertSame( [], $result['tags'] );
	}
}
