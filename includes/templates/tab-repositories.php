<?php
/**
 * Repositories tab content.
 *
 * Renders the repository management table and "Add repository" form.
 * This template is included inside the repositories form in admin-page.php.
 *
 * @package ChangelogToBlogPost
 */

// Guard: direct access not allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;
use TenUp\ChangelogToBlogPost\Plugin_Constants;

$repo_settings = new Repository_Settings();
$repos         = $repo_settings->get_repositories();
$max_repos     = (int) apply_filters( 'ctbp_max_repositories', Repository_Settings::MAX_REPOSITORIES );
$at_limit      = count( $repos ) >= $max_repos;
?>

<?php if ( $at_limit ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %d: maximum number of repositories */
				esc_html__( 'You have reached the maximum of %d tracked repositories.', 'changelog-to-blog-post' ),
				esc_html( $max_repos )
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php if ( empty( $repos ) ) : ?>
	<p><?php echo esc_html__( 'No repositories are being tracked yet. Add one below.', 'changelog-to-blog-post' ); ?></p>
<?php else : ?>
	<table class="ctbp-repo-table widefat striped">
		<thead>
			<tr>
				<th scope="col"><?php echo esc_html__( 'Display Name', 'changelog-to-blog-post' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Repository', 'changelog-to-blog-post' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Status', 'changelog-to-blog-post' ); ?></th>
				<th scope="col"><?php echo esc_html__( 'Actions', 'changelog-to-blog-post' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $repos as $i => $repo ) : ?>
				<?php
				$identifier   = $repo['identifier'] ?? '';
				$display_name = $repo['display_name'] ?? $identifier;
				$paused       = ! empty( $repo['paused'] );
				$wporg_slug   = $repo['wporg_slug'] ?? '';
				$custom_url   = $repo['custom_url'] ?? '';
				$post_status  = $repo['post_status'] ?? '';
				$category     = (int) ( $repo['category'] ?? 0 );
				$tags         = (array) ( $repo['tags'] ?? [] );
				$row_id       = 'ctbp-repo-' . $i;
				$edit_row_id  = 'ctbp-repo-edit-' . $i;
				?>
				<tr id="<?php echo esc_attr( $row_id ); ?>">
					<td><?php echo esc_html( $display_name ); ?></td>
					<td><code><?php echo esc_html( $identifier ); ?></code></td>
					<td>
						<?php if ( $paused ) : ?>
							<span class="ctbp-status-badge ctbp-status-paused" aria-label="<?php echo esc_attr__( 'Paused', 'changelog-to-blog-post' ); ?>">
								<?php echo esc_html__( 'Paused', 'changelog-to-blog-post' ); ?>
							</span>
						<?php else : ?>
							<span class="ctbp-status-badge ctbp-status-active" aria-label="<?php echo esc_attr__( 'Active', 'changelog-to-blog-post' ); ?>">
								<?php echo esc_html__( 'Active', 'changelog-to-blog-post' ); ?>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<button
							type="button"
							class="button button-small ctbp-edit-repo-btn"
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $edit_row_id ); ?>"
						>
							<?php echo esc_html__( 'Edit', 'changelog-to-blog-post' ); ?>
						</button>
						<button
							type="button"
							class="button button-small button-link-delete ctbp-remove-repo-btn"
							data-repo="<?php echo esc_attr( $identifier ); ?>"
						>
							<?php echo esc_html__( 'Remove', 'changelog-to-blog-post' ); ?>
						</button>
						<button
							type="button"
							class="button button-small ctbp-generate-draft"
							data-repo="<?php echo esc_attr( $identifier ); ?>"
						>
							<?php echo esc_html__( 'Generate draft now', 'changelog-to-blog-post' ); ?>
						</button>
					</td>
				</tr>
				<tr id="<?php echo esc_attr( $edit_row_id ); ?>" class="ctbp-repo-edit-row" hidden>
					<td colspan="4">
						<fieldset>
							<legend class="screen-reader-text">
								<?php
								printf(
									/* translators: %s: repository identifier */
									esc_html__( 'Settings for %s', 'changelog-to-blog-post' ),
									esc_html( $identifier )
								);
								?>
							</legend>

							<p>
								<label for="ctbp-display-name-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'Display Name', 'changelog-to-blog-post' ); ?>
								</label><br>
								<input
									type="text"
									id="ctbp-display-name-<?php echo esc_attr( $i ); ?>"
									name="repos[<?php echo esc_attr( $identifier ); ?>][display_name]"
									value="<?php echo esc_attr( $display_name ); ?>"
									class="regular-text"
								>
							</p>

							<p>
								<label for="ctbp-wporg-slug-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'WordPress.org Plugin Slug', 'changelog-to-blog-post' ); ?>
								</label><br>
								<input
									type="text"
									id="ctbp-wporg-slug-<?php echo esc_attr( $i ); ?>"
									name="repos[<?php echo esc_attr( $identifier ); ?>][wporg_slug]"
									value="<?php echo esc_attr( $wporg_slug ); ?>"
									class="regular-text ctbp-wporg-slug-input"
								>
								<button type="button" class="button button-small ctbp-validate-slug">
									<?php echo esc_html__( 'Validate', 'changelog-to-blog-post' ); ?>
								</button>
								<span class="ctbp-slug-validation-result" aria-live="polite"></span>
							</p>

							<p>
								<label for="ctbp-custom-url-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'Custom Download URL', 'changelog-to-blog-post' ); ?>
									<span class="description"><?php echo esc_html__( '(Overrides WP.org link)', 'changelog-to-blog-post' ); ?></span>
								</label><br>
								<input
									type="url"
									id="ctbp-custom-url-<?php echo esc_attr( $i ); ?>"
									name="repos[<?php echo esc_attr( $identifier ); ?>][custom_url]"
									value="<?php echo esc_attr( $custom_url ); ?>"
									class="regular-text"
								>
							</p>

							<p>
								<label for="ctbp-post-status-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'Default Post Status', 'changelog-to-blog-post' ); ?>
								</label><br>
								<select
									id="ctbp-post-status-<?php echo esc_attr( $i ); ?>"
									name="repos[<?php echo esc_attr( $identifier ); ?>][post_status]"
								>
									<option value="" <?php selected( $post_status, '' ); ?>><?php echo esc_html__( 'Use global default', 'changelog-to-blog-post' ); ?></option>
									<option value="draft" <?php selected( $post_status, 'draft' ); ?>><?php echo esc_html__( 'Draft', 'changelog-to-blog-post' ); ?></option>
									<option value="publish" <?php selected( $post_status, 'publish' ); ?>><?php echo esc_html__( 'Publish', 'changelog-to-blog-post' ); ?></option>
								</select>
							</p>

							<p>
								<label for="ctbp-category-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'Default Category', 'changelog-to-blog-post' ); ?>
								</label><br>
								<?php
								wp_dropdown_categories(
									[
										'name'             => 'repos[' . esc_attr( $identifier ) . '][category]',
										'id'               => 'ctbp-category-' . esc_attr( $i ),
										'selected'         => $category,
										'show_option_none' => __( 'Use global default', 'changelog-to-blog-post' ),
										'option_none_value' => '0',
										'hide_empty'       => false,
									]
								);
								?>
							</p>

							<p>
								<label for="ctbp-tags-<?php echo esc_attr( $i ); ?>">
									<?php echo esc_html__( 'Default Tags', 'changelog-to-blog-post' ); ?>
									<span class="description"><?php echo esc_html__( '(comma-separated)', 'changelog-to-blog-post' ); ?></span>
								</label><br>
								<input
									type="text"
									id="ctbp-tags-<?php echo esc_attr( $i ); ?>"
									name="repos[<?php echo esc_attr( $identifier ); ?>][tags]"
									value="<?php echo esc_attr( \TenUp\ChangelogToBlogPost\Admin\Admin_Page::tag_ids_to_names( $tags ) ); ?>"
									class="regular-text"
								>
							</p>

							<p>
								<label>
									<input
										type="checkbox"
										name="repos[<?php echo esc_attr( $identifier ); ?>][paused]"
										value="1"
										<?php checked( $paused ); ?>
									>
									<?php echo esc_html__( 'Pause monitoring for this repository', 'changelog-to-blog-post' ); ?>
								</label>
							</p>
						</fieldset>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( ! $at_limit ) : ?>
	<h3><?php echo esc_html__( 'Add Repository', 'changelog-to-blog-post' ); ?></h3>
	<p>
		<label for="ctbp-new-repo">
			<?php echo esc_html__( 'GitHub repository', 'changelog-to-blog-post' ); ?>
			<span class="description"><?php echo esc_html__( '(owner/repo or full GitHub URL)', 'changelog-to-blog-post' ); ?></span>
		</label><br>
		<input
			type="text"
			id="ctbp-new-repo"
			name="ctbp_new_repo"
			value=""
			placeholder="owner/repo"
			class="regular-text"
		>
		<button type="submit" name="ctbp_add_repo" class="button">
			<?php echo esc_html__( 'Add Repository', 'changelog-to-blog-post' ); ?>
		</button>
	</p>
<?php endif; ?>

<!-- Conflict resolution dialog for "Generate draft now" (JS-driven) -->
<dialog id="ctbp-conflict-dialog" aria-labelledby="ctbp-conflict-dialog-title">
	<p id="ctbp-conflict-dialog-title">
		<?php echo esc_html__( 'A post already exists for this release.', 'changelog-to-blog-post' ); ?>
	</p>
	<p id="ctbp-conflict-post-info"></p>
	<p class="ctbp-conflict-replace-warning">
		<?php echo esc_html__( 'This will permanently delete the existing post and generate a new draft. This cannot be undone.', 'changelog-to-blog-post' ); ?>
	</p>
	<div class="ctbp-conflict-actions">
		<button type="button" id="ctbp-conflict-replace" class="button button-primary button-link-delete">
			<?php echo esc_html__( 'Replace existing', 'changelog-to-blog-post' ); ?>
		</button>
		<button type="button" id="ctbp-conflict-alongside" class="button">
			<?php echo esc_html__( 'Add alongside', 'changelog-to-blog-post' ); ?>
		</button>
		<button type="button" id="ctbp-conflict-cancel" class="button button-link">
			<?php echo esc_html__( 'Cancel', 'changelog-to-blog-post' ); ?>
		</button>
	</div>
</dialog>

<!-- Confirmation dialog for repo removal (JS-driven) -->
<dialog id="ctbp-remove-dialog" aria-labelledby="ctbp-remove-dialog-title">
	<p id="ctbp-remove-dialog-title">
		<?php echo esc_html__( 'Are you sure you want to remove this repository?', 'changelog-to-blog-post' ); ?>
	</p>
	<p><?php echo esc_html__( 'Previously generated posts will not be deleted.', 'changelog-to-blog-post' ); ?></p>
	<form method="post">
		<?php wp_nonce_field( 'ctbp_save_repositories', 'ctbp_nonce' ); ?>
		<input type="hidden" name="ctbp_action" value="repositories">
		<input type="hidden" id="ctbp-remove-repo-input" name="ctbp_remove_repo" value="">
		<button type="submit" class="button button-primary button-link-delete">
			<?php echo esc_html__( 'Remove', 'changelog-to-blog-post' ); ?>
		</button>
		<button type="button" id="ctbp-remove-cancel" class="button">
			<?php echo esc_html__( 'Cancel', 'changelog-to-blog-post' ); ?>
		</button>
	</form>
</dialog>
