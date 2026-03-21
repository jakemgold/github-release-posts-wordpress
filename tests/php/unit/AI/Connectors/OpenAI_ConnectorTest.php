<?php
/**
 * Tests for AI\Connectors\OpenAI_Connector.
 *
 * @package ChangelogToBlogPost\Tests\AI\Connectors
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI\Connectors;

use TenUp\ChangelogToBlogPost\AI\Connectors\OpenAI_Connector;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use WP_Mock\Tools\TestCase;

class OpenAI_ConnectorTest extends TestCase {

	private Global_Settings $settings;
	private OpenAI_Connector $connector;
	private ReleaseData $release;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->settings  = $this->createMock( Global_Settings::class );
		$this->connector = new OpenAI_Connector( $this->settings );
		$this->release   = new ReleaseData(
			identifier:   'owner/plugin',
			tag:          'v2.0.0',
			name:         'Version 2.0.0',
			body:         '## Changes',
			html_url:     'https://github.com/owner/plugin/releases/tag/v2.0.0',
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

	public function test_get_slug_returns_openai(): void {
		$this->assertSame( 'openai', $this->connector->get_slug() );
	}

	public function test_requires_api_key_returns_true(): void {
		$this->assertTrue( $this->connector->requires_api_key() );
	}

	// -------------------------------------------------------------------------
	// test_connection()
	// -------------------------------------------------------------------------

	public function test_test_connection_returns_wp_error_when_no_key(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => '' ] );

		$result = $this->connector->test_connection();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_openai_no_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — no key
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_when_no_key(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => '' ] );

		$result = $this->connector->generate_post( $this->release, 'prompt text' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_openai_no_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — 401 invalid key
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_on_401(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => 'sk-invalid' ] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( OpenAI_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 401 ] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 401 );

		$result = $this->connector->generate_post( $this->release, 'prompt text' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_openai_invalid_key', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — 429 rate limit
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_wp_error_on_429(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => 'sk-valid' ] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( OpenAI_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [ 'response' => [ 'code' => 429 ] ] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 429 );

		$result = $this->connector->generate_post( $this->release, 'prompt text' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ctbp_openai_quota', $result->get_error_code() );
	}

	// -------------------------------------------------------------------------
	// generate_post() — success
	// -------------------------------------------------------------------------

	public function test_generate_post_returns_generated_post_on_success(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => 'sk-valid' ] );

		$body_json = json_encode( [
			'choices' => [ [ 'message' => [ 'content' => "Post Title\nPost body content." ] ] ],
		] );

		\WP_Mock::userFunction( 'get_option' )->andReturn( [] );
		\WP_Mock::userFunction( 'apply_filters' )->andReturn( OpenAI_Connector::DEFAULT_MODEL );
		\WP_Mock::userFunction( 'wp_json_encode' )->andReturn( '{}' );
		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn( $body_json );
		\WP_Mock::userFunction( 'wpautop' )->andReturnArg( 0 );

		$result = $this->connector->generate_post( $this->release, 'prompt text' );

		$this->assertInstanceOf( GeneratedPost::class, $result );
		$this->assertSame( 'Post Title', $result->title );
		$this->assertSame( 'openai', $result->provider_slug );
	}

	// -------------------------------------------------------------------------
	// Model priority (AC-024, AC-025, AC-026)
	// -------------------------------------------------------------------------

	public function test_custom_model_takes_precedence_over_filter_and_default(): void {
		$this->settings->method( 'get_api_keys' )->willReturn( [ 'openai' => 'sk-valid' ] );

		// Custom model stored in options.
		\WP_Mock::userFunction( 'get_option' )
			->with( \TenUp\ChangelogToBlogPost\Plugin_Constants::OPTION_AI_CUSTOM_MODELS, [] )
			->andReturn( [ 'openai' => 'gpt-4-turbo' ] );

		$captured_body = null;
		\WP_Mock::userFunction( 'wp_json_encode' )
			->andReturnUsing( function ( $data ) use ( &$captured_body ) {
				$captured_body = $data;
				return json_encode( $data );
			} );

		\WP_Mock::userFunction( 'wp_remote_post' )->andReturn( [] );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
		\WP_Mock::userFunction( 'wp_remote_retrieve_body' )->andReturn(
			json_encode( [ 'choices' => [ [ 'message' => [ 'content' => "Title\nBody" ] ] ] ] )
		);
		\WP_Mock::userFunction( 'wpautop' )->andReturnArg( 0 );

		$this->connector->generate_post( $this->release, 'prompt' );

		$this->assertSame( 'gpt-4-turbo', $captured_body['model'] ?? null );
	}
}
