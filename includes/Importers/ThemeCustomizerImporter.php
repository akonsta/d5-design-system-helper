<?php
/**
 * Excel importer for Divi 5 Theme Customizer settings.
 *
 * Reads the Settings sheet and writes back to theme_mods_Divi.
 *
 * ## Import rules
 *  - Each row's Value column is JSON-decoded; if decoding fails it is treated
 *    as a plain string.
 *  - Keys in the DB but absent from the xlsx are left untouched.
 *  - A SnapshotManager snapshot is taken BEFORE any write.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Importers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\ThemeCustomizerRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class ThemeCustomizerImporter
 */
class ThemeCustomizerImporter {

	private ThemeCustomizerRepository $repo;
	private string                    $file_path;

	/**
	 * @param string $file_path Absolute path to the uploaded .xlsx file.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
		$this->repo      = new ThemeCustomizerRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Save a legacy backup.
	 *
	 * @return string Backup option key.
	 */
	public function backup_current(): string {
		return $this->repo->backup();
	}

	/**
	 * Parse and return a diff without writing.
	 *
	 * @return array{changes: array, new_entries: array, parse_errors: array}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function dry_run(): array {
		$from_xlsx = $this->parse_xlsx();
		$current   = $this->repo->get_raw();
		return $this->compute_diff( $from_xlsx, $current );
	}

	/**
	 * Snapshot then commit.
	 *
	 * @return array{updated: int, skipped: int, new: int, backup_option: string}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function commit(): array {
		\D5DesignSystemHelper\Admin\SimpleImporter::reset_sanitization_log();
		$raw = $this->repo->get_raw();
		SnapshotManager::push( 'theme_customizer', $raw, 'import', basename( $this->file_path ) );

		$from_xlsx  = $this->parse_xlsx();
		$diff       = $this->compute_diff( $from_xlsx, $raw );
		$updated_db = $raw;
		$new_count  = 0;

		foreach ( $from_xlsx as $key => $value ) {
			$ctx    = 'Excel Theme Customizer "' . $key . '"';
			$key    = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_and_log( $key, $ctx, 'key', 'key' );
			$value  = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_deep( $value, $ctx );
			$is_new = ! array_key_exists( $key, $updated_db );
			if ( $is_new ) {
				$new_count++;
			}
			$updated_db[ $key ] = $value;
		}

		$this->repo->save_raw( $updated_db );

		return [
			'updated'          => count( $diff['changes'] ),
			'skipped'          => count( $from_xlsx ) - count( $diff['changes'] ) - $new_count,
			'new'              => $new_count,
			'backup_option'    => 'd5dsh_snap_theme_customizer_0',
			'sanitization_log' => \D5DesignSystemHelper\Admin\SimpleImporter::get_sanitization_log(),
		];
	}

	// ── Parser ────────────────────────────────────────────────────────────────

	/**
	 * Parse the Settings sheet.
	 *
	 * Returns a flat key→value array (values JSON-decoded where applicable).
	 *
	 * @return array<string, mixed>
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function parse_xlsx(): array {
		$ss  = IOFactory::load( $this->file_path );
		$ws  = $ss->getSheetByName( 'Settings' );
		$out = [];

		if ( ! $ws ) {
			return $out;
		}

		for ( $row = 2; $row <= $ws->getHighestDataRow(); $row++ ) {
			// Col A = Category (informational, not stored), Col B = Key, Col C = Value
			$key   = trim( (string) $ws->getCell( 'B' . $row )->getValue() );
			$value = trim( (string) $ws->getCell( 'C' . $row )->getValue() );

			if ( ! $key ) {
				continue;
			}

			// Attempt JSON decode for complex values.
			if ( $value !== '' ) {
				$decoded = json_decode( $value, true );
				if ( json_last_error() === JSON_ERROR_NONE && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
					$out[ $key ] = $decoded;
					continue;
				}
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	// ── Diff engine ───────────────────────────────────────────────────────────

	/**
	 * Compare parsed xlsx settings against the current DB.
	 *
	 * @param array $from_xlsx Key→value pairs from xlsx.
	 * @param array $current   Current raw theme_mods_Divi value.
	 * @return array{changes: array, new_entries: array, parse_errors: array}
	 */
	private function compute_diff( array $from_xlsx, array $current ): array {
		$changes     = [];
		$new_entries = [];

		foreach ( $from_xlsx as $key => $value ) {
			if ( ! array_key_exists( $key, $current ) ) {
				$new_entries[] = [ 'key' => $key, 'value' => $value ];
				continue;
			}

			// Serialize both sides for comparison.
			$old_str = is_scalar( $current[ $key ] ) ? (string) $current[ $key ] : wp_json_encode( $current[ $key ] );
			$new_str = is_scalar( $value )            ? (string) $value            : wp_json_encode( $value );

			if ( $old_str !== $new_str ) {
				$changes[] = [
					'id'        => $key,
					'label'     => $key,
					'type'      => 'theme_customizer',
					'field'     => 'value',
					'old_value' => $old_str,
					'new_value' => $new_str,
				];
			}
		}

		return [ 'changes' => $changes, 'new_entries' => $new_entries, 'parse_errors' => [] ];
	}
}
