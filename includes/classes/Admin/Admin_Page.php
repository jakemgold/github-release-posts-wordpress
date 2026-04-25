<?php
/**
 * Admin settings page.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Admin;

use TenUp\ChangelogToBlogPost\GitHub\API_Client;
use TenUp\ChangelogToBlogPost\Post\Post_Creator;
use TenUp\ChangelogToBlogPost\GitHub\Onboarding_Handler;
use TenUp\ChangelogToBlogPost\GitHub\Release_Monitor;
use TenUp\ChangelogToBlogPost\GitHub\Release_Queue;
use TenUp\ChangelogToBlogPost\GitHub\Release_State;
use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Global_Settings;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

/**
 * Registers the plugin settings page under the WordPress Tools menu,
 * enqueues page-scoped assets, handles tab routing, form submissions,
 * and AJAX endpoints.
 */
class Admin_Page {

	/**
	 * The hook suffix returned by add_management_page(), used to scope asset enqueuing.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Repository settings service.
	 *
	 * @var Repository_Settings
	 */
	private Repository_Settings $repo_settings;

	/**
	 * Global settings service.
	 *
	 * @var Global_Settings
	 */
	private Global_Settings $global_settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->repo_settings   = new Repository_Settings();
		$this->global_settings = new Global_Settings();
	}

	/**
	 * Checks whether the block editor is active for the 'post' post type.
	 *
	 * Returns false when Classic Editor or similar is active, meaning
	 * the plugin cannot generate block-based content.
	 *
	 * @return bool
	 */
	public static function is_block_editor_active(): bool {
		if ( ! function_exists( 'use_block_editor_for_post_type' ) ) {
			return true; // Pre-check: assume active if function doesn't exist yet.
		}
		return (bool) use_block_editor_for_post_type( 'post' );
	}

	/**
	 * Registers all WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_action( 'admin_init', [ $this, 'handle_form_submission' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( CHANGELOG_TO_BLOG_POST_PATH . 'changelog-to-blog-post.php' ), [ $this, 'add_action_links' ] );

		// Register meta immediately — setup() is called during 'init',
		// so hooking 'init' again would be too late.
		$this->register_post_meta();

		// Settings API registration (handles the Settings tab form).
		( new Settings_Page( $this->global_settings ) )->setup();
	}

	/**
	 * Adds a "Configure" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array Modified action links.
	 */
	public function add_action_links( array $links ): array {
		$configure_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'tools.php?page=changelog-to-blog-post' ) ),
			esc_html__( 'Configure', 'changelog-to-blog-post' )
		);
		array_unshift( $links, $configure_link );
		return $links;
	}

	/**
	 * Registers the settings page as a Tools submenu item.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		$this->page_hook = (string) add_management_page(
			__( 'GitHub Release Posts', 'changelog-to-blog-post' ),
			__( 'Release Posts', 'changelog-to-blog-post' ),
			'manage_options',
			'changelog-to-blog-post',
			[ $this, 'render_page' ]
		);

		// Add contextual help tabs once the page is loaded.
		add_action( 'load-' . $this->page_hook, [ $this, 'add_help_tabs' ] );
	}

	/**
	 * Adds contextual help tabs to the plugin's admin page.
	 *
	 * @return void
	 */
	public function add_help_tabs(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-overview',
				'title'   => __( 'Overview', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'GitHub Release Posts', 'changelog-to-blog-post' ) . '</h3>'
					. '<p>' . esc_html__( 'This plugin monitors GitHub repositories for new releases and uses AI to automatically generate blog posts from release notes. Posts are created as drafts (or published immediately) so your readers always know what changed in the projects you maintain.', 'changelog-to-blog-post' ) . '</p>'
					. '<p>' . esc_html__( 'The plugin checks for new releases on a daily schedule via WP-Cron. You can also generate a post manually from the Repositories tab at any time.', 'changelog-to-blog-post' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-getting-started',
				'title'   => __( 'Getting Started', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'Getting Started', 'changelog-to-blog-post' ) . '</h3>'
					. '<ol>'
					. '<li>' . esc_html__( 'Set up an AI connector under Settings → Connectors (Anthropic, OpenAI, or Google recommended).', 'changelog-to-blog-post' ) . '</li>'
					. '<li>' . esc_html__( 'Add a GitHub repository in the Repositories tab using the format "owner/repo" (e.g. "WordPress/gutenberg").', 'changelog-to-blog-post' ) . '</li>'
					. '<li>' . esc_html__( 'When you add a repository, the plugin automatically checks for the latest release and generates a draft post if one is found. You can also generate a post manually at any time.', 'changelog-to-blog-post' ) . '</li>'
					. '</ol>'
					. '<p>' . esc_html__( 'Optionally, add a GitHub Personal Access Token in the Settings tab to increase the API rate limit from 60 to 5,000 requests per hour.', 'changelog-to-blog-post' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-repositories',
				'title'   => __( 'Repositories', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'Managing Repositories', 'changelog-to-blog-post' ) . '</h3>'
					. '<p>' . esc_html__( 'Each repository you add is monitored for new GitHub releases. When a new release is detected, the plugin fetches the release notes, sends them to your configured AI provider, and creates a blog post.', 'changelog-to-blog-post' ) . '</p>'
					. '<h4>' . esc_html__( 'Per-Repository Options', 'changelog-to-blog-post' ) . '</h4>'
					. '<ul>'
					. '<li><strong>' . esc_html__( 'Display Name', 'changelog-to-blog-post' ) . '</strong> — ' . esc_html__( 'The project name used in post titles. Defaults to a cleaned-up version of the repo name.', 'changelog-to-blog-post' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Project Link', 'changelog-to-blog-post' ) . '</strong> — ' . esc_html__( 'A URL included in the generated post as a download or project link. If the repository is a WordPress plugin, you can enter just the WordPress.org slug instead. If left blank, the GitHub release URL is used.', 'changelog-to-blog-post' ) . '</li>'
					. '</ul>'
					. '<h4>' . esc_html__( 'Generate Draft Now', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'Creates a post from the latest release immediately, bypassing the cron schedule. Useful for testing your setup or generating a post on demand.', 'changelog-to-blog-post' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-ai-settings',
				'title'   => __( 'AI & Prompts', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'Post Creation Settings', 'changelog-to-blog-post' ) . '</h3>'
				. '<p>' . esc_html__( 'This plugin uses WordPress Connectors to communicate with AI providers. Configure your preferred connector (Anthropic, OpenAI, or Google) under Settings → Connectors.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'Recommended Models', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'The plugin specifies a list of preferred models and automatically uses the best available one via your configured connector. For best results, your AI provider account should support one of these models:', 'changelog-to-blog-post' ) . '</p>'
				. '<ul>'
				. '<li>' . esc_html__( 'Anthropic — Claude Opus 4.7', 'changelog-to-blog-post' ) . '</li>'
				. '<li>' . esc_html__( 'OpenAI — GPT-5.5', 'changelog-to-blog-post' ) . '</li>'
				. '<li>' . esc_html__( 'Google — Gemini 2.5 Pro', 'changelog-to-blog-post' ) . '</li>'
				. '</ul>'
				. '<p>' . esc_html__( 'If none of these models are available, the plugin falls back to whatever model your connector provides. Developers can customize the preferred model list via the ctbp_wp_ai_client_model_preferences filter.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'Research Depth', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'Controls how much context the AI gathers before writing. "Standard" uses the release notes, linked issues/PRs, and README. "Deep" also fetches commit messages and file change summaries between the previous and current release, giving the AI more detail to work with — especially useful for releases with sparse notes.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'Post Audience', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'Controls the technical depth of generated posts. "Site owners & managers" avoids all jargon; "Engineering teams" includes hook signatures, code examples, and architecture details.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'Custom Prompt Instructions', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'Add extra instructions to guide the AI\'s writing style, tone, or voice. For example: "Write in a friendly, conversational tone" or "Our readers are non-technical site owners." Keep it under 500 characters for best results.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'AI Disclosure', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'When enabled, the following note is appended to the end of each generated post in small italic text:', 'changelog-to-blog-post' ) . '</p>'
				. '<blockquote><em>' . esc_html__( 'This post was generated from release notes with the help of AI using GitHub Release Posts plugin for WordPress.', 'changelog-to-blog-post' ) . '</em></blockquote>'
				. '<p>' . esc_html__( 'This text is part of the post content and can be edited or removed. Developers can customize it with the ctbp_ai_disclosure_text filter.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'SEO: Excerpts & Slugs', 'changelog-to-blog-post' ) . '</h4>'
				. '<p>' . esc_html__( 'Each generated post includes an AI-written excerpt (150–160 characters, optimized as a meta description) and an SEO-friendly URL slug based on the project name, version, and key topics. Published posts keep their existing slug when regenerated to preserve live URLs.', 'changelog-to-blog-post' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-github',
				'title'   => __( 'GitHub Token', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'GitHub Personal Access Token', 'changelog-to-blog-post' ) . '</h3>'
				. '<p>' . esc_html__( 'By default, the plugin uses unauthenticated GitHub API requests, which are limited to 60 per hour. Adding a Personal Access Token raises this limit to 5,000 requests per hour.', 'changelog-to-blog-post' ) . '</p>'
				. '<p>' . esc_html__( 'A token is recommended if you track more than a few repositories or check for releases frequently.', 'changelog-to-blog-post' ) . '</p>'
				. '<h4>' . esc_html__( 'Creating a Token', 'changelog-to-blog-post' ) . '</h4>'
				. '<ol>'
				. '<li>'
					. sprintf(
						/* translators: %s: link to GitHub token settings */
						esc_html__( 'Visit %s on GitHub.', 'changelog-to-blog-post' ),
						'<a href="https://github.com/settings/tokens" target="_blank" rel="noopener">' . esc_html__( 'Settings &rarr; Personal access tokens', 'changelog-to-blog-post' ) . '</a>'
					)
				. '</li>'
				. '<li>' . esc_html__( 'Generate a new token (classic) with the "public_repo" scope. For private repositories, use the full "repo" scope instead.', 'changelog-to-blog-post' ) . '</li>'
				. '<li>' . esc_html__( 'Paste the token into the GitHub Personal Access Token field in the Settings tab.', 'changelog-to-blog-post' ) . '</li>'
				. '</ol>'
				. '<p>' . esc_html__( 'The token is encrypted at rest using libsodium and is never exposed in the admin UI after saving.', 'changelog-to-blog-post' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ctbp-help-troubleshooting',
				'title'   => __( 'Troubleshooting', 'changelog-to-blog-post' ),
				'content' => '<h3>' . esc_html__( 'Troubleshooting', 'changelog-to-blog-post' ) . '</h3>'
					. '<h4>' . esc_html__( 'Post generation fails or times out', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'AI generation can take 30–60 seconds for complex releases. If your hosting environment has a short PHP execution time limit, the request may time out before the AI responds. Contact your host about increasing the limit, or try generating again — some releases take longer than others.', 'changelog-to-blog-post' ) . '</p>'
					. '<h4>' . esc_html__( 'API credits or billing error', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'If you see a billing or credits error, verify that your AI provider account has API credits loaded. Some providers have separate billing for API usage and chat subscriptions.', 'changelog-to-blog-post' ) . '</p>'
					. '<h4>' . esc_html__( 'Images show "unexpected or invalid content"', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'If image blocks show a validation warning in the editor, click "Attempt recovery" — this usually resolves the issue. The plugin rebuilds image blocks from AI output, and minor formatting differences can occasionally trigger this warning.', 'changelog-to-blog-post' ) . '</p>'
					. '<h4>' . esc_html__( 'Posts are empty or very short', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'This usually means the GitHub release has no release notes (just a tag with no body text). The plugin generates content from the release notes — if there are none, the AI has little to work with. Check the release on GitHub to confirm it has a description.', 'changelog-to-blog-post' ) . '</p>'
					. '<h4>' . esc_html__( 'Scheduled checks are not running', 'changelog-to-blog-post' ) . '</h4>'
					. '<p>' . esc_html__( 'The plugin relies on WP-Cron, which requires regular site traffic to trigger. On low-traffic sites, consider setting up a real server cron job to call wp-cron.php. Check Tools → Site Health for WP-Cron status.', 'changelog-to-blog-post' ) . '</p>',
			]
		);
	}

	/**
	 * Enqueues admin CSS and JS only on the plugin's settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $this->page_hook !== $hook_suffix ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'changelog-to-blog-post-admin',
			CHANGELOG_TO_BLOG_POST_URL . 'dist/css/admin-style.css',
			[],
			CHANGELOG_TO_BLOG_POST_VERSION
		);

		$admin_asset_file = CHANGELOG_TO_BLOG_POST_PATH . 'dist/js/admin.asset.php';
		$admin_asset      = file_exists( $admin_asset_file ) ? require $admin_asset_file : [
			'dependencies' => [],
			'version'      => CHANGELOG_TO_BLOG_POST_VERSION,
		];

		wp_enqueue_script(
			'changelog-to-blog-post-admin-js',
			CHANGELOG_TO_BLOG_POST_URL . 'dist/js/admin.js',
			$admin_asset['dependencies'],
			$admin_asset['version'] ?? CHANGELOG_TO_BLOG_POST_VERSION,
			true
		);

		wp_localize_script(
			'changelog-to-blog-post-admin-js',
			'ctbpAdmin',
			[
				'restUrl'           => get_rest_url( null, 'ctbp/v1' ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'blockEditorActive' => self::is_block_editor_active(),
				'i18n'              => [
					'unsavedChanges'    => __( 'You have unsaved changes. Are you sure you want to leave this tab?', 'changelog-to-blog-post' ),
					'confirmRemove'     => __( 'Are you sure you want to remove this repository? This cannot be undone.', 'changelog-to-blog-post' ),
					'validating'        => __( 'Validating…', 'changelog-to-blog-post' ),
					'slugValid'         => __( 'Found on WordPress.org.', 'changelog-to-blog-post' ),
					'slugNotFound'      => __( 'Not found on WordPress.org.', 'changelog-to-blog-post' ),
					'validUrl'          => __( 'Valid URL.', 'changelog-to-blog-post' ),
					'invalidUrl'        => __( 'Invalid URL format.', 'changelog-to-blog-post' ),
					'pluginLinkHint'    => __( 'Enter a valid URL or WordPress.org slug.', 'changelog-to-blog-post' ),
					'selectImage'       => __( 'Select Featured Image', 'changelog-to-blog-post' ),
					'useImage'          => __( 'Use this image', 'changelog-to-blog-post' ),
					'removeImage'       => __( 'Remove', 'changelog-to-blog-post' ),
					'notImplemented'    => __( 'This feature is not yet available.', 'changelog-to-blog-post' ),
					'edit'              => __( 'Edit', 'changelog-to-blog-post' ),
					'editLabel'         => __( 'Edit:', 'changelog-to-blog-post' ),
					'done'              => __( 'Done', 'changelog-to-blog-post' ),
					'generateDraft'     => __( 'Generate draft post', 'changelog-to-blog-post' ),
					'generatePost'      => __( 'Generate post', 'changelog-to-blog-post' ),
					'generating'        => __( 'Generating…', 'changelog-to-blog-post' ),
					'draftCreated'      => __( 'Draft created.', 'changelog-to-blog-post' ),
					'viewDraft'         => __( 'View draft', 'changelog-to-blog-post' ),
					'regenerateConfirm' => __( 'A post already exists for this release. Regenerate it?', 'changelog-to-blog-post' ),
					'valid'             => __( 'Valid', 'changelog-to-blog-post' ),
					'connectionSuccess' => __( 'Connection successful.', 'changelog-to-blog-post' ),
				],
			]
		);
	}

	/**
	 * Enqueues the block editor script for release attribution.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		// Only load on post edit screens for posts generated by this plugin.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 === $post_id ) {
			return;
		}

		$source_repo = get_post_meta( $post_id, Plugin_Constants::META_SOURCE_REPO, true );
		if ( empty( $source_repo ) ) {
			return;
		}

		$asset_file = CHANGELOG_TO_BLOG_POST_PATH . 'dist/js/editor.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => CHANGELOG_TO_BLOG_POST_VERSION,
		];

		wp_enqueue_script(
			'changelog-to-blog-post-editor',
			CHANGELOG_TO_BLOG_POST_URL . 'dist/js/editor.js',
			$asset['dependencies'],
			$asset['version'] ?? CHANGELOG_TO_BLOG_POST_VERSION,
			true
		);
	}

	/**
	 * Registers post meta keys for REST API visibility (block editor).
	 *
	 * @return void
	 */
	public function register_post_meta(): void {
		$meta_keys = [
			Plugin_Constants::META_SOURCE_REPO,
			Plugin_Constants::META_RELEASE_TAG,
			Plugin_Constants::META_RELEASE_URL,
			Plugin_Constants::META_GENERATED_BY,
		];

		foreach ( $meta_keys as $key ) {
			register_post_meta(
				'post',
				$key,
				[
					'show_in_rest'  => true,
					'single'        => true,
					'type'          => 'string',
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				]
			);
		}
	}

	/**
	 * Registers REST API routes for plugin operations.
	 *
	 * Hooked to rest_api_init. All routes require the manage_options capability.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'ctbp/v1',
			'/releases/generate-draft',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_generate_draft' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'repo' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'ctbp/v1',
			'/releases/regenerate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_regenerate_post' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'post_id'  => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'feedback' => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					],
				],
			]
		);

		register_rest_route(
			'ctbp/v1',
			'/notifications/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_send_test_notification' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			]
		);

		register_rest_route(
			'ctbp/v1',
			'/wporg/validate',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_validate_plugin_link' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'value' => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Permission callback shared by all plugin REST routes.
	 *
	 * @return bool|\WP_Error
	 */
	public function rest_permission_check(): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ctbp_forbidden',
				__( 'You do not have permission to perform this action.', 'changelog-to-blog-post' ),
				[ 'status' => 403 ]
			);
		}
		return true;
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'changelog-to-blog-post' ) );
		}

		include CHANGELOG_TO_BLOG_POST_PATH . 'includes/templates/admin-page.php';
	}

	/**
	 * Dispatches form submissions to the appropriate handler.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- nonce is verified per-action in handle_repositories_save(); this is just the early-return guard.
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ctbp_action'] ) || ( $_GET['page'] ?? '' ) !== 'changelog-to-blog-post' ) {
			return;
		}

		$action = sanitize_key( $_POST['ctbp_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'repositories' === $action ) {
			$this->handle_repositories_save();
		}
	}

	/**
	 * Handles saving the repositories form.
	 *
	 * @return void
	 */
	private function handle_repositories_save(): void {
		check_admin_referer( 'ctbp_save_repositories', 'ctbp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'changelog-to-blog-post' ) );
		}

		// Handle "Remove" action.
		if ( ! empty( $_POST['ctbp_remove_repo'] ) ) {
			$identifier = sanitize_text_field( wp_unslash( $_POST['ctbp_remove_repo'] ) );
			$this->repo_settings->remove_repository( $identifier );
			// Clear per-repo state so a re-add starts clean (AC-003, AC-004).
			( new Release_State() )->clear_state( $identifier );
			wp_safe_redirect(
				add_query_arg(
					[
						'tab'   => 'repositories',
						'saved' => '1',
					],
					$this->get_page_url()
				)
			);
			exit;
		}

		// Handle "Add repository" action.
		if ( isset( $_POST['ctbp_add_repo'] ) && ! empty( $_POST['ctbp_new_repo'] ) ) {
			$result = $this->repo_settings->add_repository(
				sanitize_text_field( wp_unslash( $_POST['ctbp_new_repo'] ) )
			);

			if ( ! $result['success'] ) {
				$this->set_admin_error( $result['error'] ?? __( 'Could not add repository.', 'changelog-to-blog-post' ) );
				wp_safe_redirect(
					add_query_arg( 'tab', 'repositories', $this->get_page_url() )
				);
				exit;
			}

			// Trigger onboarding preview draft (US-003).
			$added_identifier = '';
			foreach ( array_reverse( $result['repos'] ) as $repo ) {
				if ( isset( $repo['identifier'] ) ) {
					$added_identifier = $repo['identifier'];
					break;
				}
			}

			if ( '' !== $added_identifier ) {
				try {
					$onboarding = ( new Onboarding_Handler(
						new API_Client( $this->global_settings ),
						new Release_State()
					) )->trigger( $added_identifier );

					$this->set_admin_notice( $onboarding['type'], $onboarding['message'], $onboarding['post_url'] );
				} catch ( \Throwable $e ) {
					$this->set_admin_notice( 'warning', __( 'Repository added, but initial release check failed. It will be checked on the next scheduled run.', 'changelog-to-blog-post' ), '' );
				}
			}

			wp_safe_redirect(
				add_query_arg(
					[
						'tab'   => 'repositories',
						'saved' => '1',
					],
					$this->get_page_url()
				)
			);
			exit;
		}

		// Handle bulk "Save" of per-repo configurations.
		$posted_repos = isset( $_POST['repos'] ) && is_array( $_POST['repos'] ) ? $_POST['repos'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$update_failures = 0;
		foreach ( $posted_repos as $identifier => $config ) {
			$identifier      = sanitize_text_field( wp_unslash( (string) $identifier ) );
			$raw_repo_tags   = sanitize_text_field( wp_unslash( $config['tags'] ?? '' ) );
			$raw_plugin_link = sanitize_text_field( wp_unslash( $config['plugin_link'] ?? '' ) );
			// If it looks like a URL, apply URL sanitization.
			if ( Repository_Settings::is_url( $raw_plugin_link ) ) {
				$raw_plugin_link = esc_url_raw( $raw_plugin_link );
			}

			$sanitized = [
				'display_name'   => sanitize_text_field( wp_unslash( $config['display_name'] ?? '' ) ),
				'plugin_link'    => $raw_plugin_link,
				'author'         => absint( $config['author'] ?? 0 ),
				'post_status'    => sanitize_key( $config['post_status'] ?? '' ),
				'categories'     => array_map( 'absint', array_filter( (array) ( $config['categories'] ?? [] ) ) ),
				'tags'           => $this->resolve_tag_names_to_ids( $raw_repo_tags ),
				'paused'         => ! empty( $config['paused'] ),
				'featured_image' => absint( $config['featured_image'] ?? 0 ),
			];
			if ( ! $this->repo_settings->update_repository( $identifier, $sanitized ) ) {
				++$update_failures;
			}
		}

		$redirect_args = [
			'tab'   => 'repositories',
			'saved' => '1',
		];
		if ( $update_failures > 0 ) {
			$redirect_args['saved'] = '0';
			set_transient(
				'ctbp_admin_errors_' . get_current_user_id(),
				sprintf(
					/* translators: %d: number of repositories that failed to update */
					__( '%d repository update(s) failed. Please try again.', 'changelog-to-blog-post' ),
					$update_failures
				),
				30
			);
		}

		wp_safe_redirect(
			add_query_arg(
				$redirect_args,
				$this->get_page_url()
			)
		);
		exit;
	}

	/**
	 * Builds a standard post data array for REST responses.
	 *
	 * @param \WP_Post $post WordPress post object.
	 * @return array Post data with id, title, status, edit_url, tag, and date.
	 */
	private function build_post_response( \WP_Post $post ): array {
		return [
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'status'   => $post->post_status,
			'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			'tag'      => get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_TAG, true ),
			'date'     => get_the_date( 'Y/m/d', $post->ID ),
		];
	}

	/**
	 * REST handler: generates a draft post for the latest release of a repository.
	 *
	 * Returns conflict data when a post already exists for the tag (BR-003),
	 * otherwise fires ctbp_process_release and returns the created post.
	 *
	 * @param \WP_REST_Request $request REST request containing the repo identifier.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::is_block_editor_active() ) {
			return new \WP_Error( 'ctbp_no_block_editor', __( 'Post generation requires the block editor.', 'changelog-to-blog-post' ), [ 'status' => 400 ] );
		}

		$identifier = $request->get_param( 'repo' );
		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( null === $release ) {
			return new \WP_Error( 'ctbp_no_release', __( 'No releases found for this repository.', 'changelog-to-blog-post' ), [ 'status' => 404 ] );
		}

		// Check for existing post — offer conflict resolution (BR-003).
		$existing = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $existing instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'conflict' => true,
					'post'     => $this->build_post_response( $existing ),
				],
				200
			);
		}

		/**
		 * Fires to trigger AI generation for a manual draft request.
		 *
		 * DOM-05/06 hooks here. force_draft ensures the post is always a draft
		 * regardless of the global post-status setting (AC-011).
		 *
		 * @param array<string, mixed> $entry   Queue entry with release data.
		 * @param array<string, mixed> $context Context flags: force_draft, manual.
		 */
		do_action(
			'ctbp_process_release',
			Release_Queue::from_release( $identifier, $release ),
			[
				'force_draft' => true,
				'manual'      => true,
			]
		);

		$post = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $post instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'conflict' => false,
					'post'     => $this->build_post_response( $post ),
				],
				201
			);
		}

		$last_error = \TenUp\ChangelogToBlogPost\AI\AI_Processor::get_last_error();

		if ( $last_error instanceof \WP_Error ) {
			return new \WP_Error(
				$last_error->get_error_code(),
				$last_error->get_error_message(),
				[ 'status' => 422 ]
			);
		}

		return new \WP_Error(
			'ctbp_generation_failed',
			__( 'Draft could not be generated. Check the debug log for details or verify your connector configuration under Settings → Connectors.', 'changelog-to-blog-post' ),
			[ 'status' => 422 ]
		);
	}

	/**
	 * REST handler: validates a plugin link (URL or WP.org slug).
	 *
	 * @param \WP_REST_Request $request REST request containing the plugin link value.
	 * @return \WP_REST_Response
	 */
	public function rest_validate_plugin_link( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->repo_settings->validate_plugin_link( $request->get_param( 'value' ) );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: sends a test notification email.
	 *
	 * Uses the most recent generated post to build a realistic sample email,
	 * or a placeholder example if no posts have been generated yet.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_send_test_notification(): \WP_REST_Response|\WP_Error {
		$notif = $this->global_settings->get_notification_settings();
		if ( empty( $notif['notify_site_owner'] ) && empty( $this->global_settings->get_additional_email_list() ) ) {
			return new \WP_Error(
				'ctbp_no_recipients',
				__( 'No notification recipients configured. Enable the site owner checkbox or add email addresses first.', 'changelog-to-blog-post' ),
				[ 'status' => 400 ]
			);
		}

		$entry = $this->build_test_notification_entry();

		$significance = new \TenUp\ChangelogToBlogPost\AI\Release_Significance();
		$notifier     = new \TenUp\ChangelogToBlogPost\Notification\Email_Notifier(
			$this->global_settings,
			$significance,
			$this->repo_settings
		);

		// Use reflection to call the private build methods and send directly.
		$entries = [ $entry ];

		// Build subject matching the real notification format, prefixed with [Test].
		$display = $entry['display_name'] . ' ' . $entry['tag'];
		if ( 'publish' === $entry['status'] ) {
			/* translators: %s: project name and version */
			$subject = sprintf( __( '[Test] %s — release post published', 'changelog-to-blog-post' ), $display );
		} else {
			/* translators: %s: project name and version */
			$subject = sprintf( __( '[Test] %s — draft ready for review', 'changelog-to-blog-post' ), $display );
		}

		$html_body = $this->build_test_email_body( $entries );
		$headers   = [ 'Content-Type: text/html; charset=UTF-8' ];

		$recipients = [];
		if ( ! empty( $notif['notify_site_owner'] ) ) {
			$admin_email = get_option( 'admin_email', '' );
			if ( ! empty( $admin_email ) ) {
				$recipients[] = $admin_email;
			}
		}
		foreach ( $this->global_settings->get_additional_email_list() as $email ) {
			if ( ! in_array( $email, $recipients, true ) ) {
				$recipients[] = $email;
			}
		}

		$sent = false;
		foreach ( $recipients as $recipient ) {
			if ( wp_mail( $recipient, $subject, $html_body, $headers ) ) {
				$sent = true;
			}
		}

		if ( ! $sent ) {
			return new \WP_Error(
				'ctbp_mail_failed',
				__( 'Failed to send test email. Check your site\'s email configuration.', 'changelog-to-blog-post' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'message' => sprintf(
					/* translators: %s: comma-separated list of recipient emails */
					__( 'Test email sent to %s.', 'changelog-to-blog-post' ),
					implode( ', ', $recipients )
				),
			],
			200
		);
	}

	/**
	 * Builds a test notification entry from the most recent generated post,
	 * or a placeholder if no posts exist yet.
	 *
	 * @return array{post_id: int, status: string, identifier: string, display_name: string, tag: string, html_url: string, significance: string}
	 */
	private function build_test_notification_entry(): array {
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'       => Plugin_Constants::META_SOURCE_REPO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			]
		);

		if ( ! empty( $posts ) ) {
			$post       = $posts[0];
			$identifier = get_post_meta( $post->ID, Plugin_Constants::META_SOURCE_REPO, true );
			$tag        = get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_TAG, true );
			$html_url   = get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_URL, true );
			$config     = $this->repo_settings->get_repository( $identifier );

			return [
				'post_id'      => $post->ID,
				'status'       => $post->post_status,
				'identifier'   => $identifier,
				'display_name' => ! empty( $config['display_name'] ) ? $config['display_name'] : $identifier,
				'tag'          => $tag,
				'html_url'     => $html_url,
				'post_title'   => get_the_title( $post->ID ),
			];
		}

		// Placeholder when no posts exist.
		return [
			'post_id'      => 0,
			'status'       => 'draft',
			'identifier'   => 'example/plugin',
			'display_name' => 'Example Plugin',
			'tag'          => 'v1.0.0',
			'html_url'     => 'https://github.com/example/plugin/releases/tag/v1.0.0',
			'post_title'   => 'Example Plugin 1.0: A Major New Release',
		];
	}

	/**
	 * Builds the HTML email body for a test notification.
	 *
	 * Mirrors the format used by Email_Notifier::build_html_body().
	 *
	 * @param array $entries Post entries.
	 * @return string
	 */
	private function build_test_email_body( array $entries ): string {
		$site_url    = esc_url( home_url() );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$plugin_url  = 'https://github.com/jakemgold/github-release-posts-wordpress';
		$plugin_name = 'GitHub Release Posts';

		$html  = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; max-width: 600px;">';
		$html .= '<p><em>' . esc_html__( 'This is a test email.', 'changelog-to-blog-post' ) . '</em></p>';
		$html .= '<p>' . sprintf(
			/* translators: 1: linked site URL, 2: linked plugin name */
			esc_html__( 'New posts have been generated from GitHub releases on %1$s, via the %2$s plugin.', 'changelog-to-blog-post' ),
			'<a href="' . $site_url . '">' . esc_html( $site_host ) . '</a>',
			'<a href="' . esc_url( $plugin_url ) . '">' . esc_html( $plugin_name ) . '</a>'
		) . '</p>';

		$html .= '<table style="width: 100%; border-collapse: collapse;">';

		foreach ( $entries as $entry ) {
			$title = ! empty( $entry['post_title'] )
				? $entry['post_title']
				: $entry['display_name'] . ' ' . $entry['tag'];

			$html .= '<tr style="border-bottom: 1px solid #eee;">';
			$html .= '<td style="padding: 12px 0;">';
			$html .= '<strong>' . esc_html( $title ) . '</strong>';
			$html .= ' <span style="color: #666;">(' . esc_html( $entry['display_name'] ) . ' ' . esc_html( $entry['tag'] ) . ')</span>';
			$html .= '<br>';

			if ( $entry['post_id'] > 0 ) {
				if ( 'publish' === $entry['status'] ) {
					$view_url = esc_url( get_permalink( $entry['post_id'] ) );
					$html    .= '<a href="' . $view_url . '">' . esc_html__( 'View post', 'changelog-to-blog-post' ) . '</a>';
					$html    .= ' · <a href="' . esc_url( (string) get_edit_post_link( $entry['post_id'], 'raw' ) ) . '">' . esc_html__( 'Edit', 'changelog-to-blog-post' ) . '</a>';
				} else {
					$html .= '<a href="' . esc_url( (string) get_edit_post_link( $entry['post_id'], 'raw' ) ) . '"><strong>' . esc_html__( 'Review draft', 'changelog-to-blog-post' ) . '</strong></a>';
				}
				$html .= ' · ';
			}

			$html .= '<a href="' . esc_url( $entry['html_url'] ) . '">' . esc_html__( 'GitHub release', 'changelog-to-blog-post' ) . '</a>';
			$html .= '</td>';
			$html .= '</tr>';
		}

		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * REST handler: regenerates the content for an existing post.
	 *
	 * Re-fetches the release from GitHub, re-runs AI generation with optional
	 * user feedback appended to the prompt, and updates the post content.
	 *
	 * @param \WP_REST_Request $request REST request with post_id and optional feedback.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_regenerate_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id  = (int) $request->get_param( 'post_id' );
		$feedback = (string) $request->get_param( 'feedback' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'ctbp_post_not_found', __( 'Post not found.', 'changelog-to-blog-post' ), [ 'status' => 404 ] );
		}

		// Read source meta.
		$identifier  = (string) get_post_meta( $post_id, Plugin_Constants::META_SOURCE_REPO, true );
		$release_tag = (string) get_post_meta( $post_id, Plugin_Constants::META_RELEASE_TAG, true );

		if ( empty( $identifier ) || empty( $release_tag ) ) {
			return new \WP_Error( 'ctbp_not_generated', __( 'This post was not generated by the plugin.', 'changelog-to-blog-post' ), [ 'status' => 422 ] );
		}

		// Fetch the release from GitHub.
		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( null === $release ) {
			return new \WP_Error( 'ctbp_no_release', __( 'No release found on GitHub.', 'changelog-to-blog-post' ), [ 'status' => 404 ] );
		}

		// Build ReleaseData from the fetched release.
		$data = new \TenUp\ChangelogToBlogPost\AI\ReleaseData(
			identifier:   $identifier,
			tag:          $release->tag,
			name:         $release->name,
			body:         $release->body,
			html_url:     $release->html_url,
			published_at: $release->published_at,
			assets:       $release->assets,
		);

		// Generate a new prompt — the standard pipeline handles enrichment and significance.
		$prompt = (string) apply_filters( 'ctbp_generate_prompt', '', $data );

		// Append user feedback if provided.
		if ( '' !== trim( $feedback ) ) {
			$prompt .= "\n\nFEEDBACK FROM THE SITE OWNER (apply these changes):\n" . trim( $feedback );
		}

		// Call the AI provider.
		$factory  = new \TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory( $this->global_settings );
		$provider = $factory->get_provider();

		if ( is_wp_error( $provider ) ) {
			return new \WP_Error( $provider->get_error_code(), $provider->get_error_message(), [ 'status' => 422 ] );
		}

		$result = $provider->generate_post( $data, $prompt );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 422 ] );
		}

		// Assemble full title.
		$repo_config  = $this->repo_settings->get_repository( $identifier );
		$display_name = ! empty( $repo_config['display_name'] )
			? (string) $repo_config['display_name']
			: $this->repo_settings->derive_display_name( explode( '/', $identifier )[1] ?? $identifier );

		$full_title = "{$display_name} {$data->tag} — {$result->title}";

		// Convert HTML to blocks and update the existing post (creates a revision).
		$block_content  = Post_Creator::convert_html_to_blocks( $result->content );
		$block_content .= Post_Creator::build_disclosure_block( $data );
		$update_args    = [
			'ID'           => $post_id,
			'post_title'   => $full_title,
			'post_content' => $block_content,
		];

		// Always update the excerpt.
		if ( '' !== $result->excerpt ) {
			$update_args['post_excerpt'] = $result->excerpt;
		}

		// Only update the slug if the post is not yet published (preserve live URLs).
		if ( '' !== $result->slug_keywords && ! in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			$repo_config              = $this->repo_settings->get_repository( $identifier );
			$display_name             = ! empty( $repo_config['display_name'] )
				? (string) $repo_config['display_name']
				: $this->repo_settings->derive_display_name( explode( '/', $identifier )[1] ?? $identifier );
			$version                  = strtolower( ltrim( $data->tag, 'vV' ) );
			$version                  = str_replace( '.', '-', $version );
			$update_args['post_name'] = sanitize_title( $display_name . '-' . $version . '-' . $result->slug_keywords );
		}

		$update_result = wp_update_post( $update_args, true );

		if ( is_wp_error( $update_result ) ) {
			return new \WP_Error(
				'ctbp_update_failed',
				$update_result->get_error_message(),
				[ 'status' => 422 ]
			);
		}

		// Sideload any remote images into the media library.
		Post_Creator::sideload_images( $post_id );

		// Update the provider meta.
		update_post_meta( $post_id, Plugin_Constants::META_GENERATED_BY, $result->provider_slug );

		$updated_post = get_post( $post_id );
		return new \WP_REST_Response(
			[
				'success' => true,
				'post'    => $updated_post ? $this->build_post_response( $updated_post ) : [
					'id'       => $post_id,
					'title'    => $full_title,
					'edit_url' => get_edit_post_link( $post_id, 'raw' ),
				],
			],
			200
		);
	}

	/**
	 * Returns the base URL of the plugin settings page.
	 *
	 * @return string
	 */
	public function get_page_url(): string {
		return admin_url( 'tools.php?page=changelog-to-blog-post' );
	}

	/**
	 * Stores an error message in a short-lived transient so the template can display it.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function set_admin_error( string $message ): void {
		$user_id = get_current_user_id();
		set_transient( 'ctbp_admin_errors_' . $user_id, $message, 60 );
	}

	/**
	 * Stores a typed admin notice in a short-lived transient so the template can display it.
	 *
	 * @param string      $type    Notice type: 'success', 'warning', or 'error'.
	 * @param string      $message Notice message.
	 * @param string|null $url     Optional URL for a "View" link appended to the notice.
	 * @return void
	 */
	private function set_admin_notice( string $type, string $message, ?string $url = null ): void {
		$user_id = get_current_user_id();
		set_transient(
			'ctbp_admin_notice_' . $user_id,
			[
				'type'    => $type,
				'message' => $message,
				'url'     => $url,
			],
			60
		);
	}

	/**
	 * Converts a comma-separated string of tag names into an array of term IDs.
	 *
	 * Tags that don't exist are silently skipped — the site owner must create
	 * them first via the standard WordPress tag management UI.
	 *
	 * @param string $raw Comma-separated tag names.
	 * @return int[] Array of tag term IDs.
	 */
	private function resolve_tag_names_to_ids( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}

		$names = array_map( 'trim', explode( ',', $raw ) );
		$ids   = [];

		foreach ( $names as $name ) {
			if ( '' === $name ) {
				continue;
			}

			$term = get_term_by( 'name', $name, 'post_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}

		return $ids;
	}

	/**
	 * Converts an array of tag term IDs into a comma-separated string of tag names.
	 *
	 * Used for displaying stored tag IDs back as human-readable names in the UI.
	 *
	 * @param int[] $ids Array of tag term IDs.
	 * @return string Comma-separated tag names.
	 */
	public static function tag_ids_to_names( array $ids ): string {
		if ( empty( $ids ) ) {
			return '';
		}

		$names = [];
		foreach ( $ids as $id ) {
			$term = get_term( (int) $id, 'post_tag' );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}

		return implode( ', ', $names );
	}
}
