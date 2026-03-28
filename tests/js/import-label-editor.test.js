/**
 * JS unit tests for the import label editor helpers added in v0.6.19:
 *   - siMakeBulkTransform  (prefix, suffix, find_replace, normalize)
 *   - siNormalizeCase      (title, upper, lower, snake, camel)
 *   - siOnLabelChange      (state management logic — tested standalone)
 *   - siBuildLabelEditor   (DOM structure)
 *
 * Uses Node.js built-in test runner (node:test) + assert — no npm install needed.
 * Requires Node 18+.  Run with:
 *
 *   node --test tests/js/import-label-editor.test.js
 *
 * Strategy:
 *   Pure transform helpers are re-implemented here (extracted from admin.js).
 *   siBuildLabelEditor is tested with a minimal DOM stub using jsdom-style
 *   document.createElement shims.
 */

'use strict';

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

// ── Helpers extracted from admin.js ───────────────────────────────────────────

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

/** Simplified siOnLabelChange state logic (extracted from admin.js). */
function makeOverrideStore( manifest ) {
	const overrides = {};
	function onLabelChange( fileKey, id, newLabel ) {
		if ( ! overrides[ fileKey ] ) { overrides[ fileKey ] = {}; }
		// Find original label from manifest items.
		let orig = '';
		( manifest.files || [] ).forEach( function ( f ) {
			if ( f.key === fileKey ) {
				( f.items || [] ).forEach( function ( item ) {
					if ( item.id === id ) { orig = item.label; }
				} );
			}
		} );
		if ( newLabel === orig ) {
			delete overrides[ fileKey ][ id ];
		} else {
			overrides[ fileKey ][ id ] = newLabel;
		}
	}
	return { overrides, onLabelChange };
}

// ── siNormalizeCase ────────────────────────────────────────────────────────────

describe( 'siNormalizeCase', function () {

	test( 'upper converts to UPPER CASE', function () {
		assert.equal( siNormalizeCase( 'hello world', 'upper' ), 'HELLO WORLD' );
	} );

	test( 'lower converts to lower case', function () {
		assert.equal( siNormalizeCase( 'HELLO WORLD', 'lower' ), 'hello world' );
	} );

	test( 'title capitalises each word', function () {
		assert.equal( siNormalizeCase( 'hello world', 'title' ), 'Hello World' );
	} );

	test( 'snake converts spaces and hyphens to underscores', function () {
		assert.equal( siNormalizeCase( 'line spacing base', 'snake' ), 'line_spacing_base' );
		assert.equal( siNormalizeCase( 'font-size-xl', 'snake' ), 'font_size_xl' );
	} );

	test( 'snake strips special characters', function () {
		assert.equal( siNormalizeCase( 'color (primary)!', 'snake' ), 'color_primary' );
	} );

	test( 'camel converts space-separated words', function () {
		assert.equal( siNormalizeCase( 'line spacing base', 'camel' ), 'lineSpacingBase' );
	} );

	test( 'camel converts hyphen-separated words', function () {
		assert.equal( siNormalizeCase( 'font-size-xl', 'camel' ), 'fontSizeXl' );
	} );

	test( 'unknown caseType returns label unchanged', function () {
		assert.equal( siNormalizeCase( 'hello', 'unknown' ), 'hello' );
	} );

} );

// ── siMakeBulkTransform ────────────────────────────────────────────────────────

describe( 'siMakeBulkTransform', function () {

	test( 'prefix prepends value to label', function () {
		const fn = siMakeBulkTransform( 'prefix', 'ls_', '', '' );
		assert.equal( fn( 'base' ), 'ls_base' );
		assert.equal( fn( 'heading' ), 'ls_heading' );
	} );

	test( 'prefix with empty string leaves label unchanged', function () {
		const fn = siMakeBulkTransform( 'prefix', '', '', '' );
		assert.equal( fn( 'base' ), 'base' );
	} );

	test( 'suffix appends value to label', function () {
		const fn = siMakeBulkTransform( 'suffix', '_v2', '', '' );
		assert.equal( fn( 'Primary Color' ), 'Primary Color_v2' );
	} );

	test( 'find_replace substitutes all occurrences', function () {
		const fn = siMakeBulkTransform( 'find_replace', 'Color', 'Colour', '' );
		assert.equal( fn( 'Primary Color and Secondary Color' ), 'Primary Colour and Secondary Colour' );
	} );

	test( 'find_replace with empty replace removes find string', function () {
		const fn = siMakeBulkTransform( 'find_replace', 'OLD ', '', '' );
		assert.equal( fn( 'OLD label' ), 'label' );
	} );

	test( 'find_replace returns null when valA is empty', function () {
		const fn = siMakeBulkTransform( 'find_replace', '', 'anything', '' );
		assert.equal( fn, null );
	} );

	test( 'normalize delegates to siNormalizeCase', function () {
		const fn = siMakeBulkTransform( 'normalize', '', '', 'upper' );
		assert.equal( fn( 'hello' ), 'HELLO' );
	} );

	test( 'unknown op returns null', function () {
		const fn = siMakeBulkTransform( 'bogus', '', '', '' );
		assert.equal( fn, null );
	} );

	test( 'empty op returns null', function () {
		const fn = siMakeBulkTransform( '', '', '', '' );
		assert.equal( fn, null );
	} );

} );

// ── siOnLabelChange state logic ────────────────────────────────────────────────

describe( 'siOnLabelChange state logic', function () {

	const manifest = {
		files: [ {
			key:   'vars.json',
			items: [
				{ id: 'gcid-a', label: 'Primary',   type: 'Color'  },
				{ id: 'gvid-b', label: 'Base Size',  type: 'Number' },
			],
		} ],
	};

	test( 'stores new label in overrides', function () {
		const { overrides, onLabelChange } = makeOverrideStore( manifest );
		onLabelChange( 'vars.json', 'gcid-a', 'Brand Red' );
		assert.equal( overrides['vars.json']['gcid-a'], 'Brand Red' );
	} );

	test( 'removes entry when label reverts to original', function () {
		const { overrides, onLabelChange } = makeOverrideStore( manifest );
		onLabelChange( 'vars.json', 'gcid-a', 'Brand Red' );
		assert.ok( 'gcid-a' in overrides['vars.json'] );
		// Revert to original.
		onLabelChange( 'vars.json', 'gcid-a', 'Primary' );
		assert.ok( ! ( 'gcid-a' in overrides['vars.json'] ) );
	} );

	test( 'separate files have independent override maps', function () {
		const m2 = {
			files: [
				{ key: 'a.json', items: [ { id: 'gcid-x', label: 'X', type: 'Color' } ] },
				{ key: 'b.json', items: [ { id: 'gcid-x', label: 'X', type: 'Color' } ] },
			],
		};
		const { overrides, onLabelChange } = makeOverrideStore( m2 );
		onLabelChange( 'a.json', 'gcid-x', 'Changed in A' );
		assert.equal( overrides['a.json']['gcid-x'], 'Changed in A' );
		assert.ok( ! overrides['b.json'] || ! overrides['b.json']['gcid-x'] );
	} );

	test( 'changing multiple IDs in one file are all recorded', function () {
		const { overrides, onLabelChange } = makeOverrideStore( manifest );
		onLabelChange( 'vars.json', 'gcid-a', 'New Primary' );
		onLabelChange( 'vars.json', 'gvid-b', 'New Size' );
		assert.equal( overrides['vars.json']['gcid-a'], 'New Primary' );
		assert.equal( overrides['vars.json']['gvid-b'], 'New Size' );
	} );

} );

// ── siBuildLabelEditor DOM structure (minimal DOM stub) ───────────────────────

describe( 'siBuildLabelEditor DOM structure', function () {

	// ── Minimal DOM environment ───────────────────────────────────────────────

	class FakeEl {
		constructor( tag ) {
			this.tagName     = tag.toUpperCase();
			this.className   = '';
			this.innerHTML   = '';
			this.textContent = '';
			this.hidden      = false;
			this.type        = '';
			this.value       = '';
			this.dataset     = {};
			this.style       = {};
			this.children    = [];
			this._listeners  = {};
			this._attrs      = {};
		}
		appendChild( child ) { this.children.push( child ); return child; }
		setAttribute( k, v ) { this._attrs[ k ] = v; }
		getAttribute( k ) { return this._attrs[ k ] ?? null; }
		querySelector( sel ) {
			// Very naive: only supports class selector on direct children.
			const cls = sel.startsWith( '.' ) ? sel.slice( 1 ) : null;
			for ( const c of this.children ) {
				if ( cls && c.className && c.className.split( ' ' ).includes( cls ) ) { return c; }
			}
			return null;
		}
		querySelectorAll( sel ) {
			const results = [];
			const cls = sel.startsWith( '.' ) ? sel.slice( 1 ) : null;
			const walk = ( el ) => {
				if ( cls && el.className && el.className.split( ' ' ).includes( cls ) ) {
					results.push( el );
				}
				for ( const c of el.children ) { walk( c ); }
			};
			for ( const c of this.children ) { walk( c ); }
			return results;
		}
		addEventListener( ev, fn ) {
			if ( ! this._listeners[ ev ] ) { this._listeners[ ev ] = []; }
			this._listeners[ ev ].push( fn );
		}
		removeAttribute() {}
	}

	const document = {
		createElement: ( tag ) => new FakeEl( tag ),
	};

	// ── Paste the function under test ─────────────────────────────────────────

	function siEscape( str ) {
		return String( str )
			.replace( /&/g,  '&amp;'  )
			.replace( /</g,  '&lt;'   )
			.replace( />/g,  '&gt;'   )
			.replace( /"/g,  '&quot;' )
			.replace( /'/g,  '&#039;' );
	}

	// siOnLabelChange stub (just records calls).
	const labelChangeCalls = [];
	function siOnLabelChange( fileKey, id, newLabel ) {
		labelChangeCalls.push( { fileKey, id, newLabel } );
	}

	function siBuildLabelEditor( fi, cardId ) {
		if ( ! fi.items || ! fi.items.length ) { return null; }
		const fileKey = fi.key;
		const details = document.createElement( 'details' );
		details.className = 'd5dsh-si-label-editor';
		const summary = document.createElement( 'summary' );
		summary.className = 'd5dsh-si-label-editor-summary';
		summary.innerHTML =
			'Edit Labels <span class="d5dsh-si-label-badge">' + fi.items.length + ' item' +
			( fi.items.length !== 1 ? 's' : '' ) + '</span>';
		details.appendChild( summary );
		const inner = document.createElement( 'div' );
		inner.className = 'd5dsh-si-label-editor-inner';
		const bar = document.createElement( 'div' );
		bar.className = 'd5dsh-si-label-bulk-bar';
		bar.innerHTML =
			'<select class="d5dsh-si-bulk-op"></select>' +
			'<input type="text" class="d5dsh-si-bulk-a regular-text" style="display:none">' +
			'<input type="text" class="d5dsh-si-bulk-b regular-text" style="display:none">' +
			'<select class="d5dsh-si-bulk-case" style="display:none"></select>' +
			'<button type="button" class="button d5dsh-si-bulk-apply-btn">Apply</button>' +
			'<button type="button" class="button d5dsh-si-bulk-reset-btn">Reset All</button>';
		inner.appendChild( bar );
		const table = document.createElement( 'table' );
		table.className = 'd5dsh-si-label-table widefat striped';
		const tbody = document.createElement( 'tbody' );
		fi.items.forEach( function ( item ) {
			const tr     = document.createElement( 'tr' );
			const tdType = document.createElement( 'td' );
			const tdId   = document.createElement( 'td' );
			const tdLabel = document.createElement( 'td' );
			tdType.textContent = item.type || '';
			const input = document.createElement( 'input' );
			input.type         = 'text';
			input.className    = 'd5dsh-si-label-input';
			input.value        = item.label;
			input.dataset.orig = item.label;
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
		return details;
	}

	// ── Tests ─────────────────────────────────────────────────────────────────

	test( 'returns null when fi has no items', function () {
		const result = siBuildLabelEditor( { key: 'x.json', items: [] }, 'card1' );
		assert.equal( result, null );
	} );

	test( 'returns null when fi.items is absent', function () {
		const result = siBuildLabelEditor( { key: 'x.json' }, 'card1' );
		assert.equal( result, null );
	} );

	test( 'returns a details element with class d5dsh-si-label-editor', function () {
		const fi = {
			key:   'vars.json',
			items: [ { id: 'gcid-a', label: 'Primary', type: 'Color' } ],
		};
		const el = siBuildLabelEditor( fi, 'card1' );
		assert.ok( el );
		assert.ok( el.className.includes( 'd5dsh-si-label-editor' ) );
	} );

	test( 'summary shows correct item count (singular)', function () {
		const fi = {
			key:   'vars.json',
			items: [ { id: 'gcid-a', label: 'Primary', type: 'Color' } ],
		};
		const el = siBuildLabelEditor( fi, 'card1' );
		assert.ok( el.children[0].innerHTML.includes( '1 item<' ) );
	} );

	test( 'summary shows correct item count (plural)', function () {
		const fi = {
			key:   'vars.json',
			items: [
				{ id: 'gcid-a', label: 'A', type: 'Color' },
				{ id: 'gcid-b', label: 'B', type: 'Color' },
			],
		};
		const el = siBuildLabelEditor( fi, 'card1' );
		assert.ok( el.children[0].innerHTML.includes( '2 items<' ) );
	} );

	test( 'creates one input per item with correct initial value', function () {
		const fi = {
			key:   'vars.json',
			items: [
				{ id: 'gcid-a', label: 'Primary',   type: 'Color'  },
				{ id: 'gvid-b', label: 'Base Size',  type: 'Number' },
			],
		};
		const el = siBuildLabelEditor( fi, 'card1' );
		const inputs = el.querySelectorAll( '.d5dsh-si-label-input' );
		assert.equal( inputs.length, 2 );
		assert.equal( inputs[0].value, 'Primary' );
		assert.equal( inputs[1].value, 'Base Size' );
	} );

	test( 'input dataset.orig stores the original label', function () {
		const fi = {
			key:   'vars.json',
			items: [ { id: 'gcid-a', label: 'Primary', type: 'Color' } ],
		};
		const el     = siBuildLabelEditor( fi, 'card1' );
		const inputs = el.querySelectorAll( '.d5dsh-si-label-input' );
		assert.equal( inputs[0].dataset.orig, 'Primary' );
		assert.equal( inputs[0].dataset.id,   'gcid-a' );
	} );

	test( 'input event listener is registered on each input', function () {
		const fi = {
			key:   'vars.json',
			items: [ { id: 'gcid-a', label: 'Primary', type: 'Color' } ],
		};
		const el     = siBuildLabelEditor( fi, 'card1' );
		const inputs = el.querySelectorAll( '.d5dsh-si-label-input' );
		assert.ok( inputs[0]._listeners['input'] && inputs[0]._listeners['input'].length > 0 );
	} );

} );
