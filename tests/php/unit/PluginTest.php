<?php
/**
 * Tests for the Plugin singleton class.
 *
 * @package ChangelogToBlogPost\Tests
 */

namespace TenUp\ChangelogToBlogPost\Tests;

use TenUp\ChangelogToBlogPost\Plugin;
use WP_Mock\Tools\TestCase;

/**
 * Tests Plugin singleton behaviour and bootstrap hooks.
 */
class PluginTest extends TestCase {

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
	 * get_instance() returns the same object on repeated calls.
	 */
	public function test_get_instance_returns_singleton(): void {
		\WP_Mock::expectActionAdded( 'init', [ \WP_Mock\Functions::type( Plugin::class ), 'i18n' ] );
		\WP_Mock::expectActionAdded( 'init', [ \WP_Mock\Functions::type( Plugin::class ), 'init' ] );

		$instance_a = Plugin::get_instance();
		$instance_b = Plugin::get_instance();

		$this->assertSame( $instance_a, $instance_b );
		$this->assertConditionsMet();
	}

	/**
	 * setup() hooks i18n and init to the 'init' action.
	 */
	public function test_setup_registers_hooks(): void {
		\WP_Mock::expectActionAdded( 'init', [ \WP_Mock\Functions::type( Plugin::class ), 'i18n' ] );
		\WP_Mock::expectActionAdded( 'init', [ \WP_Mock\Functions::type( Plugin::class ), 'init' ] );

		Plugin::get_instance();

		$this->assertConditionsMet();
	}

	/**
	 * i18n() calls load_plugin_textdomain with the correct text domain.
	 */
	public function test_i18n_loads_text_domain(): void {
		\WP_Mock::expectActionAdded( 'init', \WP_Mock\Functions::anyOf() );

		if ( ! defined( 'CHANGELOG_TO_BLOG_POST_PATH' ) ) {
			define( 'CHANGELOG_TO_BLOG_POST_PATH', dirname( __DIR__, 3 ) . '/' );
		}

		\WP_Mock::userFunction( 'load_plugin_textdomain' )
			->once()
			->with(
				'changelog-to-blog-post',
				false,
				CHANGELOG_TO_BLOG_POST_PATH . 'languages'
			);

		Plugin::get_instance()->i18n();

		$this->assertConditionsMet();
	}
}
