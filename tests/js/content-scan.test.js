/**
 * JS unit tests for the ContentScanner rendering functions.
 *
 * Uses Node.js built-in test runner (node:test) + assert — no npm install needed.
 * Requires Node 18+.  Run with:
 *
 *   node --test tests/js/content-scan.test.js
 *
 * Strategy:
 *   The rendering functions live inside an IIFE in admin.js and reference the
 *   DOM (document.getElementById) and escHtml().  Rather than loading the full
 *   admin.js bundle (which would need a browser or jsdom), this file re-implements
 *   only the pure rendering logic under test — the string-builder helpers that
 *   have no side-effects:
 *
 *     contentScanRowTable( rows )
 *     contentScanInventoryRow( row, typeOverride )
 *     contentScanDsoTable( heading, map )
 *
 *   The DOM-writing functions (contentScanRenderMeta, contentScanRenderActiveReport,
 *   contentScanRenderInventory, contentScanRenderDsoUsage) are tested by providing
 *   a minimal DOM stub and asserting on the innerHTML that was written.
 *
 * If the output format changes (after reviewing the smoke-test results) update
 * the assertions here to match the new format before shipping.
 */

'use strict';

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

// ── Minimal escHtml (mirrors the one in admin.js) ─────────────────────────────

function escHtml( text ) {
	return String( text )
		.replace( /&/g,  '&amp;'  )
		.replace( /</g,  '&lt;'   )
		.replace( />/g,  '&gt;'   )
		.replace( /"/g,  '&quot;' )
		.replace( /'/g,  '&#039;' );
}

// ── Pure rendering helpers (copied verbatim from admin.js Section 19) ─────────
// These are the functions under test.  If admin.js changes, update here too.

function contentScanRowTable( rows ) {
	if ( ! rows || ! rows.length ) return '';

	let html = '<table class="d5dsh-scan-table widefat striped">'
		+ '<thead><tr>'
		+ '<th>ID</th><th>Title</th><th>Status</th><th>Modified</th><th>Vars</th><th>Presets</th>'
		+ '</tr></thead><tbody>';

	rows.forEach( function ( row ) {
		html += '<tr>'
			+ '<td>' + escHtml( String( row.post_id || '' ) ) + '</td>'
			+ '<td>' + escHtml( row.post_title || '(no title)' ) + '</td>'
			+ '<td><span class="d5dsh-scan-status d5dsh-scan-status-' + escHtml( row.post_status || '' ) + '">'
			+   escHtml( row.post_status || '' ) + '</span></td>'
			+ '<td>' + escHtml( ( row.post_modified || '' ).substring( 0, 10 ) ) + '</td>'
			+ '<td>' + escHtml( String( row.var_refs ? row.var_refs.length : 0 ) ) + '</td>'
			+ '<td>' + escHtml( String( row.preset_refs ? row.preset_refs.length : 0 ) ) + '</td>'
			+ '</tr>';
	} );

	html += '</tbody></table>';
	return html;
}

function contentScanInventoryRow( row, typeOverride ) {
	const dsoCount = ( row.var_refs ? row.var_refs.length : 0 )
		+ ( row.preset_refs ? row.preset_refs.length : 0 );
	const indent   = typeOverride ? ' d5dsh-scan-canvas-row' : '';
	const typeCell = typeOverride
		? '<em>' + escHtml( typeOverride ) + '</em>'
		: escHtml( row.post_type || '' );

	return '<tr class="' + indent + '">'
		+ '<td>' + escHtml( String( row.post_id || '' ) ) + '</td>'
		+ '<td>' + escHtml( row.post_title || '(no title)' ) + '</td>'
		+ '<td>' + typeCell + '</td>'
		+ '<td><span class="d5dsh-scan-status d5dsh-scan-status-' + escHtml( row.post_status || '' ) + '">'
		+   escHtml( row.post_status || '' ) + '</span></td>'
		+ '<td>' + escHtml( ( row.post_modified || '' ).substring( 0, 10 ) ) + '</td>'
		+ '<td>' + ( dsoCount > 0 ? '<span class="d5dsh-audit-badge">' + dsoCount + '</span>' : '&mdash;' ) + '</td>'
		+ '</tr>';
}

function contentScanDsoTable( heading, map ) {
	const keys = Object.keys( map );
	if ( ! keys.length ) return '';

	let html = '<h4 class="d5dsh-scan-type-heading">'
		+ escHtml( heading ) + ' <span class="d5dsh-audit-badge">' + keys.length + '</span>'
		+ '</h4>'
		+ '<table class="d5dsh-scan-table widefat striped">'
		+ '<thead><tr><th>DSO ID</th><th>Used by</th><th>Content items</th></tr></thead>'
		+ '<tbody>';

	keys.forEach( function ( id ) {
		const entry  = map[ id ];
		const posts  = entry.posts || [];
		const titles = posts.map( function ( p ) {
			return escHtml( p.post_title || String( p.post_id ) || '' );
		} ).join( ', ' );

		html += '<tr>'
			+ '<td><code>' + escHtml( id ) + '</code></td>'
			+ '<td><span class="d5dsh-audit-badge">' + escHtml( String( entry.count || 0 ) ) + '</span></td>'
			+ '<td class="d5dsh-scan-usage-titles">' + titles + '</td>'
			+ '</tr>';
	} );

	html += '</tbody></table>';
	return html;
}

// ── Minimal DOM stub for the render* functions ────────────────────────────────

/**
 * A minimal document.getElementById stub.
 * Returns a fake element that records what was written to innerHTML / textContent.
 */
function makeDom( ids ) {
	const elements = {};
	for ( const id of ids ) {
		elements[ id ] = { innerHTML: '', textContent: '', style: { display: '' } };
	}
	return {
		getElementById: ( id ) => elements[ id ] || null,
		elements,
	};
}

// ── DOM-writing functions (also copied verbatim from admin.js Section 19) ─────

function contentScanRenderMeta( meta, dom ) {
	const el = dom.getElementById( 'd5dsh-scan-meta' );
	if ( ! el || ! meta ) return;

	let html = 'Scan run: ' + escHtml( meta.ran_at || '' )
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

function contentScanRenderActiveReport( active, dom ) {
	const badge = dom.getElementById( 'd5dsh-scan-active-badge' );
	const body  = dom.getElementById( 'd5dsh-scan-active-body' );
	if ( ! body ) return;

	const total = ( active && active.total ) ? active.total : 0;
	if ( badge ) badge.textContent = String( total );

	if ( total === 0 ) {
		body.innerHTML = '<p class="d5dsh-audit-clean">No content references any DSO.</p>';
		return;
	}

	const byType = active.by_type || {};
	let html = '';

	Object.keys( byType ).forEach( function ( postType ) {
		const rows = byType[ postType ];
		html += '<h4 class="d5dsh-scan-type-heading">'
			+ escHtml( postType ) + ' <span class="d5dsh-audit-badge">' + rows.length + '</span>'
			+ '</h4>'
			+ contentScanRowTable( rows );
	} );

	body.innerHTML = html;
}

function contentScanRenderInventory( inventory, dom ) {
	const badge = dom.getElementById( 'd5dsh-scan-inventory-badge' );
	const body  = dom.getElementById( 'd5dsh-scan-inventory-body' );
	if ( ! body ) return;

	const rows  = ( inventory && inventory.rows ) ? inventory.rows : [];
	const total = ( inventory && inventory.total ) ? inventory.total : 0;
	if ( badge ) badge.textContent = String( total );

	if ( rows.length === 0 ) {
		body.innerHTML = '<p class="d5dsh-audit-clean">No Divi content found.</p>';
		return;
	}

	let html = '<table class="d5dsh-scan-table widefat striped">'
		+ '<thead><tr>'
		+ '<th>ID</th><th>Title</th><th>Type</th><th>Status</th><th>Modified</th><th>DSOs</th>'
		+ '</tr></thead><tbody>';

	rows.forEach( function ( row ) {
		html += contentScanInventoryRow( row, '' );
		if ( row.canvases && row.canvases.length ) {
			row.canvases.forEach( function ( canvas ) {
				html += contentScanInventoryRow( canvas, canvas.canvas_label + ' canvas' );
			} );
		}
	} );

	html += '</tbody></table>';
	body.innerHTML = html;
}

function contentScanRenderDsoUsage( dsoUsage, dom ) {
	const badge = dom.getElementById( 'd5dsh-scan-dso-badge' );
	const body  = dom.getElementById( 'd5dsh-scan-dso-body' );
	if ( ! body ) return;

	const vars    = ( dsoUsage && dsoUsage.variables ) ? dsoUsage.variables : {};
	const presets = ( dsoUsage && dsoUsage.presets )   ? dsoUsage.presets   : {};
	const total   = Object.keys( vars ).length + Object.keys( presets ).length;
	if ( badge ) badge.textContent = String( total );

	if ( total === 0 ) {
		body.innerHTML = '<p class="d5dsh-audit-clean">No DSO references found in content.</p>';
		return;
	}

	body.innerHTML = contentScanDsoTable( 'Variables', vars )
		+ contentScanDsoTable( 'Presets', presets );
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

const SLIM_ROW = {
	post_id:       42,
	post_type:     'page',
	post_status:   'publish',
	post_title:    'My Page',
	post_modified: '2025-06-01 10:00:00',
	has_dso:       true,
	var_refs:      [ { type: 'color', name: 'gcid-primary' } ],
	preset_refs:   [],
};

const SLIM_ROW_NO_DSO = {
	post_id:       7,
	post_type:     'post',
	post_status:   'draft',
	post_title:    'Draft Post',
	post_modified: '2025-01-15 08:00:00',
	has_dso:       false,
	var_refs:      [],
	preset_refs:   [],
};

const CANVAS_ROW = {
	post_id:       55,
	post_type:     'et_header_layout',
	post_status:   'publish',
	post_title:    'Header Canvas',
	post_modified: '2025-03-10 12:00:00',
	canvas_label:  'Header',
	has_dso:       false,
	var_refs:      [],
	preset_refs:   [],
};

// ── Tests ─────────────────────────────────────────────────────────────────────

// ── contentScanRowTable ───────────────────────────────────────────────────────

describe( 'contentScanRowTable', () => {

	test( 'returns empty string for null input', () => {
		assert.equal( contentScanRowTable( null ), '' );
	} );

	test( 'returns empty string for empty array', () => {
		assert.equal( contentScanRowTable( [] ), '' );
	} );

	test( 'wraps output in a table element', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.startsWith( '<table' ), 'should start with <table' );
		assert.ok( html.endsWith( '</table>' ), 'should end with </table>' );
	} );

	test( 'includes post_id in output', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.includes( '42' ) );
	} );

	test( 'includes post_title in output', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.includes( 'My Page' ) );
	} );

	test( 'includes post_status with CSS class', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.includes( 'd5dsh-scan-status-publish' ) );
		assert.ok( html.includes( 'publish' ) );
	} );

	test( 'shows var_refs count', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		// SLIM_ROW has 1 var_ref — should appear in the Vars column
		assert.ok( html.includes( '<td>1</td>' ) );
	} );

	test( 'shows zero preset_refs count', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.includes( '<td>0</td>' ) );
	} );

	test( 'truncates modified date to 10 chars', () => {
		const html = contentScanRowTable( [ SLIM_ROW ] );
		assert.ok( html.includes( '2025-06-01' ) );
		assert.ok( ! html.includes( '10:00:00' ) );
	} );

	test( 'uses (no title) fallback when post_title is empty', () => {
		const row  = { ...SLIM_ROW, post_title: '' };
		const html = contentScanRowTable( [ row ] );
		assert.ok( html.includes( '(no title)' ) );
	} );

	test( 'escapes HTML in post_title', () => {
		const row  = { ...SLIM_ROW, post_title: '<script>alert(1)</script>' };
		const html = contentScanRowTable( [ row ] );
		assert.ok( ! html.includes( '<script>' ) );
		assert.ok( html.includes( '&lt;script&gt;' ) );
	} );

	test( 'renders multiple rows', () => {
		const html = contentScanRowTable( [ SLIM_ROW, SLIM_ROW_NO_DSO ] );
		// Count only <tr> inside <tbody> — the <thead> also has one <tr>
		const tbody  = html.replace( /^.*<tbody>/s, '' );
		const trCount = ( tbody.match( /<tr>/g ) || [] ).length;
		assert.equal( trCount, 2 );
	} );

} );

// ── contentScanInventoryRow ───────────────────────────────────────────────────

describe( 'contentScanInventoryRow', () => {

	test( 'returns a <tr> element', () => {
		const html = contentScanInventoryRow( SLIM_ROW, '' );
		assert.ok( html.startsWith( '<tr' ) );
		assert.ok( html.endsWith( '</tr>' ) );
	} );

	test( 'has no canvas class when typeOverride is empty', () => {
		const html = contentScanInventoryRow( SLIM_ROW, '' );
		assert.ok( ! html.includes( 'd5dsh-scan-canvas-row' ) );
	} );

	test( 'adds canvas class when typeOverride is set', () => {
		const html = contentScanInventoryRow( CANVAS_ROW, 'Header canvas' );
		assert.ok( html.includes( 'd5dsh-scan-canvas-row' ) );
	} );

	test( 'wraps typeOverride in <em>', () => {
		const html = contentScanInventoryRow( CANVAS_ROW, 'Header canvas' );
		assert.ok( html.includes( '<em>Header canvas</em>' ) );
	} );

	test( 'shows post_type when no typeOverride', () => {
		const html = contentScanInventoryRow( SLIM_ROW, '' );
		assert.ok( html.includes( 'page' ) );
	} );

	test( 'shows DSO badge when dsoCount > 0', () => {
		const html = contentScanInventoryRow( SLIM_ROW, '' );
		assert.ok( html.includes( 'd5dsh-audit-badge' ) );
		assert.ok( html.includes( '>1<' ) );
	} );

	test( 'shows em-dash when dsoCount is 0', () => {
		const html = contentScanInventoryRow( SLIM_ROW_NO_DSO, '' );
		assert.ok( html.includes( '&mdash;' ) );
		assert.ok( ! html.includes( 'd5dsh-audit-badge' ) );
	} );

	test( 'counts both var_refs and preset_refs', () => {
		const row  = { ...SLIM_ROW, var_refs: [ {}, {} ], preset_refs: [ 'p1' ] };
		const html = contentScanInventoryRow( row, '' );
		assert.ok( html.includes( '>3<' ) );
	} );

	test( 'escapes HTML in post_title', () => {
		const row  = { ...SLIM_ROW, post_title: '<b>Bold</b>' };
		const html = contentScanInventoryRow( row, '' );
		assert.ok( ! html.includes( '<b>' ) );
		assert.ok( html.includes( '&lt;b&gt;' ) );
	} );

} );

// ── contentScanDsoTable ───────────────────────────────────────────────────────

describe( 'contentScanDsoTable', () => {

	test( 'returns empty string for empty map', () => {
		assert.equal( contentScanDsoTable( 'Variables', {} ), '' );
	} );

	test( 'includes heading', () => {
		const map  = { 'gcid-primary': { count: 2, posts: [] } };
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( html.includes( 'Variables' ) );
	} );

	test( 'includes count badge with number of distinct DSOs', () => {
		const map = {
			'gcid-a': { count: 1, posts: [] },
			'gcid-b': { count: 3, posts: [] },
		};
		const html = contentScanDsoTable( 'Variables', map );
		// The heading badge shows the number of distinct IDs (2).
		// Match the badge specifically in the heading <h4> block.
		const headingBadge = html.match( /d5dsh-scan-type-heading[\s\S]*?<\/h4>/ );
		assert.ok( headingBadge, 'heading should be present' );
		assert.ok( headingBadge[0].includes( '>2<' ), 'heading badge should show 2' );
	} );

	test( 'renders one table row per DSO', () => {
		const map = {
			'gcid-a': { count: 1, posts: [] },
			'gcid-b': { count: 2, posts: [] },
		};
		const html  = contentScanDsoTable( 'Variables', map );
		// Count only <tr> inside <tbody> — the <thead> also has one <tr>
		const tbody   = html.replace( /^.*<tbody>/s, '' );
		const trCount = ( tbody.match( /<tr>/g ) || [] ).length;
		assert.equal( trCount, 2 );
	} );

	test( 'renders DSO id inside <code>', () => {
		const map  = { 'gcid-primary': { count: 1, posts: [] } };
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( html.includes( '<code>gcid-primary</code>' ) );
	} );

	test( 'renders usage count in a badge', () => {
		const map  = { 'gcid-primary': { count: 5, posts: [] } };
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( html.includes( '>5<' ) );
	} );

	test( 'renders post titles joined by comma', () => {
		const map = {
			'gcid-primary': {
				count: 2,
				posts: [
					{ post_id: 1, post_title: 'Page A' },
					{ post_id: 2, post_title: 'Page B' },
				],
			},
		};
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( html.includes( 'Page A' ) );
		assert.ok( html.includes( 'Page B' ) );
		assert.ok( html.includes( ', ' ) );
	} );

	test( 'falls back to post_id string when post_title is empty', () => {
		const map = {
			'gcid-x': {
				count: 1,
				posts: [ { post_id: 99, post_title: '' } ],
			},
		};
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( html.includes( '99' ) );
	} );

	test( 'escapes HTML in DSO id', () => {
		const map  = { '<evil>': { count: 1, posts: [] } };
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( ! html.includes( '<evil>' ) );
		assert.ok( html.includes( '&lt;evil&gt;' ) );
	} );

	test( 'escapes HTML in post titles', () => {
		const map = {
			'gcid-x': {
				count: 1,
				posts: [ { post_id: 1, post_title: '<script>xss</script>' } ],
			},
		};
		const html = contentScanDsoTable( 'Variables', map );
		assert.ok( ! html.includes( '<script>' ) );
	} );

} );

// ── contentScanRenderMeta (DOM stub) ──────────────────────────────────────────

describe( 'contentScanRenderMeta', () => {

	test( 'writes scan date and counts to element', () => {
		const dom = makeDom( [ 'd5dsh-scan-meta' ] );
		contentScanRenderMeta( {
			ran_at: '2025-06-01 12:00:00 UTC',
			total_scanned: 42,
			active_count:  7,
			limit:         1000,
			limit_reached: false,
		}, dom );
		const html = dom.elements[ 'd5dsh-scan-meta' ].innerHTML;
		assert.ok( html.includes( '2025-06-01 12:00:00 UTC' ) );
		assert.ok( html.includes( '42' ) );
		assert.ok( html.includes( '7' ) );
	} );

	test( 'does not include limit warning when limit not reached', () => {
		const dom = makeDom( [ 'd5dsh-scan-meta' ] );
		contentScanRenderMeta( {
			ran_at: '2025-06-01 12:00:00 UTC',
			total_scanned: 10, active_count: 0,
			limit: 1000, limit_reached: false,
		}, dom );
		assert.ok( ! dom.elements[ 'd5dsh-scan-meta' ].innerHTML.includes( 'partial' ) );
	} );

	test( 'includes limit warning when limit is reached', () => {
		const dom = makeDom( [ 'd5dsh-scan-meta' ] );
		contentScanRenderMeta( {
			ran_at: '2025-06-01 12:00:00 UTC',
			total_scanned: 1000, active_count: 50,
			limit: 1000, limit_reached: true,
		}, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-meta' ].innerHTML.includes( 'partial' ) );
		assert.ok( dom.elements[ 'd5dsh-scan-meta' ].innerHTML.includes( '1000' ) );
	} );

	test( 'does nothing when meta element is missing from DOM', () => {
		// Should not throw
		const dom = makeDom( [] );
		assert.doesNotThrow( () => {
			contentScanRenderMeta( { ran_at: 'x', total_scanned: 0, active_count: 0, limit: 1000, limit_reached: false }, dom );
		} );
	} );

} );

// ── contentScanRenderActiveReport (DOM stub) ──────────────────────────────────

describe( 'contentScanRenderActiveReport', () => {

	test( 'writes empty-state message when total is 0', () => {
		const dom = makeDom( [ 'd5dsh-scan-active-badge', 'd5dsh-scan-active-body' ] );
		contentScanRenderActiveReport( { total: 0, by_type: {} }, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-active-body' ].innerHTML.includes( 'No content' ) );
	} );

	test( 'sets badge to total count', () => {
		const dom = makeDom( [ 'd5dsh-scan-active-badge', 'd5dsh-scan-active-body' ] );
		contentScanRenderActiveReport( { total: 3, by_type: { page: [ SLIM_ROW, SLIM_ROW, SLIM_ROW ] } }, dom );
		assert.equal( dom.elements[ 'd5dsh-scan-active-badge' ].textContent, '3' );
	} );

	test( 'renders a type heading for each post type', () => {
		const dom = makeDom( [ 'd5dsh-scan-active-badge', 'd5dsh-scan-active-body' ] );
		contentScanRenderActiveReport( {
			total: 2,
			by_type: {
				page: [ SLIM_ROW ],
				post: [ SLIM_ROW_NO_DSO ],
			},
		}, dom );
		const html = dom.elements[ 'd5dsh-scan-active-body' ].innerHTML;
		assert.ok( html.includes( 'page' ) );
		assert.ok( html.includes( 'post' ) );
	} );

	test( 'handles null gracefully', () => {
		const dom = makeDom( [ 'd5dsh-scan-active-badge', 'd5dsh-scan-active-body' ] );
		assert.doesNotThrow( () => contentScanRenderActiveReport( null, dom ) );
		assert.ok( dom.elements[ 'd5dsh-scan-active-body' ].innerHTML.includes( 'No content' ) );
	} );

} );

// ── contentScanRenderInventory (DOM stub) ─────────────────────────────────────

describe( 'contentScanRenderInventory', () => {

	test( 'writes empty-state message when rows is empty', () => {
		const dom = makeDom( [ 'd5dsh-scan-inventory-badge', 'd5dsh-scan-inventory-body' ] );
		contentScanRenderInventory( { total: 0, rows: [] }, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-inventory-body' ].innerHTML.includes( 'No Divi content' ) );
	} );

	test( 'sets badge to total', () => {
		const dom = makeDom( [ 'd5dsh-scan-inventory-badge', 'd5dsh-scan-inventory-body' ] );
		contentScanRenderInventory( { total: 2, rows: [ SLIM_ROW, SLIM_ROW_NO_DSO ] }, dom );
		assert.equal( dom.elements[ 'd5dsh-scan-inventory-badge' ].textContent, '2' );
	} );

	test( 'renders a table for non-empty inventory', () => {
		const dom = makeDom( [ 'd5dsh-scan-inventory-badge', 'd5dsh-scan-inventory-body' ] );
		contentScanRenderInventory( { total: 1, rows: [ SLIM_ROW ] }, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-inventory-body' ].innerHTML.includes( '<table' ) );
	} );

	test( 'renders canvas sub-rows when canvases present', () => {
		const templateRow = {
			...SLIM_ROW,
			post_type: 'et_template',
			post_title: 'My Template',
			canvases: [ CANVAS_ROW ],
		};
		const dom = makeDom( [ 'd5dsh-scan-inventory-badge', 'd5dsh-scan-inventory-body' ] );
		contentScanRenderInventory( { total: 1, rows: [ templateRow ] }, dom );
		const html = dom.elements[ 'd5dsh-scan-inventory-body' ].innerHTML;
		assert.ok( html.includes( 'd5dsh-scan-canvas-row' ) );
		assert.ok( html.includes( 'Header canvas' ) );
	} );

	test( 'handles null gracefully', () => {
		const dom = makeDom( [ 'd5dsh-scan-inventory-badge', 'd5dsh-scan-inventory-body' ] );
		assert.doesNotThrow( () => contentScanRenderInventory( null, dom ) );
	} );

} );

// ── contentScanRenderDsoUsage (DOM stub) ──────────────────────────────────────

describe( 'contentScanRenderDsoUsage', () => {

	test( 'writes empty-state message when no DSOs', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		contentScanRenderDsoUsage( { variables: {}, presets: {} }, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-dso-body' ].innerHTML.includes( 'No DSO' ) );
	} );

	test( 'sets badge to total distinct DSO count', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		contentScanRenderDsoUsage( {
			variables: { 'gcid-a': { count: 1, posts: [] }, 'gcid-b': { count: 2, posts: [] } },
			presets:   { 'preset-x': { count: 1, posts: [] } },
		}, dom );
		assert.equal( dom.elements[ 'd5dsh-scan-dso-badge' ].textContent, '3' );
	} );

	test( 'renders variables heading', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		contentScanRenderDsoUsage( {
			variables: { 'gcid-primary': { count: 1, posts: [] } },
			presets:   {},
		}, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-dso-body' ].innerHTML.includes( 'Variables' ) );
	} );

	test( 'renders presets heading', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		contentScanRenderDsoUsage( {
			variables: {},
			presets:   { 'preset-x': { count: 1, posts: [] } },
		}, dom );
		assert.ok( dom.elements[ 'd5dsh-scan-dso-body' ].innerHTML.includes( 'Presets' ) );
	} );

	test( 'handles null gracefully', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		assert.doesNotThrow( () => contentScanRenderDsoUsage( null, dom ) );
		assert.ok( dom.elements[ 'd5dsh-scan-dso-body' ].innerHTML.includes( 'No DSO' ) );
	} );

	test( 'handles missing variables key gracefully', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		assert.doesNotThrow( () => contentScanRenderDsoUsage( { presets: {} }, dom ) );
	} );

	test( 'handles missing presets key gracefully', () => {
		const dom = makeDom( [ 'd5dsh-scan-dso-badge', 'd5dsh-scan-dso-body' ] );
		assert.doesNotThrow( () => contentScanRenderDsoUsage( { variables: {} }, dom ) );
	} );

} );
