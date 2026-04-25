<?php
/**
 * Tests for AI\GeneratedPost value object.
 *
 * @package ChangelogToBlogPost\Tests\AI
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI;

use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use WP_Mock\Tools\TestCase;

class GeneratedPostTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	public function test_constructor_sets_all_properties(): void {
		$post = new GeneratedPost(
			title:         'Test Post Title',
			content:       '<p>Content here.</p>',
			provider_slug: 'wp_ai_client',
		);

		$this->assertSame( 'Test Post Title', $post->title );
		$this->assertSame( '<p>Content here.</p>', $post->content );
		$this->assertSame( 'wp_ai_client', $post->provider_slug );
	}
}
