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
use GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Runs the fresh-add lifecycle transition for a newly added repository.
 *
 * One raw release snapshot drives everything: package discovery (warming the
 * Quick Edit chooser cache), content eligibility, the stream baseline, and
 * the initial-generation decision. There is no second "latest release"
 * request — a single snapshot, projected twice, keeps every decision
 * consistent with what the monitor will later observe.
 *
 * If the snapshot fails, the repository stays `onboarding_pending` and the
 * cron reruns this same transition (via Release_Monitor) until a snapshot
 * succeeds — failed and successful onboarding converge on identical
 * behavior instead of drifting into inferred lifecycle states.
 *
 * AI post generation itself is deferred to the client — the admin gets a
 * fast redirect and the post is generated via the same REST endpoint the
 * manual "Generate post" button uses.
 */
class Onboarding_Handler {

	/**
	 * Constructor.
	 *
	 * @param API_Client          $api_client    GitHub HTTP client.
	 * @param Release_State       $state         Per-repo state storage.
	 * @param Repository_Settings $repo_settings Tracked repository configuration.
	 */
	public function __construct(
		private readonly API_Client $api_client,
		private readonly Release_State $state,
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Decides what to do after a newly added repository.
	 *
	 * Possible outcomes:
	 *  - Snapshot failed → repository stays onboarding-pending; warning
	 *    notice; the cron retries the full onboarding transition.
	 *  - No eligible releases → empty ready baseline; success notice
	 *    explaining the first release will generate (with pre-release and
	 *    pattern-filtered variants).
	 *  - Package choice available (2+ recognized packages) → all current
	 *    stream heads baselined; generation suppressed; info notice nudging
	 *    the admin to choose packages.
	 *  - Single or mixed topology → all stream heads baselined EXCEPT the
	 *    latest release's stream; client auto-triggers generation of that
	 *    release (crash-safe: if the client fails, the absent cursor lets
	 *    cron generate it).
	 *  - Latest release already has a post → cursors advanced; info notice
	 *    linking to the existing post.
	 *
	 * @param string $identifier Normalised `owner/repo` identifier.
	 * @return array{
	 *     auto_trigger: bool,
	 *     notice: array{type: string, message: string, url: string}|null
	 * }
	 */
	public function handle_add( string $identifier ): array {
		// Persisted BEFORE the API call: until a full snapshot succeeds, the
		// repository is explicitly mid-onboarding — never mistakable for
		// pre-feature legacy state or a completed baseline.
		$this->state->mark_onboarding_pending( $identifier );

		$snapshot = $this->api_client->fetch_release_snapshot( $identifier );

		if ( is_wp_error( $snapshot ) ) {
			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'warning',
					'message' => sprintf(
						/* translators: %s: error message from GitHub API */
						__( 'Repository added, but the initial release scan failed: %s It will be retried on the next scheduled run.', 'auto-release-posts-for-github' ),
						$snapshot->get_error_message()
					),
					'url'     => '',
				],
			];
		}

		// DISCOVERY projection: full snapshot, pre-releases included, patterns
		// ignored — warms the Quick Edit package chooser so it renders
		// instantly at the moment the repo is most likely to be configured.
		$packages_payload = Tag_Pattern_Matcher::build_packages_payload( $snapshot );
		set_transient( Cache_Keys::repo_packages( $identifier ), $packages_payload, 15 * MINUTE_IN_SECONDS );

		// Generation is only suppressed when the admin is actually offered a
		// package choice, and the chooser renders exactly when the payload
		// recognizes 2+ named packages.
		$ui_choice = (bool) $packages_payload['multi_package'];

		// MONITORING projection: the repository's real eligibility policy.
		// Cursors are built from this view only — seeding from the
		// pre-release-inclusive discovery list would pin cursors at
		// pre-release versions and swallow later stable releases.
		$repo_config         = $this->repo_settings->get_repository( $identifier );
		$include_prereleases = ! empty( $repo_config['include_prereleases'] );
		/** This filter is documented in includes/classes/GitHub/Release_Monitor.php */
		$tag_patterns = (string) apply_filters( 'ghrp_repo_tag_patterns', (string) ( $repo_config['tag_patterns'] ?? '' ), $identifier, $repo_config );

		$eligible = Release_Selector::monitoring_projection( $snapshot, $include_prereleases, $tag_patterns );
		$plan     = Release_Selector::onboarding_plan( $eligible, $ui_choice );

		$this->state->complete_baseline(
			$identifier,
			$plan['cursors'],
			Release_Selector::policy_hash( $include_prereleases, $tag_patterns )
		);

		// Monorepo nudge: the default (posts for all packages) is unchanged
		// pre-1.2 behavior, which floods feeds with utility-package posts.
		// The add moment is the one moment the admin is certainly looking,
		// so surface the detection here instead of hoping they open Quick Edit.
		$package_note = '';
		if ( $ui_choice ) {
			$package_note = sprintf(
				/* translators: %d: number of packages detected in the repository */
				__( 'This repository releases %d different packages — by default, every release gets a post. Edit the repository to choose which packages.', 'auto-release-posts-for-github' ),
				count( $packages_payload['packages'] )
			);
		}

		if ( null === $plan['initial'] ) {
			// Package choice available: every current head is baselined; the
			// admin picks packages before anything is drafted. The daily cron
			// generates future releases with whatever patterns are set by then.
			if ( $ui_choice && ! empty( $eligible ) ) {
				return [
					'auto_trigger' => false,
					'notice'       => [
						'type'    => 'info',
						'message' => $package_note . ' ' . __( 'Automatic generation was skipped so you can choose first.', 'auto-release-posts-for-github' ),
						'url'     => '',
					],
				];
			}

			// No eligible releases. Distinguish why, so the notice is honest.
			$has_stable = false;
			foreach ( $snapshot as $release ) {
				if ( ! $release->prerelease ) {
					$has_stable = true;
					break;
				}
			}

			if ( ! empty( $snapshot ) && ! $has_stable ) {
				return [
					'auto_trigger' => false,
					'notice'       => [
						'type'    => 'success',
						'message' => trim( __( 'Repository added. This repo only has pre-release versions (betas, release candidates, etc.). Edit the repo and turn on "Include pre-releases" to start tracking them.', 'auto-release-posts-for-github' ) . ' ' . $package_note ),
						'url'     => '',
					],
				];
			}

			if ( ! empty( $snapshot ) ) {
				// Stable releases exist but the effective tag patterns exclude
				// them all (a code-level filter can apply at add time).
				return [
					'auto_trigger' => false,
					'notice'       => [
						'type'    => 'success',
						'message' => __( 'Repository added. No recent releases match the configured tag patterns — a draft will be generated automatically when a matching release is published.', 'auto-release-posts-for-github' ),
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

		$release = $plan['initial'];

		$existing = Release_Monitor::find_post( $identifier, $release->tag );
		if ( $existing instanceof \WP_Post ) {
			// A post already exists for the initial release — advance both the
			// display cursor and the stream cursor so the cron doesn't
			// re-process. Safe here because there is no in-flight generation
			// that might fail.
			$this->state->update_last_seen( $identifier, $release->tag, $release->published_at );
			$parsed = Tag_Pattern_Matcher::derive_package( $release->tag );
			$this->state->update_stream_seen( $identifier, null === $parsed ? '' : $parsed['package'], $release->tag, $release->published_at );

			return [
				'auto_trigger' => false,
				'notice'       => [
					'type'    => 'success',
					'message' => __( 'Repository added. A post already exists for the latest release — use "Generate post" if you want to regenerate it.', 'auto-release-posts-for-github' ),
					'url'     => (string) get_edit_post_link( $existing->ID, 'raw' ),
				],
			];
		}

		// Happy path: client will trigger generation via the inline UI. The
		// initial release's stream cursor was deliberately OMITTED from the
		// baseline — if the client-side auto-generate fails (browser close,
		// JS error, AI provider down) before a post is created, the absent
		// cursor lets the cron generate it on the next run. The cron's own
		// pipeline advances cursors after successful post creation, and
		// find_post() idempotency prevents double-creation when the cron and
		// client race in the narrow window during AI generation.
		return [
			'auto_trigger' => true,
			'notice'       => null,
		];
	}
}
