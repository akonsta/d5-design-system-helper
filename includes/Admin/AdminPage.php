<?php
/**
 * Admin page for D5 Design System Helper.
 *
 * Registers the Divi → Design System submenu and handles all POST/GET
 * requests for export, import, snapshot restore, and snapshot delete.
 *
 * ## Tabs
 *   Export     — hierarchical checkbox tree; exports one or more types
 *   Import     — file upload with dry-run preview and commit
 *   Snapshots  — list and restore/delete snapshots per type
 *
 * ## Export dispatch
 *   Single type selected → streams single .xlsx
 *   Multiple types       → bundles into .zip via ZipArchive
 *
 * ## Import dispatch
 *   Auto-detects type from Config sheet cell B5 (File Type row)
 *   Dispatches to the appropriate importer class
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use D5DesignSystemHelper\Exporters\VarsExporter;
use D5DesignSystemHelper\Exporters\PresetsExporter;
use D5DesignSystemHelper\Importers\VarsImporter;
use D5DesignSystemHelper\Importers\PresetsImporter;
use D5DesignSystemHelper\Exporters\JsonExporter;
use D5DesignSystemHelper\Util\ExportUtil;
use D5DesignSystemHelper\Admin\Validator;
use D5DesignSystemHelper\Admin\SimpleImporter;
use D5DesignSystemHelper\Data\VarsRepository;
use D5DesignSystemHelper\Data\PresetsRepository;
use D5DesignSystemHelper\Admin\AuditEngine;
use D5DesignSystemHelper\Admin\ContentScanner;
use D5DesignSystemHelper\Admin\HelpManager;
use D5DesignSystemHelper\Admin\NotesManager;
use D5DesignSystemHelper\Admin\ImpactAnalyzer;
use D5DesignSystemHelper\Admin\CategoryManager;
use D5DesignSystemHelper\Admin\MergeManager;
use D5DesignSystemHelper\Admin\StyleGuideBuilder;
use D5DesignSystemHelper\Exporters\DtcgExporter;
use D5DesignSystemHelper\Exporters\TemplateBuilder;
use D5DesignSystemHelper\Util\DebugLogger;

/**
 * Class AdminPage
 */
class AdminPage {

	/** Admin page slug. */
	const PAGE_SLUG = 'd5dsh-design-tool';

	/** Nonce actions. */
	const NONCE_EXPORT        = 'd5dsh_export';
	const NONCE_IMPORT        = 'd5dsh_import';
	const NONCE_VALIDATE      = 'd5dsh-validate';
	const NONCE_SNAPSHOT      = 'd5dsh_snapshot';
	const NONCE_MANAGE        = 'd5dsh_manage';
	const NONCE_SETTINGS      = 'd5dsh_settings';

	/** wp_options key for persisted plugin settings. */
	const SETTINGS_OPTION = 'd5dsh_settings';

	/** Required capability. */
	const CAPABILITY = 'manage_options';

	/**
	 * Map of file_type key → human label (for UI and dispatch).
	 * Used by the import auto-detect and export dispatch.
	 */
	const TYPE_LABELS = [
		'vars'              => 'Global Variables',
		'presets'           => 'Presets',
		'layouts'           => 'Layouts',
		'pages'             => 'Pages',
		'theme_customizer'  => 'Theme Customizer',
		'builder_templates' => 'Builder Templates',
	];

	/**
	 * Types available for Excel (.xlsx) export and import.
	 * JSON export remains available for all TYPE_LABELS types.
	 */
	const XLSX_TYPES = [ 'vars', 'presets' ];

	// ── Registration ──────────────────────────────────────────────────────────

	/**
	 * Register WP hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu',            [ $this, 'add_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init',            [ $this, 'handle_requests' ] );
		add_action( 'admin_post_d5dsh_dl_template', [ $this, 'handle_download_template' ] );

		// Register AJAX endpoints for the Manage tab (Labels and Presets).
		( new LabelManager() )->register();
		( new PresetsManager() )->register();

		// Register Simple Import AJAX endpoints.
		( new SimpleImporter() )->register();

		// Register settings save AJAX endpoint.
		add_action( 'wp_ajax_d5dsh_save_settings', [ $this, 'ajax_save_settings' ] );

		// Register validate AJAX endpoint.
		add_action( 'wp_ajax_d5dsh_validate', [ $this, 'ajax_validate' ] );

		// Register Audit AJAX endpoints.
		$audit = new AuditEngine();
		add_action( 'wp_ajax_d5dsh_audit_run',      [ $audit, 'ajax_run'         ] );
		add_action( 'wp_ajax_d5dsh_audit_run_full', [ $audit, 'ajax_run_full'    ] );
		add_action( 'wp_ajax_d5dsh_audit_xlsx',     [ $audit, 'ajax_audit_xlsx'  ] );
		add_action( 'wp_ajax_d5dsh_scan_xlsx',      [ $audit, 'ajax_scan_xlsx'   ] );

		// Register Content Scanner AJAX endpoint.
		$scanner = new ContentScanner();
		add_action( 'wp_ajax_d5dsh_content_scan', [ $scanner, 'ajax_run' ] );

		// Register Impact Analyzer AJAX endpoint.
		( new ImpactAnalyzer() )->register();

		// Register Category Manager AJAX endpoints.
		( new CategoryManager() )->register();

		// Register Merge Manager AJAX endpoints.
		( new MergeManager() )->register();

		// Register Style Guide Builder AJAX endpoint.
		( new StyleGuideBuilder() )->register();

		// Register Notes AJAX endpoints.
		( new NotesManager() )->register();

		// Register Help AJAX endpoints. Help tabs are wired in add_menu() after
		// add_submenu_page() returns the actual load-{hook} suffix.
		( new HelpManager() )->register();

	}

	/**
	 * Add the Design System submenu under Divi (or Tools as fallback).
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$parent    = menu_page_url( 'et_divi_options', false ) ? 'et_divi_options' : 'tools.php';
		$page_hook = add_submenu_page(
			$parent,
			__( 'D5 Design System Utilities', 'd5-design-system-helper' ),
			__( 'Design System Helper',        'd5-design-system-helper' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);

		// Wire WP native help tabs using the actual load-{hook} action suffix.
		if ( $page_hook ) {
			add_action( 'load-' . $page_hook, [ new HelpManager(), 'register_help_tabs' ] );
		}
	}

	/**
	 * Enqueue CSS and JS only on our admin page.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
			return;
		}
		wp_enqueue_style(
			'd5dsh-tabulator',
			D5DSH_URL . 'assets/css/tabulator.min.css',
			[],
			'6.4.0'
		);
		wp_enqueue_style(
			'd5dsh-admin',
			D5DSH_URL . 'assets/css/admin.css',
			[ 'd5dsh-tabulator' ],
			D5DSH_VERSION
		);
		wp_enqueue_script(
			'd5dsh-tabulator',
			D5DSH_URL . 'assets/js/tabulator.min.js',
			[],
			'6.4.0',
			true
		);
		wp_enqueue_script(
			'd5dsh-admin',
			D5DSH_URL . 'assets/js/admin.js',
			[ 'd5dsh-tabulator' ],
			D5DSH_VERSION,
			true
		);
		wp_enqueue_script(
			'd5dsh-vars-table',
			D5DSH_URL . 'assets/js/manage-vars-table.js',
			[ 'd5dsh-tabulator', 'd5dsh-admin' ],
			D5DSH_VERSION,
			true
		);
		wp_enqueue_script(
			'd5dsh-presets-gp-table',
			D5DSH_URL . 'assets/js/manage-presets-gp-table.js',
			[ 'd5dsh-tabulator', 'd5dsh-admin' ],
			D5DSH_VERSION,
			true
		);
		wp_enqueue_script(
			'd5dsh-presets-ep-table',
			D5DSH_URL . 'assets/js/manage-presets-ep-table.js',
			[ 'd5dsh-tabulator', 'd5dsh-admin' ],
			D5DSH_VERSION,
			true
		);
		wp_enqueue_script(
			'd5dsh-presets-all-table',
			D5DSH_URL . 'assets/js/manage-presets-all-table.js',
			[ 'd5dsh-tabulator', 'd5dsh-admin' ],
			D5DSH_VERSION,
			true
		);
		wp_enqueue_script(
			'd5dsh-everything-table',
			D5DSH_URL . 'assets/js/manage-everything-table.js',
			[ 'd5dsh-tabulator', 'd5dsh-admin' ],
			D5DSH_VERSION,
			true
		);

		// Pass AJAX URL and nonce for the Manage tab (Variables).
		wp_localize_script( 'd5dsh-admin', 'd5dtManage', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( self::NONCE_MANAGE ),
			'xlsxAction' => 'd5dsh_manage_xlsx',
			'version'    => D5DSH_VERSION,
		] );
		// Pass AJAX URL and nonce for the Manage tab (Presets).
		wp_localize_script( 'd5dsh-admin', 'd5dtPresets', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( PresetsManager::NONCE_ACTION ),
			'xlsxAction' => 'd5dsh_presets_manage_xlsx',
		] );
		wp_localize_script( 'd5dsh-admin', 'd5dtValidate', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_VALIDATE ),
		] );
		// Pass AJAX URL and nonce for Simple Import.
		wp_localize_script( 'd5dsh-admin', 'd5dtSimpleImport', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( SimpleImporter::NONCE_ACTION ),
		] );
		// Pass AJAX URL and nonce for the Audit tab and Content Scanner.
		// ContentScanner::ajax_run() verifies the same 'd5dsh_audit_nonce' nonce.
		wp_localize_script( 'd5dsh-admin', 'd5dtAudit', [
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'd5dsh_audit_nonce' ),
			'scanXlsxAction' => 'd5dsh_scan_xlsx',
		] );

		// Pass AJAX URL and nonce for the Notes system.
		wp_localize_script( 'd5dsh-admin', 'd5dtNotes', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( NotesManager::NONCE ),
			'saveAction'    => NotesManager::AJAX_SAVE,
			'deleteAction'  => NotesManager::AJAX_DELETE,
			'getAllAction'   => NotesManager::AJAX_GET_ALL,
		] );

		// Enqueue Fuse.js for help panel search.
		wp_enqueue_script(
			'd5dsh-fuse',
			D5DSH_URL . 'assets/js/fuse.min.js',
			[],
			'7.0.0',
			true
		);

		// Pass AJAX URL for the help panel (no nonce — read-only public content).
		wp_localize_script( 'd5dsh-admin', 'd5dtHelp', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'actionContent' => HelpManager::AJAX_CONTENT,
			'actionIndex'   => HelpManager::AJAX_INDEX,
		] );

		// Pass settings and nonce to JS.
		$settings = self::get_settings();
		$blog_name  = get_bloginfo( 'name' );
		if ( '' === trim( $blog_name ) ) {
			$blog_name = get_bloginfo( 'blogname' );
		}
		if ( '' === trim( $blog_name ) ) {
			$blog_name = parse_url( home_url(), PHP_URL_HOST ) ?? 'site';
		}
		$blog_slug  = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', $blog_name ) );
		$blog_slug  = trim( $blog_slug, '_' );
		$site_abbr  = ! empty( $settings['site_abbr'] ) ? $settings['site_abbr'] : $blog_slug;
		wp_localize_script( 'd5dsh-admin', 'd5dtSettings', [
			'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'         => wp_create_nonce( self::NONCE_SETTINGS ),
			'debugMode'     => ! empty( $settings['debug_mode'] ),
			'betaPreview'   => ! empty( $settings['beta_preview'] ),
			'siteUrl'       => home_url(),
			'reportHeader'  => $settings['report_header'] ?? '',
			'reportFooter'  => $settings['report_footer'] ?? '',
			'siteAbbr'      => $site_abbr,
			'blogName'      => $blog_name,
		] );
	}

	// ── Request handling ──────────────────────────────────────────────────────

	/**
	 * Dispatch all POST requests from admin_init (before headers are sent).
	 *
	 * @return void
	 */
	public function handle_requests(): void {
		if ( ! isset( $_POST['d5dsh_action'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'd5-design-system-helper' ) );
		}

		$action = sanitize_key( $_POST['d5dsh_action'] );

		match ( $action ) {
			'export'            => $this->handle_export(),
			'import'            => $this->handle_import(),
			'restore_snapshot'  => $this->handle_restore_snapshot(),
			'delete_snapshot'   => $this->handle_delete_snapshot(),
			'undo_import'       => $this->handle_undo_import(),
			default             => null,
		};
	}

	// ── Export ────────────────────────────────────────────────────────────────

	/**
	 * Handle the export form submission.
	 *
	 * Single type  → stream .xlsx or .json depending on format selection.
	 * Multiple types → stream .zip containing .xlsx or .json files.
	 */
	private function handle_export(): void {
		check_admin_referer( self::NONCE_EXPORT );

		// Collect unique types from the checkbox tree.
		$types         = array_unique( array_map( 'sanitize_key', (array) ( $_POST['d5dsh_types'] ?? [] ) ) );
		$layout_status = sanitize_key( $_POST['d5dsh_layout_status'] ?? 'any' );
		$page_status   = sanitize_key( $_POST['d5dsh_page_status']   ?? 'any' );
		$format        = sanitize_key( $_POST['d5dsh_format']        ?? 'xlsx' );
		$types         = array_values( array_filter( $types, fn( $t ) => isset( self::TYPE_LABELS[ $t ] ) ) );

		if ( $format === 'xlsx' ) {
			$types = array_values( array_filter( $types, fn( $t ) => in_array( $t, self::XLSX_TYPES, true ) ) );
		}

		// Collect optional additional information fields (Excel export only).
		$additional_info = [
			'owner'       => sanitize_text_field( $_POST['d5dsh_info_owner']       ?? '' ),
			'customer'    => sanitize_text_field( $_POST['d5dsh_info_customer']    ?? '' ),
			'company'     => sanitize_text_field( $_POST['d5dsh_info_company']     ?? '' ),
			'project'     => sanitize_text_field( $_POST['d5dsh_info_project']     ?? '' ),
			'version_tag' => sanitize_text_field( $_POST['d5dsh_info_version_tag'] ?? '' ),
			'status'      => sanitize_text_field( $_POST['d5dsh_info_status']      ?? '' ),
			'environment' => sanitize_text_field( $_POST['d5dsh_info_environment'] ?? '' ),
			'comments'    => sanitize_textarea_field( $_POST['d5dsh_info_comments'] ?? '' ),
		];

		if ( ! in_array( $format, [ 'xlsx', 'json', 'dtcg' ], true ) ) {
			$format = 'xlsx';
		}

		// DTCG export covers all variables regardless of $types selection.
		if ( $format === 'dtcg' ) {
			try {
				( new DtcgExporter() )->stream_download();
				exit;
			} catch ( \Throwable $e ) {
				DebugLogger::log_exception( $e, __METHOD__ . ' (dtcg)' );
				$this->redirect_with_error( 'export_failed', $e->getMessage() );
			}
		}

		if ( empty( $types ) ) {
			$this->redirect_with_error( 'no_types_selected' );
		}

		try {
			if ( $format === 'json' ) {
				if ( count( $types ) === 1 ) {
					( new JsonExporter( $types[0], $types[0] === 'layouts' ? $layout_status : $page_status ) )->stream_download();
					exit;
				}
				$this->stream_json_zip( $types, $layout_status, $page_status );
				exit;
			}

			// xlsx (default).
			if ( count( $types ) === 1 ) {
				$exporter = $this->build_exporter( $types[0], $layout_status, $page_status, $additional_info );
				$exporter->stream_download();
				exit;
			}
			$this->stream_zip( $types, $layout_status, $page_status, $additional_info );
			exit;
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ . ' (export)' );
			$this->redirect_with_error( 'export_failed', $e->getMessage() );
		}
	}

	/**
	 * Build the appropriate exporter for the given type.
	 *
	 * @param string  $type
	 * @param string  $layout_status
	 * @param string  $page_status
	 * @param array   $additional_info Optional additional info fields (vars xlsx only).
	 * @return object  An exporter with stream_download() and build_spreadsheet().
	 */
	private function build_exporter( string $type, string $layout_status = 'any', string $page_status = 'any', array $additional_info = [] ): object {
		return match ( $type ) {
			'vars'    => new VarsExporter( $additional_info ),
			'presets' => new PresetsExporter(),
			default   => throw new \InvalidArgumentException( "Excel export is not available for type: $type" ),
		};
	}

	/**
	 * Bundle multiple xlsx files into a zip and stream to browser.
	 *
	 * @param string[] $types
	 * @param string   $layout_status
	 * @param string   $page_status
	 * @param array    $additional_info Optional additional info fields for vars xlsx.
	 * @return never
	 */
	private function stream_zip( array $types, string $layout_status, string $page_status, array $additional_info = [] ): never {
		$zip_path = tempnam( sys_get_temp_dir(), 'd5dsh_zip_' ) . '.zip';
		$zip      = new \ZipArchive();

		if ( $zip->open( $zip_path, \ZipArchive::CREATE ) !== true ) {
			$this->redirect_with_error( 'export_failed', 'Could not create zip file.' );
		}

		$tz        = wp_timezone();
		$timestamp = ( new \DateTime( 'now', $tz ) )->format( 'Y-m-d_H.i' );

		$temp_files = [];
		foreach ( $types as $type ) {
			$exporter   = $this->build_exporter( $type, $layout_status, $page_status, $additional_info );
			$ss         = $exporter->build_spreadsheet();
			$tmp_xlsx   = ExportUtil::save_to_temp( $ss );
			$temp_files[] = $tmp_xlsx;
			$zip->addFile( $tmp_xlsx, 'divi5-' . $type . '-' . $timestamp . '.xlsx' );
		}
		$zip->close();

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="d5dsh-export-' . $timestamp . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'Cache-Control: max-age=0' );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

		@unlink( $zip_path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		foreach ( $temp_files as $f ) {
			@unlink( $f ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		exit;
	}

	/**
	 * Bundle multiple JSON files into a zip and stream to browser.
	 *
	 * @param string[] $types
	 * @param string   $layout_status
	 * @param string   $page_status
	 * @return never
	 */
	private function stream_json_zip( array $types, string $layout_status, string $page_status ): never {
		$zip_path = tempnam( sys_get_temp_dir(), 'd5dsh_jsonzip_' ) . '.zip';
		$zip      = new \ZipArchive();

		if ( $zip->open( $zip_path, \ZipArchive::CREATE ) !== true ) {
			$this->redirect_with_error( 'export_failed', 'Could not create zip file.' );
		}

		$temp_files = [];
		foreach ( $types as $type ) {
			$status   = $type === 'layouts' ? $layout_status : $page_status;
			$exporter = new JsonExporter( $type, $status );
			$tmp_json = $exporter->save_to_temp();
			$temp_files[] = $tmp_json;
			$zip->addFile( $tmp_json, 'divi5-' . $type . '-' . gmdate( 'Y-m-d' ) . '.json' );
		}
		$zip->close();

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="d5dsh-export-json-' . gmdate( 'Y-m-d' ) . '.zip"' );
		header( 'Content-Length: ' . filesize( $zip_path ) );
		header( 'Cache-Control: max-age=0' );
		readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

		@unlink( $zip_path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		foreach ( $temp_files as $f ) {
			@unlink( $f ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		exit;
	}

	// ── Import ────────────────────────────────────────────────────────────────

	/**
	 * AJAX: validate an uploaded xlsx file without importing.
	 */
	public function ajax_validate(): void {
		check_ajax_referer( self::NONCE_VALIDATE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
		}

		if ( empty( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
		}

		$tmp = $_FILES['file']['tmp_name'];

		try {
			$validator = new Validator( $tmp );
			$report    = $validator->validate();
			wp_send_json_success( $report );
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			wp_send_json_error( [ 'message' => 'Validation failed: ' . $e->getMessage() ] );
		}
	}

	/**
	 * Handle the import form submission.
	 */
	private function handle_import(): void {
		check_admin_referer( self::NONCE_IMPORT );

		if ( empty( $_FILES['d5dsh_xlsx']['tmp_name'] ) ) {
			$this->redirect_with_error( 'no_file' );
		}

		$file          = $_FILES['d5dsh_xlsx'];
		$allowed_types = [
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'application/zip',
			'application/octet-stream',
		];
		if ( ! in_array( $file['type'], $allowed_types, true ) ) {
			$this->redirect_with_error( 'no_file' );
		}

		$dry_run = ( ( $_POST['d5dsh_dry_run'] ?? '' ) === '1' );

		try {
			$file_type = $this->detect_file_type( $file['tmp_name'] );
			if ( ! $file_type ) {
				$this->redirect_with_error( 'unknown_file_type' );
			}

			$importer = $this->build_importer( $file_type, $file['tmp_name'] );

			if ( $dry_run ) {
				$diff = $importer->dry_run();
				set_transient( 'd5dsh_dry_run_result_' . get_current_user_id(), $diff, 10 * MINUTE_IN_SECONDS );
				$this->redirect_with_notice( 'dry_run_complete', 'import' );
			} else {
				$result = $importer->commit();
				set_transient( 'd5dsh_import_result_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
				$this->redirect_with_notice( 'import_complete', 'import' );
			}
		} catch ( \Throwable $e ) {
			DebugLogger::log_exception( $e, __METHOD__ );
			$this->redirect_with_error( 'import_failed', $e->getMessage() );
		}
	}

	/**
	 * Read the Config sheet File Type value (row 5, col B) from an xlsx file.
	 *
	 * @param string $file_path
	 * @return string|null  Type key or null if not found.
	 */
	private function detect_file_type( string $file_path ): ?string {
		try {
			$ss = \PhpOffice\PhpSpreadsheet\IOFactory::load( $file_path );
			$ws = $ss->getSheetByName( 'Config' );
			if ( ! $ws ) {
				return null;
			}
			$type = trim( (string) $ws->getCell( 'B5' )->getValue() );
			return in_array( $type, self::XLSX_TYPES, true ) ? $type : null;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Build the appropriate importer.
	 *
	 * @param string $file_type
	 * @param string $file_path
	 * @return object  An importer with dry_run() and commit().
	 */
	private function build_importer( string $file_type, string $file_path ): object {
		return match ( $file_type ) {
			'vars'    => new VarsImporter( $file_path ),
			'presets' => new PresetsImporter( $file_path ),
			default   => throw new \InvalidArgumentException( "Excel import is not available for type: $file_type" ),
		};
	}

	// ── Snapshot actions ──────────────────────────────────────────────────────

	/**
	 * Restore a snapshot.
	 */
	private function handle_restore_snapshot(): void {
		check_admin_referer( self::NONCE_SNAPSHOT );
		$type  = sanitize_key( $_POST['d5dsh_snap_type']  ?? '' );
		$index = (int) ( $_POST['d5dsh_snap_index'] ?? -1 );

		if ( ! $type || $index < 0 ) {
			$this->redirect_with_error( 'snapshot_restore_failed' );
		}

		$ok = SnapshotManager::restore( $type, $index );
		$ok ? $this->redirect_with_notice( 'snapshot_restored', 'snapshots' )
			: $this->redirect_with_error( 'snapshot_restore_failed' );
	}

	/**
	 * Delete a snapshot.
	 */
	private function handle_delete_snapshot(): void {
		check_admin_referer( self::NONCE_SNAPSHOT );
		$type  = sanitize_key( $_POST['d5dsh_snap_type']  ?? '' );
		$index = (int) ( $_POST['d5dsh_snap_index'] ?? -1 );

		if ( $type && $index >= 0 ) {
			SnapshotManager::delete_snapshot( $type, $index );
		}
		$this->redirect_with_notice( 'snapshot_deleted', 'snapshots' );
	}

	/**
	 * Undo the most recent import by restoring the newest import snapshot.
	 */
	private function handle_undo_import(): void {
		check_admin_referer( self::NONCE_SNAPSHOT );
		$type = sanitize_key( $_POST['d5dsh_snap_type'] ?? '' );

		if ( ! $type ) {
			$this->redirect_with_error( 'undo_failed' );
		}

		$meta         = SnapshotManager::list_snapshots( $type );
		$import_index = null;
		foreach ( $meta as $snap ) {
			if ( ( $snap['trigger'] ?? '' ) === 'import' ) {
				$import_index = $snap['index'];
				break;
			}
		}

		if ( $import_index === null ) {
			$this->redirect_with_error( 'undo_failed' );
		}

		$ok = SnapshotManager::restore( $type, $import_index );
		$ok ? $this->redirect_with_notice( 'undo_complete', 'snapshots' )
			: $this->redirect_with_error( 'undo_failed' );
	}

	// ── Template download ─────────────────────────────────────────────────────

	/**
	 * Stream an Excel import template file.
	 *
	 * Triggered by GET admin-post.php?action=d5dsh_dl_template&type=vars&_wpnonce=...
	 * Nonce is checked via check_admin_referer( 'd5dsh_dl_template_{type}' ).
	 *
	 * @return void
	 */
	public function handle_download_template(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'd5-design-system-helper' ) );
		}

		$type = sanitize_key( $_GET['type'] ?? '' );
		check_admin_referer( 'd5dsh_dl_template_' . $type );

		$builder = new TemplateBuilder();

		match ( $type ) {
			'vars'             => $builder->stream_vars_template(),
			'presets'          => $builder->stream_presets_template(),
			'theme_customizer' => $builder->stream_theme_customizer_template(),
			default            => wp_die( esc_html__( 'Unknown template type.', 'd5-design-system-helper' ) ),
		};
		exit;
	}

	// ── Settings ──────────────────────────────────────────────────────────────

	/**
	 * Return the plugin settings array, with defaults applied.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::SETTINGS_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_merge(
			[
				'debug_mode'    => false,
				'beta_preview'  => false,
				'report_header' => '',
				'report_footer' => '',
				'site_abbr'     => '',
			],
			$saved
		);
	}

	/**
	 * AJAX: save plugin settings.
	 *
	 * Expects JSON body: { debug_mode: bool }
	 */
	public function ajax_save_settings(): void {
		check_ajax_referer( self::NONCE_SETTINGS, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
		}

		$raw     = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$payload = $raw ? json_decode( $raw, true ) : [];
		if ( ! is_array( $payload ) ) {
			$payload = [];
		}

		$current                  = self::get_settings();
		$current['debug_mode']    = ! empty( $payload['debug_mode'] );
		$current['beta_preview']  = ! empty( $payload['beta_preview'] );
		$current['report_header'] = sanitize_text_field( $payload['report_header'] ?? '' );
		$current['report_footer'] = sanitize_text_field( $payload['report_footer'] ?? '' );
		$current['site_abbr']     = sanitize_text_field( $payload['site_abbr'] ?? '' );

		update_option( self::SETTINGS_OPTION, $current, false );

		DebugLogger::log( 'Settings saved. debug_mode=' . ( $current['debug_mode'] ? 'true' : 'false' ), 'AdminPage::ajax_save_settings' );

		wp_send_json_success( [
			'message'      => __( 'Settings saved.', 'd5-design-system-helper' ),
			'debug_mode'   => $current['debug_mode'],
			'beta_preview' => $current['beta_preview'],
		] );
	}

	// ── Render ────────────────────────────────────────────────────────────────

	/**
	 * Render the full admin page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$debug_on   = DebugLogger::is_active();
		$settings   = self::get_settings();
		$beta_on    = ! empty( $settings['beta_preview'] );
		$active_tab = sanitize_key( $_GET['tab'] ?? 'manage' );

		$blog_name  = get_bloginfo( 'name' );
		if ( '' === trim( $blog_name ) ) {
			$blog_name = parse_url( home_url(), PHP_URL_HOST ) ?? 'site';
		}
		$blog_slug  = strtolower( preg_replace( '/[^a-z0-9]+/i', '_', $blog_name ) );
		$blog_slug  = trim( $blog_slug, '_' );
		$site_abbr  = ! empty( $settings['site_abbr'] ) ? $settings['site_abbr'] : $blog_slug;
		// If a beta-only tab is requested without beta enabled, fall back to manage.
		if ( ! $beta_on && in_array( $active_tab, [ 'audit', 'styleguide', 'snapshots' ], true ) ) {
			$active_tab = 'manage';
		}
		?>
		<?php if ( $debug_on ) : ?>
		<div class="d5dsh-debug-banner" role="alert" aria-live="polite">
			<span class="d5dsh-debug-banner-icon">&#9888;</span>
			<?php esc_html_e( 'DEBUG MODE ACTIVE — detailed errors are logged to d5dsh-logs/debug.log', 'd5-design-system-helper' ); ?>
		</div>
		<?php endif; ?>
		<div class="wrap d5dsh-wrap">
			<div class="d5dsh-top-bar">
				<h1 class="d5dsh-top-title"><?php esc_html_e( 'Divi 5 Design System Helper', 'd5-design-system-helper' ); ?></h1>
				<div class="d5dsh-top-icons">
					<button type="button" class="d5dsh-icon-btn" id="d5dsh-btn-help" title="<?php esc_attr_e( 'Help', 'd5-design-system-helper' ); ?>">&#63;</button>
					<button type="button" class="d5dsh-icon-btn" id="d5dsh-btn-settings" title="<?php esc_attr_e( 'Settings', 'd5-design-system-helper' ); ?>">&#9881;</button>
					<button type="button" class="d5dsh-icon-btn" id="d5dsh-btn-contact" title="<?php esc_attr_e( 'Contact / Feedback', 'd5-design-system-helper' ); ?>">&#9993;</button>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<nav class="nav-tab-wrapper d5dsh-tabs">
				<?php
				$beta_tabs = [ 'audit', 'styleguide', 'snapshots' ];
				foreach ( [
					'manage'     => __( 'Manage',      'd5-design-system-helper' ),
					'export'     => __( 'Export',      'd5-design-system-helper' ),
					'import'     => __( 'Import',      'd5-design-system-helper' ),
					'audit'      => __( 'Analysis',    'd5-design-system-helper' ),
					'styleguide' => __( 'Style Guide', 'd5-design-system-helper' ),
					'snapshots'  => __( 'Snapshots',   'd5-design-system-helper' ),
				] as $tab => $label ) :
					$is_beta_tab = in_array( $tab, $beta_tabs, true );
					if ( $is_beta_tab && ! $beta_on ) {
						continue;
					}
					$tab_classes = 'nav-tab';
					if ( $active_tab === $tab ) { $tab_classes .= ' nav-tab-active'; }
					if ( $is_beta_tab )         { $tab_classes .= ' d5dsh-beta-feature'; }
				?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => $tab ], admin_url( 'admin.php' ) ) ); ?>"
					   class="<?php echo esc_attr( $tab_classes ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="d5dsh-tab-content">
				<?php
				match ( $active_tab ) {
					'export'    => $this->render_export_tab(),
					'import'    => $this->render_import_tab(),
					'manage'    => $this->render_manage_tab(),
					'snapshots' => $this->render_snapshots_tab(),
					'audit'      => $this->render_audit_tab(),
					'styleguide' => $this->render_styleguide_tab(),
						default      => $this->render_manage_tab(),
				};
				?>
			</div>
		</div>

		<?php /* ── Settings modal ───────────────────────────────────── */ ?>
		<div id="d5dsh-settings-modal" class="d5dsh-modal" style="display:none" role="dialog" aria-modal="true">
			<div class="d5dsh-modal-box">
				<div class="d5dsh-modal-header">
					<span class="d5dsh-modal-title"><?php esc_html_e( 'Settings', 'd5-design-system-helper' ); ?></span>
					<button type="button" class="d5dsh-modal-close" data-modal="d5dsh-settings-modal">&times;</button>
				</div>
				<nav class="d5dsh-modal-tabs">
					<button type="button" class="d5dsh-modal-tab d5dsh-modal-tab-active" data-tab="general"><?php esc_html_e( 'General', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-modal-tab" data-tab="appearance"><?php esc_html_e( 'Appearance', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-modal-tab" data-tab="print"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-modal-tab" data-tab="advanced"><?php esc_html_e( 'Advanced', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-modal-tab" data-tab="about"><?php esc_html_e( 'About', 'd5-design-system-helper' ); ?></button>
				</nav>
				<div class="d5dsh-modal-body">
					<div class="d5dsh-modal-pane" data-pane="general">
						<label class="d5dsh-setting-row d5dsh-setting-row-block">
							<span class="d5dsh-setting-label"><?php esc_html_e( 'Report Header', 'd5-design-system-helper' ); ?></span>
							<input type="text" id="d5dsh-setting-report-header" class="regular-text" value="<?php echo esc_attr( $settings['report_header'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. My Company — D5 Design System', 'd5-design-system-helper' ); ?>">
							<p class="d5dsh-setting-description"><?php esc_html_e( 'Optional text shown at the top of printed reports and saved report files.', 'd5-design-system-helper' ); ?></p>
						</label>
						<label class="d5dsh-setting-row d5dsh-setting-row-block">
							<span class="d5dsh-setting-label"><?php esc_html_e( 'Report Footer', 'd5-design-system-helper' ); ?></span>
							<input type="text" id="d5dsh-setting-report-footer" class="regular-text" value="<?php echo esc_attr( $settings['report_footer'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Confidential — Internal Use Only', 'd5-design-system-helper' ); ?>">
							<p class="d5dsh-setting-description"><?php esc_html_e( 'Optional text shown in the footer of printed reports alongside the page number.', 'd5-design-system-helper' ); ?></p>
						</label>
						<label class="d5dsh-setting-row d5dsh-setting-row-block">
							<span class="d5dsh-setting-label"><?php esc_html_e( 'Site Abbreviation', 'd5-design-system-helper' ); ?></span>
							<input type="text" id="d5dsh-setting-site-abbr" class="regular-text" value="<?php echo esc_attr( $settings['site_abbr'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $site_abbr ); ?>">
							<p class="d5dsh-setting-description"><?php printf( esc_html__( 'Short identifier used in all exported file names (letters, numbers, underscores only). Leave blank to use the auto-generated slug from your WordPress site name: %s', 'd5-design-system-helper' ), '<code>' . esc_html( $site_abbr ) . '</code>' ); ?></p>
						</label>
					</div>
					<div class="d5dsh-modal-pane" data-pane="appearance" style="display:none">
						<label class="d5dsh-setting-row">
							<input type="checkbox" id="d5dsh-setting-banding">
							<?php esc_html_e( 'Alternating row shading in Manage table', 'd5-design-system-helper' ); ?>
						</label>
					</div>
					<div class="d5dsh-modal-pane" data-pane="print" style="display:none">
						<p class="d5dsh-setting-description"><?php esc_html_e( 'Choose which Variable types are grouped together on the same worksheet when exporting to Excel. Deselecting a type places it on its own separate sheet.', 'd5-design-system-helper' ); ?></p>
						<strong><?php esc_html_e( 'Variable types to group on one worksheet', 'd5-design-system-helper' ); ?></strong>
						<div class="d5dsh-print-type-filters">
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-colors"   value="colors"   checked> <?php esc_html_e( 'Colors',  'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-numbers"  value="numbers"  checked> <?php esc_html_e( 'Numbers', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-fonts"    value="fonts"    checked> <?php esc_html_e( 'Fonts',   'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-images"   value="images"   checked> <?php esc_html_e( 'Images',  'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-strings"  value="strings"  checked> <?php esc_html_e( 'Text',    'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-type-chk" id="d5dsh-print-type-links"    value="links"    checked> <?php esc_html_e( 'Links',   'd5-design-system-helper' ); ?></label>
						</div>
						<p class="d5dsh-setting-description" style="margin-top:12px"><?php esc_html_e( 'Use the Export tab to choose which Variable types to include in the export file.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-modal-pane" data-pane="advanced" style="display:none">
						<label class="d5dsh-setting-row">
							<input type="checkbox" id="d5dsh-setting-debug-mode"<?php checked( $debug_on ); ?>>
							<?php esc_html_e( 'Debug mode', 'd5-design-system-helper' ); ?>
						</label>
						<p class="d5dsh-setting-description">
							<?php esc_html_e( 'When enabled: detailed error information is shown in notices and written to d5dsh-logs/debug.log inside wp-content/uploads. A banner is displayed at the top of every page. Turn off when not troubleshooting.', 'd5-design-system-helper' ); ?>
						</p>
						<label class="d5dsh-setting-row">
							<input type="checkbox" id="d5dsh-setting-beta-preview"<?php checked( $beta_on ); ?>>
							<?php esc_html_e( 'Enable Beta Preview', 'd5-design-system-helper' ); ?>
						</label>
						<p class="d5dsh-setting-description">
							<?php esc_html_e( 'When enabled: shows the Snapshots and Audit tabs, the Audit button on the Import page, Bulk Label Change mode, and blank import template downloads. Off by default.', 'd5-design-system-helper' ); ?>
						</p>
					</div>
					<div class="d5dsh-modal-pane" data-pane="about" style="display:none">
						<p><strong><?php esc_html_e( 'D5 Design System Helper', 'd5-design-system-helper' ); ?></strong></p>
						<p><?php echo esc_html( 'Version ' . D5DSH_VERSION ); ?></p>
						<p><?php esc_html_e( 'Export, import, and manage your Divi 5 Design System data.', 'd5-design-system-helper' ); ?></p>
						<p><?php echo esc_html( '© ' . gmdate( 'Y' ) . ' Andrew Konstantaras and Claude Code. All rights reserved.' ); ?></p>
						<p><a href="https://github.com/akonsta/d5-design-system-helper" target="_blank" rel="noopener"><?php esc_html_e( 'GitHub Repository', 'd5-design-system-helper' ); ?></a></p>
					</div>
				</div>
				<div class="d5dsh-settings-footer">
					<button type="button" id="d5dsh-settings-save" class="button button-primary" disabled>
						<?php esc_html_e( 'Save Settings', 'd5-design-system-helper' ); ?>
					</button>
					<span id="d5dsh-settings-save-status" class="d5dsh-settings-save-status" aria-live="polite"></span>
				</div>
			</div>
		</div>

		<?php /* ── Help aside panel (slide-in, non-modal) ── */ ?>
		<aside id="d5dsh-help-panel" class="d5dsh-help-panel" aria-label="<?php esc_attr_e( 'User Guide', 'd5-design-system-helper' ); ?>" hidden>
			<div class="d5dsh-help-panel-header">
				<span class="d5dsh-help-panel-title"><?php esc_html_e( 'User Guide', 'd5-design-system-helper' ); ?></span>
				<div class="d5dsh-help-panel-controls">
					<input
						type="search"
						id="d5dsh-help-search"
						class="d5dsh-help-search"
						placeholder="<?php esc_attr_e( 'Search help…', 'd5-design-system-helper' ); ?>"
						aria-label="<?php esc_attr_e( 'Search help content', 'd5-design-system-helper' ); ?>"
					>
					<button type="button" id="d5dsh-help-close" class="d5dsh-help-close" aria-label="<?php esc_attr_e( 'Close help panel', 'd5-design-system-helper' ); ?>">&times;</button>
				</div>
			</div>
			<div id="d5dsh-help-search-results" class="d5dsh-help-search-results" hidden></div>
			<div id="d5dsh-help-body" class="d5dsh-help-body">
				<p class="d5dsh-help-loading"><?php esc_html_e( 'Loading user guide…', 'd5-design-system-helper' ); ?></p>
			</div>
		</aside>

		<?php /* ── Print setup modal ───────────────────────────── */ ?>
		<div id="d5dsh-print-modal" class="d5dsh-modal" style="display:none" role="dialog" aria-modal="true">
			<div class="d5dsh-modal-box d5dsh-print-modal-box">
				<div class="d5dsh-modal-header">
					<span class="d5dsh-modal-title"><?php esc_html_e( 'Print Setup', 'd5-design-system-helper' ); ?></span>
					<button type="button" class="d5dsh-modal-close" data-modal="d5dsh-print-modal">&times;</button>
				</div>
				<div class="d5dsh-modal-body">
					<div class="d5dsh-print-setup-section">
						<strong><?php esc_html_e( 'Orientation', 'd5-design-system-helper' ); ?></strong>
						<div class="d5dsh-print-orientation">
							<label class="d5dsh-setting-row">
								<input type="radio" name="d5dsh-print-orientation" value="portrait" checked>
								<?php esc_html_e( 'Portrait', 'd5-design-system-helper' ); ?>
							</label>
							<label class="d5dsh-setting-row">
								<input type="radio" name="d5dsh-print-orientation" value="landscape">
								<?php esc_html_e( 'Landscape', 'd5-design-system-helper' ); ?>
							</label>
						</div>
					</div>
					<div class="d5dsh-print-setup-section">
						<strong><?php esc_html_e( 'Margins (inches, min 0.3)', 'd5-design-system-helper' ); ?></strong>
						<div class="d5dsh-print-margins">
							<label class="d5dsh-print-margin-field">
								<span><?php esc_html_e( 'Top', 'd5-design-system-helper' ); ?></span>
								<input type="number" id="d5dsh-print-margin-top" class="d5dsh-print-margin-input" value="0.5" min="0.3" max="2" step="0.1">
							</label>
							<label class="d5dsh-print-margin-field">
								<span><?php esc_html_e( 'Right', 'd5-design-system-helper' ); ?></span>
								<input type="number" id="d5dsh-print-margin-right" class="d5dsh-print-margin-input" value="0.5" min="0.3" max="2" step="0.1">
							</label>
							<label class="d5dsh-print-margin-field">
								<span><?php esc_html_e( 'Bottom', 'd5-design-system-helper' ); ?></span>
								<input type="number" id="d5dsh-print-margin-bottom" class="d5dsh-print-margin-input" value="0.5" min="0.3" max="2" step="0.1">
							</label>
							<label class="d5dsh-print-margin-field">
								<span><?php esc_html_e( 'Left', 'd5-design-system-helper' ); ?></span>
								<input type="number" id="d5dsh-print-margin-left" class="d5dsh-print-margin-input" value="0.5" min="0.3" max="2" step="0.1">
							</label>
						</div>
					</div>
					<div class="d5dsh-print-setup-section">
						<strong><?php esc_html_e( 'Columns to print', 'd5-design-system-helper' ); ?></strong>
						<div class="d5dsh-print-cols">
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="order" checked> <?php esc_html_e( '#', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="type" checked> <?php esc_html_e( 'Type', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="id" checked> <?php esc_html_e( 'ID', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="label" checked> <?php esc_html_e( 'Label', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="swatch"> <?php esc_html_e( 'Swatch', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="value" checked> <?php esc_html_e( 'Value', 'd5-design-system-helper' ); ?></label>
							<label class="d5dsh-setting-row"><input type="checkbox" class="d5dsh-print-col-chk" value="status"> <?php esc_html_e( 'Status', 'd5-design-system-helper' ); ?></label>
						</div>
					</div>
				</div>
				<div class="d5dsh-modal-footer">
					<button type="button" class="button" data-modal="d5dsh-print-modal" id="d5dsh-print-cancel"><?php esc_html_e( 'Cancel', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button button-primary" id="d5dsh-print-go"><?php esc_html_e( 'Preview &amp; Print', 'd5-design-system-helper' ); ?></button>
				</div>
			</div>
		</div>

		<?php /* ── Contact modal ─────────────────────────────────── */ ?>
		<?php /* ── Impact / Dependency modal ──────────────────────── */ ?>
		<div id="d5dsh-impact-modal" class="d5dsh-modal" style="display:none" role="dialog" aria-modal="true">
			<div class="d5dsh-modal-box d5dsh-modal-box-wide">
				<div class="d5dsh-modal-header">
					<span class="d5dsh-modal-title" id="d5dsh-impact-modal-title"><?php esc_html_e( 'Impact Analysis', 'd5-design-system-helper' ); ?></span>
					<button type="button" class="d5dsh-modal-close" data-modal="d5dsh-impact-modal">&times;</button>
				</div>
				<nav class="d5dsh-modal-tabs">
					<button type="button" class="d5dsh-modal-tab d5dsh-modal-tab-active" data-tab="impact"><?php esc_html_e( 'What Breaks?', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-modal-tab" data-tab="deps"><?php esc_html_e( 'Dependencies', 'd5-design-system-helper' ); ?></button>
				</nav>
				<div class="d5dsh-impact-toolbar" id="d5dsh-impact-toolbar">
					<button type="button" class="button button-small" id="d5dsh-impact-expand-all"><?php esc_html_e( 'Expand All', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button button-small" id="d5dsh-impact-collapse-all"><?php esc_html_e( 'Collapse All', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button button-small" id="d5dsh-impact-print"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button button-small d5dsh-impact-help-toggle" id="d5dsh-impact-help-toggle">?</button>
				</div>
				<div class="d5dsh-impact-help-panel" id="d5dsh-impact-help-panel" style="display:none">
					<div class="d5dsh-impact-help-cols">
						<div class="d5dsh-impact-help-col">
							<h5><?php esc_html_e( 'Content Types', 'd5-design-system-helper' ); ?></h5>
							<dl>
								<dt>page</dt>          <dd><?php esc_html_e( 'WordPress page', 'd5-design-system-helper' ); ?></dd>
								<dt>post</dt>          <dd><?php esc_html_e( 'WordPress blog post', 'd5-design-system-helper' ); ?></dd>
								<dt>et_pb_layout</dt>  <dd><?php esc_html_e( 'Divi Library layout (saved section, row, or module)', 'd5-design-system-helper' ); ?></dd>
								<dt>et_template</dt>   <dd><?php esc_html_e( 'Divi Theme Builder template (header, body, or footer canvas)', 'd5-design-system-helper' ); ?></dd>
								<dt>et_header_layout</dt> <dd><?php esc_html_e( 'Divi Theme Builder header layout', 'd5-design-system-helper' ); ?></dd>
								<dt>et_body_layout</dt>   <dd><?php esc_html_e( 'Divi Theme Builder body layout', 'd5-design-system-helper' ); ?></dd>
								<dt>et_footer_layout</dt> <dd><?php esc_html_e( 'Divi Theme Builder footer layout', 'd5-design-system-helper' ); ?></dd>
							</dl>
						</div>
						<div class="d5dsh-impact-help-col">
							<h5><?php esc_html_e( 'Status Values', 'd5-design-system-helper' ); ?></h5>
							<dl>
								<dt>publish</dt>   <dd><?php esc_html_e( 'Live on the site — visitors can see it', 'd5-design-system-helper' ); ?></dd>
								<dt>draft</dt>     <dd><?php esc_html_e( 'Saved but not public', 'd5-design-system-helper' ); ?></dd>
								<dt>pending</dt>   <dd><?php esc_html_e( 'Awaiting editorial review', 'd5-design-system-helper' ); ?></dd>
								<dt>future</dt>    <dd><?php esc_html_e( 'Scheduled for future publication', 'd5-design-system-helper' ); ?></dd>
								<dt>private</dt>   <dd><?php esc_html_e( 'Visible only to editors and admins', 'd5-design-system-helper' ); ?></dd>
								<dt>trash</dt>     <dd><?php esc_html_e( 'In the trash — still scanned because references remain in the database', 'd5-design-system-helper' ); ?></dd>
							</dl>
						</div>
					</div>
					<p class="d5dsh-impact-help-guide-ref"><?php esc_html_e( 'For a full explanation, see Section 17 (Impact Modal) in the User Guide, accessible from the help panel (? icon in the top-right corner of the plugin).', 'd5-design-system-helper' ); ?></p>
				</div>
				<div class="d5dsh-modal-body">
					<div class="d5dsh-modal-pane" data-pane="impact" id="d5dsh-impact-pane"></div>
					<div class="d5dsh-modal-pane" data-pane="deps" id="d5dsh-deps-pane" style="display:none"></div>
				</div>
				<div class="d5dsh-modal-footer">
					<span id="d5dsh-impact-delete-warning" class="d5dsh-impact-warning"></span>
					<button type="button" class="button d5dsh-modal-close" data-modal="d5dsh-impact-modal"><?php esc_html_e( 'Close', 'd5-design-system-helper' ); ?></button>
				</div>
			</div>
		</div>

		<div id="d5dsh-contact-modal" class="d5dsh-modal" style="display:none" role="dialog" aria-modal="true">
			<div class="d5dsh-modal-box">
				<div class="d5dsh-modal-header">
					<span class="d5dsh-modal-title"><?php esc_html_e( 'Contact / Feedback', 'd5-design-system-helper' ); ?></span>
					<button type="button" class="d5dsh-modal-close" data-modal="d5dsh-contact-modal">&times;</button>
				</div>
				<div class="d5dsh-modal-body">
					<p><?php esc_html_e( 'To report a bug or request a feature, please use GitHub Issues:', 'd5-design-system-helper' ); ?></p>
					<p><a href="https://github.com/akonsta/d5-design-system-helper/issues" target="_blank" rel="noopener"><?php esc_html_e( 'Open an Issue on GitHub', 'd5-design-system-helper' ); ?></a></p>
					<p><?php esc_html_e( 'Or send us an email with suggestions or feedback:', 'd5-design-system-helper' ); ?></p>
					<p><a href="mailto:konsta@me2we.com"><?php esc_html_e( 'konsta@me2we.com', 'd5-design-system-helper' ); ?></a></p>
				</div>
			</div>
		</div>

		<?php
	}

	// ── Export tab ────────────────────────────────────────────────────────────

	/**
	 * Return object counts for display next to export checkboxes.
	 *
	 * @return array<string,int|array<string,int>>
	 */
	private function get_export_counts(): array {
		$vars_repo    = new VarsRepository();
		$presets_repo = new PresetsRepository();

		// get_all() returns a flat array of records (not keyed by section).
		// Count by type — colors, fonts, numbers, images, strings, links are all present.
		$all_vars   = $vars_repo->get_all();
		$var_counts = [];
		foreach ( $all_vars as $v ) {
			if ( ! is_array( $v ) ) { continue; }
			$t = $v['type'] ?? 'unknown';
			$var_counts[ $t ] = ( $var_counts[ $t ] ?? 0 ) + 1;
		}

		$presets_raw  = $presets_repo->get_raw();
		// Count preset elements (all items inside each module's items array).
		$preset_elements = 0;
		foreach ( $presets_raw['module'] ?? [] as $module_presets ) {
			$preset_elements += count( $module_presets['items'] ?? [] );
		}
		// Count preset groups.
		$preset_groups = 0;
		foreach ( $presets_raw['group'] ?? [] as $group_presets ) {
			$preset_groups += count( $group_presets['items'] ?? [] );
		}

		$layouts_count     = (int) ( wp_count_posts( 'et_pb_layout' )->publish ?? 0 );
		$pages_count       = (int) ( wp_count_posts( 'page' )->publish ?? 0 );
		$customizer_count  = count( get_option( 'theme_mods_Divi', [] ) );
		$templates_count   = (int) ( wp_count_posts( 'et_template' )->publish ?? 0 );

		$vars_total    = array_sum( $var_counts );
		$presets_total = $preset_elements + $preset_groups;
		$everything    = $vars_total + $presets_total + $layouts_count + $pages_count + $customizer_count + $templates_count;

		return [
			'everything'        => $everything,
			'vars'              => $vars_total,
			'var_types'         => $var_counts,
			'presets'           => $presets_total,
			'preset_elements'   => $preset_elements,
			'preset_groups'     => $preset_groups,
			'layouts'           => $layouts_count,
			'pages'             => $pages_count,
			'theme_customizer'  => $customizer_count,
			'builder_templates' => $templates_count,
		];
	}

	/**
	 * Render the Export tab.
	 */
	private function render_export_tab(): void {
		$counts = $this->get_export_counts();
		?>
		<div class="d5dsh-panel">
			<h2><?php esc_html_e( 'Export', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Export Variables and Presets to Excel (.xlsx) for editing and reimporting. Variables and Presets are also available as JSON in Divi-native format, importable directly via Divi\'s own interface. The DTCG option exports Variables only in the W3C Design Tokens format, compatible with Figma, Style Dictionary, and similar tools. Additional export options are available in Settings (gear icon, upper right).', 'd5-design-system-helper' ); ?></p>

			<form method="post" action="" id="d5dsh-export-form">
				<?php wp_nonce_field( self::NONCE_EXPORT ); ?>
				<input type="hidden" name="d5dsh_action" value="export">

				<div class="d5dsh-tree">

					<div class="d5dsh-tree-item d5dsh-tree-root">
						<label class="d5dsh-tree-label">
							<input type="checkbox" class="d5dsh-cb" id="cb-everything"
							       data-children="cb-vars cb-presets">
							<strong><?php esc_html_e( 'Everything', 'd5-design-system-helper' ); ?></strong>
							<span class="d5dsh-count-badge"><?php echo (int) ( $counts['everything'] ?? 0 ); ?></span>
						</label>
					</div>

					<div class="d5dsh-tree-item d5dsh-tree-l1">
						<label class="d5dsh-tree-label">
							<input type="checkbox" class="d5dsh-cb" id="cb-vars"
							       data-parent="cb-everything"
							       data-children="cb-vars-colors cb-vars-numbers cb-vars-fonts cb-vars-images cb-vars-strings cb-vars-links">
							<?php esc_html_e( 'All Variables', 'd5-design-system-helper' ); ?>
							<span class="d5dsh-count-badge"><?php echo (int) ( $counts['vars'] ?? 0 ); ?></span>
						</label>
						<div class="d5dsh-tree-children">
							<?php foreach ( [ 'colors' => 'Colors', 'numbers' => 'Numbers', 'fonts' => 'Fonts', 'images' => 'Images', 'strings' => 'Text', 'links' => 'Links' ] as $sub => $lbl ) :
								$sub_count = $counts['var_types'][ $sub ] ?? 0;
							?>
							<div class="d5dsh-tree-item d5dsh-tree-l2">
								<label class="d5dsh-tree-label">
									<input type="checkbox" class="d5dsh-cb" id="cb-vars-<?php echo esc_attr( $sub ); ?>"
									       name="d5dsh_types[]" value="vars"
									       data-parent="cb-vars">
									<?php echo esc_html( $lbl ); ?>
									<span class="d5dsh-count-badge"><?php echo (int) $sub_count; ?></span>
								</label>
							</div>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="d5dsh-tree-item d5dsh-tree-l1">
						<label class="d5dsh-tree-label">
							<input type="checkbox" class="d5dsh-cb" id="cb-presets"
							       data-parent="cb-everything"
							       data-children="cb-presets-module cb-presets-group">
							<?php esc_html_e( 'All Presets', 'd5-design-system-helper' ); ?>
							<span class="d5dsh-count-badge"><?php echo (int) ( $counts['presets'] ?? 0 ); ?></span>
						</label>
						<div class="d5dsh-tree-children">
							<div class="d5dsh-tree-item d5dsh-tree-l2">
								<label class="d5dsh-tree-label">
									<input type="checkbox" class="d5dsh-cb" id="cb-presets-module"
									       name="d5dsh_types[]" value="presets"
									       data-parent="cb-presets">
									<?php esc_html_e( 'Element Presets', 'd5-design-system-helper' ); ?>
									<span class="d5dsh-count-badge"><?php echo (int) ( $counts['preset_elements'] ?? 0 ); ?></span>
								</label>
							</div>
							<div class="d5dsh-tree-item d5dsh-tree-l2">
								<label class="d5dsh-tree-label">
									<input type="checkbox" class="d5dsh-cb" id="cb-presets-group"
									       name="d5dsh_types[]" value="presets"
									       data-parent="cb-presets">
									<?php esc_html_e( 'Group Presets', 'd5-design-system-helper' ); ?>
									<span class="d5dsh-count-badge"><?php echo (int) ( $counts['preset_groups'] ?? 0 ); ?></span>
								</label>
							</div>
						</div>
					</div>

				</div><!-- .d5dsh-tree -->

				<details class="d5dsh-additional-info" id="d5dsh-additional-info">
					<summary class="d5dsh-section-heading">
						<?php esc_html_e( 'Additional Information (optional):', 'd5-design-system-helper' ); ?>
					</summary>
					<div class="d5dsh-additional-info-body">
						<p class="d5dsh-additional-info-note">
							<?php esc_html_e( 'Any additional information provided will only be included in an Excel export.', 'd5-design-system-helper' ); ?>
						</p>
						<table class="d5dsh-info-table">
							<tr>
								<td><label for="d5dsh_info_owner"><?php esc_html_e( 'Owner / Web Designer Name', 'd5-design-system-helper' ); ?></label></td>
								<td><input type="text" id="d5dsh_info_owner" name="d5dsh_info_owner" class="d5dsh-info-input" value="<?php echo esc_attr( $_POST['d5dsh_info_owner'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_customer"><?php esc_html_e( 'Customer Name', 'd5-design-system-helper' ); ?></label></td>
								<td><input type="text" id="d5dsh_info_customer" name="d5dsh_info_customer" class="d5dsh-info-input" value="<?php echo esc_attr( $_POST['d5dsh_info_customer'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_company"><?php esc_html_e( 'Customer Company', 'd5-design-system-helper' ); ?></label></td>
								<td><input type="text" id="d5dsh_info_company" name="d5dsh_info_company" class="d5dsh-info-input" value="<?php echo esc_attr( $_POST['d5dsh_info_company'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_project"><?php esc_html_e( 'Project Name', 'd5-design-system-helper' ); ?></label></td>
								<td><input type="text" id="d5dsh_info_project" name="d5dsh_info_project" class="d5dsh-info-input" value="<?php echo esc_attr( $_POST['d5dsh_info_project'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_version_tag"><?php esc_html_e( 'Project / Version Tag', 'd5-design-system-helper' ); ?></label></td>
								<td><input type="text" id="d5dsh_info_version_tag" name="d5dsh_info_version_tag" class="d5dsh-info-input" value="<?php echo esc_attr( $_POST['d5dsh_info_version_tag'] ?? '' ); ?>"></td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_status"><?php esc_html_e( 'Project Status', 'd5-design-system-helper' ); ?></label></td>
								<td>
									<select id="d5dsh_info_status" name="d5dsh_info_status">
										<?php
										$status_options = [ '', 'Local', 'Dev', 'Test/QA', 'User Acceptance Testing', 'Alpha', 'Beta', 'Production', 'Maintenance', 'Redesign' ];
										$current_status = $_POST['d5dsh_info_status'] ?? '';
										foreach ( $status_options as $opt ) {
											printf(
												'<option value="%s"%s>%s</option>',
												esc_attr( $opt ),
												selected( $current_status, $opt, false ),
												esc_html( $opt === '' ? '— Select —' : $opt )
											);
										}
										?>
									</select>
								</td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_environment"><?php esc_html_e( 'Environment', 'd5-design-system-helper' ); ?></label></td>
								<td>
									<select id="d5dsh_info_environment" name="d5dsh_info_environment">
										<?php
										$env_options = [ '', 'Design/Build', 'Test/Stage', 'Live' ];
										$current_env = $_POST['d5dsh_info_environment'] ?? '';
										foreach ( $env_options as $opt ) {
											printf(
												'<option value="%s"%s>%s</option>',
												esc_attr( $opt ),
												selected( $current_env, $opt, false ),
												esc_html( $opt === '' ? '— Select —' : $opt )
											);
										}
										?>
									</select>
								</td>
							</tr>
							<tr>
								<td><label for="d5dsh_info_comments"><?php esc_html_e( 'Comments', 'd5-design-system-helper' ); ?></label></td>
								<td><textarea id="d5dsh_info_comments" name="d5dsh_info_comments" class="d5dsh-info-input d5dsh-info-textarea" rows="3"><?php echo esc_textarea( $_POST['d5dsh_info_comments'] ?? '' ); ?></textarea></td>
							</tr>
						</table>
					</div>
				<div class="d5dsh-addlinfo-actions">
					<button type="button" id="d5dsh-addlinfo-save" class="button button-small"><?php esc_html_e( 'Save', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-addlinfo-clear" class="button button-small d5dsh-btn-clear"><?php esc_html_e( 'Reset', 'd5-design-system-helper' ); ?></button>
				</div>
				</details>

				<div class="d5dsh-export-format">
					<strong><?php esc_html_e( 'Format:', 'd5-design-system-helper' ); ?></strong>
					<label class="d5dsh-format-label">
						<input type="radio" name="d5dsh_format" value="xlsx" checked>
						<?php esc_html_e( 'Excel (.xlsx) — for editing and reimporting via this plugin', 'd5-design-system-helper' ); ?>
					</label>
					<label class="d5dsh-format-label">
						<input type="radio" name="d5dsh_format" value="json">
						<?php esc_html_e( 'JSON (.json) — Divi-native format for Variables and Presets; importable directly via Divi\'s own interface', 'd5-design-system-helper' ); ?>
					</label>
					<label class="d5dsh-format-label">
						<input type="radio" name="d5dsh_format" value="dtcg">
						<?php esc_html_e( 'DTCG (design-tokens.json) — W3C Design Tokens format for Figma, Style Dictionary, and other tools', 'd5-design-system-helper' ); ?>
						<button
							type="button"
							class="d5dsh-help-trigger d5dsh-help-trigger-inline"
							aria-label="<?php esc_attr_e( 'About DTCG export — open user guide', 'd5-design-system-helper' ); ?>"
							title="<?php esc_attr_e( 'Click to open the User Guide section on DTCG Export.', 'd5-design-system-helper' ); ?>"
							data-help-anchor="12-dtcg-export-design-tokens-format"
						>&#9432;</button>
					</label>
					<p id="d5dsh-dtcg-note" class="d5dsh-dtcg-note" style="display:none;">
						<?php esc_html_e( 'DTCG export includes variables only (colors, numbers, fonts, strings). Other selected types will be ignored.', 'd5-design-system-helper' ); ?>
					</p>
				</div>

				<div class="d5dsh-export-actions">
					<p id="d5dsh-selection-hint" class="d5dsh-selection-hint d5dsh-hint-none">
						<?php esc_html_e( 'No types selected', 'd5-design-system-helper' ); ?>
					</p>
					<p id="d5dsh-export-pending-warning" class="d5dsh-export-pending-warning" style="display:none">
						⚠ You have unsaved variable changes. Save or discard them on the Manage tab before exporting.
					</p>
					<button type="submit" class="button button-primary d5dsh-btn-export">
						<?php esc_html_e( 'Export Selected', 'd5-design-system-helper' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	// ── Import tab ────────────────────────────────────────────────────────────

	/**
	 * Render the Import tab.
	 */
	private function render_import_tab(): void {
		$this->render_dry_run_results();
		$this->render_import_result();
		?>
		<div class="d5dsh-panel d5dsh-import-panel">

			<?php /* ── Simple Import ─────────────────────────────────────── */ ?>
			<div class="d5dsh-si-page-header">
				<div class="d5dsh-si-page-header-main">
					<h2><?php esc_html_e( 'Import', 'd5-design-system-helper' ); ?></h2>
					<p class="d5dsh-import-intro"><?php esc_html_e( 'Upload a .json, .xlsx, .zip, or design-tokens.json (DTCG) file exported by this plugin or a compatible tool. Files are analysed before importing — you can review and select which files to import.', 'd5-design-system-helper' ); ?></p>
				</div>
				<div class="d5dsh-si-page-header-utils" id="d5dsh-si-header-utils" style="display:none">
					<button type="button" class="d5dsh-icon-btn" id="d5dsh-si-print-report-btn" title="<?php esc_attr_e( 'Print report', 'd5-design-system-helper' ); ?>">&#9113;</button>
					<button type="button" class="d5dsh-icon-btn" id="d5dsh-si-download-report-btn" title="<?php esc_attr_e( 'Download report (CSV)', 'd5-design-system-helper' ); ?>">&#8595;</button>
				</div>
			</div>

			<?php /* Drop zone */ ?>
			<div id="d5dsh-si-dropzone" class="d5dsh-si-dropzone" role="button" tabindex="0" aria-label="<?php esc_attr_e( 'Drop file here or click to browse', 'd5-design-system-helper' ); ?>">
				<div class="d5dsh-si-dropzone-inner">
					<svg class="d5dsh-si-upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<polyline points="16 16 12 12 8 16"></polyline>
						<line x1="12" y1="12" x2="12" y2="21"></line>
						<path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"></path>
					</svg>
					<p class="d5dsh-si-dropzone-label"><?php esc_html_e( 'Drop file here or', 'd5-design-system-helper' ); ?> <span class="d5dsh-si-browse-link"><?php esc_html_e( 'browse', 'd5-design-system-helper' ); ?></span></p>
					<p class="d5dsh-si-dropzone-hint"><?php esc_html_e( 'Supports .json (including design-tokens.json), .xlsx, .zip', 'd5-design-system-helper' ); ?></p>
				</div>
				<input type="file" id="d5dsh-si-file-input" class="d5dsh-si-file-input" accept=".json,.xlsx,.zip">
			</div>

			<?php /* State: spinner */ ?>
			<div id="d5dsh-si-analyzing" class="d5dsh-si-state" style="display:none">
				<span class="spinner is-active" style="float:none"></span>
				<span class="d5dsh-si-state-label"><?php esc_html_e( 'Analysing file…', 'd5-design-system-helper' ); ?></span>
			</div>

			<?php /* State: error */ ?>
			<div id="d5dsh-si-error" class="d5dsh-si-state d5dsh-si-error-state" style="display:none">
				<p id="d5dsh-si-error-msg"></p>
				<button type="button" class="button d5dsh-si-reset-btn"><?php esc_html_e( 'Try again', 'd5-design-system-helper' ); ?></button>
			</div>

			<?php /* State: analysis results */ ?>
			<div id="d5dsh-si-analysis" style="display:none">

				<?php /* Action row — Import | Audit | Convert to Excel | Go Back */ ?>
				<div class="d5dsh-si-action-row">
					<div class="d5dsh-si-action-row-left">
						<button type="button" id="d5dsh-si-import-btn" class="button button-primary">
							<?php esc_html_e( 'Import', 'd5-design-system-helper' ); ?>
						</button>
						<span id="d5dsh-si-import-spinner" class="spinner" style="float:none;display:none;"></span>
						<span class="d5dsh-si-audit-wrap d5dsh-beta-feature">
							<span class="d5dsh-beta-badge"><?php esc_html_e( 'Beta', 'd5-design-system-helper' ); ?></span>
							<button type="button" id="d5dsh-si-audit-btn" class="button button-secondary" title="<?php esc_attr_e( 'Generate and download an audit report', 'd5-design-system-helper' ); ?>">
								<?php esc_html_e( 'Audit', 'd5-design-system-helper' ); ?>
							</button>
						</span>
						<button type="button" id="d5dsh-si-convert-btn" class="button button-secondary">
							<?php esc_html_e( 'Convert to Excel', 'd5-design-system-helper' ); ?>
						</button>
						<button type="button" class="button d5dsh-si-reset-btn d5dsh-si-go-back-btn">
							<?php esc_html_e( 'Go Back', 'd5-design-system-helper' ); ?>
						</button>
					</div>
				</div>

				<?php /* Select-all row: hidden for single file, shown for zip by JS */ ?>
				<div id="d5dsh-si-check-all-wrap" class="d5dsh-si-select-all-row" style="display:none">
					<label class="d5dsh-si-check-all-label">
						<input type="checkbox" id="d5dsh-si-check-all">
						<?php esc_html_e( 'Select all', 'd5-design-system-helper' ); ?>
					</label>
					<span id="d5dsh-si-selection-count" class="d5dsh-si-selection-count"></span>
				</div>

				<?php /* File card list (for zip) */ ?>
				<div id="d5dsh-si-file-list" style="display:none">
					<div id="d5dsh-si-file-rows" class="d5dsh-si-card-list"></div>
				</div>

				<?php /* Unified single-file card (filename + imported items + preliminary analysis) — populated by JS */ ?>
				<div id="d5dsh-si-single-summary" style="display:none">
					<div id="d5dsh-si-single-summary-body"></div>
				</div>

				<?php /* xlsx dry-run diff (below the card) */ ?>
				<div id="d5dsh-si-xlsx-diff" style="display:none">
					<h4 class="d5dsh-si-diff-title"><?php esc_html_e( 'Dry Run Preview', 'd5-design-system-helper' ); ?></h4>
					<div id="d5dsh-si-xlsx-diff-body"></div>
				</div>
			</div>

			<?php /* Results modal */ ?>
			<div id="d5dsh-si-results-modal" class="d5dsh-modal" style="display:none" role="dialog" aria-modal="true">
				<div class="d5dsh-modal-box d5dsh-si-results-modal-box">
					<div class="d5dsh-modal-header">
						<span class="d5dsh-modal-title"><?php esc_html_e( 'Import Results', 'd5-design-system-helper' ); ?></span>
						<button type="button" class="d5dsh-modal-close" data-modal="d5dsh-si-results-modal">&times;</button>
					</div>
					<div class="d5dsh-modal-body" id="d5dsh-si-results-body"></div>
					<div class="d5dsh-modal-footer">
						<button type="button" id="d5dsh-si-save-report-btn" class="button"><?php esc_html_e( 'Save Report (.txt)', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-si-print-results-btn" class="button"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
						<button type="button" class="button button-primary d5dsh-modal-close" data-modal="d5dsh-si-results-modal"><?php esc_html_e( 'Done', 'd5-design-system-helper' ); ?></button>
					</div>
				</div>
			</div>

			<?php /* Template download links — Beta feature */ ?>
			<div class="d5dsh-import-templates d5dsh-beta-feature">
				<span class="d5dsh-import-templates-label"><?php esc_html_e( 'Download blank import templates:', 'd5-design-system-helper' ); ?></span>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=d5dsh_dl_template&type=vars' ), 'd5dsh_dl_template_vars' ) ); ?>" class="d5dsh-template-link">
					<?php esc_html_e( 'Variables (.xlsx)', 'd5-design-system-helper' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=d5dsh_dl_template&type=presets' ), 'd5dsh_dl_template_presets' ) ); ?>" class="d5dsh-template-link">
					<?php esc_html_e( 'Presets (.xlsx)', 'd5-design-system-helper' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=d5dsh_dl_template&type=theme_customizer' ), 'd5dsh_dl_template_theme_customizer' ) ); ?>" class="d5dsh-template-link">
					<?php esc_html_e( 'Theme Customizer (.xlsx)', 'd5-design-system-helper' ); ?>
				</a>
			</div>

		</div>
		<?php
	}

	// ── Manage tab ────────────────────────────────────────────────────────────

	/**
	 * Render the Manage tab.
	 *
	 * Contains two sub-areas:
	 *   1. Variables section — AJAX-loaded via d5dsh_manage_load (LabelManager)
	 *   2. Presets sections  — AJAX-loaded via d5dsh_presets_manage_load (PresetsManager)
	 *
	 * A top-level section switcher (Variables | Group Presets | Element Presets |
	 * All Presets) controls which content area is visible. Only the active section's
	 * data is loaded (lazy), and only on first switch to that section.
	 */
	private function render_manage_tab(): void {
		?>
		<div class="d5dsh-panel" id="d5dsh-manage-panel">

		<?php /* ── Top section switcher ─────────────────────────────────── */ ?>
		<div class="d5dsh-section-switcher" id="d5dsh-section-switcher">
			<button type="button" class="d5dsh-section-btn d5dsh-section-active" data-section="variables">
				<?php esc_html_e( 'Variables', 'd5-design-system-helper' ); ?>
			</button>
			<button type="button" class="d5dsh-section-btn" data-section="group_presets">
				<?php esc_html_e( 'Group Presets', 'd5-design-system-helper' ); ?>
			</button>
			<button type="button" class="d5dsh-section-btn" data-section="element_presets">
				<?php esc_html_e( 'Element Presets', 'd5-design-system-helper' ); ?>
			</button>
			<button type="button" class="d5dsh-section-btn" data-section="all_presets">
				<?php esc_html_e( 'All Presets', 'd5-design-system-helper' ); ?>
			</button>
			<button type="button" class="d5dsh-section-btn" data-section="everything">
				<?php esc_html_e( 'Everything', 'd5-design-system-helper' ); ?>
			</button>
			<button type="button" class="d5dsh-section-btn d5dsh-beta-feature" data-section="categories">
				<?php esc_html_e( 'Categories', 'd5-design-system-helper' ); ?>
			</button>
		</div>

		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: Variables
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-variables" class="d5dsh-manage-section">

			<h2><?php esc_html_e( 'Manage Variables', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Use the column filters in the table below to view, select, and sort your Variables. Some columns truncate long values — hover over grey text to see the full value, or click it to copy it to the clipboard. The Notes column (second from left) lets you attach a note, tags, and suppression rules to any row — click the note icon to open the editor. Notes persist in the database and appear in Excel exports. Click the ℹ icon in the Deps column to see what uses this variable and what would break if it were deleted.', 'd5-design-system-helper' ); ?></p>

			<?php /* Mode switcher + operation bar */ ?>
			<div class="d5dsh-manage-bulk" id="d5dsh-manage-bulk">
				<div class="d5dsh-mode-switcher" id="d5dsh-mode-switcher">
					<button type="button" class="d5dsh-mode-btn d5dsh-mode-active" data-mode="view"><?php esc_html_e( 'View', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-mode-btn d5dsh-beta-feature" data-mode="manage"><?php esc_html_e( 'Bulk Label Change', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-mode-btn d5dsh-beta-feature" data-mode="merge"><?php esc_html_e( 'Merge Variables', 'd5-design-system-helper' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'audit' ], admin_url( 'admin.php' ) ) . '#d5dsh-analysis-scan' ); ?>"
				   class="d5dsh-mode-btn d5dsh-beta-feature"><?php esc_html_e( 'Scan', 'd5-design-system-helper' ); ?></a>
				</div>

				<div id="d5dsh-bulk-controls" style="display:none">
					<select id="d5dsh-bulk-op" class="d5dsh-bulk-select">
						<option value=""><?php esc_html_e( '— type of change —', 'd5-design-system-helper' ); ?></option>
						<option value="prefix"><?php esc_html_e( 'Add Prefix', 'd5-design-system-helper' ); ?></option>
						<option value="suffix"><?php esc_html_e( 'Add Suffix', 'd5-design-system-helper' ); ?></option>
						<option value="find_replace"><?php esc_html_e( 'Find &amp; Replace', 'd5-design-system-helper' ); ?></option>
						<option value="normalize"><?php esc_html_e( 'Change Label Case', 'd5-design-system-helper' ); ?></option>
					</select>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-fields-prefix" style="display:none">
						<input type="text" id="d5dsh-bulk-prefix-value" class="regular-text d5dsh-bulk-text"
						       placeholder="<?php esc_attr_e( 'Prefix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-fields-suffix" style="display:none">
						<input type="text" id="d5dsh-bulk-suffix-value" class="regular-text d5dsh-bulk-text"
						       placeholder="<?php esc_attr_e( 'Suffix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-fields-find_replace" style="display:none">
						<input type="text" id="d5dsh-bulk-find"    class="regular-text d5dsh-bulk-text"
						       placeholder="<?php esc_attr_e( 'Find', 'd5-design-system-helper' ); ?>">
						<input type="text" id="d5dsh-bulk-replace" class="regular-text d5dsh-bulk-text"
						       placeholder="<?php esc_attr_e( 'Replace with', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-fields-normalize" style="display:none">
						<select id="d5dsh-bulk-case" class="d5dsh-bulk-select">
							<option value="title"><?php esc_html_e( 'Title Case', 'd5-design-system-helper' ); ?></option>
							<option value="upper"><?php esc_html_e( 'UPPER CASE', 'd5-design-system-helper' ); ?></option>
							<option value="lower"><?php esc_html_e( 'lower case', 'd5-design-system-helper' ); ?></option>
							<option value="snake"><?php esc_html_e( 'snake_case', 'd5-design-system-helper' ); ?></option>
							<option value="camel"><?php esc_html_e( 'camelCase', 'd5-design-system-helper' ); ?></option>
						</select>
					</span>
					<span id="d5dsh-bulk-actions" style="display:none">
						<button type="button" id="d5dsh-bulk-preview" class="button d5dsh-bulk-action-btn" title="<?php esc_attr_e( 'Preview the bulk label change', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Preview', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-bulk-apply"   class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Apply the previewed changes', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Apply', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-bulk-undo"    class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Undo the preview and revert labels', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Undo', 'd5-design-system-helper' ); ?></button>
					</span>
				</div><!-- #d5dsh-bulk-controls -->
			</div><!-- .d5dsh-manage-bulk -->

			<div class="d5dsh-manage-filter" id="d5dsh-manage-filter" style="display:none">
				<button type="button" id="d5dsh-clear-all-filters" class="button button-small" style="display:none"><?php esc_html_e( 'Clear all filters', 'd5-design-system-helper' ); ?></button>
				<button type="button" id="d5dsh-reset-view" class="button button-small" style="margin-left:4px" title="<?php esc_attr_e( 'Reset all filters, sort, type and hide-system to defaults', 'd5-design-system-helper' ); ?>"><?php esc_html_e( '↺ Clear Filters', 'd5-design-system-helper' ); ?></button>
				<span id="d5dsh-manage-dupe-count" class="d5dsh-manage-dupe-count" style="display:none"></span>
				<span class="d5dsh-manage-filter-right">
					<button type="button" id="d5dsh-manage-print" class="button button-small"><?php esc_html_e( '⎙ Print', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-manage-export-xlsx" class="button button-small"><?php esc_html_e( '⬇ Excel', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-manage-export-csv" class="button button-small"><?php esc_html_e( '⬇ CSV', 'd5-design-system-helper' ); ?></button>
				</span>
			</div>

			<?php /* ── Save bar — above table so changes are immediately visible ── */ ?>
			<div class="d5dsh-manage-save-bar" id="d5dsh-manage-save-bar" style="display:none">
				<span id="d5dsh-manage-dirty-count" class="d5dsh-manage-dirty-count"></span>
				<div class="d5dsh-manage-save-actions">
					<button type="button" id="d5dsh-manage-discard" class="button"><?php esc_html_e( 'Discard Changes', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-manage-save" class="button button-primary d5dsh-btn-manage-save"><?php esc_html_e( 'Save Changes', 'd5-design-system-helper' ); ?></button>
				</div>
			</div>

		<?php /* ── Merge mode panel — above table so it's immediately visible ── */ ?>
		<div id="d5dsh-manage-mode-merge" style="display:none" class="d5dsh-merge-panel">
			<h3><?php esc_html_e( 'Merge Variables', 'd5-design-system-helper' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Select two variables: one to keep and one to retire. All preset references to the retired variable will be updated to point to the kept variable. The retired variable will be archived.', 'd5-design-system-helper' ); ?></p>
			<div class="d5dsh-merge-cards">
				<div class="d5dsh-merge-card" id="d5dsh-merge-keep-card">
					<div class="d5dsh-merge-card-title"><?php esc_html_e( 'Keep', 'd5-design-system-helper' ); ?></div>
					<input type="text" id="d5dsh-merge-keep-search" class="regular-text d5dsh-merge-search" placeholder="<?php esc_attr_e( 'Search variable…', 'd5-design-system-helper' ); ?>">
					<div id="d5dsh-merge-keep-display" class="d5dsh-merge-var-display"></div>
				</div>
				<div class="d5dsh-merge-swap-col">
					<button type="button" class="button d5dsh-merge-swap-btn" id="d5dsh-merge-swap-btn" title="<?php esc_attr_e( 'Swap keep and retire', 'd5-design-system-helper' ); ?>">&#8644;</button>
				</div>
				<div class="d5dsh-merge-card" id="d5dsh-merge-retire-card">
					<div class="d5dsh-merge-card-title"><?php esc_html_e( 'Retire', 'd5-design-system-helper' ); ?></div>
					<input type="text" id="d5dsh-merge-retire-search" class="regular-text d5dsh-merge-search" placeholder="<?php esc_attr_e( 'Search variable…', 'd5-design-system-helper' ); ?>">
					<div id="d5dsh-merge-retire-display" class="d5dsh-merge-var-display"></div>
				</div>
			</div><!-- .d5dsh-merge-cards -->
			<div id="d5dsh-merge-impact" class="d5dsh-merge-impact-area" style="display:none">
				<h4><?php esc_html_e( 'Impact Preview', 'd5-design-system-helper' ); ?></h4>
				<div id="d5dsh-merge-impact-body"></div>
			</div>
			<div class="d5dsh-merge-actions">
				<button type="button" class="button button-primary" id="d5dsh-merge-confirm-btn" disabled><?php esc_html_e( 'Merge — Update Presets', 'd5-design-system-helper' ); ?></button>
				<span id="d5dsh-merge-status" class="d5dsh-save-status"></span>
			</div>
		</div><!-- #d5dsh-manage-mode-merge -->

			<div class="d5dsh-table-outer">
				<div id="d5dsh-manage-loading" class="d5dsh-manage-loading"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></div>
				<div id="d5dsh-manage-error" class="d5dsh-manage-error" style="display:none"></div>
				<div id="d5dsh-vars-tabulator"></div>
			</div><!-- .d5dsh-table-outer -->

		</div><!-- #d5dsh-section-variables -->

		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: Group Presets
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-group_presets" class="d5dsh-manage-section" style="display:none">

			<h2><?php esc_html_e( 'Manage Group Presets', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Use the column filters in the table below to view and sort your Group Presets. Some columns truncate long values — hover over grey text to see the full value, or click it to copy it to the clipboard. The Notes column (second from left) lets you attach a note, tags, and suppression rules to any row — click the note icon to open the editor. Notes persist in the database and appear in Excel exports.', 'd5-design-system-helper' ); ?></p>

			<div class="d5dsh-manage-bulk" id="d5dsh-presets-gp-manage-bulk">
				<div class="d5dsh-mode-switcher" id="d5dsh-presets-gp-mode-switcher">
					<button type="button" class="d5dsh-mode-btn d5dsh-presets-mode-btn d5dsh-mode-active" data-section="gp" data-mode="view"><?php esc_html_e( 'View', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-mode-btn d5dsh-presets-mode-btn d5dsh-beta-feature" data-section="gp" data-mode="manage"><?php esc_html_e( 'Bulk Label Change', 'd5-design-system-helper' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'audit' ], admin_url( 'admin.php' ) ) . '#d5dsh-analysis-scan' ); ?>"
				   class="d5dsh-mode-btn d5dsh-beta-feature"><?php esc_html_e( 'Scan', 'd5-design-system-helper' ); ?></a>
				</div>
				<div id="d5dsh-presets-gp-bulk-controls" class="d5dsh-presets-bulk-controls" style="display:none">
					<select id="d5dsh-presets-gp-bulk-op" class="d5dsh-bulk-select">
						<option value=""><?php esc_html_e( '— type of change —', 'd5-design-system-helper' ); ?></option>
						<option value="prefix"><?php esc_html_e( 'Add Prefix', 'd5-design-system-helper' ); ?></option>
						<option value="suffix"><?php esc_html_e( 'Add Suffix', 'd5-design-system-helper' ); ?></option>
						<option value="find_replace"><?php esc_html_e( 'Find &amp; Replace', 'd5-design-system-helper' ); ?></option>
						<option value="normalize"><?php esc_html_e( 'Change Label Case', 'd5-design-system-helper' ); ?></option>
					</select>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-gp-fields-prefix" style="display:none">
						<input type="text" id="d5dsh-presets-gp-bulk-prefix-value" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Prefix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-gp-fields-suffix" style="display:none">
						<input type="text" id="d5dsh-presets-gp-bulk-suffix-value" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Suffix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-gp-fields-find_replace" style="display:none">
						<input type="text" id="d5dsh-presets-gp-bulk-find"    class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Find', 'd5-design-system-helper' ); ?>">
						<input type="text" id="d5dsh-presets-gp-bulk-replace" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Replace with', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-gp-fields-normalize" style="display:none">
						<select id="d5dsh-presets-gp-bulk-case" class="d5dsh-bulk-select">
							<option value="title"><?php esc_html_e( 'Title Case', 'd5-design-system-helper' ); ?></option>
							<option value="upper"><?php esc_html_e( 'UPPER CASE', 'd5-design-system-helper' ); ?></option>
							<option value="lower"><?php esc_html_e( 'lower case', 'd5-design-system-helper' ); ?></option>
							<option value="snake"><?php esc_html_e( 'snake_case', 'd5-design-system-helper' ); ?></option>
							<option value="camel"><?php esc_html_e( 'camelCase', 'd5-design-system-helper' ); ?></option>
						</select>
					</span>
					<span id="d5dsh-presets-gp-bulk-actions" style="display:none">
						<button type="button" id="d5dsh-presets-gp-bulk-preview" class="button d5dsh-bulk-action-btn"><?php esc_html_e( 'Preview', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-presets-gp-bulk-apply"   class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Apply the previewed changes', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Apply', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-presets-gp-bulk-undo"    class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Undo the preview and revert labels', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Undo', 'd5-design-system-helper' ); ?></button>
					</span>
				</div>
			</div><!-- .d5dsh-manage-bulk -->

			<div id="d5dsh-presets-gp-filter-bar" class="d5dsh-manage-filter" style="display:none">
				<button type="button" id="d5dsh-presets-gp-clear-filters" class="button button-small" style="display:none"><?php esc_html_e( 'Clear all filters', 'd5-design-system-helper' ); ?></button>
				<button type="button" id="d5dsh-presets-gp-reset-view"    class="button button-small" style="margin-left:4px"><?php esc_html_e( '↺ Clear Filters', 'd5-design-system-helper' ); ?></button>
				<span class="d5dsh-manage-filter-right">
					<button type="button" id="d5dsh-presets-gp-export-csv" class="button button-small"><?php esc_html_e( '⬇ CSV', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-gp-export-xlsx" class="button button-small"><?php esc_html_e( '⬇ Excel', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-gp-print"      class="button button-small"><?php esc_html_e( '⎙ Print', 'd5-design-system-helper' ); ?></button>
				</span>
			</div>

			<?php /* ── GP save bar — above table ── */ ?>
			<div class="d5dsh-manage-save-bar" id="d5dsh-presets-gp-save-bar" style="display:none">
				<span id="d5dsh-presets-gp-dirty-count" class="d5dsh-manage-dirty-count"></span>
				<div class="d5dsh-manage-save-actions">
					<button type="button" id="d5dsh-presets-gp-discard" class="button"><?php esc_html_e( 'Discard Changes', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-gp-save"    class="button button-primary d5dsh-btn-manage-save"><?php esc_html_e( 'Save Changes', 'd5-design-system-helper' ); ?></button>
				</div>
			</div>

			<div id="d5dsh-presets-gp-loading" class="d5dsh-manage-loading" style="display:none"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></div>
			<div id="d5dsh-presets-gp-error"   class="d5dsh-manage-error"   style="display:none"></div>

			<div id="d5dsh-presets-gp-tabulator"></div>

		</div><!-- #d5dsh-section-group_presets -->

		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: Element Presets
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-element_presets" class="d5dsh-manage-section" style="display:none">

			<h2><?php esc_html_e( 'Manage Element Presets', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Use the column filters in the table below to view and sort your Element Presets. Some columns truncate long values — hover over grey text to see the full value, or click it to copy it to the clipboard. The Notes column (second from left) lets you attach a note, tags, and suppression rules to any row — click the note icon to open the editor. Notes persist in the database and appear in Excel exports.', 'd5-design-system-helper' ); ?></p>

			<div class="d5dsh-manage-bulk" id="d5dsh-presets-ep-manage-bulk">
				<div class="d5dsh-mode-switcher" id="d5dsh-presets-ep-mode-switcher">
					<button type="button" class="d5dsh-mode-btn d5dsh-presets-mode-btn d5dsh-mode-active" data-section="ep" data-mode="view"><?php esc_html_e( 'View', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-mode-btn d5dsh-presets-mode-btn d5dsh-beta-feature" data-section="ep" data-mode="manage"><?php esc_html_e( 'Bulk Label Change', 'd5-design-system-helper' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'audit' ], admin_url( 'admin.php' ) ) . '#d5dsh-analysis-scan' ); ?>"
				   class="d5dsh-mode-btn d5dsh-beta-feature"><?php esc_html_e( 'Scan', 'd5-design-system-helper' ); ?></a>
				</div>
				<div id="d5dsh-presets-ep-bulk-controls" class="d5dsh-presets-bulk-controls" style="display:none">
					<select id="d5dsh-presets-ep-bulk-op" class="d5dsh-bulk-select">
						<option value=""><?php esc_html_e( '— type of change —', 'd5-design-system-helper' ); ?></option>
						<option value="prefix"><?php esc_html_e( 'Add Prefix', 'd5-design-system-helper' ); ?></option>
						<option value="suffix"><?php esc_html_e( 'Add Suffix', 'd5-design-system-helper' ); ?></option>
						<option value="find_replace"><?php esc_html_e( 'Find &amp; Replace', 'd5-design-system-helper' ); ?></option>
						<option value="normalize"><?php esc_html_e( 'Change Label Case', 'd5-design-system-helper' ); ?></option>
					</select>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-ep-fields-prefix" style="display:none">
						<input type="text" id="d5dsh-presets-ep-bulk-prefix-value" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Prefix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-ep-fields-suffix" style="display:none">
						<input type="text" id="d5dsh-presets-ep-bulk-suffix-value" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Suffix text', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-ep-fields-find_replace" style="display:none">
						<input type="text" id="d5dsh-presets-ep-bulk-find"    class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Find', 'd5-design-system-helper' ); ?>">
						<input type="text" id="d5dsh-presets-ep-bulk-replace" class="regular-text d5dsh-bulk-text" placeholder="<?php esc_attr_e( 'Replace with', 'd5-design-system-helper' ); ?>">
					</span>
					<span class="d5dsh-bulk-op-fields" id="d5dsh-presets-ep-fields-normalize" style="display:none">
						<select id="d5dsh-presets-ep-bulk-case" class="d5dsh-bulk-select">
							<option value="title"><?php esc_html_e( 'Title Case', 'd5-design-system-helper' ); ?></option>
							<option value="upper"><?php esc_html_e( 'UPPER CASE', 'd5-design-system-helper' ); ?></option>
							<option value="lower"><?php esc_html_e( 'lower case', 'd5-design-system-helper' ); ?></option>
							<option value="snake"><?php esc_html_e( 'snake_case', 'd5-design-system-helper' ); ?></option>
							<option value="camel"><?php esc_html_e( 'camelCase', 'd5-design-system-helper' ); ?></option>
						</select>
					</span>
					<span id="d5dsh-presets-ep-bulk-actions" style="display:none">
						<button type="button" id="d5dsh-presets-ep-bulk-preview" class="button d5dsh-bulk-action-btn"><?php esc_html_e( 'Preview', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-presets-ep-bulk-apply"   class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Apply the previewed changes', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Apply', 'd5-design-system-helper' ); ?></button>
						<button type="button" id="d5dsh-presets-ep-bulk-undo"    class="button d5dsh-bulk-action-btn" disabled title="<?php esc_attr_e( 'Undo the preview and revert labels', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Undo', 'd5-design-system-helper' ); ?></button>
					</span>
				</div>
			</div><!-- .d5dsh-manage-bulk -->

			<div id="d5dsh-presets-ep-filter-bar" class="d5dsh-manage-filter" style="display:none">
				<button type="button" id="d5dsh-presets-ep-clear-filters" class="button button-small" style="display:none"><?php esc_html_e( 'Clear all filters', 'd5-design-system-helper' ); ?></button>
				<button type="button" id="d5dsh-presets-ep-reset-view"    class="button button-small" style="margin-left:4px"><?php esc_html_e( '↺ Clear Filters', 'd5-design-system-helper' ); ?></button>
				<span class="d5dsh-manage-filter-right">
					<button type="button" id="d5dsh-presets-ep-export-csv" class="button button-small"><?php esc_html_e( '⬇ CSV', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-ep-export-xlsx" class="button button-small"><?php esc_html_e( '⬇ Excel', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-ep-print"      class="button button-small"><?php esc_html_e( '⎙ Print', 'd5-design-system-helper' ); ?></button>
				</span>
			</div>

			<?php /* ── EP save bar — above table ── */ ?>
			<div class="d5dsh-manage-save-bar" id="d5dsh-presets-ep-save-bar" style="display:none">
				<span id="d5dsh-presets-ep-dirty-count" class="d5dsh-manage-dirty-count"></span>
				<div class="d5dsh-manage-save-actions">
					<button type="button" id="d5dsh-presets-ep-discard" class="button"><?php esc_html_e( 'Discard Changes', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-ep-save"    class="button button-primary d5dsh-btn-manage-save"><?php esc_html_e( 'Save Changes', 'd5-design-system-helper' ); ?></button>
				</div>
			</div>

			<div id="d5dsh-presets-ep-loading" class="d5dsh-manage-loading" style="display:none"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></div>
			<div id="d5dsh-presets-ep-error"   class="d5dsh-manage-error"   style="display:none"></div>

			<div id="d5dsh-presets-ep-tabulator"></div>

		</div><!-- #d5dsh-section-element_presets -->

		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: All Presets (merged view)
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-all_presets" class="d5dsh-manage-section" style="display:none">

			<h2><?php esc_html_e( 'Manage All Presets', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Use the column filters below to view all presets by type. Some columns truncate long values — hover over grey text to see the full value, or click it to copy it to the clipboard. The Notes column (second from left) lets you attach a note, tags, and suppression rules to any row — click the note icon to open the editor. Notes persist in the database and appear in Excel exports.', 'd5-design-system-helper' ); ?></p>

			<div class="d5dsh-manage-bulk" id="d5dsh-presets-all-manage-bulk">
				<div class="d5dsh-mode-switcher" id="d5dsh-presets-all-mode-switcher">
					<button type="button" class="d5dsh-mode-btn d5dsh-mode-active" data-mode="view"><?php esc_html_e( 'View', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="d5dsh-mode-btn d5dsh-beta-feature" data-mode="manage" disabled title="<?php esc_attr_e( 'Use Group Presets or Element Presets section to bulk edit', 'd5-design-system-helper' ); ?>"><?php esc_html_e( 'Bulk Label Change', 'd5-design-system-helper' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'audit' ], admin_url( 'admin.php' ) ) . '#d5dsh-analysis-scan' ); ?>"
				   class="d5dsh-mode-btn d5dsh-beta-feature"><?php esc_html_e( 'Scan', 'd5-design-system-helper' ); ?></a>
				</div>
			</div>

			<div id="d5dsh-presets-all-filter-bar" class="d5dsh-manage-filter" style="display:none">
				<button type="button" id="d5dsh-presets-all-clear-filters" class="button button-small" style="display:none"><?php esc_html_e( 'Clear all filters', 'd5-design-system-helper' ); ?></button>
				<button type="button" id="d5dsh-presets-all-reset-view"    class="button button-small" style="margin-left:4px"><?php esc_html_e( '↺ Clear Filters', 'd5-design-system-helper' ); ?></button>
				<span class="d5dsh-manage-filter-right">
					<button type="button" id="d5dsh-presets-all-export-csv" class="button button-small"><?php esc_html_e( '⬇ CSV', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-all-export-xlsx" class="button button-small"><?php esc_html_e( '⬇ Excel', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-presets-all-print"      class="button button-small"><?php esc_html_e( '⎙ Print', 'd5-design-system-helper' ); ?></button>
				</span>
			</div>

			<div id="d5dsh-presets-all-loading" class="d5dsh-manage-loading" style="display:none"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></div>
			<div id="d5dsh-presets-all-error"   class="d5dsh-manage-error"   style="display:none"></div>

			<div id="d5dsh-presets-all-tabulator"></div>

		</div><!-- #d5dsh-section-all_presets -->

		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: Everything (all DSOs — vars + presets combined)
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-everything" class="d5dsh-manage-section" style="display:none">

			<h2><?php esc_html_e( 'Everything', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'All Design System Objects in one view — Variables and Presets together. Use the column filters to search and sort across the full design system. Some columns truncate long values — hover over grey text to see the full value, or click it to copy it to the clipboard. The Notes column (second from left) lets you attach a note, tags, and suppression rules to any row — click the note icon to open the editor. Notes persist in the database and appear in Excel exports.', 'd5-design-system-helper' ); ?></p>

			<div class="d5dsh-manage-bulk" id="d5dsh-everything-manage-bulk">
				<div class="d5dsh-mode-switcher" id="d5dsh-everything-mode-switcher">
					<button type="button" class="d5dsh-mode-btn d5dsh-mode-active" data-mode="view"><?php esc_html_e( 'View', 'd5-design-system-helper' ); ?></button>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE_SLUG, 'tab' => 'audit' ], admin_url( 'admin.php' ) ) . '#d5dsh-analysis-scan' ); ?>"
				   class="d5dsh-mode-btn d5dsh-beta-feature"><?php esc_html_e( 'Scan', 'd5-design-system-helper' ); ?></a>
				</div>
			</div>

			<div id="d5dsh-everything-filter-bar" class="d5dsh-manage-filter" style="display:none">
				<button type="button" id="d5dsh-everything-clear-filters" class="button button-small" style="display:none"><?php esc_html_e( 'Clear all filters', 'd5-design-system-helper' ); ?></button>
				<button type="button" id="d5dsh-everything-reset-view"    class="button button-small" style="margin-left:4px"><?php esc_html_e( '↺ Clear Filters', 'd5-design-system-helper' ); ?></button>
				<span class="d5dsh-manage-filter-right">
					<button type="button" id="d5dsh-everything-export-csv" class="button button-small"><?php esc_html_e( '⬇ CSV', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-everything-export-xlsx" class="button button-small"><?php esc_html_e( '⬇ Excel', 'd5-design-system-helper' ); ?></button>
					<button type="button" id="d5dsh-everything-print"      class="button button-small"><?php esc_html_e( '⎙ Print', 'd5-design-system-helper' ); ?></button>
				</span>
			</div>

			<div id="d5dsh-everything-loading" class="d5dsh-manage-loading" style="display:none"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></div>
			<div id="d5dsh-everything-error"   class="d5dsh-manage-error"   style="display:none"></div>

			<div id="d5dsh-everything-tabulator"></div>

		</div><!-- #d5dsh-section-everything -->
		<?php /* ════════════════════════════════════════════════════════════
		       ── SECTION: Categories
		       ════════════════════════════════════════════════════════════ */ ?>
		<div id="d5dsh-section-categories" class="d5dsh-manage-section" style="display:none">
			<div class="d5dsh-section-inner">
				<h2><?php esc_html_e( 'Categories', 'd5-design-system-helper' ); ?></h2>
				<p><?php esc_html_e( 'Define categories, then assign variables and presets to one or more. Use the column filters in the assignment table to find rows quickly. Use categories to filter and group your design system.', 'd5-design-system-helper' ); ?></p>

				<?php /* ── Add / Edit category toolbar ─────────────────────── */ ?>
				<div class="d5dsh-cat-toolbar" id="d5dsh-cat-toolbar">
					<input type="text" id="d5dsh-cat-name-input" class="regular-text" placeholder="<?php esc_attr_e( 'Category name…', 'd5-design-system-helper' ); ?>" maxlength="80">
					<input type="color" id="d5dsh-cat-color-input" value="#6b7280" title="<?php esc_attr_e( 'Category color', 'd5-design-system-helper' ); ?>">
					<button type="button" class="button button-primary" id="d5dsh-cat-add-btn"><?php esc_html_e( 'Add Category', 'd5-design-system-helper' ); ?></button>
				</div>

				<?php /* ── Category list ───────────────────────────────────── */ ?>
				<p id="d5dsh-cat-loading" class="d5dsh-loading"><?php esc_html_e( 'Loading…', 'd5-design-system-helper' ); ?></p>
				<div id="d5dsh-cat-list-wrap" class="d5dsh-cat-list-scroll-wrap" style="display:none">
					<table class="wp-list-table widefat fixed d5dsh-manage-table" id="d5dsh-cat-list-table">
						<thead><tr>
							<th class="d5dsh-col-cat-list-color" data-w="48" data-max="60"><?php esc_html_e( 'Color', 'd5-design-system-helper' ); ?></th>
							<th class="d5dsh-col-cat-list-name" data-w="160" data-max="240" data-filter-col="cat_list_name"><?php esc_html_e( 'Name', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span> <span class="d5dsh-col-expand-toggle" title="Expand/contract column">&#8596;</span></th>
							<th class="d5dsh-col-cat-list-dsos" data-w="70" data-max="90" data-filter-col="cat_list_dsos"><?php esc_html_e( '# DSOs', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-cat-list-comment" data-w="200" data-max="500"><?php esc_html_e( 'Comments', 'd5-design-system-helper' ); ?></th>
							<th class="d5dsh-col-cat-list-actions" data-w="80" data-max="100"><?php esc_html_e( 'Actions', 'd5-design-system-helper' ); ?></th>
						</tr></thead>
						<tbody id="d5dsh-cat-list-tbody"></tbody>
					</table>
				</div>

				<?php /* ── Assignment table toolbar ──────────────────────── */ ?>
				<h4 id="d5dsh-cat-assign-title" style="margin-top:24px"><?php esc_html_e( 'Assign Variables &amp; Presets', 'd5-design-system-helper' ); ?></h4>
				<div class="d5dsh-cat-assign-toolbar">
					<button type="button" class="button" id="d5dsh-cat-clear-filters-btn"><?php esc_html_e( 'Clear Filters', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button" id="d5dsh-cat-discard-btn"><?php esc_html_e( 'Discard Changes', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button button-primary" id="d5dsh-cat-save-assignments-btn"><?php esc_html_e( 'Save Assignments', 'd5-design-system-helper' ); ?></button>
					<button type="button" class="button" id="d5dsh-cat-undo-btn" style="display:none"><?php esc_html_e( 'Undo Last Save', 'd5-design-system-helper' ); ?></button>
					<span id="d5dsh-cat-save-status" class="d5dsh-save-status"></span>
				</div>
				<div class="d5dsh-table-scroll-wrap" id="d5dsh-cat-assign-wrap">
					<table class="wp-list-table widefat fixed d5dsh-manage-table d5dsh-presets-table" id="d5dsh-cat-assign-table">
						<thead><tr>
							<th class="d5dsh-col-order" data-w="30" data-max="40"><?php esc_html_e( '#', 'd5-design-system-helper' ); ?></th>
							<th class="d5dsh-col-dso-type" data-w="110" data-max="160" data-filter-col="cat_dso_type"><?php esc_html_e( 'DSO Type', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-type" data-w="120" data-max="180" data-filter-col="cat_type"><?php esc_html_e( 'Type', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-id" data-w="140" data-max="240" data-filter-col="cat_id"><?php esc_html_e( 'ID', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-label" data-w="160" data-max="300" data-filter-col="cat_label"><?php esc_html_e( 'Label', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-cat-category" data-w="120" data-max="180" data-filter-col="cat_category"><?php esc_html_e( 'Categories', 'd5-design-system-helper' ); ?> <span class="d5dsh-filter-icon">&#9660;</span></th>
							<th class="d5dsh-col-cat-color" data-w="180" data-max="240" style="white-space:nowrap"><?php esc_html_e( 'Category Colors', 'd5-design-system-helper' ); ?> <span class="d5dsh-col-expand-toggle" title="Expand/contract column">&#8596;</span></th>
						<tbody id="d5dsh-cat-assign-tbody"></tbody>
					</table>
				</div>
			</div>
		</div><!-- #d5dsh-section-categories -->

		</div><!-- .d5dsh-panel -->
		<?php
	}

	// ── Snapshots tab ─────────────────────────────────────────────────────────

	/**
	 * Render the Snapshots tab.
	 */
	private function render_snapshots_tab(): void {
		$all_types = SnapshotManager::types_with_snapshots();
		?>
		<div class="d5dsh-panel">
			<h2><?php esc_html_e( 'Snapshots', 'd5-design-system-helper' ); ?></h2>
			<p><?php esc_html_e( 'Snapshots are saved automatically before every export and import. Up to 10 per type are kept.', 'd5-design-system-helper' ); ?></p>

			<?php if ( empty( $all_types ) ) : ?>
				<p><em><?php esc_html_e( 'No snapshots found. They are created when you export or import data.', 'd5-design-system-helper' ); ?></em></p>
			<?php else : ?>

				<?php foreach ( $all_types as $type ) :
					$meta  = SnapshotManager::list_snapshots( $type );
					$label = self::TYPE_LABELS[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
					if ( empty( $meta ) ) { continue; }

					// Find the most recent import snapshot for the Undo button.
					$latest_import = null;
					foreach ( $meta as $snap ) {
						if ( ( $snap['trigger'] ?? '' ) === 'import' ) {
							$latest_import = $snap;
							break;
						}
					}
				?>
				<h3><?php echo esc_html( $label ); ?></h3>

				<?php if ( $latest_import !== null ) : ?>
				<form method="post" action="" class="d5dsh-undo-form">
					<?php wp_nonce_field( self::NONCE_SNAPSHOT ); ?>
					<input type="hidden" name="d5dsh_action"     value="undo_import">
					<input type="hidden" name="d5dsh_snap_type"  value="<?php echo esc_attr( $type ); ?>">
					<input type="hidden" name="d5dsh_snap_index" value="<?php echo esc_attr( (string) $latest_import['index'] ); ?>">
					<button type="submit" class="button d5dsh-btn-undo">
						<?php esc_html_e( '↩ Undo Last Import', 'd5-design-system-helper' ); ?>
					</button>
					<span class="description">
						<?php echo esc_html( sprintf(
							/* translators: %1$s: description, %2$s: timestamp */
							__( 'Restores: %1$s (%2$s)', 'd5-design-system-helper' ),
							$latest_import['description'] ?? '',
							$latest_import['timestamp']   ?? ''
						) ); ?>
					</span>
				</form>
				<?php endif; ?>

				<table class="wp-list-table widefat fixed striped d5dsh-snap-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Timestamp', 'd5-design-system-helper' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'd5-design-system-helper' ); ?></th>
							<th><?php esc_html_e( 'Description', 'd5-design-system-helper' ); ?></th>
							<th><?php esc_html_e( 'Entries', 'd5-design-system-helper' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'd5-design-system-helper' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $meta as $snap ) : ?>
						<tr>
							<td><?php echo esc_html( $snap['timestamp'] ?? '' ); ?></td>
							<td>
								<span class="d5dsh-trigger-badge d5dsh-trigger-<?php echo esc_attr( $snap['trigger'] ?? 'unknown' ); ?>">
									<?php echo esc_html( ucfirst( $snap['trigger'] ?? '' ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $snap['description'] ?? '' ); ?></td>
							<td><?php echo esc_html( (string) ( $snap['entry_count'] ?? '' ) ); ?></td>
							<td class="d5dsh-snap-actions">
								<form method="post" action="" style="display:inline">
									<?php wp_nonce_field( self::NONCE_SNAPSHOT ); ?>
									<input type="hidden" name="d5dsh_action"     value="restore_snapshot">
									<input type="hidden" name="d5dsh_snap_type"  value="<?php echo esc_attr( $type ); ?>">
									<input type="hidden" name="d5dsh_snap_index" value="<?php echo esc_attr( (string) $snap['index'] ); ?>">
									<button type="submit" class="button button-small"
									        onclick="return confirm('<?php esc_attr_e( 'Restore this snapshot? The current data will be overwritten.', 'd5-design-system-helper' ); ?>')">
										<?php esc_html_e( 'Restore', 'd5-design-system-helper' ); ?>
									</button>
								</form>
								<form method="post" action="" style="display:inline">
									<?php wp_nonce_field( self::NONCE_SNAPSHOT ); ?>
									<input type="hidden" name="d5dsh_action"     value="delete_snapshot">
									<input type="hidden" name="d5dsh_snap_type"  value="<?php echo esc_attr( $type ); ?>">
									<input type="hidden" name="d5dsh_snap_index" value="<?php echo esc_attr( (string) $snap['index'] ); ?>">
									<button type="submit" class="button button-small d5dsh-btn-delete"
									        onclick="return confirm('<?php esc_attr_e( 'Delete this snapshot? This cannot be undone.', 'd5-design-system-helper' ); ?>')">
										<?php esc_html_e( 'Delete', 'd5-design-system-helper' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endforeach; ?>

			<?php endif; ?>
		</div>
		<?php
	}



	// ── Style Guide tab ───────────────────────────────────────────────────────

	/**
	 * Render the Style Guide tab.
	 *
	 * @return void
	 */
	private function render_styleguide_tab(): void {
		?>
		<div class="d5dsh-panel d5dsh-styleguide-panel" id="d5dsh-styleguide-panel">
			<div class="d5dsh-styleguide-header">
				<h2><?php esc_html_e( 'Style Guide', 'd5-design-system-helper' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Generate a visual reference of your design system variables. Preview live in-browser, then download as HTML or print to PDF.', 'd5-design-system-helper' ); ?></p>

				<?php /* ── Controls ──────────────────────────────────────── */ ?>
				<div class="d5dsh-styleguide-controls">
					<button type="button" class="button button-primary" id="d5dsh-sg-generate-btn">
						<?php esc_html_e( 'Generate Style Guide', 'd5-design-system-helper' ); ?>
					</button>
					<label class="d5dsh-sg-option">
						<input type="checkbox" id="d5dsh-sg-show-system" checked>
						<?php esc_html_e( 'Include system variables', 'd5-design-system-helper' ); ?>
					</label>
					<label class="d5dsh-sg-option">
						<input type="checkbox" id="d5dsh-sg-group-cats">
						<?php esc_html_e( 'Group by category', 'd5-design-system-helper' ); ?>
					</label>
					<label class="d5dsh-sg-option">
						<input type="checkbox" id="d5dsh-sg-show-presets">
						<?php esc_html_e( 'Include presets', 'd5-design-system-helper' ); ?>
					</label>
				</div>
				<div class="d5dsh-styleguide-export-bar" id="d5dsh-sg-export-bar" style="display:none">
					<button type="button" class="button" id="d5dsh-sg-download-btn">
						<?php esc_html_e( 'Download HTML', 'd5-design-system-helper' ); ?>
					</button>
					<button type="button" class="button" id="d5dsh-sg-print-btn">
						<?php esc_html_e( 'Print / Save PDF', 'd5-design-system-helper' ); ?>
					</button>
				</div>
			</div>

			<?php /* ── Live preview area ─────────────────────────────── */ ?>
			<div id="d5dsh-styleguide-preview" class="d5dsh-styleguide-preview">
				<p class="d5dsh-sg-placeholder"><?php esc_html_e( 'Click Generate Style Guide to build your visual reference.', 'd5-design-system-helper' ); ?></p>
			</div>
		</div><!-- .d5dsh-styleguide-panel -->
		<?php
	}

	/**
	 * Render the Audit tab.
	 *
	 * @return void
	 */
	private function render_audit_tab(): void {
		?>
		<div class="d5dsh-audit-tab">

			<?php /* ── SECTION SWITCHER ──────────────────────────────────────── */ ?>
			<div class="d5dsh-section-switcher">
				<button type="button" class="d5dsh-section-btn d5dsh-section-active d5dsh-analysis-btn" data-analysis-section="audit">
					<?php esc_html_e( 'Audit', 'd5-design-system-helper' ); ?>
				</button>
				<button type="button" class="d5dsh-section-btn d5dsh-analysis-btn" data-analysis-section="content_scan" id="d5dsh-analysis-scan">
					<?php esc_html_e( 'Content Scan', 'd5-design-system-helper' ); ?>
				</button>
			</div>

			<?php /* ── AUDIT SECTION ────────────────────────────────────────── */ ?>
			<div id="d5dsh-analysis-section-audit" class="d5dsh-analysis-section">
				<p class="d5dsh-section-description">
					<?php esc_html_e( 'The Divi 5 Design System has two tiers of objects: Variables (global design tokens such as colors, spacing, and typography values) and Presets (Element Presets and Group Presets that apply saved styles to modules). All of these objects are collectively referred to in this plugin as Design System Objects ("DSOs").', 'd5-design-system-helper' ); ?>
				</p>
				<p class="d5dsh-section-description">
					<strong><?php esc_html_e( 'Simple Audit', 'd5-design-system-helper' ); ?></strong>
					<?php esc_html_e( 'Checks all global variables and presets for consistency issues — no content scan required. You can configure which columns appear in the report and export by visiting Settings (gear icon, upper right).', 'd5-design-system-helper' ); ?>
				</p>
				<p class="d5dsh-section-description">
					<strong><?php esc_html_e( 'Contextual Audit', 'd5-design-system-helper' ); ?></strong>
					<?php esc_html_e( 'Runs the Simple Audit, then runs a Content Scan and uses the scan results to perform 8 additional content-aware checks: archived DSOs in published content, broken DSO references in content, orphaned presets, high-impact variables, variables bypassing the preset system, singleton presets, overlapping presets, and preset naming conventions. Results appear in both the Audit and Content Scan sections.', 'd5-design-system-helper' ); ?>
				</p>
				<div class="d5dsh-audit-toolbar">
					<button id="d5dsh-audit-run-btn" class="button button-primary">
						<?php esc_html_e( 'Simple Audit', 'd5-design-system-helper' ); ?>
					</button>
					<button id="d5dsh-audit-full-btn" class="button button-primary">
						<?php esc_html_e( 'Contextual Audit', 'd5-design-system-helper' ); ?>
					</button>
					<span id="d5dsh-audit-spinner" class="spinner" style="display:none;"></span>
					<div id="d5dsh-audit-actions" class="d5dsh-action-btns" style="display:none;">
						<button id="d5dsh-audit-reset-btn" class="button d5dsh-btn-clear">
							<?php esc_html_e( 'Reset', 'd5-design-system-helper' ); ?>
						</button>
					</div>
				</div>
				<div id="d5dsh-audit-error" class="d5dsh-audit-error-msg" style="display:none;"></div>

				<?php /* Pre-audit term definitions — hidden once an audit has run */ ?>
				<div id="d5dsh-audit-prescan" class="d5dsh-scan-prescan">
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Orphaned Variables', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Variables defined in Global Variables but not referenced by any preset or page content. These are candidates for cleanup — they may be leftover from a previous design system version.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Broken References', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Presets that reference a variable ID that no longer exists. The preset will silently fall back to its default value instead of using the intended variable.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Duplicate Labels', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Two or more DSOs (variables or presets) share the same label but have different values or types. This causes confusion in the Divi editor picker and makes the design system harder to maintain.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Naming Conflicts', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Two or more presets of the same module type share the same name, making them impossible to distinguish in the Divi editor dropdown.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Design Inefficiencies', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Patterns that reduce the value of your design system: singleton variables (used by only one preset), near-duplicate values that could be consolidated, and hardcoded color values that appear across many presets and should be extracted into global variables.', 'd5-design-system-helper' ); ?></p>
					</div>
				</div>

				<div class="d5dsh-results-container" id="d5dsh-audit-results-container" style="display:none;">
				<div class="d5dsh-results-export-bar">
					<button id="d5dsh-audit-print-btn2" class="button">
						<?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?>
					</button>
					<button id="d5dsh-audit-xlsx-btn2" class="button">
						<?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?>
					</button>
					<button id="d5dsh-audit-csv-btn2" class="button">
						<?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?>
					</button>
				</div>
				<div id="d5dsh-audit-report"></div>
			</div>
			</div>

			<?php /* ── CONTENT SCAN SECTION ──────────────────────────────────── */ ?>
			<div id="d5dsh-analysis-section-content_scan" class="d5dsh-analysis-section" style="display:none;">
				<p class="d5dsh-section-description">
					<?php esc_html_e( 'The Divi 5 Design System has two tiers of objects: Variables (global design tokens such as colors, spacing, and typography values) and Presets (Element Presets and Group Presets that apply saved styles to modules). All of these objects are collectively referred to in this plugin as Design System Objects ("DSOs").', 'd5-design-system-helper' ); ?>
				</p>
				<p class="d5dsh-section-description">
					<?php esc_html_e( 'Scans all pages, posts, Divi Library layouts, and Theme Builder templates (all statuses, up to 1,000 items) for DSO usage. Produces three reports: Active Content (items referencing at least one DSO), Content Inventory (all scanned items), and a DSO Usage Index.', 'd5-design-system-helper' ); ?>
				</p>
				<div class="d5dsh-audit-toolbar d5dsh-scan-toolbar">
					<button id="d5dsh-scan-run-btn" class="button button-primary">
						<?php esc_html_e( 'Scan Content', 'd5-design-system-helper' ); ?>
					</button>
					<span id="d5dsh-scan-spinner" class="spinner" style="display:none;"></span>
					<div id="d5dsh-scan-actions" class="d5dsh-action-btns" style="display:none;">
						<button id="d5dsh-scan-reset-btn" class="button d5dsh-btn-clear">
							<?php esc_html_e( 'Reset', 'd5-design-system-helper' ); ?>
						</button>
						<span class="d5dsh-scan-toolbar-right">
							<button id="d5dsh-scan-print-btn" class="button">
								<?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?>
							</button>
							<button id="d5dsh-scan-xlsx-btn" class="button">
								<?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?>
							</button>
							<button id="d5dsh-scan-csv-btn" class="button">
								<?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?>
							</button>
						</span>
					</div>
				</div>

				<div id="d5dsh-scan-error" class="d5dsh-audit-error-msg" style="display:none;"></div>

				<?php /* Pre-scan description — hidden once a scan has run */ ?>
				<div id="d5dsh-scan-prescan" class="d5dsh-scan-prescan">
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Active Content', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Pages, posts, Divi Library layouts, and Theme Builder templates that contain at least one DSO reference (variable or preset). Only items actively using your design system appear here.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Content Inventory', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'A complete list of all scanned content items — published, draft, private, and trashed — with their post type, status, and DSO usage count. Useful for a full picture of your site\'s content alongside its design system adoption.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'DSO Usage Index', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'A reverse index: for each variable or preset used on the site, lists every content item that references it. Useful for understanding the impact of changing or deleting a specific DSO.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'No-DSO Content', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Content items with no variable or preset references — pages, posts, layouts, and templates not using the design system. Useful for identifying content that could benefit from DSO adoption or Divi preset assignment.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'Content → DSO Map', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'A tree view of every scanned content item and the DSOs it uses — variables referenced directly in the content, and the presets assigned to elements within it, with the variables each preset contains. Useful for auditing which design tokens are active on a specific page or layout.', 'd5-design-system-helper' ); ?></p>
					</div>
					<div class="d5dsh-scan-prescan-item">
						<strong><?php esc_html_e( 'DSO → Usage Chain', 'd5-design-system-helper' ); ?></strong>
						<p><?php esc_html_e( 'Three linked sub-tables: which variables are used by which presets; which presets are used across which content items; and which variables appear in which presets. &ldquo;Direct usage&rdquo; means the variable token appears in the content itself, not inside a preset. Useful for tracing the full chain from a variable to every page it influences.', 'd5-design-system-helper' ); ?></p>
					</div>
				</div>

				<?php /* Scan results — hidden until a scan has run */ ?>
				<div id="d5dsh-scan-results" style="display:none;">
					<div class="d5dsh-results-container">
						<div id="d5dsh-scan-meta" class="d5dsh-scan-meta"></div>

						<details class="d5dsh-scan-section" open>
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'Pages, posts, Divi Library layouts, and Theme Builder templates that contain at least one DSO reference (variable or preset). Only items actively using your design system appear here.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'Active Content', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-active-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-active-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="active"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="active"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="active"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-active-body" class="d5dsh-scan-section-body"></div>
						</details>

						<details class="d5dsh-scan-section">
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'A complete list of all scanned content items — published, draft, private, and trashed — with their post type, status, and DSO usage count. Useful for a full picture of your site\'s content alongside its design system adoption.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'Content Inventory', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-inventory-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-inventory-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="inventory"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="inventory"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="inventory"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-inventory-body" class="d5dsh-scan-section-body"></div>
						</details>

						<details class="d5dsh-scan-section">
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'A reverse index: for each variable or preset used on the site, lists every content item that references it. Useful for understanding the impact of changing or deleting a specific DSO.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'DSO Usage Index', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-dso-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-dso-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="dso"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="dso"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="dso"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-dso-body" class="d5dsh-scan-section-body"></div>
						</details>

						<details class="d5dsh-scan-section">
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'Content items with no variable or preset references — not currently using the design system.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'No-DSO Content', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-nodso-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-nodso-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="nodso"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="nodso"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="nodso"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-nodso-body" class="d5dsh-scan-section-body"></div>
						</details>

						<details class="d5dsh-scan-section">
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'For each active content item: a flat table of every DSO reference and a collapsible tree showing Content → Presets → Variables.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'Content → DSO Map', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-dsomap-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-dsomap-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="dsomap"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="dsomap"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="dsomap"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-dsomap-body" class="d5dsh-scan-section-body"></div>
						</details>

						<details class="d5dsh-scan-section">
							<summary class="d5dsh-scan-section-title">
								<span title="<?php esc_attr_e( 'For each DSO: which content uses it directly, which preset definitions embed it, and a full Preset → Variables breakdown.', 'd5-design-system-helper' ); ?>">
									<?php esc_html_e( 'DSO → Usage Chain', 'd5-design-system-helper' ); ?>
								</span>
								<span id="d5dsh-scan-dsochain-badge" class="d5dsh-audit-badge"></span>
								<span class="d5dsh-section-export-bar" id="d5dsh-scan-dsochain-export-bar">
									<button type="button" class="button button-small d5dsh-scan-section-print-btn" data-section="dsochain"><?php esc_html_e( 'Print', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-xlsx-btn" data-section="dsochain"><?php esc_html_e( '&#8595; Excel', 'd5-design-system-helper' ); ?></button>
									<button type="button" class="button button-small d5dsh-scan-section-csv-btn"  data-section="dsochain"><?php esc_html_e( '&#8595; CSV', 'd5-design-system-helper' ); ?></button>
								</span>
							</summary>
							<div id="d5dsh-scan-dsochain-body" class="d5dsh-scan-section-body"></div>
						</details>

					</div>
				</div>
			</div>

		</div>
		<?php
	}

	// ── Partial renderers ─────────────────────────────────────────────────────

	/**
	 * Render success / error notices from the previous request.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		$notice = sanitize_key( $_GET['d5dsh_notice'] ?? '' );
		$error  = sanitize_key( $_GET['d5dsh_error']  ?? '' );
		$detail = sanitize_text_field( urldecode( $_GET['d5dsh_detail'] ?? '' ) );

		$notice_messages = [
			'dry_run_complete'   => __( 'Dry run complete. Review the changes below before committing.', 'd5-design-system-helper' ),
			'import_complete'    => __( 'Import complete. Your Divi 5 Design System data has been updated.', 'd5-design-system-helper' ),
			'snapshot_restored'  => __( 'Snapshot restored successfully.', 'd5-design-system-helper' ),
			'snapshot_deleted'   => __( 'Snapshot deleted.', 'd5-design-system-helper' ),
			'undo_complete'      => __( 'Last import undone successfully.', 'd5-design-system-helper' ),
		];
		$error_messages = [
			'export_failed'           => __( 'Export failed.', 'd5-design-system-helper' ),
			'import_failed'           => __( 'Import failed.', 'd5-design-system-helper' ),
			'no_file'                 => __( 'No file uploaded. Please select an .xlsx file.', 'd5-design-system-helper' ),
			'unknown_file_type'       => __( 'Could not detect file type. Ensure the file was exported by this plugin.', 'd5-design-system-helper' ),
			'no_types_selected'       => __( 'Please select at least one type to export.', 'd5-design-system-helper' ),
			'snapshot_restore_failed' => __( 'Snapshot restore failed. The snapshot data may be missing.', 'd5-design-system-helper' ),
			'undo_failed'             => __( 'No import snapshot found to undo.', 'd5-design-system-helper' ),
		];

		if ( $notice && isset( $notice_messages[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice_messages[ $notice ] ) . '</p></div>';
		}
		if ( $error ) {
			$msg = $error_messages[ $error ] ?? __( 'An unknown error occurred.', 'd5-design-system-helper' );
			if ( $detail ) {
				$msg .= ' ' . $detail;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/**
	 * Render the dry-run diff table from transient.
	 *
	 * @return void
	 */
	private function render_dry_run_results(): void {
		$user_id = get_current_user_id();
		$diff    = get_transient( 'd5dsh_dry_run_result_' . $user_id );
		if ( ! $diff ) {
			return;
		}
		delete_transient( 'd5dsh_dry_run_result_' . $user_id );

		$count = count( $diff['changes'] ?? [] );
		if ( $count === 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Dry run: no changes detected.', 'd5-design-system-helper' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf(
			_n( 'Dry run: %d change detected. Review below, then re-upload without Dry Run to commit.', 'Dry run: %d changes detected. Review below, then re-upload without Dry Run to commit.', $count, 'd5-design-system-helper' ),
			$count
		) ) . '</p></div>';

		echo '<table class="wp-list-table widefat fixed striped d5dsh-diff-table">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'ID', 'd5-design-system-helper' )           . '</th>'
			. '<th>' . esc_html__( 'Label', 'd5-design-system-helper' )         . '</th>'
			. '<th>' . esc_html__( 'Type', 'd5-design-system-helper' )          . '</th>'
			. '<th>' . esc_html__( 'Field', 'd5-design-system-helper' )         . '</th>'
			. '<th>' . esc_html__( 'Current Value', 'd5-design-system-helper' ) . '</th>'
			. '<th>' . esc_html__( 'New Value', 'd5-design-system-helper' )     . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $diff['changes'] as $c ) {
			$old = strlen( $c['old_value'] ?? '' ) > 80 ? substr( $c['old_value'], 0, 80 ) . '…' : ( $c['old_value'] ?? '' );
			$new = strlen( $c['new_value'] ?? '' ) > 80 ? substr( $c['new_value'], 0, 80 ) . '…' : ( $c['new_value'] ?? '' );
			echo '<tr>'
				. '<td><code>' . esc_html( $c['id']    ?? '' ) . '</code></td>'
				. '<td>'       . esc_html( $c['label'] ?? '' ) . '</td>'
				. '<td>'       . esc_html( $c['type']  ?? '' ) . '</td>'
				. '<td>'       . esc_html( $c['field'] ?? '' ) . '</td>'
				. '<td class="d5dsh-old-val">' . esc_html( $old ) . '</td>'
				. '<td class="d5dsh-new-val">' . esc_html( $new ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the import result summary from transient.
	 *
	 * @return void
	 */
	private function render_import_result(): void {
		$user_id = get_current_user_id();
		$result  = get_transient( 'd5dsh_import_result_' . $user_id );
		if ( ! $result ) {
			return;
		}
		delete_transient( 'd5dsh_import_result_' . $user_id );
		echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf(
			__( 'Import complete: %1$d updated, %2$d unchanged, %3$d new.', 'd5-design-system-helper' ),
			$result['updated'] ?? 0,
			$result['skipped'] ?? 0,
			$result['new']     ?? 0
		) ) . '</p></div>';
	}

	// ── Redirect helpers ──────────────────────────────────────────────────────

	/**
	 * Redirect to admin page with a success notice code.
	 *
	 * @param string $notice
	 * @param string $tab
	 * @return never
	 */
	private function redirect_with_notice( string $notice, string $tab = 'export' ): never {
		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'tab' => $tab, 'd5dsh_notice' => $notice ],
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Redirect to admin page with an error code.
	 *
	 * @param string $error
	 * @param string $detail
	 * @return never
	 */
	private function redirect_with_error( string $error, string $detail = '' ): never {
		$args = [ 'page' => self::PAGE_SLUG, 'd5dsh_error' => $error ];
		if ( $detail ) {
			$args['d5dsh_detail'] = rawurlencode( substr( $detail, 0, 200 ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
