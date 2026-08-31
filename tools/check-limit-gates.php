<?php
/**
 * Check that the free limit never abandons a sync at the door.
 *
 * The limit caps how many products a store may hold. It is not a reason to
 * stop updating the ones it already has — stock, prices and ended listings all
 * have to keep flowing however far over a store is, or it carries on selling
 * from figures that quietly stopped being true.
 *
 * 3.7.12 fixed the per-item checks inside the import loops and claimed the
 * problem solved. It was not: all four ways INTO those loops still returned
 * early on the same condition, so the loops that had been fixed were never
 * reached and nothing changed for any affected store. The fix and the fault
 * lived in the same file, a thousand lines apart.
 *
 * So: in the functions that BEGIN a sync, a licence check may log and it may
 * record status, but it may not return. Skipping a single item is a different
 * matter and is left alone — process_item_import() handles one listing, so
 * returning there declines that listing rather than the run.
 *
 * @package TCGiant_Sync
 */

$root = dirname( __DIR__ );
$file = $root . '/includes/class-tcgiant-sync-importer.php';

if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "check-limit-gates: cannot read the importer\n" );
	exit( 1 );
}

$src = file_get_contents( $file );

/** Functions that start a run. Abandoning one strands every product it covers. */
$entry_points = array(
	'start_full_sync',
	'start_delta_sync',
	'start_specific_sync',
	'resume_sync',
);

/**
 * Pull one function's body out by brace depth.
 */
function tcg_body( $src, $name ) {
	if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
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
	return null;
}

$problems = array();

foreach ( $entry_points as $name ) {
	$body = tcg_body( $src, $name );

	if ( null === $body ) {
		$problems[] = sprintf( '%s() not found — this check needs updating', $name );
		continue;
	}

	// Every licence check in this function, with the block it guards.
	$offset = 0;

	while ( false !== ( $at = strpos( $body, 'can_import()', $offset ) ) ) {
		$offset = $at + 1;

		$brace = strpos( $body, '{', $at );
		if ( false === $brace ) {
			continue;
		}

		// Walk to the end of the block it opens.
		$depth = 0;
		$end   = strlen( $body );

		for ( $i = $brace, $len = strlen( $body ); $i < $len; $i++ ) {
			if ( '{' === $body[ $i ] ) {
				$depth++;
			} elseif ( '}' === $body[ $i ] ) {
				$depth--;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			}
		}

		$block = substr( $body, $brace, $end - $brace );

		if ( preg_match( '/\breturn\b/', $block ) ) {
			$problems[] = sprintf(
				"%s() abandons the run when the free limit is reached.\n"
				. "     The limit holds back NEW listings; it must not stop products already\n"
				. "     imported from being updated. Log it, record the status, but carry on —\n"
				. "     the per-item checks inside the loops do the actual holding back.",
				$name
			);
		}
	}
}

if ( $problems ) {
	fwrite( STDERR, "check-limit-gates: the free limit is stopping more than it should.\n" );
	foreach ( $problems as $problem ) {
		fwrite( STDERR, '  - ' . $problem . "\n" );
	}
	exit( 1 );
}

printf( "OK - free limit holds back new listings only (%d entry point(s) checked).\n", count( $entry_points ) );
exit( 0 );
