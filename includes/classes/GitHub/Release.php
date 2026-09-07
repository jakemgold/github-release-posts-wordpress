<?php
/**
 * GitHub Release value object.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable representation of a single GitHub release.
 *
 * Constructed exclusively via Release::from_api_response() so that the
 * mapping from raw API data to domain object is contained in one place.
 */
class Release {

	/**
	 * Constructor.
	 *
	 * @param string  $tag          The release tag (e.g. 'v2.3.1').
	 * @param string  $name         The release title.
	 * @param string  $body         The release notes / changelog body.
	 * @param string  $published_at ISO 8601 publication timestamp.
	 * @param string  $html_url     URL to the release page on GitHub.
	 * @param array[] $assets       Slim asset records: name, browser_download_url, size.
	 * @param bool    $prerelease   Whether GitHub marks this release as a pre-release.
	 */
	public function __construct(
		public readonly string $tag,
		public readonly string $name,
		public readonly string $body,
		public readonly string $published_at,
		public readonly string $html_url,
		public readonly array $assets,
		public readonly bool $prerelease = false,
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

		// Keep only the asset fields anything downstream could use. A raw asset
		// object is ~1.5 KB (it embeds the uploader's user record), and releases
		// with dozens of build artifacts pushed a 25-record snapshot past
		// Memcached's 1 MB item limit — the transient never stored and every
		// surface refetched.
		$assets = [];
		foreach ( (array) ( $data['assets'] ?? [] ) as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$assets[] = [
				'name'                 => (string) ( $asset['name'] ?? '' ),
				'browser_download_url' => (string) ( $asset['browser_download_url'] ?? '' ),
				'size'                 => (int) ( $asset['size'] ?? 0 ),
			];
		}

		return new self(
			tag:          $tag,
			name:         '' !== $name ? $name : $tag,
			body:         (string) ( $data['body'] ?? '' ),
			published_at: (string) ( $data['published_at'] ?? '' ),
			html_url:     (string) ( $data['html_url'] ?? '' ),
			assets:       $assets,
			prerelease: ! empty( $data['prerelease'] ),
		);
	}
}
