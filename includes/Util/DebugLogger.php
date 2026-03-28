<?php
/**
 * Debug logger for D5 Design System Helper.
 *
 * Writes timestamped entries to wp-content/uploads/d5dsh-logs/debug.log
 * when debug mode is active (D5DSH_DEBUG === true).
 *
 * The log directory is protected by an .htaccess file that denies direct
 * web access. Log files should never be publicly readable.
 *
 * Usage:
 *   DebugLogger::log( 'Something happened' );
 *   DebugLogger::log_exception( $e, 'context label' );
 *   DebugLogger::is_active();   // check before building expensive debug data
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DebugLogger
 */
class DebugLogger {

	/** wp_options key for the plugin settings array. */
	const OPTION_KEY = 'd5dsh_settings';

	/** Sub-directory inside wp-content/uploads. */
	const LOG_SUBDIR = 'd5dsh-logs';

	/** Log filename. */
	const LOG_FILE = 'debug.log';

	/** Max log file size before it is rotated (2 MB). */
	const MAX_BYTES = 2 * 1024 * 1024;

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Return true when debug mode is enabled.
	 */
	public static function is_active(): bool {
		return defined( 'D5DSH_DEBUG' ) && D5DSH_DEBUG === true;
	}

	/**
	 * Write a plain-text message to the debug log.
	 *
	 * @param string $message  Human-readable message.
	 * @param string $context  Optional label for the caller (e.g. class::method).
	 */
	public static function log( string $message, string $context = '' ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$line = self::format_line( 'INFO', $context, $message );
		self::write( $line );
	}

	/**
	 * Write an ERROR entry to the debug log.
	 *
	 * @param string $message
	 * @param string $context
	 */
	public static function log_error( string $message, string $context = '' ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$line = self::format_line( 'ERROR', $context, $message );
		self::write( $line );
	}

	/**
	 * Write an exception (with stack trace) to the debug log.
	 *
	 * @param \Throwable $e
	 * @param string     $context  Optional label for the caller.
	 */
	public static function log_exception( \Throwable $e, string $context = '' ): void {
		if ( ! self::is_active() ) {
			return;
		}
		$message = sprintf(
			"%s: %s\n  in %s:%d\n  Stack trace:\n%s",
			get_class( $e ),
			$e->getMessage(),
			$e->getFile(),
			$e->getLine(),
			self::format_trace( $e->getTrace() )
		);
		$line = self::format_line( 'EXCEPTION', $context, $message );
		self::write( $line );
	}

	/**
	 * Return a one-line debug summary of an exception suitable for a notice.
	 *
	 * When debug mode is off, returns a generic fallback instead.
	 *
	 * @param \Throwable $e
	 * @param string     $fallback  Message to return when debug is off.
	 * @return string
	 */
	public static function exception_notice( \Throwable $e, string $fallback = '' ): string {
		if ( ! self::is_active() ) {
			return $fallback ?: __( 'An unexpected error occurred.', 'd5-design-system-helper' );
		}
		return sprintf(
			'[DEBUG] %s: %s (in %s:%d)',
			get_class( $e ),
			$e->getMessage(),
			basename( $e->getFile() ),
			$e->getLine()
		);
	}

	/**
	 * Return the absolute path to the log file, or null if the log directory
	 * cannot be created or written to.
	 */
	public static function log_path(): ?string {
		$dir = self::ensure_log_dir();
		if ( ! $dir ) {
			return null;
		}
		return $dir . '/' . self::LOG_FILE;
	}

	/**
	 * Clear the log file contents (keep the file, zero it out).
	 */
	public static function clear_log(): void {
		$path = self::log_path();
		if ( $path && file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$fh = fopen( $path, 'w' );
			if ( $fh ) {
				fclose( $fh );
			}
		}
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Format a single log line.
	 */
	private static function format_line( string $level, string $context, string $message ): string {
		$ts  = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
		$ctx = $context ? " [{$context}]" : '';
		// Indent multi-line messages.
		$body = str_replace( "\n", "\n    ", $message );
		return "[{$ts}] [{$level}]{$ctx} {$body}\n";
	}

	/**
	 * Format a stack trace array into a readable string.
	 *
	 * @param array<int,array<string,mixed>> $trace
	 */
	private static function format_trace( array $trace ): string {
		$lines = [];
		foreach ( array_slice( $trace, 0, 10 ) as $i => $frame ) {
			$loc  = ( $frame['file'] ?? '?' ) . ':' . ( $frame['line'] ?? '?' );
			$fn   = ( isset( $frame['class'] ) ? $frame['class'] . $frame['type'] : '' ) . ( $frame['function'] ?? '' );
			$lines[] = "    #{$i} {$loc} {$fn}()";
		}
		return implode( "\n", $lines );
	}

	/**
	 * Append a line to the log file. Rotates if over MAX_BYTES.
	 */
	private static function write( string $line ): void {
		$path = self::log_path();
		if ( ! $path ) {
			return;
		}

		// Rotate if too large.
		if ( file_exists( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			$rotated = $path . '.' . gmdate( 'Ymd-His' ) . '.bak';
			rename( $path, $rotated ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Ensure the log directory exists and is protected. Returns the path or null.
	 */
	private static function ensure_log_dir(): ?string {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return null;
		}

		$dir = $upload['basedir'] . '/' . self::LOG_SUBDIR;

		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			if ( ! mkdir( $dir, 0755, true ) ) {
				return null;
			}
			// Write .htaccess to block direct web access.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents(
				$dir . '/.htaccess',
				"Order deny,allow\nDeny from all\n"
			);
			// Write index.php to block directory listing as extra measure.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . '/index.php', '<?php // Silence is golden.' );
		}

		if ( ! is_writable( $dir ) ) {
			return null;
		}

		return $dir;
	}
}
