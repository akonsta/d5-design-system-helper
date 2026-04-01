<?php
/**
 * WordPress function / class stubs for unit testing.
 *
 * These replace the WordPress API that the plugin classes call,
 * without requiring a real WordPress installation.
 *
 * The in-memory options store ($GLOBALS['_d5dsh_options']) is
 * reset before each test via WPFunctions::reset().
 *
 * Only the functions actually called by the plugin classes under
 * test are stubbed here.
 */

declare( strict_types=1 );

// ── In-memory option store ────────────────────────────────────────────────────

/**
 * Reset the stub state between tests.
 * Call this from your setUp() method.
 */
function _d5dsh_reset_stubs(): void {
	$GLOBALS['_d5dsh_options']      = [];
	$GLOBALS['_d5dsh_option_calls'] = [];   // log of update_option / add_option calls
	$GLOBALS['_d5dsh_transients']   = [];
	$GLOBALS['_d5dsh_php_input']    = '';
	$GLOBALS['_d5dsh_json_response'] = null;
	$GLOBALS['_d5dsh_redirect']     = null;
	$GLOBALS['_d5dsh_user_can']     = true;
	if ( isset( $GLOBALS['wpdb'] ) ) {
		$GLOBALS['wpdb']->_stub_results = [];
	}
	// Clean up any debug log file created during tests.
	$log = sys_get_temp_dir() . '/d5dsh_test_uploads/d5dsh-logs/debug.log';
	if ( file_exists( $log ) ) {
		file_put_contents( $log, '' );
	}
}

_d5dsh_reset_stubs();

// ── Upload dir stub ───────────────────────────────────────────────────────────
// Points to a temp directory so DebugLogger can create/read/write its log file.

function wp_upload_dir( ?string $time = null, bool $create_dir = true, bool $refresh_cache = false ): array {
	$dir = sys_get_temp_dir() . '/d5dsh_test_uploads';
	return [
		'path'    => $dir,
		'url'     => 'https://example.com/wp-content/uploads',
		'subdir'  => '',
		'basedir' => $dir,
		'baseurl' => 'https://example.com/wp-content/uploads',
		'error'   => false,
	];
}

function wp_mkdir_p( string $target ): bool {
	if ( is_dir( $target ) ) {
		return true;
	}
	return mkdir( $target, 0755, true );
}

// ── wp_options stubs ─────────────────────────────────────────────────────────

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['_d5dsh_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = true ): bool {
	$GLOBALS['_d5dsh_options'][ $key ]            = $value;
	$GLOBALS['_d5dsh_option_calls'][]             = [ 'fn' => 'update_option', 'key' => $key ];
	return true;
}

function add_option( string $key, mixed $value = '', string $deprecated = '', bool|string $autoload = true ): bool {
	if ( isset( $GLOBALS['_d5dsh_options'][ $key ] ) ) {
		return false; // Already exists — mirrors WP behaviour.
	}
	$GLOBALS['_d5dsh_options'][ $key ]  = $value;
	$GLOBALS['_d5dsh_option_calls'][]   = [ 'fn' => 'add_option', 'key' => $key ];
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['_d5dsh_options'][ $key ] );
	return true;
}

// ── Sanitization stubs ────────────────────────────────────────────────────────
// These mirror WordPress's actual behaviour closely enough for unit tests.

function wp_unslash( mixed $value ): mixed {
	if ( is_string( $value ) ) {
		return stripslashes( $value );
	}
	if ( is_array( $value ) ) {
		return array_map( 'stripslashes', $value );
	}
	return $value;
}

function sanitize_text_field( string $str ): string {
	// Strip tags, trim, collapse internal whitespace.
	$filtered = strip_tags( $str );
	$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered ) ?? $filtered;
	return trim( $filtered );
}

function sanitize_textarea_field( string $str ): string {
	// Like sanitize_text_field but preserves newlines.
	$filtered = strip_tags( $str );
	return trim( $filtered );
}

function sanitize_key( string $key ): string {
	$raw = strtolower( $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $raw ) ?? $raw;
}

function sanitize_hex_color( string $color ): string {
	if ( '' === $color ) {
		return '';
	}
	if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $color ) ) {
		return $color;
	}
	return '';
}

function wp_generate_uuid4(): string {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
		mt_rand( 0, 0xffff ),
		mt_rand( 0, 0x0fff ) | 0x4000,
		mt_rand( 0, 0x3fff ) | 0x8000,
		mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}

function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
	return json_encode( $data, $flags, $depth );
}

// ── Nonce stubs (always pass in test context) ─────────────────────────────────

function check_ajax_referer( string $action, string|bool $query_arg = false, bool $die = true ): int {
	return 1; // Always valid in tests.
}

function wp_create_nonce( string $action ): string {
	return 'test_nonce_' . md5( $action );
}

function wp_verify_nonce( string $nonce, string $action ): int|false {
	return 1;
}

// ── Capability stubs ──────────────────────────────────────────────────────────

// Default: current user CAN do everything.  Override in individual tests via
// $GLOBALS['_d5dsh_user_can'] = false;

function current_user_can( string $capability ): bool {
	return $GLOBALS['_d5dsh_user_can'] ?? true;
}

function get_current_user_id(): int {
	return 1;
}

// ── Transient stubs ──────────────────────────────────────────────────────────

$GLOBALS['_d5dsh_transients'] = [];

function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
	$GLOBALS['_d5dsh_transients'][ $transient ] = $value;
	return true;
}

function get_transient( string $transient ): mixed {
	return $GLOBALS['_d5dsh_transients'][ $transient ] ?? false;
}

function delete_transient( string $transient ): bool {
	unset( $GLOBALS['_d5dsh_transients'][ $transient ] );
	return true;
}

// ── Hook stubs ────────────────────────────────────────────────────────────────

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	return true;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
	return true;
}

// ── JSON output stubs ─────────────────────────────────────────────────────────
// Capture output rather than sending it so tests can inspect it.

$GLOBALS['_d5dsh_json_response'] = null;

function wp_send_json_success( mixed $data = null, int $status_code = 200 ): never {
	$GLOBALS['_d5dsh_json_response'] = [ 'success' => true, 'data' => $data, 'status' => $status_code ];
	// Use an exception to simulate WP's die() without killing the test process.
	throw new \D5DesignSystemHelper\Tests\Stubs\JsonResponseException( true, $data, $status_code );
}

function wp_send_json_error( mixed $data = null, int $status_code = 200 ): never {
	$GLOBALS['_d5dsh_json_response'] = [ 'success' => false, 'data' => $data, 'status' => $status_code ];
	throw new \D5DesignSystemHelper\Tests\Stubs\JsonResponseException( false, $data, $status_code );
}

// ── php://input ───────────────────────────────────────────────────────────────
// LabelManager calls file_get_contents('php://input') inside the
// D5DesignSystemHelper\Admin namespace.  PHP's namespace function-resolution means
// we can shadow the built-in by declaring our own file_get_contents() in that
// same namespace (see the separate namespace block at the bottom of this file).
// Tests seed: $GLOBALS['_d5dsh_php_input'] = json_encode($payload);

$GLOBALS['_d5dsh_php_input'] = '';

// ── Misc WP stubs used in plugin classes ────────────────────────────────────

function admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/' . ltrim( $path, '/' );
}

function add_query_arg( array|string $args, string $url = '' ): string {
	if ( is_array( $args ) ) {
		return $url . '?' . http_build_query( $args );
	}
	return $url . '?' . $args;
}

function wp_safe_redirect( string $location, int $status = 302 ): void {
	$GLOBALS['_d5dsh_redirect'] = [ 'location' => $location, 'status' => $status ];
}

function menu_page_url( string $menu_slug, bool $echo = true ): string {
	return '';
}

function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

// ── Hook stubs ────────────────────────────────────────────────────────────────

function do_action( string $hook_name, mixed ...$args ): void {
	// No-op in unit tests — hooks have no registered callbacks.
}

function apply_filters( string $hook_name, mixed $value, mixed ...$args ): mixed {
	return $value; // Return value unchanged — no filters registered.
}

// ── Site URL / blog info stubs ────────────────────────────────────────────────

function get_site_url( ?int $blog_id = null, string $path = '', string $scheme = '' ): string {
	return 'https://example.com';
}

function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
	return match ( $show ) {
		'name'    => 'Test Site',
		'version' => '6.5',
		default   => '',
	};
}

function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( string $url ): string {
	return $url;
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return $number === 1 ? $single : $plural;
}

// ── $wpdb stub ───────────────────────────────────────────────────────────────

/**
 * Minimal wpdb stub.  Tests that exercise list_backups() or
 * types_with_snapshots() must set $GLOBALS['wpdb']->_stub_results.
 */
class WpdbStub {
	public string $options   = 'wp_options';
	public string $posts     = 'wp_posts';
	public string $postmeta  = 'wp_postmeta';
	/** @var array Query result rows to return from get_col() or get_results(). */
	public array $_stub_results = [];
	/** @var array[] Separate result set for get_results() calls. */
	public array $_stub_rows = [];

	public function prepare( string $query, mixed ...$args ): string {
		// Replace %s and %d placeholders in order.
		$i = 0;
		return preg_replace_callback( '/%[sd]/', function ( $m ) use ( $args, &$i ) {
			$val = $args[ $i++ ] ?? '';
			if ( $m[0] === '%d' ) {
				return (string) (int) $val;
			}
			return "'" . addslashes( (string) $val ) . "'";
		}, $query ) ?? $query;
	}

	public function get_col( string $query, int $col_offset = 0 ): array {
		return $this->_stub_results;
	}

	/**
	 * Returns $_stub_rows when set, otherwise an empty array.
	 *
	 * @param string $query Ignored in stub.
	 * @param string $output ARRAY_A, OBJECT, etc. Ignored.
	 * @return array
	 */
	public function get_results( string $query, string $output = 'OBJECT' ): array {
		return $this->_stub_rows;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new WpdbStub();
}

// Namespace-scoped shadows are in a separate file to comply with PHP's
// "namespace must be the first statement" rule.
// See: tests/Stubs/NamespaceShadows.php
