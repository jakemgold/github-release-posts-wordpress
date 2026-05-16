<?php
/**
 * Single source of truth for transient and object-cache key names.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts;

/**
 * Returns the full key string for every transient / cache entry the plugin
 * reads or writes. Callers go through this class instead of composing keys
 * inline, so a future invalidation pass (or a key rename) only has to touch
 * one file.
 *
 * Keep the `ghrp_` prefix on every key — uninstall.php relies on a
 * `_transient_ghrp_%` wildcard to clean up.
 */
final class Cache_Keys {

	private const RELEASE_PREFIX            = 'ghrp_rel_';
	private const RELEASE_FETCH_LOCK_PREFIX = 'ghrp_rel_lock_';
	private const AI_RESPONSE_PREFIX        = 'ghrp_ai_resp_';
	private const USER_REPOS_PREFIX         = 'ghrp_user_repos_';
	private const PAT_VALIDATION_PREFIX     = 'ghrp_pat_valid_';
	private const RATE_LIMIT_REMAINING      = 'ghrp_rate_limit_remaining';
	private const AI_FAILURE_NOTICE         = 'ghrp_ai_failure_notice';
	private const CRON_RESULTS              = 'ghrp_cron_run_results';
	private const CRON_LOCK                 = 'ghrp_cron_lock';
	private const CONNECTOR_STATUS          = 'ghrp_connector_status';
	private const ADMIN_ERRORS_PREFIX       = 'ghrp_admin_errors_';
	private const ADMIN_NOTICE_PREFIX       = 'ghrp_admin_notice_';

	/**
	 * Per-repo GitHub release cache (transient, 15 min TTL).
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return string
	 */
	public static function release( string $identifier ): string {
		return self::RELEASE_PREFIX . md5( $identifier );
	}

	/**
	 * Per-repo stampede lock guarding the release fetch (object cache).
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return string
	 */
	public static function release_fetch_lock( string $identifier ): string {
		return self::RELEASE_FETCH_LOCK_PREFIX . md5( $identifier );
	}

	/**
	 * Per-release AI response cache (transient, 4 hr TTL).
	 *
	 * @param string $identifier Repository identifier.
	 * @param string $tag        Release tag.
	 * @return string
	 */
	public static function ai_response( string $identifier, string $tag ): string {
		return self::AI_RESPONSE_PREFIX . md5( $identifier . $tag );
	}

	/**
	 * Last-known GitHub API rate limit remaining count (transient).
	 *
	 * @return string
	 */
	public static function rate_limit_remaining(): string {
		return self::RATE_LIMIT_REMAINING;
	}

	/**
	 * Cached list of repositories accessible to a given PAT (transient,
	 * 24 hr TTL). Keying by md5(PAT) means rotating the token automatically
	 * invalidates the previous cache entry.
	 *
	 * @param string $pat Plain-text PAT.
	 * @return string
	 */
	public static function user_repos( string $pat ): string {
		return self::USER_REPOS_PREFIX . md5( $pat );
	}

	/**
	 * Cached PAT validation result (transient, 1 min TTL). Keying by md5(PAT)
	 * keeps re-validation cheap while letting a corrected token immediately
	 * surface as valid.
	 *
	 * @param string $pat Plain-text PAT.
	 * @return string
	 */
	public static function pat_validation( string $pat ): string {
		return self::PAT_VALIDATION_PREFIX . md5( $pat );
	}

	/**
	 * Admin notice transient set after consecutive AI failures.
	 *
	 * @return string
	 */
	public static function ai_failure_notice(): string {
		return self::AI_FAILURE_NOTICE;
	}

	/**
	 * Most recent cron run summary (transient, displayed on the admin page).
	 *
	 * @return string
	 */
	public static function cron_results(): string {
		return self::CRON_RESULTS;
	}

	/**
	 * Concurrency lock around the release-check cron tick.
	 *
	 * @return string
	 */
	public static function cron_lock(): string {
		return self::CRON_LOCK;
	}

	/**
	 * Cached AI connector availability status (transient, 1 min TTL).
	 *
	 * @return string
	 */
	public static function connector_status(): string {
		return self::CONNECTOR_STATUS;
	}

	/**
	 * Per-user admin error message (transient, 60s TTL).
	 *
	 * @param int $user_id Recipient user ID.
	 * @return string
	 */
	public static function admin_errors( int $user_id ): string {
		return self::ADMIN_ERRORS_PREFIX . $user_id;
	}

	/**
	 * Per-user admin notice payload (transient, 60s TTL).
	 *
	 * @param int $user_id Recipient user ID.
	 * @return string
	 */
	public static function admin_notice( int $user_id ): string {
		return self::ADMIN_NOTICE_PREFIX . $user_id;
	}
}
