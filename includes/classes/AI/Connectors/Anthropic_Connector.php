<?php
/**
 * AI provider connector for the Anthropic Messages API.
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
 * Calls the Anthropic Messages API directly using a site-owner-supplied API key.
 *
 * Default model: claude-sonnet-4-5-20251022. Override via the `ctbp_anthropic_model`
 * filter or the custom model field in plugin settings.
 */
class Anthropic_Connector implements AIProviderInterface {

	/**
	 * Default model ID — update this constant when releasing new plugin versions.
	 */
	const DEFAULT_MODEL = 'claude-sonnet-4-5-20251022';

	/**
	 * Anthropic Messages API endpoint.
	 */
	const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Anthropic API version header value.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * @param Global_Settings $settings Plugin settings service.
	 */
	public function __construct( private readonly Global_Settings $settings ) {}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return __( 'Anthropic (Claude)', 'changelog-to-blog-post' );
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
				'ctbp_anthropic_no_key',
				__( 'No Anthropic API key has been saved. Please enter your key in the plugin settings.', 'changelog-to-blog-post' )
			);
		}

		// Minimal request — send a tiny prompt with max_tokens=1 to verify auth.
		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'headers' => $this->build_headers( $key ),
				'body'    => wp_json_encode( [
					'model'      => $this->get_model(),
					'max_tokens' => 1,
					'messages'   => [ [ 'role' => 'user', 'content' => 'ping' ] ],
				] ),
				'timeout' => 15,
			]
		);

		return $this->check_response_error( $response );
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_post( ReleaseData $data, string $prompt ): GeneratedPost|\WP_Error {
		$key = $this->get_api_key();
		if ( '' === $key ) {
			return new \WP_Error(
				'ctbp_anthropic_no_key',
				__( 'No Anthropic API key has been saved.', 'changelog-to-blog-post' )
			);
		}

		$response = wp_remote_post(
			self::API_ENDPOINT,
			[
				'headers' => $this->build_headers( $key ),
				'body'    => wp_json_encode( [
					'model'      => $this->get_model(),
					'max_tokens' => 2048,
					'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
				] ),
				'timeout' => 60,
			]
		);

		$error = $this->check_response_error( $response );
		if ( is_wp_error( $error ) ) {
			return $error;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$text    = $decoded['content'][0]['text'] ?? '';

		if ( '' === $text ) {
			return new \WP_Error(
				'ctbp_anthropic_empty_response',
				__( 'Anthropic returned an empty response. Please try again.', 'changelog-to-blog-post' )
			);
		}

		return $this->parse_response( $text, $data );
	}

	/**
	 * Builds the required Anthropic request headers.
	 *
	 * @param string $key API key.
	 * @return array<string, string>
	 */
	private function build_headers( string $key ): array {
		return [
			'x-api-key'         => $key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		];
	}

	/**
	 * Returns the model ID to use, respecting the custom model and filter hierarchy.
	 *
	 * Priority (highest first):
	 *   1. Custom model ID saved in plugin settings.
	 *   2. `ctbp_anthropic_model` filter value.
	 *   3. DEFAULT_MODEL constant.
	 *
	 * @return string
	 */
	private function get_model(): string {
		$custom_models = (array) get_option( Plugin_Constants::OPTION_AI_CUSTOM_MODELS, [] );
		if ( ! empty( $custom_models['anthropic'] ) ) {
			return (string) $custom_models['anthropic'];
		}

		/**
		 * Filters the Anthropic model used for generation.
		 *
		 * @param string $model Default model ID.
		 */
		return (string) apply_filters( 'ctbp_anthropic_model', self::DEFAULT_MODEL );
	}

	/**
	 * Returns the decrypted Anthropic API key.
	 *
	 * @return string Plaintext key or empty string if not set.
	 */
	private function get_api_key(): string {
		$keys = $this->settings->get_api_keys();
		return $keys['anthropic'] ?? '';
	}

	/**
	 * Checks a wp_remote_* response for transport or HTTP errors.
	 *
	 * @param array|\WP_Error $response
	 * @return true|\WP_Error
	 */
	private function check_response_error( array|\WP_Error $response ): true|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return new \WP_Error(
				'ctbp_anthropic_invalid_key',
				__( 'Your Anthropic API key is invalid or has expired. Please update it in the plugin settings.', 'changelog-to-blog-post' )
			);
		}

		if ( 429 === $code ) {
			return new \WP_Error(
				'ctbp_anthropic_rate_limit',
				__( 'Anthropic rate limit exceeded. Generation will be retried on the next scheduled run.', 'changelog-to-blog-post' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error(
				'ctbp_anthropic_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Anthropic returned HTTP %d. Generation will be retried on the next scheduled run.', 'changelog-to-blog-post' ),
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
		$raw   = trim( $raw );
		$lines = explode( "\n", $raw, 2 );
		$title = trim( $lines[0] ?? '' );
		$body  = trim( $lines[1] ?? '' );

		if ( '' === $title ) {
			$title = sprintf(
				/* translators: 1: repo identifier 2: release tag */
				__( '%1$s %2$s Release Notes', 'changelog-to-blog-post' ),
				$data->identifier,
				$data->tag
			);
		}

		return new GeneratedPost(
			title:         $title,
			content:       wpautop( $body ),
			provider_slug: $this->get_slug(),
		);
	}
}
