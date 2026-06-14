<?php
/**
 * JSON-LD validation and normalization.
 *
 * @package SafeSchema
 */

namespace SafeSchema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validator {
	/**
	 * Validate and normalize user supplied JSON-LD.
	 *
	 * @param string $input Raw input.
	 * @return array{valid:bool,json:string,data:mixed,errors:string[],warnings:string[]}
	 */
	public static function validate( $input ) {
		$result = array(
			'valid'    => false,
			'json'     => '',
			'data'     => null,
			'errors'   => array(),
			'warnings' => array(),
		);

		if ( ! is_string( $input ) ) {
			$result['errors'][] = __( 'Schema must be submitted as text.', 'safeschema' );
			return $result;
		}

		$input = preg_replace( '/^\xEF\xBB\xBF/', '', $input );
		$input = trim( $input );

		if ( '' === $input ) {
			$result['errors'][] = __( 'Schema cannot be empty while Add or Replace mode is enabled.', 'safeschema' );
			return $result;
		}

		$extracted = self::extract_script_wrapper( $input );
		if ( is_wp_error( $extracted ) ) {
			$result['errors'][] = $extracted->get_error_message();
			return $result;
		}

		$data = json_decode( $extracted, true, 512 );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$result['errors'][] = sprintf(
				/* translators: %s: JSON error message. */
				__( 'Invalid JSON: %s', 'safeschema' ),
				json_last_error_msg()
			);
			return $result;
		}

		if ( ! is_array( $data ) || array() === $data ) {
			$result['errors'][] = __( 'The JSON root must be a non-empty object or an array of objects.', 'safeschema' );
			return $result;
		}

		if ( self::is_list( $data ) ) {
			foreach ( $data as $index => $item ) {
				if ( ! is_array( $item ) || self::is_list( $item ) ) {
					$result['errors'][] = sprintf(
						/* translators: %d: Array item number. */
						__( 'Item %d in the root array must be a JSON object.', 'safeschema' ),
						$index + 1
					);
				}
			}
		}

		if ( ! empty( $result['errors'] ) ) {
			return $result;
		}

		if ( ! self::contains_key( $data, '@context' ) ) {
			$result['warnings'][] = __( 'No @context property was found. Most JSON-LD schema should include the schema.org context.', 'safeschema' );
		}

		if ( ! self::contains_key( $data, '@type' ) && ! self::contains_key( $data, '@graph' ) ) {
			$result['warnings'][] = __( 'No @type or @graph property was found. Check that this is complete JSON-LD.', 'safeschema' );
		}

		if ( self::contains_unsafe_script_sequence( $data ) ) {
			$result['errors'][] = __( 'A value contains an unsafe closing script sequence. Remove it before saving.', 'safeschema' );
			return $result;
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		if ( false === $json ) {
			$result['errors'][] = __( 'WordPress could not encode the validated schema.', 'safeschema' );
			return $result;
		}

		$result['valid'] = true;
		$result['json']  = $json;
		$result['data']  = $data;
		return $result;
	}

	/**
	 * Accept raw JSON or one complete JSON-LD script wrapper.
	 *
	 * @param string $input Raw input.
	 * @return string|\WP_Error
	 */
	private static function extract_script_wrapper( $input ) {
		$open  = '<' . 'script';
		$close = '<' . '/script';

		if ( false === stripos( $input, $open ) && false === stripos( $input, $close ) ) {
			if ( preg_match( '/<\/?[a-z][^>]*>/i', $input ) ) {
				return new \WP_Error( 'safeschema_html', __( 'Paste JSON-LD only. HTML tags are not allowed.', 'safeschema' ) );
			}
			return $input;
		}

		$pattern = '/^\s*<' . 'script\b[^>]*\btype\s*=\s*(["\'])application\/ld\+json\1[^>]*>(.*)<' . '\/script>\s*$/is';
		if ( 1 !== preg_match( $pattern, $input, $matches ) ) {
			return new \WP_Error( 'safeschema_script', __( 'Only one complete JSON-LD script wrapper is accepted. Remove other HTML or scripts.', 'safeschema' ) );
		}

		return trim( $matches[2] );
	}

	/**
	 * Determine whether an array is a list without requiring PHP 8.1.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Recursively find a JSON-LD key.
	 *
	 * @param mixed  $value Data.
	 * @param string $key Key.
	 * @return bool
	 */
	private static function contains_key( $value, $key ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array_key_exists( $key, $value ) ) {
			return true;
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) && self::contains_key( $child, $key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reject literal closing script sequences inside values.
	 *
	 * @param mixed $value Data.
	 * @return bool
	 */
	private static function contains_unsafe_script_sequence( $value ) {
		if ( is_string( $value ) ) {
			return false !== stripos( $value, '<' . '/script' );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( self::contains_unsafe_script_sequence( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
