<?php
/**
 * Utility class for detecting and handling base64 data URI blobs.
 *
 * Mirrors the logic in the Python tool's core/blob.py module so that
 * blob detection behaviour is identical between the two tools.
 *
 * A "blob" is defined as any string that begins with the pattern:
 *   data:<mimetype>;base64,
 *
 * These are typically base64-encoded images stored as Divi variable values.
 * They are too large and unreadable to be useful in Excel, so they are
 * replaced with a human-readable placeholder. The original values are
 * preserved in the database and restored during import.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Util;

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BlobUtil
 */
class BlobUtil {

	/**
	 * Regex pattern for detecting base64 data URIs.
	 * Case-insensitive to match both 'base64' and 'BASE64'.
	 */
	private const BLOB_PATTERN = '/^data:[^;]+;base64,/i';

	/**
	 * Return true if $value is a base64 data URI blob.
	 *
	 * @param string $value The value to test.
	 * @return bool
	 */
	public static function is_blob( string $value ): bool {
		return (bool) preg_match( self::BLOB_PATTERN, $value );
	}

	/**
	 * If $value is a blob, return the placeholder string.
	 * Otherwise return the original value unchanged.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function sanitize( string $value ): string {
		return self::is_blob( $value ) ? 'Uneditable Data Not Shown' : $value;
	}
}
