<?php
/**
 * Tests for uninstall.php behaviour.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests;

use GitHubReleasePosts\Plugin_Constants;
use WP_Mock\Tools\TestCase;

/**
 * Tests the uninstall cleanup logic.
 *
 * Because uninstall.php is a procedural script, we test it by defining
 * WP_UNINSTALL_PLUGIN, requiring the file, and asserting the expected
 * WordPress functions were called.
 */
class UninstallTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		// Mock plugin_dir_path so uninstall.php can resolve the autoloader path.
		\WP_Mock::userFunction( 'plugin_dir_path' )->andReturn( dirname( __DIR__, 3 ) . '/' );
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Uninstall deletes every plugin option except the repository
	 * configuration, which is intentionally retained so a reinstall restores
	 * the repository list and its per-repo settings.
	 */
	public function test_uninstall_deletes_options_except_repositories(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$defaults = Plugin_Constants::get_defaults();

		foreach ( array_keys( $defaults ) as $key ) {
			if ( Plugin_Constants::OPTION_REPOSITORIES === $key ) {
				// The repository config must survive uninstall.
				\WP_Mock::userFunction( 'delete_option' )
					->with( $key )
					->never();
				continue;
			}

			\WP_Mock::userFunction( 'delete_option' )
				->with( $key )
				->once();
		}

		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( null );

		// Mock wpdb.
		global $wpdb;
		$wpdb         = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'query', 'prepare' ] )
			->getMock();
		$wpdb->options = 'wp_options';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'query' )->willReturn( 1 );

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertConditionsMet();
	}

	/**
	 * Uninstall retains the plugin's post meta. Generated posts are kept, and
	 * their _ghrp_* meta is the dedup/recognition key that prevents duplicate
	 * posts on reinstall — so it must never be deleted.
	 */
	public function test_uninstall_retains_post_meta(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( null );

		// Post meta must be preserved — delete_post_meta_by_key is never called.
		\WP_Mock::userFunction( 'delete_post_meta_by_key' )->never();

		global $wpdb;
		$wpdb         = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'query', 'prepare' ] )
			->getMock();
		$wpdb->options = 'wp_options';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'query' )->willReturn( 1 );

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertConditionsMet();
	}

	/**
	 * Uninstall clears all plugin cron events.
	 */
	public function test_uninstall_clears_cron_events(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );

		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
			->with( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->once();

		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
			->with( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY )
			->once();

		global $wpdb;
		$wpdb         = $this->getMockBuilder( \stdClass::class )
			->addMethods( [ 'query', 'prepare' ] )
			->getMock();
		$wpdb->options = 'wp_options';
		$wpdb->method( 'prepare' )->willReturn( '' );
		$wpdb->method( 'query' )->willReturn( 1 );

		require dirname( __DIR__, 3 ) . '/uninstall.php';

		$this->assertConditionsMet();
	}
}
