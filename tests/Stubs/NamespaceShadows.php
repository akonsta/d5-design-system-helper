<?php
/**
 * Namespace-scoped function shadows for unit testing.
 *
 * PHP resolves unqualified function calls by checking the current namespace
 * first, then falling back to global scope.  By declaring functions in the
 * same namespaces used by the plugin classes we intercept specific calls
 * without touching global scope.
 *
 * LabelManager::ajax_save() calls file_get_contents('php://input') from
 * within the D5DesignSystemHelper\Admin namespace — this shadow is picked up
 * by PHP's namespace resolution before it reaches the built-in.
 *
 * This file MUST be a standalone file (no global code before the namespace
 * declaration) and is loaded by tests/bootstrap.php.
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Admin;

/**
 * Shadow of do_action — no-op in unit tests.
 * Prevents "Call to undefined function D5DesignSystemHelper\Admin\do_action()" errors
 * when Validator::validate() calls do_action('d5dsh_validator_after_checks', ...).
 */
function do_action( string $hook_name, mixed ...$args ): void {
	// No-op.
}

/**
 * Shadow of file_get_contents that intercepts 'php://input'.
 * All other paths fall through to the global (native) function.
 */
function file_get_contents( string $filename, bool $use_include_path = false, $context = null, int $offset = 0, ?int $length = null ): string|false {
	if ( $filename === 'php://input' ) {
		return $GLOBALS['_d5dsh_php_input'] ?? '';
	}
	return \file_get_contents( $filename, $use_include_path, $context, $offset, $length ?? PHP_INT_MAX );
}
