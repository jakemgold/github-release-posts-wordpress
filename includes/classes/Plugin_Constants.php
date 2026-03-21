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
	const OPTION_REPOSITORIES = 'changelog_to_blog_post_repositories';

	/**
	 * Active AI provider slug (e.g. 'openai', 'anthropic', 'gemini', 'classifai').
	 */
	const OPTION_AI_PROVIDER = 'changelog_to_blog_post_ai_provider';

	/**
	 * Encrypted API keys, keyed by provider slug.
	 * Stored as a serialised array; each value is a libsodium-encrypted string.
	 */
	const OPTION_AI_API_KEYS = 'changelog_to_blog_post_ai_api_keys';

	/**
	 * Global default post status: 'draft' or 'publish'.
	 */
	const OPTION_DEFAULT_POST_STATUS = 'changelog_to_blog_post_default_post_status';

	/**
	 * Global default category ID (integer).
	 */
	const OPTION_DEFAULT_CATEGORY = 'changelog_to_blog_post_default_category';

	/**
	 * Global default tag IDs (array of integers).
	 */
	const OPTION_DEFAULT_TAGS = 'changelog_to_blog_post_default_tags';

	/**
	 * WP-Cron check interval: 'hourly', 'twicedaily', 'daily', or 'weekly'.
	 */
	const OPTION_CHECK_INTERVAL = 'changelog_to_blog_post_check_interval';

	/**
	 * Primary notification email address.
	 * Empty string = fall back to WordPress admin email at use time.
	 */
	const OPTION_NOTIFICATION_EMAIL = 'changelog_to_blog_post_notification_email';

	/**
	 * Optional secondary notification email address.
	 */
	const OPTION_NOTIFICATION_EMAIL_SECONDARY = 'changelog_to_blog_post_notification_email_secondary';

	/**
	 * GitHub Personal Access Token (encrypted at rest using libsodium).
	 * When set, raises the GitHub API rate limit from 60 to 5,000 req/hr.
	 */
	const OPTION_GITHUB_PAT = 'changelog_to_blog_post_github_pat';

	/**
	 * Notification trigger: 'draft', 'publish', or 'both'.
	 */
	const OPTION_NOTIFICATION_TRIGGER = 'changelog_to_blog_post_notification_trigger';

	/**
	 * Whether email notifications are enabled (boolean).
	 */
	const OPTION_NOTIFICATIONS_ENABLED = 'changelog_to_blog_post_notifications_enabled';

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
	const CRON_HOOK_RELEASE_CHECK = 'changelog_to_blog_post_release_check';

	/**
	 * Hook name for the one-time rate-limit retry cron event.
	 */
	const CRON_HOOK_RATE_LIMIT_RETRY = 'changelog_to_blog_post_rate_limit_retry';

	// -------------------------------------------------------------------------
	// Post meta keys
	// -------------------------------------------------------------------------

	/**
	 * Source GitHub repository identifier (owner/repo).
	 */
	const META_SOURCE_REPO = '_changelog_source_repo';

	/**
	 * GitHub release tag string (e.g. 'v2.3.1').
	 */
	const META_RELEASE_TAG = '_changelog_release_tag';

	/**
	 * GitHub release page URL.
	 */
	const META_RELEASE_URL = '_changelog_release_url';

	/**
	 * AI provider slug used to generate the post content.
	 */
	const META_GENERATED_BY = '_changelog_generated_by';

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
			self::OPTION_REPOSITORIES                => [],
			self::OPTION_AI_PROVIDER                 => '',
			self::OPTION_AI_API_KEYS                 => [],
			self::OPTION_DEFAULT_POST_STATUS         => 'draft',
			self::OPTION_DEFAULT_CATEGORY            => 0,
			self::OPTION_DEFAULT_TAGS                => [],
			self::OPTION_CHECK_INTERVAL              => 'daily',
			self::OPTION_NOTIFICATION_EMAIL          => '',
			self::OPTION_NOTIFICATION_EMAIL_SECONDARY => '',
			self::OPTION_NOTIFICATION_TRIGGER        => 'draft',
			self::OPTION_NOTIFICATIONS_ENABLED       => true,
			self::OPTION_GITHUB_PAT                  => '',
		];
	}
}
