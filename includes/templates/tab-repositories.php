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

if ( $ghrp_pat_configured ) {
	$ghrp_result = ( new API_Client( $ghrp_global_settings ) )->list_accessible_repos();

	if ( is_wp_error( $ghrp_result ) ) {
		$ghrp_repo_list_error = $ghrp_result->get_error_message();
	}

	if ( is_array( $ghrp_result ) ) {
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
	$ghrp_settings_url    = add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=github-release-posts' ) );
	$ghrp_settings_link   = '<a href="' . esc_url( $ghrp_settings_url ) . '">' . esc_html__( 'Settings', 'github-release-posts' ) . '</a>';
	$ghrp_grouped_options = [];

	// Input mode names: dropdown, fallback-empty, fallback-error, fallback-no-pat.
	$ghrp_input_mode = 'fallback-no-pat';
	if ( $ghrp_pat_configured && is_array( $ghrp_repo_list ) && ! empty( $ghrp_repo_list ) ) {
		$ghrp_input_mode = 'dropdown';
		foreach ( $ghrp_repo_list as $r ) {
			$ghrp_grouped_options[ $r['owner'] ][] = $r;
		}
	} elseif ( $ghrp_pat_configured && is_array( $ghrp_repo_list ) ) {
		$ghrp_input_mode = 'fallback-empty';
	} elseif ( $ghrp_pat_configured && '' !== $ghrp_repo_list_error ) {
		$ghrp_input_mode = 'fallback-error';
	}
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="ghrp-new-repo"><?php echo esc_html__( 'Add Repository', 'github-release-posts' ); ?></label>
			</th>
			<td>
				<?php if ( 'dropdown' === $ghrp_input_mode ) : ?>
					<select id="ghrp-new-repo" name="ghrp_new_repo" class="regular-text">
						<option value=""><?php echo esc_html__( '— Select a repository —', 'github-release-posts' ); ?></option>
						<?php foreach ( $ghrp_grouped_options as $ghrp_owner => $ghrp_owner_repos ) : ?>
							<optgroup label="<?php echo esc_attr( $ghrp_owner ); ?>">
								<?php foreach ( $ghrp_owner_repos as $r ) : ?>
									<option value="<?php echo esc_attr( $r['identifier'] ); ?>">
										<?php echo esc_html( $r['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<button type="submit" name="ghrp_add_repo" class="button button-primary">
						<?php echo esc_html__( 'Add', 'github-release-posts' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( 'dropdown' !== $ghrp_input_mode ) : ?>
					<input
						type="text"
						id="ghrp-new-repo"
						name="ghrp_new_repo"
						value=""
						placeholder="owner/repo"
						class="regular-text"
					>
					<button type="submit" name="ghrp_add_repo" class="button button-primary">
						<?php echo esc_html__( 'Add', 'github-release-posts' ); ?>
					</button>
				<?php endif; ?>

				<?php if ( 'fallback-empty' === $ghrp_input_mode ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Settings tab */
							wp_kses( __( 'The configured Personal Access Token does not currently have access to any new repositories. Grant the token access to more repositories on GitHub, then click "Refresh repository list" on the %s tab.', 'github-release-posts' ), [ 'a' => [ 'href' => [] ] ] ),
							$ghrp_settings_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( 'fallback-error' === $ghrp_input_mode ) : ?>
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
						<?php echo esc_html__( 'Enter a GitHub repository in owner/repo format or paste a full GitHub URL.', 'github-release-posts' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( 'fallback-no-pat' === $ghrp_input_mode ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Settings tab */
							wp_kses( __( 'Enter a GitHub repository in owner/repo format or paste a full GitHub URL. Add a Personal Access Token on the %s tab to pick from a list of your repositories instead.', 'github-release-posts' ), [ 'a' => [ 'href' => [] ] ] ),
							$ghrp_settings_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						);
						?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>
