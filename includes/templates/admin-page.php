<?php
/**
 * Admin settings page shell.
 *
 * Renders the outer page wrapper, tab navigation, admin notices, and the
 * two tab panel forms. Tab content is provided by the tab-specific templates.
 *
 * @package ChangelogToBlogPost
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
$error_message = get_transient( 'ctbp_admin_errors_' . $current_user_id );
if ( $error_message ) {
	delete_transient( 'ctbp_admin_errors_' . $current_user_id );
}

// Typed notice transient (success/warning from onboarding, etc.).
$admin_notice = get_transient( 'ctbp_admin_notice_' . $current_user_id );
if ( $admin_notice ) {
	delete_transient( 'ctbp_admin_notice_' . $current_user_id );
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'GitHub Release Posts', 'changelog-to-blog-post' ); ?></h1>
	<p class="description"><?php echo esc_html__( 'Monitor GitHub repositories for new releases and automatically generate blog posts using AI.', 'changelog-to-blog-post' ); ?></p>

	<?php if ( $show_saved || $settings_updated ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'changelog-to-blog-post' ); ?></p>
		</div>
	<?php endif; ?>

	<?php
	// Display Settings API validation errors (e.g. invalid email).
	if ( 'settings' === $active_tab ) {
		settings_errors( \TenUp\ChangelogToBlogPost\Admin\Settings_Page::OPTION_GROUP );
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
					&nbsp;<a href="<?php echo esc_url( $admin_notice['url'] ); ?>"><?php echo esc_html__( 'View draft', 'changelog-to-blog-post' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php $block_editor_active = \TenUp\ChangelogToBlogPost\Admin\Admin_Page::is_block_editor_active(); ?>

	<?php if ( ! $block_editor_active ) : ?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Block editor required.', 'changelog-to-blog-post' ); ?></strong>
				<?php echo esc_html__( 'This plugin generates posts using the block editor. Post generation is disabled while the Classic Editor is active for posts.', 'changelog-to-blog-post' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	$onboarding_repos = new \TenUp\ChangelogToBlogPost\Settings\Repository_Settings();
	$has_repos        = ! empty( $onboarding_repos->get_repositories() );

	if ( ! $has_repos ) :
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php echo esc_html__( 'Getting started:', 'changelog-to-blog-post' ); ?></strong>
				<?php echo esc_html__( 'Add your first GitHub repository below to start monitoring for releases.', 'changelog-to-blog-post' ); ?>
			</p>
		</div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" role="tablist">
		<a
			href="<?php echo esc_url( add_query_arg( 'tab', 'repositories', admin_url( 'tools.php?page=changelog-to-blog-post' ) ) ); ?>"
			role="tab"
			id="ctbp-tab-repositories"
			aria-controls="ctbp-panel-repositories"
			aria-selected="<?php echo 'repositories' === $active_tab ? 'true' : 'false'; ?>"
			class="nav-tab<?php echo 'repositories' === $active_tab ? ' nav-tab-active' : ''; ?>"
		>
			<?php echo esc_html__( 'Repositories', 'changelog-to-blog-post' ); ?>
		</a>
		<a
			href="<?php echo esc_url( add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=changelog-to-blog-post' ) ) ); ?>"
			role="tab"
			id="ctbp-tab-settings"
			aria-controls="ctbp-panel-settings"
			aria-selected="<?php echo 'settings' === $active_tab ? 'true' : 'false'; ?>"
			class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>"
		>
			<?php echo esc_html__( 'Settings', 'changelog-to-blog-post' ); ?>
		</a>
	</nav>

	<div
		id="ctbp-panel-repositories"
		role="tabpanel"
		aria-labelledby="ctbp-tab-repositories"
		class="ctbp-tab-panel"
		<?php echo 'repositories' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=changelog-to-blog-post&tab=repositories' ) ); ?>" novalidate>
			<?php wp_nonce_field( 'ctbp_save_repositories', 'ctbp_nonce' ); ?>
			<input type="hidden" name="ctbp_action" value="repositories">

			<?php require __DIR__ . '/tab-repositories.php'; ?>
		</form>

		<!-- Conflict resolution dialog for "Generate draft now" (JS-driven) -->
		<!-- Confirmation dialog for regenerating an existing post (JS-driven) -->
		<dialog id="ctbp-conflict-dialog" class="ctbp-dialog" aria-labelledby="ctbp-conflict-dialog-title">
			<p id="ctbp-conflict-dialog-title">
				<strong><?php echo esc_html__( 'A post already exists for this release.', 'changelog-to-blog-post' ); ?></strong>
			</p>
			<p id="ctbp-conflict-post-info"></p>
			<p class="description">
				<?php echo esc_html__( 'This will update the post with freshly generated content. The current version will be saved as a revision.', 'changelog-to-blog-post' ); ?>
			</p>
			<div class="ctbp-dialog-actions">
				<button type="button" id="ctbp-conflict-confirm" class="button button-primary">
					<?php echo esc_html__( 'Regenerate', 'changelog-to-blog-post' ); ?>
				</button>
				<button type="button" id="ctbp-conflict-cancel" class="button">
					<?php echo esc_html__( 'Cancel', 'changelog-to-blog-post' ); ?>
				</button>
			</div>
		</dialog>

		<!-- Confirmation dialog for repo removal (JS-driven) -->
		<dialog id="ctbp-remove-dialog" class="ctbp-dialog" aria-labelledby="ctbp-remove-dialog-title">
			<p id="ctbp-remove-dialog-title">
				<strong><?php echo esc_html__( 'Are you sure you want to remove this repository?', 'changelog-to-blog-post' ); ?></strong>
			</p>
			<p class="description"><?php echo esc_html__( 'Previously generated posts will not be deleted.', 'changelog-to-blog-post' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( 'ctbp_save_repositories', 'ctbp_nonce' ); ?>
				<input type="hidden" name="ctbp_action" value="repositories">
				<input type="hidden" id="ctbp-remove-repo-input" name="ctbp_remove_repo" value="">
				<div class="ctbp-dialog-actions">
					<button type="submit" class="button button-link-delete">
						<?php echo esc_html__( 'Remove', 'changelog-to-blog-post' ); ?>
					</button>
					<button type="button" id="ctbp-remove-cancel" class="button">
						<?php echo esc_html__( 'Cancel', 'changelog-to-blog-post' ); ?>
					</button>
				</div>
			</form>
		</dialog>
	</div>

	<div
		id="ctbp-panel-settings"
		role="tabpanel"
		aria-labelledby="ctbp-tab-settings"
		class="ctbp-tab-panel"
		<?php echo 'settings' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
			<?php require __DIR__ . '/tab-settings.php'; ?>

			<?php submit_button( __( 'Save Settings', 'changelog-to-blog-post' ) ); ?>
		</form>
	</div>
</div>
