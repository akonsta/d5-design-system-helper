<?php
/**
 * Excel importer for Divi 5 module and group presets.
 *
 * Reads Module Presets and Group Presets sheets and writes back to the
 * et_divi_builder_global_presets_d5 wp_options entry.
 *
 * ## Import rules
 *  - 'Is Default' (Yes/No) sets the default preset per module/group.
 *  - Attrs / Style Attrs columns are stored as JSON strings and decoded back.
 *  - Presets in the DB but absent from the xlsx are left untouched.
 *  - Presets in the xlsx but absent from the DB are added as new entries.
 *  - A SnapshotManager snapshot is taken BEFORE any write so the import can
 *    be reversed from the Snapshots tab.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Importers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\PresetsRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Class PresetsImporter
 */
class PresetsImporter {

	private PresetsRepository $repo;
	private string            $file_path;

	/**
	 * @param string $file_path Absolute path to the uploaded .xlsx file.
	 */
	public function __construct( string $file_path ) {
		$this->file_path = $file_path;
		$this->repo      = new PresetsRepository();
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Save a legacy backup of the current state (for manual phpMyAdmin restore).
	 *
	 * @return string Backup option key.
	 */
	public function backup_current(): string {
		return $this->repo->backup();
	}

	/**
	 * Parse the Excel file and return a diff without writing.
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
	 * Take a snapshot, then write the imported data to the database.
	 *
	 * @return array{updated: int, skipped: int, new: int, backup_option: string}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	public function commit(): array {
		\D5DesignSystemHelper\Admin\SimpleImporter::reset_sanitization_log();
		$raw = $this->repo->get_raw();

		// Snapshot current state before write so it can be undone.
		SnapshotManager::push( 'presets', $raw, 'import', basename( $this->file_path ) );

		$from_xlsx  = $this->parse_xlsx();
		$diff       = $this->compute_diff( $from_xlsx, $raw );
		$updated_db = $raw;
		$new_count  = 0;

		// Merge module presets.
		foreach ( $from_xlsx['module'] ?? [] as $module_name => $module_data ) {
			if ( ! isset( $updated_db['module'][ $module_name ] ) ) {
				$updated_db['module'][ $module_name ] = [ 'default' => '', 'items' => [] ];
			}
			foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
				$is_new = ! isset( $updated_db['module'][ $module_name ]['items'][ $preset_id ] );
				if ( $is_new ) {
					$new_count++;
				}
				$updated_db['module'][ $module_name ]['items'][ $preset_id ] = $preset;
			}
			if ( ! empty( $module_data['default'] ) ) {
				$updated_db['module'][ $module_name ]['default'] = $module_data['default'];
			}
		}

		// Merge group presets.
		foreach ( $from_xlsx['group'] ?? [] as $group_name => $group_data ) {
			if ( ! isset( $updated_db['group'][ $group_name ] ) ) {
				$updated_db['group'][ $group_name ] = [ 'default' => '', 'items' => [] ];
			}
			foreach ( $group_data['items'] ?? [] as $preset_id => $preset ) {
				$is_new = ! isset( $updated_db['group'][ $group_name ]['items'][ $preset_id ] );
				if ( $is_new ) {
					$new_count++;
				}
				$updated_db['group'][ $group_name ]['items'][ $preset_id ] = $preset;
			}
			if ( ! empty( $group_data['default'] ) ) {
				$updated_db['group'][ $group_name ]['default'] = $group_data['default'];
			}
		}

		$this->repo->save_raw( $updated_db );

		return [
			'updated'          => count( $diff['changes'] ),
			'skipped'          => 0,
			'new'              => $new_count,
			'backup_option'    => 'd5dsh_snap_presets_0',
			'sanitization_log' => \D5DesignSystemHelper\Admin\SimpleImporter::get_sanitization_log(),
		];
	}

	// ── Parser ────────────────────────────────────────────────────────────────

	/**
	 * Parse the Module Presets and Group Presets sheets.
	 *
	 * Returns a structure with 'module' and 'group' keys mirroring the DB format.
	 *
	 * @return array{module: array, group: array}
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function parse_xlsx(): array {
		$ss     = IOFactory::load( $this->file_path );
		$result = [ 'module' => [], 'group' => [] ];

		// ── Module Presets sheet (cols: Module|ID|Name|Version|IsDefault|Order|Attrs|StyleAttrs|GroupPresets) ──
		$ws_mod = $ss->getSheetByName( 'Module Presets' );
		if ( $ws_mod ) {
			for ( $row = 2; $row <= $ws_mod->getHighestDataRow(); $row++ ) {
				$module_name = trim( (string) $ws_mod->getCell( 'A' . $row )->getValue() );
				$preset_id   = trim( (string) $ws_mod->getCell( 'B' . $row )->getValue() );
				if ( ! $module_name || ! $preset_id ) {
					continue;
				}

				$is_default        = strtolower( trim( (string) $ws_mod->getCell( 'E' . $row )->getValue() ) ) === 'yes';
				$attrs_json        = trim( (string) $ws_mod->getCell( 'G' . $row )->getValue() );
				$style_attrs_json  = trim( (string) $ws_mod->getCell( 'H' . $row )->getValue() );
				$group_presets_json= trim( (string) $ws_mod->getCell( 'I' . $row )->getValue() );

				if ( ! isset( $result['module'][ $module_name ] ) ) {
					$result['module'][ $module_name ] = [ 'default' => '', 'items' => [] ];
				}

				$preset = [
					'id'         => $preset_id,
					'name'       => trim( (string) $ws_mod->getCell( 'C' . $row )->getValue() ),
					'moduleName' => $module_name,
					'version'    => trim( (string) $ws_mod->getCell( 'D' . $row )->getValue() ),
					'type'       => 'module',
					'created'    => 0,
					'updated'    => 0,
				];

				if ( $attrs_json ) {
					$preset['attrs'] = json_decode( $attrs_json, true ) ?? [];
				}
				if ( $style_attrs_json ) {
					$preset['styleAttrs'] = json_decode( $style_attrs_json, true ) ?? [];
				}
				if ( $group_presets_json ) {
					$preset['groupPresets'] = json_decode( $group_presets_json, true ) ?? [];
				}

				$preset = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_preset( $preset, 'Excel Element Preset ' . $preset_id . ' (' . $module_name . ')' );
				$result['module'][ $module_name ]['items'][ $preset_id ] = $preset;
				if ( $is_default ) {
					$result['module'][ $module_name ]['default'] = $preset_id;
				}
			}
		}

		// ── Group Presets sheet (cols: GroupName|ID|Name|Version|ModuleName|GroupID|IsDefault|Attrs|StyleAttrs) ──
		$ws_grp = $ss->getSheetByName( 'Group Presets' );
		if ( $ws_grp ) {
			for ( $row = 2; $row <= $ws_grp->getHighestDataRow(); $row++ ) {
				$group_name = trim( (string) $ws_grp->getCell( 'A' . $row )->getValue() );
				$preset_id  = trim( (string) $ws_grp->getCell( 'B' . $row )->getValue() );
				if ( ! $group_name || ! $preset_id ) {
					continue;
				}

				$is_default       = strtolower( trim( (string) $ws_grp->getCell( 'G' . $row )->getValue() ) ) === 'yes';
				$attrs_json       = trim( (string) $ws_grp->getCell( 'H' . $row )->getValue() );
				$style_attrs_json = trim( (string) $ws_grp->getCell( 'I' . $row )->getValue() );

				if ( ! isset( $result['group'][ $group_name ] ) ) {
					$result['group'][ $group_name ] = [ 'default' => '', 'items' => [] ];
				}

				$preset = [
					'id'         => $preset_id,
					'name'       => trim( (string) $ws_grp->getCell( 'C' . $row )->getValue() ),
					'version'    => trim( (string) $ws_grp->getCell( 'D' . $row )->getValue() ),
					'moduleName' => trim( (string) $ws_grp->getCell( 'E' . $row )->getValue() ),
					'groupId'    => trim( (string) $ws_grp->getCell( 'F' . $row )->getValue() ),
					'type'       => 'group',
					'created'    => 0,
					'updated'    => 0,
				];

				if ( $attrs_json ) {
					$preset['attrs'] = json_decode( $attrs_json, true ) ?? [];
				}
				if ( $style_attrs_json ) {
					$preset['styleAttrs'] = json_decode( $style_attrs_json, true ) ?? [];
				}

				$preset = \D5DesignSystemHelper\Admin\SimpleImporter::sanitize_preset( $preset, 'Excel Group Preset ' . $preset_id . ' (' . $group_name . ')' );
				$result['group'][ $group_name ]['items'][ $preset_id ] = $preset;
				if ( $is_default ) {
					$result['group'][ $group_name ]['default'] = $preset_id;
				}
			}
		}

		return $result;
	}

	// ── Diff engine ───────────────────────────────────────────────────────────

	/**
	 * Compare parsed xlsx presets against the current DB and return changes.
	 *
	 * @param array $from_xlsx Parsed records from the xlsx file.
	 * @param array $current   Raw data from the database.
	 * @return array{changes: array, new_entries: array, parse_errors: array}
	 */
	private function compute_diff( array $from_xlsx, array $current ): array {
		$changes = [];

		foreach ( $from_xlsx['module'] ?? [] as $module_name => $module_data ) {
			foreach ( $module_data['items'] ?? [] as $preset_id => $preset ) {
				$db = $current['module'][ $module_name ]['items'][ $preset_id ] ?? null;
				if ( ! $db ) {
					continue; // New entry — not a "change".
				}
				foreach ( [ 'name', 'version' ] as $field ) {
					if ( ( $db[ $field ] ?? '' ) !== ( $preset[ $field ] ?? '' ) ) {
						$changes[] = [
							'id'        => $preset_id,
							'label'     => $preset['name'] ?? $preset_id,
							'type'      => 'module_preset',
							'field'     => $field,
							'old_value' => (string) ( $db[ $field ] ?? '' ),
							'new_value' => (string) ( $preset[ $field ] ?? '' ),
						];
					}
				}
				// Compare attrs as JSON strings.
				$attrs_old = isset( $db['attrs'] )     ? wp_json_encode( $db['attrs'] )     : '';
				$attrs_new = isset( $preset['attrs'] ) ? wp_json_encode( $preset['attrs'] ) : '';
				if ( $attrs_old !== $attrs_new ) {
					$changes[] = [
						'id'        => $preset_id,
						'label'     => $preset['name'] ?? $preset_id,
						'type'      => 'module_preset',
						'field'     => 'attrs',
						'old_value' => $attrs_old,
						'new_value' => $attrs_new,
					];
				}
			}
		}

		return [ 'changes' => $changes, 'new_entries' => [], 'parse_errors' => [] ];
	}
}
