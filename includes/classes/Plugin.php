<?php
/**
 * Core plugin class.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost;

/**
 * Main plugin class.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin
	 */
	private static Plugin $instance;

	/**
	 * Gets the singleton instance.
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
	 * Sets up plugin hooks.
	 */
	protected function setup(): void {
		add_action( 'init', [ $this, 'i18n' ] );
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Loads the plugin text domain.
	 */
	public function i18n(): void {
		load_plugin_textdomain(
			'changelog-to-blog-post',
			false,
			CHANGELOG_TO_BLOG_POST_PATH . 'languages'
		);
	}

	/**
	 * Initializes the plugin.
	 */
	public function init(): void {
		// Initialize plugin components here.
	}
}
