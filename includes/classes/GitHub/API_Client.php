<?php
/**
 * GitHub API client.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\GitHub;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;

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
		$cache_key = Cache_Keys::release( $identifier );
		$cached    = get_transient( $cache_key );
		if ( $cached instanceof Release ) {
			return $cached;
		}

		// Defend against cache stampede when the transient expires under
		// concurrent load. wp_cache_add() is atomic when a persistent object
		// cache is installed; with the default in-process cache it has no
		// effect, leaving us no worse off than before.
		$lock_key  = Cache_Keys::release_fetch_lock( $identifier );
		$owns_lock = (bool) wp_cache_add( $lock_key, 1, '', 30 );
		if ( ! $owns_lock ) {
			// Another process is fetching. Wait briefly for it to populate
			// the transient — up to ~750ms total — then return whatever it
			// produced. If it never appears, fall through and fetch ourselves.
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				usleep( 250000 );
				$cached = get_transient( $cache_key );
				if ( $cached instanceof Release ) {
					return $cached;
				}
			}
		}

		try {
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
					__( 'GitHub returned 403 Forbidden. The repository may be private or require authentication.', 'auto-release-posts-for-github' )
				);
			}

			if ( 200 !== $code ) {
				return new \WP_Error(
					'github_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
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
					__( 'Failed to parse GitHub API response.', 'auto-release-posts-for-github' )
				);
			}

			$release = Release::from_api_response( $data );

			// Cache successful result for 15 minutes (AC-005).
			set_transient( $cache_key, $release, 15 * MINUTE_IN_SECONDS );

			return $release;
		} finally {
			if ( $owns_lock ) {
				wp_cache_delete( $lock_key );
			}
		}
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
			'User-Agent'           => 'github-release-posts/' . GHRP_VERSION,
		];

		$pat = $this->settings->get_github_pat();
		if ( '' !== $pat ) {
			$headers['Authorization'] = 'Bearer ' . $pat;
		}

		// Never follow redirects on these authenticated calls: WordPress replays
		// the Authorization header (the PAT) to the redirect target, and the
		// api.github.com JSON endpoints do not legitimately redirect off-host. A
		// renamed-repo 301 now surfaces as an error rather than being followed
		// silently — the admin should update the stored identifier in that case.
		return [
			'headers'     => $headers,
			'timeout'     => 15,
			'redirection' => 0,
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

		set_transient( Cache_Keys::rate_limit_remaining(), (int) $remaining, HOUR_IN_SECONDS );

		if ( 0 === (int) $remaining ) {
			// Log as warning — never fatal (AC-011).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[auto-release-posts-for-github] GitHub API rate limit exhausted. A retry has been scheduled.' );

			// Schedule one-time retry (AC-010) — only if not already queued.
			if ( ! wp_next_scheduled( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY ) ) {
				wp_schedule_single_event(
					time() + HOUR_IN_SECONDS,
					Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY
				);
			}

			// GitHub reports the remaining count *after* serving this request, so a
			// successful (2xx) response that reports zero remaining still contains
			// the release we asked for. Only abort when the response itself is
			// unusable — a real rate-limit rejection is a 403. Returning an error
			// on a 200 would discard a response we were actually handed and
			// silently skip that release until the scheduled retry.
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				return new \WP_Error(
					'github_rate_limit_exhausted',
					__( 'GitHub API rate limit exhausted. A retry has been scheduled for one hour from now.', 'auto-release-posts-for-github' )
				);
			}
		}

		return true;
	}

	/**
	 * Checks whether a repository exists (and is visible to current credentials).
	 *
	 * Needed because the release endpoints 404 identically for a nonexistent
	 * repo and a real repo with zero releases (AC-003 treats those 404s as
	 * "no releases"), so existence must be asked of `GET /repos/{owner}/{repo}`
	 * directly. GitHub masks private repos the current credentials cannot see
	 * as 404, so `false` means "nonexistent or not visible", not provably
	 * nonexistent.
	 *
	 * @param string $identifier Repository identifier (owner/repo or full URL).
	 * @return bool|\WP_Error True if visible, false on 404, WP_Error on
	 *                        network/HTTP/rate-limit failures.
	 */
	public function repo_exists( string $identifier ): bool|\WP_Error {
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'github_invalid_identifier', $e->getMessage() );
		}

		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf( '%s/repos/%s/%s', self::API_BASE, $owner, $repo );

		$response = wp_remote_get( $url, $this->build_request_args() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rate_limit = $this->handle_rate_limit( $response );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}
		if ( 404 === $code ) {
			return false;
		}

		return new \WP_Error(
			'github_http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
				$code
			)
		);
	}

	/**
	 * Fetches a list of releases for a repository (latest first, capped at 100).
	 *
	 * Used by the manual "Generate post" flow to let admins pick an older release.
	 * Drafts are always excluded. Pre-releases are excluded by default; pass
	 * `$include_prereleases = true` to include them (used when a repo has the
	 * Include pre-releases option turned on).
	 *
	 * @param string $identifier          Repository identifier (owner/repo or full URL).
	 * @param bool   $include_prereleases When true, pre-release versions are included.
	 * @param string $tag_patterns        Optional comma-separated glob patterns; when
	 *                                    non-empty, only releases whose tag matches one
	 *                                    are returned (monorepo package selection).
	 * @return Release[]|\WP_Error Releases in newest-first order, or WP_Error on failure.
	 */
	public function fetch_releases( string $identifier, bool $include_prereleases = false, string $tag_patterns = '' ): array|\WP_Error {
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'github_invalid_identifier', $e->getMessage() );
		}

		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf( '%s/repos/%s/%s/releases?per_page=100', self::API_BASE, $owner, $repo );
		$args             = $this->build_request_args();

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rate_limit = $this->handle_rate_limit( $response );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return [];
		}
		if ( 200 !== $code ) {
			return new \WP_Error(
				'github_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'github_parse_error', __( 'Failed to parse GitHub API response.', 'auto-release-posts-for-github' ) );
		}

		$releases = [];
		foreach ( $data as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Drafts never become blog posts.
			if ( ! empty( $entry['draft'] ) ) {
				continue;
			}
			// Pre-releases are excluded unless the caller opts in.
			if ( ! $include_prereleases && ! empty( $entry['prerelease'] ) ) {
				continue;
			}
			// Monorepo package selection: with patterns set, only matching tags
			// are eligible. Lives here, beside the draft/prerelease checks, so
			// every consumer (cron monitor, version picker, manual generation)
			// inherits the same eligibility rules.
			if ( ! Tag_Pattern_Matcher::matches( (string) ( $entry['tag_name'] ?? '' ), $tag_patterns ) ) {
				continue;
			}
			$releases[] = Release::from_api_response( $entry );
		}

		return $releases;
	}

	/**
	 * Fetches the latest release eligible for post generation, honoring a
	 * repo's pre-release preference.
	 *
	 * When pre-releases are excluded (the default), this delegates to
	 * `fetch_latest_release()` which uses GitHub's `/releases/latest` endpoint
	 * (fast, cached). When pre-releases are included, it falls back to
	 * `/releases` and returns the newest non-draft entry — uncached, since
	 * the cache key for `/releases/latest` would otherwise alias the two views.
	 *
	 * @param string $identifier          Repository identifier (owner/repo or full URL).
	 * @param bool   $include_prereleases When true, pre-releases are eligible.
	 * @param string $tag_patterns        Optional comma-separated glob patterns for
	 *                                    monorepo package selection.
	 * @return Release|null|\WP_Error Release on success; null if no eligible release exists;
	 *                                WP_Error on network or HTTP failure.
	 */
	public function fetch_latest_eligible_release( string $identifier, bool $include_prereleases, string $tag_patterns = '' ): Release|null|\WP_Error {
		// The fast, cached /releases/latest endpoint cannot honor tag patterns —
		// it would happily return a non-matching package's release. Only repos
		// without patterns (and without the prerelease opt-in) may use it.
		if ( ! $include_prereleases && ! Tag_Pattern_Matcher::has_patterns( $tag_patterns ) ) {
			return $this->fetch_latest_release( $identifier );
		}

		$releases = $this->fetch_releases( $identifier, $include_prereleases, $tag_patterns );
		if ( is_wp_error( $releases ) ) {
			return $releases;
		}

		if ( empty( $releases ) ) {
			return null;
		}

		// /releases is ordered by created_at, which is NOT the same as "highest
		// version": a backport (e.g. 1.9.6 published after 2.0.0) sorts first and
		// would win. A single flat reduction is also NOT valid when packages mix
		// (peer review round 3): same-package comparisons use versions while
		// cross-package ones use chronology, and combining the two relations in
		// one pass is non-transitive (a January core@2.0.0 could displace the
		// February release of another package via a March core backport). Reduce
		// in two stages instead: highest version within each package stream,
		// then newest by publication date among the stream winners.
		$comparator = new Version_Comparator();

		$groups = [];
		foreach ( $releases as $release ) {
			$parsed           = Tag_Pattern_Matcher::derive_package( $release->tag );
			$key              = null === $parsed ? '' : $parsed['package'];
			$groups[ $key ][] = $release;
		}

		$winners = [];
		foreach ( $groups as $group ) {
			$winner = $group[0];
			foreach ( array_slice( $group, 1 ) as $candidate ) {
				$state = [
					'last_seen_tag'          => $winner->tag,
					'last_seen_published_at' => $winner->published_at,
					'last_checked_at'        => 0,
				];
				if ( $comparator->is_newer( $candidate, $state ) ) {
					$winner = $candidate;
				}
			}
			$winners[] = $winner;
		}

		$latest = $winners[0];
		foreach ( array_slice( $winners, 1 ) as $winner ) {
			if ( $winner->published_at > $latest->published_at ) {
				$latest = $winner;
			}
		}

		return $latest;
	}

	/**
	 * Fetches a single release by tag.
	 *
	 * @param string $identifier Repository identifier (owner/repo or full URL).
	 * @param string $tag        Release tag (e.g. 'v2.3.0').
	 * @return Release|null|\WP_Error Release on success; null if the tag is not a published release;
	 *                                 WP_Error on network or parse failure.
	 */
	public function fetch_release_by_tag( string $identifier, string $tag ): Release|null|\WP_Error {
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'github_invalid_identifier', $e->getMessage() );
		}

		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf(
			'%s/repos/%s/%s/releases/tags/%s',
			self::API_BASE,
			$owner,
			$repo,
			rawurlencode( $tag )
		);

		$response = wp_remote_get( $url, $this->build_request_args() );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rate_limit = $this->handle_rate_limit( $response );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return null;
		}
		if ( 200 !== $code ) {
			return new \WP_Error(
				'github_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'github_parse_error', __( 'Failed to parse GitHub API response.', 'auto-release-posts-for-github' ) );
		}

		return Release::from_api_response( $data );
	}

	/**
	 * Fetches the decoded README content for a repository.
	 *
	 * Uses GitHub's `/repos/{owner}/{repo}/readme` endpoint, which transparently
	 * resolves all common README filenames and locations (README.md, .MD,
	 * docs/README.md, etc.). Requests the raw representation so we receive
	 * the decoded markdown directly rather than base64.
	 *
	 * Best-effort: returns an empty string on any failure (no README, HTTP error,
	 * rate-limit exhaustion, private repo without PAT) so callers can fall back
	 * cleanly. Never returns WP_Error — the absence of a README is a normal
	 * condition, not an error.
	 *
	 * @param string $identifier Repository identifier (`owner/repo` or full GitHub URL).
	 * @return string Raw README markdown, or empty string if unavailable.
	 */
	public function fetch_readme( string $identifier ): string {
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}

		[ $owner, $repo ] = explode( '/', $identifier, 2 );
		$url              = sprintf( '%s/repos/%s/%s/readme', self::API_BASE, $owner, $repo );

		$args                      = $this->build_request_args();
		$args['headers']['Accept'] = 'application/vnd.github.raw';
		// README endpoints sometimes return large payloads; keep the timeout
		// modest since this is best-effort on a user-facing button click.
		$args['timeout'] = 8;

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return '';
		}

		// Update rate-limit accounting but never block this path on exhaustion —
		// the caller is expected to silently fall back.
		$this->handle_rate_limit( $response );

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return '';
		}

		$body = (string) wp_remote_retrieve_body( $response );

		// Sanity cap: extremely large READMEs (>512 KB) are almost never what
		// we want to parse for a heading and risk pathological regex behavior.
		if ( strlen( $body ) > 512 * 1024 ) {
			return '';
		}

		return $body;
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
		// This identifier comes from links in untrusted release notes (via
		// Release_Enricher), so validate and URL-encode it before building the
		// request path. Without this, a value like "owner/.." or one carrying
		// extra path segments would reshape the URL and point the site's PAT at
		// an unintended GitHub endpoint.
		try {
			$identifier = $this->normalize_identifier( $identifier );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'github_invalid_identifier', $e->getMessage() );
		}

		[ $owner, $repo ] = explode( '/', $identifier, 2 );

		if ( in_array( $owner, [ '.', '..' ], true ) || in_array( $repo, [ '.', '..' ], true ) ) {
			return new \WP_Error(
				'github_invalid_identifier',
				__( 'Invalid repository identifier.', 'auto-release-posts-for-github' )
			);
		}

		$url  = sprintf(
			'%s/repos/%s/%s/issues/%d',
			self::API_BASE,
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			$number
		);
		$args = $this->build_request_args();

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
					__( 'GitHub compare %1$s...%2$s returned HTTP %3$d.', 'auto-release-posts-for-github' ),
					$base_tag,
					$head_tag,
					$code
				)
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'github_compare_parse_error', __( 'Failed to parse compare response.', 'auto-release-posts-for-github' ) );
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
	 * Lists repositories the configured PAT can access.
	 *
	 * Calls `GET /user/repos` with pagination. Caches the resulting list in a
	 * 24-hour transient keyed by md5(PAT) so that rotating the token
	 * automatically invalidates the cache. Archived repos are filtered out.
	 *
	 * Returns a flat list of `[ 'identifier' => 'owner/repo', 'owner' => ..., 'name' => ... ]`
	 * sorted alphabetically by owner, then by name.
	 *
	 * @param bool $force_refresh Bypass the transient cache when true.
	 * @return array<int, array{identifier: string, owner: string, name: string}>|\WP_Error
	 */
	public function list_accessible_repos( bool $force_refresh = false ): array|\WP_Error {
		$pat = $this->settings->get_github_pat();
		if ( '' === $pat ) {
			return new \WP_Error(
				'github_no_pat',
				__( 'A GitHub Personal Access Token is required to list accessible repositories.', 'auto-release-posts-for-github' )
			);
		}

		$cache_key = Cache_Keys::user_repos( $pat );

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$args     = $this->build_request_args();
		$repos    = [];
		$page     = 1;
		$max_page = 10; // Safety cap: 1,000 repos.

		while ( $page <= $max_page ) {
			$url = sprintf(
				'%s/user/repos?per_page=100&sort=full_name&affiliation=owner,collaborator,organization_member&page=%d',
				self::API_BASE,
				$page
			);

			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$rate_limit = $this->handle_rate_limit( $response );
			if ( is_wp_error( $rate_limit ) ) {
				return $rate_limit;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 401 === $code ) {
				return new \WP_Error(
					'github_unauthorized',
					__( 'GitHub rejected the Personal Access Token. Check that it is valid and has not expired.', 'auto-release-posts-for-github' )
				);
			}
			if ( 200 !== $code ) {
				return new \WP_Error(
					'github_http_error',
					sprintf(
						/* translators: %d: HTTP status code */
						__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
						$code
					)
				);
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $data ) ) {
				return new \WP_Error( 'github_parse_error', __( 'Failed to parse GitHub API response.', 'auto-release-posts-for-github' ) );
			}

			foreach ( $data as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}

				$full_name = (string) ( $entry['full_name'] ?? '' );
				$owner     = (string) ( $entry['owner']['login'] ?? '' );
				$name      = (string) ( $entry['name'] ?? '' );

				// Skip archived repos and "meta" repos like `.github` whose
				// name starts with a dot — they almost never have releases.
				$skip = ! empty( $entry['archived'] ) || ( '' !== $name && '.' === $name[0] );

				/**
				 * Filters whether to skip a repository in the accessible-repos list.
				 *
				 * Receives the GitHub API response entry. Return true to exclude it
				 * from the picker. Defaults to true for archived repos and for
				 * repos whose name starts with `.` (e.g. org community-health repos).
				 *
				 * @param bool                 $skip  Whether to skip this repo.
				 * @param array<string, mixed> $entry Raw repo entry from GitHub.
				 */
				if ( (bool) apply_filters( 'ghrp_skip_accessible_repo', $skip, $entry ) ) {
					continue;
				}

				if ( '' === $full_name || '' === $owner || '' === $name ) {
					continue;
				}

				$repos[] = [
					'identifier' => $full_name,
					'owner'      => $owner,
					'name'       => $name,
				];
			}

			// Stop when the page returned fewer than the page size.
			if ( count( $data ) < 100 ) {
				break;
			}

			++$page;
		}

		usort(
			$repos,
			static function ( $a, $b ) {
				$cmp = strcasecmp( $a['owner'], $b['owner'] );
				return 0 !== $cmp ? $cmp : strcasecmp( $a['name'], $b['name'] );
			}
		);

		set_transient( $cache_key, $repos, DAY_IN_SECONDS );

		return $repos;
	}

	/**
	 * Deletes the cached accessible-repos list for the active PAT, if any.
	 *
	 * @return void
	 */
	public function clear_accessible_repos_cache(): void {
		$pat = $this->settings->get_github_pat();
		if ( '' === $pat ) {
			return;
		}
		delete_transient( Cache_Keys::user_repos( $pat ) );
	}

	/**
	 * Lightweight check that a PAT is accepted by GitHub.
	 *
	 * Hits `GET /user` — the cheapest authenticated endpoint — and returns
	 * true on success, WP_Error on any failure (network, rate-limit, 401, etc.).
	 * The optional `$pat` argument lets callers validate a value before it
	 * has been saved (e.g. a tab-out check on the Settings field). When null,
	 * the active configured PAT is used.
	 *
	 * No caching here — caller decides cache scope.
	 *
	 * @param string|null $pat Override token to test; null uses the stored PAT.
	 * @return true|\WP_Error
	 */
	public function validate_pat( ?string $pat = null ): true|\WP_Error {
		$pat = $pat ?? $this->settings->get_github_pat();
		if ( '' === $pat ) {
			return new \WP_Error(
				'github_no_pat',
				__( 'No Personal Access Token configured.', 'auto-release-posts-for-github' )
			);
		}

		$args                             = $this->build_request_args();
		$args['headers']['Authorization'] = 'Bearer ' . $pat;
		$args['timeout']                  = 10;

		$response = wp_remote_get( self::API_BASE . '/user', $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return true;
		}
		if ( 401 === $code ) {
			return new \WP_Error(
				'github_unauthorized',
				__( 'Invalid or expired token.', 'auto-release-posts-for-github' )
			);
		}
		return new \WP_Error(
			'github_http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'GitHub API returned HTTP %d.', 'auto-release-posts-for-github' ),
				$code
			)
		);
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
