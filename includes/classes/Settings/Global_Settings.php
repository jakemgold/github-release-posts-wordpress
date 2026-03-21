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
	 * Supported AI provider identifiers.
	 */
	const SUPPORTED_PROVIDERS = [ 'openai', 'anthropic', 'gemini', 'classifai', 'wordpress_ai' ];

	/**
	 * Valid check-frequency values (match WordPress cron schedule names).
	 */
	const VALID_FREQUENCIES = [ 'hourly', 'twicedaily', 'daily', 'weekly' ];

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

		return (bool) update_option( Plugin_Constants::OPTION_AI_PROVIDER, $provider );
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
		foreach ( [ 'openai', 'anthropic', 'gemini' ] as $provider ) {
			$keys[ $provider ] = isset( $encrypted[ $provider ] )
				? $this->decrypt( (string) $encrypted[ $provider ] )
				: '';
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
		$existing  = get_option( Plugin_Constants::OPTION_AI_API_KEYS, [] );
		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		foreach ( [ 'openai', 'anthropic', 'gemini' ] as $provider ) {
			if ( ! isset( $keys[ $provider ] ) ) {
				continue;
			}

			$value = (string) $keys[ $provider ];

			if ( $value === self::MASKED_PLACEHOLDER ) {
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

		return (bool) update_option( Plugin_Constants::OPTION_AI_API_KEYS, $existing );
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

	/**
	 * Returns the global post defaults.
	 *
	 * @return array{post_status: string, category: int, tags: string[]}
	 */
	public function get_post_defaults(): array {
		return [
			'post_status' => (string) get_option( Plugin_Constants::OPTION_DEFAULT_POST_STATUS, 'draft' ),
			'category'    => (int) get_option( Plugin_Constants::OPTION_DEFAULT_CATEGORY, 0 ),
			'tags'        => (array) get_option( Plugin_Constants::OPTION_DEFAULT_TAGS, [] ),
		];
	}

	/**
	 * Saves the global post defaults.
	 *
	 * @param array{post_status?: string, category?: int, tags?: string[]} $defaults
	 * @return bool Whether all three options were saved successfully.
	 */
	public function save_post_defaults( array $defaults ): bool {
		$allowed_statuses = [ 'draft', 'publish' ];
		$status           = in_array( $defaults['post_status'] ?? '', $allowed_statuses, true )
			? $defaults['post_status']
			: 'draft';

		$a = update_option( Plugin_Constants::OPTION_DEFAULT_POST_STATUS, $status );
		$b = update_option( Plugin_Constants::OPTION_DEFAULT_CATEGORY, absint( $defaults['category'] ?? 0 ) );
		$c = update_option( Plugin_Constants::OPTION_DEFAULT_TAGS, (array) ( $defaults['tags'] ?? [] ) );

		return $a && $b && $c;
	}

	// -------------------------------------------------------------------------
	// Notification Settings
	// -------------------------------------------------------------------------

	/**
	 * Returns the current notification settings.
	 *
	 * @return array{enabled: bool, email: string, email_secondary: string, trigger: string}
	 */
	public function get_notification_settings(): array {
		return [
			'enabled'         => (bool) get_option( Plugin_Constants::OPTION_NOTIFICATIONS_ENABLED, false ),
			'email'           => (string) get_option( Plugin_Constants::OPTION_NOTIFICATION_EMAIL, get_option( 'admin_email', '' ) ),
			'email_secondary' => (string) get_option( Plugin_Constants::OPTION_NOTIFICATION_EMAIL_SECONDARY, '' ),
			'trigger'         => (string) get_option( Plugin_Constants::OPTION_NOTIFICATION_TRIGGER, 'draft' ),
		];
	}

	/**
	 * Saves notification settings.
	 *
	 * Validates email addresses before saving. Returns an error array if invalid.
	 *
	 * @param array{enabled?: bool, email?: string, email_secondary?: string, trigger?: string} $data
	 * @return array{saved: bool, errors: string[]}
	 */
	public function save_notification_settings( array $data ): array {
		$errors = [];

		$email = $data['email'] ?? '';
		if ( ! empty( $email ) && ! is_email( $email ) ) {
			$errors[] = sprintf(
				/* translators: %s: submitted email address */
				__( '"%s" is not a valid email address.', 'changelog-to-blog-post' ),
				$email
			);
		}

		$email_secondary = $data['email_secondary'] ?? '';
		if ( ! empty( $email_secondary ) && ! is_email( $email_secondary ) ) {
			$errors[] = sprintf(
				/* translators: %s: submitted email address */
				__( 'Secondary email "%s" is not a valid email address.', 'changelog-to-blog-post' ),
				$email_secondary
			);
		}

		if ( ! empty( $errors ) ) {
			return [ 'saved' => false, 'errors' => $errors ];
		}

		$allowed_triggers = [ 'draft', 'publish', 'both' ];
		$trigger          = in_array( $data['trigger'] ?? '', $allowed_triggers, true )
			? $data['trigger']
			: 'draft';

		update_option( Plugin_Constants::OPTION_NOTIFICATIONS_ENABLED, ! empty( $data['enabled'] ) );
		update_option( Plugin_Constants::OPTION_NOTIFICATION_EMAIL, $email );
		update_option( Plugin_Constants::OPTION_NOTIFICATION_EMAIL_SECONDARY, $email_secondary );
		update_option( Plugin_Constants::OPTION_NOTIFICATION_TRIGGER, $trigger );

		return [ 'saved' => true, 'errors' => [] ];
	}

	// -------------------------------------------------------------------------
	// Check Frequency
	// -------------------------------------------------------------------------

	/**
	 * Returns the current release-check cron frequency.
	 *
	 * @return string One of 'hourly', 'twicedaily', 'daily', 'weekly'.
	 */
	public function get_check_frequency(): string {
		$freq = (string) get_option( Plugin_Constants::OPTION_CHECK_INTERVAL, 'daily' );
		return in_array( $freq, self::VALID_FREQUENCIES, true ) ? $freq : 'daily';
	}

	/**
	 * Saves the cron frequency and immediately reschedules the release-check event.
	 *
	 * @param string $frequency New frequency value.
	 * @return bool Whether the save and reschedule succeeded.
	 */
	public function save_check_frequency( string $frequency ): bool {
		if ( ! in_array( $frequency, self::VALID_FREQUENCIES, true ) ) {
			return false;
		}

		update_option( Plugin_Constants::OPTION_CHECK_INTERVAL, $frequency );

		// Reschedule: clear the existing event and register a new one with the updated interval.
		wp_clear_scheduled_hook( Plugin_Constants::CRON_HOOK_RELEASE_CHECK );
		wp_schedule_event( time(), $frequency, Plugin_Constants::CRON_HOOK_RELEASE_CHECK );

		return true;
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
		return $encrypted !== '' ? $this->decrypt( $encrypted ) : '';
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
		if ( $pat === self::MASKED_PLACEHOLDER ) {
			return true; // No change.
		}

		if ( '' === $pat ) {
			return (bool) update_option( Plugin_Constants::OPTION_GITHUB_PAT, '' );
		}

		return (bool) update_option( Plugin_Constants::OPTION_GITHUB_PAT, $this->encrypt( $pat ) );
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
		return $stored !== '' ? self::MASKED_PLACEHOLDER : '';
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
		$auth_key = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'default-insecure-key';
		return substr(
			sodium_crypto_generichash( $auth_key ),
			0,
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}
}
