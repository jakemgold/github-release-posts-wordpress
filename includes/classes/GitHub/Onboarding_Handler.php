<?php
/**
 * Post-add bookkeeping handler.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the server-side bookkeeping for a newly added repository.
 *
 * Fetches the latest release (so the cron has something to compare against),
 * records it as last-seen (so the cron won't double-process it once the
 * client-side auto-generate completes), and returns a decision payload
 * telling the form handler what notice to surface and whether to include
 * the `?ghrp_just_added=<identifier>` query arg on the redirect that
 * triggers the JS auto-generate flow.
 *
 * AI post generation itself is deferred to the client — the admin gets a
 * fast redirect and the post is generated via the same REST endpoint the
 * manual "Generate post" button uses.
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
	 * Decides what to do after a newly added repository.
	 *
	 * Possible outcomes:
	 *  - Latest release fetched, no existing post → auto-trigger generation
	 *    on the client; no admin notice (the inline spinner speaks for itself).
	 *  - Latest release fetched, post already exists → no auto-trigger;
	 *    show an info notice linking to the existing post.
	 *  - No releases yet → no auto-trigger; show a success notice explaining
	 *    cron will pick up the first release.
	 *  - Fetch failed (network / rate limit / private repo without PAT) →
	 *    no auto-trigger; show a warning notice; cron will retry.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return array{
	 *     auto_trigger: bool,
	 *     notice: array{type: string, message: string, url: string}|null
	 * }
	 */
	public function handle_add( string $identifier ): array {
		// New repos default to excluding pre-releases (see Repository_Settings
		// defaults). We still surface a helpful notice if the repo turns out to
		// have only pre-releases — see the null branch below.
		$release = $this->api_client->fetch_latest_eligible_release( $identifier, false );

		if ( is_wp_error( $release ) ) {
			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: %s: error message from GitHub API */
						__( 'Repository added, but the initial release check failed: %s It will be retried on the next scheduled run.', 'auto-release-posts-for-github' ),
						$release->get_error_message()
					),
					'url'     => '',
				],
			];
		}

		if ( null === $release ) {
			// No stable release. Before saying "no releases yet," check whether
			// the repo has pre-releases — this is a common case for projects in
			// beta lifecycle, and the "no releases" notice would be misleading.
			$prereleases = $this->api_client->fetch_releases( $identifier, true );
			if ( is_array( $prereleases ) && count( $prereleases ) > 0 ) {
				return [
					'auto_trigger' => false,
					'notice'       => [
						'type'    => 'success',
						'message' => __( 'Repository added. This repo only has pre-release versions (betas, release candidates, etc.). Edit the repo and turn on "Include pre-releases" to start tracking them.', 'auto-release-posts-for-github' ),
						'url'     => '',
					],
				];
			}

			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'success',
					'message' => __( 'Repository added. No releases yet — a draft will be generated automatically once the first release is published.', 'auto-release-posts-for-github' ),
					'url'     => '',
				],
			];
		}

		$existing = Release_Monitor::find_post( $identifier, $release->tag );
		if ( $existing instanceof \WP_Post ) {
			// A post already exists for the latest tag — record last_seen so
			// the cron doesn't re-process. Safe here because there is no
			// in-flight generation that might fail.
			$this->state->update_last_seen( $identifier, $release->tag, $release->published_at );
			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'success',
					'message' => __( 'Repository added. A post already exists for the latest release — use "Generate post" if you want to regenerate it.', 'auto-release-posts-for-github' ),
					'url'     => (string) get_edit_post_link( $existing->ID, 'raw' ),
				],
			];
		}

		// Happy path: client will trigger generation via the inline UI.
		// Deliberately do NOT pre-record last_seen here — if the client-side
		// auto-generate fails (browser close, JS error, AI provider down)
		// before a post is created, the cron must be free to pick up the
		// slack on its next run. The cron's own pipeline records last_seen
		// after successful post creation (Release_Monitor::process_queue()),
		// and Post_Creator's idempotency check via find_post() prevents
		// double-creation when the cron and client race in the narrow window
		// during AI generation.
		return [
			'auto_trigger' => true,
			'notice'       => null,
		];
	}
}
