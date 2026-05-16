<?php
/**
 * Plugin Name:       GitHub Release Posts
 * Plugin URI:        https://github.com/jakemgold/github-release-posts-wordpress
 * Description:       Automatically generate blog posts from GitHub releases using AI.
 * Version:           0.10.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Jake Goldman, Fueled (formerly 10up)
 * Author URI:        https://www.linkedin.com/in/jacobgoldman/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       github-release-posts
 * Domain Path:       /languages
 *
 * @package           GitHubReleasePosts
 */

namespace GitHubReleasePosts;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GITHUB_RELEASE_POSTS_VERSION' ) ) {
	define( 'GITHUB_RELEASE_POSTS_VERSION', '0.10.0' );
}

if ( ! defined( 'GITHUB_RELEASE_POSTS_URL' ) ) {
	define( 'GITHUB_RELEASE_POSTS_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'GITHUB_RELEASE_POSTS_PATH' ) ) {
	define( 'GITHUB_RELEASE_POSTS_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'GITHUB_RELEASE_POSTS_INC' ) ) {
	define( 'GITHUB_RELEASE_POSTS_INC', GITHUB_RELEASE_POSTS_PATH . 'includes/' );
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
			echo esc_html__( 'GitHub Release Posts requires WordPress 7.0 or later with the AI Client API, and PHP 8.2 or later. The plugin is inactive until your environment meets these requirements.', 'github-release-posts' );
			echo '</p></div>';
		}
	);
	return;
}

// Load Composer autoloader. Without it, none of our namespaced classes
// can resolve — surface a clear admin notice rather than fataling on
// missing-class errors when someone clones the repo without running
// `composer install`.
if ( ! file_exists( GITHUB_RELEASE_POSTS_PATH . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'GitHub Release Posts is missing its Composer dependencies. From the plugin directory, run `composer install --no-dev --optimize-autoloader` and reload this page.', 'github-release-posts' );
			echo '</p></div>';
		}
	);
	return;
}

require_once GITHUB_RELEASE_POSTS_PATH . 'vendor/autoload.php';

// Register custom WP-Cron schedules at file load time (NOT via plugins_loaded)
// so the 'weekly' interval is available when Activator::activate() schedules
// the release-check event. Plugin::setup() runs on plugins_loaded, which fires
// AFTER the activation hook — registering the filter there would leave a
// silent gap where `wp_schedule_event(time(), 'weekly', ...)` could fail.
add_filter( 'cron_schedules', [ Plugin::class, 'add_cron_schedules' ] );

/**
 * Gets the instance of the Plugin class.
 *
 * @return Plugin
 */
function github_release_posts() {
	return Plugin::get_instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\github_release_posts' );

register_activation_hook( __FILE__, [ 'GitHubReleasePosts\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'GitHubReleasePosts\Activator', 'deactivate' ] );
