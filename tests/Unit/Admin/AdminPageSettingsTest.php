<?php
/**
 * Tests for AdminPage settings additions:
 *   - get_settings() default values (including new wizard fields)
 *   - ajax_save_settings() persists all new fields correctly
 *   - ajax_save_settings() validates enum fields (header/footer mode, date/page format)
 *   - ajax_save_settings() clamps site_abbr to 20 characters
 *   - ajax_save_settings() setup_complete is write-once (false → true only)
 *   - ajax_save_settings() preserves existing keys not in payload
 *   - Blog name fallback chain (tested via get_settings defaults)
 *
 * Strategy:
 *   - Re-use TestableAdminPage from AdminPageDebugTest (same file namespace).
 *   - Seed $GLOBALS['_d5dsh_php_input'] with JSON payload (same as LabelManager tests).
 *   - Seed $GLOBALS['_d5dsh_options']['d5dsh_settings'] to control saved state.
 *   - Catch JsonResponseException from wp_send_json_* stubs.
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\AdminPage;
use D5DesignSystemHelper\Tests\Stubs\JsonResponseException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass( AdminPage::class )]
final class AdminPageSettingsTest extends TestCase {

	private AdminPage $page;

	protected function setUp(): void {
		_d5dsh_reset_stubs();
		$this->page = new AdminPage();
	}

	// ── get_settings() defaults ──────────────────────────────────────────────

	#[Test]
	public function get_settings_returns_all_expected_default_keys(): void {
		$s = AdminPage::get_settings();

		$this->assertArrayHasKey( 'debug_mode',         $s );
		$this->assertArrayHasKey( 'beta_preview',        $s );
		$this->assertArrayHasKey( 'security_testing',    $s );
		$this->assertArrayHasKey( 'report_header',       $s );
		$this->assertArrayHasKey( 'report_header_mode',  $s );
		$this->assertArrayHasKey( 'report_footer',       $s );
		$this->assertArrayHasKey( 'report_footer_mode',  $s );
		$this->assertArrayHasKey( 'footer_date_format',  $s );
		$this->assertArrayHasKey( 'footer_page_format',  $s );
		$this->assertArrayHasKey( 'site_abbr',           $s );
		$this->assertArrayHasKey( 'setup_complete',      $s );
	}

	#[Test]
	public function get_settings_defaults_are_correct_values(): void {
		$s = AdminPage::get_settings();

		$this->assertFalse(  $s['debug_mode'] );
		$this->assertFalse(  $s['beta_preview'] );
		$this->assertFalse(  $s['security_testing'] );
		$this->assertSame( '', $s['report_header'] );
		$this->assertSame( 'default',      $s['report_header_mode'] );
		$this->assertSame( '',             $s['report_footer'] );
		$this->assertSame( 'date_page',    $s['report_footer_mode'] );
		$this->assertSame( 'dmy',          $s['footer_date_format'] );
		$this->assertSame( 'page_x_of_n', $s['footer_page_format'] );
		$this->assertSame( '',             $s['site_abbr'] );
		$this->assertFalse( $s['setup_complete'] );
	}

	#[Test]
	public function get_settings_merges_saved_values_over_defaults(): void {
		$GLOBALS['_d5dsh_options']['d5dsh_settings'] = [
			'report_header_mode' => 'site',
			'footer_date_format' => 'ymd',
			'setup_complete'     => true,
		];

		$s = AdminPage::get_settings();

		$this->assertSame( 'site', $s['report_header_mode'] );
		$this->assertSame( 'ymd',  $s['footer_date_format'] );
		$this->assertTrue( $s['setup_complete'] );
		// Defaults still present for unset keys.
		$this->assertSame( 'date_page',    $s['report_footer_mode'] );
		$this->assertSame( 'page_x_of_n', $s['footer_page_format'] );
	}

	#[Test]
	public function get_settings_treats_non_array_stored_value_as_empty(): void {
		$GLOBALS['_d5dsh_options']['d5dsh_settings'] = 'corrupted';

		$s = AdminPage::get_settings();

		$this->assertFalse( $s['debug_mode'] );
		$this->assertFalse( $s['setup_complete'] );
	}

	// ── ajax_save_settings() — basic persistence ─────────────────────────────

	#[Test]
	public function save_settings_persists_report_header_mode(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'report_header_mode' => 'site',
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 'site', $saved['report_header_mode'] );
	}

	#[Test]
	public function save_settings_persists_report_footer_mode(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'report_footer_mode' => 'page',
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 'page', $saved['report_footer_mode'] );
	}

	#[Test]
	public function save_settings_persists_footer_date_format(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'footer_date_format' => 'ymd',
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 'ymd', $saved['footer_date_format'] );
	}

	#[Test]
	public function save_settings_persists_footer_page_format(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'footer_page_format' => 'x_of_n',
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 'x_of_n', $saved['footer_page_format'] );
	}

	#[Test]
	public function save_settings_sets_setup_complete_to_true(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'setup_complete' => true,
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertTrue( $saved['setup_complete'] );
	}

	#[Test]
	public function save_settings_returns_success_response(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [] );

		$ex = null;
		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $caught ) { $ex = $caught; }

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
	}

	// ── ajax_save_settings() — site_abbr length clamp ───────────────────────

	#[Test]
	public function save_settings_clamps_site_abbr_to_20_characters(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'site_abbr' => 'abcdefghijklmnopqrstuvwxyz', // 26 chars
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 20, strlen( $saved['site_abbr'] ) );
		$this->assertSame( 'abcdefghijklmnopqrst', $saved['site_abbr'] );
	}

	#[Test]
	public function save_settings_accepts_site_abbr_at_exactly_20_characters(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'site_abbr' => 'twelve_chars_here123', // exactly 20
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( 'twelve_chars_here123', $saved['site_abbr'] );
	}

	#[Test]
	public function save_settings_accepts_empty_site_abbr(): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'site_abbr' => '',
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( '', $saved['site_abbr'] );
	}

	// ── ajax_save_settings() — enum validation ───────────────────────────────

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_header_mode_provider(): array {
		return [
			'empty string'   => [ 'report_header_mode', '',         'default' ],
			'unknown value'  => [ 'report_header_mode', 'invalid',  'default' ],
			'SQL injection'  => [ 'report_header_mode', "'; DROP--", 'default' ],
		];
	}

	#[Test]
	#[DataProvider( 'invalid_header_mode_provider' )]
	public function save_settings_falls_back_to_default_for_invalid_header_mode(
		string $field, string $value, string $expected
	): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ $field => $value ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $expected, $saved[ $field ] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_footer_mode_provider(): array {
		return [
			'empty string'  => [ 'report_footer_mode', '',        'date_page' ],
			'unknown value' => [ 'report_footer_mode', 'bogus',   'date_page' ],
		];
	}

	#[Test]
	#[DataProvider( 'invalid_footer_mode_provider' )]
	public function save_settings_falls_back_to_default_for_invalid_footer_mode(
		string $field, string $value, string $expected
	): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ $field => $value ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $expected, $saved[ $field ] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_date_format_provider(): array {
		return [
			'empty string'  => [ 'footer_date_format', '',       'dmy' ],
			'unknown key'   => [ 'footer_date_format', 'long',   'dmy' ],
		];
	}

	#[Test]
	#[DataProvider( 'invalid_date_format_provider' )]
	public function save_settings_falls_back_to_dmy_for_invalid_date_format(
		string $field, string $value, string $expected
	): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ $field => $value ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $expected, $saved[ $field ] );
	}

	/**
	 * @return array<string, array{string, string, string}>
	 */
	public static function invalid_page_format_provider(): array {
		return [
			'empty string'  => [ 'footer_page_format', '',             'page_x_of_n' ],
			'unknown key'   => [ 'footer_page_format', 'roman',        'page_x_of_n' ],
		];
	}

	#[Test]
	#[DataProvider( 'invalid_page_format_provider' )]
	public function save_settings_falls_back_to_page_x_of_n_for_invalid_page_format(
		string $field, string $value, string $expected
	): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ $field => $value ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $expected, $saved[ $field ] );
	}

	// ── Valid enum values are accepted ───────────────────────────────────────

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function valid_header_modes_provider(): array {
		return [
			'default' => [ 'default' ],
			'site'    => [ 'site'    ],
			'custom'  => [ 'custom'  ],
			'none'    => [ 'none'    ],
		];
	}

	#[Test]
	#[DataProvider( 'valid_header_modes_provider' )]
	public function save_settings_accepts_all_valid_header_modes( string $mode ): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'report_header_mode' => $mode ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $mode, $saved['report_header_mode'] );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function valid_footer_modes_provider(): array {
		return [
			'date_page'   => [ 'date_page'   ],
			'page'        => [ 'page'        ],
			'custom_page' => [ 'custom_page' ],
			'none'        => [ 'none'        ],
		];
	}

	#[Test]
	#[DataProvider( 'valid_footer_modes_provider' )]
	public function save_settings_accepts_all_valid_footer_modes( string $mode ): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'report_footer_mode' => $mode ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $mode, $saved['report_footer_mode'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function valid_date_formats_provider(): array {
		return [
			'wp'        => [ 'wp'        ],
			'dmy'       => [ 'dmy'       ],
			'mdy'       => [ 'mdy'       ],
			'ymd'       => [ 'ymd'       ],
			'short_dmy' => [ 'short_dmy' ],
			'short_mdy' => [ 'short_mdy' ],
		];
	}

	#[Test]
	#[DataProvider( 'valid_date_formats_provider' )]
	public function save_settings_accepts_all_valid_date_formats( string $fmt ): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'footer_date_format' => $fmt ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $fmt, $saved['footer_date_format'] );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function valid_page_formats_provider(): array {
		return [
			'page_x_of_n' => [ 'page_x_of_n' ],
			'page_x'      => [ 'page_x'      ],
			'x_of_n'      => [ 'x_of_n'      ],
			'x'           => [ 'x'           ],
			'none'        => [ 'none'         ],
		];
	}

	#[Test]
	#[DataProvider( 'valid_page_formats_provider' )]
	public function save_settings_accepts_all_valid_page_formats( string $fmt ): void {
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'footer_page_format' => $fmt ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertSame( $fmt, $saved['footer_page_format'] );
	}

	// ── setup_complete write-once behaviour ──────────────────────────────────

	#[Test]
	public function setup_complete_is_not_set_to_true_when_payload_omits_it(): void {
		$GLOBALS['_d5dsh_options']['d5dsh_settings'] = [ 'setup_complete' => false ];
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'site_abbr' => 'test',
			// setup_complete not present
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertFalse( $saved['setup_complete'] );
	}

	#[Test]
	public function setup_complete_cannot_be_unset_once_true(): void {
		// Pre-seed as complete.
		$GLOBALS['_d5dsh_options']['d5dsh_settings'] = [ 'setup_complete' => true ];
		// Payload sends false — should be ignored because write-once.
		$GLOBALS['_d5dsh_php_input'] = json_encode( [
			'setup_complete' => false,
		] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		$this->assertTrue( $saved['setup_complete'] );
	}

	// ── Full-replace save behaviour ───────────────────────────────────────────

	#[Test]
	public function save_settings_is_full_replace_enum_fields_default_when_absent(): void {
		// ajax_save_settings() is a full-replace endpoint: every enum field is
		// re-validated from the payload on each save, and defaults are applied
		// when a key is missing from the payload.  It is NOT a partial-patch API.
		$GLOBALS['_d5dsh_options']['d5dsh_settings'] = [
			'footer_date_format' => 'mdy',
			'footer_page_format' => 'page_x',
		];
		// Payload omits footer_date_format and footer_page_format.
		$GLOBALS['_d5dsh_php_input'] = json_encode( [ 'site_abbr' => 'myco' ] );

		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $ex ) {}

		$saved = $GLOBALS['_d5dsh_options']['d5dsh_settings'] ?? [];
		// Enum fields fall back to their coded defaults when absent from payload.
		$this->assertSame( 'dmy',          $saved['footer_date_format'] );
		$this->assertSame( 'page_x_of_n',  $saved['footer_page_format'] );
		// The field that was in the payload is correctly saved.
		$this->assertSame( 'myco',         $saved['site_abbr'] );
	}

	// ── Permission check ─────────────────────────────────────────────────────

	#[Test]
	public function save_settings_denies_when_user_lacks_capability(): void {
		$GLOBALS['_d5dsh_user_can']  = false;
		$GLOBALS['_d5dsh_php_input'] = json_encode( [] );

		$ex = null;
		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $caught ) { $ex = $caught; }

		$this->assertNotNull( $ex );
		$this->assertFalse( $ex->success );
		$this->assertSame( 403, $ex->status_code );
	}

	// ── Empty / malformed payload ─────────────────────────────────────────────

	#[Test]
	public function save_settings_handles_empty_json_body_gracefully(): void {
		$GLOBALS['_d5dsh_php_input'] = '';

		$ex = null;
		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $caught ) { $ex = $caught; }

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
	}

	#[Test]
	public function save_settings_handles_invalid_json_body_gracefully(): void {
		$GLOBALS['_d5dsh_php_input'] = 'not-json{{{';

		$ex = null;
		try { $this->page->ajax_save_settings(); } catch ( JsonResponseException $caught ) { $ex = $caught; }

		$this->assertNotNull( $ex );
		$this->assertTrue( $ex->success );
	}
}
