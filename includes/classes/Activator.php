<?php
/**
 * Plugin activation and deactivation handlers.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

/**
 * Handles plugin activation and deactivation lifecycle events.
 */
class Activator {

	/**
	 * Runs on plugin activation.
	 *
	 * - Checks capability before doing anything (manage_options required).
	 * - Writes default option values with add_option() so existing values
	 *   are preserved on reactivation.
	 * - Clears any stale cron event then registers a fresh recurring one.
	 *
	 * @return void
	 */
	public static function activate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::write_default_options();
		self::register_cron_event();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * Clears all plugin-registered cron events. Does NOT delete options
	 * or any generated posts — those are only removed on uninstall.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
		wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY );
	}

	/**
	 * Writes default option values using add_option() so that any
	 * existing values are preserved when the plugin is reactivated.
	 *
	 * @return void
	 */
	private static function write_default_options(): void {
		foreach ( Plugin_Constants::get_defaults() as $key => $default ) {
			add_option( $key, $default );
		}
	}

	/**
	 * Clears any existing release-check cron event and registers a fresh
	 * recurring one using the configured (or default) interval.
	 *
	 * @return void
	 */
	private static function register_cron_event(): void {
		// Clear stale event first (handles crash-reinstall scenario).
		wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );

		$interval = get_option(
			Plugin_Constants::OPTION_CHECK_INTERVAL,
			'daily'
		);

		if ( ! wp_next_scheduled( Plugin_Constants::CRON_HOOK_RELEASE_CHECK ) ) {
			wp_schedule_event( time(), $interval, Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
		}
	}
}
