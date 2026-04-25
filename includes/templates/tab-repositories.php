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

use Jakemgold\GitHubReleasePosts\Admin\Repository_List_Table;
use Jakemgold\GitHubReleasePosts\Settings\Repository_Settings;

$repo_settings = new Repository_Settings();
$repos         = $repo_settings->get_repositories();
$max_repos     = (int) apply_filters( 'ghrp_max_repositories', Repository_Settings::MAX_REPOSITORIES );
$at_limit      = count( $repos ) >= $max_repos;

// Inject the array index into each repo so the list table can generate
// unique IDs for edit rows.
foreach ( $repos as $i => $repo ) {
	$repos[ $i ]['_index'] = $i;
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
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="ghrp-new-repo"><?php echo esc_html__( 'Add Repository', 'github-release-posts' ); ?></label>
			</th>
			<td>
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
				<p class="description">
					<?php echo esc_html__( 'Enter a GitHub repository in owner/repo format or paste a full GitHub URL.', 'github-release-posts' ); ?>
				</p>
			</td>
		</tr>
	</table>
<?php endif; ?>
