<?php
/**
 * Hooks into ctbp_process_release and drives AI generation.
 *
 * @package ChangelogToBlogPost\AI
 */

namespace TenUp\ChangelogToBlogPost\AI;

use TenUp\ChangelogToBlogPost\Plugin_Constants;

/**
 * Listens for ctbp_process_release, fetches the active AI provider, manages
 * the response cache, tracks consecutive failures, and fires ctbp_post_generated
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
	 * @param AI_Provider_Factory $factory Provider factory.
	 */
	public function __construct( private readonly AI_Provider_Factory $factory ) {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ctbp_process_release', [ $this, 'handle' ], 10, 2 );
	}

	/**
	 * Handles a single queued release entry.
	 *
	 * Called by the ctbp_process_release action fired from Release_Monitor::process_queue().
	 *
	 * @param array $entry   Queue entry (identifier, tag, name, body, html_url, published_at, assets).
	 * @param array $context Optional context flags (e.g. ['force_draft' => true, 'onboarding' => true]).
	 * @return void
	 */
	public function handle( array $entry, array $context ): void {
		$data       = ReleaseData::from_entry( $entry );
		$cache_key  = Plugin_Constants::TRANSIENT_AI_RESPONSE_PREFIX . md5( $data->identifier . $data->tag );

		// Check response cache — skip API call if we already have a result.
		$cached = get_transient( $cache_key );
		if ( $cached instanceof GeneratedPost ) {
			/**
			 * Fires when a blog post has been generated (or retrieved from cache) for a release.
			 *
			 * @param GeneratedPost $post    The generated post data.
			 * @param ReleaseData   $data    The source release data.
			 * @param array         $context Generation context flags.
			 */
			do_action( 'ctbp_post_generated', $cached, $data, $context );
			return;
		}

		$provider = $this->factory->get_provider();
		if ( is_wp_error( $provider ) ) {
			$this->record_failure( $data, $provider );
			return;
		}

		// EPC-05.2 will supply a real prompt; use a passthrough for now.
		$prompt = (string) apply_filters( 'ctbp_generate_prompt', '', $data );

		$result = $provider->generate_post( $data, $prompt );

		if ( is_wp_error( $result ) ) {
			$this->record_failure( $data, $result );
			return;
		}

		// Success — cache the result and reset failure count.
		set_transient( $cache_key, $result, self::CACHE_TTL );
		$this->clear_failure_count( $data );

		/** This action is documented above. */
		do_action( 'ctbp_post_generated', $result, $data, $context );
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
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[CTBP] AI generation failed for %s@%s (%s): %s',
				$data->identifier,
				$data->tag,
				$error->get_error_code(),
				$error->get_error_message()
			) );
		}

		$counts  = (array) get_option( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] );
		$key     = md5( $data->identifier . $data->tag );
		$count   = (int) ( $counts[ $key ] ?? 0 ) + 1;
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
