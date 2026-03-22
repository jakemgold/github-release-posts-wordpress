<?php
/**
 * Tests for Repository_Settings class.
 *
 * @package ChangelogToBlogPost\Tests
 */

namespace TenUp\ChangelogToBlogPost\Tests\Settings;

use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
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
			->with( 'ctbp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
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
			->with( 'ctbp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
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
			->with( 'ctbp_max_repositories', Repository_Settings::MAX_REPOSITORIES )
			->andReturn( Repository_Settings::MAX_REPOSITORIES );

		\WP_Mock::userFunction( 'update_option' )
			->with( Plugin_Constants::OPTION_REPOSITORIES, \Mockery::type( 'array' ) )
			->andReturn( true );

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
}
