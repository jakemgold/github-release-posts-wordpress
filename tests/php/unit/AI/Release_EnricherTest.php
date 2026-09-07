<?php
/**
 * Tests for AI\Release_Enricher.
 *
 * @package GitHubReleasePosts\Tests\AI
 */

namespace GitHubReleasePosts\Tests\AI;

use GitHubReleasePosts\AI\Release_Enricher;
use GitHubReleasePosts\AI\ReleaseData;
use GitHubReleasePosts\GitHub\API_Client;
use WP_Mock\Tools\TestCase;

/**
 * Covers reference enrichment: multibyte-safe truncation and the
 * authenticated-vs-anonymous fetch rule for off-repository links.
 */
class Release_EnricherTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();
		\WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing( static fn( $thing ) => $thing instanceof \WP_Error );
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	private function release_data( string $body ): ReleaseData {
		return new ReleaseData(
			identifier:   'owner/repo',
			tag:          'v1.2.0',
			name:         'v1.2.0',
			body:         $body,
			html_url:     'https://github.com/owner/repo/releases/tag/v1.2.0',
			published_at: '2026-01-01T00:00:00Z',
		);
	}

	/**
	 * A 500-character cut must never split a multibyte character: the
	 * appended text has to stay valid UTF-8 or the AI client's JSON encoding
	 * fails deterministically for the release.
	 */
	public function test_truncation_is_multibyte_safe(): void {
		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_issue' )->willReturn(
			[
				'title' => 'Long PR',
				'body'  => str_repeat( 'a', 499 ) . 'é' . str_repeat( 'b', 60 ),
			]
		);

		$result = ( new Release_Enricher( $api ) )->enrich( 'Fixes #12', $this->release_data( 'Fixes #12' ) );

		$this->assertTrue( mb_check_encoding( $result, 'UTF-8' ) );
		$this->assertStringContainsString( 'aé…', $result );
		$this->assertStringNotContainsString( 'bbb', $result );
	}

	/**
	 * References to the tracked repository are fetched with the site's PAT;
	 * links to any other repository are fetched anonymously, so a release
	 * note can never pull private content into a post via the token.
	 */
	public function test_off_repo_references_are_fetched_unauthenticated(): void {
		$calls = [];

		$api = $this->createMock( API_Client::class );
		$api->method( 'fetch_issue' )->willReturnCallback(
			function ( string $identifier, int $number, bool $authenticated = true ) use ( &$calls ) {
				$calls[] = [ $identifier, $number, $authenticated ];
				return [
					'title' => 'Ref',
					'body'  => 'Body',
				];
			}
		);

		$body = 'See https://github.com/acme-org/private-app/issues/7 and #5.';
		( new Release_Enricher( $api ) )->enrich( $body, $this->release_data( $body ) );

		$this->assertContains( [ 'acme-org/private-app', 7, false ], $calls );
		$this->assertContains( [ 'owner/repo', 5, true ], $calls );
	}
}
