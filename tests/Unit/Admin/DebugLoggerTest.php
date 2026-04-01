<?php
/**
 * Tests for DebugLogger — error trapping system.
 *
 * Because D5DSH_DEBUG is a PHP constant (defined once at bootstrap), we cannot
 * flip it mid-process.  Instead, every test goes through a TestableDebugLogger
 * subclass whose is_active() method reads a writable flag
 * ($GLOBALS['_d5dsh_debug_active']) so each test can choose enabled/disabled
 * independently.
 *
 * The log directory points to sys_get_temp_dir()/d5dsh_test_uploads (set up by
 * the wp_upload_dir() stub in WPFunctions.php).  _d5dsh_reset_stubs() zeros
 * the log file between tests.
 *
 * Covers:
 *   is_active()             : reads _d5dsh_debug_active flag
 *   log()                   : writes INFO line when active, silent when not
 *   log_error()             : writes ERROR line
 *   log_exception()         : writes EXCEPTION line with class + message
 *   exception_notice()      : returns [DEBUG] string when active, fallback when not
 *   send_error() debug on   : throws JsonResponseException with debug key in data
 *   send_error() debug off  : throws JsonResponseException without debug key
 *   send_error() logs       : underlying log_exception is called (line appears in log)
 *   send_error() http status: custom HTTP status code forwarded to response
 *   clear_log()             : zeros the log file
 *   log_path()              : returns null when upload_dir errors, path when ok
 *   rotation                : log file is renamed when over MAX_BYTES
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use D5DesignSystemHelper\Util\DebugLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Overrides is_active() to read a global flag instead of the D5DSH_DEBUG
 * constant, allowing per-test enable/disable without redefining constants.
 */
class TestableDebugLogger extends DebugLogger {
	public static function is_active(): bool {
		return $GLOBALS['_d5dsh_debug_active'] ?? false;
	}
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Return the path to the test log file (may not exist yet). */
function test_log_path(): string {
	return sys_get_temp_dir() . '/d5dsh_test_uploads/d5dsh-logs/debug.log';
}

/** Read current log contents (empty string if file absent). */
function read_test_log(): string {
	$p = test_log_path();
	return file_exists( $p ) ? (string) file_get_contents( $p ) : '';
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( DebugLogger::class )]
final class DebugLoggerTest extends TestCase {

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$GLOBALS['_d5dsh_debug_active'] = false;
		// Ensure the log directory exists for tests that write to it.
		$dir = sys_get_temp_dir() . '/d5dsh_test_uploads/d5dsh-logs';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		// Remove any rotated backup files created by rotation test.
		foreach ( glob( test_log_path() . '.*.bak' ) ?: [] as $f ) {
			@unlink( $f );
		}
	}

	// ── is_active() ──────────────────────────────────────────────────────────

	#[Test]
	public function is_active_returns_false_when_flag_off(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$this->assertFalse( TestableDebugLogger::is_active() );
	}

	#[Test]
	public function is_active_returns_true_when_flag_on(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$this->assertTrue( TestableDebugLogger::is_active() );
	}

	// ── log() ────────────────────────────────────────────────────────────────

	#[Test]
	public function log_writes_info_line_when_active(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		TestableDebugLogger::log( 'hello test', 'MyContext' );
		$content = read_test_log();
		$this->assertStringContainsString( '[INFO]', $content );
		$this->assertStringContainsString( '[MyContext]', $content );
		$this->assertStringContainsString( 'hello test', $content );
	}

	#[Test]
	public function log_is_silent_when_inactive(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		TestableDebugLogger::log( 'should not appear' );
		$this->assertSame( '', read_test_log() );
	}

	// ── log_error() ──────────────────────────────────────────────────────────

	#[Test]
	public function log_error_writes_error_level(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		TestableDebugLogger::log_error( 'something broke', 'ErrorCtx' );
		$content = read_test_log();
		$this->assertStringContainsString( '[ERROR]', $content );
		$this->assertStringContainsString( '[ErrorCtx]', $content );
		$this->assertStringContainsString( 'something broke', $content );
	}

	// ── log_exception() ──────────────────────────────────────────────────────

	#[Test]
	public function log_exception_writes_exception_class_and_message(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$e = new \RuntimeException( 'test exception message' );
		TestableDebugLogger::log_exception( $e, 'ExCtx' );
		$content = read_test_log();
		$this->assertStringContainsString( '[EXCEPTION]', $content );
		$this->assertStringContainsString( '[ExCtx]', $content );
		$this->assertStringContainsString( 'RuntimeException', $content );
		$this->assertStringContainsString( 'test exception message', $content );
	}

	#[Test]
	public function log_exception_is_silent_when_inactive(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		TestableDebugLogger::log_exception( new \RuntimeException( 'nope' ) );
		$this->assertSame( '', read_test_log() );
	}

	// ── exception_notice() ───────────────────────────────────────────────────

	#[Test]
	public function exception_notice_returns_debug_string_when_active(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$e      = new \InvalidArgumentException( 'bad arg' );
		$notice = TestableDebugLogger::exception_notice( $e );
		$this->assertStringStartsWith( '[DEBUG]', $notice );
		$this->assertStringContainsString( 'InvalidArgumentException', $notice );
		$this->assertStringContainsString( 'bad arg', $notice );
	}

	#[Test]
	public function exception_notice_returns_fallback_when_inactive(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e      = new \RuntimeException( 'internal detail' );
		$notice = TestableDebugLogger::exception_notice( $e, 'Custom fallback' );
		$this->assertSame( 'Custom fallback', $notice );
		$this->assertStringNotContainsString( 'internal detail', $notice );
	}

	#[Test]
	public function exception_notice_uses_default_fallback_when_none_supplied(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e      = new \RuntimeException( 'secret' );
		$notice = TestableDebugLogger::exception_notice( $e );
		$this->assertStringNotContainsString( 'secret', $notice );
		$this->assertNotEmpty( $notice );
	}

	// ── send_error() — debug ON ──────────────────────────────────────────────

	#[Test]
	public function send_error_with_debug_on_includes_debug_key_in_response(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$e = new \RuntimeException( 'db connection failed' );

		$ex = null;
		try {
			TestableDebugLogger::send_error( $e, 'TestHandler', 'Something went wrong.' );
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex, 'send_error must throw JsonResponseException' );
		$this->assertFalse( $ex->success );
		$this->assertArrayHasKey( 'debug', $ex->data );
		$this->assertSame( 'TestHandler', $ex->data['debug']['context'] );
		$this->assertSame( 'RuntimeException', $ex->data['debug']['exception'] );
		$this->assertStringContainsString( 'db connection failed', $ex->data['debug']['error'] );
		$this->assertIsArray( $ex->data['debug']['trace'] );
	}

	#[Test]
	public function send_error_with_debug_on_includes_user_message(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$e = new \RuntimeException( 'internal' );

		$ex = null;
		try {
			TestableDebugLogger::send_error( $e, '', 'User-visible message.' );
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertSame( 'User-visible message.', $ex->data['message'] );
	}

	// ── send_error() — debug OFF ─────────────────────────────────────────────

	#[Test]
	public function send_error_with_debug_off_omits_debug_key(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e = new \RuntimeException( 'internal secret' );

		$ex = null;
		try {
			TestableDebugLogger::send_error( $e, 'Handler', 'Safe message.' );
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertFalse( $ex->success );
		$this->assertArrayNotHasKey( 'debug', $ex->data );
		$this->assertSame( 'Safe message.', $ex->data['message'] );
		// Internal detail must not leak.
		$this->assertStringNotContainsString( 'internal secret', json_encode( $ex->data ) );
	}

	#[Test]
	public function send_error_with_debug_off_uses_default_fallback(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e = new \RuntimeException( 'nope' );

		$ex = null;
		try {
			TestableDebugLogger::send_error( $e );
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertArrayHasKey( 'message', $ex->data );
		$this->assertNotEmpty( $ex->data['message'] );
	}

	// ── send_error() — HTTP status code ──────────────────────────────────────

	#[Test]
	public function send_error_forwards_custom_http_status(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e = new \RuntimeException( 'x' );

		$ex = null;
		try {
			TestableDebugLogger::send_error( $e, '', 'msg', 422 );
		} catch ( JsonResponseException $caught ) {
			$ex = $caught;
		}

		$this->assertNotNull( $ex );
		$this->assertSame( 422, $ex->status_code );
	}

	// ── send_error() writes to log ───────────────────────────────────────────

	#[Test]
	public function send_error_writes_exception_to_log_when_debug_on(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$e = new \LogicException( 'logged message' );

		try {
			TestableDebugLogger::send_error( $e, 'LogCtx', 'fallback' );
		} catch ( JsonResponseException ) {}

		$content = read_test_log();
		$this->assertStringContainsString( '[EXCEPTION]', $content );
		$this->assertStringContainsString( 'logged message', $content );
	}

	#[Test]
	public function send_error_does_not_write_to_log_when_debug_off(): void {
		$GLOBALS['_d5dsh_debug_active'] = false;
		$e = new \LogicException( 'should not appear in log' );

		try {
			TestableDebugLogger::send_error( $e, 'LogCtx', 'fallback' );
		} catch ( JsonResponseException ) {}

		$this->assertSame( '', read_test_log() );
	}

	// ── clear_log() ──────────────────────────────────────────────────────────

	#[Test]
	public function clear_log_zeros_log_file(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		// Write something first.
		TestableDebugLogger::log( 'something to clear' );
		$this->assertNotEmpty( read_test_log() );

		// Now clear — note: clear_log() uses the real is_active() check internally
		// via log_path(), so we call it directly on DebugLogger after seeding the
		// file path with our stub.
		$path = TestableDebugLogger::log_path();
		$this->assertNotNull( $path );
		file_put_contents( $path, "line1\nline2\n" );

		TestableDebugLogger::clear_log();
		$this->assertSame( '', read_test_log() );
	}

	// ── log_path() ───────────────────────────────────────────────────────────

	#[Test]
	public function log_path_returns_string_when_upload_dir_is_ok(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$path = TestableDebugLogger::log_path();
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'debug.log', $path );
	}

	// ── log rotation ─────────────────────────────────────────────────────────

	#[Test]
	public function log_is_rotated_when_over_max_size(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		$path = TestableDebugLogger::log_path();
		$this->assertNotNull( $path );

		// Write more than MAX_BYTES (2 MB) directly into the log file.
		$big_content = str_repeat( 'x', DebugLogger::MAX_BYTES + 1 );
		file_put_contents( $path, $big_content );

		// Writing one more entry should trigger rotation.
		TestableDebugLogger::log( 'trigger rotation' );

		// The original file should now be small (contains only the new entry).
		$new_size = filesize( $path );
		$this->assertLessThan( DebugLogger::MAX_BYTES, $new_size );

		// A rotated backup file should exist.
		$backups = glob( $path . '.*.bak' ) ?: [];
		$this->assertNotEmpty( $backups, 'A rotated .bak file should exist after rotation' );
	}

	// ── multiple entries are appended ────────────────────────────────────────

	#[Test]
	public function multiple_log_entries_are_appended(): void {
		$GLOBALS['_d5dsh_debug_active'] = true;
		TestableDebugLogger::log( 'entry one' );
		TestableDebugLogger::log( 'entry two' );
		TestableDebugLogger::log( 'entry three' );
		$content = read_test_log();
		$this->assertStringContainsString( 'entry one', $content );
		$this->assertStringContainsString( 'entry two', $content );
		$this->assertStringContainsString( 'entry three', $content );
	}
}
