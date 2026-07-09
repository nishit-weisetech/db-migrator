<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $profiles */
/** @var bool  $connected */

$new_url      = admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG . '&action=new' );
$settings_url = admin_url( 'admin.php?page=' . DBMig_Admin::SETTINGS_SLUG );
?>
<div class="wrap dbmig-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'DB Migrator', 'db-migrator' ); ?></h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add migration', 'db-migrator' ); ?></a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DBMig_Admin::GUIDE_SLUG ) ); ?>" class="page-title-action">📖 <?php esc_html_e( 'Guide', 'db-migrator' ); ?></a>
	<?php if ( ! empty( $profiles ) ) : ?>
		<?php
		$export_url = add_query_arg(
			array(
				'action' => 'dbmig_export_profiles',
				'nonce'  => wp_create_nonce( 'dbmig_export' ),
			),
			admin_url( 'admin-ajax.php' )
		);
		?>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action" id="dbmig-export-btn" title="<?php esc_attr_e( 'Exports the checked migrations, or all of them if none are checked.', 'db-migrator' ); ?>"><?php esc_html_e( '⬇ Export migrations', 'db-migrator' ); ?></a>
	<?php endif; ?>
	<button type="button" class="page-title-action" id="dbmig-import-btn"><?php esc_html_e( '⬆ Import migrations', 'db-migrator' ); ?></button>
	<input type="file" id="dbmig-import-file" accept="application/json,.json" style="display:none">
	<span id="dbmig-import-result" class="dbmig-inline-result"></span>
	<hr class="wp-header-end">

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'No external database configured yet.', 'db-migrator' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Go to Settings', 'db-migrator' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<td class="check-column"><input type="checkbox" id="dbmig-check-all" title="<?php esc_attr_e( 'Select all', 'db-migrator' ); ?>"></td>
				<th><?php esc_html_e( 'Name', 'db-migrator' ); ?></th>
				<th><?php esc_html_e( 'Source table', 'db-migrator' ); ?></th>
				<th><?php esc_html_e( 'Target post type', 'db-migrator' ); ?></th>
				<th><?php esc_html_e( 'Mappings', 'db-migrator' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'db-migrator' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $profiles ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No migrations yet. Click "Add migration" to create one.', 'db-migrator' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $profiles as $p ) : ?>
					<?php
					$edit_url   = admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG . '&action=edit&id=' . rawurlencode( $p['id'] ) );
					$delete_url = wp_nonce_url(
						admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG . '&dbmig_delete=' . rawurlencode( $p['id'] ) ),
						'dbmig_delete_profile'
					);
					$field_count = count( $p['fields'] ) + count( $p['repeaters'] );
					?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" class="dbmig-export-check" value="<?php echo esc_attr( $p['id'] ); ?>"></th>
						<td><strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $p['name'] ? $p['name'] : $p['id'] ); ?></a></strong></td>
						<td><code><?php echo esc_html( $p['source_table'] ); ?></code></td>
						<td><?php echo esc_html( $p['post_type'] ); ?></td>
						<td><?php echo esc_html( $field_count ); ?></td>
						<td>
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit / Run', 'db-migrator' ); ?></a>
							<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this migration profile?', 'db-migrator' ) ); ?>');"><?php esc_html_e( 'Delete', 'db-migrator' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
