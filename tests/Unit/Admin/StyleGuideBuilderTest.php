<?php
/**
 * Tests for StyleGuideBuilder — serves design system data for the Style Guide generator.
 *
 * Strategy:
 *   - $GLOBALS['_d5dsh_options'] / _d5dsh_reset_stubs() provide the in-memory
 *     WP option store used by VarsRepository and PresetsRepository.
 *   - JsonResponseException captures wp_send_json_* calls.
 *
 * Covers (12 test cases):
 *   ajax_data() structure                (5 tests)
 *   ajax_data() with seeded data         (4 tests)
 *   ajax_data() auth/permission          (2 tests)
 *   register()                           (1 test)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\StyleGuideBuilder;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Build a minimal raw presets structure for StyleGuideBuilder tests.
 */
function sg_preset_option( array $module_items = [], array $group_items = [] ): array {
	return [
		'module' => [
			'et_pb_button' => [ 'items' => $module_items ],
		],
		'group' => [
			'my_group' => [ 'items' => $group_items ],
		],
	];
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( StyleGuideBuilder::class )]
class StyleGuideBuilderTest extends TestCase {

	private StyleGuideBuilder $sgb;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->sgb = new StyleGuideBuilder();
	}

	// ── register() ───────────────────────────────────────────────────────────

	#[Test]
	public function register_does_not_throw(): void {
		// register() just calls add_action() which is a stub no-op.
		$this->sgb->register();
		$this->addToAssertionCount( 1 );
	}

	// ── ajax_data() — auth / permission ───────────────────────────────────────

	#[Test]
	public function ajax_data_returns_403_when_no_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	// ── ajax_data() — response structure ─────────────────────────────────────

	#[Test]
	public function ajax_data_returns_success(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_includes_vars_key(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayHasKey( 'vars', $e->data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_includes_presets_key(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayHasKey( 'presets', $e->data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_includes_categories_key(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayHasKey( 'categories', $e->data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_includes_category_map_key(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayHasKey( 'category_map', $e->data );
			throw $e;
		}
	}

	// ── ajax_data() — with seeded data ────────────────────────────────────────

	#[Test]
	public function ajax_data_returns_all_vars(): void {
		$GLOBALS['_d5dsh_options']['et_divi_global_variables'] = [
			'color' => [
				[ 'id' => 'gcid-primary', 'label' => 'Primary', 'value' => '#0000ff', 'status' => 'active' ],
				[ 'id' => 'gcid-accent',  'label' => 'Accent',  'value' => '#ff00ff', 'status' => 'active' ],
			],
		];

		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$this->assertCount( 2, $e->data['vars'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_flattens_module_presets(): void {
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = sg_preset_option(
			[
				'preset-001' => [ 'name' => 'Blue Button', 'moduleName' => 'et_pb_button' ],
				'preset-002' => [ 'name' => 'Red Button',  'moduleName' => 'et_pb_button' ],
			]
		);

		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$presets = $e->data['presets'];
			$ids = array_column( $presets, 'id' );
			$this->assertContains( 'preset-001', $ids );
			$this->assertContains( 'preset-002', $ids );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_marks_group_presets_as_group_type(): void {
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = sg_preset_option(
			[], // no module presets
			[
				'gp-001' => [ 'name' => 'My Group Preset' ],
			]
		);

		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$presets = $e->data['presets'];
			$found = array_filter( $presets, fn( $p ) => $p['id'] === 'gp-001' );
			$this->assertNotEmpty( $found );
			$preset = array_values( $found )[0];
			$this->assertSame( 'group', $preset['type'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_data_marks_module_presets_as_element_type(): void {
		$GLOBALS['_d5dsh_options']['et_divi_builder_global_presets_d5'] = sg_preset_option(
			[
				'mp-001' => [ 'name' => 'My Module Preset' ],
			]
		);

		$this->expectException( JsonResponseException::class );

		try {
			$this->sgb->ajax_data();
		} catch ( JsonResponseException $e ) {
			$presets = $e->data['presets'];
			$found = array_filter( $presets, fn( $p ) => $p['id'] === 'mp-001' );
			$this->assertNotEmpty( $found );
			$preset = array_values( $found )[0];
			$this->assertSame( 'element', $preset['type'] );
			throw $e;
		}
	}
}
