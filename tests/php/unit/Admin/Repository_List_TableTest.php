<?php
/**
 * Tests for Repository_List_Table class.
 *
 * @package GitHubReleasePosts\Tests
 */

namespace GitHubReleasePosts\Tests\Admin;

use GitHubReleasePosts\Admin\Repository_List_Table;
use WP_Mock\Tools\TestCase;

/**
 * Tests the Quick Edit author dropdown user-ID resolution.
 */
class Repository_List_TableTest extends TestCase {

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

	/**
	 * Creates a minimal user object with an ID, as returned by get_user_by().
	 *
	 * @param int $id User ID.
	 * @return object
	 */
	private function make_user( int $id ): object {
		return (object) [ 'ID' => $id ];
	}

	/**
	 * On single site, returns the capability-query IDs untouched.
	 */
	public function test_single_site_returns_capability_query_ids(): void {
		\WP_Mock::userFunction( 'get_users' )
			->once()
			->with(
				[
					'capability' => 'edit_posts',
					'fields'     => 'ID',
				]
			)
			->andReturn( [ '3', '7' ] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );

		$this->assertSame( [ 3, 7 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}

	/**
	 * On multisite, super admins who are members of the site are merged in
	 * even though the capability query cannot see them.
	 */
	public function test_multisite_merges_super_admin_site_members(): void {
		\WP_Mock::userFunction( 'get_users' )->once()->andReturn( [ 3 ] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_super_admins' )->once()->andReturn( [ 'network-admin', 'absent-admin' ] );

		\WP_Mock::userFunction( 'get_user_by' )
			->with( 'login', 'network-admin' )
			->andReturn( $this->make_user( 42 ) );
		\WP_Mock::userFunction( 'get_user_by' )
			->with( 'login', 'absent-admin' )
			->andReturn( $this->make_user( 99 ) );

		// Only the first super admin is a member of the current site.
		\WP_Mock::userFunction( 'is_user_member_of_blog' )->with( 42 )->andReturn( true );
		\WP_Mock::userFunction( 'is_user_member_of_blog' )->with( 99 )->andReturn( false );

		$this->assertSame( [ 3, 42 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}

	/**
	 * A super admin already matched by the capability query (they hold a
	 * local role with edit_posts) is not duplicated.
	 */
	public function test_multisite_does_not_duplicate_super_admin_with_local_role(): void {
		\WP_Mock::userFunction( 'get_users' )->once()->andReturn( [ 3, 42 ] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_super_admins' )->once()->andReturn( [ 'network-admin' ] );
		\WP_Mock::userFunction( 'get_user_by' )
			->with( 'login', 'network-admin' )
			->andReturn( $this->make_user( 42 ) );
		\WP_Mock::userFunction( 'is_user_member_of_blog' )->never();

		$this->assertSame( [ 3, 42 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}

	/**
	 * A super admin login with no matching user object is skipped.
	 */
	public function test_multisite_skips_unresolvable_super_admin_login(): void {
		\WP_Mock::userFunction( 'get_users' )->once()->andReturn( [ 3 ] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_super_admins' )->once()->andReturn( [ 'ghost' ] );
		\WP_Mock::userFunction( 'get_user_by' )
			->with( 'login', 'ghost' )
			->andReturn( false );

		$this->assertSame( [ 3 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}

	/**
	 * An empty result set returns a non-matching sentinel ID rather than an
	 * empty list, because wp_dropdown_users() treats an empty include as
	 * "no constraint" and would list every user.
	 */
	public function test_empty_result_returns_sentinel_id(): void {
		\WP_Mock::userFunction( 'get_users' )->once()->andReturn( [] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );

		$this->assertSame( [ 0 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}

	/**
	 * The ghrp_author_dropdown_user_ids filter can extend the list, and its
	 * return value is normalized (deduplicated, re-indexed, cast to int).
	 */
	public function test_filter_can_extend_and_output_is_normalized(): void {
		\WP_Mock::userFunction( 'get_users' )->once()->andReturn( [ 3 ] );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );

		\WP_Mock::onFilter( 'ghrp_author_dropdown_user_ids' )
			->with( [ 3 ] )
			->reply( [ 3, '3', 8, 8 ] );

		$this->assertSame( [ 3, 8 ], Repository_List_Table::get_author_dropdown_user_ids() );
	}
}
