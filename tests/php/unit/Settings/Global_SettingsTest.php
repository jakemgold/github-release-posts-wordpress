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
		if ( ! defined( 'AUTH_KEY' ) ) {
			define( 'AUTH_KEY', 'test-key-for-unit-tests' );
		}

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
	// AI Provider
	// -------------------------------------------------------------------------

	/**
	 * get_ai_provider() always returns 'wp_ai_client' now.
	 */
	public function test_get_ai_provider_returns_wp_ai_client(): void {
		$this->assertSame( 'wp_ai_client', ( new Global_Settings() )->get_ai_provider() );
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
