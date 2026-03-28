/* D5 Design System Helper — Everything Tabulator table */
( function () {
	'use strict';

	var evTable   = null;
	var evSort    = { key: null, dir: 'asc' };
	var evFilters = {};

	function h( s ) {
		return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
	}

	function showCopyFlash( el ) {
		if ( el.querySelector( '.d5dsh-copy-flash' ) ) { return; }
		var f = document.createElement( 'span' ); f.className = 'd5dsh-copy-flash'; f.textContent = 'Copied!';
		el.appendChild( f ); setTimeout( function () { f.parentNode && f.parentNode.removeChild( f ); }, 1400 );
	}

	function fmtNote( cell ) {
		var row = cell.getRow().getData();
		var key = ( row._source === 'group_preset' || row._source === 'element_preset' )
			? 'preset:' + ( row.dso_id || '' ) : 'var:' + ( row.dso_id || '' );
		var fn = window.d5dshNoteIndicatorHTML; if ( fn ) { return fn( key ); }
		return '<button type="button" class="d5dsh-note-btn" data-note-key="' + h( key ) + '" title="Add note">&#9675;</button>';
	}

	function fmtCategory( cell ) {
		var row = cell.getRow().getData();
		return row._categoryHtml || h( row.dso_category || '' );
	}

	function fmtId( cell ) {
		var v = cell.getValue() || '';
		return '<code class="d5dsh-copy-cell" title="' + h( v ) + '">' + h( v ) + '</code>';
	}

	function fmtValue( cell ) {
		var row = cell.getRow().getData();
		var raw = row.dso_value || '';
		if ( row._isColor && raw && /^#[0-9a-f]{3,8}$/i.test( raw.trim() ) ) {
			return '<span class="d5dsh-color-swatch-inline" style="background:' + h( raw.trim() ) + '" title="' + h( raw ) + '"></span>' + h( raw );
		}
		return '<span title="' + h( raw ) + '">' + h( raw ) + '</span>';
	}

	function fmtGroupId( cell ) {
		var v = cell.getValue() || ''; if ( ! v ) { return ''; }
		return '<code class="d5dsh-copy-cell" title="' + h( v ) + '">' + h( v ) + '</code>';
	}

	function fmtDefault( cell ) {
		return cell.getValue() === 'Yes' ? '<span class="d5dsh-default-badge" title="Default preset">&#10003;</span>' : '';
	}

	var FILTERABLE_COLS = [ 'dso_category', 'dso_type', 'dso_id', 'dso_label', 'dso_value', 'dso_module', 'dso_group_id', 'dso_status' ];

	var COLUMNS = [
		{ title: '#',               field: '_row_num',    width: 44,  minWidth: 44, maxWidth: 55,  resizable: false, headerSort: false },
		{ title: 'Notes',           field: '_note_key',   width: 65,  minWidth: 65, maxWidth: 80,  resizable: false, headerSort: false, hozAlign: 'center', formatter: fmtNote,
			cellClick: function ( e, cell ) { var btn = e.target.closest( '.d5dsh-note-btn, .d5dsh-note-indicator' ); if ( btn && typeof window.d5dshOpenNoteEditor === 'function' ) { window.d5dshOpenNoteEditor( btn.dataset.noteKey, btn ); } },
		},
		{ title: 'Category',        field: 'dso_category', width: 90, minWidth: 80, maxWidth: 160, headerSort: false, formatter: fmtCategory },
		{ title: 'Type',            field: 'dso_type',    width: 110, minWidth: 80, maxWidth: 160, headerSort: false },
		{ title: 'ID',              field: 'dso_id',      width: 174, minWidth: 80, maxWidth: 280, headerSort: false, formatter: fmtId,
			cellClick: function ( e, cell ) { var v = cell.getValue() || ''; if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); } },
		},
		{ title: 'Label',           field: 'dso_label',   width: 227, minWidth: 80, maxWidth: 400, headerSort: false },
		{ title: 'Value',           field: 'dso_value',   width: 140, minWidth: 80, maxWidth: 300, headerSort: false, formatter: fmtValue,
			cellClick: function ( e, cell ) { var v = cell.getValue() || ''; if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); } },
		},
		{ title: 'Element / Module',field: 'dso_module',  width: 136, minWidth: 80, maxWidth: 200, headerSort: false },
		{ title: 'Group ID',        field: 'dso_group_id',width: 110, minWidth: 80, maxWidth: 180, headerSort: false, formatter: fmtGroupId,
			cellClick: function ( e, cell ) { var v = cell.getValue() || ''; if ( v && navigator.clipboard ) { navigator.clipboard.writeText( v ).catch( function(){} ); showCopyFlash( cell.getElement() ); } },
		},
		{ title: 'Default',         field: 'dso_is_default',width: 80, minWidth: 70, maxWidth: 100, headerSort: false, hozAlign: 'center', formatter: fmtDefault },
		{ title: 'Status',          field: 'dso_status',  width: 90,  minWidth: 70, maxWidth: 120, headerSort: false },
	];

	function getDistinctValues( col ) {
		if ( ! evTable ) { return []; }
		var seen = {}; evTable.getData().forEach( function ( row ) { var v = String( row[ col ] || '' ); if ( v ) { seen[ v ] = true; } } );
		return Object.keys( seen ).sort();
	}

	function closeAllPanels() { document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) { p.parentNode && p.parentNode.removeChild( p ); } ); }

	function applyFiltersAndSort() {
		if ( ! evTable ) { return; }
		var filters = [];
		Object.keys( evFilters ).forEach( function ( col ) {
			var f = evFilters[ col ]; if ( ! f ) { return; }
			if ( f.mode === 'checklist' && f.vals && f.vals.length ) { filters.push( { field: col, type: 'in', value: f.vals } ); }
			else if ( f.mode === 'contains' && f.val ) { filters.push( { field: col, type: 'like', value: f.val } ); }
			else if ( f.mode === 'starts_with' && f.val ) { filters.push( { field: col, type: 'starts', value: f.val } ); }
			else if ( f.mode === 'equals' && f.val ) { filters.push( { field: col, type: '=', value: f.val } ); }
		} );
		evTable.setFilter( filters );
		if ( evSort.key ) { evTable.setSort( evSort.key, evSort.dir ); } else { evTable.clearSort(); }
		evTable.getColumns().forEach( function ( col ) {
			var el = col.getElement(); if ( ! el ) { return; }
			var f = evFilters[ col.getField() ];
			el.classList.toggle( 'd5dsh-col-filter-active', !! ( f && ( ( f.mode === 'checklist' && f.vals && f.vals.length ) || f.val ) ) );
		} );
	}

	function wireFilterHeaders() {
		if ( ! evTable ) { return; }
		FILTERABLE_COLS.forEach( function ( colField ) {
			var col = evTable.getColumn( colField ); if ( ! col ) { return; }
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
				fn( { th: th, col: colField, filtersObj: evFilters, sortObj: evSort, getValues: getDistinctValues, onApply: applyFiltersAndSort, onClear: function () { delete evFilters[ colField ]; applyFiltersAndSort(); }, onSort: applyFiltersAndSort, closeAll: closeAllPanels, scrollWrapId: 'd5dsh-everything-tabulator' } );
			} );
		} );
	}

	function calcHeight( el ) {
		var rect = el.getBoundingClientRect(); var top = rect.top;
		if ( top <= 0 ) { var node = el, t = 0; while ( node ) { t += node.offsetTop || 0; node = node.offsetParent; } top = t - window.scrollY; }
		return Math.max( 228, window.innerHeight - top - 24 );
	}

	var LS_KEY = 'd5dsh_tabcol_everything';

	function saveColWidths() {
		if ( ! evTable ) { return; }
		var w = {};
		evTable.getColumns().forEach( function ( c ) { w[ c.getField() || c.getDefinition().title ] = c.getWidth(); } );
		try { localStorage.setItem( LS_KEY, JSON.stringify( w ) ); } catch(e) {}
	}

	function restoreColWidths() {
		if ( ! evTable ) { return; }
		var saved;
		try { saved = JSON.parse( localStorage.getItem( LS_KEY ) ); } catch(e) {}
		if ( ! saved ) { return; }
		evTable.getColumns().forEach( function ( c ) {
			var key = c.getField() || c.getDefinition().title;
			if ( saved[ key ] ) { c.setWidth( saved[ key ] ); }
		} );
	}

	function initTable() {
		var el = document.getElementById( 'd5dsh-everything-tabulator' );
		if ( ! el || typeof Tabulator === 'undefined' ) { return; }
		evTable = new Tabulator( '#d5dsh-everything-tabulator', {
			height: 500, layout: 'fitDataFill', movableColumns: true, resizableColumns: true,
			placeholder: 'No items match the current filters.', columns: COLUMNS,
			columnResized: function () { saveColWidths(); },
		} );
		window.addEventListener( 'resize', function () { if ( evTable ) { evTable.setHeight( calcHeight( el ) ); } } );
	}

	window.d5dshEverythingTable = {
		recalcHeight: function () { var el = document.getElementById( 'd5dsh-everything-tabulator' ); if ( evTable && el ) { evTable.setHeight( calcHeight( el ) ); } },
		render: function ( rows ) {
			var el = document.getElementById( 'd5dsh-everything-tabulator' ); if ( ! evTable || ! el ) { return; }
			var data = rows.map( function ( row, idx ) {
				var isColor = ( row._source === 'var' && row._item && ( row._item.type === 'colors' || row._item.type === 'global_color' ) ) || row._source === 'global_color';
				return Object.assign( {}, row, {
					_row_num:      idx + 1,
					_note_key:     ( row._source === 'group_preset' || row._source === 'element_preset' ) ? 'preset:' + ( row.dso_id || '' ) : 'var:' + ( row.dso_id || '' ),
					_isColor:      isColor,
					_categoryHtml: row._categoryHtml || '',
				} );
			} );
			evTable.setData( data );
			setTimeout( function () { restoreColWidths(); evTable.setHeight( calcHeight( el ) ); wireFilterHeaders(); }, 50 );
		},
		reset: function () {
			if ( ! evTable ) { return; }
			evFilters = {}; evSort = { key: null, dir: 'asc' };
			evTable.clearFilter( true ); evTable.clearSort(); closeAllPanels();
		},
		refreshNotes: function () { if ( evTable ) { evTable.getRows().forEach( function ( r ) { r.reformat(); } ); } },
		getInstance: function () { return evTable; },
	};

	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', initTable ); } else { initTable(); }

}() );
