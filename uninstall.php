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

// Delete per-repo state options (ctbp_repo_state_{hash}).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'ctbp_repo_state_%'
	)
);

// Clean up deprecated/legacy option keys from earlier plugin versions.
delete_option( 'ctbp_default_post_status' );
delete_option( 'ctbp_default_category' );
delete_option( 'ctbp_default_tags' );
delete_option( 'ctbp_check_interval' );
delete_option( 'ctbp_notification_email' );
delete_option( 'ctbp_notification_email_secondary' );
delete_option( 'ctbp_notification_trigger' );
delete_option( 'ctbp_notifications_enabled' );

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
//      ctbp_rel_{hash}     — GitHub release cache
//      ctbp_ai_resp_{hash} — AI response cache
//      ctbp_rate_limit_*   — Rate limit tracking
//    Delete by prefix using a direct database query since WordPress does
//    not provide a wildcard delete API for transients.
// -------------------------------------------------------------------------
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_ctbp_%',
		'_transient_timeout_ctbp_%'
	)
);
