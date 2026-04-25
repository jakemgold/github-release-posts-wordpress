<?php
/**
 * In-process queue of newly detected releases.
 *
 * @package GitHubReleasePosts
 */

namespace Jakemgold\GitHubReleasePosts\GitHub;

use Jakemgold\GitHubReleasePosts\Plugin_Constants;

/**
 * Lightweight queue that holds newly detected releases within a single cron run.
 *
 * Releases are enqueued when detected, then dequeued and processed (fired as
 * actions) in the same run. The queue is stored in wp_options so it survives
 * a PHP timeout mid-run, though normal operation processes it immediately.
 */
class Release_Queue {

	/**
	 * Adds a release entry to the queue.
	 *
	 * @param string  $identifier Normalised `owner/repo` identifier.
	 * @param Release $release    The release to queue.
	 * @return void
	 */
	public function enqueue( string $identifier, Release $release ): void {
		$queue   = $this->load();
		$queue[] = self::from_release( $identifier, $release );
		$this->save( $queue );
	}

	/**
	 * Returns all queued entries and clears the queue.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function dequeue_all(): array {
		$queue = $this->load();
		$this->save( [] );
		return $queue;
	}

	/**
	 * Builds a serialisable queue entry array from a Release value object.
	 *
	 * @param string  $identifier Normalised `owner/repo` identifier.
	 * @param Release $release    The release.
	 * @return array<string, mixed>
	 */
	public static function from_release( string $identifier, Release $release ): array {
		return [
			'identifier'   => $identifier,
			'tag'          => $release->tag,
			'name'         => $release->name,
			'body'         => $release->body,
			'html_url'     => $release->html_url,
			'published_at' => $release->published_at,
			'assets'       => $release->assets,
		];
	}

	/**
	 * Loads the current queue from options.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function load(): array {
		$data = get_option( Plugin_Constants::OPTION_RELEASE_QUEUE, [] );
		return is_array( $data ) ? $data : [];
	}

	/**
	 * Persists the queue to options.
	 *
	 * @param array<int, array<string, mixed>> $queue Queue entries to persist.
	 * @return void
	 */
	private function save( array $queue ): void {
		update_option( Plugin_Constants::OPTION_RELEASE_QUEUE, $queue, false );
	}
}
