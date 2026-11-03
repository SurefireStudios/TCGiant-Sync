<?php
/**
 * WordPress 7.1.3's own escaping, for test-suggest-title.php (3.29.2): the
 * functions a product name goes through from the eBay tab's Suggest Category
 * button to eBay, copied verbatim, each with its docblock, from
 * wp-includes/formatting.php (_wp_specialchars, wp_specialchars_decode,
 * wp_check_invalid_utf8, stripslashes_deep, stripslashes_from_strings_only,
 * esc_js, esc_html, wp_pre_kses_less_than and its callback,
 * wp_strip_all_tags, sanitize_text_field, _sanitize_text_fields,
 * wp_unslash, map_deep), wp-includes/functions.php (add_magic_quotes,
 * wp_json_encode, _wp_json_sanity_check, _wp_json_convert_string,
 * is_utf8_charset, _canonical_charset) and wp-includes/compat.php
 * (_is_utf8_charset, the str_contains() polyfill). utf8.php and
 * compat-utf8.php beside it are WordPress 7.1.3's, whole. They need
 * plugin.php and kses.php (also here), get_option( 'blog_charset' ) and __().
 *
 * WordPress is free software, GPL-2.0-or-later: https://wordpress.org/about/license/
 *
 * @package WordPress
 */

// --- wp-includes/formatting.php ---

/**
 * Converts a number of special characters into their HTML entities.
 *
 * Specifically deals with: `&`, `<`, `>`, `"`, and `'`.
 *
 * `$quote_style` can be set to ENT_COMPAT to encode `"` to
 * `&quot;`, or ENT_QUOTES to do both. Default is ENT_NOQUOTES where no quotes are encoded.
 *
 * @since 1.2.2
 * @since 5.5.0 `$quote_style` also accepts `ENT_XML1`.
 * @access private
 *
 * @param string       $text          The text which is to be encoded.
 * @param int|string   $quote_style   Optional. Converts double quotes if set to ENT_COMPAT,
 *                                    both single and double if set to ENT_QUOTES or none if set to ENT_NOQUOTES.
 *                                    Converts single and double quotes, as well as converting HTML
 *                                    named entities (that are not also XML named entities) to their
 *                                    code points if set to ENT_XML1. Also compatible with old values;
 *                                    converting single quotes if set to 'single',
 *                                    double if set to 'double' or both if otherwise set.
 *                                    Default is ENT_NOQUOTES.
 * @param false|string $charset       Optional. The character encoding of the string. Default false.
 * @param bool         $double_encode Optional. Whether to encode existing HTML entities. Default false.
 * @return string The encoded text with HTML entities.
 */
function _wp_specialchars( $text, $quote_style = ENT_NOQUOTES, $charset = false, $double_encode = false ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Don't bother if there are no specialchars - saves some processing.
	if ( ! preg_match( '/[&<>"\']/', $text ) ) {
		return $text;
	}

	// Account for the previous behavior of the function when the $quote_style is not an accepted value.
	if ( empty( $quote_style ) ) {
		$quote_style = ENT_NOQUOTES;
	} elseif ( ENT_XML1 === $quote_style ) {
		$quote_style = ENT_QUOTES | ENT_XML1;
	} elseif ( ! in_array( $quote_style, array( ENT_NOQUOTES, ENT_COMPAT, ENT_QUOTES, 'single', 'double' ), true ) ) {
		$quote_style = ENT_QUOTES;
	}

	$charset = _canonical_charset( $charset ? $charset : get_option( 'blog_charset' ) );

	$_quote_style = $quote_style;

	if ( 'double' === $quote_style ) {
		$quote_style  = ENT_COMPAT;
		$_quote_style = ENT_COMPAT;
	} elseif ( 'single' === $quote_style ) {
		$quote_style = ENT_NOQUOTES;
	}

	if ( ! $double_encode ) {
		/*
		 * Guarantee every &entity; is valid, convert &garbage; into &amp;garbage;
		 * This is required for PHP < 5.4.0 because ENT_HTML401 flag is unavailable.
		 */
		$text = wp_kses_normalize_entities( $text, ( $quote_style & ENT_XML1 ) ? 'xml' : 'html' );
	}

	$text = htmlspecialchars( $text, $quote_style, $charset, $double_encode );

	// Back-compat.
	if ( 'single' === $_quote_style ) {
		$text = str_replace( "'", '&#039;', $text );
	}

	return $text;
}

/**
 * Converts a number of HTML entities into their special characters.
 *
 * Specifically deals with: `&`, `<`, `>`, `"`, and `'`.
 *
 * `$quote_style` can be set to ENT_COMPAT to decode `"` entities,
 * or ENT_QUOTES to do both `"` and `'`. Default is ENT_NOQUOTES where no quotes are decoded.
 *
 * @since 2.8.0
 *
 * @param string     $text        The text which is to be decoded.
 * @param string|int $quote_style Optional. Converts double quotes if set to ENT_COMPAT,
 *                                both single and double if set to ENT_QUOTES or
 *                                none if set to ENT_NOQUOTES.
 *                                Also compatible with old _wp_specialchars() values;
 *                                converting single quotes if set to 'single',
 *                                double if set to 'double' or both if otherwise set.
 *                                Default is ENT_NOQUOTES.
 * @return string The decoded text without HTML entities.
 */
function wp_specialchars_decode( $text, $quote_style = ENT_NOQUOTES ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Don't bother if there are no entities - saves a lot of processing.
	if ( ! str_contains( $text, '&' ) ) {
		return $text;
	}

	// Match the previous behavior of _wp_specialchars() when the $quote_style is not an accepted value.
	if ( empty( $quote_style ) ) {
		$quote_style = ENT_NOQUOTES;
	} elseif ( ! in_array( $quote_style, array( 0, 2, 3, 'single', 'double' ), true ) ) {
		$quote_style = ENT_QUOTES;
	}

	// More complete than get_html_translation_table( HTML_SPECIALCHARS ).
	$single      = array(
		'&#039;' => '\'',
		'&#x27;' => '\'',
	);
	$single_preg = array(
		'/&#0*39;/'   => '&#039;',
		'/&#x0*27;/i' => '&#x27;',
	);
	$double      = array(
		'&quot;' => '"',
		'&#034;' => '"',
		'&#x22;' => '"',
	);
	$double_preg = array(
		'/&#0*34;/'   => '&#034;',
		'/&#x0*22;/i' => '&#x22;',
	);
	$others      = array(
		'&lt;'   => '<',
		'&#060;' => '<',
		'&gt;'   => '>',
		'&#062;' => '>',
		'&amp;'  => '&',
		'&#038;' => '&',
		'&#x26;' => '&',
	);
	$others_preg = array(
		'/&#0*60;/'   => '&#060;',
		'/&#0*62;/'   => '&#062;',
		'/&#0*38;/'   => '&#038;',
		'/&#x0*26;/i' => '&#x26;',
	);

	if ( ENT_QUOTES === $quote_style ) {
		$translation      = array_merge( $single, $double, $others );
		$translation_preg = array_merge( $single_preg, $double_preg, $others_preg );
	} elseif ( ENT_COMPAT === $quote_style || 'double' === $quote_style ) {
		$translation      = array_merge( $double, $others );
		$translation_preg = array_merge( $double_preg, $others_preg );
	} elseif ( 'single' === $quote_style ) {
		$translation      = array_merge( $single, $others );
		$translation_preg = array_merge( $single_preg, $others_preg );
	} elseif ( ENT_NOQUOTES === $quote_style ) {
		$translation      = $others;
		$translation_preg = $others_preg;
	}

	// Remove zero padding on numeric entities.
	$text = preg_replace( array_keys( $translation_preg ), array_values( $translation_preg ), $text );

	// Replace characters according to translation table.
	return strtr( $text, $translation );
}

/**
 * Checks for invalid UTF8 in a string.
 *
 * Note! This function only performs its work if the `blog_charset` is set
 * to UTF-8. For all other values it returns the input text unchanged.
 *
 * Note! Unless requested, this returns an empty string if the input contains
 * any sequences of invalid UTF-8. To replace invalid byte sequences, pass
 * `true` as the optional `$strip` parameter.
 *
 * Consider using {@see wp_scrub_utf8()} instead which does not depend on
 * the value of `blog_charset`.
 *
 * Example:
 *
 *     // The `blog_charset` is `latin1`, so this returns the input unchanged.
 *     $every_possible_input === wp_check_invalid_utf8( $every_possible_input );
 *
 *     // Valid strings come through unchanged.
 *     'test' === wp_check_invalid_utf8( 'test' );
 *
 *     $invalid = "the byte \xC0 is never allowed in a UTF-8 string.";
 *
 *     // Invalid strings are rejected outright.
 *     '' === wp_check_invalid_utf8( $invalid );
 *
 *     // “Stripping” invalid sequences produces the replacement character instead.
 *     "the byte \u{FFFD} is never allowed in a UTF-8 string." === wp_check_invalid_utf8( $invalid, true );
 *     'the byte � is never allowed in a UTF-8 string.' === wp_check_invalid_utf8( $invalid, true );
 *
 * @since 2.8.0
 * @since 6.9.0 Stripping replaces invalid byte sequences with the Unicode replacement character U+FFFD (�).
 *
 * @param string $text   String which is expected to be encoded as UTF-8 unless `blog_charset` is another encoding.
 * @param bool   $strip  Optional. Whether to replace invalid sequences of bytes with the Unicode replacement
 *                       character (U+FFFD `�`). Default `false` returns an empty string for invalid UTF-8 inputs.
 * @return string The checked text.
 */
function wp_check_invalid_utf8( $text, $strip = false ) {
	$text = (string) $text;

	if ( 0 === strlen( $text ) ) {
		return '';
	}

	// Store the site charset as a static to avoid multiple calls to get_option().
	static $is_utf8 = null;
	if ( ! isset( $is_utf8 ) ) {
		$is_utf8 = is_utf8_charset();
	}

	if ( ! $is_utf8 || wp_is_valid_utf8( $text ) ) {
		return $text;
	}

	return $strip
		? wp_scrub_utf8( $text )
		: '';
}

/**
 * Navigates through an array, object, or scalar, and removes slashes from the values.
 *
 * @since 2.0.0
 *
 * @param mixed $value The value to be stripped.
 * @return mixed Stripped value.
 *
 * @phpstan-template T
 * @phpstan-param T $value
 * @phpstan-return (
 *     T is string ? string : (
 *         T is array ? array<key-of<T>, ( value-of<T> is string ? string : value-of<T> )> : T
 *     )
 * )
 */
function stripslashes_deep( $value ) {
	return map_deep( $value, 'stripslashes_from_strings_only' );
}

/**
 * Callback function for `stripslashes_deep()` which strips slashes from strings.
 *
 * @since 4.4.0
 *
 * @param mixed $value The array or string to be stripped.
 * @return mixed The stripped value.
 *
 * @phpstan-template T
 * @phpstan-param T $value
 * @phpstan-return (T is string ? string : T)
 */
function stripslashes_from_strings_only( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Escapes single quotes, `"`, `<`, `>`, `&`, and fixes line endings.
 *
 * Escapes text strings for echoing in JS. It is intended to be used for inline JS
 * (in a tag attribute, for example `onclick="..."`). Note that the strings have to
 * be in single quotes. The {@see 'js_escape'} filter is also applied here.
 *
 * @since 2.8.0
 *
 * @param string $text The text to be escaped.
 * @return string Escaped text.
 */
function esc_js( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_COMPAT );
	$safe_text = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $safe_text ) );
	$safe_text = str_replace( "\r", '', $safe_text );
	$safe_text = str_replace( "\n", '\\n', addslashes( $safe_text ) );
	/**
	 * Filters a string cleaned and escaped for output in JavaScript.
	 *
	 * Text passed to esc_js() is stripped of invalid or special characters,
	 * and properly slashed for output.
	 *
	 * @since 2.0.6
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'js_escape', $safe_text, $text );
}

/**
 * Escaping for HTML blocks.
 *
 * @since 2.8.0
 *
 * @param string $text
 * @return string Escaped text.
 */
function esc_html( $text ) {
	$safe_text = wp_check_invalid_utf8( $text );
	$safe_text = _wp_specialchars( $safe_text, ENT_QUOTES );
	/**
	 * Filters a string cleaned and escaped for output in HTML.
	 *
	 * Text passed to esc_html() is stripped of invalid or special characters
	 * before output.
	 *
	 * @since 2.8.0
	 *
	 * @param string $safe_text The text after it has been escaped.
	 * @param string $text      The text prior to being escaped.
	 */
	return apply_filters( 'esc_html', $safe_text, $text );
}

/**
 * Converts lone less than signs.
 *
 * KSES already converts lone greater than signs.
 *
 * @since 2.3.0
 *
 * @param string $content Text to be converted.
 * @return string Converted text.
 */
function wp_pre_kses_less_than( $content ) {
	return preg_replace_callback( '%<[^>]*?((?=<)|>|$)%', 'wp_pre_kses_less_than_callback', $content );
}

/**
 * Callback function used by preg_replace.
 *
 * @since 2.3.0
 *
 * @param string[] $matches Populated by matches to preg_replace.
 * @return string The text returned after esc_html if needed.
 */
function wp_pre_kses_less_than_callback( $matches ) {
	if ( ! str_contains( $matches[0], '>' ) ) {
		return esc_html( $matches[0] );
	}
	return $matches[0];
}

/**
 * Properly strips all HTML tags including 'script' and 'style'.
 *
 * This differs from strip_tags() because it removes the contents of
 * the `<script>` and `<style>` tags. E.g. `strip_tags( '<script>something</script>' )`
 * will return 'something'. wp_strip_all_tags() will return an empty string.
 *
 * @since 2.9.0
 *
 * @param string $text          String containing HTML tags
 * @param bool   $remove_breaks Optional. Whether to remove left over line breaks and white space chars
 * @return string The processed string.
 */
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	if ( is_null( $text ) ) {
		return '';
	}

	if ( ! is_scalar( $text ) ) {
		/*
		 * To maintain consistency with pre-PHP 8 error levels,
		 * wp_trigger_error() is used to trigger an E_USER_WARNING,
		 * rather than _doing_it_wrong(), which triggers an E_USER_NOTICE.
		 */
		wp_trigger_error(
			'',
			sprintf(
				/* translators: 1: The function name, 2: The argument number, 3: The argument name, 4: The expected type, 5: The provided type. */
				__( 'Warning: %1$s expects parameter %2$s (%3$s) to be a %4$s, %5$s given.' ),
				__FUNCTION__,
				'#1',
				'$text',
				'string',
				gettype( $text )
			),
			E_USER_WARNING
		);

		return '';
	}

	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( $text );

	if ( $remove_breaks ) {
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
	}

	return trim( $text );
}

/**
 * Sanitizes a string from user input or from the database.
 *
 * - Checks for invalid UTF-8,
 * - Converts single `<` characters to entities
 * - Strips all tags
 * - Removes line breaks, tabs, and extra whitespace
 * - Strips percent-encoded characters
 *
 * @since 2.9.0
 *
 * @see sanitize_textarea_field()
 * @see wp_check_invalid_utf8()
 * @see wp_strip_all_tags()
 *
 * @param string $str String to sanitize.
 * @return string Sanitized string.
 */
function sanitize_text_field( $str ) {
	$filtered = _sanitize_text_fields( $str, false );

	/**
	 * Filters a sanitized text field string.
	 *
	 * @since 2.9.0
	 *
	 * @param string $filtered The sanitized string.
	 * @param string $str      The string prior to being sanitized.
	 */
	return apply_filters( 'sanitize_text_field', $filtered, $str );
}

/**
 * Internal helper function to sanitize a string from user input or from the database.
 *
 * @since 4.7.0
 * @access private
 *
 * @param string $str           String to sanitize.
 * @param bool   $keep_newlines Optional. Whether to keep newlines. Default: false.
 * @return string Sanitized string.
 */
function _sanitize_text_fields( $str, $keep_newlines = false ) {
	if ( is_object( $str ) || is_array( $str ) ) {
		return '';
	}

	$str = (string) $str;

	$filtered = wp_check_invalid_utf8( $str );

	if ( str_contains( $filtered, '<' ) ) {
		$filtered = wp_pre_kses_less_than( $filtered );
		// This will strip extra whitespace for us.
		$filtered = wp_strip_all_tags( $filtered, false );

		/*
		 * Use HTML entities in a special case to make sure that
		 * later newline stripping stages cannot lead to a functional tag.
		 */
		$filtered = str_replace( "<\n", "&lt;\n", $filtered );
	}

	if ( ! $keep_newlines ) {
		$filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
	}
	$filtered = trim( $filtered );

	// Remove percent-encoded characters.
	$found = false;
	while ( preg_match( '/%[a-f0-9]{2}/i', $filtered, $match ) ) {
		$filtered = str_replace( $match[0], '', $filtered );
		$found    = true;
	}

	if ( $found ) {
		// Strip out the whitespace that may now exist after removing percent-encoded characters.
		$filtered = trim( preg_replace( '/ +/', ' ', $filtered ) );
	}

	return $filtered;
}

/**
 * Removes slashes from a string or recursively removes slashes from strings within an array.
 *
 * This should be used to remove slashes from data passed to core API that
 * expects data to be unslashed.
 *
 * @since 3.6.0
 *
 * @param string|array $value String or array of data to unslash.
 * @return string|array Unslashed `$value`, in the same type as supplied.
 *
 * @phpstan-template T
 * @phpstan-param T $value
 * @phpstan-return (
 *     T is string ? string : (
 *         T is array ? array<key-of<T>, ( value-of<T> is string ? string : value-of<T> )> : T
 *     )
 * )
 */
function wp_unslash( $value ) {
	return stripslashes_deep( $value );
}

/**
 * Maps a function to all non-iterable elements of an array or an object.
 *
 * This is similar to `array_walk_recursive()` but acts upon objects too.
 *
 * @since 4.4.0
 *
 * @param mixed    $value    The array, object, or scalar.
 * @param callable $callback The function to map onto $value.
 * @return mixed The value with the callback applied to all non-arrays and non-objects inside it.
 *
 * @phpstan-template T
 * @phpstan-param T $value
 * @phpstan-return (T is array ? array<key-of<T>, mixed> : (T is object ? T : mixed))
 */
function map_deep( $value, $callback ) {
	if ( is_array( $value ) ) {
		foreach ( $value as $index => $item ) {
			$value[ $index ] = map_deep( $item, $callback );
		}
	} elseif ( is_object( $value ) ) {
		$object_vars = get_object_vars( $value );
		foreach ( $object_vars as $property_name => $property_value ) {
			$value->$property_name = map_deep( $property_value, $callback );
		}
	} else {
		$value = call_user_func( $callback, $value );
	}

	return $value;
}

// --- wp-includes/functions.php ---

/**
 * Walks the array while sanitizing the contents.
 *
 * @since 0.71
 * @since 5.5.0 Non-string values are left untouched.
 *
 * @param array $input_array Array to walk while sanitizing contents.
 * @return array Sanitized $input_array.
 *
 * @phpstan-template T of array
 * @phpstan-param T $input_array
 * @phpstan-return array<key-of<T>, ( value-of<T> is string ? string : value-of<T> )>
 */
function add_magic_quotes( $input_array ) {
	foreach ( (array) $input_array as $k => $v ) {
		if ( is_array( $v ) ) {
			$input_array[ $k ] = add_magic_quotes( $v );
		} elseif ( is_string( $v ) ) {
			$input_array[ $k ] = addslashes( $v );
		}
	}

	return $input_array;
}

/**
 * Encodes a variable into JSON, with some confidence checks.
 *
 * @since 4.1.0
 * @since 5.3.0 No longer handles support for PHP < 5.6.
 * @since 6.5.0 The `$data` parameter has been renamed to `$value` and
 *              the `$options` parameter to `$flags` for parity with PHP.
 *
 * @param mixed $value Variable (usually an array or object) to encode as JSON.
 * @param int   $flags Optional. Options to be passed to json_encode(). Default 0.
 * @param int   $depth Optional. Maximum depth to walk through $value. Must be
 *                     greater than 0. Default 512.
 * @return string|false The JSON encoded string, or false if it cannot be encoded.
 */
function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	$json = json_encode( $value, $flags, $depth );

	// If json_encode() was successful, no need to do more confidence checking.
	if ( false !== $json ) {
		return $json;
	}

	try {
		$value = _wp_json_sanity_check( $value, $depth );
	} catch ( Exception $e ) {
		return false;
	}

	return json_encode( $value, $flags, $depth );
}

/**
 * Performs confidence checks on data that shall be encoded to JSON.
 *
 * @ignore
 * @since 4.1.0
 * @access private
 *
 * @see wp_json_encode()
 *
 * @throws Exception If depth limit is reached.
 *
 * @param mixed $value Variable (usually an array or object) to encode as JSON.
 * @param int   $depth Maximum depth to walk through $value. Must be greater than 0.
 * @return mixed The sanitized data that shall be encoded to JSON.
 */
function _wp_json_sanity_check( $value, $depth ) {
	if ( $depth < 0 ) {
		throw new Exception( 'Reached depth limit' );
	}

	if ( is_array( $value ) ) {
		$output = array();
		foreach ( $value as $id => $el ) {
			// Don't forget to sanitize the ID!
			if ( is_string( $id ) ) {
				$clean_id = _wp_json_convert_string( $id );
			} else {
				$clean_id = $id;
			}

			// Check the element type, so that we're only recursing if we really have to.
			if ( is_array( $el ) || is_object( $el ) ) {
				$output[ $clean_id ] = _wp_json_sanity_check( $el, $depth - 1 );
			} elseif ( is_string( $el ) ) {
				$output[ $clean_id ] = _wp_json_convert_string( $el );
			} else {
				$output[ $clean_id ] = $el;
			}
		}
	} elseif ( is_object( $value ) ) {
		$output = new stdClass();
		foreach ( $value as $id => $el ) {
			if ( is_string( $id ) ) {
				$clean_id = _wp_json_convert_string( $id );
			} else {
				$clean_id = $id;
			}

			if ( is_array( $el ) || is_object( $el ) ) {
				$output->$clean_id = _wp_json_sanity_check( $el, $depth - 1 );
			} elseif ( is_string( $el ) ) {
				$output->$clean_id = _wp_json_convert_string( $el );
			} else {
				$output->$clean_id = $el;
			}
		}
	} elseif ( is_string( $value ) ) {
		return _wp_json_convert_string( $value );
	} else {
		return $value;
	}

	return $output;
}

/**
 * Converts a string to UTF-8, so that it can be safely encoded to JSON.
 *
 * @ignore
 * @since 4.1.0
 * @access private
 *
 * @see _wp_json_sanity_check()
 *
 * @param string $input_string The string which is to be converted.
 * @return string The checked string.
 */
function _wp_json_convert_string( $input_string ) {
	static $use_mb = null;
	if ( is_null( $use_mb ) ) {
		$use_mb = function_exists( 'mb_convert_encoding' );
	}

	if ( $use_mb ) {
		$encoding = mb_detect_encoding( $input_string, mb_detect_order(), true );
		if ( $encoding ) {
			return mb_convert_encoding( $input_string, 'UTF-8', $encoding );
		} else {
			return mb_convert_encoding( $input_string, 'UTF-8', 'UTF-8' );
		}
	} else {
		return wp_check_invalid_utf8( $input_string, true );
	}
}

/**
 * Indicates if a given slug for a character set represents the UTF-8
 * text encoding. If not provided, examines the current blog's charset.
 *
 * A charset is considered to represent UTF-8 if it is a case-insensitive
 * match of "UTF-8" with or without the hyphen.
 *
 * Example:
 *
 *     true  === is_utf8_charset( 'UTF-8' );
 *     true  === is_utf8_charset( 'utf8' );
 *     false === is_utf8_charset( 'latin1' );
 *     false === is_utf8_charset( 'UTF 8' );
 *
 *     // Only strings match.
 *     false === is_utf8_charset( [ 'charset' => 'utf-8' ] );
 *
 *     // Without a given charset, it depends on the site option "blog_charset".
 *     $is_utf8 = is_utf8_charset();
 *
 * @since 6.6.0
 * @since 6.6.1 A wrapper for _is_utf8_charset
 *
 * @see _is_utf8_charset
 *
 * @param string|null $blog_charset Optional. Slug representing a text character encoding, or "charset".
 *                                  E.g. "UTF-8", "Windows-1252", "ISO-8859-1", "SJIS".
 *                                  Default value is to infer from "blog_charset" option.
 * @return bool Whether the slug represents the UTF-8 encoding.
 */
function is_utf8_charset( $blog_charset = null ) {
	return _is_utf8_charset( $blog_charset ?? get_option( 'blog_charset' ) );
}

/**
 * Retrieves a canonical form of the provided charset appropriate for passing to PHP
 * functions such as htmlspecialchars() and charset HTML attributes.
 *
 * @since 3.6.0
 * @access private
 *
 * @see https://core.trac.wordpress.org/ticket/23688
 *
 * @param string $charset A charset name, e.g. "UTF-8", "Windows-1252", "SJIS".
 * @return string The canonical form of the charset.
 */
function _canonical_charset( $charset ) {
	if ( is_utf8_charset( $charset ) ) {
		return 'UTF-8';
	}

	/*
	 * Normalize the ISO-8859-1 family of languages.
	 *
	 * This is not required for htmlspecialchars(), as it properly recognizes all of
	 * the input character sets that here are transformed into "ISO-8859-1".
	 *
	 * @todo Should this entire check be removed since it's not required for the stated purpose?
	 * @todo Should WordPress transform other potential charset equivalents, such as "latin1"?
	 */
	if (
		( 0 === strcasecmp( 'iso-8859-1', $charset ) ) ||
		( 0 === strcasecmp( 'iso8859-1', $charset ) )
	) {
		return 'ISO-8859-1';
	}

	return $charset;
}

// --- wp-includes/compat.php ---

/**
 * Indicates if a given slug for a character set represents the UTF-8 text encoding.
 *
 * A charset is considered to represent UTF-8 if it is a case-insensitive match
 * of "UTF-8" with or without the hyphen.
 *
 * Example:
 *
 *     true  === _is_utf8_charset( 'UTF-8' );
 *     true  === _is_utf8_charset( 'utf8' );
 *     false === _is_utf8_charset( 'latin1' );
 *     false === _is_utf8_charset( 'UTF 8' );
 *
 *     // Only strings match.
 *     false === _is_utf8_charset( [ 'charset' => 'utf-8' ] );
 *
 * `is_utf8_charset` should be used outside of this file.
 *
 * @ignore
 * @since 6.6.1
 *
 * @param string $charset_slug Slug representing a text character encoding, or "charset".
 *                             E.g. "UTF-8", "Windows-1252", "ISO-8859-1", "SJIS".
 *
 * @return bool Whether the slug represents the UTF-8 encoding.
 */
function _is_utf8_charset( $charset_slug ) {
	if ( ! is_string( $charset_slug ) ) {
		return false;
	}

	return (
		0 === strcasecmp( 'UTF-8', $charset_slug ) ||
		0 === strcasecmp( 'UTF8', $charset_slug )
	);
}

if ( ! function_exists( 'str_contains' ) ) {
	/**
	 * Polyfill for `str_contains()` function added in PHP 8.0.
	 *
	 * Performs a case-sensitive check indicating if needle is
	 * contained in haystack.
	 *
	 * @since 5.9.0
	 *
	 * @param string $haystack The string to search in.
	 * @param string $needle   The substring to search for in the `$haystack`.
	 * @return bool True if `$needle` is in `$haystack`, otherwise false.
	 */
	function str_contains( $haystack, $needle ) {
		if ( '' === $needle ) {
			return true;
		}

		return false !== strpos( $haystack, $needle );
	}
}
