<?php
/**
 * Structural checks for the admin view templates.
 *
 * A plain balance check is not enough. A missing </div> in the middle of a
 * document can be "balanced" by an extra one at the end: the counts match, but
 * every element after the gap is nested one level too deep. That is exactly how
 * the Push to eBay card ended up inside the Import Settings card, collapsing the
 * two-column settings grid into a single stacked column.
 *
 * So this verifies two things:
 *   1. Every <div> is closed, and none is closed too many times.
 *   2. Grid containers have the number of DIRECT card children they need —
 *      a CSS grid with one child renders as one column no matter what
 *      grid-template-columns says.
 *
 * Usage: php tools/check-views.php
 *
 * @package TCGiant_Sync
 */

$root  = dirname( __DIR__ );
$views = glob( $root . '/admin/views/*.php' );

// Grid class => minimum number of direct children it must contain.
//
// Counts children of any kind, not just cards: a column may legitimately be an
// unclassed <div> stacking several cards (the dashboard does this). What matters
// is that the grid has more than one item to lay out — with a single child it
// renders as one column regardless of grid-template-columns.
$grid_rules = array(
	'tc-row-2col' => 2,
	'tc-row-3col' => 2,
	'tc-top-bar'  => 2,
);

$failures = array();

foreach ( $views as $file ) {
	$name = basename( $file );
	$src  = file_get_contents( $file );

	// Strip <script> blocks: they build markup in strings, not real structure.
	$src = preg_replace( '#<script\b.*?</script>#is', '', $src );

	$stack = array();   // Open elements: ['line' => int, 'class' => string, 'children' => array]
	$grids = array();   // Completed grid containers.

	$offset = 0;
	if ( preg_match_all( '#<div\b[^>]*>|</div>#i', $src, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $match ) {
			list( $tag, $pos ) = $match;
			$line = substr_count( $src, "\n", 0, $pos ) + 1;

			if ( 0 === stripos( $tag, '</div' ) ) {
				if ( ! $stack ) {
					$failures[] = "{$name}:{$line}: unexpected </div> — more closes than opens";
					continue;
				}
				$closed = array_pop( $stack );

				// Record grid containers for the child-count check.
				foreach ( $grid_rules as $grid_class => $min ) {
					if ( in_array( $grid_class, $closed['class'], true ) ) {
						$grids[] = array(
							'class'    => $grid_class,
							'line'     => $closed['line'],
							'children' => $closed['children'],
							'min'      => $min,
						);
					}
				}

				// Attribute this element to its parent as a direct child.
				if ( $stack ) {
					$stack[ count( $stack ) - 1 ]['children'][] = $closed['class'];
				}
				continue;
			}

			$classes = array();
			if ( preg_match( '#class\s*=\s*"([^"]*)"#i', $tag, $cm ) ) {
				$classes = preg_split( '#\s+#', trim( $cm[1] ) );
			}
			$stack[] = array( 'line' => $line, 'class' => $classes, 'children' => array() );
		}
	}

	foreach ( $stack as $unclosed ) {
		$label = $unclosed['class'] ? '.' . implode( '.', $unclosed['class'] ) : '<div>';
		$failures[] = "{$name}:{$unclosed['line']}: unclosed {$label}";
	}

	foreach ( $grids as $grid ) {
		$children = count( $grid['children'] );
		if ( $children < $grid['min'] ) {
			$failures[] = sprintf(
				'%s:%d: .%s has %d direct child(ren), expected at least %d — '
				. 'a grid with one child renders as a single column, which usually '
				. 'means a </div> is missing and the columns nested into each other',
				$name,
				$grid['line'],
				$grid['class'],
				$children,
				$grid['min']
			);
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		echo 'FAIL ' . $failure . PHP_EOL;
	}
	echo PHP_EOL . count( $failures ) . ' structural problem(s) found.' . PHP_EOL;
	exit( 1 );
}

echo 'OK — ' . count( $views ) . ' view(s): markup balanced and grids populated.' . PHP_EOL;
exit( 0 );
