<?php
/**
 * Tests for Admin_Page class.
 *
 * @package ChangelogToBlogPost\Tests
 */

namespace TenUp\ChangelogToBlogPost\Tests\Admin;

use TenUp\ChangelogToBlogPost\Admin\Admin_Page;
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
		if ( ! defined( 'CHANGELOG_TO_BLOG_POST_URL' ) ) {
			define( 'CHANGELOG_TO_BLOG_POST_URL', 'http://example.com/wp-content/plugins/changelog-to-blog-post/' );
		}
		if ( ! defined( 'CHANGELOG_TO_BLOG_POST_VERSION' ) ) {
			define( 'CHANGELOG_TO_BLOG_POST_VERSION', '1.0.0' );
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
		$ref->setValue( $page, 'tools_page_changelog-to-blog-post' );

		$page->enqueue_assets( 'tools_page_changelog-to-blog-post' );

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
			->with( 'tools.php?page=changelog-to-blog-post' )
			->andReturn( 'http://example.com/wp-admin/tools.php?page=changelog-to-blog-post' );

		$url = ( new Admin_Page() )->get_page_url();

		$this->assertSame( 'http://example.com/wp-admin/tools.php?page=changelog-to-blog-post', $url );
	}
}
