<?php
/**
 * WP-CLI command: d5dsh security-test
 *
 * Runs a batch of JSON fixture files through the D5DSH importer, records the
 * results of each import, and restores the database to its pre-test state
 * between runs.  After all files are processed a structured JSON report is
 * written to disk.
 *
 * Usage
 * -----
 *   wp d5dsh security-test --dir=<path> [--out=<path>] [--verbose]
 *
 * Arguments
 * ---------
 *   --dir     Absolute or site-relative path to the directory containing the
 *             test JSON fixtures.  All *.json files in the directory are
 *             included; subdirectories are ignored.
 *   --out     Directory where the report JSON and per-test export files are
 *             written.  Defaults to <dir>/results/.
 *   --verbose Print per-item detail (every variable processed) in addition
 *             to the summary line.  Useful for debugging but produces a lot
 *             of output with large test files.
 *
 * How it works
 * ------------
 * 1. Before any test runs the command takes an in-memory snapshot of the two
 *    wp_options keys that hold Divi variable data:
 *      et_divi_global_variables          (non-color variables)
 *      et_divi[et_global_data][global_colors]  (user colors inside et_divi)
 *    The full et_divi option is snapshotted so the restore is lossless.
 *
 * 2. For each JSON file:
 *    a. The file is imported via SimpleImporter::import_json_file_direct().
 *    b. Any PHP exceptions or WP_Error values are caught and logged.
 *    c. The sanitization log is captured (fields modified by WordPress
 *       sanitization functions).
 *    d. The post-import state of all variables and colors is exported to
 *       a JSON file in the output directory.
 *    e. The pre-test snapshot is restored — both wp_options keys are reset
 *       to exactly what they were before step 2a.
 *
 * 3. A report JSON file is written containing:
 *    - run metadata (timestamp, WP version, plugin version, PHP version)
 *    - per-file results (status, counts, sanitization log, export file path)
 *    - a summary across all files
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Cli\SecurityTestRunner;

/**
 * Class SecurityTestCommand
 */
class SecurityTestCommand {

	// ── WP-CLI entry point ────────────────────────────────────────────────────

	/**
	 * Run a batch of JSON fixture files through the D5DSH importer and produce
	 * a structured test report.
	 *
	 * ## OPTIONS
	 *
	 * --dir=<path>
	 * : Directory containing the *.json fixture files to test.
	 *
	 * [--out=<path>]
	 * : Output directory for the report and per-test export files.
	 *   Default: <dir>/results/
	 *
	 * [--verbose]
	 * : Print per-item variable detail in addition to the summary line.
	 *
	 * ## EXAMPLES
	 *
	 *   wp d5dsh security-test --dir=/path/to/d5dsh-tests/
	 *   wp d5dsh security-test --dir=/path/to/d5dsh-tests/ --out=/tmp/results/ --verbose
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {

		// ── Resolve paths ─────────────────────────────────────────────────────
		$dir = $assoc_args['dir'] ?? '';
		if ( ! $dir ) {
			\WP_CLI::error( 'Please provide --dir=<path> pointing to the fixture directory.' );
		}

		if ( ! str_starts_with( $dir, '/' ) ) {
			$dir = ABSPATH . ltrim( $dir, '/' );
		}
		$dir = rtrim( realpath( $dir ) ?: $dir, '/' );

		if ( ! is_dir( $dir ) ) {
			\WP_CLI::error( "Directory not found: {$dir}" );
		}

		$out_dir = $assoc_args['out'] ?? ( $dir . '/results' );
		if ( ! str_starts_with( $out_dir, '/' ) ) {
			$out_dir = ABSPATH . ltrim( $out_dir, '/' );
		}
		$out_dir = rtrim( $out_dir, '/' );

		if ( ! is_dir( $out_dir ) && ! wp_mkdir_p( $out_dir ) ) {
			\WP_CLI::error( "Cannot create output directory: {$out_dir}" );
		}

		$verbose = isset( $assoc_args['verbose'] );

		// ── Collect fixture files ─────────────────────────────────────────────
		$files = array_values( array_filter(
			glob( $dir . '/*.json' ) ?: [],
			fn( $f ) => is_file( $f ) && ! str_starts_with( basename( $f ), '.' )
		) );
		sort( $files );

		if ( empty( $files ) ) {
			\WP_CLI::error( "No *.json files found in: {$dir}" );
		}

		\WP_CLI::log( sprintf( 'Found %d fixture file(s) in %s', count( $files ), $dir ) );
		\WP_CLI::log( sprintf( 'Output directory: %s', $out_dir ) );
		\WP_CLI::log( '' );

		// ── Build fixtures array and run via shared engine ────────────────────
		$fixtures = [];
		$bad_files = [];

		foreach ( $files as $file_path ) {
			$json = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				$bad_files[] = basename( $file_path );
				continue;
			}
			$fixtures[] = [ 'name' => basename( $file_path ), 'data' => $data ];
		}

		foreach ( $bad_files as $bad ) {
			\WP_CLI::warning( "Skipping (invalid JSON): {$bad}" );
		}

		if ( empty( $fixtures ) ) {
			\WP_CLI::error( 'No valid JSON fixture files to run.' );
		}

		$report = ( new SecurityTestRunner() )->run( $fixtures, $verbose );

		// ── Print per-result output ───────────────────────────────────────────
		$total = count( $report['results'] );
		foreach ( $report['results'] as $idx => $result ) {
			\WP_CLI::log( sprintf( '[%d/%d] %s', $idx + 1, $total, $result['file'] ) );

			$status_label = match ( $result['status'] ) {
				'ok'        => \WP_CLI::colorize( '%GPASS%n' ),
				'exception' => \WP_CLI::colorize( '%RFAIL%n' ),
				'json_error'=> \WP_CLI::colorize( '%RFAIL%n' ),
				'no_handler'=> \WP_CLI::colorize( '%YWARN%n' ),
				default     => \WP_CLI::colorize( '%YWARN%n' ),
			};
			\WP_CLI::log( sprintf(
				'  %s  type=%-12s  new=%-4d  updated=%-4d  sanitized=%-4d  %s',
				$status_label,
				$result['detected_type'] ?? '—',
				$result['new']           ?? 0,
				$result['updated']       ?? 0,
				count( $result['sanitization_log'] ?? [] ),
				$result['message'] ?? ''
			) );

			foreach ( $result['sanitization_log'] ?? [] as $entry ) {
				\WP_CLI::log( sprintf(
					'    SANITIZED  [%s] %s: %s → %s',
					$entry['context']   ?? '',
					$entry['field']     ?? '',
					mb_substr( $entry['original']  ?? '', 0, 80 ),
					mb_substr( $entry['sanitized'] ?? '', 0, 80 )
				) );
			}

			if ( $verbose ) {
				foreach ( $result['items'] ?? [] as $item ) {
					\WP_CLI::log( sprintf(
						'    %-10s  %-40s  %s',
						strtoupper( $item['status'] ?? '' ),
						$item['id'] ?? '',
						mb_substr( $item['value'] ?? '', 0, 60 )
					) );
				}
			}

			// Write post-import export if anything changed.
			if ( ( $result['new'] ?? 0 ) > 0 || ( $result['updated'] ?? 0 ) > 0 ) {
				$base        = pathinfo( $result['file'], PATHINFO_FILENAME );
				$export_path = $out_dir . '/d5dsh-export-' . $base . '.json';
				$this->export_current_state( $export_path );
			}

			\WP_CLI::log( '' );
		}

		// ── Write report JSON ─────────────────────────────────────────────────
		$report['meta']['fixture_dir'] = $dir;
		$report['meta']['output_dir']  = $out_dir;

		$report_name = 'security-test-report-' . gmdate( 'Ymd-His' ) . '.json';
		$report_path = $out_dir . '/' . $report_name;
		file_put_contents( $report_path, json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// ── Final summary ─────────────────────────────────────────────────────
		$sum = $report['summary'];
		\WP_CLI::log( str_repeat( '─', 60 ) );
		\WP_CLI::log( sprintf(
			'Done.  %d total  |  %d pass  |  %d fail  |  %d field(s) sanitized',
			$sum['total'], $sum['pass'], $sum['fail'], $sum['total_sanitized']
		) );
		\WP_CLI::log( 'Report: ' . $report_path );

		if ( $sum['fail'] > 0 ) {
			\WP_CLI::warning( "{$sum['fail']} test(s) failed. Check the report for details." );
		} else {
			\WP_CLI::success( 'All tests passed.' );
		}
	}

	// ── Export helper ─────────────────────────────────────────────────────────

	/**
	 * Export the current state of all variables and colors to a JSON file.
	 *
	 * Uses the same Divi-native envelope format as the test fixture files
	 * (context: et_builder) so the exports can be directly diff'd against
	 * the fixture that produced them.
	 *
	 * @param string $path Absolute path to write the export file.
	 */
	private function export_current_state( string $path ): void {
		$repo    = new VarsRepository();
		$raw     = $repo->get_raw();         // et_divi_global_variables (nested by type → id)
		$colors  = $repo->get_raw_colors();  // et_divi[et_global_data][global_colors] (dict keyed by gcid)

		// Convert raw vars to the Divi global_variables list format.
		$gv_list = [];
		foreach ( $raw as $var_type => $items ) {
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $id => $item ) {
				$gv_list[] = [
					'id'           => $item['id']    ?? (string) $id,
					'label'        => $item['label']  ?? '',
					'value'        => $item['value']  ?? '',
					'order'        => (string) ( $item['order'] ?? '' ),
					'status'       => $item['status'] ?? 'active',
					'lastUpdated'  => gmdate( 'c' ),
					'variableType' => $var_type,
					'type'         => $var_type,
				];
			}
		}

		// Convert raw colors dict to the Divi global_colors list format [ [id, payload], ... ]
		$gc_list = [];
		foreach ( $colors as $id => $entry ) {
			$gc_list[] = [
				$id,
				[
					'color'  => $entry['color']  ?? '',
					'status' => $entry['status'] ?? 'active',
					'label'  => $entry['label']  ?? '',
				],
			];
		}

		$envelope = [
			'context'          => 'et_builder',
			'data'             => [],
			'presets'          => [],
			'global_colors'    => $gc_list,
			'global_variables' => $gv_list,
			'canvases'         => [],
			'images'           => [],
			'thumbnails'       => [],
			'_d5dsh_export'    => [
				'exported_at'    => gmdate( 'c' ),
				'plugin_version' => defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : 'unknown',
				'source'         => 'security-test-command',
			],
		];

		file_put_contents( $path, json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
