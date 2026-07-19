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
use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\GitHub\Version_Comparator;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * Covers the release-check cron run, queue processing, and find_post().
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

		// The cron lock now takes a direct $wpdb query on the options table's
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
	}

	public function tearDown(): void {
		Release_Monitor::reset_find_post_cache();
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_release( string $tag, string $published_at = '2026-01-01T00:00:00Z' ): Release {
		return new Release(
			tag:          $tag,
			name:         $tag,
			body:         '',
			published_at: $published_at,
			html_url:     'https://github.com/owner/repo/releases/tag/' . $tag,
			assets:       [],
		);
	}

	// -------------------------------------------------------------------------
	// run() — records last_run_at at start (BR-004)
	// -------------------------------------------------------------------------

	/**
	 * run() records OPTION_LAST_RUN_AT before processing repos (BR-004).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_records_last_run_at_before_processing(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn( [] );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
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

	// -------------------------------------------------------------------------
	// run() — paused repo skipping (AC-025, BR-004)
	// -------------------------------------------------------------------------

	/**
	 * Paused repos do not trigger an API call (AC-025, BR-004).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_skips_paused_repos(): void {
		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo', 'paused' => true ] ]
		);

		$this->api_client->expects( $this->never() )->method( 'fetch_latest_eligible_release' );
		$this->queue->expects( $this->never() )->method( 'enqueue' );

		// dequeue_all() must still be called to process any previously queued items.
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// run() — rate limit exhausted stops the loop
	// -------------------------------------------------------------------------

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
			->method( 'fetch_latest_eligible_release' )
			->with( 'owner/repo-a' )
			->willReturn( $rate_limit_error );

		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// run() — new release enqueued
	// -------------------------------------------------------------------------

	/**
	 * A new release is enqueued and last_checked is updated.
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_enqueues_new_release(): void {
		$release = $this->make_release( 'v1.1.0' );
		$state   = [
			'last_seen_tag'          => 'v1.0.0',
			'last_seen_published_at' => '',
			'last_checked_at'        => 0,
			'packages'               => [],
			'streams_baseline_at'    => 1700000000,
			'is_monorepo'            => false,
		];

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo' ] ]
		);
		$this->api_client->method( 'fetch_latest_eligible_release' )->willReturn( $release );
		$this->release_state->method( 'get_state' )->willReturn( $state );
		$this->comparator->method( 'is_newer' )->willReturn( true );

		$this->queue->expects( $this->once() )
			->method( 'enqueue' )
			->with( 'owner/repo', $release );

		$this->release_state->expects( $this->once() )
			->method( 'update_last_checked' )
			->with( 'owner/repo' );

		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// run() — no new release skips enqueue
	// -------------------------------------------------------------------------

	/**
	 * When there is no new release, the queue is not touched.
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_does_not_enqueue_when_no_new_release(): void {
		$release = $this->make_release( 'v1.0.0' );
		$state   = [
			'last_seen_tag'          => 'v1.0.0',
			'last_seen_published_at' => '',
			'last_checked_at'        => 0,
			'packages'               => [],
			'streams_baseline_at'    => 1700000000,
			'is_monorepo'            => false,
		];

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo' ] ]
		);
		$this->api_client->method( 'fetch_latest_eligible_release' )->willReturn( $release );
		$this->release_state->method( 'get_state' )->willReturn( $state );
		$this->comparator->method( 'is_newer' )->willReturn( false );

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

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
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$this->monitor->run();

		$this->assertConditionsMet();
	}

	/**
	 * last_seen state is updated only when a post is created (BR-001).
	 *
	 * @covers Release_Monitor::run
	 */
	public function test_run_updates_last_seen_only_when_post_created(): void {
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

		// Concurrency lock mocks.
		\WP_Mock::userFunction( 'add_option' )->with( Cache_Keys::cron_lock(), \WP_Mock\Functions::type( 'int' ), '', false )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->with( Cache_Keys::cron_lock() )->andReturn( true );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

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
	// run() — per-package streams for patterned monorepos (peer review P1)
	// -------------------------------------------------------------------------

	/**
	 * Two selected packages both released between checks: BOTH are enqueued.
	 * The previous single-cursor model enqueued only the newer one and
	 * silently dropped its sibling forever. Uses the real Version_Comparator
	 * so package tags exercise the semver normalization end to end.
	 */
	public function test_patterned_run_enqueues_sibling_package_releases(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono',
					'tag_patterns' => '@acme/core@*, @acme/next@*',
				],
			]
		);

		// Coordinated release day: both packages shipped since the last check,
		// plus an older core release that must not win its stream.
		$this->api_client->method( 'fetch_releases' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
				$this->make_release( '@acme/core@1.9.0', '2026-06-01T00:00:00Z' ),
			]
		);
		$this->api_client->expects( $this->never() )->method( 'fetch_latest_eligible_release' );

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '@acme/core@1.9.0',
				'last_seen_published_at' => '2026-06-01T00:00:00Z',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => true,
				'packages'               => [
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
		);

		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		sort( $enqueued );
		$this->assertSame( [ '@acme/core@2.0.0', '@acme/next@1.5.0' ], $enqueued );
	}

	/**
	 * A package with no releases since its cursor is not re-enqueued, even
	 * when its sibling has a new release.
	 */
	public function test_patterned_run_skips_stream_with_no_new_release(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );
		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono',
					'tag_patterns' => '@acme/core@*, @acme/next@*',
				],
			]
		);

		$this->api_client->method( 'fetch_releases' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.4.0', '2026-05-01T00:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '',
				'last_seen_published_at' => '',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => true,
				'packages'               => [
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
		);

		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		$this->assertSame( [ '@acme/core@2.0.0' ], $enqueued );
	}

	/**
	 * The default all-packages mode (no patterns) must ALSO monitor per
	 * stream: a monorepo is detected from its latest release's tag shape,
	 * and coordinated sibling releases are both enqueued (peer review
	 * round 2, P1).
	 */
	public function test_unfiltered_monorepo_enqueues_sibling_releases(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );

		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/unfiltered' ] ]
		);

		// Fast path returns the repo-wide latest — a package tag, so the
		// monitor switches to streams and fetches the full list.
		$this->api_client->method( 'fetch_latest_eligible_release' )->willReturn(
			$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' )
		);
		$this->api_client->method( 'fetch_releases' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '@acme/core@1.9.0',
				'last_seen_published_at' => '2026-06-01T00:00:00Z',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => true,
				'packages'               => [
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
		);

		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		sort( $enqueued );
		$this->assertSame( [ '@acme/core@2.0.0', '@acme/next@1.5.0' ], $enqueued );
	}

	/**
	 * The first streamed run seeds cursors without generating — no
	 * one-post-per-package burst on upgrade or pattern configuration.
	 */
	public function test_first_streamed_run_seeds_without_enqueueing(): void {
		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono-seed',
					'tag_patterns' => '@acme/core@*, @acme/next@*',
				],
			]
		);

		$this->api_client->method( 'fetch_releases' )->willReturn(
			[
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
			]
		);

		// No baseline marker: this repo predates stream monitoring. An
		// unrelated default-stream cursor is present and must NOT disable
		// migration seeding (round 3 — emptiness is not the signal).
		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '@acme/core@1.0.0',
				'last_seen_published_at' => '2026-01-01T00:00:00Z',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 0,
				'is_monorepo'            => true,
				'packages'               => [
					'' => [
						'last_seen_tag'          => 'v0.9.0',
						'last_seen_published_at' => '2025-01-01T00:00:00Z',
					],
				],
			]
		);

		$seeded = null;
		$this->release_state->method( 'seed_streams' )->willReturnCallback(
			function ( string $identifier, array $cursors ) use ( &$seeded ): void {
				$seeded = $cursors;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		$this->assertSame(
			[ '@acme/core', '@acme/next' ],
			array_keys( $seeded )
		);
		$this->assertSame( '@acme/core@2.0.0', $seeded['@acme/core']['last_seen_tag'] );
	}

	/**
	 * Replay guard: a stream candidate that already has a post (manual
	 * generation, pre-existing content) advances its cursor and is NOT
	 * re-enqueued — re-enqueueing burned an AI call and could publish a
	 * review draft via the cron's publish workflow (peer review round 2).
	 */
	public function test_stream_candidate_with_existing_post_is_not_reenqueued(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ new \WP_Post( [ 'ID' => 77 ] ) ] );

		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[
				[
					'identifier'   => 'acme/mono-replay',
					'tag_patterns' => '@acme/core@*',
				],
			]
		);

		$this->api_client->method( 'fetch_releases' )->willReturn(
			[ $this->make_release( '@acme/core@3.0.0', '2026-07-18T12:00:00Z' ) ]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '',
				'last_seen_published_at' => '',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => false,
				'packages'               => [
					'@acme/core' => [
						'last_seen_tag'          => '@acme/core@2.0.0',
						'last_seen_published_at' => '2026-06-01T00:00:00Z',
					],
				],
			]
		);

		$advanced = [];
		$this->release_state->method( 'update_package_seen' )->willReturnCallback(
			function ( string $identifier, string $package, string $tag, string $published_at ) use ( &$advanced ): void {
				$advanced[] = $tag;
			}
		);

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		$this->assertSame( [ '@acme/core@3.0.0' ], $advanced );
	}

	/**
	 * A repo added before its first release (baseline stamped by onboarding,
	 * cursors empty) must generate when that first package-style release
	 * appears — onboarding promised it, and this is also the cron-retry path
	 * when client auto-generation fails (round 3).
	 */
	public function test_first_release_after_empty_add_is_enqueued(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );

		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/newborn' ] ]
		);

		$this->api_client->method( 'fetch_latest_eligible_release' )->willReturn(
			$this->make_release( 'newborn@1.0.0', '2026-07-19T00:00:00Z' )
		);
		$this->api_client->method( 'fetch_releases' )->willReturn(
			[ $this->make_release( 'newborn@1.0.0', '2026-07-19T00:00:00Z' ) ]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => '',
				'last_seen_published_at' => '',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => false,
				'packages'               => [],
			]
		);

		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		$this->assertSame( [ 'newborn@1.0.0' ], $enqueued );
	}

	/**
	 * The durable is_monorepo flag routes to stream monitoring even when the
	 * LATEST release is a plain repo-wide tag (round 3): both unseen package
	 * releases behind it are enqueued.
	 */
	public function test_monorepo_flag_survives_plain_latest_tag(): void {
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );

		$monitor = new Release_Monitor(
			$this->api_client,
			$this->release_state,
			new Version_Comparator(),
			$this->queue,
			$this->repo_settings,
		);

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'acme/mixed' ] ]
		);

		// Latest is a plain repo-wide tag — tag-shape detection would miss it.
		$this->api_client->method( 'fetch_latest_eligible_release' )->willReturn(
			$this->make_release( 'v3.0.0', '2026-07-19T00:00:00Z' )
		);
		$this->api_client->method( 'fetch_releases' )->willReturn(
			[
				$this->make_release( 'v3.0.0', '2026-07-19T00:00:00Z' ),
				$this->make_release( '@acme/core@2.0.0', '2026-07-18T12:00:00Z' ),
				$this->make_release( '@acme/next@1.5.0', '2026-07-18T11:00:00Z' ),
			]
		);

		$this->release_state->method( 'get_state' )->willReturn(
			[
				'last_seen_tag'          => 'v2.0.0',
				'last_seen_published_at' => '2026-01-01T00:00:00Z',
				'last_checked_at'        => 0,
				'streams_baseline_at'    => 1700000000,
				'is_monorepo'            => true,
				'packages'               => [
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
		);

		$enqueued = [];
		$this->queue->method( 'enqueue' )->willReturnCallback(
			function ( string $identifier, Release $release ) use ( &$enqueued ): void {
				$enqueued[] = $release->tag;
			}
		);
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'delete_option' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$monitor->run();

		sort( $enqueued );
		$this->assertSame( [ '@acme/core@2.0.0', '@acme/next@1.5.0', 'v3.0.0' ], $enqueued );
	}
}
