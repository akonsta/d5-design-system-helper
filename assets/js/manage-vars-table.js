/* D5 Design System Helper — Variables Tabulator table */
( function () {
	'use strict';

	var varsTable  = null;
	var varSort    = { key: null, dir: 'asc' };
	var varFilters = {};

	var TYPE_LABELS = {
		colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts',
		images: 'Images', strings: 'Text', links: 'Links',
		global_color: 'Global Color',
	};

	// ── Helpers ───────────────────────────────────────────────────────────────

	function getTypeText( item ) {
		return TYPE_LABELS[ item.type ] || item.type || '';
	}

	function resolveVarRef( rawVal, allVars, allGc ) {
		var m = rawVal.match( /\$variable\s*\(\s*(\{[\s\S]*?\})\s*\)\s*\$/ );
		if ( ! m ) { return null; }
		var obj;
		try { obj = JSON.parse( m[1] ); } catch (e) { return null; }
		var refId = ( obj.value && obj.value.name ) ? obj.value.name : ( obj.id || null );
		if ( ! refId ) { return null; }
		var all = ( allVars || [] ).concat( allGc || [] );
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[i].id === refId ) { return all[i]; }
		}
		return null;
	}

	function h( s ) {
		return String( s )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function getLiveItem( id ) {
		var state = window.d5dshManageState;
		var md = state ? state.getManageData() : null;
		if ( ! md ) { return null; }
		var all = ( md.vars || [] ).concat( md.global_colors || [] );
		for ( var i = 0; i < all.length; i++ ) {
			if ( all[i].id === id ) { return all[i]; }
		}
		return null;
	}

	function notifyEdit() {
		var state = window.d5dshManageState;
		if ( ! state ) { return; }
		state.setChangeSource( 'inline' );
		state.updateSaveBar();
		state.updateDupeCount( state.getDupeLabels() );
	}

	function showCopyFlash( el ) {
		if ( el.querySelector( '.d5dsh-copy-flash' ) ) { return; }
		var f = document.createElement( 'span' );
		f.className = 'd5dsh-copy-flash'; f.textContent = 'Copied!';
		el.appendChild( f );
		setTimeout( function () { f.parentNode && f.parentNode.removeChild( f ); }, 1400 );
	}

	// ── Formatters ────────────────────────────────────────────────────────────

	function fmtNote( cell ) {
		var row = cell.getRow().getData();
		var key = 'var:' + ( row.id || '' );
		var fn  = window.d5dshNoteIndicatorHTML;
		if ( fn ) { return fn( key ); }
		return '<button type="button" class="d5dsh-note-btn" data-note-key="' + h( key ) + '" title="Add note">&#9675;</button>';
	}

	function fmtType( cell ) {
		var row  = cell.getRow().getData();
		var text = getTypeText( row );
		return '<span class="d5dsh-type-badge d5dsh-type-' + h( text.toLowerCase().replace( /\s+/g, '-' ) ) + '">' + h( text ) + '</span>';
	}

	function fmtId( cell ) {
		var row      = cell.getRow().getData();
		var id       = row.id || '';
		var isSystem = row.system || row.type === 'global_color';
		var out      = '<code>' + h( id ) + '</code>';
		if ( isSystem ) {
			var msg = row.type === 'global_color'
				? 'This is a Global Color. Its label is system-defined and cannot be changed.'
				: 'This is a system variable managed by Divi. Edit with caution.';
			out += '<sup class="d5dsh-system-dagger" data-dagger-msg="' + h( msg ) + '">&#8225;</sup>';
		}
		return out;
	}

	function fmtLabel( cell ) {
		var row      = cell.getRow().getData();
		var label    = row.label || '';
		var state    = window.d5dshManageState;
		var dupes    = state ? state.getDupeLabels() : {};
		var isDupe   = !! dupes[ label.toLowerCase().trim() ];
		var isSystem = row.system || row.type === 'global_color';
		var out = '<strong>' + h( label ) + '</strong>' + ( isDupe ? ' <span class="d5dsh-dupe-badge" title="Duplicate label">2&times;</span>' : '' );
		if ( ! isSystem ) {
			out = '<span class="d5dsh-editable-hint" title="Click to edit">' + out + '</span>';
		}
		return out;
	}

	function fmtValue( cell ) {
		var row     = cell.getRow().getData();
		var raw     = ( row.value || '' ).trim();
		var isColor = row.type === 'colors' || row.type === 'global_color';
		var state   = window.d5dshManageState;
		var md      = state ? state.getManageData() : null;
		var allVars = md ? ( md.vars || [] ) : [];
		var allGc   = md ? ( md.global_colors || [] ) : [];

		if ( isColor ) {
			if ( raw.indexOf( '$variable(' ) !== -1 ) {
				var ref  = resolveVarRef( raw, allVars, allGc );
				var sw   = ( ref && ref.value && ref.value.indexOf( '$variable(' ) === -1 )
					? '<span class="d5dsh-color-swatch-inline" style="background:' + h( ref.value ) + '" title="' + h( ref.value ) + '"></span>' : '';
				var disp  = ref ? ( '\u2192 ' + ref.label ) : raw;
				var title = ref ? ( 'References: ' + ref.label + ' (' + ref.value + ')\nRaw: ' + raw ) : raw;
				return sw + '<span class="d5dsh-readonly-cell d5dsh-var-ref" title="' + h( title ) + '">' + h( disp ) + '</span>';
			}
			var swatch = raw ? '<span class="d5dsh-color-swatch-inline" style="background:' + h( raw ) + '" title="' + h( raw ) + '"></span>' : '';
			return swatch + '<span class="d5dsh-readonly-cell">' + h( raw ) + '</span>';
		}

		if ( row.type === 'images' ) {
			var filename = raw.indexOf( 'data:' ) === 0 ? '[embedded image]' : ( raw.split( '/' ).pop() || raw );
			return '<span class="d5dsh-image-filename" title="' + h( raw ) + '">' + h( filename ) + '</span>';
		}

		return '<span class="d5dsh-editable-hint" title="' + h( raw ) + '">' + h( raw ) + '</span>';
	}

	function fmtStatus( cell ) {
		var row    = cell.getRow().getData();
		var status = row.status || 'active';
		return '<select class="d5dsh-status-select" data-row-id="' + h( row.id || '' ) + '">'
			+ [ 'active', 'archived', 'inactive' ].map( function ( s ) {
				return '<option value="' + s + '"' + ( status === s ? ' selected' : '' ) + '>' + s + '</option>';
			} ).join( '' )
			+ '</select>';
	}

	function fmtCategory( cell ) {
		var row     = cell.getRow().getData();
		var key     = 'var:' + ( row.id || '' );
		var state   = window.d5dshManageState;
		var catMap  = state ? state.getCategoryMap()    : {};
		var catData = state ? state.getCategoriesData() : [];
		var ids     = catMap[ key ] || [];
		if ( ! Array.isArray( ids ) ) { ids = [ ids ]; }
		return ids.filter( Boolean ).map( function ( cid ) {
			var c = null;
			for ( var i = 0; i < catData.length; i++ ) { if ( catData[i].id === cid ) { c = catData[i]; break; } }
			return c ? '<span class="d5dsh-category-swatch" style="background:' + h( c.color ) + '" title="' + h( c.label ) + '"></span>' : '';
		} ).join( '' );
	}

	function fmtDeps( cell ) {
		var row = cell.getRow().getData();
		return '<button type="button" class="d5dsh-impact-btn"'
			+ ' title="Impact analysis: what uses this variable?"'
			+ ' data-dso-type="variable"'
			+ ' data-dso-id="'    + h( row.id    || '' ) + '"'
			+ ' data-dso-label="' + h( row.label || row.id || '' ) + '">i</button>';
	}

	// ── Inline editing ────────────────────────────────────────────────────────

	function startInlineEdit( cell, field ) {
		var el      = cell.getElement();
		var row     = cell.getRow().getData();
		var item    = getLiveItem( row.id );
		if ( ! item ) { return; }
		var current = item[ field ] || '';
		var input   = document.createElement( 'input' );
		input.type      = 'text';
		input.value     = current;
		input.className = 'd5dsh-tabulator-inline-input';
		el.innerHTML = '';
		el.appendChild( input );
		input.focus();
		input.select();

		function commit() {
			var newVal = input.value.trim();
			if ( newVal !== current ) {
				item[ field ] = newVal;
				cell.getRow().update( { label: item.label, value: item.value } );
				notifyEdit();
			} else {
				cell.getRow().reformat();
			}
		}

		input.addEventListener( 'blur',    commit );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter'  ) { input.blur(); }
			if ( e.key === 'Escape' ) { input.value = current; input.blur(); }
		} );
	}

	// ── Column definitions ─────────────────────────────────────────────────────

	var FILTERABLE_COLS = [ 'type', 'id', 'label', 'value', 'status' ];

	var COLUMNS = [
		{
			title: '#', field: '_row_num',
			width: 44, minWidth: 44, maxWidth: 55,
			resizable: false, headerSort: false,
		},
		{
			title: 'Notes', field: '_note_key',
			width: 65, minWidth: 65, maxWidth: 80,
			resizable: false, headerSort: false, hozAlign: 'center',
			formatter: fmtNote,
			cellClick: function ( e, cell ) {
				var btn = e.target.closest( '.d5dsh-note-btn, .d5dsh-note-indicator' );
				if ( btn && typeof window.d5dshOpenNoteEditor === 'function' ) {
					window.d5dshOpenNoteEditor( btn.dataset.noteKey, btn );
				}
			},
		},
		{
			title: 'Type', field: 'type',
			width: 110, minWidth: 70, maxWidth: 140,
			headerSort: false, formatter: fmtType,
		},
		{
			title: 'ID', field: 'id',
			width: 178, minWidth: 80, maxWidth: 280,
			headerSort: false, formatter: fmtId,
			cellClick: function ( e, cell ) {
				var dagger = e.target.closest( '.d5dsh-system-dagger' );
				if ( dagger ) {
					e.stopPropagation();
					var existing = document.querySelector( '.d5dsh-dagger-popover' );
					if ( existing ) {
						var same = existing._sourceBtn === dagger;
						existing.parentNode.removeChild( existing );
						if ( same ) { return; }
					}
					var pop = document.createElement( 'div' );
					pop.className = 'd5dsh-dagger-popover'; pop._sourceBtn = dagger;
					pop.textContent = dagger.getAttribute( 'data-dagger-msg' ) || '';
					var x = document.createElement( 'button' );
					x.className = 'd5dsh-dagger-popover-close'; x.textContent = '×';
					x.addEventListener( 'click', function ( ev ) { ev.stopPropagation(); pop.parentNode && pop.parentNode.removeChild( pop ); } );
					pop.appendChild( x );
					var td = cell.getElement(); td.style.position = 'relative'; td.appendChild( pop );
					setTimeout( function () {
						document.addEventListener( 'click', function d() { pop.parentNode && pop.parentNode.removeChild( pop ); document.removeEventListener( 'click', d ); } );
					}, 0 );
					return;
				}
				var id = cell.getRow().getData().id || '';
				if ( id && navigator.clipboard ) { navigator.clipboard.writeText( id ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
			},
		},
		{
			title: 'Label', field: 'label',
			width: 189, minWidth: 80, maxWidth: 400,
			headerSort: false, formatter: fmtLabel,
			cellClick: function ( e, cell ) {
				var row = cell.getRow().getData();
				if ( row.system || row.type === 'global_color' ) { return; }
				startInlineEdit( cell, 'label' );
			},
		},
		{
			title: 'Value', field: 'value',
			width: 193, minWidth: 80, maxWidth: 500,
			headerSort: false, formatter: fmtValue,
			cellClick: function ( e, cell ) {
				var row     = cell.getRow().getData();
				var isColor = row.type === 'colors' || row.type === 'global_color';
				if ( isColor ) {
					var raw = row.value || '';
					if ( raw && navigator.clipboard ) { navigator.clipboard.writeText( raw ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
					return;
				}
				startInlineEdit( cell, 'value' );
			},
		},
		{
			title: 'Category', field: '_cat',
			width: 90, minWidth: 90, maxWidth: 220,
			headerSort: false, formatter: fmtCategory,
		},
		{
			title: 'Deps', field: '_deps',
			width: 58, minWidth: 58, maxWidth: 70,
			resizable: false, headerSort: false, hozAlign: 'center',
			formatter: fmtDeps,
			cellClick: function ( e, cell ) {
				var btn = e.target.closest( '.d5dsh-impact-btn' );
				if ( btn && typeof window.d5dshOpenImpactModal === 'function' ) {
					window.d5dshOpenImpactModal( btn.dataset.dsoType, btn.dataset.dsoId, btn.dataset.dsoLabel );
				}
			},
		},
		{
			title: 'Status', field: 'status',
			width: 116, minWidth: 80, maxWidth: 140,
			headerSort: false, formatter: fmtStatus,
			cellClick: function ( e, cell ) {
				var sel = e.target.closest( '.d5dsh-status-select' );
				if ( ! sel ) { return; }
				sel.addEventListener( 'change', function () {
					var item = getLiveItem( sel.dataset.rowId );
					if ( item ) {
						item.status = sel.value;
						cell.getRow().update( { status: sel.value } );
						notifyEdit();
					}
				}, { once: true } );
			},
		},
	];

	// ── Filter panel integration ───────────────────────────────────────────────

	function getDistinctValues( col ) {
		if ( ! varsTable ) { return []; }
		var seen = {};
		varsTable.getData().forEach( function ( row ) {
			var v = col === 'type' ? ( TYPE_LABELS[ row[ col ] ] || row[ col ] || '' ) : String( row[ col ] || '' );
			if ( v ) { seen[ v ] = true; }
		} );
		return Object.keys( seen ).sort();
	}

	function closeAllVarFilterPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) {
			p.parentNode && p.parentNode.removeChild( p );
		} );
	}

	function applyVarFiltersAndSort() {
		if ( ! varsTable ) { return; }

		var filters = [];
		Object.keys( varFilters ).forEach( function ( col ) {
			var f = varFilters[ col ];
			if ( ! f ) { return; }
			if ( f.mode === 'checklist' && f.vals && f.vals.length ) {
				// For type, match against the raw type field using TYPE_LABELS mapping.
				if ( col === 'type' ) {
					var rawVals = f.vals.map( function ( label ) {
						var found = Object.keys( TYPE_LABELS ).filter( function ( k ) { return TYPE_LABELS[k] === label; } );
						return found.length ? found[0] : label;
					} );
					filters.push( { field: col, type: 'in', value: rawVals } );
				} else {
					filters.push( { field: col, type: 'in', value: f.vals } );
				}
			} else if ( f.mode === 'contains' && f.val ) {
				filters.push( { field: col, type: 'like', value: f.val } );
			} else if ( f.mode === 'starts_with' && f.val ) {
				filters.push( { field: col, type: 'starts', value: f.val } );
			} else if ( f.mode === 'equals' && f.val ) {
				filters.push( { field: col, type: '=', value: f.val } );
			}
		} );
		varsTable.setFilter( filters );

		if ( varSort.key ) {
			varsTable.setSort( varSort.key, varSort.dir );
		} else {
			varsTable.clearSort();
		}

		// Update active filter indicators on column headers.
		varsTable.getColumns().forEach( function ( col ) {
			var field = col.getField();
			var el    = col.getElement();
			if ( ! el ) { return; }
			var hasFilter = varFilters[ field ] && (
				( varFilters[ field ].mode === 'checklist' && varFilters[ field ].vals && varFilters[ field ].vals.length ) ||
				( varFilters[ field ].val )
			);
			el.classList.toggle( 'd5dsh-col-filter-active', !! hasFilter );
		} );
	}

	function wireFilterHeaders() {
		if ( ! varsTable ) { return; }
		FILTERABLE_COLS.forEach( function ( colField ) {
			var col = varsTable.getColumn( colField );
			if ( ! col ) { return; }
			var th = col.getElement();
			if ( ! th ) { return; }

			th.style.cursor = 'pointer';

			// Append filter icon into the title element.
			var titleEl = th.querySelector( '.tabulator-col-title' );
			if ( titleEl && ! titleEl.querySelector( '.d5dsh-filter-icon' ) ) {
				var icon = document.createElement( 'span' );
				icon.className   = 'd5dsh-filter-icon';
				icon.textContent = ' \u25bc';
				titleEl.appendChild( icon );
			}

			// Attach to the title element, not the outer col div, to avoid
			// Tabulator's own click handler swallowing the event.
			var clickTarget = titleEl || th;
			clickTarget.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( document.querySelector( '.d5dsh-col-filter-panel[data-col="' + colField + '"]' ) ) {
					closeAllVarFilterPanels();
					return;
				}
				closeAllVarFilterPanels();
				var fn = window.d5dshOpenFilterPanel;
				if ( typeof fn !== 'function' ) { return; }
				fn( {
					th:           th,
					col:          colField,
					filtersObj:   varFilters,
					sortObj:      varSort,
					getValues:    getDistinctValues,
					onApply:      applyVarFiltersAndSort,
					onClear:      function () { delete varFilters[ colField ]; applyVarFiltersAndSort(); },
					onSort:       applyVarFiltersAndSort,
					closeAll:     closeAllVarFilterPanels,
					scrollWrapId: 'd5dsh-vars-tabulator',
				} );
			} );
		} );
	}

	// ── Height calculation ─────────────────────────────────────────────────────

	function calcHeight( el ) {
		// If the section is hidden, getBoundingClientRect returns zeros.
		// Walk the offset chain to estimate position.
		var rect = el.getBoundingClientRect();
		var top  = rect.top;
		if ( top <= 0 ) {
			var node = el, t = 0;
			while ( node ) { t += node.offsetTop || 0; node = node.offsetParent; }
			top = t - window.scrollY;
		}
		return Math.max( 228, window.innerHeight - top - 24 );
	}

	// ── Init ──────────────────────────────────────────────────────────────────

	var LS_KEY = 'd5dsh_tabcol_vars';

	function saveColWidths() {
		if ( ! varsTable ) { return; }
		var w = {};
		varsTable.getColumns().forEach( function ( c ) { w[ c.getField() || c.getDefinition().title ] = c.getWidth(); } );
		try { localStorage.setItem( LS_KEY, JSON.stringify( w ) ); } catch(e) {}
	}

	function restoreColWidths() {
		if ( ! varsTable ) { return; }
		var saved;
		try { saved = JSON.parse( localStorage.getItem( LS_KEY ) ); } catch(e) {}
		if ( ! saved ) { return; }
		varsTable.getColumns().forEach( function ( c ) {
			var key = c.getField() || c.getDefinition().title;
			if ( saved[ key ] ) { c.setWidth( saved[ key ] ); }
		} );
	}

	function initVarsTable() {
		var el = document.getElementById( 'd5dsh-vars-tabulator' );
		if ( ! el || typeof Tabulator === 'undefined' ) { return; }

		varsTable = new Tabulator( '#d5dsh-vars-tabulator', {
			height:           500,
			layout:           'fitDataFill',
			movableColumns:   true,
			resizableColumns: true,
			placeholder:      'No items match the current filters.',
			columns:          COLUMNS,
			columnResized:    function () { saveColWidths(); },
		} );

		window.addEventListener( 'resize', function () {
			if ( varsTable ) { varsTable.setHeight( calcHeight( el ) ); }
		} );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	window.d5dshVarsTable = {
		recalcHeight: function () {
			var el = document.getElementById( 'd5dsh-vars-tabulator' );
			if ( varsTable && el ) { varsTable.setHeight( calcHeight( el ) ); }
		},
		render: function ( rows ) {
			var el = document.getElementById( 'd5dsh-vars-tabulator' );
			if ( ! varsTable || ! el ) { return; }
			var data = rows.map( function ( item, idx ) {
				return Object.assign( {}, item, {
					_row_num:  item.order != null ? item.order : ( idx + 1 ),
					_note_key: 'var:' + ( item.id || '' ),
					_cat:      'var:' + ( item.id || '' ),
					_deps:     item.id || '',
				} );
			} );
			varsTable.setData( data );
			setTimeout( function () {
				restoreColWidths();
				varsTable.setHeight( calcHeight( el ) );
				wireFilterHeaders();
			}, 50 );
		},
		reset: function () {
			if ( ! varsTable ) { return; }
			varFilters = {};
			varSort    = { key: null, dir: 'asc' };
			varsTable.clearFilter( true );
			varsTable.clearSort();
			closeAllVarFilterPanels();
			applyVarFiltersAndSort();
		},
		refreshNotes: function () {
			if ( ! varsTable ) { return; }
			varsTable.getRows().forEach( function ( row ) { row.reformat(); } );
		},
		getInstance: function () { return varsTable; },
	};

	// ── Boot ──────────────────────────────────────────────────────────────────

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initVarsTable );
	} else {
		initVarsTable();
	}

}() );
