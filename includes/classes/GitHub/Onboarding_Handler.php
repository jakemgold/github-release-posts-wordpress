<?php
/**
 * Onboarding preview draft handler.
 *
 * @package GitHubReleasePosts
 */

namespace Jakemgold\GitHubReleasePosts\GitHub;

/**
 * Triggers an immediate preview draft when a repository is first added.
 *
 * Fires the ghrp_process_release action (DOM-05/06 hooks here for generation),
 * records the latest release tag as last-seen so the first cron run does not
 * re-process it, and returns a notice payload for the admin UI.
 */
class Onboarding_Handler {

	/**
	 * Constructor.
	 *
	 * @param API_Client    $api_client GitHub HTTP client.
	 * @param Release_State $state      Per-repo state storage.
	 */
	public function __construct(
		private readonly API_Client $api_client,
		private readonly Release_State $state,
	) {}

	/**
	 * Fetches the latest release and triggers onboarding generation.
	 *
	 * Always records the last-seen tag (if a release is found) so the first
	 * scheduled cron run does not duplicate the onboarding post (AC-012).
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return array{type: string, message: string, post_url: string|null}
	 */
	public function trigger( string $identifier ): array {
		$release = $this->api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			return $this->failure_notice(
				sprintf(
					/* translators: %s: error message */
					__( 'Could not fetch the latest release: %s Use "Generate post" once your configuration is complete.', 'github-release-posts' ),
					$release->get_error_message()
				)
			);
		}

		if ( null === $release ) {
			return $this->failure_notice(
				__( 'No releases found for this repository. A draft will be generated automatically after the first release is published.', 'github-release-posts' )
			);
		}

		// Record the latest tag as last-seen before attempting generation.
		// This prevents the cron from re-processing the same release (AC-012).
		$this->state->update_last_seen( $identifier, $release->tag, $release->published_at );

		/**
		 * Fires to trigger AI generation for the onboarding preview draft.
		 *
		 * DOM-05/06 hooks here. The generated post must always be a draft
		 * regardless of the global publish/draft setting (AC-011).
		 *
		 * @param array<string, mixed> $entry   Queue entry with release data.
		 * @param array<string, mixed> $context Context flags: force_draft, onboarding.
		 */
		do_action(
			'ghrp_process_release',
			Release_Queue::from_release( $identifier, $release ),
			[
				'force_draft' => true,
				'onboarding'  => true,
			]
		);

		// Check whether a post was created by the action subscribers (AC-013, AC-014).
		$post = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $post instanceof \WP_Post ) {
			return [
				'type'     => 'success',
				'message'  => sprintf(
					/* translators: %s: post title */
					__( 'Preview draft created: "%s". Review it, then publish or discard to confirm your setup is working correctly.', 'github-release-posts' ),
					$post->post_title
				),
				'post_url' => get_edit_post_link( $post->ID, 'raw' ),
			];
		}

		// AC-014: generation not available yet (no AI connector configured).
		return $this->failure_notice(
			__( 'Repository saved. To generate a preview draft, set up an AI connector under Settings → Connectors, then use "Generate post".', 'github-release-posts' )
		);
	}

	/**
	 * Builds a warning notice array.
	 *
	 * @param string $message Notice message.
	 * @return array{type: string, message: string, post_url: null}
	 */
	private function failure_notice( string $message ): array {
		return [
			'type'     => 'warning',
			'message'  => $message,
			'post_url' => null,
		];
	}
}
