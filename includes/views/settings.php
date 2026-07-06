<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $config */
/** @var bool  $ready */
?>
<div class="wrap dbmig-wrap">
	<h1><?php esc_html_e( 'DB Migrator — Settings', 'db-migrator' ); ?></h1>

	<div class="dbmig-card">
		<h2><?php esc_html_e( 'External (legacy) database', 'db-migrator' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Connection to the source MySQL database you are migrating FROM. The plugin reads from it; it never writes to it.', 'db-migrator' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( 'dbmig_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dbmig_host"><?php esc_html_e( 'Host', 'db-migrator' ); ?></label></th>
					<td><input name="dbmig_host" id="dbmig_host" type="text" class="regular-text" value="<?php echo esc_attr( $config['host'] ); ?>" placeholder="localhost"></td>
				</tr>
				<tr>
					<th scope="row"><label for="dbmig_dbname"><?php esc_html_e( 'Database name', 'db-migrator' ); ?></label></th>
					<td><input name="dbmig_dbname" id="dbmig_dbname" type="text" class="regular-text" value="<?php echo esc_attr( $config['dbname'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="dbmig_dbuser"><?php esc_html_e( 'Database user', 'db-migrator' ); ?></label></th>
					<td><input name="dbmig_dbuser" id="dbmig_dbuser" type="text" class="regular-text" value="<?php echo esc_attr( $config['dbuser'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="dbmig_dbpass"><?php esc_html_e( 'Database password', 'db-migrator' ); ?></label></th>
					<td><input name="dbmig_dbpass" id="dbmig_dbpass" type="text" class="regular-text" value="<?php echo esc_attr( $config['dbpass'] ); ?>" autocomplete="off"></td>
				</tr>
			</table>

			<p class="dbmig-actions">
				<button type="button" class="button" id="dbmig-test-connection"><?php esc_html_e( 'Test connection', 'db-migrator' ); ?></button>
				<span id="dbmig-test-result" class="dbmig-inline-result"></span>
			</p>

			<p class="submit">
				<button type="submit" name="dbmig_save_settings" class="button button-primary"><?php esc_html_e( 'Save settings', 'db-migrator' ); ?></button>
			</p>
		</form>
	</div>

	<div class="dbmig-card">
		<h2><?php esc_html_e( 'WordPress schema', 'db-migrator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Adds two columns to wp_posts — legacy_id and legacy_table_name — used to keep the relation between WordPress posts and source rows, resolve cross-table references, and make re-runs idempotent.', 'db-migrator' ); ?>
		</p>
		<p>
			<?php if ( $ready ) : ?>
				<span class="dbmig-badge dbmig-badge-ok"><?php esc_html_e( 'Columns present', 'db-migrator' ); ?></span>
			<?php else : ?>
				<span class="dbmig-badge dbmig-badge-warn"><?php esc_html_e( 'Columns missing', 'db-migrator' ); ?></span>
			<?php endif; ?>
		</p>
		<form method="post" action="">
			<?php wp_nonce_field( 'dbmig_settings' ); ?>
			<button type="submit" name="dbmig_ensure_schema" class="button"><?php esc_html_e( 'Ensure schema', 'db-migrator' ); ?></button>
		</form>
	</div>

	<div class="dbmig-card">
		<h2><?php esc_html_e( 'Media tools', 'db-migrator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Migrated media attachments are created without any resized versions (fast). Use this to generate the missing image sizes (thumbnails etc.) afterwards. Attachments whose sizes already exist are skipped.', 'db-migrator' ); ?>
		</p>
		<p class="dbmig-actions">
			<label class="dbmig-checkbox" style="display:inline-flex;gap:6px;align-items:center">
				<input type="checkbox" id="dbmig-media-pluginonly" checked>
				<?php esc_html_e( 'Only attachments created by DB Migrator', 'db-migrator' ); ?>
			</label>
			<button type="button" class="button" id="dbmig-media-scan"><?php esc_html_e( 'Scan for missing sizes', 'db-migrator' ); ?></button>
			<button type="button" class="button button-primary" id="dbmig-media-generate" disabled><?php esc_html_e( 'Generate missing sizes', 'db-migrator' ); ?></button>
			<span id="dbmig-media-result" class="dbmig-inline-result"></span>
		</p>
		<div id="dbmig-media-progress-wrap" style="display:none">
			<div class="dbmig-progress"><div class="dbmig-progress-bar" id="dbmig-media-bar"></div></div>
			<p id="dbmig-media-progress-text"></p>
		</div>
	</div>
</div>
