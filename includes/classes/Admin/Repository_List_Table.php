<?php
/**
 * Repository list table.
 *
 * Extends WP_List_Table to render tracked repositories with the same
 * look and feel as the WordPress posts list table.
 *
 * @package ChangelogToBlogPost
 */

namespace TenUp\ChangelogToBlogPost\Admin;

use TenUp\ChangelogToBlogPost\Plugin_Constants;
use TenUp\ChangelogToBlogPost\Settings\Repository_Settings;

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
	 * @var array<string, array{post_id: int, tag: string, date: string, edit_url: string}|null>
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
			'title'     => __( 'Repository', 'changelog-to-blog-post' ),
			'github'    => __( 'GitHub', 'changelog-to-blog-post' ),
			'last_post' => __( 'Last Post', 'changelog-to-blog-post' ),
			'status'    => __( 'Status', 'changelog-to-blog-post' ),
			'action'    => __( 'Action', 'changelog-to-blog-post' ),
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

		$posts = get_posts(
			[
				'post_type'   => 'post',
				'post_status' => [ 'publish', 'draft', 'pending', 'private' ],
				'meta_key'    => Plugin_Constants::META_SOURCE_REPO,
				'meta_value'  => $identifiers, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'meta_compare'    => 'IN',
			'posts_per_page'  => 100,
			'orderby'         => 'date',
			'order'           => 'DESC',
			]
		);

		// Keep only the most recent post per identifier.
		foreach ( $posts as $post ) {
			$repo = get_post_meta( $post->ID, Plugin_Constants::META_SOURCE_REPO, true );
			if ( '' === $repo || isset( $this->last_posts[ $repo ] ) ) {
				continue; // Already have a newer one.
			}

			$this->last_posts[ $repo ] = [
				'post_id'  => $post->ID,
				'tag'      => get_post_meta( $post->ID, Plugin_Constants::META_RELEASE_TAG, true ),
				'date'     => get_the_date( 'Y/m/d', $post->ID ),
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
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

		echo '<tr id="ctbp-repo-' . esc_attr( $index ) . '"';
		echo ' data-repo="' . esc_attr( $identifier ) . '"';
		echo ' data-display-name="' . esc_attr( $item['display_name'] ?? $identifier ) . '"';
		echo ' data-plugin-link="' . esc_attr( $item['plugin_link'] ?? '' ) . '"';
		echo ' data-post-status="' . esc_attr( ! empty( $item['post_status'] ) ? $item['post_status'] : 'draft' ) . '"';
		echo ' data-categories="' . esc_attr( wp_json_encode( array_map( 'intval', (array) ( $item['categories'] ?? [] ) ) ) ) . '"';
		echo ' data-tags="' . esc_attr( Admin_Page::tag_ids_to_names( (array) ( $item['tags'] ?? [] ) ) ) . '"';
		echo ' data-author="' . esc_attr( $item['author'] ?? 0 ) . '"';
		echo ' data-paused="' . esc_attr( ! empty( $item['paused'] ) ? '1' : '' ) . '"';
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
				'<a href="#" class="ctbp-edit-repo-btn">%s</a>',
				esc_html__( 'Edit', 'changelog-to-blog-post' )
			),
			'delete' => sprintf(
				'<a href="#" class="ctbp-remove-repo-btn submitdelete" data-repo="%s">%s</a>',
				esc_attr( $identifier ),
				esc_html__( 'Remove', 'changelog-to-blog-post' )
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
		$identifier = $item['identifier'] ?? '';
		return sprintf(
			'<code>%s</code>',
			esc_html( $identifier )
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
				'<span class="ctbp-status-badge ctbp-status-paused" aria-label="%s">%s</span>',
				esc_attr__( 'Paused', 'changelog-to-blog-post' ),
				esc_html__( 'Paused', 'changelog-to-blog-post' )
			);
		}

		return sprintf(
			'<span class="ctbp-status-badge ctbp-status-active" aria-label="%s">%s</span>',
			esc_attr__( 'Active', 'changelog-to-blog-post' ),
			esc_html__( 'Active', 'changelog-to-blog-post' )
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

		$data  = $this->last_posts[ $identifier ];
		$label = $data['tag'] ? $data['tag'] . ' ' . __( 'on', 'changelog-to-blog-post' ) . ' ' . $data['date'] : $data['date'];

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $data['edit_url'] ),
			esc_html( $label )
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

		static $provider = null;
		if ( null === $provider ) {
			$provider = ( new \TenUp\ChangelogToBlogPost\Settings\Global_Settings() )->get_ai_provider();
		}

		if ( empty( $provider ) ) {
			return sprintf(
				'<button type="button" class="button button-small" disabled title="%s">%s</button>',
				esc_attr__( 'Configure an AI provider in the Settings tab first.', 'changelog-to-blog-post' ),
				esc_html__( 'Generate post', 'changelog-to-blog-post' )
			);
		}

		return sprintf(
			'<button type="button" class="button button-small ctbp-generate-draft" data-repo="%s" title="%s">%s</button>' .
			'<span class="spinner ctbp-generate-spinner"></span>' .
			'<span class="ctbp-generate-status" aria-live="polite"></span>',
			esc_attr( $identifier ),
			esc_attr__( 'Generate a draft post from the latest release version', 'changelog-to-blog-post' ),
			esc_html__( 'Generate post', 'changelog-to-blog-post' )
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
		<table style="display: none"><tbody id="ctbp-inline-edit">
			<tr class="ctbp-repo-edit-row inline-edit-row quick-edit-row">
				<td colspan="<?php echo esc_attr( $col_count ); ?>" class="colspanchange">
					<div class="inline-edit-wrapper" role="region">

						<fieldset class="inline-edit-col-left">
							<legend class="inline-edit-legend"></legend>
							<div class="inline-edit-col">
								<label>
									<span class="title"><?php echo esc_html__( 'Name', 'changelog-to-blog-post' ); ?></span>
									<span class="input-text-wrap">
										<input type="text" data-field="display_name">
									</span>
								</label>

								<label>
									<span class="title"><?php echo esc_html__( 'Project Link', 'changelog-to-blog-post' ); ?></span>
									<span class="input-text-wrap">
										<input type="text" data-field="plugin_link" class="ctbp-plugin-link-input" placeholder="URL or WordPress.org slug">
										<span class="ctbp-plugin-link-status" aria-live="polite"></span>
									</span>
								</label>

								<label>
									<span class="title"><?php echo esc_html__( 'Status', 'changelog-to-blog-post' ); ?></span>
									<?php
									$statuses = (array) apply_filters(
										'ctbp_post_status_options',
										[
											'draft'   => __( 'Draft', 'changelog-to-blog-post' ),
											'pending' => __( 'Pending Review', 'changelog-to-blog-post' ),
											'publish' => __( 'Published', 'changelog-to-blog-post' ),
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
									<span class="title"><?php echo esc_html__( 'Author', 'changelog-to-blog-post' ); ?></span>
									<?php
									wp_dropdown_users(
										[
											'name'  => '',
											'id'    => '',
											'class' => 'ctbp-tpl-author',
											'who'   => 'authors',
											'show'  => 'display_name',
										]
									);
									?>
								</label>

								<div class="inline-edit-group wp-clearfix">
									<label class="alignleft">
										<input type="checkbox" data-field="paused" value="1">
										<span class="checkbox-title"><?php echo esc_html__( 'Pause monitoring', 'changelog-to-blog-post' ); ?></span>
									</label>
								</div>
							</div>
						</fieldset>

						<fieldset class="inline-edit-col-center inline-edit-categories">
							<div class="inline-edit-col">
								<span class="title inline-edit-categories-label"><?php echo esc_html__( 'Categories', 'changelog-to-blog-post' ); ?></span>
								<input type="hidden" name="" value="0" class="ctbp-tpl-cat-hidden">
								<ul class="cat-checklist category-checklist ctbp-tpl-categories">
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
										<span class="title"><?php echo esc_html__( 'Tags', 'changelog-to-blog-post' ); ?></span>
										<textarea data-field="tags" cols="22" rows="1"></textarea>
									</label>
									<p class="howto"><?php echo esc_html__( 'Separate tags with commas', 'changelog-to-blog-post' ); ?></p>
								</div>

								<div class="inline-edit-group wp-clearfix ctbp-featured-image-field">
									<span class="title"><?php echo esc_html__( 'Featured Image', 'changelog-to-blog-post' ); ?></span>
									<div class="ctbp-featured-image-preview"></div>
									<input type="hidden" data-field="featured_image" value="0">
									<button type="button" class="button button-small ctbp-select-image">
										<?php echo esc_html__( 'Select Image', 'changelog-to-blog-post' ); ?>
									</button>
									<button type="button" class="button-link ctbp-remove-image" style="display:none">
										<?php echo esc_html__( 'Remove', 'changelog-to-blog-post' ); ?>
									</button>
								</div>
							</div>
						</fieldset>

						<div class="submit inline-edit-save">
							<button type="submit" class="button button-primary save">
								<?php echo esc_html__( 'Update', 'changelog-to-blog-post' ); ?>
							</button>
							<button type="button" class="button cancel ctbp-cancel-edit">
								<?php echo esc_html__( 'Cancel', 'changelog-to-blog-post' ); ?>
							</button>
						</div>

					</div>
				</td>
			</tr>
		</tbody></table>
		<?php
	}

	/**
	 * Message displayed when there are no repositories.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No repositories are being tracked yet. Add one below.', 'changelog-to-blog-post' );
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
