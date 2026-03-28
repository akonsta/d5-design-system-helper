/**
 * JS unit tests for the five new feature functions:
 *   - Impact Modal rendering (_impactContentTable, renderImpactPane, renderDepsPane)
 *   - Category Manager rendering (renderCategoryList, renderCategoryAssignTable)
 *   - Merge Mode rendering (_renderMergeCard)
 *   - Style Guide rendering (_sgVarSections)
 *
 * Uses Node.js built-in test runner (node:test) + assert — no npm install needed.
 * Requires Node 18+.  Run with:
 *
 *   node --test tests/js/new-features.test.js
 *
 * Strategy:
 *   Pure string-builder helpers are re-implemented here (extracted from admin.js).
 *   DOM-writing functions are tested with a minimal DOM stub.
 *   Event handlers and AJAX calls are out of scope for unit tests.
 */

'use strict';

import { test, describe } from 'node:test';
import assert from 'node:assert/strict';

// ── Utilities (mirrors admin.js) ──────────────────────────────────────────────

function escHtml( text ) {
	return String( text )
		.replace( /&/g,  '&amp;'  )
		.replace( /</g,  '&lt;'   )
		.replace( />/g,  '&gt;'   )
		.replace( /"/g,  '&quot;' )
		.replace( /'/g,  '&#039;' );
}

// ── Minimal DOM stub ──────────────────────────────────────────────────────────

function makeDom( ids ) {
	const elements = {};
	for ( const id of ids ) {
		elements[ id ] = { innerHTML: '', textContent: '', style: { display: '' } };
	}
	return {
		getElementById:  ( id ) => elements[ id ] || null,
		querySelector:   ( sel ) => null,
		querySelectorAll: ( sel ) => [],
		elements,
	};
}

// ── Impact modal pure helpers ─────────────────────────────────────────────────

/**
 * Build a compact HTML table from an array of post-reference objects.
 * Extracted from admin.js _impactContentTable.
 */
function _impactContentTable( posts ) {
	if ( ! posts || ! posts.length ) {
		return '<p class="d5dsh-impact-empty">No content items found.</p>';
	}
	let html = '<table class="d5dsh-impact-table widefat striped">'
		+ '<thead><tr><th>Title</th><th>Type</th><th>Status</th></tr></thead><tbody>';
	posts.forEach( function ( p ) {
		const statusClass = p.post_status === 'publish'
			? 'd5dsh-impact-severity-high' : 'd5dsh-impact-severity-low';
		html += '<tr>'
			+ '<td>' + escHtml( p.post_title || '(untitled)' ) + '</td>'
			+ '<td>' + escHtml( p.post_type  || '' ) + '</td>'
			+ '<td><span class="' + statusClass + '">' + escHtml( p.post_status || '' ) + '</span></td>'
			+ '</tr>';
	} );
	html += '</tbody></table>';
	return html;
}

/**
 * Build the "What Breaks?" pane HTML from analyze result data.
 * Extracted from admin.js renderImpactPane.
 */
function renderImpactPane( data ) {
	if ( ! data ) return '<p>No data.</p>';

	const directCount = ( data.direct_content || [] ).length;
	const viaCount    = ( data.via_presets || [] ).reduce(
		( n, p ) => n + ( p.content || [] ).length, 0
	);
	const totalAffected = directCount + viaCount;
	const hasPublished  = ( data.direct_content || [] ).some( p => p.post_status === 'publish' )
		|| ( data.via_presets || [] ).some( p =>
			( p.content || [] ).some( c => c.post_status === 'publish' )
		);

	let html = '<p class="d5dsh-impact-summary">'
		+ 'Removing this would affect <strong>' + totalAffected + '</strong> content item(s).'
		+ '</p>';

	if ( hasPublished ) {
		html += '<p class="d5dsh-impact-warning">⚠ Published content would be affected.</p>';
	}

	if ( directCount > 0 ) {
		html += '<h4>Direct references (' + directCount + ')</h4>';
		html += _impactContentTable( data.direct_content );
	}

	( data.via_presets || [] ).forEach( function ( pdata ) {
		html += '<details class="d5dsh-impact-preset-block">'
			+ '<summary>' + escHtml( pdata.preset_label || pdata.preset_id ) + ' ('
			+ ( pdata.content || [] ).length + ' items)</summary>'
			+ _impactContentTable( pdata.content || [] )
			+ '</details>';
	} );

	return html;
}

/**
 * Build a single node HTML for the dependency tree.
 * Extracted from admin.js _buildImpactTreeNode.
 */
function _buildImpactTreeNode( node, depth ) {
	if ( ! node ) return '';
	const children = node.children || [];
	const label    = escHtml( node.label || node.id );
	const typeLabel = { variable: 'Var', preset: 'Preset', content: 'Content', group: '' }[ node.type ] || '';

	if ( ! children.length ) {
		return '<li class="d5dsh-impact-leaf d5dsh-impact-node-' + escHtml( node.type || 'content' ) + '">'
			+ ( typeLabel ? '<span class="d5dsh-impact-type-chip">' + typeLabel + '</span> ' : '' )
			+ label + '</li>';
	}

	let html = '<details' + ( depth === 0 ? ' open' : '' ) + '>'
		+ '<summary class="d5dsh-impact-node-' + escHtml( node.type || '' ) + '">'
		+ ( typeLabel ? '<span class="d5dsh-impact-type-chip">' + typeLabel + '</span> ' : '' )
		+ label + '</summary><ul>';
	children.forEach( function ( child ) {
		html += _buildImpactTreeNode( child, depth + 1 );
	} );
	html += '</ul></details>';
	return html;
}

/**
 * Build the "Dependencies" pane HTML from analyze result data.
 * Extracted from admin.js renderDepsPane.
 */
function renderDepsPane( data ) {
	if ( ! data || ! data.dep_tree ) {
		return '<p class="d5dsh-impact-empty">No dependency data.</p>';
	}
	return '<div class="d5dsh-deps-tree"><ul>' + _buildImpactTreeNode( data.dep_tree, 0 ) + '</ul></div>';
}

// ── Category Manager pure helpers ─────────────────────────────────────────────

/**
 * Build the category list table HTML.
 * Extracted from admin.js renderCategoryList.
 */
function renderCategoryList( categories ) {
	if ( ! categories || ! categories.length ) {
		return '<p class="d5dsh-cat-empty">No categories yet. Add one above.</p>';
	}
	let html = '<table class="d5dsh-cat-list-table widefat striped">'
		+ '<thead><tr><th>Color</th><th>Name</th><th>Actions</th></tr></thead><tbody>';
	categories.forEach( function ( cat ) {
		html += '<tr data-cat-id="' + escHtml( cat.id ) + '">'
			+ '<td><span class="d5dsh-category-swatch" style="background:' + escHtml( cat.color ) + '"></span></td>'
			+ '<td>' + escHtml( cat.label ) + '</td>'
			+ '<td>'
			+ '<button class="button-link d5dsh-cat-delete" data-cat-id="' + escHtml( cat.id ) + '">Delete</button>'
			+ '</td>'
			+ '</tr>';
	} );
	html += '</tbody></table>';
	return html;
}

/**
 * Build the category assignment table HTML.
 * Extracted from admin.js renderCategoryAssignTable.
 */
function renderCategoryAssignTable( vars, categories, categoryMap ) {
	if ( ! vars || ! vars.length ) {
		return '<p class="d5dsh-cat-empty">No variables loaded.</p>';
	}
	const catOptions = [ '<option value="">— None —</option>' ]
		.concat( categories.map( cat =>
			'<option value="' + escHtml( cat.id ) + '">' + escHtml( cat.label ) + '</option>'
		) ).join( '' );

	let html = '<table class="d5dsh-cat-assign-table widefat striped">'
		+ '<thead><tr><th>#</th><th>Type</th><th>ID</th><th>Label</th><th>Category</th></tr></thead><tbody>';

	vars.forEach( function ( v, i ) {
		const currentCat = categoryMap[ v.id ] || '';
		const selectHtml = '<select class="d5dsh-category-assign-dropdown" '
			+ 'data-var-id="' + escHtml( v.id ) + '">'
			+ catOptions.replace(
				'value="' + escHtml( currentCat ) + '"',
				'value="' + escHtml( currentCat ) + '" selected'
			)
			+ '</select>';
		html += '<tr>'
			+ '<td>' + ( i + 1 ) + '</td>'
			+ '<td>' + escHtml( v.type  || '' ) + '</td>'
			+ '<td><code>' + escHtml( v.id    || '' ) + '</code></td>'
			+ '<td>' + escHtml( v.label || '' ) + '</td>'
			+ '<td>' + selectHtml + '</td>'
			+ '</tr>';
	} );

	html += '</tbody></table>';
	return html;
}

// ── Merge mode pure helpers ───────────────────────────────────────────────────

/**
 * Build a merge card HTML for a variable.
 * Extracted from admin.js _renderMergeCard.
 */
function _renderMergeCard( role, variable ) {
	if ( ! variable ) {
		return '<div class="d5dsh-merge-card d5dsh-merge-card-empty">'
			+ '<p>No variable selected.</p></div>';
	}
	const roleLabel = role === 'keep' ? 'Keep (survivor)' : 'Retire (replaced)';
	const colorSwatch = variable.type === 'colors' && variable.value
		? '<span class="d5dsh-merge-swatch" style="background:' + escHtml( variable.value ) + '"></span>'
		: '';
	return '<div class="d5dsh-merge-card d5dsh-merge-card-' + escHtml( role ) + '">'
		+ '<h4>' + escHtml( roleLabel ) + '</h4>'
		+ '<dl>'
		+ '<dt>ID</dt><dd><code>' + escHtml( variable.id ) + '</code></dd>'
		+ '<dt>Label</dt><dd>' + escHtml( variable.label || '' ) + '</dd>'
		+ '<dt>Type</dt><dd>' + escHtml( variable.type || '' ) + '</dd>'
		+ '<dt>Value</dt><dd>' + colorSwatch + escHtml( variable.value || '' ) + '</dd>'
		+ '</dl></div>';
}

// ── Style Guide pure helpers ──────────────────────────────────────────────────

/**
 * Sort vars into sections (colors, fonts, numbers, other).
 * Extracted from admin.js _sgVarSections.
 */
function _sgVarSections( vars ) {
	const sections = { colors: [], fonts: [], numbers: [], other: [] };
	( vars || [] ).forEach( function ( v ) {
		const t = v.type || 'other';
		if ( t === 'colors' )      sections.colors.push( v );
		else if ( t === 'fonts' )  sections.fonts.push( v );
		else if ( t === 'numbers' ) sections.numbers.push( v );
		else                       sections.other.push( v );
	} );
	return sections;
}

// ── Tests: Impact modal — _impactContentTable ─────────────────────────────────

describe( 'Impact Modal — _impactContentTable', function () {

	test( 'returns empty-state paragraph when posts array is empty', function () {
		const html = _impactContentTable( [] );
		assert.ok( html.includes( 'd5dsh-impact-empty' ), 'should include empty class' );
		assert.ok( ! html.includes( '<table' ), 'should not include a table' );
	} );

	test( 'returns a table when posts are present', function () {
		const posts = [ { post_title: 'Home', post_type: 'page', post_status: 'publish', post_id: 1 } ];
		const html = _impactContentTable( posts );
		assert.ok( html.includes( '<table' ), 'should include a table' );
		assert.ok( html.includes( 'Home' ), 'should include post title' );
	} );

	test( 'applies high-severity class for published posts', function () {
		const posts = [ { post_title: 'Published', post_type: 'page', post_status: 'publish', post_id: 1 } ];
		const html = _impactContentTable( posts );
		assert.ok( html.includes( 'd5dsh-impact-severity-high' ), 'published post should have high severity' );
	} );

	test( 'applies low-severity class for draft posts', function () {
		const posts = [ { post_title: 'Draft', post_type: 'page', post_status: 'draft', post_id: 2 } ];
		const html = _impactContentTable( posts );
		assert.ok( html.includes( 'd5dsh-impact-severity-low' ), 'draft post should have low severity' );
	} );

	test( 'escapes HTML in post title', function () {
		const posts = [ { post_title: '<script>alert(1)</script>', post_type: 'page', post_status: 'publish', post_id: 3 } ];
		const html = _impactContentTable( posts );
		assert.ok( ! html.includes( '<script>' ), 'script tag should be escaped' );
		assert.ok( html.includes( '&lt;script&gt;' ), 'should contain escaped tag' );
	} );

} );

// ── Tests: Impact modal — renderImpactPane ────────────────────────────────────

describe( 'Impact Modal — renderImpactPane', function () {

	test( 'returns no-data message for null input', function () {
		const html = renderImpactPane( null );
		assert.ok( html.includes( 'No data' ), 'null input should produce no-data message' );
	} );

	test( 'shows zero affected items when arrays are empty', function () {
		const data = { direct_content: [], via_presets: [] };
		const html = renderImpactPane( data );
		assert.ok( html.includes( '0' ), 'should mention zero items' );
		assert.ok( ! html.includes( 'd5dsh-impact-warning' ), 'no warning for zero published' );
	} );

	test( 'shows warning when published content is directly referenced', function () {
		const data = {
			direct_content: [ { post_title: 'Published Page', post_type: 'page', post_status: 'publish', post_id: 1 } ],
			via_presets: [],
		};
		const html = renderImpactPane( data );
		assert.ok( html.includes( 'd5dsh-impact-warning' ), 'published direct content should trigger warning' );
	} );

	test( 'shows warning when published content is via a preset', function () {
		const data = {
			direct_content: [],
			via_presets: [
				{
					preset_id: 'p1', preset_label: 'My Preset',
					content: [ { post_title: 'X', post_type: 'page', post_status: 'publish', post_id: 5 } ],
				},
			],
		};
		const html = renderImpactPane( data );
		assert.ok( html.includes( 'd5dsh-impact-warning' ), 'published via-preset content should trigger warning' );
	} );

	test( 'renders a details block per via_presets entry', function () {
		const data = {
			direct_content: [],
			via_presets: [
				{ preset_id: 'p1', preset_label: 'Preset A', content: [] },
				{ preset_id: 'p2', preset_label: 'Preset B', content: [] },
			],
		};
		const html = renderImpactPane( data );
		assert.ok( html.includes( 'Preset A' ), 'should include Preset A' );
		assert.ok( html.includes( 'Preset B' ), 'should include Preset B' );
		assert.strictEqual( ( html.match( /<details/g ) || [] ).length, 2, 'should have 2 details blocks' );
	} );

	test( 'counts via_presets content in total affected', function () {
		const data = {
			direct_content: [ { post_id: 1, post_title: 'A', post_type: 'page', post_status: 'draft' } ],
			via_presets: [
				{ preset_id: 'p1', preset_label: 'P1', content: [
					{ post_id: 2, post_title: 'B', post_type: 'page', post_status: 'draft' },
					{ post_id: 3, post_title: 'C', post_type: 'page', post_status: 'draft' },
				] },
			],
		};
		const html = renderImpactPane( data );
		assert.ok( html.includes( '>3<' ), 'should show total of 3 affected items' );
	} );

} );

// ── Tests: Impact modal — renderDepsPane ─────────────────────────────────────

describe( 'Impact Modal — renderDepsPane', function () {

	test( 'returns empty-state when data is null', function () {
		const html = renderDepsPane( null );
		assert.ok( html.includes( 'd5dsh-impact-empty' ), 'should show empty state' );
	} );

	test( 'returns empty-state when dep_tree is missing', function () {
		const html = renderDepsPane( {} );
		assert.ok( html.includes( 'd5dsh-impact-empty' ), 'should show empty state for missing dep_tree' );
	} );

	test( 'renders the root node label', function () {
		const data = {
			dep_tree: { id: 'gcid-primary', label: 'Primary Color', type: 'variable', children: [] },
		};
		const html = renderDepsPane( data );
		assert.ok( html.includes( 'Primary Color' ), 'should include root label' );
	} );

	test( 'root details element is open at depth 0', function () {
		const data = {
			dep_tree: {
				id: 'gcid-x', label: 'X', type: 'variable',
				children: [ { id: 'p1', label: 'Preset 1', type: 'preset', children: [] } ],
			},
		};
		const html = renderDepsPane( data );
		assert.ok( html.includes( '<details open>' ), 'root details should be open' );
	} );

	test( 'leaf nodes render as list items without details', function () {
		const data = {
			dep_tree: {
				id: 'gcid-x', label: 'X', type: 'variable',
				children: [ { id: 'p1', label: 'P1', type: 'preset', children: [] } ],
			},
		};
		const html = renderDepsPane( data );
		assert.ok( html.includes( '<li class="d5dsh-impact-leaf' ), 'leaf node should be a list item' );
	} );

} );

// ── Tests: Category Manager — renderCategoryList ──────────────────────────────

describe( 'Category Manager — renderCategoryList', function () {

	test( 'shows empty message when categories list is empty', function () {
		const html = renderCategoryList( [] );
		assert.ok( html.includes( 'd5dsh-cat-empty' ), 'should show empty state' );
		assert.ok( ! html.includes( '<table' ), 'should not include a table' );
	} );

	test( 'renders a table row per category', function () {
		const cats = [
			{ id: 'cat-1', label: 'Brand',    color: '#ff0000' },
			{ id: 'cat-2', label: 'Neutral',  color: '#888888' },
		];
		const html = renderCategoryList( cats );
		assert.ok( html.includes( 'Brand' ),   'should include Brand' );
		assert.ok( html.includes( 'Neutral' ), 'should include Neutral' );
		assert.strictEqual( ( html.match( /<tr data-cat-id=/g ) || [] ).length, 2, 'should have 2 data rows' );
	} );

	test( 'renders the color swatch with correct background', function () {
		const cats = [ { id: 'cat-1', label: 'Brand', color: '#0000ff' } ];
		const html = renderCategoryList( cats );
		assert.ok( html.includes( 'background:#0000ff' ), 'should have correct swatch color' );
	} );

	test( 'renders a delete button with category id', function () {
		const cats = [ { id: 'cat-xyz', label: 'Test', color: '#000000' } ];
		const html = renderCategoryList( cats );
		assert.ok( html.includes( 'data-cat-id="cat-xyz"' ), 'delete button should have correct data attribute' );
	} );

	test( 'escapes HTML in category label', function () {
		const cats = [ { id: 'cat-1', label: '<b>Bad</b>', color: '#000000' } ];
		const html = renderCategoryList( cats );
		assert.ok( ! html.includes( '<b>' ), 'label should be HTML-escaped' );
	} );

} );

// ── Tests: Category Manager — renderCategoryAssignTable ───────────────────────

describe( 'Category Manager — renderCategoryAssignTable', function () {

	const categories = [
		{ id: 'cat-1', label: 'Brand',   color: '#ff0000' },
		{ id: 'cat-2', label: 'Neutral', color: '#888888' },
	];

	test( 'shows empty message when vars list is empty', function () {
		const html = renderCategoryAssignTable( [], categories, {} );
		assert.ok( html.includes( 'd5dsh-cat-empty' ), 'should show empty state' );
	} );

	test( 'renders a row per variable', function () {
		const vars = [
			{ id: 'gcid-primary', label: 'Primary',  type: 'colors' },
			{ id: 'gcid-spacing', label: 'Spacing',  type: 'numbers' },
		];
		const html = renderCategoryAssignTable( vars, categories, {} );
		assert.ok( html.includes( 'gcid-primary' ), 'should include first variable' );
		assert.ok( html.includes( 'gcid-spacing' ), 'should include second variable' );
	} );

	test( 'dropdown includes category options', function () {
		const vars = [ { id: 'gcid-x', label: 'X', type: 'colors' } ];
		const html = renderCategoryAssignTable( vars, categories, {} );
		assert.ok( html.includes( 'Brand' ),   'should include Brand option' );
		assert.ok( html.includes( 'Neutral' ), 'should include Neutral option' );
	} );

	test( 'pre-selects assigned category', function () {
		const vars = [ { id: 'gcid-x', label: 'X', type: 'colors' } ];
		const html = renderCategoryAssignTable( vars, categories, { 'gcid-x': 'cat-1' } );
		assert.ok( html.includes( 'value="cat-1" selected' ), 'should pre-select assigned category' );
	} );

} );

// ── Tests: Merge Mode — _renderMergeCard ─────────────────────────────────────

describe( 'Merge Mode — _renderMergeCard', function () {

	test( 'renders empty card when variable is null', function () {
		const html = _renderMergeCard( 'keep', null );
		assert.ok( html.includes( 'd5dsh-merge-card-empty' ), 'should render empty card' );
	} );

	test( 'renders keep card with correct role class', function () {
		const v = { id: 'gcid-primary', label: 'Primary', type: 'colors', value: '#ff0000' };
		const html = _renderMergeCard( 'keep', v );
		assert.ok( html.includes( 'd5dsh-merge-card-keep' ), 'should have keep role class' );
	} );

	test( 'renders retire card with correct role class', function () {
		const v = { id: 'gcid-old', label: 'Old', type: 'colors', value: '#aaa' };
		const html = _renderMergeCard( 'retire', v );
		assert.ok( html.includes( 'd5dsh-merge-card-retire' ), 'should have retire role class' );
	} );

	test( 'shows variable ID and label', function () {
		const v = { id: 'gcid-brand', label: 'Brand Blue', type: 'colors', value: '#0000ff' };
		const html = _renderMergeCard( 'keep', v );
		assert.ok( html.includes( 'gcid-brand' ),   'should show variable ID' );
		assert.ok( html.includes( 'Brand Blue' ),   'should show variable label' );
	} );

	test( 'renders color swatch for color type variables', function () {
		const v = { id: 'gcid-c', label: 'C', type: 'colors', value: '#123456' };
		const html = _renderMergeCard( 'keep', v );
		assert.ok( html.includes( 'd5dsh-merge-swatch' ), 'should include color swatch' );
		assert.ok( html.includes( 'background:#123456' ), 'swatch should have correct color' );
	} );

	test( 'does not render color swatch for non-color types', function () {
		const v = { id: 'gcid-n', label: 'N', type: 'numbers', value: '16' };
		const html = _renderMergeCard( 'keep', v );
		assert.ok( ! html.includes( 'd5dsh-merge-swatch' ), 'should not include swatch for numbers' );
	} );

	test( 'escapes HTML in variable label', function () {
		const v = { id: 'gcid-xss', label: '<script>alert(1)</script>', type: 'numbers', value: '1' };
		const html = _renderMergeCard( 'keep', v );
		assert.ok( ! html.includes( '<script>' ), 'label should be HTML-escaped' );
	} );

} );

// ── Tests: Style Guide — _sgVarSections ──────────────────────────────────────

describe( 'Style Guide — _sgVarSections', function () {

	test( 'returns empty sections for empty input', function () {
		const sections = _sgVarSections( [] );
		assert.strictEqual( sections.colors.length,  0, 'no colors' );
		assert.strictEqual( sections.fonts.length,   0, 'no fonts' );
		assert.strictEqual( sections.numbers.length, 0, 'no numbers' );
		assert.strictEqual( sections.other.length,   0, 'no other' );
	} );

	test( 'routes colors type vars to colors section', function () {
		const vars = [ { id: 'gcid-c', type: 'colors', label: 'C', value: '#fff' } ];
		const sections = _sgVarSections( vars );
		assert.strictEqual( sections.colors.length, 1, 'should have 1 color' );
		assert.strictEqual( sections.fonts.length,  0, 'no fonts' );
	} );

	test( 'routes fonts type vars to fonts section', function () {
		const vars = [ { id: 'gcid-f', type: 'fonts', label: 'F', value: 'Roboto' } ];
		const sections = _sgVarSections( vars );
		assert.strictEqual( sections.fonts.length,  1, 'should have 1 font' );
		assert.strictEqual( sections.colors.length, 0, 'no colors' );
	} );

	test( 'routes numbers type vars to numbers section', function () {
		const vars = [ { id: 'gcid-n', type: 'numbers', label: 'N', value: '16' } ];
		const sections = _sgVarSections( vars );
		assert.strictEqual( sections.numbers.length, 1, 'should have 1 number' );
	} );

	test( 'routes unknown types to other section', function () {
		const vars = [
			{ id: 'gcid-i', type: 'images', label: 'I', value: 'url' },
			{ id: 'gcid-s', type: 'strings', label: 'S', value: 'text' },
		];
		const sections = _sgVarSections( vars );
		assert.strictEqual( sections.other.length, 2, 'should have 2 other items' );
	} );

	test( 'handles mixed vars correctly', function () {
		const vars = [
			{ id: 'gcid-c1', type: 'colors',  label: 'C1', value: '#000' },
			{ id: 'gcid-c2', type: 'colors',  label: 'C2', value: '#fff' },
			{ id: 'gcid-f1', type: 'fonts',   label: 'F1', value: 'Arial' },
			{ id: 'gcid-n1', type: 'numbers', label: 'N1', value: '8' },
			{ id: 'gcid-x1', type: 'links',   label: 'X1', value: 'https://example.com' },
		];
		const sections = _sgVarSections( vars );
		assert.strictEqual( sections.colors.length,  2, 'should have 2 colors' );
		assert.strictEqual( sections.fonts.length,   1, 'should have 1 font' );
		assert.strictEqual( sections.numbers.length, 1, 'should have 1 number' );
		assert.strictEqual( sections.other.length,   1, 'should have 1 other' );
	} );

	test( 'handles null/undefined input gracefully', function () {
		const sections = _sgVarSections( null );
		assert.strictEqual( sections.colors.length, 0, 'null input should return empty sections' );
	} );

} );
