<?php
/**
 * Finalizes post status and collects cron run results for admin notices.
 *
 * @package ChangelogToBlogPost\Post
 */

namespace TenUp\ChangelogToBlogPost\Post;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Plugin_Constants;

use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Hooks into ctbp_post_created (after Taxonomy_Assigner) and sets the final
 * post status based on per-repo or global configuration. Collects results
 * for a dismissible admin notice summarizing the cron run outcome.
 */
class Publish_Workflow {

	/**
	 * Transient key for storing cron run results for the admin notice.
	 */
	const TRANSIENT_CRON_RESULTS = Plugin_Constants::TRANSIENT_CRON_RESULTS;

	/**
	 * @param Repository_Settings $repo_settings   Per-repo configuration.
	 * @param Global_Settings     $global_settings Global defaults.
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
		add_action( 'ctbp_post_created', [ $this, 'handle' ], 20, 4 );
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
		$status = (string) apply_filters( 'ctbp_post_status', $status, $post_id, $data->identifier, $data->tag );

		$update_args = [
			'ID'          => $post_id,
			'post_status' => $status,
		];

		if ( 'publish' === $status ) {
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
		do_action( 'ctbp_post_status_set', $post_id, $status, $data, $context );
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
		$results = get_transient( self::TRANSIENT_CRON_RESULTS );
		if ( ! is_array( $results ) ) {
			$results = [ 'drafted' => [], 'published' => [], 'errors' => [] ];
		}

		$entry = [
			'post_id'    => $post_id,
			'identifier' => $data->identifier,
			'tag'        => $data->tag,
			'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
		];

		if ( 'publish' === $status ) {
			$results['published'][] = $entry;
		} else {
			$results['drafted'][] = $entry;
		}

		// Store for 24 hours — overwritten on next cron run.
		set_transient( self::TRANSIENT_CRON_RESULTS, $results, DAY_IN_SECONDS );
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

		$results = get_transient( self::TRANSIENT_CRON_RESULTS );
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
			$links   = array_map( function ( $entry ) {
				return sprintf(
					'<a href="%s">%s %s</a>',
					esc_url( $entry['edit_url'] ?? '#' ),
					esc_html( $entry['identifier'] ),
					esc_html( $entry['tag'] )
				);
			}, $drafted );
			$lines[] = sprintf(
				/* translators: 1: count, 2: comma-separated edit links */
				_n( '%1$d draft created: %2$s', '%1$d drafts created: %2$s', $count, 'changelog-to-blog-post' ),
				$count,
				implode( ', ', $links )
			);
		}

		if ( ! empty( $published ) ) {
			$count   = count( $published );
			$links   = array_map( function ( $entry ) {
				return sprintf(
					'<a href="%s">%s %s</a>',
					esc_url( $entry['edit_url'] ?? '#' ),
					esc_html( $entry['identifier'] ),
					esc_html( $entry['tag'] )
				);
			}, $published );
			$lines[] = sprintf(
				/* translators: 1: count, 2: comma-separated edit links */
				_n( '%1$d post published: %2$s', '%1$d posts published: %2$s', $count, 'changelog-to-blog-post' ),
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
					'changelog-to-blog-post'
				),
				$count
			);
		}

		printf(
			'<div class="notice notice-info is-dismissible" id="ctbp-cron-results"><p><strong>%s</strong></p><p>%s</p></div>',
			esc_html__( 'Changelog to Blog Post', 'changelog-to-blog-post' ),
			wp_kses_post( implode( '<br>', $lines ) )
		);

		// Clear the transient so it doesn't reappear (AC-009).
		delete_transient( self::TRANSIENT_CRON_RESULTS );
	}
}
