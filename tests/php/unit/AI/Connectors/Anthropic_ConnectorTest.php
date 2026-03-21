<?php
/**
 * Tests for AI\Connectors\Anthropic_Connector.
 *
 * @package ChangelogToBlogPost\Tests\AI\Connectors
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI\Connectors;

use TenUp\ChangelogToBlogPost\AI\Connectors\Anthropic_Connector;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use WP_Mock\Tools\TestCase;

class Anthropic_ConnectorTest extends TestCase {

	private Global_Settings $settings;
	private Anthropic_Connector $connector;
	private ReleaseData $release;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->settings  = $this->createMock( Global_Settings::class );
		$this->connector = new Anthropic_Connector( $this->settings );
		$this->release   = new ReleaseData(
			identifier:   'owner/plugin',
			tag:          'v3.0.0',
			name:         'Version 3.0.0',
			body:         '## Changes',
			html_url:     'https://github.com/owner/plugin/releases/tag/v3.0.0',
			published_at: '2026-01-01T00:00:00Z',
		);
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Metadata
	// -------------------------------------------------------------------------

	public function test_get_slug_returns_anthropic(): void {
		$this->assertSame( 'anthropic', $this->connector->get_slug() );
	}

	public function test_requires_api_key_returns_true(): void {
		$this->assertTrue( $this->connector->requires_api_key() );
	}

	// -------------------------------------------------------------------------
	// test_connection()
	// -------------------------------------------------------------------------

	public function test_test_connection_returns_wp_error_when_no_key(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'anthropic' => '' ] );

		$result = $this->connector->test_connection();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_anthropic_no_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — no key
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_when_no_key(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'anthropic' => '' ] );

		$result = $this->connector->generate_post( $this->release, 'prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_anthropic_no_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — 401 invalid key
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_on_401(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'anthropic' => 'sk-ant-invalid' ] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( Anthropic_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 401 );

		$result = $this->connector->generate_post( $this->release, 'prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_anthropic_invalid_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — 429 rate limit
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_on_429(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'anthropic' => 'sk-ant-valid' ] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( Anthropic_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 429 );

		$result = $this->connector->generate_post( $this->release, 'prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_anthropic_rate_limit', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — success
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_generated_post_on_success(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'anthropic' => 'sk-ant-valid' ] );

		$body_json = json_encode( [
			'content' => [ [ 'text' => "Blog Post Title\nFull body content here." ] ],
		] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( Anthropic_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body_json );
		\WP_Mock::userFunction( 'wpautop' )->andReturnArg( 0 );

		$result = $this->connector->generate_post( $this->release, 'prompt' );

		$this->assertInstanceOf( GeneratedPost::class, $result );
		$this->assertSame( 'Blog Post Title', $result->title );
		$this->assertSame( 'anthropic', $result->provider_slug );
	}
}
