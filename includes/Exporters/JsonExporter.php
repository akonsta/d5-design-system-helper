<?php
/**
 * Exports any supported Divi 5 design system type as a Divi-native JSON file.
 *
 * The output matches the format produced by Divi's own export — so the file
 * can be imported directly through Divi's built-in interface, with no
 * dependency on this plugin.
 *
 * ## Divi native envelope structure
 *
 *   vars:
 *     { "et_divi_global_variables": { <raw vars array> } }
 *
 *   presets:
 *     { "et_divi_builder_global_presets_d5": { <raw presets array> } }
 *
 *   layouts / pages:
 *     { "posts": [ { <post fields + meta + terms> }, ... ] }
 *
 *   theme_customizer:
 *     { "theme_mods_Divi": { <raw mods array> } }
 *
 *   builder_templates:
 *     { "et_template": [ { <template fields + meta> }, ... ] }
 *
 * Single type → stream as .json
 * Multiple types → caller bundles into .zip
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Exporters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Admin\SnapshotManager;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Data\LayoutsRepository;
use D5DesignSystemHelper\Data\ThemeCustomizerRepository;
use D5DesignSystemHelper\Data\BuilderTemplatesRepository;

/**
 * Class JsonExporter
 */
class JsonExporter {

	/** @var string One of the TYPE_LABELS keys. */
	private string $type;

	/** @var string Status filter for layouts/pages ('any'|'publish'|'draft'|'private'). */
	private string $status;

	/**
	 * @param string $type   Export type key (vars, presets, layouts, pages, theme_customizer, builder_templates).
	 * @param string $status Status filter for layouts/pages.
	 */
	public function __construct( string $type, string $status = 'any' ) {
		$this->type   = $type;
		$this->status = $status;
	}

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Stream the JSON file directly to the browser as a download.
	 *
	 * @return never
	 */
	public function stream_download(): never {
		$data     = $this->build_export_data();
		$json     = $this->encode( $data );
		$filename = 'divi5-' . $this->type . '-' . gmdate( 'Y-m-d\TH-i-s' ) . '.json';

		// Push snapshot before streaming.
		SnapshotManager::push(
			$this->type,
			$this->get_raw_for_snapshot(),
			'export',
			'JSON export: ' . $filename
		);

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		header( 'Cache-Control: max-age=0' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Build the export data array and return it (without streaming).
	 * Used by the zip bundler.
	 *
	 * @return array<string, mixed>
	 */
	public function build_export_data(): array {
		return match ( $this->type ) {
			'vars'              => $this->build_vars_data(),
			'presets'           => $this->build_presets_data(),
			'layouts'           => $this->build_layouts_data( 'et_pb_layout' ),
			'pages'             => $this->build_layouts_data( 'page' ),
			'theme_customizer'  => $this->build_theme_customizer_data(),
			'builder_templates' => $this->build_builder_templates_data(),
			default             => throw new \InvalidArgumentException( "Unknown export type: {$this->type}" ),
		};
	}

	/**
	 * Save the JSON to a temp file and return the path.
	 * Used by the zip bundler in AdminPage.
	 *
	 * @return string Temp file path.
	 */
	public function save_to_temp(): string {
		$data = $this->build_export_data();
		$json = $this->encode( $data );
		$path = tempnam( sys_get_temp_dir(), 'd5dsh_json_' ) . '.json';
		file_put_contents( $path, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		return $path;
	}

	// ── Type builders ─────────────────────────────────────────────────────────

	/**
	 * Global Variables — envelope: { et_divi_global_variables: {...} }
	 */
	private function build_vars_data(): array {
		$repo = new VarsRepository();
		return [
			'et_divi_global_variables' => $repo->get_raw(),
			'_meta' => $this->meta_block( 'vars' ),
		];
	}

	/**
	 * Presets — envelope: { et_divi_builder_global_presets_d5: {...} }
	 */
	private function build_presets_data(): array {
		$repo = new PresetsRepository();
		return [
			'et_divi_builder_global_presets_d5' => $repo->get_raw(),
			'_meta' => $this->meta_block( 'presets' ),
		];
	}

	/**
	 * Layouts or Pages — envelope: { posts: [ {...}, ... ] }
	 *
	 * @param string $post_type 'et_pb_layout' or 'page'.
	 */
	private function build_layouts_data( string $post_type ): array {
		$repo  = new LayoutsRepository();
		$posts = $repo->get_all( $post_type, $this->status );

		$export_posts = [];
		foreach ( $posts as $post_id => $post ) {
			$export_posts[] = [
				'ID'          => $post['ID'],
				'post_title'  => $post['post_title'],
				'post_name'   => $post['post_name'],
				'post_status' => $post['post_status'],
				'post_type'   => $post['post_type'],
				'post_date'   => $post['post_date'],
				'menu_order'  => $post['menu_order'],
				'post_parent' => $post['post_parent'],
				'post_meta'   => $post['post_meta']  ?? [],
				'terms'       => $post['terms']      ?? [],
			];
		}

		return [
			'posts'  => $export_posts,
			'_meta'  => $this->meta_block( $this->type ),
		];
	}

	/**
	 * Theme Customizer — envelope: { theme_mods_Divi: {...} }
	 */
	private function build_theme_customizer_data(): array {
		$repo = new ThemeCustomizerRepository();
		return [
			'theme_mods_Divi' => get_option( ThemeCustomizerRepository::OPTION_KEY, [] ),
			'_meta'           => $this->meta_block( 'theme_customizer' ),
		];
	}

	/**
	 * Builder Templates — envelope: { et_template: [...], layouts: {...} }
	 *
	 * post_content IS included here (unlike the xlsx export) because Divi
	 * needs it to restore templates via its own import.
	 */
	private function build_builder_templates_data(): array {
		$repo = new BuilderTemplatesRepository();
		$data = $repo->get_all();

		// Re-attach post_content to each layout for Divi-native import.
		$layouts_with_content = [];
		foreach ( $data['layouts'] as $post_id => $layout ) {
			$layouts_with_content[ $post_id ] = array_merge(
				$layout,
				[ 'post_content' => $repo->get_layout_content( (int) $post_id ) ]
			);
		}

		return [
			'et_template' => $data['templates'],
			'layouts'     => $layouts_with_content,
			'_meta'       => $this->meta_block( 'builder_templates' ),
		];
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Build the _meta block included in every export.
	 *
	 * This is a D5 Design System Helper addition — Divi's own import ignores
	 * unknown keys, so this is safe to include and aids debugging.
	 *
	 * @param string $type
	 * @return array<string, mixed>
	 */
	private function meta_block( string $type ): array {
		return [
			'exported_by'  => 'D5 Design System Helper',
			'version'      => D5DSH_VERSION,
			'type'         => $type,
			'exported_at'  => gmdate( 'c' ),
			'site_url'     => get_site_url(),
		];
	}

	/**
	 * Get the raw DB data for snapshotting before export.
	 *
	 * @return array<string, mixed>
	 */
	private function get_raw_for_snapshot(): array {
		return match ( $this->type ) {
			'vars'             => ( new VarsRepository() )->get_raw(),
			'presets'          => ( new PresetsRepository() )->get_raw(),
			'layouts', 'pages' => ( new LayoutsRepository() )->get_all(
				$this->type === 'layouts' ? 'et_pb_layout' : 'page',
				$this->status
			),
			'theme_customizer'  => get_option( ThemeCustomizerRepository::OPTION_KEY, [] ),
			'builder_templates' => ( new BuilderTemplatesRepository() )->get_all(),
			default             => [],
		};
	}

	/**
	 * JSON encode with pretty-print and unicode preserved.
	 *
	 * @param mixed $data
	 * @return string
	 */
	private function encode( mixed $data ): string {
		return (string) json_encode(
			$data,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
	}
}
