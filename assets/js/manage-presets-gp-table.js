/* D5 Design System Helper — Group Presets Tabulator table */
( function () {
	'use strict';

	var gpTable  = null;
	var gpSort   = { key: null, dir: 'asc' };
	var gpFilters = {};

	function h( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function abbrevModule( s ) {
		var fn = window.d5dshAbbreviateModule;
		return fn ? fn( s || '' ).short : ( s || '' );
	}

	function abbrevGroupId( s ) {
		var fn = window.d5dshAbbreviateGroupId;
		return fn ? fn( s || '' ).short : ( s || '' );
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
		var key = 'preset:' + ( row.preset_id || '' );
		var fn = window.d5dshNoteIndicatorHTML;
		if ( fn ) { return fn( key ); }
		return '<button type="button" class="d5dsh-note-btn" data-note-key="' + h( key ) + '" title="Add note">&#9675;</button>';
	}

	function fmtGroupName( cell ) {
		var v = cell.getValue() || '';
		return '<span class="d5dsh-copy-cell" title="' + h( v ) + '">' + h( abbrevGroupId( v ) ) + '</span>';
	}

	function fmtId( cell ) {
		var v = cell.getValue() || '';
		return '<code class="d5dsh-copy-cell" title="' + h( v ) + '">' + h( v ) + '</code>';
	}

	function fmtLabel( cell ) {
		var row = cell.getRow().getData();
		var name = row.name || '';
		var dirty = row._dirty;
		var mode = row._mode;
		if ( mode === 'manage' ) {
			return '<input type="text" class="d5dsh-preset-name-input' + ( dirty ? ' d5dsh-cell-dirty' : '' ) + '"'
				+ ' data-preset-id="' + h( row.preset_id || '' ) + '"'
				+ ' data-module-name="' + h( row.module_name || '' ) + '"'
				+ ' data-group-id="' + h( row.group_id || '' ) + '"'
				+ ' data-table-key="gp"'
				+ ' data-original="' + h( row._originalName || name ) + '"'
				+ ' value="' + h( name ) + '">';
		}
		return h( name ) + ( dirty ? ' <span class="d5dsh-dirty-dot" title="Unsaved change">•</span>' : '' );
	}

	function fmtModule( cell ) {
		var v = cell.getValue() || '';
		return '<span title="' + h( v ) + '">' + h( abbrevModule( v ) ) + '</span>';
	}

	function fmtGroupId( cell ) {
		var v = cell.getValue() || '';
		return '<code class="d5dsh-copy-cell" title="' + h( v ) + '">' + h( abbrevGroupId( v ) ) + '</code>';
	}

	function fmtDefault( cell ) {
		return cell.getValue() ? '<span class="d5dsh-default-badge" title="Default preset">&#10003;</span>' : '';
	}

	// ── Columns ────────────────────────────────────────────────────────────────

	var FILTERABLE_COLS = [ 'group_name', 'preset_id', 'name', 'module_name', 'group_id' ];

	var COLUMNS = [
		{ title: '#',          field: '_row_num',   width: 44,  minWidth: 44,  maxWidth: 55,  resizable: false, headerSort: false },
		{ title: 'Notes',      field: '_note_key',  width: 65,  minWidth: 65,  maxWidth: 80,  resizable: false, headerSort: false, hozAlign: 'center', formatter: fmtNote,
			cellClick: function ( e, cell ) {
				var btn = e.target.closest( '.d5dsh-note-btn, .d5dsh-note-indicator' );
				if ( btn && typeof window.d5dshOpenNoteEditor === 'function' ) { window.d5dshOpenNoteEditor( btn.dataset.noteKey, btn ); }
			},
		},
		{ title: 'Group Name', field: 'group_name', width: 150, minWidth: 80,  maxWidth: 200, headerSort: false, formatter: fmtGroupName,
			cellClick: function ( e, cell ) {
				var v = cell.getValue() || '';
				if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
			},
		},
		{ title: 'ID',         field: 'preset_id',  width: 188, minWidth: 80,  maxWidth: 280, headerSort: false, formatter: fmtId,
			cellClick: function ( e, cell ) {
				var v = cell.getValue() || '';
				if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
			},
		},
		{ title: 'Label',      field: 'name',       width: 240, minWidth: 80,  maxWidth: 400, headerSort: false, formatter: fmtLabel,
			cellClick: function ( e, cell ) {
				var input = e.target.closest( 'input.d5dsh-preset-name-input' );
				if ( input ) {
					input.addEventListener( 'change', function () {
						var fn = window.d5dshHandlePresetsNameChange;
						if ( fn ) { fn( 'gp', input ); }
					}, { once: true } );
				}
			},
		},
		{ title: 'Module',     field: 'module_name', width: 125, minWidth: 70,  maxWidth: 200, headerSort: false, formatter: fmtModule },
		{ title: 'Group ID',   field: 'group_id',   width: 137, minWidth: 80,  maxWidth: 200, headerSort: false, formatter: fmtGroupId,
			cellClick: function ( e, cell ) {
				var v = cell.getValue() || '';
				if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
			},
		},
		{ title: 'Default',    field: 'is_default', width: 90,  minWidth: 80,  maxWidth: 110, headerSort: false, hozAlign: 'center', formatter: fmtDefault },
	];

	// ── Filter / sort ──────────────────────────────────────────────────────────

	function getDistinctValues( col ) {
		if ( ! gpTable ) { return []; }
		var seen = {};
		gpTable.getData().forEach( function ( row ) { var v = String( row[ col ] || '' ); if ( v ) { seen[ v ] = true; } } );
		return Object.keys( seen ).sort();
	}

	function closeAllPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) { p.parentNode && p.parentNode.removeChild( p ); } );
	}

	function applyFiltersAndSort() {
		if ( ! gpTable ) { return; }
		var filters = [];
		Object.keys( gpFilters ).forEach( function ( col ) {
			var f = gpFilters[ col ];
			if ( ! f ) { return; }
			if ( f.mode === 'checklist' && f.vals && f.vals.length ) { filters.push( { field: col, type: 'in', value: f.vals } ); }
			else if ( f.mode === 'contains' && f.val ) { filters.push( { field: col, type: 'like', value: f.val } ); }
			else if ( f.mode === 'starts_with' && f.val ) { filters.push( { field: col, type: 'starts', value: f.val } ); }
			else if ( f.mode === 'equals' && f.val ) { filters.push( { field: col, type: '=', value: f.val } ); }
		} );
		gpTable.setFilter( filters );
		if ( gpSort.key ) { gpTable.setSort( gpSort.key, gpSort.dir ); } else { gpTable.clearSort(); }
		gpTable.getColumns().forEach( function ( col ) {
			var el = col.getElement(); if ( ! el ) { return; }
			var f = gpFilters[ col.getField() ];
			var active = f && ( ( f.mode === 'checklist' && f.vals && f.vals.length ) || f.val );
			el.classList.toggle( 'd5dsh-col-filter-active', !! active );
		} );
	}

	function wireFilterHeaders() {
		if ( ! gpTable ) { return; }
		FILTERABLE_COLS.forEach( function ( colField ) {
			var col = gpTable.getColumn( colField ); if ( ! col ) { return; }
			var th = col.getElement(); if ( ! th ) { return; }
			th.style.cursor = 'pointer';
			var titleEl = th.querySelector( '.tabulator-col-title' );
			if ( titleEl && ! titleEl.querySelector( '.d5dsh-filter-icon' ) ) {
				var icon = document.createElement( 'span' );
				icon.className = 'd5dsh-filter-icon'; icon.textContent = ' \u25bc';
				titleEl.appendChild( icon );
			}
			var clickTarget = titleEl || th;
			clickTarget.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( document.querySelector( '.d5dsh-col-filter-panel[data-col="' + colField + '"]' ) ) { closeAllPanels(); return; }
				closeAllPanels();
				var fn = window.d5dshOpenFilterPanel; if ( typeof fn !== 'function' ) { return; }
				fn( { th: th, col: colField, filtersObj: gpFilters, sortObj: gpSort, getValues: getDistinctValues, onApply: applyFiltersAndSort, onClear: function () { delete gpFilters[ colField ]; applyFiltersAndSort(); }, onSort: applyFiltersAndSort, closeAll: closeAllPanels, scrollWrapId: 'd5dsh-presets-gp-tabulator' } );
			} );
		} );
	}

	// ── Height ─────────────────────────────────────────────────────────────────

	function calcHeight( el ) {
		var rect = el.getBoundingClientRect();
		var top = rect.top;
		if ( top <= 0 ) { var node = el, t = 0; while ( node ) { t += node.offsetTop || 0; node = node.offsetParent; } top = t - window.scrollY; }
		return Math.max( 228, window.innerHeight - top - 24 );
	}

	// ── Init ──────────────────────────────────────────────────────────────────

	var LS_KEY = 'd5dsh_tabcol_gp';

	function saveColWidths() {
		if ( ! gpTable ) { return; }
		var w = {};
		gpTable.getColumns().forEach( function ( c ) { w[ c.getField() || c.getDefinition().title ] = c.getWidth(); } );
		try { localStorage.setItem( LS_KEY, JSON.stringify( w ) ); } catch(e) {}
	}

	function restoreColWidths() {
		if ( ! gpTable ) { return; }
		var saved;
		try { saved = JSON.parse( localStorage.getItem( LS_KEY ) ); } catch(e) {}
		if ( ! saved ) { return; }
		gpTable.getColumns().forEach( function ( c ) {
			var key = c.getField() || c.getDefinition().title;
			if ( saved[ key ] ) { c.setWidth( saved[ key ] ); }
		} );
	}

	function initTable() {
		var el = document.getElementById( 'd5dsh-presets-gp-tabulator' );
		if ( ! el || typeof Tabulator === 'undefined' ) { return; }
		gpTable = new Tabulator( '#d5dsh-presets-gp-tabulator', {
			height: 500, layout: 'fitDataFill', movableColumns: true, resizableColumns: true,
			placeholder: 'No items match the current filters.', columns: COLUMNS,
			columnResized: function () { saveColWidths(); },
		} );
		window.addEventListener( 'resize', function () { if ( gpTable ) { gpTable.setHeight( calcHeight( el ) ); } } );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	window.d5dshPresetsGpTable = {
		recalcHeight: function () { var el = document.getElementById( 'd5dsh-presets-gp-tabulator' ); if ( gpTable && el ) { gpTable.setHeight( calcHeight( el ) ); } },
		render: function ( rows, mode ) {
			var el = document.getElementById( 'd5dsh-presets-gp-tabulator' ); if ( ! gpTable || ! el ) { return; }
			var data = rows.map( function ( row, idx ) {
				return Object.assign( {}, row, { _row_num: row.order != null ? row.order : ( idx + 1 ), _note_key: 'preset:' + ( row.preset_id || '' ), _mode: mode || 'view', _dirty: false } );
			} );
			gpTable.setData( data );
			setTimeout( function () { restoreColWidths(); gpTable.setHeight( calcHeight( el ) ); wireFilterHeaders(); }, 50 );
		},
		reset: function () {
			if ( ! gpTable ) { return; }
			gpFilters = {}; gpSort = { key: null, dir: 'asc' };
			gpTable.clearFilter( true ); gpTable.clearSort(); closeAllPanels();
		},
		refreshNotes: function () { if ( gpTable ) { gpTable.getRows().forEach( function ( r ) { r.reformat(); } ); } },
		getInstance: function () { return gpTable; },
	};

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', initTable ); } else { initTable(); }

}() );
