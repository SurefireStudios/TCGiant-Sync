<?php
/**
 * The placeholder engine behind eBay listing templates.
 *
 * Logic-less and Mustache-like, rendered in one pass:
 *
 * - {{x}} is HTML-escaped;
 * - {{{x}}} is raw, and only for the values the render pipeline has already
 *   sanitised - the description, the blocks, the prebuilt tables. Asked for
 *   anything else it is escaped all the same, with a warning;
 * - {{#x}}...{{/x}} is a section, or a loop when x is a list;
 * - {{^x}}...{{/x}} shows only when x is empty.
 *
 * No eval, no PHP, no includes. A value is written out once and never read
 * again as a template, so a product title that happens to contain {{title}}
 * reaches eBay as those very characters. A placeholder nobody knows renders
 * empty and says so: LystoSync prints unknown placeholders on the live
 * listing, where only a buyer sees them.
 *
 * Nothing here knows about WordPress or WooCommerce. The context is plain data,
 * built by TCGiant_Sync_Templates; this only puts it into the markup.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TCGiant_Sync_Template_Renderer class.
 */
class TCGiant_Sync_Template_Renderer {

	/**
	 * What a placeholder name may hold: letters, digits, dot, underscore and
	 * hyphen, so attr.pa_brand and meta.card-number both read as one name.
	 */
	const NAME = '[A-Za-z0-9_.\-]+';

	/**
	 * How deep sections may nest. A template has no reason to go past a few
	 * levels; one that does is a mistake, and an unbounded one a stack to blow.
	 */
	const MAX_DEPTH = 12;

	/**
	 * Render a template against a context.
	 *
	 * @param string $template The template's markup.
	 * @param array  $context  Name => value. Values are strings, numbers, bools,
	 *                         lists of rows (each row name => value) for loops,
	 *                         or one row for a section.
	 * @param array  $options  'raw':      names that {{{x}}} may write unescaped;
	 *                                     a name ending ".*" covers its prefix.
	 *                         'resolver': callable( $name ) for names the context
	 *                                     does not hold, returning array( found,
	 *                                     value, warning ).
	 * @return array{html: string, warnings: string[], used: string[]}
	 */
	public static function render( $template, array $context, array $options = array() ) {
		$state = array(
			'raw'      => isset( $options['raw'] ) && is_array( $options['raw'] ) ? $options['raw'] : array(),
			'resolver' => isset( $options['resolver'] ) && is_callable( $options['resolver'] ) ? $options['resolver'] : null,
			'warnings' => array(),
			'used'     => array(),
		);

		$tree = self::parse( (string) $template, $state['warnings'] );
		$html = self::render_nodes( $tree, array( $context ), $state, 0 );

		return array(
			'html'     => $html,
			'warnings' => array_values( array_unique( $state['warnings'] ) ),
			'used'     => array_keys( $state['used'] ),
		);
	}

	/**
	 * The placeholder names a template uses, sections included, in order.
	 *
	 * @param string $template Markup.
	 * @return string[]
	 */
	public static function names( $template ) {
		$names = array();
		if ( preg_match_all( '/\{\{\{\s*(' . self::NAME . ')\s*\}\}\}|\{\{\s*[#^\/]?\s*(' . self::NAME . ')\s*\}\}/', (string) $template, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL ) ) {
			foreach ( $m as $hit ) {
				$names[ (string) ( $hit[1] ?? $hit[2] ) ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * Read a template into a tree of text, values and sections.
	 *
	 * Anything that is not a well-formed placeholder - a lone "{{", a name with
	 * a space in it - is text, written out as it stands.
	 *
	 * @param string   $template Markup.
	 * @param string[] $warnings Filled with what was wrong with it.
	 * @return array
	 */
	private static function parse( $template, array &$warnings ) {
		$pattern = '/\{\{\{\s*(' . self::NAME . ')\s*\}\}\}|\{\{\s*([#^\/]?)\s*(' . self::NAME . ')\s*\}\}/';
		$root    = array( 'children' => array() );
		$stack   = array( &$root );
		$offset  = 0;

		if ( preg_match_all( $pattern, $template, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches as $match ) {
				$at  = $match[0][1];
				$top = count( $stack ) - 1;

				if ( $at > $offset ) {
					$stack[ $top ]['children'][] = array( 'type' => 'text', 'text' => substr( $template, $offset, $at - $offset ) );
				}
				$offset = $at + strlen( $match[0][0] );

				// {{{x}}}: its group took part in the match (an offset, not -1).
				if ( isset( $match[1] ) && -1 !== $match[1][1] ) {
					$stack[ $top ]['children'][] = array( 'type' => 'raw', 'name' => $match[1][0] );
					continue;
				}

				$sigil = $match[2][0];
				$name  = $match[3][0];

				if ( '' === $sigil ) {
					$stack[ $top ]['children'][] = array( 'type' => 'value', 'name' => $name );
				} elseif ( '#' === $sigil || '^' === $sigil ) {
					if ( count( $stack ) > self::MAX_DEPTH ) {
						$warnings[] = sprintf( 'Sections nest more than %d deep at {{%s%s}}; the rest of it was left out.', self::MAX_DEPTH, $sigil, $name );
						continue;
					}
					$stack[ $top ]['children'][] = array(
						'type'     => '#' === $sigil ? 'section' : 'inverted',
						'name'     => $name,
						'children' => array(),
					);
					$last           = count( $stack[ $top ]['children'] ) - 1;
					$stack[]        = &$stack[ $top ]['children'][ $last ];
				} elseif ( $top > 0 && $stack[ $top ]['name'] === $name ) {
					array_pop( $stack );
				} else {
					$warnings[] = sprintf( '{{/%s}} closes a section that is not open; it was left out.', $name );
				}
			}
		}

		$top = count( $stack ) - 1;
		if ( $offset < strlen( $template ) ) {
			$stack[ $top ]['children'][] = array( 'type' => 'text', 'text' => substr( $template, $offset ) );
		}

		for ( $i = $top; $i > 0; $i-- ) {
			$warnings[] = sprintf( '{{%s%s}} is never closed: it runs to the end of the template.', 'inverted' === $stack[ $i ]['type'] ? '^' : '#', $stack[ $i ]['name'] );
		}

		return $root['children'];
	}

	/**
	 * Write out a list of nodes.
	 *
	 * @param array $nodes  Nodes.
	 * @param array $frames Contexts, innermost last.
	 * @param array $state  Options and what has been said so far.
	 * @param int   $depth  How deep in sections this is.
	 * @return string
	 */
	private static function render_nodes( array $nodes, array $frames, array &$state, $depth ) {
		$out = '';

		foreach ( $nodes as $node ) {
			switch ( $node['type'] ) {
				case 'text':
					$out .= $node['text'];
					break;

				case 'value':
				case 'raw':
					list( $found, $value ) = self::lookup( $node['name'], $frames, $state );
					if ( ! $found ) {
						break;
					}
					if ( is_array( $value ) || is_object( $value ) ) {
						$state['warnings'][] = sprintf( '{{%s}} is a list or a section, not a value; it rendered empty.', $node['name'] );
						break;
					}
					$text = is_bool( $value ) ? '' : (string) $value;

					if ( 'raw' === $node['type'] && self::raw_allowed( $node['name'], $state['raw'] ) ) {
						$out .= $text;
					} else {
						if ( 'raw' === $node['type'] ) {
							$state['warnings'][] = sprintf( '{{{%1$s}}} is always escaped: only already-cleaned values can be written raw. Use {{%1$s}}.', $node['name'] );
						}
						$out .= self::escape( $text );
					}
					break;

				case 'section':
				case 'inverted':
					list( $found, $value ) = self::lookup( $node['name'], $frames, $state );
					$empty = ! $found || self::is_empty( $value );

					if ( 'inverted' === $node['type'] ) {
						if ( $empty ) {
							$out .= self::render_nodes( $node['children'], $frames, $state, $depth + 1 );
						}
						break;
					}

					if ( $empty ) {
						break;
					}

					if ( self::is_list( $value ) ) {
						foreach ( $value as $row ) {
							$inner   = $frames;
							$inner[] = is_array( $row ) ? $row : array( '.' => $row );
							$out    .= self::render_nodes( $node['children'], $inner, $state, $depth + 1 );
						}
					} else {
						$inner = $frames;
						if ( is_array( $value ) ) {
							$inner[] = $value;
						}
						$out .= self::render_nodes( $node['children'], $inner, $state, $depth + 1 );
					}
					break;
			}
		}

		return $out;
	}

	/**
	 * Find a name, innermost context first.
	 *
	 * @param string $name   Placeholder name.
	 * @param array  $frames Contexts, innermost last.
	 * @param array  $state  Options and what has been said so far.
	 * @return array{0: bool, 1: mixed}
	 */
	private static function lookup( $name, array $frames, array &$state ) {
		$state['used'][ $name ] = true;

		for ( $i = count( $frames ) - 1; $i >= 0; $i-- ) {
			if ( is_array( $frames[ $i ] ) && array_key_exists( $name, $frames[ $i ] ) ) {
				return array( true, $frames[ $i ][ $name ] );
			}
		}

		if ( null !== $state['resolver'] ) {
			$answer = call_user_func( $state['resolver'], $name );
			if ( is_array( $answer ) ) {
				if ( ! empty( $answer[0] ) ) {
					return array( true, $answer[1] ?? '' );
				}
				if ( ! empty( $answer[2] ) ) {
					$state['warnings'][] = (string) $answer[2];
					return array( false, null );
				}
			}
		}

		$state['warnings'][] = sprintf( 'Unknown placeholder {{%s}}: it rendered empty.', $name );
		return array( false, null );
	}

	/**
	 * Whether {{{name}}} may write this name unescaped.
	 *
	 * @param string   $name Placeholder name.
	 * @param string[] $raw  Names, or prefixes ending ".*".
	 * @return bool
	 */
	private static function raw_allowed( $name, array $raw ) {
		foreach ( $raw as $allowed ) {
			$allowed = (string) $allowed;
			if ( $allowed === $name ) {
				return true;
			}
			if ( '.*' === substr( $allowed, -2 ) && 0 === strpos( $name, substr( $allowed, 0, -1 ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Empty for a section: nothing, false, '' or an empty list. A "0" is a
	 * value - a grade, a count - and shows.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty( $value ) {
		return null === $value || false === $value || '' === $value || 0 === $value || ( is_array( $value ) && empty( $value ) );
	}

	/**
	 * A list of rows, for a loop, rather than one row for a section.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_list( $value ) {
		return is_array( $value ) && array() !== $value && array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * HTML-escape a value, as esc_html() does: an entity already in it is
	 * left as one, so "Pok&eacute;mon" stays what the shop typed.
	 *
	 * @param string $text Value.
	 * @return string
	 */
	public static function escape( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false );
	}
}
