<?php
/**
 * AI provider connector for the WordPress Connectors / AI Client API.
 *
 * @package ChangelogToBlogPost\AI\Connectors
 */

namespace TenUp\ChangelogToBlogPost\AI\Connectors;

use TenUp\ChangelogToBlogPost\AI\AIProviderInterface;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;

/**
 * Delegates generation to the WordPress AI Client API (Connectors).
 *
 * In WordPress 7.0+ the AI Client is built into core. On earlier versions
 * it's available via the wp-ai-client plugin. Site owners configure their
 * preferred AI connector (Anthropic, OpenAI, Google, etc.) once under
 * Settings → AI Credentials, and this connector routes requests through
 * whichever service they've set up — no separate API key entry needed in
 * this plugin.
 *
 * Uses model preferences (option 3): specifies a list of preferred models
 * and falls back to automatic selection if none are available on the site.
 */
class WP_AI_Client_Connector implements AIProviderInterface {

	/**
	 * Request timeout in seconds for AI generation calls.
	 *
	 * Blog post generation with reasoning models can take well over 30s.
	 */
	const REQUEST_TIMEOUT = 120;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'wp_ai_client';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'WordPress Connectors', 'changelog-to-blog-post' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Credentials are managed by WordPress Connectors — no separate key
	 * entry is required in this plugin's settings.
	 */
	public function requires_api_key(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns WP_Error if the WordPress AI Client API is not available.
	 */
	public function test_connection(): true|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'ctbp_wp_ai_client_unavailable',
				__( 'The WordPress AI Client API is not available. On WordPress 7.0+ it is built in — make sure at least one AI connector is activated under Settings → AI Credentials. On older versions, install and activate the wp-ai-client plugin.', 'changelog-to-blog-post' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ReleaseData $data   Structured release data.
	 * @param string      $prompt Fully assembled prompt string.
	 */
	public function generate_post( ReleaseData $data, string $prompt ): GeneratedPost|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'ctbp_wp_ai_client_unavailable',
				__( 'The WordPress AI Client API is not available.', 'changelog-to-blog-post' )
			);
		}

		// Temporarily override the default request timeout for this call.
		// Blog post generation typically needs 60-120s, well above the 30s default.
		$timeout_override = static function () {
			return self::REQUEST_TIMEOUT;
		};
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_override );

		$builder = wp_ai_client_prompt( $prompt ) // phpcs:ignore
			->using_max_tokens( 16384 );

		remove_filter( 'wp_ai_client_default_request_timeout', $timeout_override );

		$this->configure_model( $builder );

		$response = $builder->generate_text();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = (string) $response;
		if ( '' === trim( $text ) ) {
			return new \WP_Error(
				'ctbp_wp_ai_client_empty_response',
				__( 'WordPress Connectors returned an empty response. Please try again.', 'changelog-to-blog-post' )
			);
		}

		return $this->parse_response( $text, $data );
	}

	/**
	 * Configures model preferences and provider-specific options on the builder.
	 *
	 * Checks which AI providers are registered and configured on the site,
	 * then applies the appropriate model preferences. When only an OpenAI-
	 * compatible provider is available (i.e., no higher-priority Anthropic
	 * or Google provider will be selected), also sets reasoning effort via
	 * ModelConfig custom options.
	 *
	 * @param object $builder The WP_AI_Client_Prompt_Builder instance.
	 * @return void
	 */
	private function configure_model( object $builder ): void {
		$preferences = $this->get_model_preferences();
		if ( ! empty( $preferences ) ) {
			$builder->using_model_preference( ...$preferences );
		}

		// Apply OpenAI reasoning effort only when an OpenAI provider will
		// handle the request. Custom options are passed directly into the
		// API request body, so provider-specific parameters like `reasoning`
		// would cause errors if sent to Anthropic or Google.
		if ( $this->will_use_openai_provider() ) {
			$this->apply_openai_config( $builder );
		}
	}

	/**
	 * Returns the ordered list of preferred model IDs.
	 *
	 * The AI Client will try each model in order and use the first one
	 * available via the site's configured connectors. If none match, it
	 * falls back to automatic model selection.
	 *
	 * @return array<int, string> Model IDs in preference order.
	 */
	private function get_model_preferences(): array {
		$defaults = [
			'claude-opus-4-7',
			'gpt-5.5',
			'gemini-2.5-pro',
		];

		/**
		 * Filters the preferred model list for the WordPress Connectors provider.
		 *
		 * Models are tried in order; the first available model on the site is
		 * used. If none are available, the AI Client falls back to automatic
		 * selection. Return an empty array to always use automatic selection.
		 *
		 * @param string[] $preferences Model IDs in preference order.
		 */
		return (array) apply_filters( 'ctbp_wp_ai_client_model_preferences', $defaults );
	}

	/**
	 * Determines whether the request will likely be handled by an OpenAI provider.
	 *
	 * Returns true only when an OpenAI-compatible provider is configured AND
	 * no higher-priority provider (Anthropic, Google) is also configured.
	 * When multiple providers are active, the model preference list determines
	 * which one is used — since Anthropic and Google models appear before
	 * OpenAI in the defaults, OpenAI config should not be applied when those
	 * providers are available.
	 *
	 * @return bool
	 */
	private function will_use_openai_provider(): bool {
		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return false;
		}

		$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();

		$has_openai          = false;
		$has_higher_priority = false;

		foreach ( $provider_ids as $id ) {
			if ( ! $registry->isProviderConfigured( $id ) ) {
				continue;
			}

			if ( str_contains( $id, 'openai' ) ) {
				$has_openai = true;
			}

			if ( str_contains( $id, 'anthropic' ) || str_contains( $id, 'google' ) ) {
				$has_higher_priority = true;
			}
		}

		return $has_openai && ! $has_higher_priority;
	}

	/**
	 * Applies OpenAI-specific configuration via ModelConfig custom options.
	 *
	 * Sets reasoning effort to "high" for GPT-5.x models which support
	 * controllable reasoning. Custom options are passed directly into the
	 * API request body by the OpenAI-compatible provider implementation.
	 *
	 * Only called when an OpenAI provider is the active/configured provider
	 * to avoid sending provider-specific parameters to other APIs.
	 *
	 * @param object $builder The WP_AI_Client_Prompt_Builder instance.
	 * @return void
	 */
	private function apply_openai_config( object $builder ): void {
		if ( ! class_exists( 'WordPress\AiClient\Providers\Models\DTO\ModelConfig' ) ) {
			return;
		}

		/**
		 * Filters the reasoning effort level for OpenAI models.
		 *
		 * GPT-5.x models support controllable reasoning via this parameter.
		 * Valid values: 'low', 'medium', 'high'. Return empty string to skip.
		 *
		 * @param string $effort Reasoning effort level. Default 'high'.
		 */
		$effort = (string) apply_filters( 'ctbp_openai_reasoning_effort', 'high' );

		if ( '' === $effort ) {
			return;
		}

		$config = \WordPress\AiClient\Providers\Models\DTO\ModelConfig::fromArray(
			[
				'customOptions' => [
					'reasoning' => [ 'effort' => $effort ],
				],
			]
		);

		$builder->using_model_config( $config );
	}

	/**
	 * Checks whether the WordPress AI Client API is available.
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Parses a plain-text AI response into a GeneratedPost.
	 *
	 * Expects the model to return the title on the first line, followed by
	 * the body. Falls back gracefully if the format is unexpected.
	 *
	 * @param string      $raw  Raw text response from the AI provider.
	 * @param ReleaseData $data Source release data (used for fallback title).
	 * @return GeneratedPost
	 */
	private function parse_response( string $raw, ReleaseData $data ): GeneratedPost {
		return GeneratedPost::from_raw_text( $raw, $data, $this->get_slug() );
	}
}
