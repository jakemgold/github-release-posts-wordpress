<?php
/**
 * Tests for Onboarding_Handler.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\GitHub\Onboarding_Handler;
use GitHubReleasePosts\GitHub\Release;
use GitHubReleasePosts\GitHub\Release_State;
use WP_Mock\Tools\TestCase;

/**
 * Covers the monorepo-aware add flow: cache warm, nudge notice, and the
 * auto-generate suppression for multi-package repositories.
 */
class Onboarding_HandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true )->byDefault();
		// find_post() (dedup lookup) — no existing posts in these scenarios.
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] )->byDefault();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a Release for a tag.
	 *
	 * @param string $tag Release tag.
	 * @return Release
	 */
	private function release( string $tag ): Release {
		return new Release(
			tag:          $tag,
			name:         $tag,
			body:         '',
			html_url:     'https://github.com/o/r/releases/tag/' . rawurlencode( $tag ),
			published_at: '2026-07-01T00:00:00Z',
			assets:       [],
		);
	}

	/**
	 * Adding a monorepo suppresses client auto-generation and surfaces the
	 * package nudge instead: the repo-wide latest could belong to a package
	 * the site never wants, and drafting it while the notice says "choose
	 * packages" would be contradictory.
	 */
	public function test_monorepo_add_suppresses_auto_generate_and_nudges(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_releases' )->willReturn(
			[
				$this->release( '@acme/core@2.0.0' ),
				$this->release( '@acme/utils@1.1.0' ),
			]
		);
		$api->method( 'fetch_latest_eligible_release' )->willReturn( $this->release( '@acme/utils@1.1.0' ) );

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->never() )->method( 'update_last_seen' );

		$outcome = ( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/monorepo-' . uniqid() );

		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertSame( 'info', $outcome['notice']['type'] );
		$this->assertStringContainsString( '2 different packages', $outcome['notice']['message'] );
		$this->assertStringContainsString( 'skipped', $outcome['notice']['message'] );
	}

	/**
	 * A single-package repository keeps the original happy path: client
	 * auto-generation with no admin notice.
	 */
	public function test_single_package_add_keeps_auto_generate(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_releases' )->willReturn( [ $this->release( 'v3.1.0' ) ] );
		$api->method( 'fetch_latest_eligible_release' )->willReturn( $this->release( 'v3.1.0' ) );

		$state = $this->createMock( Release_State::class );

		$outcome = ( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/plugin-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
		$this->assertNull( $outcome['notice'] );
	}
}
