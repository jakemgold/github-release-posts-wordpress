<?php
/**
 * Plugin constants — option keys and default values.
 *
 * All wp_options keys and their default values are centralised here.
 * Use these constants everywhere rather than hardcoded strings.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

/**
 * Centralises all option key strings and their default values.
 */
class Plugin_Constants {

	// -------------------------------------------------------------------------
	// Option keys
	// -------------------------------------------------------------------------

	/**
	 * Tracked repositories list.
	 * Stored as a serialised array of repo config arrays.
	 */
	const OPTION_REPOSITORIES = 'ctbp_repositories';

	/**
	 * Active AI provider slug. Always 'wp_ai_client' since 2.0.
	 */
	const OPTION_AI_PROVIDER = 'ctbp_ai_provider';

	/**
	 * Global default post status: 'draft' or 'publish'.
	 */
	const OPTION_DEFAULT_POST_STATUS = 'ctbp_default_post_status';

	/**
	 * Global default category ID (integer).
	 */
	const OPTION_DEFAULT_CATEGORY = 'ctbp_default_category';

	/**
	 * Global default tag IDs (array of integers).
	 */
	const OPTION_DEFAULT_TAGS = 'ctbp_default_tags';

	/**
	 * WP-Cron check interval: 'hourly', 'twicedaily', 'daily', or 'weekly'.
	 *
	 * @deprecated Use the `ctbp_check_frequency` filter instead. This constant
	 *             is retained for sites that may have stored a value in this option
	 *             but is no longer written or read by the plugin.
	 */
	const OPTION_CHECK_INTERVAL = 'ctbp_check_interval';

	/**
	 * Whether the site admin email (from Settings → General) receives
	 * notifications when posts are generated (boolean).
	 */
	const OPTION_NOTIFY_SITE_OWNER = 'ctbp_notify_site_owner';

	/**
	 * Comma-separated list of additional email addresses to notify (up to 5).
	 * Stored as a single string; parsed at send time.
	 */
	const OPTION_ADDITIONAL_EMAILS = 'ctbp_additional_emails';

	/**
	 * GitHub Personal Access Token (encrypted at rest using libsodium).
	 * When set, raises the GitHub API rate limit from 60 to 5,000 req/hr.
	 */
	const OPTION_GITHUB_PAT = 'ctbp_github_pat';

	/**
	 * Post audience level: 'general', 'mixed', 'developer', or 'engineering'.
	 * Controls how technical the AI-generated post content is.
	 */
	const OPTION_AUDIENCE_LEVEL = 'ctbp_audience_level';

	/**
	 * Legacy notification trigger option key.
	 *
	 * @deprecated Use OPTION_NOTIFY_SITE_OWNER and OPTION_ADDITIONAL_EMAILS instead.
	 */
	const OPTION_NOTIFICATION_TRIGGER = 'ctbp_notification_trigger';

	/**
	 * Legacy notifications enabled option key.
	 *
	 * @deprecated Use OPTION_NOTIFY_SITE_OWNER instead.
	 */
	const OPTION_NOTIFICATIONS_ENABLED = 'ctbp_notifications_enabled';

	// -------------------------------------------------------------------------
	// Release monitoring option keys
	// -------------------------------------------------------------------------

	/**
	 * Prefix for per-repo release state options.
	 * Full key: OPTION_REPO_STATE_PREFIX . md5( 'owner/repo' )
	 */
	const OPTION_REPO_STATE_PREFIX = 'ctbp_repo_state_';

	/**
	 * In-process queue of newly detected releases pending AI generation.
	 * Stored as a serialised array; cleared after each cron run.
	 */
	const OPTION_RELEASE_QUEUE = 'ctbp_release_queue';

	// -------------------------------------------------------------------------
	// Transient keys
	// -------------------------------------------------------------------------

	/**
	 * Prefix for per-repo release cache transients.
	 * Full key: TRANSIENT_RELEASE_PREFIX . md5( 'owner/repo' )
	 */
	const TRANSIENT_RELEASE_PREFIX = 'ctbp_rel_';

	/**
	 * Stores the last-known GitHub API rate limit remaining count.
	 */
	const TRANSIENT_RATE_LIMIT_REMAINING = 'ctbp_rate_limit_remaining';

	// -------------------------------------------------------------------------
	// Cron hook names
	// -------------------------------------------------------------------------

	/**
	 * Hook name for the recurring release-check cron event.
	 */
	const CRON_HOOK_RELEASE_CHECK = 'ctbp_release_check';

	/**
	 * Hook name for the one-time rate-limit retry cron event.
	 */
	const CRON_HOOK_RATE_LIMIT_RETRY = 'ctbp_rate_limit_retry';

	/**
	 * Unix timestamp of the most recent completed cron run start.
	 * 0 means no run has ever occurred.
	 */
	const OPTION_LAST_RUN_AT = 'ctbp_last_run_at';

	// -------------------------------------------------------------------------
	// AI integration option/transient keys
	// -------------------------------------------------------------------------

	/**
	 * Free-text custom prompt instructions entered by the site owner.
	 * Appended to the AI prompt to influence voice, style, and tone.
	 */
	const OPTION_CUSTOM_PROMPT_INSTRUCTIONS = 'ctbp_custom_prompt_instructions';

	/**
	 * Whether to append an AI disclosure statement to generated posts (boolean).
	 */
	const OPTION_AI_DISCLOSURE = 'ctbp_ai_disclosure';

	/**
	 * Transient storing AI failure notice data for admin display.
	 * Set when consecutive failures reach the threshold.
	 */
	const TRANSIENT_AI_FAILURE_NOTICE = 'ctbp_ai_failure_notice';

	/**
	 * Transient storing cron run results for the admin notice.
	 * Overwritten on each cron run; cleared after display.
	 */
	const TRANSIENT_CRON_RESULTS = 'ctbp_cron_run_results';

	/**
	 * Prefix for AI response cache transients.
	 * Full key: TRANSIENT_AI_RESPONSE_PREFIX . md5( 'owner/repo' . tag )
	 * TTL: 4 hours.
	 */
	const TRANSIENT_AI_RESPONSE_PREFIX = 'ctbp_ai_resp_';

	/**
	 * Consecutive AI generation failure counts per release.
	 * Stored as a serialised array keyed by md5( identifier . tag ).
	 * Reset to 0 on a successful generation.
	 */
	const OPTION_AI_FAILURE_COUNTS = 'ctbp_ai_failure_counts';

	// -------------------------------------------------------------------------
	// Post meta keys
	// -------------------------------------------------------------------------

	/**
	 * Source GitHub repository identifier (owner/repo).
	 */
	const META_SOURCE_REPO = '_ctbp_source_repo';

	/**
	 * GitHub release tag string (e.g. 'v2.3.1').
	 */
	const META_RELEASE_TAG = '_ctbp_release_tag';

	/**
	 * GitHub release page URL.
	 */
	const META_RELEASE_URL = '_ctbp_release_url';

	/**
	 * AI provider slug used to generate the post content.
	 */
	const META_GENERATED_BY = '_ctbp_generated_by';

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Returns the default option values written on plugin activation.
	 *
	 * Use add_option() (not update_option()) when writing these so that
	 * existing values are preserved on reactivation.
	 *
	 * @return array<string, mixed> Map of option key => default value.
	 */
	public static function get_defaults(): array {
		return [
			self::OPTION_REPOSITORIES               => [],
			self::OPTION_AI_PROVIDER                => 'wp_ai_client',
			self::OPTION_AUDIENCE_LEVEL             => 'mixed',
			self::OPTION_CUSTOM_PROMPT_INSTRUCTIONS => '',
			self::OPTION_AI_DISCLOSURE              => false,
			self::OPTION_LAST_RUN_AT                => 0,
			self::OPTION_NOTIFY_SITE_OWNER          => true,
			self::OPTION_ADDITIONAL_EMAILS          => '',
			self::OPTION_GITHUB_PAT                 => '',
			self::OPTION_RELEASE_QUEUE              => [],
			self::OPTION_AI_FAILURE_COUNTS          => [],
		];
	}
}
