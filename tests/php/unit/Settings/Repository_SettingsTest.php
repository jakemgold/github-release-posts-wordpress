<?php
/**
 * Tests for Repository_Settings class.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests\Settings;

use GitHubReleasePosts\Settings\Repository_Settings;
use GitHubReleasePosts\Plugin_Constants;
use WP_Mock\Tools\TestCase;

/**
 * Tests for Repository_Settings: normalization, validation, CRUD, display name derivation.
 */
class Repository_SettingsTest extends TestCase {

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
	// normalize_identifier()
	// -------------------------------------------------------------------------

	/**
	 * normalize_identifier() strips a full GitHub URL to owner/repo.
	 */
	public function test_normalize_identifier_strips_github_url(): void {
		$settings = new Repository_Settings();
		$result   = $settings->normalize_identifier( 'https://github.com/owner/repo' );
		$this->assertSame( 'owner/repo', $result );
	}

	/**
	 * normalize_identifier() accepts a bare owner/repo string unchanged.
	 */
	public function test_normalize_identifier_accepts_bare_format(): void {
		$settings = new Repository_Settings();
		$this->assertSame( 'owner/repo', $settings->normalize_identifier( 'owner/repo' ) );
	}

	/**
	 * normalize_identifier() strips a trailing .git suffix.
	 */
	public function test_normalize_identifier_strips_git_suffix(): void {
		$settings = new Repository_Settings();
		$this->assertSame( 'owner/repo', $settings->normalize_identifier( 'owner/repo.git' ) );
	}

	/**
	 * normalize_identifier() throws on an invalid format.
	 */
	public function test_normalize_identifier_rejects_invalid_format(): void {
		$this->expectException( \InvalidArgumentException::class );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		( new Repository_Settings() )->normalize_identifier( 'not-valid' );
	}

	/**
	 * normalize_identifier() rejects a dot-only segment ("owner/.."), which
	 * passes the character-class check but is a path traversal once used in a URL.
	 */
	public function test_normalize_identifier_rejects_dot_segment(): void {
		$this->expectException( \InvalidArgumentException::class );
		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		( new Repository_Settings() )->normalize_identifier( 'owner/..' );
	}

	// -------------------------------------------------------------------------
	// derive_display_name()
	// -------------------------------------------------------------------------

	/**
	 * derive_display_name() converts hyphens and underscores to spaces with title case.
	 */
	public function test_derive_display_name_converts_hyphens_and_underscores(): void {
		$settings = new Repository_Settings();
		$this->assertSame( 'My Awesome Plugin', $settings->derive_display_name( 'my-awesome-plugin' ) );
		$this->assertSame( 'My Awesome Plugin', $settings->derive_display_name( 'my_awesome_plugin' ) );
	}

	// -------------------------------------------------------------------------
	// get_display_name()
	// -------------------------------------------------------------------------

	/**
	 * get_display_name() returns the configured display_name when set.
	 */
	public function test_get_display_name_uses_configured_value(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn(
				[
					[
						'identifier'   => 'owner/my-plugin',
						'display_name' => 'My Custom Plugin',
					],
				]
			);

		$settings = new Repository_Settings();
		$this->assertSame( 'My Custom Plugin', $settings->get_display_name( 'owner/my-plugin' ) );
	}

	/**
	 * get_display_name() derives from the repo slug when no display_name is configured.
	 */
	public function test_get_display_name_derives_when_not_configured(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( [] );

		$settings = new Repository_Settings();
		$this->assertSame( 'Cool Widget', $settings->get_display_name( 'unknown-org/cool-widget' ) );
	}

	// -------------------------------------------------------------------------
	// add_repository()
	// -------------------------------------------------------------------------

	/**
	 * add_repository() returns an error when the repo is already tracked.
	 */
	public function test_add_repository_rejects_duplicate(): void {
		$existing = [
			[ 'identifier' => 'owner/repo', 'display_name' => 'Owner Repo', 'paused' => false, 'plugin_link' => '', 'post_status' => '', 'categories' => [], 'tags' => [] ],
		];

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );
		\WP_Mock::userFunction( 'apply_filters' )
			->with( 'ghrp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
			->andReturn( Repository_Settings::MAX_REPOSITORIES );

		$result = ( new Repository_Settings() )->add_repository( 'owner/repo' );

		$this->assertFalse( $result['success'] );
		$this->assertNotEmpty( $result['error'] );
	}

	/**
	 * add_repository() enforces the repository limit.
	 */
	public function test_add_repository_enforces_limit(): void {
		// Build 25 fake repos.
		$existing = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$existing[] = [ 'identifier' => "owner/repo{$i}", 'display_name' => "Repo {$i}", 'paused' => false, 'plugin_link' => '', 'post_status' => '', 'categories' => [], 'tags' => [] ];
		}

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'apply_filters' )
			->with( 'ghrp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
			->andReturn( 25 );

		\WP_Mock::userFunction( '__' )->andReturnArg( 0 );

		$result = ( new Repository_Settings() )->add_repository( 'owner/new-repo' );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( '25', $result['error'] );
	}

	/**
	 * add_repository() saves the new repo and returns success.
	 */
	public function test_add_repository_saves_successfully(): void {
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( [] );

		\WP_Mock::userFunction( 'apply_filters' )
			->with( 'ghrp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
			->andReturn( Repository_Settings::MAX_REPOSITORIES );

		\WP_Mock::userFunction( 'update_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, \Mockery::type( 'array' ), false )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 1 );

		$result = ( new Repository_Settings() )->add_repository( 'owner/new-repo' );

		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['error'] );
		$this->assertCount( 1, $result['repos'] );
	}

	// -------------------------------------------------------------------------
	// remove_repository()
	// -------------------------------------------------------------------------

	/**
	 * remove_repository() only removes the target repo, leaving others intact.
	 */
	public function test_remove_repository_does_not_affect_other_repos(): void {
		$existing = [
			[ 'identifier' => 'owner/repo-a', 'display_name' => 'Repo A', 'paused' => false, 'plugin_link' => '', 'post_status' => '', 'categories' => [], 'tags' => [] ],
			[ 'identifier' => 'owner/repo-b', 'display_name' => 'Repo B', 'paused' => false, 'plugin_link' => '', 'post_status' => '', 'categories' => [], 'tags' => [] ],
		];

		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'update_option' )
			->andReturnUsing( function ( $option, $value ) {
				// Assert repo-b is still in the saved array.
				$this->assertCount( 1, $value );
				$this->assertSame( 'owner/repo-b', $value[0]['identifier'] );
				return true;
			} );

		$result = ( new Repository_Settings() )->remove_repository( 'owner/repo-a' );

		$this->assertTrue( $result );
	}

	// -------------------------------------------------------------------------
	// update_repository() / save_repositories() no-op handling
	// -------------------------------------------------------------------------

	/**
	 * Re-saving a repository with no changes is a successful no-op, not a
	 * failure. WordPress update_option() returns false when the stored value is
	 * unchanged; save_repositories() must not surface that as an error — this is
	 * the source of the spurious "repository update failed" message.
	 */
	public function test_update_repository_treats_unchanged_save_as_success(): void {
		$existing = [
			[ 'identifier' => 'owner/repo-a', 'display_name' => 'Repo A', 'paused' => false, 'plugin_link' => '', 'author' => 1, 'post_status' => 'draft', 'categories' => [], 'tags' => [], 'featured_image' => 0 ],
		];

		// get_repositories() reads this, and so does the no-op fallback check.
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( $existing );

		// WordPress signals "value unchanged" by returning false.
		\WP_Mock::userFunction( 'update_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, \Mockery::type( 'array' ), false )
			->andReturn( false );

		// Same values back in — nothing actually changes.
		$result = ( new Repository_Settings() )->update_repository(
			'owner/repo-a',
			[ 'display_name' => 'Repo A', 'paused' => false, 'plugin_link' => '', 'author' => 1, 'post_status' => 'draft', 'categories' => [], 'tags' => [], 'featured_image' => 0 ]
		);

		$this->assertTrue( $result, 'An unchanged save is a no-op success, not a failure.' );
	}

	/**
	 * A genuine save failure — update_option() returns false and the stored
	 * value still differs from what we tried to write — is reported as a
	 * failure, so real errors are not masked by the no-op handling.
	 */
	public function test_update_repository_reports_genuine_save_failure(): void {
		$existing = [
			[ 'identifier' => 'owner/repo-a', 'display_name' => 'Repo A', 'paused' => false, 'plugin_link' => '', 'author' => 1, 'post_status' => 'draft', 'categories' => [], 'tags' => [], 'featured_image' => 0 ],
		];

		// The write never takes effect: get_option keeps returning the old value.
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, [] )
			->andReturn( $existing );

		\WP_Mock::userFunction( 'update_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, \Mockery::type( 'array' ), false )
			->andReturn( false );

		// Rename the repo so the desired value differs from what is stored.
		$result = ( new Repository_Settings() )->update_repository(
			'owner/repo-a',
			[ 'display_name' => 'Renamed Repo' ]
		);

		$this->assertFalse( $result, 'A write that did not persist must report failure.' );
	}
}
