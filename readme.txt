=== DB Migrator ===
Migrate data from an external (legacy) MySQL database into WordPress posts,
post meta, taxonomies and ACF fields with a visual field-mapping panel.

== What it does ==

Connect to a legacy MySQL database (built in any technology), then visually map
its tables and columns onto WordPress post types, post meta, taxonomies and ACF
fields — including ACF repeaters and ACF relationship fields. The plugin keeps a
permanent link between every WordPress post and its source row so cross-table
relations resolve correctly and re-running a migration updates instead of
duplicating.

== How the legacy relation works ==

Two columns are added to wp_posts:

  * legacy_id          — the primary key value of the source row
  * legacy_table_name  — which source table the row came from

Together they uniquely identify a migrated row. They are used to:
  * make re-runs idempotent (match -> update, no duplicates)
  * resolve ACF relationship / post-object fields (a legacy foreign key is
    looked up against these columns to find the already-migrated WP post)

A copy is also stored as post meta (_dbmig_legacy_id, _dbmig_legacy_table) for
convenient WP_Query lookups.

== Setup ==

1. Activate the plugin. (Activation adds the legacy columns automatically.)
2. DB Migrator -> Settings:
   - Enter the external DB host / name / user / password and "Test connection".
   - Confirm "Ensure schema" shows the legacy columns are present.

== Creating a migration ==

DB Migrator -> Add migration:

  Step 1 Target        Choose "Migrate into": Posts or Users.
                         - Posts: pick the post type (auto-listed from all
                           registered types) and the status to import as.
                         - Users: pick the default role for migrated users.
  Step 2 Source table  Pick the legacy table and its ID column. Optionally add
                       JOINs to pull in columns from related tables (use
                       qualified names like authors.name).
  Step 3 Field mapping Every WordPress field is listed on the LEFT automatically
                       (post defaults + the post type's ACF fields, or the user
                       profile fields for a Users migration). You only pick the
                       SOURCE COLUMN on the right. Leave a row blank to skip that
                       field. Field kinds shown:
                         - Post / User field  (post_title, user_email, ...)
                         - ACF field          (from the type's field groups)
                         - ACF relation       (relationship/post_object fields;
                                               resolves a legacy id -> migrated
                                               post via the referenced table)
                       The special "Author (post_author)" row can resolve a
                       legacy user id to a migrated WordPress user (set the
                       "Resolve from migrated user table" option).
                       Each row has an optional value transform (int, bool, date,
                       strip tags, JSON / unserialize, ...).
                       An "Additional mappings" sub-section adds custom post/user
                       meta keys, taxonomy assignments, and single ACF fields that
                       are not auto-listed (e.g. an ACF field whose group location
                       does not match the current post type / users) — pick any
                       ACF field from the list and map its source column.
                       (ACF repeaters are configured in Step 4.)
         Media          Under "Additional mappings" choose "Media (attachment)".
                       Upload the image files yourself into wp-content/uploads
                       (the current-month folder), then map the DB column holding
                       the file name. On import the plugin creates a WordPress
                       attachment pointing at that file WITHOUT generating resized
                       versions (fast), and attaches it as one of: Featured image
                       (posts), Post/User meta (stores the attachment ID), ACF
                       image field, or Attachment-only. The source value may be a
                       bare name, a path, or a URL — only the file name is used.
                       Re-runs reuse the existing attachment (no duplicates).
                       Generate the resized versions later with Settings ->
                       "Media tools -> Generate missing sizes".

  Step 4 ACF repeaters Map a one-to-many child table into an ACF repeater (works
                       for both post and user migrations). Each child row becomes
                       a repeater row; map each sub-field to a child column.
                       Sub-fields can themselves resolve relations.
  Step 5 Save & run    Save, then "Run import" (processed in batches with a live
                       progress bar and log). "Generate SQL" outputs fast,
                       same-server, idempotent create-or-update SQL for BOTH post
                       and user migrations (see "Generated SQL" below).

== Migration order ==

Run migrations in dependency order: migrate the tables that are *referenced*
first (e.g. users/authors, categories) so that post_author and ACF relationship
fields on later migrations can resolve their legacy ids to existing WP users /
posts. A typical order is: Users -> taxonomy source tables -> Posts.

Users keep their legacy link in usermeta (_dbmig_legacy_id / _dbmig_legacy_table)
rather than on wp_users, so re-running a user migration is idempotent too.

== Generated SQL (fast path) ==

"Generate SQL" produces same-server cross-database statements for posts OR users.
It is idempotent (create-or-update) so running it more than once never creates
duplicates:

  - Main row : UPDATE the existing row (matched by the legacy key) + INSERT only
               rows that have not been migrated yet.
  - Meta     : DELETE the existing meta for that key on migrated rows, then INSERT
               (so meta never piles up on re-runs).
  - post_author and single ACF relationship / post-object values are resolved to
    the migrated WP user / post via sub-queries on the legacy link.

All cross-database string comparisons are collation-normalised, so a legacy DB
whose collation differs from the WordPress DB still works.

Clicking "Generate SQL" writes a timestamped .sql file into the plugin's
exports/ folder (protected from direct web access) and shows:
  - the absolute file path,
  - a "Download .sql" button (authenticated), and
  - a ready-to-run import command, e.g.:
        mysql -h localhost -u root <wp_db> < "/path/to/exports/<name>.sql"
Run the command in a terminal that has the mysql client on PATH (the Laragon
Terminal does). If the DB has a password, mysql will prompt for it. The SQL
selects the WordPress database and fully-qualifies the legacy database, so it
needs read access to the legacy DB and write access to the WordPress DB.

Taxonomies ARE handled by the generated SQL: terms are created from the taxonomy
source table (slug derived from the name) and assigned to posts by walking the
join chain (base -> junction -> detail), so many-to-many relations attach every
term; term counts are updated. Runs in seconds even for hundreds of thousands of
relationships.

Still run these via "Run import" (not expressible as bulk SQL): ACF repeaters and
multi-value ACF relationships. NOTE
for users: passwords written by SQL are stored as-is and are not phpass hashes —
map a column that already holds WP hashes, run "Run import" (which hashes), or
have users reset their password.

== Notes ==

  * ACF is optional. When ACF is active, values are written with update_field()
    so serialization, repeaters and field-key meta are handled correctly. When
    ACF is not active, ACF/repeater targets fall back to plain post meta.
  * The plugin only reads from the external database; it never writes to it.
  * Re-running a saved migration is always safe (idempotent by legacy key).
