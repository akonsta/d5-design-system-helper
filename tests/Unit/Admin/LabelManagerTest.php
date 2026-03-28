<?php
/**
 * Tests for LabelManager.
 *
 * LabelManager's public surface is tested through its internal helpers, which
 * are exposed to the test suite via a thin subclass that makes private methods
 * accessible via protected visibility redeclaration.
 *
 * AJAX handler tests (ajax_load / ajax_save) catch the JsonResponseException
 * that the wp_send_json_* stubs throw instead of calling die().
 *
 * Covers:
 *   normalize_label()      : all five case transforms
 *   scope_includes()       : all scope variants × both sections
 *   apply_bulk() - prefix  : scope=all, scope=vars, scope=global_colors, scope=type:colors
 *   apply_bulk() - suffix  : basic
 *   apply_bulk() - find_replace : basic + empty replacement
 *   apply_bulk() - normalize    : all case styles
 *   apply_bulk() - unknown op  : returns input unchanged
 *   sanitize_vars_list()   : non-array input, missing id, all fields present
 *   sanitize_gc_list()     : non-array input, missing id, all fields present
 *   get_global_colors()    : empty presets, populated presets, missing gc key
 *   save_global_colors()   : rebuilds dict; preserves existing module/group keys
 *   ajax_load()            : happy path returns vars + gc
 *   ajax_save()            : happy path (no bulk), with bulk, vars snapshot, gc snapshot
 *   ajax_save()            : empty body returns error; invalid JSON returns error
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\LabelManager;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

// ── Test-only subclass to access private/protected methods ───────────────────

/**
 * Exposes LabelManager internals for white-box testing without using
 * PHP reflection (which couples tests tightly to implementation details).
 */
class TestableLabelManager extends LabelManager {

	public function pub_normalize_label( string $label, string $case ): string {
		// Delegate via parent:: so we test the real implementation.
		return parent::normalize_label( $label, $case );
	}

	public function pub_scope_includes( string $scope, string $section ): bool {
		return parent::scope_includes( $scope, $section );
	}

	public function pub_apply_bulk( array $vars, array $gc, array $bulk ): array {
		return parent::apply_bulk( $vars, $gc, $bulk );
	}

	public function pub_sanitize_vars_list( mixed $input ): array {
		return parent::sanitize_vars_list( $input );
	}

	public function pub_sanitize_gc_list( mixed $input ): array {
		return parent::sanitize_gc_list( $input );
	}

	public function pub_get_global_colors( PresetsRepository $repo ): array {
		return parent::get_global_colors( $repo );
	}

	public function pub_save_global_colors( PresetsRepository $repo, array $list ): bool {
		return parent::save_global_colors( $repo, $list );
	}
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( LabelManager::class )]
final class LabelManagerTest extends TestCase {

	private TestableLabelManager $lm;
	private VarsRepository       $vars_repo;
	private PresetsRepository    $presets_repo;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->lm           = new TestableLabelManager();
		$this->vars_repo    = new VarsRepository();
		$this->presets_repo = new PresetsRepository();
	}

	// ── Fixtures ─────────────────────────────────────────────────────────────

	/** Return a small flat vars list for reuse across tests. */
	private function make_vars( array $overrides = [] ): array {
		return array_merge( [
			[ 'id' => 'c-1', 'label' => 'Primary Blue',   'value' => '#0070f3', 'type' => 'colors',  'status' => 'active', 'order' => 1 ],
			[ 'id' => 'c-2', 'label' => 'Secondary Green', 'value' => '#00b894', 'type' => 'colors',  'status' => 'active', 'order' => 2 ],
			[ 'id' => 'n-1', 'label' => 'Base Spacing',    'value' => '8px',    'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		], $overrides );
	}

	/** Return a small global colors list for reuse. */
	private function make_gc(): array {
		return [
			[ 'id' => 'gcid-1', 'label' => 'Brand Red',   'value' => '#e74c3c', 'status' => 'active' ],
			[ 'id' => 'gcid-2', 'label' => 'Brand Green',  'value' => '#27ae60', 'status' => 'active' ],
		];
	}

	// ── normalize_label() ────────────────────────────────────────────────────

	#[Test]
	#[DataProvider( 'normalizeProvider' )]
	public function normalize_label_converts_correctly( string $input, string $case, string $expected ): void {
		$this->assertSame( $expected, $this->lm->pub_normalize_label( $input, $case ) );
	}

	public static function normalizeProvider(): array {
		return [
			'title case'           => [ 'hello world',      'title', 'Hello World' ],
			'title case uppercase' => [ 'HELLO WORLD',      'title', 'Hello World' ],
			'title case mixed'     => [ 'hElLo WoRlD',      'title', 'Hello World' ],
			'upper case'           => [ 'hello world',      'upper', 'HELLO WORLD' ],
			'lower case'           => [ 'HELLO WORLD',      'lower', 'hello world' ],
			'snake case spaces'    => [ 'Hello World',      'snake', 'hello_world' ],
			'snake case hyphens'   => [ 'hello-world',      'snake', 'hello_world' ],
			'snake multi spaces'   => [ 'Hello  Big World', 'snake', 'hello_big_world' ],
			'camel case'           => [ 'hello world',      'camel', 'helloWorld' ],
			'camel multi word'     => [ 'primary base font', 'camel', 'primaryBaseFont' ],
			'unknown case passthru'=> [ 'Hello World',      'bogus', 'Hello World' ],
			'empty string'         => [ '',                 'title', '' ],
		];
	}

	// ── scope_includes() ─────────────────────────────────────────────────────

	#[Test]
	#[DataProvider( 'scopeProvider' )]
	public function scope_includes_returns_correct_bool(
		string $scope,
		string $section,
		bool   $expected
	): void {
		$this->assertSame( $expected, $this->lm->pub_scope_includes( $scope, $section ) );
	}

	public static function scopeProvider(): array {
		return [
			'all → vars'                 => [ 'all',           'vars',          true  ],
			'all → global_colors'        => [ 'all',           'global_colors', true  ],
			'vars → vars'                => [ 'vars',          'vars',          true  ],
			'vars → global_colors'       => [ 'vars',          'global_colors', false ],
			'global_colors → gc'         => [ 'global_colors', 'global_colors', true  ],
			'global_colors → vars'       => [ 'global_colors', 'vars',          false ],
			'type:colors → vars'         => [ 'type:colors',   'vars',          true  ],
			'type:colors → global_colors'=> [ 'type:colors',   'global_colors', false ],
			'type:numbers → vars'        => [ 'type:numbers',  'vars',          true  ],
			'unknown scope → vars'       => [ 'other_scope',   'vars',          false ],
			'unknown scope → gc'         => [ 'other_scope',   'global_colors', false ],
		];
	}

	// ── apply_bulk() - prefix ─────────────────────────────────────────────────

	#[Test]
	public function apply_bulk_prefix_applies_to_all_labels_by_default(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();

		[ $v, $g ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'prefix', 'scope' => 'all', 'value' => 'DS-' ] );

		foreach ( $v as $item ) {
			$this->assertStringStartsWith( 'DS-', $item['label'] );
		}
		foreach ( $g as $item ) {
			$this->assertStringStartsWith( 'DS-', $item['label'] );
		}
	}

	#[Test]
	public function apply_bulk_prefix_scope_vars_does_not_affect_global_colors(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();
		$original_gc_labels = array_column( $gc, 'label' );

		[ , $g ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'prefix', 'scope' => 'vars', 'value' => 'V-' ] );

		$this->assertSame( $original_gc_labels, array_column( $g, 'label' ) );
	}

	#[Test]
	public function apply_bulk_prefix_scope_global_colors_does_not_affect_vars(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();
		$original_var_labels = array_column( $vars, 'label' );

		[ $v ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'prefix', 'scope' => 'global_colors', 'value' => 'GC-' ] );

		$this->assertSame( $original_var_labels, array_column( $v, 'label' ) );
	}

	#[Test]
	public function apply_bulk_prefix_type_filter_affects_only_matching_type(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();

		[ $v ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'prefix', 'scope' => 'type:colors', 'value' => 'CLR-' ] );

		$colors  = array_filter( $v, fn( $i ) => $i['type'] === 'colors' );
		$numbers = array_filter( $v, fn( $i ) => $i['type'] === 'numbers' );

		foreach ( $colors as $item ) {
			$this->assertStringStartsWith( 'CLR-', $item['label'] );
		}
		foreach ( $numbers as $item ) {
			$this->assertStringStartsNotWith( 'CLR-', $item['label'] );
		}
	}

	#[Test]
	public function apply_bulk_prefix_with_empty_value_leaves_labels_unchanged(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();
		$original = array_column( $vars, 'label' );

		[ $v ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'prefix', 'scope' => 'vars', 'value' => '' ] );

		$this->assertSame( $original, array_column( $v, 'label' ) );
	}

	// ── apply_bulk() - suffix ─────────────────────────────────────────────────

	#[Test]
	public function apply_bulk_suffix_appends_text(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();

		[ $v ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'suffix', 'scope' => 'vars', 'value' => '-v2' ] );

		foreach ( $v as $item ) {
			$this->assertStringEndsWith( '-v2', $item['label'] );
		}
	}

	// ── apply_bulk() - find_replace ───────────────────────────────────────────

	#[Test]
	public function apply_bulk_find_replace_replaces_matching_substring(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();

		[ $v ] = $this->lm->pub_apply_bulk( $vars, $gc, [
			'op'      => 'find_replace',
			'scope'   => 'vars',
			'find'    => 'Blue',
			'replace' => 'Cobalt',
		] );

		$primary = current( array_filter( $v, fn( $i ) => $i['id'] === 'c-1' ) );
		$this->assertSame( 'Primary Cobalt', $primary['label'] );
	}

	#[Test]
	public function apply_bulk_find_replace_with_empty_replacement_removes_text(): void {
		$vars = $this->make_vars();

		[ $v ] = $this->lm->pub_apply_bulk( $vars, [], [
			'op'      => 'find_replace',
			'scope'   => 'vars',
			'find'    => ' Blue',
			'replace' => '',
		] );

		$primary = current( array_filter( $v, fn( $i ) => $i['id'] === 'c-1' ) );
		$this->assertSame( 'Primary', $primary['label'] );
	}

	#[Test]
	public function apply_bulk_find_replace_does_nothing_when_find_not_present(): void {
		$vars     = $this->make_vars();
		$original = array_column( $vars, 'label' );

		[ $v ] = $this->lm->pub_apply_bulk( $vars, [], [
			'op'      => 'find_replace',
			'scope'   => 'vars',
			'find'    => 'zzzzz',
			'replace' => 'nope',
		] );

		$this->assertSame( $original, array_column( $v, 'label' ) );
	}

	// ── apply_bulk() - normalize ──────────────────────────────────────────────

	#[Test]
	public function apply_bulk_normalize_title_capitalizes_words(): void {
		$vars = [ [ 'id' => 'n-1', 'label' => 'base spacing', 'value' => '8px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ] ];

		[ $v ] = $this->lm->pub_apply_bulk( $vars, [], [ 'op' => 'normalize', 'scope' => 'vars', 'case' => 'title' ] );

		$this->assertSame( 'Base Spacing', $v[0]['label'] );
	}

	#[Test]
	public function apply_bulk_normalize_snake_replaces_spaces_with_underscores(): void {
		$vars = [ [ 'id' => 'n-1', 'label' => 'Base Spacing', 'value' => '8px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ] ];

		[ $v ] = $this->lm->pub_apply_bulk( $vars, [], [ 'op' => 'normalize', 'scope' => 'vars', 'case' => 'snake' ] );

		$this->assertSame( 'base_spacing', $v[0]['label'] );
	}

	#[Test]
	public function apply_bulk_normalize_camel_produces_camel_case(): void {
		$vars = [ [ 'id' => 'n-1', 'label' => 'base spacing value', 'value' => '8px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ] ];

		[ $v ] = $this->lm->pub_apply_bulk( $vars, [], [ 'op' => 'normalize', 'scope' => 'vars', 'case' => 'camel' ] );

		$this->assertSame( 'baseSpacingValue', $v[0]['label'] );
	}

	// ── apply_bulk() - unknown op ─────────────────────────────────────────────

	#[Test]
	public function apply_bulk_unknown_op_returns_input_unchanged(): void {
		$vars = $this->make_vars();
		$gc   = $this->make_gc();
		$original_vars = $vars;
		$original_gc   = $gc;

		[ $v, $g ] = $this->lm->pub_apply_bulk( $vars, $gc, [ 'op' => 'nonexistent_op', 'scope' => 'all' ] );

		$this->assertSame( $original_vars, $v );
		$this->assertSame( $original_gc,   $g );
	}

	// ── sanitize_vars_list() ─────────────────────────────────────────────────

	#[Test]
	public function sanitize_vars_list_returns_empty_for_non_array_input(): void {
		$this->assertSame( [], $this->lm->pub_sanitize_vars_list( null ) );
		$this->assertSame( [], $this->lm->pub_sanitize_vars_list( 'string' ) );
		$this->assertSame( [], $this->lm->pub_sanitize_vars_list( 42 ) );
	}

	#[Test]
	public function sanitize_vars_list_skips_entries_missing_id(): void {
		$input = [
			[ 'label' => 'No ID', 'value' => 'x', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
			[ 'id' => 'n-1', 'label' => 'Has ID', 'value' => '1', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];

		$result = $this->lm->pub_sanitize_vars_list( $input );

		$this->assertCount( 1, $result );
		$this->assertSame( 'n-1', $result[0]['id'] );
	}

	#[Test]
	public function sanitize_vars_list_strips_html_from_label(): void {
		$input = [ [ 'id' => 'c-1', 'label' => '<b>Bold</b>', 'value' => '#fff', 'type' => 'colors', 'status' => 'active', 'order' => 1 ] ];

		$result = $this->lm->pub_sanitize_vars_list( $input );

		$this->assertSame( 'Bold', $result[0]['label'] );
	}

	#[Test]
	public function sanitize_vars_list_defaults_type_to_numbers(): void {
		$input = [ [ 'id' => 'x-1', 'label' => 'X', 'value' => 'y', 'status' => 'active', 'order' => 1 ] ];

		$result = $this->lm->pub_sanitize_vars_list( $input );

		$this->assertSame( 'numbers', $result[0]['type'] );
	}

	#[Test]
	public function sanitize_vars_list_enforces_minimum_order_of_1(): void {
		$input = [ [ 'id' => 'x-1', 'label' => 'X', 'value' => 'y', 'type' => 'numbers', 'status' => 'active', 'order' => -5 ] ];

		$result = $this->lm->pub_sanitize_vars_list( $input );

		$this->assertSame( 1, $result[0]['order'] );
	}

	#[Test]
	public function sanitize_vars_list_defaults_missing_optional_fields(): void {
		$input = [ [ 'id' => 'x-1' ] ];

		$result = $this->lm->pub_sanitize_vars_list( $input );

		$this->assertSame( '',        $result[0]['label'] );
		$this->assertSame( '',        $result[0]['value'] );
		$this->assertSame( 'numbers', $result[0]['type'] );
		$this->assertSame( 'active',  $result[0]['status'] );
		$this->assertSame( 1,         $result[0]['order'] );
	}

	// ── sanitize_gc_list() ────────────────────────────────────────────────────

	#[Test]
	public function sanitize_gc_list_returns_empty_for_non_array(): void {
		$this->assertSame( [], $this->lm->pub_sanitize_gc_list( false ) );
	}

	#[Test]
	public function sanitize_gc_list_skips_entries_without_id(): void {
		$input = [
			[ 'label' => 'No ID', 'value' => '#fff', 'status' => 'active' ],
			[ 'id' => 'gcid-1', 'label' => 'Red', 'value' => '#f00', 'status' => 'active' ],
		];

		$result = $this->lm->pub_sanitize_gc_list( $input );

		$this->assertCount( 1, $result );
		$this->assertSame( 'gcid-1', $result[0]['id'] );
	}

	#[Test]
	public function sanitize_gc_list_defaults_status_to_active(): void {
		$input = [ [ 'id' => 'gcid-1', 'label' => 'X', 'value' => '#ff0' ] ];

		$result = $this->lm->pub_sanitize_gc_list( $input );

		$this->assertSame( 'active', $result[0]['status'] );
	}

	// ── get_global_colors() ───────────────────────────────────────────────────

	#[Test]
	public function get_global_colors_returns_empty_when_no_global_colors_key(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [ 'module' => [], 'group' => [] ];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		$this->assertSame( [], $result );
	}

	#[Test]
	public function get_global_colors_returns_flat_list(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'module'        => [],
			'group'         => [],
			'global_colors' => [
				'gcid-1' => [ 'id' => 'gcid-1', 'label' => 'Red',  'value' => '#f00', 'status' => 'active' ],
				'gcid-2' => [ 'id' => 'gcid-2', 'label' => 'Blue', 'value' => '#00f', 'status' => 'archived' ],
			],
		];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		$this->assertCount( 2, $result );
		$this->assertSame( 'gcid-1', $result[0]['id'] );
		$this->assertSame( 'Red',    $result[0]['label'] );
		$this->assertSame( '#f00',   $result[0]['value'] );
		$this->assertSame( 'active', $result[0]['status'] );
	}

	#[Test]
	public function get_global_colors_includes_type_global_color_on_every_item(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'global_colors' => [
				'gcid-1' => [ 'id' => 'gcid-1', 'label' => 'Red',  'value' => '#f00', 'status' => 'active' ],
				'gcid-2' => [ 'id' => 'gcid-2', 'label' => 'Blue', 'value' => '#00f', 'status' => 'active' ],
			],
		];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		foreach ( $result as $item ) {
			$this->assertArrayHasKey( 'type', $item, 'Each global color item must carry a type key' );
			$this->assertSame( 'global_color', $item['type'], 'type must be global_color for JS hide-system filter' );
		}
	}

	#[Test]
	public function get_global_colors_uses_array_key_as_id_fallback(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'global_colors' => [
				'gcid-fallback' => [ 'label' => 'No ID', 'value' => '#abc', 'status' => 'active' ],
			],
		];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		$this->assertSame( 'gcid-fallback', $result[0]['id'] );
	}

	#[Test]
	public function get_global_colors_defaults_missing_fields(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'global_colors' => [
				'gcid-x' => [ 'id' => 'gcid-x' ],
			],
		];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		$this->assertSame( '',       $result[0]['label'] );
		$this->assertSame( '',       $result[0]['value'] );
		$this->assertSame( 'active', $result[0]['status'] );
	}

	#[Test]
	public function get_global_colors_returns_empty_when_global_colors_is_not_array(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'global_colors' => 'corrupted',
		];

		$result = $this->lm->pub_get_global_colors( $this->presets_repo );

		$this->assertSame( [], $result );
	}

	// ── save_global_colors() ─────────────────────────────────────────────────

	#[Test]
	public function save_global_colors_rebuilds_dict_under_global_colors_key(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [ 'module' => [ 'x' => [] ], 'group' => [] ];

		$list = [
			[ 'id' => 'gcid-1', 'label' => 'Red',  'value' => '#f00', 'status' => 'active' ],
			[ 'id' => 'gcid-2', 'label' => 'Blue', 'value' => '#00f', 'status' => 'active' ],
		];

		$this->lm->pub_save_global_colors( $this->presets_repo, $list );

		$stored = $GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ];
		$this->assertArrayHasKey( 'global_colors', $stored );
		$this->assertArrayHasKey( 'gcid-1', $stored['global_colors'] );
		$this->assertArrayHasKey( 'gcid-2', $stored['global_colors'] );
	}

	#[Test]
	public function save_global_colors_preserves_existing_module_and_group_keys(): void {
		$original_modules = [ 'divi/button' => [ 'default' => 'p1', 'items' => [] ] ];
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'module' => $original_modules,
			'group'  => [],
		];

		$this->lm->pub_save_global_colors( $this->presets_repo, [
			[ 'id' => 'gcid-1', 'label' => 'Red', 'value' => '#f00', 'status' => 'active' ],
		] );

		$stored = $GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ];
		$this->assertSame( $original_modules, $stored['module'] );
	}

	#[Test]
	public function save_global_colors_skips_entries_with_empty_id(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [ 'module' => [], 'group' => [] ];

		$list = [
			[ 'id' => '',       'label' => 'No ID', 'value' => '#fff', 'status' => 'active' ],
			[ 'id' => 'gcid-1', 'label' => 'Valid', 'value' => '#0f0', 'status' => 'active' ],
		];

		$this->lm->pub_save_global_colors( $this->presets_repo, $list );

		$stored = $GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ]['global_colors'];
		$this->assertCount( 1, $stored );
		$this->assertArrayHasKey( 'gcid-1', $stored );
	}

	#[Test]
	public function save_global_colors_returns_true_on_success(): void {
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [ 'module' => [], 'group' => [] ];

		$result = $this->lm->pub_save_global_colors( $this->presets_repo, [] );

		$this->assertTrue( $result );
	}

	// ── ajax_load() ───────────────────────────────────────────────────────────

	#[Test]
	public function ajax_load_returns_vars_and_global_colors_on_success(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [
			'colors' => [ 'c-1' => [ 'id' => 'c-1', 'label' => 'Red', 'value' => '#f00', 'status' => 'active' ] ],
		];
		$GLOBALS['_d5dsh_options'][ PresetsRepository::OPTION_KEY ] = [
			'module'        => [],
			'group'         => [],
			'global_colors' => [ 'gcid-1' => [ 'id' => 'gcid-1', 'label' => 'Blue', 'value' => '#00f', 'status' => 'active' ] ],
		];

		try {
			$this->lm->ajax_load();
			$this->fail( 'Expected JsonResponseException' );
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'vars',          $e->data );
			$this->assertArrayHasKey( 'global_colors', $e->data );
			$this->assertCount( 1, $e->data['vars'] );
			$this->assertCount( 1, $e->data['global_colors'] );
		}
	}

	#[Test]
	public function ajax_load_returns_empty_lists_when_db_is_empty(): void {
		try {
			$this->lm->ajax_load();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertSame( [], $e->data['vars'] );
			$this->assertSame( [], $e->data['global_colors'] );
		}
	}

	// ── ajax_save() ───────────────────────────────────────────────────────────

	#[Test]
	public function ajax_save_writes_vars_to_database(): void {
		$vars = [
			[ 'id' => 'n-1', 'label' => 'Updated Spacing', 'value' => '16px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];
		$this->seed_php_input( [ 'vars' => $vars, 'global_colors' => [] ] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
		}

		$stored = $GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ];
		$this->assertArrayHasKey( 'numbers', $stored );
		$this->assertSame( 'Updated Spacing', $stored['numbers']['n-1']['label'] );
	}

	#[Test]
	public function ajax_save_writes_colors_to_et_divi(): void {
		// Colors are now stored in et_divi[et_global_data][global_colors], not presets.
		$vars = [
			[ 'id' => 'gcid-1', 'label' => 'New Red', 'value' => '#ff0000', 'type' => 'colors', 'status' => 'active', 'order' => 1 ],
		];
		$this->seed_php_input( [ 'vars' => $vars, 'global_colors' => [] ] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
		}

		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$stored  = $et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] ?? [];
		$this->assertSame( 'New Red', $stored['gcid-1']['label'] );
	}

	#[Test]
	public function ajax_save_snapshots_vars_before_write(): void {
		// Pre-populate non-color vars so there's something to snapshot.
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [
			'numbers' => [ 'n-1' => [ 'id' => 'n-1', 'label' => 'Old Size', 'value' => '12px', 'status' => 'active' ] ],
		];

		$vars = [ [ 'id' => 'n-1', 'label' => 'New Size', 'value' => '16px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ] ];
		$this->seed_php_input( [ 'vars' => $vars, 'global_colors' => [] ] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException ) {}

		// A snapshot must have been created at index 0 with trigger='manage'.
		$snap = get_option( 'd5dsh_snap_vars_0' );
		$this->assertNotFalse( $snap );
		$this->assertArrayHasKey( 'numbers', $snap );
		$this->assertSame( 'Old Size', $snap['numbers']['n-1']['label'] );
	}

	#[Test]
	public function ajax_save_applies_bulk_op_before_saving(): void {
		// Colors are now stored in et_divi, not et_divi_global_variables.
		$vars = [
			[ 'id' => 'gcid-1', 'label' => 'primary blue', 'value' => '#00f', 'type' => 'colors', 'status' => 'active', 'order' => 1 ],
		];
		$this->seed_php_input( [
			'vars'          => $vars,
			'global_colors' => [],
			'bulk'          => [ 'op' => 'normalize', 'scope' => 'vars', 'case' => 'title' ],
		] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
		}

		// Colors are saved to et_divi[et_global_data][global_colors].
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$stored_colors = $et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] ?? [];
		$this->assertSame( 'Primary Blue', $stored_colors['gcid-1']['label'] );
	}

	#[Test]
	public function ajax_save_returns_updated_state_in_response(): void {
		$vars = [ [ 'id' => 'n-1', 'label' => 'Spacing', 'value' => '8px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ] ];
		$this->seed_php_input( [ 'vars' => $vars, 'global_colors' => [] ] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'vars',          $e->data );
			$this->assertArrayHasKey( 'global_colors', $e->data );
			$this->assertArrayHasKey( 'vars_saved',    $e->data );
			$this->assertArrayHasKey( 'gc_saved',      $e->data );
		}
	}

	#[Test]
	public function ajax_save_returns_error_on_empty_body(): void {
		// Seed empty input.
		$GLOBALS['_d5dsh_php_input'] = '';

		try {
			$this->lm->ajax_save();
			$this->fail( 'Expected JsonResponseException' );
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
		}
	}

	#[Test]
	public function ajax_save_returns_error_on_invalid_json(): void {
		$GLOBALS['_d5dsh_php_input'] = '{not valid json';

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
		}
	}

	// ── Integration: bulk prefix round-trip through save ─────────────────────

	#[Test]
	public function bulk_prefix_round_trip_persists_prefixed_labels(): void {
		$vars = [
			[ 'id' => 'gcid-1', 'label' => 'Blue',  'value' => '#00f', 'type' => 'colors',  'status' => 'active', 'order' => 1 ],
			[ 'id' => 'gcid-2', 'label' => 'Green', 'value' => '#0f0', 'type' => 'colors',  'status' => 'active', 'order' => 2 ],
			[ 'id' => 'n-1',    'label' => 'Space',  'value' => '8px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];
		$this->seed_php_input( [
			'vars'          => $vars,
			'global_colors' => [],
			'bulk'          => [ 'op' => 'prefix', 'scope' => 'type:colors', 'value' => 'clr/' ],
		] );

		try {
			$this->lm->ajax_save();
		} catch ( JsonResponseException ) {}

		// Colors saved to et_divi[et_global_data][global_colors].
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$stored_colors = $et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] ?? [];
		$this->assertSame( 'clr/Blue',  $stored_colors['gcid-1']['label'] );
		$this->assertSame( 'clr/Green', $stored_colors['gcid-2']['label'] );

		// Non-color vars still saved to et_divi_global_variables.
		$stored_vars = $GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ];
		$this->assertSame( 'Space', $stored_vars['numbers']['n-1']['label'] );
	}

	// ── Helper: seed php://input substitute ─────────────────────────────────

	/**
	 * LabelManager reads file_get_contents('php://input') which is impossible
	 * to mock directly.  We stub it out at the global level.
	 *
	 * The bootstrap file defines a file_get_contents() wrapper that checks
	 * $GLOBALS['_d5dsh_php_input'] first — see WPFunctions.php.
	 */
	private function seed_php_input( array $payload ): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( $payload );
	}
}
