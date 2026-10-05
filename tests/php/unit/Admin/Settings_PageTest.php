<?php
/**
 * Tests for Settings_Page.
 *
 * @package GitHubReleasePosts\Tests\Admin
 */

namespace GitHubReleasePosts\Tests\Admin;

use GitHubReleasePosts\Admin\Settings_Page;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Settings\Global_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GitHubReleasePosts\Admin\Settings_Page
 */
class Settings_PageTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v )->byDefault();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * When the PAT is supplied by a constant/env, saving the settings page must
	 * NOT wipe the stored database ciphertext — the disabled field submits an
	 * empty value, but the stored token must survive as a fallback.
	 */
	public function test_sanitize_preserves_stored_pat_when_externally_managed(): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_github_pat_source' )->willReturn( 'constant' );
		$global->expects( $this->never() )->method( 'encrypt' );

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_GITHUB_PAT, '' )
			->andReturn( 'STORED_CIPHERTEXT' );

		$page   = new Settings_Page( $global );
		$result = $page->sanitize_github_pat( '' );

		$this->assertSame( 'STORED_CIPHERTEXT', $result );
	}

	/**
	 * When the PAT is database-managed, a newly submitted token is encrypted and
	 * stored as normal.
	 */
	public function test_sanitize_encrypts_new_value_when_db_managed(): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_github_pat_source' )->willReturn( 'db' );
		$global->method( 'encrypt' )->with( 'ghp_new_token' )->willReturn( 'ENCRYPTED' );

		$page   = new Settings_Page( $global );
		$result = $page->sanitize_github_pat( 'ghp_new_token' );

		$this->assertSame( 'ENCRYPTED', $result );
	}

	/**
	 * When encryption fails, the error must be registered under the option
	 * GROUP — the page template renders settings_errors( OPTION_GROUP ), which
	 * filters by that first argument, so an error registered under the option
	 * name is silently hidden (the admin saw "Settings saved." while the token
	 * quietly vanished; observed in production).
	 */
	public function test_sanitize_encrypt_failure_registers_visible_error_and_preserves_value(): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_github_pat_source' )->willReturn( 'none' );
		$global->method( 'encrypt' )->with( 'ghp_new_token' )->willReturn( '' );

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_GITHUB_PAT, '' )
			->andReturn( 'EXISTING_CIPHERTEXT' );

		\WP_Mock::userFunction( 'add_settings_error' )
			->once()
			->with(
				Settings_Page::OPTION_GROUP,
				'ghrp_pat_encrypt_failed',
				\Mockery::type( 'string' ),
				'error'
			);

		$page   = new Settings_Page( $global );
		$result = $page->sanitize_github_pat( 'ghp_new_token' );

		$this->assertSame( 'EXISTING_CIPHERTEXT', $result );
	}

	/**
	 * A stored token that no longer decrypts (AUTH_KEY rotated, or the
	 * database moved to a site with different salts) must be reported: the
	 * field still shows the masked placeholder while every request has been
	 * going out unauthenticated.
	 */
	public function test_pat_field_reports_a_token_that_no_longer_decrypts(): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_masked_github_pat' )->willReturn( Global_Settings::MASKED_PLACEHOLDER );
		$global->method( 'get_github_pat_source' )->willReturn( 'db' );
		$global->method( 'get_github_pat' )->willReturn( '' );
		$global->method( 'can_encrypt' )->willReturn( true );

		\WP_Mock::userFunction( 'disabled' )->andReturn( '' );

		ob_start();
		( new Settings_Page( $global ) )->render_github_pat_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ghrp-pat-status--invalid', $html );
		$this->assertStringContainsString( 'can no longer be decrypted', $html );
	}

	/**
	 * With no token stored at all, the status stays quiet.
	 */
	public function test_pat_field_shows_no_status_without_a_token(): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_masked_github_pat' )->willReturn( '' );
		$global->method( 'get_github_pat_source' )->willReturn( 'none' );
		$global->method( 'get_github_pat' )->willReturn( '' );
		$global->method( 'can_encrypt' )->willReturn( true );

		\WP_Mock::userFunction( 'disabled' )->andReturn( '' );

		ob_start();
		( new Settings_Page( $global ) )->render_github_pat_field();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'ghrp-pat-status--none', $html );
		$this->assertStringNotContainsString( 'decrypted', $html );
	}

	/**
	 * A definitive token check (valid, or rejected with 401) is cached for
	 * 15 minutes; a timeout or GitHub outage only for a minute, so a
	 * transient failure does not show the token as invalid for 15 minutes.
	 *
	 * @dataProvider token_check_ttl_provider
	 */
	public function test_token_check_caches_only_definitive_answers( int $http_code, int $expected_ttl ): void {
		$global = $this->createMock( Global_Settings::class );
		$global->method( 'get_masked_github_pat' )->willReturn( Global_Settings::MASKED_PLACEHOLDER );
		$global->method( 'get_github_pat_source' )->willReturn( 'db' );
		$global->method( 'get_github_pat' )->willReturn( 'ghp_token' );
		$global->method( 'can_encrypt' )->willReturn( true );

		\WP_Mock::userFunction( 'disabled' )->andReturn( '' );
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_get' )->andReturn( [] );
		\WP_Mock::userFunction( 'is_wp_error' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_remote_retrieve_response_code' )->andReturn( $http_code );
		\WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( \Mockery::type( 'string' ), \Mockery::type( 'array' ), $expected_ttl );

		ob_start();
		( new Settings_Page( $global ) )->render_github_pat_field();
		ob_end_clean();

		$this->assertConditionsMet();
	}

	public static function token_check_ttl_provider(): array {
		return [
			'valid token'      => [ 200, 15 * MINUTE_IN_SECONDS ],
			'rejected token'   => [ 401, 15 * MINUTE_IN_SECONDS ],
			'GitHub 503'       => [ 503, MINUTE_IN_SECONDS ],
			'rate-limited 403' => [ 403, MINUTE_IN_SECONDS ],
		];
	}
}
