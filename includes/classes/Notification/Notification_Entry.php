<?php
/**
 * Value object for a single row in a batched notification email.
 *
 * @package GitHubReleasePosts\Notification
 */

namespace GitHubReleasePosts\Notification;

/**
 * Typed container for the data each notification row needs: which post, what
 * status, which release. Built by Email_Notifier::collect and consumed by the
 * subject + body builders. Also used by the test-email path in Admin_Page to
 * render the preview against real or placeholder data.
 *
 * Replaces an associative array whose shape was repeated across collect(),
 * three body-builder methods, and the test handler — a missing or misspelled
 * key would have surfaced as a silently-empty notification.
 */
final readonly class Notification_Entry {

	/**
	 * Constructor.
	 *
	 * @param int    $post_id      WordPress post ID (0 for placeholder rows in the test-email flow).
	 * @param string $status       Post status slug (e.g. 'publish', 'draft').
	 * @param string $identifier   Repository identifier (owner/repo).
	 * @param string $display_name Resolved display name for the repository.
	 * @param string $tag          Release tag.
	 * @param string $html_url     GitHub release URL.
	 * @param string $post_title   Rendered post title.
	 */
	public function __construct(
		public int $post_id,
		public string $status,
		public string $identifier,
		public string $display_name,
		public string $tag,
		public string $html_url,
		public string $post_title,
	) {}
}
