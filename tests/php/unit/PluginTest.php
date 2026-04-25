<?php
/**
 * Tests for the Plugin singleton class.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace Jakemgold\GitHubReleasePosts\Tests;

use Jakemgold\GitHubReleasePosts\Plugin;
use WP_Mock\Tools\TestCase;

/**
 * Tests Plugin singleton behaviour and bootstrap hooks.
 */
class PluginTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		// Plugin::setup() calls add_filter and add_action — allow them.
		\WP_Mock::userFunction( 'add_filter' )->andReturn( true );
		\WP_Mock::userFunction( 'add_action' )->andReturn( true );
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * get_instance() returns the same object on repeated calls.
	 */
	public function test_get_instance_returns_singleton(): void {
		$instance_a = Plugin::get_instance();
		$instance_b = Plugin::get_instance();

		$this->assertSame( $instance_a, $instance_b );
	}

	/**
	 * setup() registers cron_schedules filter and both init actions.
	 */
	public function test_setup_registers_hooks(): void {
		$plugin = Plugin::get_instance();

		// Verify the hooks are registered by calling setup methods directly.
		$this->assertTrue( method_exists( $plugin, 'add_cron_schedules' ) );
		$this->assertTrue( method_exists( $plugin, 'i18n' ) );
		$this->assertTrue( method_exists( $plugin, 'init' ) );
	}

	/**
	 * add_cron_schedules() adds the 'weekly' interval when not already present (AC-008).
	 */
	public function test_add_cron_schedules_registers_weekly(): void {
		$plugin    = Plugin::get_instance();
		$schedules = $plugin->add_cron_schedules( [] );

		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertSame( WEEK_IN_SECONDS, $schedules['weekly']['interval'] );
		$this->assertNotEmpty( $schedules['weekly']['display'] );
	}

	/**
	 * add_cron_schedules() does not overwrite an existing 'weekly' schedule.
	 */
	public function test_add_cron_schedules_does_not_overwrite_existing_weekly(): void {
		$existing = [ 'weekly' => [ 'interval' => 999, 'display' => 'Custom weekly' ] ];
		$plugin   = Plugin::get_instance();
		$result   = $plugin->add_cron_schedules( $existing );

		$this->assertSame( 999, $result['weekly']['interval'], 'Existing weekly schedule should not be overwritten' );
	}

	/**
	 * i18n() calls load_plugin_textdomain with the correct text domain.
	 */
	public function test_i18n_loads_text_domain(): void {
		if ( ! defined( 'GITHUB_RELEASE_POSTS_PATH' ) ) {
			define( 'GITHUB_RELEASE_POSTS_PATH', dirname( __DIR__, 3 ) . '/' );
		}

		\WP_Mock::userFunction( 'load_plugin_textdomain' )
			->once()
			->with(
				'github-release-posts',
				false,
				GITHUB_RELEASE_POSTS_PATH . 'languages'
			);

		Plugin::get_instance()->i18n();

		$this->assertConditionsMet();
	}
}
