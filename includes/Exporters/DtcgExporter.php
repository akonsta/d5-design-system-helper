<?php
/**
 * Exports Divi 5 global variables in W3C Design Tokens Community Group (DTCG)
 * format (design-tokens.json).
 *
 * Specification: https://tr.designtokens.org/format/ (version 2025.10)
 *
 * ## Output format
 *
 *   {
 *     "$schema": "https://tr.designtokens.org/format/",
 *     "color": {
 *       "gcid-xxx": { "$type": "color", "$value": "#hex", "$description": "label", "extensions": {...} }
 *     },
 *     "dimension": { ... },
 *     "number":    { ... },
 *     "fontFamily":{ ... },
 *     "string":    { ... },
 *     "_meta":     { "exported_by": "...", "dtcg_schema": "2025.10", ... }
 *   }
 *
 * ## Type mapping
 *
 *   Divi type  → DTCG $type
 *   ----------   -----------
 *   colors     → color
 *   numbers    → dimension  (when value has a CSS unit)
 *              → number     (bare numeric value)
 *   fonts      → fontFamily
 *   strings    → string     (unofficial but widely used by tools)
 *   images     → (omitted — no DTCG equivalent)
 *   links      → (omitted — no DTCG equivalent)
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

use D5DesignSystemHelper\Data\VarsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DtcgExporter
 */
class DtcgExporter {

	/** DTCG specification schema URL. */
	private const DTCG_SCHEMA = 'https://tr.designtokens.org/format/';

	/** DTCG spec version label stored in _meta. */
	private const DTCG_VERSION = '2025.10';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Build the DTCG data, serialise to JSON, and stream to the browser.
	 *
	 * Calls exit() — must not be used in unit tests directly.
	 */
	public function stream_download(): never {
		$data     = $this->build_export_data();
		$json     = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$filename = 'design-tokens-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'Cache-Control: no-store' );
		echo $json;
		exit;
	}

	/**
	 * Build and return the full DTCG export data as a PHP array.
	 *
	 * Safe to call from unit tests.
	 *
	 * @return array
	 */
	public function build_export_data(): array {
		$vars_repo  = new VarsRepository();
		$all_vars   = $vars_repo->get_all();

		// Build a quick lookup of color ID → raw value for reference resolution.
		$color_lookup = [];
		foreach ( $all_vars as $var ) {
			if ( $var['type'] === 'colors' ) {
				$color_lookup[ $var['id'] ] = $var['value'];
			}
		}

		$groups = $this->build_token_groups( $all_vars, $color_lookup );

		$output = [ '$schema' => self::DTCG_SCHEMA ];

		foreach ( $groups as $group_key => $tokens ) {
			$output[ $group_key ] = $tokens;
		}

		$output['_meta'] = $this->meta_block();

		return $output;
	}

	/**
	 * Save DTCG export to a temp file and return the path.
	 *
	 * Used by zip bundling routines (mirrors JsonExporter::save_to_temp()).
	 *
	 * @return string Temp file path.
	 */
	public function save_to_temp(): string {
		$data = $this->build_export_data();
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$path = tempnam( sys_get_temp_dir(), 'd5dsh_dtcg_' );
		file_put_contents( $path, $json );
		return $path;
	}

	// ── Private builders ──────────────────────────────────────────────────────

	/**
	 * Group normalised variable records into DTCG token groups.
	 *
	 * @param array $vars         Normalised variable records from VarsRepository::get_all().
	 * @param array $color_lookup Map of color ID → raw value for reference resolution.
	 * @return array<string, array> Associative array of group key → token entries.
	 */
	private function build_token_groups( array $vars, array $color_lookup ): array {
		$groups = [];

		foreach ( $vars as $var ) {
			$dtcg_type = $this->map_type( $var );
			if ( $dtcg_type === null ) {
				continue; // images, links — no DTCG equivalent.
			}

			$value = $var['value'];

			if ( $dtcg_type === 'color' ) {
				$value = $this->resolve_color_value( $value, $color_lookup );
			}

			$groups[ $dtcg_type ][ $var['id'] ] = [
				'$type'        => $dtcg_type,
				'$value'       => $value,
				'$description' => $var['label'],
				'extensions'   => [
					'd5dsh:id'     => $var['id'],
					'd5dsh:status' => $var['status'],
					'd5dsh:system' => (bool) $var['system'],
				],
			];
		}

		return $groups;
	}

	/**
	 * Map a normalised variable record to a DTCG $type string.
	 *
	 * Returns null for types that have no DTCG equivalent (images, links).
	 *
	 * @param array $var Normalised variable record.
	 * @return string|null DTCG type string or null to skip.
	 */
	private function map_type( array $var ): ?string {
		return match ( $var['type'] ) {
			'colors'  => 'color',
			'numbers' => $this->is_dimension( $var['value'] ) ? 'dimension' : 'number',
			'fonts'   => 'fontFamily',
			'strings' => 'string',
			default   => null, // images, links
		};
	}

	/**
	 * Resolve a color value, following one level of $variable()$ aliasing.
	 *
	 * If the value is a $variable()$ reference, looks up the target color's
	 * raw value in the lookup table. If the reference cannot be resolved,
	 * returns the original value unchanged.
	 *
	 * @param string $value       The raw color value from the variable record.
	 * @param array  $color_lookup Map of color ID → raw value.
	 * @return string Resolved color value.
	 */
	private function resolve_color_value( string $value, array $color_lookup ): string {
		if ( ! str_starts_with( $value, '$variable(' ) ) {
			return $value;
		}

		// Extract the JSON payload from $variable({...})$.
		if ( ! preg_match( '/\$variable\((\{[^)]+\})\)\$/', $value, $m ) ) {
			return $value;
		}

		$json    = str_replace( [ '\\u0022', '\\"' ], '"', $m[1] );
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return $value;
		}

		$target_id = $decoded['value']['name'] ?? '';

		return isset( $color_lookup[ $target_id ] ) ? $color_lookup[ $target_id ] : $value;
	}

	/**
	 * Determine whether a number value string represents a CSS dimension.
	 *
	 * A dimension has a recognised CSS unit attached (px, rem, em, %, vh, vw, etc.).
	 * Bare numbers (e.g. "1.5", "42") are mapped to DTCG `number` instead.
	 *
	 * @param string $value The variable value string.
	 * @return bool True if the value ends with a CSS unit.
	 */
	private function is_dimension( string $value ): bool {
		return (bool) preg_match( '/\d(\s*)(px|rem|em|%|vh|vw|vmin|vmax|pt|cm|mm|in|ex|ch)$/i', $value );
	}

	/**
	 * Build the _meta block for the DTCG export.
	 *
	 * @return array
	 */
	private function meta_block(): array {
		return [
			'exported_by'  => 'D5 Design System Helper',
			'version'      => defined( 'D5DSH_VERSION' ) ? D5DSH_VERSION : '',
			'exported_at'  => gmdate( 'c' ),
			'site_url'     => function_exists( 'get_site_url' ) ? get_site_url() : '',
			'dtcg_schema'  => self::DTCG_VERSION,
		];
	}
}
