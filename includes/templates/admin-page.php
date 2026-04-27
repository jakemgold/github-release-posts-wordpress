<?php
/**
 * Admin settings page shell.
 *
 * Renders the outer page wrapper, tab navigation, admin notices, and the
 * two tab panel forms. Tab content is provided by the tab-specific templates.
 *
 * @package GitHubReleasePosts
 */

// Guard: direct access not allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template included from Admin_Page::render_page().

$allowed_tabs = [ 'repositories', 'settings' ];
$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'repositories'; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'repositories';
}

$current_user_id = get_current_user_id();

// Success notice (repositories tab uses ?saved=1, settings tab uses ?settings-updated from Settings API).
$show_saved       = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore WordPress.Security.NonceVerification
$settings_updated = 'settings' === $active_tab && isset( $_GET['settings-updated'] ); // phpcs:ignore WordPress.Security.NonceVerification

// Error transient.
$error_message = get_transient( 'ghrp_admin_errors_' . $current_user_id );
if ( $error_message ) {
	delete_transient( 'ghrp_admin_errors_' . $current_user_id );
}

// Typed notice transient (success/warning from onboarding, etc.).
$admin_notice = get_transient( 'ghrp_admin_notice_' . $current_user_id );
if ( $admin_notice ) {
	delete_transient( 'ghrp_admin_notice_' . $current_user_id );
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'GitHub Release Posts', 'github-release-posts' ); ?></h1>
	<p class="description"><?php echo esc_html__( 'Monitor GitHub repositories for new releases and automatically generate blog posts using AI.', 'github-release-posts' ); ?></p>

	<?php if ( $show_saved || $settings_updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'github-release-posts' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// Display Settings API validation errors (e.g. invalid email).
	if ( 'settings' === $active_tab ) {
		settings_errors( \Jakemgold\GitHubReleasePosts\Admin\Settings_Page::OPTION_GROUP );
	}
	?>

	<?php if ( $error_message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $error_message ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $admin_notice && ! empty( $admin_notice['message'] ) ) : ?>
		<?php
		$notice_class = 'notice-info';
		if ( 'success' === $admin_notice['type'] ) {
			$notice_class = 'notice-success';
		} elseif ( 'warning' === $admin_notice['type'] || 'error' === $admin_notice['type'] ) {
			$notice_class = 'notice-warning';
		}
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible">
			<p>
				<?php echo esc_html( $admin_notice['message'] ); ?>
				<?php if ( ! empty( $admin_notice['url'] ) ) : ?>
					&nbsp;<a href="<?php echo esc_url( $admin_notice['url'] ); ?>"><?php echo esc_html__( 'View draft', 'github-release-posts' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php $block_editor_active = \Jakemgold\GitHubReleasePosts\Admin\Admin_Page::is_block_editor_active(); ?>

	<?php if ( ! $block_editor_active ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Block editor required.', 'github-release-posts' ); ?></strong>
				<?php echo esc_html__( 'This plugin generates posts using the block editor. Post generation is disabled while the Classic Editor is active for posts.', 'github-release-posts' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( ! \Jakemgold\GitHubReleasePosts\Admin\Settings_Page::is_any_connector_configured() ) : ?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'No AI connector is configured.', 'github-release-posts' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to the WordPress Connectors settings page */
					wp_kses( __( 'Post generation is disabled until at least one AI connector is set up and ready in %s.', 'github-release-posts' ), [ 'a' => [ 'href' => [] ] ] ),
					'<a href="' . esc_url( admin_url( 'options-connectors.php' ) ) . '">' . esc_html__( 'WordPress Connectors', 'github-release-posts' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	$onboarding_repos = new \Jakemgold\GitHubReleasePosts\Settings\Repository_Settings();
	$has_repos        = ! empty( $onboarding_repos->get_repositories() );

	if ( ! $has_repos ) :
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html__( 'Getting started:', 'github-release-posts' ); ?></strong>
				<?php echo esc_html__( 'Add your first GitHub repository below to start monitoring for releases.', 'github-release-posts' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" role="tablist">
		<a
			href="<?php echo esc_url( add_query_arg( 'tab', 'repositories', admin_url( 'tools.php?page=github-release-posts' ) ) ); ?>"
			role="tab"
			id="ghrp-tab-repositories"
			aria-controls="ghrp-panel-repositories"
			aria-selected="<?php echo 'repositories' === $active_tab ? 'true' : 'false'; ?>"
			class="nav-tab<?php echo 'repositories' === $active_tab ? ' nav-tab-active' : ''; ?>"
		>
			<?php echo esc_html__( 'Repositories', 'github-release-posts' ); ?>
		</a>
		<a
			href="<?php echo esc_url( add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=github-release-posts' ) ) ); ?>"
			role="tab"
			id="ghrp-tab-settings"
			aria-controls="ghrp-panel-settings"
			aria-selected="<?php echo 'settings' === $active_tab ? 'true' : 'false'; ?>"
			class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>"
		>
			<?php echo esc_html__( 'Settings', 'github-release-posts' ); ?>
		</a>
	</nav>

	<div
		id="ghrp-panel-repositories"
		role="tabpanel"
		aria-labelledby="ghrp-tab-repositories"
		class="ghrp-tab-panel"
		<?php echo 'repositories' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=github-release-posts&tab=repositories' ) ); ?>" novalidate>
			<?php wp_nonce_field( 'ghrp_save_repositories', 'ghrp_nonce' ); ?>
			<input type="hidden" name="ghrp_action" value="repositories">

			<?php require __DIR__ . '/tab-repositories.php'; ?>
		</form>

		<!-- Confirmation dialog for regenerating an existing post (JS-driven) -->
		<dialog id="ghrp-conflict-dialog" class="ghrp-dialog" aria-labelledby="ghrp-conflict-dialog-title">
			<p id="ghrp-conflict-dialog-title">
				<strong><?php echo esc_html__( 'A post already exists for this release.', 'github-release-posts' ); ?></strong>
			</p>
			<p id="ghrp-conflict-post-info"></p>
			<p class="description">
				<?php echo esc_html__( 'This will update the post with freshly generated content. The current version will be saved as a revision.', 'github-release-posts' ); ?>
			</p>
			<div class="ghrp-dialog-actions">
				<button type="button" id="ghrp-conflict-confirm" class="button button-primary">
					<?php echo esc_html__( 'Regenerate', 'github-release-posts' ); ?>
				</button>
				<button type="button" id="ghrp-conflict-cancel" class="button">
					<?php echo esc_html__( 'Cancel', 'github-release-posts' ); ?>
				</button>
			</div>
		</dialog>

		<!-- Version picker dialog for "Generate post" when a repo has multiple releases (JS-driven) -->
		<dialog id="ghrp-version-picker-dialog" class="ghrp-dialog" aria-labelledby="ghrp-version-picker-title">
			<p id="ghrp-version-picker-title">
				<strong><?php echo esc_html__( 'Generate a post for which release?', 'github-release-posts' ); ?></strong>
			</p>
			<p>
				<label for="ghrp-version-picker-select">
					<?php echo esc_html__( 'Release version', 'github-release-posts' ); ?>
				</label>
				<br>
				<select id="ghrp-version-picker-select" class="regular-text"></select>
			</p>
			<p id="ghrp-version-picker-conflict" class="ghrp-version-picker-conflict" hidden>
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<span id="ghrp-version-picker-conflict-text"></span>
			</p>
			<p id="ghrp-version-picker-backdate" class="description" hidden>
				<?php echo esc_html__( 'Because this is an older release, the post date will be set to one hour after the release was published. You can adjust it before publishing.', 'github-release-posts' ); ?>
			</p>
			<div class="ghrp-dialog-actions">
				<button type="button" id="ghrp-version-picker-confirm" class="button button-primary">
					<?php echo esc_html__( 'Generate post', 'github-release-posts' ); ?>
				</button>
				<button type="button" id="ghrp-version-picker-cancel" class="button">
					<?php echo esc_html__( 'Cancel', 'github-release-posts' ); ?>
				</button>
			</div>
		</dialog>

		<!-- Confirmation dialog for repo removal (JS-driven) -->
		<dialog id="ghrp-remove-dialog" class="ghrp-dialog" aria-labelledby="ghrp-remove-dialog-title">
			<p id="ghrp-remove-dialog-title">
				<strong><?php echo esc_html__( 'Are you sure you want to remove this repository?', 'github-release-posts' ); ?></strong>
			</p>
			<p class="description"><?php echo esc_html__( 'Previously generated posts will not be deleted.', 'github-release-posts' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'ghrp_save_repositories', 'ghrp_nonce' ); ?>
				<input type="hidden" name="ghrp_action" value="repositories">
				<input type="hidden" id="ghrp-remove-repo-input" name="ghrp_remove_repo" value="">
				<div class="ghrp-dialog-actions">
					<button type="submit" class="button button-link-delete">
						<?php echo esc_html__( 'Remove', 'github-release-posts' ); ?>
					</button>
					<button type="button" id="ghrp-remove-cancel" class="button">
						<?php echo esc_html__( 'Cancel', 'github-release-posts' ); ?>
					</button>
				</div>
			</form>
		</dialog>
	</div>

	<div
		id="ghrp-panel-settings"
		role="tabpanel"
		aria-labelledby="ghrp-tab-settings"
		class="ghrp-tab-panel"
		<?php echo 'settings' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php require __DIR__ . '/tab-settings.php'; ?>

			<?php submit_button( __( 'Save Settings', 'github-release-posts' ) ); ?>
		</form>
	</div>
</div>
