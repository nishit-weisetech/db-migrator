<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array|null $profile */
/** @var array $post_types */
/** @var array $post_statuses */
/** @var array $post_field_map */
/** @var array $ext_config */
/** @var bool  $schema_ready */

$is_new       = empty( $profile );
$profile_json = wp_json_encode( $profile ? $profile : new stdClass() );
$post_field_json = wp_json_encode( $post_field_map );
$list_url     = admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG );
$settings_url = admin_url( 'admin.php?page=' . DBMig_Admin::SETTINGS_SLUG );
?>
<div class="wrap dbmig-wrap dbmig-editor"
	data-profile="<?php echo esc_attr( $profile_json ); ?>"
	data-postfields="<?php echo esc_attr( $post_field_json ); ?>">

	<?php if ( ! $is_new ) : ?>
		<?php // Covers the editor until the saved migration is loaded + populated via AJAX, so the user never sees a flash of the empty/default form. Hidden by admin.js when hydrate finishes (with a safety timeout). ?>
		<div id="dbmig-editor-loading" class="dbmig-editor-loading" role="status" aria-live="polite">
			<div class="dbmig-editor-loading-inner">
				<span class="dbmig-spinner" aria-hidden="true"></span>
				<span class="dbmig-editor-loading-text"><?php esc_html_e( 'Loading saved migration…', 'db-migrator' ); ?></span>
			</div>
		</div>
	<?php endif; ?>

	<h1 class="wp-heading-inline"><?php echo $is_new ? esc_html__( 'New migration', 'db-migrator' ) : esc_html__( 'Edit migration', 'db-migrator' ); ?></h1>
	<a href="<?php echo esc_url( $list_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'db-migrator' ); ?></a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DBMig_Admin::GUIDE_SLUG ) ); ?>" class="page-title-action" target="_blank">📖 <?php esc_html_e( 'Guide', 'db-migrator' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( empty( $ext_config['dbname'] ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Configure the external database first.', 'db-migrator' ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'db-migrator' ); ?></a>
		</p></div>
	<?php endif; ?>

	<?php if ( ! $schema_ready ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'The legacy_id / legacy_table_name columns are missing on wp_posts. Add them in Settings → Ensure schema before running an import.', 'db-migrator' ); ?>
			<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'db-migrator' ); ?></a>
		</p></div>
	<?php endif; ?>

	<input type="hidden" id="dbmig-profile-id" value="<?php echo esc_attr( $profile['id'] ?? '' ); ?>">

	<!-- Step 1: target -->
	<div class="dbmig-card">
		<h2><span class="dbmig-step">1</span><?php esc_html_e( 'Target', 'db-migrator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dbmig-name"><?php esc_html_e( 'Migration name', 'db-migrator' ); ?></label></th>
				<td>
					<input type="text" id="dbmig-name" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Articles → Posts', 'db-migrator' ); ?>">
					<label class="dbmig-partial-inline"><input type="checkbox" id="dbmig-partial"> <strong><?php esc_html_e( 'Partial update', 'db-migrator' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'Partial update: only update rows already migrated, writing just the fields mapped here (no new rows created). Use it to fill in a field added after the first run.', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dbmig-migration-type"><?php esc_html_e( 'Migrate into', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-migration-type">
						<option value="post"><?php esc_html_e( 'Posts (a post type)', 'db-migrator' ); ?></option>
						<option value="user"><?php esc_html_e( 'Users', 'db-migrator' ); ?></option>
						<option value="term"><?php esc_html_e( 'Taxonomy terms', 'db-migrator' ); ?></option>
						<option value="attachment"><?php esc_html_e( 'Media attachments', 'db-migrator' ); ?></option>
						<option value="comment"><?php esc_html_e( 'Comments', 'db-migrator' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Users → migrate authors / accounts. Taxonomy terms → migrate a legacy table into a taxonomy. Media attachments → create attachment posts in the Media Library (you place the image files into wp-content/uploads yourself, then regenerate sizes).', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-hasid">
				<th scope="row"><?php esc_html_e( 'Preserve source IDs', 'db-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" id="dbmig-preserve-id"> <strong><?php esc_html_e( 'Keep the source primary key as the WordPress ID', 'db-migrator' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'On insert, give each row the same ID it had in the source (post ID / term_id / user ID / comment ID) instead of a new auto-increment one. Only affects newly created rows — existing ones are matched and updated by legacy id as usual. Make sure those IDs are free in WordPress; a collision with an existing ID will error. Applies on the "Run SQL (fast)" path.', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-hasslug">
				<th scope="row"><?php esc_html_e( 'Auto slug from title', 'db-migrator' ); ?></th>
				<td>
					<label><input type="checkbox" id="dbmig-auto-slug"> <strong><?php esc_html_e( 'Generate the slug from the title / name when no slug column is mapped', 'db-migrator' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'For the "Run SQL (fast)" path. Posts derive the slug (post_name) from the title; users derive the nicename from the display name. A mapped slug always wins — the source only fills a blank one. (Taxonomy terms already generate a slug from the name automatically; the "Run import (PHP)" path also derives these itself.)', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-post">
				<th scope="row"><label for="dbmig-post-type"><?php esc_html_e( 'Post type', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-post-type">
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Auto-populated from all registered post types. Choosing one loads its ACF fields below.', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-post">
				<th scope="row"><label for="dbmig-post-status"><?php esc_html_e( 'Post status', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-post-status">
						<?php foreach ( $post_statuses as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="dbmig-when-user" style="display:none">
				<th scope="row"><label for="dbmig-role"><?php esc_html_e( 'User role', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-role"></select>
					<p class="description"><?php esc_html_e( 'Default role for migrated users (used unless a role column is mapped).', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-term" style="display:none">
				<th scope="row"><label for="dbmig-taxonomy"><?php esc_html_e( 'Taxonomy', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-taxonomy"></select>
					<p class="description"><?php esc_html_e( 'Which taxonomy the migrated terms are created in. Choosing one loads its ACF term fields below.', 'db-migrator' ); ?></p>
				</td>
			</tr>
			<tr class="dbmig-when-comment" style="display:none">
				<th scope="row"><?php esc_html_e( 'Target', 'db-migrator' ); ?></th>
				<td>
					<p><strong><?php esc_html_e( 'WordPress comments (wp_comments).', 'db-migrator' ); ?></strong></p>
					<p class="description">
						<?php esc_html_e( 'Map the legacy post-id column to Post (comment_post_ID) with the “Resolve → migrated post” transform (pick the legacy posts table) so each comment attaches to the right migrated post. Migrate the posts first. For threaded replies, map the parent-comment id with “Resolve → migrated comment”. Comment counts are recalculated automatically.', 'db-migrator' ); ?>
					</p>
				</td>
			</tr>
			<tr class="dbmig-when-attachment" style="display:none">
				<th scope="row"><?php esc_html_e( 'Target', 'db-migrator' ); ?></th>
				<td>
					<p><strong><?php esc_html_e( 'Media Library (attachment posts, status “inherit”).', 'db-migrator' ); ?></strong></p>
					<p class="description">
						<?php esc_html_e( 'Map the file path to WordPress with a Post-meta row: key = _wp_attached_file, value = the path relative to wp-content/uploads (e.g. 2024/03/photo.jpg). Set the File URL (guid) for a complete record — the MIME type is auto-detected from its extension. Place the actual files in wp-content/uploads yourself, then run Settings → Media tools → Generate missing sizes (or the WP-CLI command: wp media regenerate --only-missing).', 'db-migrator' ); ?>
					</p>
				</td>
			</tr>
		</table>
	</div>

	<!-- Step 2: source -->
	<div class="dbmig-card">
		<h2><span class="dbmig-step">2</span><?php esc_html_e( 'Source table', 'db-migrator' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dbmig-source-table"><?php esc_html_e( 'Legacy table', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-source-table" class="dbmig-grow"><option value=""><?php esc_html_e( '— loading tables —', 'db-migrator' ); ?></option></select>
					<button type="button" class="button" id="dbmig-reload-tables"><?php esc_html_e( 'Reload', 'db-migrator' ); ?></button>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dbmig-source-id"><?php esc_html_e( 'Legacy ID column', 'db-migrator' ); ?></label></th>
				<td>
					<select id="dbmig-source-id" class="dbmig-grow"><option value=""></option></select>
					<p class="description"><?php esc_html_e( 'Primary key of the source row. Stored on each post as legacy_id to keep the relation.', 'db-migrator' ); ?></p>
				</td>
			</tr>
		</table>

		<section class="dbmig-joins">
			<h3 class="dbmig-joins-title"><?php esc_html_e( 'Related tables (JOINs)', 'db-migrator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Join other source tables so their columns become available for mapping. Both ON columns are dropdowns listing the base table AND every table joined above — so you can chain joins (e.g. base → junction → detail). Add joins top-to-bottom in dependency order.', 'db-migrator' ); ?></p>
			<div id="dbmig-joins-list"></div>
			<button type="button" class="button" id="dbmig-add-join"><?php esc_html_e( '+ Add join', 'db-migrator' ); ?></button>
		</section>

		<section class="dbmig-rowfilter">
			<h3 class="dbmig-joins-title"><?php esc_html_e( 'Row filter (which source rows to migrate)', 'db-migrator' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Optional. Restrict, order and slice the source rows before migrating — handy for testing (e.g. only 10 rows) or migrating in chunks. These apply to the generated SQL, the PHP import, the count and the preview alike.', 'db-migrator' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dbmig-where"><?php esc_html_e( 'WHERE condition', 'db-migrator' ); ?></label></th>
					<td>
						<input type="text" id="dbmig-where" class="dbmig-grow large-text" placeholder="e.g. status = 'published' AND created_at &gt;= '2020-01-01'">
						<p class="description"><?php esc_html_e( 'A raw boolean expression on the source table columns (no "WHERE" keyword). Leave blank for all rows. Semicolons and SQL comments are stripped.', 'db-migrator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dbmig-orderby"><?php esc_html_e( 'Order by', 'db-migrator' ); ?></label></th>
					<td>
						<select id="dbmig-orderby" class="dbmig-grow"><option value=""><?php esc_html_e( '— default (ID column) —', 'db-migrator' ); ?></option></select>
						<select id="dbmig-orderdir">
							<option value="ASC"><?php esc_html_e( 'ASC', 'db-migrator' ); ?></option>
							<option value="DESC"><?php esc_html_e( 'DESC', 'db-migrator' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Only affects which rows a LIMIT/OFFSET picks. Defaults to the ID column so slices stay stable across batches.', 'db-migrator' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="dbmig-limit"><?php esc_html_e( 'Limit / Offset', 'db-migrator' ); ?></label></th>
					<td>
						<input type="number" id="dbmig-limit" min="0" step="1" style="width:9em" placeholder="<?php esc_attr_e( 'Limit (0 = all)', 'db-migrator' ); ?>">
						<input type="number" id="dbmig-offset" min="0" step="1" style="width:9em" placeholder="<?php esc_attr_e( 'Offset', 'db-migrator' ); ?>">
						<p class="description"><?php esc_html_e( 'Limit = max rows to migrate (0 = no limit). Offset = how many rows to skip first. Together they let you migrate a range, e.g. limit 1000 offset 2000.', 'db-migrator' ); ?></p>
					</td>
				</tr>
			</table>
		</section>
	</div>

	<!-- Step 3: field mapping -->
	<div class="dbmig-card">
		<h2><span class="dbmig-step">3</span><?php esc_html_e( 'Field mapping', 'db-migrator' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Every WordPress field (post defaults + ACF fields for the chosen type) is listed on the left. Pick a source column on the right. Leave a row blank to skip that field.', 'db-migrator' ); ?>
			<span id="dbmig-acf-status"></span>
		</p>

		<table class="dbmig-map-table widefat">
			<thead>
				<tr>
					<th style="width:32%"><?php esc_html_e( 'WordPress field', 'db-migrator' ); ?></th>
					<th style="width:34%"><?php esc_html_e( 'Source column', 'db-migrator' ); ?></th>
					<th style="width:34%"><?php esc_html_e( 'Options', 'db-migrator' ); ?></th>
				</tr>
			</thead>
			<tbody id="dbmig-fields-list">
				<tr><td colspan="3"><?php esc_html_e( 'Select a source table to load columns…', 'db-migrator' ); ?></td></tr>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="dbmig-automap"><?php esc_html_e( 'Auto-map by name', 'db-migrator' ); ?></button>
			<span class="description"><?php esc_html_e( 'Guesses source columns for common fields (title, content, date…).', 'db-migrator' ); ?></span>
		</p>

		<h3 class="dbmig-subhead"><?php esc_html_e( 'Additional mappings (custom meta & taxonomies)', 'db-migrator' ); ?></h3>
		<p class="description"><?php esc_html_e( 'For meta keys or taxonomies not shown above (e.g. plain post meta, category/tag assignment).', 'db-migrator' ); ?></p>
		<table class="dbmig-map-table widefat">
			<thead>
				<tr>
					<th style="width:16%"><?php esc_html_e( 'Kind', 'db-migrator' ); ?></th>
					<th style="width:26%"><?php esc_html_e( 'Target', 'db-migrator' ); ?></th>
					<th style="width:26%"><?php esc_html_e( 'Source column', 'db-migrator' ); ?></th>
					<th style="width:22%"><?php esc_html_e( 'Options', 'db-migrator' ); ?></th>
					<th style="width:10%"></th>
				</tr>
			</thead>
			<tbody id="dbmig-extra-list"></tbody>
		</table>
		<button type="button" class="button" id="dbmig-add-extra"><?php esc_html_e( '+ Add mapping', 'db-migrator' ); ?></button>
	</div>

	<!-- Step 4: ACF repeaters -->
	<div class="dbmig-card" id="dbmig-repeater-section">
		<h2><span class="dbmig-step">4</span><?php esc_html_e( 'ACF repeaters (child tables)', 'db-migrator' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Map a one-to-many child table into an ACF repeater field. Each child row becomes a repeater row.', 'db-migrator' ); ?></p>
		<div id="dbmig-repeaters-list"></div>
		<button type="button" class="button" id="dbmig-add-repeater"><?php esc_html_e( '+ Add repeater', 'db-migrator' ); ?></button>
	</div>

	<!-- Save / Run -->
	<div class="dbmig-card dbmig-run">
		<h2><span class="dbmig-step">5</span><?php esc_html_e( 'Save & run', 'db-migrator' ); ?></h2>
		<p class="dbmig-actions">
			<button type="button" class="button button-primary" id="dbmig-save"><?php esc_html_e( 'Save migration', 'db-migrator' ); ?></button>
			<label class="dbmig-batch"><?php esc_html_e( 'Batch size', 'db-migrator' ); ?>
				<input type="number" id="dbmig-batch-size" value="50" min="1" max="500" style="width:80px">
			</label>
			<button type="button" class="button" id="dbmig-preview"><?php esc_html_e( '👁 Preview 1st row', 'db-migrator' ); ?></button>
				<button type="button" class="button" id="dbmig-run" disabled><?php esc_html_e( 'Run import (PHP)', 'db-migrator' ); ?></button>
			<button type="button" class="button button-primary" id="dbmig-run-sql" disabled><?php esc_html_e( 'Run SQL (fast)', 'db-migrator' ); ?></button>
			<button type="button" class="button" id="dbmig-generate-sql" disabled><?php esc_html_e( 'Generate SQL file', 'db-migrator' ); ?></button>
			<span id="dbmig-save-result" class="dbmig-inline-result"></span>
		</p>
		<p class="description">
			<?php esc_html_e( '"Run SQL (fast)" executes the generated create-or-update SQL directly (posts, meta, taxonomies, author linking) with a progress bar — no external mysql client needed. "Run import (PHP)" also handles ACF repeaters. "Generate SQL file" saves a .sql to run yourself.', 'db-migrator' ); ?>
		</p>

		<div id="dbmig-preview-wrap" style="display:none">
			<h4><?php esc_html_e( 'Preview — first source row', 'db-migrator' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Dry run: how the first source row resolves into WordPress. Nothing is written.', 'db-migrator' ); ?></p>
			<div id="dbmig-preview-body"></div>
		</div>

		<div id="dbmig-sqlrun-wrap" style="display:none">
			<div class="dbmig-progress"><div class="dbmig-progress-bar" id="dbmig-sqlrun-bar"></div></div>
			<p id="dbmig-sqlrun-text"></p>
			<pre id="dbmig-sqlrun-log" class="dbmig-log" style="max-height:200px"></pre>
		</div>

		<div id="dbmig-progress-wrap" style="display:none">
			<div class="dbmig-progress"><div class="dbmig-progress-bar" id="dbmig-progress-bar"></div></div>
			<p id="dbmig-progress-text"></p>
		</div>

		<div id="dbmig-log-wrap" style="display:none">
			<h4><?php esc_html_e( 'Log', 'db-migrator' ); ?></h4>
			<pre id="dbmig-log" class="dbmig-log"></pre>
		</div>

		<div id="dbmig-sql-wrap" style="display:none">
			<h4><?php esc_html_e( 'Generated SQL files', 'db-migrator' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Idempotent, same-server create-or-update SQL written into the plugin\'s exports/ folder. A new file is only created when the field mapping changes; otherwise the existing file is reused. ACF repeaters / multi-relationships / taxonomy terms still run via "Run import".', 'db-migrator' ); ?></p>
			<p id="dbmig-sql-status" class="dbmig-inline-result"></p>

			<table class="wp-list-table widefat striped" id="dbmig-sql-list">
				<thead>
					<tr>
						<th style="width:26%"><?php esc_html_e( 'File', 'db-migrator' ); ?></th>
						<th style="width:14%"><?php esc_html_e( 'Created', 'db-migrator' ); ?></th>
						<th style="width:8%"><?php esc_html_e( 'Size', 'db-migrator' ); ?></th>
						<th style="width:12%"><?php esc_html_e( 'Status', 'db-migrator' ); ?></th>
						<th style="width:40%"><?php esc_html_e( 'Import command', 'db-migrator' ); ?></th>
					</tr>
				</thead>
				<tbody id="dbmig-sql-rows"></tbody>
			</table>

			<p><a href="#" id="dbmig-sql-toggle-preview"><?php esc_html_e( 'Show/hide last-generated SQL preview', 'db-migrator' ); ?></a></p>
			<textarea id="dbmig-sql" class="dbmig-sql" readonly rows="14" style="display:none"></textarea>
		</div>

		<script type="text/template" id="dbmig-tpl-sqlrow">
			<tr data-file="{file}" class="{rowclass}">
				<td><a href="{download}" class="dbmig-sql-dl"><strong>{file}</strong></a></td>
				<td>{created}</td>
				<td>{size} KB</td>
				<td>{statusbadge}</td>
				<td>
					<input type="text" class="dbmig-cmd code" value="{command}" readonly onclick="this.select()" style="width:82%">
					<button type="button" class="button button-small dbmig-cmd-copy"><?php esc_attr_e( 'Copy', 'db-migrator' ); ?></button>
					<button type="button" class="button button-small dbmig-cmd-del" title="<?php esc_attr_e( 'Delete file', 'db-migrator' ); ?>">✕</button>
				</td>
			</tr>
		</script>
	</div>

	<!-- Row templates (hidden) -->
	<script type="text/template" id="dbmig-tpl-join">
		<div class="dbmig-join-row">
			<div class="dbmig-join-main">
				<select class="dbmig-join-type"><option value="LEFT">LEFT JOIN</option><option value="INNER">INNER JOIN</option></select>
				<select class="dbmig-join-table dbmig-tablelist"></select>
				<span><?php esc_html_e( 'ON', 'db-migrator' ); ?></span>
				<select class="dbmig-join-left"><option value="">— column —</option></select>
				<span>=</span>
				<select class="dbmig-join-right"><option value="">— column —</option></select>
				<button type="button" class="button button-small dbmig-join-addcond" title="<?php esc_attr_e( 'Add an extra AND/OR condition (e.g. post_type = news)', 'db-migrator' ); ?>"><?php esc_html_e( '+ condition', 'db-migrator' ); ?></button>
				<button type="button" class="button-link dbmig-remove"><?php esc_html_e( 'Remove', 'db-migrator' ); ?></button>
			</div>
			<div class="dbmig-join-conds"></div>
		</div>
	</script>

	<script type="text/template" id="dbmig-tpl-repeater">
		<div class="dbmig-repeater-row">
			<div class="dbmig-repeater-head">
				<label><?php esc_html_e( 'Repeater field', 'db-migrator' ); ?>
					<select class="dbmig-rep-field dbmig-acf-repeaters"></select>
				</label>
				<label><?php esc_html_e( 'Child table', 'db-migrator' ); ?>
					<select class="dbmig-rep-table dbmig-tablelist"></select>
				</label>
				<label><?php esc_html_e( 'Child FK column', 'db-migrator' ); ?>
					<select class="dbmig-rep-fk"></select>
				</label>
				<label title="<?php esc_attr_e( 'Column the child FK matches. Blank = the parent source id. Or a “link via” table column (e.g. chip_counts.id).', 'db-migrator' ); ?>"><?php esc_html_e( 'matches', 'db-migrator' ); ?>
					<select class="dbmig-rep-parentcol"></select>
				</label>
				<label><?php esc_html_e( 'Order by', 'db-migrator' ); ?>
					<select class="dbmig-rep-orderby"></select>
				</label>
				<button type="button" class="button-link dbmig-remove"><?php esc_html_e( 'Remove repeater', 'db-migrator' ); ?></button>
			</div>
			<div class="dbmig-rep-via">
				<span class="dbmig-rep-via-label"><?php esc_html_e( 'Link via (optional intermediate tables):', 'db-migrator' ); ?></span>
				<div class="dbmig-rep-via-list"></div>
				<button type="button" class="button button-small dbmig-rep-addvia"><?php esc_html_e( '+ link table', 'db-migrator' ); ?></button>
			</div>
			<table class="dbmig-submap widefat">
				<thead><tr><th><?php esc_html_e( 'Sub field', 'db-migrator' ); ?></th><th><?php esc_html_e( 'Child column', 'db-migrator' ); ?></th><th><?php esc_html_e( 'Resolve relation (legacy table)', 'db-migrator' ); ?></th><th></th></tr></thead>
				<tbody class="dbmig-submap-list"></tbody>
			</table>
			<button type="button" class="button button-small dbmig-add-sub"><?php esc_html_e( '+ Add sub field', 'db-migrator' ); ?></button>
		</div>
	</script>
</div>
