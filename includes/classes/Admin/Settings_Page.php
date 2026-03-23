<?php
/**
 * Settings API registration for the Settings tab.
 *
 * Registers all global settings with the WordPress Settings API so the
 * Settings tab uses `settings_fields()` / `do_settings_sections()` instead
 * of manual form handling.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Admin;

use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;

/**
 * Handles WordPress Settings API registration for the plugin's Settings tab.
 */
class Settings_Page {

	/**
	 * Option group name used by settings_fields().
	 *
	 * @var string
	 */
	const OPTION_GROUP = 'ctbp_settings';

	/**
	 * Settings page slug (matches the menu page slug).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'changelog-to-blog-post';

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
		$this->register_github_section();
		$this->register_ai_provider_section();
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
			'ctbp_section_github',
			__( 'GitHub', 'changelog-to-blog-post' ),
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
			__( 'Personal Access Token', 'changelog-to-blog-post' ),
			[ $this, 'render_github_pat_field' ],
			self::PAGE_SLUG,
			'ctbp_section_github',
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
			<?php echo esc_html__( 'Optional. Raises the GitHub API rate limit from 60 to 5,000 requests per hour.', 'changelog-to-blog-post' ); ?>
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
			'ctbp_section_ai_provider',
			__( 'AI Provider', 'changelog-to-blog-post' ),
			'__return_null',
			self::PAGE_SLUG
		);

		// AI provider select.
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_AI_PROVIDER,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_ai_provider' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_AI_PROVIDER,
			__( 'AI Service', 'changelog-to-blog-post' ),
			[ $this, 'render_ai_provider_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider',
			[ 'label_for' => Plugin_Constants::OPTION_AI_PROVIDER ]
		);

		// API keys (stored as a single serialized option).
		register_setting(
			self::OPTION_GROUP,
			Plugin_Constants::OPTION_AI_API_KEYS,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_api_keys' ],
				'autoload'          => false,
			]
		);

		add_settings_field(
			Plugin_Constants::OPTION_AI_API_KEYS,
			__( 'API Key', 'changelog-to-blog-post' ),
			[ $this, 'render_api_keys_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider',
			[ 'class' => 'ctbp-api-keys-row' ]
		);

		// Connection test (rendered as a field so it sits below the API key).
		add_settings_field(
			'ctbp_connection_test',
			'',
			[ $this, 'render_connection_test_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider',
			[ 'class' => 'ctbp-connection-test-row' ]
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
			__( 'Post Audience', 'changelog-to-blog-post' ),
			[ $this, 'render_audience_level_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider',
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
			__( 'Custom Prompt Instructions', 'changelog-to-blog-post' ),
			[ $this, 'render_custom_prompt_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider',
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
			__( 'AI Disclosure', 'changelog-to-blog-post' ),
			[ $this, 'render_ai_disclosure_field' ],
			self::PAGE_SLUG,
			'ctbp_section_ai_provider'
		);
	}

	/**
	 * Renders the AI provider select dropdown.
	 *
	 * @return void
	 */
	public function render_ai_provider_field(): void {
		$provider = $this->global_settings->get_ai_provider();
		?>
		<select id="<?php echo esc_attr( Plugin_Constants::OPTION_AI_PROVIDER ); ?>" name="<?php echo esc_attr( Plugin_Constants::OPTION_AI_PROVIDER ); ?>">
			<option value="" <?php selected( $provider, '' ); ?>><?php echo esc_html__( '— Select a provider —', 'changelog-to-blog-post' ); ?></option>
			<option value="wp_ai_client" <?php selected( $provider, 'wp_ai_client' ); ?>><?php echo esc_html__( 'WordPress AI Services (recommended)', 'changelog-to-blog-post' ); ?></option>
			<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php echo esc_html__( 'OpenAI', 'changelog-to-blog-post' ); ?></option>
			<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php echo esc_html__( 'Anthropic', 'changelog-to-blog-post' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Renders the API key fields for key-based providers and the wp_ai_client note.
	 *
	 * @return void
	 */
	public function render_api_keys_field(): void {
		$provider            = $this->global_settings->get_ai_provider();
		$key_based_providers = [ 'openai', 'anthropic' ];
		$no_key_providers    = [ 'wp_ai_client' ];

		foreach ( $key_based_providers as $p ) :
			?>
			<div
				class="ctbp-api-key-row"
				data-provider="<?php echo esc_attr( $p ); ?>"
				<?php echo $provider !== $p ? 'hidden' : ''; ?>
			>
				<input
					type="password"
					id="ctbp_api_key_<?php echo esc_attr( $p ); ?>"
					name="<?php echo esc_attr( Plugin_Constants::OPTION_AI_API_KEYS ); ?>[<?php echo esc_attr( $p ); ?>]"
					value="<?php echo esc_attr( $this->global_settings->get_masked_key( $p ) ); ?>"
					class="regular-text"
					autocomplete="new-password"
				>
				<p class="description">
					<?php echo esc_html__( 'Leave unchanged to keep the existing key. Clear the field to remove the key.', 'changelog-to-blog-post' ); ?>
				</p>
			</div>
			<?php
		endforeach;

		foreach ( $no_key_providers as $p ) :
			?>
			<div
				class="ctbp-provider-note"
				data-provider="<?php echo esc_attr( $p ); ?>"
				<?php echo $provider !== $p ? 'hidden' : ''; ?>
			>
				<p class="description">
					<?php echo esc_html__( 'WordPress AI Services manages its own API keys. Configure your preferred AI provider in Settings → AI Services.', 'changelog-to-blog-post' ); ?>
				</p>
			</div>
			<?php
		endforeach;
	}

	/**
	 * Renders the audience level select dropdown.
	 *
	 * @return void
	 */
	public function render_audience_level_field(): void {
		$level = $this->global_settings->get_audience_level();
		?>
		<select id="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>" name="<?php echo esc_attr( Plugin_Constants::OPTION_AUDIENCE_LEVEL ); ?>">
			<option value="general" <?php selected( $level, 'general' ); ?>>
				<?php echo esc_html__( 'Site owners & managers — plain language, no jargon', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="mixed" <?php selected( $level, 'mixed' ); ?>>
				<?php echo esc_html__( 'Mixed audience — accessible language, developer section when relevant (default)', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="developer" <?php selected( $level, 'developer' ); ?>>
				<?php echo esc_html__( 'Developers & builders — technical details woven throughout', 'changelog-to-blog-post' ); ?>
			</option>
			<option value="engineering" <?php selected( $level, 'engineering' ); ?>>
				<?php echo esc_html__( 'Engineering teams — full technical depth, API and hook details', 'changelog-to-blog-post' ); ?>
			</option>
		</select>
		<p class="description">
			<?php echo esc_html__( 'Controls how technical the generated posts are and who they are written for.', 'changelog-to-blog-post' ); ?>
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
			placeholder="<?php echo esc_attr__( 'e.g. Write in a friendly, conversational tone. Our audience is non-technical WordPress site owners. Avoid jargon. See example post: https://example.com/blog/plugin-update', 'changelog-to-blog-post' ); ?>"
		><?php echo esc_textarea( $custom_instructions ); ?></textarea>
		<p class="description">
			<?php echo esc_html__( 'Optional. Additional instructions sent to the AI when generating posts. Use this to guide the writing style, tone, voice, audience, or point to examples of posts you like. Best results with under 500 characters.', 'changelog-to-blog-post' ); ?>
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
			<?php echo esc_html__( 'Append a note to generated posts stating the content was created with AI assistance.', 'changelog-to-blog-post' ); ?>
		</label>
		<?php
	}

	/**
	 * Sanitizes the AI provider value.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Validated provider slug or empty string.
	 */
	public function sanitize_ai_provider( $value ): string {
		$value = sanitize_key( (string) $value );

		if ( '' !== $value && ! in_array( $value, Global_Settings::SUPPORTED_PROVIDERS, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Sanitizes the API keys array before saving.
	 *
	 * Handles encryption of new keys, preservation of masked placeholders,
	 * and removal of cleared keys.
	 *
	 * @param mixed $value Submitted array of provider => key values.
	 * @return array<string, string> Encrypted API keys array.
	 */
	public function sanitize_api_keys( $value ): array {
		if ( ! is_array( $value ) ) {
			$value = [];
		}

		$existing = get_option( Plugin_Constants::OPTION_AI_API_KEYS, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		foreach ( [ 'openai', 'anthropic' ] as $provider ) {
			if ( ! isset( $value[ $provider ] ) ) {
				continue;
			}

			$submitted = wp_unslash( (string) $value[ $provider ] );

			if ( Global_Settings::MASKED_PLACEHOLDER === $submitted ) {
				// User left the masked placeholder — preserve existing encrypted key.
				continue;
			}

			if ( '' === $submitted ) {
				// User cleared the field — remove the key.
				unset( $existing[ $provider ] );
			} else {
				$existing[ $provider ] = $this->global_settings->encrypt( $submitted );
			}
		}

		return $existing;
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
			'ctbp_section_notifications',
			__( 'Notifications', 'changelog-to-blog-post' ),
			function () {
				echo '<p>' . esc_html__( 'Send email notifications when posts are generated.', 'changelog-to-blog-post' ) . '</p>';
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
			__( 'Site Owner', 'changelog-to-blog-post' ),
			[ $this, 'render_notify_site_owner_field' ],
			self::PAGE_SLUG,
			'ctbp_section_notifications'
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
			__( 'Additional Addresses', 'changelog-to-blog-post' ),
			[ $this, 'render_additional_emails_field' ],
			self::PAGE_SLUG,
			'ctbp_section_notifications',
			[ 'label_for' => Plugin_Constants::OPTION_ADDITIONAL_EMAILS ]
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
				esc_html__( 'Send notifications to %s (the admin email from Settings > General)', 'changelog-to-blog-post' ),
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
			<?php echo esc_html__( 'Comma-separated list of email addresses (up to 5).', 'changelog-to-blog-post' ); ?>
		</p>
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
				'ctbp_invalid_additional_emails',
				sprintf(
					/* translators: %s: comma-separated list of invalid addresses */
					__( 'The following email addresses are invalid and were removed: %s', 'changelog-to-blog-post' ),
					esc_html( implode( ', ', $invalid ) )
				),
				'error'
			);
		}

		if ( count( $addresses ) > 5 ) {
			add_settings_error(
				self::OPTION_GROUP,
				'ctbp_too_many_emails',
				__( 'Only the first 5 valid email addresses were saved.', 'changelog-to-blog-post' ),
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
			'ctbp_section_schedule',
			__( 'Release Check Schedule', 'changelog-to-blog-post' ),
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
					esc_html__( 'Last run: %s ago.', 'changelog-to-blog-post' ),
					esc_html( human_time_diff( $last_run_at, $now ) )
				);
				?>
			<?php else : ?>
				<?php echo esc_html__( 'Last run: No runs yet.', 'changelog-to-blog-post' ); ?>
			<?php endif; ?>

			<?php if ( $next_check ) : ?>
				&nbsp;
				<?php
				printf(
					/* translators: %s: human-readable time until next check */
					esc_html__( 'Next run: in %s.', 'changelog-to-blog-post' ),
					esc_html( human_time_diff( $now, $next_check ) )
				);
				?>
			<?php else : ?>
				&nbsp;
				<?php echo esc_html__( 'Next run: not scheduled. If this persists, check your site\'s WP-Cron health or configure a real server cron to call wp-cron.php.', 'changelog-to-blog-post' ); ?>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			printf(
				/* translators: %s: current frequency (e.g. "daily") */
				esc_html__( 'Checks run %1$s by default. Developers can override this with the %2$s filter.', 'changelog-to-blog-post' ),
				esc_html( (string) apply_filters( 'ctbp_check_frequency', 'daily' ) ),
				'<code>ctbp_check_frequency</code>'
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

	/**
	 * Renders the connection test button as an inline settings field.
	 *
	 * @return void
	 */
	public function render_connection_test_field(): void {
		?>
		<button type="button" id="ctbp-test-connection" class="button">
			<?php echo esc_html__( 'Test Connection', 'changelog-to-blog-post' ); ?>
		</button>
		<span class="spinner ctbp-connection-spinner"></span>
		<span id="ctbp-connection-result" aria-live="polite"></span>
		<?php
	}
}
