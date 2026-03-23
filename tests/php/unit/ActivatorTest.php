<?php
/**
 * Tests for Activator class.
 *
 * @package ChangelogToBlogPost\Tests
 */

namespace TenUp\ChangelogToBlogPost\Tests;

use TenUp\ChangelogToBlogPost\Activator;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use WP_Mock\Tools\TestCase;

/**
 * Tests the activation and deactivation lifecycle handlers.
 */
class ActivatorTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Activation tests
	// -------------------------------------------------------------------------

	/**
	 * Activation returns early if current user lacks manage_options.
	 */
	public function test_activate_returns_early_without_capability(): void {
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->once()
			->andReturn( false );

		// add_option and cron functions should NOT be called.
		\WP_Mock::userFunction( 'add_option' )->never();
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->never();
		\WP_Mock::userFunction( 'wp_schedule_event' )->never();

		Activator::activate();

		$this->assertConditionsMet();
	}

	/**
	 * Activation writes all default options via add_option().
	 */
	public function test_activate_writes_default_options(): void {
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->once()
			->andReturn( true );

		$defaults = Plugin_Constants::get_defaults();

		foreach ( $defaults as $key => $value ) {
			\WP_Mock::userFunction( 'add_option' )
				->with( $key, $value, '', false )
				->once();
		}

		// Stub cron functions.
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( null );
		\WP_Mock::onFilter( 'ctbp_check_frequency' )->with( 'daily' )->reply( 'daily' );
		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_schedule_event' )->andReturn( true );

		Activator::activate();

		$this->assertConditionsMet();
	}

	/**
	 * Activation clears any stale cron event before registering a new one (AC-004).
	 */
	public function test_activate_clears_stale_cron_before_registering(): void {
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_options' )
			->andReturn( true );

		\WP_Mock::userFunction( 'add_option' )->andReturn( true );

		// The stale event must be cleared first.
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
			->with( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->once();

		\WP_Mock::onFilter( 'ctbp_check_frequency' )->with( 'daily' )->reply( 'daily' );

		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_schedule_event' )
			->with( \WP_Mock\Functions::anyOf( time() ), 'daily', Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->once();

		Activator::activate();

		$this->assertConditionsMet();
	}

	/**
	 * Activation does not register a duplicate cron event if one is already scheduled (BR-001).
	 */
	public function test_activate_does_not_duplicate_cron_event(): void {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( null );
		\WP_Mock::onFilter( 'ctbp_check_frequency' )->with( 'daily' )->reply( 'daily' );

		// Simulate already-scheduled event.
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->andReturn( time() + 3600 );

		// wp_schedule_event must NOT be called.
		\WP_Mock::userFunction( 'wp_schedule_event' )->never();

		Activator::activate();

		$this->assertConditionsMet();
	}

	/**
	 * The ctbp_check_frequency filter value is used when registering the cron event (AC-008).
	 */
	public function test_register_cron_event_uses_filter_value(): void {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'add_option' )->andReturn( true );
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )->andReturn( null );

		// Developer filters to 'hourly'.
		\WP_Mock::onFilter( 'ctbp_check_frequency' )->with( 'daily' )->reply( 'hourly' );

		\WP_Mock::userFunction( 'wp_next_scheduled' )->andReturn( false );

		\WP_Mock::userFunction( 'wp_schedule_event' )
			->once()
			->andReturnUsing( function ( $timestamp, $recurrence, $hook ) {
				$this->assertSame( 'hourly', $recurrence );
				return true;
			} );

		Activator::activate();
	}

	// -------------------------------------------------------------------------
	// Deactivation tests
	// -------------------------------------------------------------------------

	/**
	 * Deactivation clears the recurring release-check cron event.
	 */
	public function test_deactivate_clears_release_check_cron(): void {
		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
			->with( Plugin_Constants::CRON_HOOK_RELEASE_CHECK )
			->once();

		\WP_Mock::userFunction( 'wp_clear_scheduled_hook' )
			->with( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY )
			->once();

		Activator::deactivate();

		$this->assertConditionsMet();
	}
}
