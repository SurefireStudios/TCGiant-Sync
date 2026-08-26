<?php
/**
 * Check that the in-page tab bar and the WordPress admin menu agree.
 *
 * These are two separate lists of the same pages, written in two places, and
 * nothing tied them together. Stock Review was added to the menu and not to
 * the tabs, so for several releases the sidebar and the row of tabs beside it
 * disagreed about what the plugin contains — and the pages left out of the
 * tabs had no way back to the others.
 *
 * Checks three things:
 *   1. Every menu page appears as a tab, and vice versa.
 *   2. They are in the same order, because two orders is its own confusion.
 *   3. Every render_tabs( 'key' ) call names a tab that exists, or the page
 *      renders with nothing highlighted.
 *
 * @package TCGiant_Sync
 */

$root  = dirname( __DIR__ );
$admin = $root . '/admin/class-tcgiant-sync-admin.php';

if ( ! is_readable( $admin ) ) {
	fwrite( STDERR, "check-tabs: cannot read admin class\n" );
	exit( 1 );
}

$src = file_get_contents( $admin );

/**
 * Pull one function's body out by brace depth.
 */
function tcg_function_body( $src, $name ) {
	if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return '';
	}
	$start = $m[0][1] + strlen( $m[0][0] ) - 1;
	$depth = 0;
	for ( $i = $start, $len = strlen( $src ); $i < $len; $i++ ) {
		if ( '{' === $src[ $i ] ) {
			$depth++;
		} elseif ( '}' === $src[ $i ] ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $src, $start, $i - $start + 1 );
			}
		}
	}
	return '';
}

/**
 * Every argument list for one function call, as raw strings.
 *
 * Regex cannot do this: the arguments contain __( 'Text', 'domain' ), whose
 * commas and brackets are not the call's own.
 */
function tcg_call_args( $src, $function ) {
	$calls  = array();
	$needle = $function . '(';
	$offset = 0;

	while ( false !== ( $pos = strpos( $src, $needle, $offset ) ) ) {
		$i     = $pos + strlen( $needle );
		$depth = 1;
		$start = $i;
		$len   = strlen( $src );

		for ( ; $i < $len && $depth > 0; $i++ ) {
			$ch = $src[ $i ];
			if ( "'" === $ch || '"' === $ch ) {
				// Skip the string whole, so brackets inside it are ignored.
				$quote = $ch;
				for ( $i++; $i < $len; $i++ ) {
					if ( '\\' === $src[ $i ] ) {
						$i++;
					} elseif ( $src[ $i ] === $quote ) {
						break;
					}
				}
			} elseif ( '(' === $ch ) {
				$depth++;
			} elseif ( ')' === $ch ) {
				$depth--;
			}
		}

		$calls[] = tcg_split_args( substr( $src, $start, $i - $start - 1 ) );
		$offset  = $i;
	}

	return $calls;
}

/**
 * Split an argument list on its own commas only.
 */
function tcg_split_args( $inner ) {
	$args    = array();
	$current = '';
	$depth   = 0;

	for ( $i = 0, $len = strlen( $inner ); $i < $len; $i++ ) {
		$ch = $inner[ $i ];

		if ( "'" === $ch || '"' === $ch ) {
			$quote    = $ch;
			$current .= $ch;
			for ( $i++; $i < $len; $i++ ) {
				$current .= $inner[ $i ];
				if ( '\\' === $inner[ $i ] && $i + 1 < $len ) {
					$current .= $inner[ ++$i ];
				} elseif ( $inner[ $i ] === $quote ) {
					break;
				}
			}
			continue;
		}

		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
		} elseif ( ')' === $ch || ']' === $ch ) {
			$depth--;
		}

		if ( ',' === $ch && 0 === $depth ) {
			$args[]  = trim( $current );
			$current = '';
			continue;
		}

		$current .= $ch;
	}

	if ( '' !== trim( $current ) ) {
		$args[] = trim( $current );
	}

	return $args;
}

/** A quoted literal, or '' if the argument is an expression. */
function tcg_literal( $arg ) {
	return preg_match( "/^'([^']*)'$/", trim( $arg ), $m ) ? $m[1] : '';
}

// ---- the menu ---------------------------------------------------------------
$menu_body = tcg_function_body( $src, 'add_menu_pages' );
$menu      = array();

foreach ( tcg_call_args( $menu_body, 'add_submenu_page' ) as $args ) {
	if ( count( $args ) < 5 ) {
		continue;
	}

	// The setup wizard is deliberately hidden: it passes an empty parent and
	// is reachable only by URL, so it is not expected in the tab bar.
	if ( '' === tcg_literal( $args[0] ) ) {
		continue;
	}

	$slug = tcg_literal( $args[4] );

	// The first submenu repeats the top-level slug, which is how WordPress
	// gets a sensible name onto the first item. One tab covers both.
	if ( '' !== $slug && ! in_array( $slug, $menu, true ) ) {
		$menu[] = $slug;
	}
}

// ---- the tabs ---------------------------------------------------------------
$tabs_body = tcg_function_body( $src, 'render_tabs' );
$tabs      = array();
$tab_keys  = array();

// Lazy, so each entry stops at its own 'page' rather than running into the next.
preg_match_all( "/'([a-z_]+)'\s*=>\s*array\(.*?'page'\s*=>\s*'([a-z0-9\-]+)'/s", $tabs_body, $found, PREG_SET_ORDER );

foreach ( $found as $entry ) {
	$tab_keys[] = $entry[1];
	$tabs[]     = $entry[2];
}

// ---- compare ----------------------------------------------------------------
$problems = array();

if ( empty( $menu ) || empty( $tabs ) ) {
	$problems[] = 'could not read the menu or the tabs — the parser needs updating';
} else {
	foreach ( array_diff( $menu, $tabs ) as $missing ) {
		$problems[] = sprintf( 'page "%s" is in the admin menu but has no tab', $missing );
	}

	foreach ( array_diff( $tabs, $menu ) as $extra ) {
		$problems[] = sprintf( 'tab "%s" is not in the admin menu', $extra );
	}

	if ( ! $problems && $menu !== $tabs ) {
		$problems[] = sprintf(
			"the tabs and the menu are in different orders\n     menu: %s\n     tabs: %s",
			implode( ', ', $menu ),
			implode( ', ', $tabs )
		);
	}
}

// ---- every view asks for a tab that exists ----------------------------------
if ( $tab_keys ) {
	foreach ( glob( $root . '/admin/views/*.php' ) as $view ) {
		$body = file_get_contents( $view );

		if ( ! preg_match_all( "/render_tabs\(\s*'([a-z_]+)'\s*\)/", $body, $calls ) ) {
			continue;
		}

		foreach ( $calls[1] as $key ) {
			if ( ! in_array( $key, $tab_keys, true ) ) {
				$problems[] = sprintf(
					'%s asks for tab "%s", which does not exist — the page would render with nothing highlighted',
					basename( $view ),
					$key
				);
			}
		}
	}
}

if ( $problems ) {
	fwrite( STDERR, "check-tabs: the tab bar and the admin menu disagree.\n" );
	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  - ' . $problem . "\n" );
	}
	exit( 1 );
}

printf( "OK - tab bar matches the admin menu (%d page(s), same order).\n", count( $menu ) );
exit( 0 );
