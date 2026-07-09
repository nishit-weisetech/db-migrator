<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var bool   $connected */
/** @var string $settings_url */
$list_url  = admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG );
$guide_url = admin_url( 'admin.php?page=' . DBMig_Admin::GUIDE_SLUG ) . '#g-normalize';
?>
<div class="wrap dbmig-wrap" id="dbmig-normalize">

	<h1 class="wp-heading-inline"><?php esc_html_e( 'Users from names', 'db-migrator' ); ?></h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'db-migrator' ); ?></a>
	<a href="<?php echo esc_url( $guide_url ); ?>" class="page-title-action" target="_blank">📖 <?php esc_html_e( 'Guide', 'db-migrator' ); ?></a>
	<hr class="wp-header-end">

	<p class="description" style="max-width:860px">
		<?php esc_html_e( 'Use this when your legacy data has an author/user name repeated on many rows but no user id or table to migrate users from (e.g. every post just stores an author name). It builds a users lookup table in the legacy database — one unique id per distinct name — and adds an id column to the source table linking each row to its name. The same name always maps to one user. Once done, migrate that lookup table as Users and link post authors by that id, exactly like any normal migration.', 'db-migrator' ); ?>
	</p>

	<?php if ( ! $connected ) : ?>
		<div class="notice notice-warning"><p>
			<?php
			printf(
				/* translators: %s: settings page URL */
				wp_kses_post( __( 'Connect the legacy database first on the <a href="%s">Settings</a> page.', 'db-migrator' ) ),
				esc_url( $settings_url )
			);
			?>
		</p></div>
	<?php endif; ?>

	<!-- Step 1: source -->
	<div class="dbmig-card">
		<h2><span class="dbmig-step">1</span><?php esc_html_e( 'Choose the name to normalize', 'db-migrator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dbmig-nz-table"><?php esc_html_e( 'Source table', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-nz-table" class="dbmig-grow"><option value=""><?php esc_html_e( '— loading tables —', 'db-migrator' ); ?></option></select>
					<button type="button" class="button" id="dbmig-nz-reload"><?php esc_html_e( 'Reload', 'db-migrator' ); ?></button>
					<p class="description"><?php esc_html_e( 'The legacy table that holds the name (this tool writes to it).', 'db-migrator' ); ?></p>
					<span id="dbmig-nz-conn-err" class="dbmig-inline-result err"></span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dbmig-nz-col"><?php esc_html_e( 'Name column', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-nz-col" class="dbmig-grow"><option value=""><?php esc_html_e( '— select table first —', 'db-migrator' ); ?></option></select>
					<label class="dbmig-partial-inline"><input type="checkbox" id="dbmig-nz-trim" checked> <strong><?php esc_html_e( 'Trim whitespace', 'db-migrator' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'The column with the repeated name (e.g. author_name). Trimming ignores leading/trailing spaces when matching.', 'db-migrator' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Step 2: naming -->
	<div class="dbmig-card">
		<h2><span class="dbmig-step">2</span><?php esc_html_e( 'New table &amp; id column', 'db-migrator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dbmig-nz-target"><?php esc_html_e( 'Users lookup table', 'db-migrator' ); ?></label></th>
				<td>
					<input type="text" id="dbmig-nz-target" class="regular-text" value="dbmig_authors" placeholder="dbmig_authors">
					<p class="description"><?php esc_html_e( 'Created in the legacy DB with columns: id (BIGINT, auto-increment) and name (UNIQUE).', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dbmig-nz-fk"><?php esc_html_e( 'New id column', 'db-migrator' ); ?></label></th>
				<td>
					<input type="text" id="dbmig-nz-fk" class="regular-text" value="author_id" placeholder="author_id">
					<p class="description"><?php esc_html_e( 'Added to the source table and filled with the matching id from the lookup table.', 'db-migrator' ); ?></p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Step 3: preview & run -->
	<div class="dbmig-card dbmig-run">
		<h2><span class="dbmig-step">3</span><?php esc_html_e( 'Preview &amp; run', 'db-migrator' ); ?></h2>
		<p class="dbmig-actions">
			<button type="button" class="button button-primary" id="dbmig-nz-preview"><?php esc_html_e( 'Preview &amp; generate SQL', 'db-migrator' ); ?></button>
			<button type="button" class="button button-primary" id="dbmig-nz-run" disabled><?php esc_html_e( 'Run against legacy DB', 'db-migrator' ); ?></button>
			<span id="dbmig-nz-result" class="dbmig-inline-result"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Preview first to see the counts and the exact SQL, then run. Running WRITES to the legacy database (CREATE TABLE, ALTER TABLE, INSERT, UPDATE) — that DB user needs those privileges; a read-only server user will fail. Safe to re-run.', 'db-migrator' ); ?>
		</p>

		<div id="dbmig-nz-plan-wrap" style="display:none">
			<ul id="dbmig-nz-summary" class="dbmig-bullets"></ul>

			<p><a href="#" id="dbmig-nz-toggle-sql"><?php esc_html_e( 'Show / hide the SQL', 'db-migrator' ); ?></a></p>
			<textarea id="dbmig-nz-sql" class="dbmig-sql" readonly rows="14" style="display:none"></textarea>

			<p id="dbmig-nz-run-result" class="dbmig-inline-result"></p>
			<ul id="dbmig-nz-run-log" class="dbmig-bullets"></ul>

			<div id="dbmig-nz-next" style="display:none">
				<h4><?php esc_html_e( 'Next steps', 'db-migrator' ); ?></h4>
				<ol class="dbmig-steps-list">
					<li><?php esc_html_e( 'Add a User migration: source = the new lookup table, id column = id, Display name = name.', 'db-migrator' ); ?></li>
					<li><?php esc_html_e( 'In the post migration, map Author (post_author) to the new id column and set “Resolve from migrated user table” to the lookup table.', 'db-migrator' ); ?></li>
				</ol>
			</div>
		</div>
	</div>
</div>
