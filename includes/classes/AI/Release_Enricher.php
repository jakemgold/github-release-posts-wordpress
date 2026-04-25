<?php
/**
 * Enriches release body with linked PR/issue context.
 *
 * @package GitHubReleasePosts\AI
 */

namespace Jakemgold\GitHubReleasePosts\AI;

use Jakemgold\GitHubReleasePosts\GitHub\API_Client;
use Jakemgold\GitHubReleasePosts\GitHub\Release_State;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;

/**
 * Scans the release body for GitHub issue/PR references (#123 or full URLs),
 * fetches their titles and descriptions, and appends a summary section.
 * Optionally fetches commit history for deep research mode.
 *
 * Hooks into ghrp_release_body filter (fired by Prompt_Builder).
 */
class Release_Enricher {

	/**
	 * Maximum number of references to resolve per release.
	 * Prevents excessive API calls for changelogs that reference dozens of PRs.
	 */
	const MAX_REFERENCES = 10;

	/**
	 * Constructor.
	 *
	 * @param API_Client $api_client GitHub API client.
	 */
	public function __construct(
		private readonly API_Client $api_client,
	) {}

	/**
	 * Registers the ghrp_release_body filters.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( 'ghrp_release_body', [ $this, 'enrich' ], 10, 2 );
		add_filter( 'ghrp_release_body', [ $this, 'enrich_with_commits' ], 20, 2 );
	}

	/**
	 * Enriches a release body with linked PR/issue context.
	 *
	 * @param string      $body Raw release body.
	 * @param ReleaseData $data Release data (provides identifier for API calls).
	 * @return string Enriched body with appended reference details.
	 */
	public function enrich( string $body, ReleaseData $data ): string {
		$references = $this->extract_references( $body, $data->identifier );

		if ( empty( $references ) ) {
			return $body;
		}

		// Limit to prevent excessive API calls.
		$references = array_slice( $references, 0, self::MAX_REFERENCES );

		$details = [];
		foreach ( $references as $ref ) {
			$result = $this->api_client->fetch_issue( $ref['identifier'], $ref['number'] );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			$title = $result['title'];
			$desc  = $result['body'];

			// Truncate long PR descriptions to keep prompt size reasonable.
			if ( strlen( $desc ) > 500 ) {
				$desc = substr( $desc, 0, 500 ) . '…';
			}

			$details[] = sprintf(
				"- #%d: %s\n  %s",
				$ref['number'],
				$title,
				$desc ? $desc : '(no description)'
			);
		}

		if ( empty( $details ) ) {
			return $body;
		}

		$section = "\n\n---\nReferenced pull requests and issues:\n" . implode( "\n\n", $details );

		return $body . $section;
	}

	/**
	 * Appends commit history and file change summary when deep research is enabled.
	 *
	 * Fetches the comparison between the previous release tag and the current
	 * one via the GitHub Compare API. Only runs when research depth is 'deep'.
	 *
	 * @param string      $body Raw (or already-enriched) release body.
	 * @param ReleaseData $data Release data.
	 * @return string Body with commit history appended, or unchanged if skipped.
	 */
	public function enrich_with_commits( string $body, ReleaseData $data ): string {
		$settings = new Global_Settings();

		/**
		 * Filters the research depth for a specific repository.
		 *
		 * Allows developers to override the global research depth setting
		 * on a per-repo basis. Return 'standard' or 'deep'.
		 *
		 * @param string $depth      Current research depth.
		 * @param string $identifier Repository identifier (owner/repo).
		 */
		$depth = (string) apply_filters( 'ghrp_research_depth', $settings->get_research_depth(), $data->identifier );

		if ( 'deep' !== $depth ) {
			return $body;
		}

		// Get the previous tag from stored release state.
		$state        = ( new Release_State() )->get_state( $data->identifier );
		$previous_tag = $state['last_seen_tag'] ?? '';

		if ( '' === $previous_tag ) {
			return $body; // First release tracked — nothing to compare against.
		}

		$compare = $this->api_client->fetch_compare( $data->identifier, $previous_tag, $data->tag );

		if ( is_wp_error( $compare ) ) {
			return $body; // Graceful degradation — skip commit data on API failure.
		}

		if ( empty( $compare['commits'] ) ) {
			return $body;
		}

		$section = "\n\n---\nCOMMIT HISTORY (since " . $previous_tag . "):\n\n";

		foreach ( $compare['commits'] as $commit ) {
			$section .= sprintf( "- %s: %s\n", $commit['sha'], $commit['message'] );
		}

		$section .= sprintf( "\nFILES CHANGED: %d files\n", $compare['files_changed'] );

		if ( ! empty( $compare['top_files'] ) ) {
			$file_summaries = [];
			foreach ( array_slice( $compare['top_files'], 0, 10 ) as $file ) {
				$file_summaries[] = sprintf(
					'%s (+%d/-%d)',
					$file['filename'],
					$file['additions'],
					$file['deletions']
				);
			}
			$section .= 'Most affected: ' . implode( ', ', $file_summaries ) . "\n";
		}

		return $body . $section;
	}

	/**
	 * Extracts issue/PR references from a release body.
	 *
	 * Matches:
	 * - Bare `#123` references (resolved against the release's own repo)
	 * - Full GitHub URLs: `https://github.com/owner/repo/pull/123` or `/issues/123`
	 *
	 * @param string $body       Release body text.
	 * @param string $identifier Default repo identifier for bare #N references.
	 * @return array<int, array{identifier: string, number: int}> Unique references.
	 */
	private function extract_references( string $body, string $identifier ): array {
		$refs = [];
		$seen = [];

		// Full GitHub PR/issue URLs.
		if ( preg_match_all( '#https?://github\.com/([A-Za-z0-9_./-]+)/(?:pull|issues)/(\d+)#', $body, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$repo   = $match[1];
				$number = (int) $match[2];
				$key    = $repo . '#' . $number;

				if ( ! isset( $seen[ $key ] ) ) {
					$refs[]       = [
						'identifier' => $repo,
						'number'     => $number,
					];
					$seen[ $key ] = true;
				}
			}
		}

		// Bare #123 references (not preceded by a slash to avoid matching URLs already captured).
		if ( preg_match_all( '/(?<![\/\w])#(\d+)\b/', $body, $matches ) ) {
			foreach ( $matches[1] as $number ) {
				$number = (int) $number;
				$key    = $identifier . '#' . $number;

				if ( ! isset( $seen[ $key ] ) ) {
					$refs[]       = [
						'identifier' => $identifier,
						'number'     => $number,
					];
					$seen[ $key ] = true;
				}
			}
		}

		return $refs;
	}
}
