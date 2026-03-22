<?php
/**
 * AI provider connector for the WordPress AI Services (wp-ai-client) plugin.
 *
 * @package ChangelogToBlogPost\AI\Connectors
 */

namespace TenUp\ChangelogToBlogPost\AI\Connectors;

use TenUp\ChangelogToBlogPost\AI\AIProviderInterface;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;

/**
 * Delegates generation to the official WordPress AI Services plugin
 * (https://github.com/WordPress/wp-ai-client).
 *
 * Non-technical users configure their preferred AI provider (Anthropic, OpenAI,
 * Google Gemini, etc.) once via Settings > AI Services, and this connector
 * routes requests through whichever service they've set up — no separate API
 * key entry needed in this plugin.
 *
 * This connector is the recommended primary option for most site owners.
 */
class WP_AI_Client_Connector implements AIProviderInterface {

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
		return __( 'WordPress AI Services', 'changelog-to-blog-post' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Credentials are managed by the WordPress AI Services plugin — no
	 * separate key entry is required in this plugin's settings.
	 */
	public function requires_api_key(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Returns WP_Error if the WordPress AI Services plugin is not active or
	 * has no configured provider.
	 */
	public function test_connection(): true|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'ctbp_wp_ai_client_unavailable',
				__( 'The WordPress AI Services plugin is not active. Please install and activate it from WordPress.org, then configure an AI provider under Settings > AI Services.', 'changelog-to-blog-post' )
			);
		}

		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate_post( ReleaseData $data, string $prompt ): GeneratedPost|\WP_Error {
		if ( ! $this->is_available() ) {
			return new \WP_Error(
				'ctbp_wp_ai_client_unavailable',
				__( 'The WordPress AI Services plugin is not active.', 'changelog-to-blog-post' )
			);
		}

		$response = wp_ai_client_prompt( $prompt )->generate_text(); // phpcs:ignore

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_response( (string) $response, $data );
	}

	/**
	 * Checks whether the WordPress AI Services plugin is loaded and functional.
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
