<?php
/**
 * Tests for AI\Connectors\WP_AI_Client_Connector.
 *
 * @package GitHubReleasePosts\Tests\AI\Connectors
 */

namespace Jakemgold\GitHubReleasePosts\Tests\AI\Connectors;

use Jakemgold\GitHubReleasePosts\AI\Connectors\WP_AI_Client_Connector;
use Jakemgold\GitHubReleasePosts\AI\GeneratedPost;
use Jakemgold\GitHubReleasePosts\AI\ReleaseData;
use WP_Mock\Tools\TestCase;

class WP_AI_Client_ConnectorTest extends TestCase {

	private WP_AI_Client_Connector $connector;

	private ReleaseData $release;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->connector = new WP_AI_Client_Connector();
		$this->release   = new ReleaseData(
			identifier:   'owner/plugin',
			tag:          'v1.0.0',
			name:         'v1.0.0',
			body:         '',
			html_url:     '',
			published_at: '2026-01-01T00:00:00Z',
		);
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_get_slug_returns_wp_ai_client(): void {
		$this->assertSame( 'wp_ai_client', $this->connector->get_slug() );
	}

	public function test_get_label_returns_wordpress_connectors(): void {
		$this->assertSame( 'WordPress Connectors', $this->connector->get_label() );
	}

	public function test_requires_api_key_returns_false(): void {
		$this->assertFalse( $this->connector->requires_api_key() );
	}

	public function test_test_connection_returns_wp_error_when_plugin_not_active(): void {
		// wp_ai_client_prompt() is not defined in test environment.
		$result = $this->connector->test_connection();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ghrp_wp_ai_client_unavailable', $result->get_error_code() );
	}

	public function test_generate_post_returns_wp_error_when_plugin_not_active(): void {
		$result = $this->connector->generate_post( $this->release, 'prompt' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ghrp_wp_ai_client_unavailable', $result->get_error_code() );
	}
}
