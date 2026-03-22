<?php
/**
 * Tests for Email_Notifier.
 *
 * @package ChangelogToBlogPost\Tests\Notification
 */

namespace TenUp\ChangelogToBlogPost\Tests\Notification;

use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\AI\Release_Significance;
use TenUp\ChangelogToBlogPost\Notification\Email_Notifier;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use WP_Mock\Tools\TestCase;

/**
 * @covers \TenUp\ChangelogToBlogPost\Notification\Email_Notifier
 */
class Email_NotifierTest extends TestCase {

	private Email_Notifier $notifier;
	private Global_Settings $global_settings;
	private Release_Significance $significance;

	public function setUp(): void {
		parent::setUp();

		$this->global_settings = \Mockery::mock( Global_Settings::class );
		$this->significance    = \Mockery::mock( Release_Significance::class );
		$this->significance->shouldReceive( 'classify' )->andReturn( 'minor' )->byDefault();

		$repo_settings = \Mockery::mock( Repository_Settings::class );
		$repo_settings->shouldReceive( 'get_repository' )->andReturn( [ 'display_name' => 'Test Plugin' ] )->byDefault();
		$repo_settings->shouldReceive( 'derive_display_name' )->andReturn( 'Repo' )->byDefault();

		$this->notifier = new Email_Notifier( $this->global_settings, $this->significance, $repo_settings );
	}

	// -------------------------------------------------------------------------
	// setup()
	// -------------------------------------------------------------------------

	public function test_setup_registers_action(): void {
		\WP_Mock::expectActionAdded( 'ctbp_post_status_set', [ $this->notifier, 'collect' ], 10, 4 );
		$this->notifier->setup();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// collect() — skips manual triggers (AC-008)
	// -------------------------------------------------------------------------

	public function test_collect_skips_force_draft(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->never();
		\WP_Mock::userFunction( 'add_action' )->never();

		$this->notifier->collect( 1, 'draft', $this->make_data(), [ 'force_draft' => true ] );
		$this->assertConditionsMet();
	}

	public function test_collect_skips_bypass_idempotency(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->never();

		$this->notifier->collect( 1, 'draft', $this->make_data(), [ 'bypass_idempotency' => true ] );
		$this->assertConditionsMet();
	}

	public function test_collect_skips_manual_trigger(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->never();

		$this->notifier->collect( 1, 'draft', $this->make_data(), [ 'manual' => true ] );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// collect() — skips when no recipients configured
	// -------------------------------------------------------------------------

	public function test_collect_skips_when_no_recipients(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->andReturn( [
			'notify_site_owner' => false,
			'additional_emails' => '',
		] );
		$this->global_settings->shouldReceive( 'get_additional_email_list' )->andReturn( [] );

		$this->notifier->collect( 1, 'draft', $this->make_data(), [] );

		// send() should have nothing.
		$this->notifier->send();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// collect() + send() — successful email
	// -------------------------------------------------------------------------

	public function test_send_sends_batched_email_to_site_owner(): void {
		$this->mock_site_owner_notifications();

		// Register the shutdown hook expectation.
		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'draft', $this->make_data(), [] );

		// Now send.
		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );

		\WP_Mock::userFunction( 'wp_mail' )
			->once()
			->with(
				'admin@example.com',
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				\Mockery::type( 'array' )
			)
			->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	public function test_send_does_not_send_when_no_entries(): void {
		\WP_Mock::userFunction( 'wp_mail' )->never();

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// send() — always sends for both draft and published posts
	// -------------------------------------------------------------------------

	public function test_send_sends_for_draft_posts(): void {
		$this->mock_site_owner_notifications();
		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'draft', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );

		\WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	public function test_send_sends_for_published_posts(): void {
		$this->mock_site_owner_notifications();
		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'publish', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );
		\WP_Mock::userFunction( 'get_permalink' )->with( 42 )->andReturn( 'https://example.com/my-plugin-update/' );

		\WP_Mock::userFunction( 'wp_mail' )->once()->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// send() — email includes both edit and view links for published
	// -------------------------------------------------------------------------

	public function test_send_includes_view_link_for_published_posts(): void {
		$this->mock_site_owner_notifications();

		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'publish', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );
		\WP_Mock::userFunction( 'get_permalink' )->with( 42 )->andReturn( 'https://example.com/my-plugin-update/' );

		\WP_Mock::userFunction( 'wp_mail' )
			->once()
			->with(
				'admin@example.com',
				\Mockery::type( 'string' ),
				\Mockery::on( function ( $body ) {
					// Both edit and view links should be present.
					return str_contains( $body, 'Edit post' )
						&& str_contains( $body, 'View post' );
				} ),
				\Mockery::type( 'array' )
			)
			->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	public function test_send_omits_view_link_for_draft_posts(): void {
		$this->mock_site_owner_notifications();

		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'draft', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );

		\WP_Mock::userFunction( 'wp_mail' )
			->once()
			->with(
				'admin@example.com',
				\Mockery::type( 'string' ),
				\Mockery::on( function ( $body ) {
					return str_contains( $body, 'Edit post' )
						&& ! str_contains( $body, 'View post' );
				} ),
				\Mockery::type( 'array' )
			)
			->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// send() — additional recipients
	// -------------------------------------------------------------------------

	public function test_send_sends_to_additional_recipients(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->andReturn( [
			'notify_site_owner' => true,
			'additional_emails' => 'editor@example.com, team@example.com',
		] );
		$this->global_settings->shouldReceive( 'get_additional_email_list' )->andReturn( [
			'editor@example.com',
			'team@example.com',
		] );

		\WP_Mock::userFunction( 'get_option' )->with( 'admin_email', '' )->andReturn( 'admin@example.com' );
		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'draft', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );

		// Should be called 3 times — admin + 2 additional.
		\WP_Mock::userFunction( 'wp_mail' )->times( 3 )->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	public function test_send_sends_only_to_additional_emails_when_site_owner_disabled(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->andReturn( [
			'notify_site_owner' => false,
			'additional_emails' => 'editor@example.com',
		] );
		$this->global_settings->shouldReceive( 'get_additional_email_list' )->andReturn( [
			'editor@example.com',
		] );

		\WP_Mock::expectActionAdded( 'shutdown', [ $this->notifier, 'send' ] );

		$this->notifier->collect( 42, 'draft', $this->make_data(), [] );

		\WP_Mock::userFunction( 'get_bloginfo' )->with( 'name' )->andReturn( 'Test Site' );
		\WP_Mock::userFunction( 'get_edit_post_link' )->andReturn( 'https://example.com/wp-admin/post.php?post=42' );

		// Should be called once — only the additional email.
		\WP_Mock::userFunction( 'wp_mail' )
			->once()
			->with(
				'editor@example.com',
				\Mockery::type( 'string' ),
				\Mockery::type( 'string' ),
				\Mockery::type( 'array' )
			)
			->andReturn( true );

		$this->notifier->send();
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// send() — filter can suppress email (AC-013)
	// -------------------------------------------------------------------------

	/**
	 * @group integration
	 */
	public function test_send_suppressed_by_filter(): void {
		// WP_Mock's onFilter() does not support Mockery matchers, and the
		// email_data array is built dynamically, so we cannot construct the
		// exact args for onFilter()->with()->reply(). This test requires
		// integration-level testing with a real WordPress filter system.
		$this->markTestSkipped( 'Filter suppression test requires integration test environment (WP_Mock onFilter cannot match dynamic args).' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_data(): ReleaseData {
		return new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.0.0',
			name:         'v1.0.0',
			body:         'Changes.',
			html_url:     'https://github.com/owner/repo/releases/tag/v1.0.0',
			published_at: '2025-01-01T00:00:00Z',
			assets:       [],
		);
	}

	private function mock_site_owner_notifications(): void {
		$this->global_settings->shouldReceive( 'get_notification_settings' )->andReturn( [
			'notify_site_owner' => true,
			'additional_emails' => '',
		] );
		$this->global_settings->shouldReceive( 'get_additional_email_list' )->andReturn( [] );
		\WP_Mock::userFunction( 'get_option' )->with( 'admin_email', '' )->andReturn( 'admin@example.com' );
	}
}
