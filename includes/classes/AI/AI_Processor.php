<?php
/**
 * Hooks into ghrp_process_release and drives AI generation.
 *
 * @package GitHubReleasePosts\AI
 */

namespace Jakemgold\GitHubReleasePosts\AI;

use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;

/**
 * Listens for ghrp_process_release, fetches the active AI provider, manages
 * the response cache, tracks consecutive failures, and fires ghrp_post_generated
 * on a successful generation.
 */
class AI_Processor {

	/**
	 * Transient TTL for cached AI responses (4 hours).
	 */
	const CACHE_TTL = 4 * HOUR_IN_SECONDS;

	/**
	 * Number of consecutive failures before an admin notice is shown.
	 */
	const FAILURE_THRESHOLD = 3;

	/**
	 * Last error encountered during processing, if any.
	 *
	 * @var \WP_Error|null
	 */
	private static ?\WP_Error $last_error = null;

	/**
	 * Constructor.
	 *
	 * @param AI_Provider_Factory $factory Provider factory.
	 */
	public function __construct( private readonly AI_Provider_Factory $factory ) {}

	/**
	 * Returns the last error encountered during processing, or null if none.
	 *
	 * @return \WP_Error|null
	 */
	public static function get_last_error(): ?\WP_Error {
		return self::$last_error;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ghrp_process_release', [ $this, 'handle' ], 10, 2 );
	}

	/**
	 * Handles a single queued release entry.
	 *
	 * Called by the ghrp_process_release action fired from Release_Monitor::process_queue().
	 *
	 * @param array $entry   Queue entry (identifier, tag, name, body, html_url, published_at, assets).
	 * @param array $context Optional context flags (e.g. ['force_draft' => true, 'onboarding' => true]).
	 * @return void
	 */
	public function handle( array $entry, array $context ): void {
		self::$last_error = null;

		$data      = ReleaseData::from_entry( $entry );
		$cache_key = Plugin_Constants::TRANSIENT_AI_RESPONSE_PREFIX . md5( $data->identifier . $data->tag );

		// Check response cache — skip API call if we already have a result.
		// Bypass cache for manual requests (e.g. "Generate draft now", "Regenerate")
		// so that prompt/settings changes take effect immediately.
		$is_manual = ! empty( $context['manual'] );
		$cached    = $is_manual ? false : get_transient( $cache_key );
		if ( $cached instanceof GeneratedPost ) {
			/**
			 * Fires when a blog post has been generated (or retrieved from cache) for a release.
			 *
			 * @param GeneratedPost $post    The generated post data.
			 * @param ReleaseData   $data    The source release data.
			 * @param array         $context Generation context flags.
			 */
			do_action( 'ghrp_post_generated', $cached, $data, $context );
			return;
		}

		$provider = $this->factory->get_provider();
		if ( is_wp_error( $provider ) ) {
			$this->record_failure( $data, $provider );
			return;
		}

		$prompt = (string) apply_filters( 'ghrp_generate_prompt', '', $data );

		if ( '' === trim( $prompt ) ) {
			$this->record_failure(
				$data,
				new \WP_Error(
					'ghrp_empty_prompt',
					__( 'AI prompt is empty. Check that the prompt builder is configured correctly.', 'github-release-posts' )
				)
			);
			return;
		}

		$result = $provider->generate_post( $data, $prompt );

		if ( is_wp_error( $result ) ) {
			$this->record_failure( $data, $result );
			return;
		}

		// Success — cache the result and reset failure count.
		set_transient( $cache_key, $result, self::CACHE_TTL );
		$this->clear_failure_count( $data );

		/** This action is documented above. */
		do_action( 'ghrp_post_generated', $result, $data, $context );
	}

	/**
	 * Increments the consecutive failure count for a release.
	 *
	 * When the count reaches FAILURE_THRESHOLD, an admin notice transient is set.
	 *
	 * @param ReleaseData $data  The release that failed.
	 * @param \WP_Error   $error The error that occurred.
	 * @return void
	 */
	private function record_failure( ReleaseData $data, \WP_Error $error ): void {
		self::$last_error = $error;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'[CTBP] AI generation failed for %s@%s (%s): %s',
					$data->identifier,
					$data->tag,
					$error->get_error_code(),
					$error->get_error_message()
				)
			);
		}

		$counts         = (array) get_option( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] );
		$key            = md5( $data->identifier . $data->tag );
		$count          = (int) ( $counts[ $key ] ?? 0 ) + 1;
		$counts[ $key ] = $count;
		update_option( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, $counts, false );

		if ( $count >= self::FAILURE_THRESHOLD ) {
			set_transient(
				Plugin_Constants::TRANSIENT_AI_FAILURE_NOTICE,
				[
					'identifier' => $data->identifier,
					'tag'        => $data->tag,
					'message'    => $error->get_error_message(),
				],
				DAY_IN_SECONDS
			);

			// Send a failure email once per streak (not on every failure).
			if ( self::FAILURE_THRESHOLD === $count ) {
				$this->send_failure_email( $data, $error );
			}
		}
	}

	/**
	 * Sends a failure notification email to configured recipients.
	 *
	 * Uses the same recipient list as the post notification emails.
	 * Only called once per failure streak (at exactly FAILURE_THRESHOLD).
	 *
	 * @param ReleaseData $data  The release that failed.
	 * @param \WP_Error   $error The error that occurred.
	 * @return void
	 */
	private function send_failure_email( ReleaseData $data, \WP_Error $error ): void {
		$settings   = new Global_Settings();
		$recipients = [];

		// Always include the site admin for failure alerts — these are
		// operational notifications, not optional post notifications.
		$admin_email = get_option( 'admin_email', '' );
		if ( ! empty( $admin_email ) ) {
			$recipients[] = $admin_email;
		}

		// Also include any additional notification recipients.
		foreach ( $settings->get_additional_email_list() as $email ) {
			if ( ! in_array( $email, $recipients, true ) ) {
				$recipients[] = $email;
			}
		}

		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: 1: site name, 2: repo identifier */
			__( '[%1$s] Post generation failing for %2$s', 'github-release-posts' ),
			$site_name,
			$data->identifier
		);

		$body = sprintf(
			/* translators: 1: repo identifier, 2: release tag, 3: failure count, 4: error message */
			__(
				'GitHub Release Posts has failed to generate a post for %1$s (%2$s) after %3$d consecutive attempts.

Last error: %4$s

This may be caused by an issue with your AI connector configuration, insufficient API credits, or server timeout limits. You can try generating the post manually from Tools → Release Posts, or check your connector setup under Settings → Connectors.

This notification will not be sent again for this release unless the issue is resolved and a new failure streak occurs.',
				'github-release-posts'
			),
			$data->identifier,
			$data->tag,
			self::FAILURE_THRESHOLD,
			$error->get_error_message()
		);

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		foreach ( $recipients as $recipient ) {
			try {
				wp_mail( $recipient, $subject, $body, $headers );
			} catch ( \Throwable $e ) {
				// Never let a mail failure interrupt the generation pipeline.
				continue;
			}
		}
	}

	/**
	 * Resets the consecutive failure count for a release after a successful generation.
	 *
	 * @param ReleaseData $data The release that succeeded.
	 * @return void
	 */
	private function clear_failure_count( ReleaseData $data ): void {
		$counts = (array) get_option( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] );
		$key    = md5( $data->identifier . $data->tag );

		if ( isset( $counts[ $key ] ) ) {
			unset( $counts[ $key ] );
			update_option( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, $counts, false );
		}
	}
}
