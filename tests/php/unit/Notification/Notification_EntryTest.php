<?php
/**
 * Tests for the Notification_Entry value object.
 *
 * @package GitHubReleasePosts\Tests\Notification
 */

namespace GitHubReleasePosts\Tests\Notification;

use GitHubReleasePosts\Notification\Notification_Entry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GitHubReleasePosts\Notification\Notification_Entry
 */
class Notification_EntryTest extends TestCase {

	public function test_constructs_from_named_arguments(): void {
		$entry = new Notification_Entry(
			post_id:      42,
			status:       'publish',
			identifier:   'owner/repo',
			display_name: 'My Plugin',
			tag:          'v1.0.0',
			html_url:     'https://github.com/owner/repo/releases/tag/v1.0.0',
			post_title:   'My Plugin 1.0',
		);

		$this->assertSame( 42, $entry->post_id );
		$this->assertSame( 'publish', $entry->status );
		$this->assertSame( 'owner/repo', $entry->identifier );
		$this->assertSame( 'My Plugin', $entry->display_name );
		$this->assertSame( 'v1.0.0', $entry->tag );
		$this->assertSame( 'https://github.com/owner/repo/releases/tag/v1.0.0', $entry->html_url );
		$this->assertSame( 'My Plugin 1.0', $entry->post_title );
	}

	public function test_properties_are_readonly(): void {
		$entry = new Notification_Entry(
			post_id:      1,
			status:       'draft',
			identifier:   'a/b',
			display_name: 'B',
			tag:          'v1',
			html_url:     'https://example.com',
			post_title:   'Hello',
		);

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line — intentional readonly violation under test.
		$entry->status = 'publish';
	}

	public function test_required_fields_cannot_be_skipped(): void {
		$this->expectException( \ArgumentCountError::class );
		// @phpstan-ignore-next-line — intentional missing-argument test.
		new Notification_Entry( post_id: 1 );
	}
}
