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

namespace Jakemgold\GitHubReleasePosts\Admin;

use Jakemgold\GitHubReleasePosts\Plugin_Constants;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;

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
			__( 'GitHub', 'github-release-posts' ),
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
			__( 'Personal Access Token', 'github-release-posts' ),
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
		$masked_pat = $this->global_settings->get_masked_github_pat();
		?>
		<input
			type="password"
			id="<?php echo esc_attr( Plugin_Constants::OPTION_GITHUB_PAT ); ?>"
			name="<?php echo esc_attr( Plugin_Constants::OPTION_GITHUB_PAT ); ?>"
			value="<?php echo esc_attr( $masked_pat ); ?>"
			class="regular-text"
			autocomplete="new-password"
		>
		<p class="description">
			<?php echo esc_html__( 'Optional. Raises the GitHub API rate limit from 60 to 5,000 requests per hour.', 'github-release-posts' ); ?>
		</p>
		<?php
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

		if ( Global_Settings::MASKED_PLACEHOLDER === $value ) {
			return get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		}

		if ( '' === $value ) {
			return '';
		}

		return $this->global_settings->encrypt( $value );
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
			__( 'Post Creation', 'github-release-posts' ),
			'__return_null',
			self::PAGE_SLUG
		);

		add_settings_field(
			'ghrp_connector_status',
			__( 'AI Connector', 'github-release-posts' ),
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
			__( 'Research Depth', 'github-release-posts' ),
			[ $this, 'render_research_depth_field' ],
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
			__( 'Post Audience', 'github-release-posts' ),
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
			__( 'Custom Prompt Instructions', 'github-release-posts' ),
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
			__( 'AI Disclosure', 'github-release-posts' ),
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
		$status          = $this->get_connector_status();
		$connectors_url  = admin_url( 'options-connectors.php' );
		$connectors_link = '<a href="' . esc_url( $connectors_url ) . '">' . esc_html__( 'WordPress Connectors', 'github-release-posts' ) . '</a>';

		if ( ! $status['configured'] ) {
			printf(
				'<p>&#10007; %s</p>',
				sprintf(
					/* translators: %s: link to WordPress Connectors settings */
					esc_html__( 'No AI connector configured. Set one up in %s.', 'github-release-posts' ),
					$connectors_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				)
			);
			return;
		}

		// Connected — show provider and model.
		// Both labels are pre-escaped via esc_html() for safe use in printf().
		$provider_label = esc_html( $status['provider_name'] );
		$model_label    = esc_html( $status['model_id'] );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $provider_label and $model_label are esc_html()'d above; $connectors_link is built from esc_url() + esc_html().
		if ( $status['is_preferred_model'] ) {
			printf(
				'<p>&#10003; %s</p>',
				sprintf(
					/* translators: 1: link to WordPress Connectors settings, 2: provider name, 3: model ID */
					esc_html__( 'Using %1$s for %2$s (%3$s)', 'github-release-posts' ),
					$connectors_link,
					$provider_label,
					'<code>' . $model_label . '</code>'
				)
			);
		} elseif ( $status['is_preferred_provider'] ) {
			// Right provider, wrong model tier.
			printf(
				'<p>&#9888; %s</p>',
				sprintf(
					/* translators: 1: link to WordPress Connectors settings, 2: provider name, 3: model ID */
					esc_html__( 'Using %1$s for %2$s (%3$s)', 'github-release-posts' ),
					$connectors_link,
					$provider_label,
					'<code>' . $model_label . '</code>'
				)
			);
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: 1: provider name, 2: recommended model */
					esc_html__( 'For best results, your %1$s account should support %2$s.', 'github-release-posts' ),
					$provider_label,
					esc_html( $status['recommended_model'] )
				)
			);
		} else {
			// Unknown / untested provider.
			printf(
				'<p>&#9888; %s</p>',
				sprintf(
					/* translators: 1: link to WordPress Connectors settings, 2: provider name, 3: model ID */
					esc_html__( 'Using %1$s for %2$s (%3$s)', 'github-release-posts' ),
					$connectors_link,
					$provider_label,
					'<code>' . $model_label . '</code>'
				)
			);
			echo '<p class="description">' . esc_html__( 'We recommend the Anthropic, OpenAI, or Google connector.', 'github-release-posts' ) . '</p>';
		}
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Returns the current connector status, cached for 1 minute.
	 *
	 * @return array{configured: bool, provider_name: string, provider_id: string, model_id: string, is_preferred_model: bool, is_preferred_provider: bool, recommended_model: string}
	 */
	private function get_connector_status(): array {
		$cached = get_transient( 'ghrp_connector_status' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$status = $this->detect_connector_status();
		set_transient( 'ghrp_connector_status', $status, MINUTE_IN_SECONDS );

		return $status;
	}

	/**
	 * Detects the active connector, provider name, and model from the AI Client registry.
	 *
	 * @return array{configured: bool, provider_name: string, provider_id: string, model_id: string, is_preferred_model: bool, is_preferred_provider: bool, recommended_model: string}
	 */
	private function detect_connector_status(): array {
		$default = [
			'configured'            => false,
			'provider_name'         => '',
			'provider_id'           => '',
			'model_id'              => '',
			'is_preferred_model'    => false,
			'is_preferred_provider' => false,
			'recommended_model'     => '',
		];

		if ( ! class_exists( 'WordPress\AiClient\AiClient' ) ) {
			return $default;
		}

		$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
		$provider_ids = $registry->getRegisteredProviderIds();

		// Preferred providers and their recommended models.
		$preferred_providers = [
			'anthropic' => 'Claude Opus 4.7',
			'openai'    => 'GPT-5.5',
			'google'    => 'Gemini 2.5 Pro',
		];

		// Preferred model IDs (from the connector's preference list).
		$preferred_models = [
			'claude-opus-4-7',
			'gpt-5.5',
			'gemini-2.5-pro',
		];

		// Find the first configured provider.
		foreach ( $provider_ids as $id ) {
			if ( ! $registry->isProviderConfigured( $id ) ) {
				continue;
			}

			$class_name    = $registry->getProviderClassName( $id );
			$metadata      = $class_name::metadata();
			$provider_name = $metadata->getName();

			// Get the first available model from this provider.
			$model_dir = $class_name::modelMetadataDirectory();
			$models    = $model_dir->listModelMetadata();
			$model_id  = ! empty( $models ) ? $models[0]->getId() : '';

			// Check if this is a preferred provider.
			$is_preferred_provider = false;
			$recommended_model     = '';
			foreach ( $preferred_providers as $pref_id => $pref_model ) {
				if ( str_contains( $id, $pref_id ) ) {
					$is_preferred_provider = true;
					$recommended_model     = $pref_model;
					break;
				}
			}

			// Check if the model is in our preferred list.
			$is_preferred_model = in_array( $model_id, $preferred_models, true );

			$status = [
				'configured'            => true,
				'provider_name'         => $provider_name,
				'provider_id'           => $id,
				'model_id'              => $model_id,
				'is_preferred_model'    => $is_preferred_model,
				'is_preferred_provider' => $is_preferred_provider,
				'recommended_model'     => $recommended_model,
			];

			return $status;
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
				<strong><?php echo esc_html__( 'Standard', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Reviews release notes, linked issues and PRs, metadata, and README.', 'github-release-posts' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_RESEARCH_DEPTH ); ?>" value="deep" <?php checked( $depth, 'deep' ); ?>>
				<strong><?php echo esc_html__( 'Deep', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Adds a review of commit messages and file changes since the last release.', 'github-release-posts' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php echo esc_html__( 'Deep research may increase API usage and generation time, especially for repositories with many commits between releases.', 'github-release-posts' ); ?>
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
				<strong><?php echo esc_html__( 'Site owners & managers', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Plain language, no jargon.', 'github-release-posts' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="mixed" <?php checked( $level, 'mixed' ); ?>>
				<strong><?php echo esc_html__( 'Mixed audience', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Accessible language, developer section when relevant (default).', 'github-release-posts' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="developer" <?php checked( $level, 'developer' ); ?>>
				<strong><?php echo esc_html__( 'Developers & builders', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Technical details woven throughout.', 'github-release-posts' ); ?>
			</label>
			<br>
			<label>
				<input type="radio" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" value="engineering" <?php checked( $level, 'engineering' ); ?>>
				<strong><?php echo esc_html__( 'Engineering teams', 'github-release-posts' ); ?></strong> —
				<?php echo esc_html__( 'Full technical depth, API and hook details.', 'github-release-posts' ); ?>
			</label>
		</fieldset>
		<p class="description">
			<?php echo esc_html__( 'Controls how technical the generated posts are and who they are written for.', 'github-release-posts' ); ?>
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
			placeholder="<?php echo esc_attr__( 'e.g. Write in a friendly, conversational tone. Our audience is non-technical WordPress site owners. Avoid jargon. See example post: https://example.com/blog/plugin-update', 'github-release-posts' ); ?>"
		><?php echo esc_textarea( $custom_instructions ); ?></textarea>
		<p class="description">
			<?php echo esc_html__( 'Optional. Additional instructions sent to the AI when generating posts. Use this to guide the writing style, tone, voice, audience, or point to examples of posts you like. Best results with under 500 characters.', 'github-release-posts' ); ?>
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
			<?php echo esc_html__( 'Append a note to generated posts stating the content was created with AI assistance.', 'github-release-posts' ); ?>
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
			__( 'Notifications', 'github-release-posts' ),
			function () {
				echo '<p>' . esc_html__( 'Send email notifications when posts are generated.', 'github-release-posts' ) . '</p>';
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
			__( 'Site Owner', 'github-release-posts' ),
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
			__( 'Additional Addresses', 'github-release-posts' ),
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
				esc_html__( 'Send notifications to %s (the admin email from Settings > General)', 'github-release-posts' ),
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
			value="<?php echo esc_attr( $notif['additional_emails'] ?? '' ); ?>"
			class="large-text"
			placeholder="editor@example.com, team@example.com"
		>
		<p class="description">
			<?php echo esc_html__( 'Comma-separated list of email addresses (up to 5).', 'github-release-posts' ); ?>
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
			<?php echo esc_html__( 'Send Test Email', 'github-release-posts' ); ?>
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
					__( 'The following email addresses are invalid and were removed: %s', 'github-release-posts' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'error'
			);
		}

		if ( count( $addresses ) > 5 ) {
			add_settings_error(
				self::OPTION_GROUP,
				'ghrp_too_many_emails',
				__( 'Only the first 5 valid email addresses were saved.', 'github-release-posts' ),
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
			__( 'Release Check Schedule', 'github-release-posts' ),
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
					esc_html__( 'Last run: %s ago.', 'github-release-posts' ),
					esc_html( human_time_diff( $last_run_at, $now ) )
				);
				?>
			<?php else : ?>
				<?php echo esc_html__( 'Last run: No runs yet.', 'github-release-posts' ); ?>
			<?php endif; ?>

			<?php if ( $next_check ) : ?>
				&nbsp;
				<?php
				printf(
					/* translators: %s: human-readable time until next check */
					esc_html__( 'Next run: in %s.', 'github-release-posts' ),
					esc_html( human_time_diff( $now, $next_check ) )
				);
				?>
			<?php else : ?>
				&nbsp;
				<?php echo esc_html__( 'Next run: not scheduled. If this persists, check your site\'s WP-Cron health or configure a real server cron to call wp-cron.php.', 'github-release-posts' ); ?>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: current frequency (e.g. "daily") */
				esc_html__( 'Checks run %1$s by default. Developers can override this with the %2$s filter.', 'github-release-posts' ),
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

	/**
	 * Converts a comma-separated string of tag names into an array of term IDs.
	 *
	 * Tags that don't exist are silently skipped.
	 *
	 * @param string $raw Comma-separated tag names.
	 * @return int[] Array of tag term IDs.
	 */
	private function resolve_tag_names_to_ids( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}

		$names = array_map( 'trim', explode( ',', $raw ) );
		$ids   = [];

		foreach ( $names as $name ) {
			if ( '' === $name ) {
				continue;
			}

			$term = get_term_by( 'name', $name, 'post_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}
}
