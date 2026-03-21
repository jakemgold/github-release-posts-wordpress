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

$allowed_tabs = [ 'repositories', 'settings' ];
$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'repositories'; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
	$active_tab = 'repositories';
}

$current_user_id = get_current_user_id();

// Success notice.
$show_saved = isset( $_GET['saved'] ) && '1' === $_GET['saved']; // phpcs:ignore WordPress.Security.NonceVerification

// Error transient.
$error_message = get_transient( 'ctbp_admin_errors_' . $current_user_id );
if ( $error_message ) {
	delete_transient( 'ctbp_admin_errors_' . $current_user_id );
}
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Changelog to Blog Post', 'changelog-to-blog-post' ); ?></h1>

	<?php if ( $show_saved ) : ?>
		<div class="notice notice-success is-dismissible">
			<p><?php echo esc_html__( 'Settings saved.', 'changelog-to-blog-post' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $error_message ) : ?>
		<div class="notice notice-error is-dismissible">
			<p><?php echo esc_html( $error_message ); ?></p>
		</div>
	<?php endif; ?>

	<nav>
		<ul role="tablist" class="ctbp-tabs nav-tab-wrapper">
			<li>
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
			</li>
			<li>
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
			</li>
		</ul>
	</nav>

	<div
		id="ctbp-panel-repositories"
		role="tabpanel"
		aria-labelledby="ctbp-tab-repositories"
		class="ctbp-tab-panel"
		<?php echo 'repositories' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="">
			<?php wp_nonce_field( 'ctbp_save_repositories', 'ctbp_nonce' ); ?>
			<input type="hidden" name="ctbp_action" value="repositories">

			<?php include __DIR__ . '/tab-repositories.php'; ?>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php echo esc_html__( 'Save Repositories', 'changelog-to-blog-post' ); ?>
				</button>
			</p>
		</form>
	</div>

	<div
		id="ctbp-panel-settings"
		role="tabpanel"
		aria-labelledby="ctbp-tab-settings"
		class="ctbp-tab-panel"
		<?php echo 'settings' !== $active_tab ? 'hidden' : ''; ?>
	>
		<form method="post" action="">
			<?php wp_nonce_field( 'ctbp_save_settings', 'ctbp_nonce' ); ?>
			<input type="hidden" name="ctbp_action" value="settings">

			<?php include __DIR__ . '/tab-settings.php'; ?>

			<p class="submit">
				<button type="submit" class="button button-primary">
					<?php echo esc_html__( 'Save Settings', 'changelog-to-blog-post' ); ?>
				</button>
			</p>
		</form>
	</div>
</div>
