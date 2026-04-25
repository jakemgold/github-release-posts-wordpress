<?php
/**
 * GitHub API client.
 *
 * @package GitHubReleasePosts
 */

namespace Jakemgold\GitHubReleasePosts\GitHub;

use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;

/**
 * Thin HTTP client for the GitHub Releases API.
 *
 * All HTTP calls use WordPress's wp_remote_get() (BR-004).
 * Failures always return WP_Error — no exceptions are thrown (BR-005).
 */
class API_Client {

	/**
	 * GitHub REST API base URL.
	 */
	const API_BASE = 'https://api.github.com';

	/**
	 * Constructor.
	 *
	 * @param Global_Settings $settings Plugin settings service (provides PAT and version).
	 */
	public function __construct(
		private readonly Global_Settings $settings,
	) {}

	/**
	 * Fetches the latest release for a GitHub repository.
	 *
	 * Accepts both `owner/repo` and `https://github.com/owner/repo` formats (BR-002).
	 * Uses `/releases/latest`, which natively excludes drafts and pre-releases (BR-006).
	 * Responses are cached in a 15-minute transient (AC-005).
	 *
	 * @param string $identifier Repository identifier (`owner/repo` or full GitHub URL).
	 * @return Release|null|\WP_Error Release on success; null if no releases exist;
	 *                                WP_Error on network failure, HTTP error, or invalid input.
	 */
	public function fetch_latest_release( string $identifier ): Release|null|\WP_Error {
		// Normalise input to owner/repo.
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'github_invalid_identifier', $e->getMessage() );
		}

		// Return cached result if available.
		$cache_key = Plugin_Constants::TRANSIENT_RELEASE_PREFIX . md5( $identifier );
		$cached    = get_transient( $cache_key );
		if ( $cached instanceof Release ) {
			return $cached;
		}

		// Build request.
		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf( '%s/repos/%s/%s/releases/latest', self::API_BASE, $owner, $repo );
		$args             = $this->build_request_args();

		// Make HTTP call (BR-004).
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response; // Propagate network error (BR-005).
		}

		// Inspect and record rate limit headers (AC-009).
		$rate_limit_result = $this->handle_rate_limit( $response );
		if ( is_wp_error( $rate_limit_result ) ) {
			return $rate_limit_result; // Rate limit exhausted (AC-010).
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			// Repo has no releases, or does not exist (BR-001: private repos rejected upstream).
			// Treat as "no release found" rather than a hard error (AC-003).
			return null;
		}

		if ( 403 === $code ) {
			// Private repo or authentication error.
			return new \WP_Error(
				'github_forbidden',
				__( 'GitHub returned 403 Forbidden. The repository may be private or require authentication.', 'github-release-posts' )
			);
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				'github_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned HTTP %d.', 'github-release-posts' ),
					$code
				)
			);
		}

		// Parse JSON body.
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'github_parse_error',
				__( 'Failed to parse GitHub API response.', 'github-release-posts' )
			);
		}

		$release = Release::from_api_response( $data );

		// Cache successful result for 15 minutes (AC-005).
		set_transient( $cache_key, $release, 15 * MINUTE_IN_SECONDS );

		return $release;
	}

	/**
	 * Builds the wp_remote_get() arguments array.
	 *
	 * Includes a User-Agent (required by GitHub), the correct Accept header,
	 * and an Authorization header only when a PAT is configured (AC-006, AC-007).
	 * The PAT value is never included in any log or error output (AC-008).
	 *
	 * @return array<string, mixed>
	 */
	private function build_request_args(): array {
		$headers = [
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'github-release-posts/' . GITHUB_RELEASE_POSTS_VERSION,
		];

		$pat = $this->settings->get_github_pat();
		if ( '' !== $pat ) {
			$headers['Authorization'] = 'Bearer ' . $pat;
		}

		return [
			'headers' => $headers,
			'timeout' => 15,
		];
	}

	/**
	 * Inspects rate limit headers and handles exhaustion.
	 *
	 * Records the remaining request count in a transient (AC-009).
	 * If exhausted, logs a warning, schedules a one-hour retry, and returns
	 * a WP_Error so the caller can stop processing further repos (AC-010, AC-011).
	 *
	 * @param array|\WP_Error $response wp_remote_get() response.
	 * @return true|\WP_Error True if within limit; WP_Error if exhausted.
	 */
	private function handle_rate_limit( array|\WP_Error $response ): true|\WP_Error {
		$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );

		if ( '' === $remaining ) {
			return true; // Header absent — unauthenticated or header not sent.
		}

		set_transient( Plugin_Constants::TRANSIENT_RATE_LIMIT_REMAINING, (int) $remaining, HOUR_IN_SECONDS );

		if ( 0 === (int) $remaining ) {
			// Log as warning — never fatal (AC-011).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[github-release-posts] GitHub API rate limit exhausted. A retry has been scheduled.' );

			// Schedule one-time retry (AC-010) — only if not already queued.
			if ( ! wp_next_scheduled( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY ) ) {
				wp_schedule_single_event(
					time() + HOUR_IN_SECONDS,
					Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY
				);
			}

			return new \WP_Error(
				'github_rate_limit_exhausted',
				__( 'GitHub API rate limit exhausted. A retry has been scheduled for one hour from now.', 'github-release-posts' )
			);
		}

		return true;
	}

	/**
	 * Fetches a single issue or pull request by number.
	 *
	 * GitHub's Issues API returns both issues and PRs. No caching — these
	 * are fetched during prompt enrichment only.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @param int    $number     Issue or PR number.
	 * @return array{title: string, body: string}|\WP_Error Issue data or error.
	 */
	public function fetch_issue( string $identifier, int $number ): array|\WP_Error {
		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf( '%s/repos/%s/%s/issues/%d', self::API_BASE, $owner, $repo, $number );
		$args             = $this->build_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->handle_rate_limit( $response );

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error( 'github_issue_fetch_failed', sprintf( 'GitHub API returned HTTP %d for #%d.', $code, $number ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'github_issue_parse_error', 'Failed to parse issue response.' );
		}

		return [
			'title' => (string) ( $data['title'] ?? '' ),
			'body'  => (string) ( $data['body'] ?? '' ),
		];
	}

	/**
	 * Fetches the comparison between two tags (commits and file changes).
	 *
	 * Uses the GitHub Compare API to retrieve commit messages and a file
	 * change summary between two release tags. Results are structured for
	 * inclusion in the AI prompt during deep research.
	 *
	 * @param string $identifier Repository identifier (owner/repo).
	 * @param string $base_tag   Previous release tag.
	 * @param string $head_tag   Current release tag.
	 * @return array{commits: array<int, array{sha: string, message: string}>, files_changed: int, top_files: array<int, array{filename: string, status: string, additions: int, deletions: int}>}|\WP_Error
	 */
	public function fetch_compare( string $identifier, string $base_tag, string $head_tag ): array|\WP_Error {
		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf(
			'%s/repos/%s/%s/compare/%s...%s',
			self::API_BASE,
			$owner,
			$repo,
			rawurlencode( $base_tag ),
			rawurlencode( $head_tag )
		);
		$args             = $this->build_request_args();

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->handle_rate_limit( $response );

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new \WP_Error(
				'github_compare_failed',
				sprintf(
					/* translators: 1: base tag, 2: head tag, 3: HTTP status code */
					__( 'GitHub compare %1$s...%2$s returned HTTP %3$d.', 'github-release-posts' ),
					$base_tag,
					$head_tag,
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'github_compare_parse_error', __( 'Failed to parse compare response.', 'github-release-posts' ) );
		}

		// Extract commit subject lines (first line of each message), capped at 100.
		$commits = [];
		foreach ( array_slice( (array) ( $data['commits'] ?? [] ), 0, 100 ) as $commit ) {
			$full_message = (string) ( $commit['commit']['message'] ?? '' );
			$subject      = strtok( $full_message, "\n" );
			$sha          = substr( (string) ( $commit['sha'] ?? '' ), 0, 7 );

			if ( '' !== $sha && '' !== $subject ) {
				$commits[] = [
					'sha'     => $sha,
					'message' => $subject,
				];
			}
		}

		// Extract file change summary, sorted by most changes, capped at 20.
		$files     = (array) ( $data['files'] ?? [] );
		$top_files = [];

		usort(
			$files,
			function ( $a, $b ) {
				return ( ( $b['additions'] ?? 0 ) + ( $b['deletions'] ?? 0 ) ) - ( ( $a['additions'] ?? 0 ) + ( $a['deletions'] ?? 0 ) );
			}
		);

		foreach ( array_slice( $files, 0, 20 ) as $file ) {
			$top_files[] = [
				'filename'  => (string) ( $file['filename'] ?? '' ),
				'status'    => (string) ( $file['status'] ?? '' ),
				'additions' => (int) ( $file['additions'] ?? 0 ),
				'deletions' => (int) ( $file['deletions'] ?? 0 ),
			];
		}

		return [
			'commits'       => $commits,
			'files_changed' => count( (array) ( $data['files'] ?? [] ) ),
			'top_files'     => $top_files,
		];
	}

	/**
	 * Normalises a repository identifier to `owner/repo` format.
	 *
	 * Delegates to Repository_Settings for consistent normalisation logic (BR-002).
	 *
	 * @param string $input Raw identifier.
	 * @return string Normalised `owner/repo`.
	 * @throws \InvalidArgumentException If the identifier cannot be normalised.
	 */
	private function normalize_identifier( string $input ): string {
		return ( new Repository_Settings() )->normalize_identifier( $input );
	}
}
