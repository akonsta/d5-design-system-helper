<?php
/**
 * DiviBlocParser — centralised parser for Divi 5 block serialisation tokens.
 *
 * ## Overview
 *
 * All regex patterns that describe how Divi 5 serialises variable and preset
 * references are defined here as class constants. If Divi changes its
 * serialisation format in a future release, update the relevant constants and/or
 * private strategy methods in this single file.  No changes are required in
 * callers (AuditEngine, SimpleImporter, VarsExporter, etc.).
 *
 * For a detailed explanation of the serialisation format — including an ABNF
 * grammar, a change-impact matrix, and a guide to adding alternative strategies —
 * see docs/SERIALIZATION_SPEC.md.
 *
 * ## Multi-strategy dispatch
 *
 * Both extract_variable_refs() and extract_preset_refs() iterate over a list of
 * registered strategy methods.  Each strategy handles one serialisation variant.
 * Results from all strategies are merged and deduplicated before being returned.
 * This means adding a NEW serialisation format requires:
 *
 *   1. Add a const for the new pattern.
 *   2. Add a private static extract_variable_refs_<name>() / extract_preset_refs_<name>() method.
 *   3. Add the method name string to VARIABLE_REF_STRATEGIES / PRESET_REF_STRATEGIES.
 *
 * No other changes are needed.
 *
 * ## Current token formats (Divi 5.x)
 *
 *   Variable reference (Strategy: divi5_dollar_token)
 *     $variable({"type":"color|content","value":{"name":"gcid-xxx|gvid-xxx"}})$
 *
 *   Inner quotes may be encoded in three ways — all are normalised automatically:
 *     \u0022  — Divi block markup (post_content HTML comment context)
 *     \"      — JSON re-encode artefact (json_encode on already-decoded PHP array)
 *     "       — Plain (raw JSON files, export payloads)
 *
 *   Element Preset reference (Strategy: divi5_module_preset)
 *     "modulePreset":["preset-id"]
 *
 *   Option Group Preset reference (Strategy: divi5_group_preset)
 *     "presetId":["preset-id"]
 *
 * @package D5DesignSystemHelper
 */

namespace D5DesignSystemHelper\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DiviBlocParser
 */
class DiviBlocParser {

	// ── Strategy registries ───────────────────────────────────────────────────

	/**
	 * Ordered list of variable-reference extraction strategies.
	 *
	 * Each entry is the name of a private static method on this class with the
	 * signature:  function(string $raw): array<array{type: string, name: string}>
	 *
	 * Strategies are tried in order. Results from all strategies are merged and
	 * deduplicated (by 'name' field) before being returned to callers.
	 *
	 * To add a new format: append the method name here.
	 */
	private const VARIABLE_REF_STRATEGIES = [
		'extract_variable_refs_divi5_dollar_token',
		// Future example: 'extract_variable_refs_divi6_html_element',
	];

	/**
	 * Ordered list of preset-reference extraction strategies.
	 *
	 * Each entry is the name of a private static method on this class with the
	 * signature:  function(string $post_content): string[]
	 *
	 * Results from all strategies are merged and deduplicated before returning.
	 */
	private const PRESET_REF_STRATEGIES = [
		'extract_preset_refs_divi5_module_preset',
		'extract_preset_refs_divi5_group_preset',
		// Future example: 'extract_preset_refs_divi6_xml_element',
	];

	// ── Pattern constants: Divi 5 dollar-token format ─────────────────────────
	// These define the $variable(...)$ token introduced in Divi 5.
	// Update these constants if the token wrapper changes (e.g. prefix or suffix).
	// See docs/SERIALIZATION_SPEC.md §10 for the change-impact matrix.

	/**
	 * Matches the full JSON payload inside a $variable(...)$ token.
	 * Uses a character-class-based match [^)]+ to stop at the closing ).
	 *
	 * Capture group 1: raw JSON payload (not yet decoded).
	 *
	 * Use this (greedy, char-class) variant for scanning multi-token strings such
	 * as JSON-encoded preset attrs.
	 */
	const VARIABLE_TOKEN_PATTERN = '/\$variable\((\{[^)]+\})\)\$/';

	/**
	 * Strips (removes) all $variable(...)$ tokens from a string entirely.
	 *
	 * Used before hex-color scanning to prevent hex digits inside a token payload
	 * from being counted as hardcoded colors.
	 */
	const VARIABLE_STRIP_PATTERN = '/\$variable\(\{[^)]+\}\)\$/';

	/**
	 * Matches the payload inside a $variable(...)$ token using a non-greedy .+?
	 * instead of a character-class [^)]+.
	 *
	 * Use this variant when parsing a single field value string where the
	 * character-class form might stop too early (e.g. if Divi ever puts ) inside
	 * the payload).  The single-token helper extract_variable_ref_name() uses this.
	 */
	const VARIABLE_TOKEN_PATTERN_NONGREEDY = '/\$variable\((.+?)\)\$/';

	// ── Pattern constants: Divi 5 preset-reference formats ────────────────────

	/**
	 * Matches an Element Preset reference in block markup.
	 * Capture group 1: preset ID string.
	 *
	 * Block markup form:  "modulePreset":["some-preset-id"]
	 */
	const ELEMENT_PRESET_PATTERN = '/"modulePreset":\["([^"]+)"\]/';

	/**
	 * Matches an Option Group Preset reference in block markup.
	 * Capture group 1: preset ID string.
	 *
	 * Block markup form (inside a groupPreset container):
	 *   "presetId":["some-preset-id"]
	 */
	const GROUP_PRESET_PATTERN = '/"presetId":\["([^"]+)"\]/';

	// ── Public API ────────────────────────────────────────────────────────────

	/**
	 * Extract all variable references from a raw string.
	 *
	 * Iterates all registered VARIABLE_REF_STRATEGIES.  Results from every
	 * strategy are merged; entries with duplicate 'name' values are collapsed
	 * (first occurrence wins).
	 *
	 * Handles \u0022, \", and plain " quote encodings transparently.
	 *
	 * @param string $raw Preset attrs JSON, block markup, or any string that
	 *                    may contain variable reference tokens.
	 * @return array[] Each element: [ 'type' => string, 'name' => string ]
	 */
	public static function extract_variable_refs( string $raw ): array {
		$all  = [];
		$seen = []; // name => true  (deduplication index)

		foreach ( self::VARIABLE_REF_STRATEGIES as $method ) {
			foreach ( self::$method( $raw ) as $ref ) {
				$name = $ref['name'] ?? '';
				if ( $name !== '' && ! isset( $seen[ $name ] ) ) {
					$seen[ $name ] = true;
					$all[]         = $ref;
				}
			}
		}

		return $all;
	}

	/**
	 * Extract all preset ID references from a block post_content string.
	 *
	 * Iterates all registered PRESET_REF_STRATEGIES and returns the merged,
	 * deduplicated list of unique preset IDs.
	 *
	 * @param string $post_content WordPress block markup string.
	 * @return string[] Flat array of unique preset IDs.
	 */
	public static function extract_preset_refs( string $post_content ): array {
		$all = [];

		foreach ( self::PRESET_REF_STRATEGIES as $method ) {
			foreach ( self::$method( $post_content ) as $id ) {
				$all[] = $id;
			}
		}

		return array_values( array_unique( $all ) );
	}

	/**
	 * Extract the referenced variable name from a single $variable({...})$ expression.
	 *
	 * Unlike extract_variable_refs(), this is a single-field helper — it takes a
	 * single CSS value string (which may or may not contain a token) and returns
	 * just the variable ID string, or '' if none is found.
	 *
	 * Uses the non-greedy pattern variant because single-field values are short and
	 * the non-greedy form is more robust against edge-case payloads.
	 *
	 * Example input:  '$variable({"type":"color","value":{"name":"gcid-primary"}})$'
	 * Example output: 'gcid-primary'
	 *
	 * @param string $value Raw field value string.
	 * @return string The variable name (ID), or '' if not found / unparseable.
	 */
	public static function extract_variable_ref_name( string $value ): string {
		if ( preg_match( self::VARIABLE_TOKEN_PATTERN_NONGREEDY, $value, $m ) ) {
			$json    = str_replace( [ '\\u0022', '\\"' ], '"', $m[1] );
			$decoded = json_decode( $json, true );
			return $decoded['value']['name'] ?? '';
		}
		return '';
	}

	/**
	 * Strip all $variable(...)$ tokens from a string.
	 *
	 * Used before hex-color scanning to avoid matching hex digits embedded
	 * inside a variable token payload.
	 *
	 * @param string $raw Input string.
	 * @return string Input with all variable tokens removed.
	 */
	public static function strip_variable_tokens( string $raw ): string {
		$result = preg_replace( self::VARIABLE_STRIP_PATTERN, '', $raw );
		return $result ?? $raw;
	}

	/**
	 * Convert a preset's attrs (and styleAttrs) to a single raw string
	 * suitable for regex scanning.
	 *
	 * JSON-encodes the 'attrs' and 'styleAttrs' arrays separately and
	 * concatenates the results with a space.  This produces the `\"` quote
	 * encoding variant that decode_variable_payload() normalises transparently.
	 *
	 * If Divi adds a new attribute key that should also be scanned (e.g.
	 * 'themeAttrs'), add it here.  No other changes are required.
	 *
	 * @param array $preset Preset item array.
	 * @return string Scannable string representation of preset attributes.
	 */
	public static function preset_attrs_to_string( array $preset ): string {
		$parts = [];

		foreach ( [ 'attrs', 'styleAttrs' ] as $key ) {
			if ( isset( $preset[ $key ] ) ) {
				$encoded = json_encode( $preset[ $key ] );
				if ( is_string( $encoded ) ) {
					$parts[] = $encoded;
				}
			}
		}

		return implode( ' ', $parts );
	}

	// ── Strategy implementations: variable references ─────────────────────────

	/**
	 * Strategy: divi5_dollar_token
	 *
	 * Extracts variable references using the Divi 5 $variable(...)$ token format.
	 *
	 * Spec reference: docs/SERIALIZATION_SPEC.md §3
	 * Pattern: $variable({"type":"color|content","value":{"name":"<id>"}})$
	 *
	 * To update if Divi changes the token wrapper:
	 *   - Update VARIABLE_TOKEN_PATTERN (the wrapping regex).
	 *   - Update decode_variable_payload() if the inner JSON structure changes.
	 *
	 * @param string $raw Input string.
	 * @return array[] Each element: [ 'type' => string, 'name' => string ]
	 */
	private static function extract_variable_refs_divi5_dollar_token( string $raw ): array {
		$refs = [];

		if ( ! preg_match_all( self::VARIABLE_TOKEN_PATTERN, $raw, $matches ) ) {
			return $refs;
		}

		foreach ( $matches[1] as $payload ) {
			$decoded = self::decode_variable_payload( $payload );
			if ( $decoded !== null && $decoded['name'] !== '' ) {
				$refs[] = $decoded;
			}
		}

		return $refs;
	}

	// ── Strategy implementations: preset references ────────────────────────────

	/**
	 * Strategy: divi5_module_preset
	 *
	 * Extracts Element Preset IDs from "modulePreset":["id"] keys in block markup.
	 *
	 * Spec reference: docs/SERIALIZATION_SPEC.md §4.1
	 *
	 * @param string $post_content Block markup or JSON string.
	 * @return string[] Preset IDs found.
	 */
	private static function extract_preset_refs_divi5_module_preset( string $post_content ): array {
		$ids = [];
		if ( preg_match_all( self::ELEMENT_PRESET_PATTERN, $post_content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Strategy: divi5_group_preset
	 *
	 * Extracts Group Preset IDs from "presetId":["id"] keys in block markup.
	 *
	 * Spec reference: docs/SERIALIZATION_SPEC.md §4.2
	 *
	 * @param string $post_content Block markup or JSON string.
	 * @return string[] Preset IDs found.
	 */
	private static function extract_preset_refs_divi5_group_preset( string $post_content ): array {
		$ids = [];
		if ( preg_match_all( self::GROUP_PRESET_PATTERN, $post_content, $m ) ) {
			foreach ( $m[1] as $id ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Decode a raw variable token payload into a [ type, name ] pair.
	 *
	 * Normalises all three quote-encoding variants before calling json_decode():
	 *   \u0022  (6-char literal) → "    post_content block markup
	 *   \"      (2-char literal) → "    json_encode re-encode artefact
	 *   "       (plain)          → "    raw JSON / export files (no-op)
	 *
	 * Returns null if json_decode fails or the expected keys are absent.
	 *
	 * To update if the JSON payload structure changes:
	 *   - Update the key paths used to extract 'type' and 'name'.
	 *   - If new top-level keys become relevant, add them to the returned array.
	 *
	 * @param string $payload Raw payload string captured by VARIABLE_TOKEN_PATTERN.
	 * @return array{type: string, name: string}|null
	 */
	private static function decode_variable_payload( string $payload ): ?array {
		$json    = str_replace( [ '\\u0022', '\\"' ], '"', $payload );
		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return null;
		}

		$name = $decoded['value']['name'] ?? '';
		if ( $name === '' ) {
			return null;
		}

		return [
			'type' => $decoded['type'] ?? '',
			'name' => $name,
		];
	}
}
