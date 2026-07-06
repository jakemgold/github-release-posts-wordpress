<?php
/**
 * Sends batched email notifications after a cron run.
 *
 * @package GitHubReleasePosts\Notification
 */

namespace GitHubReleasePosts\Notification;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Post\Post_Status;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Hooks into ghrp_post_status_set, collects results during a cron run,
 * and sends a single batched summary email at shutdown.
 *
 * One email per run, never one per post (BR-001).
 */
class Email_Notifier {

	/**
	 * Posts collected during this request for the batched email.
	 *
	 * @var Notification_Entry[]
	 */
	private array $entries = [];

	/**
	 * Whether the shutdown hook has been registered (ensures single registration).
	 *
	 * @var bool
	 */
	private bool $shutdown_registered = false;

	/**
	 * Constructor.
	 *
	 * @param Global_Settings     $global_settings Global settings (notification prefs).
	 * @param Repository_Settings $repo_settings   Per-repo config (display name lookup).
	 */
	public function __construct(
		private readonly Global_Settings $global_settings,
		private readonly Repository_Settings $repo_settings,
	) {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'ghrp_post_status_set', [ $this, 'collect' ], 10, 4 );
	}

	/**
	 * Collects a post result for the batched email.
	 *
	 * Skips manual triggers (AC-008) and no-recipient configurations.
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

		// Skip if no recipients are configured.
		$notif = $this->global_settings->get_notification_settings();
		if ( empty( $notif['notify_site_owner'] ) && empty( $this->global_settings->get_additional_email_list() ) ) {
			return;
		}

		// Key by post_id so a post whose status is set more than once in a run
		// (e.g. an initial draft then the final status) is listed once — last
		// write wins — rather than appearing twice in the summary email.
		$this->entries[ $post_id ] = new Notification_Entry(
			post_id:      $post_id,
			status:       $status,
			identifier:   $data->identifier,
			display_name: $this->repo_settings->get_display_name( $data->identifier ),
			tag:          $data->tag,
			html_url:     $data->html_url,
			post_title:   (string) get_the_title( $post_id ),
		);

		// Register shutdown hook once to send the batched email.
		if ( ! $this->shutdown_registered ) {
			add_action( 'shutdown', [ $this, 'send' ] );
			$this->shutdown_registered = true;
		}
	}

	/**
	 * Sends the batched summary email at shutdown.
	 *
	 * Always sends for any generated post (draft or published).
	 *
	 * @return void
	 */
	public function send(): void {
		if ( empty( $this->entries ) ) {
			return;
		}

		// Entries are keyed by post_id (for dedup in collect()); flatten to a
		// 0-indexed list for the subject/body builders.
		$eligible   = array_values( $this->entries );
		$recipients = $this->resolve_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = $this->build_subject( $eligible, $site_name );

		$text_body = $this->build_text_body( $eligible );
		$html_body = self::build_html_body( $eligible );

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
		$email_data = apply_filters( 'ghrp_notification_email', $email_data, $eligible );

		if ( empty( $email_data ) ) {
			return; // Suppressed by filter.
		}

		// Attach the plain-text body as the multipart/alternative part so the mail
		// isn't HTML-only — better deliverability and text-client support. wp_mail
		// exposes no direct arg for this, so set AltBody on the PHPMailer instance.
		$text_alt     = (string) ( $email_data['text'] ?? '' );
		$set_alt_body = static function ( $phpmailer ) use ( $text_alt ) {
			$phpmailer->AltBody = $text_alt; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer property.
		};
		if ( '' !== $text_alt ) {
			add_action( 'phpmailer_init', $set_alt_body );
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

		if ( '' !== $text_alt ) {
			remove_action( 'phpmailer_init', $set_alt_body );
		}

		// Clear collected entries.
		$this->entries = [];
	}

	/**
	 * Resolves the list of recipient email addresses.
	 *
	 * Combines the site admin email (if opted in) with any additional emails.
	 *
	 * @return string[] Recipient email addresses.
	 */
	private function resolve_recipients(): array {
		$notif      = $this->global_settings->get_notification_settings();
		$recipients = [];

		if ( ! empty( $notif['notify_site_owner'] ) ) {
			$admin_email = get_option( 'admin_email', '' );
			if ( ! empty( $admin_email ) ) {
				$recipients[] = $admin_email;
			}
		}

		$additional = $this->global_settings->get_additional_email_list();
		foreach ( $additional as $email ) {
			if ( ! in_array( $email, $recipients, true ) ) {
				$recipients[] = $email;
			}
		}

		return $recipients;
	}

	/**
	 * Builds a contextual subject line.
	 *
	 * Single entry: "Gutenberg 19.0 — draft ready for review"
	 * Multiple:     "[Site Name] 2 release posts ready"
	 *
	 * @param Notification_Entry[] $entries   Post entries.
	 * @param string               $site_name Site name.
	 * @return string
	 */
	private function build_subject( array $entries, string $site_name ): string {
		if ( 1 === count( $entries ) ) {
			$entry  = $entries[0];
			$prefix = $entry->display_name . ' ' . $entry->tag;

			if ( Post_Status::is_public( $entry->status ) ) {
				/* translators: %s: project name and version, e.g. "Gutenberg 19.0" */
				return sprintf( __( '%s — release post published', 'auto-release-posts-for-github' ), $prefix );
			}

			/* translators: %s: project name and version, e.g. "Gutenberg 19.0" */
			return sprintf( __( '%s — draft ready for review', 'auto-release-posts-for-github' ), $prefix );
		}

		return sprintf(
			/* translators: 1: site name, 2: number of posts */
			__( '[%1$s] %2$d release posts ready', 'auto-release-posts-for-github' ),
			$site_name,
			count( $entries )
		);
	}

	/**
	 * Builds the plain text email body.
	 *
	 * @param Notification_Entry[] $entries Post entries.
	 * @return string
	 */
	private function build_text_body( array $entries ): string {
		$site_url = home_url();
		$lines    = [];

		$lines[] = sprintf(
			/* translators: 1: site URL, 2: plugin name */
			__( 'New posts have been generated from GitHub releases on %1$s, via the %2$s plugin.', 'auto-release-posts-for-github' ),
			$site_url,
			'Auto Release Posts for GitHub'
		);
		$lines[] = '';

		foreach ( $entries as $entry ) {
			$title   = '' !== $entry->post_title ? $entry->post_title : $entry->display_name . ' ' . $entry->tag;
			$lines[] = sprintf( '• %s', $title );

			if ( Post_Status::is_public( $entry->status ) ) {
				$lines[] = sprintf( '  %s: %s', __( 'View post', 'auto-release-posts-for-github' ), get_permalink( $entry->post_id ) );
			} else {
				$lines[] = sprintf( '  %s: %s', __( 'Review draft', 'auto-release-posts-for-github' ), (string) get_edit_post_link( $entry->post_id, 'raw' ) );
			}

			$lines[] = sprintf( '  %s: %s', __( 'GitHub release', 'auto-release-posts-for-github' ), $entry->html_url );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Builds the HTML email body.
	 *
	 * Pure function of the entries array — exposed statically so the test-email
	 * REST handler in Admin_Page can render the same layout without duplicating
	 * the template. An optional preamble is prepended inside the wrapper div
	 * (used by the test email to flag "This is a test email."). Entries with
	 * a zero post_id (placeholder rows in the test flow) skip the view/edit
	 * links since there is no real post to point at.
	 *
	 * @param Notification_Entry[] $entries  Post entries.
	 * @param string               $preamble Optional HTML inserted before the entries table.
	 * @return string
	 */
	public static function build_html_body( array $entries, string $preamble = '' ): string {
		$site_url    = esc_url( home_url() );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$plugin_url  = 'https://github.com/jakemgold/github-release-posts-wordpress';
		$plugin_name = 'Auto Release Posts for GitHub';

		$html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px;">';

		if ( '' !== $preamble ) {
			$html .= $preamble;
		}

		$html .= '<p>' . sprintf(
			/* translators: 1: linked site URL, 2: linked plugin name */
			esc_html__( 'New posts have been generated from GitHub releases on %1$s, via the %2$s plugin.', 'auto-release-posts-for-github' ),
			'<a href="' . $site_url . '">' . esc_html( $site_host ) . '</a>',
			'<a href="' . esc_url( $plugin_url ) . '">' . esc_html( $plugin_name ) . '</a>'
		) . '</p>';

		$html .= '<table style="width: 100%; border-collapse: collapse;">';

		foreach ( $entries as $entry ) {
			$title = '' !== $entry->post_title ? $entry->post_title : $entry->display_name . ' ' . $entry->tag;

			$html .= '<tr style="border-bottom: 1px solid #eee;">';
			$html .= '<td style="padding: 12px 0;">';
			$html .= '<strong>' . esc_html( $title ) . '</strong>';
			$html .= ' <span style="color: #666;">(' . esc_html( $entry->display_name ) . ' ' . esc_html( $entry->tag ) . ')</span>';
			$html .= '<br>';

			if ( $entry->post_id > 0 ) {
				if ( Post_Status::is_public( $entry->status ) ) {
					$view_url = esc_url( get_permalink( $entry->post_id ) );
					$edit_url = esc_url( (string) get_edit_post_link( $entry->post_id, 'raw' ) );
					$html    .= '<a href="' . $view_url . '">' . esc_html__( 'View post', 'auto-release-posts-for-github' ) . '</a>';
					$html    .= ' · <a href="' . $edit_url . '">' . esc_html__( 'Edit', 'auto-release-posts-for-github' ) . '</a>';
				} else {
					$edit_url = esc_url( (string) get_edit_post_link( $entry->post_id, 'raw' ) );
					$html    .= '<a href="' . $edit_url . '"><strong>' . esc_html__( 'Review draft', 'auto-release-posts-for-github' ) . '</strong></a>';
				}
				$html .= ' · ';
			}

			$html .= '<a href="' . esc_url( $entry->html_url ) . '">' . esc_html__( 'GitHub release', 'auto-release-posts-for-github' ) . '</a>';
			$html .= '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '</div>';

		return $html;
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
			error_log(
				sprintf(
					'[GHRP] Email_Notifier: wp_mail failed for recipient "%s".',
					$recipient
				)
			);
		}
	}
}
