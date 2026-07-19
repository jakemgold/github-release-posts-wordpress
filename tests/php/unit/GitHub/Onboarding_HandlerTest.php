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
		// Monorepo add: baseline stamped WITH per-package cursors (auto-gen is
		// suppressed, so every current release is historical), and the durable
		// topology flag is recorded (round 3).
		$state->expects( $this->once() )->method( 'seed_streams' )->with(
			$this->anything(),
			$this->callback( static fn( array $cursors ): bool => array_keys( $cursors ) === [ '@acme/core', '@acme/utils' ] )
		);
		$state->expects( $this->once() )->method( 'set_monorepo' )->with( $this->anything(), true );

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
		// Single-package add: baseline stamped with NO cursors — the pending
		// latest release must remain generatable by the client auto-trigger
		// or by the cron retry if that fails (round 3).
		$state->expects( $this->once() )->method( 'seed_streams' )->with( $this->anything(), [] );
		$state->expects( $this->once() )->method( 'set_monorepo' )->with( $this->anything(), false );

		$outcome = ( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/plugin-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
		$this->assertNull( $outcome['notice'] );
	}

	/**
	 * Round-5: a transient failure of the onboarding list fetch writes NO
	 * monitoring state at all — no baseline, no topology. An empty baseline
	 * would turn the next successful topology discovery into a one-post-per-
	 * package burst; with no baseline, the next cron routes by actual
	 * topology (single-stream repos enqueue the pending latest, multi-stream
	 * repos run one-time seeding).
	 */
	public function test_list_fetch_failure_writes_no_monitoring_state(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_releases' )->willReturn( new \WP_Error( 'http_request_failed', 'timeout' ) );
		$api->method( 'fetch_latest_eligible_release' )->willReturn( $this->release( 'newborn@1.0.0' ) );

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->never() )->method( 'seed_streams' );
		$state->expects( $this->never() )->method( 'set_monorepo' );

		$outcome = ( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/flaky-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
	}

	/**
	 * Round-6 (revising round 5): one recognized package stream plus plain
	 * repo-wide tags is stream-MONITORED (persisted as such) but not
	 * package-CHOOSABLE — the Packages picker only renders for 2+ recognized
	 * packages, so suppressing the initial draft would promise a chooser that
	 * never appears. The repo keeps main's initial latest-draft behavior;
	 * its NON-latest stream is baselined, and the latest release's stream
	 * stays unseeded for the auto-trigger / cron retry.
	 */
	public function test_one_package_plus_plain_stream_is_stream_monitored(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_releases' )->willReturn(
			[
				new Release( tag: 'v9.0.0', name: 'plain', body: '', html_url: 'https://github.com/acme/mixed/releases/tag/a', published_at: '2026-07-01T00:00:00Z', assets: [] ),
				new Release( tag: '@acme/core@2.0.0', name: 'core', body: '', html_url: 'https://github.com/acme/mixed/releases/tag/b', published_at: '2026-06-01T00:00:00Z', assets: [] ),
			]
		);
		$api->method( 'fetch_latest_eligible_release' )->willReturn( $this->release( 'v9.0.0' ) );

		$state  = $this->createMock( Release_State::class );
		$seeded = null;
		$state->method( 'seed_streams' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);
		$state->expects( $this->once() )->method( 'set_monorepo' )->with( $this->anything(), true );

		$outcome = ( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/mixed-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
		$this->assertNull( $outcome['notice'] );
		// Latest is the plain v9.0.0 → the default stream stays unseeded for
		// generation; the package stream is baselined.
		$this->assertSame( [ '@acme/core' ], array_keys( $seeded ) );
	}

	/**
	 * Round-4: monorepo baselines are seeded from the SAME stream-winner
	 * selection the monitor uses — the default (plain-tag) stream is seeded
	 * too, and a later-created backport never becomes a cursor.
	 */
	public function test_monorepo_seeding_uses_stream_winners(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_releases' )->willReturn(
			[
				new Release( tag: '@acme/core@1.9.6', name: 'backport', body: '', html_url: 'https://github.com/acme/mono/releases/tag/a', published_at: '2026-03-01T00:00:00Z', assets: [] ),
				new Release( tag: 'v9.0.0', name: 'plain', body: '', html_url: 'https://github.com/acme/mono/releases/tag/b', published_at: '2026-07-01T00:00:00Z', assets: [] ),
				new Release( tag: '@acme/core@2.0.0', name: 'core2', body: '', html_url: 'https://github.com/acme/mono/releases/tag/c', published_at: '2026-01-01T00:00:00Z', assets: [] ),
				new Release( tag: '@acme/next@1.0.0', name: 'next1', body: '', html_url: 'https://github.com/acme/mono/releases/tag/d', published_at: '2026-02-01T00:00:00Z', assets: [] ),
			]
		);
		$api->method( 'fetch_latest_eligible_release' )->willReturn( $this->release( 'v9.0.0' ) );

		$state  = $this->createMock( Release_State::class );
		$seeded = null;
		$state->method( 'seed_streams' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		( new Onboarding_Handler( $api, $state ) )->handle_add( 'acme/mono-winners-' . uniqid() );

		$this->assertNotNull( $seeded );
		$keys = array_keys( $seeded );
		sort( $keys );
		$this->assertSame( [ '', '@acme/core', '@acme/next' ], $keys );
		$this->assertSame( '@acme/core@2.0.0', $seeded['@acme/core']['last_seen_tag'] );
		$this->assertSame( 'v9.0.0', $seeded['']['last_seen_tag'] );
	}
}
