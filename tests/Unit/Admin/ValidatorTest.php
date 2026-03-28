<?php
/**
 * Tests for the Validator class.
 *
 * Strategy: most of Validator's logic is in private methods that operate on
 * Spreadsheet / Worksheet objects.  Rather than mocking PhpSpreadsheet we use
 * it directly — it has no external I/O cost when created in-memory — and we
 * write minimal test fixtures to a temp file so IOFactory::load() can read them.
 *
 * Where possible we test via a TestableValidator subclass that exposes private
 * helpers; for the full validation pipeline we build tiny .xlsx fixtures and
 * call validate().
 *
 * Covers:
 *   Constants       : severity levels, FILE_TYPE_SIGNATURES, REQUIRED_COLUMNS,
 *                     VALID_STATUSES, COLOR_PATTERN
 *   add()           : public issue-collection API
 *   build_report()  : counts, passed flag, file_type
 *   detect_file_type() : best-match scoring across known signatures
 *   check_color_value(): all valid + invalid patterns
 *   read_headers()  : trailing-empty-header stripping
 *   validate() pipeline: unreadable file → FATAL; missing required sheet → FATAL;
 *                        missing required column → ERROR; invalid color → ERROR
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Unit\Admin;

use D5DesignSystemHelper\Admin\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

// ── Testable subclass ─────────────────────────────────────────────────────────

/**
 * Exposes Validator private helpers as public methods for white-box testing.
 */
class TestableValidator extends Validator {

	/**
	 * Allow tests to inject a pre-built Spreadsheet rather than reading a file.
	 * We call detect_file_type() directly by making it accessible.
	 */
	public function pub_detect_file_type( Spreadsheet $ss ): void {
		// Use reflection to call the private method.
		$m = new \ReflectionMethod( Validator::class, 'detect_file_type' );
		$m->setAccessible( true );
		$m->invoke( $this, $ss );
	}

	public function pub_check_color_value( string $sheet, int $row, string $value ): void {
		$m = new \ReflectionMethod( Validator::class, 'check_color_value' );
		$m->setAccessible( true );
		$m->invoke( $this, $sheet, $row, $value );
	}

	public function pub_read_headers( \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws ): array {
		$m = new \ReflectionMethod( Validator::class, 'read_headers' );
		$m->setAccessible( true );
		return $m->invoke( $this, $ws );
	}

	public function pub_build_report(): array {
		$m = new \ReflectionMethod( Validator::class, 'build_report' );
		$m->setAccessible( true );
		return $m->invoke( $this );
	}

	/** Read the private $file_type property. */
	public function get_file_type(): string|null {
		$p = new \ReflectionProperty( Validator::class, 'file_type' );
		$p->setAccessible( true );
		return $p->getValue( $this );
	}

	/** Read the private $issues property. */
	public function get_issues(): array {
		$p = new \ReflectionProperty( Validator::class, 'issues' );
		$p->setAccessible( true );
		return $p->getValue( $this );
	}
}

// ── Helper ────────────────────────────────────────────────────────────────────

/**
 * Build and write a Spreadsheet to a temp file; return the path.
 * The caller is responsible for unlinking it.
 */
function write_temp_xlsx( Spreadsheet $ss ): string {
	$path = sys_get_temp_dir() . '/d5dsh_validator_test_' . uniqid() . '.xlsx';
	( new XlsxWriter( $ss ) )->save( $path );
	return $path;
}

// ── Test class ────────────────────────────────────────────────────────────────

#[CoversClass( Validator::class )]
class ValidatorTest extends TestCase {

	/** @var list<string> Temp files to clean up after each test. */
	private array $temp_files = [];

	protected function tearDown(): void {
		foreach ( $this->temp_files as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
		$this->temp_files = [];
	}

	// ── Helper ───────────────────────────────────────────────────────────────

	private function make_validator( string $path = '/dev/null' ): TestableValidator {
		return new TestableValidator( $path );
	}

	private function temp( Spreadsheet $ss ): string {
		$path = write_temp_xlsx( $ss );
		$this->temp_files[] = $path;
		return $path;
	}

	// ── Constants ─────────────────────────────────────────────────────────────

	#[Test]
	public function severity_level_constants_are_correct(): void {
		$this->assertSame( 'fatal',   Validator::FATAL );
		$this->assertSame( 'error',   Validator::ERROR );
		$this->assertSame( 'warning', Validator::WARNING );
		$this->assertSame( 'info',    Validator::INFO );
	}

	#[Test]
	public function file_type_signatures_contains_all_known_types(): void {
		$sigs = Validator::FILE_TYPE_SIGNATURES;
		foreach ( [ 'vars', 'presets', 'layouts', 'pages', 'theme_customizer', 'builder_templates' ] as $type ) {
			$this->assertArrayHasKey( $type, $sigs, "Missing signature for type: {$type}" );
			$this->assertIsArray( $sigs[ $type ]['sheets'] );
			$this->assertIsArray( $sigs[ $type ]['require'] );
		}
	}

	#[Test]
	public function required_columns_covers_all_sheet_types(): void {
		$cols = Validator::REQUIRED_COLUMNS;
		foreach ( [ 'Colors', 'Numbers', 'Fonts', 'Images', 'Text', 'Links' ] as $sheet ) {
			$this->assertArrayHasKey( $sheet, $cols, "Missing required column entry for: {$sheet}" );
		}
		// All color-family sheets require at minimum ID and Label.
		foreach ( $cols as $sheet => $required ) {
			$this->assertContains( 'ID',    $required, "{$sheet} must require ID" );
			$this->assertContains( 'Label', $required, "{$sheet} must require Label" );
		}
	}

	#[Test]
	public function valid_statuses_contains_expected_values(): void {
		$this->assertContains( 'active',   Validator::VALID_STATUSES );
		$this->assertContains( 'archived', Validator::VALID_STATUSES );
		$this->assertContains( 'inactive', Validator::VALID_STATUSES );
		$this->assertCount( 3, Validator::VALID_STATUSES );
	}

	// ── add() ────────────────────────────────────────────────────────────────

	#[Test]
	public function add_stores_issues_correctly(): void {
		$v = $this->make_validator();
		$v->add( Validator::ERROR, 'Colors', 5, 'Value', 'Bad color' );
		$issues = $v->get_issues();
		$this->assertCount( 1, $issues );
		$this->assertSame( 'error',     $issues[0]['level'] );
		$this->assertSame( 'Colors',    $issues[0]['sheet'] );
		$this->assertSame( 5,           $issues[0]['row'] );
		$this->assertSame( 'Value',     $issues[0]['col'] );
		$this->assertSame( 'Bad color', $issues[0]['message'] );
	}

	#[Test]
	public function add_accepts_null_row_and_col(): void {
		$v = $this->make_validator();
		$v->add( Validator::INFO, '', null, null, 'General note' );
		$issues = $v->get_issues();
		$this->assertNull( $issues[0]['row'] );
		$this->assertNull( $issues[0]['col'] );
	}

	// ── build_report() ───────────────────────────────────────────────────────

	#[Test]
	public function build_report_counts_issues_by_level(): void {
		$v = $this->make_validator();
		$v->add( Validator::FATAL,   '', null, null, 'f1' );
		$v->add( Validator::ERROR,   '', null, null, 'e1' );
		$v->add( Validator::ERROR,   '', null, null, 'e2' );
		$v->add( Validator::WARNING, '', null, null, 'w1' );
		$v->add( Validator::INFO,    '', null, null, 'i1' );
		$v->add( Validator::INFO,    '', null, null, 'i2' );
		$v->add( Validator::INFO,    '', null, null, 'i3' );

		$report = $v->pub_build_report();
		$this->assertSame( 1, $report['counts']['fatal'] );
		$this->assertSame( 2, $report['counts']['error'] );
		$this->assertSame( 1, $report['counts']['warning'] );
		$this->assertSame( 3, $report['counts']['info'] );
	}

	#[Test]
	public function build_report_passed_is_false_when_fatal_present(): void {
		$v = $this->make_validator();
		$v->add( Validator::FATAL, '', null, null, 'oops' );
		$this->assertFalse( $v->pub_build_report()['passed'] );
	}

	#[Test]
	public function build_report_passed_is_false_when_only_errors_present(): void {
		$v = $this->make_validator();
		$v->add( Validator::ERROR, '', null, null, 'bad row' );
		$this->assertFalse( $v->pub_build_report()['passed'] );
	}

	#[Test]
	public function build_report_passed_is_true_with_only_warnings_and_info(): void {
		$v = $this->make_validator();
		$v->add( Validator::WARNING, '', null, null, 'heads up' );
		$v->add( Validator::INFO,    '', null, null, 'fyi' );
		$this->assertTrue( $v->pub_build_report()['passed'] );
	}

	#[Test]
	public function build_report_passed_is_true_with_no_issues(): void {
		$v = $this->make_validator();
		$this->assertTrue( $v->pub_build_report()['passed'] );
	}

	#[Test]
	public function build_report_includes_issues_array(): void {
		$v = $this->make_validator();
		$v->add( Validator::INFO, 'Colors', 1, null, 'note' );
		$report = $v->pub_build_report();
		$this->assertCount( 1, $report['issues'] );
		$this->assertSame( 'note', $report['issues'][0]['message'] );
	}

	// ── detect_file_type() ───────────────────────────────────────────────────

	#[Test]
	public function detect_file_type_identifies_vars_file(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ss->createSheet()->setTitle( 'Numbers' );
		$ss->createSheet()->setTitle( 'Fonts' );

		$v = $this->make_validator();
		$v->pub_detect_file_type( $ss );
		$this->assertSame( 'vars', $v->get_file_type() );
	}

	#[Test]
	public function detect_file_type_identifies_presets_file(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Presets – Modules' );
		$ss->createSheet()->setTitle( 'Presets – Groups' );

		$v = $this->make_validator();
		$v->pub_detect_file_type( $ss );
		$this->assertSame( 'presets', $v->get_file_type() );
	}

	#[Test]
	public function detect_file_type_identifies_layouts_file(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Layouts' );

		$v = $this->make_validator();
		$v->pub_detect_file_type( $ss );
		$this->assertSame( 'layouts', $v->get_file_type() );
	}

	#[Test]
	public function detect_file_type_returns_null_for_unrecognised_sheets(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Completely Unknown Sheet' );

		$v = $this->make_validator();
		$v->pub_detect_file_type( $ss );
		$this->assertNull( $v->get_file_type() );
	}

	#[Test]
	public function detect_file_type_picks_best_match_by_score(): void {
		// Give vars 3 matches and layouts 1 — vars should win.
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ss->createSheet()->setTitle( 'Numbers' );
		$ss->createSheet()->setTitle( 'Fonts' );
		$ss->createSheet()->setTitle( 'Layouts' ); // Would score 1 for 'layouts'

		$v = $this->make_validator();
		$v->pub_detect_file_type( $ss );
		$this->assertSame( 'vars', $v->get_file_type() );
	}

	#[Test]
	public function detect_file_type_does_not_overwrite_existing_type(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Layouts' );

		// Simulate file_type already set (e.g. from Config sheet).
		$v = $this->make_validator();
		$v->add( Validator::INFO, 'Config', null, null, 'File type: vars' );
		// Manually set file_type via reflection.
		$p = new \ReflectionProperty( Validator::class, 'file_type' );
		$p->setAccessible( true );
		$p->setValue( $v, 'vars' );

		$v->pub_detect_file_type( $ss );
		$this->assertSame( 'vars', $v->get_file_type(), 'detect_file_type should not overwrite an existing type' );
	}

	// ── check_color_value() ──────────────────────────────────────────────────

	/** @return array<string, array{string, bool}> */
	public static function color_value_provider(): array {
		return [
			// Valid hex
			'valid 3-digit hex'         => [ '#abc',       true  ],
			'valid 6-digit hex'         => [ '#1a2b3c',    true  ],
			'valid 8-digit hex (alpha)' => [ '#1a2b3c4d',  true  ],
			'valid hex uppercase'       => [ '#AABBCC',    true  ],
			// Valid rgb / rgba
			'valid rgb'                 => [ 'rgb(255,0,128)', true ],
			'valid rgba'                => [ 'rgba(0,0,0,0.5)', true ],
			'rgb with spaces'           => [ 'rgb( 10, 20, 30 )', true ],
			// Variable references — always valid (no issue added)
			'variable reference'        => [ '$variable(gcid-123)$', true ],
			// Invalid
			'invalid short hex'         => [ '#ab',        false ],
			'invalid no hash'           => [ 'abc',         false ],
			'invalid word'              => [ 'red',         false ],
			'invalid hsl'               => [ 'hsl(120,50%,50%)', false ],
			'invalid empty'             => [ '',            false ], // blank → WARNING, not pass
		];
	}

	#[Test]
	#[DataProvider( 'color_value_provider' )]
	public function check_color_value_adds_issue_for_invalid_colors( string $value, bool $should_pass ): void {
		$v = $this->make_validator();
		$v->pub_check_color_value( 'Colors', 2, $value );
		$issues = $v->get_issues();

		if ( $should_pass ) {
			// No ERROR should be present for valid values.
			$errors = array_filter( $issues, fn( $i ) => $i['level'] === Validator::ERROR );
			$this->assertEmpty( $errors, "Expected no error for color value: '{$value}'" );
		} else {
			// At least one ERROR or WARNING should be present.
			$problems = array_filter( $issues, fn( $i ) => in_array( $i['level'], [ Validator::ERROR, Validator::WARNING ], true ) );
			$this->assertNotEmpty( $problems, "Expected an issue for invalid color value: '{$value}'" );
		}
	}

	#[Test]
	public function check_color_value_blank_adds_warning_not_error(): void {
		$v = $this->make_validator();
		$v->pub_check_color_value( 'Colors', 5, '' );
		$issues = $v->get_issues();
		$this->assertCount( 1, $issues );
		$this->assertSame( Validator::WARNING, $issues[0]['level'] );
	}

	#[Test]
	public function check_color_value_invalid_adds_error(): void {
		$v = $this->make_validator();
		$v->pub_check_color_value( 'Colors', 3, 'not-a-color' );
		$issues = $v->get_issues();
		$this->assertCount( 1, $issues );
		$this->assertSame( Validator::ERROR, $issues[0]['level'] );
	}

	#[Test]
	public function check_color_value_variable_reference_adds_no_issue(): void {
		$v = $this->make_validator();
		$v->pub_check_color_value( 'Colors', 4, '$variable(gcid-abc123)$' );
		$this->assertEmpty( $v->get_issues() );
	}

	// ── read_headers() ───────────────────────────────────────────────────────

	#[Test]
	public function read_headers_returns_header_row_values(): void {
		$ss = new Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );

		$v       = $this->make_validator();
		$headers = $v->pub_read_headers( $ws );
		$this->assertSame( [ 'ID', 'Label', 'Value' ], $headers );
	}

	#[Test]
	public function read_headers_strips_trailing_empty_columns(): void {
		$ss = new Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', '' );    // trailing blank
		$ws->setCellValue( 'D1', '' );    // trailing blank

		$v       = $this->make_validator();
		$headers = $v->pub_read_headers( $ws );
		$this->assertSame( [ 'ID', 'Label' ], $headers );
	}

	#[Test]
	public function read_headers_returns_empty_for_blank_sheet(): void {
		$ss = new Spreadsheet();
		$ws = $ss->getActiveSheet();

		$v       = $this->make_validator();
		$headers = $v->pub_read_headers( $ws );
		$this->assertSame( [], $headers );
	}

	#[Test]
	public function read_headers_trims_whitespace(): void {
		$ss = new Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', '  ID  ' );
		$ws->setCellValue( 'B1', ' Label ' );

		$v       = $this->make_validator();
		$headers = $v->pub_read_headers( $ws );
		$this->assertSame( [ 'ID', 'Label' ], $headers );
	}

	// ── validate() integration pipeline ──────────────────────────────────────

	#[Test]
	public function validate_returns_fatal_for_unreadable_file(): void {
		$v      = new Validator( '/this/file/does/not/exist.xlsx' );
		$report = $v->validate();
		$this->assertSame( 1, $report['counts']['fatal'] );
		$this->assertFalse( $report['passed'] );
	}

	#[Test]
	public function validate_identifies_vars_file_and_passes_with_valid_data(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		// Write headers (row 1) + one valid data row (row 2).
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );
		$ws->setCellValue( 'D1', 'Status' );
		$ws->setCellValue( 'A2', 'gcid-abc123' );
		$ws->setCellValue( 'B2', 'Primary Color' );
		$ws->setCellValue( 'C2', '#1a2b3c' );
		$ws->setCellValue( 'D2', 'active' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertSame( 'vars', $report['file_type'] );
		$this->assertSame( 0, $report['counts']['fatal'] );
		$this->assertSame( 0, $report['counts']['error'] );
		$this->assertTrue( $report['passed'] );
	}

	#[Test]
	public function validate_reports_error_for_invalid_color_value(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );
		$ws->setCellValue( 'A2', 'gcid-001' );
		$ws->setCellValue( 'B2', 'My Color' );
		$ws->setCellValue( 'C2', 'not-a-color' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertGreaterThan( 0, $report['counts']['error'] );
		$this->assertFalse( $report['passed'] );
	}

	#[Test]
	public function validate_reports_warning_for_invalid_status(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );
		$ws->setCellValue( 'D1', 'Status' );
		$ws->setCellValue( 'A2', 'gcid-001' );
		$ws->setCellValue( 'B2', 'My Color' );
		$ws->setCellValue( 'C2', '#ffffff' );
		$ws->setCellValue( 'D2', 'INVALID_STATUS' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertGreaterThan( 0, $report['counts']['warning'] );
	}

	#[Test]
	public function validate_reports_error_for_blank_id(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );
		$ws->setCellValue( 'A2', '' );          // blank ID
		$ws->setCellValue( 'B2', 'My Color' );
		$ws->setCellValue( 'C2', '#000000' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertGreaterThan( 0, $report['counts']['error'] );
	}

	#[Test]
	public function validate_reports_warning_for_duplicate_id(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'Colors' );
		$ws = $ss->getActiveSheet();
		$ws->setCellValue( 'A1', 'ID' );
		$ws->setCellValue( 'B1', 'Label' );
		$ws->setCellValue( 'C1', 'Value' );
		$ws->setCellValue( 'A2', 'gcid-dup' );
		$ws->setCellValue( 'B2', 'Color A' );
		$ws->setCellValue( 'C2', '#ff0000' );
		$ws->setCellValue( 'A3', 'gcid-dup' );  // duplicate
		$ws->setCellValue( 'B3', 'Color B' );
		$ws->setCellValue( 'C3', '#00ff00' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertGreaterThan( 0, $report['counts']['warning'] );
	}

	#[Test]
	public function validate_reports_fatal_for_completely_unknown_file(): void {
		$ss = new Spreadsheet();
		$ss->getActiveSheet()->setTitle( 'RandomSheet' );

		$path   = $this->temp( $ss );
		$v      = new Validator( $path );
		$report = $v->validate();

		$this->assertGreaterThan( 0, $report['counts']['fatal'] );
		$this->assertFalse( $report['passed'] );
		$this->assertNull( $report['file_type'] );
	}
}
