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
}
