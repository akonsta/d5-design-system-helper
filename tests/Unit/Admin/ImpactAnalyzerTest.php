<?php
/**
 * Tests for ImpactAnalyzer — "what breaks if I delete this DSO?"
 *
 * Strategy:
 *   - TestableImpactAnalyzer subclass overrides load_content_rows() and
 *     build_preset_var_map() so tests can inject data without $wpdb.
 *   - PresetsRepository and VarsRepository are exercised via the global
 *     _d5dsh_options stub store (get_option / update_option stubs).
 *
 * Covers (20 test cases):
 *   analyze() routing                    (2 tests)
 *   analyze_variable()                   (6 tests)
 *   analyze_preset()                     (4 tests)
 *   helper methods                       (3 tests)
 *   ajax_analyze()                       (5 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\ImpactAnalyzer;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Exposes private/protected methods and allows data injection.
 */
class TestableImpactAnalyzer extends ImpactAnalyzer {

	/** @var array[] Injected content rows. */
	private array $injected_rows = [];

	/** @var array Injected preset→var map. */
	private array $injected_preset_var_map = [];

	public function set_rows( array $rows ): void {
		$this->injected_rows = $rows;
	}

	public function set_preset_var_map( array $map ): void {
		$this->injected_preset_var_map = $map;
	}

	protected function load_content_rows(): array {
		return $this->injected_rows;
	}

	protected function build_preset_var_map(): array {
		if ( ! empty( $this->injected_preset_var_map ) ) {
			return $this->injected_preset_var_map;
		}
		return parent::build_preset_var_map();
	}
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/**
 * Build a minimal content row fixture for ImpactAnalyzer tests.
 */
function ia_make_row( array $overrides = [] ): array {
	return array_merge( [
		'post_id'      => 1,
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Test Page',
		'post_content' => '',
	], $overrides );
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( ImpactAnalyzer::class )]
class ImpactAnalyzerTest extends TestCase {

	private TestableImpactAnalyzer $analyzer;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->analyzer = new TestableImpactAnalyzer();
	}

	// ── analyze() routing ─────────────────────────────────────────────────────

	#[Test]
	public function analyze_variable_returns_correct_dso_type(): void {
		$result = $this->analyzer->analyze( 'variable', 'gcid-test' );

		$this->assertSame( 'variable', $result['dso_type'] );
		$this->assertSame( 'gcid-test', $result['dso_id'] );
	}

	#[Test]
	public function analyze_preset_returns_correct_dso_type(): void {
		$result = $this->analyzer->analyze( 'preset', 'preset-abc' );

		$this->assertSame( 'preset', $result['dso_type'] );
		$this->assertSame( 'preset-abc', $result['dso_id'] );
	}

	// ── analyze_variable() ────────────────────────────────────────────────────

	#[Test]
	public function analyze_variable_returns_all_required_keys(): void {
		$result = $this->analyzer->analyze( 'variable', 'gcid-primary' );

		$this->assertArrayHasKey( 'label',              $result );
		$this->assertArrayHasKey( 'dso_type',           $result );
		$this->assertArrayHasKey( 'dso_id',             $result );
		$this->assertArrayHasKey( 'direct_content',     $result );
		$this->assertArrayHasKey( 'via_presets',        $result );
		$this->assertArrayHasKey( 'containing_presets', $result );
		$this->assertArrayHasKey( 'dep_tree',           $result );
	}

	#[Test]
	public function analyze_variable_detects_direct_reference_in_content(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-brand"}})$';
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_id' => 5, 'post_title' => 'Brand Page', 'post_content' => $content ] ),
		] );

		$result = $this->analyzer->analyze( 'variable', 'gcid-brand' );

		$this->assertCount( 1, $result['direct_content'] );
		$this->assertSame( 5, $result['direct_content'][0]['post_id'] );
	}

	#[Test]
	public function analyze_variable_does_not_include_unrelated_content(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-other"}})$';
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_content' => $content ] ),
		] );

		$result = $this->analyzer->analyze( 'variable', 'gcid-brand' );

		$this->assertSame( [], $result['direct_content'] );
	}

	#[Test]
	public function analyze_variable_deduplicates_direct_content(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-dup"}})$';
		// Inject the same row twice (simulates the same post appearing twice).
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_id' => 7, 'post_content' => $content ] ),
			ia_make_row( [ 'post_id' => 7, 'post_content' => $content ] ),
		] );

		$result = $this->analyzer->analyze( 'variable', 'gcid-dup' );

		$this->assertCount( 1, $result['direct_content'] );
	}

	#[Test]
	public function analyze_variable_via_preset_branch_populated(): void {
		// A preset that contains gcid-via.
		$this->analyzer->set_preset_var_map( [
			'preset-x' => [ [ 'type' => 'color', 'name' => 'gcid-via' ] ],
		] );

		// A content row that uses preset-x.
		$content = '"modulePreset":["preset-x"]';
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_id' => 8, 'post_title' => 'Via Page', 'post_content' => $content ] ),
		] );

		$result = $this->analyzer->analyze( 'variable', 'gcid-via' );

		$this->assertNotEmpty( $result['via_presets'] );
		$this->assertSame( 'preset-x', $result['via_presets'][0]['preset_id'] );
		$this->assertCount( 1, $result['via_presets'][0]['content'] );
	}

	#[Test]
	public function analyze_variable_dep_tree_root_has_variable_type(): void {
		$result = $this->analyzer->analyze( 'variable', 'gcid-tree-test' );

		$this->assertSame( 'variable', $result['dep_tree']['type'] );
		$this->assertSame( 'gcid-tree-test', $result['dep_tree']['id'] );
	}

	// ── analyze_preset() ─────────────────────────────────────────────────────

	#[Test]
	public function analyze_preset_returns_all_required_keys(): void {
		$result = $this->analyzer->analyze( 'preset', 'preset-test' );

		$this->assertArrayHasKey( 'label',              $result );
		$this->assertArrayHasKey( 'dso_type',           $result );
		$this->assertArrayHasKey( 'direct_content',     $result );
		$this->assertArrayHasKey( 'via_presets',        $result );
		$this->assertArrayHasKey( 'dep_tree',           $result );
	}

	#[Test]
	public function analyze_preset_finds_content_that_uses_preset(): void {
		$content = '"modulePreset":["preset-abc"]';
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_id' => 10, 'post_content' => $content ] ),
		] );
		$this->analyzer->set_preset_var_map( [ 'preset-abc' => [] ] );

		$result = $this->analyzer->analyze( 'preset', 'preset-abc' );

		$this->assertCount( 1, $result['direct_content'] );
	}

	#[Test]
	public function analyze_preset_via_presets_is_empty_array(): void {
		$result = $this->analyzer->analyze( 'preset', 'preset-xyz' );

		$this->assertSame( [], $result['via_presets'] );
	}

	#[Test]
	public function analyze_preset_dep_tree_root_has_preset_type(): void {
		$result = $this->analyzer->analyze( 'preset', 'preset-tree' );

		$this->assertSame( 'preset', $result['dep_tree']['type'] );
		$this->assertSame( 'preset-tree', $result['dep_tree']['id'] );
	}

	// ── Helper methods ────────────────────────────────────────────────────────

	#[Test]
	public function analyze_variable_label_falls_back_to_id(): void {
		// No vars in option store — label should be the raw ID.
		$result = $this->analyzer->analyze( 'variable', 'gcid-no-label' );

		$this->assertSame( 'gcid-no-label', $result['label'] );
	}

	#[Test]
	public function analyze_preset_label_falls_back_to_id(): void {
		$result = $this->analyzer->analyze( 'preset', 'preset-no-label' );

		$this->assertSame( 'preset-no-label', $result['label'] );
	}

	#[Test]
	public function dep_tree_has_direct_branch_when_direct_content_exists(): void {
		$content = '$variable({"type":"color","value":{"name":"gcid-direct-tree"}})$';
		$this->analyzer->set_rows( [
			ia_make_row( [ 'post_content' => $content ] ),
		] );

		$result = $this->analyzer->analyze( 'variable', 'gcid-direct-tree' );
		$child_ids = array_column( $result['dep_tree']['children'], 'id' );

		$this->assertContains( '__direct__', $child_ids );
	}

	// ── ajax_analyze() ────────────────────────────────────────────────────────

	#[Test]
	public function ajax_analyze_returns_403_when_no_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->analyzer->ajax_analyze();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_analyze_returns_400_when_body_not_json(): void {
		$GLOBALS['_d5dsh_php_input'] = 'not json';

		$this->expectException( JsonResponseException::class );

		try {
			$this->analyzer->ajax_analyze();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_analyze_returns_400_when_dso_type_missing(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'dso_id' => 'gcid-x' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->analyzer->ajax_analyze();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_analyze_returns_400_when_dso_type_invalid(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'dso_type' => 'widget', 'dso_id' => 'xyz' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->analyzer->ajax_analyze();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_analyze_returns_success_for_valid_variable_request(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'dso_type' => 'variable', 'dso_id' => 'gcid-test' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->analyzer->ajax_analyze();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'label', $e->data );
			$this->assertArrayHasKey( 'dep_tree', $e->data );
			throw $e;
		}
	}
}
