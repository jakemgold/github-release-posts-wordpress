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
use GitHubReleasePosts\GitHub\Release_Monitor;
use GitHubReleasePosts\GitHub\Release_Selector;
use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * Covers the fresh-add lifecycle transition: one snapshot, the onboarding
 * matrix (empty / single / mixed / package-choice), pending-on-failure, and
 * the discovery-vs-monitoring projection split.
 */
class Onboarding_HandlerTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true )->byDefault();
		// find_post() (dedup lookup) — no existing posts unless a test overrides.
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] )->byDefault();
	}

	public function tearDown(): void {
		Release_Monitor::reset_find_post_cache();
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a Release for a tag.
	 *
	 * @param string $tag          Release tag.
	 * @param string $published_at Publication timestamp.
	 * @param bool   $prerelease   GitHub pre-release flag.
	 * @return Release
	 */
	private function release( string $tag, string $published_at = '2026-07-01T00:00:00Z', bool $prerelease = false ): Release {
		return new Release(
			tag:          $tag,
			name:         $tag,
			body:         '',
			html_url:     'https://github.com/o/r/releases/tag/' . rawurlencode( $tag ),
			published_at: $published_at,
			assets:       [],
			prerelease:   $prerelease,
		);
	}

	/**
	 * Builds the handler with a Repository_Settings mock returning the given
	 * per-repo config (defaults: pre-releases off, no patterns).
	 *
	 * @param API_Client    $api    API client mock.
	 * @param Release_State $state  State mock.
	 * @param array         $config Repo config returned by get_repository().
	 * @return Onboarding_Handler
	 */
	private function handler( API_Client $api, Release_State $state, array $config = [] ): Onboarding_Handler {
		$repo_settings = $this->createMock( Repository_Settings::class );
		$repo_settings->method( 'get_repository' )->willReturn(
			array_merge(
				[
					'include_prereleases' => false,
					'tag_patterns'        => '',
				],
				$config
			)
		);

		return new Onboarding_Handler( $api, $state, $repo_settings );
	}

	/**
	 * The pending marker is persisted BEFORE the snapshot request, and a
	 * failed snapshot resolves nothing: no baseline is written, the warning
	 * notice promises a retry, and the repository stays pending for the cron
	 * to rerun the full onboarding transition.
	 */
	public function test_snapshot_failure_leaves_repository_pending(): void {
		$order = [];

		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturnCallback(
			function () use ( &$order ) {
				$order[] = 'snapshot';
				return new \WP_Error( 'http_request_failed', 'timeout' );
			}
		);

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'mark_onboarding_pending' )->willReturnCallback(
			function () use ( &$order ): void {
				$order[] = 'pending';
			}
		);
		$state->expects( $this->never() )->method( 'complete_baseline' );
		$state->expects( $this->never() )->method( 'update_last_seen' );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/flaky-' . uniqid() );

		$this->assertSame( [ 'pending', 'snapshot' ], $order );
		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertSame( 'warning', $outcome['notice']['type'] );
		$this->assertStringContainsString( 'retried', $outcome['notice']['message'] );
	}

	/**
	 * Adding a repo with 2+ recognized packages suppresses client
	 * auto-generation (the chooser actually renders for this topology),
	 * baselines EVERY eligible stream head, and surfaces the package nudge.
	 */
	public function test_two_package_add_suppresses_and_baselines_all_winners(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->release( '@acme/core@2.0.0', '2026-07-01T00:00:00Z' ),
				$this->release( '@acme/utils@1.1.0', '2026-06-01T00:00:00Z' ),
			]
		);

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'complete_baseline' )->with(
			$this->anything(),
			$this->callback(
				static fn( array $cursors ): bool => array_keys( $cursors ) === [ '@acme/core', '@acme/utils' ]
			),
			Release_Selector::policy_hash( false, '' )
		);
		$state->expects( $this->never() )->method( 'update_last_seen' );
		// The multi-package observation is persisted at add time so package
		// naming engages without the admin ever opening the chooser.
		$state->expects( $this->once() )->method( 'mark_multi_package' );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/monorepo-' . uniqid() );

		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertSame( 'info', $outcome['notice']['type'] );
		$this->assertStringContainsString( '2 different packages', $outcome['notice']['message'] );
		$this->assertStringContainsString( 'skipped', $outcome['notice']['message'] );
	}

	/**
	 * A single-stream repository keeps the original happy path: client
	 * auto-generation with no admin notice. Its only stream is the initial
	 * release's own, so the baseline is written with NO cursors — the absent
	 * cursor is what lets the cron generate the release if the client fails.
	 */
	public function test_single_package_add_auto_triggers_with_empty_baseline(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn( [ $this->release( 'v3.1.0' ) ] );

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'complete_baseline' )->with( $this->anything(), [], $this->anything() );
		$state->expects( $this->never() )->method( 'update_last_seen' );
		// One recognized package is not a multi-package topology — the naming
		// observation must NOT be recorded for single-stream repositories.
		$state->expects( $this->never() )->method( 'mark_multi_package' );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/plugin-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
		$this->assertNull( $outcome['notice'] );
	}

	/**
	 * One recognized package stream plus plain repo-wide tags: the Packages
	 * chooser does NOT render for this topology (it needs 2+ recognized
	 * packages), so the initial draft is not suppressed. The latest release
	 * (the plain tag) auto-triggers; the package stream is baselined.
	 */
	public function test_one_package_plus_plain_add_keeps_initial_draft(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->release( 'v9.0.0', '2026-07-01T00:00:00Z' ),
				$this->release( '@acme/core@2.0.0', '2026-06-01T00:00:00Z' ),
			]
		);

		$state  = $this->createMock( Release_State::class );
		$seeded = null;
		$state->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);
		// Mixed one-package-plus-plain is stream-monitored but not
		// multi-PACKAGE — the naming observation stays unset.
		$state->expects( $this->never() )->method( 'mark_multi_package' );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/mixed-' . uniqid() );

		$this->assertTrue( $outcome['auto_trigger'] );
		$this->assertNull( $outcome['notice'] );
		// Latest is the plain v9.0.0 → the default stream stays uncursored for
		// generation; the package stream is baselined.
		$this->assertSame( [ '@acme/core' ], array_keys( $seeded ) );
	}

	/**
	 * REGRESSION (design-doc "round 7"): monitoring cursors must come from
	 * the MONITORING projection, never the pre-release-inclusive discovery
	 * list. With pre-releases off, a package whose newest release is a beta
	 * must be baselined at its newest STABLE release — a beta cursor would
	 * silently swallow the next stable release below that version.
	 */
	public function test_cursors_come_from_stable_projection_not_discovery(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->release( '@acme/core@3.0.0-beta', '2026-07-10T00:00:00Z', true ),
				$this->release( '@acme/core@2.0.0', '2026-06-01T00:00:00Z' ),
				$this->release( '@acme/utils@1.0.0', '2026-05-01T00:00:00Z' ),
			]
		);

		$state  = $this->createMock( Release_State::class );
		$seeded = null;
		$state->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/prerelease-mono-' . uniqid() );

		// Discovery still sees both packages → chooser topology → suppressed.
		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertNotNull( $seeded );
		$this->assertSame( '@acme/core@2.0.0', $seeded['@acme/core']['last_seen_tag'] );
		$this->assertSame( '@acme/utils@1.0.0', $seeded['@acme/utils']['last_seen_tag'] );
	}

	/**
	 * A repo with only pre-releases (pre-releases off) completes onboarding
	 * with an EMPTY baseline — nothing is eligible, nothing is cursored — and
	 * the notice explains how to opt in.
	 */
	public function test_prerelease_only_repo_baselines_empty_and_explains(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn(
			[ $this->release( 'v1.0.0-beta.1', '2026-07-01T00:00:00Z', true ) ]
		);

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'complete_baseline' )->with( $this->anything(), [], $this->anything() );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/beta-only-' . uniqid() );

		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertSame( 'success', $outcome['notice']['type'] );
		$this->assertStringContainsString( 'pre-release', $outcome['notice']['message'] );
	}

	/**
	 * A repo with no releases at all completes onboarding with an empty ready
	 * baseline; the first later release generates through normal monitoring.
	 */
	public function test_no_releases_add_promises_first_release(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn( [] );

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'complete_baseline' )->with( $this->anything(), [], $this->anything() );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/newborn-' . uniqid() );

		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertStringContainsString( 'No releases yet', $outcome['notice']['message'] );
	}

	/**
	 * When a post already exists for the initial release, both the display
	 * cursor and the stream cursor advance (no in-flight generation can
	 * fail), and the notice links to the existing post.
	 */
	public function test_existing_post_for_initial_advances_cursors(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ new \WP_Post( (object) [ 'ID' => 41 ] ) ] );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.test/edit/41' );

		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn( [ $this->release( 'v2.0.0' ) ] );

		$state = $this->createMock( Release_State::class );
		$state->expects( $this->once() )->method( 'update_last_seen' )->with( $this->anything(), 'v2.0.0', '2026-07-01T00:00:00Z' );
		$state->expects( $this->once() )->method( 'update_stream_seen' )->with( $this->anything(), '', 'v2.0.0', '2026-07-01T00:00:00Z' );

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/existing-' . uniqid() );

		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertStringContainsString( 'already exists', $outcome['notice']['message'] );
	}

	/**
	 * Baselines come from stream WINNERS: a later-published backport never
	 * becomes a cursor, and every eligible stream head is represented (the
	 * default stream included when a package choice is offered).
	 */
	public function test_baseline_uses_stream_winners_not_list_order(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->release( '@acme/core@1.9.6', '2026-03-01T00:00:00Z' ),
				$this->release( 'v9.0.0', '2026-07-01T00:00:00Z' ),
				$this->release( '@acme/core@2.0.0', '2026-01-01T00:00:00Z' ),
				$this->release( '@acme/next@1.0.0', '2026-02-01T00:00:00Z' ),
			]
		);

		$state  = $this->createMock( Release_State::class );
		$seeded = null;
		$state->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		$outcome = $this->handler( $api, $state )->handle_add( 'acme/mono-winners-' . uniqid() );

		// Two recognized packages → chooser topology → suppressed, all streams cursored.
		$this->assertFalse( $outcome['auto_trigger'] );
		$this->assertNotNull( $seeded );
		$keys = array_keys( $seeded );
		sort( $keys );
		$this->assertSame( [ '', '@acme/core', '@acme/next' ], $keys );
		$this->assertSame( '@acme/core@2.0.0', $seeded['@acme/core']['last_seen_tag'] );
		$this->assertSame( 'v9.0.0', $seeded['']['last_seen_tag'] );
	}
}
