<?php
/**
 * Tests for SnapshotManager.
 *
 * Covers:
 *   - push()                  : stores data + meta; entry_count; index 0
 *   - push() overflow         : purges oldest when > MAX_SNAPSHOTS
 *   - list_snapshots()        : returns meta ordered newest-first
 *   - restore()               : writes back to the correct option key
 *   - restore() missing       : returns false for non-existent index
 *   - delete_snapshot()       : removes data + strips from meta
 *   - purge()                 : removes all data + meta
 *   - types_with_snapshots()  : reads from wpdb stub
 *   - entry counting          : per-type heuristics (vars, presets, layouts, etc.)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\SnapshotManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass( SnapshotManager::class )]
final class SnapshotManagerTest extends TestCase {

	protected function setUp(): void {
		_d5dsh_reset_stubs();
	}

	// ── push() ────────────────────────────────────────────────────────────────

	#[Test]
	public function push_stores_data_at_index_0(): void {
		$data = [ 'colors' => [ 'c-1' => [ 'id' => 'c-1', 'label' => 'Red', 'value' => '#f00', 'status' => 'active' ] ] ];
		SnapshotManager::push( 'vars', $data, 'export', 'First snapshot' );

		$stored = get_option( 'd5dsh_snap_vars_0' );
		$this->assertSame( $data, $stored );
	}

	#[Test]
	public function push_writes_meta_with_correct_fields(): void {
		SnapshotManager::push( 'vars', [], 'import', 'Test import' );

		$meta = SnapshotManager::list_snapshots( 'vars' );

		$this->assertCount( 1, $meta );
		$this->assertSame( 0,        $meta[0]['index'] );
		$this->assertSame( 'import', $meta[0]['trigger'] );
		$this->assertSame( 'Test import', $meta[0]['description'] );
		$this->assertArrayHasKey( 'timestamp',   $meta[0] );
		$this->assertArrayHasKey( 'entry_count', $meta[0] );
	}

	#[Test]
	public function push_shifts_existing_snapshots_to_higher_indices(): void {
		SnapshotManager::push( 'vars', [ 'first' ], 'export' );
		SnapshotManager::push( 'vars', [ 'second' ], 'export' );

		// Most recent (second) is at index 0; first is at index 1.
		$this->assertSame( [ 'second' ], get_option( 'd5dsh_snap_vars_0' ) );
		$this->assertSame( [ 'first' ],  get_option( 'd5dsh_snap_vars_1' ) );
	}

	#[Test]
	public function push_stores_timestamp_as_iso8601_utc(): void {
		SnapshotManager::push( 'vars', [], 'export' );

		$meta = SnapshotManager::list_snapshots( 'vars' );
		// ISO-8601 UTC pattern: 2026-03-12T14:30:00Z
		$this->assertMatchesRegularExpression(
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
			$meta[0]['timestamp']
		);
	}

	#[Test]
	public function push_purges_oldest_snapshot_when_over_limit(): void {
		// Fill up to MAX_SNAPSHOTS.
		for ( $i = 0; $i < SnapshotManager::MAX_SNAPSHOTS; $i++ ) {
			SnapshotManager::push( 'vars', [ 'data' => $i ], 'export', "Snapshot $i" );
		}

		// At limit — all 10 should exist.
		$meta = SnapshotManager::list_snapshots( 'vars' );
		$this->assertCount( SnapshotManager::MAX_SNAPSHOTS, $meta );

		// Push one more — should evict the oldest (index 9 before push).
		SnapshotManager::push( 'vars', [ 'data' => 'overflow' ], 'export', 'Overflow' );

		$meta = SnapshotManager::list_snapshots( 'vars' );
		$this->assertCount( SnapshotManager::MAX_SNAPSHOTS, $meta );

		// The newest should be 'Overflow'.
		$this->assertSame( 'Overflow', $meta[0]['description'] );
	}

	// ── list_snapshots() ─────────────────────────────────────────────────────

	#[Test]
	public function list_snapshots_returns_empty_array_when_no_snapshots(): void {
		$this->assertSame( [], SnapshotManager::list_snapshots( 'vars' ) );
	}

	#[Test]
	public function list_snapshots_returns_newest_first(): void {
		SnapshotManager::push( 'vars', [], 'export', 'First' );
		SnapshotManager::push( 'vars', [], 'import', 'Second' );

		$meta = SnapshotManager::list_snapshots( 'vars' );

		$this->assertSame( 'Second', $meta[0]['description'] );
		$this->assertSame( 'First',  $meta[1]['description'] );
	}

	// ── restore() ────────────────────────────────────────────────────────────

	#[Test]
	public function restore_writes_snapshot_data_back_to_vars_option(): void {
		$original = [
			'colors' => [ 'c-1' => [ 'id' => 'c-1', 'label' => 'Blue', 'value' => '#00f', 'status' => 'active' ] ],
		];
		SnapshotManager::push( 'vars', $original, 'export' );

		// Overwrite the live option to simulate a change.
		update_option( 'et_divi_global_variables', [ 'colors' => [] ] );

		$result = SnapshotManager::restore( 'vars', 0 );

		$this->assertTrue( $result );
		$this->assertSame( $original, get_option( 'et_divi_global_variables' ) );
	}

	#[Test]
	public function restore_writes_snapshot_data_back_to_presets_option(): void {
		$original = [ 'module' => [], 'group' => [], 'global_colors' => [] ];
		SnapshotManager::push( 'presets', $original, 'export' );

		update_option( 'et_divi_builder_global_presets_d5', [ 'module' => [ 'changed' => [] ], 'group' => [] ] );

		$result = SnapshotManager::restore( 'presets', 0 );

		$this->assertTrue( $result );
		$this->assertSame( $original, get_option( 'et_divi_builder_global_presets_d5' ) );
	}

	#[Test]
	public function restore_returns_false_for_nonexistent_index(): void {
		$result = SnapshotManager::restore( 'vars', 99 );
		$this->assertFalse( $result );
	}

	#[Test]
	public function restore_returns_false_when_snapshot_data_is_not_array(): void {
		// Manually plant a corrupt option.
		update_option( 'd5dsh_snap_vars_0', 'not an array' );
		update_option( 'd5dsh_snap_vars_meta', wp_json_encode( [ [ 'index' => 0, 'timestamp' => '', 'trigger' => 'export', 'entry_count' => 0, 'description' => '' ] ] ) );

		$result = SnapshotManager::restore( 'vars', 0 );
		$this->assertFalse( $result );
	}

	// ── delete_snapshot() ────────────────────────────────────────────────────

	#[Test]
	public function delete_snapshot_removes_data_option(): void {
		SnapshotManager::push( 'vars', [ 'to_delete' ], 'export' );

		SnapshotManager::delete_snapshot( 'vars', 0 );

		$this->assertFalse( get_option( 'd5dsh_snap_vars_0', false ) );
	}

	#[Test]
	public function delete_snapshot_removes_entry_from_meta(): void {
		SnapshotManager::push( 'vars', [], 'export', 'Keep' );
		SnapshotManager::push( 'vars', [], 'export', 'Delete me' );

		// Index 0 is newest ('Delete me').
		SnapshotManager::delete_snapshot( 'vars', 0 );

		$meta = SnapshotManager::list_snapshots( 'vars' );
		$this->assertCount( 1, $meta );
		$this->assertSame( 'Keep', $meta[0]['description'] );
	}

	#[Test]
	public function delete_snapshot_returns_true(): void {
		SnapshotManager::push( 'vars', [], 'export' );
		$result = SnapshotManager::delete_snapshot( 'vars', 0 );
		$this->assertTrue( $result );
	}

	// ── purge() ───────────────────────────────────────────────────────────────

	#[Test]
	public function purge_removes_all_snapshots_and_meta(): void {
		SnapshotManager::push( 'vars', [ 1 ], 'export' );
		SnapshotManager::push( 'vars', [ 2 ], 'export' );

		SnapshotManager::purge( 'vars' );

		$this->assertSame( [], SnapshotManager::list_snapshots( 'vars' ) );
		$this->assertFalse( get_option( 'd5dsh_snap_vars_0', false ) );
		$this->assertFalse( get_option( 'd5dsh_snap_vars_1', false ) );
	}

	#[Test]
	public function purge_on_empty_type_does_not_throw(): void {
		SnapshotManager::purge( 'vars' ); // No snapshots — should be silent.
		$this->assertTrue( true );
	}

	// ── types_with_snapshots() ───────────────────────────────────────────────

	#[Test]
	public function types_with_snapshots_parses_wpdb_results(): void {
		$GLOBALS['wpdb']->_stub_results = [
			'd5dsh_snap_vars_meta',
			'd5dsh_snap_presets_meta',
		];

		$types = SnapshotManager::types_with_snapshots();

		$this->assertContains( 'vars',    $types );
		$this->assertContains( 'presets', $types );
	}

	#[Test]
	public function types_with_snapshots_returns_empty_array_when_no_meta_keys(): void {
		$GLOBALS['wpdb']->_stub_results = [];
		$this->assertSame( [], SnapshotManager::types_with_snapshots() );
	}

	// ── Entry counting heuristics ─────────────────────────────────────────────

	#[Test]
	public function push_counts_vars_entries_across_all_types(): void {
		$data = [
			'colors'  => [ 'c-1' => [], 'c-2' => [] ],
			'numbers' => [ 'n-1' => [] ],
		];
		SnapshotManager::push( 'vars', $data, 'export' );

		$meta = SnapshotManager::list_snapshots( 'vars' );
		$this->assertSame( 3, $meta[0]['entry_count'] );
	}

	#[Test]
	public function push_counts_presets_items_across_modules(): void {
		$data = [
			'module' => [
				'divi/button' => [
					'default' => 'p1',
					'items'   => [ 'p1' => [], 'p2' => [] ],
				],
				'divi/text' => [
					'default' => 'p3',
					'items'   => [ 'p3' => [] ],
				],
			],
			'group' => [
				'group1' => [
					'default' => 'g1',
					'items'   => [ 'g1' => [], 'g2' => [] ],
				],
			],
		];
		SnapshotManager::push( 'presets', $data, 'export' );

		$meta = SnapshotManager::list_snapshots( 'presets' );
		// 2 (button) + 1 (text) + 2 (group1) = 5
		$this->assertSame( 5, $meta[0]['entry_count'] );
	}

	#[Test]
	public function push_counts_layouts_as_top_level_array_length(): void {
		$data = [ [ 'id' => 1 ], [ 'id' => 2 ], [ 'id' => 3 ] ];
		SnapshotManager::push( 'layouts', $data, 'export' );

		$meta = SnapshotManager::list_snapshots( 'layouts' );
		$this->assertSame( 3, $meta[0]['entry_count'] );
	}

	#[Test]
	public function push_counts_builder_templates_via_templates_key(): void {
		$data = [ 'templates' => [ [], [], [] ], 'layouts' => [ [], [] ] ];
		SnapshotManager::push( 'builder_templates', $data, 'export' );

		$meta = SnapshotManager::list_snapshots( 'builder_templates' );
		$this->assertSame( 3, $meta[0]['entry_count'] );
	}
}
