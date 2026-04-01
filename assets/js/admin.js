/**
 * D5 Design System Utilities — Admin JavaScript
 *
 * Implements tri-state hierarchical checkbox behavior for the Export tab.
 *
 * ## Tri-state cycle for PARENT checkboxes
 *   unchecked       → click → checked (all children checked)
 *   checked         → click → unchecked (all children unchecked)
 *   indeterminate   → click → checked (all children checked)
 *
 * ## DOM structure expected
 *   <input type="checkbox" class="d5dsh-cb" id="cb-X"
 *          data-children="cb-A cb-B cb-C"   ← space-separated IDs of direct children
 *          data-parent="cb-Y">              ← ID of parent (omit for root)
 *
 * ## Status filter dropdowns
 *   When #cb-layouts or #cb-pages is checked, show the corresponding
 *   #filter-layouts / #filter-pages div.
 *
 * ## Selection hint
 *   Updates #d5dsh-selection-hint with a count of top-level types selected.
 */

( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		// Clear stale column-width localStorage if the plugin version changed.
		var _colKeys = [ 'd5dsh_col_widths_vars', 'd5dsh_col_widths_gp', 'd5dsh_col_widths_ep',
		                 'd5dsh_col_widths_all', 'd5dsh_col_widths_everything', 'd5dsh_col_widths_cat',
		                 'd5dsh_col_widths_cat_list' ];
		var _vKey = 'd5dsh_col_widths_version';
		try {
			var _savedVer = localStorage.getItem( _vKey );
			var _curVer   = ( typeof d5dtManage !== 'undefined' && d5dtManage.version ) ? d5dtManage.version : '';
			if ( _curVer && _savedVer !== _curVer ) {
				_colKeys.forEach( function ( k ) { localStorage.removeItem( k ); } );
				localStorage.setItem( _vKey, _curVer );
			}
		} catch(e) {}
		initModals();        // Must run first — wires help/settings/contact on every tab.
		initSettingsSave();  // Save button in settings modal.
		initDebugLogViewer(); // Debug log viewer (only active in debug mode).
		// Mark body with debug-active class so help panel shifts below debug banner.
		if ( document.querySelector( '.d5dsh-debug-banner' ) ) {
			document.body.classList.add( 'd5dsh-debug-active' );
		}
		// Apply beta-preview visibility on page load.
		applyBetaState( d5dtSettings && d5dtSettings.betaPreview );
		initTree();
		initStatusFilters();
		initSelectionHint();
		initExportFormValidation();
		initExportStatePersistence();
		initAdditionalInfo();
		initPrintTypeSettings();
		initManageTab();
		// If the Manage tab is not active, clear any stale pending-changes flag from
		// sessionStorage — the in-memory dirty state does not survive page navigation.
		if ( ! document.getElementById( 'd5dsh-manage-panel' ) ) {
			try { sessionStorage.removeItem( 'd5dsh_pending_changes' ); } catch(e) {}
		}
		initValidate();
		initSimpleImport();
		initAudit();
		initContentScan();
		initNotes();
		initExportFormat();
		initCategories();
		initMergeMode();
		initStyleGuide();
		initSecurityTest();
		initJsErrorCapture();
	} );

	// ── JS error capture ─────────────────────────────────────────────────────

	/**
	 * Capture uncaught JS errors and unhandled promise rejections and POST them
	 * to the server debug log when debug mode is active.
	 *
	 * Only fires when d5dtSettings.debugMode is true so there is zero overhead
	 * in production.
	 */
	function initJsErrorCapture() {
		if ( ! ( typeof d5dtSettings !== 'undefined' && d5dtSettings.debugMode ) ) {
			return;
		}

		function sendJsError( payload ) {
			try {
				var fd = new FormData();
				fd.append( 'action', 'd5dsh_log_js_error' );
				fd.append( 'nonce',  d5dtSettings.nonce );
				fd.append( 'body',   JSON.stringify( payload ) );
				// Use sendBeacon when available so errors during page unload are captured.
				if ( navigator.sendBeacon ) {
					var blob = new Blob(
						[ 'action=d5dsh_log_js_error&nonce=' + encodeURIComponent( d5dtSettings.nonce ) +
						  '&body=' + encodeURIComponent( JSON.stringify( payload ) ) ],
						{ type: 'application/x-www-form-urlencoded' }
					);
					navigator.sendBeacon( d5dtSettings.ajaxUrl, blob );
				} else {
					var xhr = new XMLHttpRequest();
					xhr.open( 'POST', d5dtSettings.ajaxUrl, true );
					xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
					xhr.send( 'action=d5dsh_log_js_error&nonce=' + encodeURIComponent( d5dtSettings.nonce ) +
					           '&body=' + encodeURIComponent( JSON.stringify( payload ) ) );
				}
			} catch (e) {
				// Never throw from the error handler.
			}
		}

		window.addEventListener( 'error', function ( event ) {
			sendJsError( {
				type:    'error',
				message: event.message  || 'Unknown error',
				source:  event.filename || '',
				lineno:  event.lineno   || 0,
				colno:   event.colno    || 0,
				stack:   ( event.error && event.error.stack ) ? event.error.stack : '',
			} );
		} );

		window.addEventListener( 'unhandledrejection', function ( event ) {
			var reason = event.reason;
			var message = ( reason instanceof Error ) ? reason.message : String( reason );
			var stack   = ( reason instanceof Error && reason.stack ) ? reason.stack : '';
			sendJsError( {
				type:    'unhandledrejection',
				message: message,
				source:  '',
				lineno:  0,
				colno:   0,
				stack:   stack,
			} );
		} );
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 4 — EXPORT TAB                                               ║
	// ╚══════════════════════════════════════════════════════════════════╝
	// ── Tri-state tree ────────────────────────────────────────────────────────

	function initTree() {
		var checkboxes = document.querySelectorAll( '.d5dsh-cb' );
		checkboxes.forEach( function ( cb ) {
			cb.addEventListener( 'click', function () {
				handleCheckboxClick( cb );
			} );
		} );
		// Sync parent tri-state to match any pre-checked leaf nodes (e.g. restored via
		// initExportStatePersistence). Run bubbleUp on each leaf (no data-children).
		checkboxes.forEach( function ( cb ) {
			if ( ! cb.dataset.children ) {
				bubbleUp( cb );
			}
		} );
		updateSelectionHint();
	}

	/**
	 * Handle a click on a checkbox.
	 * The browser has already toggled the checked state before this fires.
	 */
	function handleCheckboxClick( cb ) {
		var childIds = ( cb.dataset.children || '' ).trim().split( /\s+/ ).filter( Boolean );

		if ( childIds.length > 0 ) {
			// Parent: propagate down to all children.
			childIds.forEach( function ( childId ) {
				var child = document.getElementById( childId );
				if ( child ) {
					child.checked       = cb.checked;
					child.indeterminate = false;
					// Recurse: propagate to grandchildren.
					var grandchildIds = ( child.dataset.children || '' ).trim().split( /\s+/ ).filter( Boolean );
					if ( grandchildIds.length ) {
						grandchildIds.forEach( function ( gcId ) {
							var gc = document.getElementById( gcId );
							if ( gc ) {
								gc.checked       = cb.checked;
								gc.indeterminate = false;
							}
						} );
					}
				}
			} );
		}

		// Bubble up: update all ancestors.
		bubbleUp( cb );

		// Update status filter visibility and selection hint.
		updateStatusFilters();
		updateSelectionHint();
	}

	/**
	 * Walk up the tree updating each ancestor's tri-state.
	 */
	function bubbleUp( cb ) {
		var parentId = cb.dataset.parent;
		if ( ! parentId ) { return; }
		var parent = document.getElementById( parentId );
		if ( ! parent ) { return; }

		var childIds           = ( parent.dataset.children || '' ).trim().split( /\s+/ ).filter( Boolean );
		var checkedCount       = 0;
		var indeterminateCount = 0;

		childIds.forEach( function ( cid ) {
			var c = document.getElementById( cid );
			if ( ! c ) { return; }
			if ( c.indeterminate )  { indeterminateCount++; }
			else if ( c.checked )   { checkedCount++; }
		} );

		var total = childIds.length;

		if ( indeterminateCount > 0 || ( checkedCount > 0 && checkedCount < total ) ) {
			parent.indeterminate = true;
			parent.checked       = false;
		} else if ( checkedCount === total ) {
			parent.indeterminate = false;
			parent.checked       = true;
		} else {
			parent.indeterminate = false;
			parent.checked       = false;
		}

		// Continue up.
		bubbleUp( parent );
	}

	// ── Status filter dropdowns ───────────────────────────────────────────────

	function initStatusFilters() {
		updateStatusFilters();
	}

	function updateStatusFilters() {
		[ 'layouts', 'pages' ].forEach( function ( type ) {
			var cb     = document.getElementById( 'cb-' + type );
			var filter = document.getElementById( 'filter-' + type );
			if ( cb && filter ) {
				filter.style.display = ( cb.checked && ! cb.indeterminate ) ? 'block' : 'none';
			}
		} );
	}

	// ── Selection hint ────────────────────────────────────────────────────────

	function initSelectionHint() {
		updateSelectionHint();
	}

	function updateSelectionHint() {
		var hint      = document.getElementById( 'd5dsh-selection-hint' );
		var exportBtn = document.querySelector( '.d5dsh-btn-export' );
		if ( ! hint ) { return; }

		// Count leaf checkboxes with name="d5dsh_types[]" that are checked.
		var typeCheckboxes = document.querySelectorAll( 'input[name="d5dsh_types[]"]' );
		var checkedCount   = 0;
		typeCheckboxes.forEach( function ( cb ) {
			if ( cb.checked && ! cb.indeterminate ) { checkedCount++; }
		} );

		if ( checkedCount === 0 ) {
			hint.textContent = 'No types selected';
			hint.className   = 'd5dsh-selection-hint d5dsh-hint-none';
		} else if ( checkedCount === 1 ) {
			hint.textContent = '1 type selected \u2014 will download as .xlsx';
			hint.className   = 'd5dsh-selection-hint d5dsh-hint-ok';
		} else {
			hint.textContent = checkedCount + ' types selected \u2014 will download as .zip';
			hint.className   = 'd5dsh-selection-hint d5dsh-hint-ok';
		}

		// Gray out the Export Selected button when nothing is chosen or there are pending changes.
		// On the export tab, manageData is null, so check sessionStorage for pending flag.
		var pendingWarning = document.getElementById( 'd5dsh-export-pending-warning' );
		var blocked = hasPendingChanges() > 0;
		if ( ! blocked ) {
			try { blocked = sessionStorage.getItem( 'd5dsh_pending_changes' ) === '1'; } catch(e) {}
		}
		if ( exportBtn ) {
			exportBtn.disabled = ( checkedCount === 0 ) || blocked;
		}
		if ( pendingWarning ) {
			pendingWarning.style.display = blocked ? '' : 'none';
		}
	}

	// ── Export form validation ────────────────────────────────────────────────

	function initExportFormValidation() {
		var form = document.getElementById( 'd5dsh-export-form' );
		if ( ! form ) { return; }
		form.addEventListener( 'submit', function ( e ) {
			// Block export if there are unsaved variable changes (check sessionStorage for cross-tab flag).
			var pendingFlag = false;
			try { pendingFlag = sessionStorage.getItem( 'd5dsh_pending_changes' ) === '1'; } catch(ex) {}
			if ( hasPendingChanges() > 0 || pendingFlag ) {
				e.preventDefault();
				showToast( 'error', 'Pending changes',
					'You have unsaved variable changes on the Manage tab. Save or discard them before exporting.' );
				return;
			}

			// DTCG export does not require any type selection.
			var fmtChecked = form.querySelector( 'input[name="d5dsh_format"]:checked' );
			if ( fmtChecked && fmtChecked.value === 'dtcg' ) { return; }

			var typeCheckboxes = form.querySelectorAll( 'input[name="d5dsh_types[]"]:checked' );
			if ( typeCheckboxes.length === 0 ) {
				e.preventDefault();
				showToast( 'error', 'Nothing selected', 'Please select at least one type to export.' );
			}
		} );
	}

	// ── DTCG format: show/hide advisory note ──────────────────────────────────

	function initExportFormat() {
		var form = document.getElementById( 'd5dsh-export-form' );
		if ( ! form ) { return; }

		function updateDtcgNote() {
			var fmt  = form.querySelector( 'input[name="d5dsh_format"]:checked' );
			var note = document.getElementById( 'd5dsh-dtcg-note' );
			if ( note ) {
				note.style.display = ( fmt && fmt.value === 'dtcg' ) ? 'block' : 'none';
			}
		}

		form.querySelectorAll( 'input[name="d5dsh_format"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', updateDtcgNote );
		} );

		updateDtcgNote(); // Run once on load in case dtcg is restored from sessionStorage.
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 2 — STATE VARIABLES & CONSTANTS                              ║
	// ╚══════════════════════════════════════════════════════════════════╝
	// ── Export tab: persist selections across tab switches (sessionStorage) ──

	var EXPORT_STATE_KEY = 'd5dsh_export_state';

	function saveExportState() {
		var form = document.getElementById( 'd5dsh-export-form' );
		if ( ! form ) { return; }
		var state = { types: [], format: '', layout_status: '', page_status: '' };
		form.querySelectorAll( 'input[name="d5dsh_types[]"]:checked' ).forEach( function ( cb ) {
			state.types.push( cb.id );
		} );
		var fmt = form.querySelector( 'input[name="d5dsh_format"]:checked' );
		if ( fmt ) { state.format = fmt.value; }
		var ls = form.querySelector( 'select[name="d5dsh_layout_status"]' );
		if ( ls ) { state.layout_status = ls.value; }
		var ps = form.querySelector( 'select[name="d5dsh_page_status"]' );
		if ( ps ) { state.page_status = ps.value; }
		try { sessionStorage.setItem( EXPORT_STATE_KEY, JSON.stringify( state ) ); } catch(e) {}
	}

	function restoreExportState() {
		var form = document.getElementById( 'd5dsh-export-form' );
		if ( ! form ) { return; }
		var saved;
		try { saved = JSON.parse( sessionStorage.getItem( EXPORT_STATE_KEY ) || 'null' ); } catch(e) {}
		if ( ! saved ) { return; }
		// Restore checkboxes.
		if ( Array.isArray( saved.types ) ) {
			saved.types.forEach( function ( cbId ) {
				var cb = document.getElementById( cbId );
				if ( cb ) {
					cb.checked = true;
					cb.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		}
		// Restore format radio.
		if ( saved.format && /^[a-z]+$/.test( saved.format ) ) {
			var fmtRadio = form.querySelector( 'input[name="d5dsh_format"][value="' + saved.format + '"]' );
			if ( fmtRadio ) { fmtRadio.checked = true; }
		}
		// Restore status dropdowns.
		if ( saved.layout_status ) {
			var ls = form.querySelector( 'select[name="d5dsh_layout_status"]' );
			if ( ls ) { ls.value = saved.layout_status; }
		}
		if ( saved.page_status ) {
			var ps = form.querySelector( 'select[name="d5dsh_page_status"]' );
			if ( ps ) { ps.value = saved.page_status; }
		}
	}

	function initExportStatePersistence() {
		var form = document.getElementById( 'd5dsh-export-form' );
		if ( ! form ) { return; }
		restoreExportState();
		// After restoring leaf checkboxes, re-sync parent tri-states and update hint.
		syncTreeAfterRestore();
		// Save on any change to checkboxes, radios, or dropdowns.
		form.addEventListener( 'change', saveExportState );
	}

	/**
	 * Re-sync all parent tri-states and the selection hint after checkboxes
	 * are programmatically set (e.g. restoreExportState). Mirrors the tail
	 * of initTree() but can be called any time.
	 */
	function syncTreeAfterRestore() {
		var checkboxes = document.querySelectorAll( '.d5dsh-cb' );
		checkboxes.forEach( function ( cb ) {
			if ( ! cb.dataset.children ) {
				bubbleUp( cb );
			}
		} );
		updateSelectionHint();
	}

	// ── Manage tab: persist filter/sort/mode state across tab switches ────────

	var MANAGE_STATE_KEY = 'd5dsh_manage_state';

	function saveManageState() {
		// colFilters contains Set objects which don't serialise via JSON.stringify.
		// Convert each checklist filter's vals Set to an Array for storage.
		var filtersSerial = {};
		Object.keys( colFilters ).forEach( function ( col ) {
			var f = colFilters[ col ];
			if ( ! f ) { return; }
			if ( f.mode === 'checklist' ) {
				filtersSerial[ col ] = { mode: 'checklist', vals: f.vals ? Array.from( f.vals ) : [] };
			} else {
				filtersSerial[ col ] = { mode: f.mode, val: f.val || '' };
			}
		} );
		var state = {
			mode:        currentManageMode,
			hideSystem:  hideSystem,
			colSort:     { key: colSort.key, dir: colSort.dir },
			colFilters:  filtersSerial,
		};
		try { sessionStorage.setItem( MANAGE_STATE_KEY, JSON.stringify( state ) ); } catch(e) {}
	}

	function restoreManageState() {
		var saved;
		try { saved = JSON.parse( sessionStorage.getItem( MANAGE_STATE_KEY ) || 'null' ); } catch(e) {}
		if ( ! saved ) { return; }

		// Restore mode.
		if ( saved.mode ) {
			setManageMode( saved.mode );
		}

		// Restore hide-system toggle.
		if ( saved.hideSystem ) {
			hideSystem = true;
			var hsBtn = document.getElementById( 'd5dsh-hide-system' );
			if ( hsBtn ) { hsBtn.textContent = 'Show system items'; }
		}

		// Restore sort.
		if ( saved.colSort && saved.colSort.key ) {
			colSort = { key: saved.colSort.key, dir: saved.colSort.dir || 'asc' };
		}

		// Restore column filters — convert Arrays back to Sets.
		if ( saved.colFilters ) {
			Object.keys( saved.colFilters ).forEach( function ( col ) {
				var f = saved.colFilters[ col ];
				if ( ! f ) { return; }
				if ( f.mode === 'checklist' ) {
					colFilters[ col ] = { mode: 'checklist', vals: new Set( f.vals || [] ) };
				} else {
					colFilters[ col ] = { mode: f.mode, val: f.val || '' };
				}
			} );
		}
	}


	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 5 — MANAGE TAB: CORE                                         ║
	// ╚══════════════════════════════════════════════════════════════════╝
	// ── Manage tab ────────────────────────────────────────────────────────────
	//
	// State:
	//   manageData.vars          = [ {id, label, value, type, status, order}, ... ]
	//   manageData.global_colors = [ {id, label, value, status}, ... ]
	//   manageOriginal           = deep-clone of manageData at load time (for dirty tracking)
	//   pendingBulk              = null | { op, scope, value?, find?, replace?, case? }
	//
	// Data flow:
	//   1. Tab shown → ajax_load() → populate table
	//   2. User edits label/value/status → mark row dirty
	//   3. User clicks bulk Preview → apply bulk op in-memory, re-render
	//   4. User clicks Save → send { vars, global_colors, bulk? } → ajax_save() → re-render clean
	//   5. User clicks Discard → restore manageOriginal → re-render clean

	var manageData     = null;   // live data object { vars: [], global_colors: [] }
	var manageOriginal = null;   // deep-clone of data at last save/load
	var pendingBulk       = null;
	var bulkPreviewActive = false;  // true while preview transform has been applied in-memory

	// ── Change-source tracking ───────────────────────────────────────────────
	// Tracks the origin of the current set of unsaved changes so the save bar
	// can display contextual information (e.g. "3 unsaved changes from inline editing").
	// { source: 'inline'|'bulk'|'merge'|'import', detail: string, timestamp: Date }
	var changeSource = null;

	function setChangeSource( source, detail ) {
		if ( ! changeSource ) {
			changeSource = { source: source, detail: detail || '', timestamp: new Date() };
		}
	}

	function clearChangeSource() {
		changeSource = null;
		try { sessionStorage.removeItem( 'd5dsh_pending_changes' ); } catch(e) {}
	}

	function formatChangeSource() {
		if ( ! changeSource ) { return ''; }
		var ts = changeSource.timestamp;
		var timeStr = ts.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
		var dateStr = ts.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
		var label   = '';
		switch ( changeSource.source ) {
			case 'inline': label = 'inline editing'; break;
			case 'bulk':   label = 'bulk label change'; break;
			case 'merge':  label = 'merge variables'; break;
			case 'import': label = 'importing ' + ( changeSource.detail || 'file' ); break;
			default:       label = changeSource.source;
		}
		return ' from ' + label + ' on ' + dateStr + ' at ' + timeStr;
	}

	/**
	 * Check whether there are unsaved variable changes (inline edits or bulk ops).
	 * Returns the dirty count (0 = clean).
	 */
	function hasPendingChanges() {
		return countDirtyItems() + ( pendingBulk ? 1 : 0 );
	}

	/**
	 * Show a gate dialog requiring the user to save or discard before proceeding.
	 * Returns true if blocked, false if no pending changes.
	 */
	function requireCleanState( actionLabel ) {
		var pending = hasPendingChanges();
		if ( pending === 0 ) { return false; }
		var desc = pending + ' unsaved change' + ( pending !== 1 ? 's' : '' ) + formatChangeSource();
		showToast( 'error', 'Pending changes',
			'You have ' + desc + '. Please save or discard your changes before ' + actionLabel + '.' );
		return true;
	}

	function updateBulkButtonStates() {
		var previewBtn = document.getElementById( 'd5dsh-bulk-preview' );
		var applyBtn   = document.getElementById( 'd5dsh-bulk-apply' );
		var undoBtn    = document.getElementById( 'd5dsh-bulk-undo' );
		if ( previewBtn ) {
			// Toggle visual active state.
			previewBtn.classList.toggle( 'd5dsh-bulk-preview-active', bulkPreviewActive );
		}
		if ( applyBtn ) {
			applyBtn.disabled = ! bulkPreviewActive;
		}
		if ( undoBtn ) {
			undoBtn.disabled = ! bulkPreviewActive;
		}
	}   // bulk op to send with next Save
	var manageSection  = 'vars'; // which section is displayed: 'vars' | 'global_colors'
	var showBanding      = false;  // alternating row shading toggle (default off)
	var hideSystem       = false;  // hide system/global-color rows toggle
	var dupeFilterActive = false;  // show only rows with duplicate labels
	var currentDupeLabels = {};    // populated each render, used by dupe filter

	// ── Additional Information collapsible ────────────────────────────────────

	/**
	 * Auto-open the Additional Information <details> section if any field
	 * already has a value (e.g. after a page reload with POST data preserved).
	 */
	var ADDL_INFO_KEY = 'd5dsh_additional_info';

	function initAdditionalInfo() {
		var details = document.getElementById( 'd5dsh-additional-info' );
		if ( ! details ) { return; }

		var inputs = details.querySelectorAll( 'input[type="text"], select, textarea' );

		// Restore saved values from localStorage.
		var saved = {};
		try { saved = JSON.parse( localStorage.getItem( ADDL_INFO_KEY ) || '{}' ); } catch(e) {}

		var hasValue = false;
		inputs.forEach( function ( el ) {
			var key = el.id || el.name;
			if ( key && saved[ key ] !== undefined ) {
				el.value = saved[ key ];
			}
			var val = el.tagName === 'SELECT' ? el.value : el.value.trim();
			if ( val && val !== '' ) { hasValue = true; }
		} );

		if ( hasValue ) { details.setAttribute( 'open', '' ); }

		// Persist changes to localStorage on input.
		inputs.forEach( function ( el ) {
			el.addEventListener( 'change', saveAdditionalInfo );
			el.addEventListener( 'input',  saveAdditionalInfo );
		} );

		// Wire up Save and Clear buttons.
		var saveBtn  = document.getElementById( 'd5dsh-addlinfo-save' );
		var clearBtn = document.getElementById( 'd5dsh-addlinfo-clear' );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', function () {
				saveAdditionalInfo();
				saveBtn.textContent = 'Saved ✓';
				setTimeout( function () { saveBtn.textContent = 'Save'; }, 2000 );
			} );
		}
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				inputs.forEach( function ( el ) { el.value = ''; } );
				localStorage.removeItem( ADDL_INFO_KEY );
			} );
		}
	}

	function saveAdditionalInfo() {
		var details = document.getElementById( 'd5dsh-additional-info' );
		if ( ! details ) { return; }
		var inputs = details.querySelectorAll( 'input[type="text"], select, textarea' );
		var data = {};
		inputs.forEach( function ( el ) {
			var key = el.id || el.name;
			if ( key ) { data[ key ] = el.value; }
		} );
		try { localStorage.setItem( ADDL_INFO_KEY, JSON.stringify( data ) ); } catch(e) {}
	}

	// ── Print type filter settings ────────────────────────────────────────────

	var PRINT_TYPES_KEY = 'd5dsh_print_types';

	/**
	 * Restore saved print-type filter selections from localStorage and wire
	 * change listeners so selections persist automatically.
	 */
	function initPrintTypeSettings() {
		var saved;
		try { saved = JSON.parse( localStorage.getItem( PRINT_TYPES_KEY ) || 'null' ); } catch(e) {}

		document.querySelectorAll( '.d5dsh-print-type-chk' ).forEach( function ( chk ) {
			// If we have saved state, apply it; otherwise leave the HTML default (all checked).
			if ( saved && typeof saved[ chk.value ] !== 'undefined' ) {
				chk.checked = !! saved[ chk.value ];
			}
			chk.addEventListener( 'change', savePrintTypeSettings );
		} );
	}

	function savePrintTypeSettings() {
		var data = {};
		document.querySelectorAll( '.d5dsh-print-type-chk' ).forEach( function ( chk ) {
			data[ chk.value ] = chk.checked;
		} );
		try { localStorage.setItem( PRINT_TYPES_KEY, JSON.stringify( data ) ); } catch(e) {}
	}

	/**
	 * Return an array of Variable type strings that are currently enabled
	 * for printing (as per the Print settings pane).
	 * Returns null when all types are enabled (no filtering needed).
	 */
	function getPrintEnabledTypes() {
		var all = [ 'colors', 'numbers', 'fonts', 'images', 'strings', 'links' ];
		var enabled = [];
		document.querySelectorAll( '.d5dsh-print-type-chk' ).forEach( function ( chk ) {
			if ( chk.checked ) { enabled.push( chk.value ); }
		} );
		// If every type is enabled, return null so executePrint skips the filter.
		return ( enabled.length === all.length ) ? null : enabled;
	}

	// ── Manage tab ────────────────────────────────────────────────────────────

	/**
	 * Resize the manage table wrap so its bottom edge aligns with the viewport bottom.
	 * Called on init, resize, and after data loads.
	 */
	function resizeTableWrap() {
		var wrap = document.getElementById( 'd5dsh-manage-table-wrap' );
		if ( ! wrap ) { return; }
		var rect    = wrap.getBoundingClientRect();
		var padding = 16; // small gap at bottom of viewport
		var height  = window.innerHeight - rect.top - padding;
		// Minimum: ~6 data rows visible (~32px each) + header (~36px) ≈ 228px.
		if ( height < 228 ) { height = 228; }
		wrap.style.height = height + 'px';
	}

	// Native CSS position:sticky is used for table headers.
	// .d5dsh-table-scroll-wrap has overflow-x:auto + overflow-y:auto and a
	// fixed JS-set height, so it is the scroll container on both axes.
	// thead th with position:sticky top:0 sticks within this container.
	// All ancestors of .d5dsh-table-scroll-wrap have overflow:visible (default),
	// so they do not block position:sticky. No JS needed.



	function initManageTab() {
		var panel = document.getElementById( 'd5dsh-manage-panel' );
		if ( ! panel ) { return; } // Not on the Manage tab.

		// ── Mode switcher (View / Manage / Scan) ─────────────────────────────
		var modeButtons = document.querySelectorAll( '.d5dsh-mode-btn' );
		modeButtons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				if ( btn.disabled ) { return; }
				var mode = btn.dataset.mode;
				// Gate: if switching to a new editing mode while there are unsaved
				// changes from a different source, require save/discard first.
				if ( mode !== 'view' && mode !== currentManageMode && hasPendingChanges() > 0 ) {
					if ( requireCleanState( 'switching to ' + mode + ' mode' ) ) { return; }
				}
				setManageMode( mode );
			} );
		} );

		// ── Restore persisted state before wiring controls ───────────────────
		restoreManageState();

		// ── Bulk op controls ─────────────────────────────────────────────────
		var bulkOpSel = document.getElementById( 'd5dsh-bulk-op' );
		if ( bulkOpSel ) {
			bulkOpSel.addEventListener( 'change', function () { updateBulkFields(); } );
		}
		// Bulk action buttons: Preview is always enabled; Apply/Undo enabled after preview.
		var bulkPreview = document.getElementById( 'd5dsh-bulk-preview' );
		var bulkApply   = document.getElementById( 'd5dsh-bulk-apply' );
		var bulkUndo    = document.getElementById( 'd5dsh-bulk-undo' );
		if ( bulkPreview ) { bulkPreview.addEventListener( 'click', applyBulkPreview ); }
		if ( bulkApply )   { bulkApply.addEventListener( 'click', applyBulkToVisible ); }
		if ( bulkUndo )    { bulkUndo.addEventListener( 'click', undoBulkPreview ); }
		updateBulkButtonStates();

		// ── Save / Discard ────────────────────────────────────────────────────
		var saveBtn    = document.getElementById( 'd5dsh-manage-save' );
		var discardBtn = document.getElementById( 'd5dsh-manage-discard' );
		if ( saveBtn )    { saveBtn.addEventListener(    'click', handleSave    ); }
		if ( discardBtn ) { discardBtn.addEventListener( 'click', handleDiscard ); }

		// ── Clear all filters (hidden until a filter is active) ───────────────
		var clearAllBtn = document.getElementById( 'd5dsh-clear-all-filters' );
		if ( clearAllBtn ) {
			clearAllBtn.addEventListener( 'click', function () {
				colFilters = {};
				colSort    = { key: null, dir: 'asc' };
				updateFilterIndicators();
				updateClearAllVisibility();
				renderTable();
			} );
		}

		// ── Reset all (filters + sort + type + hide-system) ──────────────────
		var resetBtn = document.getElementById( 'd5dsh-reset-view' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				colFilters       = {};
				colSort          = { key: null, dir: 'asc' };
				hideSystem       = false;
				dupeFilterActive = false;

				var hideSystemBtn2 = document.getElementById( 'd5dsh-hide-system' );
				if ( hideSystemBtn2 ) { hideSystemBtn2.textContent = 'Hide system items'; }
				var dupeEl = document.getElementById( 'd5dsh-manage-dupe-count' );
				if ( dupeEl ) { dupeEl.classList.remove( 'd5dsh-dupe-filter-on' ); }

				updateFilterIndicators();
				updateClearAllVisibility();
				saveManageState();
				if ( window.d5dshVarsTable ) { window.d5dshVarsTable.reset(); }
				renderTable();
			} );
		}


		// ── Print / Excel / CSV ───────────────────────────────────────────────
		var printBtn = document.getElementById( 'd5dsh-manage-print' );
		if ( printBtn ) {
			printBtn.addEventListener( 'click', openPrintSetup );
		}
		var xlsxBtn = document.getElementById( 'd5dsh-manage-export-xlsx' );
		if ( xlsxBtn ) {
			xlsxBtn.addEventListener( 'click', function () {
				if ( typeof d5dtManage === 'undefined' ) { return; }
				xlsxBtn.disabled    = true;
				xlsxBtn.textContent = 'Generating…';
				var url = d5dtManage.ajaxUrl
					+ '?action=' + encodeURIComponent( d5dtManage.xlsxAction )
					+ '&nonce='  + encodeURIComponent( d5dtManage.nonce );
				fetch( url, { method: 'POST' } )
					.then( function ( r ) {
						if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
						return r.blob();
					} )
					.then( function ( blob ) {
						var a      = document.createElement( 'a' );
						a.href     = URL.createObjectURL( blob );
						a.download = 'divi5-vars.xlsx';
						a.click();
						URL.revokeObjectURL( a.href );
					} )
					.catch( function ( err ) {
						showToast( 'error', 'Excel download failed', err.message || 'Network error.' );
					} )
					.finally( function () {
						xlsxBtn.disabled    = false;
						xlsxBtn.textContent = '⬇ Excel';
					} );
			} );
		}
		var csvBtn = document.getElementById( 'd5dsh-manage-export-csv' );
		if ( csvBtn ) {
			csvBtn.addEventListener( 'click', function () { openExportCsvSetup( 'vars' ); } );
		}

		// ── Dupe filter badge click ───────────────────────────────────────────
		var dupeCountEl = document.getElementById( 'd5dsh-manage-dupe-count' );
		if ( dupeCountEl ) {
			dupeCountEl.style.cursor = 'pointer';
			dupeCountEl.title        = 'Click to show only duplicate labels';
			dupeCountEl.addEventListener( 'click', function () {
				dupeFilterActive = ! dupeFilterActive;
				dupeCountEl.classList.toggle( 'd5dsh-dupe-filter-on', dupeFilterActive );
				dupeCountEl.title = dupeFilterActive ? 'Click to clear duplicate filter' : 'Click to show only duplicate labels';
				renderTable();
			} );
		}

		// ── Settings: alternating row banding ────────────────────────────────
		var bandingChk = document.getElementById( 'd5dsh-setting-banding' );
		if ( bandingChk ) {
			bandingChk.addEventListener( 'change', function () {
				showBanding = bandingChk.checked;
				renderTable();
			} );
		}

		// ── Column filters ────────────────────────────────────────────────────
		initColumnFilters();

		// ── Resize table to fill viewport ─────────────────────────────────────
		window.addEventListener( 'resize', resizeTableWrap );
		window.addEventListener( 'resize', resizePresetsWraps );

		// ── Load data ─────────────────────────────────────────────────────
		loadManageData();

		// ── Section switcher (Variables | Group Presets | Element Presets | All Presets) ──
		initSectionSwitcher();

		// Variables table is now rendered by Tabulator (manage-vars-table.js).

		// ── Scroll jumper buttons (▲ / ▼ beside each table wrap) ─────────────
		initScrollJumpers();

		// ── Click-to-copy for truncated cells ─────────────────────────────────
		initClickToCopy();

		// ── Event delegation for Impact buttons in Manage table ────────────────
		var managePanel = document.getElementById( 'd5dsh-manage-panel' );
		if ( managePanel ) {
			managePanel.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.d5dsh-impact-btn' );
				if ( ! btn ) { return; }
				e.stopPropagation();
				openImpactModal( btn.dataset.dsoType || 'variable', btn.dataset.dsoId || '', btn.dataset.dsoLabel || '' );
			} );
		}
	}

	/**
	 * Switch the Manage tab between View, Bulk Label Change, and Scan modes.
	 * View:             bulk controls hidden; table is read-display.
	 * Bulk Label Change: bulk controls visible with type-of-change selector.
	 */
	var currentManageMode = 'view';
	function setManageMode( mode ) {
		currentManageMode = mode;
		var modeButtons = document.querySelectorAll( '.d5dsh-mode-btn' );
		modeButtons.forEach( function ( btn ) {
			btn.classList.toggle( 'd5dsh-mode-active', btn.dataset.mode === mode );
		} );
		var bulkControls = document.getElementById( 'd5dsh-bulk-controls' );
		if ( bulkControls ) {
			bulkControls.style.display = ( mode === 'manage' ) ? '' : 'none';
		}
		var mergePanel = document.getElementById( 'd5dsh-manage-mode-merge' );
		if ( mergePanel ) {
			mergePanel.style.display = ( mode === 'merge' ) ? '' : 'none';
		}
		// Reset inner bulk-op selector when changing mode.
		if ( mode !== 'manage' ) {
			var bulkOpInner = document.getElementById( 'd5dsh-bulk-op' );
			if ( bulkOpInner ) {
				bulkOpInner.value = '';
				updateBulkFields();
			}
			if ( bulkPreviewActive ) { undoBulkPreview(); }
		}
		saveManageState();
	}

	/**
	 * Show/hide the Clear All Filters button based on whether any filter/sort is active.
	 */
	function updateClearAllVisibility() {
		var btn = document.getElementById( 'd5dsh-clear-all-filters' );
		if ( ! btn ) { return; }
		var hasFilter = Object.keys( colFilters ).length > 0 || colSort.key !== null;
		btn.style.display = hasFilter ? '' : 'none';
	}

	// ── Toast notification system ──────────────────────────────────────────────

	/**
	 * Show a toast notification.
	 *
	 * @param {'success'|'error'|'info'} type
	 * @param {string} title   Short bold heading.
	 * @param {string} message Body text (may be multi-line).
	 * @param {number} [ttl]   Auto-dismiss after ms. 0 = never auto-dismiss.
	 *                         Defaults: success=4000, info=4000, error=0.
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 20 — SETTINGS & NOTIFICATIONS                                ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function showToast( type, title, message, ttl ) {
		var container = document.getElementById( 'd5dsh-toast-container' );
		if ( ! container ) {
			container = document.createElement( 'div' );
			container.id = 'd5dsh-toast-container';
			document.body.appendChild( container );
		}

		var icons = { success: '✓', error: '✕', info: 'ℹ' };
		var toast = document.createElement( 'div' );
		toast.className = 'd5dsh-toast d5dsh-toast-' + type;

		var iconEl = document.createElement( 'span' );
		iconEl.className   = 'd5dsh-toast-icon';
		iconEl.textContent = icons[ type ] || '';

		var body = document.createElement( 'div' );
		body.className = 'd5dsh-toast-body';

		if ( title ) {
			var titleEl = document.createElement( 'div' );
			titleEl.className   = 'd5dsh-toast-title';
			titleEl.textContent = title;
			body.appendChild( titleEl );
		}
		if ( message ) {
			var msgEl = document.createElement( 'div' );
			msgEl.className   = 'd5dsh-toast-msg';
			msgEl.textContent = message;
			body.appendChild( msgEl );
		}

		var closeBtn = document.createElement( 'button' );
		closeBtn.className   = 'd5dsh-toast-close';
		closeBtn.textContent = '×';
		closeBtn.setAttribute( 'aria-label', 'Dismiss' );

		toast.appendChild( iconEl );
		toast.appendChild( body );
		toast.appendChild( closeBtn );
		container.appendChild( toast );

		function dismiss() {
			toast.classList.add( 'd5dsh-toast-out' );
			setTimeout( function () {
				if ( toast.parentNode ) { toast.parentNode.removeChild( toast ); }
			}, 320 );
		}

		closeBtn.addEventListener( 'click', dismiss );

		var autoTtl = ( ttl !== undefined ) ? ttl : ( type === 'error' ? 0 : 4000 );
		if ( autoTtl > 0 ) {
			setTimeout( dismiss, autoTtl );
		}

		return toast;
	}

	// ── Settings save ──────────────────────────────────────────────────────────

	/**
	 * Show or hide all .d5dsh-beta-feature elements based on enabled flag.
	 *
	 * @param {boolean} enabled
	 */
	function applyBetaState( enabled ) {
		document.querySelectorAll( '.d5dsh-beta-feature' ).forEach( function ( el ) {
			if ( enabled ) {
				el.classList.remove( 'd5dsh-beta-hidden' );
			} else {
				el.classList.add( 'd5dsh-beta-hidden' );
			}
		} );
	}

	/**
	 * Reset all beta-only UI state when beta preview is turned off.
	 * Discards unsaved changes, resets manage mode to view, clears merge state.
	 */
	function resetBetaState() {
		// Reset manage mode to 'view' (exits bulk label change or merge mode).
		if ( typeof setManageMode === 'function' ) {
			setManageMode( 'view' );
		}
		// Discard any unsaved inline edits or bulk operations.
		if ( manageOriginal ) {
			manageData  = deepClone( manageOriginal );
			pendingBulk = null;
			clearChangeSource();
			if ( typeof renderTable === 'function' ) { renderTable(); }
		}
		// Clear merge prefill from sessionStorage.
		try { sessionStorage.removeItem( 'd5dsh_merge_prefill' ); } catch(e) {}
		// Clear the manage state from sessionStorage so it does not restore merge/bulk on reload.
		try { sessionStorage.removeItem( MANAGE_STATE_KEY ); } catch(e) {}
	}

	// ── Settings modal state snapshot (for close-without-save revert) ────────
	var _settingsSnapshot = null;
	var _settingsSaved    = false;

	function snapshotSettingsState() {
		var debugChk    = document.getElementById( 'd5dsh-setting-debug-mode' );
		var betaChk     = document.getElementById( 'd5dsh-setting-beta-preview' );
		var headerInput = document.getElementById( 'd5dsh-setting-report-header' );
		var footerInput = document.getElementById( 'd5dsh-setting-report-footer' );
		var siteAbbrInput = document.getElementById( 'd5dsh-setting-site-abbr' );
		_settingsSnapshot = {
			debug:    debugChk    ? debugChk.checked     : false,
			beta:     betaChk     ? betaChk.checked      : false,
			header:   headerInput ? headerInput.value     : '',
			footer:   footerInput ? footerInput.value     : '',
			siteAbbr: siteAbbrInput ? siteAbbrInput.value : '',
		};
		_settingsSaved = false;
	}

	function restoreSettingsState() {
		if ( ! _settingsSnapshot || _settingsSaved ) { return; }
		var debugChk    = document.getElementById( 'd5dsh-setting-debug-mode' );
		var betaChk     = document.getElementById( 'd5dsh-setting-beta-preview' );
		var headerInput = document.getElementById( 'd5dsh-setting-report-header' );
		var footerInput = document.getElementById( 'd5dsh-setting-report-footer' );
		var siteAbbrInput = document.getElementById( 'd5dsh-setting-site-abbr' );
		if ( debugChk )      { debugChk.checked   = _settingsSnapshot.debug; }
		if ( betaChk )       { betaChk.checked    = _settingsSnapshot.beta; }
		if ( headerInput )   { headerInput.value   = _settingsSnapshot.header; }
		if ( footerInput )   { footerInput.value   = _settingsSnapshot.footer; }
		if ( siteAbbrInput ) { siteAbbrInput.value = _settingsSnapshot.siteAbbr; }
		// Reset Save button state.
		var saveBtn  = document.getElementById( 'd5dsh-settings-save' );
		var statusEl = document.getElementById( 'd5dsh-settings-save-status' );
		if ( saveBtn )  { saveBtn.disabled = true; }
		if ( statusEl ) { statusEl.textContent = ''; statusEl.className = 'd5dsh-settings-save-status'; }
	}

	// ── Debug Log Viewer ─────────────────────────────────────────────────────

	function initDebugLogViewer() {
		var refreshBtn  = document.getElementById( 'd5dsh-debug-log-refresh' );
		var clearBtn    = document.getElementById( 'd5dsh-debug-log-clear' );
		var downloadBtn = document.getElementById( 'd5dsh-debug-log-download' );
		var outputEl    = document.getElementById( 'd5dsh-debug-log-output' );
		var metaEl      = document.getElementById( 'd5dsh-debug-log-meta' );
		if ( ! refreshBtn || ! outputEl ) { return; }

		function loadLog() {
			outputEl.textContent = 'Loading…';
			var url = d5dtSettings.ajaxUrl + '?action=d5dsh_debug_log_read&nonce=' +
			          encodeURIComponent( d5dtSettings.nonce ) + '&lines=200';
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( data ) {
					if ( data.success ) {
						outputEl.textContent = data.data.lines || '(log is empty)';
						outputEl.scrollTop   = outputEl.scrollHeight;
						if ( metaEl ) {
							metaEl.textContent = data.data.size_kb + ' KB — ' + data.data.path;
						}
					} else {
						outputEl.textContent = 'Error: ' + ( data.data && data.data.message ? data.data.message : 'unknown' );
					}
				} )
				.catch( function ( err ) { outputEl.textContent = 'Request failed: ' + err; } );
		}

		refreshBtn.addEventListener( 'click', loadLog );

		clearBtn.addEventListener( 'click', function () {
			if ( ! confirm( 'Clear the debug log? This cannot be undone.' ) ) { return; }
			var fd = new FormData();
			fd.append( 'action', 'd5dsh_debug_log_clear' );
			fd.append( 'nonce',  d5dtSettings.nonce );
			fetch( d5dtSettings.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function () { loadLog(); } )
				.catch( function () {} );
		} );

		downloadBtn.addEventListener( 'click', function () {
			var content = outputEl.textContent;
			var blob    = new Blob( [ content ], { type: 'text/plain' } );
			var a       = document.createElement( 'a' );
			a.href      = URL.createObjectURL( blob );
			a.download  = 'd5dsh-debug.log';
			a.click();
			URL.revokeObjectURL( a.href );
		} );

		// Auto-load when the advanced pane is shown.
		var advancedTab = document.querySelector( '.d5dsh-modal-tab[data-tab="advanced"]' );
		if ( advancedTab ) {
			advancedTab.addEventListener( 'click', function () {
				// Small delay to let the pane become visible first.
				setTimeout( loadLog, 100 );
			} );
		}
	}

	function initSettingsSave() {
		var saveBtn         = document.getElementById( 'd5dsh-settings-save' );
		var statusEl        = document.getElementById( 'd5dsh-settings-save-status' );
		var debugChk        = document.getElementById( 'd5dsh-setting-debug-mode' );
		var betaChk         = document.getElementById( 'd5dsh-setting-beta-preview' );
		var secTestChk      = document.getElementById( 'd5dsh-setting-security-testing' );
		var headerInput     = document.getElementById( 'd5dsh-setting-report-header' );
		var footerInput     = document.getElementById( 'd5dsh-setting-report-footer' );
		if ( ! saveBtn ) { return; }

		// Toggle the security test panel visibility immediately when the checkbox changes.
		if ( secTestChk ) {
			secTestChk.addEventListener( 'change', function () {
				var p = document.getElementById( 'd5dsh-sectest-panel' );
				if ( p ) { p.style.display = secTestChk.checked ? '' : 'none'; }
			} );
		}

		// Enable Save button as soon as any setting changes.
		function markDirty() {
			saveBtn.disabled = false;
			if ( statusEl ) { statusEl.textContent = ''; statusEl.className = 'd5dsh-settings-save-status'; }
		}
		[ debugChk, betaChk, secTestChk ].forEach( function ( el ) {
			if ( el ) { el.addEventListener( 'change', markDirty ); }
		} );
		[ headerInput, footerInput ].forEach( function ( el ) {
			if ( el ) { el.addEventListener( 'input', markDirty ); }
		} );

		saveBtn.addEventListener( 'click', function () {
			saveBtn.disabled    = true;
			if ( statusEl ) { statusEl.textContent = ''; statusEl.className = 'd5dsh-settings-save-status'; }

			var saveUrl = d5dtSettings.ajaxUrl
				+ '?action=d5dsh_save_settings'
				+ '&nonce=' + encodeURIComponent( d5dtSettings.nonce );

			var siteAbbrInput = document.getElementById( 'd5dsh-setting-site-abbr' );
			var payload = {
				debug_mode:       debugChk    ? debugChk.checked    : false,
				beta_preview:     betaChk     ? betaChk.checked     : false,
				security_testing: secTestChk  ? secTestChk.checked  : false,
				report_header:    headerInput ? headerInput.value.trim() : '',
				report_footer:    footerInput ? footerInput.value.trim() : '',
				site_abbr:        siteAbbrInput ? siteAbbrInput.value.trim() : '',
			};

			fetch( saveUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( payload ),
			} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success ) {
					_settingsSaved = true;
					saveBtn.disabled = true; // Re-disable after successful save.
					if ( statusEl ) {
						statusEl.textContent  = '✓ Saved';
						statusEl.className    = 'd5dsh-settings-save-status is-success';
					}
					// Update in-memory settings so print/reports use new values immediately.
					if ( headerInput ) { d5dtSettings.reportHeader = headerInput.value.trim(); }
					if ( footerInput ) { d5dtSettings.reportFooter = footerInput.value.trim(); }
					if ( siteAbbrInput && siteAbbrInput.value.trim() ) { d5dtSettings.siteAbbr = siteAbbrInput.value.trim(); }
					// Apply beta state immediately without reload.
					if ( betaChk ) {
						var wasBeta = d5dtSettings.betaPreview;
						d5dtSettings.betaPreview = betaChk.checked;
						applyBetaState( betaChk.checked );
						// When switching from beta → no-beta, reset all views to defaults
						// and discard any unsaved changes from beta-only features.
						if ( wasBeta && ! betaChk.checked ) {
							resetBetaState();
						}
					}
					var reloadNeeded = debugChk && ( debugChk.checked !== ( ( d5dtSettings.debugMode ) ? true : false ) );
					if ( reloadNeeded ) {
						showToast( 'success', 'Settings saved', debugChk.checked
							? 'Debug mode is now ON. Reload the page to see the banner.'
							: 'Debug mode is now OFF.' );
						setTimeout( function () { window.location.reload(); }, 1200 );
					} else {
						showToast( 'success', 'Settings saved', 'Your preferences have been updated.' );
					}
				} else {
					saveBtn.disabled = false; // Re-enable so user can retry.
					if ( statusEl ) {
						statusEl.textContent = '✕ Save failed';
						statusEl.className   = 'd5dsh-settings-save-status is-error';
					}
					showToast( 'error', 'Save failed', ( json.data && json.data.message ) || 'Unknown error' );
				}
			} )
			.catch( function ( err ) {
				saveBtn.disabled = false; // Re-enable so user can retry.
				if ( statusEl ) {
					statusEl.textContent = '✕ Network error';
					statusEl.className   = 'd5dsh-settings-save-status is-error';
				}
				showToast( 'error', 'Save failed', 'Network error: ' + err.message );
			} );
		} );
	}

	/**
	 * Initialise the modal dialogs (Settings, Contact) and the help aside panel.
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 19 — HELP PANEL & MODALS                                     ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initModals() {
		// Wire open buttons for remaining modals.
		var helpBtn     = document.getElementById( 'd5dsh-btn-help' );
		var settingsBtn = document.getElementById( 'd5dsh-btn-settings' );
		var contactBtn  = document.getElementById( 'd5dsh-btn-contact' );
		if ( helpBtn ) {
			helpBtn.addEventListener( 'click', function () {
				// Open help panel scrolled to the section matching the current active tab.
				// Anchor IDs are slugs generated from the h2 headings in PLUGIN_USER_GUIDE.md.
				var tabAnchors = {
					manage:    '4-managing-variables-manage-tab',
					export:    '5-exporting-to-excel',
					import:    '8-importing-from-excel',
					snapshots: '11-snapshots-tab',
					audit:     null,
				};
				var params    = new URLSearchParams( window.location.search );
				var activeTab = params.get( 'tab' ) || 'export';
				openHelpPanel( tabAnchors[ activeTab ] !== undefined ? tabAnchors[ activeTab ] : null );
			} );
		}
		if ( settingsBtn ) { settingsBtn.addEventListener( 'click', function () { snapshotSettingsState(); openModal( 'd5dsh-settings-modal' ); } ); }
		if ( contactBtn )  { contactBtn.addEventListener(  'click', function () { openModal( 'd5dsh-contact-modal' );  } ); }

		// Wire close buttons (data-modal attribute on each × button).
		document.querySelectorAll( '.d5dsh-modal-close' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				closeModal( btn.dataset.modal );
			} );
		} );

		// Close on backdrop click.
		document.querySelectorAll( '.d5dsh-modal' ).forEach( function ( modal ) {
			modal.addEventListener( 'click', function ( e ) {
				if ( e.target === modal ) { closeModal( modal.id ); }
			} );
		} );

		// Wire Settings modal tab buttons.
		var settingsModal = document.getElementById( 'd5dsh-settings-modal' );
		if ( settingsModal ) {
			settingsModal.querySelectorAll( '.d5dsh-modal-tab' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					settingsModal.querySelectorAll( '.d5dsh-modal-tab' ).forEach( function ( t ) {
						t.classList.toggle( 'd5dsh-modal-tab-active', t === tab );
					} );
					settingsModal.querySelectorAll( '.d5dsh-modal-pane' ).forEach( function ( pane ) {
						pane.style.display = ( pane.dataset.pane === tab.dataset.tab ) ? '' : 'none';
					} );
				} );
			} );
		}

		// Initialise help aside panel.
		initHelpPanel();

		// Wire Impact modal tabs.
		initImpactModal();
	}

	function openModal( id ) {
		var modal = document.getElementById( id );
		if ( modal ) { modal.style.display = 'flex'; }
	}

	function closeModal( id ) {
		var modal = document.getElementById( id );
		if ( modal ) { modal.style.display = 'none'; }
		// After closing the import results modal, refresh the Manage tab data and reset the import UI.
		if ( id === 'd5dsh-si-results-modal' ) {
			loadManageData();
			siReset();
		}
		// Revert unsaved settings when closing the settings modal without saving.
		if ( id === 'd5dsh-settings-modal' ) {
			restoreSettingsState();
		}
	}

	// ── Help aside panel ───────────────────────────────────────────────────────

	var helpLoaded   = false;
	var helpFuse     = null;
	var helpSearchTO = null;

	/**
	 * Initialise help panel close button, search input, and deep-link triggers.
	 */
	function initHelpPanel() {
		var closeBtn = document.getElementById( 'd5dsh-help-close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', closeHelpPanel );
		}

		var searchInput = document.getElementById( 'd5dsh-help-search' );
		if ( searchInput ) {
			searchInput.addEventListener( 'input', function () {
				clearTimeout( helpSearchTO );
				helpSearchTO = setTimeout( function () {
					runHelpSearch( searchInput.value.trim() );
				}, 220 );
			} );
		}

		// Wire data-help-anchor clicks via delegation so dynamically-injected
		// elements (e.g. type badges in the import analysis panel) are covered.
		document.addEventListener( 'click', function ( e ) {
			var el = e.target.closest( '[data-help-anchor]' );
			if ( el ) {
				e.preventDefault();
				openHelpPanel( el.dataset.helpAnchor );
			}
		} );
	}

	/**
	 * Open the help aside panel. Optionally scroll to an anchor after load.
	 *
	 * @param {string} [anchor] - ID of the heading element to scroll to.
	 */
	function openHelpPanel( anchor ) {
		var panel = document.getElementById( 'd5dsh-help-panel' );
		if ( ! panel ) { return; }

		// Close the WP contextual help dropdown if it is open.
		var wpHelpWrap = document.getElementById( 'contextual-help-wrap' );
		if ( wpHelpWrap && wpHelpWrap.offsetHeight > 0 ) {
			var wpHelpLink = document.getElementById( 'contextual-help-link' );
			if ( wpHelpLink ) { wpHelpLink.click(); }
		}

		panel.hidden = false;
		panel.setAttribute( 'aria-hidden', 'false' );

		if ( ! helpLoaded ) {
			fetchHelpContent( anchor );
		} else if ( anchor ) {
			scrollHelpToAnchor( anchor );
		}
	}

	/**
	 * Close the help aside panel.
	 */
	function closeHelpPanel() {
		var panel = document.getElementById( 'd5dsh-help-panel' );
		if ( panel ) {
			panel.hidden = true;
			panel.setAttribute( 'aria-hidden', 'true' );
		}
	}

	/**
	 * Fetch parsed HTML from the server and inject into the panel body.
	 * Also fetches the search index and initialises Fuse.js.
	 *
	 * @param {string} [anchor] - Anchor to scroll to after load.
	 */
	function fetchHelpContent( anchor ) {
		if ( typeof d5dtHelp === 'undefined' ) { return; }

		var body    = document.getElementById( 'd5dsh-help-body' );
		var loading = body ? body.querySelector( '.d5dsh-help-loading' ) : null;
		if ( loading ) { loading.style.display = ''; }

		var fd = new FormData();
		fd.append( 'action', d5dtHelp.actionContent );

		fetch( d5dtHelp.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json.success || ! body ) { return; }
				body.innerHTML = json.data.html;
				helpLoaded = true;
				if ( anchor ) {
					scrollHelpToAnchor( anchor );
				}
				fetchHelpIndex();
			} )
			.catch( function () {
				if ( body ) { setElMsg( body, '', 'Could not load user guide. Check your connection and try again.' ); }
			} );
	}

	/**
	 * Fetch the Fuse.js search index from the server.
	 */
	function fetchHelpIndex() {
		if ( typeof d5dtHelp === 'undefined' ) { return; }

		var fd = new FormData();
		fd.append( 'action', d5dtHelp.actionIndex );

		fetch( d5dtHelp.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json.success || typeof Fuse === 'undefined' ) { return; }
				helpFuse = new Fuse( json.data, {
					keys: [
						{ name: 'heading', weight: 2 },
						{ name: 'text',    weight: 1 },
					],
					threshold:         0.35,
					includeScore:      true,
					minMatchCharLength: 2,
				} );
			} );
	}

	/**
	 * Scroll the help panel body to the element with the given id.
	 *
	 * @param {string} anchor
	 */
	function scrollHelpToAnchor( anchor ) {
		var body   = document.getElementById( 'd5dsh-help-body' );
		var target = body ? body.querySelector( '#' + CSS.escape( anchor ) ) : null;
		if ( target ) {
			target.scrollIntoView( { behavior: 'instant', block: 'start' } );
			target.classList.add( 'd5dsh-help-highlight' );
			setTimeout( function () { target.classList.remove( 'd5dsh-help-highlight' ); }, 2000 );
		}
	}

	/**
	 * Run a Fuse.js search and render results.
	 *
	 * @param {string} query
	 */
	function runHelpSearch( query ) {
		var resultsEl = document.getElementById( 'd5dsh-help-search-results' );
		var bodyEl    = document.getElementById( 'd5dsh-help-body' );
		if ( ! resultsEl ) { return; }

		if ( ! query ) {
			resultsEl.hidden = true;
			if ( bodyEl ) { bodyEl.style.display = ''; }
			return;
		}

		if ( ! helpFuse ) {
			setElMsg( resultsEl, 'd5dsh-help-search-loading', 'Loading search index…' );
			resultsEl.hidden = false;
			if ( bodyEl ) { bodyEl.style.display = 'none'; }
			return;
		}

		var results = helpFuse.search( query ).slice( 0, 8 );
		if ( ! results.length ) {
			var _srp = document.createElement( 'p' ); _srp.className = 'd5dsh-help-search-none'; _srp.appendChild( document.createTextNode( 'No results for ' ) ); var _srem = document.createElement( 'em' ); _srem.textContent = query; _srp.appendChild( _srem ); _srp.appendChild( document.createTextNode( '.' ) ); resultsEl.innerHTML = ''; resultsEl.appendChild( _srp );
		} else {
			var html = '<ul class="d5dsh-help-search-list">';
			results.forEach( function ( r ) {
				var item    = r.item;
				var snippet = item.text ? item.text.slice( 0, 120 ) + ( item.text.length > 120 ? '…' : '' ) : '';
				html += '<li><a href="#" class="d5dsh-help-search-hit" data-anchor="' + escAttr( item.id ) + '">'
					+ '<strong>' + escHtml( item.heading ) + '</strong>'
					+ ( snippet ? '<br><span class="d5dsh-help-search-snippet">' + escHtml( snippet ) + '</span>' : '' )
					+ '</a></li>';
			} );
			html += '</ul>';
			resultsEl.innerHTML = html;

			// Wire result links.
			resultsEl.querySelectorAll( '.d5dsh-help-search-hit' ).forEach( function ( link ) {
				link.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var anchor = link.dataset.anchor;
					resultsEl.hidden = true;
					if ( bodyEl ) { bodyEl.style.display = ''; }
					var searchInput = document.getElementById( 'd5dsh-help-search' );
					if ( searchInput ) { searchInput.value = ''; }
					scrollHelpToAnchor( anchor );
				} );
			} );
		}

		resultsEl.hidden = false;
		if ( bodyEl ) { bodyEl.style.display = 'none'; }
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 1 — UTILITIES & SHARED HELPERS                               ║
	// ╚══════════════════════════════════════════════════════════════════╝
	/**
	 * Escape a string for safe insertion as HTML text content.
	 */
	function escHtml( str ) {
		return String( str )
			.replace( /&/g,  '&amp;' )
			.replace( /</g,  '&lt;' )
			.replace( />/g,  '&gt;' )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	/**
	 * Escape a string for safe insertion as an HTML attribute value.
	 */
	function escAttr( str ) {
		return String( str ).replace( /"/g, '&quot;' ).replace( /'/g, '&#039;' );
	}

	/**
	 * Set a single-message paragraph inside a container element without using
	 * innerHTML string concatenation.
	 *
	 * Preferred over `el.innerHTML = '<p class="...">' + escHtml(msg) + '</p>'`
	 * because it uses textContent assignment — the browser never parses the
	 * message as HTML, eliminating any residual XSS risk regardless of input.
	 *
	 * @param {Element} el        Target container element.
	 * @param {string}  className CSS class(es) for the <p> element.
	 * @param {string}  text      Plain-text message (not HTML).
	 */
	function setElMsg( el, className, text ) {
		var p = document.createElement( 'p' );
		if ( className ) { p.className = className; }
		p.textContent = String( text );
		el.innerHTML  = '';
		el.appendChild( p );
	}

	// ── Data loading ──────────────────────────────────────────────────────────

	function loadManageData() {
		if ( typeof d5dtManage === 'undefined' ) { return; }

		showManageLoading( true );

		var fd = new FormData();
		fd.append( 'action', 'd5dsh_manage_load' );
		fd.append( 'nonce',  d5dtManage.nonce );

		fetch( d5dtManage.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( ! json.success ) {
					showManageError( json.data ? json.data.message : 'Load failed.' );
					return;
				}
				manageData     = json.data;
				manageOriginal = deepClone( manageData );
				pendingBulk    = null;
				clearChangeSource();
				showManageLoading( false );
				renderTable();
				updateFilterIndicators();
				updateClearAllVisibility();
				// If presets are already loaded, refresh the Everything view with the now-available var data.
				if ( presetsDataLoaded ) { renderEverythingTable(); }
				// Tabulator manages its own height/columns.
			} )
			.catch( function ( err ) {
				showManageError( 'Request failed: ' + err.message );
			} );
	}

	function showManageLoading( on ) {
		var loading   = document.getElementById( 'd5dsh-manage-loading' );
		var tabulator = document.getElementById( 'd5dsh-vars-tabulator' );
		var filter    = document.getElementById( 'd5dsh-manage-filter' );
		if ( loading   ) { loading.style.display   = on ? 'block' : 'none'; }
		if ( tabulator ) { tabulator.style.display  = on ? 'none'  : ''; }
		if ( filter    ) { filter.style.display     = on ? 'none'  : ''; }
	}

	function showManageError( msg ) {
		var el = document.getElementById( 'd5dsh-manage-error' );
		if ( el ) {
			el.textContent  = msg;
			el.style.display = 'block';
		}
		showManageLoading( false );
	}

	// ── Table rendering ───────────────────────────────────────────────────────

	function renderTable() {
		if ( ! manageData ) { return; }

		// Compute full row list (respects section/type filters).
		var allRows = getDisplayRows( false );

		// Detect duplicate labels.
		currentDupeLabels = findDupeLabels( allRows );

		// Apply dupe filter if active.
		var rows = dupeFilterActive
			? allRows.filter( function ( item ) {
				return currentDupeLabels[ ( item.label || '' ).toLowerCase().trim() ];
			} )
			: allRows;

		// Delegate rendering to Tabulator.
		if ( window.d5dshVarsTable ) {
			window.d5dshVarsTable.render( rows );
		}

		// Update dupe count badge.
		updateDupeCount( currentDupeLabels );

		// Update save bar.
		updateSaveBar();
	}

	function getDisplayRows( /* _applyDupeFilter — reserved */ ) {
		if ( ! manageData ) { return []; }
		var rows;
		// Show all variables (vars + global_colors).
		rows = ( manageData.vars || [] ).concat( manageData.global_colors || [] );
		// Apply column filters.
		if ( Object.keys( colFilters ).length > 0 ) {
			rows = rows.filter( passesColFilters );
		}
		// Hide system/global-color items if toggle active.
		if ( hideSystem ) {
			rows = rows.filter( function ( item ) {
				return ! item.system && item.type !== 'global_color';
			} );
		}
		// Apply column sort.
		if ( colSort.key ) {
			var sortKey = colSort.key;
			var sortDir = colSort.dir;
			var TYPE_LABELS_SORT = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
			rows = rows.slice().sort( function ( a, b ) {
				var va, vb;
				if ( sortKey === 'type' ) {
					va = a.type === 'global_color' ? 'Global Color' : ( TYPE_LABELS_SORT[ a.type ] || a.type || '' );
					vb = b.type === 'global_color' ? 'Global Color' : ( TYPE_LABELS_SORT[ b.type ] || b.type || '' );
				} else {
					va = ( a[ sortKey ] || '' ).toString().toLowerCase();
					vb = ( b[ sortKey ] || '' ).toString().toLowerCase();
				}
				if ( va < vb ) { return sortDir === 'asc' ? -1 : 1; }
				if ( va > vb ) { return sortDir === 'asc' ?  1 : -1; }
				return 0;
			} );
		}
		return rows;
	}

	function findDupeLabels( rows ) {
		var seen   = {};
		var dupes  = {};
		rows.forEach( function ( item ) {
			var lc = ( item.label || '' ).toLowerCase().trim();
			if ( ! lc ) { return; }
			if ( seen[ lc ] ) {
				dupes[ lc ] = true;
			}
			seen[ lc ] = true;
		} );
		return dupes;
	}

	function updateDupeCount( dupeLabels ) {
		var count = Object.keys( dupeLabels ).length;
		var el    = document.getElementById( 'd5dsh-manage-dupe-count' );
		if ( ! el ) { return; }
		if ( count === 0 ) {
			el.style.display = 'none';
		} else {
			el.textContent   = count === 1 ? '1 duplicate label' : count + ' duplicate labels';
			el.style.display = 'inline';
		}
	}

	/**
	 * Build a single table row element.
	 *
	 * @param  {Object}  item       { id, label, value, type?, status, order? }
	 * @param  {number}  idx        0-based display index (for move buttons)
	 * @param  {Object}  dupeLabels { lowercase_label: true, ... }
	 * @return {HTMLTableRowElement}
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 6 — MANAGE TAB: ROW BUILDING & EDITING                       ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function buildRow( item, idx, dupeLabels ) {
		var tr = document.createElement( 'tr' );
		tr.dataset.id      = item.id;
		tr.dataset.type    = item.type || '';
		tr.dataset.section = item.type === 'global_color' ? 'global_colors' : 'vars';

		var isDirty = isItemDirty( item );
		if ( isDirty ) { tr.classList.add( 'd5dsh-row-dirty' ); }

		var lc       = ( item.label || '' ).toLowerCase().trim();
		var isDupe   = !! dupeLabels[ lc ];
		var isGc     = item.type === 'global_color';
		var TYPE_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
		var typeText = isGc ? 'global color' : ( TYPE_LABELS[ item.type ] || item.type || '' );
		var orderVal = item.order != null ? item.order : ( idx + 1 );

		// Order cell.
		var tdOrder = document.createElement( 'td' );
		tdOrder.className   = 'd5dsh-col-order';
		tdOrder.textContent = String( orderVal );
		tdOrder.dataset.smartValue = String( orderVal );
		tr.appendChild( tdOrder );

		// Note cell (position 2).
		var tdNoteEarly = document.createElement( 'td' );
		tdNoteEarly.className = 'd5dsh-col-note';
		tdNoteEarly.innerHTML = noteIndicatorHTML( 'var:' + ( item.id || '' ) );
		tr.appendChild( tdNoteEarly );

		// Type cell.
		var tdType = document.createElement( 'td' );
		tdType.className = 'd5dsh-col-type';
		var typeBadge = document.createElement( 'span' );
		typeBadge.className = 'd5dsh-type-badge d5dsh-type-' + typeText.replace( /\s+/g, '-' );
		typeBadge.textContent = typeText;
		tdType.appendChild( typeBadge );
		var isSystem = item.system || isGc;
		tdType.dataset.smartValue = typeText;
		tr.appendChild( tdType );

		// ID cell. For global colors/system vars append a ‡ dagger superscript with tooltip.
		var tdId = document.createElement( 'td' );
		tdId.className = 'd5dsh-col-id d5dsh-copy-cell';
		tdId.title = item.id; // enables click-to-copy of the full ID
		var idCode = document.createElement( 'code' );
		idCode.textContent = item.id;
		tdId.appendChild( idCode );
		if ( isSystem ) {
			var daggerMsg = isGc
				? 'This is a Global Color. Its label is system-defined and cannot be changed. You may edit its value (hex color).'
				: 'This is a system variable managed by Divi. Edit with caution.';
			var dagger = document.createElement( 'sup' );
			dagger.className = 'd5dsh-system-dagger';
			dagger.textContent = '‡';
			dagger.setAttribute( 'aria-label', daggerMsg );
			dagger.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				// Remove any existing popover first.
				var existing = document.querySelector( '.d5dsh-dagger-popover' );
				if ( existing ) {
					existing.parentNode.removeChild( existing );
					if ( existing._sourceBtn === dagger ) { return; } // toggle off
				}
				var pop = document.createElement( 'div' );
				pop.className = 'd5dsh-dagger-popover';
				pop._sourceBtn = dagger;
				pop.textContent = daggerMsg;
				var closeBtn = document.createElement( 'button' );
				closeBtn.className = 'd5dsh-dagger-popover-close';
				closeBtn.textContent = '×';
				closeBtn.addEventListener( 'click', function ( ev ) {
					ev.stopPropagation();
					if ( pop.parentNode ) { pop.parentNode.removeChild( pop ); }
				} );
				pop.appendChild( closeBtn );
				tdId.style.position = 'relative';
				tdId.appendChild( pop );
				// Auto-dismiss on outside click.
				setTimeout( function () {
					document.addEventListener( 'click', function dismissPop() {
						if ( pop.parentNode ) { pop.parentNode.removeChild( pop ); }
						document.removeEventListener( 'click', dismissPop );
					} );
				}, 0 );
			} );
			tdId.appendChild( dagger );
		}
		tdId.dataset.smartValue = item.id;
		tr.appendChild( tdId );

		// Label cell — read-only in all sections (value cell is editable instead).
		var tdLabel = document.createElement( 'td' );
		tdLabel.className = 'd5dsh-col-label';
		var labelSpan = document.createElement( 'span' );
		labelSpan.className = 'd5dsh-readonly-label';
		labelSpan.textContent = item.label || '';
		if ( isDupe ) {
			var dupeBadge = document.createElement( 'span' );
			dupeBadge.className = 'd5dsh-dupe-badge';
			dupeBadge.title = 'Duplicate label';
			dupeBadge.textContent = '2×';
			labelSpan.appendChild( dupeBadge );
		}
		tdLabel.appendChild( labelSpan );
		tdLabel.dataset.smartValue = item.label || '';
		tr.appendChild( tdLabel );

		// Swatch column hidden (CSS display:none) — swatch now renders inline in Value cell.
		var tdSwatch = document.createElement( 'td' );
		tdSwatch.className = 'd5dsh-col-swatch';
		tr.appendChild( tdSwatch );

		var isColor = isGc || item.type === 'colors';

		// Value cell — read-only for colors; swatch dot prepended inline.
		var tdValue = document.createElement( 'td' );
		tdValue.className = 'd5dsh-col-value';
		if ( isColor ) {
			// Inline swatch dot before the value text.
			var rawVal = ( item.value || '' ).trim();
			var isVarRef = rawVal.indexOf( '$variable(' ) !== -1;
			if ( isVarRef ) {
				// Resolve the variable reference: extract the ID and look it up.
				var refId    = null;
				var refMatch = rawVal.match( /\$variable\s*\(\s*(\{[^}]*\})\s*\)\s*\$/ );
				if ( refMatch ) {
					try {
						var refObj = JSON.parse( refMatch[1] );
						refId = refObj.id || null;
					} catch ( e ) { /* malformed JSON — leave refId null */ }
				}
				var refItem = null;
				if ( refId && manageData ) {
					var allColors = ( manageData.vars || [] ).filter( function ( v ) { return v.type === 'colors'; } )
						.concat( manageData.global_colors || [] );
					for ( var ci = 0; ci < allColors.length; ci++ ) {
						if ( allColors[ ci ].id === refId ) { refItem = allColors[ ci ]; break; }
					}
				}
				// Show a swatch for the resolved color if we found it.
				if ( refItem && refItem.value && refItem.value.indexOf( '$variable(' ) === -1 ) {
					var refSwatch = document.createElement( 'span' );
					refSwatch.className = 'd5dsh-color-swatch-inline';
					refSwatch.style.background = refItem.value;
					refSwatch.title = refItem.value;
					tdValue.appendChild( refSwatch );
				}
				var colorValSpan = document.createElement( 'span' );
				colorValSpan.className = 'd5dsh-readonly-cell d5dsh-var-ref';
				colorValSpan.textContent = refItem ? ( '\u2192 ' + refItem.label ) : rawVal;
				colorValSpan.title = refItem
					? ( 'References: ' + refItem.label + ' (' + refItem.value + ')\nRaw: ' + rawVal )
					: rawVal;
				tdValue.appendChild( colorValSpan );
			} else {
				if ( rawVal ) {
					var swatchDot = document.createElement( 'span' );
					swatchDot.className = 'd5dsh-color-swatch-inline';
					swatchDot.style.background = rawVal;
					swatchDot.title = rawVal;
					tdValue.appendChild( swatchDot );
				}
				var colorValSpan = document.createElement( 'span' );
				colorValSpan.className = 'd5dsh-readonly-cell';
				colorValSpan.textContent = item.value || '';
				colorValSpan.title = 'Color value — edit via export/import to update.';
				tdValue.appendChild( colorValSpan );
			}
		} else if ( ! isGc && item.type === 'images' ) {
			var imgVal = ( item.value || '' );
			var displayVal = imgVal;
			if ( imgVal.indexOf( 'data:' ) === 0 ) {
				displayVal = '[embedded image — not shown]';
			} else if ( imgVal.length > 0 ) {
				// Extract filename from URL.
				var parts = imgVal.split( '/' );
				displayVal = parts[ parts.length - 1 ] || imgVal;
			}
			var imgSpan = document.createElement( 'span' );
			imgSpan.className = 'd5dsh-image-filename';
			imgSpan.textContent = displayVal;
			imgSpan.title = imgVal;
			tdValue.appendChild( imgSpan );
		} else if ( d5dtSettings && d5dtSettings.betaPreview ) {
			tdValue.appendChild( buildEditableCell( item, 'value', false ) );
		} else {
			var roValSpan = document.createElement( 'span' );
			roValSpan.className = 'd5dsh-readonly-cell';
			roValSpan.textContent = item.value || '';
			tdValue.appendChild( roValSpan );
		}
		// Column expand toggles read dataset.smartValue — set it to the actual display text,
		// not textContent (which would include child span/input text).
		var _smv;
		if ( isColor ) {
			_smv = ( item.value || '' ).trim();
		} else if ( item.type === 'images' ) {
			_smv = ( function() {
				var v = item.value || '';
				if ( v.indexOf( 'data:' ) === 0 ) { return '[embedded image]'; }
				var p = v.split( '/' ); return p[ p.length - 1 ] || v;
			}() );
		} else {
			_smv = item.value || '';
		}
		tdValue.dataset.smartValue = _smv;
		tr.appendChild( tdValue );

		// Category cell — swatches for assigned user-defined categories.
		var tdVarCat = document.createElement( 'td' );
		tdVarCat.className = 'd5dsh-col-var-category';
		var _varCatIds = categoryMap[ 'var:' + ( item.id || '' ) ] || [];
		if ( ! Array.isArray( _varCatIds ) ) { _varCatIds = [ _varCatIds ]; }
		_varCatIds = _varCatIds.filter( Boolean );
		if ( _varCatIds.length > 0 ) {
			tdVarCat.innerHTML = _varCatIds.map( function ( cid ) {
				var c = categoriesData.find( function ( x ) { return x.id === cid; } );
				return c ? '<span class="d5dsh-category-swatch" style="background:' + escHtml( c.color ) + '" title="' + escHtml( c.label ) + '"></span>' : '';
			} ).join( '' );
		}
		tr.appendChild( tdVarCat );

		// Actions cell — Impact button.
		var tdActions = document.createElement( 'td' );
		tdActions.className = 'd5dsh-col-actions';
		var impactBtn = document.createElement( 'button' );
		impactBtn.type      = 'button';
		impactBtn.className = 'd5dsh-impact-btn';
		impactBtn.title     = 'Impact analysis: what uses this variable?';
		impactBtn.textContent = 'ℹ';
		impactBtn.dataset.dsoType = 'variable';
		impactBtn.dataset.dsoId   = item.id || '';
		impactBtn.dataset.dsoLabel = item.label || item.id || '';
		tdActions.appendChild( impactBtn );
		tr.appendChild( tdActions );

		// Status cell — editable dropdown in beta, read-only text otherwise.
		var tdStatus = document.createElement( 'td' );
		tdStatus.className = 'd5dsh-col-status';
		if ( d5dtSettings && d5dtSettings.betaPreview ) {
			tdStatus.appendChild( buildStatusSelect( item ) );
		} else {
			tdStatus.textContent = item.status || 'active';
		}
		tdStatus.dataset.smartValue = item.status || 'active';
		tr.appendChild( tdStatus );

		return tr;
	}

	/**
	 * Build an inline-editable cell.
	 * Click to edit → shows <input>; Enter/Blur to commit; Escape to cancel.
	 */
	function buildEditableCell( item, field, isDupe ) {
		var wrap = document.createElement( 'span' );
		wrap.className = 'd5dsh-editable-wrap';

		var display = document.createElement( 'span' );
		display.className   = 'd5dsh-editable-display';
		display.textContent = item[ field ] || '';
		if ( isDupe && field === 'label' ) {
			var dupeBadge = document.createElement( 'span' );
			dupeBadge.className   = 'd5dsh-dupe-badge';
			dupeBadge.textContent = 'duplicate';
			display.appendChild( document.createTextNode( ' ' ) );
			display.appendChild( dupeBadge );
		}
		wrap.appendChild( display );

		display.addEventListener( 'click', function () {
			startEdit( wrap, display, input, item, field );
		} );

		var input = document.createElement( 'input' );
		input.type      = 'text';
		input.className = 'd5dsh-editable-input';
		input.value     = item[ field ] || '';
		input.style.display = 'none';
		wrap.appendChild( input );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' )  { commitEdit( wrap, display, input, item, field ); }
			if ( e.key === 'Escape' ) { cancelEdit( wrap, display, input ); }
		} );
		input.addEventListener( 'blur', function () {
			commitEdit( wrap, display, input, item, field );
		} );

		return wrap;
	}

	function startEdit( wrap, display, input, item, field ) {
		display.style.display = 'none';
		input.style.display   = 'inline-block';
		input.value = item[ field ] || '';
		input.focus();
		input.select();
	}

	function commitEdit( wrap, display, input, item, field ) {
		if ( input.style.display === 'none' ) { return; } // Already committed.
		var newVal = input.value;
		item[ field ] = newVal;
		// Update display text (strip old dupe badge first).
		display.textContent = newVal;
		display.style.display = 'inline';
		input.style.display   = 'none';
		setChangeSource( 'inline' );
		// Re-render to update dirty state, dupe badges, save bar.
		renderTable();
	}

	function cancelEdit( wrap, display, input ) {
		display.style.display = 'inline';
		input.style.display   = 'none';
	}

	/**
	 * Build a <select> for the status field.
	 */
	function buildStatusSelect( item ) {
		var sel = document.createElement( 'select' );
		sel.className = 'd5dsh-status-select';
		[ 'active', 'archived', 'inactive' ].forEach( function ( s ) {
			var opt = document.createElement( 'option' );
			opt.value     = s;
			opt.textContent = s;
			opt.selected  = item.status === s;
			sel.appendChild( opt );
		} );
		sel.addEventListener( 'change', function () {
			item.status = sel.value;
			setChangeSource( 'inline' );
			renderTable();
		} );
		return sel;
	}

	// ── Row reorder ───────────────────────────────────────────────────────────

	/**
	 * Move an item in the vars array by ±1 within its type group.
	 */
	function moveRow( item, direction ) {
		if ( ! manageData ) { return; }

		var vars   = manageData.vars;
		var type   = item.type;
		var typeItems = vars.filter( function ( v ) { return v.type === type; } );
		var curIdx    = typeItems.indexOf( item );
		var newIdx    = curIdx + direction;

		if ( newIdx < 0 || newIdx >= typeItems.length ) { return; }

		// Swap in the full vars array.
		var aIdx = vars.indexOf( typeItems[ curIdx ] );
		var bIdx = vars.indexOf( typeItems[ newIdx ] );
		var temp   = vars[ aIdx ];
		vars[ aIdx ] = vars[ bIdx ];
		vars[ bIdx ] = temp;

		// Re-derive order numbers within each type.
		recomputeOrder( vars );

		renderTable();
	}

	function recomputeOrder( vars ) {
		var counters = {};
		vars.forEach( function ( v ) {
			counters[ v.type ] = ( counters[ v.type ] || 0 ) + 1;
			v.order = counters[ v.type ];
		} );
	}

	// ── Dirty tracking ────────────────────────────────────────────────────────

	/**
	 * Return true if item differs from its original counterpart.
	 */
	function isItemDirty( item ) {
		if ( ! manageOriginal ) { return false; }

		// Search in the list that matches the item's type.
		var origList = item.type === 'global_color'
			? manageOriginal.global_colors
			: manageOriginal.vars;

		var orig = origList.find( function ( o ) { return o.id === item.id; } );
		if ( ! orig ) { return true; } // New item (shouldn't happen in v0.6).

		return ( orig.label !== item.label
			|| orig.value  !== item.value
			|| orig.status !== item.status
			|| ( orig.order !== undefined && orig.order !== item.order ) );
	}

	function countDirtyItems() {
		if ( ! manageData || ! manageOriginal ) { return 0; }
		var n = 0;
		manageData.vars.forEach( function ( v ) {
			if ( isItemDirtyInList( v, manageOriginal.vars ) ) { n++; }
		} );
		manageData.global_colors.forEach( function ( gc ) {
			if ( isItemDirtyInList( gc, manageOriginal.global_colors ) ) { n++; }
		} );
		return n;
	}

	function isItemDirtyInList( item, origList ) {
		var orig = ( origList || [] ).find( function ( o ) { return o.id === item.id; } );
		if ( ! orig ) { return true; }
		return ( orig.label !== item.label
			|| orig.value  !== item.value
			|| orig.status !== item.status
			|| ( orig.order !== undefined && orig.order !== item.order ) );
	}

	function updateSaveBar() {
		var bar       = document.getElementById( 'd5dsh-manage-save-bar' );
		var countEl   = document.getElementById( 'd5dsh-manage-dirty-count' );
		var dirtyCount = countDirtyItems();
		var hasBulk    = pendingBulk !== null;

		if ( bar ) {
			bar.style.display = ( dirtyCount > 0 || hasBulk ) ? 'flex' : 'none';
		}
		if ( countEl ) {
			var suffix = formatChangeSource();
			if ( hasBulk ) {
				countEl.textContent = 'Bulk operation ready' + suffix + ' — click Save to apply and commit.';
			} else if ( dirtyCount === 1 ) {
				countEl.textContent = '1 unsaved change' + suffix;
			} else if ( dirtyCount > 1 ) {
				countEl.textContent = dirtyCount + ' unsaved changes' + suffix;
			} else {
				countEl.textContent = '';
			}
		}

		// Persist pending-changes flag to sessionStorage so the Export tab can read it.
		if ( dirtyCount > 0 || hasBulk ) {
			try { sessionStorage.setItem( 'd5dsh_pending_changes', '1' ); } catch(e) {}
		} else {
			try { sessionStorage.removeItem( 'd5dsh_pending_changes' ); } catch(e) {}
		}
	}

	// ── Bulk operations ───────────────────────────────────────────────────────

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 7 — MANAGE TAB: BULK OPERATIONS                              ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function updateBulkFields() {
		var op      = ( document.getElementById( 'd5dsh-bulk-op' ) || {} ).value || '';
		var ops     = [ 'prefix', 'suffix', 'find_replace', 'normalize' ];
		var actions = document.getElementById( 'd5dsh-bulk-actions' );

		ops.forEach( function ( o ) {
			var el = document.getElementById( 'd5dsh-fields-' + o );
			if ( el ) { el.style.display = o === op ? 'inline' : 'none'; }
		} );
		// Show action buttons only when an op is selected.
		if ( actions ) { actions.style.display = op ? 'inline-flex' : 'none'; }
		// Reset preview state whenever op changes.
		bulkPreviewActive = false;
		updateBulkButtonStates();
		// Enable Preview when an operation is selected.
		var previewBtn = document.getElementById( 'd5dsh-bulk-preview' );
		if ( previewBtn ) { previewBtn.disabled = ! op; }
	}

	/**
	 * Toggle bulk preview on/off.
	 * First click: applies transform in-memory (preview). Second click: reverts.
	 */
	function applyBulkPreview() {
		if ( bulkPreviewActive ) {
			// Toggle off — revert to original.
			manageData  = deepClone( manageOriginal );
			pendingBulk = null;
			bulkPreviewActive = false;
			updateBulkButtonStates();
			renderTable();
			updateSaveBar();
			return;
		}

		var op    = ( document.getElementById( 'd5dsh-bulk-op'    ) || {} ).value || '';
		var scope = ( document.getElementById( 'd5dsh-bulk-scope' ) || {} ).value || 'all';
		if ( ! op ) { return; }

		var bulk = { op: op, scope: scope };

		if ( op === 'prefix' ) {
			bulk.value = ( document.getElementById( 'd5dsh-bulk-prefix-value' ) || {} ).value || '';
		} else if ( op === 'suffix' ) {
			bulk.value = ( document.getElementById( 'd5dsh-bulk-suffix-value' ) || {} ).value || '';
		} else if ( op === 'find_replace' ) {
			bulk.find    = ( document.getElementById( 'd5dsh-bulk-find'    ) || {} ).value || '';
			bulk.replace = ( document.getElementById( 'd5dsh-bulk-replace' ) || {} ).value || '';
		} else if ( op === 'normalize' ) {
			bulk.case = ( document.getElementById( 'd5dsh-bulk-case' ) || {} ).value || 'title';
		}

		// Apply bulk transform in-memory so user sees the result immediately.
		// The server re-applies the same transform on Save (idempotent).
		var transform = makeBulkTransform( bulk );
		if ( transform && manageData ) {
			applyTransformToList( manageData.vars, scope, transform, false );
			applyTransformToList( manageData.global_colors, scope, transform, true );
		}

		pendingBulk = bulk;
		bulkPreviewActive = true;
		setChangeSource( 'bulk' );
		updateBulkButtonStates();
		renderTable();
		updateSaveBar();
	}

	/**
	 * Apply the pending bulk op to the current visible (filtered) rows only.
	 */
	function applyBulkToVisible() {
		if ( ! bulkPreviewActive || ! pendingBulk ) { return; }
		// Already applied in-memory to all rows during preview.
		// "Apply" just confirms — save bar is already shown.
		// Nothing extra to do client-side; Save button will commit.
		var applyBtn = document.getElementById( 'd5dsh-bulk-apply' );
		if ( applyBtn ) {
			applyBtn.textContent = 'Applied ✓';
			setTimeout( function () { applyBtn.textContent = 'Apply'; }, 2000 );
		}
	}

	/**
	 * Undo the preview — revert to original data.
	 */
	function undoBulkPreview() {
		manageData  = deepClone( manageOriginal );
		pendingBulk = null;
		bulkPreviewActive = false;
		updateBulkButtonStates();
		renderTable();
		updateSaveBar();
	}

	/**
	 * Return a function(label) → string for the given bulk descriptor, or null.
	 */
	function makeBulkTransform( bulk ) {
		var op = bulk.op;
		if ( op === 'prefix'      ) { return function ( l ) { return ( bulk.value || '' ) + l; }; }
		if ( op === 'suffix'      ) { return function ( l ) { return l + ( bulk.value || '' ); }; }
		if ( op === 'find_replace') {
			return function ( l ) {
				var find = bulk.find || '';
				if ( ! find ) { return l; }
				return l.split( find ).join( bulk.replace || '' );
			};
		}
		if ( op === 'normalize'   ) {
			var caseType = bulk.case || 'title';
			return function ( l ) { return normalizeCase( l, caseType ); };
		}
		return null;
	}

	/**
	 * Apply transform to a list in-place. Skips global colors (isGc=true) for
	 * all scopes except 'all' and 'global_colors' — but global colors have
	 * system labels that cannot be meaningfully renamed, so we skip them entirely.
	 */
	function applyTransformToList( list, scope, transform, isGc ) {
		// Global colors: never apply bulk label ops (labels are system-defined).
		if ( isGc ) { return; }
		list.forEach( function ( item ) {
			// Scope filtering (vars only — isGc already excluded above).
			if ( scope === 'vars' || scope === 'all' ) {
				item.label = transform( item.label || '' );
			} else if ( scope.indexOf( 'type:' ) === 0 ) {
				var typeKey = scope.slice( 5 );
				if ( item.type === typeKey ) {
					item.label = transform( item.label || '' );
				}
			}
		} );
	}

	/**
	 * Client-side case normalisation (mirrors server LabelManager::normalize_label).
	 */
	function normalizeCase( label, caseType ) {
		if ( caseType === 'upper' ) { return label.toUpperCase(); }
		if ( caseType === 'lower' ) { return label.toLowerCase(); }
		if ( caseType === 'title' ) {
			return label.replace( /\b\w/g, function ( c ) { return c.toUpperCase(); } );
		}
		if ( caseType === 'snake' ) {
			return label.trim().toLowerCase().replace( /[\s\-]+/g, '_' );
		}
		if ( caseType === 'camel' ) {
			var words = label.trim().split( /[\s\-_]+/ );
			return words.map( function ( w, i ) {
				return i === 0 ? w.toLowerCase() : w.charAt( 0 ).toUpperCase() + w.slice( 1 ).toLowerCase();
			} ).join( '' );
		}
		return label;
	}

	// ── Save / Discard ────────────────────────────────────────────────────────

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 8 — MANAGE TAB: SAVE / DISCARD                               ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function handleSave() {
		if ( typeof d5dtManage === 'undefined' ) { return; }

		var saveBtn = document.getElementById( 'd5dsh-manage-save' );
		if ( saveBtn ) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

		var payload = {
			vars:          manageData.vars,
			global_colors: manageData.global_colors,
		};
		if ( pendingBulk ) {
			payload.bulk = pendingBulk;
		}

		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_manage_save&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( payload ),
		} )
			.then( function ( res ) { return res.json(); } )
			.then( function ( json ) {
				if ( saveBtn ) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
				if ( ! json.success ) {
					showManageError( json.data ? json.data.message : 'Save failed.' );
					return;
				}
				// Update live data with server response (bulk ops applied there).
				manageData     = json.data;
				manageOriginal = deepClone( manageData );
				pendingBulk    = null;
				clearChangeSource();
				renderTable();
				showSaveSuccess();
			} )
			.catch( function ( err ) {
				if ( saveBtn ) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
				showManageError( 'Save request failed: ' + err.message );
			} );
	}

	function handleDiscard() {
		if ( ! manageOriginal ) { return; }
		manageData  = deepClone( manageOriginal );
		pendingBulk = null;
		clearChangeSource();
		renderTable();
	}

	function showSaveSuccess() {
		var bar = document.getElementById( 'd5dsh-manage-save-bar' );
		var msg = document.createElement( 'span' );
		msg.className   = 'd5dsh-save-success';
		msg.textContent = 'Saved.';
		if ( bar ) {
			bar.appendChild( msg );
			setTimeout( function () {
				if ( msg.parentNode ) { msg.parentNode.removeChild( msg ); }
			}, 3000 );
		}
		showToast( 'success', 'Variables saved', 'Your changes have been saved successfully.' );
	}

	// ── Utilities ─────────────────────────────────────────────────────────────

	function deepClone( obj ) {
		return JSON.parse( JSON.stringify( obj ) );
	}

	// ── Print setup ───────────────────────────────────────────────────────────

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 10 — PRINT & CSV                                             ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function openPrintSetup() {
		// Restore modal title (may have been changed by a presets print call).
		var titleEl = document.querySelector( '#d5dsh-print-modal .d5dsh-modal-title' );
		if ( titleEl ) { titleEl.textContent = 'Print Setup'; }
		var modal = document.getElementById( 'd5dsh-print-modal' );
		if ( modal ) { modal.dataset.exportMode = 'print'; modal.dataset.tableKey = 'vars'; }
		var goBtn2 = document.getElementById( 'd5dsh-print-go' );
		if ( goBtn2 ) { goBtn2.textContent = 'Print'; }

		// Always define VAR_COLS with screen px widths for proportional print layout.
		var VAR_COLS = [
			{ value: 'order',  label: '#',      checked: true,  px: 36  },
			{ value: 'note',   label: 'Notes',  checked: false, cssClass: 'd5dsh-col-note', px: 50 },
			{ value: 'type',   label: 'Type',   checked: true,  px: 80  },
			{ value: 'id',     label: 'ID',     checked: true,  px: 140 },
			{ value: 'label',  label: 'Label',  checked: true,  px: 160 },
			{ value: 'swatch', label: 'Swatch', checked: false, px: 36  },
			{ value: 'value',  label: 'Value',  checked: true,  px: 630 },
			{ value: 'status', label: 'Status', checked: false, px: 88  },
		];

		// Always rebuild Variables columns so data-px attributes are set correctly.
		var colsWrap = document.querySelector( '#d5dsh-print-modal .d5dsh-print-cols' );
		if ( colsWrap ) {
			colsWrap.innerHTML = '';
			VAR_COLS.forEach( function ( col ) {
				var lbl = document.createElement( 'label' );
				lbl.className = 'd5dsh-setting-row';
				var chk = document.createElement( 'input' );
				chk.type           = 'checkbox';
				chk.className      = 'd5dsh-print-col-chk';
				chk.value          = col.value;
				chk.checked        = col.checked;
				chk.dataset.px     = col.px;
				lbl.appendChild( chk );
				lbl.appendChild( document.createTextNode( ' ' + col.label ) );
				colsWrap.appendChild( lbl );
			} );
		}

		openModal( 'd5dsh-print-modal' );

		var goBtn     = document.getElementById( 'd5dsh-print-go' );
		var cancelBtn = document.getElementById( 'd5dsh-print-cancel' );

		// Remove any previously attached one-time listeners before re-attaching.
		var newGo     = goBtn.cloneNode( true );
		var newCancel = cancelBtn.cloneNode( true );
		goBtn.parentNode.replaceChild( newGo, goBtn );
		cancelBtn.parentNode.replaceChild( newCancel, cancelBtn );

		newCancel.addEventListener( 'click', function () {
			closeModal( 'd5dsh-print-modal' );
		} );

		newGo.addEventListener( 'click', function () {
			// Read column checkboxes.
			var hiddenCols  = [];
			var visibleCols = [];
			document.querySelectorAll( '.d5dsh-print-col-chk' ).forEach( function ( chk ) {
				if ( ! chk.checked ) {
					hiddenCols.push( chk.value );
				} else {
					visibleCols.push( { value: chk.value, px: parseInt( chk.dataset.px, 10 ) || 0 } );
				}
			} );

			var modalEl    = document.getElementById( 'd5dsh-print-modal' );
			var exportMode = modalEl ? ( modalEl.dataset.exportMode || 'print' ) : 'print';
			var tKey       = modalEl ? ( modalEl.dataset.tableKey   || 'vars'  ) : 'vars';

			closeModal( 'd5dsh-print-modal' );

			if ( exportMode === 'csv' ) {
				executeExportCsv( tKey, visibleCols );
				return;
			}

			// Read orientation.
			var orientationEl = document.querySelector( 'input[name="d5dsh-print-orientation"]:checked' );
			var orientation   = orientationEl ? orientationEl.value : 'portrait';

			// Read and clamp margins (min 0.3in).
			function clampMargin( id ) {
				var el = document.getElementById( id );
				var v  = el ? parseFloat( el.value ) : 0.5;
				return Math.max( 0.3, isNaN( v ) ? 0.5 : v );
			}
			var margins = {
				top:    clampMargin( 'd5dsh-print-margin-top' ),
				right:  clampMargin( 'd5dsh-print-margin-right' ),
				bottom: clampMargin( 'd5dsh-print-margin-bottom' ),
				left:   clampMargin( 'd5dsh-print-margin-left' ),
			};

			executePrint( hiddenCols, orientation, margins, visibleCols );
		} );
	}

	/**
	 * Shared print popup helper used by all print functions in the plugin.
	 * Opens a new window with a clean PrintBuilder-style HTML document:
	 * header (reportHeader setting), footer (reportFooter setting + page numbers),
	 * A4 margins, Print/Close buttons.
	 *
	 * @param {string} bodyHtml    The inner HTML to place in the document body.
	 * @param {string} docTitle    Title shown at top of document.
	 * @param {string} orientation 'portrait' | 'landscape'
	 * @param {Object} margins     { top, right, bottom, left } in inches.
	 */
	function openPrintWindow( bodyHtml, docTitle, orientation, margins ) {
		margins = margins || { top: 0.75, right: 0.75, bottom: 1, left: 0.75 };
		var reportHeader = ( d5dtSettings && d5dtSettings.reportHeader ) ? d5dtSettings.reportHeader : '';
		var reportFooter = ( d5dtSettings && d5dtSettings.reportFooter ) ? d5dtSettings.reportFooter : 'D5 Design System Helper';
		var now = new Date().toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
		var win = window.open( '', '_blank', 'width=900,height=750,scrollbars=yes' );
		if ( ! win ) { return; }

		var pageMargin = margins.top + 'in ' + margins.right + 'in ' + margins.bottom + 'in ' + margins.left + 'in';

		win.document.write(
			'<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
			+ '<title>' + escHtml( docTitle ) + '</title>'
			+ '<style>'
			+ '*, *::before, *::after { box-sizing: border-box; }'
			+ 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #333; margin: 0; padding: 20px; }'
			+ '@media print {'
			+ '  body { padding: 0; }'
			+ '  .no-print { display: none !important; }'
			+ '  @page { size: A4 ' + orientation + '; margin: ' + pageMargin + '; }'
			+ '  .print-footer { position: fixed; bottom: 0; left: 0; right: 0; height: 30px; display: flex; justify-content: space-between; align-items: center; padding: 0 0.75in; font-size: 8pt; color: #666; background: white; border-top: 1px solid #eee; }'
			+ '  h2, h3 { page-break-after: avoid; }'
			+ '  table { page-break-inside: avoid; }'
			+ '  .page-number::after { content: counter(page); }'
			+ '  .page-total::after { content: counter(pages); }'
			+ '  tr:nth-child(even) { background: #f9fafb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
			+ '}'
			+ '@media screen {'
			+ '  .print-footer { display: none; }'
			+ '  .print-btn-bar { position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000; }'
			+ '  .print-btn { padding: 8px 18px; background: #2563eb; color: white; border: none; border-radius: 5px; font-size: 13px; cursor: pointer; }'
			+ '  .print-btn.sec { background: #6b7280; }'
			+ '  .preview { max-width: 860px; margin: 60px auto 40px; background: white; box-shadow: 0 0 20px rgba(0,0,0,.1); padding: 40px; }'
			+ '}'
			+ '.doc-title { font-size: 15pt; font-weight: bold; text-align: center; margin: 0 0 4px 0; }'
			+ '.doc-date  { font-size: 10pt; text-align: center; color: #555; margin: 0 0 1.5em 0; border-bottom: 1px solid #e5e7eb; padding-bottom: 1em; }'
			+ 'table { border-collapse: collapse; width: 100%; margin: 0.5em 0 1em; font-size: 10pt; }'
			+ 'th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; vertical-align: top; }'
			+ 'th { background: #f3f4f6; font-weight: 600; }'
			+ 'code { font-family: "SF Mono", Menlo, Monaco, monospace; font-size: 9pt; background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }'
			+ 'h2 { font-size: 13pt; margin: 1.2em 0 0.4em; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }'
			+ 'h3 { font-size: 11pt; margin: 1em 0 0.3em; color: #374151; }'
			+ '.error { color: #dc2626; } .warning { color: #b45309; } .success { color: #166534; }'
			+ '</style></head><body>'
			+ '<div class="print-btn-bar no-print">'
			+ '<button class="print-btn" onclick="window.print()">Print / Save PDF</button>'
			+ '<button class="print-btn sec" onclick="window.close()">Close</button>'
			+ '</div>'
			+ '<div class="preview">'
			+ ( reportHeader ? '<p class="doc-title">' + escHtml( reportHeader ) + '</p>' : '' )
			+ '<p class="doc-date">' + escHtml( docTitle ) + ' &mdash; ' + escHtml( now ) + '</p>'
			+ bodyHtml
			+ '</div>'
			+ '<div class="print-footer">'
			+ '<span>' + escHtml( reportFooter ) + '</span>'
			+ '<span>Page <span class="page-number"></span> of <span class="page-total"></span></span>'
			+ '</div>'
			+ '</body></html>'
		);
		win.document.close();
	}

	/**
	 * Build a popup print window for the Variables table.
	 * Reads visible rows directly from the DOM so it works regardless of
	 * which section is currently active in the Manage tab.
	 */
	function executePrint( hiddenCols, orientation, margins, visibleCols ) {
		var COL_LABELS = { order: '#', type: 'Type', id: 'ID', label: 'Label', swatch: 'Swatch', value: 'Value', status: 'Status' };
		var ALL_COLS   = [ 'order', 'type', 'id', 'label', 'swatch', 'value', 'status' ];
		var hidden     = hiddenCols || [];

		// Read which variable types to include (null = all types).
		var enabledTypes = getPrintEnabledTypes();

		// Calculate proportional % widths from visible px values.
		var totalPx = ( visibleCols || [] ).reduce( function ( s, c ) { return s + ( c.px || 0 ); }, 0 );
		var pctMap  = {};
		if ( totalPx > 0 ) {
			( visibleCols || [] ).forEach( function ( c ) {
				pctMap[ c.value ] = Math.round( ( c.px / totalPx ) * 100 );
			} );
		}

		// Build thead.
		var thead = '<thead><tr>';
		ALL_COLS.forEach( function ( col ) {
			if ( hidden.indexOf( col ) !== -1 ) { return; }
			var w = pctMap[ col ] ? ' style="width:' + pctMap[ col ] + '%"' : '';
			var align = ( col === 'order' ) ? ' style="text-align:center;' + ( pctMap[ col ] ? 'width:' + pctMap[ col ] + '%' : '' ) + '"' : w;
			thead += '<th' + align + '>' + escHtml( COL_LABELS[ col ] || col ) + '</th>';
		} );
		thead += '</tr></thead>';

		// Pull rows from live DOM, filtering by enabled Variable types if needed.
		var tbody    = '';
		var tbodyEl  = document.getElementById( 'd5dsh-manage-tbody' );
		var rows     = tbodyEl ? tbodyEl.querySelectorAll( 'tr' ) : [];
		rows.forEach( function ( tr ) {
			var cells = tr.querySelectorAll( 'td' );
			if ( ! cells.length ) { return; }

			// Skip row if its Variable type is not in the enabled list.
			if ( enabledTypes !== null ) {
				var rowType = tr.dataset.type || '';
				if ( enabledTypes.indexOf( rowType ) === -1 ) { return; }
			}

			tbody += '<tr>';
			ALL_COLS.forEach( function ( col, i ) {
				if ( hidden.indexOf( col ) !== -1 ) { return; }
				var cell = cells[ i ];
				var text = cell ? ( cell.dataset.value || cell.textContent || '' ).trim() : '';
				var align = ( col === 'order' ) ? ' style="text-align:center"' : '';
				tbody += '<td' + align + '>' + escHtml( text ) + '</td>';
			} );
			tbody += '</tr>';
		} );

		var bodyHtml = '<table>' + thead + '<tbody>' + tbody + '</tbody></table>';
		openPrintWindow( bodyHtml, 'Variables', orientation, margins );
	}

	/**
	 * Return 1-based column index for a named column (used for nth-child hiding).
	 * Must match the column order in AdminPage.php thead.
	 */
	function getColIndex( col ) {
		var ORDER = [ 'order', 'type', 'id', 'label', 'swatch', 'value', 'status' ];
		return ORDER.indexOf( col ) + 1; // 1-based
	}

	// ── Print / CSV export ───────────────────────────────────────────────────

	function exportManageCsv() {
		if ( ! manageData ) { return; }
		var rows  = getDisplayRows();
		var TYPE_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };

		var csvRows = [];
		csv_rows_push( csvRows, [ '#', 'Type', 'ID', 'Label', 'Value', 'Status' ] );
		rows.forEach( function ( item, idx ) {
			var typeText = item.type === 'global_color' ? 'Global Color' : ( TYPE_LABELS[ item.type ] || item.type || '' );
			var csvValue = item.value || '';
			// For images: show filename instead of base64 blob bytes.
			if ( item.type === 'images' && csvValue ) {
				if ( csvValue.indexOf( 'data:' ) === 0 ) {
					csvValue = '[embedded image]';
				} else {
					var parts = csvValue.split( '/' );
					csvValue = parts[ parts.length - 1 ] || csvValue;
				}
			}
			csv_rows_push( csvRows, [
				String( item.order != null ? item.order : idx + 1 ),
				typeText,
				item.id || '',
				item.label || '',
				csvValue,
				item.status || '',
			] );
		} );

		var csvStr  = csvRows.join( '\n' );
		var blob    = new Blob( [ csvStr ], { type: 'text/csv;charset=utf-8;' } );
		var url     = URL.createObjectURL( blob );
		var a       = document.createElement( 'a' );
		a.href      = url;
		var now     = new Date();
		var pad     = function ( n ) { return String( n ).padStart( 2, '0' ); };
		var ts      = now.getFullYear() + '-' + pad( now.getMonth() + 1 ) + '-' + pad( now.getDate() )
		            + '_' + pad( now.getHours() ) + '.' + pad( now.getMinutes() );
		var host    = window.location.hostname.replace( /^www\./, '' );
		a.download  = 'd5dsh-vars-' + host + '-' + ts + '.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	function csv_rows_push( rows, fields ) {
		var line = fields.map( function ( f ) {
			var s = String( f ).replace( /"/g, '""' );
			if ( s.indexOf( ',' ) !== -1 || s.indexOf( '"' ) !== -1 || s.indexOf( '\n' ) !== -1 ) {
				s = '"' + s + '"';
			}
			return s;
		} ).join( ',' );
		rows.push( line );
	}

	// ── Column filters ────────────────────────────────────────────────────────
	//
	// Click-to-expand panel per filterable column header.
	// Filterable columns: Type, ID, Label, Value, Status.
	// Filter modes: contains | equals | starts_with | is_empty.
	//
	// State stored in colFilters: { colKey: { mode, value } }

	var colFilters = {};        // { type: {mode,val}, id: {mode,val}, label: {mode,val}, value: {mode,val}, status: {mode,val} }
	var colSort    = { key: null, dir: 'asc' }; // current sort: { key: 'id'|'label'|..., dir: 'asc'|'desc' }
	var activeFilterCol = null; // which dropdown is currently open

	var FILTER_COLS = [
		{ key: 'type',   label: 'Type'   },
		{ key: 'id',     label: 'ID'     },
		{ key: 'label',  label: 'Label'  },
		{ key: 'value',  label: 'Value'  },
		{ key: 'status', label: 'Status' },
	];

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 9 — COLUMN FILTERS  (shared openFilterPanel)                 ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initColumnFilters() {
		var table = document.getElementById( 'd5dsh-manage-table' );
		if ( ! table ) { return; }

		// Observe table visibility (it starts hidden) so we can wire up TH clicks after it shows.
		var observer = new MutationObserver( function () {
			if ( table.style.display !== 'none' ) {
				observer.disconnect();
				wireFilterHeaders();
			}
		} );
		observer.observe( table, { attributes: true, attributeFilter: [ 'style' ] } );

		// Also close any open filter panel on outside click.
		document.addEventListener( 'click', function ( e ) {
			if ( activeFilterCol && ! e.target.closest( '.d5dsh-col-filter-panel' ) && ! e.target.closest( 'th[data-filter-col]' ) ) {
				closeAllFilterPanels();
			}
			if ( activeCatFilterCol && ! e.target.closest( '.d5dsh-col-filter-panel' ) && ! e.target.closest( 'th[data-filter-col]' ) ) {
				closeAllCatFilterPanels();
			}
			// Close the category checkbox panel on outside click.
			var cbPanel = document.getElementById( 'd5dsh-cat-checkbox-panel' );
			if ( cbPanel && ! cbPanel.contains( e.target ) && ! e.target.closest( '.d5dsh-cat-assign-trigger' ) ) {
				cbPanel.remove();
			}
		} );
	}

	function wireFilterHeaders() {
		var ths = document.querySelectorAll( '#d5dsh-manage-table thead th[data-filter-col]' );
		ths.forEach( function ( th ) {
			th.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				// Let the expand-toggle icon handle its own click without opening the filter panel.
				if ( e.target.closest( '.d5dsh-col-expand-toggle' ) ) { return; }
				var col = th.dataset.filterCol;
				if ( activeFilterCol === col ) {
					closeAllFilterPanels();
				} else {
					activeFilterCol = col;
					openFilterPanel( {
						th:           th,
						col:          col,
						filtersObj:   colFilters,
						sortObj:      colSort,
						getValues:    getDistinctValues,
						closeAll:     closeAllFilterPanels,
						scrollWrapId: 'd5dsh-manage-table-wrap',
						onApply: function () {
							updateFilterIndicators();
							updateClearAllVisibility();
							renderTable();
						},
						onClear: function () {
							updateFilterIndicators();
							updateClearAllVisibility();
							renderTable();
						},
						onSort: function () {
							updateFilterIndicators();
							renderTable();
						},
					} );
				}
			} );
		} );
	}

	function openFilterPanel( cfg ) {
		// ── openFilterPanel( cfg ) ─────────────────────────────────────────────
		// Shared filter-panel builder used by the Variables table and all three
		// Presets tables. Pass a cfg object describing the calling context:
		//
		//   cfg.th           {Element}  The <th> that was clicked.
		//   cfg.col          {string}   Column key, e.g. 'type', 'group_name'.
		//   cfg.filtersObj   {object}   Read/write filter state (colFilters or presetsFiltersXX).
		//   cfg.sortObj      {object}   Read/write sort state  (colSort or presetsSortXX).
		//   cfg.getValues    {function} fn(col) → string[]  distinct checklist values.
		//   cfg.onApply      {function} Called after filter is applied; should re-render + update indicators.
		//   cfg.onClear      {function} Called after filter is cleared; should re-render + update indicators.
		//   cfg.onSort       {function} Called after sort is applied; should re-render.
		//   cfg.closeAll     {function} Close all panels for this context.
		//   cfg.scrollWrapId {string}   (optional) ID of the scroll container to track on scroll.
		//
		// New tables can snap in by supplying their own cfg — no changes to this function needed.

		var th         = cfg.th;
		var col        = cfg.col;
		var filtersObj = cfg.filtersObj;
		var sortObj    = cfg.sortObj;
		var closeAll   = cfg.closeAll;

		closeAll();
		th.classList.add( 'd5dsh-col-filter-active' );

		var existing       = filtersObj[ col ] || {};
		var existingSearch = ( existing.mode === 'contains' || existing.mode === 'starts_with' || existing.mode === 'equals' ) ? ( existing.val || '' ) : '';

		var panel = document.createElement( 'div' );
		panel.className   = 'd5dsh-col-filter-panel';
		panel.dataset.col = col;
		// Prevent clicks inside from bubbling to the document close handler.
		panel.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );

		// ── Drag handle ──────────────────────────────────────────────────────────
		var dragHandle = document.createElement( 'div' );
		dragHandle.className = 'd5dsh-fp-drag-handle';
		var dragTitle = document.createElement( 'span' );
		dragTitle.className   = 'd5dsh-fp-drag-handle-title';
		// Title-case the column key, replacing underscores with spaces.
		dragTitle.textContent = col.replace( /_/g, ' ' ).replace( /\b\w/g, function ( c ) { return c.toUpperCase(); } );
		var dragClose = document.createElement( 'button' );
		dragClose.type      = 'button';
		dragClose.className = 'd5dsh-fp-drag-close';
		dragClose.innerHTML = '&times;';
		dragClose.addEventListener( 'click', function () { closeAll(); } );
		dragHandle.appendChild( dragTitle );
		dragHandle.appendChild( dragClose );
		panel.appendChild( dragHandle );

		// Draggable panel.
		dragHandle.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === dragClose ) { return; }
			e.preventDefault();
			var startX = e.clientX - parseInt( panel.style.left || 0, 10 );
			var startY = e.clientY - parseInt( panel.style.top  || 0, 10 );
			function onMove( mv ) {
				panel.style.left = ( mv.clientX - startX ) + 'px';
				panel.style.top  = ( mv.clientY - startY ) + 'px';
			}
			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup',   onUp   );
			}
			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup',   onUp   );
		} );

		// ── Sort section ─────────────────────────────────────────────────────────
		var sortSection = document.createElement( 'div' );
		sortSection.className = 'd5dsh-fp-sort-section';
		var sortRow = document.createElement( 'div' );
		sortRow.className = 'd5dsh-filter-sort-row';

		function makeSortBtn( label, dir ) {
			var btn = document.createElement( 'button' );
			btn.type      = 'button';
			btn.className = 'd5dsh-sort-btn' + ( sortObj.key === col && sortObj.dir === dir ? ' d5dsh-sort-active' : '' );
			btn.innerHTML = label;
			btn.addEventListener( 'click', function () {
				sortObj.key = col; sortObj.dir = dir;
				closeAll();
				cfg.onSort();
			} );
			return btn;
		}
		sortRow.appendChild( makeSortBtn( '&#8593; Ascending',  'asc'  ) );
		sortRow.appendChild( makeSortBtn( '&#8595; Descending', 'desc' ) );

		if ( sortObj.key === col ) {
			var sortClear = document.createElement( 'button' );
			sortClear.type      = 'button';
			sortClear.className = 'd5dsh-sort-clear-btn';
			sortClear.textContent = '\u00d7 Clear sort';
			sortClear.addEventListener( 'click', function () {
				sortObj.key = null; sortObj.dir = 'asc';
				closeAll();
				cfg.onSort();
			} );
			sortRow.appendChild( sortClear );
		}

		sortSection.appendChild( sortRow );
		panel.appendChild( sortSection );

		// ── Separator ────────────────────────────────────────────────────────────
		var sep = document.createElement( 'div' );
		sep.className = 'd5dsh-fp-sort-filter-sep';
		panel.appendChild( sep );

		// ── Filter section (checklist + search + select-all) ─────────────────────
		var filterSection = document.createElement( 'div' );
		filterSection.className = 'd5dsh-fp-filter-section';
		var filterLabel = document.createElement( 'div' );
		filterLabel.className   = 'd5dsh-fp-section-label';
		filterLabel.textContent = 'Filter';
		filterSection.appendChild( filterLabel );

		var allVals    = cfg.getValues( col );
		var activeVals = existing.mode === 'checklist' ? existing.vals : null;

		var searchInput = document.createElement( 'input' );
		searchInput.type        = 'text';
		searchInput.className   = 'd5dsh-filter-search';
		searchInput.placeholder = 'Search\u2026';
		searchInput.value       = existingSearch;
		filterSection.appendChild( searchInput );

		var selectAllRow = document.createElement( 'label' );
		selectAllRow.className = 'd5dsh-fp-checklist-row d5dsh-fp-select-all';
		var selectAllCb = document.createElement( 'input' );
		selectAllCb.type    = 'checkbox';
		selectAllCb.checked = ! activeVals;
		selectAllRow.appendChild( selectAllCb );
		selectAllRow.appendChild( document.createTextNode( ' (Select All)' ) );
		filterSection.appendChild( selectAllRow );

		var listWrap = document.createElement( 'div' );
		listWrap.className = 'd5dsh-fp-checklist';
		var itemCheckboxes = [];
		allVals.forEach( function ( val ) {
			var rowEl = document.createElement( 'label' );
			rowEl.className = 'd5dsh-fp-checklist-row';
			var cb = document.createElement( 'input' );
			cb.type    = 'checkbox';
			cb.value   = val;
			cb.checked = ! activeVals || activeVals.has( val );
			rowEl.appendChild( cb );
			rowEl.appendChild( document.createTextNode( ' ' + val ) );
			listWrap.appendChild( rowEl );
			itemCheckboxes.push( cb );
		} );

		var pendingVals = activeVals ? new Set( activeVals ) : null;

		function syncSelectAll() {
			var allChecked = itemCheckboxes.every( function ( c ) { return c.checked; } );
			selectAllCb.checked       = allChecked;
			selectAllCb.indeterminate = ! allChecked && itemCheckboxes.some( function ( c ) { return c.checked; } );
			pendingVals = allChecked ? null : new Set(
				itemCheckboxes.filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } )
			);
		}

		itemCheckboxes.forEach( function ( cb ) { cb.addEventListener( 'change', syncSelectAll ); } );

		selectAllCb.addEventListener( 'change', function () {
			itemCheckboxes.forEach( function ( cb ) {
				if ( cb.closest( 'label' ).style.display !== 'none' ) { cb.checked = selectAllCb.checked; }
			} );
			syncSelectAll();
		} );

		searchInput.addEventListener( 'input', function () {
			var q = searchInput.value.toLowerCase();
			itemCheckboxes.forEach( function ( cb ) {
				cb.closest( 'label' ).style.display = ( ! q || cb.value.toLowerCase().indexOf( q ) !== -1 ) ? '' : 'none';
			} );
		} );

		filterSection.appendChild( listWrap );
		panel.appendChild( filterSection );

		// ── Apply / Clear buttons ────────────────────────────────────────────────
		var btnRow = document.createElement( 'div' );
		btnRow.className = 'd5dsh-fp-btn-row';

		var applyBtn = document.createElement( 'button' );
		applyBtn.type      = 'button';
		applyBtn.className = 'button button-small d5dsh-fp-apply-btn';
		applyBtn.textContent = 'Apply Filter';
		applyBtn.addEventListener( 'click', function () {
			var searchTerm = searchInput.value.trim();
			if ( searchTerm && pendingVals === null ) {
				// Text typed but nothing unchecked — treat as "contains" text filter.
				filtersObj[ col ] = { mode: 'contains', val: searchTerm };
			} else if ( pendingVals === null ) {
				// All checked, no search — remove filter.
				delete filtersObj[ col ];
			} else {
				filtersObj[ col ] = { mode: 'checklist', vals: pendingVals };
			}
			closeAll();
			cfg.onApply();
		} );

		var clearBtn = document.createElement( 'button' );
		clearBtn.type      = 'button';
		clearBtn.className = 'button-link d5dsh-fp-clear-btn';
		clearBtn.textContent = 'Clear Filter';
		clearBtn.addEventListener( 'click', function () {
			delete filtersObj[ col ];
			closeAll();
			cfg.onClear();
		} );

		btnRow.appendChild( applyBtn );
		btnRow.appendChild( clearBtn );
		panel.appendChild( btnRow );

		// ── Position & attach ────────────────────────────────────────────────────
		// Attach to document.body so the panel is never clipped by overflow:auto
		// scroll containers or mis-positioned by a sticky <th>.
		var rect = th.getBoundingClientRect();
		panel.style.position = 'fixed';
		panel.style.top      = rect.bottom + 'px';
		panel.style.left     = rect.left   + 'px';
		panel.style.zIndex   = '99999';
		// Default width on every open — resets any previous resize.
		var vpW = window.innerWidth || document.documentElement.clientWidth;
		panel.style.width = Math.min( 360, vpW - 24 ) + 'px';
		document.body.appendChild( panel );

		// Reposition on scroll / resize so the panel tracks the header.
		function repositionPanel() {
			var r = th.getBoundingClientRect();
			panel.style.top  = r.bottom + 'px';
			panel.style.left = r.left   + 'px';
		}
		var scrollEl = cfg.scrollWrapId ? document.getElementById( cfg.scrollWrapId ) : null;
		if ( scrollEl ) { scrollEl.addEventListener( 'scroll', repositionPanel ); }
		window.addEventListener( 'resize', repositionPanel );
		panel._cleanup = function () {
			if ( scrollEl ) { scrollEl.removeEventListener( 'scroll', repositionPanel ); }
			window.removeEventListener( 'resize', repositionPanel );
		};
	}

	function closeAllFilterPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) {
			if ( p._cleanup ) { p._cleanup(); }
			p.parentNode.removeChild( p );
		} );
		document.querySelectorAll( 'th.d5dsh-col-filter-active' ).forEach( function ( th ) { th.classList.remove( 'd5dsh-col-filter-active' ); } );
		activeFilterCol = null;
	}

	function applyColFilter( col, mode, val ) {
		colFilters[ col ] = { mode: mode, val: val };
		updateFilterIndicators();
		renderTable();
	}

	function updateFilterIndicators() {
		FILTER_COLS.forEach( function ( fc ) {
			var th = document.querySelector( '#d5dsh-manage-table thead th[data-filter-col="' + fc.key + '"]' );
			if ( ! th ) { return; }
			var f = colFilters[ fc.key ];
			var hasFilter = false;
			if ( f ) {
				if ( f.mode === 'checklist' ) { hasFilter = !! ( f.vals && f.vals.size > 0 ); }
				else { hasFilter = f.mode === 'is_empty' || !! f.val; }
			}
			var hasSorted = colSort.key === fc.key;
			th.classList.toggle( 'd5dsh-col-filtered', hasFilter );
			th.classList.toggle( 'd5dsh-col-sorted', hasSorted );
		} );
		updateClearAllVisibility();
		saveManageState();
	}

	/**
	 * Return sorted distinct display values for a given column key.
	 * Used to populate the checklist filter for Type and Status columns.
	 */
	function getDistinctValues( col ) {
		if ( ! manageData ) { return []; }
		var TYPE_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
		// Use rows visible under all OTHER active filters (not this column's own filter)
		// so the checklist reflects what is actually on screen.
		var otherFilters = {};
		Object.keys( colFilters ).forEach( function ( k ) {
			if ( k !== col ) { otherFilters[ k ] = colFilters[ k ]; }
		} );
		var savedFilters = colFilters;
		colFilters = otherFilters;
		var visibleRows = getDisplayRows();
		colFilters = savedFilters;

		var seen = {};
		visibleRows.forEach( function ( item ) {
			var val;
			if ( col === 'type' ) {
				val = item.type === 'global_color' ? 'Global Color' : ( TYPE_LABELS[ item.type ] || item.type || '' );
			} else if ( col === 'status' ) {
				val = item.status || '';
			} else {
				val = ( item[ col ] || '' ).toString();
			}
			if ( val ) { seen[ val ] = true; }
		} );
		return Object.keys( seen ).sort();
	}

	function passesColFilters( item ) {
		var isGcRow = item.type === 'global_color';
		var TYPE_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
		var fields = {
			type:   isGcRow ? 'Global Color' : ( TYPE_LABELS[ item.type ] || item.type || '' ),
			id:     item.id || '',
			label:  item.label || '',
			value:  item.value || '',
			status: item.status || '',
		};
		return Object.keys( colFilters ).every( function ( col ) {
			var f = colFilters[ col ];
			if ( ! f ) { return true; }
			if ( f.mode === 'checklist' ) {
				if ( ! f.vals || f.vals.size === 0 ) { return false; }
				return f.vals.has( fields[ col ] );
			}
			var haystack = ( fields[ col ] || '' ).toLowerCase();
			var needle   = ( f.val || '' ).toLowerCase();
			if ( f.mode === 'is_empty'   ) { return haystack === ''; }
			if ( f.mode === 'equals'     ) { return haystack === needle; }
			if ( f.mode === 'starts_with') { return haystack.startsWith( needle ); }
			return haystack.indexOf( needle ) !== -1; // contains (default)
		} );
	}

	// ── Section switcher ─────────────────────────────────────────────────────
	//
	// Manages which content panel is visible inside the Manage tab.
	// Sections: 'variables' | 'group_presets' | 'element_presets' | 'all_presets' | 'everything'
	// Presets data is loaded lazily on first visit to any presets section.
	// The 'everything' section requires BOTH manageData (vars) AND presetsData to be loaded.

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 12 — PRESETS: CORE                                           ║
	// ╚══════════════════════════════════════════════════════════════════╝
	var SECTION_STATE_KEY   = 'd5dsh_manage_section';
	var currentSection      = 'variables';
	var presetsDataLoaded   = false;  // true once presets AJAX has succeeded
	var presetsData         = null;   // { element_presets: [...], group_presets: [...] }
	var presetsOriginal     = null;   // deep-clone for dirty tracking
	var presetsDirtyEP      = {};     // { preset_id: { ...changed row } }
	var presetsDirtyGP      = {};     // { preset_id: { ...changed row } }

	// Per-presets-table sort/filter state (mirrors colSort/colFilters for variables).
	var presetsSortGP    = { key: null, dir: 'asc' };
	var presetsFiltersGP = {};
	var presetsSortEP    = { key: null, dir: 'asc' };
	var presetsFiltersEP = {};
	var presetsSortAll   = { key: null, dir: 'asc' };
	var presetsFiltersAll = {};
	var presetsSortEverything    = { key: null, dir: 'asc' };
	var presetsFiltersEverything = {};

	/**
	 * Resize all visible presets table wraps to fill to viewport bottom.
	 * Mirrors resizeTableWrap() for the variables table.
	 */
	function resizePresetsWraps() {
		[ 'd5dsh-presets-gp-table-wrap',
		  'd5dsh-presets-ep-table-wrap',
		  'd5dsh-presets-all-table-wrap',
		  'd5dsh-everything-table-wrap',
		  'd5dsh-cat-assign-wrap' ].forEach( function ( id ) {
			var wrap = document.getElementById( id );
			if ( ! wrap ) { return; }
			// Only size wraps that are currently visible.
			if ( wrap.offsetParent === null ) { return; }
			var rect    = wrap.getBoundingClientRect();
			var padding = 16;
			var height  = window.innerHeight - rect.top - padding;
			// Minimum: ~6 data rows visible (~32px each) + header (~36px) ≈ 228px.
			if ( height < 228 ) { height = 228; }
			wrap.style.height = height + 'px';
		} );
	}

	function initSectionSwitcher() {
		// Restore last section from sessionStorage.
		try {
			var saved = sessionStorage.getItem( SECTION_STATE_KEY );
			if ( saved ) { currentSection = saved; }
		} catch(e) {}

		// Wire buttons.
		document.querySelectorAll( '.d5dsh-section-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				switchSection( btn.dataset.section );
			} );
		} );

		// Activate saved section.
		activateSection( currentSection );
	}

	function switchSection( section ) {
		currentSection = section;
		try { sessionStorage.setItem( SECTION_STATE_KEY, section ); } catch(e) {}
		// Close any open filter or category panels before switching.
		closeAllFilterPanels();
		closeAllCatFilterPanels();
		var cbPanel = document.getElementById( 'd5dsh-cat-checkbox-panel' );
		if ( cbPanel ) { cbPanel.remove(); }
		activateSection( section );
	}

	function activateSection( section ) {
		// Update button active states.
		document.querySelectorAll( '.d5dsh-section-btn' ).forEach( function ( btn ) {
			btn.classList.toggle( 'd5dsh-section-active', btn.dataset.section === section );
		} );

		// Show/hide sections.
		document.querySelectorAll( '.d5dsh-manage-section' ).forEach( function ( div ) {
			div.style.display = 'none';
		} );
		var activeDiv = document.getElementById( 'd5dsh-section-' + section );
		if ( activeDiv ) { activeDiv.style.display = ''; }

		// Lazy-load presets on first visit to any presets section (including Everything).
		if ( section !== 'variables' && ! presetsDataLoaded ) {
			loadPresetsData();
		}

		// Everything section also needs var data — trigger var load if not yet done.
		if ( section === 'everything' && ! manageData ) {
			loadManageData();
		}

		// Re-size any now-visible table wrap to fill the viewport.
		if ( section !== 'variables' ) {
			setTimeout( resizePresetsWraps, 0 );
		}
		// Recalculate Tabulator height for the newly visible section.
		setTimeout( function () {
			if ( section === 'variables'      && window.d5dshVarsTable        ) { window.d5dshVarsTable.recalcHeight(); }
			if ( section === 'group_presets'   && window.d5dshPresetsGpTable  ) { window.d5dshPresetsGpTable.recalcHeight(); }
			if ( section === 'element_presets' && window.d5dshPresetsEpTable  ) { window.d5dshPresetsEpTable.recalcHeight(); }
			if ( section === 'all_presets'     && window.d5dshPresetsAllTable ) { window.d5dshPresetsAllTable.recalcHeight(); }
			if ( section === 'everything'      && window.d5dshEverythingTable ) { window.d5dshEverythingTable.recalcHeight(); }
		}, 0 );
	}

	// ── Presets: module abbreviation ─────────────────────────────────────────

	/**
	 * Return a shortened module name for display:
	 *   divi/button         → button
	 *   divi/woocommerce-*  → woo/…
	 *   woocommerce/product → woo/product
	 *
	 * The original full name is returned as the tooltip (title attribute).
	 *
	 * @param  {string} moduleName
	 * @return {{ short: string, full: string }}
	 */
	function abbreviateModule( moduleName ) {
		if ( ! moduleName ) { return { short: '', full: '' }; }
		var full  = moduleName;
		var short = moduleName;

		// Strip 'divi/' prefix.
		short = short.replace( /^divi\//, '' );

		// Map 'woocommerce' (as segment) to 'woo'.
		short = short.replace( /\bwoocommerce\b/g, 'woo' );

		return { short: short, full: full };
	}

	// ── Presets: data loading ─────────────────────────────────────────────────

	function loadPresetsData() {
		if ( typeof d5dtPresets === 'undefined' ) { return; }

		// Show loading spinners in all presets sections (including Everything).
		[ 'gp', 'ep', 'all' ].forEach( function ( suffix ) {
			var loading = document.getElementById( 'd5dsh-presets-' + suffix + '-loading' );
			var table   = document.getElementById( 'd5dsh-presets-' + suffix + '-table' );
			var filter  = document.getElementById( 'd5dsh-presets-' + suffix + '-filter-bar' );
			if ( loading ) { loading.style.display = 'block'; }
			if ( table  ) { table.style.display   = 'none'; }
			if ( filter ) { filter.style.display  = 'none'; }
		} );
		var evLoad = document.getElementById( 'd5dsh-everything-loading' );
		var evTbl  = document.getElementById( 'd5dsh-everything-table' );
		var evFlt  = document.getElementById( 'd5dsh-everything-filter-bar' );
		if ( evLoad ) { evLoad.style.display = 'block'; }
		if ( evTbl  ) { evTbl.style.display  = 'none'; }
		if ( evFlt  ) { evFlt.style.display  = 'none'; }

		var fd = new FormData();
		fd.append( 'action', 'd5dsh_presets_manage_load' );
		fd.append( 'nonce',  d5dtPresets.nonce );

		fetch( d5dtPresets.ajaxUrl, { method: 'POST', body: fd } )
			.then( function (res) { return res.json(); } )
			.then( function (json) {
				if ( ! json.success ) {
					showPresetsError( json.data ? json.data.message : 'Load failed.' );
					return;
				}
				presetsData     = json.data;
				presetsOriginal = deepClone( presetsData );
				presetsDataLoaded = true;
				presetsDirtyEP  = {};
				presetsDirtyGP  = {};

				// Hide loading, show tables/filters.
				[ 'gp', 'ep', 'all' ].forEach( function ( suffix ) {
					var loading = document.getElementById( 'd5dsh-presets-' + suffix + '-loading' );
					if ( loading ) { loading.style.display = 'none'; }
				} );
				var evLoadDone = document.getElementById( 'd5dsh-everything-loading' );
				if ( evLoadDone ) { evLoadDone.style.display = 'none'; }

				renderPresetsTable( 'gp' );
				renderPresetsTable( 'ep' );
				renderPresetsTable( 'all' );
				renderEverythingTable();

				renderCategoryAssignTable();
				// Wire up controls now that we have data.
				initPresetsControls();

				// Size the scroll wraps to fill the viewport, then smart-size columns.
				// Defer until browser has reflowed the newly-visible tables.
				requestAnimationFrame( function() {
					resizePresetsWraps();
					// flex col: gp=3 (Label), ep=3 (Label), all=4 (Label), everything=5 (Label)
					// Column widths are set by initTableColumns from data- attributes on <th>.
				} );


			} )
			.catch( function (err) {
				showPresetsError( 'Request failed: ' + err.message );
			} );
	}

	function showPresetsError( msg ) {
		[ 'gp', 'ep', 'all' ].forEach( function ( suffix ) {
			var loading = document.getElementById( 'd5dsh-presets-' + suffix + '-loading' );
			var errEl   = document.getElementById( 'd5dsh-presets-' + suffix + '-error' );
			if ( loading ) { loading.style.display = 'none'; }
			if ( errEl  ) { errEl.textContent = msg; errEl.style.display = 'block'; }
		} );
		var evLoad = document.getElementById( 'd5dsh-everything-loading' );
		var evErr  = document.getElementById( 'd5dsh-everything-error' );
		if ( evLoad ) { evLoad.style.display = 'none'; }
		if ( evErr  ) { evErr.textContent = msg; evErr.style.display = 'block'; }
	}

	// ── Presets: control wiring ───────────────────────────────────────────────

	function initPresetsControls() {
		// Group Presets save/discard.
		var gpSave    = document.getElementById( 'd5dsh-presets-gp-save' );
		var gpDiscard = document.getElementById( 'd5dsh-presets-gp-discard' );
		if ( gpSave    ) { gpSave.addEventListener(    'click', function () { handlePresetsSave( 'gp' ); } ); }
		if ( gpDiscard ) { gpDiscard.addEventListener( 'click', function () { handlePresetsDiscard( 'gp' ); } ); }

		// Element Presets save/discard.
		var epSave    = document.getElementById( 'd5dsh-presets-ep-save' );
		var epDiscard = document.getElementById( 'd5dsh-presets-ep-discard' );
		if ( epSave    ) { epSave.addEventListener(    'click', function () { handlePresetsSave( 'ep' ); } ); }
		if ( epDiscard ) { epDiscard.addEventListener( 'click', function () { handlePresetsDiscard( 'ep' ); } ); }

		// Clear filters / reset — Group Presets.
		var gpClear = document.getElementById( 'd5dsh-presets-gp-clear-filters' );
		var gpReset = document.getElementById( 'd5dsh-presets-gp-reset-view' );
		if ( gpClear ) { gpClear.addEventListener( 'click', function () { if ( window.d5dshPresetsGpTable ) { window.d5dshPresetsGpTable.reset(); renderPresetsTable( 'gp' ); } } ); }
		if ( gpReset ) { gpReset.addEventListener( 'click', function () { if ( window.d5dshPresetsGpTable ) { window.d5dshPresetsGpTable.reset(); renderPresetsTable( 'gp' ); } } ); }

		// Clear filters / reset — Element Presets.
		var epClear = document.getElementById( 'd5dsh-presets-ep-clear-filters' );
		var epReset = document.getElementById( 'd5dsh-presets-ep-reset-view' );
		if ( epClear ) { epClear.addEventListener( 'click', function () { if ( window.d5dshPresetsEpTable ) { window.d5dshPresetsEpTable.reset(); renderPresetsTable( 'ep' ); } } ); }
		if ( epReset ) { epReset.addEventListener( 'click', function () { if ( window.d5dshPresetsEpTable ) { window.d5dshPresetsEpTable.reset(); renderPresetsTable( 'ep' ); } } ); }

		// Clear filters / reset — All Presets.
		var allClear = document.getElementById( 'd5dsh-presets-all-clear-filters' );
		var allReset = document.getElementById( 'd5dsh-presets-all-reset-view' );
		if ( allClear ) { allClear.addEventListener( 'click', function () { if ( window.d5dshPresetsAllTable ) { window.d5dshPresetsAllTable.reset(); renderPresetsTable( 'all' ); } } ); }
		if ( allReset ) { allReset.addEventListener( 'click', function () { if ( window.d5dshPresetsAllTable ) { window.d5dshPresetsAllTable.reset(); renderPresetsTable( 'all' ); } } ); }

		// Clear filters / reset — Everything.
		var evClear = document.getElementById( 'd5dsh-everything-clear-filters' );
		var evReset = document.getElementById( 'd5dsh-everything-reset-view' );
		if ( evClear ) { evClear.addEventListener( 'click', function () { if ( window.d5dshEverythingTable ) { window.d5dshEverythingTable.reset(); renderEverythingTable(); } } ); }
		if ( evReset ) { evReset.addEventListener( 'click', function () { if ( window.d5dshEverythingTable ) { window.d5dshEverythingTable.reset(); renderEverythingTable(); } } ); }

		// Column header filter panel (same UX as Variables table).
		wirePresetsFilterHeaders( 'gp' );
		wirePresetsFilterHeaders( 'ep' );
		wirePresetsFilterHeaders( 'all' );
		wirePresetsFilterHeaders( 'everything' );

		// Close filter panels on outside click.
		document.addEventListener( 'click', function ( e ) {
			if ( activePresetsFilterCol && ! e.target.closest( '.d5dsh-col-filter-panel' ) && ! e.target.closest( 'th[data-filter-col]' ) ) {
				closeAllPresetsFilterPanels();
			}
		} );

		// CSV export buttons.
		var gpCsv  = document.getElementById( 'd5dsh-presets-gp-export-csv' );
		var epCsv  = document.getElementById( 'd5dsh-presets-ep-export-csv' );
		var allCsv = document.getElementById( 'd5dsh-presets-all-export-csv' );
		var evCsv  = document.getElementById( 'd5dsh-everything-export-csv' );
		if ( gpCsv  ) { gpCsv.addEventListener(  'click', function () { openExportCsvSetup( 'gp'  ); } ); }
		if ( epCsv  ) { epCsv.addEventListener(  'click', function () { openExportCsvSetup( 'ep'  ); } ); }
		if ( allCsv ) { allCsv.addEventListener( 'click', function () { openExportCsvSetup( 'all' ); } ); }
		if ( evCsv  ) { evCsv.addEventListener(  'click', function () { openExportCsvSetup( 'everything' ); } ); }

		// Excel export buttons — all presets sections share the same XLSX endpoint.
		function presetsXlsxDownload( btn ) {
			if ( typeof d5dtPresets === 'undefined' || ! d5dtPresets.xlsxAction ) { return; }
			btn.disabled    = true;
			btn.textContent = 'Generating…';
			var url = d5dtPresets.ajaxUrl
				+ '?action=' + encodeURIComponent( d5dtPresets.xlsxAction )
				+ '&nonce='  + encodeURIComponent( d5dtPresets.nonce );
			fetch( url, { method: 'POST' } )
				.then( function ( r ) {
					if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
					return r.blob();
				} )
				.then( function ( blob ) {
					var a      = document.createElement( 'a' );
					a.href     = URL.createObjectURL( blob );
					a.download = 'divi5-presets.xlsx';
					a.click();
					URL.revokeObjectURL( a.href );
				} )
				.catch( function ( err ) {
					showToast( 'error', 'Excel download failed', err.message || 'Network error.' );
				} )
				.finally( function () {
					btn.disabled    = false;
					btn.textContent = '⬇ Excel';
				} );
		}
		var gpXlsx  = document.getElementById( 'd5dsh-presets-gp-export-xlsx' );
		var epXlsx  = document.getElementById( 'd5dsh-presets-ep-export-xlsx' );
		var allXlsx = document.getElementById( 'd5dsh-presets-all-export-xlsx' );
		var evXlsx  = document.getElementById( 'd5dsh-everything-export-xlsx' );
		if ( gpXlsx  ) { gpXlsx.addEventListener(  'click', function () { presetsXlsxDownload( gpXlsx );  } ); }
		if ( epXlsx  ) { epXlsx.addEventListener(  'click', function () { presetsXlsxDownload( epXlsx );  } ); }
		if ( allXlsx ) { allXlsx.addEventListener( 'click', function () { presetsXlsxDownload( allXlsx ); } ); }
		if ( evXlsx  ) {
			// Everything = vars + presets — download both XLSX files.
			evXlsx.addEventListener( 'click', function () {
				presetsXlsxDownload( evXlsx );
				// Also trigger vars xlsx download.
				if ( typeof d5dtManage !== 'undefined' && d5dtManage.xlsxAction ) {
					var url2 = d5dtManage.ajaxUrl
						+ '?action=' + encodeURIComponent( d5dtManage.xlsxAction )
						+ '&nonce='  + encodeURIComponent( d5dtManage.nonce );
					fetch( url2, { method: 'POST' } )
						.then( function ( r ) {
							if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
							return r.blob();
						} )
						.then( function ( blob ) {
							var a2      = document.createElement( 'a' );
							a2.href     = URL.createObjectURL( blob );
							a2.download = 'divi5-vars.xlsx';
							a2.click();
							URL.revokeObjectURL( a2.href );
						} )
						.catch( function ( err ) {
							showToast( 'error', 'Variables Excel download failed', err.message || 'Network error.' );
						} );
				}
			} );
		}

		// Print buttons — simple window.print() targeting just the active presets table.
		var gpPrint  = document.getElementById( 'd5dsh-presets-gp-print' );
		var epPrint  = document.getElementById( 'd5dsh-presets-ep-print' );
		var allPrint = document.getElementById( 'd5dsh-presets-all-print' );
		var evPrint  = document.getElementById( 'd5dsh-everything-print' );
		if ( gpPrint  ) { gpPrint.addEventListener(  'click', function () { openPresetsPrint( 'gp'  ); } ); }
		if ( epPrint  ) { epPrint.addEventListener(  'click', function () { openPresetsPrint( 'ep'  ); } ); }
		if ( allPrint ) { allPrint.addEventListener( 'click', function () { openPresetsPrint( 'all' ); } ); }
		if ( evPrint  ) { evPrint.addEventListener(  'click', function () { openEverythingPrint(); } ); }

		// Bulk label change.
		initPresetsBulk( 'gp' );
		initPresetsBulk( 'ep' );

		// Column sizing for all Manage tab tables (drag-resize + data- attribute defaults).
		// Variables table is initialized earlier (before data loads); refresh it here.
		refreshTableColumns( 'd5dsh-manage-table',      'd5dsh_col_widths_vars' );
		initTableColumns( 'd5dsh-presets-gp-table',  'd5dsh_col_widths_gp' );
		initTableColumns( 'd5dsh-presets-ep-table',  'd5dsh_col_widths_ep' );
		initTableColumns( 'd5dsh-presets-all-table', 'd5dsh_col_widths_all' );
		initTableColumns( 'd5dsh-everything-table',  'd5dsh_col_widths_everything' );
		initTableColumns( 'd5dsh-cat-assign-table',  'd5dsh_col_widths_cat' );

		// Expand/collapse toggle icons — all Manage tab tables. Independent of drag-resize.
		// Group Presets:   Group Name (2), ID (3), Label (4), Group ID (6).
		// Element Presets: Element (2), ID (3), Label (4).
		// All Presets:     Group/Element (3), Preset ID (4), Label (5), Module (6), Group ID (7).
		// Everything:      Label (5), Value (6), Element/Module (7), Group ID (8).
		// Categories:      ID (3), Label (4), Categories (5).
		initColExpandToggles( 'd5dsh-presets-gp-table',  'd5dsh_col_widths_gp',          [ 2, 3, 4, 6 ]    );
		initColExpandToggles( 'd5dsh-presets-ep-table',  'd5dsh_col_widths_ep',          [ 2, 3, 4 ]       );
		initColExpandToggles( 'd5dsh-presets-all-table', 'd5dsh_col_widths_all',         [ 3, 4, 5, 6, 7 ] );
		initColExpandToggles( 'd5dsh-everything-table',  'd5dsh_col_widths_everything',  [ 5, 6, 7, 8 ]    );
		initColExpandToggles( 'd5dsh-cat-assign-table',  'd5dsh_col_widths_cat',         [ 2, 3, 4, 6 ]    );
	}

	function togglePresetsSort( tableKey, col ) {
		var sortObj = tableKey === 'gp' ? presetsSortGP : ( tableKey === 'ep' ? presetsSortEP : ( tableKey === 'everything' ? presetsSortEverything : presetsSortAll ) );
		if ( sortObj.key === col ) {
			sortObj.dir = sortObj.dir === 'asc' ? 'desc' : 'asc';
		} else {
			sortObj.key = col;
			sortObj.dir = 'asc';
		}
	}

	function updatePresetsClearVisibility( tableKey ) {
		var filtersObj = tableKey === 'gp' ? presetsFiltersGP : ( tableKey === 'ep' ? presetsFiltersEP : ( tableKey === 'everything' ? presetsFiltersEverything : presetsFiltersAll ) );
		var sortObj    = tableKey === 'gp' ? presetsSortGP    : ( tableKey === 'ep' ? presetsSortEP    : ( tableKey === 'everything' ? presetsSortEverything    : presetsSortAll ) );
		var btnId      = tableKey === 'everything' ? 'd5dsh-everything-clear-filters' : ( 'd5dsh-presets-' + tableKey + '-clear-filters' );
		var btn = document.getElementById( btnId );
		if ( ! btn ) { return; }
		var hasActive = Object.keys( filtersObj ).length > 0 || sortObj.key !== null;
		btn.style.display = hasActive ? '' : 'none';
	}

	// ── Presets: render ───────────────────────────────────────────────────────

	/**
	 * Render one of the three presets tables.
	 *
	 * @param {'gp'|'ep'|'all'} tableKey
	 */
	function renderPresetsTable( tableKey ) {
		if ( ! presetsData ) { return; }

		// Show filter bar.
		var filterBar = document.getElementById( 'd5dsh-presets-' + tableKey + '-filter-bar' );
		if ( filterBar ) { filterBar.style.display = ''; }

		if ( tableKey === 'gp' ) {
			var rows = buildPresetsRowSet_gp();
			var mode = getPresetsMode( 'gp' );
			if ( window.d5dshPresetsGpTable ) { window.d5dshPresetsGpTable.render( rows, mode ); }
		} else if ( tableKey === 'ep' ) {
			var rows = buildPresetsRowSet_ep();
			var mode = getPresetsMode( 'ep' );
			if ( window.d5dshPresetsEpTable ) { window.d5dshPresetsEpTable.render( rows, mode ); }
		} else {
			var rows = buildPresetsRowSet_all();
			if ( window.d5dshPresetsAllTable ) { window.d5dshPresetsAllTable.render( rows ); }
		}
	}

	/**
	 * Return the display value for a presets row field, matching what the filter checklist shows.
	 */
	function presetsDisplayVal( row, col ) {
		if ( col === 'is_default' )  { return row.is_default ? 'Yes' : 'No'; }
		if ( col === 'module_name' || col === 'group_element' ) { return abbreviateModule( row[ col ] || '' ).short; }
		if ( col === 'group_name' )  { return abbreviateGroupId( row.group_name || '' ).short; }
		if ( col === 'group_id' )    { return abbreviateGroupId( row.group_id   || '' ).short; }
		return String( row[ col ] || '' );
	}

	/**
	 * Test whether a row passes all active filters for a given filters object.
	 */
	function passesPresetsFilters( row, filtersObj ) {
		return Object.keys( filtersObj ).every( function ( col ) {
			var f = filtersObj[ col ];
			if ( ! f ) { return true; }
			var display = presetsDisplayVal( row, col );
			if ( f.mode === 'checklist' ) {
				if ( ! f.vals || f.vals.size === 0 ) { return false; }
				return f.vals.has( display );
			}
			if ( f.mode === 'contains' ) {
				return display.toLowerCase().indexOf( ( f.val || '' ).toLowerCase() ) !== -1;
			}
			return true;
		} );
	}

	/**
	 * Build the flat row array for Group Presets, applying active filters.
	 */
	function buildPresetsRowSet_gp() {
		if ( ! presetsData || ! presetsData.group_presets ) { return []; }
		return presetsData.group_presets.filter( function (r) { return passesPresetsFilters( r, presetsFiltersGP ); } );
	}

	/**
	 * Build the flat row array for Element Presets, applying active filters.
	 */
	function buildPresetsRowSet_ep() {
		if ( ! presetsData || ! presetsData.element_presets ) { return []; }
		return presetsData.element_presets.filter( function (r) { return passesPresetsFilters( r, presetsFiltersEP ); } );
	}

	/**
	 * Build the merged All Presets row array.
	 * Group rows get preset_type='Group'; element rows get preset_type='Element'.
	 */
	function buildPresetsRowSet_all() {
		if ( ! presetsData ) { return []; }
		var rows = [];

		( presetsData.group_presets || [] ).forEach( function (r) {
			rows.push( {
				preset_type:   'Group',
				group_element: r.group_name,
				preset_id:     r.preset_id,
				name:          r.name,
				module_name:   r.module_name,
				group_id:      r.group_id,
				is_default:    r.is_default,
				_order:        r.order,
			} );
		} );

		( presetsData.element_presets || [] ).forEach( function (r) {
			rows.push( {
				preset_type:   'Element',
				group_element: r.module_name,  // abbreviated in render
				preset_id:     r.preset_id,
				name:          r.name,
				module_name:   '',  // blank for element rows (already in group_element)
				group_id:      '',
				is_default:    r.is_default,
				_order:        r.order,
			} );
		} );

		return rows.filter( function (r) { return passesPresetsFilters( r, presetsFiltersAll ); } );
	}

	// ── Everything: build, render ─────────────────────────────────────────────

	/**
	 * Build the unified row set for the Everything table.
	 * Combines vars (colors, numbers, fonts, images, text, links + global colors)
	 * and presets (group + element) into a single flat array.
	 *
	 * Each row has: dso_category, dso_type, dso_id, dso_label, dso_value,
	 *               dso_module, dso_group_id, dso_is_default, dso_status
	 */
	// Returns swatch dots for user-defined categories assigned to an Everything row,
	// falling back to the DSO type label ("Variable" / "Preset") if none assigned.
	function _everythingCategoryCell( row ) {
		var dsoKey = ( row._source === 'group_preset' ? 'gp:' : row._source === 'element_preset' ? 'ep:' : 'var:' ) + ( row.dso_id || '' );
		var ids    = categoryMap[ dsoKey ] || [];
		if ( ! Array.isArray( ids ) ) { ids = [ ids ]; }
		ids = ids.filter( Boolean );
		if ( ids.length === 0 ) { return escHtml( row.dso_category ); }
		return ids.map( function ( cid ) {
			var c = categoriesData.find( function ( x ) { return x.id === cid; } );
			return c ? '<span class="d5dsh-category-swatch" style="background:' + escHtml( c.color ) + '" title="' + escHtml( c.label ) + '"></span>' : '';
		} ).filter( Boolean ).join( '' );
	}

	function buildEverythingRowSet() {
		var rows = [];

		// ── Variables ──────────────────────────────────────────────────────────
		if ( manageData ) {
			( manageData.vars || [] ).forEach( function ( v ) {
				var typeLabel = ( v.type || '' ).replace( /_/g, ' ' );
				typeLabel = typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1);
				rows.push( {
					dso_category:   'Variable',
					dso_type:       typeLabel,
					dso_id:         v.id,
					dso_label:      v.label,
					dso_value:      v.value,
					dso_module:     'N/A',
					dso_group_id:   '',
					dso_is_default: '',
					dso_status:     v.status,
					_source:        'var',
					_item:          v,
				} );
			} );
			( manageData.global_colors || [] ).forEach( function ( c ) {
				rows.push( {
					dso_category:   'Variable',
					dso_type:       'Global Color',
					dso_id:         c.id,
					dso_label:      c.label,
					dso_value:      c.value,
					dso_module:     'N/A',
					dso_group_id:   '',
					dso_is_default: '',
					dso_status:     c.status,
					_source:        'global_color',
					_item:          c,
				} );
			} );
		}

		// ── Presets ────────────────────────────────────────────────────────────
		if ( presetsData ) {
			( presetsData.group_presets || [] ).forEach( function ( p ) {
				rows.push( {
					dso_category:   'Preset',
					dso_type:       'Group Preset',
					dso_id:         p.preset_id,
					dso_label:      p.name,
					dso_value:      '',
					dso_module:     abbreviateModule( p.module_name || '' ).short,
					dso_group_id:   abbreviateGroupId( p.group_id || '' ).short,
					dso_is_default: p.is_default ? 'Yes' : 'No',
					dso_status:     '',
					_source:        'group_preset',
					_item:          p,
				} );
			} );
			( presetsData.element_presets || [] ).forEach( function ( p ) {
				rows.push( {
					dso_category:   'Preset',
					dso_type:       'Element Preset',
					dso_id:         p.preset_id,
					dso_label:      p.name,
					dso_value:      '',
					dso_module:     abbreviateModule( p.module_name || '' ).short,
					dso_group_id:   '',
					dso_is_default: p.is_default ? 'Yes' : 'No',
					dso_status:     '',
					_source:        'element_preset',
					_item:          p,
				} );
			} );
		}

		// Apply filters — uses the same checklist-Set logic as all other tables.
		return rows.filter( function ( r ) {
			return Object.keys( presetsFiltersEverything ).every( function ( col ) {
				var f = presetsFiltersEverything[ col ];
				if ( ! f || ! f.mode ) { return true; }
				var val;
				if ( col === 'dso_category' ) {
					// Match against user-defined category labels.
					var _fKey = ( r._source === 'group_preset' ? 'gp:' : r._source === 'element_preset' ? 'ep:' : 'var:' ) + ( r.dso_id || '' );
					var _fIds = categoryMap[ _fKey ] || [];
					if ( ! Array.isArray( _fIds ) ) { _fIds = [ _fIds ]; }
					_fIds = _fIds.filter( Boolean );
					if ( _fIds.length === 0 ) {
						val = '(none)';
					} else {
						var _fLabels = _fIds.map( function ( cid ) {
							var _fc = categoriesData.find( function ( x ) { return x.id === cid; } );
							return _fc ? _fc.label : '';
						} ).filter( Boolean );
						if ( f.mode === 'checklist' ) {
							if ( ! f.vals || f.vals.size === 0 ) { return false; }
							return _fLabels.some( function ( lbl ) { return f.vals.has( lbl ); } );
						}
						val = _fLabels.join( ', ' );
					}
				} else {
					val = String( r[ col ] || '' );
				}
				if ( f.mode === 'checklist' ) {
					if ( ! f.vals || f.vals.size === 0 ) { return false; }
					// '(blank)' in checklist matches rows with an empty value.
					if ( val === '' ) { return f.vals.has( '(blank)' ); }
					return f.vals.has( val );
				}
				var haystack = val.toLowerCase();
				var needle   = ( f.val || '' ).toLowerCase();
				if ( f.mode === 'is_empty'    ) { return haystack === ''; }
				if ( f.mode === 'equals'      ) { return haystack === needle; }
				if ( f.mode === 'starts_with' ) { return haystack.startsWith( needle ); }
				return haystack.indexOf( needle ) !== -1; // contains (default)
			} );
		} );
	}

	/**
	 * Render the Everything table.
	 * Called after either manageData or presetsData loads — waits for both.
	 */
	function renderEverythingTable() {
		if ( ! presetsData ) { return; }

		var filterBar = document.getElementById( 'd5dsh-everything-filter-bar' );
		if ( filterBar ) { filterBar.style.display = ''; }

		if ( ! window.d5dshEverythingTable ) { return; }

		var rows = buildEverythingRowSet();
		// Attach pre-rendered category HTML to each row so the Tabulator formatter can use it.
		rows = rows.map( function ( row ) {
			return Object.assign( {}, row, { _categoryHtml: _everythingCategoryCell( row ) } );
		} );
		window.d5dshEverythingTable.render( rows );
	}

	/**
	 * Strip the 'divi/' prefix from a group name or group ID for display.
	 * Returns { short, full } like abbreviateModule().
	 */
	function abbreviateGroupId( raw ) {
		if ( ! raw ) { return { short: '', full: '' }; }
		return { short: raw.replace( /^divi\//, '' ), full: raw };
	}

	/**
	 * Build a single <tr> HTML string for a presets table row.
	 *
	 * @param {'gp'|'ep'|'all'} tableKey
	 * @param {object}          row
	 * @param {number}          displayIdx  1-based display index
	 * @return {string}
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 13 — PRESETS: ROW BUILDING                                   ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function buildPresetsRow( tableKey, row, displayIdx ) {
		var defaultBadge = row.is_default
			? '<span class="d5dsh-default-badge" title="Default preset">&#10003;</span>'
			: '';

		var tr = '<tr>';
		tr += '<td class="d5dsh-col-order">' + displayIdx + '</td>';
		tr += '<td class="d5dsh-col-note">' + noteIndicatorHTML( 'preset:' + escAttr( row.preset_id || '' ) ) + '</td>';

		if ( tableKey === 'gp' ) {
			var gnAbbr = abbreviateGroupId( row.group_name );
			tr += '<td class="d5dsh-col-preset-group-name d5dsh-copy-cell" title="' + escHtml( gnAbbr.full ) + '"><span>' + escHtml( gnAbbr.short ) + '</span></td>';
			tr += '<td class="d5dsh-col-preset-id d5dsh-copy-cell" title="' + escHtml( row.preset_id || '' ) + '"><code>' + escHtml( row.preset_id || '' ) + '</code></td>';
			tr += '<td class="d5dsh-col-preset-name">'        + buildPresetNameCell( row, 'gp', getPresetsMode('gp') ) + '</td>';
			var modGP = abbreviateModule( row.module_name );
			tr += '<td class="d5dsh-col-preset-module d5dsh-copy-cell" title="' + escHtml( modGP.full ) + '"><span>' + escHtml( modGP.short ) + '</span></td>';
			var giAbbr = abbreviateGroupId( row.group_id );
			tr += '<td class="d5dsh-col-preset-group-id d5dsh-copy-cell" title="' + escHtml( giAbbr.full ) + '"><code>' + escHtml( giAbbr.short ) + '</code></td>';
			tr += '<td class="d5dsh-col-preset-default">' + defaultBadge + '</td>';

		} else if ( tableKey === 'ep' ) {
			var modEP = abbreviateModule( row.module_name );
			tr += '<td class="d5dsh-col-preset-element d5dsh-copy-cell" title="' + escHtml( modEP.full ) + '"><span>' + escHtml( modEP.short ) + '</span></td>';
			tr += '<td class="d5dsh-col-preset-id d5dsh-copy-cell" title="' + escHtml( row.preset_id || '' ) + '"><code>' + escHtml( row.preset_id || '' ) + '</code></td>';
			tr += '<td class="d5dsh-col-preset-name">'       + buildPresetNameCell( row, 'ep', getPresetsMode('ep') ) + '</td>';
			tr += '<td class="d5dsh-col-preset-default">'    + defaultBadge + '</td>';

		} else { // 'all'
			// group_element: abbreviated group_name for Group rows, abbreviated module for Element rows.
			var geDisplay;
			if ( row.preset_type === 'Group' ) {
				var geAbbr = abbreviateGroupId( row.group_element );
				geDisplay = '<span>' + escHtml( geAbbr.short ) + '</span>';
			} else {
				var modAll = abbreviateModule( row.group_element );
				geDisplay = '<span>' + escHtml( modAll.short ) + '</span>';
			}
			var geTitle = row.preset_type === 'Group'
				? abbreviateGroupId( row.group_element ).full
				: abbreviateModule( row.group_element ).full;

			// Module column: abbreviated for Group rows, blank for Element rows.
			var modAllCol = '';
			var modAllTitle = '';
			if ( row.preset_type === 'Group' && row.module_name ) {
				var modAllG = abbreviateModule( row.module_name );
				modAllCol   = '<span>' + escHtml( modAllG.short ) + '</span>';
				modAllTitle = modAllG.full;
			}
			// Group ID: abbreviated, truncated display.
			var giAllAbbr = row.group_id ? abbreviateGroupId( row.group_id ) : { short: '', full: '' };

			tr += '<td class="d5dsh-col-preset-type">'           + escHtml( row.preset_type   || '' ) + '</td>';
			tr += '<td class="d5dsh-col-preset-group-element d5dsh-copy-cell" title="' + escHtml( geTitle ) + '">' + geDisplay + '</td>';
			tr += '<td class="d5dsh-col-preset-id d5dsh-copy-cell" title="' + escHtml( row.preset_id || '' ) + '"><code>' + escHtml( row.preset_id || '' ) + '</code></td>';
			tr += '<td class="d5dsh-col-preset-name-ro">'         + escHtml( row.name          || '' ) + '</td>';
			tr += '<td class="d5dsh-col-preset-module' + ( modAllTitle ? ' d5dsh-copy-cell' : '' ) + '"' + ( modAllTitle ? ' title="' + escHtml( modAllTitle ) + '"' : '' ) + '>' + modAllCol + '</td>';
			tr += '<td class="d5dsh-col-preset-group-id d5dsh-copy-cell" title="' + escHtml( giAllAbbr.full ) + '"><code>' + escHtml( giAllAbbr.short ) + '</code></td>';
			tr += '<td class="d5dsh-col-preset-default">'         + defaultBadge + '</td>';
		}

		tr += '</tr>';
		return tr;
	}

	/**
	 * Build the editable Label cell for GP and EP tables.
	 * The input carries data-preset-id and data-table-key for change tracking.
	 */
	/**
	 * Return the active mode ('view' or 'manage') for a given tableKey ('gp' or 'ep').
	 */
	function getPresetsMode( tableKey ) {
		var activeBtn = document.querySelector(
			'#d5dsh-presets-' + tableKey + '-mode-switcher .d5dsh-presets-mode-btn.d5dsh-mode-active'
		);
		return ( activeBtn && activeBtn.dataset.mode ) ? activeBtn.dataset.mode : 'view';
	}

	/**
	 * Build the Label cell for GP and EP tables.
	 * In View mode: plain text (no box).
	 * In Bulk Label Change mode: editable input.
	 */
	function buildPresetNameCell( row, tableKey, mode ) {
		var dirty = tableKey === 'gp' ? presetsDirtyGP[ row.preset_id ] : presetsDirtyEP[ row.preset_id ];
		var currentName = ( dirty && dirty.name !== undefined ) ? dirty.name : ( row.name || '' );
		if ( mode !== 'manage' ) {
			// View mode: plain text, no input box.
			var dirtyMark = dirty ? ' <span class="d5dsh-dirty-dot" title="Unsaved change">•</span>' : '';
			return escHtml( currentName ) + dirtyMark;
		}
		// Bulk Label Change mode: editable input.
		var dirtyClass = dirty ? ' d5dsh-cell-dirty' : '';
		return '<input type="text" class="d5dsh-preset-name-input' + dirtyClass + '" '
			+ 'data-preset-id="'   + escHtml( row.preset_id   || '' ) + '" '
			+ 'data-module-name="' + escHtml( row.module_name || '' ) + '" '
			+ 'data-group-id="'    + escHtml( row.group_id    || '' ) + '" '
			+ 'data-table-key="'   + escHtml( tableKey ) + '" '
			+ 'data-original="'    + escHtml( row.name || '' ) + '" '
			+ 'value="'            + escHtml( currentName ) + '">';
	}

	function updatePresetsSortIndicators( tableKey ) {
		var sortObj    = tableKey === 'gp' ? presetsSortGP    : ( tableKey === 'ep' ? presetsSortEP    : ( tableKey === 'everything' ? presetsSortEverything    : presetsSortAll ) );
		var filtersObj = tableKey === 'gp' ? presetsFiltersGP : ( tableKey === 'ep' ? presetsFiltersEP : ( tableKey === 'everything' ? presetsFiltersEverything : presetsFiltersAll ) );
		var tableId  = presetsTableId( tableKey );
		var tableEl  = document.getElementById( tableId );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'th[data-filter-col]' ).forEach( function ( th ) {
			var col = th.dataset.filterCol;
			var f = filtersObj[ col ];
			var hasFilter = !! ( f && ( f.vals ? f.vals.size > 0 : f.val ) );
			var hasSorted = sortObj.key === col;
			th.classList.toggle( 'd5dsh-col-filtered', hasFilter );
			th.classList.toggle( 'd5dsh-col-sorted',   hasSorted );
		} );
	}

	// ── Presets: filter panel (same UX as Variables) ──────────────────────────

	var activePresetsFilterCol    = null;
	var activePresetsFilterTable  = null;

	/**
	 * Wire column header clicks for a presets table to open the filter panel.
	 * Called after data loads.
	 */
	function presetsTableId( tableKey ) {
		return tableKey === 'everything' ? 'd5dsh-everything-table' : ( 'd5dsh-presets-' + tableKey + '-table' );
	}

	function wirePresetsFilterHeaders( tableKey ) {
		var tableEl = document.getElementById( presetsTableId( tableKey ) );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'thead th[data-filter-col]' ).forEach( function ( th ) {
			th.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				// Let the expand-toggle icon handle its own click without opening the filter panel.
				if ( e.target.closest( '.d5dsh-col-expand-toggle' ) ) { return; }
				var col = th.dataset.filterCol;
				if ( activePresetsFilterCol === col && activePresetsFilterTable === tableKey ) {
					closeAllPresetsFilterPanels();
				} else {
					openPresetsFilterPanel( tableKey, th, col );
				}
			} );
		} );
	}

	/**
	 * Thin wrapper — builds the cfg object for a presets table and delegates
	 * to the shared openFilterPanel( cfg ).  Adding a new table type only
	 * requires a new cfg block here; the panel logic itself never changes.
	 */
	function openPresetsFilterPanel( tableKey, th, col ) {
		var filtersObj = tableKey === 'gp' ? presetsFiltersGP
		              : ( tableKey === 'ep' ? presetsFiltersEP : ( tableKey === 'everything' ? presetsFiltersEverything : presetsFiltersAll ) );
		var sortObj    = tableKey === 'gp' ? presetsSortGP
		              : ( tableKey === 'ep' ? presetsSortEP    : ( tableKey === 'everything' ? presetsSortEverything    : presetsSortAll ) );

		activePresetsFilterCol   = col;
		activePresetsFilterTable = tableKey;

		openFilterPanel( {
			th:         th,
			col:        col,
			filtersObj: filtersObj,
			sortObj:    sortObj,
			getValues:  function ( c ) { return getPresetsDistinctValues( tableKey, c ); },
			closeAll:   closeAllPresetsFilterPanels,
			onApply: function () {
				updatePresetsClearVisibility( tableKey );
				updatePresetsSortIndicators( tableKey );
				renderPresetsTable( tableKey );
			},
			onClear: function () {
				updatePresetsClearVisibility( tableKey );
				updatePresetsSortIndicators( tableKey );
				renderPresetsTable( tableKey );
			},
			onSort: function () {
				updatePresetsSortIndicators( tableKey );
				renderPresetsTable( tableKey );
			},
		} );
	}

	function closeAllPresetsFilterPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) {
			if ( p._cleanup ) { p._cleanup(); }
			p.parentNode.removeChild( p );
		} );
		document.querySelectorAll( 'th.d5dsh-col-filter-active' ).forEach( function ( th ) { th.classList.remove( 'd5dsh-col-filter-active' ); } );
		activePresetsFilterCol   = null;
		activePresetsFilterTable = null;
	}

	/**
	 * Return sorted distinct display values for a given column in a presets table.
	 */
	function getPresetsDistinctValues( tableKey, col ) {
		var rows;
		if ( tableKey === 'gp' )            { rows = buildPresetsRowSet_gp(); }
		else if ( tableKey === 'ep' )        { rows = buildPresetsRowSet_ep(); }
		else if ( tableKey === 'everything' ) { rows = buildEverythingRowSet(); }
		else                                 { rows = buildPresetsRowSet_all(); }

		var seen = {};
		rows.forEach( function ( r ) {
			var val;
			if ( col === 'is_default' ) {
				val = r.is_default ? 'Yes' : 'No';
			} else if ( col === 'module_name' || col === 'group_element' ) {
				// Abbreviated module columns.
				val = abbreviateModule( r[ col ] || '' ).short;
			} else if ( col === 'group_name' ) {
				val = abbreviateGroupId( r.group_name || '' ).short;
			} else if ( col === 'group_id' ) {
				val = abbreviateGroupId( r.group_id || '' ).short;
			} else if ( col === 'dso_category' && tableKey === 'everything' ) {
				// Show user-defined category labels; '(none)' if none assigned.
				var _dsoKey = ( r._source === 'group_preset' ? 'gp:' : r._source === 'element_preset' ? 'ep:' : 'var:' ) + ( r.dso_id || '' );
				var _catIds = categoryMap[ _dsoKey ] || [];
				if ( ! Array.isArray( _catIds ) ) { _catIds = [ _catIds ]; }
				_catIds = _catIds.filter( Boolean );
				if ( _catIds.length === 0 ) { seen[ '(none)' ] = true; }
				else { _catIds.forEach( function ( cid ) { var _c = categoriesData.find( function ( x ) { return x.id === cid; } ); if ( _c ) { seen[ _c.label ] = true; } } ); }
				return;
			} else {
				// Everything-table columns are already pre-formatted in the row object.
				val = String( r[ col ] || '' );
			}
			// Include blank values as '(blank)' so users can filter for them.
			seen[ val !== '' ? val : '(blank)' ] = true;
		} );
		return Object.keys( seen ).sort();
	}

	// ── Presets: inline name editing ─────────────────────────────────────────

	function handlePresetsNameChange( tableKey, input ) {
		var presetId  = input.dataset.presetId;
		var original  = input.dataset.original;
		var newName   = input.value;
		var dirty     = tableKey === 'gp' ? presetsDirtyGP : presetsDirtyEP;

		if ( newName !== original ) {
			dirty[ presetId ] = {
				preset_id:   presetId,
				module_name: input.dataset.moduleName || '',
				group_id:    input.dataset.groupId    || '',
				name:        newName,
			};
			input.classList.add( 'd5dsh-cell-dirty' );
		} else {
			delete dirty[ presetId ];
			input.classList.remove( 'd5dsh-cell-dirty' );
		}

		updatePresetsSaveBar( tableKey );
	}

	function updatePresetsSaveBar( tableKey ) {
		var dirty    = tableKey === 'gp' ? presetsDirtyGP : presetsDirtyEP;
		var barId    = 'd5dsh-presets-' + tableKey + '-save-bar';
		var countId  = 'd5dsh-presets-' + tableKey + '-dirty-count';
		var bar      = document.getElementById( barId );
		var countEl  = document.getElementById( countId );
		var n        = Object.keys( dirty ).length;
		if ( bar ) { bar.style.display = n > 0 ? 'flex' : 'none'; }
		if ( countEl ) {
			countEl.textContent = n > 0 ? ( n + ' unsaved change' + ( n === 1 ? '' : 's' ) ) : '';
		}
	}

	// ── Presets: save & discard ───────────────────────────────────────────────

	function handlePresetsSave( tableKey ) {
		if ( typeof d5dtPresets === 'undefined' ) { return; }

		var dirtyGP = tableKey === 'gp' ? Object.values( presetsDirtyGP ) : [];
		var dirtyEP = tableKey === 'ep' ? Object.values( presetsDirtyEP ) : [];

		if ( dirtyGP.length === 0 && dirtyEP.length === 0 ) { return; }

		var payload = {
			group_presets:   dirtyGP.map( function (r) { return { preset_id: r.preset_id, group_id: r.group_id,    name: r.name }; } ),
			element_presets: dirtyEP.map( function (r) { return { preset_id: r.preset_id, module_name: r.module_name, name: r.name }; } ),
		};

		var saveBtn = document.getElementById( 'd5dsh-presets-' + tableKey + '-save' );
		if ( saveBtn ) { saveBtn.disabled = true; saveBtn.textContent = 'Saving…'; }

		// Nonce in query string; JSON payload in body (matches LabelManager pattern).
		fetch( d5dtPresets.ajaxUrl + '?action=d5dsh_presets_manage_save&nonce=' + encodeURIComponent( d5dtPresets.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( payload ),
		} )
		.then( function (res) { return res.json(); } )
		.then( function (json) {
			if ( saveBtn ) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
			if ( ! json.success ) {
				showToast( 'error', 'Save failed', ( json.data ? json.data.message : 'Unknown error' ) );
				return;
			}
			presetsData     = json.data;
			presetsOriginal = deepClone( presetsData );
			if ( tableKey === 'gp' ) { presetsDirtyGP = {}; } else { presetsDirtyEP = {}; }
			updatePresetsSaveBar( tableKey );
			renderPresetsTable( 'gp' );
			renderPresetsTable( 'ep' );
			renderPresetsTable( 'all' );
			showToast( 'success', 'Presets saved', 'Your changes have been saved.' );
		} )
		.catch( function (err) {
			if ( saveBtn ) { saveBtn.disabled = false; saveBtn.textContent = 'Save Changes'; }
			showToast( 'error', 'Save failed', err.message );
		} );
	}

	function handlePresetsDiscard( tableKey ) {
		if ( tableKey === 'gp' ) { presetsDirtyGP = {}; } else { presetsDirtyEP = {}; }
		// Restore live data from original.
		presetsData = deepClone( presetsOriginal );
		updatePresetsSaveBar( tableKey );
		renderPresetsTable( tableKey );
		if ( tableKey === 'gp' || tableKey === 'ep' ) { renderPresetsTable( 'all' ); }
	}

	// ── Presets: CSV export ───────────────────────────────────────────────────

	// ═══════════════════════════════════════════════════════════════════════
	//  Shared filename generator
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Build a standardised output filename.
	 * Format: d5dsh-<site>-<outputType>-<timestamp>.<ext>
	 * e.g.   d5dsh-my_site-vars-21Mar2026-12.30.csv
	 *
	 * @param {string} outputType  Up to 6 chars: 'vars','audit','scan','export','import', etc.
	 * @param {string} ext         File extension without dot: 'csv','xlsx','json','zip'
	 * @returns {string}
	 */
	function d5dshFilename( outputType, ext ) {
		// Site abbreviation: use stored setting or auto-derive from siteAbbr localized value.
		var abbr = ( d5dtSettings && d5dtSettings.siteAbbr && d5dtSettings.siteAbbr.length )
			? d5dtSettings.siteAbbr
			: ( window.location.hostname.replace( /^www\./, '' ).replace( /[^a-z0-9]+/gi, '_' ).replace( /^_+|_+$/g, '' ).toLowerCase() || 'site' );

		// Timestamp: DDMmmYYYY-HH.mm
		var now     = new Date();
		var MONTHS  = [ 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec' ];
		var pad     = function ( n ) { return String( n ).padStart( 2, '0' ); };
		var ts      = pad( now.getDate() ) + MONTHS[ now.getMonth() ] + now.getFullYear()
			        + '-' + pad( now.getHours() ) + '.' + pad( now.getMinutes() );

		return 'd5dsh-' + abbr + '-' + outputType + '-' + ts + '.' + ext;
	}

	// ═══════════════════════════════════════════════════════════════════════
	//  Column-selector CSV export (shared across all tables)
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Column definitions for CSV export per table.
	 * Notes/Tags always available; checked=false by default.
	 */
	var CSV_COL_DEFS = {
		vars: [
			{ value: 'order',  label: '#',      checked: true  },
			{ value: 'note',   label: 'Notes',  checked: false },
			{ value: 'tags',   label: 'Tags',   checked: false },
			{ value: 'type',   label: 'Type',   checked: true  },
			{ value: 'id',     label: 'ID',     checked: true  },
			{ value: 'label',  label: 'Label',  checked: true  },
			{ value: 'value',  label: 'Value',  checked: true  },
			{ value: 'status', label: 'Status', checked: true  },
		],
		gp: [
			{ value: 'order',      label: '#',           checked: true  },
			{ value: 'note',       label: 'Notes',       checked: false },
			{ value: 'tags',       label: 'Tags',        checked: false },
			{ value: 'group_name', label: 'Group Name',  checked: true  },
			{ value: 'preset_id',  label: 'Preset ID',   checked: true  },
			{ value: 'name',       label: 'Label',       checked: true  },
			{ value: 'module',     label: 'Module',      checked: true  },
			{ value: 'group_id',   label: 'Group ID',    checked: false },
			{ value: 'is_default', label: 'Default',  checked: true  },
		],
		ep: [
			{ value: 'order',      label: '#',          checked: true  },
			{ value: 'note',       label: 'Notes',      checked: false },
			{ value: 'tags',       label: 'Tags',       checked: false },
			{ value: 'element',    label: 'Element',    checked: true  },
			{ value: 'preset_id',  label: 'Preset ID',  checked: true  },
			{ value: 'name',       label: 'Label',      checked: true  },
			{ value: 'is_default', label: 'Default', checked: true  },
		],
		all: [
			{ value: 'order',         label: '#',              checked: true  },
			{ value: 'note',          label: 'Notes',          checked: false },
			{ value: 'tags',          label: 'Tags',           checked: false },
			{ value: 'type',          label: 'Type',           checked: true  },
			{ value: 'group_element', label: 'Group / Element',checked: true  },
			{ value: 'preset_id',     label: 'Preset ID',      checked: true  },
			{ value: 'name',          label: 'Label',          checked: true  },
			{ value: 'module',        label: 'Module',         checked: true  },
			{ value: 'group_id',      label: 'Group ID',       checked: false },
			{ value: 'is_default',    label: 'Default',     checked: true  },
		],
		everything: [
			{ value: 'order',          label: '#',               checked: true  },
			{ value: 'note',           label: 'Notes',           checked: false },
			{ value: 'tags',           label: 'Tags',            checked: false },
			{ value: 'dso_category',   label: 'Category',        checked: true  },
			{ value: 'dso_type',       label: 'Type',            checked: true  },
			{ value: 'dso_id',         label: 'ID',              checked: true  },
			{ value: 'dso_label',      label: 'Label',           checked: true  },
			{ value: 'dso_value',      label: 'Value',           checked: true  },
			{ value: 'dso_module',     label: 'Element / Module',checked: true  },
			{ value: 'dso_group_id',   label: 'Group ID',        checked: false },
			{ value: 'dso_is_default', label: 'Default',         checked: true  },
			{ value: 'dso_status',     label: 'Status',          checked: true  },
		],
	};

	/**
	 * Open the print-setup modal in CSV mode for the given table.
	 * Shows column checkboxes; on Go, calls executeExportCsv().
	 */
	function openExportCsvSetup( tableKey ) {
		var colDefs = CSV_COL_DEFS[ tableKey ] || [];
		var colsWrap = document.querySelector( '#d5dsh-print-modal .d5dsh-print-cols' );
		if ( colsWrap ) {
			colsWrap.innerHTML = '';
			colDefs.forEach( function ( col ) {
				var lbl = document.createElement( 'label' );
				lbl.className = 'd5dsh-setting-row';
				var chk = document.createElement( 'input' );
				chk.type      = 'checkbox';
				chk.className = 'd5dsh-print-col-chk';
				chk.value     = col.value;
				chk.checked   = col.checked;
				chk.dataset.px = 0;
				lbl.appendChild( chk );
				lbl.appendChild( document.createTextNode( ' ' + col.label ) );
				colsWrap.appendChild( lbl );
			} );
		}
		var TABLE_TITLES = { vars: 'Variables', gp: 'Group Presets', ep: 'Element Presets', all: 'All Presets', everything: 'Everything' };
		var titleEl = document.querySelector( '#d5dsh-print-modal .d5dsh-modal-title' );
		if ( titleEl ) { titleEl.textContent = 'Export CSV — ' + ( TABLE_TITLES[ tableKey ] || '' ); }
		var modalEl = document.getElementById( 'd5dsh-print-modal' );
		if ( modalEl ) { modalEl.dataset.exportMode = 'csv'; modalEl.dataset.tableKey = tableKey; }
		var goBtn3 = document.getElementById( 'd5dsh-print-go' );
		if ( goBtn3 ) { goBtn3.textContent = 'Export CSV'; }
		openModal( 'd5dsh-print-modal' );
	}

	/**
	 * Build and download a CSV from the current table data,
	 * including only the columns the user selected.
	 *
	 * @param {string} tableKey  'vars'|'gp'|'ep'|'all'|'everything'
	 * @param {Array}  selCols   [{ value, px }] from checked checkboxes
	 */
	function executeExportCsv( tableKey, selCols ) {
		var colValues = selCols.map( function ( c ) { return c.value; } );
		var colLabels = [];
		( CSV_COL_DEFS[ tableKey ] || [] ).forEach( function ( def ) {
			if ( colValues.indexOf( def.value ) !== -1 ) { colLabels.push( def.label ); }
		} );

		var rows = [];
		var TYPE_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };

		function getField( row, field, idx ) {
			if ( field === 'order' ) { return idx + 1; }
			if ( field === 'note' ) {
				var nKey = tableKey === 'vars' ? 'var:' + ( row.id || row.dso_id || '' ) : 'preset:' + ( row.preset_id || row.dso_id || '' );
				return ( notesData[ nKey ] && notesData[ nKey ].note ) || '';
			}
			if ( field === 'tags' ) {
				var tKey = tableKey === 'vars' ? 'var:' + ( row.id || row.dso_id || '' ) : 'preset:' + ( row.preset_id || row.dso_id || '' );
				return ( notesData[ tKey ] && notesData[ tKey ].tags ) ? notesData[ tKey ].tags.join( ', ' ) : '';
			}
			if ( field === 'type' && tableKey === 'vars' ) {
				return row.type === 'global_color' ? 'Global Color' : ( TYPE_LABELS[ row.type ] || row.type || '' );
			}
			if ( field === 'is_default' ) { return row.is_default ? 'Yes' : 'No'; }
			if ( field === 'module' ) { return row.module_name || ''; }
			if ( field === 'element' ) { return row.module_name || ''; }
			var val = row[ field ];
			if ( val === undefined || val === null ) { return ''; }
			if ( typeof val === 'boolean' ) { return val ? 'Yes' : 'No'; }
			if ( tableKey === 'vars' && field === 'value' && row.type === 'images' && val ) {
				if ( val.indexOf( 'data:' ) === 0 ) { return '[embedded image]'; }
				var parts = val.split( '/' );
				return parts[ parts.length - 1 ] || val;
			}
			return String( val );
		}

		var sourceRows = [];
		if ( tableKey === 'vars' ) {
			sourceRows = getDisplayRows();
		} else if ( tableKey === 'gp' ) {
			sourceRows = buildPresetsRowSet_gp();
		} else if ( tableKey === 'ep' ) {
			sourceRows = buildPresetsRowSet_ep();
		} else if ( tableKey === 'all' ) {
			sourceRows = buildPresetsRowSet_all();
		} else if ( tableKey === 'everything' ) {
			sourceRows = buildEverythingRowSet();
		}

		var lines = [ colLabels.map( csvEscape ).join( ',' ) ];
		sourceRows.forEach( function ( row, idx ) {
			var cells = colValues.map( function ( field ) { return csvEscape( getField( row, field, idx ) ); } );
			lines.push( cells.join( ',' ) );
		} );

		var TABLE_NAMES = { vars: 'vars', gp: 'gp-presets', ep: 'ep-presets', all: 'all-presets', everything: 'everything' };
		var blob = new Blob( [ lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = d5dshFilename( TABLE_NAMES[ tableKey ] || tableKey, 'csv' );
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	function exportPresetsCsv( tableKey ) {
		if ( ! presetsData ) { return; }
		var rows, headers, fieldOrder;

		if ( tableKey === 'gp' ) {
			headers    = [ '#', 'Group Name', 'Preset ID', 'Name', 'Module', 'Group ID', 'Is Default' ];
			fieldOrder = [ '_idx', 'group_name', 'preset_id', 'name', 'module_name', 'group_id', 'is_default' ];
			rows       = buildPresetsRowSet_gp();
		} else if ( tableKey === 'ep' ) {
			headers    = [ '#', 'Element', 'Preset ID', 'Name', 'Is Default' ];
			fieldOrder = [ '_idx', 'module_name', 'preset_id', 'name', 'is_default' ];
			rows       = buildPresetsRowSet_ep();
		} else {
			headers    = [ '#', 'Type', 'Group / Element', 'Preset ID', 'Name', 'Module', 'Group ID', 'Is Default' ];
			fieldOrder = [ '_idx', 'preset_type', 'group_element', 'preset_id', 'name', 'module_name', 'group_id', 'is_default' ];
			rows       = buildPresetsRowSet_all();
		}

		var lines = [ headers.map( csvEscape ).join( ',' ) ];
		rows.forEach( function ( row, idx ) {
			var cells = fieldOrder.map( function ( f ) {
				if ( f === '_idx' ) { return idx + 1; }
				var val = row[ f ];
				if ( val === true ) { return 'Yes'; }
				if ( val === false ) { return 'No'; }
				return val !== undefined ? val : '';
			} );
			lines.push( cells.map( csvEscape ).join( ',' ) );
		} );

		var blob = new Blob( [ lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = 'presets-' + tableKey + '-' + new Date().toISOString().slice( 0, 10 ) + '.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	function exportEverythingCsv() {
		var rows    = buildEverythingRowSet();
		var headers = [ '#', 'Category', 'Type', 'ID', 'Label', 'Value / Module', 'Status' ];
		var fields  = [ '_idx', 'dso_category', 'dso_type', 'dso_id', 'dso_label', 'dso_value', 'dso_status' ];
		var lines   = [ headers.map( csvEscape ).join( ',' ) ];
		rows.forEach( function ( row, idx ) {
			var cells = fields.map( function ( f ) {
				if ( f === '_idx' ) { return idx + 1; }
				return row[ f ] !== undefined ? row[ f ] : '';
			} );
			lines.push( cells.map( csvEscape ).join( ',' ) );
		} );
		var blob = new Blob( [ lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = 'd5dsh-everything-' + new Date().toISOString().slice( 0, 10 ) + '.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	function openEverythingPrint() {
		openPresetsPrint( 'everything' );
	}

	function csvEscape( val ) {
		var s = String( val === null || val === undefined ? '' : val );
		if ( s.search( /[",\r\n]/ ) !== -1 ) {
			s = '"' + s.replace( /"/g, '""' ) + '"';
		}
		return s;
	}

	// ── Validate tab ──────────────────────────────────────────────────────────

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 16 — VALIDATE TAB                                            ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initValidate() {
		var btn     = document.getElementById( 'd5dsh-validate-btn' );
		var fileIn  = document.getElementById( 'd5dsh-validate-file' );
		var result  = document.getElementById( 'd5dsh-validate-result' );
		var spinner = document.getElementById( 'd5dsh-validate-spinner' );
		if ( ! btn || ! fileIn ) { return; }

		btn.addEventListener( 'click', function () {
			if ( ! fileIn.files || ! fileIn.files[0] ) {
				showToast( 'error', 'No file selected', 'Please select an Excel file first.' );
				return;
			}
			if ( typeof d5dtValidate === 'undefined' ) { return; }

			btn.disabled = true;
			if ( spinner ) { spinner.style.display = 'inline-block'; }
			if ( result  ) { result.style.display  = 'none'; result.innerHTML = ''; }

			var fd = new FormData();
			fd.append( 'action', 'd5dsh_validate' );
			fd.append( 'nonce',  d5dtValidate.nonce );
			fd.append( 'file',   fileIn.files[0] );

			fetch( d5dtValidate.ajaxUrl, { method: 'POST', body: fd } )
				.then( function (r) { return r.json(); } )
				.then( function (json) {
					btn.disabled = false;
					if ( spinner ) { spinner.style.display = 'none'; }
					if ( ! result ) { return; }
					if ( ! json.success ) {
						result.innerHTML = ''; var _vfe = document.createElement( 'div' ); _vfe.className = 'd5dsh-validate-fatal'; var _vfs = document.createElement( 'strong' ); _vfs.textContent = 'Error: '; _vfe.appendChild( _vfs ); _vfe.appendChild( document.createTextNode( json.data ? json.data.message : 'Unknown error' ) ); result.appendChild( _vfe );
						result.style.display = 'block';
						return;
					}
					result.innerHTML = buildValidateReport( json.data );
					result.style.display = 'block';
				} )
				.catch( function (err) {
					btn.disabled = false;
					if ( spinner ) { spinner.style.display = 'none'; }
					if ( result ) {
						result.innerHTML = ''; var _vfe2 = document.createElement( 'div' ); _vfe2.className = 'd5dsh-validate-fatal'; _vfe2.textContent = 'Request failed: ' + ( err.message || '' ); result.appendChild( _vfe2 );
						result.style.display = 'block';
					}
				} );
		} );
	}

	function buildValidateReport( data ) {
		var counts  = data.counts || {};
		var issues  = data.issues || [];
		var passed  = data.passed;
		var type    = data.file_type || 'unknown';

		var LEVEL_ORDER = [ 'fatal', 'error', 'warning', 'info' ];
		var LEVEL_LABEL = { fatal: 'Fatal', error: 'Error', warning: 'Warning', info: 'Info' };

		var html = '<div class="d5dsh-validate-report">';

		// Summary banner.
		var bannerClass = passed ? 'd5dsh-validate-pass' : 'd5dsh-validate-fail';
		var bannerIcon  = passed ? '✓' : '✗';
		var bannerText  = passed
			? 'File looks good — no fatal errors or import blockers found.'
			: 'Issues found that need attention before importing.';
		html += '<div class="d5dsh-validate-banner ' + bannerClass + '">';
		html += '<span class="d5dsh-validate-icon">' + bannerIcon + '</span> ';
		html += '<strong>' + escHtml( bannerText ) + '</strong>';
		html += '</div>';

		// Count pills.
		html += '<div class="d5dsh-validate-counts">';
		html += '<span class="d5dsh-val-count d5dsh-val-fatal">' + ( counts.fatal || 0 ) + ' Fatal</span>';
		html += '<span class="d5dsh-val-count d5dsh-val-error">' + ( counts.error || 0 ) + ' Error</span>';
		html += '<span class="d5dsh-val-count d5dsh-val-warning">' + ( counts.warning || 0 ) + ' Warning</span>';
		html += '<span class="d5dsh-val-count d5dsh-val-info">' + ( counts.info || 0 ) + ' Info</span>';
		if ( type !== 'unknown' ) {
			html += '<span class="d5dsh-val-filetype">Type: ' + escHtml( type ) + '</span>';
		}
		html += '</div>';

		if ( issues.length === 0 ) {
			html += '<p>No issues found.</p>';
		} else {
			// Group by level.
			LEVEL_ORDER.forEach( function ( level ) {
				var group = issues.filter( function (i) { return i.level === level; } );
				if ( group.length === 0 ) { return; }
				html += '<details class="d5dsh-val-group d5dsh-val-group-' + level + '" open>';
				html += '<summary><strong>' + escHtml( LEVEL_LABEL[ level ] ) + '</strong> (' + group.length + ')</summary>';
				html += '<ul class="d5dsh-val-list">';
				group.forEach( function (issue) {
					var loc = '';
					if ( issue.sheet ) { loc += escHtml( issue.sheet ); }
					if ( issue.row   ) { loc += ( loc ? ' · ' : '' ) + 'Row ' + issue.row; }
					if ( issue.col   ) { loc += ( loc ? ' · ' : '' ) + 'Col ' + escHtml( issue.col ); }
					html += '<li>';
					if ( loc ) { html += '<span class="d5dsh-val-loc">' + loc + '</span> '; }
					html += escHtml( issue.message );
					html += '</li>';
				} );
				html += '</ul></details>';
			} );
		}

		html += '</div>';
		return html;
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' );
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 15 — PRESETS: PRINT                                          ║
	// ╚══════════════════════════════════════════════════════════════════╝
	// ── Presets: print (shares the same Print Setup modal as Variables) ──────

	/**
	 * Column definitions for each presets table.
	 * Each entry: { value, label, checked, cssClass }
	 * cssClass is the <th>/<td> class that will be hidden when unchecked.
	 */
	// px values reflect visual screen proportions. Flex columns (name/label) are assigned
	// the space remaining after all fixed columns at a typical table width of ~1200px.
	// GP fixed: 34+130+110+90+120+76 = 560 → name flex gets ~640px
	// EP fixed: 34+100+110+76 = 320 → name flex gets ~880px
	// ALL fixed: 34+66+120+110+90+120+76 = 616 → name flex gets ~584px
	var PRESETS_PRINT_COLS = {
		gp: [
			{ value: 'order',      label: '#',           checked: true,  cssClass: 'd5dsh-col-order',             px: 34  },
			{ value: 'note', label: 'Notes', checked: false, cssClass: 'd5dsh-col-note', px: 50 },
			{ value: 'group_name', label: 'Group Name',  checked: true,  cssClass: 'd5dsh-col-preset-group-name', px: 130 },
			{ value: 'preset_id',  label: 'Preset ID',   checked: true,  cssClass: 'd5dsh-col-preset-id',         px: 130 },
			{ value: 'name',       label: 'Label',        checked: true,  cssClass: 'd5dsh-col-preset-name',       px: 640 },
			{ value: 'module',     label: 'Module',       checked: true,  cssClass: 'd5dsh-col-preset-module',     px: 90  },
			{ value: 'group_id',   label: 'Group ID',     checked: false, cssClass: 'd5dsh-col-preset-group-id',   px: 120 },
			{ value: 'is_default', label: 'Default',   checked: true,  cssClass: 'd5dsh-col-preset-default',   px: 76  },
		],
		ep: [
			{ value: 'order',      label: '#',           checked: true,  cssClass: 'd5dsh-col-order',           px: 34  },
			{ value: 'note', label: 'Notes', checked: false, cssClass: 'd5dsh-col-note', px: 50 },
			{ value: 'element',    label: 'Element',     checked: true,  cssClass: 'd5dsh-col-preset-element',  px: 120 },
			{ value: 'preset_id',  label: 'Preset ID',   checked: true,  cssClass: 'd5dsh-col-preset-id',       px: 130 },
			{ value: 'name',       label: 'Label',        checked: true,  cssClass: 'd5dsh-col-preset-name',     px: 880 },
			{ value: 'is_default', label: 'Is Default',   checked: true,  cssClass: 'd5dsh-col-preset-default', px: 76  },
		],
		all: [
			{ value: 'order',         label: '#',              checked: true,  cssClass: 'd5dsh-col-order',               px: 34  },
			{ value: 'note', label: 'Notes', checked: false, cssClass: 'd5dsh-col-note', px: 50 },
			{ value: 'type',          label: 'Type',            checked: true,  cssClass: 'd5dsh-col-preset-type',         px: 66  },
			{ value: 'group_element', label: 'Group / Element', checked: true,  cssClass: 'd5dsh-col-preset-group-element',px: 120 },
			{ value: 'preset_id',     label: 'Preset ID',       checked: true,  cssClass: 'd5dsh-col-preset-id',           px: 120 },
			{ value: 'name',          label: 'Label',            checked: true,  cssClass: 'd5dsh-col-preset-name-ro',      px: 584 },
			{ value: 'module',        label: 'Module',           checked: true,  cssClass: 'd5dsh-col-preset-module',       px: 90  },
			{ value: 'group_id',      label: 'Group ID',         checked: false, cssClass: 'd5dsh-col-preset-group-id',     px: 120 },
			{ value: 'is_default',    label: 'Is Default',       checked: true,  cssClass: 'd5dsh-col-preset-default',      px: 76  },
		],
		everything: [
			{ value: 'order',          label: '#',               checked: true,  cssClass: 'd5dsh-col-order',           px: 34  },
			{ value: 'note', label: 'Notes', checked: false, cssClass: 'd5dsh-col-note', px: 50 },
			{ value: 'dso_category',   label: 'Category',        checked: true,  cssClass: 'd5dsh-col-dso-category',    px: 70  },
			{ value: 'dso_type',       label: 'Type',             checked: true,  cssClass: 'd5dsh-col-dso-type',        px: 100 },
			{ value: 'dso_id',         label: 'ID',               checked: true,  cssClass: 'd5dsh-col-id',              px: 120 },
			{ value: 'dso_label',      label: 'Label',             checked: true,  cssClass: 'd5dsh-col-label',           px: 300 },
			{ value: 'dso_value',      label: 'Value',             checked: true,  cssClass: 'd5dsh-col-value',           px: 130 },
			{ value: 'dso_module',     label: 'Element / Module',  checked: true,  cssClass: 'd5dsh-col-dso-module',      px: 100 },
			{ value: 'dso_group_id',   label: 'Group ID',          checked: false, cssClass: 'd5dsh-col-dso-group-id',    px: 100 },
			{ value: 'dso_is_default', label: 'Default',           checked: true,  cssClass: 'd5dsh-col-dso-is-default',  px: 60  },
			{ value: 'dso_status',     label: 'Status',            checked: true,  cssClass: 'd5dsh-col-status',          px: 60  },
		],
	};

	/**
	 * Open the shared Print Setup modal configured for the given presets table.
	 * Mirrors openPrintSetup() for the Variables table — same UI, same margins/
	 * orientation controls — but with column choices appropriate for the table.
	 *
	 * @param {'gp'|'ep'|'all'} tableKey
	 */
	function openPresetsPrint( tableKey ) {
		var colDefs = PRESETS_PRINT_COLS[ tableKey ];
		if ( ! colDefs ) { return; }

		// Rebuild the "Columns to print" section with this table's columns.
		var colsWrap = document.querySelector( '#d5dsh-print-modal .d5dsh-print-cols' );
		if ( colsWrap ) {
			colsWrap.innerHTML = '';
			colDefs.forEach( function ( col ) {
				var lbl = document.createElement( 'label' );
				lbl.className = 'd5dsh-setting-row';
				var chk = document.createElement( 'input' );
				chk.type           = 'checkbox';
				chk.className      = 'd5dsh-print-col-chk';
				chk.value          = col.value;
				chk.checked        = col.checked;
				chk.dataset.px     = col.px || 0;
				lbl.appendChild( chk );
				lbl.appendChild( document.createTextNode( ' ' + col.label ) );
				colsWrap.appendChild( lbl );
			} );
		}

		// Update the modal title to reflect which table is being printed.
		var titleEl = document.querySelector( '#d5dsh-print-modal .d5dsh-modal-title' );
		var TABLE_TITLES = { gp: 'Group Presets', ep: 'Element Presets', all: 'All Presets', everything: 'Everything' };
		if ( titleEl ) { titleEl.textContent = 'Print Setup — ' + ( TABLE_TITLES[ tableKey ] || '' ); }

		// Tag modal with mode and tableKey.
		var presetModal = document.getElementById( 'd5dsh-print-modal' );
		if ( presetModal ) { presetModal.dataset.exportMode = 'print'; presetModal.dataset.tableKey = tableKey; }
		var goLbl = document.getElementById( 'd5dsh-print-go' );
		if ( goLbl ) { goLbl.textContent = 'Print'; }

		// Open the shared modal.
		openModal( 'd5dsh-print-modal' );

		var goBtn     = document.getElementById( 'd5dsh-print-go' );
		var cancelBtn = document.getElementById( 'd5dsh-print-cancel' );

		// Clone to remove any previously attached one-time listeners.
		var newGo     = goBtn.cloneNode( true );
		var newCancel = cancelBtn.cloneNode( true );
		goBtn.parentNode.replaceChild( newGo, goBtn );
		cancelBtn.parentNode.replaceChild( newCancel, cancelBtn );

		newCancel.addEventListener( 'click', function () {
			closeModal( 'd5dsh-print-modal' );
		} );

		newGo.addEventListener( 'click', function () {
			// Collect checked and unchecked columns.
			var hiddenClasses = [];
			var visibleCols2  = [];
			document.querySelectorAll( '.d5dsh-print-col-chk' ).forEach( function ( chk ) {
				if ( ! chk.checked ) {
					colDefs.forEach( function ( col ) {
						if ( col.value === chk.value ) { hiddenClasses.push( col.cssClass ); }
					} );
				} else {
					visibleCols2.push( { value: chk.value, px: parseInt( chk.dataset.px, 10 ) || 0 } );
				}
			} );

			var presetModalEl = document.getElementById( 'd5dsh-print-modal' );
			var pExportMode   = presetModalEl ? ( presetModalEl.dataset.exportMode || 'print' ) : 'print';

			closeModal( 'd5dsh-print-modal' );

			if ( pExportMode === 'csv' ) {
				executeExportCsv( tableKey, visibleCols2 );
				return;
			}

			// Read orientation.
			var orientationEl = document.querySelector( 'input[name="d5dsh-print-orientation"]:checked' );
			var orientation   = orientationEl ? orientationEl.value : 'portrait';

			// Read and clamp margins.
			function clampMargin( id ) {
				var el = document.getElementById( id );
				var v  = el ? parseFloat( el.value ) : 0.5;
				return Math.max( 0.3, isNaN( v ) ? 0.5 : v );
			}
			var margins = {
				top:    clampMargin( 'd5dsh-print-margin-top' ),
				right:  clampMargin( 'd5dsh-print-margin-right' ),
				bottom: clampMargin( 'd5dsh-print-margin-bottom' ),
				left:   clampMargin( 'd5dsh-print-margin-left' ),
			};

			executePresetsPrint( tableKey, hiddenClasses, orientation, margins );
		} );
	}

	/**
	 * Execute the presets print — mirrors executePrint() for Variables.
	 * Injects a temporary @media print style that isolates the target table,
	 * hides the requested columns, then triggers window.print().
	 *
	 * @param {'gp'|'ep'|'all'} tableKey
	 * @param {string[]}        hiddenClasses  CSS class names of columns to hide
	 * @param {string}          orientation    'portrait' or 'landscape'
	 * @param {object}          margins        { top, right, bottom, left } in inches
	 */
	/**
	 * Execute the presets print using the shared openPrintWindow() popup.
	 * Reads rows directly from the live DOM tbody so it works regardless of
	 * which section is currently active.
	 *
	 * @param {'gp'|'ep'|'all'} tableKey
	 * @param {string[]}        hiddenClasses  CSS class names of columns to hide
	 * @param {string}          orientation    'portrait' or 'landscape'
	 * @param {object}          margins        { top, right, bottom, left } in inches
	 */
	function executePresetsPrint( tableKey, hiddenClasses, orientation, margins ) {
		var colDefs   = PRESETS_PRINT_COLS[ tableKey ] || [];
		var hiddenSet = {};
		hiddenClasses.forEach( function ( cls ) { hiddenSet[ cls ] = true; } );
		var visCols   = colDefs.filter( function ( c ) { return ! hiddenSet[ c.cssClass ]; } );

		// Proportional widths.
		var totalPx = visCols.reduce( function ( s, c ) { return s + ( c.px || 0 ); }, 0 );
		var pctMap  = {};
		if ( totalPx > 0 ) {
			visCols.forEach( function ( c ) {
				pctMap[ c.cssClass ] = Math.round( ( c.px / totalPx ) * 100 );
			} );
		}

		// Build thead.
		var thead = '<thead><tr>';
		visCols.forEach( function ( c ) {
			var align = ( c.cssClass === 'd5dsh-col-order' || c.cssClass === 'd5dsh-col-preset-default' )
				? 'text-align:center;' : '';
			var w = pctMap[ c.cssClass ] ? 'width:' + pctMap[ c.cssClass ] + '%;' : '';
			thead += '<th style="' + align + w + '">' + escHtml( c.label ) + '</th>';
		} );
		thead += '</tr></thead>';

		// Pull rows from live DOM.
		var tbodyId = 'd5dsh-presets-' + tableKey + '-tbody';
		var tbodyEl = document.getElementById( tbodyId );
		var tbody   = '';
		var allRows = tbodyEl ? tbodyEl.querySelectorAll( 'tr' ) : [];
		allRows.forEach( function ( tr ) {
			var cells = tr.querySelectorAll( 'td' );
			if ( ! cells.length ) { return; }
			tbody += '<tr>';
			colDefs.forEach( function ( c, i ) {
				if ( hiddenSet[ c.cssClass ] ) { return; }
				var cell  = cells[ i ];
				var text  = cell ? ( cell.dataset.value || cell.textContent || '' ).trim() : '';
				var align = ( c.cssClass === 'd5dsh-col-order' || c.cssClass === 'd5dsh-col-preset-default' )
					? ' style="text-align:center"' : '';
				tbody += '<td' + align + '>' + escHtml( text ) + '</td>';
			} );
			tbody += '</tr>';
		} );

		var TABLE_TITLES = { gp: 'Group Presets', ep: 'Element Presets', all: 'All Presets', everything: 'Everything' };
		var bodyHtml = '<table>' + thead + '<tbody>' + tbody + '</tbody></table>';
		openPrintWindow( bodyHtml, TABLE_TITLES[ tableKey ] || 'Presets', orientation, margins );
	}

	// ── Click-to-copy for truncated cells ────────────────────────────────────

	/**
	 * Wire click-to-copy on any element with class d5dsh-copy-cell.
	 * Copies the element's title attribute (the full/untruncated value) to
	 * the clipboard and shows a brief "Copied!" flash tooltip.
	 * Uses delegated event on document so it works for dynamically-rendered rows.
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 11 — COLUMN RESIZE & SMART SIZING                            ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initClickToCopy() {
		document.addEventListener( 'click', function ( e ) {
			var cell = e.target.closest( '.d5dsh-copy-cell' );
			if ( ! cell ) { return; }
			// Only copy when the user isn't clicking an interactive child.
			if ( e.target.tagName === 'INPUT' || e.target.tagName === 'BUTTON' || e.target.tagName === 'A' ) { return; }

			var text = cell.title || cell.textContent || '';
			if ( ! text.trim() ) { return; }

			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function () {
					showCopyFlash( cell );
				} ).catch( function () {
					legacyCopy( text );
					showCopyFlash( cell );
				} );
			} else {
				legacyCopy( text );
				showCopyFlash( cell );
			}
		} );
	}

	function legacyCopy( text ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
		document.body.appendChild( ta );
		ta.select();
		try { document.execCommand( 'copy' ); } catch(e) {}
		document.body.removeChild( ta );
	}

	function showCopyFlash( cell ) {
		var existing = cell.querySelector( '.d5dsh-copy-flash' );
		if ( existing ) { return; }
		var flash = document.createElement( 'span' );
		flash.className   = 'd5dsh-copy-flash';
		flash.textContent = 'Copied!';
		cell.appendChild( flash );
		setTimeout( function () {
			if ( flash.parentNode ) { flash.parentNode.removeChild( flash ); }
		}, 1400 );
	}

	// ── Scroll jumpers (▲ / ▼ beside each table wrap) ────────────────────────

	/**
	 * Wire all .d5dsh-scroll-jumpers groups.
	 * Each group has a data-target pointing to the scrollable wrap's ID.
	 * Called once on DOMContentLoaded; the buttons are always present in HTML.
	 */
	function initScrollJumpers() {
		document.querySelectorAll( '.d5dsh-scroll-jumpers' ).forEach( function ( group ) {
			var targetId = group.dataset.target;
			if ( ! targetId ) { return; }

			var topBtn = group.querySelector( '.d5dsh-jump-top' );
			var botBtn = group.querySelector( '.d5dsh-jump-bottom' );

			if ( topBtn ) {
				topBtn.addEventListener( 'click', function () {
					var el = document.getElementById( targetId );
					if ( el ) { el.scrollTop = 0; }
				} );
			}
			if ( botBtn ) {
				botBtn.addEventListener( 'click', function () {
					var el = document.getElementById( targetId );
					if ( el ) { el.scrollTop = el.scrollHeight; }
				} );
			}
		} );
	}

	// ── Column sizing ─────────────────────────────────────────────────────────
	//
	// Single function that owns ALL column widths for d5dsh-manage-table tables.
	// Each <th> declares data-w (default px width) and data-max (max px width).
	// Exactly one <th> per table may carry data-flex — it absorbs remaining space.
	// localStorage stores only user-dragged overrides; Reset clears them and
	// reapplies the declared defaults from markup.
	//
	// Usage: initTableColumns( tableId, storageKey )

	/**
	 * Apply column widths from data-w / data-max / data-flex attributes,
	 * add drag-resize handles, and persist overrides to localStorage.
	 *
	 * @param {string} tableId    ID of the <table> element.
	 * @param {string} storageKey localStorage key for persisted overrides.
	 */
	function initTableColumns( tableId, storageKey ) {
		var table = document.getElementById( tableId );
		if ( ! table ) { return; }

		var ths = Array.prototype.slice.call( table.querySelectorAll( 'thead tr:first-child th' ) );
		if ( ! ths.length ) { return; }

		var MIN_W = 40;

		// ── helpers ──

		function applyDefaults() {
			var totalW = 0;

			ths.forEach( function ( th ) {
				var w   = parseInt( th.dataset.w || '0', 10 ) || MIN_W;
				var max = parseInt( th.dataset.max || '9999', 10 );
				w = Math.min( w, max );
				th.style.width    = w + 'px';
				th.style.maxWidth = w + 'px';
				totalW += w;
			} );

			table.style.width    = totalW + 'px';
			table.style.minWidth = '';
		}

		function persistWidths() {
			if ( ! storageKey ) { return; }
			var widths = {};
			ths.forEach( function ( th, i ) { widths[ i ] = th.offsetWidth; } );
			try { localStorage.setItem( storageKey, JSON.stringify( widths ) ); } catch(e) {}
		}

		function restoreSaved() {
			var saved = {};
			try { saved = JSON.parse( localStorage.getItem( storageKey ) || '{}' ); } catch(e) {}
			var any = false;
			ths.forEach( function ( th, i ) {
				if ( saved[ i ] !== undefined ) {
					var max = parseInt( th.dataset.max || '9999', 10 );
					th.style.width    = Math.min( saved[ i ], max ) + 'px';
					th.style.maxWidth = th.style.width;
					any = true;
				}
			} );
			if ( any ) {
				var newTotal = 0;
				ths.forEach( function ( th ) { newTotal += parseInt( th.style.width, 10 ) || 0; } );
				table.style.width    = newTotal + 'px';
				table.style.minWidth = '';
			}
			return any;
		}

		// ── apply widths ──

		applyDefaults();
		restoreSaved(); // override with any user-dragged widths (clamped to max)
		syncTdWidths( tableId );

		// ── drag handles ──

		ths.forEach( function ( th ) {
			var handle = document.createElement( 'div' );
			handle.className = 'd5dsh-col-resize-handle';
			th.appendChild( handle );

			var startX, startW;

			handle.addEventListener( 'mousedown', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				startX = e.clientX;
				startW = th.offsetWidth;
				document.body.classList.add( 'd5dsh-resizing' );

				ths.forEach( function ( t ) {
					if ( t !== th ) { t.style.width = t.offsetWidth + 'px'; }
				} );

				function onMove( ev ) {
					var max   = parseInt( th.dataset.max || '9999', 10 );
					var delta = ev.clientX - startX;
					var newW  = Math.max( MIN_W, Math.min( max, startW + delta ) );
					th.style.width    = newW + 'px';
					th.style.maxWidth = newW + 'px';
					var newTotal = 0;
					ths.forEach( function ( t ) { newTotal += parseInt( t.style.width, 10 ) || 0; } );
					table.style.width = newTotal + 'px';
				}

				function onUp() {
					document.body.classList.remove( 'd5dsh-resizing' );
					document.removeEventListener( 'mousemove', onMove );
					document.removeEventListener( 'mouseup',   onUp   );
					syncTdWidths( tableId );
					persistWidths();
				}

				document.addEventListener( 'mousemove', onMove );
				document.addEventListener( 'mouseup',   onUp   );
			} );
		} );

		// ── Reset button ──

		var section = table.closest( '.d5dsh-manage-section, #d5dsh-manage-table-wrap, .d5dsh-section-inner' );
		if ( section ) {
			var resetBtn = section.querySelector( '[id$="-reset-view"], #d5dsh-reset-view, #d5dsh-cat-clear-filters-btn' );
			if ( resetBtn ) {
				resetBtn.addEventListener( 'click', function () {
					try { localStorage.removeItem( storageKey ); } catch(e) {}
					ths.forEach( function ( th ) { th.style.width = ''; th.style.maxWidth = ''; } );
					table.style.width = '';
					table.style.minWidth = '';
					requestAnimationFrame( function() { applyDefaults(); syncTdWidths( tableId ); } );
				} );
			}
		}
	}

	/**
	 * Re-apply default column widths for a table (e.g. after data loads).
	 * Reads data-w / data-max / data-flex from markup; restores localStorage overrides.
	 */
	function refreshTableColumns( tableId, storageKey ) {
		var table = document.getElementById( tableId );
		if ( ! table ) { return; }
		var ths = Array.prototype.slice.call( table.querySelectorAll( 'thead tr:first-child th' ) );
		if ( ! ths.length ) { return; }
		var MIN_W = 40;

		var totalW = 0;
		ths.forEach( function ( th ) {
			var w   = parseInt( th.dataset.w || '0', 10 ) || MIN_W;
			var max = parseInt( th.dataset.max || '9999', 10 );
			w = Math.min( w, max );
			th.style.width    = w + 'px';
			th.style.maxWidth = w + 'px';
			totalW += w;
		} );
		table.style.width    = totalW + 'px';
		table.style.minWidth = '';

		// Restore saved overrides and recompute table width.
		var saved = {};
		try { saved = JSON.parse( localStorage.getItem( storageKey ) || '{}' ); } catch(e) {}
		var anyOverride = false;
		ths.forEach( function ( th, i ) {
			if ( saved[ i ] !== undefined ) {
				var max = parseInt( th.dataset.max || '9999', 10 );
				th.style.width    = Math.min( saved[ i ], max ) + 'px';
				th.style.maxWidth = th.style.width;
				anyOverride = true;
			}
		} );
		if ( anyOverride ) {
			var newTotal = 0;
			ths.forEach( function ( th ) { newTotal += parseInt( th.style.width, 10 ) || 0; } );
			table.style.width = newTotal + 'px';
		}

		syncTdWidths( tableId );
	}

	/**
	 * Sync <th> inline widths down to every <td> in each column.
	 * Required because table-layout:fixed alone doesn't prevent <td> content
	 * from overflowing when the cell contains an inline element.
	 */
	function syncTdWidths( tableId ) {
		var table = document.getElementById( tableId );
		if ( ! table ) { return; }
		var ths = Array.prototype.slice.call( table.querySelectorAll( 'thead tr:first-child th' ) );
		var rows = Array.prototype.slice.call( table.querySelectorAll( 'tbody tr' ) );
		rows.forEach( function ( tr ) {
			var tds = tr.querySelectorAll( 'td' );
			ths.forEach( function ( th, i ) {
				if ( tds[ i ] ) {
					tds[ i ].style.width    = th.style.width;
					tds[ i ].style.maxWidth = th.style.width;
				}
			} );
		} );
	}

	/**
	 * Persist all column widths from a table's <th> elements to localStorage.
	 */
	function _persistColWidths( table, ths, storageKey ) {
		if ( ! storageKey ) { return; }
		var widths = {};
		ths.forEach( function ( th, i ) { widths[ i ] = th.offsetWidth; } );
		try { localStorage.setItem( storageKey, JSON.stringify( widths ) ); } catch(e) {}
	}

	/**
	 * Add ↔ expand/collapse toggle icons to specified columns of a table.
	 * Black = at default width; red = expanded to fit all content.
	 *
	 * @param {string}   tableId          ID of the <table> element.
	 * @param {string}   storageKey       localStorage key used by initTableColumns.
	 * @param {number[]} expandColIndices 0-based indices of th elements that need a toggle.
	 */
	function initColExpandToggles( tableId, storageKey, expandColIndices ) {
		var table = document.getElementById( tableId );
		if ( ! table ) { return; }
		var ths = Array.prototype.slice.call( table.querySelectorAll( 'thead tr:first-child th' ) );
		if ( ! ths.length ) { return; }

		var canvas    = document.createElement( 'canvas' );
		var ctx       = canvas.getContext( '2d' );
		var CELL_FONT = '13px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		var H_PAD     = 24;
		var MAX_EXP   = 500;

		function measureCol( colIdx ) {
			var rows = Array.prototype.slice.call( table.querySelectorAll( 'tbody tr' ) );
			ctx.font = CELL_FONT;
			var maxW = 0;
			rows.forEach( function ( tr ) {
				var cell = tr.querySelectorAll( 'td' )[ colIdx ];
				if ( ! cell ) { return; }
				var text = ( cell.dataset.smartValue !== undefined
					? cell.dataset.smartValue
					: ( cell.dataset.value || cell.textContent || '' ) ).trim();
				var w = ctx.measureText( text ).width + H_PAD;
				if ( w > maxW ) { maxW = w; }
			} );
			return Math.min( MAX_EXP, Math.max( 40, Math.ceil( maxW ) ) );
		}

		expandColIndices.forEach( function ( idx ) {
			var th = ths[ idx ];
			if ( ! th ) { return; }

			var icon       = document.createElement( 'span' );
			icon.className = 'd5dsh-col-expand-toggle';
			icon.textContent = '\u2194'; // ↔
			icon.title     = 'Expand column to fit content';
			th.appendChild( icon );

			icon.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var expanded = th.dataset.colExpanded === '1';
				if ( ! expanded ) {
					// Snapshot current width before expanding.
					if ( ! th.dataset.colSnapW ) { th.dataset.colSnapW = th.offsetWidth; }
					var fitW = measureCol( idx );
					th.style.maxWidth = fitW + 'px';
					th.style.width    = fitW + 'px';
					th.dataset.colExpanded = '1';
					icon.classList.add( 'is-expanded' );
					icon.title = 'Collapse column to default width';
					var expTotal = 0;
					ths.forEach( function ( t ) { expTotal += parseInt( t.style.width, 10 ) || 0; } );
					table.style.width = expTotal + 'px';
					table.style.minWidth = '';
				} else {
					// Collapse back to snapshot or data-w default.
					var defW = parseInt( th.dataset.colSnapW || th.dataset.w || '100', 10 );
					var max  = parseInt( th.dataset.max || '9999', 10 );
					th.style.width    = defW + 'px';
					th.style.maxWidth = defW + 'px';
					delete th.dataset.colSnapW;
					th.dataset.colExpanded = '0';
					icon.classList.remove( 'is-expanded' );
					icon.title = 'Expand column to fit content';
					var colTotal = 0;
					ths.forEach( function ( t ) { colTotal += parseInt( t.style.width, 10 ) || 0; } );
					table.style.width    = colTotal + 'px';
					table.style.minWidth = '';
				}
				_persistColWidths( table, ths, storageKey );
			} );
		} );

		// Wire Reset button to collapse all expanded columns.
		var section  = table.closest( '.d5dsh-manage-section, #d5dsh-manage-table-wrap, .d5dsh-section-inner' );
		if ( section ) {
			var resetBtn = section.querySelector( '[id$="-reset-view"], #d5dsh-reset-view, #d5dsh-cat-clear-filters-btn' );
			if ( resetBtn ) {
				resetBtn.addEventListener( 'click', function () {
					expandColIndices.forEach( function ( idx ) {
						var th   = ths[ idx ];
						var icon = th ? th.querySelector( '.d5dsh-col-expand-toggle' ) : null;
						if ( th ) {
							th.dataset.colExpanded = '0';
							delete th.dataset.colSnapW;
						}
						if ( icon ) {
							icon.classList.remove( 'is-expanded' );
							icon.title = 'Expand column to fit content';
						}
					} );
					// initTableColumns reset handler already clears localStorage and re-applies defaults.
				} );
			}
		}
	}

	// ── Presets: bulk label change ────────────────────────────────────────────
	//
	// Wires View / Bulk Label Change mode switcher for a presets section.
	// tableKey: 'gp' | 'ep'
	//
	// Reads and writes presetsData.group_presets[].name (gp)
	//   or presetsData.element_presets[].name (ep)
	// Uses the same makeBulkTransform() / normalizeCase() as the variables tab.

	var presetsBulkState = {
		gp: { op: null, previewActive: false },
		ep: { op: null, previewActive: false },
	};

	// Original data for bulk undo per section (separate from presetsOriginal which
	// is the last-saved server state).
	var presetsBulkOriginal = { gp: null, ep: null };

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 14 — PRESETS: BULK OPERATIONS, SAVE/DISCARD & FILTERS        ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initPresetsBulk( tableKey ) {
		// Mode switcher buttons.
		var modeBtns = document.querySelectorAll(
			'#d5dsh-presets-' + tableKey + '-mode-switcher .d5dsh-presets-mode-btn'
		);
		modeBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				setPresetsMode( tableKey, btn.dataset.mode );
			} );
		} );

		// Operation selector.
		var opSel = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-op' );
		if ( opSel ) {
			opSel.addEventListener( 'change', function () {
				updatePresetsBulkFields( tableKey );
			} );
		}

		// Preview / Apply / Undo buttons.
		var previewBtn = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-preview' );
		var applyBtn   = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-apply' );
		var undoBtn    = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-undo' );

		if ( previewBtn ) {
			previewBtn.addEventListener( 'click', function () {
				applyPresetsBulkPreview( tableKey );
			} );
		}
		if ( applyBtn ) {
			applyBtn.addEventListener( 'click', function () {
				// "Apply" just confirms — Save button commits.
				if ( applyBtn ) {
					applyBtn.textContent = 'Applied ✓';
					setTimeout( function () { applyBtn.textContent = 'Apply'; }, 2000 );
				}
			} );
		}
		if ( undoBtn ) {
			undoBtn.addEventListener( 'click', function () {
				undoPresetsBulkPreview( tableKey );
			} );
		}
	}

	function setPresetsMode( tableKey, mode ) {
		// Update button active classes.
		var modeBtns = document.querySelectorAll(
			'#d5dsh-presets-' + tableKey + '-mode-switcher .d5dsh-presets-mode-btn'
		);
		modeBtns.forEach( function ( btn ) {
			btn.classList.toggle( 'd5dsh-mode-active', btn.dataset.mode === mode );
		} );

		// Show/hide bulk controls.
		var controls = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-controls' );
		if ( controls ) { controls.style.display = mode === 'manage' ? '' : 'none'; }

		// If leaving manage mode and preview is active, undo it.
		if ( mode !== 'manage' && presetsBulkState[ tableKey ].previewActive ) {
			undoPresetsBulkPreview( tableKey );
		}

		// Reset op selector when leaving manage mode.
		if ( mode !== 'manage' ) {
			var opSel = document.getElementById( 'd5dsh-presets-' + tableKey + '-bulk-op' );
			if ( opSel ) { opSel.value = ''; updatePresetsBulkFields( tableKey ); }
		}

		// Re-render so Label cells switch between plain text (View) and inputs (Bulk Label Change).
		if ( presetsDataLoaded ) { renderPresetsTable( tableKey ); }
	}

	function updatePresetsBulkFields( tableKey ) {
		var pfx = 'd5dsh-presets-' + tableKey;
		var op  = ( document.getElementById( pfx + '-bulk-op' ) || {} ).value || '';
		var ops = [ 'prefix', 'suffix', 'find_replace', 'normalize' ];
		ops.forEach( function ( o ) {
			var el = document.getElementById( pfx + '-fields-' + o );
			if ( el ) { el.style.display = o === op ? 'inline' : 'none'; }
		} );
		var actionsEl = document.getElementById( pfx + '-bulk-actions' );
		if ( actionsEl ) { actionsEl.style.display = op ? 'inline-flex' : 'none'; }

		// Reset preview state when op changes.
		presetsBulkState[ tableKey ].previewActive = false;
		updatePresetsBulkBtnStates( tableKey );
	}

	function updatePresetsBulkBtnStates( tableKey ) {
		var pfx        = 'd5dsh-presets-' + tableKey;
		var active     = presetsBulkState[ tableKey ].previewActive;
		var previewBtn = document.getElementById( pfx + '-bulk-preview' );
		var applyBtn   = document.getElementById( pfx + '-bulk-apply' );
		var undoBtn    = document.getElementById( pfx + '-bulk-undo' );
		if ( previewBtn ) {
			previewBtn.textContent = active ? 'Undo Preview' : 'Preview';
			previewBtn.classList.toggle( 'd5dsh-bulk-preview-active', active );
		}
		if ( applyBtn ) { applyBtn.disabled = ! active; }
		if ( undoBtn  ) { undoBtn.disabled  = ! active; }
	}

	function applyPresetsBulkPreview( tableKey ) {
		if ( presetsBulkState[ tableKey ].previewActive ) {
			undoPresetsBulkPreview( tableKey );
			return;
		}

		if ( ! presetsData ) { return; }

		var pfx = 'd5dsh-presets-' + tableKey;
		var op  = ( document.getElementById( pfx + '-bulk-op' ) || {} ).value || '';
		if ( ! op ) { return; }

		var bulk = { op: op };
		if ( op === 'prefix'      ) { bulk.value   = ( document.getElementById( pfx + '-bulk-prefix-value' ) || {} ).value || ''; }
		if ( op === 'suffix'      ) { bulk.value   = ( document.getElementById( pfx + '-bulk-suffix-value' ) || {} ).value || ''; }
		if ( op === 'find_replace') { bulk.find    = ( document.getElementById( pfx + '-bulk-find'    ) || {} ).value || '';
		                              bulk.replace  = ( document.getElementById( pfx + '-bulk-replace' ) || {} ).value || ''; }
		if ( op === 'normalize'   ) { bulk.case    = ( document.getElementById( pfx + '-bulk-case'    ) || {} ).value || 'title'; }

		var transform = makeBulkTransform( bulk );
		if ( ! transform ) { return; }

		// Snapshot current state for undo.
		presetsBulkOriginal[ tableKey ] = deepClone( presetsData );

		// Apply transform in-memory to the appropriate list.
		var list = tableKey === 'gp' ? presetsData.group_presets : presetsData.element_presets;
		list.forEach( function ( item ) {
			item.name = transform( item.name || '' );
		} );

		// Mark all items as dirty so Save bar appears.
		list.forEach( function ( item ) {
			var dirtyObj = tableKey === 'gp' ? presetsDirtyGP : presetsDirtyEP;
			dirtyObj[ item.preset_id ] = {
				preset_id:   item.preset_id,
				module_name: item.module_name || '',
				group_id:    item.group_id    || '',
				name:        item.name,
			};
		} );

		presetsBulkState[ tableKey ].previewActive = true;
		updatePresetsBulkBtnStates( tableKey );
		renderPresetsTable( tableKey );
		renderPresetsTable( 'all' );
		updatePresetsSaveBar( tableKey );
	}

	function undoPresetsBulkPreview( tableKey ) {
		if ( presetsBulkOriginal[ tableKey ] ) {
			presetsData = presetsBulkOriginal[ tableKey ];
			presetsBulkOriginal[ tableKey ] = null;
		}
		if ( tableKey === 'gp' ) { presetsDirtyGP = {}; } else { presetsDirtyEP = {}; }
		presetsBulkState[ tableKey ].previewActive = false;
		updatePresetsBulkBtnStates( tableKey );
		renderPresetsTable( tableKey );
		renderPresetsTable( 'all' );
		updatePresetsSaveBar( tableKey );
	}

	// ── Simple Import ─────────────────────────────────────────────────────────

	/**
	 * State for the Simple Import flow.
	 *
	 *   siState       — 'idle' | 'analyzing' | 'analysis' | 'error' | 'importing'
	 *   siManifest    — last analysis response from server
	 *   siImportResults — last import results from server
	 */
	var siState          = 'idle';
	var siManifest       = null;
	var siImportResults  = null;
	var siLastFilename   = '';   // Original filename of the last analyzed import file.
	var siLabelOverrides = {};  // { fileKey: { dsoId: newLabel } }

	/**
	 * Initialise the Simple Import UI.
	 * Only runs when the drop zone is present (i.e. on the Import tab).
	 */
	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 17 — SIMPLE IMPORT TAB                                       ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initSimpleImport() {
		var dropzone  = document.getElementById( 'd5dsh-si-dropzone' );
		var fileInput = document.getElementById( 'd5dsh-si-file-input' );
		if ( ! dropzone || ! fileInput ) { return; }

		// Click on dropzone → open file browser.
		dropzone.addEventListener( 'click', function ( e ) {
			if ( e.target === fileInput ) { return; }
			fileInput.click();
		} );
		dropzone.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' || e.key === ' ' ) { fileInput.click(); }
		} );

		// Drag-and-drop support.
		dropzone.addEventListener( 'dragover', function ( e ) {
			e.preventDefault();
			dropzone.classList.add( 'd5dsh-si-dropzone-over' );
		} );
		dropzone.addEventListener( 'dragleave', function () {
			dropzone.classList.remove( 'd5dsh-si-dropzone-over' );
		} );
		dropzone.addEventListener( 'drop', function ( e ) {
			e.preventDefault();
			dropzone.classList.remove( 'd5dsh-si-dropzone-over' );
			var files = e.dataTransfer && e.dataTransfer.files;
			if ( files && files.length ) {
				siStartAnalysis( files[0] );
			}
		} );

		// File input change.
		fileInput.addEventListener( 'change', function () {
			if ( fileInput.files && fileInput.files.length ) {
				siStartAnalysis( fileInput.files[0] );
				fileInput.value = ''; // Reset so same file can be re-selected.
			}
		} );

		// Reset buttons (Go Back).
		document.querySelectorAll( '.d5dsh-si-reset-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', siReset );
		} );

		// Import button.
		var importBtn = document.getElementById( 'd5dsh-si-import-btn' );
		if ( importBtn ) {
			importBtn.addEventListener( 'click', siExecuteImport );
		}

		// Action-row Convert to Excel button.
		var convertBtn = document.getElementById( 'd5dsh-si-convert-btn' );
		if ( convertBtn ) {
			convertBtn.addEventListener( 'click', function () {
				siConvertToXlsx( '', convertBtn );
			} );
		}

		// Action-row Audit button — runs audit and downloads a CSV report directly.
		var auditBtn = document.getElementById( 'd5dsh-si-audit-btn' );
		if ( auditBtn ) {
			auditBtn.addEventListener( 'click', function () {
				var origText = auditBtn.textContent;
				auditBtn.disabled    = true;
				auditBtn.textContent = 'Running\u2026';

				var fd = new FormData();
				fd.append( 'action', 'd5dsh_audit_run' );
				fd.append( 'nonce',  d5dtAudit.nonce );

				fetch( d5dtAudit.ajaxUrl, { method: 'POST', body: fd } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( json ) {
						if ( ! json.success ) {
							showToast( 'error', 'Audit failed', ( json.data && json.data.message ) || 'Unknown error' );
							return;
						}
						var data  = json.data;
						auditStreamXlsx( data );

						auditBtn.textContent = 'Done!';
						setTimeout( function () {
							auditBtn.disabled    = false;
							auditBtn.textContent = origText;
						}, 2500 );
					} )
					.catch( function () {
						showToast( 'error', 'Audit failed', 'Network error — could not reach server.' );
						auditBtn.disabled    = false;
						auditBtn.textContent = origText;
					} );
			} );
		}

		// Header Print / Download buttons.
		var headerPrintBtn    = document.getElementById( 'd5dsh-si-print-report-btn' );
		var headerDownloadBtn = document.getElementById( 'd5dsh-si-download-report-btn' );
		if ( headerPrintBtn ) {
			headerPrintBtn.addEventListener( 'click', function () {
				var depEl  = document.getElementById( 'd5dsh-si-dep-report' );
				var report = depEl && depEl._depReport;
				var name   = depEl && depEl._depFilename || 'report';
				if ( report ) {
					siPrintDepReport( report, name );
				}
			} );
		}
		if ( headerDownloadBtn ) {
			headerDownloadBtn.addEventListener( 'click', function () {
				var depEl  = document.getElementById( 'd5dsh-si-dep-report' );
				var report = depEl && depEl._depReport;
				var name   = depEl && depEl._depFilename || 'report';
				if ( report ) {
					siDownloadDepReportCsv( report, name );
				}
			} );
		}

		// Select-all checkbox.
		var checkAll = document.getElementById( 'd5dsh-si-check-all' );
		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				document.querySelectorAll( '.d5dsh-si-file-chk:not(:disabled)' ).forEach( function ( chk ) {
					chk.checked = checkAll.checked;
				} );
				siUpdateSelectionCount();
			} );
		}

		// Save report button.
		var saveReportBtn = document.getElementById( 'd5dsh-si-save-report-btn' );
		if ( saveReportBtn ) {
			saveReportBtn.addEventListener( 'click', siSaveReport );
		}
	}

	/**
	 * Begin analysis: upload file to server and display results.
	 *
	 * @param {File} file
	 */
	function siStartAnalysis( file ) {
		if ( typeof d5dtSimpleImport === 'undefined' ) {
			siShowError( 'Simple Import is not configured. Please reload the page.' );
			return;
		}

		siLastFilename = file.name || '';
		siShowAnalyzing();

		var fd = new FormData();
		fd.append( 'action', 'd5dsh_simple_analyze' );
		fd.append( 'nonce',  d5dtSimpleImport.nonce );
		fd.append( 'file',   file );

		fetch( d5dtSimpleImport.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! json.success ) {
					siShowError( ( json.data && json.data.message ) || 'Analysis failed.' );
					return;
				}
				siManifest = json.data;
				siShowAnalysis( siManifest );
			} )
			.catch( function ( err ) {
				siShowError( 'Network error: ' + err.message );
			} );
	}

	/**
	 * Execute the import for selected files.
	 */
	function siExecuteImport() {
		if ( typeof d5dtSimpleImport === 'undefined' ) { return; }

		// Gate: require save/discard of any pending variable changes before importing.
		if ( hasPendingChanges() > 0 ) {
			requireCleanState( 'importing' );
			return;
		}

		var selectedKeys = siGetSelectedKeys();
		if ( ! selectedKeys.length ) {
			showToast( 'error', 'Nothing selected', 'Please select at least one file to import.' );
			return;
		}

		var importBtn   = document.getElementById( 'd5dsh-si-import-btn' );
		var spinner     = document.getElementById( 'd5dsh-si-import-spinner' );
		if ( importBtn ) { importBtn.disabled = true; }
		if ( spinner )   { spinner.style.display = 'inline-block'; }

		var executeUrl = d5dtSimpleImport.ajaxUrl
			+ '?action=d5dsh_simple_execute'
			+ '&nonce=' + encodeURIComponent( d5dtSimpleImport.nonce );

		fetch( executeUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( {
				selected_keys: selectedKeys,
				label_overrides: siLabelOverrides,
				conflict_resolutions: siConflictResolutions,
			} ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( importBtn ) { importBtn.disabled = false; }
			if ( spinner )   { spinner.style.display = 'none'; }
			if ( ! json.success ) {
				siShowError( ( json.data && json.data.message ) || 'Import failed.' );
				return;
			}
			siImportResults = json.data;
			siShowResultsModal( siImportResults );
		} )
		.catch( function ( err ) {
			if ( importBtn ) { importBtn.disabled = false; }
			if ( spinner )   { spinner.style.display = 'none'; }
			siShowError( 'Network error: ' + err.message );
		} );
	}

	// ── State helpers ─────────────────────────────────────────────────────────

	function siShowAnalyzing() {
		siSetVisibility( 'analyzing' );
	}

	function siShowError( message ) {
		var msgEl = document.getElementById( 'd5dsh-si-error-msg' );
		if ( msgEl ) { msgEl.textContent = message; }
		siSetVisibility( 'error' );
	}

	function siShowAnalysis( manifest ) {
		var isZip    = manifest.files && manifest.files.length > 1;
		var isSingle = manifest.files && manifest.files.length === 1;

		siSetVisibility( 'analysis' );

		// Show header utility icons now that a report is available.
		var headerUtils = document.getElementById( 'd5dsh-si-header-utils' );
		if ( headerUtils ) { headerUtils.style.display = ''; }

		// Select-all wrapper: visible for zip, hidden for single file.
		var checkAllWrap = document.getElementById( 'd5dsh-si-check-all-wrap' );
		if ( checkAllWrap ) {
			checkAllWrap.style.display = isZip ? '' : 'none';
		}

		if ( isZip ) {
			// Inject expand/collapse all controls into the select-all row.
			var checkAllWrapEl = document.getElementById( 'd5dsh-si-check-all-wrap' );
			if ( checkAllWrapEl && ! checkAllWrapEl.querySelector( '.d5dsh-mfc-bulk-controls' ) ) {
				var bulkCtrl = document.createElement( 'div' );
				bulkCtrl.className = 'd5dsh-mfc-bulk-controls';
				bulkCtrl.innerHTML =
					'<button type="button" class="d5dsh-icon-btn d5dsh-mfc-expand-all-btn" title="Expand all cards">&#8661;</button>';
				checkAllWrapEl.appendChild( bulkCtrl );

				bulkCtrl.querySelector( '.d5dsh-mfc-expand-all-btn' ).addEventListener( 'click', function () {
					var allBodies = document.querySelectorAll( '.d5dsh-mfc-body' );
					var allCollapseBtns = document.querySelectorAll( '.d5dsh-mfc-collapse-btn' );
					// Determine current state: if all expanded → collapse all; else expand all.
					var allExpanded = true;
					allCollapseBtns.forEach( function ( btn ) {
						if ( btn.getAttribute( 'aria-expanded' ) !== 'true' ) { allExpanded = false; }
					} );
					allBodies.forEach( function ( b, i ) {
						var btn = allCollapseBtns[ i ];
						if ( allExpanded ) {
							// Collapse all.
							b.hidden = true;
							if ( btn ) {
								btn.setAttribute( 'aria-expanded', 'false' );
								var chev = btn.querySelector( '.d5dsh-mfc-chevron' );
								if ( chev ) { chev.innerHTML = '&#9658;'; }
							}
						} else {
							// Expand all.
							b.hidden = false;
							if ( btn ) {
								btn.setAttribute( 'aria-expanded', 'true' );
								var chev = btn.querySelector( '.d5dsh-mfc-chevron' );
								if ( chev ) { chev.innerHTML = '&#9660;'; }
							}
						}
					} );
				} );
			}

			// Show file list.
			var fileListEl = document.getElementById( 'd5dsh-si-file-list' );
			var rowsEl     = document.getElementById( 'd5dsh-si-file-rows' );
			if ( fileListEl ) { fileListEl.style.display = ''; }
			if ( rowsEl ) {
				rowsEl.innerHTML = '';
				manifest.files.forEach( function ( fi, idx ) {
					rowsEl.appendChild( siBuildFileRow( fi, 'd5dsh-mfc-' + idx ) );
				} );
			}
			siUpdateSelectionCount();

			// Wire individual checkboxes.
			document.querySelectorAll( '.d5dsh-si-file-chk' ).forEach( function ( chk ) {
				chk.addEventListener( 'change', siUpdateSelectionCount );
			} );

			// Update import button label for zip.
			var importBtnZip = document.getElementById( 'd5dsh-si-import-btn' );
			if ( importBtnZip ) { importBtnZip.textContent = 'Import Selected'; }

		} else if ( isSingle ) {
			var fi          = manifest.files[0];
			var summaryEl   = document.getElementById( 'd5dsh-si-single-summary' );
			var summaryBody = document.getElementById( 'd5dsh-si-single-summary-body' );

			if ( summaryEl ) { summaryEl.style.display = ''; }

			if ( summaryBody && fi.valid ) {
				summaryBody.innerHTML = siBuildSingleSummaryHTML( fi );

				// Wire breakdown toggle button.
				var breakdownBtn = summaryBody.querySelector( '.d5dsh-ufc-breakdown-btn' );
				var breakdownDiv = summaryBody.querySelector( '#d5dsh-ufc-breakdown' );
				if ( breakdownBtn && breakdownDiv ) {
					breakdownBtn.addEventListener( 'click', function () {
						var exp = breakdownBtn.getAttribute( 'aria-expanded' ) === 'true';
						breakdownBtn.setAttribute( 'aria-expanded', ! exp );
						breakdownDiv.hidden = exp;
						var chev = breakdownBtn.querySelector( '.d5dsh-ufc-chevron' );
						if ( chev ) { chev.innerHTML = exp ? '&#9658;' : '&#9660;'; }
					} );
				}

				// Render Preliminary Analysis (injects into #d5dsh-si-dep-report inside the card).
				siRenderDepReport( fi.dependency_report || null, fi.name || '' );

				// Render label/ID conflict resolution panel if conflicts exist.
				siRenderConflictPanel( fi, summaryBody );

				// Inject label editor for vars / presets files that expose an items list.
				if ( fi.items && fi.items.length ) {
					var labelEditorEl = siBuildLabelEditor( fi, 'd5dsh-ufc' );
					if ( labelEditorEl ) { summaryBody.appendChild( labelEditorEl ); }
				}

			} else if ( summaryBody ) {
				summaryBody.innerHTML = siBuildSingleSummaryHTML( fi );
			}

			// Configure the action-row "Convert to Excel" button based on file format.
			var convertBtn = document.getElementById( 'd5dsh-si-convert-btn' );
			if ( convertBtn ) {
				if ( fi.format === 'json' ) {
					convertBtn.disabled = false;
					convertBtn.removeAttribute( 'title' );
				} else {
					convertBtn.disabled = true;
					convertBtn.title    = 'Available for JSON files only';
				}
			}

			// Show xlsx dry-run diff if available.
			if ( manifest.xlsx_dry_run ) {
				var diffEl   = document.getElementById( 'd5dsh-si-xlsx-diff' );
				var diffBody = document.getElementById( 'd5dsh-si-xlsx-diff-body' );
				if ( diffEl ) { diffEl.style.display = ''; }
				if ( diffBody ) { diffBody.innerHTML = siRenderXlsxDiff( manifest.xlsx_dry_run ); }
			}
		}
	}

	/**
	 * Render the Preliminary Analysis section inside the unified card.
	 *
	 * Injects into #d5dsh-si-dep-report (which lives inside .d5dsh-si-unified-card):
	 *  - Accordion header: "Preliminary Analysis" + live summary + chevron
	 *  - Expanded panel containing:
	 *      Dependency Warning (yellow) — when has_warnings
	 *      Divi Built-in Variables (blue) — when builtin_refs present
	 *      All-clear notice — when no warnings and no builtins
	 *
	 * The report object is stored on the element (_depReport) so the print
	 * and CSV handlers can access it without a stale closure.
	 *
	 * @param {Object|null} report    dependency_report from the server, or null.
	 * @param {string}      [filename] Source filename used in CSV/print title.
	 */
	function siRenderDepReport( report, filename ) {
		var el = document.getElementById( 'd5dsh-si-dep-report' );
		if ( ! el ) { return; }

		// No report (xlsx, unknown type) — show nothing.
		if ( ! report ) {
			el.style.display = 'none';
			el.innerHTML     = '';
			el._depReport    = null;
			return;
		}

		el._depReport   = report;
		el._depFilename = filename || 'dependency-report';

		var hasWarn      = report.has_warnings;
		var builtins     = report.builtin_refs    || [];
		var missingVars  = report.missing_vars     || [];
		var missingPresets = report.missing_presets || [];
		var totalMissing = missingVars.length + missingPresets.length;

		// Build live summary text for the accordion header.
		var summaryParts = [];
		if ( totalMissing > 0 ) { summaryParts.push( 'Missing Variables: ' + totalMissing ); }
		if ( builtins.length  ) { summaryParts.push( 'Built-in Variables: ' + builtins.length ); }
		var summaryText = summaryParts.length ? summaryParts.join( ' \u00b7 ' ) : 'No issues detected';

		var html = '';

		// ── Preliminary Analysis divider ─────────────────────────────────────
		html += '<div class="d5dsh-ufc-divider"></div>';

		// ── Preliminary Analysis accordion header ────────────────────────────
		html += '<div class="d5dsh-ufc-prelim-header">';
		var noIssues = summaryParts.length === 0;
		html += '<button type="button" class="d5dsh-ufc-prelim-toggle' + ( noIssues ? ' d5dsh-prelim-no-issues' : '' ) + '" aria-expanded="false" aria-controls="d5dsh-ufc-prelim-panel"' + ( noIssues ? ' disabled' : '' ) + '>';
		html += '<span class="d5dsh-ufc-prelim-title">Preliminary Analysis</span>';
		html += '<span class="d5dsh-ufc-prelim-summary">' + escHtml( summaryText ) + '</span>';
		html += '<span class="d5dsh-ufc-prelim-chevron" aria-hidden="true">&#9658;</span>';
		html += '</button>';
		html += '</div>';

		// ── Accordion panel (collapsed by default) ───────────────────────────
		html += '<div id="d5dsh-ufc-prelim-panel" class="d5dsh-ufc-prelim-panel" hidden>';

		// Dependency Warning (yellow) ─────────────────────────────────────────
		if ( hasWarn ) {
			html += '<div class="d5dsh-pa-warning-panel">';
			html += '<div class="d5dsh-pa-panel-header">';
			html += '<span class="d5dsh-pa-panel-title">Dependency Warning</span>';
			html += '<div class="d5dsh-pa-panel-utils">';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-print-btn" title="Print Dependency Warning">&#9113;</button>';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-csv-btn" title="Download Dependency Warning">&#8595;</button>';
			html += '</div>';
			html += '</div>';
			html += '<p class="d5dsh-pa-body-text">'
				+ totalMissing + ' missing ' + ( totalMissing === 1 ? 'dependency' : 'dependencies' ) + ' detected. '
				+ 'Import will still proceed &mdash; missing items will not overwrite anything and elements may fall back to site defaults.'
				+ '</p>';

			if ( missingVars.length ) {
				html += '<div class="d5dsh-pa-list-section">';
				html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">'
					+ 'Missing Variables <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span>'
					+ '</button>';
				html += '<div class="d5dsh-pa-list-body" hidden>';
				html += '<ul class="d5dsh-pa-id-list">';
				missingVars.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code>'
						+ ( item.context ? ' <span class="d5dsh-pa-context">' + siEscape( item.context ) + '</span>' : '' )
						+ '</li>';
				} );
				html += '</ul>';
				html += '</div>';
				html += '</div>';
			}

			if ( missingPresets.length ) {
				html += '<div class="d5dsh-pa-list-section">';
				html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">'
					+ 'Missing Presets <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span>'
					+ '</button>';
				html += '<div class="d5dsh-pa-list-body" hidden>';
				html += '<ul class="d5dsh-pa-id-list">';
				missingPresets.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code>'
						+ ( item.context ? ' <span class="d5dsh-pa-context">referenced in: ' + siEscape( item.context ) + '</span>' : '' )
						+ '</li>';
				} );
				html += '</ul>';
				html += '</div>';
				html += '</div>';
			}

			html += '</div>'; // .d5dsh-pa-warning-panel
		}

		// Divi Built-in Variables (blue) ──────────────────────────────────────
		if ( builtins.length ) {
			html += '<div class="d5dsh-pa-info-panel">';
			html += '<div class="d5dsh-pa-panel-header">';
			html += '<span class="d5dsh-pa-panel-title">Divi Built-in Variables</span>';
			html += '<div class="d5dsh-pa-panel-utils">';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-print-btn d5dsh-pa-builtins-print" title="Print Divi Built-in Variables">&#9113;</button>';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-csv-btn d5dsh-pa-builtins-csv" title="Download Divi Built-in Variables">&#8595;</button>';
			html += '</div>';
			html += '</div>';
			html += '<p class="d5dsh-pa-body-text">'
				+ builtins.length + ' reference' + ( builtins.length === 1 ? '' : 's' )
				+ ' to Divi-internal variable' + ( builtins.length === 1 ? '' : 's' )
				+ ' detected. These are baked into Divi and are not exported &mdash; this is normal.'
				+ '</p>';

			html += '<div class="d5dsh-pa-list-section">';
			html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">'
				+ 'Show IDs <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span>'
				+ '</button>';
			html += '<div class="d5dsh-pa-list-body" hidden>';
			html += '<ul class="d5dsh-pa-id-list">';
			builtins.forEach( function ( id ) {
				html += '<li><code>' + siEscape( id ) + '</code></li>';
			} );
			html += '</ul>';
			html += '</div>';
			html += '</div>';

			html += '</div>'; // .d5dsh-pa-info-panel
		}

		// All-clear when no warnings and no builtins ──────────────────────────
		if ( ! hasWarn && ! builtins.length ) {
			var totalRefs = ( report.variable_refs || 0 ) + ( report.preset_refs || 0 );
			html += '<div class="d5dsh-pa-ok-notice">';
			html += '<p><strong>&#10003; Dependencies OK</strong> &mdash; All ' + totalRefs + ' referenced IDs found on this site.</p>';
			html += '</div>';
		}

		html += '</div>'; // #d5dsh-ufc-prelim-panel

		el.innerHTML     = html;
		el.style.display = '';

		// ── Wire accordion toggle ──────────────────────────────────────────────
		var toggle = el.querySelector( '.d5dsh-ufc-prelim-toggle' );
		var panel  = el.querySelector( '#d5dsh-ufc-prelim-panel' );
		var chevron = el.querySelector( '.d5dsh-ufc-prelim-chevron' );
		if ( toggle && panel ) {
			toggle.addEventListener( 'click', function () {
				var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
				toggle.setAttribute( 'aria-expanded', ! expanded );
				panel.hidden = expanded;
				if ( chevron ) { chevron.innerHTML = expanded ? '&#9658;' : '&#9660;'; }
			} );
		}

		// ── Wire list toggles (Missing Variables, Show IDs, etc.) ─────────────
		el.querySelectorAll( '.d5dsh-pa-list-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var isExpanded = btn.getAttribute( 'aria-expanded' ) === 'true';
				var body = btn.nextElementSibling;
				btn.setAttribute( 'aria-expanded', ! isExpanded );
				if ( body ) { body.hidden = isExpanded; }
				var arrow = btn.querySelector( '.d5dsh-pa-list-arrow' );
				if ( arrow ) { arrow.innerHTML = isExpanded ? '&#9658;' : '&#9660;'; }
			} );
		} );

		// ── Wire print/download buttons ────────────────────────────────────────
		// Warning panel print/download acts on the full dep report.
		var warnPrintBtns = el.querySelectorAll( '.d5dsh-pa-warning-panel .d5dsh-pa-print-btn' );
		var warnCsvBtns   = el.querySelectorAll( '.d5dsh-pa-warning-panel .d5dsh-pa-csv-btn' );
		warnPrintBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siPrintDepReport( el._depReport, el._depFilename ); } );
		} );
		warnCsvBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siDownloadDepReportCsv( el._depReport, el._depFilename ); } );
		} );

		// Built-ins panel print/download.
		var builtinsPrintBtns = el.querySelectorAll( '.d5dsh-pa-info-panel .d5dsh-pa-print-btn' );
		var builtinsCsvBtns   = el.querySelectorAll( '.d5dsh-pa-info-panel .d5dsh-pa-csv-btn' );
		builtinsPrintBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siPrintBuiltinsReport( el._depReport, el._depFilename ); } );
		} );
		builtinsCsvBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siDownloadBuiltinsReportCsv( el._depReport, el._depFilename ); } );
		} );
	}

	/**
	 * Open a print-friendly popup containing the dependency report.
	 *
	 * Generates a self-contained HTML document with minimal styling so the
	 * browser's print dialog renders cleanly on paper or to PDF.
	 *
	 * @param {Object} report
	 * @param {string} filename  Used as the document title.
	 */
	function siPrintDepReport( report, filename ) {
		if ( ! report ) { return; }

		var now     = new Date().toLocaleString();
		var title   = 'Dependency Report — ' + siEscape( filename );
		var missing = ( report.missing_vars || [] ).concat( report.missing_presets || [] );
		var builtins = report.builtin_refs || [];

		var body = '<h1>' + title + '</h1>';
		body += '<p style="color:#555;font-size:13px">Generated: ' + now + '</p>';
		body += '<p>Variable references scanned: <strong>' + report.variable_refs + '</strong> &nbsp;|&nbsp; ';
		body += 'Preset references scanned: <strong>' + report.preset_refs + '</strong></p>';

		if ( report.has_warnings ) {
			body += '<h2 style="color:#b45309">&#9888; Missing Dependencies (' + missing.length + ')</h2>';
			body += '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px">';
			body += '<thead style="background:#fef3c7"><tr><th>ID</th><th>Type</th><th>Context</th></tr></thead><tbody>';
			( report.missing_vars || [] ).forEach( function ( item ) {
				body += '<tr><td><code>' + siEscape( item.id ) + '</code></td><td>Variable</td><td>' + siEscape( item.context ) + '</td></tr>';
			} );
			( report.missing_presets || [] ).forEach( function ( item ) {
				body += '<tr><td><code>' + siEscape( item.id ) + '</code></td><td>Preset</td><td>' + siEscape( item.context ) + '</td></tr>';
			} );
			body += '</tbody></table>';
		} else {
			body += '<p style="color:#166534">&#10003; All referenced IDs found on this site. No missing dependencies.</p>';
		}

		if ( builtins.length ) {
			body += '<h2 style="color:#1e40af">&#8505; Divi Built-in References (' + builtins.length + ')</h2>';
			body += '<p style="font-size:13px">These IDs are internal to Divi and are never exported. This is normal.</p>';
			body += '<ul style="font-size:13px">';
			builtins.forEach( function ( id ) { body += '<li><code>' + siEscape( id ) + '</code></li>'; } );
			body += '</ul>';
		}

		openPrintWindow( body, title, 'portrait', null );
	}

	/**
	 * Build a CSV string from the dependency report and trigger a file download.
	 *
	 * Columns: Type, ID, Context, Severity
	 *
	 * @param {Object} report
	 * @param {string} filename  Base name for the downloaded file (no extension).
	 */
	function siDownloadDepReportCsv( report, filename ) {
		if ( ! report ) { return; }

		var rows = [ [ 'Type', 'ID', 'Context', 'Severity' ] ];

		( report.missing_vars || [] ).forEach( function ( item ) {
			rows.push( [ 'Variable', item.id, item.context, 'Warning' ] );
		} );
		( report.missing_presets || [] ).forEach( function ( item ) {
			rows.push( [ 'Preset', item.id, item.context, 'Warning' ] );
		} );
		( report.builtin_refs || [] ).forEach( function ( id ) {
			rows.push( [ 'Variable', id, 'Divi built-in', 'Info' ] );
		} );

		if ( rows.length === 1 ) {
			// Only header — add an all-clear row.
			rows.push( [ '—', '—', 'All dependencies found on this site', 'OK' ] );
		}

		var csv = rows.map( function ( row ) {
			return row.map( function ( cell ) {
				// Wrap in quotes; escape any existing quotes by doubling them.
				return '"' + String( cell ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );

		var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = ( filename || 'dependency-report' ).replace( /[^a-z0-9_-]/gi, '-' ) + '.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	/**
	 * Print popup for the Divi Built-in Variables panel only.
	 *
	 * @param {Object} report
	 * @param {string} filename
	 */
	function siPrintBuiltinsReport( report, filename ) {
		if ( ! report ) { return; }
		var builtins = report.builtin_refs || [];
		var title    = 'Divi Built-in Variables — ' + siEscape( filename );
		var titleSafe = escHtml( title );
		var body     = '<h1>' + titleSafe + '</h1>';
		body += '<p>Generated: ' + new Date().toLocaleString() + '</p>';
		body += '<h2 style="color:#1e40af">&#8505; Divi Built-in References (' + builtins.length + ')</h2>';
		body += '<p style="font-size:13px">These IDs are internal to Divi and are never exported. This is normal.</p>';
		body += '<ul style="font-size:13px">';
		builtins.forEach( function ( id ) { body += '<li><code>' + siEscape( id ) + '</code></li>'; } );
		body += '</ul>';
		var win = window.open( '', '_blank', 'width=800,height=400' );
		if ( ! win ) { return; }
		win.document.write(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + titleSafe + '</title>' +
			'<style>body{font-family:sans-serif;margin:32px;color:#111}code{font-size:12px;background:#f3f4f6;padding:1px 4px;border-radius:2px}</style>' +
			'</head><body>' + body +
			'<script>window.onload=function(){window.print();}<\/script>' +
			'</body></html>'
		);
		win.document.close();
	}

	/**
	 * CSV download for the Divi Built-in Variables panel only.
	 *
	 * @param {Object} report
	 * @param {string} filename
	 */
	function siDownloadBuiltinsReportCsv( report, filename ) {
		if ( ! report ) { return; }
		var builtins = report.builtin_refs || [];
		var rows = [ [ 'ID', 'Type', 'Note' ] ];
		builtins.forEach( function ( id ) {
			rows.push( [ id, 'Variable', 'Divi built-in — not exported' ] );
		} );
		var csv = rows.map( function ( row ) {
			return row.map( function ( cell ) {
				return '"' + String( cell ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );
		var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = ( filename || 'builtins-report' ).replace( /[^a-z0-9_-]/gi, '-' ) + '-builtins.csv';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	/**
	 * HTML-escape a string for safe insertion into innerHTML.
	 *
	 * @param {string} str
	 * @return {string}
	 */
	function siEscape( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	// ── Import label editor ───────────────────────────────────────────────────

	/**
	 * Build an "Edit Labels" <details> accordion for a file's items list.
	 *
	 * Stores original labels on each input so resets can revert cleanly.
	 *
	 * @param {Object} fi      File info object from the manifest (must have .items and .key).
	 * @param {string} cardId  Unique card prefix for DOM IDs.
	 * @returns {HTMLDetailsElement|null}
	 */
	function siBuildLabelEditor( fi, cardId ) {
		if ( ! fi.items || ! fi.items.length ) { return null; }

		var fileKey = fi.key;

		var details = document.createElement( 'details' );
		details.className = 'd5dsh-si-label-editor';

		// Summary header.
		var summary = document.createElement( 'summary' );
		summary.className = 'd5dsh-si-label-editor-summary';
		summary.innerHTML =
			'Edit Labels <span class="d5dsh-si-label-badge">' + fi.items.length + ' item' +
			( fi.items.length !== 1 ? 's' : '' ) + '</span>';
		details.appendChild( summary );

		var inner = document.createElement( 'div' );
		inner.className = 'd5dsh-si-label-editor-inner';

		// ── Bulk ops bar ─────────────────────────────────────────────────────
		var bar = document.createElement( 'div' );
		bar.className = 'd5dsh-si-label-bulk-bar';
		bar.innerHTML =
			'<select class="d5dsh-si-bulk-op">' +
				'<option value="">— Bulk operation —</option>' +
				'<option value="prefix">Add prefix</option>' +
				'<option value="suffix">Add suffix</option>' +
				'<option value="find_replace">Find &amp; Replace</option>' +
				'<option value="normalize">Normalize case</option>' +
			'</select>' +
			'<input type="text" class="d5dsh-si-bulk-a regular-text" placeholder="Value / Find" style="display:none">' +
			'<input type="text" class="d5dsh-si-bulk-b regular-text" placeholder="Replace with" style="display:none">' +
			'<select class="d5dsh-si-bulk-case" style="display:none">' +
				'<option value="title">Title Case</option>' +
				'<option value="upper">UPPER CASE</option>' +
				'<option value="lower">lower case</option>' +
				'<option value="snake">snake_case</option>' +
				'<option value="camel">camelCase</option>' +
			'</select>' +
			'<button type="button" class="button d5dsh-si-bulk-apply-btn">Apply</button>' +
			'<button type="button" class="button d5dsh-si-bulk-reset-btn">Reset All</button>';
		inner.appendChild( bar );

		// Show/hide correct inputs when operation changes.
		var opSel    = bar.querySelector( '.d5dsh-si-bulk-op' );
		var inputA   = bar.querySelector( '.d5dsh-si-bulk-a' );
		var inputB   = bar.querySelector( '.d5dsh-si-bulk-b' );
		var caseSel  = bar.querySelector( '.d5dsh-si-bulk-case' );

		opSel.addEventListener( 'change', function () {
			var op = opSel.value;
			inputA.style.display  = ( op === 'prefix' || op === 'suffix' || op === 'find_replace' ) ? '' : 'none';
			inputA.placeholder    = op === 'find_replace' ? 'Find' : 'Value';
			inputB.style.display  = ( op === 'find_replace' ) ? '' : 'none';
			caseSel.style.display = ( op === 'normalize' ) ? '' : 'none';
		} );

		// ── Editable table ───────────────────────────────────────────────────
		var table = document.createElement( 'table' );
		table.className = 'd5dsh-si-label-table widefat striped';
		table.innerHTML =
			'<thead><tr>' +
				'<th style="width:110px">Type</th>' +
				'<th style="width:180px">ID</th>' +
				'<th>Label</th>' +
			'</tr></thead>';
		var tbody = document.createElement( 'tbody' );

		fi.items.forEach( function ( item ) {
			var tr = document.createElement( 'tr' );
			var tdType  = document.createElement( 'td' );
			var tdId    = document.createElement( 'td' );
			var tdLabel = document.createElement( 'td' );

			tdType.textContent = item.type || '';
			tdId.innerHTML     = '<code style="font-size:0.85em">' + siEscape( item.id ) + '</code>';

			var input = document.createElement( 'input' );
			input.type         = 'text';
			input.className    = 'd5dsh-si-label-input';
			input.value        = item.label;
			input.dataset.orig = item.label;  // original for reset
			input.dataset.id   = item.id;

			input.addEventListener( 'input', function () {
				siOnLabelChange( fileKey, item.id, input.value );
			} );

			tdLabel.appendChild( input );
			tr.appendChild( tdType );
			tr.appendChild( tdId );
			tr.appendChild( tdLabel );
			tbody.appendChild( tr );
		} );

		table.appendChild( tbody );
		inner.appendChild( table );
		details.appendChild( inner );

		// ── Bulk apply ───────────────────────────────────────────────────────
		bar.querySelector( '.d5dsh-si-bulk-apply-btn' ).addEventListener( 'click', function () {
			var op = opSel.value;
			if ( ! op ) { return; }
			var fn = siMakeBulkTransform( op, inputA.value, inputB.value, caseSel.value );
			if ( ! fn ) { return; }
			tbody.querySelectorAll( '.d5dsh-si-label-input' ).forEach( function ( inp ) {
				inp.value = fn( inp.value );
				siOnLabelChange( fileKey, inp.dataset.id, inp.value );
			} );
		} );

		// ── Reset all ────────────────────────────────────────────────────────
		bar.querySelector( '.d5dsh-si-bulk-reset-btn' ).addEventListener( 'click', function () {
			tbody.querySelectorAll( '.d5dsh-si-label-input' ).forEach( function ( inp ) {
				inp.value = inp.dataset.orig;
				siOnLabelChange( fileKey, inp.dataset.id, inp.value );
			} );
		} );

		return details;
	}

	/**
	 * Record a label change into siLabelOverrides.
	 * Removes the entry when value matches the original (keeps the map lean).
	 *
	 * @param {string} fileKey  fi.key for this file.
	 * @param {string} id       DSO id (var or preset id).
	 * @param {string} newLabel New label value.
	 */
	function siOnLabelChange( fileKey, id, newLabel ) {
		if ( ! siLabelOverrides[ fileKey ] ) {
			siLabelOverrides[ fileKey ] = {};
		}
		// Find original label from manifest items.
		var orig = '';
		if ( siManifest ) {
			( siManifest.files || [] ).forEach( function ( f ) {
				if ( f.key === fileKey ) {
					( f.items || [] ).forEach( function ( item ) {
						if ( item.id === id ) { orig = item.label; }
					} );
				}
			} );
		}
		if ( newLabel === orig ) {
			delete siLabelOverrides[ fileKey ][ id ];
		} else {
			siLabelOverrides[ fileKey ][ id ] = newLabel;
		}
	}

	/**
	 * Return a transform function for a bulk label operation.
	 *
	 * @param {string} op     'prefix'|'suffix'|'find_replace'|'normalize'
	 * @param {string} valA   Primary value (prefix text, suffix text, or find string).
	 * @param {string} valB   Replace-with string (find_replace only).
	 * @param {string} caseType 'title'|'upper'|'lower'|'snake'|'camel' (normalize only).
	 * @returns {Function|null}
	 */
	function siMakeBulkTransform( op, valA, valB, caseType ) {
		switch ( op ) {
			case 'prefix':
				return function ( label ) { return valA + label; };
			case 'suffix':
				return function ( label ) { return label + valA; };
			case 'find_replace':
				if ( ! valA ) { return null; }
				return function ( label ) { return label.split( valA ).join( valB || '' ); };
			case 'normalize':
				return function ( label ) { return siNormalizeCase( label, caseType ); };
			default:
				return null;
		}
	}

	/**
	 * Normalize label case (mirrors server-side apply_bulk normalize logic).
	 *
	 * @param {string} label
	 * @param {string} caseType 'title'|'upper'|'lower'|'snake'|'camel'
	 * @returns {string}
	 */
	function siNormalizeCase( label, caseType ) {
		switch ( caseType ) {
			case 'upper': return label.toUpperCase();
			case 'lower': return label.toLowerCase();
			case 'title': return label.replace( /\b\w/g, function ( c ) { return c.toUpperCase(); } );
			case 'snake':
				return label.trim().toLowerCase()
					.replace( /[\s\-]+/g, '_' )
					.replace( /[^a-z0-9_]/g, '' );
			case 'camel':
				return label.trim().toLowerCase()
					.replace( /[\s_\-]+(.)/g, function ( _, c ) { return c.toUpperCase(); } )
					.replace( /[^a-zA-Z0-9]/g, '' );
			default: return label;
		}
	}

	function siReset() {
		siManifest              = null;
		siImportResults         = null;
		siLabelOverrides        = {};
		siConflictResolutions   = {};
		siSetVisibility( 'idle' );

		// Clear content.
		var rowsEl      = document.getElementById( 'd5dsh-si-file-rows' );
		var summaryBody = document.getElementById( 'd5dsh-si-single-summary-body' );
		var diffBody    = document.getElementById( 'd5dsh-si-xlsx-diff-body' );
		if ( rowsEl )      { rowsEl.innerHTML     = ''; }
		if ( summaryBody ) { summaryBody.innerHTML = ''; }
		if ( diffBody )    { diffBody.innerHTML    = ''; }

		// Hide containers.
		var fileListEl   = document.getElementById( 'd5dsh-si-file-list' );
		var summaryEl    = document.getElementById( 'd5dsh-si-single-summary' );
		var diffEl       = document.getElementById( 'd5dsh-si-xlsx-diff' );
		var checkAllWrap = document.getElementById( 'd5dsh-si-check-all-wrap' );
		if ( fileListEl )   { fileListEl.style.display   = 'none'; }
		if ( summaryEl )    { summaryEl.style.display    = 'none'; }
		if ( diffEl )       { diffEl.style.display       = 'none'; }
		if ( checkAllWrap ) { checkAllWrap.style.display = 'none'; }

		// Hide header utility icons.
		var headerUtils = document.getElementById( 'd5dsh-si-header-utils' );
		if ( headerUtils ) { headerUtils.style.display = 'none'; }

		// Reset import button label.
		var importBtn = document.getElementById( 'd5dsh-si-import-btn' );
		if ( importBtn ) { importBtn.textContent = 'Import'; }
	}

	/**
	 * Show/hide the correct state panel.
	 *
	 * @param {'idle'|'analyzing'|'analysis'|'error'} state
	 */
	function siSetVisibility( state ) {
		siState = state;
		var dropzone   = document.getElementById( 'd5dsh-si-dropzone' );
		var analyzing  = document.getElementById( 'd5dsh-si-analyzing' );
		var errorEl    = document.getElementById( 'd5dsh-si-error' );
		var analysisEl = document.getElementById( 'd5dsh-si-analysis' );

		if ( dropzone )   { dropzone.style.display   = state === 'idle'      ? ''     : 'none'; }
		if ( analyzing )  { analyzing.style.display  = state === 'analyzing' ? 'flex' : 'none'; }
		if ( errorEl )    { errorEl.style.display    = state === 'error'     ? ''     : 'none'; }
		if ( analysisEl ) { analysisEl.style.display = state === 'analysis'  ? ''     : 'none'; }
	}

	// ── Render helpers ────────────────────────────────────────────────────────

	/**
	 * Build a unified file card for a file in the zip manifest.
	 *
	 * Matches the single-file unified card pattern but adds:
	 *  - Selection checkbox on the left of the filename
	 *  - Collapse chevron on the right of the filename row
	 *  - Collapsible card body (Imported Items + Preliminary Analysis)
	 *
	 * @param {Object} fi
	 * @param {string} cardId  Unique ID prefix for this card's DOM elements.
	 * @returns {HTMLDivElement}
	 */
	function siBuildFileRow( fi, cardId ) {
		var lastSlash = fi.name.lastIndexOf( '/' );
		var basename  = lastSlash >= 0 ? fi.name.slice( lastSlash + 1 ) : fi.name;
		var dirpath   = lastSlash >= 0 ? fi.name.slice( 0, lastSlash )  : '';

		var report = fi.dependency_report || null;
		var total  = fi.object_count || 0;
		var newCnt = fi.new_count    || 0;

		var card = document.createElement( 'div' );
		card.className = 'd5dsh-mfc-card' + ( fi.valid ? '' : ' d5dsh-mfc-card--invalid' );

		// ── Title row: [checkbox] [filename+path] [chevron] ───────────────────
		var titleRow = document.createElement( 'div' );
		titleRow.className = 'd5dsh-mfc-title-row';

		// Checkbox.
		var chk = document.createElement( 'input' );
		chk.type      = 'checkbox';
		chk.className = 'd5dsh-si-file-chk';
		chk.value     = fi.key;
		chk.checked   = fi.valid;
		chk.disabled  = ! fi.valid;
		chk.setAttribute( 'aria-label', 'Select ' + basename );
		titleRow.appendChild( chk );

		// Filename + optional path.
		var nameWrap = document.createElement( 'div' );
		nameWrap.className = 'd5dsh-mfc-name-wrap';

		var fnName = document.createElement( 'span' );
		fnName.className   = 'd5dsh-mfc-filename';
		fnName.title       = fi.name;
		fnName.textContent = basename;
		nameWrap.appendChild( fnName );

		if ( dirpath ) {
			var fnPath = document.createElement( 'span' );
			fnPath.className   = 'd5dsh-mfc-filepath';
			fnPath.textContent = dirpath;
			nameWrap.appendChild( fnPath );
		}
		titleRow.appendChild( nameWrap );

		// Collapse chevron button (right-aligned).
		var collapseBtn = document.createElement( 'button' );
		collapseBtn.type      = 'button';
		collapseBtn.className = 'd5dsh-mfc-collapse-btn';
		collapseBtn.setAttribute( 'aria-expanded', 'true' );
		collapseBtn.setAttribute( 'aria-label', 'Collapse ' + basename );
		collapseBtn.innerHTML = '<span class="d5dsh-mfc-chevron" aria-hidden="true">&#9660;</span>';
		titleRow.appendChild( collapseBtn );

		card.appendChild( titleRow );

		// ── Collapsible body ──────────────────────────────────────────────────
		var body = document.createElement( 'div' );
		body.className = 'd5dsh-mfc-body';

		if ( fi.valid ) {
			// File type line.
			var typeLine = document.createElement( 'p' );
			typeLine.className   = 'd5dsh-mfc-type';
			typeLine.textContent = 'File Type: ' + ( fi.type_label || fi.type || '' );
			body.appendChild( typeLine );

			// Divider.
			var div1 = document.createElement( 'div' );
			div1.className = 'd5dsh-ufc-divider';
			body.appendChild( div1 );

			// ── Imported Items ─────────────────────────────────────────────────
			var iiWrap = document.createElement( 'div' );
			iiWrap.className = 'd5dsh-ufc-imported-items';

			var iiHeaderRow = document.createElement( 'div' );
			iiHeaderRow.className = 'd5dsh-ufc-ii-header-row';
			iiHeaderRow.innerHTML = '<span class="d5dsh-ufc-ii-label">Imported Items</span>'
				+ '<span class="d5dsh-ufc-ii-helper">[total (new)]</span>';
			iiWrap.appendChild( iiHeaderRow );

			var iiTotalRow = document.createElement( 'div' );
			iiTotalRow.className = 'd5dsh-ufc-ii-total-row';

			var iiTotal = document.createElement( 'strong' );
			iiTotal.className   = 'd5dsh-ufc-ii-total';
			iiTotal.textContent = 'Total: ' + total + ' (' + newCnt + ')';
			iiTotalRow.appendChild( iiTotal );

			var breakdownId   = cardId + '-breakdown';
			var breakdownBtn  = document.createElement( 'button' );
			breakdownBtn.type = 'button';
			breakdownBtn.className = 'd5dsh-ufc-breakdown-btn';
			breakdownBtn.setAttribute( 'aria-expanded', 'false' );
			breakdownBtn.setAttribute( 'aria-controls', breakdownId );
			breakdownBtn.innerHTML = 'Breakdown <span class="d5dsh-ufc-chevron" aria-hidden="true">&#9658;</span>';
			iiTotalRow.appendChild( breakdownBtn );
			iiWrap.appendChild( iiTotalRow );

			// Collapsible breakdown.
			var breakdownDiv = document.createElement( 'div' );
			breakdownDiv.id      = breakdownId;
			breakdownDiv.className = 'd5dsh-ufc-breakdown';
			breakdownDiv.hidden  = true;

			var hasCats = fi.category_counts && Object.keys( fi.category_counts ).length > 0;
			var bkHtml = '<ul class="d5dsh-ufc-count-grid">';
			if ( hasCats ) {
				Object.keys( fi.category_counts ).forEach( function ( key ) {
					var cat = fi.category_counts[ key ];
					bkHtml += '<li>' + escHtml( key ) + ': ' + ( cat.total || 0 ) + ' (' + ( cat.new || 0 ) + ')</li>';
				} );
			} else if ( total > 0 ) {
				bkHtml += '<li>' + escHtml( fi.type_label || fi.type || 'Items' ) + ': ' + total + ' (' + newCnt + ')</li>';
			}
			bkHtml += '</ul>';
			breakdownDiv.innerHTML = bkHtml;
			iiWrap.appendChild( breakdownDiv );
			body.appendChild( iiWrap );

			// Wire breakdown toggle.
			breakdownBtn.addEventListener( 'click', function () {
				var exp = breakdownBtn.getAttribute( 'aria-expanded' ) === 'true';
				breakdownBtn.setAttribute( 'aria-expanded', ! exp );
				breakdownDiv.hidden = exp;
				var chev = breakdownBtn.querySelector( '.d5dsh-ufc-chevron' );
				if ( chev ) { chev.innerHTML = exp ? '&#9658;' : '&#9660;'; }
			} );

			// ── Preliminary Analysis (inline) ──────────────────────────────────
			if ( report ) {
				var prelim = document.createElement( 'div' );
				prelim.className = 'd5dsh-ufc-prelim-wrap';
				prelim.id        = cardId + '-dep';
				siInjectCardPrelim( prelim, report, basename );
				body.appendChild( prelim );
			}

			// ── Label Editor (vars / presets only) ─────────────────────────────
			if ( fi.items && fi.items.length ) {
				var fileCardLabelEditor = siBuildLabelEditor( fi, cardId );
				if ( fileCardLabelEditor ) { body.appendChild( fileCardLabelEditor ); }
			}

		} else {
			var errMsg = document.createElement( 'p' );
			errMsg.className   = 'd5dsh-mfc-invalid-msg';
			errMsg.textContent = '\u2717 ' + ( fi.error || 'Invalid file' );
			body.appendChild( errMsg );
		}

		card.appendChild( body );

		// ── Wire collapse chevron ─────────────────────────────────────────────
		collapseBtn.addEventListener( 'click', function () {
			var expanded = collapseBtn.getAttribute( 'aria-expanded' ) === 'true';
			collapseBtn.setAttribute( 'aria-expanded', ! expanded );
			body.hidden = expanded;
			var chev = collapseBtn.querySelector( '.d5dsh-mfc-chevron' );
			if ( chev ) { chev.innerHTML = expanded ? '&#9658;' : '&#9660;'; }
		} );

		return card;
	}

	/**
	 * Inject the Preliminary Analysis accordion into a per-card prelim wrapper.
	 * Reuses the same pattern as siRenderDepReport but operates on a provided element.
	 *
	 * @param {HTMLElement} el     Target container element.
	 * @param {Object}      report dependency_report object.
	 * @param {string}      name   File basename (for print/download title).
	 */
	function siInjectCardPrelim( el, report, name ) {
		var hasWarn        = report.has_warnings;
		var builtins       = report.builtin_refs    || [];
		var missingVars    = report.missing_vars     || [];
		var missingPresets = report.missing_presets  || [];
		var totalMissing   = missingVars.length + missingPresets.length;

		var summaryParts = [];
		if ( totalMissing > 0 ) { summaryParts.push( 'Missing Variables: ' + totalMissing ); }
		if ( builtins.length  ) { summaryParts.push( 'Built-in Variables: ' + builtins.length ); }
		var summaryText = summaryParts.length ? summaryParts.join( ' \u00b7 ' ) : 'No issues detected';

		var panelId = el.id + '-panel';
		var html = '';
		html += '<div class="d5dsh-ufc-divider"></div>';
		html += '<div class="d5dsh-ufc-prelim-header">';
		var noIssues = summaryParts.length === 0;
		html += '<button type="button" class="d5dsh-ufc-prelim-toggle' + ( noIssues ? ' d5dsh-prelim-no-issues' : '' ) + '" aria-expanded="false" aria-controls="' + panelId + '"' + ( noIssues ? ' disabled' : '' ) + '>';
		html += '<span class="d5dsh-ufc-prelim-title">Preliminary Analysis</span>';
		html += '<span class="d5dsh-ufc-prelim-summary">' + escHtml( summaryText ) + '</span>';
		html += '<span class="d5dsh-ufc-prelim-chevron" aria-hidden="true">&#9658;</span>';
		html += '</button></div>';
		html += '<div id="' + panelId + '" class="d5dsh-ufc-prelim-panel" hidden>';

		// Dependency Warning panel.
		if ( hasWarn ) {
			html += '<div class="d5dsh-pa-warning-panel">';
			html += '<div class="d5dsh-pa-panel-header">';
			html += '<span class="d5dsh-pa-panel-title">Dependency Warning</span>';
			html += '<div class="d5dsh-pa-panel-utils">';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-print-btn" title="Print Dependency Warning">&#9113;</button>';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-csv-btn" title="Download Dependency Warning">&#8595;</button>';
			html += '</div></div>';
			html += '<p class="d5dsh-pa-body-text">' + totalMissing + ' missing '
				+ ( totalMissing === 1 ? 'dependency' : 'dependencies' )
				+ ' detected. Import will still proceed &mdash; missing items will not overwrite anything and elements may fall back to site defaults.</p>';

			if ( missingVars.length ) {
				html += '<div class="d5dsh-pa-list-section">';
				html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">Missing Variables <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span></button>';
				html += '<div class="d5dsh-pa-list-body" hidden><ul class="d5dsh-pa-id-list">';
				missingVars.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code>'
						+ ( item.context ? ' <span class="d5dsh-pa-context">' + siEscape( item.context ) + '</span>' : '' )
						+ '</li>';
				} );
				html += '</ul></div></div>';
			}
			if ( missingPresets.length ) {
				html += '<div class="d5dsh-pa-list-section">';
				html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">Missing Presets <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span></button>';
				html += '<div class="d5dsh-pa-list-body" hidden><ul class="d5dsh-pa-id-list">';
				missingPresets.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code>'
						+ ( item.context ? ' <span class="d5dsh-pa-context">referenced in: ' + siEscape( item.context ) + '</span>' : '' )
						+ '</li>';
				} );
				html += '</ul></div></div>';
			}
			html += '</div>';
		}

		// Divi Built-in Variables panel.
		if ( builtins.length ) {
			html += '<div class="d5dsh-pa-info-panel">';
			html += '<div class="d5dsh-pa-panel-header">';
			html += '<span class="d5dsh-pa-panel-title">Divi Built-in Variables</span>';
			html += '<div class="d5dsh-pa-panel-utils">';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-print-btn d5dsh-pa-builtins-print" title="Print Divi Built-in Variables">&#9113;</button>';
			html += '<button type="button" class="d5dsh-icon-btn d5dsh-pa-csv-btn d5dsh-pa-builtins-csv" title="Download Divi Built-in Variables">&#8595;</button>';
			html += '</div></div>';
			html += '<p class="d5dsh-pa-body-text">' + builtins.length + ' reference'
				+ ( builtins.length === 1 ? '' : 's' )
				+ ' to Divi-internal variable' + ( builtins.length === 1 ? '' : 's' )
				+ ' detected. These are baked into Divi and are not exported &mdash; this is normal.</p>';
			html += '<div class="d5dsh-pa-list-section">';
			html += '<button type="button" class="d5dsh-pa-list-toggle" aria-expanded="false">Show IDs <span class="d5dsh-pa-list-arrow" aria-hidden="true">&#9658;</span></button>';
			html += '<div class="d5dsh-pa-list-body" hidden><ul class="d5dsh-pa-id-list">';
			builtins.forEach( function ( id ) {
				html += '<li><code>' + siEscape( id ) + '</code></li>';
			} );
			html += '</ul></div></div>';
			html += '</div>';
		}

		if ( ! hasWarn && ! builtins.length ) {
			var totalRefs = ( report.variable_refs || 0 ) + ( report.preset_refs || 0 );
			html += '<div class="d5dsh-pa-ok-notice"><p><strong>&#10003; Dependencies OK</strong> &mdash; All ' + totalRefs + ' referenced IDs found on this site.</p></div>';
		}

		html += '</div>'; // prelim-panel

		el.innerHTML = html;

		// Wire accordion toggle.
		var toggle  = el.querySelector( '.d5dsh-ufc-prelim-toggle' );
		var panel   = el.querySelector( '#' + panelId );
		var chevron = el.querySelector( '.d5dsh-ufc-prelim-chevron' );
		if ( toggle && panel ) {
			toggle.addEventListener( 'click', function () {
				var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
				toggle.setAttribute( 'aria-expanded', ! expanded );
				panel.hidden = expanded;
				if ( chevron ) { chevron.innerHTML = expanded ? '&#9658;' : '&#9660;'; }
			} );
		}

		// Wire list toggles.
		el.querySelectorAll( '.d5dsh-pa-list-toggle' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var isExpanded = btn.getAttribute( 'aria-expanded' ) === 'true';
				var listBody   = btn.nextElementSibling;
				btn.setAttribute( 'aria-expanded', ! isExpanded );
				if ( listBody ) { listBody.hidden = isExpanded; }
				var arrow = btn.querySelector( '.d5dsh-pa-list-arrow' );
				if ( arrow ) { arrow.innerHTML = isExpanded ? '&#9658;' : '&#9660;'; }
			} );
		} );

		// Wire print/download buttons.
		el._depReport   = report;
		el._depFilename = name;

		el.querySelectorAll( '.d5dsh-pa-warning-panel .d5dsh-pa-print-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siPrintDepReport( report, name ); } );
		} );
		el.querySelectorAll( '.d5dsh-pa-warning-panel .d5dsh-pa-csv-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siDownloadDepReportCsv( report, name ); } );
		} );
		el.querySelectorAll( '.d5dsh-pa-info-panel .d5dsh-pa-print-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siPrintBuiltinsReport( report, name ); } );
		} );
		el.querySelectorAll( '.d5dsh-pa-info-panel .d5dsh-pa-csv-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { siDownloadBuiltinsReportCsv( report, name ); } );
		} );
	}

	/**
	 * Build compact dependency report HTML for inline display inside a zip card.
	 *
	 * Intentionally lighter than siRenderDepReport() (no action bar — that's
	 * added separately by siBuildFileRow()).
	 *
	 * @param {Object} report  dependency_report object from the server.
	 * @return {string} HTML string safe to set as innerHTML.
	 */
	function siCardDepReportHTML( report ) {
		if ( ! report ) { return ''; }

		var html    = '';
		var hasWarn = report.has_warnings;
		var builtins = report.builtin_refs || [];

		if ( hasWarn ) {
			var missingVars    = report.missing_vars    || [];
			var missingPresets = report.missing_presets || [];
			var total = missingVars.length + missingPresets.length;

			html += '<p class="d5dsh-sfc-dep-warn-msg">\u26A0 ' + total + ' missing ' + ( total === 1 ? 'dependency' : 'dependencies' ) + '</p>';

			if ( missingVars.length ) {
				html += '<details open><summary>Missing Variables (' + missingVars.length + ')</summary><ul class="d5dsh-sfc-dep-list">';
				missingVars.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code> <span class="d5dsh-dep-context">(' + siEscape( item.context ) + ')</span></li>';
				} );
				html += '</ul></details>';
			}

			if ( missingPresets.length ) {
				html += '<details open><summary>Missing Presets (' + missingPresets.length + ')</summary><ul class="d5dsh-sfc-dep-list">';
				missingPresets.forEach( function ( item ) {
					html += '<li><code>' + siEscape( item.id ) + '</code> <span class="d5dsh-dep-context">in: ' + siEscape( item.context ) + '</span></li>';
				} );
				html += '</ul></details>';
			}
		} else {
			var total_refs = ( report.variable_refs || 0 ) + ( report.preset_refs || 0 );
			html += '<p class="d5dsh-sfc-dep-ok-msg">\u2713 All ' + total_refs + ' referenced IDs found on this site.</p>';
		}

		if ( builtins.length ) {
			html += '<details><summary>Divi Built-in References (' + builtins.length + ')</summary><ul class="d5dsh-sfc-dep-list">';
			builtins.forEach( function ( id ) {
				html += '<li><code>' + siEscape( id ) + '</code></li>';
			} );
			html += '</ul></details>';
		}

		return html;
	}


	/**
	 * Render count pills HTML for a file info object.
	 *
	 * @param {Object} fi
	 * @returns {string}
	 */
	function siRenderCountPills( fi ) {
		var html = '';
		if ( fi.object_count > 0 ) {
			html += '<span class="d5dsh-si-pill d5dsh-si-pill-total">' + fi.object_count + ' total</span>';
		}
		if ( fi.update_count > 0 ) {
			html += ' <span class="d5dsh-si-pill d5dsh-si-pill-update">' + fi.update_count + ' update</span>';
		}
		if ( fi.new_count > 0 ) {
			html += ' <span class="d5dsh-si-pill d5dsh-si-pill-new">' + fi.new_count + ' new</span>';
		}
		return html;
	}

	// ── Label / ID conflict resolution panel ─────────────────────────────────

	/**
	 * Module-level store for conflict resolutions.
	 * Shape: { "<id>": { action: "accept_import"|"keep_current"|"rename"|"skip", label: string } }
	 * Sent to the server alongside label_overrides on import execution.
	 */
	var siConflictResolutions = {};

	/**
	 * Render a per-row conflict resolution panel when the analysis detects
	 * label or ID conflicts. The Import button stays disabled until every
	 * conflict row has a resolution selected.
	 *
	 * Label-changed rows (same ID, different label):
	 *   - Accept imported label
	 *   - Keep current label
	 *   - Rename (editable text field, prefilled "Imported — <label>")
	 *
	 * Duplicate-label rows (different IDs, same label):
	 *   - Import as-is (accept the duplicate)
	 *   - Rename imported item (editable, prefilled "Imported — <label>")
	 *   - Skip this item (don't import it)
	 *
	 * @param {Object}      fi          File info from the manifest.
	 * @param {HTMLElement}  container   Element to append the panel to.
	 */
	function siRenderConflictPanel( fi, container ) {
		var conflicts = fi.label_conflicts;
		if ( ! conflicts ) { return; }

		var changed = conflicts.label_changed  || [];
		var dupes   = conflicts.duplicate_label || [];
		if ( ! changed.length && ! dupes.length ) { return; }

		siConflictResolutions = {};
		var total = changed.length + dupes.length;

		// Block the import button until every row is resolved.
		var importBtn = document.getElementById( 'd5dsh-si-import-btn' );
		if ( importBtn ) {
			importBtn.disabled = true;
			importBtn.title    = 'Resolve ' + total + ' conflict(s) before importing.';
		}

		var panel = document.createElement( 'div' );
		panel.className = 'd5dsh-si-conflict-panel';
		panel.id = 'd5dsh-si-conflict-panel';

		var html = '<div class="d5dsh-si-conflict-header">'
			+ '<span class="dashicons dashicons-warning" style="color:#d63638;"></span> '
			+ '<strong>' + total + ' Conflict' + ( total > 1 ? 's' : '' ) + ' Detected</strong>'
			+ '</div>';

		html += '<p class="d5dsh-si-conflict-desc">'
			+ 'The imported file contains items that conflict with your existing data. '
			+ 'Resolve each conflict below, then proceed with the import.</p>';

		// ── Label-changed conflicts (same ID, different label) ───────────
		if ( changed.length ) {
			html += '<div class="d5dsh-si-conflict-section">';
			html += '<h4>Label Changes (' + changed.length + ')</h4>';
			html += '<p class="d5dsh-si-conflict-note">These items already exist but the import '
				+ 'would change their label. Choose how to handle each one.</p>';

			changed.forEach( function ( c, idx ) {
				var rName = 'd5dsh-cr-lc-' + idx;
				html += '<div class="d5dsh-si-conflict-row" data-conflict-type="label_changed" data-conflict-id="' + escAttr( c.id ) + '">';
				html += '<div class="d5dsh-si-conflict-row-header">'
					+ '<span class="d5dsh-si-conflict-id">' + escHtml( c.id ) + '</span>'
					+ '<span class="d5dsh-si-conflict-compare">'
					+ '<span class="d5dsh-si-conflict-cur">' + escHtml( c.current_label ) + '</span>'
					+ ' <span class="d5dsh-si-conflict-arrow">&rarr;</span> '
					+ '<span class="d5dsh-si-conflict-imp">' + escHtml( c.import_label ) + '</span>'
					+ '</span>'
					+ '</div>';
				html += '<div class="d5dsh-si-conflict-options">';
				html += '<label><input type="radio" name="' + rName + '" value="accept_import"> Accept imported label</label>';
				html += '<label><input type="radio" name="' + rName + '" value="keep_current"> Keep current label</label>';
				html += '<label><input type="radio" name="' + rName + '" value="rename"> Rename: '
					+ '<input type="text" class="d5dsh-si-conflict-rename" '
					+ 'data-conflict-id="' + escAttr( c.id ) + '" '
					+ 'value="' + escAttr( 'Imported \u2014 ' + c.import_label ) + '" '
					+ 'disabled></label>';
				html += '</div>';
				html += '</div>';
			} );

			html += '</div>';
		}

		// ── Duplicate-label conflicts (different IDs, same label) ────────
		if ( dupes.length ) {
			html += '<div class="d5dsh-si-conflict-section">';
			html += '<h4>Duplicate Labels (' + dupes.length + ')</h4>';
			html += '<p class="d5dsh-si-conflict-note">These imported items share a label with an '
				+ 'existing item that has a different ID.</p>';

			dupes.forEach( function ( d, idx ) {
				var rName = 'd5dsh-cr-dl-' + idx;
				html += '<div class="d5dsh-si-conflict-row" data-conflict-type="duplicate_label" data-conflict-id="' + escAttr( d.import_id ) + '">';
				html += '<div class="d5dsh-si-conflict-row-header">'
					+ '<span class="d5dsh-si-conflict-label-dup">&ldquo;' + escHtml( d.import_label ) + '&rdquo;</span>'
					+ '<span class="d5dsh-si-conflict-ids">'
					+ 'Existing: <span class="d5dsh-si-conflict-id">' + escHtml( d.existing_id ) + '</span>'
					+ ' &middot; Import: <span class="d5dsh-si-conflict-id">' + escHtml( d.import_id ) + '</span>'
					+ '</span>'
					+ '</div>';
				html += '<div class="d5dsh-si-conflict-options">';
				html += '<label><input type="radio" name="' + rName + '" value="accept_import"> Import as-is (accept duplicate)</label>';
				html += '<label><input type="radio" name="' + rName + '" value="rename"> Rename imported item: '
					+ '<input type="text" class="d5dsh-si-conflict-rename" '
					+ 'data-conflict-id="' + escAttr( d.import_id ) + '" '
					+ 'value="' + escAttr( 'Imported \u2014 ' + d.import_label ) + '" '
					+ 'disabled></label>';
				html += '<label><input type="radio" name="' + rName + '" value="skip"> Skip this item</label>';
				html += '</div>';
				html += '</div>';
			} );

			html += '</div>';
		}

		// Status bar + reject button.
		html += '<div class="d5dsh-si-conflict-footer">';
		html += '<span class="d5dsh-si-conflict-status" id="d5dsh-si-conflict-status">'
			+ '0 of ' + total + ' resolved</span>';
		html += '<button type="button" class="button" id="d5dsh-si-conflict-reject">'
			+ 'Reject Entire Import</button>';
		html += '</div>';

		panel.innerHTML = html;
		container.appendChild( panel );

		// ── Wire radio buttons ───────────────────────────────────────────
		var allRadios = panel.querySelectorAll( 'input[type="radio"]' );
		allRadios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				var row       = radio.closest( '.d5dsh-si-conflict-row' );
				var cType     = row.dataset.conflictType;
				var cId       = row.dataset.conflictId;
				var action    = radio.value;

				// Enable/disable the rename text input for this row.
				var renameInput = row.querySelector( '.d5dsh-si-conflict-rename' );
				if ( renameInput ) {
					renameInput.disabled = ( action !== 'rename' );
				}

				// Store resolution.
				var res = { action: action };
				if ( action === 'rename' && renameInput ) {
					res.label = renameInput.value;
				} else if ( action === 'keep_current' ) {
					// Find the current label from the conflict data.
					var cur = changed.filter( function ( c ) { return c.id === cId; } )[0];
					if ( cur ) { res.label = cur.current_label; }
				}
				siConflictResolutions[ cId ] = res;

				siUpdateConflictStatus( total );
			} );
		} );

		// Also listen to rename input changes.
		panel.querySelectorAll( '.d5dsh-si-conflict-rename' ).forEach( function ( input ) {
			input.addEventListener( 'input', function () {
				var cId = input.dataset.conflictId;
				if ( siConflictResolutions[ cId ] && siConflictResolutions[ cId ].action === 'rename' ) {
					siConflictResolutions[ cId ].label = input.value;
				}
			} );
		} );

		// Reject button.
		document.getElementById( 'd5dsh-si-conflict-reject' ).addEventListener( 'click', function () {
			siConflictResolutions = {};
			siReset();
			showToast( 'info', 'Import cancelled', 'The file was discarded.' );
		} );
	}

	/**
	 * Update the conflict status bar and toggle the Import button.
	 *
	 * @param {number} total  Total number of conflicts.
	 */
	function siUpdateConflictStatus( total ) {
		var resolved = Object.keys( siConflictResolutions ).length;
		var statusEl = document.getElementById( 'd5dsh-si-conflict-status' );
		if ( statusEl ) {
			statusEl.textContent = resolved + ' of ' + total + ' resolved';
			statusEl.className = 'd5dsh-si-conflict-status'
				+ ( resolved >= total ? ' d5dsh-si-conflict-status-done' : '' );
		}
		var importBtn = document.getElementById( 'd5dsh-si-import-btn' );
		if ( importBtn ) {
			if ( resolved >= total ) {
				importBtn.disabled = false;
				importBtn.title    = '';
			} else {
				importBtn.disabled = true;
				importBtn.title    = 'Resolve ' + ( total - resolved ) + ' conflict(s) before importing.';
			}
		}
	}

	/**
	 * Build the unified single-file card HTML.
	 *
	 * Unified card contains:
	 *  1. File info: filename, optional path, "File Type: ..." label
	 *  2. Imported Items: total line + collapsible breakdown
	 *  3. Preliminary Analysis accordion placeholder (content injected by siRenderDepReport)
	 *
	 * @param {Object} fi  File info object from the analysis manifest.
	 * @returns {string}
	 */
	function siBuildSingleSummaryHTML( fi ) {
		if ( ! fi.valid ) {
			return '<div class="d5dsh-si-single-invalid">'
				+ '<p><strong>&#10007; Invalid file</strong></p>'
				+ '<p>' + escHtml( fi.error || 'Unknown error' ) + '</p>'
				+ '</div>';
		}

		var lastSlash = fi.name.lastIndexOf( '/' );
		var basename  = lastSlash >= 0 ? fi.name.slice( lastSlash + 1 ) : fi.name;
		var dirpath   = lastSlash >= 0 ? fi.name.slice( 0, lastSlash )  : '';

		var total  = fi.object_count || 0;
		var newCnt = fi.new_count    || 0;

		var html = '<div class="d5dsh-si-unified-card">';

		// ── File info header ──────────────────────────────────────────────────
		html += '<div class="d5dsh-ufc-file-header">';
		html += '<span class="d5dsh-ufc-filename" title="' + escAttr( fi.name ) + '">' + escHtml( basename ) + '</span>';
		if ( dirpath ) {
			html += '<span class="d5dsh-ufc-filepath">' + escHtml( dirpath ) + '</span>';
		}
		html += '<span class="d5dsh-ufc-type" data-help-anchor="dso-types" title="Click to learn about DSO file types" style="cursor:pointer;">File Type: ' + escHtml( fi.type_label || fi.type || '' ) + '</span>';
		html += '</div>';

		// ── Divider ───────────────────────────────────────────────────────────
		html += '<div class="d5dsh-ufc-divider"></div>';

		// ── Imported Items ────────────────────────────────────────────────────
		html += '<div class="d5dsh-ufc-imported-items">';
		html += '<div class="d5dsh-ufc-ii-header-row">';
		html += '<span class="d5dsh-ufc-ii-label">Imported Items</span>';
		html += '<span class="d5dsh-ufc-ii-helper">[total (new)]</span>';
		html += '</div>';

		// Total line + breakdown toggle.
		html += '<div class="d5dsh-ufc-ii-total-row">';
		html += '<strong class="d5dsh-ufc-ii-total">Total: ' + total + ' (' + newCnt + ')</strong>';

		var hasCats = fi.category_counts && Object.keys( fi.category_counts ).length > 0;
		if ( hasCats || total > 0 ) {
			html += ' <button type="button" class="d5dsh-ufc-breakdown-btn" aria-expanded="false" aria-controls="d5dsh-ufc-breakdown">'
				+ 'Breakdown <span class="d5dsh-ufc-chevron" aria-hidden="true">&#9658;</span>'
				+ '</button>';
		}
		html += '</div>';

		// Collapsible breakdown grid.
		html += '<div id="d5dsh-ufc-breakdown" class="d5dsh-ufc-breakdown" hidden>';
		if ( hasCats ) {
			html += '<ul class="d5dsh-ufc-count-grid">';
			var cats = fi.category_counts;
			Object.keys( cats ).forEach( function ( key ) {
				var cat = cats[ key ];
				html += '<li>' + escHtml( key ) + ': ' + ( cat.total || 0 ) + ' (' + ( cat.new || 0 ) + ')</li>';
			} );
			html += '</ul>';
		} else if ( total > 0 ) {
			// Fallback: no per-category data — show single summary row.
			html += '<ul class="d5dsh-ufc-count-grid">';
			html += '<li>' + escHtml( fi.type_label || fi.type || 'Items' ) + ': ' + total + ' (' + newCnt + ')</li>';
			html += '</ul>';
		}
		html += '</div>';

		html += '</div>'; // .d5dsh-ufc-imported-items

		// ── Preliminary Analysis accordion (content injected by siRenderDepReport) ──
		html += '<div class="d5dsh-ufc-prelim-wrap" id="d5dsh-si-dep-report"></div>';

		html += '</div>'; // .d5dsh-si-unified-card
		return html;
	}

	/**
	 * Render an xlsx dry-run diff as HTML.
	 *
	 * @param {Object} diff  { changes: [], new_entries: [], parse_errors: [] }
	 * @returns {string}
	 */
	function siRenderXlsxDiff( diff ) {
		var changes    = diff.changes    || [];
		var newEntries = diff.new_entries || [];
		var errors     = diff.parse_errors || [];
		var html       = '';

		if ( errors.length ) {
			html += '<div class="d5dsh-si-diff-errors"><strong>Parse errors:</strong><ul>';
			errors.forEach( function ( e ) { html += '<li>' + escHtml( String( e ) ) + '</li>'; } );
			html += '</ul></div>';
		}

		if ( ! changes.length && ! newEntries.length ) {
			html += '<p class="d5dsh-si-diff-none">No changes detected — this file matches the current database.</p>';
			return html;
		}

		html += '<p class="d5dsh-si-diff-summary">';
		if ( changes.length ) {
			html += '<strong>' + changes.length + '</strong> field change' + ( changes.length === 1 ? '' : 's' );
		}
		if ( newEntries.length ) {
			html += ( changes.length ? ', ' : '' ) + '<strong>' + newEntries.length + '</strong> new entr' + ( newEntries.length === 1 ? 'y' : 'ies' );
		}
		html += ' will be applied.</p>';

		if ( changes.length ) {
			html += '<details class="d5dsh-si-diff-details"><summary>View changes (' + changes.length + ')</summary>';
			html += '<table class="d5dsh-si-diff-table"><thead><tr><th>ID</th><th>Label</th><th>Field</th><th>Current</th><th>New</th></tr></thead><tbody>';
			changes.forEach( function ( c ) {
				html += '<tr>'
					+ '<td class="d5dsh-si-diff-id">' + escHtml( c.id ) + '</td>'
					+ '<td>' + escHtml( c.label ) + '</td>'
					+ '<td>' + escHtml( c.field ) + '</td>'
					+ '<td class="d5dsh-si-diff-old">' + escHtml( c.old_value ) + '</td>'
					+ '<td class="d5dsh-si-diff-new">' + escHtml( c.new_value ) + '</td>'
					+ '</tr>';
			} );
			html += '</tbody></table></details>';
		}

		if ( newEntries.length ) {
			html += '<details class="d5dsh-si-diff-details"><summary>New entries (' + newEntries.length + ')</summary>';
			html += '<table class="d5dsh-si-diff-table"><thead><tr><th>ID</th><th>Label</th><th>Type</th></tr></thead><tbody>';
			newEntries.forEach( function ( e ) {
				html += '<tr>'
					+ '<td class="d5dsh-si-diff-id">' + escHtml( e.id ) + '</td>'
					+ '<td>' + escHtml( e.label || '' ) + '</td>'
					+ '<td>' + escHtml( e.type  || '' ) + '</td>'
					+ '</tr>';
			} );
			html += '</tbody></table></details>';
		}

		return html;
	}

	/**
	 * Show the results modal with import outcome.
	 *
	 * @param {Object} data  { results: [], total: int }
	 */
	/**
	 * Build the structured report text used by both Save and Print.
	 *
	 * @param {object} data  The siImportResults object from the server.
	 * @returns {string}
	 */
	function siFormatReportText( data ) {
		var results      = data.results || [];
		var hr           = '\u2500'.repeat( 60 );
		var imported     = new Date().toLocaleString();
		var reportHeader = ( d5dtSettings && d5dtSettings.reportHeader ) ? d5dtSettings.reportHeader : '';
		var reportFooter = ( d5dtSettings && d5dtSettings.reportFooter ) ? d5dtSettings.reportFooter : '';
		var lines        = [];
		if ( reportHeader ) { lines.push( reportHeader ); lines.push( hr ); }
		lines.push( 'D5 Design System Helper \u2014 Import Report' );
		lines.push( 'Generated: ' + imported );
		lines.push( hr );
		lines.push( '' );

		var siteUrl = ( d5dtSettings && d5dtSettings.siteUrl ) ? d5dtSettings.siteUrl : '';

		results.forEach( function ( r, idx ) {
			lines.push( 'FILE ' + ( idx + 1 ) + ' OF ' + results.length );
			lines.push( 'Source file:  ' + ( r.name || '' ) );
			lines.push( 'Imported at:  ' + ( r.imported_at ? new Date( r.imported_at ).toLocaleString() : imported ) );
			if ( siteUrl ) { lines.push( 'Site:         ' + siteUrl ); }
			lines.push( 'Type:         ' + ( r.type_label || r.type || '' ) );
			lines.push( 'Status:       ' + ( r.success ? 'Success' : 'Failed' ) );

			// Source metadata (from _meta block in plugin exports, or ET native fields).
			var sm = r.source_meta || {};
			if ( sm.exported_by  ) { lines.push( 'Exported by:  ' + sm.exported_by ); }
			if ( sm.exported_at  ) { lines.push( 'Exported at:  ' + new Date( sm.exported_at ).toLocaleString() ); }
			if ( sm.site_url     ) { lines.push( 'Source site:  ' + sm.site_url ); }
			if ( sm.plugin_ver   ) { lines.push( 'Plugin ver:   ' + sm.plugin_ver ); }
			if ( sm.app_version  ) { lines.push( 'App version:  ' + sm.app_version ); }
			if ( sm.context      ) { lines.push( 'Context:      ' + sm.context ); }

			lines.push( '' );

			if ( ! r.success ) {
				lines.push( 'Error: ' + ( r.message || 'Unknown error.' ) );
				lines.push( '' );
				lines.push( hr );
				lines.push( '' );
				return;
			}

			// Summary table by group.
			lines.push( 'SUMMARY BY GROUP' );
			var groups = r.groups || {};
			var groupKeys = Object.keys( groups );
			if ( groupKeys.length ) {
				groupKeys.forEach( function ( gk ) {
					var g      = groups[ gk ];
					var label  = siGroupLabel( gk, g );
					var inJson = ( g.in_json !== undefined ) ? g.in_json : ( ( g.new || 0 ) + ( g.updated || 0 ) + ( g.skipped || 0 ) );
					var parts  = [ 'in JSON: ' + inJson ];
					if ( g.updated ) { parts.push( g.updated + ' updated' ); }
					if ( g.new     ) { parts.push( g.new     + ' new' ); }
					if ( g.skipped ) { parts.push( g.skipped + ' skipped' ); }
					lines.push( '  ' + label + ': ' + parts.join( ', ' ) );
				} );
			} else {
				// Fallback: flat totals.
				var parts = [];
				if ( r.new     ) { parts.push( r.new     + ' new' ); }
				if ( r.updated ) { parts.push( r.updated + ' updated' ); }
				if ( r.skipped ) { parts.push( r.skipped + ' skipped' ); }
				lines.push( '  Total: ' + ( parts.length ? parts.join( ', ' ) : r.message || '' ) );
			}
			lines.push( '' );

			// Detail: all items by group.
			var items = r.items || [];
			if ( items.length ) {
				lines.push( 'DETAIL' );
				var curGroup = null;
				items.forEach( function ( item ) {
					var VAR_LABELS = {
						colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts',
						images: 'Images', strings: 'Text', links: 'Links',
					};
					var groupLabel = VAR_LABELS[ item.group ] || item.group || '';
					if ( groupLabel !== curGroup ) {
						lines.push( '' );
						lines.push( '  [ ' + groupLabel + ' ]' );
						curGroup = groupLabel;
					}
					var status = item.status === 'new' ? '[NEW]' : item.status === 'updated' ? '[UPD]' : '[SKP]';
					var detail = '    ' + status + ' ' + ( item.id || '' );
					if ( item.label && item.label !== item.id ) { detail += '  \u2014  ' + item.label; }
					if ( item.value ) { detail += '  =  ' + item.value; }
					lines.push( detail );
				} );
				lines.push( '' );
			}

			lines.push( hr );
			lines.push( '' );
		} );

		return lines.join( '\n' );
	}

	/**
	 * Build a human-readable group label from a group key.
	 */
	function siGroupLabel( gk, g ) {
		var VAR_LABELS = {
			colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts',
			images: 'Images', strings: 'Text', links: 'Links',
		};
		return VAR_LABELS[ gk ] || ( g && g.label ? g.label : gk );
	}

	/**
	 * Show the import results modal with a fully detailed report.
	 */
	function siShowResultsModal( data ) {
		var bodyEl = document.getElementById( 'd5dsh-si-results-body' );
		if ( ! bodyEl ) { return; }

		var results = data.results || [];
		var allOk   = results.every( function ( r ) { return r.success; } );
		var html    = '';

		// Import source and timestamp.
		var importTs = new Date().toLocaleString( [], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } );
		html += '<div class="d5dsh-si-import-meta">'
			+ ( siLastFilename ? 'Imported from <strong>' + escHtml( siLastFilename ) + '</strong>' : 'Import' )
			+ ' on ' + escHtml( importTs )
			+ '</div>';

		// Builder refresh notice.
		html += '<div class="d5dsh-si-builder-notice">'
			+ '<strong>Note:</strong> If the Divi Builder is currently open in another tab, '
			+ 'refresh that tab to load the updated design system.'
			+ '</div>';

		// Overall status banner.
		html += '<div class="d5dsh-si-results-summary ' + ( allOk ? 'd5dsh-si-results-ok' : 'd5dsh-si-results-partial' ) + '">'
			+ '<p>' + ( allOk ? '&#10003; All files imported successfully.' : '&#9888; Some files had errors.' ) + '</p>'
			+ '</div>';

		results.forEach( function ( r, idx ) {
			var lastSlash  = ( r.name || '' ).lastIndexOf( '/' );
			var basename   = lastSlash >= 0 ? r.name.slice( lastSlash + 1 ) : ( r.name || '' );
			var dirpath    = lastSlash >= 0 ? r.name.slice( 0, lastSlash ) : '';
			var sm         = r.source_meta || {};
			var importedAt = r.imported_at ? new Date( r.imported_at ).toLocaleString() : new Date().toLocaleString();

			html += '<div class="d5dsh-si-file-card ' + ( r.success ? 'd5dsh-sfc-ok' : 'd5dsh-sfc-error' ) + '">';

			// Header: filename + type badge.
			html += '<div class="d5dsh-sfc-filename-wrap" title="' + escAttr( r.name || '' ) + '">';
			html += '<span class="d5dsh-sfc-filename">' + escHtml( basename ) + '</span>';
			if ( dirpath ) { html += '<span class="d5dsh-sfc-filepath">' + escHtml( dirpath ) + '</span>'; }
			html += '</div>';

			if ( r.type_label || r.type ) {
				html += '<div class="d5dsh-sfc-type">'
					+ '<span class="d5dsh-si-type-label" data-help-anchor="dso-types" title="Click to learn about DSO file types" style="cursor:pointer">'
					+ escHtml( r.type_label || r.type || '' )
					+ '</span></div>';
			}

			// Source metadata block.
			var siteUrl = ( d5dtSettings && d5dtSettings.siteUrl ) ? d5dtSettings.siteUrl : '';
			var metaRows = [];
			metaRows.push( [ 'Imported', importedAt ] );
			if ( siteUrl        ) { metaRows.push( [ 'Site', siteUrl ] ); }
			if ( sm.exported_by ) { metaRows.push( [ 'Exported by', sm.exported_by ] ); }
			if ( sm.exported_at ) { metaRows.push( [ 'Exported at', new Date( sm.exported_at ).toLocaleString() ] ); }
			if ( sm.site_url    ) { metaRows.push( [ 'Source site', sm.site_url ] ); }
			if ( sm.plugin_ver  ) { metaRows.push( [ 'Plugin ver',  sm.plugin_ver ] ); }
			if ( sm.app_version ) { metaRows.push( [ 'App version', sm.app_version ] ); }
			if ( sm.context     ) { metaRows.push( [ 'Context',     sm.context ] ); }

			if ( metaRows.length ) {
				html += '<table class="d5dsh-sfc-meta-table">';
				metaRows.forEach( function ( row ) {
					html += '<tr><th>' + escHtml( row[0] ) + '</th><td>' + escHtml( row[1] ) + '</td></tr>';
				} );
				html += '</table>';
			}

			if ( ! r.success ) {
				html += '<div class="d5dsh-sfc-msg d5dsh-sfc-msg-err">' + escHtml( r.message || 'Import failed.' ) + '</div>';
				html += '</div>'; // card
				return;
			}

			// Summary by group.
			var groups = r.groups || {};
			var gKeys  = Object.keys( groups );
			if ( gKeys.length ) {
				html += '<div class="d5dsh-sfc-group-summary">';
				html += '<div class="d5dsh-sfc-group-summary-title">Summary</div>';
				html += '<table class="d5dsh-sfc-group-table"><thead>'
					+ '<tr><th>Group</th><th>In JSON</th><th>Updated</th><th>New</th><th>Skipped</th></tr>'
					+ '</thead><tbody>';
				gKeys.forEach( function ( gk ) {
					var g      = groups[ gk ];
					var inJson = ( g.in_json !== undefined ) ? g.in_json : ( ( g.new || 0 ) + ( g.updated || 0 ) + ( g.skipped || 0 ) );
					html += '<tr>'
						+ '<td>' + escHtml( siGroupLabel( gk, g ) ) + '</td>'
						+ '<td class="d5dsh-sfc-count">' + inJson             + '</td>'
						+ '<td class="d5dsh-sfc-count">' + ( g.updated || 0 ) + '</td>'
						+ '<td class="d5dsh-sfc-count">' + ( g.new     || 0 ) + '</td>'
						+ '<td class="d5dsh-sfc-count">' + ( g.skipped || 0 ) + '</td>'
						+ '</tr>';
				} );
				html += '</tbody></table></div>';
			} else {
				html += '<div class="d5dsh-sfc-msg d5dsh-sfc-msg-ok">' + escHtml( r.message || 'Imported successfully.' ) + '</div>';
			}

			// Detail by item, grouped.
			var items = r.items || [];
			if ( items.length ) {
				html += '<details class="d5dsh-sfc-detail"><summary>Detail (' + items.length + ' items)</summary>';
				html += '<table class="d5dsh-sfc-detail-table"><thead>'
					+ '<tr><th>Group</th><th>ID</th><th>Label</th><th>Value</th><th>Status</th></tr>'
					+ '</thead><tbody>';
				items.forEach( function ( item ) {
					var VAR_LABELS = {
						colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts',
						images: 'Images', strings: 'Text', links: 'Links',
					};
					var gl     = VAR_LABELS[ item.group ] || item.group || '';
					var stCls  = item.status === 'new' ? 'd5dsh-sfc-new' : item.status === 'updated' ? 'd5dsh-sfc-updated' : 'd5dsh-sfc-skipped';
					html += '<tr class="' + stCls + '">'
						+ '<td>' + escHtml( gl ) + '</td>'
						+ '<td class="d5dsh-sfc-id">' + escHtml( item.id || '' ) + '</td>'
						+ '<td>' + escHtml( item.label || '' ) + '</td>'
						+ '<td class="d5dsh-sfc-value">' + escHtml( item.value || '' ) + '</td>'
						+ '<td>' + escHtml( item.status || '' ) + '</td>'
						+ '</tr>';
				} );
				html += '</tbody></table></details>';
			}

			// Convert to Excel button (JSON files only).
			if ( r.type && r.type !== 'unknown' ) {
				html += '<div class="d5dsh-sfc-convert" data-file-key="' + escAttr( r.name || '' ) + '">'
					+ '<button type="button" class="button d5dsh-sfc-convert-btn">Convert to Excel</button>'
					+ '</div>';
			}

			html += '</div>'; // card
		} );

		// Conflict resolution log (if any conflicts were resolved during this import).
		var conflictLog = data.conflict_log || [];
		if ( conflictLog.length ) {
			html += '<div class="d5dsh-si-resolution-log">';
			html += '<h4>Conflict Resolutions (' + conflictLog.length + ')</h4>';
			html += '<table><thead><tr>'
				+ '<th>ID</th><th>Action</th><th>Detail</th>'
				+ '</tr></thead><tbody>';
			conflictLog.forEach( function ( entry ) {
				var actionLabel = {
					accept_import: 'Accepted',
					keep_current:  'Kept current',
					rename:        'Renamed',
					skip:          'Skipped',
				}[ entry.action ] || entry.action;
				html += '<tr>'
					+ '<td class="d5dsh-si-conflict-id">' + escHtml( entry.id || '' ) + '</td>'
					+ '<td>' + escHtml( actionLabel ) + '</td>'
					+ '<td>' + escHtml( entry.detail || '' ) + '</td>'
					+ '</tr>';
			} );
			html += '</tbody></table>';
			html += '</div>';
		}

		// Sanitization log — shows what the sanitizer caught and cleaned.
		var sanitizationLog = data.sanitization_log || [];
		if ( sanitizationLog.length ) {
			html += '<div class="d5dsh-si-sanitization-log">';
			html += '<div class="d5dsh-san-header">';
			html += '<span class="d5dsh-san-icon">&#9888;</span>';
			html += '<strong>' + sanitizationLog.length + ' value' + ( sanitizationLog.length !== 1 ? 's' : '' ) + ' were modified during import</strong>';
			html += '</div>';
			html += '<p class="d5dsh-san-intro">Some values in the file contained content that could cause security problems on your site. WordPress automatically cleaned them before storing. Nothing dangerous was saved. Details for each modification are shown below.</p>';
			sanitizationLog.forEach( function ( entry ) {
				var outcome      = entry.outcome      || 'partial';
				var outcomeClass = 'd5dsh-san-card--' + outcome;
				var storedAs     = ( entry.sanitized && entry.sanitized !== '' ) ? entry.sanitized : '(empty — the entire value was removed)';
				var refUrl       = entry.reference_url || '';
				html += '<div class="d5dsh-san-card ' + escHtml( outcomeClass ) + '">';
				// Card header
				html += '<div class="d5dsh-san-card-head">';
				html += '<span class="d5dsh-san-card-location">' + escHtml( entry.context || '' ) + ' &rsaquo; <em>' + escHtml( entry.field || '' ) + '</em></span>';
				html += '<span class="d5dsh-san-card-badge d5dsh-san-badge--' + escHtml( outcome ) + '">' + escHtml( outcome.charAt(0).toUpperCase() + outcome.slice(1) ) + '</span>';
				html += '</div>';
				// What was found
				html += '<div class="d5dsh-san-card-body">';
				html += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">What was found</span>';
				html += '<span class="d5dsh-san-value"><code class="d5dsh-san-code">' + escHtml( ( entry.original || '' ).substring( 0, 300 ) ) + '</code></span></div>';
				// Why it matters
				html += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Why it matters</span>';
				html += '<span class="d5dsh-san-value">' + escHtml( entry.threat_summary || '' );
				if ( refUrl ) {
					html += ' <a class="d5dsh-san-learn-more" href="' + escHtml( refUrl ) + '" target="_blank" rel="noopener">Learn&nbsp;more &#8599;</a>';
				}
				html += '</span></div>';
				// What was done
				html += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">What was done</span>';
				html += '<span class="d5dsh-san-value">' + escHtml( entry.outcome_detail || '' ) + '</span></div>';
				// Stored as
				html += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Stored as</span>';
				html += '<span class="d5dsh-san-value"><code class="d5dsh-san-code d5dsh-san-code--stored">' + escHtml( storedAs.substring( 0, 300 ) ) + '</code></span></div>';
				html += '</div>'; // .d5dsh-san-card-body
				html += '</div>'; // .d5dsh-san-card
			} );
			html += '</div>'; // .d5dsh-si-sanitization-log
		}

		bodyEl.innerHTML = html;

		// Wire Convert to Excel buttons (injected via innerHTML so must re-bind).
		bodyEl.querySelectorAll( '.d5dsh-sfc-convert' ).forEach( function ( wrap ) {
			var btn    = wrap.querySelector( '.d5dsh-sfc-convert-btn' );
			var key    = wrap.dataset.fileKey || '';
			if ( btn ) {
				btn.addEventListener( 'click', function () { siConvertToXlsx( key, btn ); } );
			}
		} );

		// Wire Print button in modal footer.
		var printBtn = document.getElementById( 'd5dsh-si-print-results-btn' );
		if ( printBtn ) {
			printBtn.onclick = function () { siPrintResults(); };
		}

		// Wire Export Security Report (.xlsx) button — only shown when sanitization log is non-empty.
		var sanXlsxBtn = document.getElementById( 'd5dsh-si-export-san-xlsx-btn' );
		if ( sanXlsxBtn ) {
			var sanLog = ( data && data.sanitization_log ) ? data.sanitization_log : [];
			sanXlsxBtn.style.display = sanLog.length ? '' : 'none';
			sanXlsxBtn.onclick = function () { siExportSanitizationXlsx( sanLog ); };
		}

		openModal( 'd5dsh-si-results-modal' );
	}

	/**
	 * Print the import results using a PrintBuilder-style popup with page header/footer.
	 */
	function siPrintResults() {
		if ( ! siImportResults ) { return; }

		var results       = siImportResults.results || [];
		var reportHeader  = ( d5dtSettings && d5dtSettings.reportHeader ) ? d5dtSettings.reportHeader : '';
		var reportFooter  = ( d5dtSettings && d5dtSettings.reportFooter ) ? d5dtSettings.reportFooter : 'D5 Design System Helper — Import Report';
		var now           = new Date().toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
		var win           = window.open( '', '_blank', 'width=900,height=750,scrollbars=yes' );
		if ( ! win ) { return; }

		var bodyHtml = '';

		results.forEach( function ( r, idx ) {
			var sm = r.source_meta || {};
			var importedAt = r.imported_at ? new Date( r.imported_at ).toLocaleString() : now;
			var siteUrl    = ( d5dtSettings && d5dtSettings.siteUrl ) ? d5dtSettings.siteUrl : '';

			bodyHtml += '<div class="file-section">';

			if ( results.length > 1 ) {
				bodyHtml += '<h2>File ' + ( idx + 1 ) + ' of ' + results.length + '</h2>';
			}

			// Header block.
			bodyHtml += '<table class="meta-table"><tbody>';
			bodyHtml += '<tr><th>File</th><td>' + escHtml( r.name || '' ) + '</td></tr>';
			bodyHtml += '<tr><th>Type</th><td>' + escHtml( r.type_label || r.type || '' ) + '</td></tr>';
			bodyHtml += '<tr><th>Imported</th><td>' + escHtml( importedAt ) + '</td></tr>';
			if ( siteUrl ) { bodyHtml += '<tr><th>Site</th><td>' + escHtml( siteUrl ) + '</td></tr>'; }
			if ( sm.exported_by ) { bodyHtml += '<tr><th>Exported by</th><td>' + escHtml( sm.exported_by ) + '</td></tr>'; }
			if ( sm.exported_at ) { bodyHtml += '<tr><th>Exported at</th><td>' + escHtml( new Date( sm.exported_at ).toLocaleString() ) + '</td></tr>'; }
			if ( sm.site_url    ) { bodyHtml += '<tr><th>Source site</th><td>' + escHtml( sm.site_url ) + '</td></tr>'; }
			if ( sm.plugin_ver  ) { bodyHtml += '<tr><th>Plugin ver</th><td>' + escHtml( sm.plugin_ver ) + '</td></tr>'; }
			if ( sm.app_version ) { bodyHtml += '<tr><th>App version</th><td>' + escHtml( sm.app_version ) + '</td></tr>'; }
			bodyHtml += '</tbody></table>';

			if ( ! r.success ) {
				bodyHtml += '<p class="error">Import failed: ' + escHtml( r.message || 'Unknown error' ) + '</p>';
				bodyHtml += '</div>';
				return;
			}

			// Summary table.
			var groups   = r.groups || {};
			var groupKeys = Object.keys( groups );
			if ( groupKeys.length ) {
				bodyHtml += '<h3>Summary by Group</h3>';
				bodyHtml += '<table><thead><tr><th>Group</th><th>In JSON</th><th>Updated</th><th>New</th><th>Skipped</th></tr></thead><tbody>';
				groupKeys.forEach( function ( gk ) {
					var g       = groups[ gk ];
					var inJson  = ( g.in_json  !== undefined ) ? g.in_json  : ( ( g.new || 0 ) + ( g.updated || 0 ) + ( g.skipped || 0 ) );
					bodyHtml += '<tr>'
						+ '<td>' + escHtml( siGroupLabel( gk, g ) ) + '</td>'
						+ '<td>' + inJson           + '</td>'
						+ '<td>' + ( g.updated || 0 ) + '</td>'
						+ '<td>' + ( g.new     || 0 ) + '</td>'
						+ '<td>' + ( g.skipped || 0 ) + '</td>'
						+ '</tr>';
				} );
				bodyHtml += '</tbody></table>';
			}

			// Detail table.
			var items = r.items || [];
			if ( items.length ) {
				var VAR_LABELS = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
				bodyHtml += '<h3>Detail</h3>';
				bodyHtml += '<table><thead><tr><th>Group</th><th>ID</th><th>Label</th><th>Value</th><th>Status</th></tr></thead><tbody>';
				items.forEach( function ( item ) {
					var gl = VAR_LABELS[ item.group ] || item.group || '';
					bodyHtml += '<tr>'
						+ '<td>' + escHtml( gl ) + '</td>'
						+ '<td><code>' + escHtml( item.id || '' ) + '</code></td>'
						+ '<td>' + escHtml( item.label || '' ) + '</td>'
						+ '<td>' + escHtml( item.value || '' ) + '</td>'
						+ '<td>' + escHtml( item.status || '' ) + '</td>'
						+ '</tr>';
				} );
				bodyHtml += '</tbody></table>';
			}

			bodyHtml += '</div>';
			if ( idx < results.length - 1 ) { bodyHtml += '<hr class="page-break">'; }
		} );

		win.document.write( '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Import Report</title><style>'
			+ '*, *::before, *::after { box-sizing: border-box; }'
			+ 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; font-size: 11pt; line-height: 1.5; color: #333; margin: 0; padding: 20px; }'
			+ '@media print {'
			+ '  body { padding: 0; }'
			+ '  .no-print { display: none !important; }'
			+ '  .page-break { page-break-after: always; border: none; }'
			+ '  @page { size: A4; margin: 0.75in 0.75in 1in 0.75in; }'
			+ '  .print-footer { position: fixed; bottom: 0; left: 0; right: 0; height: 30px; display: flex; justify-content: space-between; align-items: center; padding: 0 0.75in; font-size: 8pt; color: #666; background: white; border-top: 1px solid #eee; }'
			+ '  h2, h3 { page-break-after: avoid; }'
			+ '  table { page-break-inside: avoid; }'
			+ '  .page-number::after { content: counter(page); }'
			+ '  .page-total::after { content: counter(pages); }'
			+ '}'
			+ '@media screen {'
			+ '  .print-footer { display: none; }'
			+ '  .print-btn-bar { position: fixed; top: 20px; right: 20px; display: flex; gap: 10px; z-index: 1000; }'
			+ '  .print-btn { padding: 8px 18px; background: #2563eb; color: white; border: none; border-radius: 5px; font-size: 13px; cursor: pointer; }'
			+ '  .print-btn.sec { background: #6b7280; }'
			+ '  .preview { max-width: 820px; margin: 60px auto 40px; background: white; box-shadow: 0 0 20px rgba(0,0,0,.1); padding: 40px; }'
			+ '}'
			+ '.doc-title { font-size: 15pt; font-weight: bold; text-align: center; margin: 0 0 4px 0; }'
			+ '.doc-date  { font-size: 10pt; text-align: center; color: #555; margin: 0 0 1.5em 0; }'
			+ 'h2 { font-size: 13pt; margin: 1.2em 0 0.4em; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }'
			+ 'h3 { font-size: 11pt; margin: 1em 0 0.3em; color: #374151; }'
			+ 'table { border-collapse: collapse; width: 100%; margin: 0.5em 0 1em; font-size: 10pt; }'
			+ 'th, td { border: 1px solid #d1d5db; padding: 5px 8px; text-align: left; }'
			+ 'th { background: #f3f4f6; font-weight: 600; width: 1%; white-space: nowrap; }'
			+ 'tr:nth-child(even) { background: #f9fafb; }'
			+ 'code { font-family: "SF Mono", Menlo, Monaco, monospace; font-size: 9pt; background: #f3f4f6; padding: 1px 4px; border-radius: 3px; }'
			+ '.meta-table th { width: 120px; }'
			+ 'hr { border: none; border-top: 1px solid #e5e7eb; margin: 2em 0; }'
			+ '.file-section { margin-bottom: 2em; }'
			+ '.error { color: #dc2626; }'
			+ '</style></head><body>'
			+ '<div class="print-btn-bar no-print">'
			+ '<button class="print-btn" onclick="window.print()">Print / Save PDF</button>'
			+ '<button class="print-btn sec" onclick="window.close()">Close</button>'
			+ '</div>'
			+ '<div class="preview">'
			+ ( reportHeader ? '<p class="doc-title">' + escHtml( reportHeader ) + '</p>' : '' )
			+ '<p class="doc-date">Import Report &mdash; ' + escHtml( now ) + '</p>'
			+ bodyHtml
			+ '</div>'
			+ '<div class="print-footer">'
			+ '<span>' + escHtml( reportFooter ) + '</span>'
			+ '<span>Page <span class="page-number"></span> of <span class="page-total"></span></span>'
			+ '</div>'
			+ '</body></html>'
		);
		win.document.close();
		win.print();
	}

	/**
	 * Convert a cached JSON file to xlsx and download it.
	 *
	 * @param {string} key    File key within the session (empty for single-file).
	 * @param {HTMLButtonElement} btn  The clicked button (for feedback).
	 */
	function siConvertToXlsx( key, btn ) {
		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = 'Converting…';
		}

		var url = d5dtSimpleImport.ajaxUrl
			+ '?action=d5dsh_simple_json_to_xlsx'
			+ '&nonce=' + encodeURIComponent( d5dtSimpleImport.nonce );

		fetch( url, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { file_key: key || '' } ),
		} )
		.then( function ( r ) {
			if ( ! r.ok ) {
				return r.json().then( function ( j ) {
					throw new Error( ( j.data && j.data.message ) || ( 'HTTP ' + r.status ) );
				} );
			}
			// Success: file is streamed as xlsx — trigger download via blob.
			return r.blob().then( function ( blob ) {
				var disposition = r.headers.get( 'Content-Disposition' ) || '';
				var match       = disposition.match( /filename="([^"]+)"/ );
				var filename    = match ? match[1] : ( ( key ? key.replace( /\.json$/i, '' ) : 'export' ) + '.xlsx' );
				var dlUrl       = URL.createObjectURL( blob );
				var a           = document.createElement( 'a' );
				a.href          = dlUrl;
				a.download      = filename;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( dlUrl );
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = 'Convert to Excel';
				}
			} );
		} )
		.catch( function ( err ) {
			if ( btn ) {
				btn.disabled    = false;
				btn.textContent = 'Convert to Excel';
			}
			showToast( 'error', 'Conversion failed', err.message || 'Unknown error' );
		} );
	}

	/**
	 * Save the import results as a plain-text .txt file.
	 */
	function siSaveReport() {
		if ( ! siImportResults ) { return; }
		var text = siFormatReportText( siImportResults );
		var blob = new Blob( [ text ], { type: 'text/plain' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href     = url;
		a.download = 'd5dsh-import-report-' + new Date().toISOString().slice( 0, 19 ).replace( /[T:]/g, '-' ) + '.txt';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		URL.revokeObjectURL( url );
	}

	/**
	 * Export the sanitization log to a formatted XLSX via the server-side AJAX endpoint.
	 *
	 * @param {Array} sanLog  The sanitization_log array from the import response.
	 */
	function siExportSanitizationXlsx( sanLog ) {
		if ( ! sanLog || ! sanLog.length ) { return; }
		if ( typeof d5dtSimpleImport === 'undefined' || ! d5dtSimpleImport.xlsxAction ) {
			showToast( 'error', 'Not configured', 'Export endpoint is not available.' );
			return;
		}

		var btn = document.getElementById( 'd5dsh-si-export-san-xlsx-btn' );
		if ( btn ) {
			btn.disabled    = true;
			btn.textContent = 'Generating…';
		}

		var now      = new Date().toISOString().slice( 0, 19 ).replace( /[T:]/g, '-' );
		var payload  = JSON.stringify( { log: sanLog, filename: 'd5dsh-sanitization-report-' + now } );
		var url      = d5dtSimpleImport.ajaxUrl
			+ '?action=' + encodeURIComponent( d5dtSimpleImport.xlsxAction )
			+ '&nonce='  + encodeURIComponent( d5dtSimpleImport.xlsxNonce );

		fetch( url, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: payload,
		} )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( 'Server error ' + r.status ); }
				var cd = r.headers.get( 'Content-Disposition' ) || '';
				var fnMatch = cd.match( /filename="([^"]+)"/ );
				var dlName  = fnMatch ? fnMatch[ 1 ] : 'd5dsh-sanitization-report.xlsx';
				return r.blob().then( function ( blob ) { return { blob: blob, name: dlName }; } );
			} )
			.then( function ( result ) {
				var url = URL.createObjectURL( result.blob );
				var a   = document.createElement( 'a' );
				a.href     = url;
				a.download = result.name;
				document.body.appendChild( a );
				a.click();
				document.body.removeChild( a );
				URL.revokeObjectURL( url );
			} )
			.catch( function ( err ) {
				showToast( 'error', 'Export failed', err.message || 'Could not generate the report.' );
			} )
			.finally( function () {
				if ( btn ) {
					btn.disabled    = false;
					btn.textContent = 'Export Security Report (.xlsx)';
				}
			} );
	}

	/**
	 * Get selected file keys from the zip file list.
	 *
	 * For single-file flow: returns ['single'] as a signal (server ignores it).
	 *
	 * @returns {string[]}
	 */
	function siGetSelectedKeys() {
		// Check if we have a single-file summary visible.
		var singleEl = document.getElementById( 'd5dsh-si-single-summary' );
		if ( singleEl && singleEl.style.display !== 'none' ) {
			return [ 'single' ];
		}
		// Zip: collect checked keys.
		var keys = [];
		document.querySelectorAll( '.d5dsh-si-file-chk:checked' ).forEach( function ( chk ) {
			keys.push( chk.value );
		} );
		return keys;
	}

	/**
	 * Update the selection count label, check-all state, and dependent button states.
	 */
	function siUpdateSelectionCount() {
		var allChks   = document.querySelectorAll( '.d5dsh-si-file-chk:not(:disabled)' );
		var checked   = document.querySelectorAll( '.d5dsh-si-file-chk:checked' );
		var countEl   = document.getElementById( 'd5dsh-si-selection-count' );
		var checkAll  = document.getElementById( 'd5dsh-si-check-all' );
		var importBtn = document.getElementById( 'd5dsh-si-import-btn' );
		var headerUtils = document.getElementById( 'd5dsh-si-header-utils' );

		var nChecked = checked.length;
		var nTotal   = allChks.length;

		// Count label: "2 of 3 files selected" or "1 file selected".
		if ( countEl ) {
			countEl.textContent = nChecked + ' of ' + nTotal + ' ' + ( nTotal === 1 ? 'file' : 'files' ) + ' selected';
		}

		// Select-all indeterminate state.
		if ( checkAll ) {
			checkAll.checked       = nChecked === nTotal && nTotal > 0;
			checkAll.indeterminate = nChecked > 0 && nChecked < nTotal;
		}

		// Import button: disabled when nothing selected.
		if ( importBtn ) {
			importBtn.textContent = 'Import Selected';
			importBtn.disabled    = nChecked === 0;
		}

		// Header print/download icons: disabled when nothing selected.
		if ( headerUtils ) {
			headerUtils.querySelectorAll( '.d5dsh-icon-btn' ).forEach( function ( btn ) {
				btn.disabled = nChecked === 0;
			} );
		}
	}

	/* ════════════════════════════════════════════════════════════════════
	   AUDIT TAB
	   ════════════════════════════════════════════════════════════════════ */

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 18 — AUDIT TAB                                               ║
	// ╚══════════════════════════════════════════════════════════════════╝
	function initAudit() {
		// ── Analysis section switcher ─────────────────────────────────────────
		document.querySelectorAll( '.d5dsh-analysis-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var sec = btn.dataset.analysisSection;
				document.querySelectorAll( '.d5dsh-analysis-btn' ).forEach( function ( b ) {
					b.classList.toggle( 'd5dsh-section-active', b === btn );
				} );
				document.querySelectorAll( '.d5dsh-analysis-section' ).forEach( function ( el ) {
					el.style.display = el.id === 'd5dsh-analysis-section-' + sec ? '' : 'none';
				} );
			} );
		} );

		// ── Audit buttons ─────────────────────────────────────────────────────
		const btn = document.getElementById( 'd5dsh-audit-run-btn' );
		if ( btn ) btn.addEventListener( 'click', auditRun );
		const fullBtn = document.getElementById( 'd5dsh-audit-full-btn' );
		if ( fullBtn ) fullBtn.addEventListener( 'click', auditFullRun );
		const resetBtn = document.getElementById( 'd5dsh-audit-reset-btn' );
		if ( resetBtn ) resetBtn.addEventListener( 'click', auditReset );

		// ── Whole-audit export bar (buttons inside results container) ─────────
		const printBtn2 = document.getElementById( 'd5dsh-audit-print-btn2' );
		if ( printBtn2 ) printBtn2.addEventListener( 'click', auditPrint );
		const xlsxBtn2 = document.getElementById( 'd5dsh-audit-xlsx-btn2' );
		if ( xlsxBtn2 ) xlsxBtn2.addEventListener( 'click', function () {
			var data = window._d5dshLastAuditReport;
			if ( data ) { auditStreamXlsx( data ); }
		} );
		const csvBtn2 = document.getElementById( 'd5dsh-audit-csv-btn2' );
		if ( csvBtn2 ) csvBtn2.addEventListener( 'click', auditDownloadCsv );

		// ── Per-tier export buttons (delegated — rendered dynamically after audit runs) ──
		var auditSection = document.getElementById( 'd5dsh-analysis-section-audit' );
		if ( auditSection ) {
			auditSection.addEventListener( 'click', function ( e ) {
				var printB = e.target.closest( '.d5dsh-tier-print-btn' );
				var xlsxB  = e.target.closest( '.d5dsh-tier-xlsx-btn' );
				var csvB   = e.target.closest( '.d5dsh-tier-csv-btn' );
				if ( printB ) {
					e.stopPropagation();
					auditPrintTier( printB.dataset.tier );
				} else if ( xlsxB ) {
					e.stopPropagation();
					auditStreamXlsxTier( xlsxB.dataset.tier );
				} else if ( csvB ) {
					e.stopPropagation();
					auditDownloadCsvTier( csvB.dataset.tier );
				}
			} );
		}

		// ── Restore persisted results if available ────────────────────────────
		if ( window._d5dshLastAuditReport ) {
			auditRenderReport( window._d5dshLastAuditReport );
			var actionsEl = document.getElementById( 'd5dsh-audit-actions' );
			if ( actionsEl ) { actionsEl.style.display = ''; }
		}
		if ( window._d5dshLastScanReport ) {
			renderScanResults( window._d5dshLastScanReport );
			var scanActionsEl = document.getElementById( 'd5dsh-scan-actions' );
			if ( scanActionsEl ) { scanActionsEl.style.display = ''; }
		}
	}

	function auditRun() {
		const btn     = document.getElementById( 'd5dsh-audit-run-btn' );
		const spinner = document.getElementById( 'd5dsh-audit-spinner' );
		const errBox  = document.getElementById( 'd5dsh-audit-error' );
		const report  = document.getElementById( 'd5dsh-audit-report' );
		const actions = document.getElementById( 'd5dsh-audit-actions' );

		btn.disabled          = true;
		spinner.style.display = 'inline-block';
		errBox.style.display  = 'none';
		actions.style.display = 'none';

		const fd = new FormData();
		fd.append( 'action', 'd5dsh_audit_run' );
		fd.append( 'nonce',  d5dtAudit.nonce );

		fetch( d5dtAudit.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( ! json.success ) {
					auditShowError( ( json.data && json.data.message ) ? json.data.message : 'Audit failed.' );
					return;
				}
				window._d5dshLastAuditReport = json.data;
				auditRenderReport( json.data );
				actions.style.display = 'flex';
			} )
			.catch( function () {
				auditShowError( 'Network error — audit could not be completed.' );
			} )
			.then( function () {
				btn.disabled          = false;
				spinner.style.display = 'none';
			} );
	}

	/**
	 * Contextual Audit — runs a Content Scan first, then calls the full-audit endpoint
	 * with the scan's dso_usage index so content-dependent checks can run.
	 * Results are shown in both the Audit and Content Scan sections.
	 */
	function auditFullRun() {
		if ( window._d5dshLastScanReport ) {
			var proceed = window.confirm(
				'A Content Scan report is currently displayed.\n\n' +
				'The Contextual Audit will run a fresh Content Scan and replace the existing ' +
				'scan results.\n\n' +
				'Click OK to continue, or Cancel to export the existing scan first.'
			);
			if ( ! proceed ) { return; }
		}

		const runBtn  = document.getElementById( 'd5dsh-audit-run-btn' );
		const fullBtn = document.getElementById( 'd5dsh-audit-full-btn' );
		const spinner = document.getElementById( 'd5dsh-audit-spinner' );
		const errBox  = document.getElementById( 'd5dsh-audit-error' );
		const report  = document.getElementById( 'd5dsh-audit-report' );
		const actions = document.getElementById( 'd5dsh-audit-actions' );

		if ( runBtn )  { runBtn.disabled  = true; }
		if ( fullBtn ) { fullBtn.disabled = true; }
		spinner.style.display = 'inline-block';
		errBox.style.display  = 'none';
		report.style.display  = 'none';
		actions.style.display = 'none';

		function _reenable() {
			if ( runBtn )  { runBtn.disabled  = false; }
			if ( fullBtn ) { fullBtn.disabled = false; }
			spinner.style.display = 'none';
		}

		// Step 1: run the content scan.
		const scanFd = new FormData();
		scanFd.append( 'action', 'd5dsh_content_scan' );
		scanFd.append( 'nonce',  d5dtAudit.nonce );

		fetch( d5dtAudit.ajaxUrl, { method: 'POST', body: scanFd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( scanJson ) {
				if ( ! scanJson.success ) {
					_reenable();
					auditShowError( 'Content scan failed — could not run Contextual Audit.' );
					return;
				}
				// Store and render scan results.
				window._d5dshLastScanReport = scanJson.data;
				renderScanResults( scanJson.data );
				var scanActionsEl = document.getElementById( 'd5dsh-scan-actions' );
				if ( scanActionsEl ) { scanActionsEl.style.display = ''; }

				// Step 2: post dso_usage to the full-audit endpoint.
				var dsoUsage = ( scanJson.data && scanJson.data.dso_usage ) ? scanJson.data.dso_usage : {};
				return fetch(
					d5dtAudit.ajaxUrl + '?action=d5dsh_audit_run_full&nonce=' + encodeURIComponent( d5dtAudit.nonce ),
					{
						method:  'POST',
						headers: { 'Content-Type': 'application/json' },
						body:    JSON.stringify( { dso_usage: dsoUsage } ),
					}
				).then( function ( r ) { return r.json(); } );
			} )
			.then( function ( auditJson ) {
				if ( ! auditJson ) { return; } // aborted above
				_reenable();
				if ( ! auditJson.success ) {
					auditShowError( ( auditJson.data && auditJson.data.message ) ? auditJson.data.message : 'Audit failed.' );
					return;
				}
				window._d5dshLastAuditReport = auditJson.data;
				auditRenderReport( auditJson.data );
				actions.style.display = 'flex';
			} )
			.catch( function () {
				_reenable();
				auditShowError( 'Network error — Contextual Audit could not be completed.' );
			} );
	}

	/**
	 * Reset the audit panel: clear results and return to initial state.
	 */
	function auditReset() {
		window._d5dshLastAuditReport = null;
		var report    = document.getElementById( 'd5dsh-audit-report' );
		var container = document.getElementById( 'd5dsh-audit-results-container' );
		var errBox    = document.getElementById( 'd5dsh-audit-error' );
		var actions   = document.getElementById( 'd5dsh-audit-actions' );
		var prescan   = document.getElementById( 'd5dsh-audit-prescan' );
		if ( report )    { report.innerHTML = ''; report.style.display = ''; }
		if ( container ) { container.style.display = 'none'; }
		if ( errBox )    { errBox.textContent = ''; errBox.style.display = 'none'; }
		if ( actions )   { actions.style.display = 'none'; }
		if ( prescan )   { prescan.style.display = ''; }
	}

	function auditRenderReport( data ) {
		const el    = document.getElementById( 'd5dsh-audit-report' );
		if ( ! el ) { return; }
		const tiers = [
			{ key: 'errors',     label: 'Errors',     cls: 'error'    },
			{ key: 'warnings',   label: 'Warnings',   cls: 'warning'  },
			{ key: 'advisories', label: 'Advisories', cls: 'advisory' },
		];

		// Build a DSO usage index from the last scan report (if available).
		var dsoUsageIndex = {};
		var scanReport = window._d5dshLastScanReport;
		if ( scanReport && scanReport.dso_usage ) {
			var vars    = scanReport.dso_usage.variables || {};
			var presets = scanReport.dso_usage.presets   || {};
			[ vars, presets ].forEach( function ( map ) {
				Object.keys( map ).forEach( function ( id ) {
					var entry = map[ id ];
					dsoUsageIndex[ id ] = {
						count:  entry.count || 0,
						titles: ( entry.posts || [] ).map( function ( p ) {
							return p.post_title || String( p.post_id );
						} ).join( ', ' ),
					};
				} );
			} );
		}

		let meta = data.meta || {};
		var isContextual = !! meta.is_full;
		var auditTypeBadge = isContextual
			? ' <span class="d5dsh-audit-full-badge">Contextual Audit</span>'
			: ' <span class="d5dsh-audit-basic-badge">Simple Audit</span>';
		let html = '<div class="d5dsh-audit-meta">Audit run: '
			+ escHtml( meta.ran_at || '' ) + auditTypeBadge
			+ ' &mdash; '
			+ ( meta.variable_count || 0 ) + ' variables, '
			+ ( meta.color_count    || 0 ) + ' colors, '
			+ ( meta.preset_count   || 0 ) + ' presets'
			+ '</div>';

		// Simple Audit callout — inform user about the additional Contextual checks.
		if ( ! isContextual ) {
			html += '<div class="d5dsh-audit-simple-callout">'
				+ '<strong>Simple Audit</strong> — checks Global Variables and Presets without scanning content. '
				+ 'Run a <strong>Contextual Audit</strong> to also detect: archived DSOs in published pages, '
				+ 'broken DSO references in content, orphaned presets, high-impact variables, '
				+ 'variables bypassing presets, singleton presets, overlapping presets, and preset naming conventions.'
				+ '</div>';
		}

		// Distribution chart — rendered from variable_type_distribution advisory data.
		html += auditRenderDistributionChart( data );

		for ( let i = 0; i < tiers.length; i++ ) {
			html += auditRenderTierPanel(
				data[ tiers[ i ].key ] || [],
				tiers[ i ].label,
				tiers[ i ].cls,
				dsoUsageIndex,
				isContextual
			);
		}

		el.innerHTML = html;
		el.style.display = '';
		// Show the results container, hide pre-audit term cards.
		var container = document.getElementById( 'd5dsh-audit-results-container' );
		if ( container ) { container.style.display = ''; }
		var prescan = document.getElementById( 'd5dsh-audit-prescan' );
		if ( prescan ) { prescan.style.display = 'none'; }
	}

	// Hover definitions for each audit check type (Item 12)
	var AUDIT_CHECK_DEFS = {
		// Basic audit checks
		broken_variable_refs:           'A preset references a variable ID that no longer exists in Global Variables. The preset will render its default value instead of the intended variable value.',
		archived_vars_in_presets:        'A preset references a variable that is marked as archived (inactive). The variable still exists but is not active; this may be intentional or may indicate a stale reference.',
		singleton_variables:             'A variable is defined but is only used once across all presets. Consider whether this variable is worth keeping as a global token or whether it should be hardcoded in the single preset that uses it.',
		near_duplicate_values:           'Two or more variables of the same type have identical or very similar values. Consolidating them into a single variable improves consistency and reduces maintenance overhead.',
		hardcoded_extraction_candidates: 'A CSS property value is hardcoded in a preset but the same value already exists as a global variable. Replacing the hardcoded value with the variable reference improves design system consistency.',
		orphaned_variables:              'A variable exists in Global Variables but is not referenced by any preset or content. It may be unused legacy data that can be safely removed.',
		duplicate_labels:                'Two or more DSOs share the same label but have different values or types. Duplicate labels across DSO types cause confusion when selecting design tokens and make the design system harder to maintain.',
		preset_duplicate_names:          'Two or more presets of the same module type share the same name. Duplicate preset names make it impossible to identify the correct preset in the Divi editor dropdown.',
		empty_label_variables:           'A variable or color has no label. Unlabelled variables are unnamed in the Divi editor picker and cannot be meaningfully identified or selected.',
		unnamed_presets:                 'A preset has no name. Unnamed presets appear blank in the Divi editor dropdown and cannot be distinguished from other presets of the same type.',
		similar_variable_names:          'Two or more variables have labels that normalise to the same token (e.g. "Primary Blue", "primary-blue", "PrimaryBlue"). Inconsistent label formats make the variable list harder to scan in the Divi editor.',
		naming_convention_inconsistency: 'Variables of the same type use mixed naming styles (e.g. some use "Title Case" and others use "kebab-case"). A consistent naming convention makes the design system easier to maintain.',
		preset_no_variable_refs:         'A preset contains no variable references — all style values are hardcoded. Changes to the global design system will not affect this preset.',
		variable_type_distribution:      'Summary of how variables are distributed across types. A type exceeding 60% of all variables may indicate an unbalanced design system.',
		// Contextual Audit — content-dependent checks
		archived_dsos_in_content:        'An archived variable or preset is directly referenced in published content. The page will render incorrectly because the DSO is no longer active.',
		broken_dso_refs_in_content:      'A variable or preset ID is referenced directly in published post content but is not defined on this site. The element will fail to load the intended style.',
		orphaned_presets:                'A preset exists in the design system but is not applied in any scanned content item. It may be stale and a candidate for removal.',
		high_impact_variables:           'A variable is directly referenced in a large number of content items. Changes to this variable — renaming, changing its value, or archiving it — will have widespread impact across the site.',
		preset_naming_convention:        'Presets for the same module type use mixed naming styles. Consistent preset names make the editor dropdown easier to scan.',
		variables_bypassing_presets:     'A variable that is also defined inside preset definitions is being referenced directly in post content. Inline references bypass the preset system — consider whether a preset should be applied instead.',
		singleton_presets:               'A preset is applied in only one content item. It may have been created for a one-off style need rather than as a reusable design system component.',
		overlapping_presets:             'Two presets for the same module type share a high proportion of their variable references. They may be near-duplicates that could be consolidated into a single preset.',
	};

	function auditCheckDefinition( checkKey ) {
		return AUDIT_CHECK_DEFS[ checkKey ] || '';
	}

	/**
	 * Build a CSS bar chart from variable_type_distribution advisory data.
	 * Reads the Distribution summary item to extract per-type counts.
	 * Returns an empty string if the data is absent.
	 */
	function auditRenderDistributionChart( data ) {
		var advisories = data.advisories || [];
		var distCheck  = null;
		for ( var i = 0; i < advisories.length; i++ ) {
			if ( advisories[ i ].check === 'variable_type_distribution' ) {
				distCheck = advisories[ i ];
				break;
			}
		}
		if ( ! distCheck ) { return ''; }

		// Find the Distribution summary item (label === 'Distribution').
		var distItem = null;
		for ( var j = 0; j < distCheck.items.length; j++ ) {
			if ( distCheck.items[ j ].label === 'Distribution' ) {
				distItem = distCheck.items[ j ];
				break;
			}
		}
		if ( ! distItem ) { return ''; }

		// Parse "Variable type breakdown — Colors: 12, Numbers: 5, ... (total: 20)."
		var detail = distItem.detail || '';
		var pairs  = detail.replace( /.*—\s*/, '' ).replace( /\s*\(total:.*/, '' ).split( ',' );
		var entries = [];
		var total   = 0;
		pairs.forEach( function ( pair ) {
			var m = pair.trim().match( /^(.+?):\s*(\d+)$/ );
			if ( m ) {
				var count = parseInt( m[ 2 ], 10 );
				entries.push( { type: m[ 1 ].trim(), count: count } );
				total += count;
			}
		} );
		if ( ! entries.length || total === 0 ) { return ''; }

		// Color palette for known types; fallback gray for unknowns.
		var TYPE_COLORS = {
			Colors: '#e85353', Numbers: '#4b8ef1', Fonts: '#f5a623',
			Images: '#7ed321', Text: '#9b59b6', Links: '#1abc9c',
		};

		var bars = entries.map( function ( e ) {
			var pct   = Math.round( ( e.count / total ) * 100 );
			var color = TYPE_COLORS[ e.type ] || '#8a8fa8';
			return '<div class="d5dsh-dist-row">'
				+ '<span class="d5dsh-dist-label">' + escHtml( e.type ) + '</span>'
				+ '<div class="d5dsh-dist-bar-wrap">'
				+ '<div class="d5dsh-dist-bar" style="width:' + pct + '%;background:' + color + '"></div>'
				+ '</div>'
				+ '<span class="d5dsh-dist-count">' + e.count + ' <small>(' + pct + '%)</small></span>'
				+ '</div>';
		} ).join( '' );

		return '<div class="d5dsh-dist-chart">'
			+ '<div class="d5dsh-dist-title">Variable Distribution <small>(' + total + ' total)</small></div>'
			+ bars
			+ '</div>';
	}

	// Checks that only appear in the Contextual Audit (require a Content Scan).
	var CONTEXTUAL_ONLY_CHECKS = [
		'archived_dsos_in_content',
		'broken_dso_refs_in_content',
		'orphaned_presets',
		'high_impact_variables',
		'preset_naming_convention',
		'variables_bypassing_presets',
		'singleton_presets',
		'overlapping_presets',
	];

	function auditRenderTierPanel( checks, tierLabel, tierCls, dsoUsageIndex, isContextual ) {
		var totalItems = 0;
		for ( var i = 0; i < checks.length; i++ ) {
			totalItems += ( checks[ i ].items ? checks[ i ].items.length : 0 );
		}

		var badge = totalItems > 0
			? '<span class="d5dsh-audit-badge d5dsh-audit-badge-' + tierCls + '">' + totalItems + '</span>'
			: '<span class="d5dsh-audit-badge d5dsh-audit-badge-ok">&#10003; None</span>';

		// Per-tier export bar id.
		var exportBarId = 'd5dsh-audit-tier-export-' + tierCls;
		var tierBodyId  = 'd5dsh-audit-tier-body-' + tierCls;

		// Tier panel — Errors open by default, others collapsed.
		var isOpen = tierCls === 'error' ? ' open' : '';
		var html = '<details class="d5dsh-audit-tier d5dsh-audit-tier-' + tierCls + '"' + isOpen + '>'
			+ '<summary class="d5dsh-audit-tier-summary">'
			+ escHtml( tierLabel ) + ' ' + badge
			+ '<span class="d5dsh-section-export-bar" id="' + escAttr( exportBarId ) + '">'
			+ '<button type="button" class="button button-small d5dsh-tier-print-btn" data-tier="' + escAttr( tierCls ) + '">&#9113; Print</button>'
			+ '<button type="button" class="button button-small d5dsh-tier-xlsx-btn"  data-tier="' + escAttr( tierCls ) + '">&#8595; Excel</button>'
			+ '<button type="button" class="button button-small d5dsh-tier-csv-btn"   data-tier="' + escAttr( tierCls ) + '">&#8595; CSV</button>'
			+ '</span>'
			+ '</summary>'
			+ '<div class="d5dsh-audit-tier-body" id="' + escAttr( tierBodyId ) + '">';

		if ( totalItems === 0 ) {
			html += '<p class="d5dsh-audit-clean">No ' + escHtml( tierLabel.toLowerCase() ) + ' found.</p>';
			html += '</div></details>';
			return html;
		}

		// Flatten all checks into rows for d5dshRenderSection — one row per item,
		// with the check name carried on each row.
		var hasDso = dsoUsageIndex && Object.keys( dsoUsageIndex ).length > 0;
		var rows   = [];
		for ( var ci = 0; ci < checks.length; ci++ ) {
			var check = checks[ ci ];
			if ( ! check.items || ! check.items.length ) { continue; }
			var isContextualCheck = CONTEXTUAL_ONLY_CHECKS.indexOf( check.check ) !== -1;
			var checkLabel = check.check.replace( /_/g, ' ' );
			// Append a Contextual tag to the check label for visual differentiation.
			var checkLabelRendered = isContextualCheck
				? checkLabel + ' <span class="d5dsh-audit-contextual-tag" title="Only runs in Contextual Audit">Contextual</span>'
				: checkLabel;
			var checkDef   = auditCheckDefinition( check.check );
			for ( var ji = 0; ji < check.items.length; ji++ ) {
				var item      = check.items[ ji ];
				var firstId   = ( item.id || '' ).split( ',' )[ 0 ].trim();
				var usageEntry = hasDso ? ( dsoUsageIndex[ firstId ] || null ) : null;
				rows.push( {
					check:            checkLabelRendered,
					_checkKey:        check.check,
					_isContextual:    isContextualCheck,
					_checkDef:        checkDef,
					id:               item.id       || '',
					var_type:         item.var_type || '',
					label:            item.label    || '',
					detail:           item.detail   || '',
					_dso_uses:        usageEntry ? usageEntry.count  : '',
					_used_in:         usageEntry ? usageEntry.titles : '',
				} );
			}
		}

		// Unique inner body id for d5dshRenderSection.
		var innerBodyId = 'd5dsh-audit-tier-inner-' + tierCls;
		html += '<div id="' + escAttr( innerBodyId ) + '"></div>';
		html += '</div></details>';

		// Return the shell HTML now; wire the inner table after it's in the DOM.
		// We use a deferred call so the <details> is in the document first.
		setTimeout( function () {
			var cols = hasDso ? AUDIT_TIER_COLS_WITH_USAGE : AUDIT_TIER_COLS;
			d5dshRenderSection( {
				bodyId:    innerBodyId,
				columns:   cols,
				filterCols: [ 'check', 'id', 'label' ],
				getRows:   function () { return rows; },
				emptyMsg:  'No items.',
				data:      rows,
			} );
		}, 0 );

		return html;
	}

	function auditPrint() {
		var data = window._d5dshLastAuditReport;
		if ( ! data ) { return; }

		var meta   = data.meta || {};
		var tiers  = [
			{ key: 'errors',     label: 'Errors',     cls: 'error'   },
			{ key: 'warnings',   label: 'Warnings',   cls: 'warning' },
			{ key: 'advisories', label: 'Advisories', cls: 'advisory' },
		];

		var body = '<p>Audit run: ' + escHtml( meta.ran_at || '' )
			+ ' &mdash; ' + ( meta.variable_count || 0 ) + ' variables, '
			+ ( meta.preset_count || 0 ) + ' presets</p>';

		tiers.forEach( function ( tier ) {
			var checks = data[ tier.key ] || [];
			var items  = [];
			checks.forEach( function ( c ) {
				( c.items || [] ).forEach( function ( it ) { items.push( { check: c.label || c.key, item: it } ); } );
			} );
			body += '<h2 class="' + tier.cls + '">' + escHtml( tier.label ) + ' (' + items.length + ')</h2>';
			if ( items.length ) {
				body += '<table><thead><tr><th>Check</th><th>Detail</th></tr></thead><tbody>';
				items.forEach( function ( row ) {
					body += '<tr><td>' + escHtml( row.check ) + '</td><td>' + escHtml( typeof row.item === 'string' ? row.item : JSON.stringify( row.item ) ) + '</td></tr>';
				} );
				body += '</tbody></table>';
			} else {
				body += '<p class="success">&#10003; None</p>';
			}
		} );

		openPrintWindow( body, 'Audit Report', 'portrait', null );
	}

	function auditDownloadCsv() {
		const data = window._d5dshLastAuditReport;
		if ( ! data ) return;
		auditStreamXlsx( data );
	}

	/**
	 * Print a single audit tier (errors | warnings | advisories).
	 */
	function auditPrintTier( tierCls ) {
		var data = window._d5dshLastAuditReport;
		if ( ! data ) { return; }
		var tierMap = { error: 'errors', warning: 'warnings', advisory: 'advisories' };
		var tierKey   = tierMap[ tierCls ] || tierCls;
		var tierLabel = tierCls.charAt(0).toUpperCase() + tierCls.slice(1) + 's';
		var checks    = data[ tierKey ] || [];
		var rows      = [];
		checks.forEach( function ( c ) {
			( c.items || [] ).forEach( function ( it ) {
				rows.push( { check: c.check || '', id: it.id || '', label: it.label || '', detail: it.detail || '' } );
			} );
		} );
		var body = '<h2>' + escHtml( tierLabel ) + ' (' + rows.length + ')</h2>';
		if ( rows.length ) {
			body += '<table><thead><tr><th>Check</th><th>ID</th><th>Label</th><th>Detail</th></tr></thead><tbody>';
			rows.forEach( function ( r ) {
				body += '<tr><td>' + escHtml( r.check ) + '</td><td>' + escHtml( r.id ) + '</td>'
					+ '<td>' + escHtml( r.label ) + '</td><td>' + escHtml( r.detail ) + '</td></tr>';
			} );
			body += '</tbody></table>';
		} else {
			body += '<p>&#10003; None</p>';
		}
		openPrintWindow( body, tierLabel + ' — Audit Report', 'landscape', null );
	}

	/**
	 * XLSX download scoped to a single audit tier (re-uses full audit XLSX for now).
	 */
	function auditStreamXlsxTier( tierCls ) {
		var data = window._d5dshLastAuditReport;
		if ( data ) { auditStreamXlsx( data ); }
	}

	/**
	 * CSV download scoped to a single audit tier.
	 */
	function auditDownloadCsvTier( tierCls ) {
		var data = window._d5dshLastAuditReport;
		if ( ! data ) { return; }
		var tierMap = { error: 'errors', warning: 'warnings', advisory: 'advisories' };
		var tierKey = tierMap[ tierCls ] || tierCls;
		var checks  = data[ tierKey ] || [];
		var rows    = [];
		checks.forEach( function ( c ) {
			( c.items || [] ).forEach( function ( it ) {
				rows.push( [ c.check || '', it.id || '', it.label || '', it.detail || '' ] );
			} );
		} );
		var csv = 'Check,ID,Label,Detail\n';
		rows.forEach( function ( r ) {
			csv += r.map( function ( v ) { return '"' + String( v ).replace( /"/g, '""' ) + '"'; } ).join( ',' ) + '\n';
		} );
		var blob = new Blob( [ csv ], { type: 'text/csv' } );
		var a    = document.createElement( 'a' );
		a.href     = URL.createObjectURL( blob );
		a.download = 'd5dsh-audit-' + tierCls + '.csv';
		a.click();
		URL.revokeObjectURL( a.href );
	}

	/**
	 * POST audit report data to the server and trigger an XLSX download.
	 *
	 * @param {object} data  Audit report (errors/warnings/advisories/meta).
	 */
	function auditStreamXlsx( data ) {
		if ( typeof d5dtAudit === 'undefined' ) { return; }

		var url = d5dtAudit.ajaxUrl
			+ '?action=d5dsh_audit_xlsx'
			+ '&nonce=' + encodeURIComponent( d5dtAudit.nonce );

		// Include last scan report if available so the server can append DSO usage data.
		var payload = {
			audit: data,
			scan:  window._d5dshLastScanReport || null,
		};

		fetch( url, {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( payload ),
		} )
		.then( function ( res ) {
			if ( ! res.ok ) { throw new Error( 'HTTP ' + res.status ); }
			return res.blob();
		} )
		.then( function ( blob ) {
			var a      = document.createElement( 'a' );
			a.href     = URL.createObjectURL( blob );
			a.download = 'd5dsh-audit-report.xlsx';
			a.click();
			URL.revokeObjectURL( a.href );
		} )
		.catch( function ( err ) {
			showToast( 'error', 'Download failed', err.message || 'Could not generate XLSX.' );
		} );
	}

	function auditShowError( msg ) {
		const el         = document.getElementById( 'd5dsh-audit-error' );
		el.textContent   = msg;
		el.style.display = 'block';
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 19 — CONTENT SCAN                                           ║
	// ╚══════════════════════════════════════════════════════════════════════╝

	/**
	 * Wire the Content Scan button.
	 * Called from DOMContentLoaded via initAudit — the button lives on the
	 * same Audit tab so we initialise it there.
	 */
	function initContentScan() {
		const btn = document.getElementById( 'd5dsh-scan-run-btn' );
		if ( ! btn ) return;
		btn.addEventListener( 'click', contentScanRun );

		const xlsxBtn = document.getElementById( 'd5dsh-scan-xlsx-btn' );
		if ( xlsxBtn ) { xlsxBtn.addEventListener( 'click', scanDownloadXlsx ); }

		const resetBtn = document.getElementById( 'd5dsh-scan-reset-btn' );
		if ( resetBtn ) { resetBtn.addEventListener( 'click', scanReset ); }

		// ── Per-scan-section export buttons (delegated on results container) ──
		var scanResults = document.getElementById( 'd5dsh-scan-results' );
		if ( scanResults ) {
			scanResults.addEventListener( 'click', function ( e ) {
				var printB = e.target.closest( '.d5dsh-scan-section-print-btn' );
				var xlsxB  = e.target.closest( '.d5dsh-scan-section-xlsx-btn' );
				var csvB   = e.target.closest( '.d5dsh-scan-section-csv-btn' );
				if ( printB ) {
					e.stopPropagation();
					scanPrintSection( printB.dataset.section );
				} else if ( xlsxB ) {
					e.stopPropagation();
					// Full scan xlsx (server-side, includes all sections).
					scanDownloadXlsx();
				} else if ( csvB ) {
					e.stopPropagation();
					scanDownloadCsvSection( csvB.dataset.section );
				}
			} );
		}
	}

	/**
	 * Run the content scan via AJAX and render results.
	 */
	function contentScanRun() {
		const btn     = document.getElementById( 'd5dsh-scan-run-btn' );
		const spinner = document.getElementById( 'd5dsh-scan-spinner' );
		const errBox  = document.getElementById( 'd5dsh-scan-error' );
		const results = document.getElementById( 'd5dsh-scan-results' );

		btn.disabled          = true;
		spinner.style.display = 'inline-block';
		errBox.style.display  = 'none';
		results.style.display = 'none';

		const fd = new FormData();
		fd.append( 'action', 'd5dsh_content_scan' );
		fd.append( 'nonce',  d5dtAudit.nonce );

		fetch( d5dtAudit.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				btn.disabled          = false;
				spinner.style.display = 'none';

				if ( ! json.success ) {
					errBox.textContent   = json.data && json.data.message ? json.data.message : 'Content scan failed.';
					errBox.style.display = 'block';
					return;
				}

				window._d5dshLastScanReport = json.data;
				renderScanResults( json.data );
			} )
			.catch( function ( err ) {
				btn.disabled          = false;
				spinner.style.display = 'none';
				errBox.textContent    = 'Content scan request failed: ' + ( err.message || 'Network error' );
				errBox.style.display  = 'block';
			} );
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ UNIFIED SECTION RENDERER — d5dshRenderSection( cfg )                 ║
	// ╚══════════════════════════════════════════════════════════════════════╝
	//
	// Single parameterised function that renders any scan/audit table section.
	// All table sections — Active Content, Inventory, DSO Usage, No-DSO,
	// and the three Audit tier tables — go through this one function.
	// Changing styling, sticky-header behaviour, filter dropdowns, or
	// export-button placement here updates every table at once.
	//
	// cfg properties:
	//   bodyId       {string}   ID of the container <div> to render into.
	//   badgeId      {string}   ID of the badge <span> in the section summary.
	//   columns      {Array}    [{ key, label, width?, formatter? }, ...]
	//                           formatter(value, row) → HTML string (optional).
	//   getRows      {Function} (data) → Array of flat row objects.
	//   getTotal     {Function} (data) → integer total for badge (default: rows.length).
	//   groupBy      {string}   Optional row field to group rows under H4 subheadings.
	//   subRows      {Function} Optional (row) → Array of child rows.
	//   subRowClass  {string}   CSS class applied to sub-rows (default 'd5dsh-scan-canvas-row').
	//   filterCols   {Array}    Column keys that get a live filter dropdown ([] = none).
	//   noteKey      {Function} Optional (row) → string for note-store lookup (tints row).
	//   emptyMsg     {string}   Message shown when no rows found.
	//   wrapId       {string}   ID of scroll-wrap div (receives JS-computed height).
	//   exportCfg    {object}   { printFn, xlsxFn, csvFn } — per-section export callbacks.
	//                           Buttons are injected into the matching d5dsh-section-export-bar.
	//   data         {*}        The raw data object passed to getRows / getTotal.

	// ── Per-section filter state (keyed by bodyId) ─────────────────────────
	var _sectionFilters  = {};   // bodyId → { colKey: filterValue }
	var _sectionSort     = {};   // bodyId → { key, dir }
	var _activeFilterSection = null;  // bodyId currently showing a filter panel

	function d5dshRenderSection( cfg ) {
		var bodyEl = document.getElementById( cfg.bodyId );
		if ( ! bodyEl ) { return; }

		var badgeEl = cfg.badgeId ? document.getElementById( cfg.badgeId ) : null;

		// Initialise per-section state on first call.
		if ( ! _sectionFilters[ cfg.bodyId ] ) { _sectionFilters[ cfg.bodyId ] = {}; }
		if ( ! _sectionSort[ cfg.bodyId ] )    { _sectionSort[ cfg.bodyId ]    = { key: '', dir: 'asc' }; }

		var filters = _sectionFilters[ cfg.bodyId ];
		var sortObj = _sectionSort[ cfg.bodyId ];

		// Extract rows.
		var allRows = cfg.getRows ? cfg.getRows( cfg.data ) : [];
		var total   = cfg.getTotal ? cfg.getTotal( cfg.data ) : allRows.length;

		if ( badgeEl ) { badgeEl.textContent = String( total ); }

		if ( allRows.length === 0 ) {
			setElMsg( bodyEl, 'd5dsh-audit-clean', cfg.emptyMsg || 'No data found.' );
			_sectionInitScrollWrap( cfg );
			return;
		}

		// Apply column filters.
		var rows = allRows.filter( function ( row ) {
			return Object.keys( filters ).every( function ( colKey ) {
				var fv = ( filters[ colKey ] || '' ).toLowerCase().trim();
				if ( ! fv ) { return true; }
				// Find formatter for this column to get display value.
				var col = ( cfg.columns || [] ).find( function ( c ) { return c.key === colKey; } );
				var raw = col && col.formatter
					? col.formatter( row[ colKey ], row )
					: String( row[ colKey ] == null ? '' : row[ colKey ] );
				// Strip HTML tags for comparison.
				return raw.replace( /<[^>]*>/g, '' ).toLowerCase().indexOf( fv ) !== -1;
			} );
		} );

		// Apply sort.
		if ( sortObj.key ) {
			rows = rows.slice().sort( function ( a, b ) {
				var av = String( a[ sortObj.key ] == null ? '' : a[ sortObj.key ] ).toLowerCase();
				var bv = String( b[ sortObj.key ] == null ? '' : b[ sortObj.key ] ).toLowerCase();
				var cmp = av < bv ? -1 : av > bv ? 1 : 0;
				return sortObj.dir === 'desc' ? -cmp : cmp;
			} );
		}

		// Build scroll-wrap + table HTML.
		var wrapId = cfg.wrapId || ( cfg.bodyId + '-wrap' );
		var cols   = cfg.columns || [];
		var filterCols = cfg.filterCols || [];

		// thead — supports optional two-row grouped headers via col.groupHeader
		var hasGroups = cols.some( function ( c ) { return !! c.groupHeader; } );
		var theadHtml = '<thead>';

		if ( hasGroups ) {
			// Row 1: group labels (merged spans) for grouped columns; blank th for ungrouped
			theadHtml += '<tr class="d5dsh-thead-group-row">';
			var gi = 0;
			while ( gi < cols.length ) {
				var gc = cols[ gi ];
				if ( gc.groupHeader ) {
					// Count how many consecutive cols share this exact groupHeader label
					var gSpan = 1;
					while ( gi + gSpan < cols.length && cols[ gi + gSpan ].groupHeader === gc.groupHeader ) {
						gSpan++;
					}
					theadHtml += '<th colspan="' + gSpan + '" class="d5dsh-thead-group-label">' + escHtml( gc.groupHeader ) + '</th>';
					gi += gSpan;
				} else {
					theadHtml += '<th rowspan="2"'
						+ ( gc.width ? ' style="width:' + gc.width + '"' : '' )
						+ ( gc.headerTitle ? ' title="' + escAttr( gc.headerTitle ) + '"' : '' )
						+ '>' + escHtml( gc.label ) + '</th>';
					gi++;
				}
			}
			theadHtml += '</tr>';

			// Row 2: individual column labels (only for grouped columns — ungrouped already have rowspan=2)
			theadHtml += '<tr>';
			cols.forEach( function ( col ) {
				if ( ! col.groupHeader ) { return; } // already rendered with rowspan=2
				var isFilterable = filterCols.indexOf( col.key ) !== -1;
				var activeFilter = filters[ col.key ] ? ' d5dsh-filter-active' : '';
				theadHtml += '<th'
					+ ( col.width ? ' style="width:' + col.width + '"' : '' )
					+ ( col.headerTitle ? ' title="' + escAttr( col.headerTitle ) + '"' : '' )
					+ ( isFilterable ? ' data-filter-col="' + escAttr( col.key ) + '" class="d5dsh-filterable' + activeFilter + '"' : '' )
					+ '>'
					+ escHtml( col.label )
					+ ( isFilterable ? ' <span class="d5dsh-filter-icon">&#9660;</span>' : '' )
					+ '</th>';
			} );
			theadHtml += '</tr>';

		} else {
			// Single-row thead (no grouped columns)
			theadHtml += '<tr>';
			cols.forEach( function ( col ) {
				var isFilterable = filterCols.indexOf( col.key ) !== -1;
				var activeFilter = filters[ col.key ] ? ' d5dsh-filter-active' : '';
				theadHtml += '<th'
					+ ( col.width ? ' style="width:' + col.width + '"' : '' )
					+ ( col.headerTitle ? ' title="' + escAttr( col.headerTitle ) + '"' : '' )
					+ ( isFilterable ? ' data-filter-col="' + escAttr( col.key ) + '" class="d5dsh-filterable' + activeFilter + '"' : '' )
					+ '>'
					+ escHtml( col.label )
					+ ( isFilterable ? ' <span class="d5dsh-filter-icon">&#9660;</span>' : '' )
					+ '</th>';
			} );
			theadHtml += '</tr>';
		}

		theadHtml += '</thead>';

		// tbody — grouped or flat
		var tbodyHtml = '<tbody>';

		if ( cfg.groupBy ) {
			// Group rows by cfg.groupBy field with H4 subheadings.
			// Each group gets its own sub-table inside the body.
			var groups = {};
			var groupOrder = [];
			rows.forEach( function ( row ) {
				var gk = String( row[ cfg.groupBy ] || '(other)' );
				if ( ! groups[ gk ] ) { groups[ gk ] = []; groupOrder.push( gk ); }
				groups[ gk ].push( row );
			} );
			tbodyHtml = '';  // reset — groups use separate tables
			groupOrder.forEach( function ( gk ) {
				var gRows = groups[ gk ];
				tbodyHtml += '<h4 class="d5dsh-scan-type-heading">'
					+ escHtml( gk )
					+ ' <span class="d5dsh-audit-badge">' + gRows.length + '</span>'
					+ '</h4>'
					+ '<table class="d5dsh-section-table widefat striped">'
					+ theadHtml
					+ '<tbody>';
				gRows.forEach( function ( row ) {
					tbodyHtml += _sectionBuildRow( row, cols, cfg );
					// Sub-rows (canvas children etc.)
					if ( cfg.subRows ) {
						var children = cfg.subRows( row ) || [];
						children.forEach( function ( child ) {
							tbodyHtml += _sectionBuildRow( child, cols, cfg, true );
						} );
					}
				} );
				tbodyHtml += '</tbody></table>';
			} );
		} else {
			rows.forEach( function ( row ) {
				tbodyHtml += _sectionBuildRow( row, cols, cfg );
				if ( cfg.subRows ) {
					var children = cfg.subRows( row ) || [];
					children.forEach( function ( child ) {
						tbodyHtml += _sectionBuildRow( child, cols, cfg, true );
					} );
				}
			} );
			tbodyHtml += '</tbody>';
		}

		// Assemble final HTML — for grouped layout there's no outer <table>.
		var tableHtml;
		if ( cfg.groupBy ) {
			tableHtml = tbodyHtml;
		} else {
			tableHtml = '<table class="d5dsh-section-table widefat striped">'
				+ theadHtml + tbodyHtml + '</table>';
		}

		// Inject into a scroll-wrap div so we can do JS-computed height.
		bodyEl.innerHTML = '<div id="' + escAttr( wrapId ) + '" class="d5dsh-section-scroll-wrap">'
			+ tableHtml
			+ '</div>';

		// Wire filter-header clicks (same openFilterPanel used by Manage tab).
		if ( filterCols.length ) {
			_sectionWireFilters( cfg, bodyEl, rows, allRows );
		}

		// JS-computed height (matches Manage tab approach).
		_sectionInitScrollWrap( cfg );
	}

	// ── Build one <tr> for d5dshRenderSection ─────────────────────────────
	function _sectionBuildRow( row, cols, cfg, isSubRow ) {
		var noteKey  = cfg.noteKey ? cfg.noteKey( row ) : null;
		var noteData = noteKey ? ( notesData[ noteKey ] || {} ) : {};
		var hasNote  = !! ( noteData.note );
		var rowClass = ( isSubRow ? ( cfg.subRowClass || 'd5dsh-scan-canvas-row' ) : '' )
			+ ( hasNote ? ' d5dsh-row-has-note' : '' );

		var html = '<tr' + ( rowClass ? ' class="' + escAttr( rowClass.trim() ) + '"' : '' ) + '>';
		cols.forEach( function ( col ) {
			var rawVal = row[ col.key ];
			var cellHtml = col.formatter
				? col.formatter( rawVal, row )
				: escHtml( rawVal == null ? '' : String( rawVal ) );
			html += '<td>' + cellHtml + '</td>';
		} );
		html += '</tr>';
		return html;
	}

	// ── JS-computed scroll-wrap height (matches Manage tab) ───────────────
	// Skips computation if the wrap is inside a closed <details> element
	// (getBoundingClientRect returns all-zeros for hidden content, making the
	// height computation meaningless). In that case the CSS max-height fallback
	// on .d5dsh-section-scroll-wrap takes effect instead.
	function _sectionInitScrollWrap( cfg ) {
		var wrapId = cfg.wrapId || ( cfg.bodyId + '-wrap' );
		var wrap   = document.getElementById( wrapId );
		if ( ! wrap ) { return; }

		// If any ancestor <details> is closed, skip JS height now.
		// The section's toggle event (wired in renderScanResults) will call us again.
		var el = wrap.parentElement;
		while ( el ) {
			if ( el.tagName === 'DETAILS' && ! el.open ) { return; }
			el = el.parentElement;
		}

		var rect       = wrap.getBoundingClientRect();
		var padding    = 24;
		var minHeight  = 200; // enough to show at least 4 data rows fully
		var height     = Math.max( window.innerHeight - rect.top - padding, minHeight );
		wrap.style.height    = height + 'px';
		wrap.style.overflowY = 'auto';
		wrap.style.overflowX = 'auto';
	}

	// ── Wire column filter dropdowns (reuses shared openFilterPanel) ───────
	function _sectionWireFilters( cfg, bodyEl, visibleRows, allRows ) {
		var filterCols = cfg.filterCols || [];
		var filters    = _sectionFilters[ cfg.bodyId ];
		var sortObj    = _sectionSort[ cfg.bodyId ];

		bodyEl.querySelectorAll( 'th[data-filter-col]' ).forEach( function ( th ) {
			th.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var col = th.dataset.filterCol;
				if ( _activeFilterSection === cfg.bodyId + ':' + col ) {
					closeAllFilterPanels();
					_activeFilterSection = null;
					return;
				}
				_activeFilterSection = cfg.bodyId + ':' + col;

				openFilterPanel( {
					th:           th,
					col:          col,
					filtersObj:   filters,
					sortObj:      sortObj,
					getValues:    function ( key ) {
						// Distinct values from the full (unfiltered) row set.
						var seen = {};
						var vals = [];
						allRows.forEach( function ( row ) {
							var colDef = ( cfg.columns || [] ).find( function ( c ) { return c.key === key; } );
							var v = colDef && colDef.formatter
								? colDef.formatter( row[ key ], row ).replace( /<[^>]*>/g, '' )
								: String( row[ key ] == null ? '' : row[ key ] );
							if ( ! seen[ v ] ) { seen[ v ] = true; vals.push( v ); }
						} );
						return vals.sort();
					},
					closeAll:     function () {
						closeAllFilterPanels();
						_activeFilterSection = null;
					},
					scrollWrapId: cfg.wrapId || ( cfg.bodyId + '-wrap' ),
					onApply: function () { d5dshRenderSection( cfg ); },
					onClear: function () { d5dshRenderSection( cfg ); },
					onSort:  function () { d5dshRenderSection( cfg ); },
				} );
			} );
		} );
	}

	// ── Column formatters (reusable) ───────────────────────────────────────

	function _fmtStatus( val ) {
		return '<span class="d5dsh-scan-status d5dsh-scan-status-' + escAttr( val || '' ) + '">'
			+ escHtml( val || '' ) + '</span>';
	}

	function _fmtDate( val ) {
		return escHtml( ( val || '' ).substring( 0, 10 ) );
	}

	function _fmtBadgeCount( val ) {
		var n = parseInt( val, 10 ) || 0;
		return n > 0 ? '<span class="d5dsh-audit-badge">' + n + '</span>' : '&mdash;';
	}

	function _fmtCode( val ) {
		return '<code>' + escHtml( val || '' ) + '</code>';
	}

	function _fmtDsoCount( val, row ) {
		var n = ( row.var_refs ? row.var_refs.length : 0 )
			  + ( row.preset_refs ? row.preset_refs.length : 0 );
		return n > 0 ? '<span class="d5dsh-audit-badge">' + n + '</span>' : '&mdash;';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Scan section configs
	// ─────────────────────────────────────────────────────────────────────────

	// Active Content columns
	var SCAN_ACTIVE_COLS = [
		{ key: 'post_id',       label: 'ID',       width: '60px',  headerTitle: 'WordPress post ID — the unique integer ID assigned by WordPress to each page, post, layout, or template. This is the internal database ID, not a Divi-specific identifier.' },
		{ key: 'post_title',    label: 'Title'                     },
		{ key: 'post_status',   label: 'Status',   width: '90px',  formatter: function ( v ) { return _fmtStatus( v ); } },
		{ key: 'post_modified', label: 'Modified', width: '100px', formatter: function ( v ) { return _fmtDate( v ); } },
		{ key: 'var_refs',      label: 'Vars',     width: '55px',  formatter: function ( v ) { return escHtml( String( v ? v.length : 0 ) ); } },
		{ key: 'preset_refs',   label: 'Presets',  width: '65px',  formatter: function ( v ) { return escHtml( String( v ? v.length : 0 ) ); } },
		{
			key: 'tot_vars_in_presets',
			label: 'Tot Vars',
			width: '72px',
			groupHeader: 'Vars in Presets',
			headerTitle: 'Vars in Presets — Total variable references found inside the presets used by this item',
			formatter: function ( v ) { return escHtml( String( v || 0 ) ); },
		},
		{
			key: 'uniq_vars_in_presets',
			label: 'Uniq Vars',
			width: '72px',
			groupHeader: 'Vars in Presets',
			headerTitle: 'Vars in Presets — Number of distinct variables referenced inside the presets used by this item',
			formatter: function ( v ) { return escHtml( String( v || 0 ) ); },
		},
	];

	// Content Inventory columns
	var SCAN_INVENTORY_COLS = [
		{ key: 'post_id',       label: 'ID',       width: '60px',  headerTitle: 'WordPress post ID — the unique integer ID assigned by WordPress to each page, post, layout, or template. This is the internal database ID, not a Divi-specific identifier.' },
		{ key: 'post_title',    label: 'Title'                     },
		{ key: 'post_type',     label: 'Type',     width: '120px' },
		{ key: 'post_status',   label: 'Status',   width: '90px',  formatter: function ( v ) { return _fmtStatus( v ); } },
		{ key: 'post_modified', label: 'Modified', width: '100px', formatter: function ( v ) { return _fmtDate( v ); } },
		{ key: 'var_refs',      label: 'Vars',     width: '55px',  formatter: function ( v ) { return escHtml( String( v ? v.length : 0 ) ); } },
		{ key: 'preset_refs',   label: 'Presets',  width: '65px',  formatter: function ( v ) { return escHtml( String( v ? v.length : 0 ) ); } },
		{
			key: 'tot_vars_in_presets',
			label: 'Tot Vars',
			width: '72px',
			groupHeader: 'Vars in Presets',
			headerTitle: 'Vars in Presets — Total variable references found inside the presets used by this item',
			formatter: function ( v ) { return escHtml( String( v || 0 ) ); },
		},
		{
			key: 'uniq_vars_in_presets',
			label: 'Uniq Vars',
			width: '72px',
			groupHeader: 'Vars in Presets',
			headerTitle: 'Vars in Presets — Number of distinct variables referenced inside the presets used by this item',
			formatter: function ( v ) { return escHtml( String( v || 0 ) ); },
		},
	];

	// DSO Usage Index columns (variables sub-table)
	var SCAN_DSO_VAR_COLS = [
		{ key: 'dso_type',   label: 'Type',        width: '90px'  },
		{ key: '_dso_id',    label: 'DSO ID',       headerTitle: 'The unique identifier for this Design System Object as stored in the Divi database (e.g. gcid-abc123 for a variable, or a preset UUID for a preset).', formatter: function ( v ) { return _fmtCode( v ); } },
		{ key: 'label',      label: 'Label',        width: '160px' },
		{ key: 'count',      label: 'Used by',      width: '80px',  formatter: function ( v ) { return _fmtBadgeCount( v ); } },
		{ key: '_titles',    label: 'Content items' },
	];

	// DSO Usage Index columns (presets sub-table) — same shape
	var SCAN_DSO_PRESET_COLS = SCAN_DSO_VAR_COLS;

	// No-DSO Content columns
	var SCAN_NODSO_COLS = [
		{ key: 'post_id',       label: 'ID',       width: '60px'  },
		{ key: 'post_title',    label: 'Title'                     },
		{ key: 'post_status',   label: 'Status',   width: '90px',  formatter: function ( v ) { return _fmtStatus( v ); } },
		{ key: 'post_modified', label: 'Modified', width: '100px', formatter: function ( v ) { return _fmtDate( v ); } },
	];

	// Content → DSO Map flat-table columns (one row per content × DSO reference)
	var SCAN_DSOMAP_COLS = [
		{ key: '_title',      label: 'Title'                                         },
		{ key: 'post_type',   label: 'Type',     width: '110px'                      },
		{ key: 'post_status', label: 'Status',   width: '90px',  formatter: function ( v ) { return _fmtStatus( v ); } },
		{ key: 'dso_type',    label: 'DSO Type', width: '80px'                       },
		{ key: '_dso_id',     label: 'DSO ID',   width: '180px', formatter: function ( v ) { return _fmtCode( v ); } },
		{ key: 'dso_label',   label: 'Label',    width: '150px'                      },
		{ key: 'via',         label: 'Via',      width: '160px'                      },
	];

	// Audit tier item columns
	var AUDIT_TIER_COLS = [
		{ key: 'check',      label: 'Check',    width: '180px' },
		{ key: 'id',         label: 'ID',       width: '180px', formatter: function ( v ) { return _fmtCode( v ); } },
		{ key: 'var_type',   label: 'Type',     width: '90px',  formatter: function ( v ) { return v ? escHtml( v ) : '&mdash;'; } },
		{ key: 'label',      label: 'Label',    width: '160px' },
		{ key: 'detail',     label: 'Detail'                   },
	];

	// Audit tier columns with DSO usage (when scan data present)
	var AUDIT_TIER_COLS_WITH_USAGE = AUDIT_TIER_COLS.concat( [
		{ key: '_dso_uses',  label: 'DSO Uses', width: '80px',  formatter: function ( v ) { return v ? _fmtBadgeCount( v ) : '&mdash;'; } },
		{ key: '_used_in',   label: 'Used In'  },
	] );

	// ─────────────────────────────────────────────────────────────────────────
	// Render functions — thin wrappers that build cfg and call d5dshRenderSection
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render all scan panels from a stored report object.
	 */
	function renderScanResults( data ) {
		if ( ! data ) { return; }
		contentScanRenderMeta( data.meta );
		contentScanRenderActiveReport( data.active_content );
		contentScanRenderInventory( data.inventory );
		contentScanRenderDsoUsage( data.dso_usage );
		contentScanRenderNoDso( data.no_dso_content );
		contentScanRenderDsoMap( data );
		contentScanRenderDsoChain( data );
		// Hide pre-scan description, show results.
		var prescan = document.getElementById( 'd5dsh-scan-prescan' );
		if ( prescan ) { prescan.style.display = 'none'; }
		var results = document.getElementById( 'd5dsh-scan-results' );
		if ( results ) { results.style.display = 'block'; }
		var scanActions = document.getElementById( 'd5dsh-scan-actions' );
		if ( scanActions ) { scanActions.style.display = ''; }

		// Wire toggle events on each scan <details> so scroll-wraps recompute
		// their heights when a closed section is opened for the first time.
		if ( results ) {
			results.querySelectorAll( '.d5dsh-scan-section' ).forEach( function ( det ) {
				det.addEventListener( 'toggle', function () {
					if ( ! det.open ) { return; }
					// Resize all .d5dsh-section-scroll-wrap elements inside this section.
					det.querySelectorAll( '.d5dsh-section-scroll-wrap' ).forEach( function ( w ) {
						var r = w.getBoundingClientRect();
						var h = window.innerHeight - r.top - 24;
						if ( h < 120 ) { h = 120; }
						w.style.height    = h + 'px';
						w.style.overflowY = 'auto';
						w.style.overflowX = 'auto';
					} );
				} );
			} );
		}

		// Resize the already-open (first) section's wrap after layout settles.
		setTimeout( function () {
			var openSections = results ? results.querySelectorAll( '.d5dsh-scan-section[open]' ) : [];
			openSections.forEach( function ( det ) {
				det.querySelectorAll( '.d5dsh-section-scroll-wrap' ).forEach( function ( w ) {
					var r = w.getBoundingClientRect();
					var h = window.innerHeight - r.top - 24;
					if ( h < 120 ) { h = 120; }
					w.style.height    = h + 'px';
					w.style.overflowY = 'auto';
					w.style.overflowX = 'auto';
				} );
			} );
		}, 80 );
	}

	/**
	 * Reset all scan panels to initial state.
	 */
	function scanReset() {
		window._d5dshLastScanReport = null;
		// Clear per-section filter/sort state.
		[ 'd5dsh-scan-active-body', 'd5dsh-scan-inventory-body',
		  'd5dsh-scan-dso-body', 'd5dsh-scan-nodso-body',
		  'd5dsh-scan-dsomap-body', 'd5dsh-scan-dsochain-body' ].forEach( function ( id ) {
			_sectionFilters[ id ] = {};
			_sectionSort[ id ]    = { key: '', dir: 'asc' };
		} );
		var results     = document.getElementById( 'd5dsh-scan-results' );
		var meta        = document.getElementById( 'd5dsh-scan-meta' );
		var activeBody  = document.getElementById( 'd5dsh-scan-active-body' );
		var invBody     = document.getElementById( 'd5dsh-scan-inventory-body' );
		var dsoBody     = document.getElementById( 'd5dsh-scan-dso-body' );
		var noDsoBody   = document.getElementById( 'd5dsh-scan-nodso-body' );
		var dsoMapBody  = document.getElementById( 'd5dsh-scan-dsomap-body' );
		var dsoChainBody = document.getElementById( 'd5dsh-scan-dsochain-body' );
		var dsoMapBadge  = document.getElementById( 'd5dsh-scan-dsomap-badge' );
		var dsoChainBadge = document.getElementById( 'd5dsh-scan-dsochain-badge' );
		var errBox      = document.getElementById( 'd5dsh-scan-error' );
		var scanActions = document.getElementById( 'd5dsh-scan-actions' );
		var prescan     = document.getElementById( 'd5dsh-scan-prescan' );
		if ( meta )       { meta.innerHTML = ''; }
		if ( activeBody ) { activeBody.innerHTML = ''; }
		if ( invBody )    { invBody.innerHTML = ''; }
		if ( dsoBody )    { dsoBody.innerHTML = ''; }
		if ( noDsoBody )  { noDsoBody.innerHTML = ''; }
		if ( dsoMapBody )  { dsoMapBody.innerHTML = ''; }
		if ( dsoChainBody ) { dsoChainBody.innerHTML = ''; }
		if ( dsoMapBadge )  { dsoMapBadge.textContent = ''; }
		if ( dsoChainBadge ) { dsoChainBadge.textContent = ''; }
		if ( results )    { results.style.display = 'none'; }
		if ( errBox )     { errBox.textContent = ''; errBox.style.display = 'none'; }
		if ( scanActions ){ scanActions.style.display = 'none'; }
		if ( prescan )    { prescan.style.display = ''; }
	}

	/**
	 * Render the meta bar (scan date, totals, limit warning).
	 */
	function contentScanRenderMeta( meta ) {
		var el = document.getElementById( 'd5dsh-scan-meta' );
		if ( ! el || ! meta ) return;

		var html = 'Scan run: ' + escHtml( meta.ran_at || '' )
			+ ' &mdash; '
			+ escHtml( String( meta.total_scanned || 0 ) ) + ' items scanned'
			+ ' (' + escHtml( String( meta.active_count || 0 ) ) + ' with DSOs)';

		if ( meta.limit_reached ) {
			html += ' <strong class="d5dsh-scan-limit-warning">'
				+ '&#9888; Limit of ' + escHtml( String( meta.limit || 1000 ) )
				+ ' items reached — results are partial.'
				+ '</strong>';
		}
		el.innerHTML = html;
	}

	function contentScanRenderActiveReport( active ) {
		d5dshRenderSection( {
			bodyId:    'd5dsh-scan-active-body',
			badgeId:   'd5dsh-scan-active-badge',
			columns:   SCAN_ACTIVE_COLS,
			filterCols: [ 'post_type', 'post_status' ],
			groupBy:   'post_type',
			getRows:   function ( d ) {
				var rows = [];
				Object.keys( ( d && d.by_type ) || {} ).forEach( function ( t ) {
					( d.by_type[ t ] || [] ).forEach( function ( r ) { rows.push( r ); } );
				} );
				return rows;
			},
			getTotal:  function ( d ) { return ( d && d.total ) || 0; },
			emptyMsg:  'No content references any DSO.',
			data:      active,
		} );
	}

	function contentScanRenderInventory( inventory ) {
		d5dshRenderSection( {
			bodyId:    'd5dsh-scan-inventory-body',
			badgeId:   'd5dsh-scan-inventory-badge',
			columns:   SCAN_INVENTORY_COLS,
			filterCols: [ 'post_type', 'post_status' ],
			subRows:   function ( row ) { return row.canvases || []; },
			subRowClass: 'd5dsh-scan-canvas-row',
			getRows:   function ( d ) { return ( d && d.rows ) || []; },
			getTotal:  function ( d ) { return ( d && d.total ) || 0; },
			emptyMsg:  'No Divi content found.',
			data:      inventory,
		} );
	}

	function contentScanRenderDsoUsage( dsoUsage ) {
		var vars    = ( dsoUsage && dsoUsage.variables ) ? dsoUsage.variables : {};
		var presets = ( dsoUsage && dsoUsage.presets )   ? dsoUsage.presets   : {};
		var total   = Object.keys( vars ).length + Object.keys( presets ).length;

		// Badge updated manually since DSO usage is two sub-sections in one body.
		var badge = document.getElementById( 'd5dsh-scan-dso-badge' );
		if ( badge ) { badge.textContent = String( total ); }

		var body = document.getElementById( 'd5dsh-scan-dso-body' );
		if ( ! body ) { return; }

		if ( total === 0 ) {
			setElMsg( body, 'd5dsh-audit-clean', 'No DSO references found in content.' );
			return;
		}

		// Flatten a keyed usage map { id: { count, dso_type, label, posts:[{post_id, post_title},...] } } into rows.
		function mapToRows( map ) {
			return Object.keys( map ).map( function ( id ) {
				var entry  = map[ id ];
				// Build plain-text title list — do NOT pre-escape; _sectionBuildRow escapes it.
				var titles = ( entry.posts || [] ).map( function ( p ) {
					return p.post_title || String( p.post_id ) || '';
				} ).join( ', ' );
				return {
					dso_type: entry.dso_type || '',
					_dso_id:  id,
					label:    entry.label    || '',
					count:    entry.count    || 0,
					_titles:  titles,
				};
			} );
		}

		// Build all HTML up front so all inner divs exist when d5dshRenderSection runs.
		var varRows    = mapToRows( vars );
		var presetRows = mapToRows( presets );
		var html = '';
		if ( varRows.length ) {
			html += '<h4 class="d5dsh-scan-type-heading">Variables'
				+ ' <span class="d5dsh-audit-badge">' + varRows.length + '</span></h4>'
				+ '<div id="d5dsh-scan-dso-var-inner"></div>';
		}
		if ( presetRows.length ) {
			html += '<h4 class="d5dsh-scan-type-heading">Presets'
				+ ' <span class="d5dsh-audit-badge">' + presetRows.length + '</span></h4>'
				+ '<div id="d5dsh-scan-dso-preset-inner"></div>';
		}
		body.innerHTML = html;

		// Now render each sub-section into its placeholder div.
		if ( varRows.length ) {
			d5dshRenderSection( {
				bodyId:     'd5dsh-scan-dso-var-inner',
				columns:    SCAN_DSO_VAR_COLS,
				filterCols: [ '_dso_id' ],
				getRows:    function () { return varRows; },
				emptyMsg:   '',
				data:       varRows,
			} );
		}
		if ( presetRows.length ) {
			d5dshRenderSection( {
				bodyId:     'd5dsh-scan-dso-preset-inner',
				columns:    SCAN_DSO_PRESET_COLS,
				filterCols: [ '_dso_id' ],
				getRows:    function () { return presetRows; },
				emptyMsg:   '',
				data:       presetRows,
			} );
		}
	}

	function contentScanRenderNoDso( noDso ) {
		d5dshRenderSection( {
			bodyId:    'd5dsh-scan-nodso-body',
			badgeId:   'd5dsh-scan-nodso-badge',
			columns:   SCAN_NODSO_COLS,
			filterCols: [ 'post_type', 'post_status' ],
			groupBy:   'post_type',
			getRows:   function ( d ) {
				var rows = [];
				Object.keys( ( d && d.by_type ) || {} ).forEach( function ( t ) {
					( d.by_type[ t ] || [] ).forEach( function ( r ) { rows.push( r ); } );
				} );
				return rows;
			},
			getTotal:  function ( d ) { return ( d && d.total ) || 0; },
			emptyMsg:  'All scanned content uses at least one DSO reference.',
			data:      noDso,
		} );
	}

	// ── Section 1: Content → DSO Map ─────────────────────────────────────────────────────────────

	/**
	 * Build a leaf variable node for the DSO tree.
	 */
	function _buildTreeVarLeaf( ref, dsoUsage, repeatNote, varInfoMap ) {
		// Resolve label: prefer varInfoMap (covers all vars including preset-only ones),
		// fall back to dsoUsage index (direct-use vars only).
		var info  = ( varInfoMap && varInfoMap[ ref.name ] ) || null;
		var label = ( info && info.label )
			? info.label
			: ( dsoUsage && dsoUsage.variables && dsoUsage.variables[ ref.name ]
				? ( dsoUsage.variables[ ref.name ].label || '' )
				: '' );

		// Resolve display type: varInfoMap has human labels (Color/Number/etc.).
		// Divi's raw ref.type is "color" or "content" (not useful as-is).
		var typeDisplay = '';
		if ( info && info.var_type ) {
			typeDisplay = info.var_type;
		} else if ( ref.type === 'color' ) {
			typeDisplay = 'Color';
		} else if ( ref.type === 'content' ) {
			// "content" is Divi's generic type for non-color vars; infer from ID prefix.
			typeDisplay = ref.name && ref.name.indexOf( 'gcid-' ) === 0 ? 'Color' : 'Variable';
		} else if ( ref.type ) {
			typeDisplay = ref.type;
		}

		// Show: Label (id) [type]  — or just (id) if no label
		var primary = label
			? escHtml( label ) + ' <span class="d5dsh-tree-dso-id">&#8203;(' + escHtml( ref.name ) + ')</span>'
			: '<span class="d5dsh-tree-dso-id">' + escHtml( ref.name ) + '</span>';
		return '<div class="d5dsh-tree-leaf d5dsh-tree-var">'
			+ primary
			+ ( typeDisplay ? ' <span class="d5dsh-tree-dso-type">[' + escHtml( typeDisplay ) + ']</span>' : '' )
			+ ( repeatNote ? ' <span class="d5dsh-tree-repeat">' + escHtml( repeatNote ) + '</span>' : '' )
			+ '</div>';
	}

	/**
	 * Build the collapsible DSO tree HTML for one content row.
	 */
	function _buildContentTreeNode( row, presetVarMap, dsoUsage, varInfoMap, presetInfoMap ) {
		var directVars   = row.var_refs    || [];
		var presetIds    = row.preset_refs || [];
		var title        = row.post_title  || '(no title)';
		var postId       = row.post_id     || '';

		// Count total occurrences of each var name across the whole content item
		// so we can annotate repeats.
		var varCount = {};
		directVars.forEach( function ( r ) {
			varCount[ r.name ] = ( varCount[ r.name ] || 0 ) + 1;
		} );
		presetIds.forEach( function ( pid ) {
			( presetVarMap[ pid ] || [] ).forEach( function ( r ) {
				varCount[ r.name ] = ( varCount[ r.name ] || 0 ) + 1;
			} );
		} );

		var totalDsos = directVars.length + presetIds.length;

		// Direct variables branch
		var directHtml = '';
		if ( directVars.length ) {
			var leaves = directVars.map( function ( r ) {
				var note = varCount[ r.name ] > 1 ? '\xd7' + varCount[ r.name ] : '';
				return _buildTreeVarLeaf( r, dsoUsage, note, varInfoMap );
			} ).join( '' );
			directHtml = '<details class="d5dsh-tree-item" open>'
				+ '<summary class="d5dsh-tree-label d5dsh-tree-group-label">'
				+ 'Direct Variables <span class="d5dsh-audit-badge">' + directVars.length + '</span>'
				+ '</summary>'
				+ '<div class="d5dsh-tree-children">' + leaves + '</div>'
				+ '</details>';
		}

		// Presets branch
		var presetsHtml = '';
		if ( presetIds.length ) {
			var presetNodes = presetIds.map( function ( pid ) {
				var pVars   = presetVarMap[ pid ] || [];
				var pi      = presetInfoMap && presetInfoMap[ pid ];
				var pLabel  = ( pi && pi.label ) ? pi.label
					: ( dsoUsage && dsoUsage.presets && dsoUsage.presets[ pid ]
						? ( dsoUsage.presets[ pid ].label || '' ) : '' );
				var pType   = pi ? pi.preset_type : '';
				var pHeader = ( pLabel ? escHtml( pLabel ) + ' ' : '<em>(no name)</em> ' )
					+ '<span class="d5dsh-tree-dso-id">(' + escHtml( pid ) + ')</span>'
					+ ( pType ? ' <span class="d5dsh-tree-dso-type">[' + escHtml( pType ) + ']</span>' : '' )
					+ ' <span class="d5dsh-audit-badge">' + pVars.length + ' var' + ( pVars.length !== 1 ? 's' : '' ) + '</span>';
				var pChildren;
				if ( pVars.length === 0 ) {
					pChildren = '<div class="d5dsh-tree-leaf d5dsh-tree-empty">(no variable references in this preset)</div>';
				} else {
					pChildren = pVars.map( function ( r ) {
						var note = varCount[ r.name ] > 1 ? '\xd7' + varCount[ r.name ] : '';
						return _buildTreeVarLeaf( r, dsoUsage, note, varInfoMap );
					} ).join( '' );
				}
				return '<details class="d5dsh-tree-item">'
					+ '<summary class="d5dsh-tree-label d5dsh-tree-preset-label">' + pHeader + '</summary>'
					+ '<div class="d5dsh-tree-children">' + pChildren + '</div>'
					+ '</details>';
			} ).join( '' );
			presetsHtml = '<details class="d5dsh-tree-item" open>'
				+ '<summary class="d5dsh-tree-label d5dsh-tree-group-label">'
				+ 'Presets <span class="d5dsh-audit-badge">' + presetIds.length + '</span>'
				+ '</summary>'
				+ '<div class="d5dsh-tree-children">' + presetNodes + '</div>'
				+ '</details>';
		}

		return '<details class="d5dsh-tree-item d5dsh-tree-content">'
			+ '<summary class="d5dsh-tree-label d5dsh-tree-content-label">'
			+ escHtml( title )
			+ ' <span class="d5dsh-tree-id">#' + escHtml( String( postId ) ) + '</span>'
			+ ' <span class="d5dsh-audit-badge">' + totalDsos + ' DSO' + ( totalDsos !== 1 ? 's' : '' ) + '</span>'
			+ '</summary>'
			+ '<div class="d5dsh-tree-children">' + directHtml + presetsHtml + '</div>'
			+ '</details>';
	}

	/**
	 * Render the Content → DSO Map section.
	 *
	 * Flat table: one row per content × DSO reference (direct var or preset).
	 * Tree: collapsible per-content node with Direct Variables and Presets branches.
	 */
	function contentScanRenderDsoMap( data ) {
		var body = document.getElementById( 'd5dsh-scan-dsomap-body' );
		if ( ! body ) { return; }

		var active         = data && data.active_content;
		var dsoUsage       = data && data.dso_usage;
		var presetVarMap   = ( data && data.preset_var_map   ) || {};
		var varInfoMap     = ( data && data.var_info_map     ) || {};
		var presetInfoMap  = ( data && data.preset_info_map  ) || {};

		// Collect content rows.
		var contentRows = [];
		if ( active && active.by_type ) {
			Object.keys( active.by_type ).forEach( function ( t ) {
				( active.by_type[ t ] || [] ).forEach( function ( r ) { contentRows.push( r ); } );
			} );
		}

		if ( contentRows.length === 0 ) {
			setElMsg( body, 'd5dsh-audit-clean', 'No content with DSO references found.' );
			return;
		}

		// Update badge with unique content item count.
		var badge = document.getElementById( 'd5dsh-scan-dsomap-badge' );
		if ( badge ) { badge.textContent = String( contentRows.length ); }

		// ── Build flat table rows ────────────────────────────────────────────
		var flatRows = [];
		contentRows.forEach( function ( row ) {
			// Direct variable refs.
			( row.var_refs || [] ).forEach( function ( ref ) {
				var varEntry = dsoUsage && dsoUsage.variables && dsoUsage.variables[ ref.name ];
				flatRows.push( {
					_title:      row.post_title || '',
					post_type:   row.post_type  || '',
					post_status: row.post_status || '',
					dso_type:    'Variable',
					_dso_id:     ref.name,
					dso_label:   varEntry ? ( varEntry.label || '' ) : '',
					via:         'direct',
				} );
			} );
			// Preset refs.
			( row.preset_refs || [] ).forEach( function ( pid ) {
				var pEntry = dsoUsage && dsoUsage.presets && dsoUsage.presets[ pid ];
				var pLabel = pEntry ? ( pEntry.label || '' ) : '';
				flatRows.push( {
					_title:      row.post_title || '',
					post_type:   row.post_type  || '',
					post_status: row.post_status || '',
					dso_type:    'Preset',
					_dso_id:     pid,
					dso_label:   pLabel,
					via:         'direct',
				} );
				// Variables inside this preset.
				( presetVarMap[ pid ] || [] ).forEach( function ( ref ) {
					var varEntry = dsoUsage && dsoUsage.variables && dsoUsage.variables[ ref.name ];
					flatRows.push( {
						_title:      row.post_title || '',
						post_type:   row.post_type  || '',
						post_status: row.post_status || '',
						dso_type:    'Variable',
						_dso_id:     ref.name,
						dso_label:   varEntry ? ( varEntry.label || '' ) : '',
						via:         'via preset: ' + ( pLabel || pid ),
					} );
				} );
			} );
		} );

		// ── Build tree HTML ──────────────────────────────────────────────────
		var treeHtml = contentRows.map( function ( row ) {
			return _buildContentTreeNode( row, presetVarMap, dsoUsage, varInfoMap, presetInfoMap );
		} ).join( '' );

		// ── Render into body ─────────────────────────────────────────────────
		body.innerHTML =
			'<p class="d5dsh-scan-section-desc">'
			+ 'Maps each active content item to the DSOs it references. '
			+ '<strong>Flat Reference Table</strong> lists every Content \u2192 DSO pairing in a sortable table. '
			+ '<strong>DSO Tree</strong> shows the same data as a collapsible tree: expand a content item to see its direct variables and presets, then expand a preset to see which variables it embeds.'
			+ '</p>'
			+ '<details class="d5dsh-tree-subsection" open>'
			+ '<summary class="d5dsh-tree-subsection-title">Flat Reference Table'
			+ ' <span class="d5dsh-audit-badge">' + flatRows.length + ' ref' + ( flatRows.length !== 1 ? 's' : '' ) + '</span>'
			+ '</summary>'
			+ '<div id="d5dsh-scan-dsomap-flat-inner"></div>'
			+ '</details>'
			+ '<details class="d5dsh-tree-subsection">'
			+ '<summary class="d5dsh-tree-subsection-title">DSO Tree'
			+ ' <span class="d5dsh-audit-badge">' + contentRows.length + ' item' + ( contentRows.length !== 1 ? 's' : '' ) + '</span>'
			+ '</summary>'
			+ '<div class="d5dsh-tree-root">' + treeHtml + '</div>'
			+ '</details>';

		d5dshRenderSection( {
			bodyId:    'd5dsh-scan-dsomap-flat-inner',
			columns:   SCAN_DSOMAP_COLS,
			filterCols: [ 'post_type', 'dso_type' ],
			getRows:   function () { return flatRows; },
			getTotal:  function () { return flatRows.length; },
			emptyMsg:  'No DSO references found.',
			data:      flatRows,
		} );
	}

	// ── Section 2: DSO → Usage Chain ─────────────────────────────────────────────────────────

	/**
	 * Render the DSO → Usage Chain section.
	 *
	 * Sub-A: Variable → Usage Chain — for each variable: how each content item
	 *         uses it (directly or via which preset).
	 * Sub-B: Preset → Variables — for each used preset: what variables it contains.
	 * Sub-C: Variable → Presets — for each variable: which preset definitions embed it.
	 */
	function contentScanRenderDsoChain( data ) {
		var body = document.getElementById( 'd5dsh-scan-dsochain-body' );
		if ( ! body ) { return; }

		var dsoUsage     = ( data && data.dso_usage )     || {};
		var presetVarMap = ( data && data.preset_var_map ) || {};
		var active       = ( data && data.active_content ) || {};
		var varInfoMap    = ( data && data.var_info_map    ) || {};
		var presetInfoMap = ( data && data.preset_info_map ) || {};

		var variables = dsoUsage.variables || {};
		var presets   = dsoUsage.presets   || {};

		var varCount    = Object.keys( variables ).length;
		var presetCount = Object.keys( presets ).length;
		var totalDsos   = varCount + presetCount;

		if ( totalDsos === 0 ) {
			setElMsg( body, 'd5dsh-audit-clean', 'No DSO references found in content.' );
			return;
		}

		var badge = document.getElementById( 'd5dsh-scan-dsochain-badge' );
		if ( badge ) { badge.textContent = String( totalDsos ); }

		// Build a map: varName → list of preset IDs that contain it.
		var varToPresets = {};
		Object.keys( presetVarMap ).forEach( function ( pid ) {
			( presetVarMap[ pid ] || [] ).forEach( function ( ref ) {
				if ( ! varToPresets[ ref.name ] ) { varToPresets[ ref.name ] = []; }
				varToPresets[ ref.name ].push( pid );
			} );
		} );

		// Build a map: postId → row (for looking up var_refs / preset_refs per content item).
		var contentByPostId = {};
		if ( active.by_type ) {
			Object.keys( active.by_type ).forEach( function ( t ) {
				( active.by_type[ t ] || [] ).forEach( function ( r ) {
					contentByPostId[ String( r.post_id ) ] = r;
				} );
			} );
		}

		// ── Sub-A: Variable → Usage Chain ──────────────────────────────────
		var subAHtml = '';
		if ( varCount ) {
			var varRows = Object.keys( variables ).map( function ( vid ) {
				var entry = variables[ vid ];
				// For each post that uses this var, determine if it's direct or via preset.
				var usageRows = ( entry.posts || [] ).map( function ( p ) {
					var row    = contentByPostId[ String( p.post_id ) ];
					var isDirect = row && ( row.var_refs || [] ).some( function ( r ) { return r.name === vid; } );
					var viaParts = [];
					if ( isDirect ) { viaParts.push( 'direct' ); }
					// Check each preset this content uses that contains this var.
					if ( row ) {
						( row.preset_refs || [] ).forEach( function ( pid ) {
							var hasVar = ( presetVarMap[ pid ] || [] ).some( function ( r ) { return r.name === vid; } );
							if ( hasVar ) {
								var pl = presets[ pid ] ? ( presets[ pid ].label || pid ) : pid;
								viaParts.push( 'via \u201c' + pl + '\u201d' );
							}
						} );
					}
					return '<tr>'
						+ '<td>' + escHtml( viaParts.join( ', ' ) || 'indirect' ) + '</td>'
						+ '<td>' + escHtml( p.post_title || String( p.post_id ) ) + '</td>'
						+ '<td>' + escHtml( p.post_type   || '' ) + '</td>'
						+ '<td>' + _fmtStatus( p.post_status || '' ) + '</td>'
						+ '</tr>';
				} ).join( '' );

				var primary = entry.label
					? escHtml( entry.label ) + ' <span class="d5dsh-tree-dso-id">(&#8203;' + escHtml( vid ) + ')</span>'
					: '<span class="d5dsh-tree-dso-id">' + escHtml( vid ) + '</span>';
				return '<details class="d5dsh-tree-item d5dsh-chain-var-block">'
					+ '<summary class="d5dsh-tree-label d5dsh-chain-var-label">'
					+ primary
					+ ' <span class="d5dsh-audit-badge">' + ( entry.posts || [] ).length + ' use' + ( ( entry.posts || [] ).length !== 1 ? 's' : '' ) + '</span>'
					+ '</summary>'
					+ '<table class="d5dsh-chain-table widefat striped">'
					+ '<thead><tr><th>Used Via</th><th>Content Title</th><th>Type</th><th>Status</th></tr></thead>'
					+ '<tbody>' + usageRows + '</tbody>'
					+ '</table>'
					+ '</details>';
			} );
			subAHtml = '<details class="d5dsh-tree-subsection" open>'
				+ '<summary class="d5dsh-tree-subsection-title">Variable \u2192 Usage Chain'
				+ ' <span class="d5dsh-audit-badge">' + varCount + '</span></summary>'
				+ '<div class="d5dsh-chain-section">' + varRows.join( '' ) + '</div>'
				+ '</details>';
		}

		// ── Sub-B: Preset → Variables ──────────────────────────────────────
		var subBHtml = '';
		if ( presetCount ) {
			var presetBlocks = Object.keys( presets ).map( function ( pid ) {
				var entry  = presets[ pid ];
				var pi     = presetInfoMap[ pid ];
				var pVars  = presetVarMap[ pid ] || [];
				var pLabel = ( pi && pi.label ) ? pi.label : ( entry.label || '' );
				var pType  = pi ? pi.preset_type : '';
				var pPrimary = ( pLabel
					? escHtml( pLabel ) + ' <span class="d5dsh-tree-dso-id">(&#8203;' + escHtml( pid ) + ')</span>'
					: '<em>(no name)</em> <span class="d5dsh-tree-dso-id">' + escHtml( pid ) + '</span>' )
					+ ( pType ? ' <span class="d5dsh-tree-dso-type">[' + escHtml( pType ) + ']</span>' : '' );
				var rows;
				if ( pVars.length === 0 ) {
					rows = '<tr><td colspan="4"><em>No variable references in this preset.</em></td></tr>';
				} else {
					rows = pVars.map( function ( ref ) {
						var ve     = variables[ ref.name ];
						var vi     = varInfoMap[ ref.name ];
						// Label: prefer varInfoMap (all vars), then usage index, then ID as last resort.
						var vlabel = ( vi && vi.label ) ? vi.label : ( ve ? ( ve.label || ref.name ) : ref.name );
						// Count: from direct-usage index; show '—' when var only lives in preset defs.
						var vcount = ve ? ( ve.count || 0 ) : '\u2014';
						// Type: use human label from varInfoMap; map Divi raw token type as fallback.
						var vtype  = ( vi && vi.var_type ) ? vi.var_type
							: ( ref.type === 'color' ? 'Color'
							: ( ref.type === 'content' ? ( ref.name && ref.name.indexOf( 'gcid-' ) === 0 ? 'Color' : 'Variable' ) : ( ref.type || '' ) ) );
						return '<tr>'
							+ '<td>' + escHtml( ref.name ) + '</td>'
							+ '<td>' + escHtml( vlabel ) + '</td>'
							+ '<td>' + escHtml( vtype ) + '</td>'
							+ '<td>' + ( typeof vcount === 'number' ? String( vcount ) : escHtml( vcount ) ) + '</td>'
							+ '</tr>';
					} ).join( '' );
				}
				return '<details class="d5dsh-tree-item d5dsh-chain-preset-block">'
					+ '<summary class="d5dsh-tree-label d5dsh-chain-preset-label">'
					+ pPrimary
					+ ' <span class="d5dsh-audit-badge">' + pVars.length + ' var' + ( pVars.length !== 1 ? 's' : '' ) + '</span>'
					+ '</summary>'
					+ '<table class="d5dsh-chain-table widefat striped">'
					+ '<thead><tr><th>Variable</th><th>Label</th><th>Var Type</th><th>Direct uses</th></tr></thead>'
					+ '<tbody>' + rows + '</tbody>'
					+ '</table>'
					+ '</details>';
			} ).join( '' );
			subBHtml = '<details class="d5dsh-tree-subsection">'
				+ '<summary class="d5dsh-tree-subsection-title">Preset \u2192 Variables'
				+ ' <span class="d5dsh-audit-badge">' + presetCount + '</span></summary>'
				+ '<div class="d5dsh-chain-section">' + presetBlocks + '</div>'
				+ '</details>';
		}

		// ── Sub-C: Variable → Presets that contain it ────────────────────
		var subCHtml = '';
		var varInAnyPreset = Object.keys( varToPresets ).filter( function ( v ) { return varToPresets[ v ].length > 0; } );
		if ( varInAnyPreset.length ) {
			var varPresetRows = varInAnyPreset.map( function ( vid ) {
				var ve       = variables[ vid ];
				var vi       = varInfoMap[ vid ];
				var vlabel   = ( vi && vi.label ) ? vi.label : ( ve ? ( ve.label || '' ) : '' );
				var pids     = varToPresets[ vid ];
				var pidTexts = pids.map( function ( pid ) {
					var pi2  = presetInfoMap && presetInfoMap[ pid ];
					var pl   = ( pi2 && pi2.label ) ? pi2.label : ( presets[ pid ] ? ( presets[ pid ].label || '' ) : '' );
					var pt   = pi2 ? pi2.preset_type : '';
					return ( pl ? escHtml( pl ) : '<em>(no name)</em>' )
						+ ' <span class="d5dsh-tree-dso-id">(&#8203;' + escHtml( pid ) + ')</span>'
						+ ( pt ? ' <span class="d5dsh-tree-dso-type">[' + escHtml( pt ) + ']</span>' : '' );
				} ).join( '<br>' );
				var vcPrimary = vlabel
					? escHtml( vlabel ) + '<br><span class="d5dsh-tree-dso-id">(' + escHtml( vid ) + ')</span>'
					: '<span class="d5dsh-tree-dso-id">' + escHtml( vid ) + '</span>';
				return '<tr>'
					+ '<td>' + vcPrimary + '</td>'
					+ '<td>' + pids.length + '</td>'
					+ '<td>' + pidTexts + '</td>'
					+ '</tr>';
			} ).join( '' );
			subCHtml = '<details class="d5dsh-tree-subsection">'
				+ '<summary class="d5dsh-tree-subsection-title">Variable \u2192 Presets Containing It'
				+ ' <span class="d5dsh-audit-badge">' + varInAnyPreset.length + '</span></summary>'
				+ '<table class="d5dsh-chain-table widefat striped">'
				+ '<thead><tr><th>Variable</th><th>In N Presets</th><th>Presets</th></tr></thead>'
				+ '<tbody>' + varPresetRows + '</tbody>'
				+ '</table>'
				+ '</details>';
		}

		var chainDesc = '<p class="d5dsh-scan-section-desc">'
			+ 'Answers the question <em>"Where is this DSO used?"</em> from three angles. '
			+ '<strong>Variable \u2192 Usage Chain</strong> shows each variable, which content items use it, and whether they reach it directly or through a preset. '
			+ '<strong>Preset \u2192 Variables</strong> shows each preset used in content and the variables embedded in its definition. '
			+ '<strong>Variable \u2192 Presets Containing It</strong> cross-references every variable back to the preset definitions that embed it.'
			+ '</p>';
		body.innerHTML = chainDesc + subAHtml + subBHtml + subCHtml;
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 20 — SCAN XLSX DOWNLOAD                                      ║
	// ╚══════════════════════════════════════════════════════════════════════╝

	function scanDownloadXlsx() {
		var report = window._d5dshLastScanReport;
		if ( ! report ) { return; }
		var btn = document.getElementById( 'd5dsh-scan-xlsx-btn' );
		if ( btn ) { btn.disabled = true; btn.textContent = 'Generating…'; }

		fetch( d5dtAudit.ajaxUrl + '?action=' + d5dtAudit.scanXlsxAction + '&nonce=' + encodeURIComponent( d5dtAudit.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( report ),
		} )
			.then( function ( r ) { return r.blob(); } )
			.then( function ( blob ) {
				var url  = URL.createObjectURL( blob );
				var a    = document.createElement( 'a' );
				a.href     = url;
				a.download = 'd5dsh-scan-report.xlsx';
				document.body.appendChild( a );
				a.click();
				setTimeout( function () { URL.revokeObjectURL( url ); a.remove(); }, 1000 );
			} )
			.catch( function ( err ) {
				alert( 'Scan Excel download failed: ' + ( err.message || 'Network error' ) );
			} )
			.finally( function () {
				if ( btn ) { btn.disabled = false; btn.innerHTML = '&#8595; Excel'; }
			} );
	}

	/**
	 * Print the visible rows from a single scan section.
	 * section: 'active' | 'inventory' | 'dso' | 'nodso' | 'dsomap' | 'dsochain'
	 */
	function scanPrintSection( section ) {
		var bodyId = {
			active:    'd5dsh-scan-active-body',
			inventory: 'd5dsh-scan-inventory-body',
			dso:       'd5dsh-scan-dso-body',
			nodso:     'd5dsh-scan-nodso-body',
			dsomap:    'd5dsh-scan-dsomap-body',
			dsochain:  'd5dsh-scan-dsochain-body',
		}[ section ];
		var labelMap = {
			active:    'Active Content',
			inventory: 'Content Inventory',
			dso:       'DSO Usage Index',
			nodso:     'No-DSO Content',
			dsomap:    'Content \u2192 DSO Map',
			dsochain:  'DSO \u2192 Usage Chain',
		};
		var bodyEl = bodyId ? document.getElementById( bodyId ) : null;
		if ( ! bodyEl ) { return; }
		// Clone the section's inner HTML (tables already rendered there).
		var body = '<h2>' + escHtml( labelMap[ section ] || section ) + '</h2>' + bodyEl.innerHTML;
		openPrintWindow( body, labelMap[ section ] || section, 'landscape', null );
	}

	/**
	 * CSV download for a single scan section — extracts rows from the rendered table.
	 * section: 'active' | 'inventory' | 'dso' | 'nodso' | 'dsomap' | 'dsochain'
	 */
	function scanDownloadCsvSection( section ) {
		var bodyId = {
			active:    'd5dsh-scan-active-body',
			inventory: 'd5dsh-scan-inventory-body',
			dso:       'd5dsh-scan-dso-body',
			nodso:     'd5dsh-scan-nodso-body',
			dsomap:    'd5dsh-scan-dsomap-body',
			dsochain:  'd5dsh-scan-dsochain-body',
		}[ section ];
		var bodyEl = bodyId ? document.getElementById( bodyId ) : null;
		if ( ! bodyEl ) { return; }

		var lines = [];
		// Extract from all tables inside the body element.
		bodyEl.querySelectorAll( 'table' ).forEach( function ( tbl ) {
			// Headers.
			var headers = [];
			tbl.querySelectorAll( 'thead th' ).forEach( function ( th ) {
				headers.push( th.textContent.trim() );
			} );
			if ( headers.length && ! lines.length ) {
				lines.push( headers.map( function ( h ) {
					return '"' + h.replace( /"/g, '""' ) + '"';
				} ).join( ',' ) );
			}
			// Rows.
			tbl.querySelectorAll( 'tbody tr' ).forEach( function ( tr ) {
				var cells = [];
				tr.querySelectorAll( 'td' ).forEach( function ( td ) {
					cells.push( '"' + td.textContent.trim().replace( /"/g, '""' ) + '"' );
				} );
				if ( cells.length ) { lines.push( cells.join( ',' ) ); }
			} );
		} );

		if ( ! lines.length ) { return; }
		var blob = new Blob( [ lines.join( '\n' ) ], { type: 'text/csv' } );
		var a    = document.createElement( 'a' );
		a.href     = URL.createObjectURL( blob );
		a.download = 'd5dsh-scan-' + section + '.csv';
		a.click();
		URL.revokeObjectURL( a.href );
	}

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 21 — NOTES SYSTEM                                            ║
	// ╚══════════════════════════════════════════════════════════════════════╝

	/**
	 * All notes loaded from the server once on page load.
	 * Shape: { "var:gcid-xxx": { note, tags, suppress }, ... }
	 */

	// ══════════════════════════════════════════════════════════════════════════
	// ── IMPACT MODAL (Features 3 & 4: What Breaks? + Dependencies)
	// ══════════════════════════════════════════════════════════════════════════

	/**
	 * Open the Impact Analysis modal for a DSO.
	 *
	 * @param {string} dsoType  'variable' or 'preset'
	 * @param {string} dsoId    The variable or preset ID
	 * @param {string} label    Human-readable label for the title bar
	 */
	function openImpactModal( dsoType, dsoId, label ) {
		var modal = document.getElementById( 'd5dsh-impact-modal' );
		if ( ! modal ) { return; }

		// Set title.
		var titleEl = document.getElementById( 'd5dsh-impact-modal-title' );
		if ( titleEl ) { titleEl.textContent = 'Impact: ' + ( label || dsoId ); }

		// Reset warning.
		var warnEl = document.getElementById( 'd5dsh-impact-delete-warning' );
		if ( warnEl ) { warnEl.textContent = ''; warnEl.style.display = 'none'; }

		// Reset tabs to "What Breaks?" active.
		modal.querySelectorAll( '.d5dsh-modal-tab' ).forEach( function ( t ) {
			t.classList.toggle( 'd5dsh-modal-tab-active', t.dataset.tab === 'impact' );
		} );
		var impactPane = document.getElementById( 'd5dsh-impact-pane' );
		var depsPane   = document.getElementById( 'd5dsh-deps-pane' );
		if ( impactPane ) { impactPane.style.display = ''; }
		if ( depsPane )   { depsPane.style.display   = 'none'; }

		// Show spinner while loading.
		if ( impactPane ) { setElMsg( impactPane, 'd5dsh-impact-loading', 'Analyzing\u2026' ); }
		if ( depsPane )   { depsPane.innerHTML   = ''; }

		openModal( 'd5dsh-impact-modal' );

		// Fetch analysis data.
		if ( typeof d5dtAudit === 'undefined' ) { return; }
		fetch( d5dtAudit.ajaxUrl + '?action=d5dsh_impact_analyze&nonce=' + encodeURIComponent( d5dtAudit.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { dso_type: dsoType, dso_id: dsoId } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( ! json.success ) {
				if ( impactPane ) { setElMsg( impactPane, 'd5dsh-impact-error', json.data && json.data.message ? json.data.message : 'Analysis failed.' ); }
				return;
			}
			renderImpactPane( json.data );
			renderDepsPane( json.data );
		} )
		.catch( function () {
			if ( impactPane ) { setElMsg( impactPane, 'd5dsh-impact-error', 'Request failed.' ); }
		} );
	}

	/**
	 * Render the "What Breaks?" tab pane.
	 * @param {Object} data  Response from d5dsh_impact_analyze
	 */
	function renderImpactPane( data ) {
		var pane = document.getElementById( 'd5dsh-impact-pane' );
		if ( ! pane ) { return; }

		var directContent = data.direct_content  || [];
		var viaPresets    = data.via_presets      || [];
		var containingPr  = data.containing_presets || [];

		// Count unique published affected posts.
		var affectedPosts = {};
		directContent.forEach( function ( p ) { affectedPosts[ p.post_id ] = p; } );
		viaPresets.forEach( function ( pdata ) {
			( pdata.content || [] ).forEach( function ( p ) { affectedPosts[ p.post_id ] = p; } );
		} );
		var affectedList     = Object.values( affectedPosts );
		var publishedCount   = affectedList.filter( function ( p ) { return p.post_status === 'publish'; } ).length;
		var totalCount       = affectedList.length;

		// Severity.
		var isHigh = publishedCount > 0;
		var warnEl = document.getElementById( 'd5dsh-impact-delete-warning' );
		if ( warnEl ) {
			if ( isHigh ) {
				warnEl.textContent = '\u26a0\ufe0f Deleting this would break ' + publishedCount + ' published content item' + ( publishedCount !== 1 ? 's' : '' ) + '.';
				warnEl.style.display = '';
			} else {
				warnEl.textContent = '';
				warnEl.style.display = 'none';
			}
		}

		var html = '';

		// Summary line.
		html += '<p class="d5dsh-impact-summary">';
		if ( totalCount === 0 ) {
			html += '<span class="d5dsh-impact-severity-low">No content references this DSO.</span>';
		} else {
			html += 'Deleting this would affect <strong>' + totalCount + '</strong> content item' + ( totalCount !== 1 ? 's' : '' );
			if ( publishedCount > 0 ) {
				html += ' including <span class="d5dsh-impact-severity-high">' + publishedCount + ' published</span>';
			}
			html += '.';
		}
		html += '</p>';

		// Direct content table.
		if ( directContent.length > 0 ) {
			html += '<h4 class="d5dsh-impact-section-title">Direct references (' + directContent.length + ')</h4>';
			html += _impactContentTable( directContent );
		}

		// Via-preset sections.
		if ( viaPresets.length > 0 ) {
			html += '<h4 class="d5dsh-impact-section-title">Via presets</h4>';
			viaPresets.forEach( function ( pdata ) {
				html += '<details class="d5dsh-impact-preset-block" open>';
				html += '<summary class="d5dsh-impact-preset-label">';
				html += escHtml( pdata.preset_label || pdata.preset_id );
				html += ' <span class="d5dsh-audit-badge">' + ( pdata.content || [] ).length + ' item' + ( ( pdata.content || [] ).length !== 1 ? 's' : '' ) + '</span>';
				html += '</summary>';
				html += _impactContentTable( pdata.content || [] );
				html += '</details>';
			} );
		}

		// For preset DSOs: show variables inside the preset (stored in containing_presets field).
		if ( data.dso_type === 'preset' && containingPr.length > 0 ) {
			html += '<h4 class="d5dsh-impact-section-title">Variables inside this preset (' + containingPr.length + ')</h4>';
			html += '<table class="d5dsh-impact-table"><thead><tr><th>Variable ID</th><th>Label</th><th>Type</th></tr></thead><tbody>';
			containingPr.forEach( function ( v ) {
				html += '<tr><td><code>' + escHtml( v.var_id || '' ) + '</code></td><td>' + escHtml( v.var_label || '' ) + '</td><td>' + escHtml( v.var_type || '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		// For variable DSOs: show presets that contain it.
		if ( data.dso_type === 'variable' && containingPr.length > 0 ) {
			html += '<h4 class="d5dsh-impact-section-title">Preset definitions containing this variable (' + containingPr.length + ')</h4>';
			html += '<table class="d5dsh-impact-table"><thead><tr><th>Preset ID</th><th>Label</th><th>Module</th></tr></thead><tbody>';
			containingPr.forEach( function ( p ) {
				html += '<tr><td><code>' + escHtml( p.preset_id || '' ) + '</code></td><td>' + escHtml( p.preset_label || '' ) + '</td><td>' + escHtml( p.module_name || '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		pane.innerHTML = html;
	}

	/**
	 * Build a compact content table from an array of post refs.
	 */
	function _impactContentTable( posts ) {
		if ( ! posts || posts.length === 0 ) { return '<p class="d5dsh-impact-empty">No content items.</p>'; }
		var html = '<table class="d5dsh-impact-table"><thead><tr><th>Title</th><th>Type</th><th>Status</th></tr></thead><tbody>';
		posts.forEach( function ( p ) {
			var statusCls = p.post_status === 'publish' ? ' d5dsh-impact-severity-high' : '';
			html += '<tr>';
			html += '<td>' + escHtml( p.post_title || '(untitled)' ) + '</td>';
			html += '<td>' + escHtml( p.post_type || '' ) + '</td>';
			html += '<td><span class="d5dsh-status-badge' + statusCls + '">' + escHtml( p.post_status || '' ) + '</span></td>';
			html += '</tr>';
		} );
		html += '</tbody></table>';
		return html;
	}

	/**
	 * Render the "Dependencies" tab pane from the dep_tree data.
	 * @param {Object} data  Response from d5dsh_impact_analyze
	 */
	function renderDepsPane( data ) {
		var pane = document.getElementById( 'd5dsh-deps-pane' );
		if ( ! pane ) { return; }
		var tree = data.dep_tree;
		if ( ! tree ) { setElMsg( pane, 'd5dsh-impact-empty', 'No dependency data.' ); return; }
		pane.innerHTML = '<div class="d5dsh-tree-root">' + _buildImpactTreeNode( tree, true ) + '</div>';
	}

	/**
	 * Recursively build a tree node for the Dependencies tab.
	 * @param {Object}  node   { id, label, type, children, status?, post_type?, var_type? }
	 * @param {boolean} open   Whether the <details> is open by default
	 */
	function _buildImpactTreeNode( node, open ) {
		var children  = node.children || [];
		var typeLabel = _impactNodeTypeLabel( node );
		var badge     = children.length > 0 ? ' <span class="d5dsh-audit-badge">' + children.length + '</span>' : '';

		if ( children.length === 0 ) {
			// Leaf node.
			var statusBadge = node.status && node.status === 'publish' ? ' <span class="d5dsh-impact-severity-high">published</span>' : '';
			return '<div class="d5dsh-tree-leaf">'
				+ '<span class="d5dsh-tree-var-bullet">•</span>'
				+ '<span class="d5dsh-tree-dso-label">' + escHtml( node.label || node.id ) + '</span>'
				+ typeLabel
				+ statusBadge
				+ '</div>';
		}

		var html = '<details class="d5dsh-tree-item' + _impactNodeClass( node ) + '"' + ( open ? ' open' : '' ) + '>';
		html += '<summary class="d5dsh-tree-label">' + escHtml( node.label || node.id ) + typeLabel + badge + '</summary>';
		html += '<div class="d5dsh-tree-children">';
		children.forEach( function ( child ) {
			html += _buildImpactTreeNode( child, false );
		} );
		html += '</div></details>';
		return html;
	}

	function _impactNodeClass( node ) {
		if ( node.type === 'content' )  { return ' d5dsh-tree-content'; }
		if ( node.type === 'variable' ) { return ''; }
		if ( node.type === 'preset' )   { return ''; }
		if ( node.type === 'group' )    { return ''; }
		return '';
	}

	function _impactNodeTypeLabel( node ) {
		var label = '';
		if ( node.type === 'variable' ) { label = node.var_type ? '[' + node.var_type + ']' : '[var]'; }
		if ( node.type === 'preset' )   { label = '[preset]'; }
		if ( node.type === 'content' )  { label = node.post_type ? '[' + node.post_type + ']' : '[content]'; }
		if ( ! label ) { return ''; }
		return ' <span class="d5dsh-tree-dso-type">' + escHtml( label ) + '</span>';
	}

	/**
	 * Wire the impact modal tab buttons (called from initModals via initImpactModal).
	 */
	function initImpactModal() {
		var modal = document.getElementById( 'd5dsh-impact-modal' );
		if ( ! modal ) { return; }
		modal.querySelectorAll( '.d5dsh-modal-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				modal.querySelectorAll( '.d5dsh-modal-tab' ).forEach( function ( t ) {
					t.classList.toggle( 'd5dsh-modal-tab-active', t === tab );
				} );
				modal.querySelectorAll( '.d5dsh-modal-pane' ).forEach( function ( pane ) {
					pane.style.display = ( pane.dataset.pane === tab.dataset.tab ) ? '' : 'none';
				} );
			} );
		} );

		// Expand / Collapse / Print toolbar buttons.
		var expandBtn   = document.getElementById( 'd5dsh-impact-expand-all' );
		var collapseBtn = document.getElementById( 'd5dsh-impact-collapse-all' );
		var printBtn    = document.getElementById( 'd5dsh-impact-print' );

		var helpToggle = document.getElementById( 'd5dsh-impact-help-toggle' );
		var helpPanel  = document.getElementById( 'd5dsh-impact-help-panel' );
		if ( helpToggle && helpPanel ) {
			helpToggle.addEventListener( 'click', function () {
				var open = helpPanel.style.display !== 'none';
				helpPanel.style.display = open ? 'none' : '';
				helpToggle.classList.toggle( 'd5dsh-impact-help-active', ! open );
			} );
		}

		if ( expandBtn ) {
			expandBtn.addEventListener( 'click', function () {
				modal.querySelectorAll( '.d5dsh-modal-body details' ).forEach( function ( d ) { d.setAttribute( 'open', '' ); } );
			} );
		}
		if ( collapseBtn ) {
			collapseBtn.addEventListener( 'click', function () {
				modal.querySelectorAll( '.d5dsh-modal-body details' ).forEach( function ( d ) { d.removeAttribute( 'open' ); } );
			} );
		}
		if ( printBtn ) {
			printBtn.addEventListener( 'click', function () {
				// Expand all before printing so nothing is hidden.
				modal.querySelectorAll( '.d5dsh-modal-body details' ).forEach( function ( d ) { d.setAttribute( 'open', '' ); } );
				var titleEl = document.getElementById( 'd5dsh-impact-modal-title' );
				var title   = titleEl ? titleEl.textContent : 'Impact Analysis';
				// Collect visible pane content.
				var visiblePane = modal.querySelector( '.d5dsh-modal-pane[style=""]' ) || modal.querySelector( '.d5dsh-modal-pane:not([style*="display: none"])' ) || modal.querySelector( '.d5dsh-modal-pane:not([style*="display:none"])' );
				var activeTab   = modal.querySelector( '.d5dsh-modal-tab-active' );
				var tabLabel    = activeTab ? activeTab.textContent : '';
				var bodyHtml    = visiblePane ? visiblePane.innerHTML : '';
				var warnEl      = document.getElementById( 'd5dsh-impact-delete-warning' );
				var warnHtml    = ( warnEl && warnEl.style.display !== 'none' ) ? '<p style="color:#9a3412;font-weight:600">' + escHtml( warnEl.textContent ) + '</p>' : '';
				var win = window.open( '', '_blank', 'width=900,height=700' );
				if ( ! win ) { return; }
				win.document.write(
					'<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + escHtml( title ) + '</title>'
					+ '<style>'
					+ 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;padding:24px;max-width:860px;margin:0 auto}'
					+ 'h1{font-size:18px;margin:0 0 4px}'
					+ 'h2{font-size:14px;margin:16px 0 8px;color:#1d2327}'
					+ 'table{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}'
					+ 'th{background:#f6f7f7;font-weight:600;padding:4px 8px;border-bottom:1px solid #e2e4e7;text-align:left}'
					+ 'td{padding:4px 8px;border-bottom:1px solid #f0f0f1}'
					+ 'th:nth-child(2),td:nth-child(2){width:110px}'
					+ 'th:nth-child(3),td:nth-child(3){width:80px}'
					+ 'details{margin:8px 0;border:1px solid #e2e4e7;border-radius:3px;padding:8px}'
					+ 'summary{font-weight:600;cursor:pointer}'
					+ '.d5dsh-status-badge{padding:1px 6px;border-radius:3px;font-size:11px}'
					+ '.d5dsh-impact-severity-high{color:#9a3412;font-weight:600}'
					+ '.d5dsh-audit-badge{background:#e2e4e7;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:400}'
					+ '@media print{body{padding:0}}'
					+ '</style></head><body>'
					+ '<h1>' + escHtml( title ) + '</h1>'
					+ '<h2>' + escHtml( tabLabel ) + '</h2>'
					+ warnHtml
					+ bodyHtml
					+ '</body></html>'
				);
				win.document.close();
				setTimeout( function () { win.print(); }, 300 );
			} );
		}
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ── CATEGORIES (Feature 1)
	// ══════════════════════════════════════════════════════════════════════════

	var categoriesData  = [];   // [ { id, label, color }, ... ]
	var categoryMap     = {};   // { "var:gcid-xxx": ["cat-id",...], "gp:id": [...], "ep:id": [...] }
	var pendingCatMap   = {};   // unsaved working copy
	var catUndoSnapshot = null; // one-level undo: previous categoryMap before last save

	// Distinct palette — visually different from WP admin greys/blues.
	var CAT_AUTO_PALETTE = [
		'#e05c5c', '#e08c2a', '#c4b800', '#3daa6e', '#2a9dbf',
		'#8057d4', '#c44da8', '#5b8e3e', '#b05030', '#4a7fbf',
		'#7dab4a', '#cc6680', '#5599cc', '#9b6b2e', '#3db89b'
	];

	function catNextAutoColor() {
		var used = {};
		categoriesData.forEach( function ( c ) { if ( c.color ) { used[ c.color.toLowerCase() ] = true; } } );
		for ( var i = 0; i < CAT_AUTO_PALETTE.length; i++ ) {
			if ( ! used[ CAT_AUTO_PALETTE[ i ] ] ) { return CAT_AUTO_PALETTE[ i ]; }
		}
		return CAT_AUTO_PALETTE[ categoriesData.length % CAT_AUTO_PALETTE.length ];
	}

	function _catRefreshColorInput() {
		var colorInput = document.getElementById( 'd5dsh-cat-color-input' );
		if ( colorInput ) { colorInput.value = catNextAutoColor(); }
	}

	// Return the DSO key for a row object: "var:id", "gp:id", or "ep:id".
	function catDsoKey( row ) {
		if ( row._dsoKind === 'gp' ) { return 'gp:' + row.id; }
		if ( row._dsoKind === 'ep' ) { return 'ep:' + row.id; }
		return 'var:' + row.id;
	}

	// Build flat list of all assignable DSO rows from current manageData + presetsData.
	function buildCatRows() {
		var rows = [];
		var TYPE_LABELS_CAT = { colors: 'Colors', numbers: 'Numbers', fonts: 'Fonts', images: 'Images', strings: 'Text', links: 'Links' };
		if ( manageData ) {
			( manageData.vars || [] ).forEach( function ( v ) {
				rows.push( {
					_dsoKind: 'var',
					_dsoType: 'Variable',
					subType:  TYPE_LABELS_CAT[ v.type ] || v.type || '',
					id:       v.id,
					label:    v.label || '',
				} );
			} );
		}
		if ( presetsData ) {
			( presetsData.group_presets || [] ).forEach( function ( p ) {
				rows.push( {
					_dsoKind: 'gp',
					_dsoType: 'Group Preset',
					subType:  abbreviateGroupId( p.group_id || '' ).short || p.group_id || '',
					id:       p.preset_id,
					label:    p.name || '',
				} );
			} );
			( presetsData.element_presets || [] ).forEach( function ( p ) {
				rows.push( {
					_dsoKind: 'ep',
					_dsoType: 'Element Preset',
					subType:  abbreviateModule( p.module_name || '' ).short || p.module_name || '',
					id:       p.preset_id,
					label:    p.name || '',
				} );
			} );
		}
		return rows;
	}

	// ── Categories: init ──────────────────────────────────────────────────────

	function initCategories() {
		var section = document.getElementById( 'd5dsh-section-categories' );
		if ( ! section ) { return; }
		loadCategories();

		// Add category button.
		var addBtn = document.getElementById( 'd5dsh-cat-add-btn' );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				var nameInput  = document.getElementById( 'd5dsh-cat-name-input' );
				var colorInput = document.getElementById( 'd5dsh-cat-color-input' );
				var label = ( nameInput  ? nameInput.value  : '' ).trim();
				var color = ( colorInput ? colorInput.value : catNextAutoColor() );
				if ( ! label ) { return; }
				categoriesData.push( { id: '', label: label, color: color } );
				saveCategories( function () {
					if ( nameInput ) { nameInput.value = ''; }
					_catRefreshColorInput();
					renderCategoryList();
					renderCategoryAssignTable();
				} );
			} );
		}

		// Save Assignments button.
		var saveBtn = document.getElementById( 'd5dsh-cat-save-assignments-btn' );
		if ( saveBtn ) { saveBtn.addEventListener( 'click', saveCategoryAssignments ); }

		// Clear Filters button — resets column filters, sort, and column widths.
		var clearBtn = document.getElementById( 'd5dsh-cat-clear-filters-btn' );
		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', function () {
				catFilters = {};
				catSort    = { key: null, dir: 'asc' };
				closeAllCatFilterPanels();
				updateCatSortIndicators();
				renderCategoryAssignTable();
			} );
		}

		// Discard Changes button — resets pending map back to last saved state.
		var discardBtn = document.getElementById( 'd5dsh-cat-discard-btn' );
		if ( discardBtn ) {
			discardBtn.addEventListener( 'click', function () {
				pendingCatMap = _deepCloneMap( categoryMap );
				renderCategoryAssignTable();
			} );
		}

		// Undo Last Save button — restores snapshot saved before the last save.
		var undoBtn = document.getElementById( 'd5dsh-cat-undo-btn' );
		if ( undoBtn ) {
			undoBtn.addEventListener( 'click', function () {
				if ( ! catUndoSnapshot ) { return; }
				pendingCatMap = _deepCloneMap( catUndoSnapshot );
				catUndoSnapshot = null;
				undoBtn.style.display = 'none';
				// Save the restored state to the server.
				var statusEl = document.getElementById( 'd5dsh-cat-save-status' );
				if ( statusEl ) { statusEl.textContent = 'Undoing…'; }
				fetch( d5dtManage.ajaxUrl + '?action=d5dsh_categories_assign&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json' },
					body:    JSON.stringify( { assignments: pendingCatMap } ),
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json.success ) {
						categoryMap   = json.data.category_map || {};
						pendingCatMap = _deepCloneMap( categoryMap );
						if ( statusEl ) { statusEl.textContent = 'Undone.'; setTimeout( function () { statusEl.textContent = ''; }, 2000 ); }
					}
					renderCategoryAssignTable();
				} );
			} );
		}
	}

	// ── Categories: load / save ───────────────────────────────────────────────

	function loadCategories() {
		if ( typeof d5dtManage === 'undefined' ) { return; }
		var loadingEl = document.getElementById( 'd5dsh-cat-loading' );
		if ( loadingEl ) { loadingEl.style.display = ''; }
		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_categories_load&nonce=' + encodeURIComponent( d5dtManage.nonce ) )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				categoriesData = json.data.categories   || [];
				categoryMap    = json.data.category_map || {};
				pendingCatMap  = _deepCloneMap( categoryMap );
			}
			if ( loadingEl ) { loadingEl.style.display = 'none'; }
			renderCategoryList();
			renderCategoryAssignTable();
			wireCatFilterHeaders();
			wireCatListFilterHeaders();
			_catRefreshColorInput();
			setTimeout( resizePresetsWraps, 0 );
		} );
	}

	function saveCategories( callback ) {
		if ( typeof d5dtManage === 'undefined' ) { return; }
		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_categories_save&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { categories: categoriesData } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				categoriesData = json.data.categories   || [];
				categoryMap    = json.data.category_map || {};
				pendingCatMap  = _deepCloneMap( categoryMap );
			}
			if ( callback ) { callback(); }
		} );
	}

	function saveCategoryAssignments() {
		if ( typeof d5dtManage === 'undefined' ) { return; }
		var statusEl = document.getElementById( 'd5dsh-cat-save-status' );
		if ( statusEl ) { statusEl.textContent = 'Saving\u2026'; }
		// Snapshot current state for one-level undo before overwriting.
		catUndoSnapshot = _deepCloneMap( categoryMap );
		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_categories_assign&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { assignments: pendingCatMap } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( json.success ) {
				categoryMap   = json.data.category_map || {};
				pendingCatMap = _deepCloneMap( categoryMap );
				if ( statusEl ) { statusEl.textContent = 'Saved.'; setTimeout( function () { statusEl.textContent = ''; }, 2000 ); }
				// Show undo button now that a save has happened.
				var undoBtn = document.getElementById( 'd5dsh-cat-undo-btn' );
				if ( undoBtn ) { undoBtn.style.display = ''; }
			}
		} );
	}

	function _deepCloneMap( m ) {
		var out = {};
		Object.keys( m ).forEach( function ( k ) { out[ k ] = Array.isArray( m[ k ] ) ? m[ k ].slice() : [ m[ k ] ]; } );
		return out;
	}

	// ── Category list (top table) — filter/sort state ────────────────────────

	var catListFilters         = {};
	var catListSort            = { key: null, dir: 'asc' };
	var activeCatListFilterCol = null;
	var catListHeadersWired    = false;

	function _catListRowVal( cat, col, counts ) {
		if ( col === 'cat_list_name' ) { return ( cat.label || '' ).toLowerCase(); }
		if ( col === 'cat_list_dsos' ) { return String( counts[ cat.id ] || 0 ); }
		return '';
	}

	function _applyCatListFilters( cats, counts ) {
		return cats.filter( function ( cat ) {
			return Object.keys( catListFilters ).every( function ( col ) {
				var f = catListFilters[ col ];
				if ( ! f ) { return true; }
				var cellVal = _catListRowVal( cat, col, counts );
				if ( f.mode === 'is_empty' ) { return cellVal === ''; }
				if ( f.vals && f.vals.size > 0 ) {
					return Array.from( f.vals ).some( function ( fv ) { return cellVal === fv.toLowerCase(); } );
				}
				if ( ! f.val ) { return true; }
				var fv = f.val.toLowerCase();
				if ( f.mode === 'equals' )      { return cellVal === fv; }
				if ( f.mode === 'starts_with' ) { return cellVal.startsWith( fv ); }
				return cellVal.includes( fv );
			} );
		} );
	}

	function _sortCatList( cats, counts ) {
		if ( ! catListSort.key ) { return cats; }
		var key = catListSort.key, dir = catListSort.dir;
		return cats.slice().sort( function ( a, b ) {
			var av = _catListRowVal( a, key, counts );
			var bv = _catListRowVal( b, key, counts );
			// Numeric sort for dsos column.
			if ( key === 'cat_list_dsos' ) { av = parseInt( av, 10 ) || 0; bv = parseInt( bv, 10 ) || 0; }
			if ( av < bv ) { return dir === 'asc' ? -1 : 1; }
			if ( av > bv ) { return dir === 'asc' ? 1 : -1; }
			return 0;
		} );
	}

	function getCatListDistinctValues( col ) {
		var counts = _buildCatListCounts();
		var seen = {}, vals = [];
		categoriesData.forEach( function ( cat ) {
			var val = _catListRowVal( cat, col, counts );
			if ( val !== '' && ! seen[ val ] ) { seen[ val ] = true; vals.push( val ); }
		} );
		return vals.sort();
	}

	function _buildCatListCounts() {
		var counts = {};
		Object.values( categoryMap ).forEach( function ( catIds ) {
			( Array.isArray( catIds ) ? catIds : [ catIds ] ).forEach( function ( cid ) {
				counts[ cid ] = ( counts[ cid ] || 0 ) + 1;
			} );
		} );
		return counts;
	}

	function updateCatListSortIndicators() {
		var tableEl = document.getElementById( 'd5dsh-cat-list-table' );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'th[data-filter-col]' ).forEach( function ( th ) {
			var col = th.dataset.filterCol;
			var f   = catListFilters[ col ];
			var hasFilter = !! ( f && ( f.vals ? f.vals.size > 0 : f.val ) );
			th.classList.toggle( 'd5dsh-col-filtered', hasFilter );
			th.classList.toggle( 'd5dsh-col-sorted',   catListSort.key === col );
		} );
	}

	function closeAllCatListFilterPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) {
			if ( p._cleanup ) { p._cleanup(); }
			p.remove();
		} );
		document.querySelectorAll( '#d5dsh-cat-list-table th.d5dsh-col-filter-active' ).forEach( function ( th ) { th.classList.remove( 'd5dsh-col-filter-active' ); } );
		activeCatListFilterCol = null;
	}

	function wireCatListFilterHeaders() {
		if ( catListHeadersWired ) { return; }
		var tableEl = document.getElementById( 'd5dsh-cat-list-table' );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'thead th[data-filter-col]' ).forEach( function ( th ) {
			th.addEventListener( 'click', function ( e ) {
				// Don't open filter when clicking the expand toggle.
				if ( e.target.classList.contains( 'd5dsh-col-expand-toggle' ) ) { return; }
				e.stopPropagation();
				var col = th.dataset.filterCol;
				if ( activeCatListFilterCol === col ) {
					closeAllCatListFilterPanels();
				} else {
					activeCatListFilterCol = col;
					openFilterPanel( {
						th:           th,
						col:          col,
						filtersObj:   catListFilters,
						sortObj:      catListSort,
						getValues:    getCatListDistinctValues,
						closeAll:     closeAllCatListFilterPanels,
						scrollWrapId: 'd5dsh-cat-list-wrap',
						onApply: function () { updateCatListSortIndicators(); renderCategoryList(); },
						onClear: function () { updateCatListSortIndicators(); renderCategoryList(); },
						onSort:  function () { updateCatListSortIndicators(); renderCategoryList(); },
					} );
				}
			} );
		} );
		// Column sizing and expand toggle for Name column.
		initTableColumns( 'd5dsh-cat-list-table', 'd5dsh_col_widths_cat_list' );
		initColExpandToggles( 'd5dsh-cat-list-table', 'd5dsh_col_widths_cat_list', [ 1 ] );
		catListHeadersWired = true;
	}

	// ── Category list (top table) ─────────────────────────────────────────────

	function renderCategoryList() {
		var tbody = document.getElementById( 'd5dsh-cat-list-tbody' );
		var wrap  = document.getElementById( 'd5dsh-cat-list-wrap' );
		if ( ! tbody ) { return; }
		if ( wrap ) { wrap.style.display = categoriesData.length ? '' : 'none'; }
		tbody.innerHTML = '';
		var counts = _buildCatListCounts();
		// Index each cat by its position in categoriesData so delete/edit work after filtering.
		var indexed = categoriesData.map( function ( cat, idx ) { return { cat: cat, idx: idx }; } );
		var filtered = _applyCatListFilters( indexed.map( function ( o ) { return o.cat; } ), counts );
		// Re-attach original indices after filter.
		var filteredIndexed = filtered.map( function ( cat ) { return indexed.find( function ( o ) { return o.cat === cat; } ); } );
		filteredIndexed = _sortCatList( filteredIndexed, counts );
		filteredIndexed.forEach( function ( o ) {
			var cat = o.cat, idx = o.idx;
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td class="d5dsh-col-cat-list-color"><span class="d5dsh-category-swatch d5dsh-cat-swatch-editable" style="background:' + escHtml( cat.color ) + '" title="Click to change color" data-idx="' + idx + '"></span>'
				+ '<input type="color" class="d5dsh-cat-inline-color" value="' + escHtml( cat.color ) + '" data-idx="' + idx + '" style="opacity:0;position:absolute;width:0;height:0"></td>'
				+ '<td class="d5dsh-col-cat-list-name">' + escHtml( cat.label ) + '</td>'
				+ '<td class="d5dsh-col-cat-list-dsos">' + ( counts[ cat.id ] || 0 ) + '</td>'
				+ '<td class="d5dsh-col-cat-list-comment"><textarea class="d5dsh-cat-comment-input" rows="1" data-idx="' + idx + '">' + escHtml( cat.comment || '' ) + '</textarea></td>'
				+ '<td class="d5dsh-col-cat-list-actions"><button type="button" class="button button-small d5dsh-cat-delete-btn" data-idx="' + idx + '">Delete</button></td>';
			tbody.appendChild( tr );
		} );
		syncTdWidths( 'd5dsh-cat-list-table' );
		// Wire delete buttons.
		tbody.querySelectorAll( '.d5dsh-cat-delete-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var i = parseInt( btn.dataset.idx, 10 );
				categoriesData.splice( i, 1 );
				saveCategories( function () { renderCategoryList(); renderCategoryAssignTable(); } );
			} );
		} );
		// Wire swatch -> hidden color picker.
		tbody.querySelectorAll( '.d5dsh-cat-swatch-editable' ).forEach( function ( swatch ) {
			swatch.addEventListener( 'click', function () {
				var picker = swatch.parentNode.querySelector( '.d5dsh-cat-inline-color' );
				if ( picker ) { picker.click(); }
			} );
		} );
		tbody.querySelectorAll( '.d5dsh-cat-inline-color' ).forEach( function ( picker ) {
			picker.addEventListener( 'input', function () {
				var i = parseInt( picker.dataset.idx, 10 );
				if ( categoriesData[ i ] ) {
					categoriesData[ i ].color = picker.value;
					var swatch = picker.parentNode.querySelector( '.d5dsh-cat-swatch-editable' );
					if ( swatch ) { swatch.style.background = picker.value; }
				}
			} );
			picker.addEventListener( 'change', function () {
				saveCategories( function () { renderCategoryAssignTable(); } );
			} );
		} );
		// Wire comment textareas — save on blur.
		tbody.querySelectorAll( '.d5dsh-cat-comment-input' ).forEach( function ( ta ) {
			ta.addEventListener( 'change', function () {
				var i = parseInt( ta.dataset.idx, 10 );
				if ( categoriesData[ i ] ) {
					categoriesData[ i ].comment = ta.value;
					saveCategories( function () {} );
				}
			} );
		} );
	}

	// ── Categories assign table: filter/sort state ────────────────────────────

	var catFilters         = {};
	var catSort            = { key: null, dir: 'asc' };
	var activeCatFilterCol = null;
	var catHeadersWired    = false;

	function getCatDistinctValues( col ) {
		var rows = buildCatRows();
		var seen = {}, vals = [];
		rows.forEach( function ( r ) {
			var val = _catRowCellVal( r, col );
			if ( val && ! seen[ val ] ) { seen[ val ] = true; vals.push( val ); }
		} );
		return vals.sort();
	}

	function _catRowCellVal( r, col ) {
		if ( col === 'cat_dso_type' ) { return r._dsoType || ''; }
		if ( col === 'cat_type' )     { return r.subType   || ''; }
		if ( col === 'cat_id' )       { return r.id        || ''; }
		if ( col === 'cat_label' )    { return r.label     || ''; }
		if ( col === 'cat_category' ) {
			var key  = catDsoKey( r );
			var ids  = pendingCatMap[ key ] || [];
			var cats = ( Array.isArray( ids ) ? ids : [ ids ] )
				.map( function ( cid ) { var c = categoriesData.find( function ( x ) { return x.id === cid; } ); return c ? c.label : ''; } )
				.filter( Boolean );
			return cats.join( ', ' );
		}
		return '';
	}

	function closeAllCatFilterPanels() {
		document.querySelectorAll( '.d5dsh-col-filter-panel' ).forEach( function ( p ) {
			if ( p._cleanup ) { p._cleanup(); }
			p.remove();
		} );
		document.querySelectorAll( '#d5dsh-cat-assign-table th.d5dsh-col-filter-active' ).forEach( function ( th ) { th.classList.remove( 'd5dsh-col-filter-active' ); } );
		activeCatFilterCol = null;
	}

	function updateCatSortIndicators() {
		var tableEl = document.getElementById( 'd5dsh-cat-assign-table' );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'th[data-filter-col]' ).forEach( function ( th ) {
			var col = th.dataset.filterCol;
			var f   = catFilters[ col ];
			var hasFilter = !! ( f && ( f.vals ? f.vals.size > 0 : f.val ) );
			th.classList.toggle( 'd5dsh-col-filtered', hasFilter );
			th.classList.toggle( 'd5dsh-col-sorted',   catSort.key === col );
		} );
	}

	function wireCatFilterHeaders() {
		if ( catHeadersWired ) { return; }
		var tableEl = document.getElementById( 'd5dsh-cat-assign-table' );
		if ( ! tableEl ) { return; }
		tableEl.querySelectorAll( 'thead th[data-filter-col]' ).forEach( function ( th ) {
			th.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var col = th.dataset.filterCol;
				if ( activeCatFilterCol === col ) {
					closeAllCatFilterPanels();
				} else {
					activeCatFilterCol = col;
					openFilterPanel( {
						th:           th,
						col:          col,
						filtersObj:   catFilters,
						sortObj:      catSort,
						getValues:    getCatDistinctValues,
						closeAll:     closeAllCatFilterPanels,
						scrollWrapId: 'd5dsh-cat-assign-wrap',
						onApply: function () { updateCatSortIndicators(); renderCategoryAssignTable(); },
						onClear: function () { updateCatSortIndicators(); renderCategoryAssignTable(); },
						onSort:  function () { updateCatSortIndicators(); renderCategoryAssignTable(); },
					} );
				}
			} );
		} );
		catHeadersWired = true;
	}

	function _applyCatFilters( rows ) {
		return rows.filter( function ( r ) {
			return Object.keys( catFilters ).every( function ( col ) {
				var f = catFilters[ col ];
				if ( ! f ) { return true; }
				var cellVal = _catRowCellVal( r, col ).toLowerCase();
				if ( f.mode === 'is_empty' ) { return cellVal === ''; }
				if ( f.vals && f.vals.size > 0 ) {
					return Array.from( f.vals ).some( function ( fv ) { return cellVal === fv.toLowerCase(); } );
				}
				if ( ! f.val ) { return true; }
				var fv = f.val.toLowerCase();
				if ( f.mode === 'equals' )      { return cellVal === fv; }
				if ( f.mode === 'starts_with' ) { return cellVal.startsWith( fv ); }
				return cellVal.includes( fv );
			} );
		} );
	}

	// ── Multi-select category dropdown (checkbox panel) ───────────────────────

	function _buildCatCheckboxPanel( dsoKey, anchorEl ) {
		// Remove any existing panel.
		var existing = document.getElementById( 'd5dsh-cat-checkbox-panel' );
		if ( existing ) { existing.remove(); if ( existing.dataset.key === dsoKey ) { return; } }

		var panel = document.createElement( 'div' );
		panel.id             = 'd5dsh-cat-checkbox-panel';
		panel.dataset.key    = dsoKey;
		panel.className      = 'd5dsh-cat-cb-panel';

		var assigned = pendingCatMap[ dsoKey ] ? ( Array.isArray( pendingCatMap[ dsoKey ] ) ? pendingCatMap[ dsoKey ] : [ pendingCatMap[ dsoKey ] ] ) : [];

		if ( categoriesData.length === 0 ) {
			setElMsg( panel, 'd5dsh-cat-cb-empty', 'No categories defined yet.' );
		} else {
			categoriesData.forEach( function ( cat ) {
				var checked = assigned.indexOf( cat.id ) !== -1;
				var row = document.createElement( 'label' );
				row.className = 'd5dsh-cat-cb-row';
				row.innerHTML =
					'<input type="checkbox" value="' + escHtml( cat.id ) + '"' + ( checked ? ' checked' : '' ) + '>'
					+ '<span class="d5dsh-category-swatch" style="background:' + escHtml( cat.color ) + '"></span>'
					+ escHtml( cat.label );
				panel.appendChild( row );
			} );
		}

		// Position below the anchor cell.
		document.body.appendChild( panel );
		var rect = anchorEl.getBoundingClientRect();
		panel.style.top  = ( rect.bottom + window.scrollY ) + 'px';
		panel.style.left = ( rect.left   + window.scrollX ) + 'px';

		// On change — update pendingCatMap and refresh swatches in the row.
		panel.addEventListener( 'change', function () {
			var selected = [];
			panel.querySelectorAll( 'input[type=checkbox]:checked' ).forEach( function ( cb ) { selected.push( cb.value ); } );
			if ( selected.length ) { pendingCatMap[ dsoKey ] = selected; }
			else { delete pendingCatMap[ dsoKey ]; }
			// Update swatch cell in the same table row.
			var tr = anchorEl.closest( 'tr' );
			if ( tr ) { _refreshRowSwatches( tr, dsoKey ); }
			// Update category cell text.
			var catCell = tr && tr.querySelector( '.d5dsh-cat-assign-cell' );
			if ( catCell ) { catCell.textContent = _catAssignedLabels( dsoKey ); }
		} );

		// Panel close is handled by the global document click handler.
	}

	function _catAssignedLabels( dsoKey ) {
		var ids = pendingCatMap[ dsoKey ] || [];
		if ( ! Array.isArray( ids ) ) { ids = [ ids ]; }
		return ids.map( function ( cid ) {
			var c = categoriesData.find( function ( x ) { return x.id === cid; } );
			return c ? c.label : '';
		} ).filter( Boolean ).join( ', ' ) || '— none —';
	}

	function _catAssignedSwatchHtml( dsoKey ) {
		var ids = pendingCatMap[ dsoKey ] || [];
		if ( ! Array.isArray( ids ) ) { ids = [ ids ]; }
		return ids.map( function ( cid ) {
			var c = categoriesData.find( function ( x ) { return x.id === cid; } );
			return c ? '<span class="d5dsh-category-swatch" style="background:' + escHtml( c.color ) + '" title="' + escHtml( c.label ) + '"></span>' : '';
		} ).filter( Boolean ).join( '' );
	}

	function _refreshRowSwatches( tr, dsoKey ) {
		var swatchCell = tr.querySelector( '.d5dsh-cat-swatch-cell' );
		if ( swatchCell ) { swatchCell.innerHTML = _catAssignedSwatchHtml( dsoKey ); }
	}

	// ── renderCategoryAssignTable ─────────────────────────────────────────────

	function renderCategoryAssignTable() {
		var tbody = document.getElementById( 'd5dsh-cat-assign-tbody' );
		if ( ! tbody ) { return; }

		var rows = buildCatRows();
		rows = _applyCatFilters( rows );

		// Sort.
		if ( catSort.key ) {
			var sKey = catSort.key, sDir = catSort.dir;
			rows = rows.sort( function ( a, b ) {
				var av = _catRowCellVal( a, sKey ).toLowerCase();
				var bv = _catRowCellVal( b, sKey ).toLowerCase();
				var cmp = av < bv ? -1 : av > bv ? 1 : 0;
				return sDir === 'desc' ? -cmp : cmp;
			} );
		}

		tbody.innerHTML = '';
		rows.forEach( function ( r, idx ) {
			var dsoKey    = catDsoKey( r );
			var labelText = _catAssignedLabels( dsoKey );
			var swatches  = _catAssignedSwatchHtml( dsoKey );
			var tr = document.createElement( 'tr' );
			tr.innerHTML =
				'<td class="d5dsh-col-order">' + ( idx + 1 ) + '</td>'
				+ '<td class="d5dsh-col-dso-type">' + escHtml( r._dsoType ) + '</td>'
				+ '<td class="d5dsh-col-type">'  + escHtml( r.subType ) + '</td>'
				+ '<td class="d5dsh-col-id"><code>' + escHtml( r.id ) + '</code></td>'
				+ '<td class="d5dsh-col-label">' + escHtml( r.label ) + '</td>'
				+ '<td class="d5dsh-col-cat-category d5dsh-cat-assign-cell d5dsh-cat-assign-trigger" data-dso-key="' + escHtml( dsoKey ) + '">' + escHtml( labelText ) + '</td>'
				+ '<td class="d5dsh-col-cat-color d5dsh-cat-swatch-cell">' + swatches + '</td>';
			tbody.appendChild( tr );
		} );
		syncTdWidths( 'd5dsh-cat-assign-table' );

		// Wire category cell clicks to open checkbox panel.
		tbody.querySelectorAll( '.d5dsh-cat-assign-trigger' ).forEach( function ( cell ) {
			cell.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				_buildCatCheckboxPanel( cell.dataset.dsoKey, cell );
			} );
		} );

		// Cat assign column widths set by initTableColumns from data- attributes.
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ── MERGE VARIABLES (Feature 2)
	// ══════════════════════════════════════════════════════════════════════════

	var mergeKeepId   = '';
	var mergeRetireId = '';

	function initMergeMode() {
		// Wire swap button.
		var swapBtn = document.getElementById( 'd5dsh-merge-swap-btn' );
		if ( swapBtn ) {
			swapBtn.addEventListener( 'click', function () {
				var tmp      = mergeKeepId;
				mergeKeepId  = mergeRetireId;
				mergeRetireId = tmp;
				_renderMergeCard( 'keep',   mergeKeepId );
				_renderMergeCard( 'retire', mergeRetireId );
				_loadMergePreview();
			} );
		}

		// Wire confirm button.
		var confirmBtn = document.getElementById( 'd5dsh-merge-confirm-btn' );
		if ( confirmBtn ) {
			confirmBtn.addEventListener( 'click', function () {
				if ( ! mergeKeepId || ! mergeRetireId ) { return; }
				confirmBtn.disabled = true;
				var statusEl = document.getElementById( 'd5dsh-merge-status' );
				if ( statusEl ) { statusEl.textContent = 'Merging…'; }
				fetch( d5dtManage.ajaxUrl + '?action=d5dsh_merge_vars&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
					method:  'POST',
					headers: { 'Content-Type': 'application/json' },
					body:    JSON.stringify( { keep_id: mergeKeepId, retire_id: mergeRetireId } ),
				} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( json ) {
					if ( json.success ) {
						var d = json.data;
						if ( statusEl ) { statusEl.textContent = 'Done — ' + d.updated_presets + ' preset' + ( d.updated_presets !== 1 ? 's' : '' ) + ' updated. “' + d.retired_label + '” archived.'; }
						mergeKeepId = mergeRetireId = '';
						_renderMergeCard( 'keep',   '' );
						_renderMergeCard( 'retire', '' );
						var impactArea = document.getElementById( 'd5dsh-merge-impact' );
						if ( impactArea ) { impactArea.style.display = 'none'; }
						// Reload manage data so archived var is reflected.
						if ( typeof loadManageData === 'function' ) { loadManageData(); }
					} else {
						if ( statusEl ) { statusEl.textContent = 'Error: ' + ( json.data && json.data.message ? json.data.message : 'Unknown error' ); }
						confirmBtn.disabled = false;
					}
				} );
			} );
		}

		// Wire search inputs.
		[ 'keep', 'retire' ].forEach( function ( role ) {
			var input = document.getElementById( 'd5dsh-merge-' + role + '-search' );
			if ( ! input ) { return; }
			var dropdown = null;
			input.addEventListener( 'input', function () {
				var q = input.value.trim().toLowerCase();
				if ( dropdown ) { dropdown.parentNode.removeChild( dropdown ); dropdown = null; }
				if ( q.length < 1 ) { return; }
				var vars = ( manageData && manageData.vars ) ? manageData.vars : [];
				var matches = vars.filter( function ( v ) {
					return ( v.label || '' ).toLowerCase().indexOf( q ) !== -1 || ( v.id || '' ).toLowerCase().indexOf( q ) !== -1;
				} ).slice( 0, 10 );
				if ( matches.length === 0 ) { return; }
				dropdown = document.createElement( 'ul' );
				dropdown.className = 'd5dsh-merge-dropdown';
				matches.forEach( function ( v ) {
					var li = document.createElement( 'li' );
					li.textContent = v.label + ' (' + v.id + ')';
					li.addEventListener( 'mousedown', function ( e ) {
						e.preventDefault();
						input.value = v.label;
						if ( role === 'keep' )   { mergeKeepId   = v.id; }
						else                     { mergeRetireId  = v.id; }
						_renderMergeCard( role, v.id );
						if ( dropdown ) { dropdown.parentNode.removeChild( dropdown ); dropdown = null; }
						_loadMergePreview();
					} );
					dropdown.appendChild( li );
				} );
				input.parentNode.style.position = 'relative';
				input.parentNode.appendChild( dropdown );
			} );
			input.addEventListener( 'blur', function () {
				setTimeout( function () {
					if ( dropdown ) { dropdown.parentNode.removeChild( dropdown ); dropdown = null; }
				}, 200 );
			} );
		} );

		// Check for a deep-link prefill from openMergeMode().
		try {
			var prefill = sessionStorage.getItem( 'd5dsh_merge_prefill' );
			if ( prefill ) {
				sessionStorage.removeItem( 'd5dsh_merge_prefill' );
				var pf = JSON.parse( prefill );
				if ( pf.keep && pf.retire ) {
					mergeKeepId   = pf.keep;
					mergeRetireId  = pf.retire;
					// Delay until manageData is loaded.
					var _pfTimer = setInterval( function () {
						if ( ! manageData ) { return; }
						clearInterval( _pfTimer );
						if ( typeof setManageMode === 'function' ) { setManageMode( 'merge' ); }
						_renderMergeCard( 'keep',   mergeKeepId );
						_renderMergeCard( 'retire', mergeRetireId );
						_loadMergePreview();
					}, 150 );
				}
			}
		} catch(e) {}
	}

	/**
	 * Pre-select two variables and switch to Merge mode.
	 * Called from Audit tab near-dupe Merge... button.
	 */
	function openMergeMode( id1, id2 ) {
		// Switch to manage tab.
		var manageUrl = new URL( window.location.href );
		manageUrl.searchParams.set( 'tab', 'manage' );
		// Store pre-selection for when the tab loads.
		try { sessionStorage.setItem( 'd5dsh_merge_prefill', JSON.stringify( { keep: id1, retire: id2 } ) ); } catch(e) {}
		window.location.href = manageUrl.toString();
	}

	function _renderMergeCard( role, varId ) {
		var displayEl = document.getElementById( 'd5dsh-merge-' + role + '-display' );
		if ( ! displayEl ) { return; }
		if ( ! varId ) { displayEl.innerHTML = ''; return; }
		var vars = ( manageData && manageData.vars ) ? manageData.vars : [];
		var v = null;
		for ( var i = 0; i < vars.length; i++ ) { if ( vars[i].id === varId ) { v = vars[i]; break; } }
		if ( ! v ) {
			displayEl.innerHTML = '';
			var _em = document.createElement( 'em' ); _em.textContent = varId; displayEl.appendChild( _em );
			return;
		}
		displayEl.innerHTML = '';
		var _strong = document.createElement( 'strong' ); _strong.textContent = v.label || varId; displayEl.appendChild( _strong );
		if ( v.type === 'colors' && v.value ) {
			var _swatch = document.createElement( 'span' ); _swatch.className = 'd5dsh-color-swatch-inline';
			_swatch.style.background = v.value; displayEl.appendChild( document.createTextNode( ' ' ) ); displayEl.appendChild( _swatch );
		}
		displayEl.appendChild( document.createElement( 'br' ) );
		var _code = document.createElement( 'code' ); _code.textContent = v.id; displayEl.appendChild( _code );
		displayEl.appendChild( document.createElement( 'br' ) );
		var _badge = document.createElement( 'span' ); _badge.className = 'd5dsh-type-badge'; _badge.textContent = v.type || ''; displayEl.appendChild( _badge );
	}

	function _loadMergePreview() {
		if ( ! mergeRetireId ) { return; }
		var impactArea = document.getElementById( 'd5dsh-merge-impact' );
		var impactBody = document.getElementById( 'd5dsh-merge-impact-body' );
		var confirmBtn = document.getElementById( 'd5dsh-merge-confirm-btn' );
		if ( impactArea ) { impactArea.style.display = ''; }
		if ( impactBody ) { impactBody.innerHTML = 'Loading…'; }
		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_merge_preview&nonce=' + encodeURIComponent( d5dtManage.nonce ), {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify( { retire_id: mergeRetireId } ),
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( ! json.success || ! impactBody ) { return; }
			var presets = json.data.affected_presets || [];
			if ( presets.length === 0 ) {
				setElMsg( impactBody, '', 'No presets reference this variable.' );
			} else {
				var rows = presets.map( function ( p ) {
					return '<tr><td><code>' + escHtml( p.preset_id ) + '</code></td><td>' + escHtml( p.preset_label ) + '</td><td>' + escHtml( p.module_name ) + '</td></tr>';
				} ).join( '' );
				impactBody.innerHTML = '<p>' + presets.length + ' preset' + ( presets.length !== 1 ? 's' : '' ) + ' will be updated:</p>'
					+ '<table class="d5dsh-merge-impact-table"><thead><tr><th>Preset ID</th><th>Name</th><th>Module</th></tr></thead><tbody>' + rows + '</tbody></table>';
			}
			if ( confirmBtn ) {
				confirmBtn.disabled = ! ( mergeKeepId && mergeRetireId && mergeKeepId !== mergeRetireId );
				confirmBtn.textContent = 'Merge — Update ' + presets.length + ' preset' + ( presets.length !== 1 ? 's' : '' );
			}
		} );
	}

	// ══════════════════════════════════════════════════════════════════════════
	// ── STYLE GUIDE (Feature 5)
	// ══════════════════════════════════════════════════════════════════════════

	function initStyleGuide() {
		var panel = document.getElementById( 'd5dsh-styleguide-panel' );
		if ( ! panel ) { return; }

		var generateBtn = document.getElementById( 'd5dsh-sg-generate-btn' );
		var downloadBtn = document.getElementById( 'd5dsh-sg-download-btn' );
		var printBtn    = document.getElementById( 'd5dsh-sg-print-btn' );

		if ( generateBtn ) { generateBtn.addEventListener( 'click', generateStyleGuide ); }
		if ( downloadBtn ) { downloadBtn.addEventListener( 'click', downloadStyleGuideHtml ); }
		if ( printBtn    ) { printBtn.addEventListener(    'click', function () { window.print(); } ); }
	}

	var _sgData = null; // Cache for current style guide data.

	function generateStyleGuide() {
		if ( typeof d5dtManage === 'undefined' ) { return; }
		var preview  = document.getElementById( 'd5dsh-styleguide-preview' );
		var exportBar = document.getElementById( 'd5dsh-sg-export-bar' );
		if ( preview ) { setElMsg( preview, 'd5dsh-sg-placeholder', 'Generating…' ); }
		if ( exportBar ) { exportBar.style.display = 'none'; }

		var showSystem  = document.getElementById( 'd5dsh-sg-show-system' );
		var groupCats   = document.getElementById( 'd5dsh-sg-group-cats' );
		var showPresets = document.getElementById( 'd5dsh-sg-show-presets' );

		fetch( d5dtManage.ajaxUrl + '?action=d5dsh_styleguide_data&nonce=' + encodeURIComponent( d5dtManage.nonce ) )
		.then( function ( r ) { return r.json(); } )
		.then( function ( json ) {
			if ( ! json.success ) {
				if ( preview ) { setElMsg( preview, 'd5dsh-sg-placeholder', 'Failed to load style guide data.' ); }
				return;
			}
			_sgData = json.data;
			var opts = {
				showSystem:  showSystem  ? showSystem.checked  : true,
				groupCats:   groupCats   ? groupCats.checked   : false,
				showPresets: showPresets ? showPresets.checked : false,
			};
			var html = _buildStyleGuideHtml( _sgData, opts );
			if ( preview ) { preview.innerHTML = html; }
			if ( exportBar ) { exportBar.style.display = ''; }
		} );
	}

	function _buildStyleGuideHtml( data, opts ) {
		var vars       = data.vars        || [];
		var presets    = data.presets      || [];
		var categories = data.categories   || [];
		var catMap     = data.category_map || {};

		// Optionally filter out system vars.
		if ( ! opts.showSystem ) { vars = vars.filter( function ( v ) { return ! v.system; } ); }

		var html = '';

		if ( opts.groupCats && categories.length > 0 ) {
			// Group by category.
			var uncatVars = vars.filter( function ( v ) { return ! catMap[ v.id ]; } );
			categories.forEach( function ( cat ) {
				var catVars = vars.filter( function ( v ) { return catMap[ v.id ] === cat.id; } );
				if ( catVars.length === 0 ) { return; }
				html += '<div class="d5dsh-sg-category-group">';
				html += '<h3 class="d5dsh-sg-category-title"><span class="d5dsh-category-swatch" style="background:' + escHtml( cat.color ) + '"></span> ' + escHtml( cat.label ) + '</h3>';
				html += _sgVarSections( catVars );
				html += '</div>';
			} );
			if ( uncatVars.length > 0 ) {
				html += '<div class="d5dsh-sg-category-group">';
				html += '<h3 class="d5dsh-sg-category-title">Uncategorised</h3>';
				html += _sgVarSections( uncatVars );
				html += '</div>';
			}
		} else {
			html += _sgVarSections( vars );
		}

		if ( opts.showPresets && presets.length > 0 ) {
			html += '<h2 class="d5dsh-sg-section-title">Presets</h2>';
			html += '<table class="d5dsh-sg-preset-table"><thead><tr><th>Name</th><th>Module</th><th>Type</th></tr></thead><tbody>';
			presets.forEach( function ( p ) {
				html += '<tr><td>' + escHtml( p.name || p.id ) + '</td><td>' + escHtml( p.moduleName || '' ) + '</td><td>' + escHtml( p.type || '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
		}

		return html;
	}

	function _sgVarSections( vars ) {
		var html     = '';
		var types    = [ 'colors', 'numbers', 'fonts', 'images', 'strings', 'links' ];
		var typeLabels = { colors: 'Colors', numbers: 'Numbers & Spacing', fonts: 'Typography', images: 'Images', strings: 'Text', links: 'Links' };

		types.forEach( function ( type ) {
			var group = vars.filter( function ( v ) { return v.type === type || ( type === 'colors' && v.type === 'global_color' ); } );
			if ( group.length === 0 ) { return; }
			html += '<h2 class="d5dsh-sg-section-title">' + escHtml( typeLabels[ type ] || type ) + '</h2>';

			if ( type === 'colors' ) {
				html += '<div class="d5dsh-sg-swatch-grid">';
				group.forEach( function ( v ) {
					var val = v.value || '';
					var isRef = val.indexOf( '$variable(' ) !== -1;
					var bg = isRef ? '#e5e7eb' : val;
					html += '<div class="d5dsh-sg-swatch-card">'
						+ '<div class="d5dsh-sg-swatch-circle" style="background:' + escHtml( bg ) + '"></div>'
						+ '<div class="d5dsh-sg-swatch-label">' + escHtml( v.label || v.id ) + '</div>'
						+ '<div class="d5dsh-sg-swatch-value">' + escHtml( isRef ? 'var ref' : val ) + '</div>'
						+ '<div class="d5dsh-sg-swatch-id">' + escHtml( v.id ) + '</div>'
						+ '</div>';
				} );
				html += '</div>';
			} else if ( type === 'fonts' ) {
				html += '<div class="d5dsh-sg-type-samples">';
				group.forEach( function ( v ) {
					var fontFamily = v.value || 'inherit';
					html += '<div class="d5dsh-sg-type-sample-row">'
						+ '<span class="d5dsh-sg-type-label">' + escHtml( v.label || v.id ) + '</span>'
						+ '<span class="d5dsh-sg-type-sample" style="font-family:' + escHtml( fontFamily ) + '">The quick brown fox jumps over the lazy dog</span>'
						+ '</div>';
				} );
				html += '</div>';
			} else if ( type === 'numbers' ) {
				html += '<div class="d5dsh-sg-number-list">';
				group.forEach( function ( v ) {
					// Try to parse a px/rem value for a visual bar.
					var raw = ( v.value || '' ).trim();
					var numVal = parseFloat( raw );
					var barWidth = isNaN( numVal ) ? 0 : Math.min( numVal, 400 );
					html += '<div class="d5dsh-sg-number-row">'
						+ '<span class="d5dsh-sg-number-label">' + escHtml( v.label || v.id ) + '</span>'
						+ '<span class="d5dsh-sg-number-value">' + escHtml( raw ) + '</span>';
					if ( barWidth > 0 ) {
						html += '<span class="d5dsh-sg-ruler-bar" style="width:' + barWidth + 'px"></span>';
					}
					html += '</div>';
				} );
				html += '</div>';
			} else {
				// Generic list.
				html += '<table class="d5dsh-sg-generic-table"><tbody>';
				group.forEach( function ( v ) {
					html += '<tr><td class="d5dsh-sg-generic-label">' + escHtml( v.label || v.id ) + '</td><td class="d5dsh-sg-generic-value">' + escHtml( v.value || '' ) + '</td></tr>';
				} );
				html += '</tbody></table>';
			}
		} );

		return html;
	}

	function downloadStyleGuideHtml() {
		var preview = document.getElementById( 'd5dsh-styleguide-preview' );
		if ( ! preview ) { return; }
		var innerHtml = preview.innerHTML;
		// Embed minimal CSS inline.
		var css = _sgEmbedCss();
		var doc = '<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="UTF-8">\n<meta name="viewport" content="width=device-width,initial-scale=1">\n<title>Style Guide</title>\n<style>' + css + '</style>\n</head>\n<body>\n<div class="d5dsh-styleguide-preview">' + innerHtml + '</div>\n</body>\n</html>';
		var blob = new Blob( [ doc ], { type: 'text/html' } );
		var url  = URL.createObjectURL( blob );
		var a    = document.createElement( 'a' );
		a.href   = url;
		a.download = 'style-guide.html';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		setTimeout( function () { URL.revokeObjectURL( url ); }, 5000 );
	}

	function _sgEmbedCss() {
		return '.d5dsh-sg-section-title{font-size:18px;font-weight:700;margin:24px 0 12px;padding-bottom:6px;border-bottom:2px solid #1d2327;color:#1d2327}'
			+ '.d5dsh-sg-swatch-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}'
			+ '.d5dsh-sg-swatch-card{border:1px solid #e2e4e7;border-radius:6px;padding:10px;font-size:12px}'
			+ '.d5dsh-sg-swatch-circle{width:48px;height:48px;border-radius:50%;margin-bottom:8px;border:1px solid rgba(0,0,0,.1)}'
			+ '.d5dsh-sg-swatch-label{font-weight:600;margin-bottom:2px}'
			+ '.d5dsh-sg-swatch-value{color:#3c434a}'
			+ '.d5dsh-sg-swatch-id{color:#8c8f94;font-size:11px}'
			+ '.d5dsh-sg-type-sample-row{display:flex;align-items:baseline;gap:16px;margin-bottom:8px}'
			+ '.d5dsh-sg-type-label{min-width:120px;font-size:12px;color:#646970}'
			+ '.d5dsh-sg-type-sample{font-size:16px}'
			+ '.d5dsh-sg-number-row{display:flex;align-items:center;gap:12px;margin-bottom:6px}'
			+ '.d5dsh-sg-number-label{min-width:120px;font-size:12px;color:#646970}'
			+ '.d5dsh-sg-number-value{min-width:80px;font-size:12px;font-family:monospace}'
			+ '.d5dsh-sg-ruler-bar{display:inline-block;height:12px;background:#1d2327;border-radius:2px}'
			+ '.d5dsh-sg-generic-table{width:100%;border-collapse:collapse;font-size:13px}'
			+ '.d5dsh-sg-generic-table td{padding:4px 8px;border-bottom:1px solid #f0f0f1}'
			+ '.d5dsh-sg-generic-label{font-weight:600;min-width:140px}'
			+ '.d5dsh-category-swatch{display:inline-block;width:14px;height:14px;border-radius:50%;vertical-align:middle}'
			+ '.d5dsh-sg-category-title{font-size:15px;font-weight:600;margin:20px 0 8px;display:flex;align-items:center;gap:8px}'
			+ '.d5dsh-sg-preset-table{width:100%;border-collapse:collapse;font-size:13px}'
			+ '.d5dsh-sg-preset-table th,.d5dsh-sg-preset-table td{padding:4px 8px;border:1px solid #e2e4e7}'
			+ '.d5dsh-sg-preset-table th{background:#f6f7f7;font-weight:600}';
	}

	var notesData = {};
	var activeNoteEditor = null;

	function initNotes() {
		if ( typeof d5dtNotes === 'undefined' ) { return; }
		loadNotes();
	}

	function loadNotes() {
		var fd = new FormData();
		fd.append( 'action', d5dtNotes.getAllAction );
		fd.append( 'nonce',  d5dtNotes.nonce );

		fetch( d5dtNotes.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success && json.data ) {
					notesData = json.data;
					// Re-render note indicators if tables are already shown.
					refreshNoteIndicators();
				}
			} )
			.catch( function () {} ); // fail silently — notes are non-critical
	}

	function saveNote( key, note, tags, suppress, onDone ) {
		var fd = new FormData();
		fd.append( 'action',   d5dtNotes.saveAction );
		fd.append( 'nonce',    d5dtNotes.nonce );
		fd.append( 'key',      key );
		fd.append( 'note',     note );
		fd.append( 'tags',     tags );
		suppress.forEach( function ( s ) { fd.append( 'suppress[]', s ); } );

		fetch( d5dtNotes.ajaxUrl, { method: 'POST', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( json ) {
				if ( json.success && json.data ) {
					notesData[ key ] = json.data.data;
					if ( note === '' && tags === '' && suppress.length === 0 ) {
						delete notesData[ key ];
					}
					refreshNoteIndicators();
					if ( onDone ) { onDone(); }
				}
			} )
			.catch( function () {} );
	}

	/**
	 * Return the note indicator HTML for a given entity key.
	 * Filled dot if note exists, empty ring if not.
	 */
	function noteIndicatorHTML( key ) {
		var hasNote = notesData[ key ] && (
			notesData[ key ].note ||
			( notesData[ key ].tags && notesData[ key ].tags.length ) ||
			( notesData[ key ].suppress && notesData[ key ].suppress.length )
		);
		return '<button type="button" class="d5dsh-note-btn' + ( hasNote ? ' d5dsh-note-btn-active' : '' ) + '" '
			+ 'data-note-key="' + escAttr( key ) + '" title="' + ( hasNote ? 'Edit note' : 'Add note' ) + '">'
			+ ( hasNote ? '&#9679;' : '&#9675;' )
			+ '</button>';
	}

	/**
	 * Refresh all note indicator buttons currently in the DOM without full re-render.
	 */
	function refreshNoteIndicators() {
		document.querySelectorAll( '.d5dsh-note-btn' ).forEach( function ( btn ) {
			var key     = btn.dataset.noteKey || '';
			var hasNote = notesData[ key ] && (
				notesData[ key ].note ||
				( notesData[ key ].tags && notesData[ key ].tags.length ) ||
				( notesData[ key ].suppress && notesData[ key ].suppress.length )
			);
			btn.classList.toggle( 'd5dsh-note-btn-active', !! hasNote );
			btn.innerHTML = hasNote ? '&#9679;' : '&#9675;';
			btn.title     = hasNote ? 'Edit note' : 'Add note';
		} );
	}

	/**
	 * Open the note editor panel anchored near anchorEl.
	 * key: "var:gcid-xxx" | "preset:xxx" | "post:42" | "check:name"
	 */
	function openNoteEditor( key, anchorEl ) {
		closeNoteEditor();

		var existing  = notesData[ key ] || { note: '', tags: [], suppress: [] };
		var panel     = document.createElement( 'div' );
		panel.className   = 'd5dsh-note-editor';
		panel.dataset.key = key;
		panel.addEventListener( 'click', function ( e ) { e.stopPropagation(); } );

		// ── Drag handle ──────────────────────────────────────────────────────
		var handle = document.createElement( 'div' );
		handle.className = 'd5dsh-note-editor-handle';
		var title = document.createElement( 'span' );
		title.textContent = 'Note — ' + key;
		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'd5dsh-fp-drag-close';
		closeBtn.innerHTML = '&times;';
		closeBtn.addEventListener( 'click', closeNoteEditor );
		handle.appendChild( title );
		handle.appendChild( closeBtn );
		panel.appendChild( handle );

		// Drag behaviour
		handle.addEventListener( 'mousedown', function ( e ) {
			if ( e.target === closeBtn ) { return; }
			e.preventDefault();
			var startX = e.clientX - parseInt( panel.style.left || 0, 10 );
			var startY = e.clientY - parseInt( panel.style.top  || 0, 10 );
			function onMove( mv ) {
				panel.style.left = ( mv.clientX - startX ) + 'px';
				panel.style.top  = ( mv.clientY - startY ) + 'px';
			}
			function onUp() {
				document.removeEventListener( 'mousemove', onMove );
				document.removeEventListener( 'mouseup',   onUp   );
			}
			document.addEventListener( 'mousemove', onMove );
			document.addEventListener( 'mouseup',   onUp   );
		} );

		// ── Note textarea ────────────────────────────────────────────────────
		var noteLabel = document.createElement( 'label' );
		noteLabel.className   = 'd5dsh-note-field-label';
		noteLabel.textContent = 'Note';
		var noteArea = document.createElement( 'textarea' );
		noteArea.className   = 'd5dsh-note-textarea';
		noteArea.rows        = 4;
		noteArea.value       = existing.note || '';
		noteArea.placeholder = 'Add a comment…';
		panel.appendChild( noteLabel );
		panel.appendChild( noteArea );

		// ── Tags input ───────────────────────────────────────────────────────
		var tagsLabel = document.createElement( 'label' );
		tagsLabel.className   = 'd5dsh-note-field-label';
		tagsLabel.textContent = 'Tags (comma-separated)';
		var tagsInput = document.createElement( 'input' );
		tagsInput.type        = 'text';
		tagsInput.className   = 'd5dsh-note-tags-input';
		tagsInput.value       = ( existing.tags || [] ).join( ', ' );
		tagsInput.placeholder = 'legacy, review, todo';
		panel.appendChild( tagsLabel );
		panel.appendChild( tagsInput );

		// ── Suppress checkboxes ──────────────────────────────────────────────
		var suppressLabel = document.createElement( 'div' );
		suppressLabel.className   = 'd5dsh-note-field-label';
		suppressLabel.textContent = 'Suppress audit warnings';
		panel.appendChild( suppressLabel );

		var knownChecks = [
			'broken_variable_refs',
			'archived_vars_in_presets',
			'singleton_variables',
			'near_duplicate_values',
			'hardcoded_extraction_candidates',
			'orphaned_variables',
		];
		var suppressWrap = document.createElement( 'div' );
		suppressWrap.className = 'd5dsh-note-suppress-list';
		var checkboxes = [];
		knownChecks.forEach( function ( checkName ) {
			var row = document.createElement( 'label' );
			row.className = 'd5dsh-note-suppress-row';
			var cb = document.createElement( 'input' );
			cb.type    = 'checkbox';
			cb.value   = checkName;
			cb.checked = ( existing.suppress || [] ).indexOf( checkName ) !== -1;
			checkboxes.push( cb );
			var lbl = document.createTextNode( ' ' + checkName.replace( /_/g, ' ' ) );
			row.appendChild( cb );
			row.appendChild( lbl );
			suppressWrap.appendChild( row );
		} );
		panel.appendChild( suppressWrap );

		// ── Action buttons ───────────────────────────────────────────────────
		var btnRow = document.createElement( 'div' );
		btnRow.className = 'd5dsh-note-btn-row';

		var saveBtn = document.createElement( 'button' );
		saveBtn.type      = 'button';
		saveBtn.className = 'button button-primary button-small';
		saveBtn.textContent = 'Save';
		saveBtn.addEventListener( 'click', function () {
			var suppress = checkboxes.filter( function ( cb ) { return cb.checked; } )
			                         .map( function ( cb ) { return cb.value; } );
			saveNote( key, noteArea.value.trim(), tagsInput.value.trim(), suppress, function () {
				closeNoteEditor();
			} );
		} );

		var clearBtn = document.createElement( 'button' );
		clearBtn.type      = 'button';
		clearBtn.className = 'button button-small';
		clearBtn.textContent = 'Clear & Delete';
		clearBtn.addEventListener( 'click', function () {
			saveNote( key, '', '', [], function () { closeNoteEditor(); } );
		} );

		btnRow.appendChild( saveBtn );
		btnRow.appendChild( clearBtn );
		panel.appendChild( btnRow );

		// ── Position ─────────────────────────────────────────────────────────
		var rect = anchorEl.getBoundingClientRect();
		panel.style.position = 'fixed';
		panel.style.top      = rect.bottom + 4 + 'px';
		panel.style.left     = rect.left + 'px';
		panel.style.zIndex   = '99999';
		document.body.appendChild( panel );
		activeNoteEditor = panel;

		// Close on outside click
		setTimeout( function () {
			document.addEventListener( 'click', closeNoteEditorOnOutside );
		}, 0 );
	}

	function closeNoteEditorOnOutside( e ) {
		if ( activeNoteEditor && ! activeNoteEditor.contains( e.target ) ) {
			closeNoteEditor();
		}
	}

	function closeNoteEditor() {
		if ( activeNoteEditor ) {
			document.removeEventListener( 'click', closeNoteEditorOnOutside );
			activeNoteEditor.parentNode && activeNoteEditor.parentNode.removeChild( activeNoteEditor );
			activeNoteEditor = null;
		}
	}

	/**
	 * Global click delegation for note buttons throughout the page.
	 */
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.d5dsh-note-btn' );
		if ( ! btn ) { return; }
		var key = btn.dataset.noteKey;
		if ( ! key ) { return; }
		if ( activeNoteEditor && activeNoteEditor.dataset.key === key ) {
			closeNoteEditor();
		} else {
			openNoteEditor( key, btn );
		}
	} );

	// Expose shared utilities for use by external table modules.
	window.d5dshOpenFilterPanel    = openFilterPanel;
	window.d5dshOpenNoteEditor     = openNoteEditor;
	window.d5dshNoteIndicatorHTML  = noteIndicatorHTML;
	window.d5dshAbbreviateModule   = abbreviateModule;
	window.d5dshAbbreviateGroupId  = abbreviateGroupId;
	window.d5dshOpenImpactModal    = openImpactModal;
	window.d5dshHandlePresetsNameChange = handlePresetsNameChange;

	// Expose manage-tab state so external table modules can read/write live data.
	window.d5dshManageState = {
		getManageData:        function () { return manageData; },
		setChangeSource:      function ( src, detail ) { setChangeSource( src, detail ); },
		updateSaveBar:        function () { updateSaveBar(); },
		updateDupeCount:      function ( dupes ) { updateDupeCount( dupes ); },
		getDupeLabels:        function () { return currentDupeLabels; },
		getCategoryMap:       function () { return ( typeof categoryMap   !== 'undefined' ) ? categoryMap   : {}; },
		getCategoriesData:    function () { return ( typeof categoriesData !== 'undefined' ) ? categoriesData : []; },
	};

	// ╔══════════════════════════════════════════════════════════════════════╗
	// ║ SECTION 22 — SECURITY TESTING PANEL                                  ║
	// ╚══════════════════════════════════════════════════════════════════════╝

	function initSecurityTest() {
		var panel = document.getElementById( 'd5dsh-sectest-panel' );
		if ( ! panel ) { return; }

		var runBtn      = document.getElementById( 'd5dsh-sectest-run-btn' );
		var dirInput    = document.getElementById( 'd5dsh-sectest-dir' );
		var fileInput   = document.getElementById( 'd5dsh-sectest-files' );
		var verboseChk  = document.getElementById( 'd5dsh-sectest-verbose' );
		var resultsWrap = document.getElementById( 'd5dsh-sectest-results' );
		var summaryEl   = document.getElementById( 'd5dsh-sectest-summary' );
		var tbodyEl     = document.getElementById( 'd5dsh-sectest-tbody' );
		var statusEl    = document.getElementById( 'd5dsh-sectest-status' );
		var spinner     = document.getElementById( 'd5dsh-sectest-spinner' );
		var dlBtn       = document.getElementById( 'd5dsh-sectest-download-btn' );
		var reportPath  = document.getElementById( 'd5dsh-sectest-report-path' );

		if ( ! runBtn ) { return; }

		var _lastReport = null;

		// Wire download button.
		if ( dlBtn ) {
			dlBtn.addEventListener( 'click', function () {
				if ( ! _lastReport ) { return; }
				var blob = new Blob( [ JSON.stringify( _lastReport, null, 2 ) ], { type: 'application/json' } );
				var a    = document.createElement( 'a' );
				a.href     = URL.createObjectURL( blob );
				a.download = 'security-test-report.json';
				a.click();
				URL.revokeObjectURL( a.href );
			} );
		}

		runBtn.addEventListener( 'click', function () {
			var dir     = dirInput  ? dirInput.value.trim() : '';
			var verbose = verboseChk ? verboseChk.checked   : false;
			var files   = fileInput  ? fileInput.files      : null;

			if ( ! dir && ( ! files || files.length === 0 ) ) {
				alert( 'Please enter a server directory path or select fixture files to upload.' );
				return;
			}

			var fd = new FormData();
			fd.append( 'action', 'd5dsh_security_test' );
			fd.append( 'nonce',  ( window.d5dtSecTest && d5dtSecTest.nonce ) ? d5dtSecTest.nonce : '' );
			fd.append( 'verbose', verbose ? '1' : '0' );

			if ( files && files.length > 0 ) {
				for ( var i = 0; i < files.length; i++ ) {
					fd.append( 'files[]', files[ i ] );
				}
			} else {
				fd.append( 'dir', dir );
			}

			runBtn.disabled = true;
			runBtn.textContent = 'Running…';
			if ( spinner )   { spinner.style.display = ''; }
			if ( statusEl )  { statusEl.textContent  = ''; }
			if ( resultsWrap ) { resultsWrap.style.display = 'none'; }

			fetch( ( window.d5dtSecTest && d5dtSecTest.ajaxUrl ) ? d5dtSecTest.ajaxUrl : ajaxurl, {
				method:      'POST',
				credentials: 'same-origin',
				body:        fd,
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					if ( ! resp.success ) {
						if ( statusEl ) {
							var errMsg = ( resp.data && resp.data.message ) ? resp.data.message : ( resp.data || 'Request failed.' );
							statusEl.textContent = errMsg;
							statusEl.style.color = '#d63638';
						}
						return;
					}
					_lastReport = resp.data;
					renderSecTestReport( resp.data, summaryEl, tbodyEl, reportPath );
					if ( resultsWrap ) { resultsWrap.style.display = ''; }
				} )
				.catch( function ( err ) {
					if ( statusEl ) {
						statusEl.textContent = 'Network error: ' + ( err.message || String( err ) );
						statusEl.style.color = '#d63638';
					}
				} )
				.finally( function () {
					runBtn.disabled = false;
					runBtn.textContent = 'Run Security Tests';
					if ( spinner ) { spinner.style.display = 'none'; }
				} );
		} );
	}

	/**
	 * Populate the pre-existing security test result elements.
	 *
	 * @param {Object}  report     Response data from d5dsh_security_test.
	 * @param {Element} summaryEl  #d5dsh-sectest-summary element.
	 * @param {Element} tbodyEl    #d5dsh-sectest-tbody element.
	 * @param {Element} reportPath #d5dsh-sectest-report-path element.
	 */
	function renderSecTestReport( report, summaryEl, tbodyEl, reportPath ) {
		var meta    = report.meta    || {};
		var summary = report.summary || {};
		var results = report.results || [];

		var passCount = summary.pass            || 0;
		var failCount = summary.fail            || 0;
		var total     = summary.total           || 0;
		var sanCount  = summary.total_sanitized || 0;

		// Summary bar.
		if ( summaryEl ) {
			summaryEl.className = 'd5dsh-sectest-summary ' + ( failCount > 0 ? 'd5dsh-sectest-summary-fail' : 'd5dsh-sectest-summary-pass' );
			var sumHtml = '<strong>' + total + ' fixture' + ( total !== 1 ? 's' : '' ) + '</strong> — ';
			sumHtml += '<span class="d5dsh-sectest-pass">' + passCount + ' pass</span> / ';
			sumHtml += '<span class="d5dsh-sectest-fail">' + failCount + ' fail</span>';
			if ( sanCount > 0 ) {
				sumHtml += ' / <span class="d5dsh-sectest-san">' + sanCount + ' field' + ( sanCount !== 1 ? 's' : '' ) + ' sanitized</span>';
			}
			sumHtml += ' <span class="d5dsh-sectest-meta">— WP ' + escHtml( meta.wp_version || '' ) + ' · PHP ' + escHtml( meta.php_version || '' ) + '</span>';
			summaryEl.innerHTML = sumHtml;
		}

		// Report path label.
		if ( reportPath && report._report_file ) {
			reportPath.textContent = report._report_file;
		}

		// Populate tbody.
		if ( tbodyEl ) {
			var tbodyHtml = '';
			results.forEach( function ( r ) {
				var statusClass = r.status === 'ok' ? 'd5dsh-sectest-ok'
					: ( r.status === 'no_handler' ? 'd5dsh-sectest-warn' : 'd5dsh-sectest-fail' );
				var statusLabel = r.status === 'ok' ? 'PASS'
					: ( r.status === 'no_handler' ? 'WARN' : 'FAIL' );
				var sanLen = r.sanitization_log ? r.sanitization_log.length : 0;

				tbodyHtml += '<tr>';
				tbodyHtml += '<td><code class="d5dsh-sectest-filename">' + escHtml( r.file || '' ) + '</code></td>';
				tbodyHtml += '<td><span class="d5dsh-sectest-badge ' + statusClass + '">' + statusLabel + '</span></td>';
				tbodyHtml += '<td>' + escHtml( r.detected_type || '—' ) + '</td>';
				tbodyHtml += '<td>' + ( r.new || 0 ) + '</td>';
				tbodyHtml += '<td>' + ( r.updated || 0 ) + '</td>';
				tbodyHtml += '<td>' + sanLen + '</td>';
				tbodyHtml += '<td>' + escHtml( r.message || '' ) + '</td>';
				tbodyHtml += '</tr>';

				if ( sanLen > 0 ) {
					tbodyHtml += '<tr class="d5dsh-sectest-san-row"><td colspan="7">';
					tbodyHtml += '<details><summary class="d5dsh-sectest-san-summary">&#9888; ' + sanLen + ' field' + ( sanLen !== 1 ? 's' : '' ) + ' sanitized — click to review</summary>';
					tbodyHtml += '<div class="d5dsh-san-cards-inline">';
					r.sanitization_log.forEach( function ( entry ) {
						var outcome      = entry.outcome || 'partial';
						var outcomeClass = 'd5dsh-san-card--' + outcome;
						var storedAs     = ( entry.sanitized && entry.sanitized !== '' ) ? entry.sanitized : '(empty — value removed)';
						var refUrl       = entry.reference_url || '';
						tbodyHtml += '<div class="d5dsh-san-card d5dsh-san-card--compact ' + escHtml( outcomeClass ) + '">';
						tbodyHtml += '<div class="d5dsh-san-card-head">';
						tbodyHtml += '<span class="d5dsh-san-card-location">' + escHtml( entry.context || '' ) + ' &rsaquo; <em>' + escHtml( entry.field || '' ) + '</em></span>';
						tbodyHtml += '<span class="d5dsh-san-card-badge d5dsh-san-badge--' + escHtml( outcome ) + '">' + escHtml( outcome.charAt(0).toUpperCase() + outcome.slice(1) ) + '</span>';
						tbodyHtml += '</div>';
						tbodyHtml += '<div class="d5dsh-san-card-body">';
						tbodyHtml += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Found</span><span class="d5dsh-san-value"><code class="d5dsh-san-code">' + escHtml( ( entry.original || '' ).substring( 0, 200 ) ) + '</code></span></div>';
						tbodyHtml += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Why</span><span class="d5dsh-san-value">' + escHtml( entry.threat_summary || '' );
						if ( refUrl ) {
							tbodyHtml += ' <a class="d5dsh-san-learn-more" href="' + escHtml( refUrl ) + '" target="_blank" rel="noopener">Learn&nbsp;more &#8599;</a>';
						}
						tbodyHtml += '</span></div>';
						tbodyHtml += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Action</span><span class="d5dsh-san-value">' + escHtml( entry.outcome_detail || '' ) + '</span></div>';
						tbodyHtml += '<div class="d5dsh-san-row"><span class="d5dsh-san-label">Stored as</span><span class="d5dsh-san-value"><code class="d5dsh-san-code d5dsh-san-code--stored">' + escHtml( storedAs.substring( 0, 200 ) ) + '</code></span></div>';
						tbodyHtml += '</div></div>';
					} );
					tbodyHtml += '</div></details>';
					tbodyHtml += '</td></tr>';
				}
			} );
			tbodyEl.innerHTML = tbodyHtml;
		}
	}

} )();
