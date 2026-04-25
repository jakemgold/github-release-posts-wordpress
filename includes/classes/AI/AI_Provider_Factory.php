<?php
/**
 * Resolves and returns the active AI provider connector.
 *
 * @package GitHubReleasePosts\AI
 */

namespace Jakemgold\GitHubReleasePosts\AI;

use Jakemgold\GitHubReleasePosts\AI\Connectors\WP_AI_Client_Connector;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;

/**
 * Instantiates the correct AI provider connector based on plugin settings.
 *
 * Third-party developers can add custom providers via the
 * `ghrp_register_ai_providers` filter.
 */
class AI_Provider_Factory {

	/**
	 * Constructor.
	 *
	 * @param Global_Settings $settings Plugin settings service.
	 */
	public function __construct( private readonly Global_Settings $settings ) {}

	/**
	 * Returns the active provider connector, or WP_Error if none is usable.
	 *
	 * @return AIProviderInterface|\WP_Error
	 */
	public function get_provider(): AIProviderInterface|\WP_Error {
		$slug = $this->settings->get_ai_provider();

		if ( '' === $slug ) {
			return new \WP_Error(
				'ghrp_no_provider',
				__( 'No AI connector is configured. Set one up under Settings → Connectors.', 'github-release-posts' )
			);
		}

		$providers = $this->get_registered_providers();

		if ( ! isset( $providers[ $slug ] ) ) {
			return new \WP_Error(
				'ghrp_unknown_provider',
				sprintf(
					/* translators: %s: provider slug */
					__( 'AI provider "%s" is not registered. It may have been deactivated.', 'github-release-posts' ),
					$slug
				)
			);
		}

		return $providers[ $slug ];
	}

	/**
	 * Returns whether a usable provider is currently configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->settings->get_ai_provider();
	}

	/**
	 * Returns all available providers as slug => label pairs for the settings UI.
	 *
	 * @return array<string, string> Map of provider slug => display label.
	 */
	public function get_available_providers(): array {
		$labels = [];
		foreach ( $this->get_registered_providers() as $slug => $provider ) {
			$labels[ $slug ] = $provider->get_label();
		}
		return $labels;
	}

	/**
	 * Builds the full map of registered providers (built-in + community).
	 *
	 * Fires the `ghrp_register_ai_providers` filter so third-party code can add
	 * custom providers. Any registered value that does not implement
	 * AIProviderInterface is rejected with an error_log warning.
	 *
	 * @return array<string, AIProviderInterface>
	 */
	private function get_registered_providers(): array {
		$providers = [
			'wp_ai_client' => new WP_AI_Client_Connector(),
		];

		/**
		 * Filters the registered AI providers.
		 *
		 * Third-party developers can add custom providers by appending an
		 * AIProviderInterface implementation keyed by the provider slug:
		 *
		 *     add_filter( 'ghrp_register_ai_providers', function( $providers ) {
		 *         $providers['my_provider'] = new My_Provider_Connector();
		 *         return $providers;
		 *     } );
		 *
		 * @param array<string, AIProviderInterface> $providers Map of slug => connector.
		 */
		$providers = apply_filters( 'ghrp_register_ai_providers', $providers );

		// Validate: reject anything that doesn't satisfy the interface.
		foreach ( $providers as $slug => $provider ) {
			if ( ! ( $provider instanceof AIProviderInterface ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log(
						sprintf(
							'[CTBP] ghrp_register_ai_providers: provider "%s" does not implement AIProviderInterface and was removed.',
							$slug
						)
					);
				}
				unset( $providers[ $slug ] );
			}
		}

		return $providers;
	}
}
