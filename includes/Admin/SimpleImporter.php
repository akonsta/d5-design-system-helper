<?php
/**
 * Simple Import — AJAX backend for importing .json, .xlsx, and .zip files.
 *
 * ## Endpoints
 *
 *   d5dsh_simple_analyze  — analyse an uploaded file and return a manifest
 *   d5dsh_simple_execute  — execute import for selected files from a zip, or
 *                           a single json/xlsx file
 *
 * ## Supported file types
 *
 *   .json  — detected by top-level envelope key (et_divi_global_variables,
 *             et_divi_builder_global_presets_d5, posts, theme_mods_Divi,
 *             et_template) or by _meta.type field.
 *             DTCG format (W3C Design Tokens Community Group) is also accepted:
 *             detected by $schema containing "designtokens.org" or by the
 *             presence of top-level token groups (color/dimension/number/
 *             fontFamily/string) with $value entries.
 *             Import is additive: updates matching records, adds new ones, never
 *             deletes. Snapshots current data before writing.
 *
 *   .xlsx  — type detected from Config sheet cell B5 (existing logic).
 *             Runs dry_run() first; results returned for frontend confirmation.
 *             On confirm, runs commit().
 *
 *   .zip   — extracts to a temp directory, analyses each file inside, returns
 *             a manifest. Manifest is cached in a transient (10-min TTL).
 *             On execute, imports selected files in dependency order:
 *               vars → presets → layouts → pages → theme_customizer → builder_templates
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\LayoutsRepository;
use D5DesignSystemHelper\Data\ThemeCustomizerRepository;
use D5DesignSystemHelper\Data\BuilderTemplatesRepository;
use D5DesignSystemHelper\Importers\VarsImporter;
use D5DesignSystemHelper\Importers\PresetsImporter;
use D5DesignSystemHelper\Importers\LayoutsImporter;
use D5DesignSystemHelper\Importers\ThemeCustomizerImporter;
use D5DesignSystemHelper\Importers\BuilderTemplatesImporter;
use D5DesignSystemHelper\Exporters\VarsExporter;
use D5DesignSystemHelper\Exporters\PresetsExporter;
use D5DesignSystemHelper\Util\DiviBlocParser;
use D5DesignSystemHelper\Util\ExportUtil;
use D5DesignSystemHelper\Util\DebugLogger;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Class SimpleImporter
 */
class SimpleImporter {

	/** Nonce action. */
	const NONCE_ACTION = 'd5dsh_simple_import';

	/** Required capability. */
	const CAPABILITY = 'manage_options';

	/** Transient TTL for zip manifests (10 minutes). */
	const ZIP_TRANSIENT_TTL = 10 * MINUTE_IN_SECONDS;

	/** Import order — files are imported in this sequence. */
	const IMPORT_ORDER = [
		'vars',
		'presets',
		'layouts',
		'pages',
		'theme_customizer',
		'builder_templates',
	];

	/**
	 * Divi-internal variable IDs that are built into the theme and never appear
	 * in exports. References to these IDs are normal and should not be flagged
	 * as errors — show them as informational only.
	 *
	 * This list is intentionally small. Additional IDs can be discovered by
	 * inspecting a fresh Divi 5 install before any user content is added.
	 */
	const DIVI_BUILTIN_IDS = [
		'gvid-r41n4b9xo4', // Internal default spacing/layout variable (referenced 104x in ET sample presets)
	];

	/**
	 * Base display names for each internal type key.
	 * Used as fallbacks and for import result messages.
	 * Type badge labels are built by resolve_type_label() instead.
	 */
	const TYPE_LABELS = [
		'vars'              => 'Global Variables',
		'presets'           => 'Element Presets',
		'layouts'           => 'Layouts',
		'pages'             => 'Pages',
		'theme_customizer'  => 'Theme Customizer',
		'builder_templates' => 'Builder Templates',
		'et_native'         => 'Layouts',   // resolved further by resolve_type_label()
		'dtcg'              => 'Design Tokens (DTCG)',
	];

	/**
	 * ET native context strings and what data they primarily carry.
	 * All ET files contain global_variables + global_colors + presets.
	 * The context tells us what extra data is in the 'data' key.
	 */
	const ET_CONTEXTS = [
		'et_builder'          => 'layouts',       // data = layout posts
		'et_builder_layouts'  => 'layouts',
		'et_builder_pages'    => 'pages',
		'et_divi_mods'        => 'theme_customizer', // data = theme mods
		'et_template'         => 'builder_templates',
	];

	// ── Path Security ─────────────────────────────────────────────────────────

	/**
	 * Validate that a file path is safely within a base directory.
	 *
	 * Prevents path traversal attacks by verifying the canonicalized path
	 * is still within the expected base directory.
	 *
	 * @param string $path     The path to validate.
	 * @param string $base_dir The base directory the path must be within.
	 * @return string|false    The canonicalized path if safe, false otherwise.
	 */
	public static function validate_path_within( string $path, string $base_dir ) {
		$real_path = realpath( $path );
		$real_base = realpath( $base_dir );

		if ( ! $real_path || ! $real_base ) {
			return false;
		}

		// Ensure the path starts with the base directory + separator.
		if ( ! str_starts_with( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		return $real_path;
	}

	// ── Sanitization helpers ──────────────────────────────────────────────────

	/**
	 * Accumulated sanitization log entries.
	 *
	 * Each entry: [ 'context' => string, 'field' => string, 'original' => string, 'sanitized' => string ]
	 *
	 * @var array
	 */
	private static array $sanitization_log = [];

	/**
	 * Reset the sanitization log (call before each import operation).
	 */
	public static function reset_sanitization_log(): void {
		self::$sanitization_log = [];
	}

	/**
	 * Get the accumulated sanitization log.
	 *
	 * @return array
	 */
	public static function get_sanitization_log(): array {
		return self::$sanitization_log;
	}

	/**
	 * Sanitize a string and log if the value changed.
	 *
	 * @param string $value   Raw value.
	 * @param string $context Human-readable context (e.g. "Variable gcid-abc123").
	 * @param string $field   Field name (e.g. "label", "value", "post_content").
	 * @param string $method  Sanitization method: 'text' (sanitize_text_field),
	 *                        'key' (sanitize_key), 'kses' (wp_kses_post).
	 * @return string Sanitized value.
	 */
	public static function sanitize_and_log( string $value, string $context, string $field, string $method = 'text' ): string {
		$sanitized = match ( $method ) {
			'key'   => sanitize_key( $value ),
			'kses'  => wp_kses_post( $value ),
			'title' => sanitize_title( $value ),
			default => sanitize_text_field( $value ),
		};
		if ( $sanitized !== $value ) {
			self::$sanitization_log[] = [
				'context'   => $context,
				'field'     => $field,
				'original'  => mb_substr( $value, 0, 200 ),
				'sanitized' => mb_substr( $sanitized, 0, 200 ),
			];
		}
		return $sanitized;
	}

	/**
	 * Recursively sanitize all string leaf values in a nested array.
	 *
	 * Used for preset attrs/styleAttrs and other JSON-decoded structures
	 * that may contain arbitrary nested data. Strips HTML tags and encodes
	 * special characters in every string value while preserving array structure,
	 * booleans, integers, and floats.
	 *
	 * @param mixed  $data    The data to sanitize.
	 * @param string $context Optional context for logging.
	 * @param string $path    Internal — tracks the current key path for log entries.
	 * @return mixed Sanitized data with the same structure.
	 */
	public static function sanitize_deep( mixed $data, string $context = '', string $path = '' ): mixed {
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $k => $v ) {
				$child_path = $path ? $path . '.' . $k : (string) $k;
				$out[ $k ]  = self::sanitize_deep( $v, $context, $child_path );
			}
			return $out;
		}
		if ( is_string( $data ) ) {
			if ( $context ) {
				return self::sanitize_and_log( $data, $context, $path ?: 'value' );
			}
			return sanitize_text_field( $data );
		}
		// Booleans, integers, floats, null — pass through unchanged.
		return $data;
	}

	/**
	 * Sanitize a post_meta value for safe storage.
	 *
	 * Scalar strings are passed through sanitize_text_field(). Arrays are
	 * recursively sanitized. Other types pass through unchanged.
	 *
	 * @param mixed  $value   The meta value.
	 * @param string $context Optional context for logging.
	 * @return mixed Sanitized value.
	 */
	public static function sanitize_meta_value( mixed $value, string $context = '' ): mixed {
		if ( is_array( $value ) ) {
			return self::sanitize_deep( $value, $context );
		}
		if ( is_string( $value ) ) {
			if ( $context ) {
				return self::sanitize_and_log( $value, $context, 'meta_value' );
			}
			return sanitize_text_field( $value );
		}
		return $value;
	}

	/**
	 * Sanitize a preset object before storage.
	 *
	 * Applies sanitize_text_field to scalar top-level fields (name, label, id,
	 * moduleName, version, type) and sanitize_deep to nested structures
	 * (attrs, styleAttrs, groupPresets).
	 *
	 * @param array  $preset  Raw preset data from JSON.
	 * @param string $context Optional context for logging (e.g. "Element Preset xyz").
	 * @return array Sanitized preset.
	 */
	public static function sanitize_preset( array $preset, string $context = '' ): array {
		$ctx = $context ?: ( 'Preset ' . ( $preset['name'] ?? $preset['id'] ?? '?' ) );
		$scalar_keys = [ 'id', 'name', 'label', 'moduleName', 'version', 'type', 'groupName', 'groupID' ];
		foreach ( $scalar_keys as $k ) {
			if ( isset( $preset[ $k ] ) && is_string( $preset[ $k ] ) ) {
				$preset[ $k ] = self::sanitize_and_log( $preset[ $k ], $ctx, $k );
			}
		}
		$deep_keys = [ 'attrs', 'styleAttrs', 'groupPresets' ];
		foreach ( $deep_keys as $k ) {
			if ( isset( $preset[ $k ] ) && is_array( $preset[ $k ] ) ) {
				$preset[ $k ] = self::sanitize_deep( $preset[ $k ], $ctx, $k );
			}
		}
		return $preset;
	}

	// ── Registration ──────────────────────────────────────────────────────────

	/**
	 * Register AJAX actions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_d5dsh_simple_analyze',       [ $this, 'ajax_analyze' ] );
		add_action( 'wp_ajax_d5dsh_simple_execute',       [ $this, 'ajax_execute' ] );
		add_action( 'wp_ajax_d5dsh_simple_json_to_xlsx',  [ $this, 'ajax_json_to_xlsx' ] );
	}

	// ── AJAX: Analyze ─────────────────────────────────────────────────────────

	/**
	 * Analyse an uploaded file and return a manifest.
	 *
	 * For .zip files: extracts, inspects each file, caches the temp dir path.
	 * For .json/.xlsx: returns single-item manifest directly.
	 *
	 * @return never
	 */
	public function ajax_analyze(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'No file uploaded.' ], 400 );
		}

		$tmp_name      = $_FILES['file']['tmp_name'];
		$original_name = sanitize_file_name( $_FILES['file']['name'] ?? 'upload' );
		$ext           = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );

		try {
			if ( $ext === 'zip' ) {
				$manifest = $this->analyze_zip( $tmp_name, $original_name );
			} elseif ( $ext === 'json' ) {
				$manifest = $this->analyze_single_file( $tmp_name, $original_name, 'json' );
				// For single files, cache the tmp file for execute.
				$session_key = 'd5dsh_si_' . get_current_user_id();
				set_transient( $session_key, [
					'type'         => 'single',
					'format'       => 'json',
					'tmp_path'     => $tmp_name,
					'display_name' => $original_name,
				], self::ZIP_TRANSIENT_TTL );
				// Move tmp file to a persistent location (it will be deleted after upload lifecycle).
				$kept = $this->keep_tmp_file( $tmp_name, 'json' );
				if ( $kept ) {
					$cached = get_transient( $session_key );
					$cached['tmp_path'] = $kept;
					set_transient( $session_key, $cached, self::ZIP_TRANSIENT_TTL );
				}
			} elseif ( $ext === 'xlsx' ) {
				$manifest = $this->analyze_single_file( $tmp_name, $original_name, 'xlsx' );
				// For xlsx: also run dry_run immediately and include the diff.
				$file_info = $manifest['files'][0] ?? null;
				if ( $file_info && $file_info['valid'] && $file_info['type'] !== null ) {
					$diff = $this->run_xlsx_dry_run( $tmp_name, $file_info['type'] );
					$manifest['xlsx_dry_run'] = $diff;
				}
				$session_key = 'd5dsh_si_' . get_current_user_id();
				$kept = $this->keep_tmp_file( $tmp_name, 'xlsx' );
				set_transient( $session_key, [
					'type'         => 'single',
					'format'       => 'xlsx',
					'tmp_path'     => $kept ?: $tmp_name,
					'fi_type'      => $file_info['type'] ?? null,
					'display_name' => $original_name,
				], self::ZIP_TRANSIENT_TTL );
			} else {
				wp_send_json_error( [ 'message' => 'Unsupported file type. Please upload a .json, .xlsx, or .zip file.' ], 400 );
			}

			wp_send_json_success( $manifest );
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			wp_send_json_error( [ 'message' => 'Analysis failed: ' . $e->getMessage() ], 500 );
		}
	}

	// ── AJAX: Execute ─────────────────────────────────────────────────────────

	/**
	 * Execute an import for selected files.
	 *
	 * Expects JSON body: { selected_keys: ['filename.json', ...] }
	 * For single json/xlsx: selected_keys is ignored (or has one item).
	 *
	 * @return never
	 */
	public function ajax_execute(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$raw_body = file_get_contents( 'php://input' );
		$payload  = $raw_body ? json_decode( $raw_body, true ) : [];
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		$selected_keys   = array_map( 'sanitize_text_field', (array) ( $payload['selected_keys'] ?? [] ) );
		$raw_overrides   = $payload['label_overrides'] ?? [];
		$label_overrides = [];
		// Sanitize label_overrides: { fileKey: { dsoId: label } }
		if ( is_array( $raw_overrides ) ) {
			foreach ( $raw_overrides as $file_key => $id_map ) {
				if ( ! is_array( $id_map ) ) { continue; }
				$file_key = sanitize_text_field( (string) $file_key );
				$label_overrides[ $file_key ] = [];
				foreach ( $id_map as $dso_id => $label ) {
					$label_overrides[ $file_key ][ sanitize_text_field( (string) $dso_id ) ] =
						sanitize_text_field( (string) $label );
				}
			}
		}

		// Parse conflict resolutions: { dsoId: { action, label? } }
		$raw_resolutions      = $payload['conflict_resolutions'] ?? [];
		$conflict_resolutions = [];
		$skip_ids             = [];
		$conflict_log         = [];
		if ( is_array( $raw_resolutions ) ) {
			foreach ( $raw_resolutions as $dso_id => $res ) {
				if ( ! is_array( $res ) ) { continue; }
				$dso_id = sanitize_text_field( (string) $dso_id );
				$action = sanitize_key( $res['action'] ?? '' );
				$label  = isset( $res['label'] ) ? sanitize_text_field( (string) $res['label'] ) : '';

				$conflict_resolutions[ $dso_id ] = [ 'action' => $action, 'label' => $label ];

				if ( $action === 'skip' ) {
					$skip_ids[] = $dso_id;
					$conflict_log[] = [
						'id'     => $dso_id,
						'action' => 'skip',
						'detail' => 'Skipped — item was not imported.',
					];
				} elseif ( $action === 'keep_current' && $label !== '' ) {
					// Merge into label_overrides so the importer uses the current label.
					// Works for both single-file and zip: put in every file-key bucket.
					foreach ( $label_overrides as $fk => &$id_map ) {
						$id_map[ $dso_id ] = $label;
					}
					unset( $id_map );
					// Also set at root level for single-file imports.
					$label_overrides['__conflict__'][ $dso_id ] = $label;
					$conflict_log[] = [
						'id'     => $dso_id,
						'action' => 'keep_current',
						'detail' => 'Kept current label: ' . $label,
					];
				} elseif ( $action === 'rename' && $label !== '' ) {
					foreach ( $label_overrides as $fk => &$id_map ) {
						$id_map[ $dso_id ] = $label;
					}
					unset( $id_map );
					$label_overrides['__conflict__'][ $dso_id ] = $label;
					$conflict_log[] = [
						'id'     => $dso_id,
						'action' => 'rename',
						'detail' => 'Renamed to: ' . $label,
					];
				} elseif ( $action === 'accept_import' ) {
					$conflict_log[] = [
						'id'     => $dso_id,
						'action' => 'accept_import',
						'detail' => 'Accepted imported value as-is.',
					];
				}
			}
		}

		$session_key = 'd5dsh_si_' . get_current_user_id();
		$session     = get_transient( $session_key );

		if ( ! $session ) {
			wp_send_json_error( [ 'message' => 'Session expired. Please re-upload the file.' ], 400 );
		}

		try {
			$results = [];

			if ( $session['type'] === 'single' ) {
				// For single files the key is the display_name.
				$single_key       = $session['display_name'] ?? '';
				$single_overrides = array_merge(
					$label_overrides[ $single_key ] ?? [],
					$label_overrides['__conflict__'] ?? []
				);
				$result = $this->execute_single( $session, $single_overrides, $skip_ids );
				$results[] = $result;
			} elseif ( $session['type'] === 'zip' ) {
				$results = $this->execute_zip( $session, $selected_keys, $label_overrides, $skip_ids );
			}

			// Keep transient alive so Convert to Excel still works after execute (expires via ZIP_TRANSIENT_TTL).

			// Aggregate sanitization logs from all file results.
			$all_sanitization_log = [];
			foreach ( $results as &$r ) {
				if ( ! empty( $r['sanitization_log'] ) ) {
					array_push( $all_sanitization_log, ...$r['sanitization_log'] );
				}
			}
			unset( $r );

			wp_send_json_success( [
				'results'           => $results,
				'total'             => count( $results ),
				'conflict_log'      => $conflict_log,
				'sanitization_log'  => $all_sanitization_log,
			] );
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			wp_send_json_error( [ 'message' => 'Import failed: ' . $e->getMessage() ], 500 );
		}
	}

	// ── Analysis helpers ──────────────────────────────────────────────────────

	/**
	 * Analyse a zip file: extract to temp dir, inspect each file.
	 *
	 * @param string $tmp_path     Uploaded zip temp path.
	 * @param string $display_name Original filename.
	 * @return array{ files: array, session_key: string }
	 */
	private function analyze_zip( string $tmp_path, string $display_name ): array {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new \RuntimeException( 'ZipArchive extension is not available.' );
		}

		$zip     = new \ZipArchive();
		$opened  = $zip->open( $tmp_path );
		if ( $opened !== true ) {
			throw new \RuntimeException( 'Could not open zip file.' );
		}

		// Extract to a temp directory.
		$extract_dir = sys_get_temp_dir() . '/d5dsh_zip_' . get_current_user_id() . '_' . time();
		wp_mkdir_p( $extract_dir );
		$zip->extractTo( $extract_dir );
		$zip->close();

		// Inspect each extracted file.
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $extract_dir, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$abs_path    = $file->getPathname();
			$rel_name    = str_replace( $extract_dir . '/', '', $abs_path );
			// Skip macOS metadata files.
			if ( str_starts_with( $rel_name, '__MACOSX' ) || str_starts_with( basename( $rel_name ), '.' ) ) {
				continue;
			}
			$ext      = strtolower( pathinfo( $rel_name, PATHINFO_EXTENSION ) );
			$format   = in_array( $ext, [ 'json', 'xlsx' ], true ) ? $ext : null;
			$file_info = $this->analyze_single_file( $abs_path, $rel_name, $format );
			// Key by relative path for selection.
			$file_info['files'][0]['key'] = $rel_name;
			$files[] = $file_info['files'][0];
		}

		// Sort by import order: valid files first, then by type order.
		usort( $files, function ( array $a, array $b ) {
			$ord_a = $a['valid'] ? ( array_search( $a['type'], self::IMPORT_ORDER, true ) + 1 ) : 999;
			$ord_b = $b['valid'] ? ( array_search( $b['type'], self::IMPORT_ORDER, true ) + 1 ) : 999;
			return $ord_a <=> $ord_b;
		} );

		// Cache session.
		$session_key = 'd5dsh_si_' . get_current_user_id();
		set_transient( $session_key, [
			'type'         => 'zip',
			'extract_dir'  => $extract_dir,
			'files'        => $files,
			'display_name' => $display_name,
		], self::ZIP_TRANSIENT_TTL );

		return [
			'upload_name' => $display_name,
			'files'       => $files,
		];
	}

	/**
	 * Analyse a single file (json or xlsx) and return a manifest entry.
	 *
	 * @param string      $abs_path    Absolute path to the file.
	 * @param string      $display_name Filename for display.
	 * @param string|null $format      'json' | 'xlsx' | null (unknown).
	 * @return array{ files: array }
	 */
	private function analyze_single_file( string $abs_path, string $display_name, ?string $format ): array {
		if ( $format === null ) {
			return [
				'files' => [ [
					'name'         => $display_name,
					'format'       => null,
					'type'         => null,
					'type_label'   => 'Unknown',
					'valid'        => false,
					'error'        => 'Unsupported file extension.',
					'object_count' => 0,
					'new_count'    => 0,
					'update_count' => 0,
					'key'          => $display_name,
				] ],
			];
		}

		$entry = [
			'name'              => $display_name,
			'format'            => $format,
			'type'              => null,
			'type_label'        => '',
			'valid'             => false,
			'error'             => null,
			'object_count'      => 0,
			'new_count'         => 0,
			'update_count'      => 0,
			'key'               => $display_name,
			'dependency_report' => null,
			'category_counts'   => [],
			'items'             => [],
			'label_conflicts'   => [],
		];

		try {
			if ( $format === 'json' ) {
				$json = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = json_decode( $json, true );
				if ( ! is_array( $data ) ) {
					$entry['error'] = 'Invalid JSON.';
					return [ 'files' => [ $entry ] ];
				}
				$type = $this->detect_json_type( $data );
				if ( ! $type ) {
					$entry['error'] = 'Cannot detect data type from JSON envelope.';
					return [ 'files' => [ $entry ] ];
				}
				$counts = $this->count_json_objects( $data, $type );
				$entry['type']              = $type;
				$entry['type_label']        = $this->resolve_type_label( $data, $type );
				$entry['valid']             = true;
				$entry['object_count']      = $counts['total'];
				$entry['new_count']         = $counts['new'];
				$entry['update_count']      = $counts['update'];
				$entry['dependency_report'] = $this->build_dependency_report( $data, $type );
				$entry['category_counts']   = $this->build_category_counts( $data, $type );
				$entry['items']             = $this->build_manifest_items( $data, $type );
				$entry['label_conflicts']   = $this->build_label_conflicts( $data, $type );
			} elseif ( $format === 'xlsx' ) {
				$type = $this->detect_xlsx_type( $abs_path );
				if ( ! $type ) {
					$entry['error'] = 'Cannot detect file type (missing or unrecognised Config sheet).';
					return [ 'files' => [ $entry ] ];
				}
				$counts = $this->count_xlsx_objects( $abs_path, $type );
				$entry['type']         = $type;
				$entry['type_label']   = $this->resolve_type_label( [], $type );
				$entry['valid']        = true;
				$entry['object_count'] = $counts['total'];
				$entry['new_count']    = $counts['new'];
				$entry['update_count']     = $counts['update'];
				$entry['category_counts']  = $this->build_category_counts( [], $type );
			}
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			$entry['error'] = $e->getMessage();
		}

		return [ 'files' => [ $entry ] ];
	}

	// ── Type detection ────────────────────────────────────────────────────────

	/**
	 * Detect JSON export type from envelope keys.
	 *
	 * @param array $data Decoded JSON.
	 * @return string|null Type key or null.
	 */
	private function detect_json_type( array $data ): ?string {
		// DTCG format: identified by $schema key pointing to designtokens.org,
		// or by presence of top-level token groups whose entries have $value/$type keys.
		if ( isset( $data['$schema'] ) && str_contains( (string) $data['$schema'], 'designtokens.org' ) ) {
			return 'dtcg';
		}
		// Heuristic for DTCG files without $schema: look for at least one group
		// with token entries containing '$value'.
		if ( ! isset( $data['_meta']['type'] ) && ! isset( $data['context'] ) ) {
			foreach ( [ 'color', 'dimension', 'number', 'fontFamily', 'string' ] as $group ) {
				if ( isset( $data[ $group ] ) && is_array( $data[ $group ] ) ) {
					$first = reset( $data[ $group ] );
					if ( is_array( $first ) && isset( $first['$value'] ) ) {
						return 'dtcg';
					}
				}
			}
		}

		// _meta.type is authoritative when present (our plugin's exports).
		if ( isset( $data['_meta']['type'] ) ) {
			$t = $data['_meta']['type'];
			return isset( self::TYPE_LABELS[ $t ] ) ? $t : null;
		}

		// ET native format: identified by the 'context' key.
		// These files are omnibus — they carry global_variables, global_colors,
		// presets, and additional data all in one file.
		if ( isset( $data['context'] ) ) {
			return 'et_native';
		}

		// Our plugin's envelope key detection (legacy / manual JSON).
		$key_map = [
			'et_divi_global_variables'          => 'vars',
			'et_divi_builder_global_presets_d5' => 'presets',
			'theme_mods_Divi'                   => 'theme_customizer',
			'et_template'                       => 'builder_templates',
		];
		foreach ( $key_map as $env_key => $type ) {
			if ( isset( $data[ $env_key ] ) ) {
				return $type;
			}
		}

		// 'posts' key — distinguish layouts vs pages by post_type of first post.
		if ( isset( $data['posts'] ) && is_array( $data['posts'] ) ) {
			$first     = $data['posts'][0] ?? null;
			$post_type = $first['post_type'] ?? '';
			if ( $post_type === 'et_pb_layout' ) { return 'layouts'; }
			if ( $post_type === 'page' )          { return 'pages'; }
			return 'layouts'; // Ambiguous — treat as layouts.
		}

		return null;
	}

	/**
	 * Build a human-readable type badge label for a detected file.
	 *
	 * Replaces the flat TYPE_LABELS lookup. Logic:
	 *
	 *  - Variables: "Global Variables (Colors, Fonts, …)" — types present, no qualifier.
	 *  - Theme Customizer: "Theme Customizer" — settings dump, no qualifier.
	 *  - et_native: derive Target DSO from ET_CONTEXTS, then apply qualifier.
	 *  - Everything else: determine Target DSO name, then append
	 *      "— Composite"  if lower-level DSOs are bundled alongside the target,
	 *      "— Standalone" if the file contains only the target layer.
	 *
	 * Composite/Standalone is intent-based (structural presence), not a
	 * completeness check. The dependency analysis panel handles completeness.
	 *
	 * @param array  $data Decoded JSON (empty array for xlsx).
	 * @param string $type Detected internal type key.
	 * @return string Display label.
	 */
	/**
	 * Resolve a human-readable type label for display.
	 *
	 * Label taxonomy (Q4):
	 *
	 * Pure DSO files:
	 *   Variables                    → "Variables"
	 *   Variables (with colors)      → "Variables"   (colors are a variable subtype)
	 *   Element Presets only         → "Element Presets"
	 *   Group Presets only           → "Group Presets"
	 *   Both preset types            → "Presets"
	 *   Presets + Variables          → "Presets — Composite"
	 *   Element Presets + Variables  → "Element Presets — Composite"
	 *   Group Presets + Variables    → "Group Presets — Composite"
	 *
	 * Divi Content files:
	 *   Layouts only                 → "Layouts"
	 *   Layouts + Element Presets    → "Layouts — Element Presets"
	 *   Layouts + Group Presets      → "Layouts — Group Presets"
	 *   Layouts + Presets            → "Layouts — Presets"
	 *   Layouts + Presets + Vars     → "Layouts — Presets — Composite"
	 *   (same pattern for Pages, Builder Templates)
	 *
	 * Theme Customizer               → "Theme Customizer"
	 *
	 * @param array  $data Decoded JSON (may be empty for xlsx).
	 * @param string $type Internal type key.
	 * @return string
	 */
	private function resolve_type_label( array $data, string $type ): string {

		// ── Theme Customizer ──────────────────────────────────────────────────
		if ( $type === 'theme_customizer' ) {
			return 'Theme Customizer';
		}

		// ── Variables-only file ───────────────────────────────────────────────
		if ( $type === 'vars' ) {
			return 'Variables';
		}

		// ── ET native bundle: inspect actual sections present ─────────────────
		if ( $type === 'et_native' ) {
			$has_vars     = ! empty( $data['global_variables'] ) || ! empty( $data['global_colors'] );
			$has_module   = ! empty( $data['presets']['module'] );
			$has_group    = ! empty( $data['presets']['group'] );
			$has_data     = ! empty( $data['data'] );
			$context      = $data['context'] ?? '';
			$content_type = self::ET_CONTEXTS[ $context ] ?? null;

			// Priority: if the bundle contains layouts/pages/templates, that determines the
			// primary type. Presets are DSO modifiers. Variables add "Composite".
			if ( $has_data && $content_type ) {
				$content_names = [
					'layouts'           => 'Layouts',
					'pages'             => 'Pages',
					'builder_templates' => 'Builder Templates',
				];
				$base = $content_names[ $content_type ] ?? 'Layouts';
				return $this->build_content_label( $base, $has_module, $has_group, $has_vars );
			}

			// Presets bundle (no content posts).
			if ( $has_module || $has_group ) {
				return $this->build_presets_label( $has_module, $has_group, $has_vars );
			}

			// Variables only.
			return 'Variables';
		}

		// ── Plugin-envelope JSON or xlsx ──────────────────────────────────────
		$has_vars   = ! empty( $data['et_divi_global_variables'] ) || ! empty( $data['global_colors'] );
		$raw        = $data['et_divi_builder_global_presets_d5'] ?? [];
		$has_module = ! empty( $raw['module'] );
		$has_group  = ! empty( $raw['group'] );

		if ( $type === 'presets' ) {
			return $this->build_presets_label( $has_module, $has_group, $has_vars );
		}

		$content_names = [
			'layouts'           => 'Layouts',
			'pages'             => 'Pages',
			'builder_templates' => 'Builder Templates',
		];
		if ( isset( $content_names[ $type ] ) ) {
			return $this->build_content_label( $content_names[ $type ], $has_module, $has_group, $has_vars );
		}

		// Fallback.
		return ucwords( str_replace( '_', ' ', $type ) );
	}

	/**
	 * Build a label for a pure-DSO presets file.
	 *
	 * @param bool $has_module   Has Element Presets.
	 * @param bool $has_group    Has Group/Option Presets.
	 * @param bool $has_vars     Has Variables (makes it Composite).
	 * @return string
	 */
	private function build_presets_label( bool $has_module, bool $has_group, bool $has_vars ): string {
		if ( $has_module && $has_group ) {
			$base = 'Presets';
		} elseif ( $has_module ) {
			$base = 'Element Presets';
		} elseif ( $has_group ) {
			$base = 'Group Presets';
		} else {
			$base = 'Presets';
		}
		return $has_vars ? $base . ' — Composite' : $base;
	}

	/**
	 * Build a label for Divi Content (layouts/pages/templates) file.
	 *
	 * Pattern: "{Content} — {DSO modifier} [— Composite]"
	 * Variables alone never add a modifier — only "Composite" suffix.
	 *
	 * @param string $content_name  "Layouts", "Pages", or "Builder Templates".
	 * @param bool   $has_module    Has Element Presets.
	 * @param bool   $has_group     Has Group Presets.
	 * @param bool   $has_vars      Has Variables.
	 * @return string
	 */
	private function build_content_label( string $content_name, bool $has_module, bool $has_group, bool $has_vars ): string {
		$parts = [ $content_name ];
		if ( $has_module && $has_group ) {
			$parts[] = 'Presets';
		} elseif ( $has_module ) {
			$parts[] = 'Element Presets';
		} elseif ( $has_group ) {
			$parts[] = 'Group Presets';
		}
		if ( $has_vars ) {
			$parts[] = 'Composite';
		}
		return implode( ' — ', $parts );
	}

	/**
	 * Detect xlsx file type from Config sheet cell B5.
	 *
	 * @param string $file_path
	 * @return string|null
	 */
	private function detect_xlsx_type( string $file_path ): ?string {
		try {
			$ss = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file_path );
			$ws = $ss->getSheetByName( 'Config' );
			if ( ! $ws ) {
				return null;
			}
			$type = trim( (string) $ws->getCell( 'B5' )->getValue() );
			return isset( self::TYPE_LABELS[ $type ] ) ? $type : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	// ── Object counting ───────────────────────────────────────────────────────

	/**
	 * Count objects in a JSON export and compare against current DB.
	 *
	 * Returns: { total, new, update }
	 *   total  = number of objects in the file
	 *   new    = not present in DB (would be added)
	 *   update = present in DB and would be modified
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $type Type key.
	 * @return array{ total: int, new: int, update: int }
	 */
	private function count_json_objects( array $data, string $type ): array {
		$total  = 0;
		$new    = 0;
		$update = 0;

		try {
			switch ( $type ) {
				case 'vars': {
					$vars = $data['et_divi_global_variables'] ?? [];
					$repo = new VarsRepository();
					$cur  = $repo->get_raw();
					foreach ( $vars as $var_type => $items ) {
						if ( ! is_array( $items ) ) { continue; }
						foreach ( $items as $id => $item ) {
							$total++;
							isset( $cur[ $var_type ][ $id ] ) ? $update++ : $new++;
						}
					}
					break;
				}
				case 'presets': {
					$raw  = $data['et_divi_builder_global_presets_d5'] ?? [];
					$repo = new PresetsRepository();
					$cur  = $repo->get_raw();
					foreach ( $raw['module'] ?? [] as $mod => $mod_data ) {
						foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
							$total++;
							isset( $cur['module'][ $mod ]['items'][ $pid ] ) ? $update++ : $new++;
						}
					}
					foreach ( $raw['group'] ?? [] as $grp => $grp_data ) {
						foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
							$total++;
							isset( $cur['group'][ $grp ]['items'][ $pid ] ) ? $update++ : $new++;
						}
					}
					break;
				}
				case 'layouts':
				case 'pages': {
					$posts = $data['posts'] ?? [];
					$total = count( $posts );
					foreach ( $posts as $post ) {
						$existing = get_posts( [
							'post_type'   => $post['post_type'] ?? 'et_pb_layout',
							'name'        => $post['post_name'] ?? '',
							'post_status' => 'any',
							'numberposts' => 1,
						] );
						$existing ? $update++ : $new++;
					}
					break;
				}
				case 'theme_customizer': {
					$mods  = $data['theme_mods_Divi'] ?? [];
					$cur   = get_option( ThemeCustomizerRepository::OPTION_KEY, [] );
					$total = count( $mods );
					foreach ( $mods as $key => $val ) {
						array_key_exists( $key, $cur ) ? $update++ : $new++;
					}
					break;
				}
				case 'builder_templates': {
					$templates = $data['et_template'] ?? [];
					$total     = count( $templates );
					foreach ( $templates as $tpl ) {
						$slug = $tpl['post_name'] ?? '';
						if ( $slug ) {
							$existing = get_posts( [
								'post_type'   => 'et_template',
								'name'        => $slug,
								'post_status' => 'any',
								'numberposts' => 1,
							] );
							$existing ? $update++ : $new++;
						} else {
							$new++;
						}
					}
					break;
				}
				case 'et_native': {
					// ET native files: global_variables (flat array), global_colors (array of
					// [id, {...}] pairs), presets { module, group }, data (layouts/pages dict).
					$repo_vars  = new VarsRepository();
					$cur_vars   = $repo_vars->get_raw();
					$cur_colors = $repo_vars->get_raw_colors();
					foreach ( $data['global_variables'] ?? [] as $item ) {
						if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
						$var_type = $item['variableType'] ?? $item['type'] ?? '';
						$total++;
						isset( $cur_vars[ $var_type ][ $item['id'] ] ) ? $update++ : $new++;
					}
					// ET global_colors format: [ [ "gcid-xxx", { color, label, status } ], ... ]
					foreach ( $data['global_colors'] ?? [] as $pair ) {
						$id = is_array( $pair ) ? ( $pair[0] ?? null ) : null;
						if ( ! $id ) { continue; }
						$total++;
						isset( $cur_colors[ $id ] ) ? $update++ : $new++;
					}
					// Presets: { module: { 'divi/heading': { items: { id: preset } } }, group: {...} }
					$repo_pr = new PresetsRepository();
					$cur_pr  = $repo_pr->get_raw();
					foreach ( $data['presets']['module'] ?? [] as $mod => $mod_data ) {
						foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
							$total++;
							isset( $cur_pr['module'][ $mod ]['items'][ $pid ] ) ? $update++ : $new++;
						}
					}
					foreach ( $data['presets']['group'] ?? [] as $grp => $grp_data ) {
						foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
							$total++;
							isset( $cur_pr['group'][ $grp ]['items'][ $pid ] ) ? $update++ : $new++;
						}
					}
					// Layouts/pages/templates in 'data' (dict keyed by post ID).
					$et_data    = $data['data'] ?? [];
					$data_count = is_array( $et_data ) ? count( $et_data ) : 0;
					if ( $data_count ) {
						$total += $data_count;
						$new   += $data_count; // Best-effort: assume new for analysis.
					}
					break;
				}
			}
		} catch ( \Throwable ) {
			// On any error, just return zeroes — analysis is best-effort.
		}

		return [ 'total' => $total, 'new' => $new, 'update' => $update ];
	}

	/**
	 * Count rows in an xlsx file for the given type.
	 *
	 * @param string $file_path
	 * @param string $type
	 * @return array{ total: int, new: int, update: int }
	 */
	private function count_xlsx_objects( string $file_path, string $type ): array {
		$total  = 0;
		$new    = 0;
		$update = 0;

		try {
			$ss = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file_path );

			if ( $type === 'vars' ) {
				$sheets = [ 'Colors', 'Numbers', 'Fonts', 'Images', 'Text', 'Links' ];
				$repo   = new VarsRepository();
				$cur    = $repo->get_raw();
				$cur_colors = $repo->get_raw_colors();
				$type_map   = [ 'Colors' => 'colors', 'Numbers' => 'numbers', 'Fonts' => 'fonts', 'Images' => 'images', 'Text' => 'strings', 'Links' => 'links' ];
				foreach ( $sheets as $sheet_name ) {
					$ws = $ss->getSheetByName( $sheet_name );
					if ( ! $ws ) { continue; }
					$var_type = $type_map[ $sheet_name ] ?? '';
					for ( $row = 2; $row <= $ws->getHighestDataRow(); $row++ ) {
						$id = trim( (string) $ws->getCell( 'B' . $row )->getValue() );
						if ( ! $id ) { continue; }
						$total++;
						$in_db = ( $var_type === 'colors' )
							? isset( $cur_colors[ $id ] )
							: isset( $cur[ $var_type ][ $id ] );
						if ( $in_db ) {
							$update++;
						} else {
							$new++;
						}
					}
				}
			} elseif ( $type === 'presets' ) {
				$repo = new PresetsRepository();
				$cur  = $repo->get_raw();
				$ws_mod = $ss->getSheetByName( 'Module Presets' );
				if ( $ws_mod ) {
					for ( $row = 2; $row <= $ws_mod->getHighestDataRow(); $row++ ) {
						$mod = trim( (string) $ws_mod->getCell( 'A' . $row )->getValue() );
						$pid = trim( (string) $ws_mod->getCell( 'B' . $row )->getValue() );
						if ( ! $mod || ! $pid ) { continue; }
						$total++;
						isset( $cur['module'][ $mod ]['items'][ $pid ] ) ? $update++ : $new++;
					}
				}
				$ws_grp = $ss->getSheetByName( 'Group Presets' );
				if ( $ws_grp ) {
					for ( $row = 2; $row <= $ws_grp->getHighestDataRow(); $row++ ) {
						$grp = trim( (string) $ws_grp->getCell( 'A' . $row )->getValue() );
						$pid = trim( (string) $ws_grp->getCell( 'B' . $row )->getValue() );
						if ( ! $grp || ! $pid ) { continue; }
						$total++;
						isset( $cur['group'][ $grp ]['items'][ $pid ] ) ? $update++ : $new++;
					}
				}
			}
			// For other xlsx types (layouts, theme_customizer, builder_templates)
			// we don't have a reliable row-count method without running the full importer.
			// Return total=0, new=0, update=0 — the dry-run result covers it.
		} catch ( \Throwable ) {
			// Best-effort.
		}

		return [ 'total' => $total, 'new' => $new, 'update' => $update ];
	}

	/**
	 * Build per-category counts for the breakdown display.
	 *
	 * @param array  $data JSON data (may be empty for xlsx).
	 * @param string $type Type key.
	 * @return array<string, array{total: int, new: int}>
	 */
	private function build_category_counts( array $data, string $type ): array {
		$display = [
			'colors'  => 'Colors',
			'numbers' => 'Numbers',
			'fonts'   => 'Fonts',
			'images'  => 'Images',
			'strings' => 'Text',
			'links'   => 'Links',
		];
		$cats  = [];
		$repo  = new VarsRepository();
		$cur   = $repo->get_raw();

		if ( $type === 'vars' ) {
			$vars = $data['et_divi_global_variables'] ?? [];
			foreach ( $vars as $vtype => $items ) {
				if ( ! is_array( $items ) ) { continue; }
				$label = $display[ $vtype ] ?? null;
				if ( ! $label ) { continue; }
				foreach ( $items as $id => $item ) {
					if ( ! isset( $cats[ $label ] ) ) { $cats[ $label ] = [ 'total' => 0, 'new' => 0 ]; }
					$cats[ $label ]['total']++;
					if ( ! isset( $cur[ $vtype ][ $id ] ) ) { $cats[ $label ]['new']++; }
				}
			}
			return $cats;
		}

		if ( $type === 'et_native' ) {
			$cur_colors  = $repo->get_raw_colors();
			$repo_pr     = new PresetsRepository();
			$cur_pr      = $repo_pr->get_raw();

			// global_variables (non-color variable types).
			foreach ( $data['global_variables'] ?? [] as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
				$vtype = $item['variableType'] ?? $item['type'] ?? '';
				$label = $display[ $vtype ] ?? null;
				if ( ! $label ) { continue; }
				if ( ! isset( $cats[ $label ] ) ) { $cats[ $label ] = [ 'total' => 0, 'new' => 0 ]; }
				$cats[ $label ]['total']++;
				if ( ! isset( $cur[ $vtype ][ $item['id'] ] ) ) { $cats[ $label ]['new']++; }
			}

			// global_colors: [ [id, {color,label,status}], ... ]
			foreach ( $data['global_colors'] ?? [] as $pair ) {
				$id = is_array( $pair ) ? ( $pair[0] ?? null ) : null;
				if ( ! $id ) { continue; }
				if ( ! isset( $cats['Colors'] ) ) { $cats['Colors'] = [ 'total' => 0, 'new' => 0 ]; }
				$cats['Colors']['total']++;
				if ( ! isset( $cur_colors[ $id ] ) ) { $cats['Colors']['new']++; }
			}

			// Presets.
			$preset_total = 0;
			$preset_new   = 0;
			foreach ( $data['presets']['module'] ?? [] as $mod => $mod_data ) {
				foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
					$preset_total++;
					if ( ! isset( $cur_pr['module'][ $mod ]['items'][ $pid ] ) ) { $preset_new++; }
				}
			}
			foreach ( $data['presets']['group'] ?? [] as $grp => $grp_data ) {
				foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
					$preset_total++;
					if ( ! isset( $cur_pr['group'][ $grp ]['items'][ $pid ] ) ) { $preset_new++; }
				}
			}
			if ( $preset_total > 0 ) {
				$cats['Presets'] = [ 'total' => $preset_total, 'new' => $preset_new ];
			}

			// Extra data (layouts / pages / templates).
			$et_data = $data['data'] ?? [];
			$context = $data['context'] ?? '';
			if ( is_array( $et_data ) && count( $et_data ) > 0 ) {
				$data_label = match ( self::ET_CONTEXTS[ $context ] ?? '' ) {
					'pages'             => 'Pages',
					'builder_templates' => 'Templates',
					default             => 'Layouts',
				};
				$cats[ $data_label ] = [ 'total' => count( $et_data ), 'new' => count( $et_data ) ];
			}

			return $cats;
		}

		return [];
	}

	// ── Manifest items ────────────────────────────────────────────────────────

	/**
	 * Build a flat list of DSO items for the analyze manifest.
	 *
	 * Used by the import label-editor: returns each variable / preset with its
	 * current label so the client can show an editable table before committing.
	 *
	 * Capped at 500 items to avoid very large payloads.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $type Detected file type.
	 * @return array<int, array{id: string, label: string, type: string}>
	 */
	protected function build_manifest_items( array $data, string $type ): array {
		$items = [];
		$cap   = 500;

		if ( $type === 'vars' ) {
			$type_labels = [
				'colors'  => 'Color',
				'numbers' => 'Number',
				'fonts'   => 'Font',
				'images'  => 'Image',
				'strings' => 'Text',
				'links'   => 'Link',
			];
			foreach ( ( $data['et_divi_global_variables'] ?? [] ) as $vtype => $type_items ) {
				if ( ! is_array( $type_items ) ) { continue; }
				$type_label = $type_labels[ $vtype ] ?? ucfirst( $vtype );
				foreach ( $type_items as $id => $item ) {
					if ( count( $items ) >= $cap ) { break 2; }
					$items[] = [
						'id'    => (string) $id,
						'label' => sanitize_text_field( $item['label'] ?? '' ),
						'type'  => $type_label,
					];
				}
			}
		} elseif ( $type === 'presets' ) {
			$raw = $data['et_divi_builder_global_presets_d5'] ?? [];
			foreach ( $raw['module'] ?? [] as $mod => $mod_data ) {
				foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
					if ( count( $items ) >= $cap ) { break 2; }
					$items[] = [
						'id'    => (string) $pid,
						'label' => sanitize_text_field( $preset['name'] ?? $preset['label'] ?? '' ),
						'type'  => 'Element — ' . $mod,
					];
				}
			}
			foreach ( $raw['group'] ?? [] as $grp => $grp_data ) {
				foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
					if ( count( $items ) >= $cap ) { break 2; }
					$items[] = [
						'id'    => (string) $pid,
						'label' => sanitize_text_field( $preset['name'] ?? $preset['label'] ?? '' ),
						'type'  => 'Group — ' . $grp,
					];
				}
			}
		}

		return $items;
	}

	// ── Label / ID conflict detection ────────────────────────────────────────

	/**
	 * Detect label and ID conflicts between an imported file and existing data.
	 *
	 * Returns two lists:
	 * - label_changed: same ID exists locally but with a different label (import would rename).
	 * - duplicate_label: different IDs share the same label (creates duplicates in UI).
	 *
	 * Only vars and presets are checked — other types don't have user-visible labels.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $type Detected file type.
	 * @return array{ label_changed: list<array>, duplicate_label: list<array> }
	 */
	protected function build_label_conflicts( array $data, string $type ): array {
		$conflicts = [ 'label_changed' => [], 'duplicate_label' => [] ];

		try {
			if ( $type === 'vars' ) {
				$repo = new VarsRepository();
				$cur  = $repo->get_raw();

				// Build a reverse map: lowercase label → [ id, var_type ] for existing items.
				$existing_labels = [];
				foreach ( $cur as $vtype => $items ) {
					if ( ! is_array( $items ) ) { continue; }
					foreach ( $items as $id => $item ) {
						$label = strtolower( trim( $item['label'] ?? '' ) );
						if ( $label !== '' ) {
							$existing_labels[ $label ][] = [ 'id' => (string) $id, 'var_type' => $vtype ];
						}
					}
				}

				foreach ( ( $data['et_divi_global_variables'] ?? [] ) as $vtype => $type_items ) {
					if ( ! is_array( $type_items ) ) { continue; }
					foreach ( $type_items as $id => $item ) {
						$import_label = trim( $item['label'] ?? '' );
						if ( $import_label === '' ) { continue; }

						// Check 1: Same ID, different label (silent rename).
						if ( isset( $cur[ $vtype ][ $id ] ) ) {
							$cur_label = trim( $cur[ $vtype ][ $id ]['label'] ?? '' );
							if ( $cur_label !== '' && $cur_label !== $import_label ) {
								$conflicts['label_changed'][] = [
									'id'            => (string) $id,
									'var_type'      => $vtype,
									'current_label' => $cur_label,
									'import_label'  => $import_label,
								];
							}
						}

						// Check 2: Same label, different ID (duplicate in UI).
						$lower = strtolower( $import_label );
						if ( isset( $existing_labels[ $lower ] ) ) {
							foreach ( $existing_labels[ $lower ] as $match ) {
								if ( $match['id'] !== (string) $id ) {
									$conflicts['duplicate_label'][] = [
										'import_id'    => (string) $id,
										'import_label' => $import_label,
										'existing_id'  => $match['id'],
										'var_type'     => $vtype,
									];
									break; // One match is enough to flag the conflict.
								}
							}
						}
					}
				}
			} elseif ( $type === 'presets' ) {
				$repo = new PresetsRepository();
				$cur  = $repo->get_raw();

				// Check element presets.
				foreach ( $data['et_divi_builder_global_presets_d5']['module'] ?? [] as $mod => $mod_data ) {
					foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
						$import_name = trim( $preset['name'] ?? $preset['label'] ?? '' );
						if ( $import_name === '' ) { continue; }
						$cur_preset = $cur['module'][ $mod ]['items'][ $pid ] ?? null;
						if ( $cur_preset ) {
							$cur_name = trim( $cur_preset['name'] ?? $cur_preset['label'] ?? '' );
							if ( $cur_name !== '' && $cur_name !== $import_name ) {
								$conflicts['label_changed'][] = [
									'id'            => (string) $pid,
									'var_type'      => 'element_preset',
									'module'        => $mod,
									'current_label' => $cur_name,
									'import_label'  => $import_name,
								];
							}
						}
					}
				}

				// Check group presets.
				foreach ( $data['et_divi_builder_global_presets_d5']['group'] ?? [] as $grp => $grp_data ) {
					foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
						$import_name = trim( $preset['name'] ?? $preset['label'] ?? '' );
						if ( $import_name === '' ) { continue; }
						$cur_preset = $cur['group'][ $grp ]['items'][ $pid ] ?? null;
						if ( $cur_preset ) {
							$cur_name = trim( $cur_preset['name'] ?? $cur_preset['label'] ?? '' );
							if ( $cur_name !== '' && $cur_name !== $import_name ) {
								$conflicts['label_changed'][] = [
									'id'            => (string) $pid,
									'var_type'      => 'group_preset',
									'group'         => $grp,
									'current_label' => $cur_name,
									'import_label'  => $import_name,
								];
							}
						}
					}
				}
			}
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
		}

		return $conflicts;
	}

	// ── Dependency analysis ───────────────────────────────────────────────────

	/**
	 * Build a dependency report for a decoded JSON file.
	 *
	 * Scans the file for all $variable()$ and preset ID references, then checks
	 * which ones are present on this site and which are missing. Known Divi
	 * built-in IDs are reported as informational rather than as errors.
	 *
	 * The report is best-effort: any exception causes a graceful empty report.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $type Detected file type.
	 * @return array{
	 *   variable_refs: int,
	 *   preset_refs: int,
	 *   missing_vars: list<array{id: string, context: string}>,
	 *   missing_presets: list<array{id: string, context: string}>,
	 *   builtin_refs: list<string>,
	 *   has_warnings: bool
	 * }
	 */
	private function build_dependency_report( array $data, string $type ): array {
		$report = [
			'variable_refs'   => 0,
			'preset_refs'     => 0,
			'missing_vars'    => [],
			'missing_presets' => [],
			'builtin_refs'    => [],
			'has_warnings'    => false,
		];

		try {
			// ── Load current site data for comparison ─────────────────────────
			$vars_repo    = new VarsRepository();
			$presets_repo = new PresetsRepository();

			// Build flat set of known variable IDs on this site.
			$site_var_ids = [];
			foreach ( $vars_repo->get_raw() as $var_type => $items ) {
				if ( is_array( $items ) ) {
					foreach ( array_keys( $items ) as $id ) {
						$site_var_ids[ $id ] = true;
					}
				}
			}
			foreach ( array_keys( $vars_repo->get_raw_colors() ) as $id ) {
				$site_var_ids[ $id ] = true;
			}

			// Build flat set of known preset IDs on this site.
			$site_preset_ids = [];
			$cur_presets     = $presets_repo->get_raw();
			foreach ( $cur_presets['module'] ?? [] as $mod_data ) {
				foreach ( array_keys( $mod_data['items'] ?? [] ) as $pid ) {
					$site_preset_ids[ $pid ] = true;
				}
			}
			foreach ( $cur_presets['group'] ?? [] as $grp_data ) {
				foreach ( array_keys( $grp_data['items'] ?? [] ) as $pid ) {
					$site_preset_ids[ $pid ] = true;
				}
			}

			// ── For et_native: also treat IDs defined IN the file as "available" ─
			// ET native files are self-contained — they bundle their own vars and
			// presets, so a reference to an ID that is also defined in the same
			// file is not a missing dependency.
			$file_var_ids    = [];
			$file_preset_ids = [];

			if ( $type === 'et_native' ) {
				foreach ( $data['global_variables'] ?? [] as $item ) {
					if ( ! empty( $item['id'] ) ) {
						$file_var_ids[ $item['id'] ] = true;
					}
				}
				foreach ( $data['global_colors'] ?? [] as $pair ) {
					$id = is_array( $pair ) ? ( $pair[0] ?? null ) : null;
					if ( $id ) { $file_var_ids[ $id ] = true; }
				}
				foreach ( $data['presets']['module'] ?? [] as $mod_data ) {
					foreach ( array_keys( $mod_data['items'] ?? [] ) as $pid ) {
						$file_preset_ids[ $pid ] = true;
					}
				}
				foreach ( $data['presets']['group'] ?? [] as $grp_data ) {
					foreach ( array_keys( $grp_data['items'] ?? [] ) as $pid ) {
						$file_preset_ids[ $pid ] = true;
					}
				}
			}

			// Combined "available" = site + file.
			$available_var_ids    = $site_var_ids    + $file_var_ids;
			$available_preset_ids = $site_preset_ids + $file_preset_ids;

			// ── Collect all $variable()$ references ───────────────────────────
			// Encode the full decoded array back to a string for regex scanning.
			// This is faster than recursive traversal for large files.
			$raw_json       = json_encode( $data );
			$var_refs_found = DiviBlocParser::extract_variable_refs( $raw_json );
			$seen_var_ids   = [];

			foreach ( $var_refs_found as $ref ) {
				$id = $ref['name'];
				if ( isset( $seen_var_ids[ $id ] ) ) {
					continue; // deduplicate per unique ID
				}
				$seen_var_ids[ $id ] = true;
				$report['variable_refs']++;

				if ( in_array( $id, self::DIVI_BUILTIN_IDS, true ) ) {
					if ( ! in_array( $id, $report['builtin_refs'], true ) ) {
						$report['builtin_refs'][] = $id;
					}
				} elseif ( ! isset( $available_var_ids[ $id ] ) ) {
					$report['missing_vars'][] = [
						'id'      => $id,
						'context' => $ref['type'] === 'color' ? 'color variable' : 'variable',
					];
				}
			}

			// ── Collect all preset ID references ─────────────────────────────
			$post_contents = [];

			if ( $type === 'et_native' ) {
				foreach ( $data['data'] ?? [] as $layout ) {
					if ( ! empty( $layout['post_content'] ) ) {
						$post_contents[] = [
							'content' => $layout['post_content'],
							'name'    => $layout['post_title'] ?? 'layout',
						];
					}
				}
			} elseif ( in_array( $type, [ 'layouts', 'pages' ], true ) ) {
				foreach ( $data['posts'] ?? [] as $post ) {
					if ( ! empty( $post['post_content'] ) ) {
						$post_contents[] = [
							'content' => $post['post_content'],
							'name'    => $post['post_name'] ?? 'post',
						];
					}
				}
			} elseif ( $type === 'builder_templates' ) {
				foreach ( $data['layouts'] ?? [] as $layout ) {
					if ( ! empty( $layout['post_content'] ) ) {
						$post_contents[] = [
							'content' => $layout['post_content'],
							'name'    => $layout['post_name'] ?? 'layout',
						];
					}
				}
			} elseif ( $type === 'presets' ) {
				// Presets don't have post_content, but attrs blocks may embed
				// groupPreset references. Scan the whole encoded presets block.
				$raw_presets     = json_encode( $data['et_divi_builder_global_presets_d5'] ?? [] );
				$post_contents[] = [ 'content' => $raw_presets, 'name' => 'presets' ];
			}

			$seen_preset_ids = [];
			foreach ( $post_contents as $item ) {
				$pids = DiviBlocParser::extract_preset_refs( $item['content'] );
				foreach ( $pids as $pid ) {
					if ( isset( $seen_preset_ids[ $pid ] ) ) {
						continue;
					}
					$seen_preset_ids[ $pid ] = true;
					$report['preset_refs']++;

					if ( ! isset( $available_preset_ids[ $pid ] ) ) {
						$report['missing_presets'][] = [
							'id'      => $pid,
							'context' => $item['name'],
						];
					}
				}
			}

			$report['has_warnings'] = ! empty( $report['missing_vars'] ) || ! empty( $report['missing_presets'] );

		} catch ( \Throwable ) {
			// Best-effort — return empty report on any error.
		}

		return $report;
	}

	// ── Execute helpers ───────────────────────────────────────────────────────

	/**
	 * Execute import for a single file (json or xlsx).
	 *
	 * @param array $session          Session data from transient.
	 * @param array $label_overrides  Optional map of { dso_id => new_label }.
	 * @param array $skip_ids         Optional list of DSO IDs to skip during import.
	 * @return array Result entry.
	 */
	private function execute_single( array $session, array $label_overrides = [], array $skip_ids = [] ): array {
		$format       = $session['format'] ?? '';
		$tmp_path     = $session['tmp_path'] ?? '';
		$display_name = $session['display_name'] ?? basename( $tmp_path );

		if ( ! $tmp_path || ! file_exists( $tmp_path ) ) {
			return [
				'name'    => $display_name ?: 'upload',
				'success' => false,
				'message' => 'Temporary file not found. Session may have expired.',
			];
		}

		if ( $format === 'json' ) {
			return $this->import_json_file( $tmp_path, $display_name, $label_overrides, $skip_ids );
		} elseif ( $format === 'xlsx' ) {
			$fi_type = $session['fi_type'] ?? null;
			return $this->import_xlsx_file( $tmp_path, $fi_type, $display_name );
		}

		return [
			'name'    => $display_name ?: 'upload',
			'success' => false,
			'message' => 'Unknown format in session.',
		];
	}

	/**
	 * Execute import for selected files from a zip.
	 *
	 * @param array    $session         Session data.
	 * @param string[] $selected_keys   Relative paths of selected files.
	 * @param array    $label_overrides Map of { fileKey: { dso_id: new_label } }.
	 * @param array    $skip_ids        DSO IDs to skip during import.
	 * @return array[] Result entries.
	 */
	private function execute_zip( array $session, array $selected_keys, array $label_overrides = [], array $skip_ids = [] ): array {
		$extract_dir  = $session['extract_dir'] ?? '';
		$session_files = $session['files'] ?? [];

		if ( ! $extract_dir || ! is_dir( $extract_dir ) ) {
			return [ [
				'name'    => 'zip',
				'success' => false,
				'message' => 'Temporary extraction directory not found. Session may have expired.',
			] ];
		}

		// Build a map of key → file info.
		$file_map = [];
		foreach ( $session_files as $fi ) {
			$file_map[ $fi['key'] ] = $fi;
		}

		// Sort selected keys by import order.
		usort( $selected_keys, function ( string $a, string $b ) use ( $file_map ) {
			$type_a = $file_map[ $a ]['type'] ?? null;
			$type_b = $file_map[ $b ]['type'] ?? null;
			$ord_a  = $type_a ? ( array_search( $type_a, self::IMPORT_ORDER, true ) + 1 ) : 999;
			$ord_b  = $type_b ? ( array_search( $type_b, self::IMPORT_ORDER, true ) + 1 ) : 999;
			return $ord_a <=> $ord_b;
		} );

		$results = [];
		foreach ( $selected_keys as $key ) {
			$fi = $file_map[ $key ] ?? null;
			if ( ! $fi || ! $fi['valid'] ) {
				$results[] = [
					'name'    => $key,
					'success' => false,
					'message' => 'File skipped (invalid or not in manifest).',
				];
				continue;
			}
			$abs_path = $extract_dir . '/' . $key;

			// Security: Prevent path traversal attacks.
			$safe_path = self::validate_path_within( $abs_path, $extract_dir );
			if ( $safe_path === false ) {
				$results[] = [
					'name'    => $key,
					'success' => false,
					'message' => 'Invalid file path.',
				];
				continue;
			}
			$abs_path = $safe_path;

			if ( ! file_exists( $abs_path ) ) {
				$results[] = [
					'name'    => $key,
					'success' => false,
					'message' => 'File not found in extracted zip.',
				];
				continue;
			}
			$format = $fi['format'];
			if ( $format === 'json' ) {
				$file_overrides = array_merge(
					$label_overrides[ $key ] ?? [],
					$label_overrides['__conflict__'] ?? []
				);
				$results[] = $this->import_json_file( $abs_path, $key, $file_overrides, $skip_ids );
			} elseif ( $format === 'xlsx' ) {
				$results[] = $this->import_xlsx_file( $abs_path, $fi['type'], $key );
			} else {
				$results[] = [
					'name'    => $key,
					'success' => false,
					'message' => 'Unsupported format.',
				];
			}
		}

		// Clean up temp directory.
		$this->rmdir_recursive( $extract_dir );

		return $results;
	}

	/**
	 * Import a single JSON file additively.
	 *
	 * @param string $abs_path        Absolute path to the file.
	 * @param string $name            Display name for results.
	 * @param array  $label_overrides Optional map of { dso_id => new_label }.
	 * @param array  $skip_ids        DSO IDs to skip during import.
	 * @return array Result entry.
	 */
	private function import_json_file( string $abs_path, string $name, array $label_overrides = [], array $skip_ids = [] ): array {
		try {
			$json = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				return [ 'name' => $name, 'success' => false, 'message' => 'Invalid JSON.' ];
			}
			$type = $this->detect_json_type( $data );
			if ( ! $type ) {
				return [ 'name' => $name, 'success' => false, 'message' => 'Cannot detect data type.' ];
			}

			// Extract source metadata from _meta block (present in our plugin's exports).
			$meta_block  = $data['_meta'] ?? [];
			$source_meta = array_filter( [
				'exported_by' => $meta_block['exported_by'] ?? null,
				'exported_at' => $meta_block['exported_at'] ?? null,
				'site_url'    => $meta_block['site_url']    ?? null,
				'plugin_ver'  => $meta_block['version']     ?? null,
				// ET native files sometimes carry app_ver or version at root.
				'app_version' => $data['app_ver'] ?? $data['version'] ?? null,
				'context'     => $data['context'] ?? null,
			] );

			$result = match ( $type ) {
				'vars'              => $this->import_json_vars( $data, $label_overrides, $skip_ids ),
				'presets'           => $this->import_json_presets( $data, $label_overrides, $skip_ids ),
				'layouts'           => $this->import_json_posts( $data, 'et_pb_layout' ),
				'pages'             => $this->import_json_posts( $data, 'page' ),
				'theme_customizer'  => $this->import_json_theme_customizer( $data ),
				'builder_templates' => $this->import_json_builder_templates( $data ),
				'et_native'         => $this->import_json_et_native( $data ),
				'dtcg'              => $this->import_json_dtcg( $data ),
				default             => [ 'success' => false, 'message' => 'Unknown type.' ],
			};

			return array_merge(
				[
					'name'        => $name,
					'type'        => $type,
					'type_label'  => $this->resolve_type_label( $data, $type ),
					'source_meta' => $source_meta,
					'imported_at' => gmdate( 'c' ),
				],
				$result
			);
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			return [ 'name' => $name, 'success' => false, 'message' => $e->getMessage() ];
		}
	}

	/**
	 * Import an xlsx file using the existing importer (dry_run already done at analyze time).
	 *
	 * @param string      $abs_path Absolute path.
	 * @param string|null $type     File type key.
	 * @return array Result entry.
	 */
	private function import_xlsx_file( string $abs_path, ?string $type, string $name = '' ): array {
		$display = $name ?: basename( $abs_path );
		if ( ! $type ) {
			return [ 'name' => $display, 'success' => false, 'message' => 'Cannot determine file type.' ];
		}
		try {
			$importer = $this->build_xlsx_importer( $type, $abs_path );
			$result   = $importer->commit();
			// xlsx importers return updated/new/skipped at top level; groups/items not yet available.
			$groups = [ $type => [
				'new'     => $result['new']     ?? 0,
				'updated' => $result['updated'] ?? 0,
				'skipped' => $result['skipped'] ?? 0,
			] ];
			return [
				'name'        => $display,
				'type'        => $type,
				'type_label'  => $this->resolve_type_label( [], $type ),
				'success'     => true,
				'updated'     => $result['updated'] ?? 0,
				'new'         => $result['new']     ?? 0,
				'skipped'     => $result['skipped'] ?? 0,
				'groups'      => $groups,
				'items'       => $result['items']  ?? [],
				'source_meta' => [],
				'imported_at' => gmdate( 'c' ),
				'message'     => sprintf(
					'%d updated, %d new, %d skipped.',
					$result['updated'] ?? 0,
					$result['new']     ?? 0,
					$result['skipped'] ?? 0
				),
			];
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			return [ 'name' => basename( $abs_path ), 'success' => false, 'message' => $e->getMessage() ];
		}
	}

	// ── JSON importers ────────────────────────────────────────────────────────

	/**
	 * Import JSON global variables additively.
	 *
	 * @param array $data            Decoded JSON.
	 * @param array $label_overrides Optional map of { var_id => new_label } applied before saving.
	 * @param array $skip_ids        DSO IDs to skip during import.
	 * @return array{ success: bool, updated: int, new: int, skipped: int, message: string }
	 */
	private function import_json_vars( array $data, array $label_overrides = [], array $skip_ids = [] ): array {
		self::reset_sanitization_log();
		$vars = $data['et_divi_global_variables'] ?? [];
		$repo = new VarsRepository();

		// Snapshot before write.
		SnapshotManager::push( 'vars', $repo->get_raw(), 'import', 'JSON import — vars' );

		$cur     = $repo->get_raw();
		$updated = 0;
		$added   = 0;
		$skipped = 0;
		$groups  = [];  // per-type counts: [ var_type => [ 'new' => n, 'updated' => n ] ]
		$items   = [];  // per-item detail: [ [ 'group'=>type, 'id'=>id, 'label'=>label, 'value'=>value, 'status'=>new|updated|skipped ] ]

		foreach ( $vars as $var_type => $type_items ) {
			if ( ! is_array( $type_items ) ) { continue; }
			$var_type = self::sanitize_and_log( $var_type, 'Variable type key', 'var_type', 'key' );
			if ( ! isset( $groups[ $var_type ] ) ) {
				$groups[ $var_type ] = [ 'new' => 0, 'updated' => 0 ];
			}
			foreach ( $type_items as $id => $item ) {
				$id = (string) $id;
				// Skip items the user chose to exclude via conflict resolution.
				if ( in_array( $id, $skip_ids, true ) ) {
					$skipped++;
					$items[] = [
						'group'  => $var_type,
						'id'     => $id,
						'label'  => $item['label'] ?? '',
						'value'  => $item['value'] ?? '',
						'status' => 'skipped',
					];
					continue;
				}
				$ctx      = 'Variable ' . $id;
				$is_new   = ! isset( $cur[ $var_type ][ $id ] );
				$existing = $cur[ $var_type ][ $id ] ?? [ 'id' => $id ];
				// Label override (from client-side import label editor) takes priority.
				$base_label        = $item['label'] ?? $existing['label'] ?? '';
				$existing['label']  = self::sanitize_and_log( $label_overrides[ $id ] ?? $base_label, $ctx, 'label' );
				$existing['value']  = self::sanitize_and_log( $item['value']  ?? $existing['value']  ?? '', $ctx, 'value' );
				$existing['status'] = self::sanitize_and_log( $item['status'] ?? $existing['status'] ?? 'active', $ctx, 'status', 'key' );
				$cur[ $var_type ][ $id ] = $existing;
				if ( $is_new ) {
					$added++;
					$groups[ $var_type ]['new']++;
				} else {
					$updated++;
					$groups[ $var_type ]['updated']++;
				}
				$items[] = [
					'group'  => $var_type,
					'id'     => $id,
					'label'  => $existing['label'],
					'value'  => $existing['value'],
					'status' => $is_new ? 'new' : 'updated',
				];
			}
		}

		$repo->save_raw( $cur );

		return [
			'success'          => true,
			'updated'          => $updated,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => $groups,
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d updated, %d new, %d skipped.', $updated, $added, $skipped ),
		];
	}

	/**
	 * Import DTCG (Design Token Community Group) JSON format additively.
	 *
	 * Supports files exported by this plugin's DtcgExporter as well as
	 * third-party DTCG files. Recognised top-level groups:
	 *   color       → Divi type 'colors'
	 *   dimension   → Divi type 'numbers'  (value kept as-is, includes CSS unit)
	 *   number      → Divi type 'numbers'  (bare numeric value)
	 *   fontFamily  → Divi type 'fonts'
	 *   string      → Divi type 'strings'
	 *
	 * Token ID comes from the d5dsh:id extension when present; otherwise the
	 * token key is used as-is. $description becomes label; $value becomes value.
	 *
	 * @param array $data Decoded DTCG JSON.
	 * @return array{ success: bool, updated: int, new: int, message: string }
	 */
	private function import_json_dtcg( array $data ): array {
		self::reset_sanitization_log();
		// Map DTCG group key → Divi variable type.
		$group_map = [
			'color'      => 'colors',
			'dimension'  => 'numbers',
			'number'     => 'numbers',
			'fontFamily' => 'fonts',
			'string'     => 'strings',
		];

		$repo = new VarsRepository();
		SnapshotManager::push( 'vars', $repo->get_raw(), 'import', 'DTCG import' );

		$cur     = $repo->get_raw();
		$updated = 0;
		$added   = 0;
		$skipped = 0;
		$groups  = [];
		$items   = [];

		foreach ( $group_map as $dtcg_group => $var_type ) {
			if ( ! isset( $data[ $dtcg_group ] ) || ! is_array( $data[ $dtcg_group ] ) ) {
				continue;
			}
			if ( ! isset( $groups[ $var_type ] ) ) {
				$groups[ $var_type ] = [ 'new' => 0, 'updated' => 0 ];
			}

			foreach ( $data[ $dtcg_group ] as $token_key => $token ) {
				if ( ! is_array( $token ) || ! isset( $token['$value'] ) ) {
					$skipped++;
					continue;
				}

				// Use d5dsh:id extension when available for round-trip fidelity.
				$raw_id = (string) ( $token['extensions']['d5dsh:id'] ?? $token_key );
				$ctx    = 'DTCG token ' . $raw_id;
				$id     = self::sanitize_and_log( $raw_id, $ctx, 'id', 'key' );
				$label  = self::sanitize_and_log( (string) ( $token['$description'] ?? $token_key ), $ctx, 'label' );
				$value  = self::sanitize_and_log( (string) $token['$value'], $ctx, 'value' );

				$is_new                      = ! isset( $cur[ $var_type ][ $id ] );
				$existing                    = $cur[ $var_type ][ $id ] ?? [ 'id' => $id ];
				$existing['label']           = $label;
				$existing['value']           = $value;
				$existing['status']          = self::sanitize_and_log( $token['extensions']['d5dsh:status'] ?? $existing['status'] ?? 'active', $ctx, 'status', 'key' );
				$cur[ $var_type ][ $id ]     = $existing;

				if ( $is_new ) {
					$added++;
					$groups[ $var_type ]['new']++;
				} else {
					$updated++;
					$groups[ $var_type ]['updated']++;
				}
				$items[] = [
					'group'  => $var_type,
					'id'     => $id,
					'label'  => $label,
					'value'  => $value,
					'status' => $is_new ? 'new' : 'updated',
				];
			}
		}

		$repo->save_raw( $cur );

		return [
			'success'          => true,
			'updated'          => $updated,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => $groups,
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d updated, %d new, %d skipped.', $updated, $added, $skipped ),
		];
	}

	/**
	 * Import JSON presets additively.
	 *
	 * @param array $data Decoded JSON.
	 * @param array $label_overrides Optional map of { preset_id => new_label } applied before saving.
	 * @param array $skip_ids        DSO IDs to skip during import.
	 * @return array
	 */
	private function import_json_presets( array $data, array $label_overrides = [], array $skip_ids = [] ): array {
		self::reset_sanitization_log();
		$raw  = $data['et_divi_builder_global_presets_d5'] ?? [];
		$repo = new PresetsRepository();

		SnapshotManager::push( 'presets', $repo->get_raw(), 'import', 'JSON import — presets' );

		$cur     = $repo->get_raw();
		$updated = 0;
		$added   = 0;
		$skipped = 0;
		$groups  = [];
		$items   = [];

		// Module presets.
		foreach ( $raw['module'] ?? [] as $mod => $mod_data ) {
			if ( ! isset( $cur['module'][ $mod ] ) ) {
				$cur['module'][ $mod ] = [ 'default' => '', 'items' => [] ];
			}
			$group_key = 'module:' . $mod;
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = [ 'new' => 0, 'updated' => 0, 'label' => $mod ];
			}
			foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
				if ( in_array( (string) $pid, $skip_ids, true ) ) {
					$skipped++;
					$items[] = [
						'group'  => 'Element Preset — ' . $mod,
						'id'     => $pid,
						'label'  => $preset['name'] ?? $preset['label'] ?? $pid,
						'value'  => '',
						'status' => 'skipped',
					];
					continue;
				}
				$is_new = ! isset( $cur['module'][ $mod ]['items'][ $pid ] );
				if ( isset( $label_overrides[ $pid ] ) ) {
					$preset['name']  = $label_overrides[ $pid ];
					$preset['label'] = $label_overrides[ $pid ];
				}
				$preset = self::sanitize_preset( $preset, 'Element Preset ' . $pid . ' (' . $mod . ')' );
				$cur['module'][ $mod ]['items'][ $pid ] = $preset;
				$is_new ? $added++ : $updated++;
				$is_new ? $groups[ $group_key ]['new']++ : $groups[ $group_key ]['updated']++;
				$items[] = [
					'group'  => 'Element Preset — ' . $mod,
					'id'     => $pid,
					'label'  => $preset['name'] ?? $preset['label'] ?? $pid,
					'value'  => '',
					'status' => $is_new ? 'new' : 'updated',
				];
			}
			if ( ! empty( $mod_data['default'] ) ) {
				$cur['module'][ $mod ]['default'] = sanitize_text_field( $mod_data['default'] );
			}
		}

		// Group presets.
		foreach ( $raw['group'] ?? [] as $grp => $grp_data ) {
			if ( ! isset( $cur['group'][ $grp ] ) ) {
				$cur['group'][ $grp ] = [ 'default' => '', 'items' => [] ];
			}
			$group_key = 'group:' . $grp;
			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = [ 'new' => 0, 'updated' => 0, 'label' => $grp ];
			}
			foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
				if ( in_array( (string) $pid, $skip_ids, true ) ) {
					$skipped++;
					$items[] = [
						'group'  => 'Option Group Preset — ' . $grp,
						'id'     => $pid,
						'label'  => $preset['name'] ?? $preset['label'] ?? $pid,
						'value'  => '',
						'status' => 'skipped',
					];
					continue;
				}
				$is_new = ! isset( $cur['group'][ $grp ]['items'][ $pid ] );
				if ( isset( $label_overrides[ $pid ] ) ) {
					$preset['name']  = $label_overrides[ $pid ];
					$preset['label'] = $label_overrides[ $pid ];
				}
				$preset = self::sanitize_preset( $preset, 'Group Preset ' . $pid . ' (' . $grp . ')' );
				$cur['group'][ $grp ]['items'][ $pid ] = $preset;
				$is_new ? $added++ : $updated++;
				$is_new ? $groups[ $group_key ]['new']++ : $groups[ $group_key ]['updated']++;
				$items[] = [
					'group'  => 'Option Group Preset — ' . $grp,
					'id'     => $pid,
					'label'  => $preset['name'] ?? $preset['label'] ?? $pid,
					'value'  => '',
					'status' => $is_new ? 'new' : 'updated',
				];
			}
			if ( ! empty( $grp_data['default'] ) ) {
				$cur['group'][ $grp ]['default'] = sanitize_text_field( $grp_data['default'] );
			}
		}

		$repo->save_raw( $cur );

		return [
			'success'          => true,
			'updated'          => $updated,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => $groups,
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d updated, %d new, %d skipped.', $updated, $added, $skipped ),
		];
	}

	/**
	 * Import JSON posts (layouts or pages) additively.
	 *
	 * Inserts new posts; skips existing (match by post_name + post_type).
	 * This is intentionally safe — we do not overwrite existing layouts.
	 *
	 * @param array  $data      Decoded JSON.
	 * @param string $post_type 'et_pb_layout' | 'page'.
	 * @return array
	 */
	private function import_json_posts( array $data, string $post_type ): array {
		self::reset_sanitization_log();
		$posts   = $data['posts'] ?? [];
		$added   = 0;
		$skipped = 0;
		$items   = [];
		$group   = $post_type === 'et_pb_layout' ? 'Layouts' : 'Pages';

		foreach ( $posts as $post ) {
			$post_name  = $post['post_name']  ?? '';
			$ctx        = $group . ' "' . ( $post['post_title'] ?? $post_name ) . '"';
			$post_title = self::sanitize_and_log( $post['post_title'] ?? '', $ctx, 'post_title' );
			// Check for existing post with same slug and type.
			$existing = get_posts( [
				'post_type'   => $post_type,
				'name'        => $post_name,
				'post_status' => 'any',
				'numberposts' => 1,
			] );

			if ( $existing ) {
				$skipped++;
				$items[] = [ 'group' => $group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
				continue;
			}

			$insert = [
				'post_title'   => $post_title,
				'post_name'    => self::sanitize_and_log( $post_name, $ctx, 'post_name', 'title' ),
				'post_status'  => self::sanitize_and_log( $post['post_status'] ?? 'publish', $ctx, 'post_status', 'key' ),
				'post_type'    => $post_type,
				'post_date'    => self::sanitize_and_log( $post['post_date'] ?? '', $ctx, 'post_date' ),
				'menu_order'   => (int) ( $post['menu_order'] ?? 0 ),
				'post_parent'  => 0,
				'post_content' => self::sanitize_and_log( $post['post_content'] ?? '', $ctx, 'post_content', 'kses' ),
			];

			$new_id = wp_insert_post( $insert, true );
			if ( is_wp_error( $new_id ) ) {
				$skipped++;
				$items[] = [ 'group' => $group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
				continue;
			}

			// Restore post meta.
			foreach ( $post['post_meta'] ?? [] as $meta_key => $meta_value ) {
				$safe_key = self::sanitize_and_log( $meta_key, $ctx, 'meta_key', 'key' );
				if ( is_array( $meta_value ) ) {
					foreach ( $meta_value as $v ) {
						add_post_meta( $new_id, $safe_key, self::sanitize_meta_value( $v, $ctx . ' meta ' . $safe_key ) );
					}
				} else {
					update_post_meta( $new_id, $safe_key, self::sanitize_meta_value( $meta_value, $ctx . ' meta ' . $safe_key ) );
				}
			}

			$added++;
			$items[] = [ 'group' => $group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'new' ];
		}

		return [
			'success'          => true,
			'updated'          => 0,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => [ $group => [ 'new' => $added, 'updated' => 0, 'skipped' => $skipped ] ],
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d added, %d skipped (already exist).', $added, $skipped ),
		];
	}

	/**
	 * Import JSON theme customizer settings additively.
	 *
	 * @param array $data Decoded JSON.
	 * @return array
	 */
	private function import_json_theme_customizer( array $data ): array {
		self::reset_sanitization_log();
		$mods = $data['theme_mods_Divi'] ?? [];
		$cur  = get_option( ThemeCustomizerRepository::OPTION_KEY, [] );

		$updated = 0;
		$added   = 0;
		$items   = [];
		foreach ( $mods as $key => $val ) {
			$ctx         = 'Theme Customizer "' . $key . '"';
			$key         = self::sanitize_and_log( $key, $ctx, 'key', 'key' );
			$val         = self::sanitize_deep( $val, $ctx );
			$is_new      = ! array_key_exists( $key, $cur );
			$cur[ $key ] = $val;
			$is_new ? $added++ : $updated++;
			$items[] = [
				'group'  => 'Theme Customizer',
				'id'     => $key,
				'label'  => $key,
				'value'  => is_scalar( $val ) ? (string) $val : wp_json_encode( $val ),
				'status' => $is_new ? 'new' : 'updated',
			];
		}

		update_option( ThemeCustomizerRepository::OPTION_KEY, $cur );

		return [
			'success'          => true,
			'updated'          => $updated,
			'new'              => $added,
			'skipped'          => 0,
			'groups'           => [ 'Theme Customizer' => [ 'new' => $added, 'updated' => $updated ] ],
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d updated, %d new.', $updated, $added ),
		];
	}

	/**
	 * Import JSON builder templates additively.
	 *
	 * Inserts new templates; skips existing (match by post_name).
	 *
	 * @param array $data Decoded JSON.
	 * @return array
	 */
	private function import_json_builder_templates( array $data ): array {
		self::reset_sanitization_log();
		$templates = $data['et_template'] ?? [];
		$added     = 0;
		$skipped   = 0;
		$items     = [];

		foreach ( $templates as $tpl ) {
			$post_name  = $tpl['post_name']  ?? '';
			$ctx        = 'Builder Template "' . ( $tpl['post_title'] ?? $post_name ) . '"';
			$post_title = self::sanitize_and_log( $tpl['post_title'] ?? '', $ctx, 'post_title' );
			$existing   = $post_name ? get_posts( [
				'post_type'   => 'et_template',
				'name'        => $post_name,
				'post_status' => 'any',
				'numberposts' => 1,
			] ) : [];

			if ( $existing ) {
				$skipped++;
				$items[] = [ 'group' => 'Builder Templates', 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
				continue;
			}

			$insert = [
				'post_title'   => $post_title,
				'post_name'    => self::sanitize_and_log( $post_name, $ctx, 'post_name', 'title' ),
				'post_status'  => self::sanitize_and_log( $tpl['post_status'] ?? 'publish', $ctx, 'post_status', 'key' ),
				'post_type'    => 'et_template',
				'post_content' => self::sanitize_and_log( $tpl['post_content'] ?? '', $ctx, 'post_content', 'kses' ),
			];

			$new_id = wp_insert_post( $insert, true );
			if ( is_wp_error( $new_id ) ) {
				$skipped++;
				$items[] = [ 'group' => 'Builder Templates', 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
				continue;
			}

			foreach ( $tpl['post_meta'] ?? [] as $meta_key => $meta_val ) {
				$safe_key = self::sanitize_and_log( $meta_key, $ctx, 'meta_key', 'key' );
				update_post_meta( $new_id, $safe_key, self::sanitize_meta_value( $meta_val, $ctx . ' meta ' . $safe_key ) );
			}

			$added++;
			$items[] = [ 'group' => 'Builder Templates', 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'new' ];
		}

		return [
			'success'          => true,
			'updated'          => 0,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => [ 'Builder Templates' => [ 'new' => $added, 'updated' => 0, 'skipped' => $skipped ] ],
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => sprintf( '%d added, %d skipped (already exist).', $added, $skipped ),
		];
	}

	// ── xlsx dry-run ──────────────────────────────────────────────────────────

	/**
	 * Import an Elegant Themes native JSON file.
	 *
	 * ET files are omnibus — a single file contains global_variables, global_colors,
	 * presets, and layout/page data all together. Each section is imported additively.
	 *
	 * ET format differences from our plugin's format:
	 *   global_variables — flat array of { id, label, value, variableType/type, status }
	 *   global_colors    — array of [ "gcid-xxx", { color, label, status } ] pairs
	 *   presets          — { module: { 'divi/heading': { default, items: {id: preset} } }, group: {...} }
	 *   data             — dict keyed by post ID (for layouts/pages/templates)
	 *   context          — "et_builder" | "et_builder_layouts" | "et_divi_mods" | "et_template"
	 *
	 * @param array $data Decoded JSON.
	 * @return array{ success: bool, updated: int, new: int, skipped: int, message: string }
	 */
	private function import_json_et_native( array $data ): array {
		self::reset_sanitization_log();
		$updated = 0;
		$added   = 0;
		$skipped = 0;
		$errors  = [];
		$groups  = [];
		$items   = [];

		// ── 1. Global Variables ───────────────────────────────────────────────
		$gv_list = $data['global_variables'] ?? [];
		if ( is_array( $gv_list ) && count( $gv_list ) ) {
			$repo_vars = new VarsRepository();
			SnapshotManager::push( 'vars', $repo_vars->get_raw(), 'import', 'ET native JSON import — vars' );
			$cur_vars = $repo_vars->get_raw();
			foreach ( $gv_list as $item ) {
				if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
				$id       = $item['id'];
				$var_type = $item['variableType'] ?? $item['type'] ?? 'strings';
				$ctx      = 'ET Variable ' . $id;
				$is_new   = ! isset( $cur_vars[ $var_type ][ $id ] );
				$existing = $cur_vars[ $var_type ][ $id ] ?? [ 'id' => $id ];
				$existing['label']  = self::sanitize_and_log( $item['label']  ?? $existing['label']  ?? '', $ctx, 'label' );
				$existing['value']  = self::sanitize_and_log( $item['value']  ?? $existing['value']  ?? '', $ctx, 'value' );
				$existing['status'] = self::sanitize_and_log( $item['status'] ?? $existing['status'] ?? 'active', $ctx, 'status', 'key' );
				$cur_vars[ $var_type ][ $id ] = $existing;
				$is_new ? $added++ : $updated++;
				if ( ! isset( $groups[ $var_type ] ) ) { $groups[ $var_type ] = [ 'new' => 0, 'updated' => 0 ]; }
				$is_new ? $groups[ $var_type ]['new']++ : $groups[ $var_type ]['updated']++;
				$items[] = [ 'group' => $var_type, 'id' => $id, 'label' => $existing['label'], 'value' => $existing['value'], 'status' => $is_new ? 'new' : 'updated' ];
			}
			$repo_vars->save_raw( $cur_vars );
		}

		// ── 2. Global Colors ──────────────────────────────────────────────────
		// ET format: [ [ "gcid-xxx", { color, label, status } ], ... ]
		$gc_list = $data['global_colors'] ?? [];
		if ( is_array( $gc_list ) && count( $gc_list ) ) {
			// Colors are stored inside the presets option via VarsRepository.
			$repo_vars  = new VarsRepository();
			$cur_colors = $repo_vars->get_raw_colors();
			if ( ! isset( $groups['colors'] ) ) { $groups['colors'] = [ 'new' => 0, 'updated' => 0 ]; }
			foreach ( $gc_list as $pair ) {
				if ( ! is_array( $pair ) || count( $pair ) < 2 ) { continue; }
				$id      = $pair[0] ?? null;
				$payload = $pair[1] ?? [];
				if ( ! $id || ! is_array( $payload ) ) { continue; }
				$is_new   = ! isset( $cur_colors[ $id ] );
				$existing = $cur_colors[ $id ] ?? [ 'id' => $id ];
				$ctx                = 'ET Color ' . $id;
				$existing['id']     = $id;
				$existing['label']  = self::sanitize_and_log( $payload['label']  ?? $existing['label']  ?? '', $ctx, 'label' );
				$existing['color']  = self::sanitize_and_log( $payload['color']  ?? $existing['color']  ?? '', $ctx, 'color' );
				$existing['status'] = self::sanitize_and_log( $payload['status'] ?? $existing['status'] ?? 'active', $ctx, 'status', 'key' );
				$cur_colors[ $id ] = $existing;
				$is_new ? $added++ : $updated++;
				$is_new ? $groups['colors']['new']++ : $groups['colors']['updated']++;
				$items[] = [ 'group' => 'colors', 'id' => $id, 'label' => $existing['label'], 'value' => $existing['color'], 'status' => $is_new ? 'new' : 'updated' ];
			}
			$repo_vars->save_raw_colors( $cur_colors );
		}

		// ── 3. Presets ────────────────────────────────────────────────────────
		// ET presets format matches our DB format for module/group structure.
		$et_presets = $data['presets'] ?? [];
		if ( is_array( $et_presets ) && ( ! empty( $et_presets['module'] ) || ! empty( $et_presets['group'] ) ) ) {
			$repo_pr = new PresetsRepository();
			SnapshotManager::push( 'presets', $repo_pr->get_raw(), 'import', 'ET native JSON import — presets' );
			$cur_pr = $repo_pr->get_raw();
			foreach ( $et_presets['module'] ?? [] as $mod => $mod_data ) {
				if ( ! isset( $cur_pr['module'][ $mod ] ) ) {
					$cur_pr['module'][ $mod ] = [ 'default' => '', 'items' => [] ];
				}
				$gk = 'module:' . $mod;
				if ( ! isset( $groups[ $gk ] ) ) { $groups[ $gk ] = [ 'new' => 0, 'updated' => 0, 'label' => $mod ]; }
				foreach ( $mod_data['items'] ?? [] as $pid => $preset ) {
					$is_new = ! isset( $cur_pr['module'][ $mod ]['items'][ $pid ] );
					$preset = self::sanitize_preset( $preset, 'ET Element Preset ' . $pid . ' (' . $mod . ')' );
					$cur_pr['module'][ $mod ]['items'][ $pid ] = $preset;
					$is_new ? $added++ : $updated++;
					$is_new ? $groups[ $gk ]['new']++ : $groups[ $gk ]['updated']++;
					$items[] = [ 'group' => 'Element Preset — ' . $mod, 'id' => $pid, 'label' => $preset['name'] ?? $preset['label'] ?? $pid, 'value' => '', 'status' => $is_new ? 'new' : 'updated' ];
				}
				if ( ! empty( $mod_data['default'] ) ) {
					$cur_pr['module'][ $mod ]['default'] = self::sanitize_and_log( $mod_data['default'], 'ET module ' . $mod, 'default' );
				}
			}
			foreach ( $et_presets['group'] ?? [] as $grp => $grp_data ) {
				if ( ! isset( $cur_pr['group'][ $grp ] ) ) {
					$cur_pr['group'][ $grp ] = [ 'default' => '', 'items' => [] ];
				}
				$gk = 'group:' . $grp;
				if ( ! isset( $groups[ $gk ] ) ) { $groups[ $gk ] = [ 'new' => 0, 'updated' => 0, 'label' => $grp ]; }
				foreach ( $grp_data['items'] ?? [] as $pid => $preset ) {
					$is_new = ! isset( $cur_pr['group'][ $grp ]['items'][ $pid ] );
					$preset = self::sanitize_preset( $preset, 'ET Group Preset ' . $pid . ' (' . $grp . ')' );
					$cur_pr['group'][ $grp ]['items'][ $pid ] = $preset;
					$is_new ? $added++ : $updated++;
					$is_new ? $groups[ $gk ]['new']++ : $groups[ $gk ]['updated']++;
					$items[] = [ 'group' => 'Option Group Preset — ' . $grp, 'id' => $pid, 'label' => $preset['name'] ?? $preset['label'] ?? $pid, 'value' => '', 'status' => $is_new ? 'new' : 'updated' ];
				}
				if ( ! empty( $grp_data['default'] ) ) {
					$cur_pr['group'][ $grp ]['default'] = self::sanitize_and_log( $grp_data['default'], 'ET group ' . $grp, 'default' );
				}
			}
			$repo_pr->save_raw( $cur_pr );
		}

		// ── 4. Layout/Page/Template data ──────────────────────────────────────
		// ET 'data' is a dict: { post_id: { post_title, post_name, post_type,
		//   post_status, post_content, post_meta: {...}, terms: [...] } }
		$et_data = $data['data'] ?? [];
		$context = $data['context'] ?? '';
		if ( is_array( $et_data ) && count( $et_data ) ) {
			foreach ( $et_data as $post_id => $post ) {
				if ( ! is_array( $post ) ) { continue; }
				$post_name  = $post['post_name']  ?? '';
				$post_type  = $post['post_type']  ?? 'et_pb_layout';
				$ctx_post   = 'ET Post "' . ( $post['post_title'] ?? $post_name ) . '"';
				$post_title = self::sanitize_and_log( $post['post_title'] ?? '', $ctx_post, 'post_title' );
				$post_group = match ( $post_type ) {
					'page'        => 'Pages',
					'et_template' => 'Builder Templates',
					default       => 'Layouts',
				};
				if ( ! isset( $groups[ $post_group ] ) ) { $groups[ $post_group ] = [ 'new' => 0, 'updated' => 0, 'skipped' => 0 ]; }

				// Skip if post with same slug already exists.
				if ( $post_name ) {
					$existing = get_posts( [
						'post_type'   => $post_type,
						'name'        => $post_name,
						'post_status' => 'any',
						'numberposts' => 1,
					] );
					if ( $existing ) {
						$skipped++;
						$groups[ $post_group ]['skipped']++;
						$items[] = [ 'group' => $post_group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
						continue;
					}
				}

				$insert = [
					'post_title'   => $post_title,
					'post_name'    => $post_name ? self::sanitize_and_log( $post_name, $ctx_post, 'post_name', 'title' ) : '',
					'post_status'  => self::sanitize_and_log( $post['post_status'] ?? 'publish', $ctx_post, 'post_status', 'key' ),
					'post_type'    => $post_type,
					'post_content' => self::sanitize_and_log( $post['post_content'] ?? '', $ctx_post, 'post_content', 'kses' ),
				];
				$new_id = wp_insert_post( $insert, true );
				if ( is_wp_error( $new_id ) ) {
					$skipped++;
					$groups[ $post_group ]['skipped']++;
					$errors[] = $new_id->get_error_message();
					$items[] = [ 'group' => $post_group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'skipped' ];
					continue;
				}
				// Restore post meta.
				foreach ( $post['post_meta'] ?? [] as $meta_key => $meta_val ) {
					$safe_key = self::sanitize_and_log( $meta_key, $ctx_post, 'meta_key', 'key' );
					update_post_meta( $new_id, $safe_key, self::sanitize_meta_value( $meta_val, $ctx_post . ' meta ' . $safe_key ) );
				}
				$added++;
				$groups[ $post_group ]['new']++;
				$items[] = [ 'group' => $post_group, 'id' => $post_name, 'label' => $post_title, 'value' => '', 'status' => 'new' ];
			}
		}

		$summary = sprintf( '%d added, %d updated, %d skipped.', $added, $updated, $skipped );
		if ( $errors ) {
			$summary .= ' Errors: ' . implode( '; ', array_slice( $errors, 0, 3 ) );
		}

		return [
			'success'          => true,
			'updated'          => $updated,
			'new'              => $added,
			'skipped'          => $skipped,
			'groups'           => $groups,
			'items'            => $items,
			'sanitization_log' => self::get_sanitization_log(),
			'message'          => $summary,
		];
	}

	/**
	 * Run a dry-run on an xlsx file and return the diff.
	 *
	 * @param string $file_path
	 * @param string $type
	 * @return array
	 */
	private function run_xlsx_dry_run( string $file_path, string $type ): array {
		try {
			$importer = $this->build_xlsx_importer( $type, $file_path );
			return $importer->dry_run();
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			return [ 'changes' => [], 'new_entries' => [], 'parse_errors' => [ $e->getMessage() ], 'error' => $e->getMessage() ];
		}
	}

	/**
	 * Build the appropriate xlsx importer for the given type.
	 *
	 * @param string $type
	 * @param string $file_path
	 * @return object
	 */
	private function build_xlsx_importer( string $type, string $file_path ): object {
		return match ( $type ) {
			'vars'              => new VarsImporter( $file_path ),
			'presets'           => new PresetsImporter( $file_path ),
			'layouts'           => new LayoutsImporter( $file_path, 'layouts' ),
			'pages'             => new LayoutsImporter( $file_path, 'pages' ),
			'theme_customizer'  => new ThemeCustomizerImporter( $file_path ),
			'builder_templates' => new BuilderTemplatesImporter( $file_path ),
			default             => throw new \InvalidArgumentException( "Unknown type: $type" ),
		};
	}

	// ── AJAX: JSON → XLSX conversion ──────────────────────────────────────────

	/**
	 * Convert a cached JSON file to an .xlsx file and stream it to the browser.
	 *
	 * Expects JSON body: { file_key: 'filename.json' }
	 *
	 * For a single-file session, file_key is ignored.
	 * For a zip session, file_key must match a key in session['files'].
	 *
	 * The JSON is never imported — only converted to Excel.
	 *
	 * @return never
	 */
	public function ajax_json_to_xlsx(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$raw_body = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload  = $raw_body ? json_decode( $raw_body, true ) : [];
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		$file_key    = sanitize_text_field( $payload['file_key'] ?? '' );
		$session_key = 'd5dsh_si_' . get_current_user_id();
		$session     = get_transient( $session_key );

		if ( ! $session ) {
			wp_send_json_error( [ 'message' => 'Session expired. Please re-upload the file.' ], 400 );
		}

		try {
			if ( $session['type'] === 'single' ) {
				$abs_path = $session['tmp_path'] ?? '';
			} elseif ( $session['type'] === 'zip' ) {
				$extract_dir = $session['extract_dir'] ?? '';
				if ( ! $extract_dir || ! is_dir( $extract_dir ) ) {
					wp_send_json_error( [ 'message' => 'Zip session directory not found.' ], 400 );
				}
				$abs_path = $extract_dir . '/' . $file_key;

				// Security: Prevent path traversal attacks.
				$safe_path = self::validate_path_within( $abs_path, $extract_dir );
				if ( $safe_path === false ) {
					wp_send_json_error( [ 'message' => 'Invalid file path.' ], 400 );
				}
				$abs_path = $safe_path;
			} else {
				wp_send_json_error( [ 'message' => 'Unknown session type.' ], 400 );
			}

			if ( ! $abs_path || ! file_exists( $abs_path ) ) {
				wp_send_json_error( [ 'message' => 'File not found in session.' ], 400 );
			}

			$json = file_get_contents( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) ) {
				wp_send_json_error( [ 'message' => 'Invalid JSON in file.' ], 400 );
			}

			$type = $this->detect_json_type( $data );
			if ( ! $type ) {
				wp_send_json_error( [ 'message' => 'Cannot detect data type from JSON envelope.' ], 400 );
			}

			// Build the spreadsheet from JSON data (without touching the DB).
			$ss          = $this->json_to_xlsx_spreadsheet( $data, $type );
			$source_name = $file_key ?: ( $session['display_name'] ?? '' );
			$basename    = pathinfo( basename( $source_name ?: 'export' ), PATHINFO_FILENAME );
			$filename    = $basename . '.xlsx';

			ExportUtil::stream_xlsx( $ss, $filename );
			// stream_xlsx() calls exit — never reaches here.
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			wp_send_json_error( [ 'message' => 'Conversion failed: ' . $e->getMessage() ], 500 );
		}
	}

	/**
	 * Build a Spreadsheet object from decoded JSON data without reading from the DB.
	 *
	 * Routes to type-specific builders. For ET native (omnibus) files, builds a
	 * combined workbook with all available data sections.
	 *
	 * @param array  $data Decoded JSON.
	 * @param string $type Detected type key.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function json_to_xlsx_spreadsheet( array $data, string $type ): Spreadsheet {
		switch ( $type ) {
			case 'vars':
				return $this->build_vars_xlsx_from_json( $data['et_divi_global_variables'] ?? [] );

			case 'presets':
				return $this->build_presets_xlsx_from_json( $data['et_divi_builder_global_presets_d5'] ?? [] );

			case 'et_native':
				return $this->build_et_native_xlsx_from_json( $data );

			case 'layouts':
			case 'pages':
				return $this->build_posts_xlsx_from_json( $data['posts'] ?? [], $type );

			case 'theme_customizer':
				return $this->build_theme_customizer_xlsx_from_json( $data['theme_mods_Divi'] ?? [] );

			case 'builder_templates':
				return $this->build_posts_xlsx_from_json( $data['et_template'] ?? [], $type );

			default:
				throw new \InvalidArgumentException( "No xlsx builder for type: $type" );
		}
	}

	/**
	 * Build a vars-style xlsx from a nested {type: {id: {label, value, status}}} dict.
	 *
	 * Produces the same sheet structure as VarsExporter (Colors, Numbers, Fonts,
	 * Images, Text, Links) so the output can be round-tripped through VarsImporter.
	 *
	 * @param array $vars_dict The et_divi_global_variables array.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_vars_xlsx_from_json( array $vars_dict ): Spreadsheet {
		// Flatten the nested dict into the normalised flat list VarsExporter expects.
		// ET type key 'strings' maps to our 'strings' type (sheet name 'Text').
		$normalized = [];
		$order_by_type = [];

		foreach ( $vars_dict as $var_type => $items ) {
			if ( ! is_array( $items ) ) { continue; }
			foreach ( $items as $id => $item ) {
				$order_by_type[ $var_type ] = ( $order_by_type[ $var_type ] ?? 0 ) + 1;
				$normalized[] = [
					'id'     => $id,
					'label'  => $item['label']  ?? $item['name'] ?? '',
					'value'  => $item['value']  ?? '',
					'status' => $item['status'] ?? 'active',
					'type'   => $var_type,
					'order'  => $item['order'] ?? $order_by_type[ $var_type ],
					'system' => false,
					'hidden' => false,
				];
			}
		}

		// Delegate to VarsExporter so the output is structurally identical to a
		// regular export — same sheet names, columns, formatting, and protection.
		return ( new VarsExporter() )->build_spreadsheet_from_normalized( $normalized );
	}

	/**
	 * Build a presets xlsx from a {module: {...}, group: {...}} dict.
	 *
	 * @param array $presets_dict The et_divi_builder_global_presets_d5 array.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_presets_xlsx_from_json( array $presets_dict ): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		ExportUtil::build_info_sheet( $ss, 'presets (from JSON)' );
		ExportUtil::add_presets_sheets( $ss, $presets_dict );
		ExportUtil::build_config_sheet( $ss, $presets_dict, 'et_divi_builder_global_presets_d5', 'presets' );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	/**
	 * Build a combined xlsx from an ET native omnibus JSON.
	 *
	 * Produces: Info, Global Variables (flat), Global Colors, Presets-Modules,
	 * Presets-Groups, and Posts sheets as available.
	 *
	 * @param array $data Full decoded ET native JSON.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_et_native_xlsx_from_json( array $data ): Spreadsheet {
		// ── Normalize global_variables + global_colors into VarsExporter format ──
		// ET global_variables: flat array of {id, label, value, variableType, status, order}
		// ET global_colors:    array of [id, {color, label, status, order?}] pairs
		$normalized = [];
		$i = 1;
		foreach ( $data['global_variables'] ?? [] as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) { continue; }
			$normalized[] = [
				'id'     => $item['id'],
				'label'  => $item['label']        ?? '',
				'value'  => $item['value']        ?? '',
				'type'   => $item['variableType'] ?? $item['type'] ?? 'strings',
				'status' => $item['status']       ?? 'active',
				'order'  => $item['order']        ?? $i,
				'system' => false,
				'hidden' => false,
			];
			$i++;
		}
		$ci = 1;
		foreach ( $data['global_colors'] ?? [] as $pair ) {
			$id    = is_array( $pair ) ? ( $pair[0] ?? null ) : null;
			$entry = is_array( $pair ) ? ( $pair[1] ?? [] ) : [];
			if ( ! $id ) { continue; }
			$normalized[] = [
				'id'     => $id,
				'label'  => $entry['label']  ?? '',
				'value'  => $entry['color']  ?? '',
				'type'   => 'colors',
				'status' => $entry['status'] ?? 'active',
				'order'  => $entry['order']  ?? $ci,
				'system' => false,
				'hidden' => false,
			];
			$ci++;
		}

		// Build vars sheets using VarsExporter so sheet names and columns match the importer.
		$ss = ( new VarsExporter() )->build_spreadsheet_from_normalized( $normalized );

		// ── Presets sheets ────────────────────────────────────────────────────
		$presets = $data['presets'] ?? [];
		if ( is_array( $presets ) && ( ! empty( $presets['module'] ) || ! empty( $presets['group'] ) ) ) {
			ExportUtil::add_presets_sheets( $ss, $presets );
		}

		// ── Posts/data sheet ─────────────────────────────────────────────────
		$et_data = $data['data'] ?? [];
		if ( is_array( $et_data ) && $et_data ) {
			$this->add_posts_sheet_from_et_data( $ss, $et_data );
		}

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	/**
	 * Build a posts/layouts xlsx from a 'posts' array.
	 *
	 * @param array  $posts Array of post objects.
	 * @param string $type  'layouts' | 'pages' | 'builder_templates'
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_posts_xlsx_from_json( array $posts, string $type ): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		ExportUtil::build_info_sheet( $ss, $type . ' (from JSON)' );

		$ws = $ss->createSheet();
		$ws->setTitle( ucfirst( $type ) );

		$headers = [ 'ID', 'Title', 'Post Type', 'Post Name', 'Status', 'Date', 'Menu Order', 'Parent ID', 'Post Meta (JSON)', 'Terms (JSON)' ];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $posts as $post ) {
			ExportUtil::cell( $ws, 1, $row )->setValue( $post['ID']          ?? $post['id']          ?? '' );
			ExportUtil::cell( $ws, 2, $row )->setValue( $post['post_title']  ?? '' );
			ExportUtil::cell( $ws, 3, $row )->setValue( $post['post_type']   ?? '' );
			ExportUtil::cell( $ws, 4, $row )->setValue( $post['post_name']   ?? '' );
			ExportUtil::cell( $ws, 5, $row )->setValue( $post['post_status'] ?? '' );
			ExportUtil::cell( $ws, 6, $row )->setValue( $post['post_date']   ?? '' );
			ExportUtil::cell( $ws, 7, $row )->setValue( $post['menu_order']  ?? 0 );
			ExportUtil::cell( $ws, 8, $row )->setValue( $post['post_parent'] ?? 0 );
			$meta = $post['post_meta'] ?? $post['meta'] ?? [];
			ExportUtil::cell( $ws, 9, $row )->setValue( $meta ? wp_json_encode( $meta ) : '' );
			$terms = $post['terms'] ?? [];
			ExportUtil::cell( $ws, 10, $row )->setValue( $terms ? wp_json_encode( $terms ) : '' );
			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, $row - 1, count( $headers ) );
		ExportUtil::set_column_widths( $ws, [ 8, 30, 16, 24, 10, 20, 10, 10, 50, 30 ] );

		ExportUtil::build_config_sheet( $ss, $posts, 'posts', $type );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	/**
	 * Build a theme customizer xlsx from a theme_mods_Divi array.
	 *
	 * @param array $mods Key/value pairs.
	 * @return Spreadsheet
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function build_theme_customizer_xlsx_from_json( array $mods ): Spreadsheet {
		$ss = new Spreadsheet();
		$ss->removeSheetByIndex( 0 );

		ExportUtil::build_info_sheet( $ss, 'theme_customizer (from JSON)' );

		$ws = $ss->createSheet();
		$ws->setTitle( 'Settings' );

		$headers = [ 'Key', 'Value' ];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $mods as $key => $value ) {
			ExportUtil::cell( $ws, 1, $row )->setValue( $key );
			$val_str = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;
			ExportUtil::cell( $ws, 2, $row )->setValue( $val_str );
			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, $row - 1, 2 );
		ExportUtil::set_column_widths( $ws, [ 40, 80 ] );

		ExportUtil::build_config_sheet( $ss, $mods, 'theme_mods_Divi', 'theme_customizer' );

		$ss->setActiveSheetIndex( 0 );
		return $ss;
	}

	/**
	 * Add a 'Data' sheet from ET native 'data' dict to an existing Spreadsheet.
	 *
	 * ET data format: { post_id: { post_title, post_name, post_type, post_content, post_meta, ... } }
	 *
	 * @param Spreadsheet $ss
	 * @param array       $et_data
	 * @return void
	 * @throws \PhpOffice\PhpSpreadsheet\Exception
	 */
	private function add_posts_sheet_from_et_data( Spreadsheet $ss, array $et_data ): void {
		$ws = $ss->createSheet();
		$ws->setTitle( 'Data' );

		$headers = [ 'Original ID', 'Title', 'Post Type', 'Post Name', 'Status', 'Post Meta (JSON)' ];
		ExportUtil::write_header_row( $ws, $headers );

		$row = 2;
		foreach ( $et_data as $original_id => $post ) {
			if ( ! is_array( $post ) ) { continue; }
			ExportUtil::cell( $ws, 1, $row )->setValue( $original_id );
			ExportUtil::cell( $ws, 2, $row )->setValue( $post['post_title']  ?? '' );
			ExportUtil::cell( $ws, 3, $row )->setValue( $post['post_type']   ?? '' );
			ExportUtil::cell( $ws, 4, $row )->setValue( $post['post_name']   ?? '' );
			ExportUtil::cell( $ws, 5, $row )->setValue( $post['post_status'] ?? '' );
			$meta = $post['post_meta'] ?? [];
			ExportUtil::cell( $ws, 6, $row )->setValue( $meta ? wp_json_encode( $meta ) : '' );
			$row++;
		}

		ExportUtil::apply_sheet_formatting( $ws, $row - 1, count( $headers ) );
		ExportUtil::set_column_widths( $ws, [ 12, 30, 18, 26, 10, 60 ] );
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	/**
	 * Keep a temp file by copying it to a persistent temp location.
	 *
	 * PHP's uploaded tmp files are deleted after the request ends.
	 * We need to keep them for the execute request.
	 *
	 * @param string $source Existing temp path.
	 * @param string $ext    File extension.
	 * @return string|null Persistent path or null on failure.
	 */
	private function keep_tmp_file( string $source, string $ext ): ?string {
		$dest = tempnam( sys_get_temp_dir(), 'd5dsh_si_' ) . '.' . $ext;
		if ( copy( $source, $dest ) ) {
			return $dest;
		}
		return null;
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory to remove.
	 * @return void
	 */
	private function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $path ) {
			if ( $path->isDir() ) {
				rmdir( $path->getPathname() );
			} else {
				unlink( $path->getPathname() );
			}
		}
		rmdir( $dir );
	}
}
