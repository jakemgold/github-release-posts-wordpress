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

namespace Jakemgold\GitHubReleasePosts;

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

// Load Composer autoloader.
if ( file_exists( GITHUB_RELEASE_POSTS_PATH . 'vendor/autoload.php' ) ) {
	require_once GITHUB_RELEASE_POSTS_PATH . 'vendor/autoload.php';
}

/**
 * Gets the instance of the Plugin class.
 *
 * @return Plugin
 */
function github_release_posts() {
	return Plugin::get_instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\github_release_posts' );

register_activation_hook( __FILE__, [ 'Jakemgold\GitHubReleasePosts\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Jakemgold\GitHubReleasePosts\Activator', 'deactivate' ] );
