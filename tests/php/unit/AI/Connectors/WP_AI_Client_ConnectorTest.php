<?php
/**
 * Tests for AI\Connectors\WP_AI_Client_Connector.
 *
 * @package GitHubReleasePosts\Tests\AI\Connectors
 */

namespace GitHubReleasePosts\Tests\AI\Connectors;

use GitHubReleasePosts\AI\Connectors\WP_AI_Client_Connector;
use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\Cache_Keys;
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

	// -------------------------------------------------------------------------
	// Model preferences — auto-resolved Claude default with pinned fallback
	// -------------------------------------------------------------------------

	/**
	 * With the AI Client library absent (as in the test env), resolution can't
	 * run, so both provider entries lead with their pinned fallback.
	 */
	public function test_model_preferences_fall_back_to_pinned_models_when_unresolvable(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $hook, $value ) {
				return $value;
			}
		);

		$prefs = $this->invoke_get_model_preferences();

		$this->assertSame( 'claude-opus-4-8', $prefs[0] );
		$this->assertSame( 'gpt-5.5', $prefs[1] );
		$this->assertSame( 'gemini-2.5-pro', $prefs[2] );
	}

	/**
	 * Previously resolved models cached in the per-provider transients are used
	 * directly, without re-running resolution — keeping it off the hot path.
	 */
	public function test_model_preferences_use_cached_resolved_models(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
			function ( $key ) {
				if ( Cache_Keys::resolved_model( 'anthropic' ) === $key ) {
					return 'claude-opus-5-0';
				}
				if ( Cache_Keys::resolved_model( 'openai' ) === $key ) {
					return 'gpt-9.9';
				}
				if ( Cache_Keys::resolved_model( 'google' ) === $key ) {
					return 'gemini-9.9-pro';
				}
				return false;
			}
		);
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $hook, $value ) {
				return $value;
			}
		);

		$prefs = $this->invoke_get_model_preferences();

		$this->assertSame( 'claude-opus-5-0', $prefs[0] );
		$this->assertSame( 'gpt-9.9', $prefs[1] );
		$this->assertSame( 'gemini-9.9-pro', $prefs[2] );
	}

	/**
	 * get_active_selection() returns null when the AI Client library is absent
	 * (as in the unit-test env), so callers fall back gracefully.
	 */
	public function test_get_active_selection_returns_null_without_library(): void {
		$this->assertNull( $this->connector->get_active_selection() );
	}

	/**
	 * The reasoning effort surfaced to both the applied config and the settings
	 * status defaults to 'high' (filterable via ghrp_openai_reasoning_effort).
	 */
	public function test_openai_reasoning_effort_defaults_to_high(): void {
		\WP_Mock::userFunction( 'apply_filters' )->andReturnUsing(
			function ( $hook, $value ) {
				return $value;
			}
		);
		$method = new \ReflectionMethod( WP_AI_Client_Connector::class, 'openai_reasoning_effort' );
		$this->assertSame( 'high', $method->invoke( $this->connector ) );
	}

	/**
	 * Invokes the private get_model_preferences() and returns the resolved list.
	 *
	 * @return array<int, string>
	 */
	private function invoke_get_model_preferences(): array {
		$method = new \ReflectionMethod( WP_AI_Client_Connector::class, 'get_model_preferences' );
		return (array) $method->invoke( $this->connector );
	}

	// -------------------------------------------------------------------------
	// post_from_result() — only complete posts are saved
	// -------------------------------------------------------------------------

	/**
	 * Builds a stand-in for the builder's GenerativeAiResult.
	 *
	 * @param string $text   Response text.
	 * @param string $finish 'stop', 'length', or 'content_filter'.
	 * @return object
	 */
	private function make_result( string $text, string $finish ): object {
		$reason = new class( $finish ) {
			public function __construct( private string $finish ) {}
			public function isStop(): bool {
				return 'stop' === $this->finish;
			}
			public function isLength(): bool {
				return 'length' === $this->finish;
			}
			public function isContentFilter(): bool {
				return 'content_filter' === $this->finish;
			}
		};
		$candidate = new class( $reason ) {
			public function __construct( private object $reason ) {}
			public function getFinishReason(): object {
				return $this->reason;
			}
		};

		return new class( $candidate, $text ) {
			public function __construct( private object $candidate, private string $text ) {}
			public function getCandidates(): array {
				return [ $this->candidate ];
			}
			public function toText(): string {
				return $this->text;
			}
		};
	}

	private function post_from_result( object $result ): mixed {
		\WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing( static fn( $text ) => trim( strip_tags( (string) $text ) ) );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$method = new \ReflectionMethod( WP_AI_Client_Connector::class, 'post_from_result' );
		return $method->invoke( $this->connector, $result, $this->release );
	}

	/**
	 * A response that did not finish normally — cut off at the token limit,
	 * stopped by a content filter or a provider error — is an error, never a
	 * post — it was saved and, on repositories set to
	 * publish, published mid-sentence.
	 *
	 * @dataProvider incomplete_finish_provider
	 */
	public function test_cut_off_response_is_an_error( string $finish ): void {
		$result = $this->post_from_result( $this->make_result( "Title\nkeywords\nExcerpt.\n\n<p>Half a sente", $finish ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ghrp_wp_ai_client_incomplete', $result->get_error_code() );
	}

	public static function incomplete_finish_provider(): array {
		return [
			'token limit'    => [ 'length' ],
			'content filter' => [ 'content_filter' ],
			'provider error' => [ 'error' ],
		];
	}

	/**
	 * A title with no body — a refusal, or a reply that stopped after the
	 * metadata lines — is an error, not a post.
	 */
	public function test_response_without_a_body_is_an_error(): void {
		$result = $this->post_from_result( $this->make_result( "I'm sorry, but I can't help with that.", 'stop' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ghrp_wp_ai_client_no_body', $result->get_error_code() );
	}

	public function test_complete_response_becomes_a_post(): void {
		$result = $this->post_from_result( $this->make_result( "A title\nsome-keywords\nAn excerpt.\n\n<p>The body.</p>", 'stop' ) );

		$this->assertInstanceOf( GeneratedPost::class, $result );
		$this->assertSame( '<p>The body.</p>', $result->content );
	}
}
