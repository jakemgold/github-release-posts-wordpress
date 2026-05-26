<?php
/**
 * Finalizes post status and collects cron run results for admin notices.
 *
 * @package GitHubReleasePosts\Post
 */

namespace GitHubReleasePosts\Post;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Hooks into ghrp_post_created (after Taxonomy_Assigner) and sets the final
 * post status based on per-repo or global configuration. Collects results
 * for a dismissible admin notice summarizing the cron run outcome.
 */
class Publish_Workflow {

	/**
	 * Constructor.
	 *
	 * @param Repository_Settings $repo_settings Per-repo configuration.
	 */
	public function __construct(
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		// Priority 20 — runs after Taxonomy_Assigner at priority 10.
		add_action( 'ghrp_post_created', [ $this, 'handle' ], 20, 4 );
		add_action( 'admin_notices', [ $this, 'display_admin_notice' ] );
	}

	/**
	 * Sets the final post status and records the result for admin notices.
	 *
	 * @param int           $post_id WordPress post ID.
	 * @param GeneratedPost $post    Generated post data.
	 * @param ReleaseData   $data    Source release data.
	 * @param array         $context Generation context flags.
	 * @return void
	 */
	public function handle( int $post_id, GeneratedPost $post, ReleaseData $data, array $context ): void {
		// Respect deliberately-trashed posts. Post_Creator::handle() fires
		// ghrp_post_created even when the existing post is in trash so that
		// the dedup signal can be observed; without this guard the workflow
		// would then update the trashed post's status back to draft/publish.
		if ( 'trash' === get_post_status( $post_id ) ) {
			return;
		}

		$status = $this->resolve_status( $data->identifier, $context );

		/**
		 * Filters the post status before it is applied.
		 *
		 * Allows developers to override the status for specific releases
		 * (e.g., auto-publish only major releases).
		 *
		 * @param string $status     Resolved post status ('draft' or 'publish').
		 * @param int    $post_id    WordPress post ID.
		 * @param string $identifier Repository identifier (owner/repo).
		 * @param string $tag        Release tag.
		 */
		$status = (string) apply_filters( 'ghrp_post_status', $status, $post_id, $data->identifier, $data->tag );

		$update_args = [
			'ID'          => $post_id,
			'post_status' => $status,
		];

		if ( Post_Status::is_public( $status ) ) {
			$update_args['post_date']     = current_time( 'mysql' );
			$update_args['post_date_gmt'] = current_time( 'mysql', true );
		}

		wp_update_post( $update_args );

		// Only record results for cron-generated posts — manual generation
		// gives immediate feedback via the REST response / JS UI.
		if ( empty( $context['manual'] ) ) {
			$this->record_result( $post_id, $status, $data );
		}

		/**
		 * Fires after the final post status has been set.
		 *
		 * This is the trigger point for the notification pipeline (DOM-07).
		 *
		 * @param int         $post_id WordPress post ID.
		 * @param string      $status  Final post status.
		 * @param ReleaseData $data    Source release data.
		 * @param array       $context Generation context flags.
		 */
		do_action( 'ghrp_post_status_set', $post_id, $status, $data, $context );
	}

	/**
	 * Resolves the effective post status for a repository.
	 *
	 * "Generate draft now" always produces a draft (AC-004).
	 * Otherwise: per-repo setting > global default > 'draft'.
	 *
	 * @param string $identifier Repository identifier.
	 * @param array  $context    Generation context flags.
	 * @return string 'draft' or 'publish'.
	 */
	private function resolve_status( string $identifier, array $context ): string {
		// "Generate draft now" override (AC-004).
		if ( ! empty( $context['force_draft'] ) ) {
			return 'draft';
		}

		// Per-repo status (defaults to 'draft' on creation).
		$config = $this->repo_settings->get_repository( $identifier );
		return ! empty( $config['post_status'] ) ? (string) $config['post_status'] : 'draft';
	}

	/**
	 * Records a post result for the admin notice transient.
	 *
	 * Replaces any previous results (AC-010 — no unbounded stacking).
	 *
	 * @param int         $post_id WordPress post ID.
	 * @param string      $status  Final post status.
	 * @param ReleaseData $data    Source release data.
	 * @return void
	 */
	private function record_result( int $post_id, string $status, ReleaseData $data ): void {
		$results = get_transient( Cache_Keys::cron_results() );
		if ( ! is_array( $results ) ) {
			$results = [
				'drafted'   => [],
				'published' => [],
				'errors'    => [],
			];
		}

		$entry = [
			'post_id'    => $post_id,
			'identifier' => $data->identifier,
			'tag'        => $data->tag,
			'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
		];

		if ( Post_Status::is_public( $status ) ) {
			$results['published'][] = $entry;
		} else {
			$results['drafted'][] = $entry;
		}

		// Store for 24 hours — overwritten on next cron run.
		set_transient( Cache_Keys::cron_results(), $results, DAY_IN_SECONDS );
	}

	/**
	 * Displays the admin notice summarizing the last cron run's results.
	 *
	 * Only shown to users with `manage_options`. Dismissible (AC-009).
	 *
	 * @return void
	 */
	public function display_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Scope the notice to this plugin's admin page only. Per WordPress.org
		// Plugin Directory Guideline 11, plugins must not show notices on
		// unrelated admin screens. Users still get the same information when
		// they visit Tools → Release Posts, plus the email notification
		// pipeline already covers the "didn't visit the admin yet" case.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'tools_page_github-release-posts' !== $screen->id ) {
			return;
		}

		$results = get_transient( Cache_Keys::cron_results() );
		if ( ! is_array( $results ) ) {
			return;
		}

		$drafted   = $results['drafted'] ?? [];
		$published = $results['published'] ?? [];
		$errors    = $results['errors'] ?? [];

		// No results — nothing to show (AC-008).
		if ( empty( $drafted ) && empty( $published ) && empty( $errors ) ) {
			return;
		}

		$lines = [];

		if ( ! empty( $drafted ) ) {
			$count   = count( $drafted );
			$links   = array_map(
				function ( $entry ) {
					return sprintf(
						'<a href="%s">%s %s</a>',
						esc_url( $entry['edit_url'] ?? '#' ),
						esc_html( $entry['identifier'] ),
						esc_html( $entry['tag'] )
					);
				},
				$drafted
			);
			$lines[] = sprintf(
				/* translators: 1: count, 2: comma-separated edit links */
				_n( '%1$d draft created: %2$s', '%1$d drafts created: %2$s', $count, 'auto-release-posts-for-github' ),
				$count,
				implode( ', ', $links )
			);
		}

		if ( ! empty( $published ) ) {
			$count   = count( $published );
			$links   = array_map(
				function ( $entry ) {
					return sprintf(
						'<a href="%s">%s %s</a>',
						esc_url( $entry['edit_url'] ?? '#' ),
						esc_html( $entry['identifier'] ),
						esc_html( $entry['tag'] )
					);
				},
				$published
			);
			$lines[] = sprintf(
				/* translators: 1: count, 2: comma-separated edit links */
				_n( '%1$d post published: %2$s', '%1$d posts published: %2$s', $count, 'auto-release-posts-for-github' ),
				$count,
				implode( ', ', $links )
			);
		}

		if ( ! empty( $errors ) ) {
			$count   = count( $errors );
			$lines[] = sprintf(
				/* translators: %d: error count */
				_n(
					'%d release skipped due to errors — check the debug log for details.',
					'%d releases skipped due to errors — check the debug log for details.',
					$count,
					'auto-release-posts-for-github'
				),
				$count
			);
		}

		printf(
			'<div class="notice notice-info is-dismissible" id="ghrp-cron-results"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'Changelog to Blog Post', 'auto-release-posts-for-github' ),
			wp_kses_post( implode( '<br>', $lines ) )
		);

		// Clear the transient so it doesn't reappear (AC-009).
		delete_transient( Cache_Keys::cron_results() );
	}
}
