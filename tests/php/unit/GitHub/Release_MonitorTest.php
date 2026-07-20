<?php
/**
 * Tests for GitHub\Release_Monitor.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\GitHub\Release;
use GitHubReleasePosts\GitHub\Release_Monitor;
use GitHubReleasePosts\GitHub\Release_Queue;
use GitHubReleasePosts\GitHub\Release_Selector;
use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\GitHub\Version_Comparator;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * Covers the universal stream monitor: normal per-stream checks, the three
 * lifecycle transitions (pending onboarding retry, released-version upgrade,
 * policy change), queue processing, and find_post().
 */
class Release_MonitorTest extends TestCase {

	private API_Client           $api_client;
	private Release_State        $release_state;
	private Version_Comparator   $comparator;
	private Release_Queue        $queue;
	private Repository_Settings  $repo_settings;
	private Release_Monitor      $monitor;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->api_client    = $this->createMock( API_Client::class );
		$this->release_state = $this->createMock( Release_State::class );
		$this->comparator    = $this->createMock( Version_Comparator::class );
		$this->queue         = $this->createMock( Release_Queue::class );
		$this->repo_settings = $this->createMock( Repository_Settings::class );

		$this->monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			$this->comparator,
			$this->queue,
			$this->repo_settings,
		);

		// The cron lock takes a direct $wpdb query on the options table's
		// unique index. Provide a mock that reports the lock as acquired.
		global $wpdb;
		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->options = 'wp_options';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( string $query ) {
				return $query;
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturn( 1 );

		\WP_Mock::userFunction( 'wp_cache_delete' )->andReturn( true );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true )->byDefault();
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false )->byDefault();
	}

	public function tearDown(): void {
		Release_Monitor::reset_find_post_cache();
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_release( string $tag, string $published_at = '2026-01-01T00:00:00Z', bool $prerelease = false ): Release {
		return new Release(
			tag:          $tag,
			name:         $tag,
			body:         '',
			published_at: $published_at,
			html_url:     'https://github.com/owner/repo/releases/tag/' . $tag,
			assets:       [],
			prerelease:   $prerelease,
		);
	}

	/**
	 * Canonical post-baseline state for a no-patterns, no-prereleases repo.
	 * Overrides let each test express exactly the lifecycle it exercises.
	 *
	 * @param array $overrides Keys to replace.
	 * @return array
	 */
	private function base_state( array $overrides = [] ): array {
		return array_merge(
			[
				'last_seen_tag'          => '',
				'last_seen_published_at' => '',
				'last_checked_at'        => 0,
				'stream_state_version'   => Release_State::STREAM_STATE_VERSION,
				'onboarding_pending'     => false,
				'streams_baseline_at'    => 1700000000,
				'policy_hash'            => Release_Selector::policy_hash( false, '' ),
				'streams'                => [],
			],
			$overrides
		);
	}

	/**
	 * Builds a monitor wired with the REAL comparator so stream grouping and
	 * version normalization are exercised end to end.
	 *
	 * @return Release_Monitor
	 */
	private function real_comparator_monitor(): Release_Monitor {
		return new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);
	}

	/**
	 * Registers the standard lock/option mocks used by full run() tests.
	 */
	private function mock_run_plumbing(): void {
		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );
	}

	/**
	 * Captures every enqueued tag into the returned array (by reference).
	 *
	 * @return array<int, string>
	 */
	private function &capture_enqueues(): array {
		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		return $enqueued;
	}

	// -------------------------------------------------------------------------
	// run() — plumbing (BR-004, AC-025, AC-031)
	// -------------------------------------------------------------------------

	/**
	 * run() records OPTION_LAST_RUN_AT before processing repos (BR-004).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_records_last_run_at_before_processing(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn( [] );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		$recorded_at = null;

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Plugin_Constants::OPTION_LAST_RUN_AT, \WP_Mock\Functions::type( 'int' ), false )
			->andReturnUsing( function ( $key, $value ) use ( &$recorded_at ) {
				$recorded_at = $value;
				return true;
			} );

		$this->monitor->run();

		$this->assertNotNull( $recorded_at );
		$this->assertGreaterThan( 0, $recorded_at );
	}

	/**
	 * Paused repos do not trigger an API call (AC-025, BR-004).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_skips_paused_repos(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo', 'paused' => true ] ]
		);

		$this->api_client->expects( $this->never() )->method( 'fetch_release_snapshot' );
		$this->queue->expects( $this->never() )->method( 'enqueue' );

		// dequeue_all() must still be called to process any previously queued items.
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$this->monitor->run();
	}

	/**
	 * Rate-limit WP_Error stops processing further repos (AC-031).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_stops_on_rate_limit_error(): void {
		$repos = [
			[ 'identifier' => 'owner/repo-a' ],
			[ 'identifier' => 'owner/repo-b' ],
		];

		$this->repo_settings->method( 'get_repositories' )->willReturn( $repos );

		$rate_limit_error = new \WP_Error( 'github_rate_limit_exhausted', 'Rate limit hit.' );

		// First repo hits rate limit — second should never be fetched.
		$this->api_client->expects( $this->once() )
			->method( 'fetch_release_snapshot' )
			->with( 'owner/repo-a' )
			->willReturn( $rate_limit_error );

		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// process_queue() — fires action and updates state on post creation (BR-001)
	// -------------------------------------------------------------------------

	/**
	 * ghrp_process_release is fired for each queued entry.
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_fires_process_release_action_for_queued_entries(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn( [] );

		$entry = [
			'identifier'   => 'owner/repo',
			'tag'          => 'v1.0.0',
			'name'         => 'v1.0.0',
			'body'         => '',
			'html_url'     => 'https://github.com/owner/repo/releases/tag/v1.0.0',
			'published_at' => '2026-01-01T00:00:00Z',
			'assets'       => [],
		];

		$this->queue->method( 'dequeue_all' )->willReturn( [ $entry ] );

		\WP_Mock::expectAction( 'ghrp_process_release', $entry, [] );

		// find_post() uses get_posts() — return empty so no state update.
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );

		// The no-post-created branch records an error for the admin notice
		// (Publish_Workflow::record_error → transient read/write).
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		$this->mock_run_plumbing();

		$this->monitor->run();

		$this->assertConditionsMet();
	}

	/**
	 * Both the repo-wide display cursor and the stream cursor advance only
	 * when a post was actually created (BR-001).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_updates_cursors_only_when_post_created(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn( [] );

		$entry = [
			'identifier'   => 'owner/repo',
			'tag'          => 'v2.0.0',
			'name'         => 'v2.0.0',
			'body'         => '',
			'html_url'     => '',
			'published_at' => '2026-03-21T00:00:00Z',
			'assets'       => [],
		];

		$this->queue->method( 'dequeue_all' )->willReturn( [ $entry ] );

		\WP_Mock::expectAction( 'ghrp_process_release', $entry, [] );

		$mock_post = new \WP_Post( (object) [
			'ID'         => 42,
			'post_title' => 'v2.0.0 release notes',
			'post_status' => 'draft',
		] );

		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ $mock_post ] );

		$this->release_state->expects( $this->once() )
			->method( 'update_last_seen' )
			->with( 'owner/repo', 'v2.0.0', '2026-03-21T00:00:00Z' );

		$this->release_state->expects( $this->once() )
			->method( 'update_stream_seen' )
			->with( 'owner/repo', '', 'v2.0.0', '2026-03-21T00:00:00Z' );

		$this->mock_run_plumbing();

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// find_post() — static deduplication helper (BR-003)
	// -------------------------------------------------------------------------

	/**
	 * find_post() returns null when no post exists.
	 *
	 * @covers Release_Monitor::find_post
	 */
	public function test_find_post_returns_null_when_no_post_found(): void {
		\WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( [] );

		$result = Release_Monitor::find_post( 'owner/repo', 'v1.0.0' );

		$this->assertNull( $result );
	}

	/**
	 * find_post() returns the WP_Post when one is found (BR-003).
	 *
	 * @covers Release_Monitor::find_post
	 */
	public function test_find_post_returns_wp_post_when_found(): void {
		$mock_post = new \WP_Post( (object) [
			'ID'          => 7,
			'post_title'  => 'Version 1.0.0',
			'post_status' => 'draft',
		] );

		\WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturn( [ $mock_post ] );

		$result = Release_Monitor::find_post( 'owner/repo', 'v1.0.0' );

		$this->assertInstanceOf( \WP_Post::class, $result );
		$this->assertSame( 7, $result->ID );
	}

	/**
	 * find_post() searches across all non-auto-draft statuses including trash (AC-003).
	 *
	 * @covers Release_Monitor::find_post
	 */
	public function test_find_post_queries_all_relevant_post_statuses(): void {
		\WP_Mock::userFunction( 'get_posts' )
			->once()
			->andReturnUsing( function ( $args ) {
				$this->assertContains( 'trash', $args['post_status'] );
				$this->assertContains( 'draft', $args['post_status'] );
				$this->assertContains( 'publish', $args['post_status'] );
				return [];
			} );

		Release_Monitor::find_post( 'owner/repo', 'v1.0.0' );
	}

	// -------------------------------------------------------------------------
	// Normal monitoring — universal per-stream checks
	// -------------------------------------------------------------------------

	/**
	 * Two selected packages both released between checks: BOTH are enqueued,
	 * and a matching policy hash means NO rebaseline happens. The old
	 * single-cursor model enqueued only the newer one and silently dropped
	 * its sibling forever.
	 */
	public function test_normal_run_enqueues_sibling_stream_releases(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor  = $this->real_comparator_monitor();
		$patterns = '@acme/core@*, @acme/next@*';

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono',
					'tag_patterns' => $patterns,
				],
			]
		);

		// Coordinated release day: both packages shipped since the last check,
		// an older core release that must not win its stream, and a plain tag
		// the patterns exclude from monitoring entirely.
		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
				$this->make_release( '@acme/core@1.9.0', '2026-06-01T00:00:00Z' ),
				$this->make_release( 'v0.5.0', '2025-01-01T00:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'policy_hash' => Release_Selector::policy_hash( false, $patterns ),
					'streams'     => [
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@1.9.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
						'@acme/next' => [
							'last_seen_tag'          => '@acme/next@1.4.0',
							'last_seen_published_at' => '2026-05-01T00:00:00Z',
						],
					],
				]
			)
		);

		$this->release_state->expects( $this->never() )->method( 'complete_baseline' );

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		sort( $enqueued );
		$this->assertSame( [ '@acme/core@2.0.0', '@acme/next@1.5.0' ], $enqueued );
	}

	/**
	 * A stream with no releases since its cursor is not re-enqueued, even
	 * when its sibling has a new release.
	 */
	public function test_stream_with_no_new_release_is_skipped(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor  = $this->real_comparator_monitor();
		$patterns = '@acme/core@*, @acme/next@*';

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono',
					'tag_patterns' => $patterns,
				],
			]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.4.0', '2026-05-01T00:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'policy_hash' => Release_Selector::policy_hash( false, $patterns ),
					'streams'     => [
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@1.9.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
						'@acme/next' => [
							'last_seen_tag'          => '@acme/next@1.4.0',
							'last_seen_published_at' => '2026-05-01T00:00:00Z',
						],
					],
				]
			)
		);

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( [ '@acme/core@2.0.0' ], $enqueued );
	}

	/**
	 * No patterns, no persisted topology: a repository mixing plain tags with
	 * package streams is monitored per stream by the ONE universal algorithm —
	 * all three new heads are enqueued in a single check.
	 */
	public function test_universal_monitor_enqueues_all_new_stream_heads(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/mixed' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( 'v3.0.0', '2026-07-19T00:00:00Z' ),
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'streams' => [
						''           => [
							'last_seen_tag'          => 'v2.0.0',
							'last_seen_published_at' => '2026-01-01T00:00:00Z',
						],
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@1.9.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
						'@acme/next' => [
							'last_seen_tag'          => '@acme/next@1.4.0',
							'last_seen_published_at' => '2026-05-01T00:00:00Z',
						],
					],
				]
			)
		);

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		sort( $enqueued );
		$this->assertSame( [ '@acme/core@2.0.0', '@acme/next@1.5.0', 'v3.0.0' ], $enqueued );
	}

	/**
	 * A stream head whose stream has no cursor (deliberately omitted at
	 * onboarding, or the stream appeared after the baseline) is enqueued —
	 * this is both the cron-retry path for a failed client auto-generate and
	 * the first-release path for a repo added before any release existed.
	 */
	public function test_missing_cursor_after_baseline_enqueues_head(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/newborn' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[ $this->make_release( 'v1.0.0', '2026-07-19T00:00:00Z' ) ]
		);

		$this->release_state->method( 'get_state' )->willReturn( $this->base_state() );

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( [ 'v1.0.0' ], $enqueued );
	}

	/**
	 * Replay guard: a stream head that already has a post (manual generation,
	 * client auto-generate) advances its cursor and is NOT re-enqueued —
	 * re-enqueueing burned an AI call and could publish a review draft via
	 * the cron's publish workflow.
	 */
	public function test_stream_head_with_existing_post_is_not_reenqueued(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ new \WP_Post( (object) [ 'ID' => 77 ] ) ] );
		$monitor  = $this->real_comparator_monitor();
		$patterns = '@acme/core@*';

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono-replay',
					'tag_patterns' => $patterns,
				],
			]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[ $this->make_release( '@acme/core@3.0.0', '2026-07-18T12:00:00Z' ) ]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'policy_hash' => Release_Selector::policy_hash( false, $patterns ),
					'streams'     => [
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@2.0.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
					],
				]
			)
		);

		$advanced = [];
		$this->release_state->method( 'update_stream_seen' )->willReturnCallback(
			function ( string $identifier, string $stream, string $tag, string $published_at ) use ( &$advanced ): void {
				$advanced[] = $tag;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( [ '@acme/core@3.0.0' ], $advanced );
	}

	/**
	 * REGRESSION (design-doc "round 7"): with pre-releases off, a pre-release
	 * head is invisible to monitoring — the newest STABLE release is the
	 * stream head, and it generates even though a higher-versioned beta
	 * exists. A beta must never block or become a cursor.
	 */
	public function test_prerelease_head_is_invisible_when_prereleases_off(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/beta-mixed' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( '@acme/core@3.0.0-beta.1', '2026-07-19T00:00:00Z', true ),
				$this->make_release( '@acme/core@2.1.0', '2026-07-18T00:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'streams' => [
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@2.0.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
					],
				]
			)
		);

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( [ '@acme/core@2.1.0' ], $enqueued );
	}

	// -------------------------------------------------------------------------
	// Transition: upgrade from the released pre-stream plugin
	// -------------------------------------------------------------------------

	/**
	 * State written by the released plugin (no stream_state_version) gets a
	 * one-time baseline of every current eligible stream head and generates
	 * NOTHING — no one-post-per-package burst on upgrade.
	 */
	public function test_upgrade_from_released_state_baselines_without_generating(): void {
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/legacy' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( 'v9.0.0', '2026-07-19T00:00:00Z' ),
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
			]
		);

		// Exactly what 1.1.x left behind: a repo-wide cursor and nothing else.
		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'last_seen_tag'          => 'v8.0.0',
					'last_seen_published_at' => '2026-01-01T00:00:00Z',
					'stream_state_version'   => 0,
					'streams_baseline_at'    => 0,
					'policy_hash'            => '',
					'streams'                => [],
				]
			)
		);

		$seeded = null;
		$this->release_state->expects( $this->once() )->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors, string $policy_hash ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertNotNull( $seeded );
		$keys = array_keys( $seeded );
		sort( $keys );
		$this->assertSame( [ '', '@acme/core', '@acme/next' ], $keys );
		$this->assertSame( '@acme/core@2.0.0', $seeded['@acme/core']['last_seen_tag'] );
	}

	// -------------------------------------------------------------------------
	// Transition: eligibility policy change (forward-only)
	// -------------------------------------------------------------------------

	/**
	 * A changed policy hash rebaselines the current eligible heads under the
	 * NEW policy and generates nothing that scan — settings changes are
	 * forward-only, and a cursor written under the old policy can never
	 * block or leak content under the new one.
	 */
	public function test_policy_change_rebaselines_without_generating(): void {
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/policy-flip' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[ $this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ) ]
		);

		// Baseline was built when Include pre-releases was ON; the repo config
		// above has it OFF, so the stored hash no longer matches.
		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'policy_hash' => Release_Selector::policy_hash( true, '' ),
					'streams'     => [
						'@acme/core' => [
							'last_seen_tag'          => '@acme/core@1.9.0',
							'last_seen_published_at' => '2026-06-01T00:00:00Z',
						],
					],
				]
			)
		);

		$captured_hash = null;
		$this->release_state->expects( $this->once() )->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors, string $policy_hash ) use ( &$captured_hash ): void {
				$captured_hash = $policy_hash;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( Release_Selector::policy_hash( false, '' ), $captured_hash );
	}

	// -------------------------------------------------------------------------
	// Transition: retry of failed onboarding (onboarding_pending)
	// -------------------------------------------------------------------------

	/**
	 * A pending single-stream repo completes onboarding on the cron retry
	 * with the SAME matrix as a successful add: empty baseline (the only
	 * stream is the initial release's own) and exactly one generation.
	 */
	public function test_pending_retry_single_stream_baselines_and_enqueues_initial(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/retry-single' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[ $this->make_release( 'v1.0.0', '2026-07-19T00:00:00Z' ) ]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'stream_state_version' => 0,
					'onboarding_pending'   => true,
					'streams_baseline_at'  => 0,
					'policy_hash'          => '',
				]
			)
		);

		$this->release_state->expects( $this->once() )->method( 'complete_baseline' )->with( 'acme/retry-single', [], $this->anything() );

		$enqueued = &$this->capture_enqueues();
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertSame( [ 'v1.0.0' ], $enqueued );
	}

	/**
	 * A pending repo whose first successful snapshot shows 2+ recognized
	 * packages follows the chooser branch of the matrix: every current head
	 * baselined, NOTHING generated (no burst of existing history), and the
	 * package nudge deferred to the settings screen.
	 */
	public function test_pending_retry_multi_package_baselines_all_and_defers_nudge(): void {
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/retry-mono' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/utils@1.1.0', '2026-07-18T11:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'stream_state_version' => 0,
					'onboarding_pending'   => true,
					'streams_baseline_at'  => 0,
					'policy_hash'          => '',
				]
			)
		);

		$seeded = null;
		$this->release_state->expects( $this->once() )->method( 'complete_baseline' )->willReturnCallback(
			function ( string $identifier, array $cursors, string $policy_hash ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		$deferred = null;
		\WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( string $key, $value ) use ( &$deferred ) {
				if ( Cache_Keys::deferred_notices() === $key ) {
					$deferred = $value;
				}
				return true;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		$this->mock_run_plumbing();

		$monitor->run();

		$this->assertNotNull( $seeded );
		$this->assertSame( [ '@acme/core', '@acme/utils' ], array_keys( $seeded ) );
		$this->assertIsArray( $deferred );
		$this->assertArrayHasKey( 'acme/retry-mono', $deferred );
	}

	/**
	 * A pending repo with no releases resolves onboarding with an empty
	 * ready baseline: the pending flag cannot linger forever, and the first
	 * later release generates through normal monitoring.
	 */
	public function test_pending_retry_empty_snapshot_resolves_onboarding(): void {
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/retry-empty' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn( [] );

		$this->release_state->method( 'get_state' )->willReturn(
			$this->base_state(
				[
					'stream_state_version' => 0,
					'onboarding_pending'   => true,
					'streams_baseline_at'  => 0,
					'policy_hash'          => '',
				]
			)
		);

		$this->release_state->expects( $this->once() )->method( 'complete_baseline' )->with( 'acme/retry-empty', [], $this->anything() );

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		$this->mock_run_plumbing();

		$monitor->run();
	}

	/**
	 * A failed snapshot on the retry leaves the repository pending — no
	 * baseline is written, so the next cron retries onboarding again.
	 */
	public function test_pending_retry_snapshot_failure_stays_pending(): void {
		$monitor = $this->real_comparator_monitor();

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/retry-flaky' ] ]
		);

		$this->api_client->method( 'fetch_release_snapshot' )->willReturn(
			new \WP_Error( 'http_request_failed', 'timeout' )
		);

		$this->release_state->expects( $this->never() )->method( 'complete_baseline' );
		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		$this->mock_run_plumbing();

		$monitor->run();
	}
}
