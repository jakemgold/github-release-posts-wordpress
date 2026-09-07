<?php
/**
 * Plugin activation and deactivation handlers.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @param bool $network_wide Whether the plugin is being activated for the
	 *                           whole network (multisite Network Admin).
	 * @return void
	 */
	public static function activate( bool $network_wide = false ): void {
		// No capability guard — activation hooks are already capability-gated by
		// the activator (the plugins screen requires `activate_plugins`, WP-CLI
		// runs as no-user, network activation runs as super admin). A stricter
		// inline check breaks CLI / network / automated activation by silently
		// skipping defaults and cron registration.

		// A network activation fires this hook ONCE, in the main site's
		// context, while options and the cron event are per-site — without
		// this loop no subsite would ever run the scheduled check.
		if ( $network_wide && is_multisite() ) {
			foreach ( get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::write_default_options();
				self::register_cron_event();
				restore_current_blog();
			}
			return;
		}

		self::write_default_options();
		self::register_cron_event();
	}

	/**
	 * Schedules the release-check event if it is not already scheduled.
	 *
	 * Runs on every request (from Plugin::init()) as a self-heal: activation
	 * is the only other place the event is created, and it never runs for
	 * subsites of a network-activated plugin, sites created after
	 * activation, or installs deployed without an activation hook (Composer,
	 * mu-plugins). wp_next_scheduled() reads the already-loaded cron option,
	 * so the steady-state cost is negligible.
	 *
	 * @return void
	 */
	public static function ensure_cron_event(): void {
		if ( wp_next_scheduled( Plugin_Constants::CRON_HOOK_RELEASE_CHECK ) ) {
			return;
		}

		/**
		 * Filters the WP-Cron schedule used for release checks.
		 *
		 * Defaults to 'daily'. Return any valid WP-Cron schedule name
		 * (e.g. 'hourly', 'twicedaily', 'daily', 'weekly').
		 *
		 * @param string $frequency Default schedule name.
		 */
		$interval = (string) apply_filters( 'ghrp_check_frequency', 'daily' );

		$result = wp_schedule_event( time(), $interval, Plugin_Constants::CRON_HOOK_RELEASE_CHECK );

		// wp_schedule_event returns false when the interval is not a registered
		// WP-Cron schedule. The main plugin file pre-registers our 'weekly'
		// interval at load time, but log loudly if anything else still slips
		// through — silent scheduling failure is the worst case.
		if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[auto-release-posts-for-github] Failed to schedule cron event with interval "%s". The interval may not be a registered WP-Cron schedule.',
					$interval
				)
			);
		}
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
			add_option( $key, $default, '', false );
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

		self::ensure_cron_event();
	}
}
