<?php
/**
 * Tests for ContentScanner — per-page/active content scan.
 *
 * Strategy:
 *   - TestableContentScanner subclass overrides load_content_rows() and
 *     load_template_canvas_map() so tests can inject data without $wpdb.
 *   - Protected methods are exercised directly (no Reflection needed —
 *     they are protected, so the subclass can call them publicly).
 *   - $GLOBALS['_d5dsh_options'] / _d5dsh_reset_stubs() provide the
 *     in-memory WP option store.
 *
 * Covers (35 test cases):
 *   scan_row()                              (3 tests)
 *   build_active_content_report()           (4 tests)
 *   build_inventory_report()               (5 tests)
 *   build_dso_usage_index()                (4 tests)
 *   build_meta()                           (4 tests)
 *   run() integration                      (4 tests)
 *   ajax_run()                             (3 tests)
 *   build_var_info_map()                   (5 tests)
 *   run() var_info_map key                 (3 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\ContentScanner;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Exposes protected methods as public and allows data injection.
 */
class TestableContentScanner extends ContentScanner {

	/** @var array[] Injected content rows. */
	private array $injected_rows = [];

	/** @var array<int, array<string, int>> Injected template→canvas map. */
	private array $injected_canvas_map = [];

	public function set_rows( array $rows ): void {
		$this->injected_rows = $rows;
	}

	public function set_canvas_map( array $map ): void {
		$this->injected_canvas_map = $map;
	}

	protected function load_content_rows(): array {
		return $this->injected_rows;
	}

	protected function load_template_canvas_map(): array {
		return $this->injected_canvas_map;
	}

	// ── Public wrappers for protected methods ──────────────────────────────

	public function pub_scan_row( array $row ): array {
		return $this->scan_row( $row );
	}

	public function pub_build_active_content_report( array $scanned ): array {
		return $this->build_active_content_report( $scanned );
	}

	public function pub_build_inventory_report(
		array $scanned,
		array $template_map,
		array $canvas_post_ids
	): array {
		return $this->build_inventory_report( $scanned, $template_map, $canvas_post_ids );
	}

	public function pub_build_dso_usage_index( array $scanned ): array {
		return $this->build_dso_usage_index( $scanned );
	}

	public function pub_build_meta( array $rows, array $scanned ): array {
		return $this->build_meta( $rows, $scanned );
	}

	public function pub_build_var_info_map(): array {
		$m = new \ReflectionMethod( ContentScanner::class, 'build_var_info_map' );
		$m->setAccessible( true );
		return $m->invoke( $this );
	}
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/**
 * Build a minimal content row fixture.
 */
function make_row( array $overrides = [] ): array {
	return array_merge( [
		'post_id'       => 1,
		'post_type'     => 'page',
		'post_status'   => 'publish',
		'post_title'    => 'Test Page',
		'post_modified' => '2025-01-01 12:00:00',
		'post_content'  => '',
		'post_parent'   => 0,
	], $overrides );
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( ContentScanner::class )]
class ContentScannerTest extends TestCase {

	private TestableContentScanner $scanner;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->scanner = new TestableContentScanner();
	}

	// ── scan_row() ────────────────────────────────────────────────────────────

	#[Test]
	public function scan_row_no_content_returns_empty_refs_and_false_has_dso(): void {
		$row    = make_row( [ 'post_content' => '' ] );
		$result = $this->scanner->pub_scan_row( $row );

		$this->assertSame( [], $result['var_refs'] );
		$this->assertSame( [], $result['preset_refs'] );
		$this->assertFalse( $result['has_dso'] );
	}

	#[Test]
	public function scan_row_with_variable_token_sets_has_dso_true(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-primary"}})$';
		$row     = make_row( [ 'post_content' => $content ] );
		$result  = $this->scanner->pub_scan_row( $row );

		$this->assertTrue( $result['has_dso'] );
		$this->assertCount( 1, $result['var_refs'] );
		$this->assertSame( 'gcid-primary', $result['var_refs'][0]['name'] );
		$this->assertSame( 'color', $result['var_refs'][0]['type'] );
	}

	#[Test]
	public function scan_row_with_preset_ref_sets_has_dso_true(): void {
		$content = '"modulePreset":["preset-abc-123"]';
		$row     = make_row( [ 'post_content' => $content ] );
		$result  = $this->scanner->pub_scan_row( $row );

		$this->assertTrue( $result['has_dso'] );
		$this->assertSame( [], $result['var_refs'] );
		$this->assertContains( 'preset-abc-123', $result['preset_refs'] );
	}

	// ── build_active_content_report() ─────────────────────────────────────────

	#[Test]
	public function active_report_empty_when_no_dso_rows(): void {
		$scanned = [
			array_merge( make_row(), [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_active_content_report( $scanned );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['by_type'] );
	}

	#[Test]
	public function active_report_includes_only_rows_with_dso(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1, 'post_type' => 'page' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-x' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( make_row( [ 'post_id' => 2, 'post_type' => 'page' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_active_content_report( $scanned );

		$this->assertSame( 1, $result['total'] );
		$this->assertArrayHasKey( 'page', $result['by_type'] );
		$this->assertCount( 1, $result['by_type']['page'] );
	}

	#[Test]
	public function active_report_groups_by_post_type(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1, 'post_type' => 'page' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-a' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( make_row( [ 'post_id' => 2, 'post_type' => 'post' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-b' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
		];
		$result = $this->scanner->pub_build_active_content_report( $scanned );

		$this->assertSame( 2, $result['total'] );
		$this->assertArrayHasKey( 'page', $result['by_type'] );
		$this->assertArrayHasKey( 'post', $result['by_type'] );
	}

	#[Test]
	public function active_report_row_does_not_contain_post_content(): void {
		$scanned = [
			array_merge( make_row( [ 'post_content' => 'SHOULD BE STRIPPED' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-x' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
		];
		$result = $this->scanner->pub_build_active_content_report( $scanned );
		$row    = $result['by_type']['page'][0];

		$this->assertArrayNotHasKey( 'post_content', $row );
	}

	// ── build_inventory_report() ──────────────────────────────────────────────

	#[Test]
	public function inventory_includes_all_rows_regardless_of_dso(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1 ] ), [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
			array_merge( make_row( [ 'post_id' => 2 ] ), [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_inventory_report( $scanned, [], [] );

		$this->assertSame( 2, $result['total'] );
		$this->assertCount( 2, $result['rows'] );
	}

	#[Test]
	public function inventory_excludes_canvas_types_from_top_level(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 10, 'post_type' => 'et_template' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
			array_merge( make_row( [ 'post_id' => 11, 'post_type' => 'et_header_layout' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_inventory_report( $scanned, [], [ 11 ] );

		// Only the template should appear at top level.
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 10, (int) $result['rows'][0]['post_id'] );
	}

	#[Test]
	public function inventory_nests_canvas_under_parent_template(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 10, 'post_type' => 'et_template', 'post_title' => 'My Template' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
			array_merge( make_row( [ 'post_id' => 11, 'post_type' => 'et_header_layout', 'post_title' => 'Header' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$template_map    = [ 10 => [ 'Header' => 11 ] ];
		$canvas_post_ids = [ 11 ];

		$result   = $this->scanner->pub_build_inventory_report( $scanned, $template_map, $canvas_post_ids );
		$template = $result['rows'][0];

		$this->assertArrayHasKey( 'canvases', $template );
		$this->assertCount( 1, $template['canvases'] );
		$this->assertSame( 'Header', $template['canvases'][0]['canvas_label'] );
		$this->assertSame( 11, (int) $template['canvases'][0]['post_id'] );
	}

	#[Test]
	public function inventory_handles_missing_canvas_gracefully(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 10, 'post_type' => 'et_template' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
			// Canvas 99 is NOT in scanned set.
		];
		$template_map = [ 10 => [ 'Body' => 99 ] ];

		$result   = $this->scanner->pub_build_inventory_report( $scanned, $template_map, [ 99 ] );
		$template = $result['rows'][0];

		$this->assertArrayHasKey( 'canvases', $template );
		$this->assertCount( 1, $template['canvases'] );
		$this->assertSame( 'Body', $template['canvases'][0]['canvas_label'] );
		$this->assertSame( '(not in scan)', $template['canvases'][0]['post_title'] );
	}

	#[Test]
	public function inventory_row_does_not_contain_post_content(): void {
		$scanned = [
			array_merge( make_row( [ 'post_content' => 'SHOULD BE STRIPPED' ] ),
				[ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_inventory_report( $scanned, [], [] );
		$row    = $result['rows'][0];

		$this->assertArrayNotHasKey( 'post_content', $row );
	}

	// ── build_dso_usage_index() ───────────────────────────────────────────────

	#[Test]
	public function dso_index_is_empty_when_no_refs(): void {
		$scanned = [
			array_merge( make_row(), [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_dso_usage_index( $scanned );

		$this->assertSame( [], $result['variables'] );
		$this->assertSame( [], $result['presets'] );
	}

	#[Test]
	public function dso_index_counts_variable_refs_correctly(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1, 'post_title' => 'Page A' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-primary' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( make_row( [ 'post_id' => 2, 'post_title' => 'Page B' ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-primary' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
		];
		$result = $this->scanner->pub_build_dso_usage_index( $scanned );

		$this->assertArrayHasKey( 'gcid-primary', $result['variables'] );
		$this->assertSame( 2, $result['variables']['gcid-primary']['count'] );
		$this->assertCount( 2, $result['variables']['gcid-primary']['posts'] );
	}

	#[Test]
	public function dso_index_counts_preset_refs_correctly(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1 ] ),
				[ 'var_refs' => [], 'preset_refs' => [ 'preset-abc' ], 'has_dso' => true ] ),
		];
		$result = $this->scanner->pub_build_dso_usage_index( $scanned );

		$this->assertArrayHasKey( 'preset-abc', $result['presets'] );
		$this->assertSame( 1, $result['presets']['preset-abc']['count'] );
	}

	#[Test]
	public function dso_index_sorts_by_count_descending(): void {
		$scanned = [
			array_merge( make_row( [ 'post_id' => 1 ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-rare' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( make_row( [ 'post_id' => 2 ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-common' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( make_row( [ 'post_id' => 3 ] ),
				[ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-common' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
		];
		$result = $this->scanner->pub_build_dso_usage_index( $scanned );
		$ids    = array_keys( $result['variables'] );

		$this->assertSame( 'gcid-common', $ids[0] );
		$this->assertSame( 'gcid-rare',   $ids[1] );
	}

	// ── build_meta() ──────────────────────────────────────────────────────────

	#[Test]
	public function meta_counts_scanned_and_active(): void {
		$rows    = [ make_row( [ 'post_id' => 1 ] ), make_row( [ 'post_id' => 2 ] ) ];
		$scanned = [
			array_merge( $rows[0], [ 'var_refs' => [ [ 'type' => 'color', 'name' => 'gcid-x' ] ], 'preset_refs' => [], 'has_dso' => true ] ),
			array_merge( $rows[1], [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ),
		];
		$result = $this->scanner->pub_build_meta( $rows, $scanned );

		$this->assertSame( 2, $result['total_scanned'] );
		$this->assertSame( 1, $result['active_count'] );
	}

	#[Test]
	public function meta_limit_reached_false_below_limit(): void {
		$rows    = array_fill( 0, 5, make_row() );
		$scanned = array_map( fn( $r ) => array_merge( $r, [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ), $rows );

		$result = $this->scanner->pub_build_meta( $rows, $scanned );

		$this->assertFalse( $result['limit_reached'] );
	}

	#[Test]
	public function meta_groups_by_type_and_status(): void {
		$rows    = [
			make_row( [ 'post_id' => 1, 'post_type' => 'page',   'post_status' => 'publish' ] ),
			make_row( [ 'post_id' => 2, 'post_type' => 'page',   'post_status' => 'draft' ] ),
			make_row( [ 'post_id' => 3, 'post_type' => 'post',   'post_status' => 'publish' ] ),
		];
		$scanned = array_map( fn( $r ) => array_merge( $r, [ 'var_refs' => [], 'preset_refs' => [], 'has_dso' => false ] ), $rows );

		$result = $this->scanner->pub_build_meta( $rows, $scanned );

		$this->assertSame( 2, $result['by_type']['page'] );
		$this->assertSame( 1, $result['by_type']['post'] );
		$this->assertSame( 2, $result['by_status']['publish'] );
		$this->assertSame( 1, $result['by_status']['draft'] );
	}

	#[Test]
	public function meta_includes_ran_at_and_limit(): void {
		$result = $this->scanner->pub_build_meta( [], [] );

		$this->assertArrayHasKey( 'ran_at', $result );
		$this->assertSame( ContentScanner::CONTENT_LIMIT, $result['limit'] );
	}

	// ── run() integration ─────────────────────────────────────────────────────

	#[Test]
	public function run_returns_all_top_level_keys(): void {
		$result = $this->scanner->run();

		$this->assertArrayHasKey( 'active_content', $result );
		$this->assertArrayHasKey( 'inventory',      $result );
		$this->assertArrayHasKey( 'dso_usage',      $result );
		$this->assertArrayHasKey( 'meta',            $result );
		$this->assertArrayHasKey( 'var_info_map',   $result );
		$this->assertArrayHasKey( 'preset_var_map', $result );
	}

	#[Test]
	public function run_with_empty_rows_returns_empty_reports(): void {
		$result = $this->scanner->run();

		$this->assertSame( 0, $result['active_content']['total'] );
		$this->assertSame( 0, $result['inventory']['total'] );
		$this->assertSame( 0, $result['meta']['total_scanned'] );
	}

	#[Test]
	public function run_correctly_wires_canvas_map(): void {
		$this->scanner->set_rows( [
			make_row( [ 'post_id' => 10, 'post_type' => 'et_template', 'post_content' => '' ] ),
			make_row( [ 'post_id' => 11, 'post_type' => 'et_header_layout', 'post_content' => '' ] ),
		] );
		$this->scanner->set_canvas_map( [ 10 => [ 'Header' => 11 ] ] );

		$result = $this->scanner->run();

		// Template row should have a canvases key.
		$template_row = $result['inventory']['rows'][0];
		$this->assertArrayHasKey( 'canvases', $template_row );
		$this->assertCount( 1, $template_row['canvases'] );
	}

	#[Test]
	public function run_produces_dso_usage_from_content(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-brand"}})$';
		$this->scanner->set_rows( [
			make_row( [ 'post_id' => 1, 'post_type' => 'page', 'post_content' => $content ] ),
		] );

		$result = $this->scanner->run();

		$this->assertArrayHasKey( 'gcid-brand', $result['dso_usage']['variables'] );
		$this->assertSame( 1, $result['dso_usage']['variables']['gcid-brand']['count'] );
	}

	// ── ajax_run() ────────────────────────────────────────────────────────────

	#[Test]
	public function ajax_run_sends_json_success_with_four_keys(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->scanner->ajax_run();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$data = $e->data;
			$this->assertArrayHasKey( 'active_content', $data );
			$this->assertArrayHasKey( 'inventory',      $data );
			$this->assertArrayHasKey( 'dso_usage',      $data );
			$this->assertArrayHasKey( 'meta',            $data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_run_returns_403_when_user_cannot_manage_options(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->scanner->ajax_run();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_run_returns_populated_data_with_rows(): void {
		$content = '"modulePreset":["preset-xyz"]';
		$this->scanner->set_rows( [
			make_row( [ 'post_id' => 5, 'post_type' => 'page', 'post_content' => $content ] ),
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->scanner->ajax_run();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertSame( 1, $e->data['active_content']['total'] );
			throw $e;
		}
	}

	// ── build_var_info_map() ───────────────────────────────────────────────────

	#[Test]
	public function build_var_info_map_returns_empty_when_no_vars(): void {
		$result = $this->scanner->pub_build_var_info_map();

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	#[Test]
	public function build_var_info_map_returns_non_color_var_with_correct_type_labels(): void {
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [
			'numbers' => [ 'gvid-spacing-sm' => [ 'label' => 'Spacing SM', 'value' => '8px' ] ],
			'fonts'   => [ 'gvid-font-body'  => [ 'label' => 'Body Font',  'value' => 'Inter' ] ],
			'images'  => [ 'gvid-logo'        => [ 'label' => 'Logo',       'value' => '' ] ],
			'strings' => [ 'gvid-tagline'     => [ 'label' => 'Tagline',    'value' => 'Hello' ] ],
			'links'   => [ 'gvid-cta-url'     => [ 'label' => 'CTA URL',    'value' => '#' ] ],
		];

		$result = $this->scanner->pub_build_var_info_map();

		$this->assertSame( 'Spacing SM', $result['gvid-spacing-sm']['label'] );
		$this->assertSame( 'Number',     $result['gvid-spacing-sm']['var_type'] );
		$this->assertSame( 'Body Font',  $result['gvid-font-body']['label'] );
		$this->assertSame( 'Font',       $result['gvid-font-body']['var_type'] );
		$this->assertSame( 'Logo',       $result['gvid-logo']['label'] );
		$this->assertSame( 'Image',      $result['gvid-logo']['var_type'] );
		$this->assertSame( 'Tagline',    $result['gvid-tagline']['label'] );
		$this->assertSame( 'Text',       $result['gvid-tagline']['var_type'] );
		$this->assertSame( 'CTA URL',    $result['gvid-cta-url']['label'] );
		$this->assertSame( 'Link',       $result['gvid-cta-url']['var_type'] );
	}

	#[Test]
	public function build_var_info_map_includes_global_colors_with_color_type(): void {
		$GLOBALS['_d5dsh_options']['et_divi'] = [
			'et_global_data' => [
				'global_colors' => [
					'gcid-primary' => [ 'label' => 'Primary', 'color' => '#ff0000' ],
					'gcid-accent'  => [ 'label' => 'Accent',  'color' => '#00ff00' ],
				],
			],
		];

		$result = $this->scanner->pub_build_var_info_map();

		$this->assertArrayHasKey( 'gcid-primary', $result );
		$this->assertSame( 'Primary', $result['gcid-primary']['label'] );
		$this->assertSame( 'Color',   $result['gcid-primary']['var_type'] );
		$this->assertSame( 'Accent',  $result['gcid-accent']['label'] );
		$this->assertSame( 'Color',   $result['gcid-accent']['var_type'] );
	}

	#[Test]
	public function build_var_info_map_handles_var_with_missing_label(): void {
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [
			'numbers' => [ 'gvid-no-label' => [ 'value' => '0px' ] ],
		];

		$result = $this->scanner->pub_build_var_info_map();

		$this->assertArrayHasKey( 'gvid-no-label', $result );
		$this->assertSame( '',       $result['gvid-no-label']['label'] );
		$this->assertSame( 'Number', $result['gvid-no-label']['var_type'] );
	}

	#[Test]
	public function build_var_info_map_ignores_non_array_type_groups(): void {
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [
			'numbers' => [ 'gvid-ok' => [ 'label' => 'OK', 'value' => '1px' ] ],
			'fonts'   => 'not-an-array',
		];

		$result = $this->scanner->pub_build_var_info_map();

		$this->assertArrayHasKey( 'gvid-ok', $result );
		$this->assertCount( 1, $result );
	}

	// ── run() var_info_map key ─────────────────────────────────────────────────

	#[Test]
	public function run_var_info_map_is_array(): void {
		$result = $this->scanner->run();

		$this->assertIsArray( $result['var_info_map'] );
	}

	#[Test]
	public function run_var_info_map_contains_injected_var(): void {
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [
			'numbers' => [ 'gvid-line-height' => [ 'label' => 'Line Height', 'value' => '1.5' ] ],
		];

		$result = $this->scanner->run();

		$this->assertArrayHasKey( 'gvid-line-height', $result['var_info_map'] );
		$this->assertSame( 'Line Height', $result['var_info_map']['gvid-line-height']['label'] );
		$this->assertSame( 'Number',      $result['var_info_map']['gvid-line-height']['var_type'] );
	}

	#[Test]
	public function run_var_info_map_contains_injected_color(): void {
		$GLOBALS['_d5dsh_options']['et_divi'] = [
			'et_global_data' => [
				'global_colors' => [
					'gcid-brand-blue' => [ 'label' => 'Brand Blue', 'color' => '#0066cc' ],
				],
			],
		];

		$result = $this->scanner->run();

		$this->assertArrayHasKey( 'gcid-brand-blue', $result['var_info_map'] );
		$this->assertSame( 'Brand Blue', $result['var_info_map']['gcid-brand-blue']['label'] );
		$this->assertSame( 'Color',      $result['var_info_map']['gcid-brand-blue']['var_type'] );
	}
}
