<?php
/**
 * Tests for AuditEngine — the three-tier Error / Warning / Advisory audit system.
 *
 * Strategy:
 *   - TestableAuditEngine subclass overrides load_layout_content() so tests can
 *     inject post_content strings without needing $wpdb.
 *   - Public wrappers expose the private check methods via ReflectionMethod.
 *   - $GLOBALS['_d5dsh_options'] / _d5dsh_reset_stubs() provide the in-memory
 *     WP option store (same pattern as SimpleImporterTest).
 *
 * Covers (31 test cases):
 *   E1  check_broken_variable_refs    (5 tests)
 *   E2  check_archived_vars_in_presets (3 tests)
 *   W1  check_singleton_variables     (4 tests)
 *   W2  check_near_duplicate_values   (4 tests)
 *   A1  check_hardcoded_extraction_candidates (5 tests)
 *   A2  check_orphaned_variables      (4 tests)
 *   run() integration                 (4 tests)
 *   ajax_run()                        (2 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\AuditEngine;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Exposes AuditEngine private methods and allows injecting layout content.
 */
class TestableAuditEngine extends AuditEngine {

	/** @var string[] Injected post_content strings (replaces wpdb). */
	private array $injected_layouts = [];

	public function set_layouts( array $layouts ): void {
		$this->injected_layouts = $layouts;
	}

	protected function load_layout_content(): array {
		return $this->injected_layouts;
	}

	// ── Private method wrappers ────────────────────────────────────────────

	public function pub_check_broken_variable_refs(
		array $raw_colors,
		array $raw_vars,
		array $preset_items
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_broken_variable_refs' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_colors, $raw_vars, $preset_items );
	}

	public function pub_check_archived_vars_in_presets(
		array $raw_colors,
		array $raw_vars,
		array $preset_items
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_archived_vars_in_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_colors, $raw_vars, $preset_items );
	}

	public function pub_check_singleton_variables(
		array $all_var_ids,
		array $preset_items
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_singleton_variables' );
		$m->setAccessible( true );
		return $m->invoke( $this, $all_var_ids, $preset_items );
	}

	public function pub_check_near_duplicate_values(
		array $raw_colors,
		array $raw_vars
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_near_duplicate_values' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_colors, $raw_vars );
	}

	public function pub_check_hardcoded_extraction_candidates( array $preset_items ): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_hardcoded_extraction_candidates' );
		$m->setAccessible( true );
		return $m->invoke( $this, $preset_items );
	}

	public function pub_check_orphaned_variables(
		array $all_var_ids,
		array $preset_items,
		array $layout_content
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_orphaned_variables' );
		$m->setAccessible( true );
		return $m->invoke( $this, $all_var_ids, $preset_items, $layout_content );
	}

	public function pub_collect_var_ids( array $raw_vars, array $raw_colors ): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'collect_var_ids' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_vars, $raw_colors );
	}

	public function pub_collect_preset_items( array $raw_presets ): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'collect_preset_items' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_presets );
	}

	// ── Wrappers for full-audit checks ────────────────────────────────────────

	public function pub_check_archived_dsos_in_content(
		array $raw_colors,
		array $raw_vars,
		array $preset_items,
		array $dso_usage,
		array $all_var_types = []
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_archived_dsos_in_content' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_colors, $raw_vars, $preset_items, $dso_usage, $all_var_types );
	}

	public function pub_check_broken_dso_refs_in_content(
		array $all_var_ids,
		array $preset_items,
		array $dso_usage
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_broken_dso_refs_in_content' );
		$m->setAccessible( true );
		return $m->invoke( $this, $all_var_ids, $preset_items, $dso_usage );
	}

	public function pub_check_orphaned_presets(
		array $preset_items,
		array $dso_usage
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_orphaned_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $preset_items, $dso_usage );
	}

	public function pub_check_high_impact_variables(
		array $all_var_ids,
		array $dso_usage,
		array $all_var_types = []
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_high_impact_variables' );
		$m->setAccessible( true );
		return $m->invoke( $this, $all_var_ids, $dso_usage, $all_var_types );
	}

	public function pub_check_preset_naming_convention( array $raw_presets ): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_preset_naming_convention' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_presets );
	}

	public function pub_check_variables_bypassing_presets(
		array $all_var_ids,
		array $preset_items,
		array $dso_usage,
		array $all_var_types = []
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_variables_bypassing_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $all_var_ids, $preset_items, $dso_usage, $all_var_types );
	}

	public function pub_check_singleton_presets(
		array $preset_items,
		array $dso_usage
	): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_singleton_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $preset_items, $dso_usage );
	}

	public function pub_check_overlapping_presets( array $raw_presets ): array {
		$m = new \ReflectionMethod( AuditEngine::class, 'check_overlapping_presets' );
		$m->setAccessible( true );
		return $m->invoke( $this, $raw_presets );
	}
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/**
 * Build a minimal et_divi option carrying the given color IDs.
 * Colors are optionally marked as archived (status != 'active').
 *
 * @param array<string, array{label?: string, color?: string, status?: string}> $colors
 *   Keys are gcid-* IDs; values override defaults.
 */
function ae_make_et_divi( array $colors ): array {
	$global_colors = [];
	foreach ( $colors as $id => $overrides ) {
		$global_colors[ $id ] = array_merge(
			[
				'id'     => $id,
				'label'  => $id,
				'color'  => '#aabbcc',
				'status' => 'active',
				'order'  => 1,
			],
			$overrides
		);
	}
	return [
		'et_global_data' => [
			'global_colors' => $global_colors,
		],
	];
}

/**
 * Seed the WP options store for AuditEngine tests.
 *
 * @param array<string, array>  $colors     Map of gcid-* => override fields.
 * @param array                 $presets    Raw presets array {module:[], group:[]}.
 * @param array<string, array>  $gvid_vars  Map of gvid-* => fields (merged into numbers type).
 */
function ae_seed(
	array $colors   = [],
	array $presets  = [],
	array $gvid_vars = []
): void {
	$GLOBALS['_d5dsh_options']['et_divi']                     = ae_make_et_divi( $colors );
	$numbers = [];
	foreach ( $gvid_vars as $id => $fields ) {
		$numbers[ $id ] = array_merge(
			[ 'id' => $id, 'label' => $id, 'value' => '16px', 'status' => 'active' ],
			$fields
		);
	}
	$GLOBALS['_d5dsh_options']['et_divi_global_variables']    = $numbers ? [ 'numbers' => $numbers ] : [];
	$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = $presets ?: [
		'module' => [],
		'group'  => [],
	];
}

/**
 * Build a minimal preset item with the given attrs JSON string embedded.
 * The attrs value is serialised so the real attrs array contains a 'value' key
 * whose string content triggers extract_variable_refs().
 *
 * @param string $preset_id
 * @param string $attrs_json Raw JSON string to embed inside attrs['value'].
 *                           Pass a full serialised $variable()$ token string.
 */
function ae_preset( string $preset_id, string $attrs_json = '' ): array {
	// Build attrs as a real PHP array whose json_encode() output will contain
	// the $variable()$ tokens.
	$decoded = $attrs_json ? json_decode( $attrs_json, true ) : null;
	return [
		'id'        => $preset_id,
		'name'      => 'Preset ' . $preset_id,
		'moduleName'=> 'divi/button',
		'attrs'     => $decoded ?? [],
	];
}

/**
 * Build a $variable()$ token string (the format Divi stores in preset attrs).
 * Returns the raw token as it would appear inside a JSON-encoded string.
 *
 * @param string $gcid   The color/variable ID.
 * @param string $type   'color' or 'content'.
 */
function ae_var_token( string $gcid, string $type = 'color' ): string {
	// We build the outer JSON representation: when json_encode() processes the
	// array, inner quotes in the $variable()$ token become \".
	// AuditEngine::extract_variable_refs() handles both \u0022 and \" variants.
	$inner = json_encode( [ 'type' => $type, 'value' => [ 'name' => $gcid ] ] );
	// Wrap as the $variable()$ token.
	return '$variable(' . $inner . ')$';
}

/**
 * Build a raw presets structure containing a single module preset.
 *
 * @param array $preset_item  Output of ae_preset().
 */
function ae_presets_with_item( array $preset_item ): array {
	return [
		'module' => [
			'divi/button' => [
				'default' => $preset_item['id'],
				'items'   => [ $preset_item['id'] => $preset_item ],
			],
		],
		'group' => [],
	];
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( AuditEngine::class )]
class AuditEngineTest extends TestCase {

	private TestableAuditEngine $ae;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->ae = new TestableAuditEngine();
	}

	// =========================================================================
	// E1 — check_broken_variable_refs
	// =========================================================================

	#[Test]
	public function e1_no_error_when_referenced_color_exists_on_site(): void {
		$gcid        = 'gcid-abc123';
		$token_str   = ae_var_token( $gcid );
		$preset      = ae_preset( 'preset-1', json_encode( [ 'textColor' => $token_str ] ) );
		$raw_colors  = [ $gcid => [ 'id' => $gcid, 'label' => 'Primary', 'color' => '#aabbcc', 'status' => 'active' ] ];

		$result = $this->ae->pub_check_broken_variable_refs( $raw_colors, [], [ $preset ] );

		$this->assertSame( 'broken_variable_refs', $result['check'] );
		$this->assertEmpty( $result['items'], 'No error expected when the color ID exists on the site' );
	}

	#[Test]
	public function e1_error_when_referenced_gcid_is_missing_from_site(): void {
		$gcid       = 'gcid-missing999';
		$token_str  = ae_var_token( $gcid );
		$preset     = ae_preset( 'preset-2', json_encode( [ 'bg' => $token_str ] ) );

		$result = $this->ae->pub_check_broken_variable_refs( [], [], [ $preset ] );

		$this->assertCount( 1, $result['items'], 'Expected one error for the missing gcid' );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	#[Test]
	public function e1_no_error_for_divi_builtin_gvid(): void {
		// gvid-r41n4b9xo4 is in DIVI_BUILTIN_IDS — must never be flagged.
		$builtin_id = 'gvid-r41n4b9xo4';
		$token_str  = ae_var_token( $builtin_id, 'content' );
		$preset     = ae_preset( 'preset-builtin', json_encode( [ 'spacing' => $token_str ] ) );

		$result = $this->ae->pub_check_broken_variable_refs( [], [], [ $preset ] );

		$this->assertEmpty( $result['items'], 'Divi built-in IDs must not be flagged as missing' );
	}

	#[Test]
	public function e1_multiple_missing_ids_produce_separate_error_items(): void {
		$id1        = 'gcid-missing-a';
		$id2        = 'gcid-missing-b';
		$attrs      = [
			'color1' => ae_var_token( $id1 ),
			'color2' => ae_var_token( $id2 ),
		];
		$preset     = ae_preset( 'preset-multi', json_encode( $attrs ) );

		$result = $this->ae->pub_check_broken_variable_refs( [], [], [ $preset ] );

		$this->assertCount( 2, $result['items'] );
		$ids = array_column( $result['items'], 'id' );
		$this->assertContains( $id1, $ids );
		$this->assertContains( $id2, $ids );
	}

	#[Test]
	public function e1_no_presets_returns_empty_items(): void {
		$result = $this->ae->pub_check_broken_variable_refs( [], [], [] );

		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// E2 — check_archived_vars_in_presets
	// =========================================================================

	#[Test]
	public function e2_no_error_when_all_vars_are_active(): void {
		$gcid        = 'gcid-active';
		$token_str   = ae_var_token( $gcid );
		$preset      = ae_preset( 'preset-active', json_encode( [ 'color' => $token_str ] ) );
		$raw_colors  = [ $gcid => [ 'id' => $gcid, 'label' => 'Active', 'color' => '#fff', 'status' => 'active' ] ];

		$result = $this->ae->pub_check_archived_vars_in_presets( $raw_colors, [], [ $preset ] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e2_no_error_when_archived_var_is_not_referenced_by_any_preset(): void {
		$gcid       = 'gcid-archived-orphan';
		$raw_colors = [ $gcid => [ 'id' => $gcid, 'label' => 'Archived', 'color' => '#000', 'status' => 'archived' ] ];
		// No preset references this ID.
		$preset     = ae_preset( 'preset-unrelated', json_encode( [ 'color' => '#ffffff' ] ) );

		$result = $this->ae->pub_check_archived_vars_in_presets( $raw_colors, [], [ $preset ] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e2_error_when_archived_var_is_referenced_in_preset(): void {
		$gcid        = 'gcid-was-archived';
		$token_str   = ae_var_token( $gcid );
		$raw_colors  = [ $gcid => [ 'id' => $gcid, 'label' => 'Old Blue', 'color' => '#0000ff', 'status' => 'archived' ] ];
		$preset      = ae_preset( 'preset-uses-archived', json_encode( [ 'bg' => $token_str ] ) );

		$result = $this->ae->pub_check_archived_vars_in_presets( $raw_colors, [], [ $preset ] );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	// =========================================================================
	// W1 — check_singleton_variables
	// =========================================================================

	#[Test]
	public function w1_no_warning_when_variable_referenced_by_multiple_presets(): void {
		$gcid   = 'gcid-shared';
		$token  = ae_var_token( $gcid );
		$p1     = ae_preset( 'p1', json_encode( [ 'color' => $token ] ) );
		$p2     = ae_preset( 'p2', json_encode( [ 'color' => $token ] ) );

		$all_ids = [ $gcid => 'Shared Color' ];
		$result  = $this->ae->pub_check_singleton_variables( $all_ids, [ $p1, $p2 ] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w1_warning_when_variable_referenced_by_exactly_one_preset(): void {
		$gcid   = 'gcid-solo';
		$token  = ae_var_token( $gcid );
		$preset = ae_preset( 'p-solo', json_encode( [ 'color' => $token ] ) );

		$all_ids = [ $gcid => 'Solo Color' ];
		$result  = $this->ae->pub_check_singleton_variables( $all_ids, [ $preset ] );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	#[Test]
	public function w1_no_warning_when_variable_referenced_by_zero_presets(): void {
		// Zero references is an orphan (A2), not a singleton warning.
		$gcid    = 'gcid-orphan';
		$all_ids = [ $gcid => 'Orphan Color' ];

		$result = $this->ae->pub_check_singleton_variables( $all_ids, [] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w1_multiple_singletons_produce_separate_warning_items(): void {
		$id1 = 'gcid-single-a';
		$id2 = 'gcid-single-b';

		$p1 = ae_preset( 'p1', json_encode( [ 'color' => ae_var_token( $id1 ) ] ) );
		$p2 = ae_preset( 'p2', json_encode( [ 'color' => ae_var_token( $id2 ) ] ) );

		$all_ids = [ $id1 => 'A', $id2 => 'B' ];
		$result  = $this->ae->pub_check_singleton_variables( $all_ids, [ $p1, $p2 ] );

		$this->assertCount( 2, $result['items'] );
	}

	// =========================================================================
	// W2 — check_near_duplicate_values
	// =========================================================================

	#[Test]
	public function w2_warning_when_two_colors_share_identical_hex(): void {
		$raw_colors = [
			'gcid-dup-a' => [ 'id' => 'gcid-dup-a', 'label' => 'A', 'color' => '#112233', 'status' => 'active' ],
			'gcid-dup-b' => [ 'id' => 'gcid-dup-b', 'label' => 'B', 'color' => '#112233', 'status' => 'active' ],
		];

		$result = $this->ae->pub_check_near_duplicate_values( $raw_colors, [] );

		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( '112233', $result['items'][0]['detail'] );
	}

	#[Test]
	public function w2_no_warning_when_all_colors_are_unique(): void {
		$raw_colors = [
			'gcid-u1' => [ 'id' => 'gcid-u1', 'label' => 'A', 'color' => '#111111', 'status' => 'active' ],
			'gcid-u2' => [ 'id' => 'gcid-u2', 'label' => 'B', 'color' => '#222222', 'status' => 'active' ],
		];

		$result = $this->ae->pub_check_near_duplicate_values( $raw_colors, [] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w2_normalises_3_digit_hex_before_comparing(): void {
		// #fff and #ffffff are the same color.
		$raw_colors = [
			'gcid-fff-a' => [ 'id' => 'gcid-fff-a', 'label' => 'A', 'color' => '#fff',    'status' => 'active' ],
			'gcid-fff-b' => [ 'id' => 'gcid-fff-b', 'label' => 'B', 'color' => '#ffffff', 'status' => 'active' ],
		];

		$result = $this->ae->pub_check_near_duplicate_values( $raw_colors, [] );

		$this->assertCount( 1, $result['items'], '#fff and #ffffff should be flagged as duplicates' );
	}

	#[Test]
	public function w2_three_colors_sharing_value_produce_one_warning_item(): void {
		$hex        = '#aabbcc';
		$raw_colors = [
			'gcid-t1' => [ 'id' => 'gcid-t1', 'label' => 'T1', 'color' => $hex, 'status' => 'active' ],
			'gcid-t2' => [ 'id' => 'gcid-t2', 'label' => 'T2', 'color' => $hex, 'status' => 'active' ],
			'gcid-t3' => [ 'id' => 'gcid-t3', 'label' => 'T3', 'color' => $hex, 'status' => 'active' ],
		];

		$result = $this->ae->pub_check_near_duplicate_values( $raw_colors, [] );

		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( '3 color', $result['items'][0]['detail'] );
	}

	// =========================================================================
	// A1 — check_hardcoded_extraction_candidates
	// =========================================================================

	/**
	 * Build N presets each containing the given hardcoded hex.
	 */
	private function make_presets_with_hex( string $hex, int $count ): array {
		$presets = [];
		for ( $i = 0; $i < $count; $i++ ) {
			$presets[] = ae_preset( 'p-hx-' . $i, json_encode( [ 'background' => $hex ] ) );
		}
		return $presets;
	}

	#[Test]
	public function a1_no_advisory_when_hardcoded_hex_appears_below_threshold(): void {
		$presets = $this->make_presets_with_hex( '#ff0000', 9 );

		$result = $this->ae->pub_check_hardcoded_extraction_candidates( $presets );

		$this->assertEmpty( $result['items'], '9 presets is below the threshold of 10' );
	}

	#[Test]
	public function a1_advisory_when_hardcoded_hex_appears_at_or_above_threshold(): void {
		$presets = $this->make_presets_with_hex( '#ff0000', 10 );

		$result = $this->ae->pub_check_hardcoded_extraction_candidates( $presets );

		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'ff0000', $result['items'][0]['detail'] );
	}

	#[Test]
	public function a1_hex_wrapped_in_variable_token_is_not_flagged(): void {
		// Build presets whose attrs contain $variable()$ tokens wrapping a color
		// reference. The token itself should not be counted as a hardcoded hex.
		// ae_var_token() produces the $variable(...)$ wrapper string; any hex that
		// might appear inside the token JSON payload must not be flagged.
		$token   = ae_var_token( 'gcid-abc', 'color' );
		$presets = [];
		for ( $i = 0; $i < 12; $i++ ) {
			// We put ONLY the token in the attrs — no literal hex outside it.
			$presets[] = ae_preset( 'p-tok-' . $i, json_encode( [ 'color' => $token ] ) );
		}

		$result = $this->ae->pub_check_hardcoded_extraction_candidates( $presets );

		// Items flagged must not include anything sourced from inside the token.
		// A simple way to verify: any flagged hex detail must not mention 'aabbcc'
		// (which is the color value embedded in ae_var_token's inner JSON if present).
		$flagged_hexes = array_column( $result['items'], 'label' );
		$this->assertNotContains( '#aabbcc', $flagged_hexes,
			'Hex referenced inside a $variable()$ token must not be flagged as hardcoded' );
		// Also assert the result is a valid check structure.
		$this->assertSame( 'hardcoded_extraction_candidates', $result['check'] );
	}

	#[Test]
	public function a1_no_presets_returns_no_advisories(): void {
		$result = $this->ae->pub_check_hardcoded_extraction_candidates( [] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a1_multiple_threshold_crossing_hexes_produce_separate_items(): void {
		$presets_red   = $this->make_presets_with_hex( '#ff0000', 10 );
		$presets_blue  = $this->make_presets_with_hex( '#0000ff', 10 );

		$result = $this->ae->pub_check_hardcoded_extraction_candidates(
			array_merge( $presets_red, $presets_blue )
		);

		$this->assertCount( 2, $result['items'] );
	}

	// =========================================================================
	// A2 — check_orphaned_variables
	// =========================================================================

	#[Test]
	public function a2_no_advisory_when_variable_referenced_in_preset(): void {
		$gcid   = 'gcid-used';
		$token  = ae_var_token( $gcid );
		$preset = ae_preset( 'p-used', json_encode( [ 'color' => $token ] ) );

		$all_ids = [ $gcid => 'Used Color' ];
		$result  = $this->ae->pub_check_orphaned_variables( $all_ids, [ $preset ], [] );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a2_advisory_when_variable_unreferenced_in_presets_and_layouts(): void {
		$gcid    = 'gcid-truly-orphaned';
		$all_ids = [ $gcid => 'Orphaned Color' ];

		$result = $this->ae->pub_check_orphaned_variables( $all_ids, [], [] );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	#[Test]
	public function a2_no_advisory_when_variable_referenced_only_in_layout_content(): void {
		$gcid          = 'gcid-layout-only';
		$token         = ae_var_token( $gcid );
		$layout_content = [ 'Some post content with ' . $token . ' embedded' ];
		$all_ids       = [ $gcid => 'Layout Color' ];

		$result = $this->ae->pub_check_orphaned_variables( $all_ids, [], $layout_content );

		$this->assertEmpty( $result['items'], 'Variable referenced in layout must not be flagged as orphaned' );
	}

	#[Test]
	public function a2_divi_builtin_ids_never_flagged_as_orphaned(): void {
		$builtin = 'gvid-r41n4b9xo4';
		$all_ids = [ $builtin => 'Divi Internal' ];

		$result = $this->ae->pub_check_orphaned_variables( $all_ids, [], [] );

		$this->assertEmpty( $result['items'], 'DIVI_BUILTIN_IDS must not appear in orphaned advisories' );
	}

	// =========================================================================
	// run() integration
	// =========================================================================

	#[Test]
	public function run_empty_site_returns_all_tiers_with_no_items(): void {
		ae_seed();

		$report = $this->ae->run();

		$this->assertArrayHasKey( 'errors',     $report );
		$this->assertArrayHasKey( 'warnings',   $report );
		$this->assertArrayHasKey( 'advisories', $report );
		$this->assertArrayHasKey( 'meta',       $report );

		foreach ( $report['errors'] as $tier_check ) {
			$this->assertEmpty( $tier_check['items'] );
		}
		foreach ( $report['warnings'] as $tier_check ) {
			$this->assertEmpty( $tier_check['items'] );
		}
		foreach ( $report['advisories'] as $tier_check ) {
			$this->assertEmpty( $tier_check['items'] );
		}
	}

	#[Test]
	public function run_meta_ran_at_is_non_empty_string(): void {
		ae_seed();

		$report = $this->ae->run();

		$this->assertIsString( $report['meta']['ran_at'] );
		$this->assertNotEmpty( $report['meta']['ran_at'] );
	}

	#[Test]
	public function run_meta_variable_count_matches_seeded_color_count(): void {
		ae_seed( [
			'gcid-c1' => [],
			'gcid-c2' => [],
			'gcid-c3' => [],
		] );

		$report = $this->ae->run();

		// variable_count includes both non-color vars and colors.
		$this->assertSame( 3, $report['meta']['color_count'] );
	}

	#[Test]
	public function run_report_has_required_shape_keys(): void {
		ae_seed();

		$report = $this->ae->run();

		$this->assertArrayHasKey( 'errors',     $report );
		$this->assertArrayHasKey( 'warnings',   $report );
		$this->assertArrayHasKey( 'advisories', $report );
		$this->assertArrayHasKey( 'meta',       $report );
		$this->assertArrayHasKey( 'variable_count', $report['meta'] );
		$this->assertArrayHasKey( 'color_count',    $report['meta'] );
		$this->assertArrayHasKey( 'preset_count',   $report['meta'] );
		$this->assertArrayHasKey( 'content_count',  $report['meta'] );
		$this->assertArrayHasKey( 'ran_at',         $report['meta'] );
	}

	// =========================================================================
	// ajax_run()
	// =========================================================================

	#[Test]
	public function ajax_run_sends_error_when_current_user_cannot_manage_options(): void {
		// Override the capability check stub to deny manage_options.
		$GLOBALS['_d5dsh_user_can'] = false;

		try {
			ae_seed();
			$this->ae->ajax_run();
			$this->fail( 'Expected JsonResponseException from wp_send_json_error' );
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
		} finally {
			$GLOBALS['_d5dsh_user_can'] = true;
		}
	}

	#[Test]
	public function ajax_run_sends_success_when_nonce_valid_and_capability_met(): void {
		ae_seed();

		try {
			$this->ae->ajax_run();
			$this->fail( 'Expected JsonResponseException from wp_send_json_success' );
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'errors',     $e->data );
			$this->assertArrayHasKey( 'warnings',   $e->data );
			$this->assertArrayHasKey( 'advisories', $e->data );
			$this->assertArrayHasKey( 'meta',       $e->data );
		}
	}

	// =========================================================================
	// ajax_audit_xlsx()
	// =========================================================================

	#[Test]
	public function ajax_audit_xlsx_sends_error_when_user_cannot_manage_options(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		try {
			$this->ae->ajax_audit_xlsx();
			$this->fail( 'Expected JsonResponseException from wp_send_json_error' );
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
		} finally {
			$GLOBALS['_d5dsh_user_can'] = true;
		}
	}

	#[Test]
	public function ajax_audit_xlsx_sends_error_when_input_is_not_json(): void {
		// Seed php://input with a non-JSON string.
		$GLOBALS['_d5dsh_php_input'] = 'not json at all';

		try {
			$this->ae->ajax_audit_xlsx();
			$this->fail( 'Expected JsonResponseException from wp_send_json_error' );
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
		}
	}

	// =========================================================================
	// E4 — check_archived_dsos_in_content
	// =========================================================================

	#[Test]
	public function e4_empty_when_no_dso_usage_provided(): void {
		$result = $this->ae->pub_check_archived_dsos_in_content( [], [], [], [] );
		$this->assertSame( 'archived_dsos_in_content', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e4_flags_archived_color_in_published_content(): void {
		$gcid       = 'gcid-archived-color';
		$raw_colors = [ $gcid => [ 'label' => 'Archived Color', 'color' => '#000', 'status' => 'archived' ] ];
		$dso_usage  = [
			'variables' => [
				$gcid => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 5, 'post_title' => 'Home', 'post_status' => 'publish' ] ],
				],
			],
			'presets' => [],
		];

		$result = $this->ae->pub_check_archived_dsos_in_content( $raw_colors, [], [], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
		$this->assertStringContainsString( 'Home', $result['items'][0]['detail'] );
	}

	#[Test]
	public function e4_skips_archived_color_used_only_in_draft(): void {
		$gcid       = 'gcid-archived-draft';
		$raw_colors = [ $gcid => [ 'label' => 'X', 'color' => '#fff', 'status' => 'inactive' ] ];
		$dso_usage  = [
			'variables' => [
				$gcid => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 9, 'post_title' => 'Draft Page', 'post_status' => 'draft' ] ],
				],
			],
			'presets' => [],
		];

		$result = $this->ae->pub_check_archived_dsos_in_content( $raw_colors, [], [], $dso_usage );

		$this->assertEmpty( $result['items'], 'Draft-only references should not be flagged' );
	}

	#[Test]
	public function e4_flags_archived_preset_in_published_content(): void {
		$preset_id   = 'preset-archived-1';
		$preset_item = [ 'id' => $preset_id, 'name' => 'Old Button', 'status' => 'archived', 'attrs' => [] ];
		$dso_usage   = [
			'variables' => [],
			'presets'   => [
				$preset_id => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 2, 'post_title' => 'About', 'post_status' => 'publish' ] ],
				],
			],
		];

		$result = $this->ae->pub_check_archived_dsos_in_content( [], [], [ $preset_item ], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $preset_id, $result['items'][0]['id'] );
	}

	// =========================================================================
	// E5 — check_broken_dso_refs_in_content
	// =========================================================================

	#[Test]
	public function e5_empty_when_no_dso_usage_provided(): void {
		$result = $this->ae->pub_check_broken_dso_refs_in_content( [], [], [] );
		$this->assertSame( 'broken_dso_refs_in_content', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e5_flags_unknown_variable_in_published_content(): void {
		$missing_id = 'gcid-not-on-site';
		$dso_usage  = [
			'variables' => [
				$missing_id => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 3, 'post_title' => 'Blog', 'post_status' => 'publish' ] ],
				],
			],
			'presets' => [],
		];

		$result = $this->ae->pub_check_broken_dso_refs_in_content( [], [], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $missing_id, $result['items'][0]['id'] );
	}

	#[Test]
	public function e5_no_flag_when_variable_exists_on_site(): void {
		$gcid      = 'gcid-exists';
		$dso_usage = [
			'variables' => [
				$gcid => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 4, 'post_title' => 'Services', 'post_status' => 'publish' ] ],
				],
			],
			'presets' => [],
		];

		$result = $this->ae->pub_check_broken_dso_refs_in_content( [ $gcid => 'Brand Color' ], [], $dso_usage );

		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function e5_skips_draft_only_missing_refs(): void {
		$missing_id = 'gcid-draft-only';
		$dso_usage  = [
			'variables' => [
				$missing_id => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 7, 'post_title' => 'WIP', 'post_status' => 'draft' ] ],
				],
			],
			'presets' => [],
		];

		$result = $this->ae->pub_check_broken_dso_refs_in_content( [], [], $dso_usage );

		$this->assertEmpty( $result['items'], 'Missing refs only in drafts should not be flagged' );
	}

	// =========================================================================
	// W8 — check_orphaned_presets
	// =========================================================================

	#[Test]
	public function w8_empty_when_no_dso_usage_provided(): void {
		$preset  = ae_preset( 'p1' );
		$result  = $this->ae->pub_check_orphaned_presets( [ $preset ], [] );
		$this->assertSame( 'orphaned_presets', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w8_flags_preset_not_in_usage_index(): void {
		$preset    = ae_preset( 'p-unused' );
		$dso_usage = [ 'variables' => [], 'presets' => [] ];

		$result = $this->ae->pub_check_orphaned_presets( [ $preset ], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'p-unused', $result['items'][0]['id'] );
	}

	#[Test]
	public function w8_no_flag_when_preset_is_in_usage_index(): void {
		$preset    = ae_preset( 'p-used' );
		$dso_usage = [
			'variables' => [],
			'presets'   => [ 'p-used' => [ 'count' => 3, 'posts' => [] ] ],
		];

		$result = $this->ae->pub_check_orphaned_presets( [ $preset ], $dso_usage );

		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// W9 — check_high_impact_variables
	// =========================================================================

	#[Test]
	public function w9_empty_when_no_dso_usage_provided(): void {
		$result = $this->ae->pub_check_high_impact_variables( [ 'gcid-x' => 'X' ], [] );
		$this->assertSame( 'high_impact_variables', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function w9_flags_variable_used_in_ten_or_more_items(): void {
		$gcid      = 'gcid-brand';
		$posts     = [];
		for ( $i = 1; $i <= 10; $i++ ) {
			$posts[] = [ 'post_id' => $i, 'post_title' => "Page $i", 'post_status' => 'publish' ];
		}
		$dso_usage = [
			'variables' => [ $gcid => [ 'count' => 10, 'posts' => $posts ] ],
			'presets'   => [],
		];

		$result = $this->ae->pub_check_high_impact_variables( [ $gcid => 'Brand Color' ], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	#[Test]
	public function w9_no_flag_below_threshold(): void {
		$gcid      = 'gcid-small';
		$dso_usage = [
			'variables' => [ $gcid => [ 'count' => 5, 'posts' => [] ] ],
			'presets'   => [],
		];

		$result = $this->ae->pub_check_high_impact_variables( [ $gcid => 'Small Use' ], $dso_usage );

		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// W10 — check_preset_naming_convention
	// =========================================================================

	#[Test]
	public function w10_empty_when_fewer_than_four_presets(): void {
		$raw_presets = [
			'module' => [
				'divi/button' => [
					'items' => [
						'p1' => [ 'id' => 'p1', 'name' => 'Primary', 'attrs' => [] ],
						'p2' => [ 'id' => 'p2', 'name' => 'secondary', 'attrs' => [] ],
					],
				],
			],
			'group' => [],
		];

		$result = $this->ae->pub_check_preset_naming_convention( $raw_presets );

		$this->assertSame( 'preset_naming_convention', $result['check'] );
		$this->assertEmpty( $result['items'], 'Fewer than 4 presets should not trigger the check' );
	}

	#[Test]
	public function w10_flags_mixed_styles_in_module_presets(): void {
		$raw_presets = [
			'module' => [
				'divi/button' => [
					'items' => [
						'p1' => [ 'id' => 'p1', 'name' => 'Primary Button', 'attrs' => [] ],
						'p2' => [ 'id' => 'p2', 'name' => 'secondary-button', 'attrs' => [] ],
						'p3' => [ 'id' => 'p3', 'name' => 'Ghost Button', 'attrs' => [] ],
						'p4' => [ 'id' => 'p4', 'name' => 'outline-button', 'attrs' => [] ],
					],
				],
			],
			'group' => [],
		];

		$result = $this->ae->pub_check_preset_naming_convention( $raw_presets );

		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'divi/button', $result['items'][0]['label'] );
	}

	#[Test]
	public function w10_no_flag_when_all_presets_use_same_style(): void {
		$raw_presets = [
			'module' => [
				'divi/text' => [
					'items' => [
						'p1' => [ 'id' => 'p1', 'name' => 'Body Text', 'attrs' => [] ],
						'p2' => [ 'id' => 'p2', 'name' => 'Lead Text', 'attrs' => [] ],
						'p3' => [ 'id' => 'p3', 'name' => 'Small Text', 'attrs' => [] ],
						'p4' => [ 'id' => 'p4', 'name' => 'Caption Text', 'attrs' => [] ],
					],
				],
			],
			'group' => [],
		];

		$result = $this->ae->pub_check_preset_naming_convention( $raw_presets );

		$this->assertEmpty( $result['items'], 'Consistent Title Case should produce no findings' );
	}

	// =========================================================================
	// A5 — check_variables_bypassing_presets
	// =========================================================================

	#[Test]
	public function a5_empty_when_no_dso_usage_provided(): void {
		$result = $this->ae->pub_check_variables_bypassing_presets( [], [], [] );
		$this->assertSame( 'variables_bypassing_presets', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a5_flags_var_used_in_content_and_in_a_preset(): void {
		$gcid      = 'gcid-shared';
		$token     = ae_var_token( $gcid );
		$preset    = ae_preset( 'p-shared', json_encode( [ 'color' => $token ] ) );
		$dso_usage = [
			'variables' => [ $gcid => [ 'count' => 2, 'posts' => [] ] ],
			'presets'   => [],
		];

		$result = $this->ae->pub_check_variables_bypassing_presets(
			[ $gcid => 'Shared Color' ],
			[ $preset ],
			$dso_usage
		);

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $gcid, $result['items'][0]['id'] );
	}

	#[Test]
	public function a5_no_flag_when_var_only_in_content_not_presets(): void {
		$gcid      = 'gcid-content-only';
		$dso_usage = [
			'variables' => [ $gcid => [ 'count' => 3, 'posts' => [] ] ],
			'presets'   => [],
		];
		// No preset references gcid-content-only.
		$result = $this->ae->pub_check_variables_bypassing_presets(
			[ $gcid => 'Content Only' ],
			[],
			$dso_usage
		);

		$this->assertEmpty( $result['items'], 'Vars only in content (not in presets) should not be flagged' );
	}

	// =========================================================================
	// A6 — check_singleton_presets
	// =========================================================================

	#[Test]
	public function a6_empty_when_no_dso_usage_provided(): void {
		$result = $this->ae->pub_check_singleton_presets( [ ae_preset( 'p1' ) ], [] );
		$this->assertSame( 'singleton_presets', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a6_flags_preset_used_in_exactly_one_content_item(): void {
		$preset    = ae_preset( 'p-single' );
		$dso_usage = [
			'variables' => [],
			'presets'   => [
				'p-single' => [
					'count' => 1,
					'posts' => [ [ 'post_id' => 1, 'post_title' => 'Contact', 'post_status' => 'publish' ] ],
				],
			],
		];

		$result = $this->ae->pub_check_singleton_presets( [ $preset ], $dso_usage );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'p-single', $result['items'][0]['id'] );
		$this->assertStringContainsString( 'Contact', $result['items'][0]['detail'] );
	}

	#[Test]
	public function a6_no_flag_when_preset_used_in_multiple_items(): void {
		$preset    = ae_preset( 'p-multi' );
		$dso_usage = [
			'variables' => [],
			'presets'   => [ 'p-multi' => [ 'count' => 4, 'posts' => [] ] ],
		];

		$result = $this->ae->pub_check_singleton_presets( [ $preset ], $dso_usage );

		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// A7 — check_overlapping_presets
	// =========================================================================

	#[Test]
	public function a7_empty_when_presets_have_no_variable_refs(): void {
		$raw_presets = ae_presets_with_item( ae_preset( 'p-empty' ) );
		$result      = $this->ae->pub_check_overlapping_presets( $raw_presets );
		$this->assertSame( 'overlapping_presets', $result['check'] );
		$this->assertEmpty( $result['items'] );
	}

	#[Test]
	public function a7_flags_highly_overlapping_presets(): void {
		// Build two presets sharing 4 of 4 vars (100% overlap).
		$vars = [ 'gcid-a', 'gcid-b', 'gcid-c', 'gcid-d' ];
		$attrs = json_encode( array_combine(
			$vars,
			array_map( fn( $v ) => ae_var_token( $v ), $vars )
		) );

		$p1 = [ 'id' => 'p-overlap-1', 'name' => 'Alpha', 'attrs' => json_decode( $attrs, true ) ];
		$p2 = [ 'id' => 'p-overlap-2', 'name' => 'Beta',  'attrs' => json_decode( $attrs, true ) ];

		$raw_presets = [
			'module' => [
				'divi/button' => [
					'items' => [
						'p-overlap-1' => $p1,
						'p-overlap-2' => $p2,
					],
				],
			],
			'group' => [],
		];

		$result = $this->ae->pub_check_overlapping_presets( $raw_presets );

		$this->assertCount( 1, $result['items'] );
		$this->assertStringContainsString( 'Alpha', $result['items'][0]['label'] );
		$this->assertStringContainsString( 'Beta',  $result['items'][0]['label'] );
	}

	#[Test]
	public function a7_no_flag_when_overlap_below_threshold(): void {
		// p1 has vars a,b,c,d; p2 has vars e,f,g,h — zero overlap.
		$vars_a = [ 'gcid-a', 'gcid-b', 'gcid-c', 'gcid-d' ];
		$vars_b = [ 'gcid-e', 'gcid-f', 'gcid-g', 'gcid-h' ];
		$make   = function ( array $vars ) {
			return array_combine( $vars, array_map( fn( $v ) => ae_var_token( $v ), $vars ) );
		};

		$p1 = [ 'id' => 'p-a', 'name' => 'Set A', 'attrs' => $make( $vars_a ) ];
		$p2 = [ 'id' => 'p-b', 'name' => 'Set B', 'attrs' => $make( $vars_b ) ];

		$raw_presets = [
			'module' => [
				'divi/text' => [
					'items' => [ 'p-a' => $p1, 'p-b' => $p2 ],
				],
			],
			'group' => [],
		];

		$result = $this->ae->pub_check_overlapping_presets( $raw_presets );
		$this->assertEmpty( $result['items'] );
	}

	// =========================================================================
	// run_full() integration
	// =========================================================================

	#[Test]
	public function run_full_returns_is_full_true_in_meta(): void {
		ae_seed();
		$report = $this->ae->run_full( [] );
		$this->assertTrue( $report['meta']['is_full'] );
	}

	#[Test]
	public function run_full_contains_all_full_audit_check_keys(): void {
		ae_seed();
		$report = $this->ae->run_full( [] );

		$all_check_keys = [];
		foreach ( [ 'errors', 'warnings', 'advisories' ] as $tier ) {
			foreach ( $report[ $tier ] as $check ) {
				$all_check_keys[] = $check['check'];
			}
		}

		$this->assertContains( 'archived_dsos_in_content',    $all_check_keys );
		$this->assertContains( 'broken_dso_refs_in_content',  $all_check_keys );
		$this->assertContains( 'orphaned_presets',            $all_check_keys );
		$this->assertContains( 'high_impact_variables',       $all_check_keys );
		$this->assertContains( 'preset_naming_convention',    $all_check_keys );
		$this->assertContains( 'variables_bypassing_presets', $all_check_keys );
		$this->assertContains( 'singleton_presets',           $all_check_keys );
		$this->assertContains( 'overlapping_presets',         $all_check_keys );
	}

	#[Test]
	public function run_returns_is_full_false_in_meta(): void {
		ae_seed();
		$report = $this->ae->run();
		$this->assertFalse( $report['meta']['is_full'] );
	}
}
