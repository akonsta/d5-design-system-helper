<?php
/**
 * StyleGuideBuilder — serves design system data for the Style Guide generator.
 *
 * Returns variables, presets, and category assignments in a single payload
 * so the client-side renderer can build a live style guide preview.
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StyleGuideBuilder
 */
class StyleGuideBuilder {

	const NONCE_ACTION = 'd5dsh_manage';
	const CAPABILITY   = 'manage_options';

	// ── Registration ──────────────────────────────────────────────────────────

	public function register(): void {
		add_action( 'wp_ajax_d5dsh_styleguide_data', [ $this, 'ajax_data' ] );
	}

	// ── AJAX handler ──────────────────────────────────────────────────────────

	/**
	 * Return all data needed to render the style guide.
	 */
	public function ajax_data(): never {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();
		$cat_manager  = new CategoryManager();

		$all_vars = $vars_repo->get_all();
		$raw      = $presets_repo->get_raw();

		// Build a flat presets list with variable refs.
		$presets = [];
		foreach ( [ 'module', 'group' ] as $group ) {
			foreach ( $raw[ $group ] ?? [] as $module_name => $module_presets ) {
				foreach ( $module_presets['items'] ?? [] as $preset_id => $preset ) {
					$presets[] = [
						'id'          => $preset_id,
						'name'        => $preset['name']       ?? $preset_id,
						'moduleName'  => $preset['moduleName'] ?? $module_name,
						'type'        => $group === 'group' ? 'group' : 'element',
					];
				}
			}
		}

		wp_send_json_success( [
			'vars'         => $all_vars,
			'presets'      => $presets,
			'categories'   => $cat_manager->get_categories(),
			'category_map' => $cat_manager->get_map(),
		] );
	}
}
