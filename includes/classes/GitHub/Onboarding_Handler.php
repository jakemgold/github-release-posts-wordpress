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

use GitHubReleasePosts\Cache_Keys;

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
	 *    EXCEPT for monorepos (2+ packages detected): auto-generation is
	 *    suppressed so the admin can choose packages first — otherwise the
	 *    repo-wide latest release (possibly a utility package the site never
	 *    wants) would be drafted while the nudge notice tells them to choose.
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
		// Fetch the full release list once up front: it warms the package
		// cache so the Quick Edit Packages picker renders instantly right
		// after adding a monorepo (the moment it is most likely to be
		// configured), and the only-prereleases branch below reuses it.
		// Failures are ignored — warming must never break onboarding.
		$all_releases     = $this->api_client->fetch_releases( $identifier, true );
		$packages_payload = [
			'multi_package' => false,
			'packages'      => [],
		];
		$multi_stream     = false;
		if ( is_array( $all_releases ) ) {
			$packages_payload = Tag_Pattern_Matcher::build_packages_payload( $all_releases );
			set_transient( Cache_Keys::repo_packages( $identifier ), $packages_payload, 15 * MINUTE_IN_SECONDS );

			// One topology predicate everywhere monitoring state is written
			// (round 5): the picker payload's multi_package is a UI notion
			// (2+ recognized packages) and undercounts repos that mix one
			// package stream with plain repo-wide tags — the monitor routes
			// those through streams too, so onboarding must agree.
			$comparator   = new Version_Comparator();
			$multi_stream = $comparator->is_multi_stream( $all_releases );

			// For stream-monitored repos — where client auto-generation is
			// suppressed — seed every stream cursor the monitor will later
			// evaluate, from the SAME winner selection (round 4): the picker
			// payload omits the default stream and trusts GitHub's created_at
			// order, which pins backports. Single-stream repos get the
			// baseline marker with no cursors: their pending latest must stay
			// generatable by the client auto-trigger, or by cron if that
			// fails (round 3).
			$cursors = [];
			if ( $multi_stream ) {
				foreach ( $comparator->select_stream_winners( $all_releases ) as $stream => $winner ) {
					$cursors[ (string) $stream ] = [
						'last_seen_tag'          => $winner->tag,
						'last_seen_published_at' => $winner->published_at,
					];
				}
			}
			$this->state->set_monorepo( $identifier, $multi_stream );
			// Baseline only on a successfully observed list (round 5): an
			// empty baseline after a FAILED list fetch would make a later
			// topology discovery treat every current stream winner as new —
			// a one-post-per-package burst. With no baseline, the next cron
			// routes by actual topology: single-stream repos enqueue the
			// pending latest (the cron-retry promise holds because only
			// multi-stream repos ever enter the seeding branch), and
			// multi-stream repos run the one-time seeding — correct for both.
			$this->state->seed_streams( $identifier, $cursors );
		}

		// Monorepo nudge: the default (posts for all packages) is unchanged
		// pre-1.2 behavior, which floods feeds with utility-package posts.
		// The add moment is the one moment the admin is certainly looking,
		// so surface the detection here instead of hoping they open Quick Edit.
		$package_note = '';
		if ( $multi_stream ) {
			$package_note = $packages_payload['multi_package']
				? sprintf(
					/* translators: %d: number of packages detected in the repository */
					__( 'This repository releases %d different packages — by default, every release gets a post. Edit the repository to choose which packages.', 'auto-release-posts-for-github' ),
					count( $packages_payload['packages'] )
				)
				: __( 'This repository mixes package releases with repository-wide releases — by default, every release gets a post. Edit the repository to review its Packages settings.', 'auto-release-posts-for-github' );
		}

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
			$prereleases = is_array( $all_releases ) ? $all_releases : [];
			if ( count( $prereleases ) > 0 ) {
				return [
					'auto_trigger' => false,
					'notice'       => [
						'type'    => 'success',
						'message' => trim( __( 'Repository added. This repo only has pre-release versions (betas, release candidates, etc.). Edit the repo and turn on "Include pre-releases" to start tracking them.', 'auto-release-posts-for-github' ) . ' ' . $package_note ),
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
					'message' => trim( __( 'Repository added. A post already exists for the latest release — use "Generate post" if you want to regenerate it.', 'auto-release-posts-for-github' ) . ' ' . $package_note ),
					'url'     => (string) get_edit_post_link( $existing->ID, 'raw' ),
				],
			];
		}

		// Monorepo: do NOT auto-generate. The repo-wide latest release may
		// belong to a package the site never wants posts for — drafting it
		// while the nudge says "choose packages" would be contradictory.
		// The daily cron still generates later with whatever patterns are
		// set by then; this suppression buys the admin the window to choose.
		if ( '' !== $package_note ) {
			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'info',
					'message' => $package_note . ' ' . __( 'Automatic generation was skipped so you can choose first.', 'auto-release-posts-for-github' ),
					'url'     => '',
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
