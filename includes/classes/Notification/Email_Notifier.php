<?php
/**
 * Sends batched email notifications after a cron run.
 *
 * @package GitHubReleasePosts\Notification
 */

namespace Jakemgold\GitHubReleasePosts\Notification;

use Jakemgold\GitHubReleasePosts\AI\ReleaseData;
use Jakemgold\GitHubReleasePosts\AI\Release_Significance;
use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;

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
	 * @var array<int, array{post_id: int, status: string, identifier: string, display_name: string, tag: string, html_url: string, significance: string, post_title: string}>
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
			'post_title'   => get_the_title( $post_id ),
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
	 * Always sends for any generated post (draft or published).
	 *
	 * @return void
	 */
	public function send(): void {
		if ( empty( $this->entries ) ) {
			return;
		}

		$eligible   = $this->entries;
		$recipients = $this->resolve_recipients();
		if ( empty( $recipients ) ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$subject   = $this->build_subject( $eligible, $site_name );

		$text_body = $this->build_text_body( $eligible );
		$html_body = $this->build_html_body( $eligible );

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
	 * @param array  $entries   Post entries.
	 * @param string $site_name Site name.
	 * @return string
	 */
	private function build_subject( array $entries, string $site_name ): string {
		if ( 1 === count( $entries ) ) {
			$entry  = $entries[0];
			$prefix = $entry['display_name'] . ' ' . $entry['tag'];

			if ( 'publish' === $entry['status'] ) {
				/* translators: %s: project name and version, e.g. "Gutenberg 19.0" */
				return sprintf( __( '%s — release post published', 'github-release-posts' ), $prefix );
			}

			/* translators: %s: project name and version, e.g. "Gutenberg 19.0" */
			return sprintf( __( '%s — draft ready for review', 'github-release-posts' ), $prefix );
		}

		return sprintf(
			/* translators: 1: site name, 2: number of posts */
			__( '[%1$s] %2$d release posts ready', 'github-release-posts' ),
			$site_name,
			count( $entries )
		);
	}

	/**
	 * Builds the plain text email body.
	 *
	 * @param array $entries Post entries.
	 * @return string
	 */
	private function build_text_body( array $entries ): string {
		$site_url = home_url();
		$lines    = [];

		$lines[] = sprintf(
			/* translators: 1: site URL, 2: plugin name */
			__( 'New posts have been generated from GitHub releases on %1$s, via the %2$s plugin.', 'github-release-posts' ),
			$site_url,
			'GitHub Release Posts'
		);
		$lines[] = '';

		foreach ( $entries as $entry ) {
			$title   = ! empty( $entry['post_title'] ) ? $entry['post_title'] : $entry['display_name'] . ' ' . $entry['tag'];
			$lines[] = sprintf( '• %s', $title );

			if ( 'publish' === $entry['status'] ) {
				$lines[] = sprintf( '  %s: %s', __( 'View post', 'github-release-posts' ), get_permalink( $entry['post_id'] ) );
			} else {
				$lines[] = sprintf( '  %s: %s', __( 'Review draft', 'github-release-posts' ), $this->get_edit_url( $entry['post_id'] ) );
			}

			$lines[] = sprintf( '  %s: %s', __( 'GitHub release', 'github-release-posts' ), $entry['html_url'] );
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Builds the HTML email body.
	 *
	 * @param array $entries Post entries.
	 * @return string
	 */
	private function build_html_body( array $entries ): string {
		$site_url    = esc_url( home_url() );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$plugin_url  = 'https://github.com/jakemgold/github-release-posts-wordpress';
		$plugin_name = 'GitHub Release Posts';

		$html  = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px;">';
		$html .= '<p>' . sprintf(
			/* translators: 1: linked site URL, 2: linked plugin name */
			esc_html__( 'New posts have been generated from GitHub releases on %1$s, via the %2$s plugin.', 'github-release-posts' ),
			'<a href="' . $site_url . '">' . esc_html( $site_host ) . '</a>',
			'<a href="' . esc_url( $plugin_url ) . '">' . esc_html( $plugin_name ) . '</a>'
		) . '</p>';

		$html .= '<table style="width: 100%; border-collapse: collapse;">';

		foreach ( $entries as $entry ) {
			$title = ! empty( $entry['post_title'] )
				? $entry['post_title']
				: $entry['display_name'] . ' ' . $entry['tag'];

			$html .= '<tr style="border-bottom: 1px solid #eee;">';
			$html .= '<td style="padding: 12px 0;">';
			$html .= '<strong>' . esc_html( $title ) . '</strong>';
			$html .= ' <span style="color: #666;">(' . esc_html( $entry['display_name'] ) . ' ' . esc_html( $entry['tag'] ) . ')</span>';
			$html .= '<br>';

			if ( 'publish' === $entry['status'] ) {
				$view_url = esc_url( get_permalink( $entry['post_id'] ) );
				$html    .= '<a href="' . $view_url . '">' . esc_html__( 'View post', 'github-release-posts' ) . '</a>';
				$html    .= ' · <a href="' . esc_url( $this->get_edit_url( $entry['post_id'] ) ) . '">' . esc_html__( 'Edit', 'github-release-posts' ) . '</a>';
			} else {
				$edit_url = esc_url( $this->get_edit_url( $entry['post_id'] ) );
				$html    .= '<a href="' . $edit_url . '"><strong>' . esc_html__( 'Review draft', 'github-release-posts' ) . '</strong></a>';
			}

			$html .= ' · <a href="' . esc_url( $entry['html_url'] ) . '">' . esc_html__( 'GitHub release', 'github-release-posts' ) . '</a>';
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
			error_log(
				sprintf(
					'[CTBP] Email_Notifier: wp_mail failed for recipient "%s".',
					$recipient
				)
			);
		}
	}
}
