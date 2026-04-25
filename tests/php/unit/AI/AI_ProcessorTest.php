<?php
/**
 * Tests for AI\AI_Processor.
 *
 * @package ChangelogToBlogPost\Tests\AI
 */

namespace TenUp\ChangelogToBlogPost\Tests\AI;

use TenUp\ChangelogToBlogPost\AI\AI_Processor;
use TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory;
use TenUp\ChangelogToBlogPost\AI\AIProviderInterface;
use TenUp\ChangelogToBlogPost\AI\GeneratedPost;
use TenUp\ChangelogToBlogPost\AI\ReleaseData;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use WP_Mock\Tools\TestCase;

class AI_ProcessorTest extends TestCase {

	private AI_Provider_Factory $factory;
	private AIProviderInterface $provider;
	private AI_Processor        $processor;

	private array $base_entry = [
		'identifier'   => 'owner/repo',
		'tag'          => 'v1.0.0',
		'name'         => 'v1.0.0',
		'body'         => '',
		'html_url'     => '',
		'published_at' => '2026-01-01T00:00:00Z',
		'assets'       => [],
	];

	public function setUp(): void {
		parent::setUp();
		\WP_Mock::setUp();

		$this->factory   = $this->createMock( AI_Provider_Factory::class );
		$this->provider  = $this->createMock( AIProviderInterface::class );
		$this->processor = new AI_Processor( $this->factory );
	}

	public function tearDown(): void {
		\WP_Mock::tearDown();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Cache hit — skips provider call (AC-010, AC-011)
	// -------------------------------------------------------------------------

	public function test_handle_returns_cached_result_without_api_call(): void {
		$cached = new GeneratedPost( 'Cached Title', '<p>body</p>', 'wp_ai_client' );

		\WP_Mock::userFunction( 'get_transient' )->andReturn( $cached );

		$this->factory->expects( $this->never() )->method( 'get_provider' );

		\WP_Mock::expectAction( 'ctbp_post_generated', $cached, \WP_Mock\Functions::type( ReleaseData::class ), [] );

		$this->processor->handle( $this->base_entry, [] );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Provider error — failure counter incremented (AC-015, AC-016)
	// -------------------------------------------------------------------------

	public function test_handle_records_failure_when_provider_unavailable(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$error = new \WP_Error( 'ctbp_no_provider', 'No provider.' );
		$this->factory->method( 'get_provider' )->willReturn( $error );

		// Failure count tracking.
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] )
			->andReturn( [] );
		\WP_Mock::userFunction( 'update_option' )->andReturn( true );
		\WP_Mock::userFunction( 'set_transient' )->andReturn( true );

		$this->processor->handle( $this->base_entry, [] );

		// No ctbp_post_generated should have fired.
		$this->assertConditionsMet();
	}

	public function test_handle_sets_admin_notice_after_three_failures(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$error = new \WP_Error( 'ctbp_openai_quota', 'Quota exceeded.' );
		$this->factory->method( 'get_provider' )->willReturn( $error );

		// Simulate existing count of 2 — next call makes it 3.
		$key = md5( 'owner/repo' . 'v1.0.0' );
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] )
			->andReturn( [ $key => 2 ] );

		\WP_Mock::userFunction( 'update_option' )->andReturn( true );

		// Admin notice transient must be set when count reaches threshold.
		\WP_Mock::userFunction( 'set_transient' )
			->once()
			->with( 'ctbp_ai_failure_notice', \WP_Mock\Functions::type( 'array' ), DAY_IN_SECONDS );

		// send_failure_email() calls get_option('admin_email', '') and get_option for additional emails.
		\WP_Mock::userFunction( 'get_option' )
			->with( 'admin_email', '' )
			->andReturn( '' );
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_ADDITIONAL_EMAILS, '' )
			->andReturn( '' );

		$this->processor->handle( $this->base_entry, [] );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Successful generation — cache stored, failure count cleared (AC-010, AC-017)
	// -------------------------------------------------------------------------

	public function test_handle_caches_result_and_clears_failure_count_on_success(): void {
		\WP_Mock::userFunction( 'get_transient' )->andReturn( false );

		$generated = new GeneratedPost( 'New Title', '<p>body</p>', 'wp_ai_client' );

		$this->provider->method( 'generate_post' )->willReturn( $generated );
		$this->provider->method( 'get_slug' )->willReturn( 'wp_ai_client' );
		$this->factory->method( 'get_provider' )->willReturn( $this->provider );

		// The prompt filter must return a non-empty string for generation to proceed.
		\WP_Mock::onFilter( 'ctbp_generate_prompt' )
			->withAnyArgs()
			->reply( 'Test prompt content' );

		// Cache should be set with 4h TTL.
		\WP_Mock::userFunction( 'set_transient' )
			->once()
			->with(
				\WP_Mock\Functions::type( 'string' ),
				$generated,
				AI_Processor::CACHE_TTL
			);

		// Failure count cleared.
		$key = md5( 'owner/repo' . 'v1.0.0' );
		\WP_Mock::userFunction( 'get_option' )
			->with( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, [] )
			->andReturn( [ $key => 1 ] );

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( Plugin_Constants::OPTION_AI_FAILURE_COUNTS, \Mockery::any(), false );

		\WP_Mock::expectAction( 'ctbp_post_generated', $generated, \WP_Mock\Functions::type( ReleaseData::class ), [] );

		$this->processor->handle( $this->base_entry, [] );

		$this->assertConditionsMet();
	}
}
