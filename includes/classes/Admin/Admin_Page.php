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
		add_action( 'init', [ $this, 'register_ajax_actions' ] );
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
			[ 'jquery' ],
			CHANGELOG_TO_BLOG_POST_VERSION,
			true
		);

		wp_localize_script(
			'changelog-to-blog-post-admin-js',
			'ctbpAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ctbp_admin_nonce' ),
				'i18n'    => [
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
	 * Registers AJAX action handlers.
	 *
	 * @return void
	 */
	public function register_ajax_actions(): void {
		add_action( 'wp_ajax_ctbp_generate_draft_now', [ $this, 'ajax_generate_draft_now' ] );
		add_action( 'wp_ajax_ctbp_resolve_conflict', [ $this, 'ajax_resolve_conflict' ] );
		add_action( 'wp_ajax_ctbp_test_ai_connection', [ $this, 'ajax_test_ai_connection' ] );
		add_action( 'wp_ajax_ctbp_validate_wporg_slug', [ $this, 'ajax_validate_wporg_slug' ] );
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
			$sanitized  = [
				'display_name' => sanitize_text_field( wp_unslash( $config['display_name'] ?? '' ) ),
				'wporg_slug'   => sanitize_text_field( wp_unslash( $config['wporg_slug'] ?? '' ) ),
				'custom_url'   => esc_url_raw( wp_unslash( $config['custom_url'] ?? '' ) ),
				'post_status'  => sanitize_key( $config['post_status'] ?? '' ),
				'category'     => absint( $config['category'] ?? 0 ),
				'tags'         => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $config['tags'] ?? [] ) ) ),
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
			'gemini'    => wp_unslash( $_POST['ctbp_api_key_gemini'] ?? '' ),
		];
		$this->global_settings->save_api_keys( $api_keys );

		// Post defaults.
		$this->global_settings->save_post_defaults(
			[
				'post_status' => sanitize_key( wp_unslash( $_POST['ctbp_default_post_status'] ?? 'draft' ) ),
				'category'    => absint( $_POST['ctbp_default_category'] ?? 0 ),
				'tags'        => array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) ( $_POST['ctbp_default_tags'] ?? [] ) ) ),
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
	 * AJAX handler: generates a draft post for the latest release of a repository.
	 *
	 * Returns conflict data when a post already exists for the tag (BR-003),
	 * otherwise fires ctbp_process_release and returns the result.
	 *
	 * @return void
	 */
	public function ajax_generate_draft_now(): void {
		check_ajax_referer( 'ctbp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'changelog-to-blog-post' ) ], 403 );
		}

		$identifier = sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) );

		if ( $identifier === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing repository identifier.', 'changelog-to-blog-post' ) ] );
		}

		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			wp_send_json_error( [ 'message' => $release->get_error_message() ] );
		}

		if ( $release === null ) {
			wp_send_json_error( [ 'message' => __( 'No releases found for this repository.', 'changelog-to-blog-post' ) ] );
		}

		// Check for existing post — offer conflict resolution (BR-003, AC-020, AC-021).
		$existing = Release_Monitor::find_post( $identifier, $release->tag );

		if ( $existing instanceof \WP_Post ) {
			wp_send_json_success(
				[
					'conflict' => true,
					'post'     => [
						'id'       => $existing->ID,
						'title'    => $existing->post_title,
						'status'   => $existing->post_status,
						'edit_url' => get_edit_post_link( $existing->ID, 'raw' ),
					],
				]
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
			wp_send_json_success(
				[
					'conflict' => false,
					'post'     => [
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'status'   => $post->post_status,
						'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					],
				]
			);
		}

		// DOM-05/06 not yet implemented — no post was created.
		wp_send_json_error( [ 'message' => __( 'Draft could not be generated. Configure an AI provider in the Settings tab and try again.', 'changelog-to-blog-post' ) ] );
	}

	/**
	 * AJAX handler: resolves a duplicate-post conflict for "Generate draft now".
	 *
	 * Accepts action=replace (deletes existing post, then re-fires generation)
	 * or action=alongside (fires generation without deleting the existing post).
	 * cancel is handled client-side and never reaches this handler.
	 *
	 * @return void
	 */
	public function ajax_resolve_conflict(): void {
		check_ajax_referer( 'ctbp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'changelog-to-blog-post' ) ], 403 );
		}

		$identifier     = sanitize_text_field( wp_unslash( $_POST['repo'] ?? '' ) );
		$resolution     = sanitize_key( wp_unslash( $_POST['resolution'] ?? '' ) );
		$existing_post_id = absint( $_POST['post_id'] ?? 0 );

		if ( $identifier === '' || ! in_array( $resolution, [ 'replace', 'alongside' ], true ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'changelog-to-blog-post' ) ] );
		}

		$api_client = new API_Client( $this->global_settings );
		$release    = $api_client->fetch_latest_release( $identifier );

		if ( is_wp_error( $release ) ) {
			wp_send_json_error( [ 'message' => $release->get_error_message() ] );
		}

		if ( $release === null ) {
			wp_send_json_error( [ 'message' => __( 'No releases found for this repository.', 'changelog-to-blog-post' ) ] );
		}

		if ( 'replace' === $resolution && $existing_post_id > 0 ) {
			// Permanently delete the existing post before generating a new one (AC-022).
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
			wp_send_json_success(
				[
					'post' => [
						'id'       => $post->ID,
						'title'    => $post->post_title,
						'status'   => $post->post_status,
						'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
					],
				]
			);
		}

		wp_send_json_error( [ 'message' => __( 'Draft could not be generated. Configure an AI provider in the Settings tab and try again.', 'changelog-to-blog-post' ) ] );
	}

	/**
	 * AJAX handler: tests the active AI provider connection.
	 *
	 * Returns JSON success with a confirmation message, or JSON error with
	 * a diagnostic message the site owner can act on.
	 *
	 * @return void
	 */
	public function ajax_test_ai_connection(): void {
		check_ajax_referer( 'ctbp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'changelog-to-blog-post' ) ], 403 );
		}

		$factory  = new \TenUp\ChangelogToBlogPost\AI\AI_Provider_Factory( $this->global_settings );
		$provider = $factory->get_provider();

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( [ 'message' => $provider->get_error_message() ] );
		}

		$result = $provider->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [
			'message' => sprintf(
				/* translators: %s: AI provider display label */
				__( 'Connection to %s successful.', 'changelog-to-blog-post' ),
				$provider->get_label()
			),
		] );
	}

	/**
	 * AJAX handler: validates a WordPress.org plugin slug.
	 *
	 * @return void
	 */
	public function ajax_validate_wporg_slug(): void {
		check_ajax_referer( 'ctbp_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'changelog-to-blog-post' ) ], 403 );
		}

		$slug   = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		$result = $this->repo_settings->validate_wporg_slug( $slug );

		wp_send_json_success( $result );
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
}
