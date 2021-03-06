<?php

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) exit;

// Load the parent class if it doesn't exist.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/**
 * Class for displaying the list of security key items.
 *
 * @since 0.1-dev
 * @access private
 *
 * @package Two_Factor
 */
class Two_Factor_U2F_User_List_Table extends WP_List_Table {

	/**
	 * Get a list of columns.
	 *
	 * @since 0.1-dev
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name' => __( 'Name' ),
			'added' => __( 'Added' ),
			'last_used' => __( 'Last Used' ),
		);
	}

	/**
	 * Prepares the list of items for displaying.
	 *
	 * @since 0.1-dev
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array(), 'name' );
	}

	/**
	 * Generates content for a single row of the table
	 *
	 * @since 0.1-dev
	 * @access protected
	 *
	 * @param object $item The current item.
	 * @param string $column_name The current column name.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				$out = '<div class="hidden" id="inline_' . esc_attr( $item->keyHandle ) . '">';
				$out .= '<div class="name">' . esc_html( $item->name ) . '</div>';
				$out .= '</div>';

				$actions = array(
					'rename hide-if-no-js' => Two_Factor_U2F_Admin::rename_link( $item ),
					'delete' => Two_Factor_U2F_Admin::delete_link( $item ),
				);

				return esc_html( $item->name ) . $out . self::row_actions( $actions );
			case 'added':
				return date( get_option( 'date_format', 'r' ), $item->added );
			case 'last_used':
				return date( get_option( 'date_format', 'r' ), $item->last_used );
			default:
				return 'WTF^^?';
		}
	}

	/**
	 * Generates custom table navigation to prevent conflicting nonces.
	 *
	 * @since 0.1-dev
	 * @access protected
	 *
	 * @param string $which The location of the bulk actions: 'top' or 'bottom'.
	 */
	protected function display_tablenav( $which ) {}

	/**
	 * Get a list of CSS classes for the list table table tag.
	 *
	 * @since 0.2-dev
	 * @access protected
	 *
	 * @return array List of CSS classes for the table tag.
	 */
	protected function get_table_classes() {
		return array( 'two-factor-table', $this->_args['plural'] );
	}

	/**
	 * Display the table
	 *
	 * @since 0.2-dev
	 * @access public
	 */
	public function display() {
		$singular = $this->_args['singular'];
		$this->display_tablenav( 'top' );
		$this->screen->render_screen_reader_content( 'heading_list' );

		?>
		<table class="wp-list-table <?php echo implode( ' ', $this->get_table_classes() ); ?>">
			<thead>
				<tr>
					<?php $this->print_column_headers(); ?>
				</tr>
			</thead>
			<tbody id="the-list"<?php
				if ( $singular ) {
					echo " data-wp-lists='list:$singular'";
				} ?>>
				<?php $this->display_rows_or_placeholder(); ?>
			</tbody>
			<tfoot>
				<tr></tr>
			</tfoot>
		</table>
		<?php

		$this->display_tablenav( 'bottom' );
	}

	/**
	 * Generates content for a single row of the table
	 *
	 * @since 0.1-dev
	 * @access public
	 *
	 * @param object $item The current item.
	 */
	public function single_row( $item ) {
		?>
		<tr id="key-<?php esc_attr( $item->keyHandle ); ?>">
		<?php $this->single_row_columns( $item ); ?>
		</tr>
		<?php
	}

	/**
	 * Outputs the hidden row displayed when inline editing
	 *
	 * @since 0.1-dev
	 */
	public function inline_edit() {
		?>
		<table style="display: none">
			<tbody id="inlineedit">
				<tr id="inline-edit" class="inline-edit-row" style="display: none">
					<td colspan="<?php echo esc_attr( $this->get_column_count() ); ?>" class="colspanchange">
						<fieldset>
							<div class="inline-edit-col">
								<h4><?php esc_html_e( 'Quick Edit' ); ?></h4>
								<label>
									<span class="title"><?php esc_html_e( 'Name' ); ?></span>
									<span class="input-text-wrap"><input type="text" name="name" class="ptitle" value="" /></span>
								</label>
							</div>
						</fieldset><?php

						$core_columns = array( 'name' => true, 'added' => true, 'last_used' => true );
						list( $columns ) = $this->get_column_info();
						foreach ( $columns as $column_name => $column_display_name ) {
							if ( isset( $core_columns[ $column_name ] ) ) {
								continue;
							}
							/**
							 *  This action is documented in 'wp-admin/includes/class-wp-posts-list-table.php'
							 */
							do_action( 'quick_edit_custom_box', $column_name, 'edit-security-keys' );
						}

						?>
						<p class="inline-edit-save submit">
							<a href="#inline-edit" class="cancel button-secondary alignleft"><?php esc_html_e( 'Cancel' ); ?></a>
							<a href="#inline-edit" class="save button-primary alignright"><?php esc_html_e( 'Update' ); ?></a>
							<span class="spinner"></span>
							<span class="error" style="display:none;"></span>
							<?php wp_nonce_field( 'keyinlineeditnonce', '_inline_edit', false ); ?>
							<br class="clear" />
						</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
