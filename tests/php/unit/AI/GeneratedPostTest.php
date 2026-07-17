<?php
/**
 * Tests for AI\GeneratedPost value object.
 *
 * @package GitHubReleasePosts\Tests\AI
 */

namespace GitHubReleasePosts\Tests\AI;

use GitHubReleasePosts\AI\GeneratedPost;
use GitHubReleasePosts\AI\ReleaseData;
use WP_Mock\Tools\TestCase;

class GeneratedPostTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'wp_strip_all_tags' )->andReturnUsing(
			static fn( $text ) => trim( strip_tags( (string) $text ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- test polyfill.
		);
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	/**
	 * Builds a minimal ReleaseData for from_raw_text() calls.
	 */
	private function release_data(): ReleaseData {
		return new ReleaseData(
			identifier:   'acme/widget',
			tag:          'v1.2.3',
			name:         'Widget 1.2.3',
			body:         'Release notes.',
			html_url:     'https://github.com/acme/widget/releases/tag/v1.2.3',
			published_at: '2026-07-01T00:00:00Z',
		);
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

	/**
	 * Baseline: the documented contract format parses into the right seats.
	 */
	public function test_from_raw_text_parses_contract_format(): void {
		$raw = "Clearer content management lands\ncontent-management-security-php82\nWidget 1.2.3 improves content tracking with a security fix.\n\n<p>The latest release…</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( 'Clearer content management lands', $post->title );
		$this->assertSame( 'content-management-security-php82', $post->slug_keywords );
		$this->assertSame( 'Widget 1.2.3 improves content tracking with a security fix.', $post->excerpt );
		$this->assertSame( '<p>The latest release…</p>', $post->content );
	}

	/**
	 * Production bug: a blank line after the title must not shift the
	 * keywords into the excerpt seat and leak the excerpt into the body.
	 */
	public function test_from_raw_text_tolerates_blank_line_after_title(): void {
		$raw = "Clearer content management lands\n\ncontent-management-security-php82\nWidget 1.2.3 improves content tracking with a security fix.\n\n<p>The latest release…</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( 'content-management-security-php82', $post->slug_keywords );
		$this->assertSame( 'Widget 1.2.3 improves content tracking with a security fix.', $post->excerpt );
		$this->assertSame( '<p>The latest release…</p>', $post->content );
		$this->assertStringNotContainsString( 'improves content tracking', $post->content );
	}

	/**
	 * Blank lines between every metadata line still parse into the right seats.
	 */
	public function test_from_raw_text_tolerates_blank_lines_between_metadata(): void {
		$raw = "Title line\n\nslug-keywords-here\n\nA human readable excerpt sentence.\n\n<p>Body.</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( 'slug-keywords-here', $post->slug_keywords );
		$this->assertSame( 'A human readable excerpt sentence.', $post->excerpt );
		$this->assertSame( '<p>Body.</p>', $post->content );
	}

	/**
	 * A slug-shaped excerpt is keywords in the wrong seat: rescued into the
	 * empty keywords field, never saved as a visible excerpt.
	 */
	public function test_from_raw_text_rescues_slug_shaped_excerpt(): void {
		$raw = "Title line\nA human readable excerpt sentence.\nslug-keywords-here\n\n<p>Body.</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		// Keywords seat held prose (not sluggy but under the length cap) and
		// the excerpt seat held the slug: the slug must not surface as excerpt.
		$this->assertSame( '', $post->excerpt );
		$this->assertSame( '<p>Body.</p>', $post->content );
	}

	/**
	 * A slug-shaped excerpt is dropped (not duplicated) when keywords are set.
	 */
	public function test_from_raw_text_drops_duplicate_slug_shaped_excerpt(): void {
		$raw = "Title line\nreal-slug-keywords\nstray-slug-line-here\n\n<p>Body.</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( 'real-slug-keywords', $post->slug_keywords );
		$this->assertSame( '', $post->excerpt );
	}

	/**
	 * HTML in the keywords seat still triggers the everything-is-body fallback.
	 */
	public function test_from_raw_text_html_after_title_is_treated_as_body(): void {
		$raw = "Title line\n\n<p>First paragraph.</p>\n<p>Second.</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( '', $post->slug_keywords );
		$this->assertSame( '', $post->excerpt );
		$this->assertSame( "<p>First paragraph.</p>\n<p>Second.</p>", $post->content );
	}

	/**
	 * HTML in the excerpt seat is shifted into the body, not saved as excerpt.
	 */
	public function test_from_raw_text_html_excerpt_shifts_to_body(): void {
		$raw = "Title line\nslug-keywords-here\n<p>First paragraph.</p>\n<p>Second.</p>";

		$post = GeneratedPost::from_raw_text( $raw, $this->release_data(), 'wp_ai_client' );

		$this->assertSame( 'slug-keywords-here', $post->slug_keywords );
		$this->assertSame( '', $post->excerpt );
		$this->assertSame( "<p>First paragraph.</p>\n<p>Second.</p>", $post->content );
	}
}
