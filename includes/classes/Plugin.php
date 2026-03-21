<?php
/**
 * Core plugin class.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

/**
 * Plugin singleton — the single entry point for all feature classes.
 *
 * All feature classes are instantiated exclusively from init(). No feature
 * class should instantiate another feature class directly.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin
	 */
	private static Plugin $instance;

	/**
	 * Gets the singleton instance, creating and setting it up on first call.
	 *
	 * @return Plugin
	 */
	public static function get_instance(): Plugin {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->setup();
		}

		return self::$instance;
	}

	/**
	 * Registers core WordPress hooks.
	 *
	 * Called once by get_instance(). Hooks i18n to 'init' and defers
	 * feature class instantiation to 'init' via init().
	 *
	 * @return void
	 */
	protected function setup(): void {
		add_action( 'init', [ $this, 'i18n' ] );
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Loads the plugin text domain for internationalisation.
	 *
	 * @return void
	 */
	public function i18n(): void {
		load_plugin_textdomain(
			'changelog-to-blog-post',
			false,
			CHANGELOG_TO_BLOG_POST_PATH . 'languages'
		);
	}

	/**
	 * Instantiates all feature classes.
	 *
	 * This is the single place where feature objects are created. To add a
	 * new feature, instantiate its class here and call ->setup() on it:
	 *
	 *   ( new \TenUp\ChangelogToBlogPost\Feature\MyFeature() )->setup();
	 *
	 * Feature classes must not instantiate other feature classes.
	 *
	 * @return void
	 */
	public function init(): void {
		// Feature classes will be wired here as domains are executed.
	}
}
