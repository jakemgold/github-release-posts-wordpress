<?php
/**
 * Repositories tab content.
 *
 * Renders the repository list table (via WP_List_Table) and the
 * "Add repository" form. Included inside the repositories form
 * in admin-page.php.
 *
 * @package GitHubReleasePosts
 */

// Guard: direct access not allowed.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template included from admin-page.php.

use GitHubReleasePosts\Admin\Repository_List_Table;
use GitHubReleasePosts\GitHub\API_Client;
use GitHubReleasePosts\Settings\Global_Settings;
use GitHubReleasePosts\Settings\Repository_Settings;

$repo_settings = new Repository_Settings();
$repos         = $repo_settings->get_repositories();
$max_repos     = (int) apply_filters( 'ghrp_max_repositories', Repository_Settings::MAX_REPOSITORIES );
$at_limit      = count( $repos ) >= $max_repos;

// Inject the array index into each repo so the list table can generate
// unique IDs for edit rows.
foreach ( $repos as $i => $repo ) {
	$repos[ $i ]['_index'] = $i;
}

// Determine whether a PAT is configured and, if so, fetch the
// repository list available to it.
$ghrp_global_settings = new Global_Settings();
$ghrp_pat_configured  = 'none' !== $ghrp_global_settings->get_github_pat_source();
$ghrp_repo_list       = null;
$ghrp_repo_list_error = '';
$ghrp_pat_validated   = false;

if ( $ghrp_pat_configured ) {
	$ghrp_result = ( new API_Client( $ghrp_global_settings ) )->list_accessible_repos();

	if ( is_wp_error( $ghrp_result ) ) {
		$ghrp_repo_list_error = $ghrp_result->get_error_message();
	}

	if ( is_array( $ghrp_result ) ) {
		$ghrp_pat_validated = true;
		// Filter out repositories that are already being tracked.
		$ghrp_tracked   = array_column( $repos, 'identifier' );
		$ghrp_repo_list = array_values(
			array_filter(
				$ghrp_result,
				static function ( $r ) use ( $ghrp_tracked ) {
					return ! in_array( $r['identifier'], $ghrp_tracked, true );
				}
			)
		);
	}
}
?>

<?php if ( $at_limit ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			printf(
				/* translators: %d: maximum number of repositories */
				esc_html__( 'You have reached the maximum of %d tracked repositories.', 'github-release-posts' ),
				esc_html( $max_repos )
			);
			?>
		</p>
	</div>
<?php endif; ?>

<?php
$table = new Repository_List_Table( $repos );
$table->prepare_items();
$table->display();
$table->render_inline_edit_template();
?>

<?php if ( ! $at_limit && ! empty( $block_editor_active ) ) : ?>
	<?php
	$ghrp_settings_url  = add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=github-release-posts' ) );
	$ghrp_settings_link = '<a href="' . esc_url( $ghrp_settings_url ) . '">' . esc_html__( 'Settings', 'github-release-posts' ) . '</a>';

	// Group the eligible repos by owner for the always-visible picker.
	$ghrp_grouped = [];
	if ( is_array( $ghrp_repo_list ) ) {
		foreach ( $ghrp_repo_list as $r ) {
			$ghrp_grouped[ $r['owner'] ][] = $r;
		}
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="ghrp-new-repo"><?php echo esc_html__( 'Add Repository', 'github-release-posts' ); ?></label>
			</th>
			<td>
				<?php
				$ghrp_has_picker = $ghrp_pat_validated && ! empty( $ghrp_grouped );
				$ghrp_opt_index  = 0;
				?>
				<div class="ghrp-repo-picker">
					<input
						type="text"
						id="ghrp-new-repo"
						name="ghrp_new_repo"
						value=""
						placeholder="owner/repo"
						class="regular-text"
						autocomplete="off"
						<?php if ( $ghrp_has_picker ) : ?>
							role="combobox"
							aria-expanded="false"
							aria-controls="ghrp-repo-picker-list"
							aria-autocomplete="list"
							aria-haspopup="listbox"
						<?php endif; ?>
					>
					<?php if ( $ghrp_has_picker ) : ?>
						<div
							id="ghrp-repo-picker-list"
							class="ghrp-repo-picker__list"
							role="listbox"
							aria-label="<?php echo esc_attr__( 'Available repositories', 'github-release-posts' ); ?>"
							hidden
						>
							<?php foreach ( $ghrp_grouped as $ghrp_owner => $ghrp_owner_repos ) : ?>
								<?php
								$ghrp_group_id = 'ghrp-repo-group-' . sanitize_html_class( $ghrp_owner );
								?>
								<div class="ghrp-repo-picker__group" role="group" aria-labelledby="<?php echo esc_attr( $ghrp_group_id ); ?>" data-owner="<?php echo esc_attr( $ghrp_owner ); ?>">
									<div class="ghrp-repo-picker__group-name" id="<?php echo esc_attr( $ghrp_group_id ); ?>">
										<?php echo esc_html( $ghrp_owner ); ?>
									</div>
									<?php foreach ( $ghrp_owner_repos as $r ) : ?>
										<?php ++$ghrp_opt_index; ?>
										<div
											id="ghrp-repo-opt-<?php echo esc_attr( (string) $ghrp_opt_index ); ?>"
											class="ghrp-repo-picker__option"
											role="option"
											aria-selected="false"
											data-value="<?php echo esc_attr( $r['identifier'] ); ?>"
										>
											<?php echo esc_html( $r['name'] ); ?>
										</div>
									<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
							<p class="ghrp-repo-picker__empty" hidden>
								<?php echo esc_html__( 'No matches from your repositories. You can still add any public owner/repo.', 'github-release-posts' ); ?>
							</p>
						</div>
					<?php endif; ?>
				</div>
				<button type="submit" name="ghrp_add_repo" class="button button-primary">
					<?php echo esc_html__( 'Add', 'github-release-posts' ); ?>
				</button>

				<?php if ( $ghrp_pat_validated ) : ?>
					<button
						type="button"
						id="ghrp-refresh-repos"
						class="button"
						title="<?php echo esc_attr__( 'Refresh your repository list', 'github-release-posts' ); ?>"
					>
						<?php echo esc_html__( 'Refresh', 'github-release-posts' ); ?>
					</button>
					<span class="spinner ghrp-refresh-repos-spinner" style="float: none; vertical-align: middle;"></span>
				<?php endif; ?>

				<?php if ( $ghrp_pat_validated && empty( $ghrp_grouped ) ) : ?>
					<p class="description">
						<?php echo esc_html__( 'The configured Personal Access Token does not currently have access to any new repositories. Grant the token access to more repositories on GitHub, then click Refresh.', 'github-release-posts' ); ?>
					</p>
				<?php elseif ( $ghrp_pat_configured && ! $ghrp_pat_validated && '' !== $ghrp_repo_list_error ) : ?>
					<p class="description ghrp-repo-list-error">
						<?php
						printf(
							/* translators: %s: error message returned by the GitHub API */
							esc_html__( 'Could not load repositories from GitHub: %s', 'github-release-posts' ),
							esc_html( $ghrp_repo_list_error )
						);
						?>
					</p>
					<p class="description">
						<?php echo esc_html__( 'You can still enter any owner/repo above to track a public repository.', 'github-release-posts' ); ?>
					</p>
				<?php elseif ( ! $ghrp_pat_configured ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Settings tab */
							wp_kses( __( 'Enter any public GitHub repository in owner/repo format. Add a Personal Access Token on the %s tab to also pick from a list of your repositories.', 'github-release-posts' ), [ 'a' => [ 'href' => [] ] ] ),
							$ghrp_settings_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>
