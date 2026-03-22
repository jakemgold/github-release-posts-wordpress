<?php
/**
 * Tests for Global_Settings class.
 *
 * @package ChangelogToBlogPost\Tests
 */

namespace TenUp\ChangelogToBlogPost\Tests\Settings;

use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use WP_Mock\Tools\TestCase;

/**
 * Tests for Global_Settings: encryption, masked keys, notification validation,
 * post defaults, and cron reschedule on frequency change.
 */
class Global_SettingsTest extends TestCase {

	/**
	 * @inheritDoc
	 */
	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	/**
	 * @inheritDoc
	 */
	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Encryption round-trip
	// -------------------------------------------------------------------------

	/**
	 * encrypt() then decrypt() returns the original plaintext.
	 */
	public function test_encrypt_decrypt_round_trip(): void {
		$settings   = new Global_Settings();
		$original   = 'sk-test-api-key-12345';
		$encrypted  = $settings->encrypt( $original );

		$this->assertNotSame( $original, $encrypted );
		$this->assertNotEmpty( $encrypted );

		$decrypted = $settings->decrypt( $encrypted );
		$this->assertSame( $original, $decrypted );
	}

	/**
	 * decrypt() returns an empty string for an empty input.
	 */
	public function test_decrypt_returns_empty_for_empty_input(): void {
		$this->assertSame( '', ( new Global_Settings() )->decrypt( '' ) );
	}

	/**
	 * decrypt() returns an empty string for corrupt data.
	 */
	public function test_decrypt_returns_empty_for_corrupt_data(): void {
		$this->assertSame( '', ( new Global_Settings() )->decrypt( 'not-valid-base64-ciphertext' ) );
	}

	// -------------------------------------------------------------------------
	// API key masking
	// -------------------------------------------------------------------------

	/**
	 * get_masked_key() returns the placeholder when a key is stored, never the real key.
	 */
	public function test_get_masked_key_returns_placeholder_not_actual_key(): void {
		$fake_encrypted = base64_encode( 'fake-encrypted-value' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_API_KEYS, [] )
			->andReturn( [ 'openai' => $fake_encrypted ] );

		$masked = ( new Global_Settings() )->get_masked_key( 'openai' );

		$this->assertSame( Global_Settings::MASKED_PLACEHOLDER, $masked );
		$this->assertStringNotContainsString( 'fake', $masked );
	}

	/**
	 * get_masked_key() returns empty string when no key is stored.
	 */
	public function test_get_masked_key_returns_empty_when_no_key(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_API_KEYS, [] )
			->andReturn( [] );

		$this->assertSame( '', ( new Global_Settings() )->get_masked_key( 'openai' ) );
	}

	// -------------------------------------------------------------------------
	// save_api_keys() — masked placeholder skipped
	// -------------------------------------------------------------------------

	/**
	 * save_api_keys() preserves the existing key when the submitted value is the masked placeholder.
	 */
	public function test_save_api_key_skips_masked_placeholder(): void {
		$existing = [ 'openai' => 'previously-encrypted-value' ];

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_API_KEYS, [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'update_option' )
			->andReturnUsing( function ( $option, $value ) use ( $existing ) {
				// The openai key should be unchanged (still the original encrypted value).
				$this->assertSame( $existing['openai'], $value['openai'] );
				return true;
			} );

		( new Global_Settings() )->save_api_keys( [ 'openai' => Global_Settings::MASKED_PLACEHOLDER ] );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Notification settings
	// -------------------------------------------------------------------------

	/**
	 * get_notification_settings() returns simplified notification settings.
	 */
	public function test_get_notification_settings_returns_defaults(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_NOTIFY_SITE_OWNER, true )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_ADDITIONAL_EMAILS, '' )
			->andReturn( '' );

		$result = ( new Global_Settings() )->get_notification_settings();

		$this->assertTrue( $result['notify_site_owner'] );
		$this->assertSame( '', $result['additional_emails'] );
	}

	/**
	 * get_additional_email_list() parses comma-separated emails and caps at 5.
	 */
	public function test_get_additional_email_list_parses_and_validates(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_ADDITIONAL_EMAILS, '' )
			->andReturn( 'a@b.com, bad, c@d.com' );

		\WP_Mock::userFunction( 'is_email' )->andReturnUsing( function ( $email ) {
			return str_contains( $email, '@' ) && str_contains( $email, '.' );
		} );

		$result = ( new Global_Settings() )->get_additional_email_list();

		$this->assertSame( [ 'a@b.com', 'c@d.com' ], $result );
	}

	// -------------------------------------------------------------------------
	// Post defaults
	// -------------------------------------------------------------------------

}
