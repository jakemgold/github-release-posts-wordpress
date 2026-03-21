<?php
/**
 * Plugin Name:       Changelog to Blog Post
 * Plugin URI:        https://github.com/10up/changelog-to-blog-post
 * Description:       Convert changelog entries into WordPress blog posts.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            10up
 * Author URI:        https://10up.com
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
	define( 'CHANGELOG_TO_BLOG_POST_VERSION', '1.0.0' );
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

/**
 * Gets the instance of the Plugin class.
 *
 * @return Plugin
 */
function changelog_to_blog_post() {
	return Plugin::get_instance();
}

add_action( 'plugins_loaded', __NAMESPACE__ . '\changelog_to_blog_post' );
