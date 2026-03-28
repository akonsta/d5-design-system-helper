<?php
/**
 * Tests for MergeManager — merge two variables, redirecting preset references.
 *
 * Strategy:
 *   - TestableMergeManager subclass overrides nothing; it exercises the
 *     full merge() logic against PresetsRepository and VarsRepository
 *     stubs backed by $GLOBALS['_d5dsh_options'].
 *   - find_affected_presets() and merge() are public via the real class;
 *     ajax handlers are tested via JsonResponseException.
 *
 * Covers (22 test cases):
 *   find_affected_presets()              (4 tests)
 *   merge()                             (8 tests)
 *   archive_variable()                  (2 tests)
 *   ajax_preview()                      (4 tests)
 *   ajax_merge()                        (4 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\MergeManager;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Option key for PresetsRepository. */
const MM_PRESETS_KEY = 'et_divi_builder_global_presets_d5';

/** Option key for VarsRepository. */
const MM_VARS_KEY = 'et_divi_global_variables';

/**
 * Build a minimal raw presets structure for MergeManager tests.
 *
 * @param array $items  Array of [ preset_id => preset_array ]
 */
function mm_preset_option( array $items = [] ): array {
	return [
		'module' => [
			'et_pb_button' => [
				'items' => $items,
			],
		],
		'group' => [],
	];
}

/**
 * Build a minimal raw vars structure for MergeManager tests.
 * Uses 'numbers' type so vars flow through denormalize() without
 * being filtered out as colors.
 */
function mm_vars_option( array $vars = [] ): array {
	// VarsRepository expects { type_key: { var_id: { id, label, value, status }, ... } }
	$indexed = [];
	foreach ( $vars as $v ) {
		$id             = $v['id'] ?? '';
		$indexed[ $id ] = $v;
	}
	return [ 'numbers' => $indexed ];
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( MergeManager::class )]
class MergeManagerTest extends TestCase {

	private MergeManager $mm;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->mm = new MergeManager();
	}

	// ── find_affected_presets() (via ajax_preview) ────────────────────────────

	#[Test]
	public function find_affected_returns_empty_when_no_presets(): void {
		// No presets option set — empty raw presets.
		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );
		$this->assertSame( 0, $result['updated_presets'] );
	}

	#[Test]
	public function find_affected_detects_retire_id_in_attrs(): void {
		$items = [
			'preset-001' => [
				'name'   => 'Button Blue',
				'attrs'  => [ 'button_bg_color' => 'var(--gcid-retire)' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );
		$this->assertSame( 1, $result['updated_presets'] );
	}

	#[Test]
	public function find_affected_ignores_presets_without_retire_id(): void {
		$items = [
			'preset-002' => [
				'name'  => 'Neutral',
				'attrs' => [ 'color' => 'var(--gcid-other)' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );
		$this->assertSame( 0, $result['updated_presets'] );
	}

	#[Test]
	public function find_affected_checks_style_attrs(): void {
		$items = [
			'preset-003' => [
				'name'       => 'Styled',
				'styleAttrs' => [ 'background' => 'gcid-retire' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );
		$this->assertSame( 1, $result['updated_presets'] );
	}

	// ── merge() ───────────────────────────────────────────────────────────────

	#[Test]
	public function merge_returns_updated_presets_count(): void {
		$items = [
			'preset-m1' => [
				'name'  => 'My Button',
				'attrs' => [ 'bg' => 'gcid-retire' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );
		$this->assertSame( 1, $result['updated_presets'] );
	}

	#[Test]
	public function merge_replaces_retire_id_with_keep_id_in_attrs(): void {
		$items = [
			'preset-m2' => [
				'name'  => 'P2',
				'attrs' => [ 'color' => 'gcid-retire' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$this->mm->merge( 'gcid-keep', 'gcid-retire' );

		$saved  = $GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ];
		$preset = $saved['module']['et_pb_button']['items']['preset-m2'];
		$this->assertSame( 'gcid-keep', $preset['attrs']['color'] );
	}

	#[Test]
	public function merge_replaces_in_style_attrs(): void {
		$items = [
			'preset-m3' => [
				'name'       => 'P3',
				'styleAttrs' => [ 'background' => 'gcid-retire' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$this->mm->merge( 'gcid-keep', 'gcid-retire' );

		$saved  = $GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ];
		$preset = $saved['module']['et_pb_button']['items']['preset-m3'];
		$this->assertSame( 'gcid-keep', $preset['styleAttrs']['background'] );
	}

	#[Test]
	public function merge_replaces_in_group_presets(): void {
		$items = [
			'preset-m4' => [
				'name'         => 'P4',
				'groupPresets' => [ 'item' => 'gcid-retire' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$this->mm->merge( 'gcid-keep', 'gcid-retire' );

		$saved  = $GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ];
		$preset = $saved['module']['et_pb_button']['items']['preset-m4'];
		$this->assertSame( 'gcid-keep', $preset['groupPresets']['item'] );
	}

	#[Test]
	public function merge_returns_keep_id_and_retire_id_in_result(): void {
		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );

		$this->assertSame( 'gcid-keep',   $result['keep_id'] );
		$this->assertSame( 'gcid-retire', $result['retire_id'] );
	}

	#[Test]
	public function merge_does_not_modify_unrelated_preset(): void {
		$items = [
			'preset-related'   => [
				'name'  => 'Related',
				'attrs' => [ 'a' => 'gcid-retire' ],
			],
			'preset-unrelated' => [
				'name'  => 'Unrelated',
				'attrs' => [ 'a' => 'gcid-unrelated' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire' );

		$this->assertSame( 1, $result['updated_presets'] );
	}

	#[Test]
	public function merge_archives_the_retired_variable(): void {
		// Seed a var in the options store.
		$GLOBALS['_d5dsh_options'][ MM_VARS_KEY ] = mm_vars_option( [
			[ 'id' => 'gcid-retire', 'label' => 'Retire Me', 'value' => '#ff0000', 'status' => 'active' ],
		] );

		$this->mm->merge( 'gcid-keep', 'gcid-retire' );

		// After merge, variable should have status 'archived' in the option store.
		$saved_vars = $GLOBALS['_d5dsh_options'][ MM_VARS_KEY ] ?? [];
		$found_archived = false;
		foreach ( $saved_vars as $type_vars ) {
			foreach ( (array) $type_vars as $v ) {
				if ( ( $v['id'] ?? '' ) === 'gcid-retire' && ( $v['status'] ?? '' ) === 'archived' ) {
					$found_archived = true;
				}
			}
		}
		$this->assertTrue( $found_archived, 'Retired variable should have status "archived" after merge.' );
	}

	// ── archive_variable() — via merge() retired_label ───────────────────────

	#[Test]
	public function merge_returns_retired_label_when_var_exists(): void {
		$GLOBALS['_d5dsh_options'][ MM_VARS_KEY ] = mm_vars_option( [
			[ 'id' => 'gcid-retire-lab', 'label' => 'My Retire Label', 'value' => '#000', 'status' => 'active' ],
		] );

		$result = $this->mm->merge( 'gcid-keep', 'gcid-retire-lab' );

		$this->assertSame( 'My Retire Label', $result['retired_label'] );
	}

	#[Test]
	public function merge_returns_empty_retired_label_when_var_missing(): void {
		$result = $this->mm->merge( 'gcid-keep', 'gcid-nonexistent' );

		$this->assertSame( '', $result['retired_label'] );
	}

	// ── ajax_preview() ────────────────────────────────────────────────────────

	#[Test]
	public function ajax_preview_returns_403_without_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_preview();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_preview_returns_400_without_retire_id(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_preview();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_preview_returns_affected_presets_and_count(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'retire_id' => 'gcid-preview' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_preview();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'affected_presets', $e->data );
			$this->assertArrayHasKey( 'count', $e->data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_preview_count_matches_affected_presets_length(): void {
		$items = [
			'preset-p1' => [
				'name'  => 'P1',
				'attrs' => [ 'bg' => 'gcid-preview-count' ],
			],
			'preset-p2' => [
				'name'  => 'P2',
				'attrs' => [ 'bg' => 'gcid-preview-count' ],
			],
		];
		$GLOBALS['_d5dsh_options'][ MM_PRESETS_KEY ] = mm_preset_option( $items );
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'retire_id' => 'gcid-preview-count' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_preview();
		} catch ( JsonResponseException $e ) {
			$this->assertSame( count( $e->data['affected_presets'] ), $e->data['count'] );
			throw $e;
		}
	}

	// ── ajax_merge() ──────────────────────────────────────────────────────────

	#[Test]
	public function ajax_merge_returns_403_without_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_merge();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_merge_returns_400_when_keep_id_missing(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'retire_id' => 'gcid-retire' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_merge();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_merge_returns_400_when_ids_are_equal(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'keep_id'   => 'gcid-same',
			'retire_id' => 'gcid-same',
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_merge();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_merge_returns_success_with_result_keys(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'keep_id'   => 'gcid-keep',
			'retire_id' => 'gcid-retire',
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->mm->ajax_merge();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'updated_presets', $e->data );
			$this->assertArrayHasKey( 'retired_label',   $e->data );
			throw $e;
		}
	}
}
