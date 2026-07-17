<?php
/**
 * Settings API registration for the Settings tab.
 *
 * Registers all global settings with the WordPress Settings API so the
 * Settings tab uses `settings_fields()` / `do_settings_sections()` instead
 * of manual form handling.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\AI\Connectors\WP_AI_Client_Connector;
use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Global_Settings;

/**
 * Handles WordPress Settings API registration for the plugin's Settings tab.
 */
class Settings_Page {

	/**
	 * Option group name used by settings_fields().
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'ghrp_settings';

	/**
	 * Settings page slug (matches the menu page slug).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'github-release-posts';

	/**
	 * Global settings service.
	 *
	 * @var Global_Settings
	 */
	private Global_Settings $global_settings;

	/**
	 * Constructor.
	 *
	 * @param Global_Settings $global_settings Global settings service instance.
	 */
	public function __construct( Global_Settings $global_settings ) {
		$this->global_settings = $global_settings;
	}

	/**
	 * Registers WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Registers all settings, sections, and fields with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$this->register_ai_provider_section();
		$this->register_github_section();
		$this->register_notifications_section();
		$this->register_schedule_section();
	}

	// -------------------------------------------------------------------------
	// GitHub Section
	// -------------------------------------------------------------------------

	/**
	 * Registers the GitHub settings section and fields.
	 *
	 * @return void
	 */
	private function register_github_section(): void {
		add_settings_section(
			'ghrp_section_github',
			__( 'GitHub', 'auto-release-posts-for-github' ),
			'__return_null',
			self::PAGE_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_GITHUB_PAT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_github_pat' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_GITHUB_PAT,
			__( 'Personal Access Token', 'auto-release-posts-for-github' ),
			[ $this, 'render_github_pat_field' ],
			self::PAGE_SLUG,
			'ghrp_section_github',
			[ 'label_for' => Plugin_Constants::OPTION_GITHUB_PAT ]
		);
	}

	/**
	 * Renders the GitHub PAT password field.
	 *
	 * @return void
	 */
	public function render_github_pat_field(): void {
		$masked_pat     = $this->global_settings->get_masked_github_pat();
		$source         = $this->global_settings->get_github_pat_source();
		$externally_set = 'constant' === $source || 'env' === $source;
		$validation     = $this->get_github_pat_validation_status();
		?>
		<input
			type="password"
			id="<?php echo esc_attr( Plugin_Constants::OPTION_GITHUB_PAT ); ?>"
			name="<?php echo esc_attr( Plugin_Constants::OPTION_GITHUB_PAT ); ?>"
			value="<?php echo esc_attr( $masked_pat ); ?>"
			class="regular-text"
			autocomplete="new-password"
			<?php disabled( $externally_set ); ?>
		>
		<span
			id="ghrp-pat-status"
			class="ghrp-pat-status ghrp-pat-status--<?php echo esc_attr( $validation['state'] ); ?>"
			aria-live="polite"
		>
			<?php if ( 'valid' === $validation['state'] ) : ?>
				<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Validated', 'auto-release-posts-for-github' ); ?></span>
			<?php elseif ( 'invalid' === $validation['state'] ) : ?>
				<span class="dashicons dashicons-warning" style="color: #dba617;" aria-hidden="true"></span>
				<span><?php echo esc_html( $validation['message'] ); ?></span>
			<?php endif; ?>
		</span>
		<?php if ( ! $externally_set && ! $this->global_settings->can_encrypt() ) : ?>
			<p class="ghrp-pat-encrypt-warning" style="color: #b32d2e;">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<?php echo esc_html__( 'Tokens cannot be stored on this site: the WordPress AUTH_KEY security constant is missing or still set to its placeholder. Define a unique AUTH_KEY in wp-config.php, then enter the token.', 'auto-release-posts-for-github' ); ?>
			</p>
		<?php endif; ?>
		<?php if ( ! $externally_set ) : ?>
			<p class="description">
				<?php echo esc_html__( 'Optional. Raises the GitHub API rate limit from 60 to 5,000 requests per hour and prepopulates the repository picker on the Repositories tab.', 'auto-release-posts-for-github' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Returns the current PAT validation status, cached for 1 minute.
	 *
	 * Mirrors the AI Connector status pattern: cheap GET /user check, cached
	 * so every Settings page render isn't an HTTP request. Result shape:
	 *   [ 'state' => 'none' | 'valid' | 'invalid', 'message' => string ]
	 *
	 * @return array{state: string, message: string}
	 */
	private function get_github_pat_validation_status(): array {
		$pat = $this->global_settings->get_github_pat();
		if ( '' === $pat ) {
			return [
				'state'   => 'none',
				'message' => '',
			];
		}

		$cache_key = Cache_Keys::pat_validation( $pat );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = ( new API_Client( $this->global_settings ) )->validate_pat( $pat );
		$status = ( true === $result )
			? [
				'state'   => 'valid',
				'message' => '',
			]
			: [
				'state'   => 'invalid',
				'message' => (string) $result->get_error_message(),
			];

		set_transient( $cache_key, $status, MINUTE_IN_SECONDS );
		return $status;
	}

	/**
	 * Sanitizes the GitHub PAT value before saving.
	 *
	 * If the masked placeholder is submitted, preserves the existing encrypted value.
	 * If empty, clears the stored PAT. Otherwise, encrypts the new value.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Encrypted PAT, existing value, or empty string.
	 */
	public function sanitize_github_pat( $value ): string {
		$value = wp_unslash( (string) $value );

		// When the PAT is supplied by the GHRP_PAT constant or an environment
		// variable, render_github_pat_field() disables the input, so the browser
		// omits it from POST and this sanitizer receives an empty value. Leave the
		// stored database ciphertext untouched instead of wiping it (mirrors the
		// externally-managed no-op in Global_Settings::save_github_pat), so the DB
		// copy survives as a fallback if the constant is later removed.
		$source = $this->global_settings->get_github_pat_source();
		if ( 'constant' === $source || 'env' === $source ) {
			return get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		}

		if ( Global_Settings::MASKED_PLACEHOLDER === $value ) {
			return get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		}

		if ( '' === $value ) {
			return '';
		}

		$encrypted = $this->global_settings->encrypt( $value );

		if ( '' === $encrypted ) {
			// encrypt() returns '' when key derivation or libsodium fails — most
			// commonly a missing or placeholder AUTH_KEY. Surface the failure
			// instead of silently storing an empty token (which would leave the
			// admin looking at "Settings saved" while the plugin quietly falls back
			// to unauthenticated GitHub access), and preserve any previously stored
			// PAT rather than wiping a working token on a transient failure.
			add_settings_error(
				// Registered under the option group — the page template renders
				// settings_errors( OPTION_GROUP ), and settings_errors() filters
				// by this first argument. Registering under the option name
				// (as this call originally did) silently hid the notice.
				self::OPTION_GROUP,
				'ghrp_pat_encrypt_failed',
				__( 'The GitHub token could not be encrypted and was not saved. Define a unique AUTH_KEY in wp-config.php, then re-enter the token.', 'auto-release-posts-for-github' ),
				'error'
			);
			return get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		}

		return $encrypted;
	}

	// -------------------------------------------------------------------------
	// AI Provider Section
	// -------------------------------------------------------------------------

	/**
	 * Registers the AI Provider settings section and fields.
	 *
	 * @return void
	 */
	private function register_ai_provider_section(): void {
		add_settings_section(
			'ghrp_section_ai_provider',
			__( 'Post Creation', 'auto-release-posts-for-github' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'ghrp_connector_status',
			__( 'AI Connector', 'auto-release-posts-for-github' ),
			[ $this, 'render_connector_status' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider'
		);

		// Research depth.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_RESEARCH_DEPTH,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_research_depth' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_RESEARCH_DEPTH,
			__( 'Research Depth', 'auto-release-posts-for-github' ),
			[ $this, 'render_research_depth_field' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider'
		);

		// Post title format.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_TITLE_FORMAT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_title_format' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_TITLE_FORMAT,
			__( 'Post Titles', 'auto-release-posts-for-github' ),
			[ $this, 'render_title_format_field' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider'
		);

		// Audience level.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_AUDIENCE_LEVEL,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_audience_level' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_AUDIENCE_LEVEL,
			__( 'Post Audience', 'auto-release-posts-for-github' ),
			[ $this, 'render_audience_level_field' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider',
			[ 'label_for' => Plugin_Constants::OPTION_AUDIENCE_LEVEL ]
		);

		// Custom prompt instructions.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS,
			__( 'Custom Prompt Instructions', 'auto-release-posts-for-github' ),
			[ $this, 'render_custom_prompt_field' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider',
			[ 'label_for' => Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS ]
		);

		// AI disclosure.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_AI_DISCLOSURE,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_AI_DISCLOSURE,
			__( 'AI Disclosure', 'auto-release-posts-for-github' ),
			[ $this, 'render_ai_disclosure_field' ],
			self::PAGE_SLUG,
			'ghrp_section_ai_provider'
		);
	}

	/**
	 * Renders the WordPress Connectors status panel.
	 *
	 * Checks the AI Client registry for configured providers and models,
	 * then displays the current status with appropriate guidance. Results
	 * are cached for 1 minute to avoid hammering provider APIs on refresh.
	 *
	 * @return void
	 */
	public function render_connector_status(): void {
		// Computed fresh on every render (not cached) so it reflects connector
		// changes immediately — matching is_any_connector_configured() below.
		$status          = $this->detect_connector_status();
		$connectors_url  = admin_url( 'options-connectors.php' );
		$connectors_link = '<a href="' . esc_url( $connectors_url ) . '">' . esc_html__( 'WordPress Connectors', 'auto-release-posts-for-github' ) . '</a>';

		if ( ! $status['configured'] ) {
			printf(
				'<p>&#10007; %s</p>',
				sprintf(
					/* translators: %s: link to WordPress Connectors settings */
					esc_html__( 'No AI connector configured. Set one up in %s.', 'auto-release-posts-for-github' ),
					$connectors_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				)
			);
			return;
		}

		// Connected — show the provider and model the next generation will use.
		// Both labels are pre-escaped via esc_html() for safe use in printf().
		$provider_label = esc_html( $status['provider_name'] );
		$model_label    = esc_html( $status['model_id'] );
		$symbol         = $status['is_preferred'] ? '&#10003;' : '&#9888;';

		// Append the reasoning effort when one is actually applied (OpenAI only).
		$model_html = '<code>' . $model_label . '</code>';
		if ( '' !== $status['effort'] ) {
			$model_html .= sprintf(
				/* translators: %s: reasoning effort level, e.g. "high" */
				esc_html__( ', %s reasoning effort', 'auto-release-posts-for-github' ),
				esc_html( $status['effort'] )
			);
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_label and $model_html are built from esc_html()/esc_html__() values; $connectors_link is built from esc_url() + esc_html(); $symbol is a literal HTML entity.
		printf(
			'<p>%1$s %2$s</p>',
			$symbol,
			sprintf(
				/* translators: 1: link to WordPress Connectors settings, 2: provider name, 3: model ID (with optional reasoning effort) */
				esc_html__( 'Using %1$s for %2$s (%3$s)', 'auto-release-posts-for-github' ),
				$connectors_link,
				$provider_label,
				$model_html
			)
		);

		if ( ! $status['is_preferred'] ) {
			echo '<p class="description">' . esc_html__( 'We recommend the Anthropic, OpenAI, or Google connector.', 'auto-release-posts-for-github' ) . '</p>';
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Fast check for whether any AI connector is configured and ready.
	 *
	 * Used by the plugin admin page template to show a top-of-page warning
	 * notice — a lightweight boolean that skips building the full status payload.
	 *
	 * @return bool True if at least one registered provider is configured.
	 */
	public static function is_any_connector_configured(): bool {
		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return false;
		}

		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		foreach ( $registry->getRegisteredProviderIds() as $id ) {
			if ( $registry->isProviderConfigured( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detects the provider and model the next generation will use.
	 *
	 * Delegates to the connector's own preference-order resolution so this
	 * status can't drift from what actually runs. Falls back to reporting any
	 * other configured (but unsupported) connector, or "not configured".
	 *
	 * @return array{configured: bool, provider_name: string, provider_id: string, model_id: string, is_preferred: bool, effort: string}
	 */
	private function detect_connector_status(): array {
		$default = [
			'configured'    => false,
			'provider_name' => '',
			'provider_id'   => '',
			'model_id'      => '',
			'is_preferred'  => false,
			'effort'        => '',
		];

		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return $default;
		}

		// Ask the connector what the next generation will actually use — the
		// same preference-order resolution the AI Client performs.
		$selection = ( new WP_AI_Client_Connector() )->get_active_selection();

		if ( null !== $selection ) {
			return [
				'configured'    => true,
				'provider_name' => $selection['provider_name'],
				'provider_id'   => $selection['provider_id'],
				'model_id'      => $selection['model_id'],
				'is_preferred'  => true,
				'effort'        => $selection['effort'],
			];
		}

		// No preferred (Anthropic/OpenAI/Google) provider is configured. If some
		// other connector is set up, surface it as unsupported; otherwise report
		// nothing configured.
		$registry = \WordPress\AiClient\AiClient::defaultRegistry();
		foreach ( $registry->getRegisteredProviderIds() as $id ) {
			if ( ! $registry->isProviderConfigured( $id ) ) {
				continue;
			}

			$class_name = $registry->getProviderClassName( $id );
			$models     = $class_name::modelMetadataDirectory()->listModelMetadata();

			return [
				'configured'    => true,
				'provider_name' => (string) $class_name::metadata()->getName(),
				'provider_id'   => $id,
				'model_id'      => ! empty( $models ) ? (string) $models[0]->getId() : '',
				'is_preferred'  => false,
				'effort'        => '',
			];
		}

		return $default;
	}

	/**
	 * Renders the audience level select dropdown.
	 *
	 * @return void
	 */
	/**
	 * Renders the research depth radio buttons.
	 *
	 * @return void
	 */
	public function render_research_depth_field(): void {
		$depth = $this->global_settings->get_research_depth();
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_RESEARCH_DEPTH ); ?>" value="standard" <?php checked( $depth, 'standard' ); ?>>
				<strong><?php echo esc_html__( 'Standard', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Reviews release notes, linked issues and PRs, metadata, and README.', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_RESEARCH_DEPTH ); ?>" value="deep" <?php checked( $depth, 'deep' ); ?>>
				<strong><?php echo esc_html__( 'Deep', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Adds a review of commit messages and file changes since the last release.', 'auto-release-posts-for-github' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php echo esc_html__( 'Deep research may increase API usage and generation time, especially for repositories with many commits between releases.', 'auto-release-posts-for-github' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitizes the research depth value.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Validated research depth.
	 */
	public function sanitize_research_depth( $value ): string {
		$allowed = [ 'standard', 'deep' ];
		return in_array( $value, $allowed, true ) ? $value : 'standard';
	}

	/**
	 * Renders the post title format radio buttons.
	 *
	 * @return void
	 */
	public function render_title_format_field(): void {
		$format = $this->global_settings->get_title_format();
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_TITLE_FORMAT ); ?>" value="full" <?php checked( $format, 'full' ); ?>>
				<strong><?php echo esc_html__( 'Plugin name and version', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'e.g. "My Plugin v1.2 — New dashboard widget"', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_TITLE_FORMAT ); ?>" value="version" <?php checked( $format, 'version' ); ?>>
				<strong><?php echo esc_html__( 'Version number only', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'e.g. "Version 1.2 — New dashboard widget"', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_TITLE_FORMAT ); ?>" value="none" <?php checked( $format, 'none' ); ?>>
				<strong><?php echo esc_html__( 'No prefix', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'The AI writes the full title with no automatic prefix.', 'auto-release-posts-for-github' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php echo esc_html__( 'For sites covering multiple plugins, the plugin name prefix is recommended. For single-plugin sites, the version-only or no-prefix options give more variety.', 'auto-release-posts-for-github' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitizes the post title format setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Validated format identifier.
	 */
	public function sanitize_title_format( $value ): string {
		return in_array( $value, Global_Settings::SUPPORTED_TITLE_FORMATS, true ) ? (string) $value : 'full';
	}

	/**
	 * Renders the audience level select dropdown.
	 *
	 * @return void
	 */
	public function render_audience_level_field(): void {
		$level = $this->global_settings->get_audience_level();
		?>
		<fieldset>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="general" <?php checked( $level, 'general' ); ?>>
				<strong><?php echo esc_html__( 'Site owners & managers', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Plain language, no jargon.', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="mixed" <?php checked( $level, 'mixed' ); ?>>
				<strong><?php echo esc_html__( 'Mixed audience', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Accessible language, developer section when relevant (default).', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="developer" <?php checked( $level, 'developer' ); ?>>
				<strong><?php echo esc_html__( 'Developers & builders', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Technical details woven throughout.', 'auto-release-posts-for-github' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="engineering" <?php checked( $level, 'engineering' ); ?>>
				<strong><?php echo esc_html__( 'Engineering teams', 'auto-release-posts-for-github' ); ?></strong> —
				<?php echo esc_html__( 'Full technical depth, API and hook details.', 'auto-release-posts-for-github' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php echo esc_html__( 'Controls how technical the generated posts are and who they are written for.', 'auto-release-posts-for-github' ); ?>
		</p>
		<?php
	}

	/**
	 * Sanitizes the audience level setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Validated audience level.
	 */
	public function sanitize_audience_level( $value ): string {
		$allowed = [ 'general', 'mixed', 'developer', 'engineering' ];
		return in_array( $value, $allowed, true ) ? $value : 'mixed';
	}

	/**
	 * Renders the custom prompt instructions textarea.
	 *
	 * @return void
	 */
	public function render_custom_prompt_field(): void {
		$custom_instructions = $this->global_settings->get_custom_prompt_instructions();
		?>
		<textarea
			id="<?php echo esc_attr( Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS ); ?>"
			name="<?php echo esc_attr( Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS ); ?>"
			rows="5"
			class="large-text"
			placeholder="<?php echo esc_attr__( 'e.g. Write in a friendly, conversational tone. Our audience is non-technical WordPress site owners. Avoid jargon. See example post: https://example.com/blog/plugin-update', 'auto-release-posts-for-github' ); ?>"
		><?php echo esc_textarea( $custom_instructions ); ?></textarea>
		<p class="description">
			<?php echo esc_html__( 'Optional. Additional instructions sent to the AI when generating posts. Use this to guide the writing style, tone, voice, audience, or point to examples of posts you like. Best results with under 500 characters.', 'auto-release-posts-for-github' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the AI disclosure checkbox.
	 *
	 * @return void
	 */
	public function render_ai_disclosure_field(): void {
		$enabled = $this->global_settings->is_ai_disclosure_enabled();
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Plugin_Constants::OPTION_AI_DISCLOSURE ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			>
			<?php echo esc_html__( 'Append a note to generated posts stating the content was created with AI assistance.', 'auto-release-posts-for-github' ); ?>
		</label>
		<?php
	}

	// -------------------------------------------------------------------------
	// Post Defaults Section
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Notifications Section
	// -------------------------------------------------------------------------

	/**
	 * Registers the Notifications settings section and fields.
	 *
	 * @return void
	 */
	private function register_notifications_section(): void {
		add_settings_section(
			'ghrp_section_notifications',
			__( 'Notifications', 'auto-release-posts-for-github' ),
			function () {
				echo '<p>' . esc_html__( 'Send email notifications when posts are generated.', 'auto-release-posts-for-github' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		// Notify site owner checkbox.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_NOTIFY_SITE_OWNER,
			[
				'type'              => 'boolean',
				'sanitize_callback' => [ $this, 'sanitize_checkbox' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_NOTIFY_SITE_OWNER,
			__( 'Site Owner', 'auto-release-posts-for-github' ),
			[ $this, 'render_notify_site_owner_field' ],
			self::PAGE_SLUG,
			'ghrp_section_notifications'
		);

		// Additional email addresses.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_ADDITIONAL_EMAILS,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_additional_emails' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_ADDITIONAL_EMAILS,
			__( 'Additional Addresses', 'auto-release-posts-for-github' ),
			[ $this, 'render_additional_emails_field' ],
			self::PAGE_SLUG,
			'ghrp_section_notifications',
			[ 'label_for' => Plugin_Constants::OPTION_ADDITIONAL_EMAILS ]
		);

		add_settings_field(
			'ghrp_test_notification',
			'',
			[ $this, 'render_test_notification_field' ],
			self::PAGE_SLUG,
			'ghrp_section_notifications',
			[ 'class' => 'ghrp-test-notification-row' ]
		);
	}

	/**
	 * Renders the "notify site owner" checkbox.
	 *
	 * @return void
	 */
	public function render_notify_site_owner_field(): void {
		$notif       = $this->global_settings->get_notification_settings();
		$admin_email = get_option( 'admin_email', '' );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( Plugin_Constants::OPTION_NOTIFY_SITE_OWNER ); ?>"
				value="1"
				<?php checked( ! empty( $notif['notify_site_owner'] ) ); ?>
			>
			<?php
			printf(
				/* translators: %s: admin email address */
				esc_html__( 'Send notifications to %s (the admin email from Settings > General)', 'auto-release-posts-for-github' ),
				'<code>' . esc_html( $admin_email ) . '</code>'
			);
			?>
		</label>
		<?php
	}

	/**
	 * Renders the additional email addresses text input.
	 *
	 * @return void
	 */
	public function render_additional_emails_field(): void {
		$notif = $this->global_settings->get_notification_settings();
		?>
		<input
			type="text"
			id="<?php echo esc_attr( Plugin_Constants::OPTION_ADDITIONAL_EMAILS ); ?>"
			name="<?php echo esc_attr( Plugin_Constants::OPTION_ADDITIONAL_EMAILS ); ?>"
			value="<?php echo esc_attr( $notif['additional_emails'] ); ?>"
			class="large-text"
			placeholder="editor@example.com, team@example.com"
		>
		<p class="description">
			<?php echo esc_html__( 'Comma-separated list of email addresses (up to 5).', 'auto-release-posts-for-github' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the test notification button.
	 *
	 * @return void
	 */
	public function render_test_notification_field(): void {
		?>
		<button type="button" id="ghrp-test-notification" class="button">
			<?php echo esc_html__( 'Send Test Email', 'auto-release-posts-for-github' ); ?>
		</button>
		<span class="spinner ghrp-test-notification-spinner"></span>
		<span id="ghrp-test-notification-result" style="vertical-align: middle;" aria-live="polite"></span>
		<?php
	}

	/**
	 * Sanitizes a checkbox value to a boolean stored as 0 or 1.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public function sanitize_checkbox( $value ): bool {
		return ! empty( $value );
	}

	/**
	 * Sanitizes the additional email addresses field.
	 *
	 * Validates each comma-separated address, caps at 5, and reports invalid ones.
	 *
	 * @param mixed $value Submitted comma-separated email string.
	 * @return string Sanitized comma-separated string of valid emails.
	 */
	public function sanitize_additional_emails( $value ): string {
		$raw = sanitize_text_field( (string) $value );
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$addresses = array_map( 'trim', explode( ',', $raw ) );
		$valid     = [];
		$invalid   = [];

		foreach ( $addresses as $addr ) {
			if ( '' === $addr ) {
				continue;
			}
			if ( is_email( $addr ) ) {
				$valid[] = $addr;
			} else {
				$invalid[] = $addr;
			}
			if ( count( $valid ) >= 5 ) {
				break;
			}
		}

		if ( ! empty( $invalid ) ) {
			add_settings_error(
				self::OPTION_GROUP,
				'ghrp_invalid_additional_emails',
				sprintf(
					/* translators: %s: comma-separated list of invalid addresses */
					__( 'The following email addresses are invalid and were removed: %s', 'auto-release-posts-for-github' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'error'
			);
		}

		if ( count( $addresses ) > 5 ) {
			add_settings_error(
				self::OPTION_GROUP,
				'ghrp_too_many_emails',
				__( 'Only the first 5 valid email addresses were saved.', 'auto-release-posts-for-github' ),
				'warning'
			);
		}

		return implode( ', ', $valid );
	}

	// -------------------------------------------------------------------------
	// Release Check Schedule Section
	// -------------------------------------------------------------------------

	/**
	 * Registers the Release Check Schedule informational section.
	 *
	 * @return void
	 */
	private function register_schedule_section(): void {
		add_settings_section(
			'ghrp_section_schedule',
			__( 'Release Check Schedule', 'auto-release-posts-for-github' ),
			[ $this, 'render_schedule_section' ],
			self::PAGE_SLUG
		);
	}

	/**
	 * Renders the Release Check Schedule section content.
	 *
	 * This section is informational only — no settings to save.
	 *
	 * @return void
	 */
	public function render_schedule_section(): void {
		$last_run_at = (int) get_option( Plugin_Constants::OPTION_LAST_RUN_AT, 0 );
		$next_check  = wp_next_scheduled( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
		$now         = time();
		?>
		<p class="description">
			<?php if ( $last_run_at > 0 ) : ?>
				<?php
				printf(
					/* translators: %s: human-readable time since last run */
					esc_html__( 'Last run: %s ago.', 'auto-release-posts-for-github' ),
					esc_html( human_time_diff( $last_run_at, $now ) )
				);
				?>
			<?php else : ?>
				<?php echo esc_html__( 'Last run: No runs yet.', 'auto-release-posts-for-github' ); ?>
			<?php endif; ?>

			<?php if ( $next_check ) : ?>
				&nbsp;
				<?php
				printf(
					/* translators: %s: human-readable time until next check */
					esc_html__( 'Next run: in %s.', 'auto-release-posts-for-github' ),
					esc_html( human_time_diff( $now, $next_check ) )
				);
				?>
			<?php else : ?>
				&nbsp;
				<?php echo esc_html__( 'Next run: not scheduled. If this persists, check your site\'s WP-Cron health or configure a real server cron to call wp-cron.php.', 'auto-release-posts-for-github' ); ?>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: current frequency (e.g. "daily") */
				esc_html__( 'Checks run %1$s by default. Developers can override this with the %2$s filter.', 'auto-release-posts-for-github' ),
				esc_html( (string) apply_filters( 'ghrp_check_frequency', 'daily' ) ),
				'<code>ghrp_check_frequency</code>'
			);
			?>
		</p>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------
}
