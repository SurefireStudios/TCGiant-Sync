<?php
/**
 * Check that no hook registration has gone missing.
 *
 * Usage:  php tools/check-hooks.php            compare against tools/hooks.txt
 *         php tools/check-hooks.php --update   record the current set
 *
 * This exists for one job: the plugin is about to be split into three editions,
 * which means moving whole groups of registrations between classes. The cron
 * class becomes Cron plus Scheduler; the inventory class becomes Inventory plus
 * Inventory_Push. Those two files own the stock-sync hooks.
 *
 * If one add_action is left behind in that move, nothing complains. There is no
 * fatal, no warning, no log line — stock simply stops flowing to eBay, and the
 * merchant finds out by overselling. That is strictly worse than a crash,
 * because a crash is loud and this is silent.
 *
 * So the set of registrations is recorded before a refactor and compared after.
 * "Did I move every one of them" stops being a matter of reading carefully and
 * becomes an assertion.
 *
 * What is recorded is the pair "hook => callback method", deliberately WITHOUT
 * the class or file it lives in. Moving a registration from one class to
 * another is the entire point of the refactor and must not fail this check;
 * losing one must.
 *
 * @package TCGiant_Sync
 */

$root   = dirname( __DIR__ );
$store  = __DIR__ . '/hooks.txt';
$update = in_array( '--update', $argv, true );

/**
 * Every plugin PHP file worth scanning.
 *
 * @param string $root Plugin root.
 * @return string[]
 */
function tcg_hook_sources( $root ) {
	$files = array();

	foreach ( array( 'includes', 'admin' ) as $dir ) {
		$base = $root . '/' . $dir;
		if ( ! is_dir( $base ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			$path = str_replace( '\\', '/', $file->getPathname() );
			// The vendored update library registers its own hooks; not ours.
			if ( false !== strpos( $path, '/plugin-update-checker/' ) ) {
				continue;
			}
			$files[] = $path;
		}
	}

	sort( $files );
	return $files;
}

/**
 * Read the argument list of a call, given the offset of its opening paren.
 *
 * Splits on top-level commas only, so array( $this, 'method' ) stays whole.
 *
 * @param string $src Source.
 * @param int    $open Offset of the '('.
 * @return string[]|null
 */
function tcg_call_args( $src, $open ) {
	$depth  = 0;
	$len    = strlen( $src );
	$args   = array();
	$buf    = '';
	$quote  = '';

	for ( $i = $open; $i < $len; $i++ ) {
		$ch = $src[ $i ];

		if ( '' !== $quote ) {
			$buf .= $ch;
			if ( $ch === $quote && '\\' !== $src[ $i - 1 ] ) {
				$quote = '';
			}
			continue;
		}

		if ( "'" === $ch || '"' === $ch ) {
			$quote = $ch;
			$buf  .= $ch;
			continue;
		}

		if ( '(' === $ch || '[' === $ch ) {
			$depth++;
			if ( 1 === $depth && '(' === $ch ) {
				continue; // Skip the opening paren itself.
			}
			$buf .= $ch;
			continue;
		}

		if ( ')' === $ch || ']' === $ch ) {
			$depth--;
			if ( 0 === $depth ) {
				$args[] = trim( $buf );
				return $args;
			}
			$buf .= $ch;
			continue;
		}

		if ( ',' === $ch && 1 === $depth ) {
			$args[] = trim( $buf );
			$buf    = '';
			continue;
		}

		$buf .= $ch;
	}

	return null; // Unbalanced; caller decides.
}

/**
 * Turn an argument expression into a stable name.
 *
 * @param string   $expr      Raw PHP expression.
 * @param string[] $constants Map of CONST_NAME => literal value.
 * @return string
 */
function tcg_resolve( $expr, array $constants ) {
	$expr = trim( $expr );

	// 'literal'
	if ( preg_match( '/^([\'"])(.*)\1$/s', $expr, $m ) ) {
		return $m[2];
	}

	// self::FOO / static::FOO / Some_Class::FOO
	if ( preg_match( '/(?:^|::)([A-Z][A-Z0-9_]*)$/', $expr, $m ) ) {
		if ( isset( $constants[ $m[1] ] ) ) {
			return $constants[ $m[1] ];
		}
		return '{' . $m[1] . '}';
	}

	// array( $this, 'method' ) or array( __CLASS__, 'method' )
	if ( preg_match( '/[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)?\s*$/', $expr, $m )
		&& 0 === strpos( $expr, 'array' ) ) {
		return $m[1];
	}

	if ( 0 === strpos( $expr, 'function' ) || 0 === strpos( $expr, 'fn' ) || false !== strpos( $expr, '=>' ) ) {
		return '<closure>';
	}

	// Bare 'function_name' handled above; anything else, keep it recognisable
	// but normalised so whitespace changes do not churn the list.
	return '{' . preg_replace( '/\s+/', ' ', $expr ) . '}';
}

/**
 * Remove comments, keeping line count so offsets stay meaningful.
 *
 * Prose is not code. The importer's docblock explains why it "uses
 * wp_schedule_single_event()", and a scanner that reads that as a registration
 * is inventing hooks that do not exist — which is the same class of mistake as
 * missing one, pointed the other way. The tokenizer knows the difference; a
 * regex over raw source never will.
 *
 * @param string $src PHP source.
 * @return string
 */
function tcg_strip_comments( $src ) {
	$out = '';

	foreach ( token_get_all( $src ) as $token ) {
		if ( ! is_array( $token ) ) {
			$out .= $token;
			continue;
		}

		if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
			$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
			continue;
		}

		$out .= $token[1];
	}

	return $out;
}

$files     = tcg_hook_sources( $root );
$constants = array();
$sources   = array();

foreach ( $files as $file ) {
	$src              = tcg_strip_comments( file_get_contents( $file ) );
	$sources[ $file ] = $src;

	if ( preg_match_all( '/const\s+([A-Z][A-Z0-9_]*)\s*=\s*([\'"])(.*?)\2\s*;/', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $hit ) {
			$constants[ $hit[1] ] = $hit[3];
		}
	}
}

$registrations = array();
$problems      = array();

// Hook registrations: what the plugin binds to, and with what.
$binders = array( 'add_action', 'add_filter' );

/*
 * Schedule calls: the hook name is what matters, not a callback.
 *
 * Which argument holds the hook differs per function, and guessing at it does
 * not work. "The first argument that looks like one of ours" recorded the
 * interval 'tcgiant_15mins' from wp_schedule_event( $time, 'tcgiant_15mins',
 * 'tcgiant_sync_import_orders' ) and missed the hook entirely — a tool meant to
 * catch a missing registration, quietly missing one.
 *
 * Positions, from the WordPress and Action Scheduler signatures.
 */
$schedulers = array(
	'wp_schedule_event'            => 2,  // ( $timestamp, $recurrence, $hook )
	'wp_schedule_single_event'     => 1,  // ( $timestamp, $hook )
	'as_schedule_single_action'    => 1,  // ( $timestamp, $hook, $args, $group )
	'as_enqueue_async_action'      => 0,  // ( $hook, $args, $group )
	'as_schedule_recurring_action' => 2,  // ( $timestamp, $interval, $hook )
);

foreach ( $sources as $file => $src ) {
	$rel = str_replace( str_replace( '\\', '/', $root ) . '/', '', $file );

	foreach ( $binders as $fn ) {
		$offset = 0;
		while ( false !== ( $at = strpos( $src, $fn . '(', $offset ) ) ) {
			$offset = $at + 1;

			// Skip function_exists( 'add_action' ) and the like.
			$before = substr( $src, max( 0, $at - 1 ), 1 );
			if ( '_' === $before || preg_match( '/[A-Za-z0-9]/', $before ) ) {
				continue;
			}

			$args = tcg_call_args( $src, $at + strlen( $fn ) );
			if ( null === $args || count( $args ) < 2 ) {
				$problems[] = sprintf( '%s: could not read a %s() call', $rel, $fn );
				continue;
			}

			$hook     = tcg_resolve( $args[0], $constants );
			$callback = tcg_resolve( $args[1], $constants );

			$registrations[] = sprintf( '%-12s %s => %s', $fn, $hook, $callback );
		}
	}

	foreach ( $schedulers as $fn => $hook_index ) {
		$offset = 0;
		while ( false !== ( $at = strpos( $src, $fn . '(', $offset ) ) ) {
			$offset = $at + 1;

			$before = substr( $src, max( 0, $at - 1 ), 1 );
			if ( '_' === $before || preg_match( '/[A-Za-z0-9]/', $before ) ) {
				continue;
			}

			$args = tcg_call_args( $src, $at + strlen( $fn ) );
			if ( null === $args ) {
				$problems[] = sprintf( '%s: could not read a %s() call', $rel, $fn );
				continue;
			}

			if ( ! isset( $args[ $hook_index ] ) ) {
				$problems[] = sprintf( '%s: %s() has no argument %d', $rel, $fn, $hook_index );
				continue;
			}

			$hook = tcg_resolve( $args[ $hook_index ], $constants );

			if ( 0 !== strpos( $hook, 'tcgiant' ) ) {
				$problems[] = sprintf(
					'%s: %s() argument %d is "%s", which is not a hook name this plugin owns',
					$rel,
					$fn,
					$hook_index,
					$hook
				);
				continue;
			}

			$registrations[] = sprintf( '%-12s %s', 'schedule', $hook );
		}
	}
}

$registrations = array_values( array_unique( $registrations ) );
sort( $registrations );

if ( $problems ) {
	fwrite( STDERR, "check-hooks: could not read every registration.\n" );
	foreach ( array_unique( $problems ) as $problem ) {
		fwrite( STDERR, '  - ' . $problem . "\n" );
	}
	fwrite( STDERR, "  A registration this tool cannot see is one it cannot protect.\n" );
	exit( 1 );
}

$body = implode( "\n", $registrations ) . "\n";

if ( $update ) {
	$header = "# Every hook this plugin registers, and the callback it registers.\n"
		. "# Generated by tools/check-hooks.php --update. Commit changes deliberately.\n"
		. "#\n"
		. "# The class and file are deliberately absent: moving a registration between\n"
		. "# classes must not fail the check, losing one must.\n\n";
	file_put_contents( $store, $header . $body );
	printf( "Recorded %d registration(s) in tools/hooks.txt.\n", count( $registrations ) );
	exit( 0 );
}

if ( ! is_readable( $store ) ) {
	fwrite( STDERR, "check-hooks: no tools/hooks.txt yet.\n" );
	fwrite( STDERR, "  Run: php tools/check-hooks.php --update\n" );
	fwrite( STDERR, "  Do that BEFORE a refactor, not after, or it records the damage.\n" );
	exit( 1 );
}

$expected = array_values( array_filter(
	array_map( 'rtrim', file( $store ) ),
	function ( $line ) {
		return '' !== $line && '#' !== substr( $line, 0, 1 );
	}
) );

$missing = array_values( array_diff( $expected, $registrations ) );
$added   = array_values( array_diff( $registrations, $expected ) );

if ( empty( $missing ) && empty( $added ) ) {
	printf( "OK - all %d hook registration(s) still present.\n", count( $registrations ) );
	exit( 0 );
}

fwrite( STDERR, "check-hooks: the set of hook registrations has changed.\n" );

if ( $missing ) {
	fwrite( STDERR, sprintf( "\n  GONE (%d) — each of these silently stops working:\n", count( $missing ) ) );
	foreach ( $missing as $line ) {
		fwrite( STDERR, '    - ' . $line . "\n" );
	}
}

if ( $added ) {
	fwrite( STDERR, sprintf( "\n  NEW (%d):\n", count( $added ) ) );
	foreach ( $added as $line ) {
		fwrite( STDERR, '    + ' . $line . "\n" );
	}
}

fwrite( STDERR, "\n  If every change above is intended, run:\n" );
fwrite( STDERR, "    php tools/check-hooks.php --update\n" );
fwrite( STDERR, "  Read the GONE list first. That is the one that costs a merchant money.\n" );

exit( 1 );
