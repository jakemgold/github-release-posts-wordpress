<?php
/**
 * Tests for GitHub\Release_State.
 *
 * @package ChangelogToBlogPost\Tests\GitHub
 */

namespace TenUp\ChangelogToBlogPost\Tests\GitHub;

use TenUp\ChangelogToBlogPost\GitHub\Release_State;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
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
				\WP_Mock\Functions\type( 'array' ),
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
				\WP_Mock\Functions\type( 'array' ),
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
}
