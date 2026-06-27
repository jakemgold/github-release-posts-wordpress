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

use GitHubReleasePosts\Plugin_Constants;

// -------------------------------------------------------------------------
// 1. Delete plugin options from wp_options.
//
// The repository configuration (OPTION_REPOSITORIES) is intentionally
// retained: generated posts are kept on uninstall, and the per-repo settings
// (author, status, categories, tags, featured image, project link) live only
// in this option. Preserving it means a reinstall restores the repository
// list and lets "Regenerate" reproduce posts with their original settings,
// instead of silently falling back to defaults. The option is stored
// non-autoloaded, so it imposes no per-request cost while the plugin is gone.
// -------------------------------------------------------------------------
foreach ( array_keys( Plugin_Constants::get_defaults() ) as $ghrp_option_key ) {
	if ( Plugin_Constants::OPTION_REPOSITORIES === $ghrp_option_key ) {
		continue;
	}
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
// 2. Plugin post meta is intentionally retained.
//
// Generated posts are kept on uninstall, and their _ghrp_* meta keys
// (source repo, release tag, release URL, generated-by) are what let the
// plugin recognize those posts again after a reinstall: the source-repo +
// release-tag pair is the deduplication key used by Release_Monitor::find_post(),
// which prevents duplicate posts, and the source-repo key powers the
// "Last post" column. Deleting the meta while keeping the posts would orphan
// them and cause duplicate generation on reinstall, so we leave it in place.
// -------------------------------------------------------------------------

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
