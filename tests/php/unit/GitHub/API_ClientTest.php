<?php
/**
 * Tests for GitHub\API_Client.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\GitHub\Release;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Global_Settings;
use WP_Mock\Tools\TestCase;

/**
 * Unit tests for API_Client covering all 15 acceptance criteria.
 *
 * HTTP calls are mocked via WP_Mock. No live network requests are made.
 */
class API_ClientTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		// Stampede lock — default to "we acquired it" / "delete succeeds".
		// Tests that exercise the contended path override these.
		\WP_Mock::userFunction( 'wp_cache_add' )->andReturn( true )->byDefault();
		\WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true )->byDefault();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Returns a minimal valid GitHub /releases/latest response payload.
	 *
	 * @return array<string, mixed>
	 */
	private function valid_release_payload(): array {
		return [
			'tag_name'     => 'v1.2.3',
			'name'         => 'Version 1.2.3',
			'body'         => '- Fix a bug',
			'published_at' => '2026-03-21T10:00:00Z',
			'html_url'     => 'https://github.com/10up/plugin/releases/tag/v1.2.3',
			'assets'       => [],
		];
	}

	/**
	 * Builds a mock wp_remote_get() response array.
	 *
	 * wp_remote_retrieve_header() and wp_remote_retrieve_response_code() are
	 * mocked separately, so the response structure here is minimal.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @return array<string, mixed>
	 */
	private function mock_response( int $code, string $body = '' ): array {
		return [
			'response' => [ 'code' => $code, 'message' => '' ],
			'body'     => $body,
			'headers'  => [],
		];
	}

	/**
	 * Creates a Global_Settings mock that returns the given PAT.
	 *
	 * @param string $pat Token value, or '' for no PAT.
	 * @return Global_Settings&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function settings_mock( string $pat = '' ): Global_Settings {
		$mock = $this->createMock( Global_Settings::class );
		$mock->method( 'get_github_pat' )->willReturn( $pat );
		return $mock;
	}

	// -------------------------------------------------------------------------
	// AC-001: Returns Release for valid public owner/repo
	// -------------------------------------------------------------------------

	/**
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_returns_release_for_valid_repo(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client  = new API_Client( $this->settings_mock() );
		$release = $client->fetch_latest_release( '10up/plugin' );

		$this->assertInstanceOf( Release::class, $release );
		$this->assertSame( 'v1.2.3', $release->tag );
		$this->assertSame( 'Version 1.2.3', $release->name );
	}

	// -------------------------------------------------------------------------
	// AC-002: Normalises full GitHub URL
	// -------------------------------------------------------------------------

	/**
	 * A full GitHub URL is normalised and fetched as owner/repo.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_normalises_full_github_url(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )
			->with(
				'https://api.github.com/repos/10up/plugin/releases/latest',
				\WP_Mock\Functions::type( 'array' )
			)
			->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client  = new API_Client( $this->settings_mock() );
		$release = $client->fetch_latest_release( 'https://github.com/10up/plugin' );

		$this->assertInstanceOf( Release::class, $release );
	}

	// -------------------------------------------------------------------------
	// AC-003: 404 returns null (no releases)
	// -------------------------------------------------------------------------

	/**
	 * HTTP 404 returns null — not a WP_Error.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_returns_null_for_404(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 404 ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 404 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_release( '10up/plugin-with-no-releases' );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// AC-004: 403 returns WP_Error with descriptive message
	// -------------------------------------------------------------------------

	/**
	 * HTTP 403 (private/auth issue) returns WP_Error.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_returns_wp_error_for_403(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 403 ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 403 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_release( '10up/private-plugin' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'github_forbidden', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// AC-005: Transient cache prevents duplicate HTTP calls
	// -------------------------------------------------------------------------

	/**
	 * A cached Release is returned without making a second HTTP call.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_uses_cached_transient_on_second_call(): void {
		$cached_release = Release::from_api_response( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )
			->with( Cache_Keys::release( '10up/plugin' ) )
			->andReturn( $cached_release );

		// wp_remote_get must NOT be called.
		\WP_Mock::userFunction( 'wp_remote_get' )->never();

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_release( '10up/plugin' );

		$this->assertSame( $cached_release, $result );
	}

	// -------------------------------------------------------------------------
	// AC-006: Authorization header present when PAT configured
	// -------------------------------------------------------------------------

	/**
	 * When a PAT is set, the Authorization: Bearer header is included.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_includes_authorization_header_when_pat_set(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )
			->with(
				\WP_Mock\Functions::anyOf( 'https://api.github.com/repos/10up/plugin/releases/latest' ),
				\WP_Mock\Functions::type( 'array' )
			)
			->andReturnUsing(
				function ( string $url, array $args ) use ( $body ) {
					$this->assertArrayHasKey( 'Authorization', $args['headers'] );
					$this->assertStringStartsWith( 'Bearer ', $args['headers']['Authorization'] );
					// Confirm the actual token value is in the header.
					$this->assertStringContainsString( 'ghp_test_token', $args['headers']['Authorization'] );
					return $this->mock_response( 200, $body );
				}
			);
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '4999' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock( 'ghp_test_token' ) );
		$client->fetch_latest_release( '10up/plugin' );
	}

	// -------------------------------------------------------------------------
	// AC-007: No Authorization header when no PAT configured
	// -------------------------------------------------------------------------

	/**
	 * When no PAT is set, the Authorization header is absent.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_no_authorization_header_when_pat_empty(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )
			->andReturnUsing(
				function ( string $url, array $args ) use ( $body ) {
					$this->assertArrayNotHasKey( 'Authorization', $args['headers'] );
					return $this->mock_response( 200, $body );
				}
			);
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock( '' ) );
		$client->fetch_latest_release( '10up/plugin' );
	}

	// -------------------------------------------------------------------------
	// AC-008: PAT value never appears in error messages
	// -------------------------------------------------------------------------

	/**
	 * WP_Error messages do not contain the PAT value.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_pat_not_in_error_messages(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 403 ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 403 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '' );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$secret_pat = 'ghp_super_secret_token_12345';
		$client     = new API_Client( $this->settings_mock( $secret_pat ) );
		$result     = $client->fetch_latest_release( '10up/private-plugin' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertStringNotContainsString( $secret_pat, $result->get_error_message() );
	}

	// -------------------------------------------------------------------------
	// AC-009: Rate limit header recorded after each response
	// -------------------------------------------------------------------------

	/**
	 * X-RateLimit-Remaining header is stored in a transient after each response.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_stores_rate_limit_remaining_header(): void {
		$body             = json_encode( $this->valid_release_payload() );
		$rate_limit_saved = false;

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '42' );
		\WP_Mock::userFunction( 'set_transient' )
			->andReturnUsing(
				function ( string $key, mixed $value ) use ( &$rate_limit_saved ) {
					if ( $key === Cache_Keys::rate_limit_remaining() ) {
						$this->assertSame( 42, $value );
						$rate_limit_saved = true;
					}
					return true;
				}
			);

		$client = new API_Client( $this->settings_mock() );
		$client->fetch_latest_release( '10up/plugin' );

		$this->assertTrue( $rate_limit_saved, 'Rate limit remaining should be saved to transient' );
	}

	// -------------------------------------------------------------------------
	// AC-010: Rate limit exhaustion schedules retry event
	// -------------------------------------------------------------------------

	/**
	 * When X-RateLimit-Remaining is 0 on a successful (200) response, a one-time
	 * retry is still scheduled — but the response is used, not discarded. GitHub
	 * reports the remaining count *after* serving the request, so the call that
	 * consumes the last token still carries the release we asked for.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_schedules_retry_on_rate_limit_exhaustion(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )
			->with( \WP_Mock\Functions::type( 'array' ), 'x-ratelimit-remaining' )
			->andReturn( '0' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY )
			->andReturn( false );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )
			->with(
				\WP_Mock\Functions::type( 'int' ),
				Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY
			)
			->once();
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_release( '10up/plugin' );

		// The retry is scheduled (asserted via ->once() above), yet the successful
		// response is returned rather than thrown away.
		$this->assertInstanceOf( Release::class, $result );
		$this->assertSame( 'v1.2.3', $result->tag );
	}

	// -------------------------------------------------------------------------
	// AC-011: Rate limit exhaustion is never fatal
	// -------------------------------------------------------------------------

	/**
	 * A genuine rate-limit rejection (a 403 with zero remaining) returns a
	 * WP_Error — and never throws an exception.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_rate_limit_exhaustion_returns_wp_error_not_exception(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 403, '{}' ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 403 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '0' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		try {
			$client = new API_Client( $this->settings_mock() );
			$result = $client->fetch_latest_release( '10up/plugin' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'github_rate_limit_exhausted', $result->get_error_code() );
		} catch ( \Throwable $e ) {
			$this->fail( 'Rate limit exhaustion should never throw. Got: ' . $e->getMessage() );
		}
	}

	// -------------------------------------------------------------------------
	// Stampede lock — contended path returns the cached value from the winner
	// -------------------------------------------------------------------------

	/**
	 * When wp_cache_add returns false (another process holds the lock) and the
	 * transient appears on retry, the contender should return that value
	 * without firing its own wp_remote_get.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_contended_fetch_returns_winner_cached_value(): void {
		$release = new Release(
			tag:          'v9.9.9',
			name:         'Cached',
			body:         '',
			html_url:     'https://github.com/10up/plugin/releases/tag/v9.9.9',
			published_at: '2026-04-01T00:00:00Z',
			assets:       [],
		);

		// First get_transient (pre-lock): miss.
		// Second get_transient (post-sleep, lock holder finished): hit.
		\WP_Mock::userFunction( 'get_transient' )
			->andReturnValues( [ false, $release ] );

		\WP_Mock::userFunction( 'wp_cache_add' )->once()->andReturn( false );

		// wp_remote_get must NOT be called — the contender returns the winner's value.
		\WP_Mock::userFunction( 'wp_remote_get' )->never();

		// Lock was not owned, so wp_cache_delete must not run either.
		\WP_Mock::userFunction( 'wp_cache_delete' )->never();

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_release( '10up/plugin' );

		$this->assertInstanceOf( Release::class, $result );
		$this->assertSame( 'v9.9.9', $result->tag );
	}

	// -------------------------------------------------------------------------
	// fetch_releases() — list endpoint
	// -------------------------------------------------------------------------

	/**
	 * @covers API_Client::fetch_releases
	 */
	public function test_fetch_releases_returns_release_array_filtered(): void {
		$payload = [
			[
				'tag_name'     => 'v3.0.0',
				'name'         => 'v3.0.0',
				'published_at' => '2026-04-01T00:00:00Z',
				'html_url'     => 'https://github.com/10up/plugin/releases/tag/v3.0.0',
				'body'         => '',
				'assets'       => [],
				'draft'        => false,
				'prerelease'   => false,
			],
			[
				'tag_name'     => 'v3.0.0-rc1',
				'name'         => 'v3.0.0-rc1',
				'published_at' => '2026-03-15T00:00:00Z',
				'html_url'     => 'https://github.com/10up/plugin/releases/tag/v3.0.0-rc1',
				'body'         => '',
				'assets'       => [],
				'draft'        => false,
				'prerelease'   => true, // Should be skipped.
			],
			[
				'tag_name'     => 'v2.9.0',
				'name'         => 'v2.9.0',
				'published_at' => '2026-02-01T00:00:00Z',
				'html_url'     => 'https://github.com/10up/plugin/releases/tag/v2.9.0',
				'body'         => '',
				'assets'       => [],
			],
		];
		$body = json_encode( $payload );

		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_releases( '10up/plugin' );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertSame( 'v3.0.0', $result[0]->tag );
		$this->assertSame( 'v2.9.0', $result[1]->tag );
	}

	/**
	 * @covers API_Client::fetch_releases
	 */
	public function test_fetch_releases_returns_empty_array_for_404(): void {
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 404 ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 404 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_releases( '10up/plugin' );

		$this->assertSame( [], $result );
	}

	/**
	 * With pre-releases included, the eligible "latest" is the highest *version*,
	 * not GitHub's most-recently-published entry — so a backport published after a
	 * newer release does not win.
	 *
	 * @covers API_Client::fetch_latest_eligible_release
	 */
	public function test_fetch_latest_eligible_release_picks_highest_version_not_newest_by_date(): void {
		// GitHub returns /releases in created_at-descending order: the backport
		// v1.9.6 was published most recently, but v2.0.0 is the higher version.
		$payload = [
			[
				'tag_name'     => 'v1.9.6',
				'name'         => 'v1.9.6',
				'published_at' => '2026-04-01T00:00:00Z',
				'html_url'     => 'https://github.com/10up/plugin/releases/tag/v1.9.6',
				'body'         => '',
				'assets'       => [],
				'draft'        => false,
				'prerelease'   => false,
			],
			[
				'tag_name'     => 'v2.0.0',
				'name'         => 'v2.0.0',
				'published_at' => '2026-03-01T00:00:00Z',
				'html_url'     => 'https://github.com/10up/plugin/releases/tag/v2.0.0',
				'body'         => '',
				'assets'       => [],
				'draft'        => false,
				'prerelease'   => false,
			],
		];
		$body = json_encode( $payload );

		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_eligible_release( '10up/plugin', true );

		$this->assertInstanceOf( Release::class, $result );
		$this->assertSame( 'v2.0.0', $result->tag );
	}

	// -------------------------------------------------------------------------
	// fetch_issue() — validates the (untrusted) identifier before requesting
	// -------------------------------------------------------------------------

	/**
	 * A multi-segment / traversing identifier (from a crafted release-note link)
	 * is rejected before any authenticated request is made with the PAT.
	 *
	 * @covers API_Client::fetch_issue
	 */
	public function test_fetch_issue_rejects_path_traversal_identifier(): void {
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_remote_get' )->never();

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_issue( 'x/y/../../../../gists/SECRET', 1 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'github_invalid_identifier', $result->get_error_code() );
	}

	/**
	 * A single-segment dot identifier ("owner/..") passes the format check but is
	 * still a path traversal, so fetch_issue() must reject it without requesting.
	 *
	 * @covers API_Client::fetch_issue
	 */
	public function test_fetch_issue_rejects_dot_segment_identifier(): void {
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'wp_remote_get' )->never();

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_issue( 'owner/..', 5 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'github_invalid_identifier', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// build_request_args() — token transmission
	// -------------------------------------------------------------------------

	/**
	 * Authenticated requests must not follow redirects: WordPress replays the
	 * Authorization header (the PAT) to the redirect target.
	 *
	 * @covers API_Client::build_request_args
	 */
	public function test_build_request_args_disables_redirects(): void {
		$client = new API_Client( $this->settings_mock( 'ghp_secret' ) );

		$method = new \ReflectionMethod( API_Client::class, 'build_request_args' );
		$args   = $method->invoke( $client );

		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( 'Bearer ghp_secret', $args['headers']['Authorization'] );
	}

	// -------------------------------------------------------------------------
	// fetch_release_by_tag()
	// -------------------------------------------------------------------------

	/**
	 * @covers API_Client::fetch_release_by_tag
	 */
	public function test_fetch_release_by_tag_returns_release(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client  = new API_Client( $this->settings_mock() );
		$release = $client->fetch_release_by_tag( '10up/plugin', 'v1.2.3' );

		$this->assertInstanceOf( Release::class, $release );
		$this->assertSame( 'v1.2.3', $release->tag );
	}

	/**
	 * @covers API_Client::fetch_release_by_tag
	 */
	public function test_fetch_release_by_tag_returns_null_for_unknown_tag(): void {
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 404 ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 404 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_release_by_tag( '10up/plugin', 'v9.9.9' );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// Tag patterns (monorepo package selection)
	// -------------------------------------------------------------------------

	/**
	 * Builds the 10up/headstartwp fixture release list from the feature brief:
	 * a monorepo stream mixing core/next releases with utility packages and
	 * correctly flagged prereleases. Newest-first, as GitHub returns it.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function headstartwp_fixture(): array {
		$rows = [
			[ '@headstartwp/core@1.6.1', '2026-04-08T00:00:00Z', false ],
			[ '@headstartwp/next@1.5.1', '2025-09-22T00:00:00Z', false ],
			[ '@headstartwp/core@1.6.0', '2025-07-29T00:00:00Z', false ],
			[ '@headstartwp/next@1.5.0', '2025-07-04T00:00:00Z', false ],
			[ '@headstartwp/epio-search@1.0.0', '2025-07-04T00:00:00Z', false ],
			[ '@headstartwp/core@1.5.0', '2025-07-04T00:00:00Z', false ],
			[ '@headstartwp/block-primitives@0.1.0', '2025-07-04T00:00:00Z', false ],
			[ '@10up/next-redis-cache-provider@2.0.0', '2025-07-04T00:00:00Z', false ],
			[ '@headstartwp/next@1.5.0-next.16', '2025-07-03T00:00:00Z', true ],
			[ '@headstartwp/core@1.5.0-next.12', '2025-07-03T00:00:00Z', true ],
		];

		return array_map(
			static fn( array $row ): array => [
				'tag_name'     => $row[0],
				'name'         => $row[0],
				'published_at' => $row[1],
				'html_url'     => 'https://github.com/10up/headstartwp/releases/tag/' . rawurlencode( $row[0] ),
				'body'         => '',
				'assets'       => [],
				'draft'        => false,
				'prerelease'   => $row[2],
			],
			$rows
		);
	}

	/**
	 * fetch_releases() with tag patterns returns only matching stable tags:
	 * utility packages are excluded by pattern, prereleases by the existing
	 * flag check (the two filters compose).
	 *
	 * @covers API_Client::fetch_releases
	 */
	public function test_fetch_releases_filters_by_tag_patterns(): void {
		$body = json_encode( $this->headstartwp_fixture() );

		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_releases( '10up/headstartwp', false, '@headstartwp/core@*, @headstartwp/next@*' );

		$this->assertIsArray( $result );
		$this->assertSame(
			[
				'@headstartwp/core@1.6.1',
				'@headstartwp/next@1.5.1',
				'@headstartwp/core@1.6.0',
				'@headstartwp/next@1.5.0',
				'@headstartwp/core@1.5.0',
			],
			array_map( static fn( Release $release ): string => $release->tag, $result )
		);
	}

	/**
	 * With patterns set, the latest-eligible lookup must use the paginated
	 * /releases list (the /releases/latest endpoint cannot honor patterns and
	 * would return e.g. an epio-search release) and pick the newest matching
	 * entry — @headstartwp/core@1.6.1 per the fixture.
	 *
	 * @covers API_Client::fetch_latest_eligible_release
	 */
	public function test_fetch_latest_eligible_release_with_patterns_uses_list_endpoint(): void {
		$body = json_encode( $this->headstartwp_fixture() );

		\WP_Mock::userFunction( 'wp_remote_get' )
			->with(
				'https://api.github.com/repos/10up/headstartwp/releases?per_page=100',
				\WP_Mock\Functions::type( 'array' )
			)
			->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_eligible_release( '10up/headstartwp', false, '@headstartwp/core@*, @headstartwp/next@*' );

		$this->assertInstanceOf( Release::class, $result );
		$this->assertSame( '@headstartwp/core@1.6.1', $result->tag );
	}

	/**
	 * Without patterns (and without the prerelease opt-in) the fast, cached
	 * /releases/latest endpoint is still used — byte-for-byte the pre-feature
	 * behavior.
	 *
	 * @covers API_Client::fetch_latest_eligible_release
	 */
	public function test_fetch_latest_eligible_release_without_patterns_uses_latest_endpoint(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )
			->with(
				'https://api.github.com/repos/10up/plugin/releases/latest',
				\WP_Mock\Functions::type( 'array' )
			)
			->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '100' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$client = new API_Client( $this->settings_mock() );
		$result = $client->fetch_latest_eligible_release( '10up/plugin', false, '  ,  ' );

		$this->assertInstanceOf( Release::class, $result );
		$this->assertSame( 'v1.2.3', $result->tag );
	}
}
