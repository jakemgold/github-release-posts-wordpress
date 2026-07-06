<?php
/**
 * AI provider connector for the WordPress Connectors / AI Client API.
 *
 * @package GitHubReleasePosts\AI\Connectors
 */

namespace GitHubReleasePosts\AI\Connectors;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\AI\AIProviderInterface;
use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\Cache_Keys;

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
	 * Pinned Claude model used when the newest Opus can't be resolved from the
	 * AI Client's provider metadata (library absent, provider unconfigured, or a
	 * lookup error). A safe, current floor.
	 */
	const FALLBACK_CLAUDE_MODEL = 'claude-opus-4-8';

	/**
	 * Pinned OpenAI model used when the newest flagship GPT can't be resolved
	 * from the AI Client's provider metadata. A safe, current floor.
	 */
	const FALLBACK_OPENAI_MODEL = 'gpt-5.5';

	/**
	 * Pinned Google model used when the newest flagship Gemini can't be resolved
	 * from the AI Client's provider metadata. A safe, current floor.
	 */
	const FALLBACK_GEMINI_MODEL = 'gemini-2.5-pro';

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
		return __( 'WordPress Connectors', 'auto-release-posts-for-github' );
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
				'ghrp_wp_ai_client_unavailable',
				__( 'The WordPress AI Client API is not available. On WordPress 7.0+ it is built in — make sure at least one AI connector is activated under Settings → AI Credentials. On older versions, install and activate the wp-ai-client plugin.', 'auto-release-posts-for-github' )
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
				'ghrp_wp_ai_client_unavailable',
				__( 'The WordPress AI Client API is not available.', 'auto-release-posts-for-github' )
			);
		}

		// Temporarily override the default request timeout for this call.
		// Blog post generation typically needs 60-120s, well above the 30s default.
		// The filter must remain installed until generate_text() returns —
		// the underlying HTTP request reads the timeout at request-fire time,
		// not at builder construction.
		$timeout_override = static function () {
			return self::REQUEST_TIMEOUT;
		};
		add_filter( 'wp_ai_client_default_request_timeout', $timeout_override );
		try {
			$builder = wp_ai_client_prompt( $prompt ) // phpcs:ignore
				->using_max_tokens( 16384 );
			$this->configure_model( $builder );
			$response = $builder->generate_text();
		} catch ( \Throwable $e ) {
			// The WP AI Client builder throws on transport/quota/provider errors
			// rather than returning a WP_Error. Convert it so the caller
			// (AI_Processor) records the failure, advances the streak counter, and
			// sends the threshold email — and so one release's failure never aborts
			// the whole cron batch by propagating an uncaught exception.
			return new \WP_Error(
				'ghrp_wp_ai_client_exception',
				sprintf(
					/* translators: %s: error message from the AI provider */
					__( 'The AI provider request failed: %s', 'auto-release-posts-for-github' ),
					$e->getMessage()
				)
			);
		} finally {
			remove_filter( 'wp_ai_client_default_request_timeout', $timeout_override );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = (string) $response;
		if ( '' === trim( $text ) ) {
			return new \WP_Error(
				'ghrp_wp_ai_client_empty_response',
				__( 'WordPress Connectors returned an empty response. Please try again.', 'auto-release-posts-for-github' )
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
			$this->preferred_claude_model(),
			$this->preferred_openai_model(),
			$this->preferred_gemini_model(),
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
		return (array) apply_filters( 'ghrp_wp_ai_client_model_preferences', $defaults );
	}

	/**
	 * Returns the preferred Claude model ID — resolved to the newest available
	 * Opus-family model (see preferred_model()).
	 *
	 * @return string
	 */
	private function preferred_claude_model(): string {
		return $this->preferred_model( 'anthropic', '#^claude-opus-#', self::FALLBACK_CLAUDE_MODEL );
	}

	/**
	 * Returns the preferred OpenAI model ID — resolved to the newest base
	 * flagship GPT model (see preferred_model()).
	 *
	 * The anchored pattern matches only bare `gpt-<version>` IDs, so it skips the
	 * -mini/-nano/-pro/-codex/-chat-latest/-search variants and dated snapshots,
	 * leaving the current general model (e.g. gpt-5.5). Reasoning depth is set
	 * separately via `ghrp_openai_reasoning_effort`.
	 *
	 * @return string
	 */
	private function preferred_openai_model(): string {
		return $this->preferred_model( 'openai', '#^gpt-\d+(?:\.\d+)?$#', self::FALLBACK_OPENAI_MODEL );
	}

	/**
	 * Returns the preferred Google model ID — resolved to the newest stable
	 * flagship Gemini "pro" model (see preferred_model()).
	 *
	 * Unlike OpenAI, Google's sort leads with `-flash` (fast tier), so the top of
	 * the list isn't the flagship. The anchored pattern selects the newest stable
	 * `gemini-<version>-pro`, skipping flash/lite/image/tts variants, `-preview`
	 * builds, the floating `-latest` aliases, and dated snapshots.
	 *
	 * @return string
	 */
	private function preferred_gemini_model(): string {
		return $this->preferred_model( 'google', '#^gemini-[0-9.]+-pro$#', self::FALLBACK_GEMINI_MODEL );
	}

	/**
	 * Resolves a provider's preferred model, cached to keep the lookup off the
	 * generation hot path.
	 *
	 * Reads the newest matching model from the AI Client's provider metadata
	 * (which itself caches the provider's model list for up to 24h) instead of
	 * pinning a version that goes stale each release, and caches the result in a
	 * per-provider transient for a week. Falls back to a pinned model when
	 * resolution isn't possible. Override the whole list via
	 * `ghrp_wp_ai_client_model_preferences`.
	 *
	 * @param string $provider   Substring matching the registered provider ID (e.g. 'anthropic').
	 * @param string $id_pattern Regex the chosen model ID must match; newest match wins.
	 * @param string $fallback   Pinned model ID used when resolution fails.
	 * @return string
	 */
	private function preferred_model( string $provider, string $id_pattern, string $fallback ): string {
		$key    = Cache_Keys::resolved_model( $provider );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$resolved = $this->resolve_latest_model( $provider, $id_pattern );

		if ( '' !== $resolved ) {
			// Refresh weekly — new flagship versions ship far less often, and the
			// provider's underlying model list is itself only fetched daily.
			set_transient( $key, $resolved, WEEK_IN_SECONDS );
			return $resolved;
		}

		// Resolution unavailable. Cache the pinned fallback briefly so we don't
		// re-run the lookup (and possibly re-incur a network timeout) on every
		// generation, while still recovering within the hour.
		set_transient( $key, $fallback, HOUR_IN_SECONDS );

		return $fallback;
	}

	/**
	 * Resolves the newest model ID matching $id_pattern from the given provider's
	 * AI Client metadata, or '' if it can't be determined.
	 *
	 * Reads the provider's already-fetched, newest-first model list through the
	 * registry — no direct API call and no custom connector. Any failure returns
	 * '' so the caller uses the pinned fallback.
	 *
	 * @param string $provider   Substring matching the registered provider ID.
	 * @param string $id_pattern Regex the model ID must match.
	 * @return string Model ID, or '' if unresolved.
	 */
	private function resolve_latest_model( string $provider, string $id_pattern ): string {
		if (
			! class_exists( '\WordPress\AiClient\AiClient' )
			|| ! class_exists( '\WordPress\AiClient\Providers\Models\DTO\ModelRequirements' )
			|| ! class_exists( '\WordPress\AiClient\Providers\Models\Enums\CapabilityEnum' )
		) {
			return '';
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();

			$matched = '';
			foreach ( $registry->getRegisteredProviderIds() as $provider_id ) {
				if ( str_contains( $provider_id, $provider ) && $registry->isProviderConfigured( $provider_id ) ) {
					$matched = $provider_id;
					break;
				}
			}

			if ( '' === $matched ) {
				return '';
			}

			$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
				[ \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ],
				[]
			);

			// list<ModelMetadata>, already sorted newest/flagship-first by the
			// provider — the first ID matching the pattern is the current latest.
			$models = $registry->findProviderModelsMetadataForSupport( $matched, $requirements );

			foreach ( $models as $model ) {
				if ( ! is_object( $model ) ) {
					continue;
				}

				$model_id = $model->getId();
				if ( is_string( $model_id ) && preg_match( $id_pattern, $model_id ) ) {
					return $model_id;
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}

		return '';
	}

	/**
	 * Returns the provider and model the next generation will actually use, or
	 * null when the AI Client is unavailable or no preferred provider is
	 * configured.
	 *
	 * This mirrors the AI Client's own resolution (PromptBuilder::getConfiguredModel):
	 * it walks the model-preference order and returns the first model offered by
	 * a configured provider. It is the single source of truth for "what will
	 * run" — both the settings-screen status and the OpenAI-config gate read it,
	 * so they can't drift from each other or from get_model_preferences().
	 *
	 * @return array{provider_id: string, provider_name: string, model_id: string, effort: string}|null
	 *         `effort` is the OpenAI reasoning effort that will be applied, or ''
	 *         (only OpenAI receives an effort; empty means none is sent).
	 */
	public function get_active_selection(): ?array {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
			return null;
		}

		// Same provider order and resolved models as get_model_preferences().
		$preferences = [
			[
				'match' => 'anthropic',
				'model' => $this->preferred_claude_model(),
			],
			[
				'match' => 'openai',
				'model' => $this->preferred_openai_model(),
			],
			[
				'match' => 'google',
				'model' => $this->preferred_gemini_model(),
			],
		];

		try {
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			$provider_ids = $registry->getRegisteredProviderIds();

			foreach ( $preferences as $preference ) {
				foreach ( $provider_ids as $id ) {
					if ( str_contains( $id, $preference['match'] ) && $registry->isProviderConfigured( $id ) ) {
						$class_name = $registry->getProviderClassName( $id );

						return [
							'provider_id'   => $id,
							'provider_name' => (string) $class_name::metadata()->getName(),
							'model_id'      => $preference['model'],
							'effort'        => str_contains( $id, 'openai' ) ? $this->openai_reasoning_effort() : '',
						];
					}
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Determines whether the request will be handled by an OpenAI provider, so
	 * OpenAI-specific options (reasoning effort) are only applied when they'll be
	 * honored — sending them to Anthropic or Google would error.
	 *
	 * Reads the active selection rather than re-deriving priority, so it always
	 * agrees with the model actually chosen. Note that OpenAI outranks Google in
	 * the preference order, so a configured Google connector does not suppress
	 * OpenAI config when OpenAI is the active provider.
	 *
	 * @return bool
	 */
	private function will_use_openai_provider(): bool {
		$selection = $this->get_active_selection();

		return null !== $selection && str_contains( $selection['provider_id'], 'openai' );
	}

	/**
	 * Returns the reasoning effort applied to OpenAI generations, or '' when
	 * disabled. Single source of truth for both apply_openai_config() (what is
	 * sent) and the settings-screen status (what is shown).
	 *
	 * @return string
	 */
	private function openai_reasoning_effort(): string {
		/**
		 * Filters the reasoning effort level for OpenAI models.
		 *
		 * GPT-5.x models support controllable reasoning via this parameter.
		 * Valid values per the OpenAI API: 'none', 'low', 'medium', 'high',
		 * 'xhigh'. Return empty string to skip.
		 *
		 * @param string $effort Reasoning effort level. Default 'high'.
		 */
		return (string) apply_filters( 'ghrp_openai_reasoning_effort', 'high' );
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

		$effort = $this->openai_reasoning_effort();

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
