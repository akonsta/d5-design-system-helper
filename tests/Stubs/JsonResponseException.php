<?php
/**
 * Exception thrown by wp_send_json_success() / wp_send_json_error() stubs.
 *
 * WordPress's real implementations call die() after outputting JSON.
 * In test context we throw this exception instead so the test process
 * survives and can inspect the response.
 *
 * Usage in tests:
 *
 *   try {
 *       $lm->ajax_save();
 *   } catch ( JsonResponseException $e ) {
 *       $this->assertTrue( $e->success );
 *       $this->assertSame( 3, $e->data['vars_saved'] );
 *   }
 */

declare( strict_types=1 );

namespace D5DesignSystemHelper\Tests\Stubs;

class JsonResponseException extends \RuntimeException {

	public function __construct(
		public readonly bool  $success,
		public readonly mixed $data,
		public readonly int   $status_code,
	) {
		parent::__construct(
			sprintf( 'wp_send_json_%s called', $success ? 'success' : 'error' )
		);
	}
}
