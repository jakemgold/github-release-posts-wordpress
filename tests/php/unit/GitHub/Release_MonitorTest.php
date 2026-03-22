<?php
/**
 * Tests for GitHub\Release_Monitor.
 *
 * @package ChangelogToBlogPost\Tests\GitHub
 */

namespace TenUp\ChangelogToBlogPost\Tests\GitHub;

use TenUp\ChangelogToBlogPost\GitHub\API_Client;
use TenUp\ChangelogToBlogPost\GitHub\Release;
use TenUp\ChangelogToBlogPost\GitHub\Release_Monitor;
use TenUp\ChangelogToBlogPost\GitHub\Release_Queue;
use TenUp\ChangelogToBlogPost\GitHub\Release_State;
use TenUp\ChangelogToBlogPost\GitHub\Version_Comparator;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
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
	}

	public function tearDown(): void {
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

		$this->api_client->expects( $this->never() )->method( 'fetch_latest_release' );
		$this->queue->expects( $this->never() )->method( 'enqueue' );

		// dequeue_all() must still be called to process any previously queued items.
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

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
			->method( 'fetch_latest_release' )
			->with( 'owner/repo-a' )
			->willReturn( $rate_limit_error );

		$this->queue->method( 'dequeue_all' )->willReturn( [] );

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
		$state   = [ 'last_seen_tag' => 'v1.0.0', 'last_seen_published_at' => '', 'last_checked_at' => 0 ];

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo' ] ]
		);
		$this->api_client->method( 'fetch_latest_release' )->willReturn( $release );
		$this->release_state->method( 'get_state' )->willReturn( $state );
		$this->comparator->method( 'is_newer' )->willReturn( true );

		$this->queue->expects( $this->once() )
			->method( 'enqueue' )
			->with( 'owner/repo', $release );

		$this->release_state->expects( $this->once() )
			->method( 'update_last_checked' )
			->with( 'owner/repo' );

		$this->queue->method( 'dequeue_all' )->willReturn( [] );

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
		$state   = [ 'last_seen_tag' => 'v1.0.0', 'last_seen_published_at' => '', 'last_checked_at' => 0 ];

		$this->repo_settings->method( 'get_repositories' )->willReturn(
			[ [ 'identifier' => 'owner/repo' ] ]
		);
		$this->api_client->method( 'fetch_latest_release' )->willReturn( $release );
		$this->release_state->method( 'get_state' )->willReturn( $state );
		$this->comparator->method( 'is_newer' )->willReturn( false );

		$this->queue->expects( $this->never() )->method( 'enqueue' );
		$this->queue->method( 'dequeue_all' )->willReturn( [] );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$this->monitor->run();
	}

	// -------------------------------------------------------------------------
	// process_queue() — fires action and updates state on post creation (BR-001)
	// -------------------------------------------------------------------------

	/**
	 * ctbp_process_release is fired for each queued entry.
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

		\WP_Mock::expectAction( 'ctbp_process_release', $entry, [] );

		// find_post() uses get_posts() — return empty so no state update.
		\WP_Mock::userFunction( 'get_posts' )->andReturn( [] );

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

		\WP_Mock::expectAction( 'ctbp_process_release', $entry, [] );

		$mock_post = new \WP_Post( (object) [
			'ID'         => 42,
			'post_title' => 'v2.0.0 release notes',
			'post_status' => 'draft',
		] );

		\WP_Mock::userFunction( 'get_posts' )->andReturn( [ $mock_post ] );

		$this->release_state->expects( $this->once() )
			->method( 'update_last_seen' )
			->with( 'owner/repo', 'v2.0.0', '2026-03-21T00:00:00Z' );

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
}
