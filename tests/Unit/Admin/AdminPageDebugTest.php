<?php
/**
 * Tests for AdminPage debug/error-trapping AJAX endpoints.
 *
 * Covers the three handlers added in the error trapping system:
 *
 *   ajax_log_js_error()      — receives a JS error payload, writes to debug log
 *   ajax_debug_log_read()    — returns the last N lines of the log
 *   ajax_debug_log_clear()   — zeroes the log file
 *
 * Strategy:
 *   - Subclass AdminPage (TestableAdminPage) to expose the three public handlers.
 *   - Seed $_POST / $_GET as needed for each test.
 *   - Catch JsonResponseException thrown by wp_send_json_* stubs to inspect the
 *     response payload.
 *   - Read the physical log file (in sys_get_temp_dir()) to verify log writes.
 *
 * D5DSH_DEBUG is defined as true in tests/bootstrap.php so DebugLogger::is_active()
 * returns true throughout the suite.  Tests for the "debug off" branch are handled
 * by DebugLoggerTest which uses TestableDebugLogger.
 *
 * Covers:
 *   ajax_log_js_error: debug on → writes to log, returns ok:true
 *   ajax_log_js_error: permission denied → 403 error response
 *   ajax_log_js_error: empty body → defaults used, still returns ok:true
 *   ajax_log_js_error: full payload → message, source, line, stack in log
 *   ajax_log_js_error: type field recorded correctly
 *   ajax_debug_log_read: empty log → returns ok:true with empty lines
 *   ajax_debug_log_read: populated log → returns last lines and size_kb
 *   ajax_debug_log_read: permission denied → 403 error response
 *   ajax_debug_log_read: path field has correct format
 *   ajax_debug_log_clear: zeros the file, returns ok:true
 *   ajax_debug_log_clear: permission denied → 403 error response
 *   ajax_debug_log_clear: non-existent log → still returns ok:true (no crash)
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\AdminPage;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use D5DesignSystemHelper\Util\DebugLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Thin wrapper that makes the three AJAX methods callable in test context.
 * AdminPage's constructor does nothing (all wiring is in register()), so
 * instantiation is safe.
 */
class TestableAdminPage extends AdminPage {}

// ── Helper functions ──────────────────────────────────────────────────────────

function ap_test_log_path(): string {
	return sys_get_temp_dir() . '/d5dsh_test_uploads/d5dsh-logs/debug.log';
}

function ap_read_log(): string {
	$p = ap_test_log_path();
	return file_exists( $p ) ? (string) file_get_contents( $p ) : '';
}

function ap_write_log( string $content ): void {
	$dir = dirname( ap_test_log_path() );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0755, true );
	}
	file_put_contents( ap_test_log_path(), $content );
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( AdminPage::class )]
final class AdminPageDebugTest extends TestCase {

	private TestableAdminPage $page;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->page = new TestableAdminPage();
		$_POST      = [];
		$_GET       = [];

		// Ensure log directory exists.
		$dir = dirname( ap_test_log_path() );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	protected function tearDown(): void {
		$_POST = [];
		$_GET  = [];
	}

	// ── ajax_log_js_error() ──────────────────────────────────────────────────

	#[Test]
	public function log_js_error_returns_ok_true_when_debug_on(): void {
		$_POST['body'] = json_encode( [
			'type'    => 'error',
			'message' => 'test JS error',
			'source'  => 'admin.js',
			'lineno'  => 42,
			'colno'   => 7,
			'stack'   => 'Error: test\n  at admin.js:42',
		] );

		$ex = null;
		try {
			$this->page->ajax_log_js_error();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertTrue( $ex->data['ok'] );
	}

	#[Test]
	public function log_js_error_writes_message_to_log(): void {
		$_POST['body'] = json_encode( [
			'type'    => 'error',
			'message' => 'unique-error-sentinel-12345',
			'source'  => '',
			'lineno'  => 0,
			'colno'   => 0,
			'stack'   => '',
		] );

		try { $this->page->ajax_log_js_error(); } catch ( JsonResponseException ) {}

		$this->assertStringContainsString( 'unique-error-sentinel-12345', ap_read_log() );
	}

	#[Test]
	public function log_js_error_includes_type_in_log_entry(): void {
		$_POST['body'] = json_encode( [
			'type'    => 'unhandledrejection',
			'message' => 'promise rejected',
			'source'  => '',
			'lineno'  => 0,
			'colno'   => 0,
			'stack'   => '',
		] );

		try { $this->page->ajax_log_js_error(); } catch ( JsonResponseException ) {}

		$this->assertStringContainsString( '[JS:unhandledrejection]', ap_read_log() );
	}

	#[Test]
	public function log_js_error_includes_source_location_in_log(): void {
		$_POST['body'] = json_encode( [
			'type'    => 'error',
			'message' => 'oops',
			'source'  => 'admin.js',
			'lineno'  => 99,
			'colno'   => 5,
			'stack'   => '',
		] );

		try { $this->page->ajax_log_js_error(); } catch ( JsonResponseException ) {}

		$log = ap_read_log();
		$this->assertStringContainsString( 'admin.js:99:5', $log );
	}

	#[Test]
	public function log_js_error_with_empty_body_uses_defaults_and_succeeds(): void {
		$_POST['body'] = '';

		$ex = null;
		try {
			$this->page->ajax_log_js_error();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		// Default message should appear in log.
		$this->assertStringContainsString( 'Unknown JS error', ap_read_log() );
	}

	#[Test]
	public function log_js_error_denies_when_user_lacks_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;
		$_POST['body'] = json_encode( [ 'message' => 'x' ] );

		$ex = null;
		try {
			$this->page->ajax_log_js_error();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertFalse( $ex->success );
		$this->assertSame( 403, $ex->status_code );
	}

	// ── ajax_debug_log_read() ────────────────────────────────────────────────

	#[Test]
	public function debug_log_read_returns_empty_when_log_does_not_exist(): void {
		// Ensure no log file exists.
		if ( file_exists( ap_test_log_path() ) ) {
			unlink( ap_test_log_path() );
		}

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertTrue( $ex->data['ok'] );
		$this->assertSame( '', $ex->data['lines'] );
		$this->assertSame( 0, $ex->data['size_kb'] );
	}

	#[Test]
	public function debug_log_read_returns_log_content(): void {
		ap_write_log( "line one\nline two\nline three\n" );

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertStringContainsString( 'line one', $ex->data['lines'] );
		$this->assertStringContainsString( 'line three', $ex->data['lines'] );
	}

	#[Test]
	public function debug_log_read_returns_size_kb(): void {
		$content = str_repeat( 'a', 2048 ); // 2 KB
		ap_write_log( $content );

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertGreaterThan( 0, $ex->data['size_kb'] );
	}

	#[Test]
	public function debug_log_read_path_has_correct_format(): void {
		ap_write_log( 'test' );

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertStringContainsString( 'debug.log', $ex->data['path'] );
		$this->assertStringContainsString( 'd5dsh-logs', $ex->data['path'] );
	}

	#[Test]
	public function debug_log_read_limits_to_requested_line_count(): void {
		// Write 300 lines.
		$lines = implode( "\n", array_map( fn( $i ) => "Line $i", range( 1, 300 ) ) ) . "\n";
		ap_write_log( $lines );
		$_GET['lines'] = 50;

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$returned_lines = explode( "\n", $ex->data['lines'] );
		$this->assertLessThanOrEqual( 50, count( $returned_lines ) );
		// Should have the last 50 lines — last line should be Line 300.
		$this->assertStringContainsString( 'Line 300', $ex->data['lines'] );
		$this->assertStringNotContainsString( 'Line 1', $ex->data['lines'] );
	}

	#[Test]
	public function debug_log_read_denies_when_user_lacks_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$ex = null;
		try {
			$this->page->ajax_debug_log_read();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertFalse( $ex->success );
		$this->assertSame( 403, $ex->status_code );
	}

	// ── ajax_debug_log_clear() ───────────────────────────────────────────────

	#[Test]
	public function debug_log_clear_zeroes_log_file(): void {
		ap_write_log( "some existing content\n" );
		$this->assertNotEmpty( ap_read_log() );

		$ex = null;
		try {
			$this->page->ajax_debug_log_clear();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertTrue( $ex->data['ok'] );
		$this->assertSame( '', ap_read_log() );
	}

	#[Test]
	public function debug_log_clear_returns_ok_when_log_does_not_exist(): void {
		if ( file_exists( ap_test_log_path() ) ) {
			unlink( ap_test_log_path() );
		}

		$ex = null;
		try {
			$this->page->ajax_debug_log_clear();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
		$this->assertTrue( $ex->data['ok'] );
	}

	#[Test]
	public function debug_log_clear_denies_when_user_lacks_capability(): void {
		$GLOBALS['_d5dsh_user_can'] = false;

		$ex = null;
		try {
			$this->page->ajax_debug_log_clear();
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertFalse( $ex->success );
		$this->assertSame( 403, $ex->status_code );
	}
}
