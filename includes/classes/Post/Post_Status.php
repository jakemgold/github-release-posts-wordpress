<?php
/**
 * Thin lens over WordPress's post-status registry.
 *
 * @package GitHubReleasePosts\Post
 */

namespace GitHubReleasePosts\Post;

/**
 * Replaces hardcoded `'publish' === $status` comparisons with semantic
 * queries that delegate to WordPress's own status registry. Custom statuses
 * registered by other plugins (e.g. Edit Flow's "pitch", "assigned",
 * "approved") behave correctly: a custom status flagged `public` reads as
 * publicly-viewable here, just like the built-in `publish`.
 */
class Post_Status {

	/**
	 * Returns true when posts with this status are visible to anonymous
	 * front-end visitors — i.e. have a usable public URL.
	 *
	 * Use this to decide "view post" vs "review draft" link styling, whether
	 * to backdate `post_date` on a publish transition, and how to bucket
	 * results in admin notices.
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function is_public( string $status ): bool {
		$obj = get_post_status_object( $status );
		return $obj instanceof \stdClass && ! empty( $obj->public );
	}

	/**
	 * Returns true when posts with this status have a permanent URL —
	 * public or private. Use to decide whether the slug should be preserved
	 * on regenerate (private posts have access-controlled but bookmarkable
	 * permalinks too).
	 *
	 * @param string $status Status slug.
	 * @return bool
	 */
	public static function has_permalink( string $status ): bool {
		$obj = get_post_status_object( $status );
		return $obj instanceof \stdClass && ( ! empty( $obj->public ) || ! empty( $obj->private ) );
	}

	/**
	 * Returns the localised label for a status, or an empty string when the
	 * status is unregistered or has no label.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function label( string $status ): string {
		$obj = get_post_status_object( $status );
		return ( $obj instanceof \stdClass && isset( $obj->label ) ) ? (string) $obj->label : '';
	}
}
