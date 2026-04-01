<?php
/**
 * Snapshot stack for undo support.
 *
 * Every export and every committed import creates a snapshot of the affected
 * data type's current database state BEFORE the change is written. Up to
 * MAX_SNAPSHOTS (10) are kept per type; the oldest is automatically purged
 * when the limit is reached.
 *
 * ## Storage layout in wp_options
 *
 *  d5dsh_snap_{type}_meta     JSON-encoded array of snapshot metadata records
 *                             (not autoloaded — only fetched on Snapshots tab)
 *  d5dsh_snap_{type}_{index}  The raw serialized data for snapshot index 0–9
 *                             (not autoloaded)
 *
 * ## Metadata record format
 *
 *  [
 *    'index'       => int,     // 0 = most recent
 *    'timestamp'   => string,  // ISO-8601 UTC
 *    'trigger'     => string,  // 'export' | 'import'
 *    'entry_count' => int,
 *    'description' => string,
 *  ]
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class SnapshotManager {

    /** Maximum snapshots retained per data type. */
    const MAX_SNAPSHOTS = 10;

    /** wp_options key prefix for snapshot data. */
    const DATA_PREFIX = 'd5dsh_snap_';

    /** wp_options key suffix for snapshot metadata. */
    const META_SUFFIX = '_meta';

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Push a new snapshot onto the stack for the given type.
     *
     * For 'vars' imports/exports this also automatically snapshots the companion
     * user-colors data (et_divi[et_global_data][global_colors]) under the synthetic
     * type key 'vars_colors', so both halves of the design system can be restored
     * together if an import corrupts either option.
     *
     * @param string $type        Data type key: 'vars', 'presets', 'layouts',
     *                            'pages', 'theme_customizer', 'builder_templates'.
     * @param array  $data        The raw DB data to snapshot.
     * @param string $trigger     'export' or 'import'.
     * @param string $description Human-readable description (e.g. filename).
     */
    public static function push(
        string $type,
        array  $data,
        string $trigger,
        string $description = ''
    ): void {
        // Automatically co-snapshot user colors whenever vars are snapshotted.
        if ( $type === 'vars' ) {
            $repo         = new \D5DesignSystemHelper\Data\VarsRepository();
            $colors_data  = $repo->get_raw_colors();
            self::push( 'vars_colors', $colors_data, $trigger, $description . ' [colors]' );
        }
        $meta = self::get_meta( $type );

        // Shift all existing indices up by 1 (newest will be index 0).
        // Delete any that exceed MAX_SNAPSHOTS - 1.
        $new_meta = [];
        foreach ( $meta as $entry ) {
            $new_index = $entry['index'] + 1;
            if ( $new_index >= self::MAX_SNAPSHOTS ) {
                // Purge the data for this overflow snapshot.
                delete_option( self::data_key( $type, $entry['index'] ) );
            } else {
                // Rename the data option to the new index.
                $old_data = get_option( self::data_key( $type, $entry['index'] ), [] );
                update_option( self::data_key( $type, $new_index ), $old_data, false );
                delete_option( self::data_key( $type, $entry['index'] ) );
                $entry['index'] = $new_index;
                $new_meta[] = $entry;
            }
        }

        // Store the new snapshot at index 0.
        $entry_count = self::count_entries( $type, $data );
        array_unshift( $new_meta, [
            'index'       => 0,
            'timestamp'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
            'trigger'     => $trigger,
            'entry_count' => $entry_count,
            'description' => $description,
        ] );

        update_option( self::data_key( $type, 0 ), $data, false );
        update_option( self::meta_key( $type ), wp_json_encode( $new_meta ), false );
    }

    /**
     * Return the metadata array for the given type (newest first).
     *
     * @param  string $type
     * @return array<int, array<string, mixed>>
     */
    public static function list_snapshots( string $type ): array {
        return self::get_meta( $type );
    }

    /**
     * Restore the snapshot at $index as the live data for $type.
     *
     * This calls the appropriate Repository::save_raw() to write the data
     * back to the canonical wp_options key.
     *
     * @param  string $type
     * @param  int    $index  0 = most recent snapshot.
     * @return bool   True on success.
     */
    public static function restore( string $type, int $index ): bool {
        $data = get_option( self::data_key( $type, $index ), null );
        if ( $data === null || ! is_array( $data ) ) {
            return false;
        }
        return self::write_to_db( $type, $data );
    }

    /**
     * Delete a single snapshot.
     *
     * @param string $type
     * @param int    $index
     * @return bool
     */
    public static function delete_snapshot( string $type, int $index ): bool {
        $meta = self::get_meta( $type );
        $new_meta = array_filter( $meta, fn( $e ) => $e['index'] !== $index );
        delete_option( self::data_key( $type, $index ) );
        update_option( self::meta_key( $type ), wp_json_encode( array_values( $new_meta ) ), false );
        return true;
    }

    /**
     * Delete all snapshots for a type.
     *
     * @param string $type
     */
    public static function purge( string $type ): void {
        $meta = self::get_meta( $type );
        foreach ( $meta as $entry ) {
            delete_option( self::data_key( $type, $entry['index'] ) );
        }
        delete_option( self::meta_key( $type ) );
    }

    /**
     * Return a list of all types that have at least one snapshot.
     *
     * @return string[]
     */
    public static function types_with_snapshots(): array {
        global $wpdb;
        $prefix  = $wpdb->esc_like( self::DATA_PREFIX ) . '%' . $wpdb->esc_like( self::META_SUFFIX );
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        $types = [];
        foreach ( (array) $results as $key ) {
            // key format: d5dsh_snap_{type}_meta
            $inner = substr( $key, strlen( self::DATA_PREFIX ) );
            $inner = substr( $inner, 0, - strlen( self::META_SUFFIX ) );
            if ( $inner ) {
                $types[] = $inner;
            }
        }
        return $types;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private static function meta_key( string $type ): string {
        return self::DATA_PREFIX . $type . self::META_SUFFIX;
    }

    private static function data_key( string $type, int $index ): string {
        return self::DATA_PREFIX . $type . '_' . $index;
    }

    /**
     * Decode and return the metadata array (empty array if not yet set).
     */
    private static function get_meta( string $type ): array {
        $raw = get_option( self::meta_key( $type ), '[]' );
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * Count meaningful entries in the raw data (type-specific heuristic).
     */
    private static function count_entries( string $type, array $data ): int {
        return match ( $type ) {
            'vars_colors'       => count( $data ),
            'vars'              => array_sum( array_map( fn( $v ) => is_array( $v ) ? count( $v ) : 0, $data ) ),
            'presets'           => array_sum( array_map(
                fn( $g ) => array_sum( array_map( fn( $m ) => count( $m['items'] ?? [] ), $g ) ),
                $data
            ) ),
            'layouts', 'pages'          => count( $data ),
            'builder_templates'         => count( $data['templates'] ?? $data ),
            'theme_customizer'          => count( $data ),
            default                     => count( $data ),
        };
    }

    /**
     * Write $data back to the live wp_options key for the given type.
     * Uses each type's Repository class.
     */
    private static function write_to_db( string $type, array $data ): bool {
        return match ( $type ) {
            'vars'        => ( new \D5DesignSystemHelper\Data\VarsRepository() )->save_raw( $data ),
            'vars_colors' => ( new \D5DesignSystemHelper\Data\VarsRepository() )->save_raw_colors( $data ),
            'presets'     => ( new \D5DesignSystemHelper\Data\PresetsRepository() )->save_raw( $data ),
            'theme_customizer' => ( new \D5DesignSystemHelper\Data\ThemeCustomizerRepository() )->save_raw( $data ),
            'layouts', 'pages' => ( new \D5DesignSystemHelper\Data\LayoutsRepository() )->restore_posts( $type, $data ),
            'builder_templates' => ( new \D5DesignSystemHelper\Data\BuilderTemplatesRepository() )->restore_templates( $data ),
            default => false,
        };
    }
}
