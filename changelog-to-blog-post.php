<?php
/**
 * Plugin Name:       GitHub Release Posts
 * Plugin URI:        https://github.com/10up/changelog-to-blog-post
 * Description:       Automatically generate blog posts from GitHub releases using AI.
 * Version:           0.8.0
 * Requires at least: 7.0
 * Requires PHP:      8.2
 * Author:            Jake Goldman, Fueled (formerly 10up)
 * Author URI:        https://www.linkedin.com/in/jacobgoldman/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       changelog-to-blog-post
 * Domain Path:       /languages
 *
 * @package           ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'CHANGELOG_TO_BLOG_POST_VERSION' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_VERSION', '0.8.0' );
}

if ( ! defined( 'CHANGELOG_TO_BLOG_POST_URL' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'CHANGELOG_TO_BLOG_POST_PATH' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'CHANGELOG_TO_BLOG_POST_INC' ) ) {
	define( 'CHANGELOG_TO_BLOG_POST_INC', CHANGELOG_TO_BLOG_POST_PATH . 'includes/' );
}

// Require Composer autoloader if it exists.
if ( file_exists( CHANGELOG_TO_BLOG_POST_PATH . 'vendor/autoload.php' ) ) {
	require_once CHANGELOG_TO_BLOG_POST_PATH . 'vendor/autoload.php';
}

// Require WordPress 7.0+ AI Client API.
if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'GitHub Release Posts requires WordPress 7.0 or later with the AI Client API. Please update WordPress.', 'changelog-to-blog-post' );
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Gets the instance of the Plugin class.
 *
 * @return Plugin
 */
function changelog_to_blog_post() {
	return Plugin::get_instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\changelog_to_blog_post' );

register_activation_hook( __FILE__, [ 'TenUp\ChangelogToBlogPost\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TenUp\ChangelogToBlogPost\Activator', 'deactivate' ] );
