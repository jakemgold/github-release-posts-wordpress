<?php
/**
 * Tests for the Cache_Keys registry.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests;

use GitHubReleasePosts\Cache_Keys;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GitHubReleasePosts\Cache_Keys
 */
class Cache_KeysTest extends TestCase {

	public function test_release_uses_md5_of_identifier(): void {
		$this->assertSame( 'ghrp_rel_' . md5( 'owner/repo' ), Cache_Keys::release( 'owner/repo' ) );
	}

	public function test_release_fetch_lock_is_distinct_from_release_cache(): void {
		$cache = Cache_Keys::release( 'owner/repo' );
		$lock  = Cache_Keys::release_fetch_lock( 'owner/repo' );

		$this->assertNotSame( $cache, $lock );
		$this->assertStringStartsWith( 'ghrp_rel_lock_', $lock );
	}

	public function test_ai_response_combines_identifier_and_tag(): void {
		$this->assertSame(
			'ghrp_ai_resp_' . md5( 'owner/repo' . 'v1.0.0' ),
			Cache_Keys::ai_response( 'owner/repo', 'v1.0.0' )
		);

		// Different tags should hash to different keys.
		$this->assertNotSame(
			Cache_Keys::ai_response( 'owner/repo', 'v1.0.0' ),
			Cache_Keys::ai_response( 'owner/repo', 'v1.0.1' )
		);
	}

	public function test_admin_keys_are_per_user(): void {
		$this->assertSame( 'ghrp_admin_errors_42', Cache_Keys::admin_errors( 42 ) );
		$this->assertSame( 'ghrp_admin_notice_42', Cache_Keys::admin_notice( 42 ) );
		$this->assertNotSame( Cache_Keys::admin_errors( 42 ), Cache_Keys::admin_errors( 43 ) );
	}

	public function test_flat_keys_are_stable_strings(): void {
		// Uninstall.php relies on a `_transient_ghrp_%` wildcard — every key
		// must keep the `ghrp_` prefix.
		$keys = [
			Cache_Keys::rate_limit_remaining(),
			Cache_Keys::ai_failure_notice(),
			Cache_Keys::cron_results(),
			Cache_Keys::cron_lock(),
			Cache_Keys::connector_status(),
		];

		foreach ( $keys as $key ) {
			$this->assertStringStartsWith( 'ghrp_', $key, "Key '$key' must start with ghrp_ for uninstall cleanup to catch it." );
		}
	}
}
