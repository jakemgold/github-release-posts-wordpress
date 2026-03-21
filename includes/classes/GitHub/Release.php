<?php
/**
 * GitHub Release value object.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\GitHub;

/**
 * Immutable representation of a single GitHub release.
 *
 * Constructed exclusively via Release::from_api_response() so that the
 * mapping from raw API data to domain object is contained in one place.
 */
class Release {

	/**
	 * @param string  $tag          The release tag (e.g. 'v2.3.1').
	 * @param string  $name         The release title.
	 * @param string  $body         The release notes / changelog body.
	 * @param string  $published_at ISO 8601 publication timestamp.
	 * @param string  $html_url     URL to the release page on GitHub.
	 * @param array[] $assets       Array of release asset objects from the API.
	 */
	public function __construct(
		public readonly string $tag,
		public readonly string $name,
		public readonly string $body,
		public readonly string $published_at,
		public readonly string $html_url,
		public readonly array $assets,
	) {}

	/**
	 * Constructs a Release from a decoded GitHub API response array.
	 *
	 * @param array<string, mixed> $data Decoded JSON from /releases/latest.
	 * @return self
	 */
	public static function from_api_response( array $data ): self {
		$tag  = (string) ( $data['tag_name'] ?? '' );
		$name = (string) ( $data['name'] ?? '' );

		return new self(
			tag:          $tag,
			name:         $name !== '' ? $name : $tag,
			body:         (string) ( $data['body'] ?? '' ),
			published_at: (string) ( $data['published_at'] ?? '' ),
			html_url:     (string) ( $data['html_url'] ?? '' ),
			assets:       is_array( $data['assets'] ?? null ) ? $data['assets'] : [],
		);
	}
}
