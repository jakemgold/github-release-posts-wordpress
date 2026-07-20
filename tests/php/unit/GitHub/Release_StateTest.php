<?php
/**
 * Tests for GitHub\Release_State.
 *
 * @package GitHubReleasePosts\Tests\GitHub
 */

namespace GitHubReleasePosts\Tests\GitHub;

use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\Plugin_Constants;
use WP_Mock\Tools\TestCase;

/**
 * Covers get_state(), update_last_seen(), update_last_checked(), and clear_state().
 */
class Release_StateTest extends TestCase {

	private Release_State $state;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		$this->state = new Release_State();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function option_key( string $identifier ): string {
		return Plugin_Constants::OPTION_REPO_STATE_PREFIX . md5( $identifier );
	}

	// -------------------------------------------------------------------------
	// get_state() — defaults and stored values
	// -------------------------------------------------------------------------

	/**
	 * get_state() returns sensible defaults when no state exists.
	 *
	 * @covers Release_State::get_state
	 */
	public function test_get_state_returns_defaults_when_no_state_stored(): void {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( [] );

		$state = $this->state->get_state( 'owner/repo' );

		$this->assertSame( '', $state['last_seen_tag'] );
		$this->assertSame( '', $state['last_seen_published_at'] );
		$this->assertSame( 0, $state['last_checked_at'] );
	}

	/**
	 * get_state() merges stored values with defaults so missing keys are safe.
	 *
	 * @covers Release_State::get_state
	 */
	public function test_get_state_merges_stored_values_with_defaults(): void {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( [ 'last_seen_tag' => 'v1.0.0' ] );

		$state = $this->state->get_state( 'owner/repo' );

		$this->assertSame( 'v1.0.0', $state['last_seen_tag'] );
		$this->assertSame( '', $state['last_seen_published_at'] );
		$this->assertSame( 0, $state['last_checked_at'] );
	}

	// -------------------------------------------------------------------------
	// update_last_seen()
	// -------------------------------------------------------------------------

	/**
	 * update_last_seen() writes tag and published_at, preserving last_checked_at.
	 *
	 * @covers Release_State::update_last_seen
	 */
	public function test_update_last_seen_writes_tag_and_published_at(): void {
		$existing = [
			'last_seen_tag'          => 'v0.9.0',
			'last_seen_published_at' => '2026-01-01T00:00:00Z',
			'last_checked_at'        => 1234567890,
		];

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				$this->option_key( 'owner/repo' ),
				\WP_Mock\Functions::type( 'array' ),
				false
			)
			->andReturnUsing( function ( $key, $data ) {
				$this->assertSame( 'v1.0.0', $data['last_seen_tag'] );
				$this->assertSame( '2026-03-21T12:00:00Z', $data['last_seen_published_at'] );
				$this->assertSame( 1234567890, $data['last_checked_at'] );
				return true;
			} );

		$this->state->update_last_seen( 'owner/repo', 'v1.0.0', '2026-03-21T12:00:00Z' );
	}

	// -------------------------------------------------------------------------
	// update_last_checked()
	// -------------------------------------------------------------------------

	/**
	 * update_last_checked() writes a non-zero timestamp without touching tag fields.
	 *
	 * @covers Release_State::update_last_checked
	 */
	public function test_update_last_checked_writes_timestamp(): void {
		$existing = [
			'last_seen_tag'          => 'v1.0.0',
			'last_seen_published_at' => '2026-03-21T12:00:00Z',
			'last_checked_at'        => 0,
		];

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				$this->option_key( 'owner/repo' ),
				\WP_Mock\Functions::type( 'array' ),
				false
			)
			->andReturnUsing( function ( $key, $data ) {
				$this->assertSame( 'v1.0.0', $data['last_seen_tag'] );
				$this->assertGreaterThan( 0, $data['last_checked_at'] );
				return true;
			} );

		$this->state->update_last_checked( 'owner/repo' );
	}

	// -------------------------------------------------------------------------
	// clear_state()
	// -------------------------------------------------------------------------

	/**
	 * clear_state() deletes the option for the given identifier.
	 *
	 * @covers Release_State::clear_state
	 */
	public function test_clear_state_deletes_option(): void {
		\WP_Mock::userFunction( 'delete_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ) );

		$this->state->clear_state( 'owner/repo' );

		$this->assertConditionsMet();
	}

	/**
	 * Different identifiers use different option keys (MD5-namespaced).
	 *
	 * @covers Release_State::get_state
	 */
	public function test_different_identifiers_use_different_keys(): void {
		$key_a = $this->option_key( 'owner/repo-a' );
		$key_b = $this->option_key( 'owner/repo-b' );

		$this->assertNotSame( $key_a, $key_b );
	}

	// -------------------------------------------------------------------------
	// mark_onboarding_pending()
	// -------------------------------------------------------------------------

	/**
	 * mark_onboarding_pending() persists the flag in one write, with every
	 * other lifecycle marker still at its default.
	 *
	 * @covers Release_State::mark_onboarding_pending
	 */
	public function test_mark_onboarding_pending_sets_only_the_flag(): void {
		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( [] );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				$this->option_key( 'owner/repo' ),
				\WP_Mock\Functions::type( 'array' ),
				false
			)
			->andReturnUsing( function ( $key, $data ) {
				$this->assertTrue( $data['onboarding_pending'] );
				$this->assertSame( 0, $data['stream_state_version'] );
				$this->assertSame( 0, $data['streams_baseline_at'] );
				$this->assertSame( [], $data['streams'] );
				return true;
			} );

		$this->state->mark_onboarding_pending( 'owner/repo' );
	}

	// -------------------------------------------------------------------------
	// mark_multi_package() / uses_package_naming()
	// -------------------------------------------------------------------------

	/**
	 * mark_multi_package() persists the display-only observation exactly once:
	 * a second call against already-marked state performs no write (sticky).
	 *
	 * @covers Release_State::mark_multi_package
	 */
	public function test_mark_multi_package_writes_once_and_sticks(): void {
		\WP_Mock::userFunction( 'get_option' )
			->andReturnValues( [ [], [ 'multi_package_observed' => true ] ] );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->andReturnUsing( function ( $key, $data ) {
				$this->assertTrue( $data['multi_package_observed'] );
				return true;
			} );

		$this->state->mark_multi_package( 'owner/repo' );
		$this->state->mark_multi_package( 'owner/repo' );
	}

	/**
	 * uses_package_naming() honors either signal: configured patterns opt in
	 * without any observation, and an observed multi-package topology opts in
	 * without patterns. Neither signal means raw naming.
	 *
	 * @covers Release_State::uses_package_naming
	 */
	public function test_uses_package_naming_honors_both_signals(): void {
		\WP_Mock::userFunction( 'get_option' )
			->andReturnValues( [ [], [ 'multi_package_observed' => true ] ] );

		$this->assertFalse( $this->state->uses_package_naming( 'owner/repo', '' ) );
		// Patterns short-circuit — no state read.
		$this->assertTrue( $this->state->uses_package_naming( 'owner/repo', '@acme/core@*' ) );
		$this->assertTrue( $this->state->uses_package_naming( 'owner/repo', '' ) );
	}

	// -------------------------------------------------------------------------
	// complete_baseline()
	// -------------------------------------------------------------------------

	/**
	 * complete_baseline() performs ONE canonical update_option() that
	 * REPLACES the stream map, stamps the baseline and policy hash, resolves
	 * pending onboarding, marks the schema current, preserves the legacy
	 * display fields — and drops keys left over from unreleased review
	 * iterations of this feature.
	 *
	 * @covers Release_State::complete_baseline
	 */
	public function test_complete_baseline_writes_one_canonical_transition(): void {
		$stored = [
			'last_seen_tag'          => 'v8.0.0',
			'last_seen_published_at' => '2026-01-01T00:00:00Z',
			'last_checked_at'        => 1234567890,
			'onboarding_pending'     => true,
			// Stale cursor map that must be REPLACED, not merged.
			'streams'                => [
				'@acme/old' => [
					'last_seen_tag'          => '@acme/old@1.0.0',
					'last_seen_published_at' => '2025-01-01T00:00:00Z',
				],
			],
			// Display-only observation — must SURVIVE the transition.
			'multi_package_observed' => true,
			// Branch-only keys from unreleased iterations — must be dropped.
			'tracking_started_at'    => 1700000000,
			'is_monorepo'            => true,
			'topology_checked_at'    => 1700000000,
		];

		$cursors = [
			'@acme/core' => [
				'last_seen_tag'          => '@acme/core@2.0.0',
				'last_seen_published_at' => '2026-07-18T00:00:00Z',
			],
		];

		\WP_Mock::userFunction( 'get_option' )
			->once()
			->with( $this->option_key( 'owner/repo' ), [] )
			->andReturn( $stored );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				$this->option_key( 'owner/repo' ),
				\WP_Mock\Functions::type( 'array' ),
				false
			)
			->andReturnUsing( function ( $key, $data ) use ( $cursors ) {
				$this->assertSame( $cursors, $data['streams'] );
				$this->assertSame( Release_State::STREAM_STATE_VERSION, $data['stream_state_version'] );
				$this->assertSame( 'hash-abc', $data['policy_hash'] );
				$this->assertFalse( $data['onboarding_pending'] );
				$this->assertGreaterThan( 0, $data['streams_baseline_at'] );
				// Legacy display fields survive the transition.
				$this->assertSame( 'v8.0.0', $data['last_seen_tag'] );
				$this->assertSame( 1234567890, $data['last_checked_at'] );
				// The display-only naming observation survives too.
				$this->assertTrue( $data['multi_package_observed'] );
				// Unreleased-iteration keys are gone after the canonical write.
				$this->assertArrayNotHasKey( 'tracking_started_at', $data );
				$this->assertArrayNotHasKey( 'is_monorepo', $data );
				$this->assertArrayNotHasKey( 'topology_checked_at', $data );
				return true;
			} );

		$this->state->complete_baseline( 'owner/repo', $cursors, 'hash-abc' );
	}
}
