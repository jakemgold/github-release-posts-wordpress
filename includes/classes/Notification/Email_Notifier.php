<?php
/**
 * Sends batched email notifications after a cron run.
 *
 * @package ChangelogToBlogPost\Notification
 */

namespace TenUp\ChangelogToBlogPost\Notification;

use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\AI\Release_Significance;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Hooks into ctbp_post_status_set, collects results during a cron run,
 * and sends a single batched summary email at shutdown.
 *
 * One email per run, never one per post (BR-001).
 */
class Email_Notifier {

	/**
	 * Posts collected during this request for the batched email.
	 *
	 * @var array<int, array{post_id: int, status: string, identifier: string, tag: string, html_url: string}>
	 */
	private array $entries = [];

	/**
	 * Whether the shutdown hook has been registered (ensures single registration).
	 *
	 * @var bool
	 */
	private bool $shutdown_registered = false;

	/**
	 * @param Global_Settings      $global_settings Global settings (notification prefs).
	 * @param Release_Significance $significance    Significance classifier for email body.
	 * @param Repository_Settings  $repo_settings   Per-repo config (display name lookup).
	 */
	public function __construct(
		private readonly Global_Settings $global_settings,
		private readonly Release_Significance $significance,
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ctbp_post_status_set', [ $this, 'collect' ], 10, 4 );
	}

	/**
	 * Collects a post result for the batched email.
	 *
	 * Skips manual triggers (AC-008) and disabled notifications (AC-011).
	 *
	 * @param int         $post_id WordPress post ID.
	 * @param string      $status  Final post status.
	 * @param ReleaseData $data    Source release data.
	 * @param array       $context Generation context flags.
	 * @return void
	 */
	public function collect( int $post_id, string $status, ReleaseData $data, array $context ): void {
		// Skip manual triggers — no notification for "Generate draft now" (AC-008, BR-004).
		if ( ! empty( $context['force_draft'] ) || ! empty( $context['bypass_idempotency'] ) || ! empty( $context['manual'] ) ) {
			return;
		}

		// Skip if notifications are disabled (AC-011).
		$notif = $this->global_settings->get_notification_settings();
		if ( empty( $notif['enabled'] ) ) {
			return;
		}

		$significance = $this->significance->classify( $data );
		$display_name = $this->resolve_display_name( $data->identifier );

		$this->entries[] = [
			'post_id'      => $post_id,
			'status'       => $status,
			'identifier'   => $data->identifier,
			'display_name' => $display_name,
			'tag'          => $data->tag,
			'html_url'     => $data->html_url,
			'significance' => $significance,
		];

		// Register shutdown hook once to send the batched email.
		if ( ! $this->shutdown_registered ) {
			add_action( 'shutdown', [ $this, 'send' ] );
			$this->shutdown_registered = true;
		}
	}

	/**
	 * Sends the batched summary email at shutdown.
	 *
	 * Checks the notification trigger preference to decide whether to send.
	 *
	 * @return void
	 */
	public function send(): void {
		if ( empty( $this->entries ) ) {
			return; // Nothing to send (AC-002).
		}

		$notif   = $this->global_settings->get_notification_settings();
		$trigger = $notif['trigger'] ?? 'draft';

		// Filter entries based on trigger preference (AC-006).
		$eligible = $this->filter_by_trigger( $this->entries, $trigger );
		if ( empty( $eligible ) ) {
			return;
		}

		$recipients = $this->resolve_recipients( $notif );
		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf(
			/* translators: %s: site name */
			__( 'New plugin update post(s) ready — %s', 'changelog-to-blog-post' ),
			$site_name
		);

		$text_body = $this->build_text_body( $eligible, $site_name );
		$html_body = $this->build_html_body( $eligible, $site_name );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
		];

		$email_data = [
			'subject' => $subject,
			'body'    => $html_body,
			'headers' => $headers,
			'text'    => $text_body,
		];

		/**
		 * Filters the notification email data before sending.
		 *
		 * Return a falsy value to suppress the email entirely (AC-013).
		 *
		 * @param array $email_data Email data: subject, body, headers, text.
		 * @param array $eligible   Post entries included in the email.
		 */
		$email_data = apply_filters( 'ctbp_notification_email', $email_data, $eligible );

		if ( empty( $email_data ) ) {
			return; // Suppressed by filter.
		}

		foreach ( $recipients as $recipient ) {
			$sent = wp_mail(
				$recipient,
				$email_data['subject'],
				$email_data['body'],
				$email_data['headers']
			);

			if ( ! $sent ) {
				$this->log_failure( $recipient );
			}
		}

		// Clear collected entries.
		$this->entries = [];
	}

	/**
	 * Filters entries based on the notification trigger preference.
	 *
	 * @param array  $entries All collected entries.
	 * @param string $trigger 'draft', 'publish', or 'both'.
	 * @return array Entries matching the trigger.
	 */
	private function filter_by_trigger( array $entries, string $trigger ): array {
		if ( 'both' === $trigger ) {
			return $entries; // Send for everything (AC-007).
		}

		return array_filter( $entries, function ( $entry ) use ( $trigger ) {
			if ( 'publish' === $trigger ) {
				return 'publish' === $entry['status'];
			}
			// 'draft' trigger — fires for drafts.
			return 'publish' !== $entry['status'];
		} );
	}

	/**
	 * Resolves the list of recipient email addresses.
	 *
	 * @param array $notif Notification settings.
	 * @return string[] Recipient email addresses.
	 */
	private function resolve_recipients( array $notif ): array {
		$recipients = [];

		$primary = $notif['email'] ?? '';
		if ( empty( $primary ) ) {
			$primary = get_option( 'admin_email', '' );
		}
		if ( ! empty( $primary ) ) {
			$recipients[] = $primary;
		}

		$secondary = $notif['email_secondary'] ?? '';
		if ( ! empty( $secondary ) ) {
			$recipients[] = $secondary;
		}

		return $recipients;
	}

	/**
	 * Builds the plain text email body.
	 *
	 * @param array  $entries   Post entries.
	 * @param string $site_name Site name.
	 * @return string
	 */
	private function build_text_body( array $entries, string $site_name ): string {
		$lines = [];
		$lines[] = sprintf(
			/* translators: %s: site name */
			__( 'New plugin update posts on %s:', 'changelog-to-blog-post' ),
			$site_name
		);
		$lines[] = '';

		foreach ( $entries as $entry ) {
			$status_label = 'publish' === $entry['status']
				? __( 'Published', 'changelog-to-blog-post' )
				: __( 'Draft', 'changelog-to-blog-post' );

			$sig_label = ucfirst( $entry['significance'] );

			$lines[] = sprintf( '• %s %s (%s) — %s', $entry['display_name'], $entry['tag'], $sig_label, $status_label );
			$lines[] = sprintf( '  %s: %s', __( 'Edit', 'changelog-to-blog-post' ), $this->get_edit_url( $entry['post_id'] ) );

			if ( 'publish' === $entry['status'] ) {
				$lines[] = sprintf( '  %s: %s', __( 'View', 'changelog-to-blog-post' ), get_permalink( $entry['post_id'] ) );
			}

			$lines[] = sprintf( '  %s: %s', __( 'GitHub Release', 'changelog-to-blog-post' ), $entry['html_url'] );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Builds the HTML email body.
	 *
	 * @param array  $entries   Post entries.
	 * @param string $site_name Site name.
	 * @return string
	 */
	private function build_html_body( array $entries, string $site_name ): string {
		$html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px;">';
		$html .= '<h2>' . sprintf(
			/* translators: %s: site name */
			esc_html__( 'New plugin update posts on %s', 'changelog-to-blog-post' ),
			esc_html( $site_name )
		) . '</h2>';

		$html .= '<table style="width: 100%; border-collapse: collapse;">';

		foreach ( $entries as $entry ) {
			$status_label = 'publish' === $entry['status']
				? esc_html__( 'Published', 'changelog-to-blog-post' )
				: esc_html__( 'Draft', 'changelog-to-blog-post' );

			$sig_label = esc_html( ucfirst( $entry['significance'] ) );
			$edit_url  = esc_url( $this->get_edit_url( $entry['post_id'] ) );

			$html .= '<tr style="border-bottom: 1px solid #eee;">';
			$html .= '<td style="padding: 12px 0;">';
			$html .= '<strong>' . esc_html( $entry['display_name'] ) . ' ' . esc_html( $entry['tag'] ) . '</strong>';
			$html .= ' <span style="color: #666;">(' . $sig_label . ')</span>';
			$html .= ' — ' . $status_label;
			$html .= '<br>';
			$html .= '<a href="' . $edit_url . '">' . esc_html__( 'Edit post', 'changelog-to-blog-post' ) . '</a>';

			if ( 'publish' === $entry['status'] ) {
				$view_url = esc_url( get_permalink( $entry['post_id'] ) );
				$html .= ' · <a href="' . $view_url . '">' . esc_html__( 'View post', 'changelog-to-blog-post' ) . '</a>';
			}

			$html .= ' · <a href="' . esc_url( $entry['html_url'] ) . '">' . esc_html__( 'GitHub Release', 'changelog-to-blog-post' ) . '</a>';
			$html .= '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Returns the edit URL for a post.
	 *
	 * @param int $post_id WordPress post ID.
	 * @return string Edit URL.
	 */
	private function get_edit_url( int $post_id ): string {
		return (string) get_edit_post_link( $post_id, 'raw' );
	}

	/**
	 * Resolves the display name for a repository.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @return string Display name.
	 */
	private function resolve_display_name( string $identifier ): string {
		$config = $this->repo_settings->get_repository( $identifier );

		if ( ! empty( $config['display_name'] ) ) {
			return (string) $config['display_name'];
		}

		$parts = explode( '/', $identifier );
		return $this->repo_settings->derive_display_name( end( $parts ) );
	}

	/**
	 * Logs a failed wp_mail call.
	 *
	 * @param string $recipient The recipient that failed.
	 * @return void
	 */
	private function log_failure( string $recipient ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[CTBP] Email_Notifier: wp_mail failed for recipient "%s".',
				$recipient
			) );
		}
	}
}
