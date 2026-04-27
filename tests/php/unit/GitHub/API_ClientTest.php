<?php
/**
 * Tests for GitHub\API_Client.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace Jakemgold\GitHubReleasePosts\Tests\GitHub;

use Jakemgold\GitHubReleasePosts\GitHub\API_Client;
use Jakemgold\GitHubReleasePosts\GitHub\Release;
use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
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
			->with( Plugin_Constants::TRANSIENT_RELEASE_PREFIX . md5( '10up/plugin' ) )
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
					if ( $key === Plugin_Constants::TRANSIENT_RATE_LIMIT_REMAINING ) {
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
	 * When X-RateLimit-Remaining is 0, a one-time retry cron event is scheduled.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_schedules_retry_on_rate_limit_exhaustion(): void {
		$body = json_encode( $this->valid_release_payload() );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, $body ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
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

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'github_rate_limit_exhausted', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// AC-011: Rate limit exhaustion is never fatal
	// -------------------------------------------------------------------------

	/**
	 * Rate limit exhaustion returns WP_Error — it never throws an exception.
	 *
	 * @covers API_Client::fetch_latest_release
	 */
	public function test_rate_limit_exhaustion_returns_wp_error_not_exception(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( $this->mock_response( 200, '{}' ) );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_header' )->andReturn( '0' );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_schedule_single_event' )->andReturn( true );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		try {
			$client = new API_Client( $this->settings_mock() );
			$result = $client->fetch_latest_release( '10up/plugin' );
			$this->assertInstanceOf( \WP_Error::class, $result );
		} catch ( \Throwable $e ) {
			$this->fail( 'Rate limit exhaustion should never throw. Got: ' . $e->getMessage() );
		}
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
}
