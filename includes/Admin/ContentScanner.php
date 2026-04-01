<?php
/**
 * ContentScanner — scans all site content for Divi 5 DSO usage.
 *
 * Produces two complementary reports:
 *
 *   1. ACTIVE CONTENT REPORT
 *      Lists every post / layout / template that contains at least one
 *      variable reference or preset reference, grouped by post type.
 *      Also lists every variable and preset that is referenced, how many
 *      pieces of content use it, and the full per-content breakdown.
 *      "Active" = a piece of content that is actually used (published /
 *      draft / private / scheduled / pending) AND references at least one
 *      DSO, OR is built with any Divi preset — even if the preset has no
 *      variable references (i.e. it has been customised at the preset level).
 *
 *   2. CONTENT INVENTORY REPORT
 *      Lists every piece of Divi-aware content on the site — regardless of
 *      whether it references any DSOs — with its post type, status, title,
 *      last modified date, and the DSOs it uses.
 *      Theme Builder templates are expanded to show their header / body /
 *      footer canvases as child rows.
 *
 * ## Limitations and assumptions (read before using)
 *
 *   WHAT WORKS
 *   ✓  Pages (post_type = page, all statuses)
 *   ✓  Blog posts (post_type = post, all statuses)
 *   ✓  Divi Library layouts (et_pb_layout, all statuses)
 *   ✓  Theme Builder templates (et_template) + their header / body / footer
 *       canvases (et_header_layout, et_body_layout, et_footer_layout)
 *   ✓  Variable references ($variable(...)$ tokens) in post_content
 *   ✓  Preset references (modulePreset / presetId keys) in post_content
 *   ✓  Third-party Divi 5-compliant modules that use the standard token API
 *   ✓  All post statuses: publish, draft, pending, private, future, trash
 *   ✓  Template → canvas parent/child association via _et_*_layout_id meta
 *
 *   WHAT DOES NOT WORK (known gaps)
 *   ✗  Custom post types (CPTs): only the eight post types listed above are
 *      queried.  A CPT built with Divi will be invisible to this scanner.
 *      Future Pro version: auto-detect CPTs that use et_builder, add them.
 *   ✗  Content in ACF / meta fields: only post_content is scanned.  If a
 *      third-party plugin stores Divi block markup in custom meta, it will
 *      not be found.
 *   ✗  Divi 4 / Classic Builder content: the $variable(...)$ token system
 *      did not exist in Divi 4.  Legacy blocks produce zero DSO hits, which
 *      is correct — they are not part of the design system.
 *   ✗  Variable references inside preset attrs are NOT shown in content rows.
 *      Preset→variable links are reported by AuditEngine, not here.
 *   ✗  Performance on very large sites: the scanner loads up to
 *      CONTENT_LIMIT posts.  Sites with more content than that will be
 *      partially scanned.  The meta count in the report indicates whether
 *      the limit was reached.
 *   ✗  Canvases of TRASHED templates: get_posts skips trash for et_template
 *      by default; canvas posts with no parent may still appear in the
 *      inventory individually.
 *
 *   ASSUMPTIONS
 *   •  post_content is the authoritative source for DSO references in page
 *      content.  This is true for all standard Divi 5 block markup.
 *   •  The $variable(...)$ token format and preset key format are as
 *      described in docs/SERIALIZATION_SPEC.md.  Third-party formats that
 *      deviate from this spec will not be detected.
 *   •  Canvas posts (et_header_layout etc.) are associated to their parent
 *      et_template via _et_header_layout_id / _et_body_layout_id /
 *      _et_footer_layout_id post meta on the template record.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Util\DiviBlocParser;
use D5DesignSystemHelper\Util\DebugLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ContentScanner
 */
class ContentScanner {

	// ── Configuration ─────────────────────────────────────────────────────────

	/**
	 * Maximum number of content rows loaded per query.
	 * Raised to 1 000 to cover medium-to-large sites.
	 * Sites exceeding this will be partially scanned — the meta section of the
	 * report includes a 'limit_reached' flag when the full count hits the ceiling.
	 */
	const CONTENT_LIMIT = 1000;

	/**
	 * Post types included in the content scan.
	 * Custom post types are intentionally excluded — see class docblock.
	 */
	const SCANNED_POST_TYPES = [
		'page',
		'post',
		'et_pb_layout',
		'et_template',
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
	];

	/**
	 * Post statuses included in the content scan.
	 * All non-auto-draft statuses are included so that draft content that
	 * uses DSOs is not incorrectly treated as orphaned.
	 */
	const SCANNED_STATUSES = [
		'publish',
		'draft',
		'pending',
		'private',
		'future',
		'trash',
	];

	/**
	 * Canvas post types — children of et_template via post meta.
	 */
	const CANVAS_POST_TYPES = [
		'et_header_layout',
		'et_body_layout',
		'et_footer_layout',
	];

	/**
	 * Meta keys that link an et_template to its canvas child post IDs.
	 * Key = meta key, value = canvas label shown in reports.
	 */
	const CANVAS_META_KEYS = [
		'_et_header_layout_id' => 'Header',
		'_et_body_layout_id'   => 'Body',
		'_et_footer_layout_id' => 'Footer',
	];

	// ── AJAX ─────────────────────────────────────────────────────────────────

	/**
	 * AJAX handler for content scan.
	 * Hooked to wp_ajax_d5dsh_content_scan.
	 */
	public function ajax_run(): void {
		check_ajax_referer( 'd5dsh_audit_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		try {
			$report = $this->run();
		} catch ( \Throwable $e ) {
			DebugLogger::send_error( $e, __METHOD__, 'Content scan failed.' );
		}
		wp_send_json_success( $report );
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Run the full content scan and return both reports.
	 *
	 * @return array{
	 *   active_content:   array,
	 *   inventory:        array,
	 *   dso_usage:        array,
	 *   no_dso_content:   array,
	 *   meta:             array,
	 * }
	 */
	public function run(): array {
		$rows            = $this->load_content_rows();
		$template_map    = $this->load_template_canvas_map();
		$canvas_post_ids = array_merge( ...array_values( $template_map ) );

		// Build a preset-ID → variable-refs map once so we can look up vars
		// that any preset references (used to compute tot/uniq vars in presets).
		$preset_var_map = $this->build_preset_var_map();

		// Scan every row for DSO references, then enrich with preset-variable counts.
		$scanned = [];
		foreach ( $rows as $row ) {
			$scanned[] = $this->enrich_with_preset_vars( $this->scan_row( $row ), $preset_var_map );
		}

		return [
			'active_content'   => $this->build_active_content_report( $scanned ),
			'inventory'        => $this->build_inventory_report( $scanned, $template_map, $canvas_post_ids ),
			'dso_usage'        => $this->build_dso_usage_index( $scanned ),
			'no_dso_content'   => $this->build_no_dso_report( $scanned ),
			'preset_var_map'   => $preset_var_map,
			'var_info_map'     => $this->build_var_info_map(),
			'preset_info_map'  => $this->build_preset_info_map(),
			'meta'             => $this->build_meta( $rows, $scanned ),
		];
	}

	// ── Data loading ──────────────────────────────────────────────────────────

	/**
	 * Load all content rows from the database.
	 *
	 * Returns an array of associative arrays with keys:
	 *   post_id, post_type, post_status, post_title,
	 *   post_modified, post_content, post_parent
	 *
	 * @return array[]
	 */
	protected function load_content_rows(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		$types_in    = implode( ',', array_fill( 0, count( self::SCANNED_POST_TYPES ), '%s' ) );
		$statuses_in = implode( ',', array_fill( 0, count( self::SCANNED_STATUSES ), '%s' ) );

		// Collect all prepare() args into one array so no positional arg follows
		// a spread — PHP does not allow that syntax regardless of version.
		$prepare_args = array_merge(
			self::SCANNED_POST_TYPES,
			self::SCANNED_STATUSES,
			[ self::CONTENT_LIMIT ]
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID AS post_id,
				        post_type,
				        post_status,
				        post_title,
				        post_modified,
				        post_content,
				        post_parent
				 FROM   {$wpdb->posts}
				 WHERE  post_type   IN ( {$types_in} )
				   AND  post_status IN ( {$statuses_in} )
				   AND  post_status != 'auto-draft'
				 ORDER  BY post_type ASC, ID ASC
				 LIMIT  %d",
				...$prepare_args
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Load the template → canvas post-ID map.
	 *
	 * Returns:  [ template_post_id => [ 'Header' => id, 'Body' => id, 'Footer' => id ], ... ]
	 * Missing canvas slots are absent from the inner array.
	 *
	 * @return array<int, array<string, int>>
	 */
	protected function load_template_canvas_map(): array {
		global $wpdb;

		if ( ! isset( $wpdb ) ) {
			return [];
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS template_id,
				        pm.meta_key,
				        pm.meta_value AS canvas_id
				 FROM   {$wpdb->posts} p
				 JOIN   {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE  p.post_type = 'et_template'
				   AND  pm.meta_key IN ('_et_header_layout_id','_et_body_layout_id','_et_footer_layout_id')",
				[]
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = [];
		foreach ( ( is_array( $rows ) ? $rows : [] ) as $row ) {
			$tid   = (int) $row['template_id'];
			$label = self::CANVAS_META_KEYS[ $row['meta_key'] ] ?? $row['meta_key'];
			$cid   = (int) $row['canvas_id'];
			if ( $cid > 0 ) {
				$map[ $tid ][ $label ] = $cid;
			}
		}

		return $map;
	}

	// ── Per-row scanning ──────────────────────────────────────────────────────

	/**
	 * Scan a single content row and return an enriched record.
	 *
	 * @param array $row  Raw DB row (post_id, post_type, post_status,
	 *                    post_title, post_modified, post_content, post_parent).
	 * @return array Enriched record with added keys:
	 *               var_refs    array[] — [ ['type'=>string, 'name'=>string], ... ]
	 *               preset_refs string[] — preset IDs
	 *               has_dso     bool
	 */
	protected function scan_row( array $row ): array {
		$content = (string) ( $row['post_content'] ?? '' );

		$var_refs    = DiviBlocParser::extract_variable_refs( $content );
		$preset_refs = DiviBlocParser::extract_preset_refs( $content );

		return array_merge( $row, [
			'var_refs'    => $var_refs,
			'preset_refs' => $preset_refs,
			'has_dso'     => ( $var_refs !== [] || $preset_refs !== [] ),
		] );
	}

	/**
	 * Build a map of preset ID → array of variable refs contained in that preset.
	 *
	 * Reads every module and group preset from the design system and extracts
	 * the variable references from their attrs/styleAttrs.  The result is used
	 * in enrich_with_preset_vars() to compute per-content-row "vars in presets"
	 * counts without requiring a database round-trip for each row.
	 *
	 * @return array<string, array[]>  preset_id => [ ['type'=>string,'name'=>string], ... ]
	 */
	private function build_preset_var_map(): array {
		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();
		$map  = [];

		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$attrs_string = DiviBlocParser::preset_attrs_to_string( $preset );
					$map[ $preset_id ] = DiviBlocParser::extract_variable_refs( $attrs_string );
				}
			}
		}

		return $map;
	}

	/**
	 * Enrich a scanned row with 'tot_vars_in_presets' and 'uniq_vars_in_presets'
	 * counts derived from the presets the row references.
	 *
	 * Both counts cover only variables found inside preset definitions — not
	 * direct variable references in post_content (those are in var_refs).
	 *
	 * @param array                $row            Scanned row from scan_row().
	 * @param array<string,array[]> $preset_var_map preset_id → var_refs from build_preset_var_map().
	 * @return array Enriched row with tot_vars_in_presets and uniq_vars_in_presets.
	 */
	private function enrich_with_preset_vars( array $row, array $preset_var_map ): array {
		$total     = 0;
		$seen_names = [];

		foreach ( $row['preset_refs'] as $preset_id ) {
			foreach ( $preset_var_map[ $preset_id ] ?? [] as $ref ) {
				$total++;
				$name = $ref['name'] ?? '';
				if ( $name !== '' ) {
					$seen_names[ $name ] = true;
				}
			}
		}

		$row['tot_vars_in_presets']  = $total;
		$row['uniq_vars_in_presets'] = count( $seen_names );
		return $row;
	}

	// ── Report builders ───────────────────────────────────────────────────────

	/**
	 * Build the active content report.
	 *
	 * Returns only rows where has_dso === true, grouped by post_type.
	 *
	 * Shape:
	 *   [
	 *     'total' => int,
	 *     'by_type' => [
	 *       'page' => [ row, ... ],
	 *       'et_pb_layout' => [ row, ... ],
	 *       ...
	 *     ]
	 *   ]
	 *
	 * Each row in by_type omits post_content (too large to send to JS).
	 *
	 * @param array[] $scanned
	 * @return array
	 */
	protected function build_active_content_report( array $scanned ): array {
		$by_type = [];
		$total   = 0;

		foreach ( $scanned as $row ) {
			if ( ! $row['has_dso'] ) {
				continue;
			}
			$type = $row['post_type'];
			$by_type[ $type ][] = $this->slim_row( $row );
			$total++;
		}

		return [
			'total'   => $total,
			'by_type' => $by_type,
		];
	}

	/**
	 * Build the content inventory report.
	 *
	 * Returns ALL scanned rows (active or not), with Theme Builder templates
	 * expanded to include their canvas children as nested 'canvases' arrays.
	 * Canvas rows are NOT duplicated at the top level.
	 *
	 * Shape:
	 *   [
	 *     'total' => int,
	 *     'rows'  => [
	 *       {
	 *         post_id, post_type, post_status, post_title, post_modified,
	 *         has_dso, var_refs, preset_refs,
	 *         canvases?: [ { canvas_label, ...same fields... }, ... ]
	 *       },
	 *       ...
	 *     ]
	 *   ]
	 *
	 * @param array[]              $scanned
	 * @param array<int,array>     $template_map   template_id → [label → canvas_id]
	 * @param int[]                $canvas_post_ids All canvas IDs (to exclude from top level)
	 * @return array
	 */
	protected function build_inventory_report(
		array $scanned,
		array $template_map,
		array $canvas_post_ids
	): array {
		// Index scanned rows by post_id for canvas lookup.
		$by_id = [];
		foreach ( $scanned as $row ) {
			$by_id[ (int) $row['post_id'] ] = $row;
		}

		$rows  = [];
		foreach ( $scanned as $row ) {
			$pid  = (int) $row['post_id'];
			$type = $row['post_type'];

			// Canvas posts appear nested under their parent template, not at top level.
			if ( in_array( $type, self::CANVAS_POST_TYPES, true ) ) {
				continue;
			}

			$slim = $this->slim_row( $row );

			// Expand templates with their canvases.
			if ( $type === 'et_template' && isset( $template_map[ $pid ] ) ) {
				$canvases = [];
				foreach ( $template_map[ $pid ] as $label => $canvas_id ) {
					if ( isset( $by_id[ $canvas_id ] ) ) {
						$c          = $this->slim_row( $by_id[ $canvas_id ] );
						$c['canvas_label'] = $label;
						$canvases[] = $c;
					} else {
						// Canvas post was not in scanned set (e.g. beyond the limit).
						$canvases[] = [
							'canvas_label' => $label,
							'post_id'      => $canvas_id,
							'post_type'    => 'unknown',
							'post_status'  => 'unknown',
							'post_title'   => '(not in scan)',
							'post_modified' => '',
							'has_dso'      => false,
							'var_refs'     => [],
							'preset_refs'  => [],
						];
					}
				}
				$slim['canvases'] = $canvases;
			}

			$rows[] = $slim;
		}

		return [
			'total' => count( $rows ),
			'rows'  => $rows,
		];
	}

	/**
	 * Build the no-DSO content report.
	 *
	 * Returns all top-level scanned rows that have no DSO references
	 * (has_dso === false), excluding canvas post types (which appear nested
	 * under their parent et_template in the inventory).
	 *
	 * Shape:
	 *   [
	 *     'total'   => int,
	 *     'by_type' => [
	 *       'post_type' => [ slim_row, ... ],
	 *       ...
	 *     ]
	 *   ]
	 *
	 * @param array[] $scanned
	 * @return array
	 */
	protected function build_no_dso_report( array $scanned ): array {
		$by_type = [];
		$total   = 0;

		foreach ( $scanned as $row ) {
			// Skip canvas post types — they appear nested under et_template.
			if ( in_array( $row['post_type'], self::CANVAS_POST_TYPES, true ) ) {
				continue;
			}
			// Only include rows with no DSO references.
			if ( $row['has_dso'] ) {
				continue;
			}
			$type = $row['post_type'];
			$by_type[ $type ][] = $this->slim_row( $row );
			$total++;
		}

		return [
			'total'   => $total,
			'by_type' => $by_type,
		];
	}

	/**
	 * Build the DSO usage index.
	 *
	 * For every variable and preset referenced in any content row, records
	 * which posts use it and how many.
	 *
	 * Shape:
	 *   [
	 *     'variables' => [
	 *       'gcid-xxx' => [
	 *         'count'  => int,
	 *         'posts'  => [ [post_id, post_title, post_type, post_status], ... ]
	 *       ],
	 *       ...
	 *     ],
	 *     'presets' => [
	 *       'preset-id' => [ same shape ],
	 *       ...
	 *     ]
	 *   ]
	 *
	 * @param array[] $scanned
	 * @return array
	 */
	protected function build_dso_usage_index( array $scanned ): array {
		$variables = [];
		$presets   = [];

		foreach ( $scanned as $row ) {
			$post_ref = [
				'post_id'     => $row['post_id'],
				'post_title'  => $row['post_title'],
				'post_type'   => $row['post_type'],
				'post_status' => $row['post_status'],
			];

			foreach ( $row['var_refs'] as $ref ) {
				$id = $ref['name'];
				if ( ! isset( $variables[ $id ] ) ) {
					$variables[ $id ] = [ 'count' => 0, 'posts' => [] ];
				}
				$variables[ $id ]['count']++;
				$variables[ $id ]['posts'][] = $post_ref;
			}

			foreach ( $row['preset_refs'] as $id ) {
				if ( ! isset( $presets[ $id ] ) ) {
					$presets[ $id ] = [ 'count' => 0, 'posts' => [] ];
				}
				$presets[ $id ]['count']++;
				$presets[ $id ]['posts'][] = $post_ref;
			}
		}

		// Sort by usage count descending.
		uasort( $variables, fn( $a, $b ) => $b['count'] <=> $a['count'] );
		uasort( $presets,   fn( $a, $b ) => $b['count'] <=> $a['count'] );

		// Enrich each entry with label and dso_type from the design-system repositories.
		$var_labels    = $this->build_var_label_map();
		$preset_labels = $this->build_preset_label_map();

		foreach ( $variables as $id => &$entry ) {
			$entry['label']    = $var_labels[ $id ] ?? '';
			$entry['dso_type'] = 'Variable';
		}
		unset( $entry );

		foreach ( $presets as $id => &$entry ) {
			$entry['label']    = $preset_labels[ $id ] ?? '';
			$entry['dso_type'] = 'Preset';
		}
		unset( $entry );

		return [
			'variables' => $variables,
			'presets'   => $presets,
		];
	}

	/**
	 * Build a flat id → { label, preset_type } map for all module and group presets.
	 *
	 * preset_type is 'Element Preset' for module presets and 'Group Preset' for group presets.
	 *
	 * @return array<string, array{label: string, preset_type: string}>
	 */
	private function build_preset_info_map(): array {
		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();
		$map  = [];

		$type_labels = [
			'module' => 'Element Preset',
			'group'  => 'Group Preset',
		];

		foreach ( [ 'module', 'group' ] as $group ) {
			$preset_type = $type_labels[ $group ];
			foreach ( $raw[ $group ] ?? [] as $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$map[ $preset_id ] = [
						'label'       => (string) ( $preset['name'] ?? '' ),
						'preset_type' => $preset_type,
					];
				}
			}
		}

		return $map;
	}

	/**
	 * Build an id → label map for all variables (including colors).
	 *
	 * @return array<string,string>
	 */
	private function build_var_label_map(): array {
		$repo      = new VarsRepository();
		$raw_vars  = $repo->get_raw();
		$raw_colors = $repo->get_raw_colors();
		$map       = [];

		foreach ( $raw_vars as $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			foreach ( $type_vars as $id => $var ) {
				$map[ $id ] = (string) ( $var['label'] ?? '' );
			}
		}
		foreach ( $raw_colors as $id => $entry ) {
			$map[ $id ] = (string) ( $entry['label'] ?? '' );
		}

		return $map;
	}

	/**
	 * Build a flat id → { label, var_type } map for all variables and global colors.
	 *
	 * var_type uses human-readable labels (Color, Number, Font, Image, Text, Link)
	 * so the JS tree can display them without needing to interpret Divi's raw
	 * token type strings ("color", "content").
	 *
	 * @return array<string, array{label: string, var_type: string}>
	 */
	private function build_var_info_map(): array {
		$repo        = new VarsRepository();
		$raw_vars    = $repo->get_raw();
		$raw_colors  = $repo->get_raw_colors();
		$map         = [];

		$type_labels = [
			'colors'  => 'Color',
			'numbers' => 'Number',
			'fonts'   => 'Font',
			'images'  => 'Image',
			'strings' => 'Text',
			'links'   => 'Link',
		];

		foreach ( $raw_vars as $type_key => $type_vars ) {
			if ( ! is_array( $type_vars ) ) { continue; }
			$type_label = $type_labels[ $type_key ] ?? ucfirst( $type_key );
			foreach ( $type_vars as $id => $var ) {
				$map[ $id ] = [
					'label'    => (string) ( $var['label'] ?? '' ),
					'var_type' => $type_label,
				];
			}
		}
		foreach ( $raw_colors as $id => $entry ) {
			$map[ $id ] = [
				'label'    => (string) ( $entry['label'] ?? '' ),
				'var_type' => 'Color',
			];
		}

		return $map;
	}

	/**
	 * Build an id → name map for all module/group presets.
	 *
	 * @return array<string,string>
	 */
	private function build_preset_label_map(): array {
		$repo = new PresetsRepository();
		$raw  = $repo->get_raw();
		$map  = [];

		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$map[ $preset_id ] = (string) ( $preset['name'] ?? '' );
				}
			}
		}

		return $map;
	}

	/**
	 * Build the meta section of the report.
	 *
	 * @param array[] $rows     Raw DB rows.
	 * @param array[] $scanned  Enriched rows.
	 * @return array
	 */
	protected function build_meta( array $rows, array $scanned ): array {
		$active_count = 0;
		$by_type      = [];
		$by_status    = [];

		foreach ( $scanned as $row ) {
			$type   = $row['post_type'];
			$status = $row['post_status'];

			$by_type[ $type ]     = ( $by_type[ $type ]     ?? 0 ) + 1;
			$by_status[ $status ] = ( $by_status[ $status ] ?? 0 ) + 1;

			if ( $row['has_dso'] ) {
				$active_count++;
			}
		}

		return [
			'total_scanned'  => count( $rows ),
			'active_count'   => $active_count,
			'limit'          => self::CONTENT_LIMIT,
			'limit_reached'  => count( $rows ) >= self::CONTENT_LIMIT,
			'by_type'        => $by_type,
			'by_status'      => $by_status,
			'scanned_types'  => self::SCANNED_POST_TYPES,
			'scanned_statuses' => self::SCANNED_STATUSES,
			'ran_at'         => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return a row stripped of post_content (too large for the JSON response).
	 *
	 * @param array $row Enriched scanned row.
	 * @return array
	 */
	private function slim_row( array $row ): array {
		$out = $row;
		unset( $out['post_content'] );
		return $out;
	}
}
