<?php
/**
 * Tests for GitHub\Release_Selector.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\Release;
use GitHubReleasePosts\GitHub\Release_Selector;
use WP_Mock\Tools\TestCase;

/**
 * Covers the pure selection helpers: the monitoring projection, the shared
 * latest-head reduction, the policy hash, and the onboarding matrix.
 */
class Release_SelectorTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

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

	// -------------------------------------------------------------------------
	// monitoring_projection()
	// -------------------------------------------------------------------------

	public function test_projection_excludes_prereleases_unless_opted_in(): void {
		$snapshot = [
			$this->release( 'v2.0.0-rc.1', '2026-07-02T00:00:00Z', true ),
			$this->release( 'v1.9.0', '2026-07-01T00:00:00Z' ),
		];

		$stable = Release_Selector::monitoring_projection( $snapshot, false, '' );
		$this->assertSame( [ 'v1.9.0' ], array_map( static fn( Release $r ): string => $r->tag, $stable ) );

		$all = Release_Selector::monitoring_projection( $snapshot, true, '' );
		$this->assertCount( 2, $all );
	}

	public function test_projection_applies_tag_patterns(): void {
		$snapshot = [
			$this->release( '@acme/core@2.0.0' ),
			$this->release( '@acme/utils@1.0.0' ),
			$this->release( 'v9.0.0' ),
		];

		$eligible = Release_Selector::monitoring_projection( $snapshot, false, '@acme/core@*' );

		$this->assertSame( [ '@acme/core@2.0.0' ], array_map( static fn( Release $r ): string => $r->tag, $eligible ) );
	}

	public function test_projection_with_no_patterns_keeps_every_stable_release(): void {
		$snapshot = [
			$this->release( '@acme/core@2.0.0' ),
			$this->release( 'v9.0.0' ),
		];

		$this->assertCount( 2, Release_Selector::monitoring_projection( $snapshot, false, '' ) );
	}

	// -------------------------------------------------------------------------
	// select_latest_head() — shared two-stage reduction
	// -------------------------------------------------------------------------

	public function test_latest_head_two_stage_reduction_is_backport_proof(): void {
		// A March backport of package A must not let January's A@2.0.0 displace
		// February's B@1.0.0: highest version per stream first, then chronology.
		$releases = [
			$this->release( '@acme/a@1.0.0', '2026-03-01T00:00:00Z' ),
			$this->release( '@acme/b@1.0.0', '2026-02-01T00:00:00Z' ),
			$this->release( '@acme/a@2.0.0', '2026-01-01T00:00:00Z' ),
		];

		$latest = Release_Selector::select_latest_head( $releases );

		$this->assertInstanceOf( Release::class, $latest );
		$this->assertSame( '@acme/b@1.0.0', $latest->tag );
	}

	public function test_latest_head_returns_null_for_empty_list(): void {
		$this->assertNull( Release_Selector::select_latest_head( [] ) );
	}

	// -------------------------------------------------------------------------
	// policy_hash()
	// -------------------------------------------------------------------------

	public function test_policy_hash_ignores_pattern_formatting_noise(): void {
		$this->assertSame(
			Release_Selector::policy_hash( false, '@acme/core@*, @acme/next@*' ),
			Release_Selector::policy_hash( false, '  @acme/core@* ,,@acme/next@*  ' )
		);
	}

	public function test_policy_hash_changes_with_policy(): void {
		$base = Release_Selector::policy_hash( false, '' );

		$this->assertNotSame( $base, Release_Selector::policy_hash( true, '' ) );
		$this->assertNotSame( $base, Release_Selector::policy_hash( false, '@acme/core@*' ) );
	}

	// -------------------------------------------------------------------------
	// onboarding_plan() — the matrix
	// -------------------------------------------------------------------------

	public function test_plan_for_empty_eligible_list_is_empty_ready_baseline(): void {
		$plan = Release_Selector::onboarding_plan( [], false );

		$this->assertSame( [], $plan['cursors'] );
		$this->assertNull( $plan['initial'] );
	}

	public function test_plan_with_ui_choice_baselines_every_head_and_generates_nothing(): void {
		$plan = Release_Selector::onboarding_plan(
			[
				$this->release( '@acme/core@2.0.0', '2026-07-02T00:00:00Z' ),
				$this->release( '@acme/utils@1.0.0', '2026-07-01T00:00:00Z' ),
			],
			true
		);

		$this->assertNull( $plan['initial'] );
		$this->assertSame( [ '@acme/core', '@acme/utils' ], array_keys( $plan['cursors'] ) );
	}

	public function test_plan_single_stream_omits_only_cursor_and_selects_initial(): void {
		$plan = Release_Selector::onboarding_plan( [ $this->release( 'v3.1.0' ) ], false );

		$this->assertSame( [], $plan['cursors'] );
		$this->assertSame( 'v3.1.0', $plan['initial']->tag );
	}

	public function test_plan_mixed_topology_omits_the_initial_streams_cursor(): void {
		$plan = Release_Selector::onboarding_plan(
			[
				$this->release( 'v9.0.0', '2026-07-02T00:00:00Z' ),
				$this->release( '@acme/core@2.0.0', '2026-07-01T00:00:00Z' ),
			],
			false
		);

		$this->assertSame( 'v9.0.0', $plan['initial']->tag );
		$this->assertSame( [ '@acme/core' ], array_keys( $plan['cursors'] ) );
		$this->assertSame( '@acme/core@2.0.0', $plan['cursors']['@acme/core']['last_seen_tag'] );
	}

	public function test_plan_cursors_use_stream_winners_not_list_order(): void {
		// The backport (1.9.6, newest by date) must not become the cursor.
		$plan = Release_Selector::onboarding_plan(
			[
				$this->release( '@acme/core@1.9.6', '2026-07-02T00:00:00Z' ),
				$this->release( 'v9.0.0', '2026-07-03T00:00:00Z' ),
				$this->release( '@acme/core@2.0.0', '2026-07-01T00:00:00Z' ),
			],
			false
		);

		$this->assertSame( 'v9.0.0', $plan['initial']->tag );
		$this->assertSame( '@acme/core@2.0.0', $plan['cursors']['@acme/core']['last_seen_tag'] );
	}
}
