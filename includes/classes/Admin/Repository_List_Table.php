<?php
/**
 * Repository list table.
 *
 * Extends WP_List_Table to render tracked repositories with the same
 * look and feel as the WordPress posts list table.
 *
 * @package GitHubReleasePosts
 */

namespace GitHubReleasePosts\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use GitHubReleasePosts\Plugin_Constants;
use GitHubReleasePosts\Post\Post_Status;
use GitHubReleasePosts\GitHub\Tag_Pattern_Matcher;
use GitHubReleasePosts\Settings\Repository_Settings;

// WP_List_Table is not loaded automatically in all contexts.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders tracked repositories as a standard WordPress admin list table.
 */
class Repository_List_Table extends \WP_List_Table {

	/**
	 * Repository data (array of associative arrays).
	 *
	 * @var array
	 */
	private array $repos;

	/**
	 * Pre-loaded last post data, keyed by repo identifier.
	 *
	 * @var array<string, array{post_id: int, tag: string, date: string, edit_url: string, status: string}|null>
	 */
	private array $last_posts = [];

	/**
	 * Constructor.
	 *
	 * @param array $repos Repository data from Repository_Settings.
	 */
	public function __construct( array $repos ) {
		parent::__construct(
			[
				'singular' => 'repository',
				'plural'   => 'repositories',
				'ajax'     => false,
			]
		);

		$this->repos = $repos;
	}

	/**
	 * Defines table columns.
	 *
	 * @return array Column slug => display name.
	 */
	public function get_columns(): array {
		return [
			'title'     => __( 'Repository', 'auto-release-posts-for-github' ),
			'github'    => __( 'GitHub', 'auto-release-posts-for-github' ),
			'last_post' => __( 'Last Post', 'auto-release-posts-for-github' ),
			'status'    => __( 'Status', 'auto-release-posts-for-github' ),
			'action'    => __( 'Action', 'auto-release-posts-for-github' ),
		];
	}

	/**
	 * Designates the primary column (receives row actions).
	 *
	 * @return string Column slug.
	 */
	protected function get_primary_column_name(): string {
		return 'title';
	}

	/**
	 * Populates items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = [
			$this->get_columns(),
			[], // Hidden columns.
			[], // Sortable columns.
			$this->get_primary_column_name(),
		];

		$this->items = $this->repos;
		$this->preload_last_posts();
	}

	/**
	 * Batch-loads the most recent generated post for each repository
	 * in a single query, avoiding N+1 queries in column_last_post().
	 *
	 * @return void
	 */
	private function preload_last_posts(): void {
		$identifiers = array_filter( array_column( $this->repos, 'identifier' ) );
		if ( empty( $identifiers ) ) {
			return;
		}

		// One bounded query per repo (the repository count is small): fetch that
		// repo's single newest post. A single global query with a row cap would
		// drop repos whose newest post falls outside the capped window when
		// another repo has many posts, leaving them wrongly showing no post.
		foreach ( $identifiers as $identifier ) {
			$posts = get_posts(
				[
					'post_type'      => 'post',
					'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
					'meta_key'       => Plugin_Constants::META_SOURCE_REPO, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $identifier, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				]
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$post = $posts[0];

			$this->last_posts[ $identifier ] = [
				'post_id'  => $post->ID,
				'tag'      => get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_TAG, true ),
				'date'     => get_the_date( 'Y/m/d', $post->ID ),
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				'status'   => $post->post_status,
			];
		}
	}

	/**
	 * Renders a single data row with data-* attributes for inline editing.
	 *
	 * @param array $item Repository data.
	 * @return void
	 */
	public function single_row( $item ): void {
		$index      = $item['_index'] ?? 0;
		$identifier = $item['identifier'] ?? '';

		echo '<tr id="ghrp-repo-' . esc_attr( $index ) . '"';
		echo ' data-repo="' . esc_attr( $identifier ) . '"';
		echo ' data-display-name="' . esc_attr( $item['display_name'] ?? $identifier ) . '"';
		echo ' data-plugin-link="' . esc_attr( $item['plugin_link'] ?? '' ) . '"';
		echo ' data-tag-patterns="' . esc_attr( $item['tag_patterns'] ?? '' ) . '"';
		echo ' data-post-status="' . esc_attr( ! empty( $item['post_status'] ) ? $item['post_status'] : 'draft' ) . '"';
		// array_values() forces a JSON array even if the stored categories have
		// non-sequential keys (from older saves), so the inline editor always
		// receives an array and never a JSON object it would choke on.
		echo ' data-categories="' . esc_attr( wp_json_encode( array_values( array_map( 'intval', (array) ( $item['categories'] ?? [] ) ) ) ) ) . '"';
		echo ' data-tags="' . esc_attr( Admin_Page::tag_ids_to_names( (array) ( $item['tags'] ?? [] ) ) ) . '"';
		echo ' data-author="' . esc_attr( $item['author'] ?? 0 ) . '"';
		echo ' data-paused="' . esc_attr( ! empty( $item['paused'] ) ? '1' : '' ) . '"';
		echo ' data-include-prereleases="' . esc_attr( ! empty( $item['include_prereleases'] ) ? '1' : '' ) . '"';
		echo ' data-featured-image="' . esc_attr( $item['featured_image'] ?? 0 ) . '"';
		echo '>';
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Renders the "title" (primary) column with row actions.
	 *
	 * @param array $item Repository data.
	 * @return string Column HTML.
	 */
	protected function column_title( array $item ): string {
		$identifier   = $item['identifier'] ?? '';
		$display_name = $item['display_name'] ?? $identifier;

		$actions = [
			'edit'   => sprintf(
				'<a href="#" class="ghrp-edit-repo-btn">%s</a>',
				esc_html__( 'Edit', 'auto-release-posts-for-github' )
			),
			'delete' => sprintf(
				'<a href="#" class="ghrp-remove-repo-btn submitdelete" data-repo="%s">%s</a>',
				esc_attr( $identifier ),
				esc_html__( 'Remove', 'auto-release-posts-for-github' )
			),
		];

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $display_name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Renders the "github" column with the owner/repo identifier.
	 *
	 * @param array $item Repository data.
	 * @return string Column HTML.
	 */
	protected function column_github( array $item ): string {
		$identifier   = $item['identifier'] ?? '';
		$releases_url = 'https://github.com/' . $identifier . '/releases';
		return sprintf(
			'<code>%s</code> <a href="%s" target="_blank" rel="noopener" title="%s"><span class="dashicons dashicons-external" style="font-size:14px;width:14px;height:14px;text-decoration:none;"></span><span class="screen-reader-text">%s</span></a>',
			esc_html( $identifier ),
			esc_url( $releases_url ),
			esc_attr__( 'View releases on GitHub', 'auto-release-posts-for-github' ),
			esc_html__( 'View releases on GitHub', 'auto-release-posts-for-github' )
		);
	}

	/**
	 * Renders the "status" column.
	 *
	 * @param array $item Repository data.
	 * @return string Column HTML.
	 */
	protected function column_status( array $item ): string {
		$paused = ! empty( $item['paused'] );

		if ( $paused ) {
			return sprintf(
				'<span class="ghrp-status-badge ghrp-status-paused" aria-label="%s">%s</span>',
				esc_attr__( 'Paused', 'auto-release-posts-for-github' ),
				esc_html__( 'Paused', 'auto-release-posts-for-github' )
			);
		}

		return sprintf(
			'<span class="ghrp-status-badge ghrp-status-active" aria-label="%s">%s</span>',
			esc_attr__( 'Active', 'auto-release-posts-for-github' ),
			esc_html__( 'Active', 'auto-release-posts-for-github' )
		);
	}

	/**
	 * Renders the "last_post" column with the date of the most recent
	 * generated post for this repository, linking to the block editor.
	 *
	 * @param array $item Repository data.
	 * @return string Column HTML.
	 */
	protected function column_last_post( array $item ): string {
		$identifier = $item['identifier'] ?? '';

		if ( empty( $identifier ) || ! isset( $this->last_posts[ $identifier ] ) ) {
			return '—';
		}

		$data = $this->last_posts[ $identifier ];
		// Package tags ("@headstartwp/core@1.6.1") render as "core 1.6.1".
		$tag_label = Tag_Pattern_Matcher::display_label(
			(string) $data['tag'],
			Tag_Pattern_Matcher::has_patterns( (string) ( $item['tag_patterns'] ?? '' ) )
		);
		$label     = $data['tag'] ? $tag_label . ' ' . __( 'on', 'auto-release-posts-for-github' ) . ' ' . $data['date'] : $data['date'];

		// Show post status for non-published posts. Pulled from WP's status
		// registry so custom statuses (Edit Flow's "Pitch", etc.) render too.
		$status_label = '';
		if ( ! Post_Status::is_public( $data['status'] ) ) {
			$status_text = Post_Status::label( $data['status'] );
			if ( '' !== $status_text ) {
				$status_label = ' <em>(' . esc_html( $status_text ) . ')</em>';
			}
		}

		return sprintf(
			'<a href="%s">%s</a>%s',
			esc_url( $data['edit_url'] ),
			esc_html( $label ),
			$status_label
		);
	}

	/**
	 * Renders the "action" column with a generate button and spinner.
	 *
	 * @param array $item Repository data.
	 * @return string Column HTML.
	 */
	protected function column_action( array $item ): string {
		if ( ! Admin_Page::is_block_editor_active() ) {
			return '';
		}

		$identifier = $item['identifier'] ?? '';

		static $has_connector = null;
		if ( null === $has_connector ) {
			$has_connector = false;
			if ( class_exists( 'WordPress\AiClient\AiClient' ) ) {
				$registry = \WordPress\AiClient\AiClient::defaultRegistry();
				foreach ( $registry->getRegisteredProviderIds() as $id ) {
					if ( $registry->isProviderConfigured( $id ) ) {
						$has_connector = true;
						break;
					}
				}
			}
		}

		if ( ! $has_connector ) {
			return sprintf(
				'<button type="button" class="button button-small" disabled aria-label="%s">%s</button>',
				esc_attr__( 'No AI connector configured. Set one up under Settings → Connectors.', 'auto-release-posts-for-github' ),
				esc_html__( 'Generate draft', 'auto-release-posts-for-github' )
			);
		}

		return sprintf(
			'<button type="button" class="button button-small ghrp-generate-draft" data-repo="%s" title="%s">%s</button>' .
			'<span class="spinner ghrp-generate-spinner"></span>' .
			'<span class="ghrp-generate-status" aria-live="polite"></span>',
			esc_attr( $identifier ),
			esc_attr__( 'Generate a draft post from the latest release version', 'auto-release-posts-for-github' ),
			esc_html__( 'Generate draft', 'auto-release-posts-for-github' )
		);
	}

	/**
	 * Renders the inline edit template row in a hidden table, mirroring
	 * the WP Quick Edit pattern. JS clones this and injects it into the
	 * main table on demand — no hidden rows in the actual tbody.
	 *
	 * @return void
	 */
	public function render_inline_edit_template(): void {
		$col_count = $this->get_column_count();
		?>
		<table style="display: none"><tbody id="ghrp-inline-edit">
			<tr class="ghrp-repo-edit-row inline-edit-row quick-edit-row">
				<td colspan="<?php echo esc_attr( (string) $col_count ); ?>" class="colspanchange">
					<div class="inline-edit-wrapper" role="region">

						<fieldset class="inline-edit-col-left">
							<legend class="inline-edit-legend"></legend>
							<div class="inline-edit-col">
								<label>
									<span class="title"><?php echo esc_html__( 'Name', 'auto-release-posts-for-github' ); ?></span>
									<span class="input-text-wrap">
										<input type="text" data-field="display_name">
									</span>
								</label>

								<label>
									<span class="title"><?php echo esc_html__( 'Project Link', 'auto-release-posts-for-github' ); ?></span>
									<span class="input-text-wrap">
										<input type="text" data-field="plugin_link" class="ghrp-plugin-link-input" placeholder="URL or WordPress.org slug">
										<span class="ghrp-plugin-link-status" aria-live="polite"></span>
									</span>
								</label>

								<?php // Written by the Packages picker; round-trips stored patterns untouched when the picker doesn't render. ?>
								<input type="hidden" data-field="tag_patterns">

								<label>
									<span class="title"><?php echo esc_html__( 'Status', 'auto-release-posts-for-github' ); ?></span>
									<?php
									$statuses = (array) apply_filters(
										'ghrp_post_status_options',
										[
											'draft'   => __( 'Draft', 'auto-release-posts-for-github' ),
											'pending' => __( 'Pending Review', 'auto-release-posts-for-github' ),
											'publish' => __( 'Published', 'auto-release-posts-for-github' ),
										]
									);
									?>
									<select data-field="post_status">
										<?php foreach ( $statuses as $status_value => $status_label ) : ?>
											<option value="<?php echo esc_attr( $status_value ); ?>"><?php echo esc_html( $status_label ); ?></option>
										<?php endforeach; ?>
									</select>
								</label>

								<label class="inline-edit-author">
									<span class="title"><?php echo esc_html__( 'Author', 'auto-release-posts-for-github' ); ?></span>
									<?php
									wp_dropdown_users(
										[
											'name'    => '',
											'id'      => '',
											'class'   => 'ghrp-tpl-author',
											'show'    => 'display_name',
											'include' => self::get_author_dropdown_user_ids(),
										]
									);
									?>
								</label>

								<div class="inline-edit-group wp-clearfix">
									<label class="alignleft">
										<input type="checkbox" data-field="paused" value="1">
										<span class="checkbox-title"><?php echo esc_html__( 'Pause monitoring', 'auto-release-posts-for-github' ); ?></span>
									</label>
								</div>

								<div class="inline-edit-group wp-clearfix">
									<label class="alignleft" title="<?php echo esc_attr__( 'Generate posts for releases marked as pre-release on GitHub (betas, release candidates, etc.). Off by default; most sites only highlight stable releases.', 'auto-release-posts-for-github' ); ?>">
										<input type="checkbox" data-field="include_prereleases" value="1">
										<span class="checkbox-title"><?php echo esc_html__( 'Include pre-releases', 'auto-release-posts-for-github' ); ?></span>
									</label>
								</div>
							</div>
						</fieldset>

						<fieldset class="inline-edit-col-center inline-edit-categories">
							<div class="inline-edit-col">
								<div class="ghrp-packages-picker" hidden>
									<span class="title"><?php echo esc_html__( 'Packages', 'auto-release-posts-for-github' ); ?></span>
									<div class="ghrp-packages-mode">
										<label>
											<input type="radio" value="all" checked>
											<?php echo esc_html__( 'Create posts for all packages', 'auto-release-posts-for-github' ); ?>
										</label>
										<label>
											<input type="radio" value="choose">
											<?php echo esc_html__( 'Choose which packages get posts', 'auto-release-posts-for-github' ); ?>
										</label>
									</div>
									<ul class="cat-checklist ghrp-package-checklist" hidden></ul>
								</div>

								<span class="title inline-edit-categories-label"><?php echo esc_html__( 'Categories', 'auto-release-posts-for-github' ); ?></span>
								<input type="hidden" name="" value="0" class="ghrp-tpl-cat-hidden">
								<ul class="cat-checklist category-checklist ghrp-tpl-categories">
									<?php
									wp_terms_checklist(
										0,
										[
											'taxonomy' => 'category',
										]
									);
									?>
								</ul>
							</div>
						</fieldset>

						<fieldset class="inline-edit-col-right">
							<div class="inline-edit-col">
								<div class="inline-edit-tags-wrap">
									<label class="inline-edit-tags">
										<span class="title"><?php echo esc_html__( 'Tags', 'auto-release-posts-for-github' ); ?></span>
										<textarea data-field="tags" cols="22" rows="1"></textarea>
									</label>
									<p class="howto"><?php echo esc_html__( 'Separate tags with commas', 'auto-release-posts-for-github' ); ?></p>
								</div>

								<div class="inline-edit-group wp-clearfix ghrp-featured-image-field">
									<span class="title"><?php echo esc_html__( 'Featured Image', 'auto-release-posts-for-github' ); ?></span>
									<div class="ghrp-featured-image-preview"></div>
									<input type="hidden" data-field="featured_image" value="0">
									<button type="button" class="button button-small ghrp-select-image">
										<?php echo esc_html__( 'Select Image', 'auto-release-posts-for-github' ); ?>
									</button>
									<button type="button" class="button-link ghrp-remove-image" style="display:none">
										<?php echo esc_html__( 'Remove', 'auto-release-posts-for-github' ); ?>
									</button>
								</div>
							</div>
						</fieldset>

						<div class="submit inline-edit-save">
							<button type="submit" class="button button-primary save">
								<?php echo esc_html__( 'Update', 'auto-release-posts-for-github' ); ?>
							</button>
							<button type="button" class="button cancel ghrp-cancel-edit">
								<?php echo esc_html__( 'Cancel', 'auto-release-posts-for-github' ); ?>
							</button>
						</div>

					</div>
				</td>
			</tr>
		</tbody></table>
		<?php
	}

	/**
	 * Returns the user IDs offered in the Quick Edit author dropdown.
	 *
	 * Users are matched by the `edit_posts` capability. On multisite,
	 * capability queries only inspect each user's per-site role meta, which
	 * never matches super admins — their access comes from the network's
	 * `site_admins` option, applied at runtime in WP_User::has_cap(). Super
	 * admins who are members of the current site are therefore merged in
	 * explicitly; without this they could not be selected as a repository's
	 * post author, and Quick Edit would silently reassign repositories
	 * already owned by one.
	 *
	 * @return int[] User IDs.
	 */
	public static function get_author_dropdown_user_ids(): array {
		$ids = array_map(
			'intval',
			get_users(
				[
					'capability' => 'edit_posts',
					'fields'     => 'ID',
				]
			)
		);

		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$user = get_user_by( 'login', $login );
				if ( $user && ! in_array( (int) $user->ID, $ids, true ) && is_user_member_of_blog( (int) $user->ID ) ) {
					$ids[] = (int) $user->ID;
				}
			}
		}

		/**
		 * Filters the user IDs selectable as a repository's post author.
		 *
		 * @param int[] $ids User IDs offered in the author dropdown.
		 */
		$ids = apply_filters( 'ghrp_author_dropdown_user_ids', $ids );

		// wp_dropdown_users() treats an empty include list as "no constraint"
		// and would list every user; pin to a non-matching ID instead so the
		// dropdown renders empty rather than unfiltered.
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		return empty( $ids ) ? [ 0 ] : $ids;
	}

	/**
	 * Message displayed when there are no repositories.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No repositories are being tracked yet. Add one below.', 'auto-release-posts-for-github' );
	}

	/**
	 * Suppresses the default tablenav (pagination, bulk actions) since
	 * repositories don't need pagination or bulk operations.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function display_tablenav( $which ): void {
		// Intentionally empty — no pagination or bulk actions needed.
	}
}
