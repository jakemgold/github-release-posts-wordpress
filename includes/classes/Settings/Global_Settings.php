<?php
/**
 * Global settings service.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Settings;

use TenUp\ChangelogToBlogPost\Plugin_Constants;

/**
 * Manages the site-wide plugin settings: AI provider, encrypted API keys,
 * post defaults, notification preferences, and check frequency.
 *
 * API keys are encrypted at rest using libsodium and decrypted only at the
 * point of use, never in the admin UI.
 */
class Global_Settings {

	/**
	 * Placeholder string shown in the API key field when a key is already saved.
	 * Submitting this value means "no change".
	 */
	const MASKED_PLACEHOLDER = '••••••••';

	/**
	 * Supported AI provider identifiers (v1).
	 *
	 * 'wp_ai_client' — WordPress AI Services plugin (recommended for non-technical users).
	 * 'openai'       — Direct OpenAI API key.
	 * 'anthropic'    — Direct Anthropic API key.
	 */
	const SUPPORTED_PROVIDERS = [ 'wp_ai_client', 'openai', 'anthropic' ];


	// -------------------------------------------------------------------------
	// AI Provider
	// -------------------------------------------------------------------------

	/**
	 * Gets the currently active AI provider identifier.
	 *
	 * @return string Provider identifier, or empty string if none is set.
	 */
	public function get_ai_provider(): string {
		return (string) get_option( Plugin_Constants::OPTION_AI_PROVIDER, '' );
	}

	/**
	 * Saves the active AI provider.
	 *
	 * @param string $provider Provider identifier. Must be in SUPPORTED_PROVIDERS.
	 * @return bool Whether the save succeeded.
	 */
	public function save_ai_provider( string $provider ): bool {
		if ( ! empty( $provider ) && ! in_array( $provider, self::SUPPORTED_PROVIDERS, true ) ) {
			return false;
		}

		return (bool) update_option( Plugin_Constants::OPTION_AI_PROVIDER, $provider, false );
	}

	// -------------------------------------------------------------------------
	// API Keys (encrypted at rest)
	// -------------------------------------------------------------------------

	/**
	 * Returns the decrypted API keys for all key-based providers.
	 *
	 * Keys are decrypted at this point for use by the AI integration layer.
	 * Never call this method from within the admin UI display path.
	 *
	 * @return array<string, string> Map of provider => plaintext key (empty string if not set).
	 */
	public function get_api_keys(): array {
		$encrypted = get_option( Plugin_Constants::OPTION_AI_API_KEYS, [] );
		if ( ! is_array( $encrypted ) ) {
			$encrypted = [];
		}

		$keys = [];
		foreach ( [ 'openai', 'anthropic' ] as $provider ) {
			if ( isset( $encrypted[ $provider ] ) && '' !== $encrypted[ $provider ] ) {
				$decrypted = $this->decrypt( (string) $encrypted[ $provider ] );
				if ( '' === $decrypted ) {
					// Key exists but can't be decrypted (AUTH_KEY may have changed).
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log(
							sprintf(
								'[CTBP] Failed to decrypt API key for provider "%s". If you migrated this site, re-enter your API key in Settings.',
								$provider
							)
						);
					}
				}
				$keys[ $provider ] = $decrypted;
			} else {
				$keys[ $provider ] = '';
			}
		}

		return $keys;
	}

	/**
	 * Saves API keys. Skips any key whose submitted value equals the masked placeholder.
	 *
	 * @param array<string, string> $keys Map of provider => submitted value.
	 * @return bool Whether the save succeeded.
	 */
	public function save_api_keys( array $keys ): bool {
		$existing = get_option( Plugin_Constants::OPTION_AI_API_KEYS, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		foreach ( [ 'openai', 'anthropic' ] as $provider ) {
			if ( ! isset( $keys[ $provider ] ) ) {
				continue;
			}

			$value = (string) $keys[ $provider ];

			if ( self::MASKED_PLACEHOLDER === $value ) {
				// User left the masked placeholder — preserve the existing encrypted key.
				continue;
			}

			if ( '' === $value ) {
				// User cleared the field — remove the key.
				unset( $existing[ $provider ] );
			} else {
				$existing[ $provider ] = $this->encrypt( $value );
			}
		}

		return (bool) update_option( Plugin_Constants::OPTION_AI_API_KEYS, $existing, false );
	}

	/**
	 * Returns the masked placeholder if a key exists for the given provider.
	 *
	 * Never returns the actual key value. Used to populate the admin UI field.
	 *
	 * @param string $provider Provider identifier.
	 * @return string Masked placeholder, or empty string if no key is stored.
	 */
	public function get_masked_key( string $provider ): string {
		$encrypted = get_option( Plugin_Constants::OPTION_AI_API_KEYS, [] );

		return ( is_array( $encrypted ) && ! empty( $encrypted[ $provider ] ) )
			? self::MASKED_PLACEHOLDER
			: '';
	}

	// -------------------------------------------------------------------------
	// Post Defaults
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// Notification Settings
	// -------------------------------------------------------------------------

	/**
	 * Returns the current notification settings.
	 *
	 * @return array{notify_site_owner: bool, additional_emails: string}
	 */
	public function get_notification_settings(): array {
		return [
			'notify_site_owner' => (bool) get_option( Plugin_Constants::OPTION_NOTIFY_SITE_OWNER, true ),
			'additional_emails' => (string) get_option( Plugin_Constants::OPTION_ADDITIONAL_EMAILS, '' ),
		];
	}

	/**
	 * Returns the parsed list of additional notification email addresses.
	 *
	 * Splits the comma-delimited string, validates each address, and caps at 5.
	 *
	 * @return string[] Valid email addresses (max 5).
	 */
	public function get_additional_email_list(): array {
		$raw = (string) get_option( Plugin_Constants::OPTION_ADDITIONAL_EMAILS, '' );
		if ( '' === trim( $raw ) ) {
			return [];
		}

		$addresses = array_map( 'trim', explode( ',', $raw ) );
		$valid     = [];

		foreach ( $addresses as $addr ) {
			if ( '' !== $addr && is_email( $addr ) ) {
				$valid[] = $addr;
			}
			if ( count( $valid ) >= 5 ) {
				break;
			}
		}

		return $valid;
	}

	// -------------------------------------------------------------------------
	// Custom Model IDs
	// -------------------------------------------------------------------------

	/**
	 * Returns the per-provider custom model IDs set by the site owner.
	 *
	 * An empty string for a provider means "use the plugin default / filter value".
	 *
	 * @return array<string, string> Map of provider slug => custom model ID.
	 */
	public function get_custom_models(): array {
		$stored = (array) get_option( Plugin_Constants::OPTION_AI_CUSTOM_MODELS, [] );
		return [
			'openai'    => (string) ( $stored['openai'] ?? '' ),
			'anthropic' => (string) ( $stored['anthropic'] ?? '' ),
		];
	}

	/**
	 * Saves per-provider custom model IDs.
	 *
	 * Pass an empty string for a provider to revert to the plugin default.
	 *
	 * @param array<string, string> $models Map of provider slug => custom model ID.
	 * @return bool Whether the option was updated.
	 */
	public function save_custom_models( array $models ): bool {
		$existing = (array) get_option( Plugin_Constants::OPTION_AI_CUSTOM_MODELS, [] );

		foreach ( [ 'openai', 'anthropic' ] as $provider ) {
			if ( array_key_exists( $provider, $models ) ) {
				$value = trim( (string) $models[ $provider ] );
				if ( '' === $value ) {
					unset( $existing[ $provider ] );
				} else {
					$existing[ $provider ] = $value;
				}
			}
		}

		return (bool) update_option( Plugin_Constants::OPTION_AI_CUSTOM_MODELS, $existing, false );
	}

	// -------------------------------------------------------------------------
	// Custom Prompt Instructions
	// -------------------------------------------------------------------------

	/**
	 * Returns the site owner's custom prompt instructions.
	 *
	 * These are free-text instructions appended to the AI prompt to influence
	 * voice, style, tone, or provide examples for generated posts.
	 *
	 * @return string Custom instructions, or empty string if not set.
	 */
	public function get_custom_prompt_instructions(): string {
		return (string) get_option( Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS, '' );
	}

	/**
	 * Returns whether the AI disclosure statement should be appended to posts.
	 *
	 * @return bool
	 */
	public function is_ai_disclosure_enabled(): bool {
		return (bool) get_option( Plugin_Constants::OPTION_AI_DISCLOSURE, false );
	}

	/**
	 * Saves the site owner's custom prompt instructions.
	 *
	 * @param string $instructions Free-text instructions.
	 * @return bool Whether the option was updated.
	 */
	public function save_custom_prompt_instructions( string $instructions ): bool {
		return (bool) update_option( Plugin_Constants::OPTION_CUSTOM_PROMPT_INSTRUCTIONS, $instructions, false );
	}

	// -------------------------------------------------------------------------
	// Audience Level
	// -------------------------------------------------------------------------

	/**
	 * Supported audience level identifiers.
	 *
	 * @var string[]
	 */
	const SUPPORTED_AUDIENCE_LEVELS = [ 'general', 'mixed', 'developer', 'engineering' ];

	/**
	 * Returns the configured audience level for generated posts.
	 *
	 * @return string One of: 'general', 'mixed', 'developer', 'engineering'. Defaults to 'mixed'.
	 */
	public function get_audience_level(): string {
		$level = (string) get_option( Plugin_Constants::OPTION_AUDIENCE_LEVEL, 'mixed' );
		return in_array( $level, self::SUPPORTED_AUDIENCE_LEVELS, true ) ? $level : 'mixed';
	}

	/**
	 * Saves the audience level setting.
	 *
	 * @param string $level One of the SUPPORTED_AUDIENCE_LEVELS values.
	 * @return bool Whether the option was updated.
	 */
	public function save_audience_level( string $level ): bool {
		if ( ! in_array( $level, self::SUPPORTED_AUDIENCE_LEVELS, true ) ) {
			$level = 'mixed';
		}
		return (bool) update_option( Plugin_Constants::OPTION_AUDIENCE_LEVEL, $level, false );
	}

	// -------------------------------------------------------------------------
	// Check Frequency
	// -------------------------------------------------------------------------

	/**
	 * Returns the release-check cron frequency.
	 *
	 * Defaults to 'daily'. Developers can override via the `ctbp_check_frequency`
	 * filter — return any valid WP-Cron schedule name (e.g. 'hourly', 'twicedaily',
	 * 'daily', 'weekly').
	 *
	 * @return string WP-Cron schedule name.
	 */
	public function get_check_frequency(): string {
		return (string) apply_filters( 'ctbp_check_frequency', 'daily' );
	}

	// -------------------------------------------------------------------------
	// GitHub Personal Access Token
	// -------------------------------------------------------------------------

	/**
	 * Returns the decrypted GitHub Personal Access Token, or empty string if not set.
	 *
	 * Never call this method from within the admin UI display path.
	 *
	 * @return string Plaintext PAT, or empty string.
	 */
	public function get_github_pat(): string {
		$encrypted = (string) get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		return '' !== $encrypted ? $this->decrypt( $encrypted ) : '';
	}

	/**
	 * Saves (or clears) the GitHub PAT. Encrypts non-empty values at rest.
	 *
	 * Pass the masked placeholder to leave the existing value unchanged.
	 * Pass an empty string to remove the stored PAT.
	 *
	 * @param string $pat Submitted value (plaintext, masked placeholder, or empty).
	 * @return bool Whether the option was updated.
	 */
	public function save_github_pat( string $pat ): bool {
		if ( self::MASKED_PLACEHOLDER === $pat ) {
			return true; // No change.
		}

		if ( '' === $pat ) {
			return (bool) update_option( Plugin_Constants::OPTION_GITHUB_PAT, '', false );
		}

		return (bool) update_option( Plugin_Constants::OPTION_GITHUB_PAT, $this->encrypt( $pat ), false );
	}

	/**
	 * Returns the masked placeholder if a GitHub PAT is stored, empty string otherwise.
	 *
	 * Used to populate the admin UI field without exposing the actual token.
	 *
	 * @return string Masked placeholder or empty string.
	 */
	public function get_masked_github_pat(): string {
		$stored = (string) get_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		return '' !== $stored ? self::MASKED_PLACEHOLDER : '';
	}

	// -------------------------------------------------------------------------
	// Encryption helpers (libsodium)
	// -------------------------------------------------------------------------

	/**
	 * Encrypts a plaintext string using libsodium secretbox.
	 *
	 * The encryption key is derived from the WordPress AUTH_KEY constant.
	 * The result is base64-encoded `nonce || ciphertext`.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64-encoded encrypted value, or empty string on failure.
	 */
	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		try {
			$key    = $this->derive_encryption_key();
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
			sodium_memzero( $plaintext );

			return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		} catch ( \SodiumException $e ) {
			return '';
		}
	}

	/**
	 * Decrypts a value previously encrypted by encrypt().
	 *
	 * @param string $encoded Base64-encoded `nonce || ciphertext`.
	 * @return string Decrypted plaintext, or empty string on failure.
	 */
	public function decrypt( string $encoded ): string {
		if ( '' === $encoded ) {
			return '';
		}

		try {
			$raw        = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$nonce_size = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

			if ( false === $raw || strlen( $raw ) <= $nonce_size ) {
				return '';
			}

			$nonce  = substr( $raw, 0, $nonce_size );
			$cipher = substr( $raw, $nonce_size );
			$key    = $this->derive_encryption_key();
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return false === $plain ? '' : $plain;
		} catch ( \SodiumException $e ) {
			return '';
		}
	}

	/**
	 * Derives a fixed-length encryption key from the WordPress AUTH_KEY constant.
	 *
	 * @return string Raw binary key of length SODIUM_CRYPTO_SECRETBOX_KEYBYTES.
	 * @throws \SodiumException If key derivation fails.
	 */
	private function derive_encryption_key(): string {
		if ( ! defined( 'AUTH_KEY' ) || '' === AUTH_KEY || 'put your unique phrase here' === AUTH_KEY ) {
			throw new \SodiumException(
				'WordPress AUTH_KEY is not configured. API keys cannot be encrypted. Define a unique AUTH_KEY in wp-config.php.'
			);
		}

		return substr(
			sodium_crypto_generichash( AUTH_KEY ),
			0,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}
