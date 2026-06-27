<?php
/**
 * Plugin Name:       Auto Release Posts for GitHub
 * Plugin URI:        https://github.com/jakemgold/github-release-posts-wordpress
 * Description:       Automatically generate blog posts from GitHub releases using AI.
 * Version:           1.0.3
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Jake Goldman, Fueled (formerly 10up)
 * Author URI:        https://www.linkedin.com/in/jacobgoldman/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       auto-release-posts-for-github
 * Domain Path:       /languages
 *
 * @package           GitHubReleasePosts
 */

namespace GitHubReleasePosts;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GHRP_VERSION' ) ) {
	define( 'GHRP_VERSION', '1.0.3' );
}

if ( ! defined( 'GHRP_URL' ) ) {
	define( 'GHRP_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GHRP_PATH' ) ) {
	define( 'GHRP_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GHRP_INC' ) ) {
	define( 'GHRP_INC', GHRP_PATH . 'includes/' );
}

// Bail early on incompatible environments, before loading the autoloader
// or referencing any plugin classes. The presence of wp_ai_client_prompt()
// is the actual signal we care about (rather than the WordPress version
// string, which sorts prereleases like "7.0-RC1" below "7.0").
if (
	version_compare( PHP_VERSION, '8.2', '<' )
	|| ! function_exists( 'wp_ai_client_prompt' )
) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Auto Release Posts for GitHub requires WordPress 7.0 or later with the AI Client API, and PHP 8.2 or later. The plugin is inactive until your environment meets these requirements.', 'auto-release-posts-for-github' );
			echo '</p></div>';
		}
	);
	return;
}

// Load Composer autoloader. Two install paths to support:
//
// - Standalone install (zip / SVN checkout): the plugin ships with its
// own bundled vendor/ directory, and we need to require its autoload.php
// before any namespaced class reference resolves.
//
// - Composer-managed install (`composer require github-release-posts/...`):
// the plugin lives under wp-content/plugins/ but has no local vendor/ —
// the consumer's project-level autoloader has already merged our PSR-4
// mapping. Detect that by checking whether our Plugin class is already
// autoloadable and skip the local require.
if ( ! class_exists( 'GitHubReleasePosts\\Plugin' ) ) {
	if ( ! file_exists( GHRP_PATH . 'vendor/autoload.php' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Auto Release Posts for GitHub is missing its Composer dependencies. From the plugin directory, run `composer install --no-dev --optimize-autoloader` and reload this page.', 'auto-release-posts-for-github' );
				echo '</p></div>';
			}
		);
		return;
	}

	require_once GHRP_PATH . 'vendor/autoload.php';
}

// Register custom WP-Cron schedules at file load time (NOT via plugins_loaded)
// so the 'weekly' interval is available when Activator::activate() schedules
// the release-check event. Plugin::setup() runs on plugins_loaded, which fires
// AFTER the activation hook — registering the filter there would leave a
// silent gap where `wp_schedule_event(time(), 'weekly', ...)` could fail.
add_filter( 'cron_schedules', [ Plugin::class, 'add_cron_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- Intervals are defined statically in Plugin::add_cron_schedules().

/**
 * Gets the instance of the Plugin class.
 *
 * @return Plugin
 */
function github_release_posts() {
	return Plugin::get_instance();
}

// Wrap in a void closure: action callbacks shouldn't return values, and
// `github_release_posts()` is also used as a public entry point so its
// `Plugin` return type must be preserved.
add_action(
	'plugins_loaded',
	static function (): void {
		github_release_posts();
	}
);

register_activation_hook( __FILE__, [ 'GitHubReleasePosts\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'GitHubReleasePosts\Activator', 'deactivate' ] );
