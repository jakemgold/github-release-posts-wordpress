<?php
/**
 * Core plugin class.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

use TenUp\ChangelogToBlogPost\AI\AI_Processor;
use TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory;
use TenUp\ChangelogToBlogPost\GitHub\API_Client;
use TenUp\ChangelogToBlogPost\GitHub\Release_Monitor;
use TenUp\ChangelogToBlogPost\GitHub\Release_Queue;
use TenUp\ChangelogToBlogPost\GitHub\Release_State;
use TenUp\ChangelogToBlogPost\GitHub\Version_Comparator;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Plugin singleton — the single entry point for all feature classes.
 *
 * All feature classes are instantiated exclusively from init(). No feature
 * class should instantiate another feature class directly.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin
	 */
	private static Plugin $instance;

	/**
	 * Gets the singleton instance, creating and setting it up on first call.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Registers core WordPress hooks.
	 *
	 * Called once by get_instance(). Hooks i18n to 'init' and defers
	 * feature class instantiation to 'init' via init().
	 *
	 * @return void
	 */
	protected function setup(): void {
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'init', [ $this, 'i18n' ] );
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Registers the 'weekly' WP-Cron schedule.
	 *
	 * WordPress ships with 'hourly', 'twicedaily', and 'daily' but not 'weekly'.
	 * This adds it so that developers who filter `ctbp_check_frequency` to 'weekly'
	 * get a working schedule. Skips registration if another plugin already defined it.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'changelog-to-blog-post' ),
			];
		}

		return $schedules;
	}

	/**
	 * Loads the plugin text domain for internationalisation.
	 *
	 * @return void
	 */
	public function i18n(): void {
		load_plugin_textdomain(
			'changelog-to-blog-post',
			false,
			CHANGELOG_TO_BLOG_POST_PATH . 'languages'
		);
	}

	/**
	 * Instantiates all feature classes.
	 *
	 * This is the single place where feature objects are created. To add a
	 * new feature, instantiate its class here and call ->setup() on it:
	 *
	 *   ( new \TenUp\ChangelogToBlogPost\Feature\MyFeature() )->setup();
	 *
	 * Feature classes must not instantiate other feature classes.
	 *
	 * @return void
	 */
	public function init(): void {
		( new \TenUp\ChangelogToBlogPost\Admin\Admin_Page() )->setup();

		// Wire the release monitor to both cron hooks.
		$monitor = new Release_Monitor(
			new API_Client( new Global_Settings() ),
			new Release_State(),
			new Version_Comparator(),
			new Release_Queue(),
			new Repository_Settings()
		);

		add_action( Plugin_Constants::CRON_HOOK_RELEASE_CHECK, [ $monitor, 'run' ] );
		add_action( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY, [ $monitor, 'run' ] );

		// AI generation — processes releases queued by the monitor.
		$global_settings = new Global_Settings();
		( new AI_Processor( new AI_Provider_Factory( $global_settings ) ) )->setup();
	}
}
