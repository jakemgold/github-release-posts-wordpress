<?php
/**
 * Admin settings page.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\Cache_Keys;
use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\Post\Post_Creator;
use GitHubReleasePosts\GitHub\Onboarding_Handler;
use GitHubReleasePosts\GitHub\Release_Monitor;
use GitHubReleasePosts\GitHub\Release_Queue;
use GitHubReleasePosts\GitHub\Release_State;
use GitHubReleasePosts\Notification\Email_Notifier;
use GitHubReleasePosts\Notification\Notification_Entry;
use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Post\Post_Status;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;

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
		add_filter( 'plugin_action_links_' . plugin_basename( GHRP_PATH . 'github-release-posts.php' ), [ $this, 'add_action_links' ] );

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
			esc_url( admin_url( 'tools.php?page=github-release-posts' ) ),
			esc_html__( 'Configure', 'auto-release-posts-for-github' )
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
			__( 'Auto Release Posts for GitHub', 'auto-release-posts-for-github' ),
			__( 'Release Posts', 'auto-release-posts-for-github' ),
			'manage_options',
			'github-release-posts',
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
				'id'      => 'ghrp-help-overview',
				'title'   => __( 'Overview', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'Auto Release Posts for GitHub', 'auto-release-posts-for-github' ) . '</h3>'
					. '<p>' . esc_html__( 'This plugin monitors GitHub repositories for new releases and uses AI to automatically generate blog posts from release notes. Posts are created as drafts (or published immediately) so your readers always know what changed in the projects you maintain.', 'auto-release-posts-for-github' ) . '</p>'
					. '<p>' . esc_html__( 'The plugin checks for new releases on a daily schedule via WP-Cron. You can also generate a post manually from the Repositories tab at any time.', 'auto-release-posts-for-github' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ghrp-help-getting-started',
				'title'   => __( 'Getting Started', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'Getting Started', 'auto-release-posts-for-github' ) . '</h3>'
					. '<ol>'
					. '<li>' . esc_html__( 'Set up an AI connector under Settings → Connectors (Anthropic, OpenAI, or Google recommended).', 'auto-release-posts-for-github' ) . '</li>'
					. '<li>' . esc_html__( 'Add a GitHub repository in the Repositories tab — either pick one from the dropdown (if a Personal Access Token is configured) or enter it in "owner/repo" format (e.g. "WordPress/gutenberg").', 'auto-release-posts-for-github' ) . '</li>'
					. '<li>' . esc_html__( 'When you add a repository, the plugin automatically checks for the latest release and generates a draft post if one is found. You can also generate a post manually at any time.', 'auto-release-posts-for-github' ) . '</li>'
					. '</ol>'
					. '<p>' . esc_html__( 'Optionally, add a GitHub Personal Access Token in the Settings tab to raise the API rate limit from 60 to 5,000 requests per hour and to populate a picker of your repositories on the Repositories tab.', 'auto-release-posts-for-github' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ghrp-help-repositories',
				'title'   => __( 'Repositories', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'Managing Repositories', 'auto-release-posts-for-github' ) . '</h3>'
					. '<p>' . esc_html__( 'Each repository you add is monitored for new GitHub releases. When a new release is detected, the plugin fetches the release notes, sends them to your configured AI provider, and creates a blog post.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Adding a Repository', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'When a Personal Access Token is configured, the Add Repository form shows a dropdown of repositories the token can access, grouped by owner and filtered to ones you are not already tracking. A Refresh button next to Add re-fetches the list — useful after granting the token access to additional repos on GitHub. Without a token, the form falls back to a free-text "owner/repo" field.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Per-Repository Options', 'auto-release-posts-for-github' ) . '</h4>'
					. '<ul>'
					. '<li><strong>' . esc_html__( 'Display Name', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'The project name used in post titles. Defaults to a cleaned-up version of the repo name.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Project Link', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'A URL included in the generated post as a download or project link. If the repository is a WordPress plugin, you can enter just the WordPress.org slug instead. If left blank, the GitHub release URL is used.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Author', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'The WordPress user assigned as the author of generated posts for this repository.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Post Status', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'Whether new posts are created as drafts, pending review, or published immediately. Defaults to draft.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Categories', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'Categories applied to every post generated for this repository.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Tags', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'Tags applied to every post generated for this repository. Tags must already exist in WordPress; new ones are not created automatically.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Featured Image', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'A featured image used as a fallback when the release notes do not contain any images suitable for promotion.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Paused', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'Temporarily skip this repository during scheduled checks. The repo and its history are preserved; uncheck to resume monitoring.', 'auto-release-posts-for-github' ) . '</li>'
					. '<li><strong>' . esc_html__( 'Include pre-releases', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'Generate posts for releases marked as pre-release on GitHub (betas, release candidates, etc.). Off by default; most sites only highlight stable releases.', 'auto-release-posts-for-github' ) . '</li>'
					. '</ul>'
					. '<p>' . esc_html__( 'Use the Edit row action to change any of these inline, then click Save Repositories at the bottom of the page.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Generate Post', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'Creates a post from a GitHub release immediately, bypassing the cron schedule. If the repository has multiple releases, a picker lets you choose any historical version — useful for backfilling an archive of past releases.', 'auto-release-posts-for-github' ) . '</p>'
					. '<p>' . esc_html__( 'Posts generated for older releases are automatically backdated to one hour after the release\'s GitHub publication time, so they slot into the archive in the correct chronological order. You can adjust the date in the editor before publishing.', 'auto-release-posts-for-github' ) . '</p>'
					. '<p>' . esc_html__( 'If a post already exists for the selected version, the picker shows an inline warning and re-generation creates a new revision while preserving the existing post date and URL slug.', 'auto-release-posts-for-github' ) . '</p>'
					. '<p>' . esc_html__( 'After generation succeeds, a green checkmark appears next to the Generate post button — click it to jump straight to the new post in the editor.', 'auto-release-posts-for-github' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ghrp-help-ai-settings',
				'title'   => __( 'AI & Prompts', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'Post Creation Settings', 'auto-release-posts-for-github' ) . '</h3>'
				. '<p>' . esc_html__( 'This plugin uses WordPress Connectors to communicate with AI providers. Configure your preferred connector (Anthropic, OpenAI, or Google) under Settings → Connectors.', 'auto-release-posts-for-github' ) . '</p>'
				. '<h4>' . esc_html__( 'Recommended Models', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'The plugin specifies a list of preferred models and automatically uses the best available one via your configured connector. For best results, your AI provider account should support one of these models:', 'auto-release-posts-for-github' ) . '</p>'
				. '<ul>'
				. '<li>' . esc_html__( 'Anthropic — Claude Opus 4.7', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'OpenAI — GPT-5.5', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Google — Gemini 2.5 Pro', 'auto-release-posts-for-github' ) . '</li>'
				. '</ul>'
				. '<p>' . sprintf(
					/* translators: %s: filter name wrapped in <code> tags */
					esc_html__( 'If none of these models are available, the plugin falls back to whatever model your connector provides. Developers can customize the preferred model list via the %s filter.', 'auto-release-posts-for-github' ),
					'<code>ghrp_wp_ai_client_model_preferences</code>'
				) . '</p>'
				. '<h4>' . esc_html__( 'Research Depth', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Controls how much context the AI gathers before writing. "Standard" uses the release notes, linked issues/PRs, and README. "Deep" also fetches commit messages and file change summaries between the previous and current release, giving the AI more detail to work with — especially useful for releases with sparse notes.', 'auto-release-posts-for-github' ) . '</p>'
				. '<h4>' . esc_html__( 'Post Audience', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Controls the technical depth of generated posts. "Site owners & managers" avoids all jargon; "Engineering teams" includes hook signatures, code examples, and architecture details.', 'auto-release-posts-for-github' ) . '</p>'
				. '<h4>' . esc_html__( 'Post Titles', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Controls the format of generated post titles:', 'auto-release-posts-for-github' ) . '</p>'
				. '<ul>'
				. '<li><strong>' . esc_html__( 'Plugin name and version', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'e.g. "My Plugin v1.2 — New dashboard widget". Recommended for sites covering multiple projects.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li><strong>' . esc_html__( 'Version number only', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'e.g. "Version 1.2 — New dashboard widget". Drops the project name from the prefix.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li><strong>' . esc_html__( 'No prefix', 'auto-release-posts-for-github' ) . '</strong> — ' . esc_html__( 'The AI writes the full title with no automatic prefix. Recommended for sites focused on a single project, where leading every title with the project name and version reads as repetitive.', 'auto-release-posts-for-github' ) . '</li>'
				. '</ul>'
				. '<p>' . sprintf(
					/* translators: %s: filter name wrapped in <code> tags */
					esc_html__( 'Developers can override the final title via the %s filter.', 'auto-release-posts-for-github' ),
					'<code>ghrp_post_title</code>'
				) . '</p>'
				. '<h4>' . esc_html__( 'Custom Prompt Instructions', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Add extra instructions to guide the AI\'s writing style, tone, or voice. For example: "Write in a friendly, conversational tone" or "Our readers are non-technical site owners." Keep it under 500 characters for best results.', 'auto-release-posts-for-github' ) . '</p>'
				. '<h4>' . esc_html__( 'AI Disclosure', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'When enabled, the following note is appended to the end of each generated post in small italic text:', 'auto-release-posts-for-github' ) . '</p>'
				. '<blockquote><em>' . esc_html__( 'This post was generated from release notes with the help of AI using the Auto Release Posts for GitHub plugin.', 'auto-release-posts-for-github' ) . '</em></blockquote>'
				. '<p>' . sprintf(
					/* translators: %s: filter name wrapped in <code> tags */
					esc_html__( 'This text is part of the post content and can be edited or removed. Developers can customize it with the %s filter.', 'auto-release-posts-for-github' ),
					'<code>ghrp_ai_disclosure_text</code>'
				) . '</p>'
				. '<h4>' . esc_html__( 'SEO: Excerpts & Slugs', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Each generated post includes an AI-written excerpt (150–160 characters, optimized as a meta description) and an SEO-friendly URL slug based on the project name, version, and key topics. Published posts keep their existing slug when regenerated to preserve live URLs.', 'auto-release-posts-for-github' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ghrp-help-github',
				'title'   => __( 'GitHub Token', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'GitHub Personal Access Token', 'auto-release-posts-for-github' ) . '</h3>'
				. '<p>' . esc_html__( 'A Personal Access Token is optional for public repositories — without one, GitHub limits the plugin to 60 API requests per hour. Adding a token does three things:', 'auto-release-posts-for-github' ) . '</p>'
				. '<ul>'
				. '<li>' . esc_html__( 'Raises the GitHub API rate limit from 60 to 5,000 requests per hour.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Replaces the "owner/repo" text field on the Repositories tab with a dropdown of repositories the token can access.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Grants access to private repositories.', 'auto-release-posts-for-github' ) . '</li>'
				. '</ul>'
				. '<h4>' . esc_html__( 'Creating a Token (fine-grained recommended)', 'auto-release-posts-for-github' ) . '</h4>'
				. '<ol>'
				. '<li>'
					. sprintf(
						/* translators: %s: link to GitHub fine-grained token settings */
						esc_html__( 'Visit %s on GitHub.', 'auto-release-posts-for-github' ),
						'<a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener">' . esc_html__( 'Settings &rarr; Personal access tokens &rarr; Fine-grained tokens', 'auto-release-posts-for-github' ) . '</a>'
					)
				. '</li>'
				. '<li>' . esc_html__( 'Under Repository access, choose "Only select repositories" and pick the repos you want to monitor — or "All repositories" if you prefer.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Set Repository permissions to Read-only for: Contents (required), Metadata (auto-selected), Issues, and Pull requests. The last two are used during AI prompt enrichment.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Generate the token and copy the github_pat_… value immediately — GitHub will not show it again.', 'auto-release-posts-for-github' ) . '</li>'
				. '<li>' . esc_html__( 'Paste the token into the GitHub Personal Access Token field in the Settings tab. A green Validated indicator appears once GitHub confirms the token.', 'auto-release-posts-for-github' ) . '</li>'
				. '</ol>'
				. '<h4>' . esc_html__( 'Supplying the Token Outside the Database', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'For sites that prefer not to store secrets in wp_options, the token can be supplied via the GHRP_PAT PHP constant in wp-config.php, or via an environment variable of the same name. The plugin reads the constant first, then the env var, then the encrypted database value. When supplied externally, the Settings field becomes read-only.', 'auto-release-posts-for-github' ) . '</p>'
				. '<p>' . esc_html__( 'When stored in the database, the token is encrypted at rest using libsodium and is never exposed in the admin UI after saving.', 'auto-release-posts-for-github' ) . '</p>'
				. '<h4>' . esc_html__( 'Classic Tokens', 'auto-release-posts-for-github' ) . '</h4>'
				. '<p>' . esc_html__( 'Classic tokens are still supported. Use the "public_repo" scope for public repos or the full "repo" scope for private repos.', 'auto-release-posts-for-github' ) . '</p>',
			]
		);

		$screen->add_help_tab(
			[
				'id'      => 'ghrp-help-troubleshooting',
				'title'   => __( 'Troubleshooting', 'auto-release-posts-for-github' ),
				'content' => '<h3>' . esc_html__( 'Troubleshooting', 'auto-release-posts-for-github' ) . '</h3>'
					. '<h4>' . esc_html__( 'Post generation fails or times out', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'AI generation can take 30–60 seconds for complex releases. If your hosting environment has a short PHP execution time limit, the request may time out before the AI responds. Contact your host about increasing the limit, or try generating again — some releases take longer than others.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'API credits or billing error', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'If you see a billing or credits error, verify that your AI provider account has API credits loaded. Some providers have separate billing for API usage and chat subscriptions.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Images show "unexpected or invalid content"', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'If image blocks show a validation warning in the editor, click "Attempt recovery" — this usually resolves the issue. The plugin rebuilds image blocks from AI output, and minor formatting differences can occasionally trigger this warning.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Posts are empty or very short', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'This usually means the GitHub release has no release notes (just a tag with no body text). The plugin generates content from the release notes — if there are none, the AI has little to work with. Check the release on GitHub to confirm it has a description.', 'auto-release-posts-for-github' ) . '</p>'
					. '<h4>' . esc_html__( 'Scheduled checks are not running', 'auto-release-posts-for-github' ) . '</h4>'
					. '<p>' . esc_html__( 'The plugin relies on WP-Cron, which requires regular site traffic to trigger. On low-traffic sites, consider setting up a real server cron job to call wp-cron.php. Check Tools → Site Health for WP-Cron status.', 'auto-release-posts-for-github' ) . '</p>',
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
			'github-release-posts-admin',
			GHRP_URL . 'dist/css/admin-style.css',
			[],
			GHRP_VERSION
		);

		$admin_asset_file = GHRP_PATH . 'dist/js/admin.asset.php';
		$admin_asset      = file_exists( $admin_asset_file ) ? require $admin_asset_file : [
			'dependencies' => [],
			'version'      => GHRP_VERSION,
		];

		wp_enqueue_script(
			'github-release-posts-admin-js',
			GHRP_URL . 'dist/js/admin.js',
			$admin_asset['dependencies'],
			$admin_asset['version'] ?? GHRP_VERSION,
			true
		);

		wp_localize_script(
			'github-release-posts-admin-js',
			'ghrpAdmin',
			[
				'restUrl'           => get_rest_url( null, 'ghrp/v1' ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'blockEditorActive' => self::is_block_editor_active(),
				'i18n'              => [
					'unsavedChanges'        => __( 'You have unsaved changes. Are you sure you want to leave this tab?', 'auto-release-posts-for-github' ),
					'confirmRemove'         => __( 'Are you sure you want to remove this repository? This cannot be undone.', 'auto-release-posts-for-github' ),
					'validating'            => __( 'Validating…', 'auto-release-posts-for-github' ),
					'slugValid'             => __( 'Found on WordPress.org.', 'auto-release-posts-for-github' ),
					'slugNotFound'          => __( 'Not found on WordPress.org.', 'auto-release-posts-for-github' ),
					'validUrl'              => __( 'Valid URL.', 'auto-release-posts-for-github' ),
					'invalidUrl'            => __( 'Invalid URL format.', 'auto-release-posts-for-github' ),
					'pluginLinkHint'        => __( 'Enter a valid URL or WordPress.org slug.', 'auto-release-posts-for-github' ),
					'selectImage'           => __( 'Select Featured Image', 'auto-release-posts-for-github' ),
					'useImage'              => __( 'Use this image', 'auto-release-posts-for-github' ),
					'removeImage'           => __( 'Remove', 'auto-release-posts-for-github' ),
					'notImplemented'        => __( 'This feature is not yet available.', 'auto-release-posts-for-github' ),
					'edit'                  => __( 'Edit', 'auto-release-posts-for-github' ),
					'editLabel'             => __( 'Edit:', 'auto-release-posts-for-github' ),
					'done'                  => __( 'Done', 'auto-release-posts-for-github' ),
					'generateDraft'         => __( 'Generate draft post', 'auto-release-posts-for-github' ),
					'generatePost'          => __( 'Generate post', 'auto-release-posts-for-github' ),
					'generating'            => __( 'Generating…', 'auto-release-posts-for-github' ),
					'draftCreated'          => __( 'Draft created.', 'auto-release-posts-for-github' ),
					'editGeneratedPost'     => __( 'Edit the generated post', 'auto-release-posts-for-github' ),
					'viewDraft'             => __( 'View draft', 'auto-release-posts-for-github' ),
					'regenerate'            => __( 'Regenerate', 'auto-release-posts-for-github' ),
					'regenerateConfirm'     => __( 'A post already exists for this release. Regenerate it?', 'auto-release-posts-for-github' ),
					'postExists'            => __( 'post exists', 'auto-release-posts-for-github' ),
					'versionPickerConflict' => __( 'A post already exists for this release. Generating will create a new revision and keep the existing post date.', 'auto-release-posts-for-github' ),
					'valid'                 => __( 'Valid', 'auto-release-posts-for-github' ),
					'connectionSuccess'     => __( 'Connection successful.', 'auto-release-posts-for-github' ),
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

		$asset_file = GHRP_PATH . 'dist/js/editor.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [
			'dependencies' => [],
			'version'      => GHRP_VERSION,
		];

		wp_enqueue_script(
			'github-release-posts-editor',
			GHRP_URL . 'dist/js/editor.js',
			$asset['dependencies'],
			$asset['version'] ?? GHRP_VERSION,
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
					// Tightened from edit_posts: only admins should be able to
					// write release-attribution meta via REST. The meta drives
					// dedup, so a lower-privileged user shouldn't be able to
					// tag arbitrary posts as already-generated for a release.
					'auth_callback' => function () {
						return current_user_can( 'manage_options' );
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
			'ghrp/v1',
			'/releases/list',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_list_releases' ],
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
			'ghrp/v1',
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
					'tag'  => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'ghrp/v1',
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
			'ghrp/v1',
			'/notifications/test',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_send_test_notification' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			]
		);

		register_rest_route(
			'ghrp/v1',
			'/repos/refresh',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_refresh_accessible_repos' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			]
		);

		register_rest_route(
			'ghrp/v1',
			'/pat/validate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_validate_pat' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'pat' => [
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'ghrp/v1',
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
				'ghrp_forbidden',
				__( 'You do not have permission to perform this action.', 'auto-release-posts-for-github' ),
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'auto-release-posts-for-github' ) );
		}

		include GHRP_PATH . 'includes/templates/admin-page.php';
	}

	/**
	 * Dispatches form submissions to the appropriate handler.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- nonce is verified per-action in handle_repositories_save(); these three lines are the early-return guard that decides whether we're handling this plugin's form submission at all.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'POST' !== $method || empty( $_POST['ghrp_action'] ) || 'github-release-posts' !== $page ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		$action = sanitize_key( $_POST['ghrp_action'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

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
		check_admin_referer( 'ghrp_save_repositories', 'ghrp_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'auto-release-posts-for-github' ) );
		}

		// Handle "Remove" action.
		if ( ! empty( $_POST['ghrp_remove_repo'] ) ) {
			$identifier = sanitize_text_field( wp_unslash( $_POST['ghrp_remove_repo'] ) );
			$this->repo_settings->remove_repository( $identifier );
			// Clear per-repo state so a re-add starts clean (AC-003, AC-004).
			( new Release_State() )->clear_state( $identifier );
			// Clear AI failure counts so a re-add doesn't inherit stale failure
			// state and trigger the threshold notification email after fewer
			// than FAILURE_THRESHOLD new failures.
			\GitHubReleasePosts\AI\AI_Processor::clear_failure_counts_for_identifier( $identifier );
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

		// Handle "Add repository" action. Pass the API client so the display
		// name can be enriched from the repo's README first heading; the
		// settings layer silently falls back to slug-derivation on any failure.
		if ( isset( $_POST['ghrp_add_repo'] ) && ! empty( $_POST['ghrp_new_repo'] ) ) {
			$result = $this->repo_settings->add_repository(
				sanitize_text_field( wp_unslash( $_POST['ghrp_new_repo'] ) ),
				new API_Client( $this->global_settings )
			);

			if ( ! $result['success'] ) {
				$this->set_admin_error( $result['error'] ?? __( 'Could not add repository.', 'auto-release-posts-for-github' ) );
				wp_safe_redirect(
					add_query_arg( 'tab', 'repositories', $this->get_page_url() )
				);
				exit;
			}

			// Identify the just-added repo (it'll be the last entry in the array).
			$added_identifier = '';
			foreach ( array_reverse( $result['repos'] ) as $repo ) {
				if ( isset( $repo['identifier'] ) ) {
					$added_identifier = $repo['identifier'];
					break;
				}
			}

			// Server-side post-add bookkeeping: fetch latest release, record
			// last_seen so cron doesn't double-process, and decide whether to
			// tell the client to auto-trigger AI generation on page load.
			$auto_trigger = false;
			if ( '' !== $added_identifier ) {
				try {
					$outcome = ( new Onboarding_Handler(
						new API_Client( $this->global_settings ),
						new Release_State()
					) )->handle_add( $added_identifier );

					if ( null !== $outcome['notice'] ) {
						$this->set_admin_notice(
							$outcome['notice']['type'],
							$outcome['notice']['message'],
							$outcome['notice']['url']
						);
					}
					$auto_trigger = $outcome['auto_trigger'];
				} catch ( \Throwable $e ) {
					$this->set_admin_notice( 'warning', __( 'Repository added, but the initial release check failed. It will be retried on the next scheduled run.', 'auto-release-posts-for-github' ), '' );
				}
			}

			$redirect_args = [
				'tab'   => 'repositories',
				'saved' => '1',
			];
			if ( $auto_trigger ) {
				// JS reads this on load, finds the matching row, and calls
				// `generateForTag( btn, '' )` directly — skipping the version
				// picker dialog since "latest" is the unambiguous intent for
				// a freshly added repo.
				$redirect_args['ghrp_just_added'] = $added_identifier;
			}

			wp_safe_redirect( add_query_arg( $redirect_args, $this->get_page_url() ) );
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
				'display_name'        => sanitize_text_field( wp_unslash( $config['display_name'] ?? '' ) ),
				'plugin_link'         => $raw_plugin_link,
				'author'              => absint( $config['author'] ?? 0 ),
				'post_status'         => sanitize_key( $config['post_status'] ?? '' ),
				// array_values() re-indexes after array_filter() drops the hidden "0"
				// fallback element, so categories store as a sequential list. Without
				// this, the kept 1-based keys serialize to a JSON object in the row's
				// data-categories attribute and crash the inline editor's category loop.
				'categories'          => array_values( array_map( 'absint', array_filter( (array) ( $config['categories'] ?? [] ) ) ) ),
				'tags'                => $this->resolve_tag_names_to_ids( $raw_repo_tags ),
				'paused'              => ! empty( $config['paused'] ),
				'featured_image'      => absint( $config['featured_image'] ?? 0 ),
				'include_prereleases' => ! empty( $config['include_prereleases'] ),
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
				Cache_Keys::admin_errors( get_current_user_id() ),
				sprintf(
					/* translators: %d: number of repositories that failed to update */
					__( '%d repository update(s) failed. Please try again.', 'auto-release-posts-for-github' ),
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
	 * REST handler: lists releases for a repository.
	 *
	 * Returns up to 100 releases (latest first), each annotated with whether
	 * a post already exists for that tag. Powers the manual version-picker UI.
	 *
	 * @param \WP_REST_Request $request REST request containing the repo identifier.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_list_releases( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$identifier = (string) $request->get_param( 'repo' );
		$api_client = new API_Client( $this->global_settings );

		// Honor the per-repo Include pre-releases setting so the version picker
		// matches the cron's eligibility rules. Falls back to false for repos
		// where the field is missing (pre-1.0 entries).
		$repo                = $this->repo_settings->get_repository( $identifier );
		$include_prereleases = ! empty( $repo['include_prereleases'] );

		$releases = $api_client->fetch_releases( $identifier, $include_prereleases );

		if ( is_wp_error( $releases ) ) {
			return new \WP_Error( $releases->get_error_code(), $releases->get_error_message(), [ 'status' => 400 ] );
		}

		if ( empty( $releases ) ) {
			return new \WP_REST_Response(
				[
					'releases'   => [],
					'latest_tag' => '',
				],
				200
			);
		}

		$latest_tag = $releases[0]->tag;

		$payload = [];
		foreach ( $releases as $release ) {
			$existing = Release_Monitor::find_post( $identifier, $release->tag );
			$entry    = [
				'tag'           => $release->tag,
				'name'          => $release->name,
				'published_at'  => $release->published_at,
				'has_post'      => $existing instanceof \WP_Post,
				'post_id'       => 0,
				'post_status'   => '',
				'post_edit_url' => '',
			];

			if ( $existing instanceof \WP_Post ) {
				$entry['post_id']       = $existing->ID;
				$entry['post_status']   = $existing->post_status;
				$entry['post_edit_url'] = (string) get_edit_post_link( $existing->ID, 'raw' );
			}

			$payload[] = $entry;
		}

		return new \WP_REST_Response(
			[
				'releases'   => $payload,
				'latest_tag' => $latest_tag,
			],
			200
		);
	}

	/**
	 * REST handler: generates a draft post for the latest release of a repository.
	 *
	 * Returns conflict data when a post already exists for the tag (BR-003),
	 * otherwise fires ghrp_process_release and returns the created post.
	 *
	 * @param \WP_REST_Request $request REST request containing the repo identifier.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! self::is_block_editor_active() ) {
			return new \WP_Error( 'ghrp_no_block_editor', __( 'Post generation requires the block editor.', 'auto-release-posts-for-github' ), [ 'status' => 400 ] );
		}

		$identifier   = $request->get_param( 'repo' );
		$selected_tag = trim( (string) $request->get_param( 'tag' ) );
		$api_client   = new API_Client( $this->global_settings );

		// Resolve the latest release first — used for the is_latest flag and as
		// the default when no tag was specified.
		$latest_release = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $latest_release ) ) {
			return new \WP_Error( $latest_release->get_error_code(), $latest_release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( null === $latest_release ) {
			return new \WP_Error( 'ghrp_no_release', __( 'No releases found for this repository.', 'auto-release-posts-for-github' ), [ 'status' => 404 ] );
		}

		if ( '' === $selected_tag || $selected_tag === $latest_release->tag ) {
			$release   = $latest_release;
			$is_latest = true;
		} else {
			$release = $api_client->fetch_release_by_tag( $identifier, $selected_tag );
			if ( is_wp_error( $release ) ) {
				return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
			}
			if ( null === $release ) {
				return new \WP_Error( 'ghrp_no_release', __( 'The selected release was not found on GitHub.', 'auto-release-posts-for-github' ), [ 'status' => 404 ] );
			}
			$is_latest = false;
		}

		// Check for existing post — offer conflict resolution (BR-003).
		// Trashed posts are treated as not-existing here: the user clicked
		// "Generate post" deliberately, and surfacing a conflict over an
		// already-trashed draft would be confusing. The cron pipeline still
		// honors trash as a "skip this release" signal (Post_Creator::handle).
		$existing          = Release_Monitor::find_post( $identifier, $release->tag );
		$existing_in_trash = ( $existing instanceof \WP_Post && 'trash' === $existing->post_status );
		if ( $existing_in_trash ) {
			$existing = null;
		}

		if ( $existing instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'conflict'  => true,
					'is_latest' => $is_latest,
					'post'      => $this->build_post_response( $existing ),
				],
				200
			);
		}

		// Backdate older releases so the post does not appear newer than later releases.
		// Rule: published_at + 1 hour, in GMT. Site owners can adjust before publishing.
		$context = [
			'force_draft' => true,
			'manual'      => true,
		];

		// If the prior post for this tag was in trash, bypass Post_Creator's
		// idempotency check — otherwise it would find the trashed post again
		// downstream and silently skip the insert, leaving the user with a
		// "success" response and no new draft. The trashed post stays in
		// trash; a new draft is created alongside it.
		if ( $existing_in_trash ) {
			$context['bypass_idempotency'] = true;
		}

		if ( ! $is_latest && '' !== $release->published_at ) {
			$timestamp = strtotime( $release->published_at );
			if ( false !== $timestamp ) {
				$backdated_gmt            = gmdate( 'Y-m-d H:i:s', $timestamp + HOUR_IN_SECONDS );
				$context['post_date_gmt'] = $backdated_gmt;
				$context['post_date']     = get_date_from_gmt( $backdated_gmt );
			}
		}

		/**
		 * Fires to trigger AI generation for a manual draft request.
		 *
		 * DOM-05/06 hooks here. force_draft ensures the post is always a draft
		 * regardless of the global post-status setting (AC-011).
		 *
		 * @param array<string, mixed> $entry   Queue entry with release data.
		 * @param array<string, mixed> $context Context flags: force_draft, manual,
		 *                                     and optionally post_date / post_date_gmt for backdating.
		 */
		do_action(
			'ghrp_process_release',
			Release_Queue::from_release( $identifier, $release ),
			$context
		);

		$post = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $post instanceof \WP_Post ) {
			// Record last_seen for latest-release generation so the cron
			// doesn't re-enqueue and waste another AI call. Skip when the
			// user generated for an older release via the version picker —
			// pinning last_seen to an older tag would suppress newer real
			// releases. The cron's own pipeline records last_seen after
			// its successful generations; this closes the same gap for
			// client-initiated generation.
			if ( $is_latest ) {
				( new Release_State() )->update_last_seen(
					$identifier,
					$release->tag,
					$release->published_at
				);
			}
			return new \WP_REST_Response(
				[
					'conflict'  => false,
					'is_latest' => $is_latest,
					'post'      => $this->build_post_response( $post ),
				],
				201
			);
		}

		$last_error = \GitHubReleasePosts\AI\AI_Processor::get_last_error();

		if ( $last_error instanceof \WP_Error ) {
			return new \WP_Error(
				$last_error->get_error_code(),
				$last_error->get_error_message(),
				[ 'status' => 422 ]
			);
		}

		return new \WP_Error(
			'ghrp_generation_failed',
			__( 'Draft could not be generated. Check the debug log for details or verify your connector configuration under Settings → Connectors.', 'auto-release-posts-for-github' ),
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
				'ghrp_no_recipients',
				__( 'No notification recipients configured. Enable the site owner checkbox or add email addresses first.', 'auto-release-posts-for-github' ),
				[ 'status' => 400 ]
			);
		}

		$entry   = $this->build_test_notification_entry();
		$entries = [ $entry ];

		// Build subject matching the real notification format, prefixed with [Test].
		$display = $entry->display_name . ' ' . $entry->tag;
		if ( Post_Status::is_public( $entry->status ) ) {
			/* translators: %s: project name and version */
			$subject = sprintf( __( '[Test] %s — release post published', 'auto-release-posts-for-github' ), $display );
		} else {
			/* translators: %s: project name and version */
			$subject = sprintf( __( '[Test] %s — draft ready for review', 'auto-release-posts-for-github' ), $display );
		}

		$preamble  = '<p><em>' . esc_html__( 'This is a test email.', 'auto-release-posts-for-github' ) . '</em></p>';
		$html_body = Email_Notifier::build_html_body( $entries, $preamble );
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
				'ghrp_mail_failed',
				__( 'Failed to send test email. Check your site\'s email configuration.', 'auto-release-posts-for-github' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'message' => sprintf(
					/* translators: %s: comma-separated list of recipient emails */
					__( 'Test email sent to %s.', 'auto-release-posts-for-github' ),
					implode( ', ', $recipients )
				),
			],
			200
		);
	}

	/**
	 * REST handler: clears and re-fetches the cached list of repositories
	 * accessible to the configured PAT.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_refresh_accessible_repos(): \WP_REST_Response|\WP_Error {
		if ( 'none' === $this->global_settings->get_github_pat_source() ) {
			return new \WP_Error(
				'ghrp_no_pat',
				__( 'No Personal Access Token is configured.', 'auto-release-posts-for-github' ),
				[ 'status' => 400 ]
			);
		}

		$result = ( new API_Client( $this->global_settings ) )->list_accessible_repos( true );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 502 ] );
			return $result;
		}

		// Filter out already-tracked repos so the client doesn't have to.
		$tracked  = array_column( $this->repo_settings->get_repositories(), 'identifier' );
		$eligible = array_values(
			array_filter(
				$result,
				static fn( $r ) => ! in_array( $r['identifier'], $tracked, true )
			)
		);

		// Group by owner for the always-visible picker list.
		$groups = [];
		foreach ( $eligible as $r ) {
			$groups[ $r['owner'] ][] = [
				'identifier' => $r['identifier'],
				'name'       => $r['name'],
			];
		}

		$count = count( $eligible );

		return new \WP_REST_Response(
			[
				'count'   => $count,
				/* translators: %d: number of repositories */
				'message' => sprintf( _n( '%d repository available.', '%d repositories available.', $count, 'auto-release-posts-for-github' ), $count ),
				'groups'  => (object) $groups,
			],
			200
		);
	}

	/**
	 * REST handler: validates a Personal Access Token against the GitHub API.
	 *
	 * When the `pat` parameter is empty or omitted, validates the currently-
	 * stored PAT. Used by the Settings field's tab-out indicator so the user
	 * gets a green check / warning before saving.
	 *
	 * @param \WP_REST_Request $request REST request, optionally with a `pat` body param.
	 * @return \WP_REST_Response
	 */
	public function rest_validate_pat( \WP_REST_Request $request ): \WP_REST_Response {
		$submitted = (string) $request->get_param( 'pat' );

		// The masked placeholder means "use the stored PAT" — same convention
		// as Global_Settings::save_github_pat.
		if ( '' === $submitted || Global_Settings::MASKED_PLACEHOLDER === $submitted ) {
			$result = ( new API_Client( $this->global_settings ) )->validate_pat();
		} else {
			$result = ( new API_Client( $this->global_settings ) )->validate_pat( $submitted );
		}

		if ( true === $result ) {
			return new \WP_REST_Response(
				[
					'valid'   => true,
					'message' => __( 'Validated', 'auto-release-posts-for-github' ),
				],
				200
			);
		}

		return new \WP_REST_Response(
			[
				'valid'   => false,
				'message' => $result->get_error_message(),
			],
			200
		);
	}

	/**
	 * Builds a test notification entry from the most recent generated post,
	 * or a placeholder if no posts exist yet.
	 *
	 * @return Notification_Entry
	 */
	private function build_test_notification_entry(): Notification_Entry {
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
			$identifier = (string) get_post_meta( $post->ID, Plugin_Constants::META_SOURCE_REPO, true );
			$tag        = (string) get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_TAG, true );
			$html_url   = (string) get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_URL, true );

			return new Notification_Entry(
				post_id:      (int) $post->ID,
				status:       (string) $post->post_status,
				identifier:   $identifier,
				display_name: $this->repo_settings->get_display_name( $identifier ),
				tag:          $tag,
				html_url:     $html_url,
				post_title:   (string) get_the_title( $post->ID ),
			);
		}

		// Placeholder when no posts exist.
		return new Notification_Entry(
			post_id:      0,
			status:       'draft',
			identifier:   'example/plugin',
			display_name: 'Example Plugin',
			tag:          'v1.0.0',
			html_url:     'https://github.com/example/plugin/releases/tag/v1.0.0',
			post_title:   'Example Plugin 1.0: A Major New Release',
		);
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
			return new \WP_Error( 'ghrp_post_not_found', __( 'Post not found.', 'auto-release-posts-for-github' ), [ 'status' => 404 ] );
		}

		// Read source meta.
		$identifier  = (string) get_post_meta( $post_id, Plugin_Constants::META_SOURCE_REPO, true );
		$release_tag = (string) get_post_meta( $post_id, Plugin_Constants::META_RELEASE_TAG, true );

		if ( empty( $identifier ) || empty( $release_tag ) ) {
			return new \WP_Error( 'ghrp_not_generated', __( 'This post was not generated by the plugin.', 'auto-release-posts-for-github' ), [ 'status' => 422 ] );
		}

		// Fetch the specific release this post was generated from — NOT the
		// current "latest" — so regenerating a historical post keeps using
		// that post's source release. Otherwise a v1.2 post regenerated after
		// v1.3 ships would silently get v1.3 content while its meta still
		// claimed v1.2.
		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_release_by_tag( $identifier, $release_tag );

		if ( is_wp_error( $release ) ) {
			return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( null === $release ) {
			return new \WP_Error(
				'ghrp_no_release',
				sprintf(
					/* translators: %s: release tag */
					__( 'Release %s not found on GitHub.', 'auto-release-posts-for-github' ),
					$release_tag
				),
				[ 'status' => 404 ]
			);
		}

		// Build ReleaseData from the fetched release.
		$data = new \GitHubReleasePosts\AI\ReleaseData(
			identifier:   $identifier,
			tag:          $release->tag,
			name:         $release->name,
			body:         $release->body,
			html_url:     $release->html_url,
			published_at: $release->published_at,
			assets:       $release->assets,
		);

		// Generate a new prompt — the standard pipeline handles enrichment and significance.
		$prompt = (string) apply_filters( 'ghrp_generate_prompt', '', $data );

		// Append user feedback if provided.
		if ( '' !== trim( $feedback ) ) {
			$prompt .= "\n\nFEEDBACK FROM THE SITE OWNER (apply these changes):\n" . trim( $feedback );
		}

		// Call the AI provider.
		$factory  = new \GitHubReleasePosts\AI\AI_Provider_Factory( $this->global_settings );
		$provider = $factory->get_provider();

		if ( is_wp_error( $provider ) ) {
			return new \WP_Error( $provider->get_error_code(), $provider->get_error_message(), [ 'status' => 422 ] );
		}

		$result = $provider->generate_post( $data, $prompt );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 422 ] );
		}

		// Assemble full title — honors the configured title format (regression fix:
		// previously hardcoded the 'full' format, doubling project name + version
		// for sites with 'none' selected).
		$display_name = $this->repo_settings->get_display_name( $identifier );
		$full_title   = Post_Creator::build_title(
			$display_name,
			$data->tag,
			$result->title,
			$this->global_settings->get_title_format(),
			$identifier
		);

		// Convert HTML to blocks and update the existing post (creates a revision).
		// KSES at the save boundary as defense-in-depth against unfiltered_html
		// admins receiving prompt-injected AI HTML (see Post_Creator::create()).
		$block_content  = Post_Creator::convert_html_to_blocks( $result->content );
		$block_content .= Post_Creator::build_disclosure_block( $data );
		$update_args    = [
			'ID'           => $post_id,
			'post_title'   => wp_strip_all_tags( $full_title ),
			'post_content' => wp_kses_post( $block_content ),
		];

		// Always update the excerpt.
		if ( '' !== $result->excerpt ) {
			$update_args['post_excerpt'] = wp_kses_post( $result->excerpt );
		}

		// Only update the slug if the post is not yet published (preserve live URLs).
		if ( '' !== $result->slug_keywords && ! Post_Status::has_permalink( $post->post_status ) ) {
			$version                  = strtolower( ltrim( $data->tag, 'vV' ) );
			$version                  = str_replace( '.', '-', $version );
			$update_args['post_name'] = sanitize_title( $display_name . '-' . $version . '-' . $result->slug_keywords );
		}

		$update_result = wp_update_post( $update_args, true );

		if ( is_wp_error( $update_result ) ) {
			return new \WP_Error(
				'ghrp_update_failed',
				$update_result->get_error_message(),
				[ 'status' => 422 ]
			);
		}

		// Sideload any remote images into the media library.
		Post_Creator::sideload_images( $post_id );

		// Update the provider meta.
		update_post_meta( $post_id, Plugin_Constants::META_GENERATED_BY, $result->provider_slug );

		$updated_post = get_post( $post_id );

		// Include content and excerpt in the response so the editor can rehydrate
		// its block list and meta in-place. Without this the editor's local
		// state still holds the pre-regeneration content; the next Save click
		// would clobber the server-side regenerated content with stale blocks.
		$post_payload = $updated_post ? $this->build_post_response( $updated_post ) : [
			'id'       => $post_id,
			'title'    => $full_title,
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		];
		if ( $updated_post ) {
			$post_payload['content'] = $updated_post->post_content;
			$post_payload['excerpt'] = $updated_post->post_excerpt;
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'post'    => $post_payload,
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
		return admin_url( 'tools.php?page=github-release-posts' );
	}

	/**
	 * Stores an error message in a short-lived transient so the template can display it.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	private function set_admin_error( string $message ): void {
		$user_id = get_current_user_id();
		set_transient( Cache_Keys::admin_errors( $user_id ), $message, 60 );
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
			Cache_Keys::admin_notice( $user_id ),
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
			if ( $term instanceof \WP_Term ) {
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
