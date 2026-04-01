<?php
/**
 * Tests for PreImportAuditor — five-check pre-import audit.
 *
 * Strategy:
 *   - Seed $GLOBALS['_d5dsh_options'] so that VarsRepository and PresetsRepository
 *     return controlled site data (same pattern as AuditEngineTest / SimpleImporterTest).
 *   - Call static private check methods via ReflectionMethod through a thin
 *     TestablePreImportAuditor subclass.
 *   - For integration tests, call PreImportAuditor::run() directly after seeding options.
 *
 * Covers (47 test cases):
 *   Helpers        detect_name_style             (6 tests)
 *   Helpers        extract_var_refs_from_attrs   (4 tests)
 *   Helpers        build_site_var_index          (3 tests)
 *   Helpers        extract_file_vars             (4 tests)
 *   E1             check_broken_refs_in_file     (6 tests)
 *   W1             check_conflict_overwrite      (5 tests)
 *   W2             check_label_clash             (5 tests)
 *   A1             check_orphaned_in_file        (5 tests)
 *   A2             check_naming_convention       (5 tests)
 *   run()          integration / meta shape      (4 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\PreImportAuditor;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass — exposes static private methods ─────────────────────────

class TestablePreImportAuditor extends PreImportAuditor {

	public static function pub_detect_name_style( string $label ): ?string {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'detect_name_style' );
		$m->setAccessible( true );
		return $m->invoke( null, $label );
	}

	public static function pub_extract_var_refs( array $attrs ): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'extract_var_refs_from_attrs' );
		$m->setAccessible( true );
		return $m->invoke( null, $attrs );
	}

	public static function pub_build_site_var_index( array $raw_vars, array $raw_colors ): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'build_site_var_index' );
		$m->setAccessible( true );
		return $m->invoke( null, $raw_vars, $raw_colors );
	}

	public static function pub_extract_file_vars( array $data, string $type ): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'extract_file_vars' );
		$m->setAccessible( true );
		return $m->invoke( null, $data, $type );
	}

	public static function pub_extract_file_presets( array $data, string $type ): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'extract_file_presets' );
		$m->setAccessible( true );
		return $m->invoke( null, $data, $type );
	}

	public static function pub_check_broken_refs(
		array $data,
		string $type,
		array $file_var_index,
		array $site_var_index
	): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'check_broken_refs_in_file' );
		$m->setAccessible( true );
		return $m->invoke( null, $data, $type, $file_var_index, $site_var_index );
	}

	public static function pub_check_conflict_overwrite(
		array $file_var_index,
		array $site_var_index
	): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'check_conflict_overwrite' );
		$m->setAccessible( true );
		return $m->invoke( null, $file_var_index, $site_var_index );
	}

	public static function pub_check_label_clash(
		array $file_var_index,
		array $site_var_index
	): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'check_label_clash' );
		$m->setAccessible( true );
		return $m->invoke( null, $file_var_index, $site_var_index );
	}

	public static function pub_check_orphaned_in_file(
		array $file_var_index,
		array $data,
		string $type
	): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'check_orphaned_in_file' );
		$m->setAccessible( true );
		return $m->invoke( null, $file_var_index, $data, $type );
	}

	public static function pub_check_naming_convention(
		array $file_var_index,
		array $site_var_index
	): array {
		$m = new \ReflectionMethod( PreImportAuditor::class, 'check_naming_convention' );
		$m->setAccessible( true );
		return $m->invoke( null, $file_var_index, $site_var_index );
	}
}

// ── Fixture helpers ────────────────────────────────────────────────────────────

/**
 * Seed WP options for VarsRepository / PresetsRepository used by PreImportAuditor::run().
 *
 * @param array $gvid_vars   Map of gvid-* id => field overrides for et_divi_global_variables
 * @param array $colors      Map of gcid-* id => field overrides for et_divi global_colors
 * @param array $presets     Raw presets structure {module:[], group:[]}
 */
function pia_seed( array $gvid_vars = [], array $colors = [], array $presets = [] ): void {
	// Build et_divi_global_variables
	$numbers = [];
	foreach ( $gvid_vars as $id => $fields ) {
		$numbers[ $id ] = array_merge(
			[ 'id' => $id, 'label' => $id, 'value' => '16px', 'status' => 'active' ],
			$fields
		);
	}
	$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = $numbers ? [ 'numbers' => $numbers ] : [];

	// Build et_divi (global colors stored inside et_global_data.global_colors)
	$gc = [];
	foreach ( $colors as $id => $fields ) {
		$gc[ $id ] = array_merge(
			[ 'id' => $id, 'label' => $id, 'color' => '#aabbcc', 'status' => 'active' ],
			$fields
		);
	}
	$GLOBALS['_d5dsh_options']['et_divi'] = [
		'et_global_data' => [ 'global_colors' => $gc ],
	];

	// Build presets
	$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = $presets ?: [
		'module' => [],
		'group'  => [],
	];
}

/**
 * Build a minimal vars-type file data array.
 */
function pia_vars_file( array $vars ): array {
	$items = [];
	foreach ( $vars as $id => $fields ) {
		$items[ $id ] = array_merge(
			[ 'id' => $id, 'label' => $id, 'value' => '16px', 'status' => 'active' ],
			$fields
		);
	}
	return [ 'et_divi_global_variables' => [ 'numbers' => $items ] ];
}

/**
 * Build an et_native-type file data array with global_variables and optional presets.
 */
function pia_et_native_file( array $vars, array $presets = [] ): array {
	$items = [];
	foreach ( $vars as $id => $fields ) {
		$items[] = array_merge(
			[ 'id' => $id, 'label' => $id, 'value' => '16px', 'variableType' => 'numbers' ],
			$fields
		);
	}
	return [
		'global_variables' => $items,
		'global_colors'    => [],
		'presets'          => $presets ?: [ 'module' => [], 'group' => [] ],
	];
}

/**
 * Build a $variable()$ token string as used in preset attrs values.
 * Format: $variable(type:id)$
 */
function pia_var_token( string $id, string $type = 'color' ): string {
	return '$variable(' . $type . ':' . $id . ')$';
}

/**
 * Build a presets-type file data array with one preset in module section.
 */
function pia_presets_file( string $preset_id, string $var_id, string $mod = 'divi/button' ): array {
	$token = pia_var_token( $var_id );
	return [
		'et_divi_builder_global_presets_d5' => [
			'module' => [
				$mod => [
					'items' => [
						$preset_id => [
							'name'  => 'Preset ' . $preset_id,
							'attrs' => [ 'color' => $token ],
						],
					],
				],
			],
			'group' => [],
		],
	];
}

// ── Test class ─────────────────────────────────────────────────────────────────

#[CoversClass( PreImportAuditor::class )]
class PreImportAuditorTest extends TestCase {

	protected function setUp(): void {
		_d5dsh_reset_stubs();
	}

	// =========================================================================
	// detect_name_style
	// =========================================================================

	#[Test]
	public function detect_name_style_kebab(): void {
		$this->assertSame( 'kebab-case', TestablePreImportAuditor::pub_detect_name_style( 'primary-color' ) );
	}

	#[Test]
	public function detect_name_style_snake(): void {
		$this->assertSame( 'snake_case', TestablePreImportAuditor::pub_detect_name_style( 'primary_color' ) );
	}

	#[Test]
	public function detect_name_style_title_case(): void {
		$this->assertSame( 'Title Case', TestablePreImportAuditor::pub_detect_name_style( 'Primary Color' ) );
	}

	#[Test]
	public function detect_name_style_camel_case(): void {
		$this->assertSame( 'camelCase', TestablePreImportAuditor::pub_detect_name_style( 'primaryColor' ) );
	}

	#[Test]
	public function detect_name_style_empty_returns_null(): void {
		$this->assertNull( TestablePreImportAuditor::pub_detect_name_style( '' ) );
	}

	#[Test]
	public function detect_name_style_ambiguous_single_word_returns_null(): void {
		// 'Red' — starts with uppercase, no space — does not match Title Case pattern
		$this->assertNull( TestablePreImportAuditor::pub_detect_name_style( 'Red' ) );
	}

	// =========================================================================
	// extract_var_refs_from_attrs
	// =========================================================================

	#[Test]
	public function extract_var_refs_returns_ids_from_variable_tokens(): void {
		$attrs = [ 'color' => pia_var_token( 'gcid-abc123' ) ];
		$refs  = TestablePreImportAuditor::pub_extract_var_refs( $attrs );
		$this->assertContains( 'gcid-abc123', $refs );
	}

	#[Test]
	public function extract_var_refs_deduplicates_same_id(): void {
		$token = pia_var_token( 'gcid-dup' );
		$attrs = [ 'color1' => $token, 'color2' => $token ];
		$refs  = TestablePreImportAuditor::pub_extract_var_refs( $attrs );
		$this->assertCount( 1, $refs );
	}

	#[Test]
	public function extract_var_refs_returns_empty_for_no_tokens(): void {
		$refs = TestablePreImportAuditor::pub_extract_var_refs( [ 'color' => '#ff0000' ] );
		$this->assertEmpty( $refs );
	}

	#[Test]
	public function extract_var_refs_ignores_non_string_values(): void {
		$refs = TestablePreImportAuditor::pub_extract_var_refs( [ 'key' => [ 'nested' => 'value' ] ] );
		$this->assertEmpty( $refs );
	}

	// =========================================================================
	// build_site_var_index
	// =========================================================================

	#[Test]
	public function build_site_var_index_from_vars(): void {
		$raw_vars = [
			'numbers' => [
				'gvid-001' => [ 'label' => 'Base Size', 'value' => '16px' ],
			],
		];
		$index = TestablePreImportAuditor::pub_build_site_var_index( $raw_vars, [] );
		$this->assertArrayHasKey( 'gvid-001', $index );
		$this->assertSame( 'Base Size', $index['gvid-001']['label'] );
	}

	#[Test]
	public function build_site_var_index_from_colors(): void {
		$raw_colors = [
			'gcid-c01' => [ 'label' => 'Brand Blue', 'color' => '#0000ff' ],
		];
		$index = TestablePreImportAuditor::pub_build_site_var_index( [], $raw_colors );
		$this->assertArrayHasKey( 'gcid-c01', $index );
		$this->assertSame( 'colors', $index['gcid-c01']['var_type'] );
	}

	#[Test]
	public function build_site_var_index_merges_vars_and_colors(): void {
		$raw_vars   = [ 'numbers' => [ 'gvid-v01' => [ 'label' => 'V', 'value' => '1px' ] ] ];
		$raw_colors = [ 'gcid-c01' => [ 'label' => 'C', 'color' => '#fff' ] ];
		$index = TestablePreImportAuditor::pub_build_site_var_index( $raw_vars, $raw_colors );
		$this->assertCount( 2, $index );
	}

	// =========================================================================
	// extract_file_vars
	// =========================================================================

	#[Test]
	public function extract_file_vars_from_vars_type(): void {
		$data  = pia_vars_file( [ 'gvid-fv01' => [ 'label' => 'File Var', 'value' => '8px' ] ] );
		$index = TestablePreImportAuditor::pub_extract_file_vars( $data, 'vars' );
		$this->assertArrayHasKey( 'gvid-fv01', $index );
		$this->assertSame( 'File Var', $index['gvid-fv01']['label'] );
	}

	#[Test]
	public function extract_file_vars_from_et_native_type(): void {
		$data  = pia_et_native_file( [ 'gvid-n01' => [ 'label' => 'Native Var' ] ] );
		$index = TestablePreImportAuditor::pub_extract_file_vars( $data, 'et_native' );
		$this->assertArrayHasKey( 'gvid-n01', $index );
	}

	#[Test]
	public function extract_file_vars_returns_empty_for_unknown_type(): void {
		$index = TestablePreImportAuditor::pub_extract_file_vars( [], 'dtcg' );
		$this->assertEmpty( $index );
	}

	#[Test]
	public function extract_file_vars_returns_empty_for_empty_data(): void {
		$index = TestablePreImportAuditor::pub_extract_file_vars( [], 'vars' );
		$this->assertEmpty( $index );
	}

	// =========================================================================
	// E1 — check_broken_refs_in_file
	// =========================================================================

	#[Test]
	public function e1_no_error_when_ref_id_is_in_file(): void {
		// Use 'presets' type so extract_file_presets can find the preset
		$var_id = 'gvid-infile';
		$token  = pia_var_token( $var_id );
		$data   = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'preset-1' => [ 'name' => 'P1', 'attrs' => [ 'color' => $token ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$file_var_index = [ $var_id => [ 'label' => 'In File', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$result = TestablePreImportAuditor::pub_check_broken_refs( $data, 'presets', $file_var_index, [] );
		$this->assertSame( 'broken_refs_in_file', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e1_no_error_when_ref_id_is_on_site(): void {
		$var_id = 'gvid-onsite';
		$token  = pia_var_token( $var_id );
		$data   = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'preset-x' => [ 'name' => 'Px', 'attrs' => [ 'color' => $token ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$site_var_index = [ $var_id => [ 'label' => 'On Site', 'value' => '', 'var_type' => 'numbers' ] ];
		$result = TestablePreImportAuditor::pub_check_broken_refs( $data, 'presets', [], $site_var_index );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e1_error_when_ref_id_absent_from_file_and_site(): void {
		$missing_id = 'gvid-missing';
		$token      = pia_var_token( $missing_id );
		$data       = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'preset-bad' => [ 'name' => 'Bad', 'attrs' => [ 'color' => $token ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$result = TestablePreImportAuditor::pub_check_broken_refs( $data, 'presets', [], [] );
		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( $missing_id, $result['items'][0]['detail'] );
	}

	#[Test]
	public function e1_divi_builtin_id_is_never_flagged(): void {
		$builtin = 'gvid-r41n4b9xo4';
		$token   = pia_var_token( $builtin );
		$data    = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/text' => [
						'items' => [
							'preset-bi' => [ 'name' => 'BI', 'attrs' => [ 'body' => $token ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$result = TestablePreImportAuditor::pub_check_broken_refs( $data, 'presets', [], [] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e1_only_first_broken_ref_flagged_per_preset(): void {
		$id1  = 'gvid-miss-1';
		$id2  = 'gvid-miss-2';
		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'p1' => [
								'name'  => 'P1',
								'attrs' => [
									'color1' => pia_var_token( $id1 ),
									'color2' => pia_var_token( $id2 ),
								],
							],
						],
					],
				],
				'group' => [],
			],
		];
		// Both IDs missing — but only 1 item per preset (break after first)
		$result = TestablePreImportAuditor::pub_check_broken_refs( $data, 'presets', [], [] );
		$this->assertCount( 1, $result['items'] );
	}

	#[Test]
	public function e1_no_presets_returns_empty(): void {
		$result = TestablePreImportAuditor::pub_check_broken_refs( [], 'presets', [], [] );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// W1 — check_conflict_overwrite
	// =========================================================================

	#[Test]
	public function w1_no_warning_when_id_not_on_site(): void {
		$file_idx = [ 'gvid-new' => [ 'label' => 'New', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_conflict_overwrite( $file_idx, [] );
		$this->assertSame( 'conflict_overwrite', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w1_no_warning_when_label_and_value_identical(): void {
		$id       = 'gvid-same';
		$file_idx = [ $id => [ 'label' => 'Same', 'value' => '16px', 'var_type' => 'numbers' ] ];
		$site_idx = [ $id => [ 'label' => 'Same', 'value' => '16px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_conflict_overwrite( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w1_warning_when_value_changes(): void {
		$id       = 'gvid-val-change';
		$file_idx = [ $id => [ 'label' => 'Spacing', 'value' => '24px', 'var_type' => 'numbers' ] ];
		$site_idx = [ $id => [ 'label' => 'Spacing', 'value' => '16px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_conflict_overwrite( $file_idx, $site_idx );
		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( '16px', $result['items'][0]['detail'] );
		$this->assertStringContainsString( '24px', $result['items'][0]['detail'] );
	}

	#[Test]
	public function w1_warning_when_label_changes(): void {
		$id       = 'gvid-lbl-change';
		$file_idx = [ $id => [ 'label' => 'Renamed', 'value' => '16px', 'var_type' => 'numbers' ] ];
		$site_idx = [ $id => [ 'label' => 'Original', 'value' => '16px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_conflict_overwrite( $file_idx, $site_idx );
		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'label', $result['items'][0]['detail'] );
	}

	#[Test]
	public function w1_no_warning_when_site_value_is_empty(): void {
		// Site stores empty value — no meaningful comparison possible
		$id       = 'gvid-empty-site';
		$file_idx = [ $id => [ 'label' => 'X', 'value' => '8px', 'var_type' => 'numbers' ] ];
		$site_idx = [ $id => [ 'label' => '', 'value' => '', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_conflict_overwrite( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// W2 — check_label_clash
	// =========================================================================

	#[Test]
	public function w2_no_warning_when_no_label_overlap(): void {
		$file_idx = [ 'gvid-f1' => [ 'label' => 'Unique Label', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$site_idx = [ 'gvid-s1' => [ 'label' => 'Different',    'value' => '2px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_label_clash( $file_idx, $site_idx );
		$this->assertSame( 'label_clash', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w2_no_warning_when_same_id_same_label(): void {
		// Same ID — this is a conflict_overwrite scenario, not a label clash
		$id       = 'gvid-same';
		$file_idx = [ $id => [ 'label' => 'Primary Color', 'value' => '#fff', 'var_type' => 'colors' ] ];
		$site_idx = [ $id => [ 'label' => 'Primary Color', 'value' => '#fff', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_label_clash( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w2_warning_when_different_id_shares_label(): void {
		$file_idx = [ 'gvid-file-1' => [ 'label' => 'Primary Color', 'value' => '#fff', 'var_type' => 'colors' ] ];
		$site_idx = [ 'gvid-site-2' => [ 'label' => 'Primary Color', 'value' => '#eee', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_label_clash( $file_idx, $site_idx );
		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'gvid-site-2', $result['items'][0]['detail'] );
	}

	#[Test]
	public function w2_case_insensitive_label_match(): void {
		$file_idx = [ 'gvid-a' => [ 'label' => 'primary color', 'value' => '', 'var_type' => 'colors' ] ];
		$site_idx = [ 'gvid-b' => [ 'label' => 'Primary Color', 'value' => '', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_label_clash( $file_idx, $site_idx );
		$this->assertCount( 1, $result['items'] );
	}

	#[Test]
	public function w2_no_warning_for_empty_label(): void {
		$file_idx = [ 'gvid-empty' => [ 'label' => '', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$site_idx = [ 'gvid-other' => [ 'label' => '', 'value' => '2px', 'var_type' => 'numbers' ] ];
		$result   = TestablePreImportAuditor::pub_check_label_clash( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// A1 — check_orphaned_in_file
	// =========================================================================

	#[Test]
	public function a1_no_advisory_when_file_has_no_vars(): void {
		$result = TestablePreImportAuditor::pub_check_orphaned_in_file( [], [], 'vars' );
		$this->assertSame( 'orphaned_in_file', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a1_no_advisory_when_file_has_no_presets(): void {
		// Vars exist but no presets — suppress to avoid noise
		$file_vars = [ 'gvid-v' => [ 'label' => 'V', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$result    = TestablePreImportAuditor::pub_check_orphaned_in_file( $file_vars, [], 'vars' );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a1_no_advisory_when_var_is_referenced_by_preset(): void {
		// Use 'presets' type so extract_file_presets finds the preset
		$var_id    = 'gvid-used';
		$data      = pia_presets_file( 'preset-r', $var_id );
		$file_vars = [ $var_id => [ 'label' => 'Used', 'value' => '1px', 'var_type' => 'numbers' ] ];
		$result    = TestablePreImportAuditor::pub_check_orphaned_in_file( $file_vars, $data, 'presets' );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a1_advisory_when_var_is_not_referenced_by_any_preset(): void {
		$used_id   = 'gvid-used';
		$orphan_id = 'gvid-orphan';
		$token     = pia_var_token( $used_id );
		$data      = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'p1' => [ 'name' => 'P1', 'attrs' => [ 'color' => $token ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$file_vars = [
			$used_id   => [ 'label' => 'Used',   'value' => '1px', 'var_type' => 'numbers' ],
			$orphan_id => [ 'label' => 'Orphan', 'value' => '2px', 'var_type' => 'numbers' ],
		];
		$result = TestablePreImportAuditor::pub_check_orphaned_in_file( $file_vars, $data, 'presets' );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $orphan_id, $result['items'][0]['id'] );
	}

	#[Test]
	public function a1_advisory_lists_all_unreferenced_vars(): void {
		$orphan1 = 'gvid-o1';
		$orphan2 = 'gvid-o2';
		$used    = 'gvid-used';
		$data    = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/text' => [
						'items' => [
							'p1' => [ 'name' => 'P1', 'attrs' => [ 'color' => pia_var_token( $used ) ] ],
						],
					],
				],
				'group' => [],
			],
		];
		$file_vars = [
			$used    => [ 'label' => 'Used',    'value' => '1px', 'var_type' => 'numbers' ],
			$orphan1 => [ 'label' => 'Orphan1', 'value' => '2px', 'var_type' => 'numbers' ],
			$orphan2 => [ 'label' => 'Orphan2', 'value' => '3px', 'var_type' => 'numbers' ],
		];
		$result = TestablePreImportAuditor::pub_check_orphaned_in_file( $file_vars, $data, 'presets' );
		$this->assertCount( 2, $result['items'] );
	}

	// =========================================================================
	// A2 — check_naming_convention
	// =========================================================================

	#[Test]
	public function a2_no_advisory_when_file_vars_empty(): void {
		$result = TestablePreImportAuditor::pub_check_naming_convention( [], [] );
		$this->assertSame( 'naming_convention', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a2_no_advisory_when_site_has_fewer_than_4_vars_of_type(): void {
		// Only 3 site vars — not enough to establish dominant style
		$site_idx = [
			's1' => [ 'label' => 'primary-color', 'value' => '', 'var_type' => 'colors' ],
			's2' => [ 'label' => 'secondary-color', 'value' => '', 'var_type' => 'colors' ],
			's3' => [ 'label' => 'accent-color',    'value' => '', 'var_type' => 'colors' ],
		];
		$file_idx = [ 'f1' => [ 'label' => 'Brand Color', 'value' => '', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_naming_convention( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a2_advisory_when_file_uses_different_style_from_dominant(): void {
		// Site uses kebab-case (4/4) — file var uses Title Case
		$site_idx = [
			's1' => [ 'label' => 'primary-color',   'value' => '', 'var_type' => 'colors' ],
			's2' => [ 'label' => 'secondary-color',  'value' => '', 'var_type' => 'colors' ],
			's3' => [ 'label' => 'accent-color',     'value' => '', 'var_type' => 'colors' ],
			's4' => [ 'label' => 'background-color', 'value' => '', 'var_type' => 'colors' ],
		];
		$file_idx = [ 'f1' => [ 'label' => 'Brand Blue', 'value' => '', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_naming_convention( $file_idx, $site_idx );
		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'kebab-case', $result['items'][0]['detail'] );
	}

	#[Test]
	public function a2_no_advisory_when_file_matches_dominant_style(): void {
		$site_idx = [
			's1' => [ 'label' => 'primary-color',   'value' => '', 'var_type' => 'colors' ],
			's2' => [ 'label' => 'secondary-color',  'value' => '', 'var_type' => 'colors' ],
			's3' => [ 'label' => 'accent-color',     'value' => '', 'var_type' => 'colors' ],
			's4' => [ 'label' => 'background-color', 'value' => '', 'var_type' => 'colors' ],
		];
		$file_idx = [ 'f1' => [ 'label' => 'brand-blue', 'value' => '', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_naming_convention( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a2_no_advisory_when_site_has_no_dominant_style(): void {
		// Mixed styles — no style exceeds 60%
		$site_idx = [
			's1' => [ 'label' => 'primary-color',  'value' => '', 'var_type' => 'colors' ],
			's2' => [ 'label' => 'Primary Color',  'value' => '', 'var_type' => 'colors' ],
			's3' => [ 'label' => 'primaryColor',   'value' => '', 'var_type' => 'colors' ],
			's4' => [ 'label' => 'primary_color',  'value' => '', 'var_type' => 'colors' ],
		];
		$file_idx = [ 'f1' => [ 'label' => 'brand-blue', 'value' => '', 'var_type' => 'colors' ] ];
		$result   = TestablePreImportAuditor::pub_check_naming_convention( $file_idx, $site_idx );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// run() — integration tests / meta shape
	// =========================================================================

	#[Test]
	public function run_returns_required_keys(): void {
		pia_seed();
		$result = PreImportAuditor::run( [], 'vars', 'test.json' );
		$this->assertArrayHasKey( 'errors', $result );
		$this->assertArrayHasKey( 'warnings', $result );
		$this->assertArrayHasKey( 'advisories', $result );
		$this->assertArrayHasKey( 'meta', $result );
	}

	#[Test]
	public function run_meta_contains_expected_fields(): void {
		pia_seed();
		$result = PreImportAuditor::run( [], 'vars', 'my-file.json' );
		$meta   = $result['meta'];
		$this->assertSame( 'pre_import', $meta['audit_target'] );
		$this->assertSame( 'my-file.json', $meta['filename'] );
		$this->assertSame( 'vars', $meta['file_type'] );
		$this->assertFalse( $meta['is_full'] );
		$this->assertArrayHasKey( 'ran_at', $meta );
		$this->assertStringEndsWith( 'UTC', $meta['ran_at'] );
	}

	#[Test]
	public function run_empty_file_produces_no_findings(): void {
		pia_seed();
		$result = PreImportAuditor::run( [], 'vars', 'empty.json' );
		$this->assertEmpty( $result['errors'] );
		$this->assertEmpty( $result['warnings'] );
		$this->assertEmpty( $result['advisories'] );
	}

	#[Test]
	public function run_detects_conflict_overwrite_with_real_site_data(): void {
		// Seed a variable on the site, then import a file that changes its value.
		pia_seed( [ 'gvid-existing' => [ 'label' => 'Spacing Base', 'value' => '16px' ] ] );

		$file_data = pia_vars_file( [
			'gvid-existing' => [ 'label' => 'Spacing Base', 'value' => '24px' ],
		] );

		$result = PreImportAuditor::run( $file_data, 'vars', 'import.json' );

		// The conflict_overwrite check should appear in warnings.
		$warning_checks = array_column( $result['warnings'], 'check' );
		$this->assertContains( 'conflict_overwrite', $warning_checks );
	}
}
