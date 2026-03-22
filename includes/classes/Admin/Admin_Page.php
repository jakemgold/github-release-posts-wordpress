<?php
/**
 * Admin settings page.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Admin;

use TenUp\ChangelogToBlogPost\GitHub\API_Client;
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
	 * Registers all WordPress hooks.
	 *
	 * @return void
	 */
	public function setup(): void {
		add_action( 'admin_menu', [ $this, 'register_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
		add_action( 'init', [ $this, 'register_post_meta' ] );
	}

	/**
	 * Registers the settings page as a Tools submenu item.
	 *
	 * @return void
	 */
	public function register_menu_page(): void {
		$this->page_hook = (string) add_management_page(
			__( 'Changelog to Blog Post', 'changelog-to-blog-post' ),
			__( 'Changelog to Blog Post', 'changelog-to-blog-post' ),
			'manage_options',
			'changelog-to-blog-post',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueues admin CSS and JS only on the plugin's settings page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style(
			'changelog-to-blog-post-admin',
			CHANGELOG_TO_BLOG_POST_URL . 'assets/css/admin/style.css',
			[],
			CHANGELOG_TO_BLOG_POST_VERSION
		);

		wp_enqueue_script(
			'changelog-to-blog-post-admin-js',
			CHANGELOG_TO_BLOG_POST_URL . 'assets/js/admin/index.js',
			[],
			CHANGELOG_TO_BLOG_POST_VERSION,
			true
		);

		wp_localize_script(
			'changelog-to-blog-post-admin-js',
			'ctbpAdmin',
			[
				'restUrl'   => get_rest_url( null, 'ctbp/v1' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => [
					'unsavedChanges'    => __( 'You have unsaved changes. Are you sure you want to leave this tab?', 'changelog-to-blog-post' ),
					'confirmRemove'     => __( 'Are you sure you want to remove this repository? This cannot be undone.', 'changelog-to-blog-post' ),
					'validating'        => __( 'Validating…', 'changelog-to-blog-post' ),
					'slugValid'         => __( 'Plugin found on WordPress.org.', 'changelog-to-blog-post' ),
					'slugNotFound'      => __( 'Plugin not found on WordPress.org. You can still save, but the WP.org download link will not be used.', 'changelog-to-blog-post' ),
					'notImplemented'    => __( 'This feature is not yet available.', 'changelog-to-blog-post' ),
					'generating'        => __( 'Generating…', 'changelog-to-blog-post' ),
					'draftCreated'      => __( 'Draft created.', 'changelog-to-blog-post' ),
					'viewDraft'         => __( 'View draft', 'changelog-to-blog-post' ),
					'conflictReplace'   => __( 'Replace existing', 'changelog-to-blog-post' ),
					'conflictAlongside' => __( 'Add alongside', 'changelog-to-blog-post' ),
					'conflictCancel'    => __( 'Cancel', 'changelog-to-blog-post' ),
					'replaceWarning'    => __( 'This will permanently delete the existing post and generate a new draft. This cannot be undone.', 'changelog-to-blog-post' ),
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
		$asset_file = CHANGELOG_TO_BLOG_POST_PATH . 'assets/js/editor/index.min.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : [ 'dependencies' => [], 'version' => CHANGELOG_TO_BLOG_POST_VERSION ];

		wp_enqueue_script(
			'changelog-to-blog-post-editor',
			CHANGELOG_TO_BLOG_POST_URL . 'assets/js/editor/index.min.js',
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
			register_post_meta( 'post', $key, [
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'string',
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			] );
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
			'/releases/resolve-conflict',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_resolve_conflict' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'repo'       => [
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'resolution' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'replace', 'alongside' ],
					],
					'post_id'    => [
						'type'    => 'integer',
						'default' => 0,
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
			'/ai/test-connection',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_test_ai_connection' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
			]
		);

		register_rest_route(
			'ctbp/v1',
			'/wporg/validate',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_validate_wporg_slug' ],
				'permission_callback' => [ $this, 'rest_permission_check' ],
				'args'                => [
					'slug' => [
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

		$this->handle_form_submission();

		include CHANGELOG_TO_BLOG_POST_PATH . 'includes/templates/admin-page.php';
	}

	/**
	 * Dispatches form submissions to the appropriate handler.
	 *
	 * @return void
	 */
	public function handle_form_submission(): void {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ctbp_action'] ) ) {
			return;
		}

		$action = sanitize_key( $_POST['ctbp_action'] );

		if ( 'repositories' === $action ) {
			check_admin_referer( 'ctbp_save_repositories', 'ctbp_nonce' );
			$this->handle_repositories_save();
		} elseif ( 'settings' === $action ) {
			check_admin_referer( 'ctbp_save_settings', 'ctbp_nonce' );
			$this->handle_settings_save();
		}
	}

	/**
	 * Handles saving the repositories form.
	 *
	 * @return void
	 */
	private function handle_repositories_save(): void {
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
					[ 'tab' => 'repositories', 'saved' => '1' ],
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

			if ( $added_identifier !== '' ) {
				$onboarding = ( new Onboarding_Handler(
					new API_Client( $this->global_settings ),
					new Release_State()
				) )->trigger( $added_identifier );

				$this->set_admin_notice( $onboarding['type'], $onboarding['message'], $onboarding['post_url'] );
			}

			wp_safe_redirect(
				add_query_arg(
					[ 'tab' => 'repositories', 'saved' => '1' ],
					$this->get_page_url()
				)
			);
			exit;
		}

		// Handle bulk "Save" of per-repo configurations.
		$posted_repos = isset( $_POST['repos'] ) && is_array( $_POST['repos'] ) ? $_POST['repos'] : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		foreach ( $posted_repos as $identifier => $config ) {
			$identifier = sanitize_text_field( wp_unslash( (string) $identifier ) );
			$raw_repo_tags = sanitize_text_field( wp_unslash( $config['tags'] ?? '' ) );
			$sanitized     = [
				'display_name' => sanitize_text_field( wp_unslash( $config['display_name'] ?? '' ) ),
				'wporg_slug'   => sanitize_text_field( wp_unslash( $config['wporg_slug'] ?? '' ) ),
				'custom_url'   => esc_url_raw( wp_unslash( $config['custom_url'] ?? '' ) ),
				'post_status'  => sanitize_key( $config['post_status'] ?? '' ),
				'category'     => absint( $config['category'] ?? 0 ),
				'tags'         => $this->resolve_tag_names_to_ids( $raw_repo_tags ),
				'paused'       => ! empty( $config['paused'] ),
			];
			$this->repo_settings->update_repository( $identifier, $sanitized );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'tab' => 'repositories', 'saved' => '1' ],
				$this->get_page_url()
			)
		);
		exit;
	}

	/**
	 * Handles saving the global settings form.
	 *
	 * @return void
	 */
	private function handle_settings_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'changelog-to-blog-post' ) );
		}

		// GitHub PAT (encrypted before storage).
		$this->global_settings->save_github_pat(
			wp_unslash( $_POST['ctbp_github_pat'] ?? '' )
		);

		// AI provider.
		$this->global_settings->save_ai_provider(
			sanitize_key( wp_unslash( $_POST['ctbp_ai_provider'] ?? '' ) )
		);

		// API keys (raw values — encrypted before storage).
		$api_keys = [
			'openai'    => wp_unslash( $_POST['ctbp_api_key_openai'] ?? '' ),
			'anthropic' => wp_unslash( $_POST['ctbp_api_key_anthropic'] ?? '' ),
		];
		$this->global_settings->save_api_keys( $api_keys );

		// Custom prompt instructions.
		$this->global_settings->save_custom_prompt_instructions(
			sanitize_textarea_field( wp_unslash( $_POST['ctbp_custom_prompt_instructions'] ?? '' ) )
		);

		// Post defaults.
		$raw_tags = sanitize_text_field( wp_unslash( $_POST['ctbp_default_tags'] ?? '' ) );
		$tag_ids  = $this->resolve_tag_names_to_ids( $raw_tags );

		$this->global_settings->save_post_defaults(
			[
				'post_status' => sanitize_key( wp_unslash( $_POST['ctbp_default_post_status'] ?? 'draft' ) ),
				'category'    => absint( $_POST['ctbp_default_category'] ?? 0 ),
				'tags'        => $tag_ids,
			]
		);

		// Notification settings.
		$notif_result = $this->global_settings->save_notification_settings(
			[
				'enabled'           => ! empty( $_POST['ctbp_notifications_enabled'] ),
				'email'             => sanitize_email( wp_unslash( $_POST['ctbp_notification_email'] ?? '' ) ),
				'email_secondary'   => sanitize_email( wp_unslash( $_POST['ctbp_notification_email_secondary'] ?? '' ) ),
				'trigger'           => sanitize_key( wp_unslash( $_POST['ctbp_notification_trigger'] ?? 'draft' ) ),
			]
		);

		if ( ! $notif_result['saved'] && ! empty( $notif_result['errors'] ) ) {
			$this->set_admin_error( implode( ' ', $notif_result['errors'] ) );
			wp_safe_redirect(
				add_query_arg( 'tab', 'settings', $this->get_page_url() )
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'tab' => 'settings', 'saved' => '1' ],
				$this->get_page_url()
			)
		);
		exit;
	}

	/**
	 * REST handler: generates a draft post for the latest release of a repository.
	 *
	 * Returns conflict data when a post already exists for the tag (BR-003),
	 * otherwise fires ctbp_process_release and returns the created post.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_generate_draft( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$identifier = $request->get_param( 'repo' );
		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( $release === null ) {
			return new \WP_Error( 'ctbp_no_release', __( 'No releases found for this repository.', 'changelog-to-blog-post' ), [ 'status' => 404 ] );
		}

		// Check for existing post — offer conflict resolution (BR-003).
		$existing = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $existing instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'conflict' => true,
					'post'     => [
						'id'       => $existing->ID,
						'title'    => $existing->post_title,
						'status'   => $existing->post_status,
						'edit_url' => get_edit_post_link( $existing->ID, 'raw' ),
					],
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
			[ 'force_draft' => true, 'manual' => true ]
		);

		$post = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $post instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'conflict' => false,
					'post'     => [
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'status'   => $post->post_status,
						'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					],
				],
				201
			);
		}

		return new \WP_Error(
			'ctbp_generation_failed',
			__( 'Draft could not be generated. Configure an AI provider in the Settings tab and try again.', 'changelog-to-blog-post' ),
			[ 'status' => 422 ]
		);
	}

	/**
	 * REST handler: resolves a duplicate-post conflict for "Generate draft now".
	 *
	 * Accepts resolution=replace (deletes existing post, then re-fires generation)
	 * or resolution=alongside (fires generation without deleting the existing post).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_resolve_conflict( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$identifier       = $request->get_param( 'repo' );
		$resolution       = $request->get_param( 'resolution' );
		$existing_post_id = (int) $request->get_param( 'post_id' );

		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			return new \WP_Error( $release->get_error_code(), $release->get_error_message(), [ 'status' => 400 ] );
		}

		if ( $release === null ) {
			return new \WP_Error( 'ctbp_no_release', __( 'No releases found for this repository.', 'changelog-to-blog-post' ), [ 'status' => 404 ] );
		}

		if ( 'replace' === $resolution && $existing_post_id > 0 ) {
			wp_delete_post( $existing_post_id, true );
		}

		/**
		 * Fires to trigger AI generation for a conflict-resolved manual draft.
		 *
		 * @param array<string, mixed> $entry   Queue entry with release data.
		 * @param array<string, mixed> $context Context flags.
		 */
		do_action(
			'ctbp_process_release',
			Release_Queue::from_release( $identifier, $release ),
			[ 'force_draft' => true, 'manual' => true ]
		);

		$post = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $post instanceof \WP_Post ) {
			return new \WP_REST_Response(
				[
					'post' => [
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'status'   => $post->post_status,
						'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					],
				],
				201
			);
		}

		return new \WP_Error(
			'ctbp_generation_failed',
			__( 'Draft could not be generated. Configure an AI provider in the Settings tab and try again.', 'changelog-to-blog-post' ),
			[ 'status' => 422 ]
		);
	}

	/**
	 * REST handler: tests the active AI provider connection.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_test_ai_connection( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$factory  = new \TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory( $this->global_settings );
		$provider = $factory->get_provider();

		if ( is_wp_error( $provider ) ) {
			return new \WP_Error( $provider->get_error_code(), $provider->get_error_message(), [ 'status' => 400 ] );
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'message' => sprintf(
					/* translators: %s: AI provider display label */
					__( 'Connection to %s successful.', 'changelog-to-blog-post' ),
					$provider->get_label()
				),
			],
			200
		);
	}

	/**
	 * REST handler: validates a WordPress.org plugin slug.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function rest_validate_wporg_slug( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->repo_settings->validate_wporg_slug( $request->get_param( 'slug' ) );
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * REST handler: regenerates the content for an existing post.
	 *
	 * Re-fetches the release from GitHub, re-runs AI generation with optional
	 * user feedback appended to the prompt, and updates the post content.
	 *
	 * @param \WP_REST_Request $request
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

		// Update the existing post.
		wp_update_post( [
			'ID'           => $post_id,
			'post_title'   => $full_title,
			'post_content' => $result->content,
		] );

		// Update the provider meta.
		update_post_meta( $post_id, Plugin_Constants::META_GENERATED_BY, $result->provider_slug );

		return new \WP_REST_Response(
			[
				'success' => true,
				'post'    => [
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
			[ 'type' => $type, 'message' => $message, 'url' => $url ],
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
