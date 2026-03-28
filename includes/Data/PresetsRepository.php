<?php
/**
 * Data access for Divi 5 module and group presets.
 *
 * Reads/writes the et_divi_builder_global_presets_d5 wp_options entry.
 *
 * ## DB format (PHP serialized, auto-deserialized by get_option)
 *
 *   [
 *     'module' => [
 *       'divi/button' => [
 *         'default' => 'preset-id',
 *         'items'   => [
 *           'preset-id' => [
 *             'id' => 'preset-id', 'name' => '...', 'moduleName' => 'divi/button',
 *             'version' => '5.0.0', 'type' => 'module',
 *             'created' => 1234567890, 'updated' => 1234567890,
 *             'attrs' => [...],       // optional
 *             'styleAttrs' => [...],  // optional
 *           ],
 *         ],
 *       ],
 *     ],
 *     'group' => [ ... same structure ... ],
 *   ]
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Data;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PresetsRepository
 */
class PresetsRepository {

	/** wp_options key for presets. */
	const OPTION_KEY = 'et_divi_builder_global_presets_d5';

	/** Prefix for legacy (non-snapshot) backup keys. */
	const BACKUP_KEY_PREFIX = 'd5dsh_backup_presets_';

	/**
	 * Return the raw presets array from wp_options.
	 *
	 * @return array{module: array, group: array}
	 */
	public function get_raw(): array {
		$raw = get_option( self::OPTION_KEY );
		return is_array( $raw ) ? $raw : [ 'module' => [], 'group' => [] ];
	}

	/**
	 * Write a new presets array back to wp_options.
	 *
	 * @param array $data The full presets structure.
	 * @return bool
	 */
	public function save_raw( array $data ): bool {
		return (bool) update_option( self::OPTION_KEY, $data );
	}

	/**
	 * Save a timestamped backup to wp_options (autoload=false).
	 *
	 * @return string The wp_options key under which the backup was stored.
	 */
	public function backup(): string {
		$key = self::BACKUP_KEY_PREFIX . gmdate( 'Ymd_His' );
		add_option( $key, $this->get_raw(), '', 'no' );
		return $key;
	}
}
