/* D5 Design System Helper — Element Presets Tabulator table */
( function () {
	'use strict';

	var epTable   = null;
	var epSort    = { key: null, dir: 'asc' };
	var epFilters = {};

	function h( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function abbrevModule( s ) {
		var fn = window.d5dshAbbreviateModule;
		return fn ? fn( s || '' ).short : ( s || '' );
	}

	function showCopyFlash( el ) {
		if ( el.querySelector( '.d5dsh-copy-flash' ) ) { return; }
		var f = document.createElement( 'span' );
		f.className = 'd5dsh-copy-flash'; f.textContent = 'Copied!';
		el.appendChild( f );
		setTimeout( function () { f.parentNode && f.parentNode.removeChild( f ); }, 1400 );
	}

	function fmtNote( cell ) {
		var row = cell.getRow().getData();
		var key = 'preset:' + ( row.preset_id || '' );
		var fn = window.d5dshNoteIndicatorHTML;
		if ( fn ) { return fn( key ); }
		return '<button type="button" class="d5dsh-note-btn" data-note-key="' + h( key ) + '" title="Add note">&#9675;</button>';
	}

	function fmtElement( cell ) {
		var v = cell.getValue() || '';
		return '<span title="' + h( v ) + '">' + h( abbrevModule( v ) ) + '</span>';
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
				+ ' data-table-key="ep"'
				+ ' data-original="' + h( row._originalName || name ) + '"'
				+ ' value="' + h( name ) + '">';
		}
		return h( name ) + ( dirty ? ' <span class="d5dsh-dirty-dot" title="Unsaved change">•</span>' : '' );
	}

	function fmtDefault( cell ) {
		return cell.getValue() ? '<span class="d5dsh-default-badge" title="Default preset">&#10003;</span>' : '';
	}

	var FILTERABLE_COLS = [ 'module_name', 'preset_id', 'name' ];

	var COLUMNS = [
		{ title: '#',       field: '_row_num',   width: 44,  minWidth: 44, maxWidth: 55,  resizable: false, headerSort: false },
		{ title: 'Notes',   field: '_note_key',  width: 65,  minWidth: 65, maxWidth: 80,  resizable: false, headerSort: false, hozAlign: 'center', formatter: fmtNote,
			cellClick: function ( e, cell ) {
				var btn = e.target.closest( '.d5dsh-note-btn, .d5dsh-note-indicator' );
				if ( btn && typeof window.d5dshOpenNoteEditor === 'function' ) { window.d5dshOpenNoteEditor( btn.dataset.noteKey, btn ); }
			},
		},
		{ title: 'Element', field: 'module_name', width: 200, minWidth: 80, maxWidth: 300, headerSort: false, formatter: fmtElement },
		{ title: 'ID',      field: 'preset_id',  width: 220, minWidth: 80, maxWidth: 300, headerSort: false, formatter: fmtId,
			cellClick: function ( e, cell ) {
				var v = cell.getValue() || '';
				if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); }
			},
		},
		{ title: 'Label',   field: 'name',       width: 400, minWidth: 80, maxWidth: 600, headerSort: false, formatter: fmtLabel,
			cellClick: function ( e, cell ) {
				var input = e.target.closest( 'input.d5dsh-preset-name-input' );
				if ( input ) {
					input.addEventListener( 'change', function () {
						var fn = window.d5dshHandlePresetsNameChange;
						if ( fn ) { fn( 'ep', input ); }
					}, { once: true } );
				}
			},
		},
		{ title: 'Default', field: 'is_default', width: 90,  minWidth: 80, maxWidth: 110, headerSort: false, hozAlign: 'center', formatter: fmtDefault },
	];

	function getDistinctValues( col ) {
		if ( ! epTable ) { return []; }
		var seen = {};
		epTable.getData().forEach( function ( row ) { var v = String( row[ col ] || '' ); if ( v ) { seen[ v ] = true; } } );
		return Object.keys( seen ).sort();
	}

	function closeAllPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) { p.parentNode && p.parentNode.removeChild( p ); } );
	}

	function applyFiltersAndSort() {
		if ( ! epTable ) { return; }
		var filters = [];
		Object.keys( epFilters ).forEach( function ( col ) {
			var f = epFilters[ col ]; if ( ! f ) { return; }
			if ( f.mode === 'checklist' && f.vals && f.vals.length ) { filters.push( { field: col, type: 'in', value: f.vals } ); }
			else if ( f.mode === 'contains' && f.val ) { filters.push( { field: col, type: 'like', value: f.val } ); }
			else if ( f.mode === 'starts_with' && f.val ) { filters.push( { field: col, type: 'starts', value: f.val } ); }
			else if ( f.mode === 'equals' && f.val ) { filters.push( { field: col, type: '=', value: f.val } ); }
		} );
		epTable.setFilter( filters );
		if ( epSort.key ) { epTable.setSort( epSort.key, epSort.dir ); } else { epTable.clearSort(); }
		epTable.getColumns().forEach( function ( col ) {
			var el = col.getElement(); if ( ! el ) { return; }
			var f = epFilters[ col.getField() ];
			el.classList.toggle( 'd5dsh-col-filter-active', !! ( f && ( ( f.mode === 'checklist' && f.vals && f.vals.length ) || f.val ) ) );
		} );
	}

	function wireFilterHeaders() {
		if ( ! epTable ) { return; }
		FILTERABLE_COLS.forEach( function ( colField ) {
			var col = epTable.getColumn( colField ); if ( ! col ) { return; }
			var th = col.getElement(); if ( ! th ) { return; }
			th.style.cursor = 'pointer';
			var titleEl = th.querySelector( '.tabulator-col-title' );
			if ( titleEl && ! titleEl.querySelector( '.d5dsh-filter-icon' ) ) {
				var icon = document.createElement( 'span' ); icon.className = 'd5dsh-filter-icon'; icon.textContent = ' \u25bc'; titleEl.appendChild( icon );
			}
			var clickTarget = titleEl || th;
			clickTarget.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				if ( document.querySelector( '.d5dsh-col-filter-panel[data-col="' + colField + '"]' ) ) { closeAllPanels(); return; }
				closeAllPanels();
				var fn = window.d5dshOpenFilterPanel; if ( typeof fn !== 'function' ) { return; }
				fn( { th: th, col: colField, filtersObj: epFilters, sortObj: epSort, getValues: getDistinctValues, onApply: applyFiltersAndSort, onClear: function () { delete epFilters[ colField ]; applyFiltersAndSort(); }, onSort: applyFiltersAndSort, closeAll: closeAllPanels, scrollWrapId: 'd5dsh-presets-ep-tabulator' } );
			} );
		} );
	}

	function calcHeight( el ) {
		var rect = el.getBoundingClientRect(); var top = rect.top;
		if ( top <= 0 ) { var node = el, t = 0; while ( node ) { t += node.offsetTop || 0; node = node.offsetParent; } top = t - window.scrollY; }
		return Math.max( 228, window.innerHeight - top - 24 );
	}

	var LS_KEY = 'd5dsh_tabcol_ep';

	function saveColWidths() {
		if ( ! epTable ) { return; }
		var w = {};
		epTable.getColumns().forEach( function ( c ) { w[ c.getField() || c.getDefinition().title ] = c.getWidth(); } );
		try { localStorage.setItem( LS_KEY, JSON.stringify( w ) ); } catch(e) {}
	}

	function restoreColWidths() {
		if ( ! epTable ) { return; }
		var saved;
		try { saved = JSON.parse( localStorage.getItem( LS_KEY ) ); } catch(e) {}
		if ( ! saved ) { return; }
		epTable.getColumns().forEach( function ( c ) {
			var key = c.getField() || c.getDefinition().title;
			if ( saved[ key ] ) { c.setWidth( saved[ key ] ); }
		} );
	}

	function initTable() {
		var el = document.getElementById( 'd5dsh-presets-ep-tabulator' );
		if ( ! el || typeof Tabulator === 'undefined' ) { return; }
		epTable = new Tabulator( '#d5dsh-presets-ep-tabulator', {
			height: 500, layout: 'fitDataFill', movableColumns: true, resizableColumns: true,
			placeholder: 'No items match the current filters.', columns: COLUMNS,
			columnResized: function () { saveColWidths(); },
		} );
		window.addEventListener( 'resize', function () { if ( epTable ) { epTable.setHeight( calcHeight( el ) ); } } );
	}

	window.d5dshPresetsEpTable = {
		recalcHeight: function () { var el = document.getElementById( 'd5dsh-presets-ep-tabulator' ); if ( epTable && el ) { epTable.setHeight( calcHeight( el ) ); } },
		render: function ( rows, mode ) {
			var el = document.getElementById( 'd5dsh-presets-ep-tabulator' ); if ( ! epTable || ! el ) { return; }
			var data = rows.map( function ( row, idx ) {
				return Object.assign( {}, row, { _row_num: row.order != null ? row.order : ( idx + 1 ), _note_key: 'preset:' + ( row.preset_id || '' ), _mode: mode || 'view', _dirty: false } );
			} );
			epTable.setData( data );
			setTimeout( function () { restoreColWidths(); epTable.setHeight( calcHeight( el ) ); wireFilterHeaders(); }, 50 );
		},
		reset: function () {
			if ( ! epTable ) { return; }
			epFilters = {}; epSort = { key: null, dir: 'asc' };
			epTable.clearFilter( true ); epTable.clearSort(); closeAllPanels();
		},
		refreshNotes: function () { if ( epTable ) { epTable.getRows().forEach( function ( r ) { r.reformat(); } ); } },
		getInstance: function () { return epTable; },
	};

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', initTable ); } else { initTable(); }

}() );
