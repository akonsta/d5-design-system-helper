<?php
/**
 * Tests for DtcgExporter — W3C DTCG-format variable export.
 *
 * Strategy:
 *   - Seed $GLOBALS['_d5dsh_options'] with VarsRepository-compatible data.
 *   - Call DtcgExporter::build_export_data() directly (stream_download() is not tested
 *     as it calls exit()).
 *   - Expose private methods via a TestableDtcgExporter subclass with ReflectionMethod
 *     wrappers for thorough unit coverage.
 *
 * Covers (20 test cases):
 *   build_export_data() structure        (4 tests)
 *   map_type() / type dispatch           (7 tests)
 *   resolve_color_value()                (3 tests)
 *   is_dimension()                       (3 tests)
 *   Token entry shape                    (3 tests — combined with type tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Exporters;

use D5DesignSystemHelper\Exporters\DtcgExporter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Exposes private methods of DtcgExporter for white-box testing.
 */
class TestableDtcgExporter extends DtcgExporter {

	public function pub_map_type( array $var ): ?string {
		$m = new \ReflectionMethod( DtcgExporter::class, 'map_type' );
		$m->setAccessible( true );
		return $m->invoke( $this, $var );
	}

	public function pub_resolve_color_value( string $value, array $color_lookup ): string {
		$m = new \ReflectionMethod( DtcgExporter::class, 'resolve_color_value' );
		$m->setAccessible( true );
		return $m->invoke( $this, $value, $color_lookup );
	}

	public function pub_is_dimension( string $value ): bool {
		$m = new \ReflectionMethod( DtcgExporter::class, 'is_dimension' );
		$m->setAccessible( true );
		return $m->invoke( $this, $value );
	}
}

// ── Fixtures ──────────────────────────────────────────────────────────────────

/**
 * Seed the WP options store to simulate a live Divi 5 site.
 *
 * Colors go into et_divi[et_global_data][global_colors].
 * Non-color vars go into et_divi_global_variables keyed by type → id.
 *
 * @param array<string, array>  $colors   Map of gcid-* => color entry overrides.
 * @param array<string, array>  $numbers  Map of gvid-* => number var entry overrides.
 * @param array<string, array>  $fonts    Map of gvid-* => font var entry overrides.
 * @param array<string, array>  $strings  Map of gvid-* => string var entry overrides.
 * @param array<string, array>  $images   Map of gvid-* => image var entry overrides.
 * @param array<string, array>  $links    Map of gvid-* => link var entry overrides.
 */
function dtcg_seed(
	array $colors  = [],
	array $numbers = [],
	array $fonts   = [],
	array $strings = [],
	array $images  = [],
	array $links   = []
): void {
	// Colors → et_divi[et_global_data][global_colors].
	$global_colors = [];
	foreach ( $colors as $id => $overrides ) {
		$global_colors[ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'Color ' . $id, 'color' => '#aabbcc', 'status' => 'active', 'order' => 1 ],
			$overrides
		);
	}
	$GLOBALS['_d5dsh_options']['et_divi'] = [
		'et_global_data' => [ 'global_colors' => $global_colors ],
	];

	// Non-color vars → et_divi_global_variables.
	$raw_vars = [];

	foreach ( $numbers as $id => $overrides ) {
		$raw_vars['numbers'][ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'Number ' . $id, 'value' => '16px', 'status' => 'active' ],
			$overrides
		);
	}
	foreach ( $fonts as $id => $overrides ) {
		$raw_vars['fonts'][ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'Font ' . $id, 'value' => 'Fraunces', 'status' => 'active' ],
			$overrides
		);
	}
	foreach ( $strings as $id => $overrides ) {
		$raw_vars['strings'][ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'String ' . $id, 'value' => 'Hello', 'status' => 'active' ],
			$overrides
		);
	}
	foreach ( $images as $id => $overrides ) {
		$raw_vars['images'][ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'Image ' . $id, 'value' => 'https://example.com/img.jpg', 'status' => 'active' ],
			$overrides
		);
	}
	foreach ( $links as $id => $overrides ) {
		$raw_vars['links'][ $id ] = array_merge(
			[ 'id' => $id, 'label' => 'Link ' . $id, 'value' => 'https://example.com/', 'status' => 'active' ],
			$overrides
		);
	}

	$GLOBALS['_d5dsh_options']['et_divi_global_variables']           = $raw_vars ?: [];
	$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = [ 'module' => [], 'group' => [] ];
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( DtcgExporter::class )]
class DtcgExporterTest extends TestCase {

	private TestableDtcgExporter $exporter;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->exporter = new TestableDtcgExporter();
	}

	// =========================================================================
	// build_export_data() structure
	// =========================================================================

	#[Test]
	public function output_has_dollar_schema_key(): void {
		dtcg_seed();
		$data = $this->exporter->build_export_data();

		$this->assertArrayHasKey( '$schema', $data );
		$this->assertStringContainsString( 'designtokens', $data['$schema'] );
	}

	#[Test]
	public function output_has_meta_block_with_required_fields(): void {
		dtcg_seed();
		$data = $this->exporter->build_export_data();

		$this->assertArrayHasKey( '_meta', $data );
		$this->assertArrayHasKey( 'exported_by', $data['_meta'] );
		$this->assertArrayHasKey( 'dtcg_schema',  $data['_meta'] );
		$this->assertSame( '2025.10', $data['_meta']['dtcg_schema'] );
	}

	#[Test]
	public function colors_appear_in_color_group(): void {
		dtcg_seed( [ 'gcid-c1' => [ 'color' => '#112233' ] ] );
		$data = $this->exporter->build_export_data();

		$this->assertArrayHasKey( 'color', $data );
		$this->assertArrayHasKey( 'gcid-c1', $data['color'] );
	}

	#[Test]
	public function dimension_vars_appear_in_dimension_group(): void {
		dtcg_seed( [], [ 'gvid-n1' => [ 'value' => '24px' ] ] );
		$data = $this->exporter->build_export_data();

		$this->assertArrayHasKey( 'dimension', $data );
		$this->assertArrayHasKey( 'gvid-n1', $data['dimension'] );
	}

	// =========================================================================
	// map_type() — type dispatch for all Divi variable types
	// =========================================================================

	#[Test]
	public function map_type_returns_color_for_colors_type(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'colors', 'value' => '#fff' ] );
		$this->assertSame( 'color', $result );
	}

	#[Test]
	public function map_type_returns_dimension_for_numbers_with_css_unit(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'numbers', 'value' => '16px' ] );
		$this->assertSame( 'dimension', $result );
	}

	#[Test]
	public function map_type_returns_number_for_numbers_without_css_unit(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'numbers', 'value' => '1.618' ] );
		$this->assertSame( 'number', $result );
	}

	#[Test]
	public function map_type_returns_font_family_for_fonts_type(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'fonts', 'value' => 'Fraunces' ] );
		$this->assertSame( 'fontFamily', $result );
	}

	#[Test]
	public function map_type_returns_string_for_strings_type(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'strings', 'value' => 'Hello' ] );
		$this->assertSame( 'string', $result );
	}

	#[Test]
	public function map_type_returns_null_for_images_type(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'images', 'value' => 'https://example.com/img.jpg' ] );
		$this->assertNull( $result );
	}

	#[Test]
	public function map_type_returns_null_for_links_type(): void {
		$result = $this->exporter->pub_map_type( [ 'type' => 'links', 'value' => 'https://example.com/' ] );
		$this->assertNull( $result );
	}

	// =========================================================================
	// resolve_color_value()
	// =========================================================================

	#[Test]
	public function resolve_color_value_returns_plain_hex_unchanged(): void {
		$result = $this->exporter->pub_resolve_color_value( '#ff0000', [] );
		$this->assertSame( '#ff0000', $result );
	}

	#[Test]
	public function resolve_color_value_follows_one_level_of_variable_reference(): void {
		$target_id = 'gcid-target';
		$token     = '$variable({"type":"color","value":{"name":"' . $target_id . '"}}' . ')$';
		$lookup    = [ $target_id => '#123456' ];

		$result = $this->exporter->pub_resolve_color_value( $token, $lookup );

		$this->assertSame( '#123456', $result );
	}

	#[Test]
	public function resolve_color_value_returns_raw_when_reference_not_resolvable(): void {
		$token  = '$variable({"type":"color","value":{"name":"gcid-nonexistent"}}' . ')$';
		$lookup = [];

		$result = $this->exporter->pub_resolve_color_value( $token, $lookup );

		$this->assertSame( $token, $result );
	}

	// =========================================================================
	// is_dimension()
	// =========================================================================

	#[Test]
	public function is_dimension_returns_true_for_px_value(): void {
		$this->assertTrue( $this->exporter->pub_is_dimension( '16px' ) );
	}

	#[Test]
	public function is_dimension_returns_true_for_rem_value(): void {
		$this->assertTrue( $this->exporter->pub_is_dimension( '1.5rem' ) );
	}

	#[Test]
	public function is_dimension_returns_false_for_bare_number(): void {
		$this->assertFalse( $this->exporter->pub_is_dimension( '42' ) );
	}

	// =========================================================================
	// Token entry shape (verified via build_export_data())
	// =========================================================================

	#[Test]
	public function token_has_correct_dollar_type_key(): void {
		dtcg_seed( [ 'gcid-shape-test' => [ 'color' => '#aabbcc' ] ] );
		$data  = $this->exporter->build_export_data();
		$token = $data['color']['gcid-shape-test'] ?? null;

		$this->assertNotNull( $token );
		$this->assertArrayHasKey( '$type', $token );
		$this->assertSame( 'color', $token['$type'] );
	}

	#[Test]
	public function token_has_dollar_value_key(): void {
		dtcg_seed( [ 'gcid-val-test' => [ 'color' => '#001122' ] ] );
		$data  = $this->exporter->build_export_data();
		$token = $data['color']['gcid-val-test'] ?? null;

		$this->assertNotNull( $token );
		$this->assertArrayHasKey( '$value', $token );
		$this->assertSame( '#001122', $token['$value'] );
	}

	#[Test]
	public function token_has_extensions_with_d5dsh_metadata(): void {
		dtcg_seed( [ 'gcid-ext-test' => [ 'label' => 'My Color', 'color' => '#ffffff' ] ] );
		$data  = $this->exporter->build_export_data();
		$token = $data['color']['gcid-ext-test'] ?? null;

		$this->assertNotNull( $token );
		$this->assertArrayHasKey( 'extensions', $token );
		$this->assertArrayHasKey( 'd5dsh:id',     $token['extensions'] );
		$this->assertArrayHasKey( 'd5dsh:status', $token['extensions'] );
		$this->assertArrayHasKey( 'd5dsh:system', $token['extensions'] );
		$this->assertSame( 'gcid-ext-test', $token['extensions']['d5dsh:id'] );
	}

	// =========================================================================
	// Omission of images and links
	// =========================================================================

	#[Test]
	public function image_variables_are_omitted_from_output(): void {
		dtcg_seed( [], [], [], [], [ 'gvid-img1' => [ 'value' => 'https://example.com/photo.jpg' ] ] );
		$data = $this->exporter->build_export_data();

		$this->assertArrayNotHasKey( 'images', $data );
		// Also confirm the specific ID doesn't appear in any group.
		foreach ( $data as $key => $group ) {
			if ( ! is_array( $group ) || str_starts_with( $key, '$' ) || $key === '_meta' ) continue;
			$this->assertArrayNotHasKey( 'gvid-img1', $group,
				"Image variable must not appear in DTCG group: $key" );
		}
	}

	#[Test]
	public function link_variables_are_omitted_from_output(): void {
		dtcg_seed( [], [], [], [], [], [ 'gvid-lnk1' => [ 'value' => 'https://example.com/' ] ] );
		$data = $this->exporter->build_export_data();

		$this->assertArrayNotHasKey( 'links', $data );
		foreach ( $data as $key => $group ) {
			if ( ! is_array( $group ) || str_starts_with( $key, '$' ) || $key === '_meta' ) continue;
			$this->assertArrayNotHasKey( 'gvid-lnk1', $group,
				"Link variable must not appear in DTCG group: $key" );
		}
	}
}
