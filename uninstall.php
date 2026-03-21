<?php
/**
 * Plugin uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress Plugins screen.
 * Removes all plugin data: options, post meta, transients, and cron events.
 * Generated posts are intentionally retained — site owners keep their content.
 *
 * @package ChangelogToBlogPost
 */

// Guard: only run when WordPress itself calls this file during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load autoloader so we can use Plugin_Constants.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

use TenUp\ChangelogToBlogPost\Plugin_Constants;

// -------------------------------------------------------------------------
// 1. Delete all plugin options from wp_options.
// -------------------------------------------------------------------------
foreach ( array_keys( Plugin_Constants::get_defaults() ) as $option_key ) {
	delete_option( $option_key );
}

// -------------------------------------------------------------------------
// 2. Delete all plugin post meta from every post.
//    Posts themselves are retained — only meta is removed.
// -------------------------------------------------------------------------
$meta_keys = [
	Plugin_Constants::META_SOURCE_REPO,
	Plugin_Constants::META_RELEASE_TAG,
	Plugin_Constants::META_RELEASE_URL,
	Plugin_Constants::META_GENERATED_BY,
];

foreach ( $meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// -------------------------------------------------------------------------
// 3. Clear all plugin-registered cron events.
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY );

// -------------------------------------------------------------------------
// 4. Delete plugin transients.
//    Transients follow the naming conventions:
//      changelog_to_blog_post_gh_{hash}   — GitHub API response cache
//      changelog_to_blog_post_ai_{hash}   — AI response cache
//    Delete by prefix using a direct database query since WordPress does
//    not provide a wildcard delete API for transients.
// -------------------------------------------------------------------------
global $wpdb;

$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_changelog_to_blog_post_%',
		'_transient_timeout_changelog_to_blog_post_%'
	)
);
