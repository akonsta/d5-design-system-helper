<?php
/**
 * Tests for CategoryManager — user-defined variable categories.
 *
 * Strategy:
 *   - $GLOBALS['_d5dsh_options'] / _d5dsh_reset_stubs() provide the
 *     in-memory WP option store.
 *   - $GLOBALS['_d5dsh_php_input'] provides the JSON request body.
 *   - JsonResponseException captures wp_send_json_* calls.
 *
 * Covers (22 test cases):
 *   get_categories() / get_map()          (3 tests)
 *   ajax_load()                           (3 tests)
 *   ajax_save()                           (9 tests)
 *   ajax_assign()                         (7 tests)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\CategoryManager;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass( CategoryManager::class )]
class CategoryManagerTest extends TestCase {

	private CategoryManager $cm;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->cm = new CategoryManager();
	}

	// ── get_categories() / get_map() ──────────────────────────────────────────

	#[Test]
	public function get_categories_returns_empty_array_when_option_absent(): void {
		$this->assertSame( [], $this->cm->get_categories() );
	}

	#[Test]
	public function get_map_returns_empty_array_when_option_absent(): void {
		$this->assertSame( [], $this->cm->get_map() );
	}

	#[Test]
	public function get_categories_returns_stored_value(): void {
		$cats = [ [ 'id' => 'cat-1', 'label' => 'Brand', 'color' => '#ff0000' ] ];
		$GLOBALS['_d5dsh_options'][ CategoryManager::OPTION_CATEGORIES ] = $cats;

		$this->assertSame( $cats, $this->cm->get_categories() );
	}

	// ── ajax_load() ───────────────────────────────────────────────────────────

	#[Test]
	public function ajax_load_returns_403_when_no_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_load();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_load_returns_categories_and_map(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_load();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertArrayHasKey( 'categories',   $e->data );
			$this->assertArrayHasKey( 'category_map', $e->data );
			throw $e;
		}
	}

	#[Test]
	public function ajax_load_returns_empty_arrays_initially(): void {
		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_load();
		} catch ( JsonResponseException $e ) {
			$this->assertSame( [], $e->data['categories'] );
			$this->assertSame( [], $e->data['category_map'] );
			throw $e;
		}
	}

	// ── ajax_save() ───────────────────────────────────────────────────────────

	#[Test]
	public function ajax_save_returns_403_when_no_capability(): void {
		$GLOBALS['_d5dsh_user_can']  = false;
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'categories' => [] ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_returns_400_on_missing_categories_key(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'something' => 'else' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_stores_and_returns_categories(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'label' => 'Typography', 'color' => '#333333' ],
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertTrue( $e->success );
			$this->assertCount( 1, $e->data['categories'] );
			$this->assertSame( 'Typography', $e->data['categories'][0]['label'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_generates_uuid_for_new_category(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'label' => 'Colors' ], // no id
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$id = $e->data['categories'][0]['id'];
			$this->assertStringStartsWith( 'cat-', $id );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_preserves_existing_id(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'id' => 'cat-existing-123', 'label' => 'Layout', 'color' => '#aabbcc' ],
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertSame( 'cat-existing-123', $e->data['categories'][0]['id'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_skips_entry_with_empty_label(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'label' => '' ],           // should be skipped
				[ 'label' => 'Visible' ],
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertCount( 1, $e->data['categories'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_removes_orphaned_map_entries(): void {
		// Seed the map with assignments — legacy bare keys get migrated to 'var:' prefix.
		$GLOBALS['_d5dsh_options'][ CategoryManager::OPTION_MAP ] = [
			'gcid-x' => 'cat-old',
			'gcid-y' => 'cat-keep',
		];
		// Save only the kept category.
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'id' => 'cat-keep', 'label' => 'Keep', 'color' => '#000000' ],
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$map = $e->data['category_map'];
			// Legacy keys migrated to 'var:' prefix; orphaned entry removed.
			$this->assertArrayNotHasKey( 'var:gcid-x', $map );
			$this->assertArrayHasKey( 'var:gcid-y', $map );
			$this->assertSame( [ 'cat-keep' ], $map['var:gcid-y'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_uses_default_color_when_empty(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'label' => 'No Color' ],
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException $e ) {
			$this->assertSame( '#6b7280', $e->data['categories'][0]['color'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_save_persists_to_option_store(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'categories' => [
				[ 'label' => 'Persistent', 'color' => '#123456' ],
			],
		] );

		try {
			$this->cm->ajax_save();
		} catch ( JsonResponseException ) {
			// swallow
		}

		$stored = get_option( CategoryManager::OPTION_CATEGORIES, [] );
		$this->assertCount( 1, $stored );
		$this->assertSame( 'Persistent', $stored[0]['label'] );
	}

	// ── ajax_assign() ─────────────────────────────────────────────────────────

	#[Test]
	public function ajax_assign_returns_403_when_no_capability(): void {
		$GLOBALS['_d5dsh_user_can']  = false;
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'assignments' => [] ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 403, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_returns_400_on_missing_assignments_key(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'other' => 'value' ] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$this->assertFalse( $e->success );
			$this->assertSame( 400, $e->status_code );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_stores_assignments(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'assignments' => [
				'gcid-primary' => 'cat-001',
				'gcid-accent'  => 'cat-002',
			],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$map = $e->data['category_map'];
			$this->assertSame( [ 'cat-001' ], $map['gcid-primary'] );
			$this->assertSame( [ 'cat-002' ], $map['gcid-accent'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_removes_assignment_when_null(): void {
		$GLOBALS['_d5dsh_options'][ CategoryManager::OPTION_MAP ] = [
			'gcid-remove-me' => 'cat-old',
		];
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'assignments' => [ 'gcid-remove-me' => null ],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayNotHasKey( 'gcid-remove-me', $e->data['category_map'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_removes_assignment_when_empty_string(): void {
		$GLOBALS['_d5dsh_options'][ CategoryManager::OPTION_MAP ] = [
			'gcid-clear' => 'cat-abc',
		];
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'assignments' => [ 'gcid-clear' => '' ],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$this->assertArrayNotHasKey( 'gcid-clear', $e->data['category_map'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_skips_entry_with_empty_var_id(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'assignments' => [ '' => 'cat-001' ],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$this->assertSame( [], $e->data['category_map'] );
			throw $e;
		}
	}

	#[Test]
	public function ajax_assign_merges_with_existing_map(): void {
		// Legacy format (bare key) — get_map() migrates to 'var:gcid-existing'.
		$GLOBALS['_d5dsh_options'][ CategoryManager::OPTION_MAP ] = [
			'gcid-existing' => 'cat-xyz',
		];
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'assignments' => [ 'gcid-new' => 'cat-abc' ],
		] );

		$this->expectException( JsonResponseException::class );

		try {
			$this->cm->ajax_assign();
		} catch ( JsonResponseException $e ) {
			$map = $e->data['category_map'];
			// Legacy key migrated to prefixed format.
			$this->assertArrayHasKey( 'var:gcid-existing', $map );
			$this->assertSame( [ 'cat-xyz' ], $map['var:gcid-existing'] );
			$this->assertArrayHasKey( 'gcid-new', $map );
			$this->assertSame( [ 'cat-abc' ], $map['gcid-new'] );
			throw $e;
		}
	}
}
