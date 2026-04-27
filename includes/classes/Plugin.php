<?php
/**
 * Core plugin class.
 *
 * @package GitHubReleasePosts
 */

namespace Jakemgold\GitHubReleasePosts;

use Jakemgold\GitHubReleasePosts\AI\AI_Processor;
use Jakemgold\GitHubReleasePosts\AI\AI_Provider_Factory;
use Jakemgold\GitHubReleasePosts\AI\Prompt_Builder;
use Jakemgold\GitHubReleasePosts\AI\Release_Enricher;
use Jakemgold\GitHubReleasePosts\AI\Release_Significance;
use Jakemgold\GitHubReleasePosts\Notification\Email_Notifier;
use Jakemgold\GitHubReleasePosts\Post\Post_Creator;
use Jakemgold\GitHubReleasePosts\Post\Publish_Workflow;
use Jakemgold\GitHubReleasePosts\Post\Taxonomy_Assigner;
use Jakemgold\GitHubReleasePosts\GitHub\API_Client;
use Jakemgold\GitHubReleasePosts\GitHub\Release_Monitor;
use Jakemgold\GitHubReleasePosts\GitHub\Release_Queue;
use Jakemgold\GitHubReleasePosts\GitHub\Release_State;
use Jakemgold\GitHubReleasePosts\GitHub\Version_Comparator;
use Jakemgold\GitHubReleasePosts\Settings\Global_Settings;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;

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
		add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
		add_action( 'init', [ $this, 'i18n' ] );
		add_action( 'init', [ $this, 'init' ] );
	}

	/**
	 * Registers the 'weekly' WP-Cron schedule.
	 *
	 * WordPress ships with 'hourly', 'twicedaily', and 'daily' but not 'weekly'.
	 * This adds it so that developers who filter `ghrp_check_frequency` to 'weekly'
	 * get a working schedule. Skips registration if another plugin already defined it.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_cron_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = [
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'github-release-posts' ),
			];
		}

		return $schedules;
	}

	/**
	 * Loads the plugin text domain for internationalisation.
	 *
	 * @return void
	 */
	public function i18n(): void {
		load_plugin_textdomain(
			'github-release-posts',
			false,
			GITHUB_RELEASE_POSTS_PATH . 'languages'
		);
	}

	/**
	 * Instantiates all feature classes.
	 *
	 * This is the single place where feature objects are created. To add a
	 * new feature, instantiate its class here and call ->setup() on it:
	 *
	 *   ( new \Jakemgold\GitHubReleasePosts\Feature\MyFeature() )->setup();
	 *
	 * Feature classes must not instantiate other feature classes.
	 *
	 * @return void
	 */
	public function init(): void {
		// Admin page — registers menu, REST routes, post meta, and editor assets.
		// Must run outside is_admin() because REST API requests need the routes.
		( new \Jakemgold\GitHubReleasePosts\Admin\Admin_Page() )->setup();

		// Shared instances — reused across the pipeline.
		$global_settings = new Global_Settings();
		$repo_settings   = new Repository_Settings();
		$significance    = new Release_Significance();
		$api_client      = new API_Client( $global_settings );

		// Wire the release monitor to both cron hooks.
		$monitor = new Release_Monitor(
			$api_client,
			new Release_State(),
			new Version_Comparator(),
			new Release_Queue(),
			$repo_settings
		);

		add_action( Plugin_Constants::CRON_HOOK_RELEASE_CHECK, [ $monitor, 'run' ] );
		add_action( Plugin_Constants::CRON_HOOK_RATE_LIMIT_RETRY, [ $monitor, 'run' ] );

		// AI generation — processes releases queued by the monitor.
		( new Release_Enricher( $api_client ) )->setup();
		( new Prompt_Builder( $repo_settings, $significance, $global_settings ) )->setup();
		( new AI_Processor( new AI_Provider_Factory( $global_settings ) ) )->setup();

		// Post creation — creates WordPress posts from AI-generated content.
		( new Post_Creator( $repo_settings, $global_settings ) )->setup();
		( new Taxonomy_Assigner( $repo_settings ) )->setup();
		( new Publish_Workflow( $repo_settings ) )->setup();

		// Email notifications — batched summary after cron runs.
		( new Email_Notifier( $global_settings, $significance, $repo_settings ) )->setup();
	}
}
