<?php
/**
 * Plugin uninstall handler.
 *
 * Runs when the plugin is deleted from the WordPress Plugins screen.
 * Removes all plugin data: options, post meta, transients, and cron events.
 * Generated posts are intentionally retained — site owners keep their content.
 *
 * @package GitHubReleasePosts
 */

// Guard: only run when WordPress itself calls this file during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load autoloader so we can use Plugin_Constants.
if ( file_exists( plugin_dir_path( __FILE__ ) . 'vendor/autoload.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
}

use Jakemgold\GitHubReleasePosts\Plugin_Constants;

// -------------------------------------------------------------------------
// 1. Delete all plugin options from wp_options.
// -------------------------------------------------------------------------
foreach ( array_keys( Plugin_Constants::get_defaults() ) as $ghrp_option_key ) {
	delete_option( $ghrp_option_key );
}

// Delete per-repo state options (ghrp_repo_state_{hash}).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		'ghrp_repo_state_%'
	)
);

// -------------------------------------------------------------------------
// 2. Delete all plugin post meta from every post.
// Posts themselves are retained — only meta is removed.
// -------------------------------------------------------------------------
$ghrp_meta_keys = [
	Plugin_Constants::META_SOURCE_REPO,
	Plugin_Constants::META_RELEASE_TAG,
	Plugin_Constants::META_RELEASE_URL,
	Plugin_Constants::META_GENERATED_BY,
];

foreach ( $ghrp_meta_keys as $ghrp_meta_key ) {
	delete_post_meta_by_key( $ghrp_meta_key );
}

// -------------------------------------------------------------------------
// 3. Clear all plugin-registered cron events.
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY );

// -------------------------------------------------------------------------
// 4. Delete plugin transients.
// Transients follow the naming conventions:
// ghrp_rel_{hash}     — GitHub release cache
// ghrp_ai_resp_{hash} — AI response cache
// ghrp_rate_limit_*   — Rate limit tracking
// Delete by prefix using a direct database query since WordPress does
// not provide a wildcard delete API for transients.
// -------------------------------------------------------------------------
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_ghrp_%',
		'_transient_timeout_ghrp_%'
	)
);
