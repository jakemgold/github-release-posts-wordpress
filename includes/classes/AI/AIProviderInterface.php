<?php
/**
 * Contract that every AI provider connector must satisfy.
 *
 * @package GitHubReleasePosts\AI
 */

namespace Jakemgold\GitHubReleasePosts\AI;

/**
 * All AI provider connectors must implement this interface.
 *
 * The rest of the plugin calls only these methods — provider-specific logic
 * (authentication, HTTP format, response parsing) is entirely isolated inside
 * each connector class.
 */
interface AIProviderInterface {

	/**
	 * Generates a blog post from release data and a pre-built prompt.
	 *
	 * @param ReleaseData $data   Structured release data.
	 * @param string      $prompt Ready-to-send prompt string (built by EPC-05.2).
	 * @return GeneratedPost|\WP_Error Generated post on success, WP_Error on failure.
	 */
	public function generate_post( ReleaseData $data, string $prompt ): GeneratedPost|\WP_Error;

	/**
	 * Verifies the connector can reach its AI service with the current configuration.
	 *
	 * Used by the "Test connection" button in settings.
	 *
	 * @return true|\WP_Error True on success, WP_Error with a diagnostic message on failure.
	 */
	public function test_connection(): true|\WP_Error;

	/**
	 * Returns the unique machine-readable identifier for this provider.
	 *
	 * Must match the value stored in OPTION_AI_PROVIDER.
	 *
	 * @return string Provider slug (e.g. 'wp_ai_client').
	 */
	public function get_slug(): string;

	/**
	 * Returns the human-readable display name for this provider.
	 *
	 * Shown in the provider selector in plugin settings.
	 *
	 * @return string Display label (e.g. 'WordPress Connectors').
	 */
	public function get_label(): string;

	/**
	 * Indicates whether this connector requires a separately-stored API key.
	 *
	 * When true, the settings UI shows an API key input for this provider.
	 * When false (e.g. wp_ai_client), credentials are managed by another plugin.
	 *
	 * @return bool
	 */
	public function requires_api_key(): bool;
}
