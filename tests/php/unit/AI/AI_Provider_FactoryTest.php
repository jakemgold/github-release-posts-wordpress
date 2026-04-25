<?php
/**
 * Tests for AI\AI_Provider_Factory.
 *
 * @package ChangelogToBlogPost\Tests\AI
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI;

use TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory;
use TenUp\ChangelogToBlogPost\AI\AIProviderInterface;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use WP_Mock\Tools\TestCase;

class AI_Provider_FactoryTest extends TestCase {

	private Global_Settings $settings;
	private AI_Provider_Factory $factory;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->settings = $this->createMock( Global_Settings::class );
		$this->factory  = new AI_Provider_Factory( $this->settings );
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// get_provider()
	// -------------------------------------------------------------------------

	public function test_get_provider_returns_wp_error_when_no_provider_set(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( '' );

		\WP_Mock::onFilter( 'ctbp_register_ai_providers' )
			->with( \Mockery::type( 'array' ) )
			->reply( [] );

		$result = $this->factory->get_provider();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_no_provider', $result->get_error_code() );
	}

	public function test_get_provider_returns_wp_error_for_unknown_slug(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( 'unknown_provider' );

		\WP_Mock::onFilter( 'ctbp_register_ai_providers' )
			->with( \Mockery::type( 'array' ) )
			->reply( [] );

		$result = $this->factory->get_provider();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_unknown_provider', $result->get_error_code() );
	}

	public function test_get_provider_returns_connector_for_wp_ai_client(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( 'wp_ai_client' );

		// Let the filter pass through so built-in providers are kept.
		\WP_Mock::userFunction( 'apply_filters' )
			->with( 'ctbp_register_ai_providers', \WP_Mock\Functions::type( 'array' ) )
			->andReturnArg( 1 );

		$result = $this->factory->get_provider();

		$this->assertInstanceOf( AIProviderInterface::class, $result );
		$this->assertSame( 'wp_ai_client', $result->get_slug() );
	}

	// -------------------------------------------------------------------------
	// is_configured()
	// -------------------------------------------------------------------------

	public function test_is_configured_returns_false_when_no_provider(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( '' );

		$this->assertFalse( $this->factory->is_configured() );
	}

	public function test_is_configured_returns_true_when_provider_set(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( 'wp_ai_client' );

		$this->assertTrue( $this->factory->is_configured() );
	}

	// -------------------------------------------------------------------------
	// ctbp_register_ai_providers hook
	// -------------------------------------------------------------------------

	public function test_invalid_registered_provider_is_rejected(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( 'bad_provider' );

		// Register a non-interface value — should be stripped.
		\WP_Mock::onFilter( 'ctbp_register_ai_providers' )
			->with( \Mockery::type( 'array' ) )
			->reply( [ 'bad_provider' => new \stdClass() ] );

		$result = $this->factory->get_provider();

		// Provider rejected → unknown provider error.
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// -------------------------------------------------------------------------
	// get_available_providers()
	// -------------------------------------------------------------------------

	public function test_get_available_providers_returns_wp_ai_client(): void {
		$this->settings->method( 'get_ai_provider' )->willReturn( '' );

		// Let the filter pass through so built-in providers are kept.
		\WP_Mock::userFunction( 'apply_filters' )
			->with( 'ctbp_register_ai_providers', \WP_Mock\Functions::type( 'array' ) )
			->andReturnArg( 1 );

		$providers = $this->factory->get_available_providers();

		$this->assertArrayHasKey( 'wp_ai_client', $providers );
		$this->assertCount( 1, $providers );
	}
}
