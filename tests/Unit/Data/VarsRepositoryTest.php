<?php
/**
 * Tests for VarsRepository.
 *
 * Covers:
 *   - get_all()                : empty DB, malformed DB, standard data (vars + colors + system fonts)
 *   - get_raw()                : mirrors the et_divi_global_variables option value
 *   - get_raw_colors()         : reads from et_divi[et_global_data][global_colors]
 *   - get_raw_system_fonts()   : reads heading_font/body_font from et_divi top level
 *   - get_raw_system_colors()  : reads accent_color/secondary_accent_color/header_color/font_color from et_divi
 *   - save_raw()               : delegates to update_option for vars
 *   - save_raw_colors()        : writes back into et_divi[et_global_data][global_colors]
 *   - save_raw_system_fonts()  : writes heading_font/body_font back into et_divi
 *   - save_raw_system_colors() : writes accent_color etc. back into et_divi
 *   - backup()                 : creates a uniquely-named option containing vars, colors, system_fonts, system_colors
 *   - normalize()              : system colors first, then user colors, then vars + system fonts + user fonts, ordering
 *   - denormalize()            : flat → nested (non-colors, non-system-fonts only)
 *   - denormalize_system_fonts()  : flat → synthesized-ID map for save_raw_system_fonts()
 *   - denormalize_system_colors() : flat → synthesized-ID map for save_raw_system_colors()
 *   - denormalize_colors()     : flat → global_colors dict, merges non-editable fields (skips system colors)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Data;

use D5DesignSystemHelper\Data\VarsRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass( VarsRepository::class )]
final class VarsRepositoryTest extends TestCase {

	private VarsRepository $repo;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->repo = new VarsRepository();
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** Set the raw colors dict inside the et_divi option the way Divi stores it. */
	private function set_colors( array $colors ): void {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] = $colors;
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;
	}

	/** Return the raw colors dict from the et_divi stub. */
	private function get_colors(): array {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		return $et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] ?? [];
	}

	/** Set system font values directly in the et_divi option stub. */
	private function set_system_fonts( string $heading, string $body ): void {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$et_divi['heading_font'] = $heading;
		$et_divi['body_font']    = $body;
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;
	}

	/** Return the system font values from the et_divi stub. */
	private function get_system_fonts_from_stub(): array {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		return [
			'heading_font' => $et_divi['heading_font'] ?? null,
			'body_font'    => $et_divi['body_font']    ?? null,
		];
	}

	// ── get_all() ─────────────────────────────────────────────────────────────

	#[Test]
	public function get_all_returns_empty_when_both_options_missing(): void {
		$this->assertSame( [], $this->repo->get_all() );
	}

	#[Test]
	public function get_all_returns_empty_when_vars_option_is_false(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = false;
		$this->assertSame( [], $this->repo->get_all() );
	}

	#[Test]
	public function get_all_returns_empty_when_vars_option_is_empty_array(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [];
		$this->assertSame( [], $this->repo->get_all() );
	}

	#[Test]
	public function get_all_returns_colors_from_et_divi(): void {
		$this->set_colors( [
			'gcid-abc' => [ 'id' => 'gcid-abc', 'label' => 'Primary', 'color' => '#fff', 'status' => 'active', 'order' => 1 ],
		] );

		$result = $this->repo->get_all();

		$this->assertCount( 1, $result );
		$this->assertSame( 'gcid-abc', $result[0]['id'] );
		$this->assertSame( 'colors',   $result[0]['type'] );
		$this->assertSame( '#fff',     $result[0]['value'] ); // 'color' field mapped to 'value'
	}

	#[Test]
	public function get_all_returns_vars_from_et_divi_global_variables(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [
			'numbers' => [
				'gvid-001' => [ 'id' => 'gvid-001', 'label' => 'Base Size', 'value' => '16px', 'status' => 'active' ],
			],
		];

		$result = $this->repo->get_all();

		$this->assertCount( 1, $result );
		$this->assertSame( 'gvid-001', $result[0]['id'] );
		$this->assertSame( 'numbers',  $result[0]['type'] );
	}

	#[Test]
	public function get_all_returns_colors_first_then_vars(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [
			'numbers' => [
				'gvid-001' => [ 'id' => 'gvid-001', 'label' => 'Base Size', 'value' => '16px', 'status' => 'active' ],
			],
		];
		$this->set_colors( [
			'gcid-abc' => [ 'id' => 'gcid-abc', 'label' => 'Primary', 'color' => '#fff', 'status' => 'active', 'order' => 1 ],
		] );

		$result = $this->repo->get_all();

		$this->assertCount( 2, $result );
		$this->assertSame( 'colors',  $result[0]['type'] );
		$this->assertSame( 'numbers', $result[1]['type'] );
	}

	// ── get_raw() ─────────────────────────────────────────────────────────────

	#[Test]
	public function get_raw_returns_empty_array_when_option_missing(): void {
		$this->assertSame( [], $this->repo->get_raw() );
	}

	#[Test]
	public function get_raw_returns_vars_option_value_unchanged(): void {
		$data = [ 'numbers' => [ 'gvid-x' => [ 'id' => 'gvid-x', 'label' => 'X', 'value' => '1', 'status' => 'active' ] ] ];
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = $data;

		$this->assertSame( $data, $this->repo->get_raw() );
	}

	// ── get_raw_colors() ─────────────────────────────────────────────────────

	#[Test]
	public function get_raw_colors_returns_empty_when_et_divi_missing(): void {
		$this->assertSame( [], $this->repo->get_raw_colors() );
	}

	#[Test]
	public function get_raw_colors_returns_global_colors_dict(): void {
		$colors = [
			'gcid-abc' => [ 'id' => 'gcid-abc', 'label' => 'Primary', 'color' => '#fff', 'status' => 'active', 'order' => 1 ],
		];
		$this->set_colors( $colors );

		$this->assertSame( $colors, $this->repo->get_raw_colors() );
	}

	// ── save_raw() ────────────────────────────────────────────────────────────

	#[Test]
	public function save_raw_writes_to_vars_option_key(): void {
		$data = [ 'numbers' => [ 'gvid-1' => [ 'id' => 'gvid-1', 'label' => 'A', 'value' => '1', 'status' => 'active' ] ] ];
		$result = $this->repo->save_raw( $data );

		$this->assertTrue( $result );
		$this->assertSame( $data, $GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] );
	}

	// ── save_raw_colors() ────────────────────────────────────────────────────

	#[Test]
	public function save_raw_colors_writes_into_et_divi_global_data(): void {
		$colors = [
			'gcid-abc' => [ 'id' => 'gcid-abc', 'label' => 'Primary', 'color' => '#fff', 'status' => 'active', 'order' => 1 ],
		];
		$result = $this->repo->save_raw_colors( $colors );

		$this->assertTrue( $result );
		$this->assertSame( $colors, $this->get_colors() );
	}

	#[Test]
	public function save_raw_colors_preserves_other_et_divi_keys(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = [
			'accent_color' => '#0000ff',
		];
		$colors = [ 'gcid-x' => [ 'id' => 'gcid-x', 'label' => 'X', 'color' => '#000', 'status' => 'active', 'order' => 1 ] ];
		$this->repo->save_raw_colors( $colors );

		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ];
		$this->assertSame( '#0000ff', $et_divi['accent_color'] );
		$this->assertSame( $colors, $et_divi[ VarsRepository::COLORS_DATA_KEY ][ VarsRepository::COLORS_COLORS_KEY ] );
	}

	// ── backup() ─────────────────────────────────────────────────────────────

	#[Test]
	public function backup_creates_new_option_with_prefix(): void {
		$key = $this->repo->backup();

		$this->assertStringStartsWith( VarsRepository::BACKUP_KEY_PREFIX, $key );
		$this->assertArrayHasKey( $key, $GLOBALS['_d5dsh_options'] );
	}

	#[Test]
	public function backup_stores_vars_colors_system_fonts_and_system_colors_keys(): void {
		$vars   = [ 'numbers' => [ 'gvid-1' => [ 'id' => 'gvid-1', 'label' => 'A', 'value' => '1', 'status' => 'active' ] ] ];
		$colors = [ 'gcid-x' => [ 'id' => 'gcid-x', 'label' => 'X', 'color' => '#000', 'status' => 'active', 'order' => 1 ] ];
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = $vars;
		$this->set_colors( $colors );
		$this->set_system_fonts( 'Fraunces', 'Manrope' );

		$key    = $this->repo->backup();
		$backup = $GLOBALS['_d5dsh_options'][ $key ];

		$this->assertSame( $vars,   $backup['vars'] );
		$this->assertSame( $colors, $backup['colors'] );
		$this->assertArrayHasKey( 'system_fonts',  $backup );
		$this->assertArrayHasKey( 'system_colors', $backup );
		$this->assertSame( 'Fraunces', $backup['system_fonts']['--et_global_heading_font'] );
		$this->assertSame( 'Manrope',  $backup['system_fonts']['--et_global_body_font'] );
	}

	#[Test]
	public function backup_returns_key_string(): void {
		$key = $this->repo->backup();
		$this->assertIsString( $key );
		$this->assertNotEmpty( $key );
	}

	// ── normalize() ───────────────────────────────────────────────────────────

	#[Test]
	public function normalize_returns_empty_for_empty_input(): void {
		$this->assertSame( [], $this->repo->normalize( [] ) );
	}

	#[Test]
	public function normalize_maps_color_field_to_value(): void {
		$colors_raw = [
			'gcid-abc' => [ 'id' => 'gcid-abc', 'label' => 'Primary', 'color' => '#ff0000', 'status' => 'active', 'order' => 1 ],
		];

		$flat = $this->repo->normalize( [], $colors_raw );

		$this->assertCount( 1, $flat );
		$this->assertSame( '#ff0000', $flat[0]['value'] );
		$this->assertSame( 'colors',  $flat[0]['type'] );
	}

	#[Test]
	public function normalize_sorts_colors_by_order_field(): void {
		$colors_raw = [
			'gcid-b' => [ 'id' => 'gcid-b', 'label' => 'B', 'color' => '#bbb', 'status' => 'active', 'order' => 2 ],
			'gcid-a' => [ 'id' => 'gcid-a', 'label' => 'A', 'color' => '#aaa', 'status' => 'active', 'order' => 1 ],
		];

		$flat = $this->repo->normalize( [], $colors_raw );

		$this->assertSame( 'gcid-a', $flat[0]['id'] );
		$this->assertSame( 'gcid-b', $flat[1]['id'] );
	}

	#[Test]
	public function normalize_places_colors_before_vars(): void {
		$vars_raw = [
			'numbers' => [ 'gvid-1' => [ 'id' => 'gvid-1', 'label' => 'N', 'value' => '1', 'status' => 'active' ] ],
		];
		$colors_raw = [
			'gcid-a' => [ 'id' => 'gcid-a', 'label' => 'A', 'color' => '#aaa', 'status' => 'active', 'order' => 1 ],
		];

		$flat = $this->repo->normalize( $vars_raw, $colors_raw );

		$this->assertSame( 'colors',  $flat[0]['type'] );
		$this->assertSame( 'numbers', $flat[1]['type'] );
	}

	#[Test]
	public function normalize_assigns_1based_order_within_vars_type(): void {
		$vars_raw = [
			'numbers' => [
				'gvid-1' => [ 'id' => 'gvid-1', 'label' => 'A', 'value' => '1', 'status' => 'active' ],
				'gvid-2' => [ 'id' => 'gvid-2', 'label' => 'B', 'value' => '2', 'status' => 'active' ],
				'gvid-3' => [ 'id' => 'gvid-3', 'label' => 'C', 'value' => '3', 'status' => 'active' ],
			],
		];

		$flat = $this->repo->normalize( $vars_raw );

		$this->assertSame( 1, $flat[0]['order'] );
		$this->assertSame( 2, $flat[1]['order'] );
		$this->assertSame( 3, $flat[2]['order'] );
	}

	#[Test]
	public function normalize_uses_array_key_as_id_fallback_for_colors(): void {
		$colors_raw = [
			'gcid-fallback' => [ 'label' => 'No ID field', 'color' => '#abc', 'status' => 'active', 'order' => 1 ],
		];

		$flat = $this->repo->normalize( [], $colors_raw );

		$this->assertSame( 'gcid-fallback', $flat[0]['id'] );
	}

	#[Test]
	public function normalize_defaults_status_to_active(): void {
		$vars_raw = [
			'numbers' => [
				'gvid-x' => [ 'id' => 'gvid-x', 'label' => 'X', 'value' => '0' ],
			],
		];

		$flat = $this->repo->normalize( $vars_raw );

		$this->assertSame( 'active', $flat[0]['status'] );
	}

	#[Test]
	public function normalize_defaults_label_and_value_to_empty_string(): void {
		$vars_raw = [
			'numbers' => [
				'gvid-x' => [ 'id' => 'gvid-x' ],
			],
		];

		$flat = $this->repo->normalize( $vars_raw );

		$this->assertSame( '', $flat[0]['label'] );
		$this->assertSame( '', $flat[0]['value'] );
	}

	#[Test]
	public function normalize_includes_unknown_var_types_after_known(): void {
		$vars_raw = [
			'custom_type' => [ 'ct-1' => [ 'id' => 'ct-1', 'label' => 'CT', 'value' => 'v', 'status' => 'active' ] ],
			'numbers'     => [ 'n-1'  => [ 'id' => 'n-1',  'label' => 'N',  'value' => '1', 'status' => 'active' ] ],
		];

		$flat = $this->repo->normalize( $vars_raw );

		// numbers is a known type; custom_type should follow it
		$types = array_column( $flat, 'type' );
		$this->assertSame( 'numbers',     $types[0] );
		$this->assertSame( 'custom_type', $types[1] );
	}

	// ── get_raw_system_fonts() ───────────────────────────────────────────────

	#[Test]
	public function get_raw_system_fonts_returns_empty_when_et_divi_missing(): void {
		$this->assertSame( [], $this->repo->get_raw_system_fonts() );
	}

	#[Test]
	public function get_raw_system_fonts_returns_heading_and_body(): void {
		$this->set_system_fonts( 'Fraunces', 'Manrope' );

		$result = $this->repo->get_raw_system_fonts();

		$this->assertSame( 'Fraunces', $result['--et_global_heading_font'] );
		$this->assertSame( 'Manrope',  $result['--et_global_body_font'] );
	}

	#[Test]
	public function get_raw_system_fonts_omits_missing_keys(): void {
		// Only heading_font set, no body_font
		$et_divi = [ 'heading_font' => 'Fraunces' ];
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;

		$result = $this->repo->get_raw_system_fonts();

		$this->assertArrayHasKey( '--et_global_heading_font', $result );
		$this->assertArrayNotHasKey( '--et_global_body_font', $result );
	}

	// ── save_raw_system_fonts() ───────────────────────────────────────────────

	#[Test]
	public function save_raw_system_fonts_writes_heading_and_body(): void {
		$this->repo->save_raw_system_fonts( [
			'--et_global_heading_font' => 'Playfair Display',
			'--et_global_body_font'    => 'Inter',
		] );

		$stored = $this->get_system_fonts_from_stub();
		$this->assertSame( 'Playfair Display', $stored['heading_font'] );
		$this->assertSame( 'Inter',            $stored['body_font'] );
	}

	#[Test]
	public function save_raw_system_fonts_preserves_other_et_divi_keys(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = [
			'accent_color' => '#ff0000',
		];
		$this->repo->save_raw_system_fonts( [ '--et_global_heading_font' => 'Lato' ] );

		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ];
		$this->assertSame( '#ff0000', $et_divi['accent_color'] );
		$this->assertSame( 'Lato',    $et_divi['heading_font'] );
	}

	// ── normalize() with system fonts ────────────────────────────────────────

	#[Test]
	public function normalize_prepends_system_fonts_before_user_fonts(): void {
		$vars_raw = [
			'fonts' => [
				'gvid-f1' => [ 'id' => 'gvid-f1', 'label' => 'Accent', 'value' => 'Helvetica', 'status' => 'active' ],
			],
		];
		$system_fonts = [
			'--et_global_heading_font' => 'Fraunces',
			'--et_global_body_font'    => 'Manrope',
		];

		$flat = $this->repo->normalize( $vars_raw, [], $system_fonts );

		$font_entries = array_values( array_filter( $flat, fn( $e ) => $e['type'] === 'fonts' ) );
		$this->assertCount( 3, $font_entries );
		$this->assertSame( '--et_global_heading_font', $font_entries[0]['id'] );
		$this->assertSame( '--et_global_body_font',    $font_entries[1]['id'] );
		$this->assertSame( 'gvid-f1',                  $font_entries[2]['id'] );
	}

	#[Test]
	public function normalize_marks_system_fonts_as_system_true(): void {
		$system_fonts = [ '--et_global_heading_font' => 'Fraunces' ];

		$flat = $this->repo->normalize( [], [], $system_fonts );

		$this->assertTrue( $flat[0]['system'] );
	}

	#[Test]
	public function normalize_marks_user_fonts_as_system_false(): void {
		$vars_raw = [
			'fonts' => [
				'gvid-f1' => [ 'id' => 'gvid-f1', 'label' => 'Accent', 'value' => 'Helvetica', 'status' => 'active' ],
			],
		];

		$flat = $this->repo->normalize( $vars_raw );

		$this->assertFalse( $flat[0]['system'] );
	}

	#[Test]
	public function normalize_system_font_uses_fixed_label_from_constant(): void {
		$system_fonts = [
			'--et_global_heading_font' => 'Fraunces',
			'--et_global_body_font'    => 'Manrope',
		];

		$flat = $this->repo->normalize( [], [], $system_fonts );

		$this->assertSame( 'Heading', $flat[0]['label'] );
		$this->assertSame( 'Body',    $flat[1]['label'] );
	}

	// ── denormalize_system_fonts() ────────────────────────────────────────────

	#[Test]
	public function denormalize_system_fonts_extracts_system_font_values(): void {
		$flat = [
			[ 'id' => '--et_global_heading_font', 'label' => 'Heading', 'value' => 'Playfair Display', 'type' => 'fonts', 'status' => 'active', 'order' => 1, 'system' => true ],
			[ 'id' => '--et_global_body_font',    'label' => 'Body',    'value' => 'Inter',             'type' => 'fonts', 'status' => 'active', 'order' => 2, 'system' => true ],
			[ 'id' => 'gvid-f1',                  'label' => 'Accent',  'value' => 'Helvetica',         'type' => 'fonts', 'status' => 'active', 'order' => 3, 'system' => false ],
		];

		$result = $this->repo->denormalize_system_fonts( $flat );

		$this->assertSame( 'Playfair Display', $result['--et_global_heading_font'] );
		$this->assertSame( 'Inter',            $result['--et_global_body_font'] );
		$this->assertArrayNotHasKey( 'gvid-f1', $result );
	}

	#[Test]
	public function denormalize_system_fonts_returns_empty_for_no_system_entries(): void {
		$flat = [
			[ 'id' => 'gvid-f1', 'label' => 'Accent', 'value' => 'Helvetica', 'type' => 'fonts', 'status' => 'active', 'order' => 1, 'system' => false ],
		];

		$this->assertSame( [], $this->repo->denormalize_system_fonts( $flat ) );
	}

	// ── denormalize() ─────────────────────────────────────────────────────────

	#[Test]
	public function denormalize_returns_empty_for_empty_input(): void {
		$this->assertSame( [], $this->repo->denormalize( [] ) );
	}

	#[Test]
	public function denormalize_skips_system_font_entries(): void {
		$flat = [
			[ 'id' => '--et_global_heading_font', 'label' => 'Heading', 'value' => 'Fraunces', 'type' => 'fonts', 'status' => 'active', 'order' => 1, 'system' => true ],
			[ 'id' => 'gvid-f1',                  'label' => 'Accent',  'value' => 'Helvetica', 'type' => 'fonts', 'status' => 'active', 'order' => 2, 'system' => false ],
		];

		$nested = $this->repo->denormalize( $flat );

		$this->assertArrayNotHasKey( '--et_global_heading_font', $nested['fonts'] ?? [] );
		$this->assertArrayHasKey( 'gvid-f1', $nested['fonts'] );
	}

	#[Test]
	public function denormalize_skips_color_entries(): void {
		$flat = [
			[ 'id' => 'gcid-1', 'label' => 'Red',  'value' => '#f00', 'type' => 'colors',  'status' => 'active', 'order' => 1 ],
			[ 'id' => 'n-1',    'label' => 'Base', 'value' => '16px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];

		$nested = $this->repo->denormalize( $flat );

		$this->assertArrayNotHasKey( 'colors',  $nested );
		$this->assertArrayHasKey( 'numbers', $nested );
	}

	#[Test]
	public function denormalize_preserves_label_value_status(): void {
		$flat = [
			[ 'id' => 'n-1', 'label' => 'Base Size', 'value' => '16px', 'type' => 'numbers', 'status' => 'archived', 'order' => 1 ],
		];

		$nested = $this->repo->denormalize( $flat );

		$entry = $nested['numbers']['n-1'];
		$this->assertSame( 'Base Size', $entry['label'] );
		$this->assertSame( '16px',      $entry['value'] );
		$this->assertSame( 'archived',  $entry['status'] );
	}

	#[Test]
	public function denormalize_skips_entries_with_no_id(): void {
		$flat = [
			[ 'id' => '',    'label' => 'No ID', 'value' => 'x', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
			[ 'id' => 'n-2', 'label' => 'Valid', 'value' => '2', 'type' => 'numbers', 'status' => 'active', 'order' => 2 ],
		];

		$nested = $this->repo->denormalize( $flat );

		$this->assertCount( 1, $nested['numbers'] );
		$this->assertArrayHasKey( 'n-2', $nested['numbers'] );
	}

	#[Test]
	public function denormalize_defaults_type_to_numbers_when_missing(): void {
		$flat = [
			[ 'id' => 'x-1', 'label' => 'X', 'value' => '1', 'status' => 'active', 'order' => 1 ],
		];

		$nested = $this->repo->denormalize( $flat );

		$this->assertArrayHasKey( 'numbers', $nested );
		$this->assertArrayHasKey( 'x-1', $nested['numbers'] );
	}

	// ── denormalize_colors() ─────────────────────────────────────────────────

	#[Test]
	public function denormalize_colors_returns_empty_for_no_color_entries(): void {
		$flat = [
			[ 'id' => 'n-1', 'label' => 'N', 'value' => '1', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];
		$this->assertSame( [], $this->repo->denormalize_colors( $flat, [] ) );
	}

	#[Test]
	public function denormalize_colors_maps_value_to_color_field(): void {
		$flat = [
			[ 'id' => 'gcid-1', 'label' => 'Red', 'value' => '#ff0000', 'type' => 'colors', 'status' => 'active', 'order' => 1 ],
		];

		$result = $this->repo->denormalize_colors( $flat, [] );

		$this->assertSame( '#ff0000', $result['gcid-1']['color'] );
	}

	#[Test]
	public function denormalize_colors_preserves_non_editable_fields(): void {
		$existing = [
			'gcid-1' => [
				'id'          => 'gcid-1',
				'label'       => 'Old Label',
				'color'       => '#000',
				'status'      => 'active',
				'order'       => 1,
				'lastUpdated' => '2024-01-01T00:00:00.000Z',
				'folder'      => 'my-folder',
				'usedInPosts' => [ 'post-1' ],
			],
		];
		$flat = [
			[ 'id' => 'gcid-1', 'label' => 'New Label', 'value' => '#ff0000', 'type' => 'colors', 'status' => 'archived', 'order' => 1 ],
		];

		$result = $this->repo->denormalize_colors( $flat, $existing );

		$this->assertSame( 'New Label',              $result['gcid-1']['label'] );
		$this->assertSame( '#ff0000',                $result['gcid-1']['color'] );
		$this->assertSame( 'archived',               $result['gcid-1']['status'] );
		$this->assertSame( '2024-01-01T00:00:00.000Z', $result['gcid-1']['lastUpdated'] );
		$this->assertSame( 'my-folder',              $result['gcid-1']['folder'] );
		$this->assertSame( [ 'post-1' ],             $result['gcid-1']['usedInPosts'] );
	}

	#[Test]
	public function denormalize_colors_skips_non_color_entries(): void {
		$flat = [
			[ 'id' => 'gcid-1', 'label' => 'Red',  'value' => '#f00', 'type' => 'colors',  'status' => 'active', 'order' => 1 ],
			[ 'id' => 'n-1',    'label' => 'Base', 'value' => '16px', 'type' => 'numbers', 'status' => 'active', 'order' => 1 ],
		];

		$result = $this->repo->denormalize_colors( $flat, [] );

		$this->assertArrayHasKey( 'gcid-1', $result );
		$this->assertArrayNotHasKey( 'n-1', $result );
	}

	#[Test]
	public function denormalize_colors_skips_system_color_entries(): void {
		// System color IDs must NOT be written into global_colors.
		$flat = [
			[ 'id' => 'gcid-primary-color', 'label' => 'Primary Color', 'value' => '#0000ff', 'type' => 'colors', 'status' => 'active', 'order' => 1, 'system' => true ],
			[ 'id' => 'gcid-1',             'label' => 'Red',           'value' => '#ff0000', 'type' => 'colors', 'status' => 'active', 'order' => 5 ],
		];

		$result = $this->repo->denormalize_colors( $flat, [] );

		$this->assertArrayNotHasKey( 'gcid-primary-color', $result );
		$this->assertArrayHasKey( 'gcid-1', $result );
	}

	// ── get_raw_system_colors() ───────────────────────────────────────────────

	#[Test]
	public function get_raw_system_colors_returns_empty_when_et_divi_missing(): void {
		$this->assertSame( [], $this->repo->get_raw_system_colors() );
	}

	#[Test]
	public function get_raw_system_colors_returns_all_four_values(): void {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$et_divi['accent_color']           = '#ff0000';
		$et_divi['secondary_accent_color'] = '#00ff00';
		$et_divi['header_color']           = '#0000ff';
		$et_divi['font_color']             = '#111111';
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;

		$result = $this->repo->get_raw_system_colors();

		$this->assertSame( '#ff0000', $result['gcid-primary-color'] );
		$this->assertSame( '#00ff00', $result['gcid-secondary-color'] );
		$this->assertSame( '#0000ff', $result['gcid-heading-color'] );
		$this->assertSame( '#111111', $result['gcid-body-color'] );
	}

	#[Test]
	public function get_raw_system_colors_omits_unset_keys(): void {
		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] ?? [];
		$et_divi['accent_color'] = '#aabbcc';
		// secondary_accent_color, header_color, font_color intentionally not set.
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;

		$result = $this->repo->get_raw_system_colors();

		$this->assertCount( 1, $result );
		$this->assertSame( '#aabbcc', $result['gcid-primary-color'] );
	}

	// ── save_raw_system_colors() ─────────────────────────────────────────────

	#[Test]
	public function save_raw_system_colors_writes_all_four_values(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = [];

		$this->repo->save_raw_system_colors( [
			'gcid-primary-color'   => '#ff0000',
			'gcid-secondary-color' => '#00ff00',
			'gcid-heading-color'   => '#0000ff',
			'gcid-body-color'      => '#111111',
		] );

		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ];
		$this->assertSame( '#ff0000', $et_divi['accent_color'] );
		$this->assertSame( '#00ff00', $et_divi['secondary_accent_color'] );
		$this->assertSame( '#0000ff', $et_divi['header_color'] );
		$this->assertSame( '#111111', $et_divi['font_color'] );
	}

	#[Test]
	public function save_raw_system_colors_preserves_other_et_divi_keys(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = [
			'heading_font' => 'Fraunces',
			'body_font'    => 'Manrope',
		];

		$this->repo->save_raw_system_colors( [ 'gcid-primary-color' => '#abc123' ] );

		$et_divi = $GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ];
		$this->assertSame( 'Fraunces', $et_divi['heading_font'] );
		$this->assertSame( 'Manrope',  $et_divi['body_font'] );
		$this->assertSame( '#abc123',  $et_divi['accent_color'] );
	}

	// ── normalize() with system colors ───────────────────────────────────────

	#[Test]
	public function normalize_prepends_system_colors_before_user_colors(): void {
		$colors_raw = [
			'gcid-custom' => [ 'id' => 'gcid-custom', 'label' => 'Custom', 'color' => '#123456', 'status' => 'active', 'order' => 5 ],
		];
		$system_colors = [
			'gcid-primary-color' => '#0000ff',
		];

		$result = $this->repo->normalize( [], $colors_raw, [], $system_colors );
		$color_entries = array_values( array_filter( $result, fn( $e ) => $e['type'] === 'colors' ) );

		$this->assertSame( 'gcid-primary-color', $color_entries[0]['id'] );
		$this->assertSame( 'gcid-custom',        $color_entries[1]['id'] );
	}

	#[Test]
	public function normalize_marks_system_colors_as_system_true(): void {
		$system_colors = [
			'gcid-primary-color' => '#0000ff',
		];

		$result = $this->repo->normalize( [], [], [], $system_colors );

		$this->assertTrue( $result[0]['system'] );
		$this->assertSame( 'Primary Color', $result[0]['label'] );
		$this->assertSame( '#0000ff',       $result[0]['value'] );
		$this->assertSame( 'colors',        $result[0]['type'] );
	}

	#[Test]
	public function normalize_all_four_system_colors_in_order(): void {
		$system_colors = [
			'gcid-primary-color'   => '#ff0000',
			'gcid-secondary-color' => '#00ff00',
			'gcid-heading-color'   => '#0000ff',
			'gcid-body-color'      => '#111111',
		];

		$result = $this->repo->normalize( [], [], [], $system_colors );

		$this->assertCount( 4, $result );
		$this->assertSame( 'gcid-primary-color',   $result[0]['id'] );
		$this->assertSame( 'gcid-secondary-color',  $result[1]['id'] );
		$this->assertSame( 'gcid-heading-color',    $result[2]['id'] );
		$this->assertSame( 'gcid-body-color',       $result[3]['id'] );
		$this->assertSame( 1, $result[0]['order'] );
		$this->assertSame( 4, $result[3]['order'] );
	}

	// ── denormalize_system_colors() ───────────────────────────────────────────

	#[Test]
	public function denormalize_system_colors_extracts_system_color_values(): void {
		$flat = [
			[ 'id' => 'gcid-primary-color', 'label' => 'Primary Color', 'value' => '#ff0000', 'type' => 'colors', 'status' => 'active', 'order' => 1, 'system' => true ],
			[ 'id' => 'gcid-custom',        'label' => 'Custom',        'value' => '#aabbcc', 'type' => 'colors', 'status' => 'active', 'order' => 5 ],
		];

		$result = $this->repo->denormalize_system_colors( $flat );

		$this->assertCount( 1, $result );
		$this->assertSame( '#ff0000', $result['gcid-primary-color'] );
		$this->assertArrayNotHasKey( 'gcid-custom', $result );
	}

	#[Test]
	public function denormalize_system_colors_returns_empty_for_no_system_entries(): void {
		$flat = [
			[ 'id' => 'gcid-custom', 'label' => 'Custom', 'value' => '#aabbcc', 'type' => 'colors', 'status' => 'active', 'order' => 5 ],
		];

		$this->assertSame( [], $this->repo->denormalize_system_colors( $flat ) );
	}

	// ── backup() includes system_colors ──────────────────────────────────────

	#[Test]
	public function backup_stores_vars_colors_system_fonts_and_system_colors(): void {
		$GLOBALS['_d5dsh_options'][ VarsRepository::OPTION_KEY ] = [ 'numbers' => [ 'n-1' => [ 'id' => 'n-1', 'label' => 'N', 'value' => '1', 'status' => 'active' ] ] ];
		$et_divi = [
			'et_global_data' => [ 'global_colors' => [ 'gcid-1' => [ 'id' => 'gcid-1', 'label' => 'C', 'color' => '#f00', 'status' => 'active', 'order' => 1 ] ] ],
			'heading_font'   => 'Fraunces',
			'body_font'      => 'Manrope',
			'accent_color'   => '#0000ff',
		];
		$GLOBALS['_d5dsh_options'][ VarsRepository::COLORS_OPTION_KEY ] = $et_divi;

		$key    = $this->repo->backup();
		$backup = $GLOBALS['_d5dsh_options'][ $key ];

		$this->assertArrayHasKey( 'vars',          $backup );
		$this->assertArrayHasKey( 'colors',        $backup );
		$this->assertArrayHasKey( 'system_fonts',  $backup );
		$this->assertArrayHasKey( 'system_colors', $backup );
		$this->assertSame( '#0000ff', $backup['system_colors']['gcid-primary-color'] );
	}
}
