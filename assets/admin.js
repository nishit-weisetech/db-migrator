/* global jQuery, DBMig */
( function ( $ ) {
	'use strict';

	var Ajax = {
		post: function ( action, data ) {
			data = data || {};
			data.action = 'dbmig_' + action;
			data.nonce = DBMig.nonce;
			return $.post( DBMig.ajaxUrl, data );
		}
	};

	var RELATIONAL = [ 'relationship', 'post_object', 'page_link' ];

	/* ------------------------------------------------------------------ *
	 *  Settings page: test connection
	 * ------------------------------------------------------------------ */
	function bindMediaTools() {
		var $scan = $( '#dbmig-media-scan' );
		if ( ! $scan.length ) {
			return;
		}
		var pendingIds = [];

		$scan.on( 'click', function () {
			var $res = $( '#dbmig-media-result' ).removeClass( 'ok err' ).text( 'Scanning…' );
			Ajax.post( 'media_scan', { plugin_only: $( '#dbmig-media-pluginonly' ).is( ':checked' ) ? 1 : 0 } ).done( function ( r ) {
				if ( r.success ) {
					pendingIds = r.data.ids || [];
					$res.addClass( 'ok' ).text( r.data.total + ' attachment(s) missing sizes.' );
					$( '#dbmig-media-generate' ).prop( 'disabled', pendingIds.length === 0 );
				} else {
					$res.addClass( 'err' ).text( r.data.message );
				}
			} );
		} );

		$( '#dbmig-media-generate' ).on( 'click', function () {
			if ( ! pendingIds.length ) { return; }
			var total = pendingIds.length, index = 0, done = 0, fail = 0, batch = 10;
			$( '#dbmig-media-generate, #dbmig-media-scan' ).prop( 'disabled', true );
			$( '#dbmig-media-progress-wrap' ).show();

			var step = function () {
				var slice = pendingIds.slice( index, index + batch );
				if ( ! slice.length ) {
					$( '#dbmig-media-bar' ).css( 'width', '100%' );
					$( '#dbmig-media-progress-text' ).text( 'Done — ' + done + ' generated, ' + fail + ' failed.' );
					$( '#dbmig-media-scan' ).prop( 'disabled', false );
					pendingIds = [];
					return;
				}
				Ajax.post( 'media_generate', { ids: slice } ).done( function ( r ) {
					if ( r.success ) { done += r.data.done; fail += r.data.fail; }
					index += slice.length;
					var pct = Math.round( ( index / total ) * 100 );
					$( '#dbmig-media-bar' ).css( 'width', pct + '%' );
					$( '#dbmig-media-progress-text' ).text( index + ' / ' + total + ' processed (' + done + ' generated, ' + fail + ' failed)' );
					step();
				} ).fail( function () {
					$( '#dbmig-media-progress-text' ).text( 'Request failed at ' + index + '/' + total );
					$( '#dbmig-media-scan' ).prop( 'disabled', false );
				} );
			};
			step();
		} );
	}

	function bindSettings() {
		bindMediaTools();
		var $btn = $( '#dbmig-test-connection' );
		if ( ! $btn.length ) {
			return;
		}
		$btn.on( 'click', function () {
			var $res = $( '#dbmig-test-result' );
			$res.removeClass( 'ok err' ).text( DBMig.i18n.testing );
			Ajax.post( 'test_connection', {
				config: {
					host: $( '#dbmig_host' ).val(),
					dbname: $( '#dbmig_dbname' ).val(),
					dbuser: $( '#dbmig_dbuser' ).val(),
					dbpass: $( '#dbmig_dbpass' ).val()
				}
			} ).done( function ( r ) {
				if ( r.success ) {
					$res.addClass( 'ok' ).text( r.data.message + ' (' + r.data.table_count + ' tables)' );
				} else {
					$res.addClass( 'err' ).text( r.data.message );
				}
			} ).fail( function () {
				$res.addClass( 'err' ).text( 'Request failed.' );
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 *  Editor
	 * ------------------------------------------------------------------ */
	var Editor = {
		$root: null,
		profile: {},
		tables: [],
		columnsByTable: {},
		acfFields: [],
		allAcfFields: [],   // every single ACF field across all groups (for manual mapping)
		allAcfLoaded: false,
		acfActive: false,
		postFields: {},
		userFields: {},
		termFields: {},
		attachmentFields: {},
		commentFields: {},
		pendingFixed: {},   // kind|target -> {source,transform,rel_table} to restore after build

		init: function () {
			this.$root = $( '.dbmig-editor' );
			if ( ! this.$root.length ) {
				return;
			}
			try {
				this.profile = JSON.parse( this.$root.attr( 'data-profile' ) ) || {};
			} catch ( e ) {
				this.profile = {};
			}
			this.postFields = DBMig.postFields || {};
			this.userFields = DBMig.userFields || {};
			this.termFields = DBMig.termFields || {};
			this.attachmentFields = DBMig.attachmentFields || {};
			this.commentFields = DBMig.commentFields || {};

			this.fillRoles();
			this.fillTaxonomies();
			this.bind();
			this.initSearch();
			this.loadAllAcf();
			this.loadTables().then( this.hydrate.bind( this ) );
		},

		/**
		 * Make every dropdown searchable (Select2). A MutationObserver re-applies
		 * it whenever selects/options are (re)built, so dynamically-added rows and
		 * refreshed option lists get search too. Mutations inside the run/log/list
		 * areas are ignored to avoid needless churn during a migration run.
		 */
		initSearch: function () {
			if ( ! $.fn.select2 || ! window.MutationObserver ) {
				return;
			}
			var self = this;
			var excluded = '#dbmig-sqlrun-wrap, #dbmig-log-wrap, #dbmig-progress-wrap, #dbmig-sql-rows, #dbmig-sql-list, #dbmig-media-progress-wrap';
			this._mo = new MutationObserver( function ( muts ) {
				for ( var i = 0; i < muts.length; i++ ) {
					var t = muts[ i ].target;
					if ( t && 1 === t.nodeType && ! $( t ).closest( excluded ).length ) {
						clearTimeout( self._st );
						self._st = setTimeout( function () { self.searchifyAll(); }, 150 );
						return;
					}
				}
			} );
			this._mo.observe( this.$root[ 0 ], { childList: true, subtree: true } );
			this.searchifyAll();
		},

		searchifyAll: function () {
			if ( ! $.fn.select2 ) {
				return;
			}
			if ( this._mo ) { this._mo.disconnect(); }
			this.$root.find( 'select' ).each( function () {
				var $s = $( this );
				if ( $s.hasClass( 'select2-hidden-accessible' ) ) {
					$s.select2( 'destroy' );
				}
				$s.select2( { width: '100%', dropdownAutoWidth: false } );
			} );
			if ( this._mo ) { this._mo.observe( this.$root[ 0 ], { childList: true, subtree: true } ); }
		},

		loadAllAcf: function () {
			var self = this;
			if ( ! DBMig.acfActive ) {
				return $.Deferred().resolve().promise();
			}
			return Ajax.post( 'get_acf_fields', { context: 'all' } ).done( function ( r ) {
				if ( r.success ) {
					self.allAcfFields = r.data.fields || [];
					self.allAcfLoaded = true;
					self.refreshExtraAcf();
				}
			} );
		},

		allAcfOptions: function () {
			var html = '<option value="">— select ACF field —</option>';
			this.allAcfFields.forEach( function ( f ) {
				var label = f.label + ' (' + f.name + ', ' + f.type + ')' + ( f.parent_label ? ' — ' + f.parent_label : '' );
				html += '<option value="' + f.key + '" data-name="' + f.name + '">' + label + '</option>';
			} );
			return html;
		},

		refreshExtraAcf: function () {
			var self = this;
			$( '.dbmig-extra-acf' ).each( function () {
				var cur = $( this ).val();
				$( this ).html( self.allAcfOptions() ).val( cur );
			} );
		},

		migrationType: function () {
			return $( '#dbmig-migration-type' ).val() || 'post';
		},

		bind: function () {
			var self = this;

			$( '#dbmig-migration-type' ).on( 'change', function () {
				self.onTypeChange();
			} );

			$( '#dbmig-post-type' ).on( 'change', function () {
				self.loadAcf();
			} );

			$( '#dbmig-taxonomy' ).on( 'change', function () {
				self.loadAcf();
			} );

			$( '#dbmig-source-table' ).on( 'change', function () {
				var t = $( this ).val();
				self.loadColumns( t ).then( function () {
					self.fillIdColumn();
					self.refreshSourceSelects();
				} );
			} );

			$( '#dbmig-reload-tables' ).on( 'click', function () {
				self.loadTables();
			} );

			$( '#dbmig-add-join' ).on( 'click', function () {
				self.addJoinRow();
			} );
			this.$root.on( 'click', '.dbmig-join-addcond', function () {
				self.addJoinCond( $( this ).closest( '.dbmig-join-row' ).find( '.dbmig-join-conds' ), {} );
			} );
			this.$root.on( 'click', '.dbmig-cond-remove', function () {
				$( this ).closest( '.dbmig-join-cond' ).remove();
			} );
			$( '#dbmig-add-extra' ).on( 'click', function () {
				self.addExtraRow();
			} );
			$( '#dbmig-add-repeater' ).on( 'click', function () {
				self.addRepeaterRow();
			} );
			$( '#dbmig-automap' ).on( 'click', function () {
				self.autoMap();
			} );

			$( '#dbmig-save' ).on( 'click', function () {
				self.save();
			} );
			$( '#dbmig-run' ).on( 'click', function () {
				self.run();
			} );
			$( '#dbmig-run-sql' ).on( 'click', function () {
				self.runSql();
			} );
			$( '#dbmig-generate-sql' ).on( 'click', function () {
				self.generateSql();
			} );

			this.$root.on( 'click', '.dbmig-cmd-copy', function () {
				var el = $( this ).closest( 'td' ).find( '.dbmig-cmd' )[ 0 ];
				el.select();
				try { document.execCommand( 'copy' ); } catch ( e ) {}
				var $b = $( this ).text( 'Copied' );
				setTimeout( function () { $b.text( 'Copy' ); }, 1500 );
			} );
			this.$root.on( 'click', '.dbmig-cmd-del', function () {
				self.deleteSql( $( this ).closest( 'tr' ).data( 'file' ) );
			} );
			this.$root.on( 'click', '#dbmig-sql-toggle-preview', function ( e ) {
				e.preventDefault();
				$( '#dbmig-sql' ).toggle();
			} );

			// Show a static-value input when "★ Static value…" is chosen as the source.
			this.$root.on( 'change', '.dbmig-source-col', function () {
				$( this ).closest( 'td' ).find( '.dbmig-static-value' ).toggle( '__static__' === $( this ).val() );
			} );

			// Show the referenced-table picker when a "Resolve → migrated …" transform is chosen.
			this.$root.on( 'change', '.dbmig-transform', function () {
				var isResolve = 0 === String( $( this ).val() ).indexOf( 'resolve_' );
				$( this ).closest( 'td' ).find( '.dbmig-resolve-wrap' ).toggle( isResolve );
			} );

			this.$root.on( 'click', '.dbmig-remove', function () {
				$( this ).closest( '.dbmig-join-row, .dbmig-repeater-row, .dbmig-submap-row, tr' ).remove();
			} );

			this.$root.on( 'change', '.dbmig-join-table, .dbmig-rep-table', function () {
				var t = $( this ).val();
				if ( t ) {
					self.loadColumns( t ).then( function () {
						self.refreshSourceSelects();
					} );
				}
			} );
		},

		onTypeChange: function () {
			var t = this.migrationType();
			$( '.dbmig-when-post' ).toggle( t === 'post' );
			$( '.dbmig-when-user' ).toggle( t === 'user' );
			$( '.dbmig-when-term' ).toggle( t === 'term' );
			$( '.dbmig-when-attachment' ).toggle( t === 'attachment' );
			$( '.dbmig-when-comment' ).toggle( t === 'comment' );
			// "Preserve source IDs" applies to every id-bearing type.
			$( '.dbmig-when-hasid' ).toggle( t === 'post' || t === 'attachment' || t === 'term' || t === 'user' || t === 'comment' );
			// "Auto slug from title" applies to posts, users and taxonomy terms.
			$( '.dbmig-when-hasslug' ).toggle( t === 'post' || t === 'user' || t === 'term' );
			this.loadAcf();          // reloads with proper context, then rebuilds list
			this.refreshExtraKinds();
		},

		/* ---- data loading ---- */
		loadTables: function () {
			var self = this;
			return Ajax.post( 'get_tables' ).then( function ( r ) {
				if ( r.success ) {
					self.tables = r.data.tables || [];
					self.currentTables = r.data.current || [];
					self.currentDb = r.data.current_db || '';
					self.sourceDb = r.data.source_db || '';
					self.fillTableSelects();
					if ( r.data.source_error ) {
						$( '#dbmig-acf-status' ).text( '— Source DB not connected: ' + r.data.source_error + ' (current-DB tables still available). Check Settings.' );
					}
				}
			} );
		},

		// The alias / bare name of a table reference ("db.table" -> "table").
		tableAlias: function ( v ) {
			var i = String( v ).lastIndexOf( '.' );
			return i >= 0 ? v.substring( i + 1 ) : v;
		},

		loadColumns: function ( table ) {
			var self = this;
			if ( ! table || this.columnsByTable[ table ] ) {
				return $.Deferred().resolve().promise();
			}
			return Ajax.post( 'get_columns', { table: table } ).then( function ( r ) {
				self.columnsByTable[ table ] = ( r.success ) ? r.data.columns : [];
			} );
		},

		loadAcf: function () {
			var self = this;
			var t = this.migrationType();
			// Comments have no auto-listed ACF fields (manual ACF still works via
			// Additional mappings). Skip the fetch and rebuild with none.
			if ( t === 'comment' ) {
				self.acfFields = [];
				self.acfActive = DBMig.acfActive;
				$( '#dbmig-acf-status' ).text( '' );
				self.rebuildFieldList();
				return $.Deferred().resolve().promise();
			}
			var data;
			if ( t === 'user' ) {
				data = { context: 'user' };
			} else if ( t === 'term' ) {
				data = { context: 'term', taxonomy: $( '#dbmig-taxonomy' ).val() };
			} else if ( t === 'attachment' ) {
				data = { context: 'post', post_type: 'attachment' };
			} else {
				data = { context: 'post', post_type: $( '#dbmig-post-type' ).val() };
			}
			return Ajax.post( 'get_acf_fields', data ).done( function ( r ) {
				if ( r.success ) {
					self.acfFields = r.data.fields || [];
					self.acfActive = r.data.acf_active;
					var $st = $( '#dbmig-acf-status' );
					if ( ! r.data.acf_active ) {
						$st.text( '— ACF not active; ACF rows fall back to ' + ( t === 'user' ? 'user' : ( t === 'term' ? 'term' : 'post' ) ) + ' meta.' );
					} else {
						$st.text( '— ' + self.acfFields.length + ' ACF fields found.' );
					}
					self.refreshAcfRepeaterSelects();
					self.rebuildFieldList();
				}
			} );
		},

		/* ---- select fillers ---- */
		fillRoles: function () {
			var html = '';
			$.each( DBMig.roles || {}, function ( k, label ) {
				html += '<option value="' + k + '">' + label + '</option>';
			} );
			$( '#dbmig-role' ).html( html );
		},

		fillTaxonomies: function () {
			var html = '';
			$.each( DBMig.taxonomies || {}, function ( k, label ) {
				html += '<option value="' + k + '">' + label + '</option>';
			} );
			$( '#dbmig-taxonomy' ).html( html );
		},

		fillTableSelects: function () {
			var opts = this.tableOptionsHtml();
			$( '#dbmig-source-table' ).html( opts );
			$( '.dbmig-tablelist' ).each( function () {
				var cur = $( this ).val();
				$( this ).html( opts ).val( cur );
			} );
		},

		tableOptionsHtml: function () {
			var self = this;
			var html = '<option value="">' + DBMig.i18n.selectCol + '</option>';
			if ( ( this.currentTables || [] ).length ) {
				html += '<optgroup label="Source DB: ' + ( this.sourceDb || 'legacy' ) + '">';
			}
			this.tables.forEach( function ( t ) {
				html += '<option value="' + t + '">' + t + '</option>';
			} );
			if ( ( this.currentTables || [] ).length ) {
				html += '</optgroup><optgroup label="Current WordPress DB: ' + this.currentDb + '">';
				this.currentTables.forEach( function ( t ) {
					html += '<option value="' + self.currentDb + '.' + t + '">' + t + '</option>';
				} );
				html += '</optgroup>';
			}
			return html;
		},

		columnOptions: function () {
			var self = this;
			var base = $( '#dbmig-source-table' ).val();
			var html = '<option value="">— skip —</option>';
			html += '<option value="__static__">★ Static value…</option>';
			if ( base && self.columnsByTable[ base ] ) {
				html += '<optgroup label="' + base + '">';
				self.columnsByTable[ base ].forEach( function ( c ) {
					html += '<option value="' + c.name + '">' + c.name + ' (' + c.type + ')</option>';
				} );
				html += '</optgroup>';
			}
			$( '.dbmig-join-table' ).each( function () {
				var jt = $( this ).val();
				if ( jt && self.columnsByTable[ jt ] ) {
					var alias = self.tableAlias( jt );
					html += '<optgroup label="' + jt + ' (join)">';
					self.columnsByTable[ jt ].forEach( function ( c ) {
						html += '<option value="' + alias + '.' + c.name + '">' + alias + '.' + c.name + '</option>';
					} );
					html += '</optgroup>';
				}
			} );
			return html;
		},

		refreshSourceSelects: function () {
			var html = this.columnOptions();
			$( '.dbmig-source-col' ).each( function () {
				var cur = $( this ).val();
				$( this ).html( html ).val( cur );
			} );
			this.refreshJoinCols();
		},

		/**
		 * Options for a join ON column: fully-qualified columns of the base table
		 * plus every joined table, so a join can reference the base OR any table
		 * joined above it (chained joins).
		 */
		joinColumnOptions: function () {
			var self = this;
			var html = '<option value="">— column —</option>';
			var addGroup = function ( tbl, label ) {
				var cols = self.columnsByTable[ tbl ];
				if ( ! cols || ! cols.length ) { return; }
				var alias = self.tableAlias( tbl );
				html += '<optgroup label="' + label + '">';
				cols.forEach( function ( c ) {
					html += '<option value="' + alias + '.' + c.name + '">' + alias + '.' + c.name + '</option>';
				} );
				html += '</optgroup>';
			};
			var base = $( '#dbmig-source-table' ).val();
			if ( base ) { addGroup( base, base + ' (base)' ); }
			$( '.dbmig-join-table' ).each( function () {
				var jt = $( this ).val();
				if ( jt ) { addGroup( jt, jt ); }
			} );
			return html;
		},

		refreshJoinCols: function () {
			var html = this.joinColumnOptions();
			$( '.dbmig-join-left, .dbmig-join-right, .dbmig-cond-col' ).each( function () {
				// Prefer the live value; fall back to the desired value stashed on
				// the element (options may not have existed when the row was built).
				var want = $( this ).val() || $( this ).attr( 'data-want' ) || '';
				$( this ).html( html );
				if ( want ) { $( this ).val( want ); }
			} );
		},

		// One extra ON condition on a join: [AND/OR] [column] [op] [value].
		addJoinCond: function ( $conds, data ) {
			data = data || {};
			var $c = $( '<div class="dbmig-join-cond"></div>' );
			$c.append( '<select class="dbmig-cond-conj"><option value="AND">AND</option><option value="OR">OR</option></select>' );
			$c.append( '<select class="dbmig-cond-col">' + this.joinColumnOptions() + '</select>' );
			$c.append( '<select class="dbmig-cond-op">' +
				'<option value="=">=</option><option value="!=">&ne;</option>' +
				'<option value="&lt;">&lt;</option><option value="&lt;=">&le;</option>' +
				'<option value="&gt;">&gt;</option><option value="&gt;=">&ge;</option>' +
				'<option value="LIKE">LIKE</option><option value="IS NULL">IS NULL</option><option value="IS NOT NULL">IS NOT NULL</option>' +
				'</select>' );
			$c.append( '<input type="text" class="dbmig-cond-val" placeholder="value (e.g. news)">' );
			$c.append( '<button type="button" class="button-link dbmig-cond-remove" title="Remove condition">✕</button>' );
			$conds.append( $c );
			if ( data.conj ) { $c.find( '.dbmig-cond-conj' ).val( data.conj ); }
			if ( data.col ) { $c.find( '.dbmig-cond-col' ).val( data.col ).attr( 'data-want', data.col ); }
			if ( data.op ) { $c.find( '.dbmig-cond-op' ).val( data.op ); }
			if ( data.val !== undefined ) { $c.find( '.dbmig-cond-val' ).val( data.val ); }
			var toggleVal = function ( op ) { $c.find( '.dbmig-cond-val' ).toggle( 'IS NULL' !== op && 'IS NOT NULL' !== op ); };
			toggleVal( $c.find( '.dbmig-cond-op' ).val() );
			$c.find( '.dbmig-cond-op' ).on( 'change', function () { toggleVal( $( this ).val() ); } );
			return $c;
		},

		fillIdColumn: function () {
			var base = $( '#dbmig-source-table' ).val();
			var cols = this.columnsByTable[ base ] || [];
			var cur = $( '#dbmig-source-id' ).val();
			var html = '<option value="">' + DBMig.i18n.selectCol + '</option>';
			cols.forEach( function ( c ) {
				html += '<option value="' + c.name + '">' + c.name + ( c.key === 'PRI' ? ' — PK' : '' ) + '</option>';
			} );
			$( '#dbmig-source-id' ).html( html );
			if ( cur ) {
				$( '#dbmig-source-id' ).val( cur );
			} else {
				cols.forEach( function ( c ) {
					if ( c.key === 'PRI' ) { $( '#dbmig-source-id' ).val( c.name ); }
				} );
			}
		},

		transformOptions: function () {
			return '<option value="none">No transform</option>' +
				'<option value="int">Integer</option>' +
				'<option value="float">Float</option>' +
				'<option value="bool">Boolean</option>' +
				'<option value="date">Date → Y-m-d H:i:s</option>' +
				'<option value="strip_tags">Strip tags</option>' +
				'<option value="json_decode">JSON decode</option>' +
				'<option value="serialize_decode">Unserialize</option>' +
				'<option value="resolve_post">🔗 Resolve → migrated post ID</option>' +
				'<option value="resolve_user">🔗 Resolve → migrated user ID</option>' +
				'<option value="resolve_term">🔗 Resolve → migrated term ID</option>' +
				'<option value="resolve_comment">🔗 Resolve → migrated comment ID</option>';
		},

		/**
		 * A transform select plus a hidden "referenced legacy table" picker that
		 * appears when a "Resolve → migrated …" transform is chosen. The source
		 * column holds the legacy id; the value becomes the resolved WP ID.
		 */
		transformCell: function () {
			return '<select class="dbmig-transform">' + this.transformOptions() + '</select>' +
				'<div class="dbmig-resolve-wrap" style="display:none;margin-top:4px">' +
				'<label class="dbmig-sub-label">Referenced legacy table (resolve by legacy id)</label>' +
				'<select class="dbmig-resolve-table dbmig-tablelist">' + this.tableOptionsHtml() + '</select></div>';
		},

		// Reveal + populate the resolve-table when the value has a resolve transform.
		applyResolve: function ( $scope, data ) {
			if ( data && 0 === ( '' + data.transform ).indexOf( 'resolve_' ) ) {
				$scope.find( '.dbmig-resolve-wrap' ).show();
				if ( data.rel_table ) { $scope.find( '.dbmig-resolve-table' ).val( data.rel_table ); }
			}
		},

		/* ---- fixed field list (left = WP fields, right = source) ---- */
		rebuildFieldList: function () {
			// Snapshot current sources so they survive a rebuild (e.g. post-type change).
			var snap = this.snapshotFixed();
			$.extend( snap, this.pendingFixed );
			this.pendingFixed = {};

			var $tbody = $( '#dbmig-fields-list' ).empty();
			var t = this.migrationType();
			var coreFields = ( t === 'user' ) ? this.userFields : ( t === 'term' ) ? this.termFields : ( t === 'attachment' ) ? this.attachmentFields : ( t === 'comment' ) ? this.commentFields : this.postFields;
			var coreKind = ( t === 'user' ) ? 'user_field' : ( t === 'term' ) ? 'term_field' : ( t === 'comment' ) ? 'comment_field' : 'post_field';
			var coreLabel = ( t === 'user' ) ? 'User fields' : ( t === 'term' ) ? 'Term fields' : ( t === 'attachment' ) ? 'Attachment fields' : ( t === 'comment' ) ? 'Comment fields' : 'Post fields';
			var self = this;

			$tbody.append( this.sectionRow( coreLabel ) );
			$.each( coreFields, function ( key, label ) {
				$tbody.append( self.fixedRow( coreKind, key, label, '', '' ) );
			} );

			if ( this.acfFields.length ) {
				$tbody.append( this.sectionRow( 'ACF fields' ) );
				this.acfFields.forEach( function ( f ) {
					if ( f.type === 'repeater' || f.type === 'group' ) {
						return; // repeaters handled in Step 4
					}
					var isRel = RELATIONAL.indexOf( f.type ) !== -1;
					var kind = isRel ? 'acf_relation' : 'acf';
					var label = f.label + ' (' + f.name + ', ' + f.type + ')';
					$tbody.append( self.fixedRow( kind, f.key, label, f.name, f.type ) );
				} );
			}

			// Restore values.
			$tbody.find( '.dbmig-fixed-row' ).each( function () {
				var $r = $( this );
				var k = $r.data( 'kind' ) + '|' + $r.data( 'target' );
				if ( snap[ k ] ) {
					self.applyFixedValue( $r, snap[ k ] );
				}
			} );
		},

		sectionRow: function ( title ) {
			return '<tr class="dbmig-section-row"><td colspan="3"><strong>' + title + '</strong></td></tr>';
		},

		fixedRow: function ( kind, target, label, acfName, fieldType ) {
			var $tr = $( '<tr class="dbmig-fixed-row"></tr>' );
			$tr.attr( 'data-kind', kind ).attr( 'data-target', target ).attr( 'data-acfname', acfName || '' );

			var badge = ( kind === 'acf_relation' ) ? ' <span class="dbmig-pill rel">relation</span>'
				: ( kind === 'acf' ? ' <span class="dbmig-pill acf">acf</span>' : '' );
			$tr.append( '<td class="dbmig-wpfield">' + label + badge + '</td>' );
			$tr.append( '<td><select class="dbmig-source-col">' + this.columnOptions() + '</select>' +
				'<input type="text" class="dbmig-static-value" placeholder="static value" style="display:none;margin-top:4px;width:100%"></td>' );

			var $opts = $( '<td class="dbmig-opt"></td>' );
			$opts.append( this.transformCell() );

			// Relation target picker for relational ACF fields, and for post_author.
			if ( kind === 'acf_relation' ) {
				var pt = '<option value="">— post type —</option>';
				$.each( DBMig.postTypes || {}, function ( k, label ) { pt += '<option value="' + k + '">' + label + '</option>'; } );
				$opts.append(
					'<div><label class="dbmig-sub-label">Match by</label>' +
					'<select class="dbmig-rel-match">' +
						'<option value="legacy">Migrated legacy id</option>' +
						'<option value="direct">Already a WP post ID (from a join)</option>' +
						'<option value="title">Post title (current DB)</option>' +
						'<option value="slug">Post slug (current DB)</option>' +
						'<option value="meta">Post meta value (current DB)</option>' +
					'</select></div>' +
					'<div class="dbmig-rel-legacy"><label class="dbmig-sub-label">Referenced legacy table</label>' +
						'<select class="dbmig-rel-table">' + this.tableOptionsHtml() + '</select></div>' +
					'<div class="dbmig-rel-current" style="display:none">' +
						'<label class="dbmig-sub-label">Match against post type</label>' +
						'<select class="dbmig-rel-pt">' + pt + '</select>' +
						'<label class="dbmig-sub-label dbmig-rel-mk-lbl" style="display:none">Meta key</label>' +
						'<input type="text" class="dbmig-rel-mk" placeholder="meta_key" style="display:none">' +
					'</div>'
				);
				var toggleRel = function ( m ) {
					$opts.find( '.dbmig-rel-legacy' ).toggle( 'legacy' === m );
					// 'direct' needs no extra input (value is already the WP id).
					$opts.find( '.dbmig-rel-current' ).toggle( 'title' === m || 'slug' === m || 'meta' === m );
					$opts.find( '.dbmig-rel-mk, .dbmig-rel-mk-lbl' ).toggle( 'meta' === m );
				};
				$opts.find( '.dbmig-rel-match' ).on( 'change', function () { toggleRel( $( this ).val() ); } );
				toggleRel( 'legacy' );
			} else if ( kind === 'post_field' && target === 'post_author' ) {
				$opts.append( '<div><label class="dbmig-sub-label">Resolve from migrated user table (optional)</label>' +
					'<select class="dbmig-rel-table">' + this.tableOptionsHtml() + '</select></div>' );
			}
			$tr.append( $opts );
			return $tr;
		},

		snapshotFixed: function () {
			var snap = {};
			$( '#dbmig-fields-list .dbmig-fixed-row' ).each( function () {
				var $r = $( this );
				var src = $r.find( '.dbmig-source-col' ).val();
				if ( ! src ) { return; }
				snap[ $r.data( 'kind' ) + '|' + $r.data( 'target' ) ] = {
					source: src,
					static_value: $r.find( '.dbmig-static-value' ).val() || '',
					transform: $r.find( '.dbmig-transform' ).val(),
					rel_table: $r.find( '.dbmig-rel-table' ).val() || $r.find( '.dbmig-resolve-table' ).val() || '',
					rel_match: $r.find( '.dbmig-rel-match' ).val() || '',
					rel_post_type: $r.find( '.dbmig-rel-pt' ).val() || '',
					rel_meta_key: $r.find( '.dbmig-rel-mk' ).val() || ''
				};
			} );
			return snap;
		},

		applyFixedValue: function ( $r, v ) {
			if ( v.source ) { $r.find( '.dbmig-source-col' ).val( v.source ); }
			if ( v.static_value !== undefined ) { $r.find( '.dbmig-static-value' ).val( v.static_value ); }
			if ( '__static__' === v.source ) { $r.find( '.dbmig-static-value' ).show(); }
			if ( v.transform ) { $r.find( '.dbmig-transform' ).val( v.transform ); }
			this.applyResolve( $r.find( '.dbmig-opt' ), v );
			if ( v.rel_table ) { $r.find( '.dbmig-rel-table' ).val( v.rel_table ); }
			if ( v.rel_match ) { $r.find( '.dbmig-rel-match' ).val( v.rel_match ).trigger( 'change' ); }
			if ( v.rel_post_type ) { $r.find( '.dbmig-rel-pt' ).val( v.rel_post_type ); }
			if ( v.rel_meta_key ) { $r.find( '.dbmig-rel-mk' ).val( v.rel_meta_key ); }
		},

		/* ---- extra mappings (custom meta / taxonomy) ---- */
		extraKindOptions: function () {
			var acf = DBMig.acfActive ? '<option value="acf">ACF field (single)</option>' : '';
			var media = '<option value="media">Media (attachment)</option>';
			var t = this.migrationType();
			if ( t === 'user' ) {
				return '<option value="user_meta">User meta</option>' + acf + media;
			}
			if ( t === 'term' ) {
				return '<option value="term_meta">Term meta</option>' + acf;
			}
			if ( t === 'comment' ) {
				return '<option value="comment_meta">Comment meta</option>' + acf;
			}
			return '<option value="post_meta">Post meta</option>' +
				'<option value="taxonomy">Taxonomy</option>' + acf + media;
		},

		mediaAttachOptions: function () {
			var isUser = ( this.migrationType() === 'user' );
			var html = '';
			if ( ! isUser ) { html += '<option value="featured">Featured image</option>'; }
			html += '<option value="meta">' + ( isUser ? 'User meta (attachment ID)' : 'Post meta (attachment ID)' ) + '</option>';
			if ( DBMig.acfActive ) { html += '<option value="acf">ACF image field</option>'; }
			html += '<option value="attachment">Attachment only</option>';
			return html;
		},

		refreshExtraKinds: function () {
			var self = this;
			$( '#dbmig-extra-list .dbmig-extra-row' ).each( function () {
				var $k = $( this ).find( '.dbmig-extra-kind' );
				var cur = $k.val();
				$k.html( self.extraKindOptions() );
				$k.val( cur );
				$k.trigger( 'change' );
			} );
		},

		addExtraRow: function ( data ) {
			data = data || {};
			var self = this;
			var $tr = $( '<tr class="dbmig-extra-row"></tr>' );
			$tr.append( '<td><select class="dbmig-extra-kind">' + this.extraKindOptions() + '</select></td>' );
			$tr.append( '<td class="dbmig-extra-target"></td>' );
			$tr.append( '<td><select class="dbmig-source-col">' + this.columnOptions() + '</select>' +
				'<input type="text" class="dbmig-static-value" placeholder="static value" style="display:none;margin-top:4px;width:100%"></td>' );
			$tr.append( '<td class="dbmig-extra-opt"></td>' );
			$tr.append( '<td><button type="button" class="button-link dbmig-remove">✕</button></td>' );
			$( '#dbmig-extra-list' ).append( $tr );

			var $kind = $tr.find( '.dbmig-extra-kind' );
			$kind.on( 'change', function () {
				self.renderExtraTarget( $tr, $( this ).val(), {} );
			} );
			if ( data.target_kind ) { $kind.val( data.target_kind ); }
			this.renderExtraTarget( $tr, $kind.val(), data );
			if ( data.source ) { $tr.find( '.dbmig-source-col' ).val( data.source ); }
			if ( data.static_value !== undefined ) { $tr.find( '.dbmig-static-value' ).val( data.static_value ); }
			if ( '__static__' === data.source ) { $tr.find( '.dbmig-static-value' ).show(); }
			return $tr;
		},

		renderExtraTarget: function ( $tr, kind, data ) {
			var $t = $tr.find( '.dbmig-extra-target' ).empty();
			var $o = $tr.find( '.dbmig-extra-opt' ).empty();

			if ( kind === 'taxonomy' ) {
				var tax = '<option value="">— taxonomy —</option>';
				$.each( DBMig.taxonomies || {}, function ( k, label ) {
					tax += '<option value="' + k + '">' + label + '</option>';
				} );
				$t.html( '<select class="dbmig-extra-targetval">' + tax + '</select>' );
				$o.html( '<select class="dbmig-term-match"><option value="name">Match by name</option><option value="slug">Match by slug</option><option value="id">Match by term id</option></select>' +
					'<label class="dbmig-checkbox">Slug: <select class="dbmig-term-slug"><option value="">— auto from name —</option>' + this.columnOptions() + '</select></label>' +
					'<label class="dbmig-checkbox"><input type="checkbox" class="dbmig-term-create"> create if missing</label>' +
					'<label class="dbmig-checkbox"><input type="checkbox" class="dbmig-term-append"> allow multiple (append — for junction/many-to-many)</label>' );
				if ( data.target ) { $t.find( 'select' ).val( data.target ); }
				if ( data.term_match ) { $o.find( '.dbmig-term-match' ).val( data.term_match ); }
				if ( data.term_slug ) { $o.find( '.dbmig-term-slug' ).val( data.term_slug ); }
				if ( data.term_create ) { $o.find( '.dbmig-term-create' ).prop( 'checked', true ); }
				if ( data.term_append ) { $o.find( '.dbmig-term-append' ).prop( 'checked', true ); }
			} else if ( kind === 'acf' ) {
				$t.html( '<select class="dbmig-extra-targetval dbmig-extra-acf">' + this.allAcfOptions() + '</select>' );
				$o.html( this.transformCell() );
				if ( data.target ) { $t.find( 'select' ).val( data.target ); }
				if ( data.transform ) { $o.find( '.dbmig-transform' ).val( data.transform ); }
				this.applyResolve( $o, data );
			} else if ( kind === 'media' ) {
				var self = this;
				$t.html( '<select class="dbmig-media-as">' + this.mediaAttachOptions() + '</select>' );
				$o.html( '<span class="dbmig-media-sub"></span>' );
				var renderSub = function ( as, d ) {
					var $sub = $o.find( '.dbmig-media-sub' ).empty();
					if ( 'meta' === as ) {
						$sub.html( '<input type="text" class="dbmig-media-target" placeholder="meta_key (stores attachment ID)">' );
						if ( d && d.target ) { $sub.find( 'input' ).val( d.target ); }
					} else if ( 'acf' === as ) {
						$sub.html( '<select class="dbmig-media-target dbmig-extra-acf">' + self.allAcfOptions() + '</select>' );
						if ( d && d.target ) { $sub.find( 'select' ).val( d.target ); }
					} else {
						$sub.html( '<span class="description">' + ( 'featured' === as ? 'Creates the attachment and sets it as the featured image.' : 'Creates the attachment (attached to the post).' ) + '</span>' );
					}
				};
				$t.find( '.dbmig-media-as' ).on( 'change', function () { renderSub( $( this ).val(), {} ); } );
				if ( data.attach_as ) { $t.find( '.dbmig-media-as' ).val( data.attach_as ); }
				renderSub( $t.find( '.dbmig-media-as' ).val(), data );
			} else {
				// post_meta / user_meta
				$t.html( '<input type="text" class="dbmig-extra-targetval" placeholder="meta_key">' );
				$o.html( this.transformCell() );
				if ( data.target ) { $t.find( 'input' ).val( data.target ); }
				if ( data.transform ) { $o.find( '.dbmig-transform' ).val( data.transform ); }
				this.applyResolve( $o, data );
			}
		},

		/* ---- joins ---- */
		addJoinRow: function ( data ) {
			data = data || {};
			var self = this;
			var $row = $( $( '#dbmig-tpl-join' ).html() );
			$( '#dbmig-joins-list' ).append( $row );
			$row.find( '.dbmig-tablelist' ).html( this.tableOptionsHtml() );
			if ( data.type ) { $row.find( '.dbmig-join-type' ).val( data.type ); }
			if ( data.table ) { $row.find( '.dbmig-join-table' ).val( data.table ); }
			// Stash desired ON columns; applied by refreshJoinCols once options exist.
			if ( data.left_col ) { $row.find( '.dbmig-join-left' ).attr( 'data-want', data.left_col ); }
			if ( data.right_col ) { $row.find( '.dbmig-join-right' ).attr( 'data-want', data.right_col ); }
			if ( data.table ) {
				this.loadColumns( data.table ).then( function () { self.refreshSourceSelects(); } );
			}
			// Extra AND/OR ON conditions.
			var $conds = $row.find( '.dbmig-join-conds' );
			( data.conditions || [] ).forEach( function ( c ) { self.addJoinCond( $conds, c ); } );
			this.refreshJoinCols();
			return $row;
		},

		/* ---- repeaters ---- */
		refreshAcfRepeaterSelects: function () {
			var self = this;
			$( '.dbmig-acf-repeaters' ).each( function () {
				var cur = $( this ).val();
				$( this ).html( self.acfRepeaterOptions() ).val( cur );
			} );
			$( '#dbmig-repeaters-list .dbmig-repeater-row' ).each( function () {
				self.refreshRepeaterSubFields( $( this ) );
			} );
		},

		acfRepeaterOptions: function () {
			var html = '<option value="">— select repeater —</option>';
			this.acfFields.forEach( function ( f ) {
				if ( f.type !== 'repeater' ) { return; }
				html += '<option value="' + f.key + '" data-name="' + f.name + '">' + f.label + ' (' + f.name + ')</option>';
			} );
			return html;
		},

		addRepeaterRow: function ( data ) {
			data = data || {};
			var self = this;
			var $row = $( $( '#dbmig-tpl-repeater' ).html() );
			$( '#dbmig-repeaters-list' ).append( $row );

			$row.find( '.dbmig-acf-repeaters' ).html( this.acfRepeaterOptions() );
			$row.find( '.dbmig-tablelist' ).html( this.tableOptionsHtml() );
			if ( data.acf_field ) { $row.find( '.dbmig-rep-field' ).val( data.acf_field ); }
			if ( data.child_table ) { $row.find( '.dbmig-rep-table' ).val( data.child_table ); }

			$row.find( '.dbmig-add-sub' ).on( 'click', function () {
				self.addSubMapRow( $row, {} );
			} );

			var fillChild = function () {
				var ct = $row.find( '.dbmig-rep-table' ).val();
				self.loadColumns( ct ).then( function () {
					var cols = self.columnsByTable[ ct ] || [];
					var colHtml = '<option value="">— column —</option>';
					cols.forEach( function ( c ) { colHtml += '<option value="' + c.name + '">' + c.name + '</option>'; } );
					$row.find( '.dbmig-rep-fk' ).html( colHtml ).val( data.child_fk || '' );
					$row.find( '.dbmig-rep-orderby' ).html( colHtml ).val( data.order_by || '' );
					$row.find( '.dbmig-rep-subcol' ).each( function () {
						var cur = $( this ).val();
						$( this ).html( colHtml ).val( cur );
					} );
				} );
			};
			$row.find( '.dbmig-rep-table' ).on( 'change', fillChild );
			$row.find( '.dbmig-rep-field' ).on( 'change', function () {
				self.refreshRepeaterSubFields( $row );
			} );

			// "Link via" intermediate joins + the parent-match column.
			$row.find( '.dbmig-rep-addvia' ).on( 'click', function () { self.addRepeaterViaRow( $row, {} ); } );
			$row.find( '.dbmig-rep-parentcol' ).attr( 'data-want', data.parent_col || '' );
			( data.joins || [] ).forEach( function ( v ) { self.addRepeaterViaRow( $row, v ); } );

			( data.sub_map || [] ).forEach( function ( s ) { self.addSubMapRow( $row, s ); } );
			if ( data.child_table ) { fillChild(); }
			self.refreshRepeaterSubFields( $row );
			self.refreshRepeaterVia( $row );
			return $row;
		},

		// Qualified column options (base table + this repeater's "link via" tables)
		// for the parent-match column and the via-join ON dropdowns.
		repeaterColOptions: function ( $row ) {
			var self = this;
			var html = '<option value="">— column —</option>';
			var addG = function ( tbl ) {
				if ( ! tbl ) { return; }
				var cols = self.columnsByTable[ tbl ];
				if ( ! cols || ! cols.length ) { return; }
				var alias = self.tableAlias( tbl );
				html += '<optgroup label="' + tbl + '">';
				cols.forEach( function ( c ) { html += '<option value="' + alias + '.' + c.name + '">' + alias + '.' + c.name + '</option>'; } );
				html += '</optgroup>';
			};
			addG( $( '#dbmig-source-table' ).val() );
			$row.find( '.dbmig-via-table' ).each( function () { addG( $( this ).val() ); } );
			return html;
		},

		refreshRepeaterVia: function ( $row ) {
			var html = this.repeaterColOptions( $row );
			$row.find( '.dbmig-rep-parentcol, .dbmig-via-left, .dbmig-via-right' ).each( function () {
				var want = $( this ).val() || $( this ).attr( 'data-want' ) || '';
				$( this ).html( html );
				if ( want ) { $( this ).val( want ); }
			} );
		},

		addRepeaterViaRow: function ( $row, d ) {
			d = d || {};
			var self = this;
			var $v = $( '<div class="dbmig-rep-via-row"></div>' );
			$v.append( '<select class="dbmig-via-table dbmig-tablelist">' + this.tableOptionsHtml() + '</select>' );
			$v.append( ' ON <select class="dbmig-via-left"></select> = <select class="dbmig-via-right"></select> ' );
			$v.append( '<span title="Keep only the newest matching row per parent (e.g. the current snapshot)">latest by <select class="dbmig-via-latest"><option value="">(all rows)</option></select></span> ' );
			$v.append( '<button type="button" class="button-link dbmig-via-remove" title="Remove">✕</button>' );
			$row.find( '.dbmig-rep-via-list' ).append( $v );
			if ( d.table ) { $v.find( '.dbmig-via-table' ).val( d.table ); }
			if ( d.left_col ) { $v.find( '.dbmig-via-left' ).attr( 'data-want', d.left_col ); }
			if ( d.right_col ) { $v.find( '.dbmig-via-right' ).attr( 'data-want', d.right_col ); }
			var fillLatest = function () {
				var t = $v.find( '.dbmig-via-table' ).val();
				var cols = self.columnsByTable[ t ] || [];
				var h = '<option value="">(all rows)</option>';
				cols.forEach( function ( c ) { h += '<option value="' + c.name + '">' + c.name + '</option>'; } );
				$v.find( '.dbmig-via-latest' ).html( h ).val( d.latest_by || '' );
			};
			$v.find( '.dbmig-via-table' ).on( 'change', function () {
				var t = $( this ).val();
				if ( t ) { self.loadColumns( t ).then( function () { self.refreshRepeaterVia( $row ); fillLatest(); } ); }
			} );
			$v.find( '.dbmig-via-remove' ).on( 'click', function () { $v.remove(); self.refreshRepeaterVia( $row ); } );
			if ( d.table ) { this.loadColumns( d.table ).then( function () { self.refreshRepeaterVia( $row ); fillLatest(); } ); }
			this.refreshRepeaterVia( $row );
			return $v;
		},

		subFieldOptionsFor: function ( repeaterKey ) {
			var html = '<option value="">— sub field —</option>';
			this.acfFields.forEach( function ( f ) {
				if ( f.key !== repeaterKey ) { return; }
				( f.sub_fields || [] ).forEach( function ( s ) {
					html += '<option value="' + s.key + '" data-name="' + s.name + '">' + s.label + ' (' + s.name + ')</option>';
				} );
			} );
			return html;
		},

		refreshRepeaterSubFields: function ( $row ) {
			var html = this.subFieldOptionsFor( $row.find( '.dbmig-rep-field' ).val() );
			$row.find( '.dbmig-rep-subfield' ).each( function () {
				var cur = $( this ).val();
				$( this ).html( html ).val( cur );
			} );
		},

		addSubMapRow: function ( $row, data ) {
			data = data || {};
			var $tr = $( '<tr class="dbmig-submap-row"></tr>' );
			$tr.append( '<td><select class="dbmig-rep-subfield"></select></td>' );
			$tr.append( '<td><select class="dbmig-rep-subcol"><option value="">— column —</option></select></td>' );
			$tr.append( '<td><select class="dbmig-sub-reltable">' + this.tableOptionsHtml() + '</select></td>' );
			$tr.append( '<td><button type="button" class="button-link dbmig-remove">✕</button></td>' );
			$row.find( '.dbmig-submap-list' ).append( $tr );

			$tr.find( '.dbmig-rep-subfield' ).html( this.subFieldOptionsFor( $row.find( '.dbmig-rep-field' ).val() ) );
			var ct = $row.find( '.dbmig-rep-table' ).val();
			var cols = this.columnsByTable[ ct ] || [];
			if ( cols.length ) {
				var colHtml = '<option value="">— column —</option>';
				cols.forEach( function ( c ) { colHtml += '<option value="' + c.name + '">' + c.name + '</option>'; } );
				$tr.find( '.dbmig-rep-subcol' ).html( colHtml );
			}
			if ( data.sub_field ) { $tr.find( '.dbmig-rep-subfield' ).val( data.sub_field ); }
			if ( data.source ) { $tr.find( '.dbmig-rep-subcol' ).val( data.source ); }
			if ( data.rel_table ) { $tr.find( '.dbmig-sub-reltable' ).val( data.rel_table ); }
			return $tr;
		},

		/* ---- hydrate ---- */
		hydrate: function () {
			var self = this;
			var p = this.profile;

			if ( ! p || ! p.source_table ) {
				this.onTypeChange();   // sets visibility + loads acf + builds field list
				$( '#dbmig-run, #dbmig-run-sql, #dbmig-generate-sql' ).prop( 'disabled', true );
				return;
			}

			$( '#dbmig-name' ).val( p.name || '' );
			$( '#dbmig-migration-type' ).val( p.migration_type || 'post' );
			$( '#dbmig-post-type' ).val( p.post_type );
			$( '#dbmig-post-status' ).val( p.post_status || 'publish' );
			if ( p.taxonomy ) { $( '#dbmig-taxonomy' ).val( p.taxonomy ); }
			if ( p.role ) { $( '#dbmig-role' ).val( p.role ); }
			$( '#dbmig-partial' ).prop( 'checked', !! p.partial );
			$( '#dbmig-preserve-id' ).prop( 'checked', !! p.preserve_id );
			$( '#dbmig-auto-slug' ).prop( 'checked', !! p.auto_slug );

			var mt = p.migration_type || 'post';
			$( '.dbmig-when-post' ).toggle( mt === 'post' );
			$( '.dbmig-when-user' ).toggle( mt === 'user' );
			$( '.dbmig-when-term' ).toggle( mt === 'term' );
			$( '.dbmig-when-attachment' ).toggle( mt === 'attachment' );
			$( '.dbmig-when-comment' ).toggle( mt === 'comment' );
			$( '.dbmig-when-hasid' ).toggle( mt === 'post' || mt === 'attachment' || mt === 'term' || mt === 'user' || mt === 'comment' );
			$( '.dbmig-when-hasslug' ).toggle( mt === 'post' || mt === 'user' || mt === 'term' );

			// Prepare fixed-row values (restored in rebuildFieldList) and collect the
			// extra rows (custom meta / taxonomy). Extra rows are built AFTER the
			// source columns load so their source <select> has options to select.
			this.pendingFixed = {};
			var pendingExtra = [];
			var pendingAcf = [];
			( p.fields || [] ).forEach( function ( f ) {
				if ( f.target_kind === 'post_field' || f.target_kind === 'user_field' || f.target_kind === 'term_field' || f.target_kind === 'comment_field' ) {
					self.pendingFixed[ f.target_kind + '|' + f.target ] = {
						source: f.source,
						static_value: f.static_value || '',
						transform: f.transform,
						rel_table: f.rel_table || '',
						rel_match: f.rel_match || '',
						rel_post_type: f.rel_post_type || '',
						rel_meta_key: f.rel_meta_key || ''
					};
				} else if ( f.target_kind === 'acf' || f.target_kind === 'acf_relation' ) {
					pendingAcf.push( f ); // routed after acf loads (fixed vs manual extra)
				} else {
					pendingExtra.push( f ); // post_meta / user_meta / taxonomy
				}
			} );

			// Joins, then columns, then field list (after acf loads).
			( p.joins || [] ).forEach( function ( j ) { self.addJoinRow( j ); } );

			$( '#dbmig-source-table' ).val( p.source_table );
			var loads = [ this.loadColumns( p.source_table ) ];
			( p.joins || [] ).forEach( function ( j ) { loads.push( self.loadColumns( j.table ) ); } );

			$.when.apply( $, loads ).then( function () {
				self.fillIdColumn();
				$( '#dbmig-source-id' ).val( p.source_id_column );
				self.refreshSourceSelects();
				// loadAcf() triggers rebuildFieldList which restores pendingFixed.
				self.loadAcf().then( function () {
					self.loadAllAcf().then( function () {
						// Build extra rows now that column + ACF options exist so
						// their source and ACF/media sub-selects restore.
						pendingExtra.forEach( function ( f ) { self.addExtraRow( f ); } );
						// Route ACF fields: auto-listed for this type -> fixed row;
						// otherwise -> a manual "ACF field" extra row (survives reload).
						pendingAcf.forEach( function ( f ) {
							var inList = self.acfFields.some( function ( af ) { return af.key === f.target; } );
							if ( inList ) {
								self.pendingFixed[ f.target_kind + '|' + f.target ] = {
									source: f.source, static_value: f.static_value || '', transform: f.transform, rel_table: f.rel_table || '',
									rel_match: f.rel_match || '', rel_post_type: f.rel_post_type || '', rel_meta_key: f.rel_meta_key || ''
								};
							} else {
								self.addExtraRow( { target_kind: 'acf', target: f.target, acf_name: f.acf_name, source: f.source, static_value: f.static_value, transform: f.transform } );
							}
						} );
						self.rebuildFieldList();
						( p.repeaters || [] ).forEach( function ( rep ) { self.addRepeaterRow( rep ); } );
					} );
				} );
			} );

			$( '#dbmig-run, #dbmig-run-sql, #dbmig-generate-sql' ).prop( 'disabled', false );

			// Show any previously generated SQL files for this profile.
			this.loadSqlList();
		},

		/* ---- auto map ---- */
		autoMap: function () {
			var base = $( '#dbmig-source-table' ).val();
			var cols = ( this.columnsByTable[ base ] || [] ).map( function ( c ) { return c.name.toLowerCase(); } );
			var colReal = this.columnsByTable[ base ] || [];
			var guess = {
				post_title: [ 'title', 'name', 'heading', 'subject' ],
				post_content: [ 'content', 'body', 'description', 'text' ],
				post_excerpt: [ 'excerpt', 'summary', 'intro' ],
				post_name: [ 'slug', 'permalink' ],
				post_date: [ 'created', 'created_at', 'date', 'published', 'pubdate' ],
				user_email: [ 'email', 'user_email', 'mail' ],
				user_login: [ 'username', 'login', 'user_name' ],
				display_name: [ 'name', 'full_name', 'display_name' ],
				first_name: [ 'first_name', 'firstname', 'fname' ],
				last_name: [ 'last_name', 'lastname', 'lname' ]
			};
			$( '#dbmig-fields-list .dbmig-fixed-row' ).each( function () {
				var target = $( this ).data( 'target' );
				if ( ! guess[ target ] ) { return; }
				for ( var i = 0; i < guess[ target ].length; i++ ) {
					var idx = cols.indexOf( guess[ target ][ i ] );
					if ( idx !== -1 ) {
						$( this ).find( '.dbmig-source-col' ).val( colReal[ idx ].name );
						break;
					}
				}
			} );
		},

		/* ---- gather + save ---- */
		gather: function () {
			var p = {
				id: $( '#dbmig-profile-id' ).val(),
				name: $( '#dbmig-name' ).val(),
				migration_type: this.migrationType(),
				post_type: ( this.migrationType() === 'attachment' ) ? 'attachment' : $( '#dbmig-post-type' ).val(),
				taxonomy: $( '#dbmig-taxonomy' ).val(),
				post_status: $( '#dbmig-post-status' ).val(),
				role: $( '#dbmig-role' ).val(),
				partial: $( '#dbmig-partial' ).is( ':checked' ) ? 1 : 0,
				preserve_id: $( '#dbmig-preserve-id' ).is( ':checked' ) ? 1 : 0,
				auto_slug: $( '#dbmig-auto-slug' ).is( ':checked' ) ? 1 : 0,
				source_table: $( '#dbmig-source-table' ).val(),
				source_id_column: $( '#dbmig-source-id' ).val(),
				joins: [],
				fields: [],
				repeaters: []
			};

			$( '#dbmig-joins-list .dbmig-join-row' ).each( function () {
				var $r = $( this );
				if ( ! $r.find( '.dbmig-join-table' ).val() ) { return; }
				var conds = [];
				$r.find( '.dbmig-join-cond' ).each( function () {
					var $c = $( this );
					var col = $c.find( '.dbmig-cond-col' ).val();
					if ( ! col ) { return; }
					conds.push( {
						conj: $c.find( '.dbmig-cond-conj' ).val() || 'AND',
						col: col,
						op: $c.find( '.dbmig-cond-op' ).val() || '=',
						val: $c.find( '.dbmig-cond-val' ).val() || ''
					} );
				} );
				p.joins.push( {
					type: $r.find( '.dbmig-join-type' ).val(),
					table: $r.find( '.dbmig-join-table' ).val(),
					left_col: $r.find( '.dbmig-join-left' ).val(),
					right_col: $r.find( '.dbmig-join-right' ).val(),
					conditions: conds
				} );
			} );

			// Fixed rows (blank source => skip).
			$( '#dbmig-fields-list .dbmig-fixed-row' ).each( function () {
				var $r = $( this );
				var src = $r.find( '.dbmig-source-col' ).val();
				if ( ! src ) { return; }
				p.fields.push( {
					target_kind: $r.data( 'kind' ),
					target: $r.data( 'target' ),
					acf_name: $r.data( 'acfname' ) || '',
					source: src,
					static_value: $r.find( '.dbmig-static-value' ).val() || '',
					transform: $r.find( '.dbmig-transform' ).val() || 'none',
					rel_table: $r.find( '.dbmig-rel-table' ).val() || $r.find( '.dbmig-resolve-table' ).val() || '',
					rel_match: $r.find( '.dbmig-rel-match' ).val() || 'legacy',
					rel_post_type: $r.find( '.dbmig-rel-pt' ).val() || '',
					rel_meta_key: $r.find( '.dbmig-rel-mk' ).val() || ''
				} );
			} );

			// Extra rows.
			$( '#dbmig-extra-list .dbmig-extra-row' ).each( function () {
				var $r = $( this );
				var kind = $r.find( '.dbmig-extra-kind' ).val();
				var src = $r.find( '.dbmig-source-col' ).val();

				// Media has its own shape (attach_as + optional target).
				if ( 'media' === kind ) {
					var as = $r.find( '.dbmig-media-as' ).val();
					if ( ! src || ! as ) { return; }
					var $mt = $r.find( '.dbmig-media-target' );
					var mtarget = $mt.val() || '';
					var macf = ( $mt.is( 'select' ) ) ? ( $mt.find( 'option:selected' ).data( 'name' ) || '' ) : '';
					if ( ( 'meta' === as || 'acf' === as ) && ! mtarget ) { return; }
					p.fields.push( {
						target_kind: 'media',
						target: mtarget,
						acf_name: macf,
						source: src,
						attach_as: as,
						transform: 'none',
						term_match: 'name',
						term_create: 0,
						rel_table: ''
					} );
					return;
				}

				var $tv = $r.find( '.dbmig-extra-targetval' );
				var target = $tv.val();
				if ( ! target || ! src ) { return; }
				var acfName = ( kind === 'acf' && $tv.is( 'select' ) )
					? ( $tv.find( 'option:selected' ).data( 'name' ) || '' ) : '';
				p.fields.push( {
					target_kind: kind,
					target: target,
					acf_name: acfName,
					source: src,
					static_value: $r.find( '.dbmig-static-value' ).val() || '',
					transform: $r.find( '.dbmig-transform' ).val() || 'none',
					term_match: $r.find( '.dbmig-term-match' ).val() || 'name',
					term_slug: $r.find( '.dbmig-term-slug' ).val() || '',
					term_create: $r.find( '.dbmig-term-create' ).is( ':checked' ) ? 1 : 0,
					term_append: $r.find( '.dbmig-term-append' ).is( ':checked' ) ? 1 : 0,
					rel_table: $r.find( '.dbmig-resolve-table' ).val() || ''
				} );
			} );

			$( '#dbmig-repeaters-list .dbmig-repeater-row' ).each( function () {
				var $r = $( this );
				var acfField = $r.find( '.dbmig-rep-field' ).val();
				var childTable = $r.find( '.dbmig-rep-table' ).val();
				if ( ! acfField || ! childTable ) { return; }
				var subMap = [];
				$r.find( '.dbmig-submap-row' ).each( function () {
					var $s = $( this );
					var sf = $s.find( '.dbmig-rep-subfield' );
					if ( ! sf.val() ) { return; }
					subMap.push( {
						sub_field: sf.val(),
						sub_name: sf.find( 'option:selected' ).data( 'name' ) || '',
						source: $s.find( '.dbmig-rep-subcol' ).val(),
						rel_table: $s.find( '.dbmig-sub-reltable' ).val() || ''
					} );
				} );
				var repJoins = [];
				$r.find( '.dbmig-rep-via-row' ).each( function () {
					var $v = $( this );
					var vt = $v.find( '.dbmig-via-table' ).val();
					var vl = $v.find( '.dbmig-via-left' ).val();
					var vrt = $v.find( '.dbmig-via-right' ).val();
					if ( vt && vl && vrt ) {
						repJoins.push( { type: 'LEFT', table: vt, left_col: vl, right_col: vrt, latest_by: $v.find( '.dbmig-via-latest' ).val() || '' } );
					}
				} );
				p.repeaters.push( {
					acf_field: acfField,
					acf_name: $r.find( '.dbmig-rep-field option:selected' ).data( 'name' ) || '',
					child_table: childTable,
					child_fk: $r.find( '.dbmig-rep-fk' ).val(),
					parent_col: $r.find( '.dbmig-rep-parentcol' ).val() || '',
					order_by: $r.find( '.dbmig-rep-orderby' ).val(),
					joins: repJoins,
					sub_map: subMap
				} );
			} );

			return p;
		},

		save: function () {
			var self = this;
			var $res = $( '#dbmig-save-result' );
			var p = this.gather();
			if ( ! p.source_table || ! p.source_id_column ) {
				$res.removeClass( 'ok' ).addClass( 'err' ).text( 'Select a source table and its ID column first.' );
				return;
			}
			// Soft warning only: for a post migration, flag the common core fields
			// left unmapped (blank source = skip). Does NOT block saving — if it's
			// intentional the user just confirms.
			if ( p.migration_type === 'post' ) {
				var core = { post_title: 'Title (post_title)', post_content: 'Content (post_content)', post_name: 'Slug (post_name)', post_date: 'Date (post_date)' };
				var missing = [];
				$.each( core, function ( target, label ) {
					var mapped = ( p.fields || [] ).some( function ( f ) {
						return 'post_field' === f.target_kind && f.target === target && f.source;
					} );
					if ( ! mapped ) { missing.push( label ); }
				} );
				if ( missing.length && ! window.confirm(
					'These fields have no source column and will be left empty:\n\n  • ' +
					missing.join( '\n  • ' ) +
					'\n\nThat\'s fine if it\'s intentional. Save the migration anyway?'
				) ) {
					$res.removeClass( 'ok err' ).text( '' );
					return;
				}
			}

			$res.removeClass( 'ok err' ).text( DBMig.i18n.saving );
			Ajax.post( 'save_profile', { profile: JSON.stringify( p ) } ).done( function ( r ) {
				if ( r.success ) {
					$( '#dbmig-profile-id' ).val( r.data.id );
					$res.addClass( 'ok' ).text( r.data.message );
					$( '#dbmig-run, #dbmig-run-sql, #dbmig-generate-sql' ).prop( 'disabled', false );
				} else {
					$res.addClass( 'err' ).text( r.data.message );
				}
			} ).fail( function () {
				$res.addClass( 'err' ).text( 'Save failed.' );
			} );
		},

		/* ---- run ---- */
		run: function () {
			var self = this;
			var id = $( '#dbmig-profile-id' ).val();
			if ( ! id ) { alert( 'Save the migration first.' ); return; }
			var batch = parseInt( $( '#dbmig-batch-size' ).val(), 10 ) || 50;

			$( '#dbmig-progress-wrap' ).show();
			$( '#dbmig-log-wrap' ).show();
			$( '#dbmig-log' ).text( '' );
			$( '#dbmig-run' ).prop( 'disabled', true );

			Ajax.post( 'count', { id: id } ).done( function ( r ) {
				if ( ! r.success ) {
					self.appendLog( 'Error: ' + r.data.message );
					$( '#dbmig-run' ).prop( 'disabled', false );
					return;
				}
				var total = r.data.total;
				if ( total === 0 ) {
					self.setProgress( 100, 'No rows to import.' );
					$( '#dbmig-run' ).prop( 'disabled', false );
					return;
				}
				self.runBatch( id, 0, batch, total, { processed: 0, created: 0, updated: 0, skipped: 0 } );
			} );
		},

		runBatch: function ( id, offset, batch, total, totals ) {
			var self = this;
			Ajax.post( 'run_batch', { id: id, offset: offset, limit: batch } ).done( function ( r ) {
				if ( ! r.success ) {
					self.appendLog( 'Error: ' + r.data.message );
					$( '#dbmig-run' ).prop( 'disabled', false );
					return;
				}
				var d = r.data;
				totals.processed += d.processed;
				totals.created += d.created;
				totals.updated += d.updated;
				totals.skipped += d.skipped;
				( d.log || [] ).forEach( function ( line ) { self.appendLog( line ); } );

				var done = offset + d.processed;
				var pct = Math.min( 100, Math.round( ( done / total ) * 100 ) );
				self.setProgress( pct, done + ' / ' + total + ' — created ' + totals.created + ', updated ' + totals.updated + ', skipped ' + totals.skipped );

				if ( d.processed > 0 && done < total ) {
					self.runBatch( id, done, batch, total, totals );
				} else {
					self.setProgress( 100, DBMig.i18n.done + ' — created ' + totals.created + ', updated ' + totals.updated + ', skipped ' + totals.skipped );
					self.appendLog( '--- Finished ---' );
					$( '#dbmig-run' ).prop( 'disabled', false );
				}
			} ).fail( function () {
				self.appendLog( 'Request failed at offset ' + offset );
				$( '#dbmig-run' ).prop( 'disabled', false );
			} );
		},

		setProgress: function ( pct, text ) {
			$( '#dbmig-progress-bar' ).css( 'width', pct + '%' );
			$( '#dbmig-progress-text' ).text( text );
		},

		appendLog: function ( line ) {
			var $log = $( '#dbmig-log' );
			$log.append( document.createTextNode( line + '\n' ) );
			$log.scrollTop( $log[ 0 ].scrollHeight );
		},

		runSql: function () {
			var self = this;
			var id = $( '#dbmig-profile-id' ).val();
			if ( ! id ) { alert( 'Save the migration first.' ); return; }
			if ( ! confirm( 'Run the generated SQL now against the database (create-or-update)?' ) ) { return; }

			var $wrap = $( '#dbmig-sqlrun-wrap' ).show();
			var $bar = $( '#dbmig-sqlrun-bar' ).css( 'width', '0%' );
			var $text = $( '#dbmig-sqlrun-text' ).text( 'Preparing…' );
			var $log = $( '#dbmig-sqlrun-log' ).text( '' );
			$( '#dbmig-run, #dbmig-run-sql, #dbmig-generate-sql' ).prop( 'disabled', true );

			var appendLog = function ( line ) {
				$log.append( document.createTextNode( line + '\n' ) );
				$log.scrollTop( $log[ 0 ].scrollHeight );
			};
			var finish = function () {
				$( '#dbmig-run, #dbmig-run-sql, #dbmig-generate-sql' ).prop( 'disabled', false );
			};

			Ajax.post( 'run_sql_prepare', { id: id } ).done( function ( r ) {
				if ( ! r.success ) { $text.text( 'Error: ' + r.data.message ); finish(); return; }
				var total = r.data.total;
				if ( ! total ) { $text.text( 'Nothing to run.' ); finish(); return; }
				var labels = r.data.labels || [];

				var step = function ( i ) {
					if ( i >= total ) {
						$bar.css( 'width', '100%' );
						$text.text( 'Done — ' + total + ' statements executed.' );
						appendLog( '--- Finished ---' );
						finish();
						return;
					}
					$text.text( 'Running ' + ( i + 1 ) + ' / ' + total + ': ' + ( labels[ i ] || '' ) + ' …' );
					Ajax.post( 'run_sql_step', { id: id, index: i } ).done( function ( s ) {
						if ( ! s.success ) {
							appendLog( '✗ [' + ( i + 1 ) + '] ' + ( s.data.label || '' ) + ' — ERROR: ' + s.data.message );
							$text.text( 'Stopped at statement ' + ( i + 1 ) + ' due to an error (see log).' );
							finish();
							return;
						}
						appendLog( '✓ [' + ( i + 1 ) + '/' + total + '] ' + s.data.label + ' (' + s.data.rows + ' rows, ' + s.data.ms + 'ms)' );
						$bar.css( 'width', Math.round( ( ( i + 1 ) / total ) * 100 ) + '%' );
						step( i + 1 );
					} ).fail( function () {
						appendLog( '✗ request failed at statement ' + ( i + 1 ) );
						finish();
					} );
				};
				step( 0 );
			} ).fail( function () {
				$text.text( 'Prepare request failed.' );
				finish();
			} );
		},

		generateSql: function () {
			var self = this;
			var id = $( '#dbmig-profile-id' ).val();
			if ( ! id ) { alert( 'Save the migration first.' ); return; }
			var $btn = $( '#dbmig-generate-sql' ).prop( 'disabled', true );
			var $status = $( '#dbmig-sql-status' ).removeClass( 'ok err' );
			Ajax.post( 'generate_sql', { id: id } ).done( function ( r ) {
				if ( r.success ) {
					$( '#dbmig-sql-wrap' ).show();
					$( '#dbmig-sql' ).val( r.data.sql );
					$status.addClass( 'ok' ).text(
						r.data.existing
							? 'Mapping unchanged — reusing existing file: ' + r.data.record.file
							: 'New SQL file generated: ' + r.data.record.file
					);
					self.renderSqlList( r.data.listing );
				} else {
					$status.addClass( 'err' ).text( r.data.message );
				}
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		},

		loadSqlList: function () {
			var self = this;
			var id = $( '#dbmig-profile-id' ).val();
			if ( ! id ) { return; }
			Ajax.post( 'list_sql', { id: id } ).done( function ( r ) {
				if ( r.success ) {
					self.renderSqlList( r.data );
				}
			} );
		},

		renderSqlList: function ( listing ) {
			var files = ( listing && listing.files ) ? listing.files : [];
			var $body = $( '#dbmig-sql-rows' ).empty();
			if ( ! files.length ) {
				$( '#dbmig-sql-wrap' ).show();
				$body.html( '<tr><td colspan="5">' + 'No SQL files generated yet. Click "Generate SQL".' + '</td></tr>' );
				return;
			}
			$( '#dbmig-sql-wrap' ).show();
			var tpl = $( '#dbmig-tpl-sqlrow' ).html();
			files.forEach( function ( f ) {
				var badge = f.is_current
					? '<span class="dbmig-pill acf">current</span>'
					: '<span class="dbmig-pill rel">stale</span>';
				var html = tpl
					.replace( /\{file\}/g, f.file )
					.replace( /\{download\}/g, f.download_url )
					.replace( /\{created\}/g, f.created_human || '' )
					.replace( /\{size\}/g, Math.max( 1, Math.round( f.bytes / 1024 ) ) )
					.replace( /\{statusbadge\}/g, badge )
					.replace( /\{rowclass\}/g, f.is_current ? 'dbmig-row-current' : '' )
					.replace( /\{command\}/g, ( f.command || '' ).replace( /"/g, '&quot;' ) );
				$body.append( html );
			} );
		},

		deleteSql: function ( file ) {
			var self = this;
			if ( ! confirm( 'Delete ' + file + ' ?' ) ) { return; }
			var id = $( '#dbmig-profile-id' ).val();
			Ajax.post( 'delete_sql', { id: id, file: file } ).done( function ( r ) {
				if ( r.success ) {
					self.renderSqlList( r.data.listing );
				} else {
					alert( r.data.message );
				}
			} );
		}
	};

	/* ------------------------------------------------------------------ *
	 *  Migrations list page: import profiles from an exported JSON file
	 *  (export is a plain download link rendered server-side).
	 * ------------------------------------------------------------------ */
	function bindListTools() {
		var $btn = $( '#dbmig-import-btn' );
		if ( ! $btn.length ) {
			return;
		}

		// Export: send only the checked migrations (all if none checked).
		$( '#dbmig-check-all' ).on( 'change', function () {
			$( '.dbmig-export-check' ).prop( 'checked', this.checked );
		} );
		$( '#dbmig-export-btn' ).on( 'click', function ( e ) {
			var ids = $( '.dbmig-export-check:checked' ).map( function () { return this.value; } ).get();
			var href = this.href.split( '&ids=' )[0]; // base url (action + nonce)
			if ( ids.length ) {
				href += '&ids=' + encodeURIComponent( ids.join( ',' ) );
			}
			e.preventDefault();
			window.location = href;
		} );

		$btn.on( 'click', function () { $( '#dbmig-import-file' ).click(); } );
		$( '#dbmig-import-file' ).on( 'change', function () {
			var file = this.files && this.files[0];
			if ( ! file ) { return; }
			var $res = $( '#dbmig-import-result' ).text( 'Importing…' );
			var reader = new FileReader();
			reader.onload = function ( e ) {
				Ajax.post( 'import_profiles', { payload: e.target.result } )
					.done( function ( r ) {
						if ( r && r.success ) {
							$res.text( r.data.message );
							setTimeout( function () { location.reload(); }, 800 );
						} else {
							$res.text( ( r && r.data && r.data.message ) ? r.data.message : 'Import failed.' );
						}
					} )
					.fail( function () { $res.text( 'Import request failed.' ); } );
			};
			reader.readAsText( file );
			this.value = '';
		} );
	}

	/* ------------------------------------------------------------------ *
	 *  Normalize-source tool page
	 * ------------------------------------------------------------------ */
	function bindNormalizer() {
		var $wrap = $( '#dbmig-normalize' );
		if ( ! $wrap.length ) {
			return;
		}
		var columnsByTable = {};

		// Make a select searchable (Select2), re-applying cleanly when its options
		// are rebuilt. This page is outside the editor's MutationObserver scope.
		function searchify( $sel ) {
			if ( ! $.fn.select2 ) { return; }
			if ( $sel.hasClass( 'select2-hidden-accessible' ) ) { $sel.select2( 'destroy' ); }
			$sel.select2( { width: '320px', dropdownAutoWidth: false } );
		}

		function loadTables() {
			$( '#dbmig-nz-conn-err' ).text( '' );
			Ajax.post( 'get_tables' ).done( function ( r ) {
				if ( ! r.success ) { return; }
				var cur = $( '#dbmig-nz-table' ).val();
				var html = '<option value="">— select table —</option>';
				( r.data.tables || [] ).forEach( function ( t ) {
					html += '<option value="' + t + '">' + t + '</option>';
				} );
				$( '#dbmig-nz-table' ).html( html ).val( cur );
				searchify( $( '#dbmig-nz-table' ) );
				if ( r.data.source_error ) {
					$( '#dbmig-nz-conn-err' ).text( r.data.source_error );
				}
			} );
		}

		function fillCols( cols ) {
			var html = '<option value="">— select column —</option>';
			cols.forEach( function ( c ) {
				html += '<option value="' + c.name + '">' + c.name + ' (' + c.type + ')</option>';
			} );
			$( '#dbmig-nz-col' ).html( html );
			searchify( $( '#dbmig-nz-col' ) );
		}

		function loadColumns( t ) {
			if ( ! t ) { fillCols( [] ); return; }
			if ( columnsByTable[ t ] ) { fillCols( columnsByTable[ t ] ); return; }
			Ajax.post( 'get_columns', { table: t } ).done( function ( r ) {
				var cols = r.success ? ( r.data.columns || [] ) : [];
				columnsByTable[ t ] = cols;
				fillCols( cols );
			} );
		}

		function opts() {
			return {
				source_table: $( '#dbmig-nz-table' ).val(),
				name_col: $( '#dbmig-nz-col' ).val(),
				target_table: $( '#dbmig-nz-target' ).val(),
				fk_col: $( '#dbmig-nz-fk' ).val(),
				trim: $( '#dbmig-nz-trim' ).is( ':checked' ) ? 1 : 0
			};
		}

		function renderSummary( p ) {
			var items = [
				'<strong>' + p.distinct_names + '</strong> distinct name(s) → that many users will be created',
				'<strong>' + p.linkable_rows + '</strong> source row(s) will be linked to an id',
				'Lookup table ' + ( p.target_exists ? 'already exists — new names will be appended' : 'will be created' ),
				'Id column ' + ( p.fk_exists ? 'already exists — it will be (re)filled' : 'will be added' )
			];
			$( '#dbmig-nz-summary' ).html( '<li>' + items.join( '</li><li>' ) + '</li>' );
		}

		// Changing any input invalidates a prior preview — disable Run until the
		// user previews again (mirrors how editing a migration re-requires Save).
		function invalidate() {
			$( '#dbmig-nz-run' ).prop( 'disabled', true );
			$( '#dbmig-nz-plan-wrap' ).hide();
			$( '#dbmig-nz-result' ).removeClass( 'ok err' ).text( '' );
		}

		$( '#dbmig-nz-table' ).on( 'change', function () { loadColumns( $( this ).val() ); invalidate(); } );
		$( '#dbmig-nz-col, #dbmig-nz-trim' ).on( 'change', invalidate );
		$( '#dbmig-nz-target, #dbmig-nz-fk' ).on( 'input', invalidate );
		$( '#dbmig-nz-reload' ).on( 'click', loadTables );
		$( '#dbmig-nz-toggle-sql' ).on( 'click', function ( e ) { e.preventDefault(); $( '#dbmig-nz-sql' ).toggle(); } );

		$( '#dbmig-nz-preview' ).on( 'click', function () {
			var o = opts();
			var $res = $( '#dbmig-nz-result' ).removeClass( 'ok err' );
			if ( ! o.source_table || ! o.name_col ) {
				$res.addClass( 'err' ).text( 'Pick a source table and a name column first.' );
				return;
			}
			$res.text( 'Checking…' );
			Ajax.post( 'normalize_preview', o ).done( function ( r ) {
				if ( ! r.success ) {
					$res.addClass( 'err' ).text( r.data.message );
					return;
				}
				$res.addClass( 'ok' ).text( 'Ready — review below, then Run.' );
				renderSummary( r.data.preview );
				$( '#dbmig-nz-sql' ).val( r.data.sql );
				$( '#dbmig-nz-run-log' ).empty();
				$( '#dbmig-nz-next' ).hide();
				$( '#dbmig-nz-run-result' ).removeClass( 'ok err' ).text( '' );
				$( '#dbmig-nz-plan-wrap' ).show();
				$( '#dbmig-nz-run' ).prop( 'disabled', false );
			} ).fail( function () {
				$res.addClass( 'err' ).text( 'Request failed.' );
			} );
		} );

		$( '#dbmig-nz-run' ).on( 'click', function () {
			var o = opts();
			if ( ! window.confirm( 'This will CREATE/ALTER/UPDATE tables in the legacy database. Continue?' ) ) {
				return;
			}
			var $res = $( '#dbmig-nz-run-result' ).removeClass( 'ok err' ).text( 'Running…' );
			$( '#dbmig-nz-run' ).prop( 'disabled', true );
			Ajax.post( 'normalize_run', o ).done( function ( r ) {
				var log = ( r.data && r.data.results ) || [];
				$( '#dbmig-nz-run-log' ).html( log.length ? '<li>' + log.map( function ( x ) {
					return x.label + ' — ' + x.rows + ' row(s)';
				} ).join( '</li><li>' ) + '</li>' : '' );
				if ( ! r.success ) {
					$res.addClass( 'err' ).text( r.data.message );
					return;
				}
				$res.addClass( 'ok' ).text( 'Done.' );
				renderSummary( r.data.preview );
				$( '#dbmig-nz-next' ).show();
			} ).fail( function () {
				$res.addClass( 'err' ).text( 'Request failed.' );
			} ).always( function () {
				$( '#dbmig-nz-run' ).prop( 'disabled', false );
			} );
		} );

		loadTables();
	}

	$( function () {
		bindSettings();
		bindListTools();
		bindNormalizer();
		Editor.init();
	} );

} )( jQuery );
