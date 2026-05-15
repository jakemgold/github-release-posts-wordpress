<?php
/**
 * Tests for the Post_Status helper.
 *
 * @package GitHubReleasePosts\Tests\Post
 */

namespace GitHubReleasePosts\Tests\Post;

use GitHubReleasePosts\Post\Post_Status;
use GitHubReleasePosts\Tests\Post_Status_Defaults;
use WP_Mock\Tools\TestCase;

/**
 * @covers \GitHubReleasePosts\Post\Post_Status
 */
class Post_StatusTest extends TestCase {

	use Post_Status_Defaults;

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		$this->install_post_status_defaults();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// is_public() — WP's `public` flag
	// -------------------------------------------------------------------------

	public function test_is_public_true_for_publish(): void {
		$this->assertTrue( Post_Status::is_public( 'publish' ) );
	}

	public function test_is_public_false_for_draft(): void {
		$this->assertFalse( Post_Status::is_public( 'draft' ) );
	}

	public function test_is_public_false_for_pending(): void {
		$this->assertFalse( Post_Status::is_public( 'pending' ) );
	}

	public function test_is_public_false_for_private(): void {
		// Private has a permalink but is NOT public — only logged-in users see it.
		$this->assertFalse( Post_Status::is_public( 'private' ) );
	}

	public function test_is_public_false_for_unregistered_status(): void {
		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'never-registered' )
			->andReturn( null );

		$this->assertFalse( Post_Status::is_public( 'never-registered' ) );
	}

	/**
	 * The whole point of this helper: a custom status registered as public
	 * (by Edit Flow, PublishPress, etc.) reads as publicly-visible.
	 */
	public function test_is_public_true_for_custom_public_status(): void {
		$obj           = new \stdClass();
		$obj->label    = 'Approved';
		$obj->public   = true;
		$obj->private  = false;
		$obj->internal = false;

		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'approved' )
			->andReturn( $obj );

		$this->assertTrue( Post_Status::is_public( 'approved' ) );
	}

	// -------------------------------------------------------------------------
	// has_permalink() — public OR private (anything with a saved URL)
	// -------------------------------------------------------------------------

	public function test_has_permalink_true_for_publish(): void {
		$this->assertTrue( Post_Status::has_permalink( 'publish' ) );
	}

	public function test_has_permalink_true_for_private(): void {
		// Private posts have URLs too — bookmarkable by logged-in users.
		$this->assertTrue( Post_Status::has_permalink( 'private' ) );
	}

	public function test_has_permalink_false_for_draft(): void {
		$this->assertFalse( Post_Status::has_permalink( 'draft' ) );
	}

	public function test_has_permalink_false_for_pending(): void {
		$this->assertFalse( Post_Status::has_permalink( 'pending' ) );
	}

	public function test_has_permalink_false_for_unregistered_status(): void {
		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'unknown' )
			->andReturn( null );

		$this->assertFalse( Post_Status::has_permalink( 'unknown' ) );
	}

	// -------------------------------------------------------------------------
	// label() — localised status label from the registry
	// -------------------------------------------------------------------------

	public function test_label_returns_registered_label(): void {
		$this->assertSame( 'Draft', Post_Status::label( 'draft' ) );
		$this->assertSame( 'Pending Review', Post_Status::label( 'pending' ) );
	}

	public function test_label_returns_empty_string_for_unregistered_status(): void {
		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'never-registered' )
			->andReturn( null );

		$this->assertSame( '', Post_Status::label( 'never-registered' ) );
	}

	public function test_label_returns_custom_status_label(): void {
		$obj        = new \stdClass();
		$obj->label = 'Pitch';
		$obj->public = false;
		$obj->private = false;

		\WP_Mock::userFunction( 'get_post_status_object' )
			->with( 'pitch' )
			->andReturn( $obj );

		$this->assertSame( 'Pitch', Post_Status::label( 'pitch' ) );
	}
}
