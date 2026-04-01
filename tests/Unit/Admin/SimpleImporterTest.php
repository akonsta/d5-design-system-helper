<?php
/**
 * Tests for the dependency-analysis methods added to SimpleImporter.
 *
 * The three methods under test are private:
 *   - extract_variable_refs( string $raw ): array
 *   - extract_preset_refs( string $post_content ): array
 *   - build_dependency_report( array $data, string $type ): array
 *
 * Strategy: Use a TestableSimpleImporter subclass with ReflectionMethod wrappers
 * (identical pattern to TestableValidator in ValidatorTest.php).
 *
 * build_dependency_report() calls VarsRepository and PresetsRepository, which
 * call get_option(). Tests seed $GLOBALS['_d5dsh_options'] directly with the
 * wp_options keys those repositories read.
 *
 * Covers:
 *   extract_variable_refs: normal $variable()$ syntax, \u0022-encoded quotes,
 *                          multiple refs, no refs, malformed payload
 *   extract_preset_refs:   modulePreset refs, presetId refs, mixed, none, dedup
 *   build_dependency_report: all-clear (all IDs on site), missing vars,
 *                             missing presets, Divi built-in IDs (informational),
 *                             et_native self-contained file, graceful empty on
 *                             exception, has_warnings flag
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\SimpleImporter;
use D5DesignSystemHelper\Util\DiviBlocParser;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ──────────────────────────────────────────────────────────

/**
 * Exposes SimpleImporter private helpers as public methods for white-box testing.
 *
 * Note: extract_variable_refs() and extract_preset_refs() were moved to
 * DiviBlocParser in v0.6.9.15.14.9. The wrappers below delegate there so
 * all existing test cases remain valid without modification.
 */
class TestableSimpleImporter extends SimpleImporter {

	public function pub_extract_variable_refs( string $raw ): array {
		return DiviBlocParser::extract_variable_refs( $raw );
	}

	public function pub_extract_preset_refs( string $post_content ): array {
		return DiviBlocParser::extract_preset_refs( $post_content );
	}

	public function pub_build_dependency_report( array $data, string $type ): array {
		$m = new \ReflectionMethod( SimpleImporter::class, 'build_dependency_report' );
		$m->setAccessible( true );
		return $m->invoke( $this, $data, $type );
	}

	/** Expose build_manifest_items (protected) for testing. */
	public function pub_build_manifest_items( array $data, string $type ): array {
		return $this->build_manifest_items( $data, $type );
	}

	/** Expose import_json_vars (private via reflection) for testing with label overrides. */
	public function pub_import_json_vars( array $data, array $label_overrides = [] ): array {
		$m = new \ReflectionMethod( SimpleImporter::class, 'import_json_vars' );
		$m->setAccessible( true );
		return $m->invoke( $this, $data, $label_overrides );
	}

	/** Expose import_json_presets (private via reflection) for testing with label overrides. */
	public function pub_import_json_presets( array $data, array $label_overrides = [] ): array {
		$m = new \ReflectionMethod( SimpleImporter::class, 'import_json_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $data, $label_overrides );
	}
}

// ── Test helpers ──────────────────────────────────────────────────────────────

/**
 * Return a minimal et_divi option value containing the given color IDs.
 *
 * VarsRepository::get_raw_colors() reads:
 *   et_divi['et_global_data']['global_colors']
 *
 * @param string[] $color_ids gcid-* IDs to register as known site colors.
 */
function make_et_divi_option( array $color_ids = [] ): array {
	$global_colors = [];
	foreach ( $color_ids as $id ) {
		$global_colors[ $id ] = [
			'id'     => $id,
			'label'  => $id,
			'color'  => '#aabbcc',
			'status' => 'active',
			'order'  => 1,
		];
	}
	return [
		'et_global_data' => [
			'global_colors' => $global_colors,
		],
	];
}

/**
 * Seed the WP options store with color IDs and presets.
 *
 * Color IDs are stored in et_divi[et_global_data][global_colors] (the path
 * VarsRepository::get_raw_colors() reads).
 *
 * Non-color gvid-* IDs go into et_divi_global_variables['numbers'].
 *
 * @param string[] $color_ids  gcid-* IDs to put in et_divi as known site colors.
 * @param array    $presets    Preset data for et_divi_builder_global_presets_d5.
 *                             Format: [ 'module' => [...], 'group' => [...] ]
 * @param string[] $gvid_ids   gvid-* non-color IDs for et_divi_global_variables.
 */
function seed_site_data( array $color_ids = [], array $presets = [], array $gvid_ids = [] ): void {
	// et_divi carries global colors (path get_raw_colors() reads).
	$GLOBALS['_d5dsh_options']['et_divi'] = make_et_divi_option( $color_ids );

	// et_divi_global_variables carries non-color variables.
	$numbers = [];
	foreach ( $gvid_ids as $id ) {
		$numbers[ $id ] = [ 'id' => $id, 'label' => $id, 'value' => '16px', 'status' => 'active' ];
	}
	$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = $numbers ? [ 'numbers' => $numbers ] : [];

	// Presets option.
	$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = $presets ?: [
		'module' => [],
		'group'  => [],
	];
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( SimpleImporter::class )]
class SimpleImporterTest extends TestCase {

	private TestableSimpleImporter $si;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->si = new TestableSimpleImporter();
	}

	// =========================================================================
	// validate_path_within (path traversal prevention)
	// =========================================================================

	#[Test]
	public function validate_path_within_returns_real_path_for_valid_child(): void {
		// Create a temp directory structure for testing.
		$base_dir = sys_get_temp_dir() . '/d5dsh_test_' . uniqid();
		$sub_dir  = $base_dir . '/subdir';
		mkdir( $sub_dir, 0755, true );
		$file = $sub_dir . '/file.txt';
		file_put_contents( $file, 'test' );

		$result = SimpleImporter::validate_path_within( $file, $base_dir );

		$this->assertNotFalse( $result );
		$this->assertSame( realpath( $file ), $result );

		// Cleanup.
		unlink( $file );
		rmdir( $sub_dir );
		rmdir( $base_dir );
	}

	#[Test]
	public function validate_path_within_rejects_path_traversal(): void {
		// Create a temp directory structure.
		$base_dir   = sys_get_temp_dir() . '/d5dsh_test_' . uniqid();
		$sibling    = sys_get_temp_dir() . '/d5dsh_sibling_' . uniqid();
		mkdir( $base_dir, 0755, true );
		mkdir( $sibling, 0755, true );
		$sibling_file = $sibling . '/secret.txt';
		file_put_contents( $sibling_file, 'secret' );

		// Try to access sibling via path traversal.
		$malicious_path = $base_dir . '/../' . basename( $sibling ) . '/secret.txt';
		$result = SimpleImporter::validate_path_within( $malicious_path, $base_dir );

		$this->assertFalse( $result );

		// Cleanup.
		unlink( $sibling_file );
		rmdir( $sibling );
		rmdir( $base_dir );
	}

	#[Test]
	public function validate_path_within_accepts_nonexistent_path_inside_base(): void {
		// A file that does not yet exist but whose parent is within the base
		// directory (the normal case during zip extraction) should be accepted.
		$base_dir = sys_get_temp_dir() . '/d5dsh_test_' . uniqid();
		mkdir( $base_dir, 0755, true );

		$result = SimpleImporter::validate_path_within( $base_dir . '/nonexistent.txt', $base_dir );

		// Should return the constructed canonical path, not false.
		$this->assertNotFalse( $result );
		$this->assertStringContainsString( 'nonexistent.txt', (string) $result );

		// Cleanup.
		rmdir( $base_dir );
	}

	#[Test]
	public function validate_path_within_rejects_nonexistent_path_outside_base(): void {
		// A path that resolves to outside the base directory must be rejected
		// even when the target file does not yet exist.
		$base_dir = sys_get_temp_dir() . '/d5dsh_test_' . uniqid();
		mkdir( $base_dir, 0755, true );

		// Build a path that would land outside: base/../outside.txt
		$result = SimpleImporter::validate_path_within( $base_dir . '/../outside.txt', $base_dir );

		$this->assertFalse( $result );

		// Cleanup.
		rmdir( $base_dir );
	}

	#[Test]
	public function validate_path_within_rejects_nonexistent_base(): void {
		$result = SimpleImporter::validate_path_within( '/tmp/file.txt', '/nonexistent/base' );
		$this->assertFalse( $result );
	}

	#[Test]
	public function validate_path_within_rejects_exact_base_match_without_separator(): void {
		// Edge case: a path that starts with the base directory name but isn't inside it.
		// e.g., base = /tmp/foo, path = /tmp/foobar/file.txt (different directory).
		$base_dir = sys_get_temp_dir() . '/d5dsh_base_' . uniqid();
		$similar  = $base_dir . 'bar'; // /tmp/d5dsh_base_xxxbar (no separator)
		mkdir( $base_dir, 0755, true );
		mkdir( $similar, 0755, true );
		$file = $similar . '/file.txt';
		file_put_contents( $file, 'test' );

		$result = SimpleImporter::validate_path_within( $file, $base_dir );

		$this->assertFalse( $result );

		// Cleanup.
		unlink( $file );
		rmdir( $similar );
		rmdir( $base_dir );
	}

	// =========================================================================
	// extract_variable_refs
	// =========================================================================

	#[Test]
	public function extract_variable_refs_returns_empty_for_plain_string(): void {
		$result = $this->si->pub_extract_variable_refs( 'no variable tokens here' );
		$this->assertSame( [], $result );
	}

	#[Test]
	public function extract_variable_refs_parses_single_color_ref(): void {
		$raw = '$variable({"type":"color","value":{"name":"gcid-abc123","settings":{}}})$';
		$result = $this->si->pub_extract_variable_refs( $raw );

		$this->assertCount( 1, $result );
		$this->assertSame( 'color', $result[0]['type'] );
		$this->assertSame( 'gcid-abc123', $result[0]['name'] );
	}

	#[Test]
	public function extract_variable_refs_parses_multiple_refs(): void {
		$raw = '$variable({"type":"color","value":{"name":"gcid-aaa","settings":{}}})$'
		     . ' and $variable({"type":"color","value":{"name":"gcid-bbb","settings":{"opacity":50}}})$';
		$result = $this->si->pub_extract_variable_refs( $raw );

		$this->assertCount( 2, $result );
		$this->assertSame( 'gcid-aaa', $result[0]['name'] );
		$this->assertSame( 'gcid-bbb', $result[1]['name'] );
	}

	#[Test]
	public function extract_variable_refs_handles_u0022_encoded_quotes(): void {
		// Simulate how Divi stores variable refs inside block markup JSON.
		$encoded_payload = '{\u0022type\u0022:\u0022color\u0022,\u0022value\u0022:{\u0022name\u0022:\u0022gcid-encoded\u0022,\u0022settings\u0022:{}}}';
		$raw = '$variable(' . $encoded_payload . ')$';
		$result = $this->si->pub_extract_variable_refs( $raw );

		$this->assertCount( 1, $result );
		$this->assertSame( 'gcid-encoded', $result[0]['name'] );
	}

	#[Test]
	public function extract_variable_refs_skips_malformed_json_payload(): void {
		// Payload is not valid JSON — should not throw, just skip.
		$raw = '$variable({not valid json})$';
		$result = $this->si->pub_extract_variable_refs( $raw );
		$this->assertSame( [], $result );
	}

	#[Test]
	public function extract_variable_refs_skips_ref_with_no_name(): void {
		// Valid JSON but missing the name field.
		$raw = '$variable({"type":"color","value":{"settings":{}}})$';
		$result = $this->si->pub_extract_variable_refs( $raw );
		$this->assertSame( [], $result );
	}

	#[Test]
	public function extract_variable_refs_parses_content_type(): void {
		$raw = '$variable({"type":"content","value":{"name":"gvid-somevar","settings":{}}})$';
		$result = $this->si->pub_extract_variable_refs( $raw );

		$this->assertCount( 1, $result );
		$this->assertSame( 'content', $result[0]['type'] );
		$this->assertSame( 'gvid-somevar', $result[0]['name'] );
	}

	// =========================================================================
	// extract_preset_refs
	// =========================================================================

	#[Test]
	public function extract_preset_refs_returns_empty_for_plain_string(): void {
		$result = $this->si->pub_extract_preset_refs( 'no preset references here' );
		$this->assertSame( [], $result );
	}

	#[Test]
	public function extract_preset_refs_finds_module_preset(): void {
		$content = '"modulePreset":["my-button-preset-id"]';
		$result  = $this->si->pub_extract_preset_refs( $content );

		$this->assertContains( 'my-button-preset-id', $result );
	}

	#[Test]
	public function extract_preset_refs_finds_preset_id(): void {
		$content = '"presetId":["my-group-preset-id"]';
		$result  = $this->si->pub_extract_preset_refs( $content );

		$this->assertContains( 'my-group-preset-id', $result );
	}

	#[Test]
	public function extract_preset_refs_finds_both_types(): void {
		$content = '"modulePreset":["mod-preset"] "presetId":["grp-preset"]';
		$result  = $this->si->pub_extract_preset_refs( $content );

		$this->assertContains( 'mod-preset', $result );
		$this->assertContains( 'grp-preset', $result );
	}

	#[Test]
	public function extract_preset_refs_deduplicates_ids(): void {
		// Same ID appearing twice (e.g. two modules sharing the same preset).
		$content = '"modulePreset":["shared-id"] "modulePreset":["shared-id"] "presetId":["shared-id"]';
		$result  = $this->si->pub_extract_preset_refs( $content );

		$this->assertCount( 1, $result );
		$this->assertSame( 'shared-id', $result[0] );
	}

	#[Test]
	public function extract_preset_refs_finds_multiple_distinct_ids(): void {
		$content = '"modulePreset":["id-a"] "presetId":["id-b"] "modulePreset":["id-c"]';
		$result  = $this->si->pub_extract_preset_refs( $content );

		$this->assertCount( 3, $result );
		$this->assertContains( 'id-a', $result );
		$this->assertContains( 'id-b', $result );
		$this->assertContains( 'id-c', $result );
	}

	// =========================================================================
	// build_dependency_report — baseline structure
	// =========================================================================

	#[Test]
	public function build_dependency_report_returns_expected_keys(): void {
		seed_site_data();
		$report = $this->si->pub_build_dependency_report( [], 'vars' );

		$this->assertArrayHasKey( 'variable_refs',   $report );
		$this->assertArrayHasKey( 'preset_refs',     $report );
		$this->assertArrayHasKey( 'missing_vars',    $report );
		$this->assertArrayHasKey( 'missing_presets', $report );
		$this->assertArrayHasKey( 'builtin_refs',    $report );
		$this->assertArrayHasKey( 'has_warnings',    $report );
	}

	#[Test]
	public function build_dependency_report_empty_data_returns_no_warnings(): void {
		seed_site_data();
		$report = $this->si->pub_build_dependency_report( [], 'vars' );

		$this->assertSame( 0,     $report['variable_refs'] );
		$this->assertSame( 0,     $report['preset_refs'] );
		$this->assertSame( [],    $report['missing_vars'] );
		$this->assertSame( [],    $report['missing_presets'] );
		$this->assertSame( [],    $report['builtin_refs'] );
		$this->assertFalse( $report['has_warnings'] );
	}

	// =========================================================================
	// build_dependency_report — all-clear (IDs present on site)
	// =========================================================================

	#[Test]
	public function build_dependency_report_all_clear_when_all_vars_present(): void {
		seed_site_data( [ 'gcid-primary', 'gcid-secondary' ] );

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'gcid-derived' => [
						'label' => 'Derived',
						'value' => '$variable({"type":"color","value":{"name":"gcid-primary","settings":{"opacity":50}}})$',
					],
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'vars' );

		$this->assertSame( 1,  $report['variable_refs'] );
		$this->assertSame( [], $report['missing_vars'] );
		$this->assertFalse( $report['has_warnings'] );
	}

	// =========================================================================
	// build_dependency_report — missing vars
	// =========================================================================

	#[Test]
	public function build_dependency_report_flags_missing_var(): void {
		// Site has gcid-present but not gcid-missing.
		seed_site_data( [ 'gcid-present' ] );

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'gcid-local' => [
						'label' => 'Local',
						'value' => '$variable({"type":"color","value":{"name":"gcid-missing","settings":{}}})$',
					],
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'vars' );

		$this->assertSame( 1, $report['variable_refs'] );
		$this->assertCount( 1, $report['missing_vars'] );
		$this->assertSame( 'gcid-missing', $report['missing_vars'][0]['id'] );
		$this->assertTrue( $report['has_warnings'] );
	}

	#[Test]
	public function build_dependency_report_deduplicates_missing_vars(): void {
		// The same missing ID referenced twice should only appear once.
		seed_site_data();

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'c1' => [ 'value' => '$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$' ],
					'c2' => [ 'value' => '$variable({"type":"color","value":{"name":"gcid-x","settings":{}}})$' ],
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'vars' );

		// gcid-x referenced twice → counted once in variable_refs and missing_vars.
		$this->assertSame( 1, $report['variable_refs'] );
		$this->assertCount( 1, $report['missing_vars'] );
	}

	// =========================================================================
	// build_dependency_report — Divi built-in IDs
	// =========================================================================

	#[Test]
	public function build_dependency_report_classifies_builtin_ids_as_informational(): void {
		seed_site_data(); // Site has no variables at all.

		// The known built-in ID should appear in builtin_refs, NOT missing_vars.
		$builtin_id = SimpleImporter::DIVI_BUILTIN_IDS[0]; // 'gvid-r41n4b9xo4'

		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'preset-a' => [
								'attrs' => [
									'spacing' => '$variable({"type":"content","value":{"name":"' . $builtin_id . '","settings":{}}})$',
								],
							],
						],
					],
				],
				'group' => [],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'presets' );

		$this->assertContains( $builtin_id, $report['builtin_refs'] );
		$this->assertSame( [], $report['missing_vars'] );
		$this->assertFalse( $report['has_warnings'] );
	}

	// =========================================================================
	// build_dependency_report — missing presets (layout/pages)
	// =========================================================================

	#[Test]
	public function build_dependency_report_flags_missing_preset_in_layout(): void {
		seed_site_data();
		// Site has no presets at all.

		$data = [
			'posts' => [
				[
					'post_name'    => 'my-page',
					'post_content' => '"modulePreset":["missing-preset-abc"]',
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'layouts' );

		$this->assertSame( 1, $report['preset_refs'] );
		$this->assertCount( 1, $report['missing_presets'] );
		$this->assertSame( 'missing-preset-abc', $report['missing_presets'][0]['id'] );
		$this->assertSame( 'my-page',            $report['missing_presets'][0]['context'] );
		$this->assertTrue( $report['has_warnings'] );
	}

	#[Test]
	public function build_dependency_report_no_warning_when_preset_on_site(): void {
		// Put the preset on the site.
		seed_site_data( [], [
			'module' => [
				'divi/button' => [
					'items' => [ 'existing-preset' => [] ],
				],
			],
			'group' => [],
		] );

		$data = [
			'posts' => [
				[
					'post_name'    => 'test-layout',
					'post_content' => '"modulePreset":["existing-preset"]',
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'layouts' );

		$this->assertSame( [], $report['missing_presets'] );
		$this->assertFalse( $report['has_warnings'] );
	}

	// =========================================================================
	// build_dependency_report — et_native self-contained file
	// =========================================================================

	#[Test]
	public function build_dependency_report_et_native_does_not_flag_self_contained_refs(): void {
		// Site is empty. The et_native file defines its own vars and presets,
		// and its layouts reference only those IDs — no cross-site deps.
		seed_site_data();

		$data = [
			'global_variables' => [
				[ 'id' => 'gcid-self', 'label' => 'Self', 'value' => '#ff0000', 'variableType' => 'colors' ],
			],
			'global_colors' => [],
			'presets' => [
				'module' => [
					'divi/text' => [
						'items' => [ 'self-preset' => [] ],
					],
				],
				'group' => [],
			],
			'data' => [
				'123' => [
					'post_title'   => 'ET Page',
					'post_content' =>
						'$variable({"type":"color","value":{"name":"gcid-self","settings":{}}})$'
						. ' "modulePreset":["self-preset"]',
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'et_native' );

		$this->assertSame( [], $report['missing_vars'],    'Self-contained variable should not be flagged.' );
		$this->assertSame( [], $report['missing_presets'], 'Self-contained preset should not be flagged.' );
		$this->assertFalse( $report['has_warnings'] );
	}

	#[Test]
	public function build_dependency_report_et_native_still_flags_truly_missing_ref(): void {
		seed_site_data();

		$data = [
			'global_variables' => [],
			'global_colors'    => [],
			'presets'          => [ 'module' => [], 'group' => [] ],
			'data'             => [
				'1' => [
					'post_title'   => 'Page',
					'post_content' => '$variable({"type":"color","value":{"name":"gcid-nowhere","settings":{}}})$',
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'et_native' );

		$this->assertCount( 1, $report['missing_vars'] );
		$this->assertSame( 'gcid-nowhere', $report['missing_vars'][0]['id'] );
		$this->assertTrue( $report['has_warnings'] );
	}

	// =========================================================================
	// build_dependency_report — builder_templates type
	// =========================================================================

	#[Test]
	public function build_dependency_report_scans_builder_templates_layouts_key(): void {
		seed_site_data();

		$data = [
			'layouts' => [
				[
					'post_name'    => 'header-template',
					'post_content' => '"modulePreset":["tpl-preset-id"]',
				],
			],
		];

		$report = $this->si->pub_build_dependency_report( $data, 'builder_templates' );

		$this->assertSame( 1, $report['preset_refs'] );
		$this->assertCount( 1, $report['missing_presets'] );
		$this->assertSame( 'tpl-preset-id',    $report['missing_presets'][0]['id'] );
		$this->assertSame( 'header-template',  $report['missing_presets'][0]['context'] );
	}

	// =========================================================================
	// build_dependency_report — graceful fallback on exception
	// =========================================================================

	#[Test]
	public function build_dependency_report_returns_empty_report_on_corrupt_data(): void {
		// Pass a completely invalid data structure. The method must not throw.
		// We do NOT seed options, so VarsRepository will get null/false — the
		// foreach loops should handle that gracefully; if they throw the
		// try/catch in build_dependency_report must swallow it.
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_global_variables']        = 'not-an-array';
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = null;

		$report = $this->si->pub_build_dependency_report( [], 'vars' );

		$this->assertArrayHasKey( 'has_warnings', $report );
		$this->assertIsBool( $report['has_warnings'] );
	}

	// =========================================================================
	// ajax_execute() — session transient lifecycle
	// =========================================================================

	#[Test]
	public function ajax_execute_session_transient_not_deleted_by_execute(): void {
		// Regression test: before the fix in v0.6.9.15, ajax_execute() called
		// delete_transient() after a successful import, breaking Convert to Excel.
		// Verify the transient is still present after ajax_execute() exits,
		// regardless of whether it succeeds or errors internally.

		$session_key = 'd5dsh_si_1'; // user ID 1 (stub default)
		$session     = [
			'type'          => 'unknown_type', // skips execute_single / execute_zip
			'tmp_dir'       => '',
			'files'         => [],
			'uploaded_name' => 'test.json',
		];

		// Seed the session transient.
		set_transient( $session_key, $session, 600 );
		$this->assertNotFalse( get_transient( $session_key ), 'Transient must exist before execute' );

		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'selected_keys' => [] ] );

		// ajax_execute() will always throw JsonResponseException (either via
		// wp_send_json_success or wp_send_json_error — both call die() via stubs).
		try {
			$this->si->ajax_execute();
		} catch ( \D5DesignSystemHelper\Tests\Stubs\JsonResponseException $e ) {
			// Expected — do not re-throw.
		}

		// Transient must still exist — delete_transient must NOT be called.
		$this->assertNotFalse(
			get_transient( $session_key ),
			'Session transient must NOT be deleted by ajax_execute — Convert to Excel depends on it'
		);
	}

	// =========================================================================
	// build_manifest_items — vars type
	// =========================================================================

	#[Test]
	public function build_manifest_items_returns_empty_for_unsupported_type(): void {
		$items = $this->si->pub_build_manifest_items( [], 'layouts' );
		$this->assertSame( [], $items );
	}

	#[Test]
	public function build_manifest_items_returns_empty_for_empty_vars_data(): void {
		$items = $this->si->pub_build_manifest_items( [ 'et_divi_global_variables' => [] ], 'vars' );
		$this->assertSame( [], $items );
	}

	#[Test]
	public function build_manifest_items_returns_flat_list_for_vars(): void {
		$data = [
			'et_divi_global_variables' => [
				'colors'  => [
					'gcid-aaa' => [ 'label' => 'Primary', 'value' => '#ff0000' ],
				],
				'numbers' => [
					'gvid-bbb' => [ 'label' => 'Base Spacing', 'value' => '16px' ],
				],
			],
		];
		$items = $this->si->pub_build_manifest_items( $data, 'vars' );

		$this->assertCount( 2, $items );
		$ids = array_column( $items, 'id' );
		$this->assertContains( 'gcid-aaa', $ids );
		$this->assertContains( 'gvid-bbb', $ids );
	}

	#[Test]
	public function build_manifest_items_includes_type_labels(): void {
		$data = [
			'et_divi_global_variables' => [
				'colors'  => [ 'gcid-x' => [ 'label' => 'X', 'value' => '#fff' ] ],
				'numbers' => [ 'gvid-y' => [ 'label' => 'Y', 'value' => '8px' ] ],
				'fonts'   => [ 'gvid-z' => [ 'label' => 'Z', 'value' => 'Arial' ] ],
			],
		];
		$items = $this->si->pub_build_manifest_items( $data, 'vars' );

		$by_id = [];
		foreach ( $items as $item ) { $by_id[ $item['id'] ] = $item; }

		$this->assertSame( 'Color',  $by_id['gcid-x']['type'] );
		$this->assertSame( 'Number', $by_id['gvid-y']['type'] );
		$this->assertSame( 'Font',   $by_id['gvid-z']['type'] );
	}

	#[Test]
	public function build_manifest_items_caps_at_500_items(): void {
		$type_items = [];
		for ( $i = 0; $i < 600; $i++ ) {
			$type_items[ 'gcid-' . $i ] = [ 'label' => 'Color ' . $i, 'value' => '#000' ];
		}
		$data  = [ 'et_divi_global_variables' => [ 'colors' => $type_items ] ];
		$items = $this->si->pub_build_manifest_items( $data, 'vars' );

		$this->assertCount( 500, $items );
	}

	// =========================================================================
	// build_manifest_items — presets type
	// =========================================================================

	#[Test]
	public function build_manifest_items_returns_flat_list_for_presets(): void {
		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'pid-mod-1' => [ 'name' => 'Blue Button', 'attrs' => [] ],
						],
					],
				],
				'group' => [
					'divi/heading' => [
						'items' => [
							'pid-grp-1' => [ 'label' => 'Hero Heading', 'attrs' => [] ],
						],
					],
				],
			],
		];
		$items = $this->si->pub_build_manifest_items( $data, 'presets' );

		$this->assertCount( 2, $items );
		$by_id = [];
		foreach ( $items as $item ) { $by_id[ $item['id'] ] = $item; }

		$this->assertSame( 'Blue Button',  $by_id['pid-mod-1']['label'] );
		$this->assertSame( 'Hero Heading', $by_id['pid-grp-1']['label'] );
		$this->assertStringContainsString( 'divi/button',  $by_id['pid-mod-1']['type'] );
		$this->assertStringContainsString( 'divi/heading', $by_id['pid-grp-1']['type'] );
	}

	// =========================================================================
	// import_json_vars — label_overrides applied
	// =========================================================================

	#[Test]
	public function import_json_vars_uses_original_label_when_no_overrides(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [];

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'gcid-test' => [ 'label' => 'Original Label', 'value' => '#ff0000', 'status' => 'active' ],
				],
			],
		];

		$result = $this->si->pub_import_json_vars( $data );

		$this->assertTrue( $result['success'] );
		$saved = $GLOBALS['_d5dsh_options']['et_divi_global_variables'];
		$this->assertSame( 'Original Label', $saved['colors']['gcid-test']['label'] );
	}

	#[Test]
	public function import_json_vars_applies_label_override(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [];

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'gcid-test' => [ 'label' => 'Original Label', 'value' => '#ff0000', 'status' => 'active' ],
				],
			],
		];
		$overrides = [ 'gcid-test' => 'Overridden Label' ];

		$result = $this->si->pub_import_json_vars( $data, $overrides );

		$this->assertTrue( $result['success'] );
		$saved = $GLOBALS['_d5dsh_options']['et_divi_global_variables'];
		$this->assertSame( 'Overridden Label', $saved['colors']['gcid-test']['label'] );
	}

	#[Test]
	public function import_json_vars_partial_overrides_leave_others_unchanged(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [];

		$data = [
			'et_divi_global_variables' => [
				'colors' => [
					'gcid-a' => [ 'label' => 'Label A', 'value' => '#aaa', 'status' => 'active' ],
					'gcid-b' => [ 'label' => 'Label B', 'value' => '#bbb', 'status' => 'active' ],
				],
			],
		];
		$overrides = [ 'gcid-a' => 'New A' ];  // only override A, not B

		$this->si->pub_import_json_vars( $data, $overrides );

		$saved = $GLOBALS['_d5dsh_options']['et_divi_global_variables'];
		$this->assertSame( 'New A',   $saved['colors']['gcid-a']['label'] );
		$this->assertSame( 'Label B', $saved['colors']['gcid-b']['label'] );
	}

	#[Test]
	public function import_json_vars_override_sanitizes_label(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [];

		$data = [
			'et_divi_global_variables' => [
				'numbers' => [
					'gvid-x' => [ 'label' => 'Raw', 'value' => '8px', 'status' => 'active' ],
				],
			],
		];
		// sanitize_text_field strips leading/trailing whitespace.
		$overrides = [ 'gvid-x' => '  Padded Label  ' ];

		$this->si->pub_import_json_vars( $data, $overrides );

		$saved = $GLOBALS['_d5dsh_options']['et_divi_global_variables'];
		$this->assertSame( 'Padded Label', $saved['numbers']['gvid-x']['label'] );
	}

	// =========================================================================
	// import_json_presets — label_overrides applied
	// =========================================================================

	#[Test]
	public function import_json_presets_applies_label_override_to_module_preset(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = [
			'module' => [], 'group' => [],
		];

		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/button' => [
						'items' => [
							'pid-btn-1' => [ 'name' => 'Default Button', 'attrs' => [] ],
						],
					],
				],
				'group' => [],
			],
		];
		$overrides = [ 'pid-btn-1' => 'Blue CTA' ];

		$result = $this->si->pub_import_json_presets( $data, $overrides );

		$this->assertTrue( $result['success'] );
		$saved = $GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'];
		$saved_preset = $saved['module']['divi/button']['items']['pid-btn-1'];
		$this->assertSame( 'Blue CTA', $saved_preset['name'] );
		$this->assertSame( 'Blue CTA', $saved_preset['label'] );
	}

	#[Test]
	public function import_json_presets_applies_label_override_to_group_preset(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = [
			'module' => [], 'group' => [],
		];

		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [],
				'group'  => [
					'divi/heading' => [
						'items' => [
							'pid-hd-1' => [ 'label' => 'Big Heading', 'attrs' => [] ],
						],
					],
				],
			],
		];
		$overrides = [ 'pid-hd-1' => 'Hero Title' ];

		$this->si->pub_import_json_presets( $data, $overrides );

		$saved = $GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'];
		$saved_preset = $saved['group']['divi/heading']['items']['pid-hd-1'];
		$this->assertSame( 'Hero Title', $saved_preset['name'] );
		$this->assertSame( 'Hero Title', $saved_preset['label'] );
	}

	#[Test]
	public function import_json_presets_no_override_keeps_original_name(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = [
			'module' => [], 'group' => [],
		];

		$data = [
			'et_divi_builder_global_presets_d5' => [
				'module' => [
					'divi/text' => [
						'items' => [
							'pid-txt-1' => [ 'name' => 'Body Text', 'attrs' => [] ],
						],
					],
				],
				'group' => [],
			],
		];

		$this->si->pub_import_json_presets( $data );

		$saved = $GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'];
		$this->assertSame( 'Body Text', $saved['module']['divi/text']['items']['pid-txt-1']['name'] );
	}

	// =========================================================================
	// ajax_execute() — label_overrides forwarded to importer
	// =========================================================================

	#[Test]
	public function ajax_execute_with_label_overrides_applies_override_on_import(): void {
		_d5dsh_reset_stubs();

		// Create a temporary JSON file with a variable.
		$json = json_encode( [
			'et_divi_global_variables' => [
				'numbers' => [
					'gvid-override-test' => [ 'label' => 'Original', 'value' => '10px', 'status' => 'active' ],
				],
			],
		] );
		$tmp = tempnam( sys_get_temp_dir(), 'd5dsh_test_' ) . '.json';
		file_put_contents( $tmp, $json );

		// Seed site options and session transient.
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [];
		$session_key = 'd5dsh_si_1';
		set_transient( $session_key, [
			'type'         => 'single',
			'format'       => 'json',
			'tmp_path'     => $tmp,
			'display_name' => 'test-vars.json',
		], 600 );

		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'selected_keys'   => [ 'test-vars.json' ],
			'label_overrides' => [ 'test-vars.json' => [ 'gvid-override-test' => 'Renamed Label' ] ],
		] );

		try {
			$this->si->ajax_execute();
		} catch ( \D5DesignSystemHelper\Tests\Stubs\JsonResponseException $e ) {
			// Either success or error response is acceptable here — what matters is
			// that the label override was applied to the saved data.
		}

		// Confirm override was applied regardless of AJAX response shape.
		$saved = $GLOBALS['_d5dsh_options']['et_divi_global_variables'];
		$this->assertSame( 'Renamed Label', $saved['numbers']['gvid-override-test']['label'] );

		unlink( $tmp );
	}
}
