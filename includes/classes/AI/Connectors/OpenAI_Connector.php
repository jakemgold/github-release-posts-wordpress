<?php
/**
 * AI provider connector for the OpenAI Chat Completions API.
 *
 * @package ChangelogToBlogPost\AI\Connectors
 */

namespace TenUp\ChangelogToBlogPost\AI\Connectors;

use TenUp\ChangelogToBlogPost\AI\AIProviderInterface;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;

/**
 * Calls the OpenAI Chat Completions endpoint directly using a site-owner-supplied API key.
 *
 * Default model: gpt-4o. Override via the `ctbp_openai_model` filter or the
 * custom model field in plugin settings.
 */
class OpenAI_Connector implements AIProviderInterface {

	/**
	 * Default model ID — update this constant when releasing new plugin versions.
	 */
	const DEFAULT_MODEL = 'o3';

	/**
	 * OpenAI Chat Completions endpoint.
	 */
	const API_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Constructor.
	 *
	 * @param Global_Settings $settings Plugin settings service.
	 */
	public function __construct( private readonly Global_Settings $settings ) {}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'OpenAI', 'changelog-to-blog-post' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function requires_api_key(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function test_connection(): true|\WP_Error {
		$key = $this->get_api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'ctbp_openai_no_key',
				__( 'No OpenAI API key has been saved. Please enter your key in the plugin settings.', 'changelog-to-blog-post' )
			);
		}

		// Minimal request — list models is a lightweight authenticated call.
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $key ],
				'timeout' => 10,
			]
		);

		return $this->check_response_error( $response );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param ReleaseData $data   Structured release data.
	 * @param string      $prompt Fully assembled prompt string.
	 */
	public function generate_post( ReleaseData $data, string $prompt ): GeneratedPost|\WP_Error {
		$key = $this->get_api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'ctbp_openai_no_key',
				__( 'No OpenAI API key has been saved.', 'changelog-to-blog-post' )
			);
		}

		$body = wp_json_encode(
			[
				'model'    => $this->get_model(),
				'messages' => [
					[
						'role'    => 'user',
						'content' => $prompt,
					],
				],
			]
		);

		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				],
				'body'    => $body,
				'timeout' => 120,
			]
		);

		$error = $this->check_response_error( $response );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'ctbp_openai_invalid_json',
				__( 'OpenAI returned an invalid response. Please try again.', 'changelog-to-blog-post' )
			);
		}

		$text = $decoded['choices'][0]['message']['content']
			?? $decoded['output'][0]['content'][0]['text']
			?? '';

		if ( '' === $text ) {
			return new \WP_Error(
				'ctbp_openai_empty_response',
				__( 'OpenAI returned an empty response. Please try again.', 'changelog-to-blog-post' )
			);
		}

		return $this->parse_response( $text, $data );
	}

	/**
	 * Returns the model ID to use, respecting the custom model and filter hierarchy.
	 *
	 * Priority (highest first):
	 *   1. Custom model ID saved in plugin settings.
	 *   2. `ctbp_openai_model` filter value.
	 *   3. DEFAULT_MODEL constant.
	 *
	 * @return string
	 */
	private function get_model(): string {
		$custom_models = (array) get_option( Plugin_Constants::OPTION_AI_CUSTOM_MODELS, [] );
		if ( ! empty( $custom_models['openai'] ) ) {
			return (string) $custom_models['openai'];
		}

		/**
		 * Filters the OpenAI model used for generation.
		 *
		 * @param string $model Default model ID.
		 */
		return (string) apply_filters( 'ctbp_openai_model', self::DEFAULT_MODEL );
	}

	/**
	 * Returns the decrypted OpenAI API key.
	 *
	 * @return string Plaintext key or empty string if not set.
	 */
	private function get_api_key(): string {
		$keys = $this->settings->get_api_keys();
		return $keys['openai'] ?? '';
	}

	/**
	 * Checks a wp_remote_* response for transport or HTTP errors.
	 *
	 * @param array|\WP_Error $response The wp_remote_* response or WP_Error on transport failure.
	 * @return true|\WP_Error
	 */
	private function check_response_error( array|\WP_Error $response ): true|\WP_Error {
		if ( is_wp_error( $response ) ) {
			if ( str_contains( $response->get_error_message(), 'cURL error 28' ) ) {
				return new \WP_Error(
					'ctbp_openai_timeout',
					__( 'The OpenAI API took too long to respond. This can happen with complex releases. Please try again.', 'changelog-to-blog-post' )
				);
			}
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return new \WP_Error(
				'ctbp_openai_invalid_key',
				__( 'Your OpenAI API key is invalid or has expired. Please update it in the plugin settings.', 'changelog-to-blog-post' )
			);
		}

		if ( 429 === $code ) {
			return new \WP_Error(
				'ctbp_openai_quota',
				__( 'OpenAI rate limit or quota exceeded. Generation will be retried on the next scheduled run.', 'changelog-to-blog-post' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			// Parse the API error body for a more specific message.
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = $body['error']['message'] ?? '';

			if ( '' !== $message ) {
				return new \WP_Error( 'ctbp_openai_api_error', $message );
			}

			return new \WP_Error(
				'ctbp_openai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'OpenAI returned HTTP %d. Generation will be retried on the next scheduled run.', 'changelog-to-blog-post' ),
					$code
				)
			);
		}

		return true;
	}

	/**
	 * Parses the raw text response into a GeneratedPost.
	 *
	 * @param string      $raw  Raw completion text.
	 * @param ReleaseData $data Source release (used for fallback title).
	 * @return GeneratedPost
	 */
	private function parse_response( string $raw, ReleaseData $data ): GeneratedPost {
		return GeneratedPost::from_raw_text( $raw, $data, $this->get_slug() );
	}
}
