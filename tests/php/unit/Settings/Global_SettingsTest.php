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
	// Notification validation
	// -------------------------------------------------------------------------

	/**
	 * save_notification_settings() returns an error for an invalid primary email.
	 */
	public function test_save_notification_settings_rejects_invalid_email(): void {
		\WP_Mock::userFunction( 'is_email' )
			->with( 'not-an-email' )
			->andReturn( false );

		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$result = ( new Global_Settings() )->save_notification_settings(
			[ 'email' => 'not-an-email', 'email_secondary' => '' ]
		);

		$this->assertFalse( $result['saved'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	/**
	 * save_notification_settings() saves successfully for valid emails.
	 */
	public function test_save_notification_settings_saves_valid_emails(): void {
		\WP_Mock::userFunction( 'is_email' )->andReturn( true );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		$result = ( new Global_Settings() )->save_notification_settings(
			[
				'enabled'         => true,
				'email'           => 'admin@example.com',
				'email_secondary' => '',
				'trigger'         => 'draft',
			]
		);

		$this->assertTrue( $result['saved'] );
		$this->assertEmpty( $result['errors'] );
	}

	// -------------------------------------------------------------------------
	// Post defaults
	// -------------------------------------------------------------------------

	/**
	 * get_post_defaults() returns 'draft' as default post status when option is not set.
	 */
	public function test_get_post_defaults_returns_draft_as_default_status(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_DEFAULT_POST_STATUS, 'draft' )
			->andReturn( 'draft' );

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_DEFAULT_CATEGORY, 0 )
			->andReturn( 0 );

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_DEFAULT_TAGS, [] )
			->andReturn( [] );

		$defaults = ( new Global_Settings() )->get_post_defaults();

		$this->assertSame( 'draft', $defaults['post_status'] );
		$this->assertSame( 0, $defaults['category'] );
		$this->assertSame( [], $defaults['tags'] );
	}
}
