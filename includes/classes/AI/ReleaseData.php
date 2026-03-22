<?php
/**
 * Value object representing a GitHub release passed into the AI generation pipeline.
 *
 * @package ChangelogToBlogPost\AI
 */

namespace TenUp\ChangelogToBlogPost\AI;

/**
 * Immutable data bag carrying everything an AI provider needs to generate a blog post.
 */
readonly class ReleaseData {

	/**
	 * @param string   $identifier  Repository identifier (owner/repo).
	 * @param string   $tag         Release tag (e.g. 'v2.3.1').
	 * @param string   $name        Release name.
	 * @param string   $body        Raw changelog body (Markdown).
	 * @param string   $html_url    URL to the release page on GitHub.
	 * @param string   $published_at ISO 8601 publish timestamp.
	 * @param array    $assets      Release asset list.
	 */
	public function __construct(
		public string $identifier,
		public string $tag,
		public string $name,
		public string $body,
		public string $html_url,
		public string $published_at,
		public array $assets = [],
	) {}

	/**
	 * Creates a ReleaseData instance from a release queue entry array.
	 *
	 * @param array $entry Queue entry as stored by Release_Queue.
	 * @return self
	 */
	public static function from_entry( array $entry ): self {
		return new self(
			identifier:   (string) ( $entry['identifier'] ?? '' ),
			tag:          (string) ( $entry['tag'] ?? '' ),
			name:         (string) ( $entry['name'] ?? '' ),
			body:         (string) ( $entry['body'] ?? '' ),
			html_url:     (string) ( $entry['html_url'] ?? '' ),
			published_at: (string) ( $entry['published_at'] ?? '' ),
			assets:       (array) ( $entry['assets'] ?? [] ),
		);
	}
}
