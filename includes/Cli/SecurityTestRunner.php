<?php
/**
 * Security test engine — shared by the WP-CLI command and the admin UI AJAX handler.
 *
 * Accepts an array of decoded JSON fixtures, imports each one through the
 * plugin's normal import path, captures the sanitization log and entry counts,
 * restores the database between each test, and returns a structured report.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Cli;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SimpleImporter;
use D5DesignSystemHelper\Data\VarsRepository;

/**
 * Class SecurityTestRunner
 */
class SecurityTestRunner {

	/**
	 * Run a batch of fixture files through the importer.
	 *
	 * @param array<int, array{name: string, data: array}> $fixtures
	 *        Each element: [ 'name' => 'filename.json', 'data' => decoded_json ]
	 * @param bool $verbose Include per-item variable detail in results.
	 * @return array Structured report.
	 */
	public function run( array $fixtures, bool $verbose = false ): array {
		global $wp_version;

		$repo    = new VarsRepository();
		$snap    = $this->snapshot( $repo );
		$results = [];

		foreach ( $fixtures as $fixture ) {
			$result = $this->run_one( $fixture['name'], $fixture['data'], $verbose );
			$results[] = $result;
			$this->restore( $repo, $snap );
		}

		$pass      = count( array_filter( $results, fn( $r ) => $r['status'] === 'ok' ) );
		$fail      = count( $results ) - $pass;
		$total_san = array_sum( array_map( fn( $r ) => count( $r['sanitization_log'] ?? [] ), $results ) );

		return [
			'meta' => [
				'run_at'         => gmdate( 'c' ),
				'wp_version'     => $wp_version,
				'php_version'    => PHP_VERSION,
				'plugin_version' => defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : 'unknown',
				'total_fixtures' => count( $fixtures ),
			],
			'summary' => [
				'total'           => count( $results ),
				'pass'            => $pass,
				'fail'            => $fail,
				'total_sanitized' => $total_san,
			],
			'results' => $results,
		];
	}

	// ── Per-fixture runner ────────────────────────────────────────────────────

	/**
	 * @param string $name    Display name (filename).
	 * @param array  $data    Decoded JSON payload.
	 * @param bool   $verbose Include per-item detail.
	 * @return array Result record.
	 */
	private function run_one( string $name, array $data, bool $verbose ): array {
		$result = [
			'file'             => $name,
			'status'           => 'ok',
			'detected_type'    => null,
			'new'              => 0,
			'updated'          => 0,
			'skipped'          => 0,
			'sanitization_log' => [],
			'items'            => [],
			'message'          => '',
		];

		try {
			$type = $this->detect_type( $data );
			$result['detected_type'] = $type;

			if ( ! $type ) {
				$result['status']  = 'no_handler';
				$result['message'] = 'Cannot detect data type.';
				return $result;
			}

			SimpleImporter::reset_sanitization_log();

			$import = ( new SimpleImporter() )->import_json_direct( $type, $data );

			$result['new']              = $import['new']     ?? 0;
			$result['updated']          = $import['updated'] ?? 0;
			$result['skipped']          = $import['skipped'] ?? 0;
			$result['sanitization_log'] = SimpleImporter::get_sanitization_log();
			$result['message']          = $import['message'] ?? '';
			$result['groups']           = $import['groups']  ?? [];

			if ( $verbose ) {
				$result['items'] = $import['items'] ?? [];
			}

			if ( ! ( $import['success'] ?? false ) ) {
				$result['status']  = 'fail';
				$result['message'] = $import['message'] ?? 'Import returned success=false.';
			}
		} catch ( \Throwable $e ) {
			$result['status']  = 'exception';
			$result['message'] = get_class( $e ) . ': ' . $e->getMessage();
		}

		return $result;
	}

	// ── Type detection ────────────────────────────────────────────────────────

	private function detect_type( array $data ): ?string {
		if ( isset( $data['context'] ) ) {
			return 'et_native';
		}
		$key_map = [
			'et_divi_global_variables'          => 'vars',
			'et_divi_builder_global_presets_d5' => 'presets',
			'theme_mods_Divi'                   => 'theme_customizer',
			'et_template'                       => 'builder_templates',
		];
		foreach ( $key_map as $key => $type ) {
			if ( isset( $data[ $key ] ) ) {
				return $type;
			}
		}
		if ( isset( $data['$schema'] ) && str_contains( (string) $data['$schema'], 'designtokens.org' ) ) {
			return 'dtcg';
		}
		if ( isset( $data['posts'] ) && is_array( $data['posts'] ) ) {
			return match ( $data['posts'][0]['post_type'] ?? '' ) {
				'et_pb_layout' => 'layouts',
				'page'         => 'pages',
				default        => 'layouts',
			};
		}
		return null;
	}

	// ── Snapshot / restore ────────────────────────────────────────────────────

	private function snapshot( VarsRepository $repo ): array {
		return [
			'vars'    => $repo->get_raw(),
			'et_divi' => get_option( VarsRepository::COLORS_OPTION_KEY, [] ),
		];
	}

	private function restore( VarsRepository $repo, array $snap ): void {
		$repo->save_raw( $snap['vars'] );
		update_option( VarsRepository::COLORS_OPTION_KEY, $snap['et_divi'] );
	}
}
