<?php
/**
 * Enriches release body with linked PR/issue context.
 *
 * @package ChangelogToBlogPost\AI
 */

namespace TenUp\ChangelogToBlogPost\AI;

use TenUp\ChangelogToBlogPost\GitHub\API_Client;

/**
 * Scans the release body for GitHub issue/PR references (#123 or full URLs),
 * fetches their titles and descriptions, and appends a summary section.
 * This gives the AI richer context for generating blog posts.
 *
 * Hooks into ctbp_release_body filter (fired by Prompt_Builder).
 */
class Release_Enricher {

	/**
	 * Maximum number of references to resolve per release.
	 * Prevents excessive API calls for changelogs that reference dozens of PRs.
	 */
	const MAX_REFERENCES = 10;

	/**
	 * @param API_Client $api_client GitHub API client.
	 */
	public function __construct(
		private readonly API_Client $api_client,
	) {}

	/**
	 * Registers the ctbp_release_body filter.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_filter( 'ctbp_release_body', [ $this, 'enrich' ], 10, 2 );
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
