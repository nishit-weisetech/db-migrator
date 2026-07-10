<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$settings_url = admin_url( 'admin.php?page=' . DBMig_Admin::SETTINGS_SLUG );
$new_url      = admin_url( 'admin.php?page=' . DBMig_Admin::MENU_SLUG . '&action=new' );
?>
<div class="wrap dbmig-wrap dbmig-guide">
	<h1><?php esc_html_e( 'DB Migrator — Guide', 'db-migrator' ); ?></h1>
	<p class="description"><?php esc_html_e( 'How to map a legacy MySQL database onto WordPress posts, users, taxonomies and ACF fields.', 'db-migrator' ); ?></p>

	<div class="dbmig-guide-toc dbmig-card">
		<strong><?php esc_html_e( 'Contents', 'db-migrator' ); ?></strong>
		<ul>
			<li><a href="#g-how">1. How it works (read this first)</a></li>
			<li><a href="#g-order">2. Migration order — the golden rule</a></li>
			<li><a href="#g-post">3. Mapping a POST migration</a></li>
			<li><a href="#g-user">4. Mapping a USER migration</a></li>
			<li><a href="#g-join">5. Joining related tables</a></li>
			<li><a href="#g-tax">6. Mapping a taxonomy (categories / tags)</a></li>
			<li><a href="#g-author">7. Mapping the author (post_author)</a></li>
			<li><a href="#g-acf">8. ACF fields, relationships &amp; repeaters</a></li>
			<li><a href="#g-media">9. Media / images</a></li>
			<li><a href="#g-comment">10. Migrating comments</a></li>
			<li><a href="#g-run">11. Running a migration (3 ways)</a></li>
			<li><a href="#g-normalize">12. Normalizing a name-only column (no ids)</a></li>
			<li><a href="#g-tips">13. Key rules &amp; gotchas</a></li>
		</ul>
	</div>

	<!-- What's new -->
	<div class="dbmig-card dbmig-whatsnew">
		<h2><?php esc_html_e( 'What’s new', 'db-migrator' ); ?></h2>
		<ul class="dbmig-bullets">
			<li><strong>Auto slug from title.</strong> A checkbox on post &amp; user migrations to derive the slug when no slug column is mapped — post <code>post_name</code> from the title, user <code>user_nicename</code> from the display name — on the fast SQL path, which otherwise leaves it blank. (Taxonomy terms already do this from the name.) (<a href="#g-tips">§13</a>)</li>
			<li><strong>Preserve source IDs.</strong> A checkbox on post, user, taxonomy-term and comment migrations to keep each row's original primary key as the WordPress ID (post ID / user ID / term_id / comment ID) on insert, instead of a new auto-increment. Only affects newly created rows; make sure those IDs are free. (<a href="#g-tips">§13</a>)</li>
			<li><strong>Users from names (normalize).</strong> A new tool that turns a repeated name column with no id (e.g. an author name on every post) into a real users lookup table + id column in the legacy DB, so the normal id-based user migration and author linking work. Same name → one user. (<a href="#g-normalize">§12</a>)</li>
			<li><strong>Taxonomy-terms migration type.</strong> A third “Migrate into” option: bring a whole category/tag table over — <em>name, slug, description, parent</em>, plus term meta and ACF term fields. (<a href="#g-tax">§6</a>)</li>
			<li><strong>Join to the current WordPress DB.</strong> The table dropdowns now list your live <code>wp_posts</code> / <code>wp_users</code> / <code>wp_terms</code> alongside the source tables, so you can resolve against already-migrated content by legacy id. (<a href="#g-join">§5</a>)</li>
			<li><strong>Extra join AND/OR conditions.</strong> A <em>+ condition</em> button on each join adds conditions like <code>AND wp_posts.post_type = …</code> so a current-DB join can’t collide across post types. (<a href="#g-join">§5</a>)</li>
			<li><strong>“Already a WP post ID” relationship mode.</strong> Link an ACF relationship to migrated content through a join — including <strong>one-to-many</strong> (all related ids are aggregated into one ACF value). (<a href="#g-acf">§8</a>)</li>
			<li><strong>Taxonomy slug mapping.</strong> Bring a source <code>slug</code> across as-is, or auto-derive it from the name. (<a href="#g-tax">§6</a>)</li>
			<li><strong>Partial update.</strong> Re-run to fill in just one field you added later, updating only already-migrated rows and creating nothing. (<a href="#g-run">§10</a>)</li>
			<li><strong>Users never get invented data.</strong> Skipped user fields keep WordPress’s defaults — no placeholder e-mails. (<a href="#g-user">§4</a>)</li>
			<li><strong>Media-attachment migration type.</strong> Import a legacy media table as Media Library <code>attachment</code> posts (title, file URL, <code>_wp_attached_file</code>; MIME type auto-detected from the file URL); place the files manually and regenerate sizes. (<a href="#g-media">§9</a>)</li>
			<li><strong>Preview 1st row.</strong> A dry run that shows how the first source row resolves into WordPress (each field's raw → written value, plus repeater rows) — no writes. Check a mapping before running. (<a href="#g-run">§11</a>)</li>
			<li><strong>Modified date.</strong> Map a legacy “updated” column to <code>post_modified</code> on post &amp; media-attachment migrations so the real last-modified date survives — WordPress otherwise stamps it to import time. (<a href="#g-post">§3</a>)</li>
			<li><strong>Row filter (WHERE / ORDER BY / LIMIT / OFFSET).</strong> Restrict, order and slice which source rows migrate — a raw <code>WHERE</code> to include only some rows, and a limit/offset to test on a handful or migrate in safe chunks. Chunked runs accumulate without wiping earlier slices. (<a href="#g-join">§5</a>)</li>
			<li><strong>Export / import migrations.</strong> Build and test your migration profiles locally, then carry them to the production server. (<a href="#g-run">§11</a>)</li>
			<li><strong>Comment migration type.</strong> Import a legacy comments table into <code>wp_comments</code> — attach to migrated posts, resolve threaded replies, recount automatically. (<a href="#g-comment">§10</a>)</li>
		</ul>
	</div>

	<!-- 1 -->
	<div class="dbmig-card" id="g-how">
		<h2>1. How it works (read this first)</h2>
		<p>You connect a <strong>legacy (source) MySQL database</strong> and describe how its tables map onto WordPress. The plugin only <em>reads</em> the source DB; it writes to WordPress.</p>
		<p>Each migration targets one of five things (Step 1 → <strong>“Migrate into”</strong>): <strong>Posts</strong> (a post type, §3), <strong>Users</strong> (§4), <strong>Taxonomy terms</strong> (§6), <strong>Media attachments</strong> (§9), or <strong>Comments</strong> (§10).</p>
		<ul class="dbmig-bullets">
			<li><strong>Legacy link.</strong> Two indexed columns — <code>legacy_id</code> and <code>legacy_table_name</code> — are added on activation to <code>wp_posts</code>, <code>wp_users</code> and <code>wp_terms</code>. Every migrated post, author and term remembers which source row it came from, so lookups and re-runs are fast and idempotent. (Older user links stored in usermeta are copied into the column automatically.)</li>
			<li><strong>Idempotent.</strong> Because of that link, running a migration again <em>updates</em> existing rows instead of creating duplicates. Safe to re-run.</li>
			<li><strong>Relations.</strong> The legacy link is how the plugin resolves things like "this post's author is legacy user #5" or "this ACF field points at migrated post #123".</li>
		</ul>
		<p><strong>First-time setup:</strong> go to <a href="<?php echo esc_url( $settings_url ); ?>">Settings</a> → enter the source DB credentials → <em>Test connection</em> → <em>Ensure schema</em> (adds the legacy columns).</p>
	</div>

	<!-- 2 -->
	<div class="dbmig-card" id="g-order">
		<h2>2. Migration order — the golden rule</h2>
		<p class="dbmig-rule"><strong>Migrate the things that are <em>referenced</em> before the things that reference them.</strong></p>
		<p>A post's author, category and relationship fields can only resolve if their targets already exist. A typical order:</p>
		<ol class="dbmig-steps-list">
			<li><strong>Users / authors</strong> (e.g. a legacy authors or users table).</li>
			<li><strong>Taxonomy source tables</strong> are handled automatically when you run the post migration, so no separate step is needed — but any posts you <em>link</em> to (ACF relationship) must be migrated first.</li>
			<li><strong>Posts</strong> (articles, products…), which then link to the users, terms and related posts above.</li>
		</ol>
	</div>

	<!-- 3 -->
	<div class="dbmig-card" id="g-post">
		<h2>3. Mapping a POST migration</h2>
		<p><a href="<?php echo esc_url( $new_url ); ?>">Add migration</a> → set <strong>Migrate into = Posts</strong>.</p>
		<table class="dbmig-guide-table">
			<tr><th>Step 1 — Target</th><td><strong>Post type</strong> (auto-listed from all registered types) and the <strong>status</strong> to import as.</td></tr>
			<tr><th>Step 2 — Source table</th><td>Pick the legacy <strong>table</strong> and its <strong>ID column</strong> (the primary key — stored as <code>legacy_id</code>).</td></tr>
			<tr><th>Step 3 — Field mapping</th><td>Every WordPress field (post defaults + the post type's ACF fields) is listed on the left. For each, choose a <strong>source column</strong> on the right. <span class="dbmig-hl">Leave a row blank to skip that field.</span></td></tr>
			<tr><th>Step 4 — Repeaters</th><td>Only for ACF repeaters fed by a child table (see §8).</td></tr>
			<tr><th>Step 5 — Save &amp; run</th><td>Save, then run (see §10).</td></tr>
		</table>
		<p><strong>Transforms</strong> (per row, in Options): <code>Integer</code>, <code>Boolean</code>, <code>Date → Y-m-d H:i:s</code>, <code>Strip tags</code>, <code>JSON / Unserialize</code>. Use <em>Date</em> for legacy datetime columns so WordPress stores them correctly.</p>
		<p><strong>Created &amp; modified dates.</strong> Map <code>post_date</code> to your legacy created timestamp and <code>post_modified</code> to your legacy updated timestamp to bring both across. This matters because WordPress otherwise stamps <code>post_modified</code> to the moment of import on <em>every</em> write (both the fast-SQL and Run-import paths force it) — mapping the column is the only way to preserve the real last-modified date. When mapped it also sets <code>post_modified_gmt</code>. Leave it unmapped to keep the old behaviour (modified = import time).</p>
		<p><strong>Static value:</strong> instead of a source column, pick <span class="dbmig-hl">★ Static value…</span> in the source dropdown and type a constant. Every migrated row gets that fixed value — handy for a constant <code>post_status</code>, an import batch tag, a default meta/ACF value, etc. Works for post/user fields, meta and ACF.</p>
		<p><strong>Resolve to a migrated WP id:</strong> when a source column holds a legacy id that points at already-migrated content, set the field's <em>transform</em> to <span class="dbmig-hl">🔗 Resolve → migrated post / user / term ID</span> and choose the <strong>referenced legacy table</strong>. The value becomes the current-DB WordPress id (looked up via the indexed <code>legacy_id</code> column). Use it to fill <code>post_parent</code>, an ACF post-object/relationship, or any meta with the real WP ID of the linked item — for <em>any</em> field, not just ACF relationship fields.</p>
		<p><strong>Additional mappings</strong> (below the field grid) let you add things not in the auto-list: custom <strong>post meta</strong> keys, <strong>taxonomy</strong> assignments, and <strong>single ACF fields</strong> whose group isn't attached to this post type.</p>
	</div>

	<!-- 4 -->
	<div class="dbmig-card" id="g-user">
		<h2>4. Mapping a USER migration</h2>
		<p>Set <strong>Migrate into = Users</strong>. The field list becomes the WordPress user fields.</p>
		<table class="dbmig-guide-table">
			<tr><th>User role</th><td>Default role for the migrated users (e.g. Author).</td></tr>
			<tr><th>Core fields</th><td><code>user_login</code>, <code>user_email</code>, <code>user_pass</code>, <code>user_nicename</code>, <code>display_name</code>, <code>first_name</code>, <code>last_name</code>, <code>nickname</code>, <code>user_url</code>, <code>description</code>, <code>user_registered</code>. (WordPress has no “modified” date for users, so there is no such field — only <code>user_registered</code>.)</td></tr>
			<tr><th>Skipped fields</th><td><strong>Nothing is invented.</strong> A field you don't map is left at WordPress's own column default (empty) — no placeholder e-mail is generated, and an unmapped e-mail simply stays blank. The one exception: WordPress hard-requires a <code>user_login</code>, so on the <em>Run import (PHP)</em> path only, a login is synthesized from the e-mail / display name / legacy id when you skip it (and made unique on collision). On the <em>fast-SQL</em> path even login/e-mail stay empty if unmapped.</td></tr>
			<tr><th>Extra</th><td>Add <strong>user meta</strong> or <strong>ACF (user) fields</strong> under Additional mappings.</td></tr>
		</table>
		<p class="dbmig-note"><strong>Why migrate users?</strong> So posts can link to real authors. After migrating the authors, a post migration's <em>Author</em> field can resolve to them (see §7).</p>
	</div>

	<!-- 5 -->
	<div class="dbmig-card" id="g-join">
		<h2>5. Joining related tables</h2>
		<p>Use the <strong>“Related tables (JOINs)”</strong> panel in Step 2 (always visible; each join is shown as its own boxed row) to pull columns from other source tables into your mapping.</p>
		<p>Each join row is all dropdowns:</p>
		<table class="dbmig-guide-table">
			<tr><th>Type</th><td><code>LEFT JOIN</code> (keep rows even without a match) or <code>INNER JOIN</code>.</td></tr>
			<tr><th>Table</th><td>The table to join.</td></tr>
			<tr><th>ON … = …</th><td>Two column dropdowns. The left dropdown lists the <strong>base table AND every table joined above</strong> — so you can <strong>chain</strong> joins.</td></tr>
		</table>
		<p>The table dropdowns list both the <strong>Source DB</strong> tables and your <strong>Current WordPress DB</strong> tables (grouped). So you can join to already-migrated content — e.g. join <code>wp_posts</code> from the current DB to copy a column, or reach a related post. (To simply turn a legacy id into the migrated WP id, the <em>Resolve</em> transform in §3 is simpler and also filters by the source table.)</p>
		<p><strong>Extra conditions (+ condition).</strong> A join matches on one column pair, but you can add more <strong>AND / OR</strong> conditions with the <em>+ condition</em> button — pick a column, an operator, and a constant value. This is important when you join the current <code>wp_posts</code> on <code>legacy_id</code> and have <strong>several post types</strong>: legacy ids can repeat across types, so add <code>AND wp_posts.post_type = your_type</code> (and/or <code>AND wp_posts.legacy_table_name = your_source_table</code>) so the join can't match a row from another post type. (AND binds tighter than OR.)</p>
		<p><strong>Chaining example</strong> — news → junction → category:</p>
		<pre class="dbmig-example">Join 1:  LEFT JOIN  news_categories   ON  news.id            = news_categories.news_id
Join 2:  LEFT JOIN  category          ON  news_categories.cat_id = category.id</pre>
		<p>Join 2's left column refers to the <em>first joined table</em>, not the base. Add joins <strong>top-to-bottom in dependency order</strong>.</p>
		<p class="dbmig-warn"><strong>Important:</strong> a one-to-many join (a junction) produces several rows per source record. That is exactly what you want for <em>taxonomy</em> and <em>repeaters</em>, but such joins are <strong>only used by the taxonomy / repeater logic</strong> — the plugin never lets them create duplicate posts. Use joins for post fields only when the relation is one-to-one (e.g. a single author or source row).</p>

		<h3 style="margin-top:1.4em">Row filter — which rows to migrate <span style="font-weight:normal">(optional)</span></h3>
		<p>Below the joins is a <strong>“Row filter”</strong> panel that lets you restrict, order and slice the source rows before migrating. It applies everywhere — the generated SQL, the PHP <em>Run import</em>, the row <em>count</em> and the <em>Preview</em> all obey it.</p>
		<table class="dbmig-guide-table">
			<tr><th>WHERE condition</th><td>A raw boolean expression on the <strong>source table's own columns</strong> (no <code>WHERE</code> keyword), e.g. <code>status = 'published'</code> or <code>created_at &gt;= '2020-01-01'</code>. Leave blank for all rows. Semicolons and SQL comments are stripped, so it can only ever be a single filter — but it is otherwise raw SQL, so test it. It filters the <em>base</em> rows only (not joined tables).</td></tr>
			<tr><th>Order by + ASC/DESC</th><td>Which column decides the order. This only matters together with a limit/offset (it picks <em>which</em> rows a slice takes). Defaults to the ID column so a slice stays stable across batches.</td></tr>
			<tr><th>Limit / Offset</th><td><strong>Limit</strong> = the maximum number of rows to migrate (0 = no limit). <strong>Offset</strong> = how many rows to skip first. Together they migrate a range — e.g. <code>limit 1000 offset 2000</code> is rows 2001–3000.</td></tr>
		</table>
		<p>Two common uses: <strong>test</strong> a mapping on a handful of rows (<code>limit 10</code>) before committing, and <strong>migrate in chunks</strong> (run <code>offset 0</code>, then <code>offset 1000</code>, …) for a very large table. Chunked runs <strong>accumulate</strong> — a later slice never wipes the meta, terms or repeater rows written by an earlier one.</p>
	</div>

	<!-- 6 -->
	<div class="dbmig-card" id="g-tax">
		<h2>6. Mapping a taxonomy (categories / tags)</h2>
		<ol class="dbmig-steps-list">
			<li>Add the <strong>join(s)</strong> that connect the base table to the table holding the term name (§5).</li>
			<li>In <strong>Additional mappings</strong>, add a row: <strong>Kind = Taxonomy</strong>.</li>
			<li><strong>Target</strong> = the WordPress taxonomy (e.g. <code>category</code>). <strong>Source column</strong> = the term-name column (e.g. <code>category.name</code>).</li>
			<li><strong>Slug</strong> — leave on <em>“— auto from name —”</em> to have WordPress derive the slug, or pick a source column (e.g. <code>category.slug</code>) to migrate the existing slug <em>as-is</em>. If that column is empty for a given row, the slug falls back to being derived from the name.</li>
			<li>Tick <strong>“create if missing”</strong> so terms are created automatically.</li>
			<li>Tick <strong>“allow multiple (append)”</strong> when a record can have several terms (a junction table). This attaches <em>every</em> term instead of keeping only the last one.</li>
		</ol>
		<p>Terms are created (slug taken from your mapped column, or derived from the name), assigned by walking the join chain, and term counts are updated — all handled for you, fast, even for hundreds of thousands of relationships.</p>

		<h3 class="dbmig-subhead">Migrating a whole term table (with meta / ACF)</h3>
		<p>The steps above create terms as a side-effect of a <em>post</em> migration. When your legacy DB has a dedicated category/tag table you want to bring over in full — with its own <strong>meta</strong> and <strong>ACF term fields</strong> — set <strong>“Migrate into” = Taxonomy terms</strong> (Step 1) instead. Then:</p>
		<ol class="dbmig-steps-list">
			<li>Pick the target <strong>Taxonomy</strong> (its ACF term fields load automatically).</li>
			<li>Choose the legacy term table + its id column (Step 2).</li>
			<li>Map the term fields: <strong>Name</strong>, <strong>Slug</strong> (blank → derived from name), <strong>Description</strong>, and <strong>Parent</strong>. For Parent, map the legacy parent-id column and set its transform to <strong>“Resolve → migrated term ID”</strong> so it points at the already-migrated parent.</li>
			<li>Add <strong>Term meta</strong> or <strong>ACF field</strong> rows under <em>Additional mappings</em> for anything extra.</li>
			<li>Run it <em>before</em> the posts that reference these terms. Idempotent by legacy id, so re-runs update in place.</li>
		</ol>
	</div>

	<!-- 7 -->
	<div class="dbmig-card" id="g-author">
		<h2>7. Mapping the author (post_author)</h2>
		<p>WordPress authors are <strong>users</strong>, so:</p>
		<ol class="dbmig-steps-list">
			<li><strong>First</strong> migrate the legacy authors as a <em>Users</em> migration (§4). This stamps each with its legacy id.</li>
			<li>In the post migration, find the <strong>Author (post_author)</strong> row in the field list.</li>
			<li>Set its <strong>source column</strong> to the legacy author-id column (e.g. <code>articles.author_id</code>).</li>
			<li>In its Options, set <strong>“Resolve from migrated user table”</strong> to the legacy table you migrated the authors from.</li>
		</ol>
		<p>On run, each post's <code>post_author</code> is resolved from the legacy author id to the matching WordPress user.</p>
		<p class="dbmig-note">If you leave “Resolve from…” empty, the source value is used as a raw WordPress user ID instead.</p>
	</div>

	<!-- 8 -->
	<div class="dbmig-card" id="g-acf">
		<h2>8. ACF fields, relationships &amp; repeaters</h2>
		<ul class="dbmig-bullets">
			<li><strong>Normal ACF fields</strong> appear automatically in the Step 3 field list for the chosen post type (or for users). Map them like any other field.</li>
			<li><strong>An ACF field not shown</strong> (its group is attached elsewhere)? Add it under <strong>Additional mappings → ACF field (single)</strong> and pick it from the list.</li>
			<li><strong>ACF relationship / post-object</strong> fields have a <strong>"Match by"</strong> option:
				<ul class="dbmig-bullets" style="margin-top:4px">
					<li><em>Migrated legacy id</em> — the source column holds the related record's legacy id; pick the <strong>referenced legacy table</strong> and it resolves to the already-migrated post (migrate those first).</li>
					<li><em>Already a WP post ID (from a join)</em> — use this when you <strong>join the current <code>wp_posts</code></strong> (on <code>legacy_id</code>) and map the field to <code>wp_posts.ID</code>: the value is already the target post's WP id, so it's stored directly. This is the way to link to already-migrated content — including <strong>one-to-many</strong> relationships through a junction (e.g. a video → many related news). All matched ids are aggregated into one ACF value automatically; add a join <em>condition</em> like <code>AND wp_posts.legacy_table_name = '&lt;source table&gt;'</code> so it only matches the right post type.</li>
					<li><em>Post title / Post slug / Post meta</em> — match the source value against a field in the <strong>current</strong> WordPress database (choose the target post type; for meta, the meta key). Use this when the relation points at existing content by title/slug/meta rather than a legacy id.</li>
				</ul>
			</li>
			<li><strong>ACF repeaters</strong> (Step 4): map a one-to-many <strong>child table</strong> into a repeater. Each child row becomes one repeater row; map each sub-field to a child column. Generated by both run modes (fast SQL and Run import). Two things matter:
				<ul class="dbmig-bullets" style="margin-top:4px">
					<li><strong>Child FK column</strong> = the column on the <em>child</em> table that points back to the parent (e.g. <code>prizes.event_id</code>) — <strong>not</strong> the child's own primary key. Getting this wrong yields empty or one-row repeaters. The <strong>matches</strong> dropdown next to it is what the FK equals (blank = the parent source id).</li>
					<li><strong>Indirect linkage (“Link via”).</strong> When the child reaches the parent through <em>another table</em> (e.g. <code>chip_count_rows → chip_counts → events</code>), add the intermediate with <strong>+ link table</strong>: pick the table and its ON columns (<code>chip_counts.event_id = events.id</code>), then set <strong>Child FK</strong> = <code>chip_count_id</code> and <strong>matches</strong> = <code>chip_counts.id</code>. You can chain several.</li>
					<li><strong>“latest by”</strong> on a link-via table: when the intermediate has <em>many</em> rows per parent but you only want the newest one (e.g. the <em>current</em> chip-count snapshot per event, not every historical one), set <strong>latest by</strong> to a date/id column (<code>created_at</code>). Only that latest row's children are used — otherwise every snapshot's rows pile up.</li>
				</ul>
			</li>
		</ul>
		<p class="dbmig-note">ACF must be active for these. If it isn't, ACF targets fall back to plain post/user meta.</p>
	</div>

	<!-- 9 -->
	<div class="dbmig-card" id="g-media">
		<h2>9. Media / images</h2>
		<ol class="dbmig-steps-list">
			<li>Upload the image files yourself into <code>wp-content/uploads</code> (the current-month folder).</li>
			<li>Add a mapping: <strong>Additional mappings → Media (attachment)</strong>, source = the DB column holding the file name (bare name, path, or URL — only the file name is used).</li>
			<li>Choose how to attach it: <strong>Featured image</strong>, <strong>Post/User meta</strong> (stores the attachment ID), <strong>ACF image field</strong>, or <strong>Attachment only</strong>.</li>
		</ol>
		<p>Attachments are created <strong>without</strong> resized versions (fast). Generate the sizes afterwards, either from <a href="<?php echo esc_url( $settings_url ); ?>">Settings → Media tools → Generate missing sizes</a>, or with WP-CLI:</p>
		<pre class="dbmig-example">wp media regenerate --only-missing</pre>
		<p class="description"><code>--only-missing</code> skips attachments that already have their thumbnails, so it is safe and fast to re-run after each migration.</p>

		<h3 class="dbmig-subhead">Importing a media table as attachments</h3>
		<p>Use this when your legacy DB has its own <strong>media/attachments table</strong> and you want each record to become a Media Library entry — then you drop the actual files in yourself. Set <strong>“Migrate into” = Media attachments</strong> (Step 1). It's mapped exactly like a post migration (post type is fixed to <code>attachment</code>, status <code>inherit</code>). Map the attachment fields:</p>
		<ul class="dbmig-bullets">
			<li><strong>Title / Slug / Description / Caption / Date / Author</strong> — as for any post.</li>
			<li><strong>File URL (guid)</strong> — the full URL to the file (recommended). The <strong>MIME type is derived automatically</strong> from the file extension in this URL (e.g. <code>.jpg → image/jpeg</code>), so there is no MIME-type field to map.</li>
			<li><strong>Attached to — post ID (post_parent)</strong> — optional; use the <em>Resolve → migrated post</em> transform if you have the parent's legacy id.</li>
			<li><strong>Add a Post-meta row</strong> under <em>Additional mappings</em>: key <code>_wp_attached_file</code>, value = the file path <em>relative to</em> <code>wp-content/uploads</code> (e.g. <code>2024/03/photo.jpg</code>). This is what links the attachment to its file — don't skip it.</li>
		</ul>
		<p><strong>After running the attachment migration:</strong></p>
		<ol class="dbmig-steps-list">
			<li>Copy the actual files into <code>wp-content/uploads</code> at the paths you mapped into <code>_wp_attached_file</code>.</li>
			<li>Build the thumbnails and attachment metadata — either from <a href="<?php echo esc_url( $settings_url ); ?>">Settings → Media tools → Generate missing sizes</a>, or with WP-CLI:
				<pre class="dbmig-example">wp media regenerate --only-missing</pre>
			</li>
		</ol>
		<p class="dbmig-warn"><strong>Heads-up:</strong> <code>wp media regenerate</code> reads <code>_wp_attached_file</code> to locate each file. If that meta row is missing or the file isn't on disk at that path yet, it reports <code>Can't find "&lt;title&gt;"</code> and skips the attachment — so map <code>_wp_attached_file</code> and place the files <em>before</em> you regenerate. Idempotent by legacy id, like every migration.</p>

		<h3 class="dbmig-subhead">Preparing the source data first (a common adjustment)</h3>
		<p>Sometimes the legacy column isn't quite in the shape a field needs, and the easiest fix is to add a prepared column <em>in the source database</em> and map that instead. The plugin only reads the source DB, so these one-off <code>ALTER</code>/<code>UPDATE</code> statements are yours to run in phpMyAdmin / the MySQL client before mapping.</p>
		<p><strong>Example</strong> — the image column holds a bare path like <code>images/news/x.jpg</code>, but the attachment <strong>File URL (guid)</strong> wants a full URL. Build a <code>new_image</code> column, then map <em>that</em> to guid.</p>
		<p>1) Add the new column:</p>
		<pre class="dbmig-example">ALTER TABLE wp_publish_news
ADD COLUMN new_image TEXT NULL AFTER image;</pre>
		<p>2) Fill it with the full URL for every row that has an image:</p>
		<pre class="dbmig-example">UPDATE wp_publish_news
SET new_image = CONCAT(
    'http://localhost/migration/wp-content/uploads/',
    image
)
WHERE image IS NOT NULL
  AND TRIM(image) &lt;&gt; '';</pre>
		<p>If your <code>image</code> value already <strong>starts with a slash</strong> (e.g. <code>/images/test.jpg</code>), drop the trailing slash from the base to avoid a double slash:</p>
		<pre class="dbmig-example">UPDATE wp_publish_news
SET new_image = CONCAT(
    'http://localhost/migration/wp-content/uploads',
    image
)
WHERE image IS NOT NULL
  AND TRIM(image) &lt;&gt; '';</pre>
		<p class="description">Adjust the base URL to your site (<code>http://localhost/migration</code> here) and the <code>uploads</code> sub-path to wherever the files actually live. Then in the migration, map <code>new_image</code> to <strong>File URL (guid)</strong>, and map the matching relative path to <code>_wp_attached_file</code>. The same "prepare a column, then map it" trick works anywhere — building a slug, normalising a date, concatenating a title, etc.</p>
	</div>

	<!-- 10 -->
	<div class="dbmig-card" id="g-comment">
		<h2>10. Migrating comments</h2>
		<p>Set <strong>“Migrate into” = Comments</strong> to bring a legacy comments table into WordPress (<code>wp_comments</code>). Same idempotent legacy-id/table tracking as everything else — re-runs update in place. <strong>Migrate the posts first</strong> so each comment can attach to one.</p>
		<table class="dbmig-guide-table">
			<tr><th>Attach to a post</th><td>Map the legacy post-id column to <strong>Post (comment_post_ID)</strong> and set its transform to <strong>“Resolve → migrated post”</strong>, choosing the legacy posts table. Each comment then lands on the right migrated post.</td></tr>
			<tr><th>Content &amp; author</th><td><code>comment_content</code>, <code>comment_author</code>, <code>comment_author_email</code>, <code>comment_author_url</code>, <code>comment_author_IP</code>, <code>comment_date</code>.</td></tr>
			<tr><th>Registered commenter</th><td>If the commenter was a logged-in user, map the legacy user id to <strong>Registered user (user_id)</strong> with <strong>“Resolve → migrated user”</strong> (migrate the users first).</td></tr>
			<tr><th>Threaded replies</th><td>Map the legacy parent-comment id to <strong>Parent comment (comment_parent)</strong> with <strong>“Resolve → migrated comment”</strong> (referenced table = the same comments table). Parents are linked in a second pass after all comments exist, so ordering doesn’t matter.</td></tr>
			<tr><th>Approved / type</th><td><code>comment_approved</code> (defaults to <code>1</code> = approved; map a column for spam/pending), <code>comment_type</code>.</td></tr>
			<tr><th>Extra</th><td>Add <strong>Comment meta</strong> rows under <em>Additional mappings</em> for anything else.</td></tr>
		</table>
		<p>After running, each post’s <code>comment_count</code> is recalculated automatically from its approved comments.</p>
	</div>

	<!-- 11 -->
	<div class="dbmig-card" id="g-run">
		<h2>11. Running a migration (3 ways)</h2>
		<table class="dbmig-guide-table">
			<tr><th>Run SQL (fast)</th><td>Executes the generated create-or-update SQL directly from the browser — posts, meta, taxonomies and author linking — with a progress bar. Best for large tables. <em>Recommended.</em></td></tr>
			<tr><th>Run import (PHP)</th><td>Row-by-row PHP. Slower; a good cross-check, and it hashes user passwords properly. (ACF repeaters now generate as SQL too, so the fast path covers them.)</td></tr>
			<tr><th>Generate SQL file</th><td>Writes a <code>.sql</code> file (in the plugin's <code>exports/</code> folder) plus a ready-to-run <code>mysql</code> command, to run yourself in a terminal.</td></tr>
		</table>
		<p><strong>Typical big migration:</strong> <em>Run SQL (fast)</em> handles everything — posts, meta, taxonomy, authors, ACF repeaters and single relationships. Use <em>Run import (PHP)</em> only if you need real password hashes or want a row-by-row cross-check.</p>
		<p><strong>👁 Preview 1st row</strong> (next to the run buttons) does a dry run against the <em>first</em> source row and shows, per mapped field, the <em>raw</em> source value and the value that would actually be written (after transforms, resolves, term lookups), plus how many rows each repeater would get. Nothing is written — use it to sanity-check a mapping before committing to a full run. Works on an unsaved profile too, so you can preview while you map.</p>

		<h3 class="dbmig-subhead">Local → server (export / import)</h3>
		<p>Build and test your migrations on local, then move them to the live server without redoing the mapping. On the <strong>Migrations</strong> list page:</p>
		<ul class="dbmig-bullets">
			<li><strong>⬇ Export migrations</strong> downloads a JSON file with <em>all</em> your migration profiles (mappings, joins, conditions, transforms — everything except the data).</li>
			<li>On the server, open the same list page and click <strong>⬆ Import migrations</strong>, choose that file. Profiles are matched by id, so importing <em>updates</em> the same migrations in place (safe to re-export/re-import as you iterate).</li>
			<li>The <strong>database connection</strong> isn't part of the export (it's environment-specific). Configure it once on the server in <a href="<?php echo esc_url( $settings_url ); ?>">Settings</a>, run <em>Ensure schema</em>, then run the imported migrations as usual.</li>
		</ul>

		<p><strong>Partial update</strong> (checkbox in Step 1, next to the migration name): tick it to <em>only</em> update rows that were already migrated, writing just the fields you mapped — no new rows are created, and unmapped columns are left untouched. Use it when you finish a migration and later want to fill in one extra field: map only that field, tick Partial update, and re-run. Leave it unticked for the initial full migration. Works on all three run modes and for posts, users and terms.</p>
	</div>

	<!-- 12 -->
	<div class="dbmig-card" id="g-normalize">
		<h2>12. Users from names — a name-only column (no ids)</h2>
		<p>Sometimes the legacy data has <strong>no user table and no id</strong> — just an author <em>name</em> repeated on every post. There's nothing unique to migrate users by, and nothing for the post's author to resolve against. The <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DBMig_Admin::NORMALIZE_SLUG ) ); ?>"><strong>Users from names</strong></a> tool (<strong>DB Migrator → Users from names</strong>) fixes that by giving each distinct name a real id — <em>in the legacy database</em> — so the ordinary id-based flow takes over.</p>
		<ol class="dbmig-steps-list">
			<li><strong>Step 1 — Choose the name to normalize.</strong> Pick the <strong>source table</strong> and the <strong>name column</strong> (e.g. <code>posts.author_name</code>). Leave <em>Trim whitespace</em> ticked so stray spaces don't split a name into two.</li>
			<li><strong>Step 2 — New table &amp; id column.</strong> Optionally rename the <strong>users lookup table</strong> (default <code>dbmig_authors</code>) and the <strong>new id column</strong> added to the source table (default <code>author_id</code>).</li>
			<li><strong>Step 3 — Preview &amp; run.</strong> <strong>Preview &amp; generate SQL</strong> shows how many distinct names and rows will be affected, plus the exact SQL; that unlocks the <strong>Run against legacy DB</strong> button. Changing any field above re-locks Run until you preview again.</li>
		</ol>
		<p>It runs four idempotent statements against the legacy DB: create a lookup table with an auto-increment <code>id</code> and a <strong>UNIQUE</strong> <code>name</code>; insert the <code>DISTINCT</code> names (so the <em>same name yields exactly one id</em>, case-insensitive under the column's collation); add the id column; and fill it by matching each row's name. Whitespace is trimmed and blank/NULL names are skipped.</p>
		<p class="dbmig-warn"><strong>Needs write access:</strong> unlike the rest of the plugin (which only reads the legacy DB), this <em>writes</em> to it — the connection user needs <code>CREATE</code>, <code>ALTER</code>, <code>INSERT</code> and <code>UPDATE</code>. A read-only server user will fail; run it where you have those rights (typically local). Safe to re-run.</p>
		<p><strong>Then migrate normally:</strong> a <a href="#g-user">User migration</a> from the new lookup table (id column = <code>id</code>, Display name = <code>name</code>), and in the post migration map <strong>Author</strong> to the new id column with <a href="#g-author">“Resolve from migrated user table”</a> pointing at the lookup table.</p>
	</div>

	<!-- 13 -->
	<div class="dbmig-card" id="g-tips">
		<h2>13. Key rules &amp; gotchas</h2>
		<ul class="dbmig-bullets">
			<li><strong>Blank = skip.</strong> Any field row with no source column is left untouched.</li>
			<li><strong>Re-runs are safe.</strong> Everything matches by the legacy link, so re-running updates instead of duplicating.</li>
			<li><strong>Order matters.</strong> Referenced data (users, related posts) must be migrated before the data that links to it.</li>
			<li><strong>Joins for post fields must be one-to-one.</strong> One-to-many joins belong to taxonomy / repeaters only.</li>
			<li><strong>Different collations are handled.</strong> The source and WordPress databases can use different collations.</li>
			<li><strong>Big tables:</strong> the generated SQL uses indexed, set-based operations. If the server feels slow, raise <code>innodb_buffer_pool_size</code> (MySQL defaults to a tiny 128&nbsp;MB).</li>
			<li><strong>Users' passwords:</strong> not migrated as working logins via SQL (they aren't WordPress hashes). Use “Run import” or have users reset their password.</li>
			<li><strong>Auto slug from title</strong> (checkbox in Step 1; posts &amp; users): on the <em>Run SQL (fast)</em> path, fills the slug when no slug column is mapped (or the mapped one is blank) — posts take <code>post_name</code> from the title, users take <code>user_nicename</code> from the display name. The fast path inserts raw rows, so without this the slug stays empty; the <em>Run import (PHP)</em> path already derives slugs itself. A mapped slug always wins. Taxonomy terms already generate a slug from the name automatically. Slugs are made URL-safe like WordPress does — punctuation is stripped and accented letters are transliterated to ASCII (<code>é→e</code>, <code>ã→a</code>, <code>ç→c</code>), so an accented title can't produce a slug that 404s. (Requires MySQL&nbsp;8+.)</li>
			<li><strong>Preserve source IDs</strong> (checkbox in Step 1; posts, users, terms &amp; comments): keeps each row's original id as the WordPress <code>ID</code> / <code>term_id</code> / <code>comment_ID</code> on insert. Only new rows are affected — already-migrated rows keep the id they got. The target ids must be free; a clash with an existing id will error. Handy when other data references content by its old id. Use the <em>Run SQL (fast)</em> path — it honours this for every type (the PHP import path preserves posts only).</li>
		</ul>
	</div>
</div>
