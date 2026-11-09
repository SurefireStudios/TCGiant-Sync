<?php
/**
 * Shop Categories Page
 *
 * Which eBay shop category each WooCommerce category goes in, for a push
 * (TCGiant_Sync_Shop_Categories).
 *
 * A shop can have hundreds of categories on each side, so no select carries the
 * list: each row has "None" and its own choice, the list is in the page once,
 * and a select is filled when it is about to be used. And the whole map goes in
 * one hidden field, as JSON: PHP's max_input_vars silently cuts a large form,
 * and a cut form would lose mappings. Every row goes, None as "", so a row the
 * page never drew keeps what it has. With no JavaScript the field stays empty,
 * and Save refuses it and keeps the map as it was.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 * @since   3.33.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_screen  = TCGiant_Sync_Shop_Categories::screen();
$tc_refresh = wp_nonce_url( admin_url( 'admin-post.php?action=' . TCGiant_Sync_Shop_Categories::REFRESH_LINK ), TCGiant_Sync_Shop_Categories::REFRESH_LINK );

$tc_problems = array(
	'gone'   => __( 'Your eBay shop no longer has this category, so listings go without it. Choose another.', 'tcgiant-sync' ),
	'parent' => __( 'This category now has subcategories, and eBay would put the listing in Other, so listings go without it. Choose one inside it.', 'tcgiant-sync' ),
	'other'  => __( 'eBay\'s Other cannot be chosen, so listings go without it. Choose another.', 'tcgiant-sync' ),
	'held'   => __( 'eBay refused a listing for this category within the last hour, so listings go without it until then. Save this page, or press Refresh from eBay, to try it again now.', 'tcgiant-sync' ),
);
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1><?php esc_html_e( 'Shop Categories', 'tcgiant-sync' ); ?></h1>
		<p class="tc-subtitle"><?php esc_html_e( 'Choose which category of your eBay shop each WooCommerce category goes in when you push to eBay.', 'tcgiant-sync' ); ?></p>
	</div>

	<?php TCGiant_Sync_Admin::instance()->render_tabs( 'shop_categories' ); ?>

	<?php TCGiant_Sync_Shop_Categories::show_notice(); ?>

	<?php if ( 'not_connected' === $tc_screen['state'] ) : ?>
		<div class="tc-card">
			<h2><?php esc_html_e( 'eBay Not Connected', 'tcgiant-sync' ); ?></h2>
			<p><?php esc_html_e( 'Connect your eBay account first: this page lists the categories of your eBay shop.', 'tcgiant-sync' ); ?></p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=tcgiant-settings' ) ); ?>" class="tc-button secondary"><?php esc_html_e( 'Go to Settings', 'tcgiant-sync' ); ?></a>
		</div>
	<?php elseif ( 'ready' !== $tc_screen['state'] ) : ?>
		<div class="tc-card">
			<?php if ( 'failed' === $tc_screen['state'] ) : ?>
				<h2><?php esc_html_e( 'Your eBay shop\'s categories could not be read', 'tcgiant-sync' ); ?></h2>
				<p>
					<?php
					/* translators: %s: eBay's own message */
					echo esc_html( sprintf( __( 'eBay said: %s', 'tcgiant-sync' ), $tc_screen['error'] ) );
					?>
				</p>
				<p><?php esc_html_e( 'Try again in a minute. Your choices are as they were, and pushes go on sending them.', 'tcgiant-sync' ); ?></p>
			<?php elseif ( 'no_terms' === $tc_screen['state'] ) : ?>
				<h2><?php esc_html_e( 'No product categories to show', 'tcgiant-sync' ); ?></h2>
				<p><?php esc_html_e( 'WordPress listed no product categories, so there is nothing to choose here. Anything already chosen stays as it was.', 'tcgiant-sync' ); ?></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'No eBay shop categories', 'tcgiant-sync' ); ?></h2>
				<p><?php esc_html_e( 'Your eBay account has no Store, or its Store has no categories of its own, so there is nothing to choose here, and pushes send no shop category.', 'tcgiant-sync' ); ?></p>
			<?php endif; ?>
			<a href="<?php echo esc_url( $tc_refresh ); ?>" class="tc-button secondary"><?php esc_html_e( 'Refresh from eBay', 'tcgiant-sync' ); ?></a>
			<?php if ( $tc_screen['has_map'] ) : ?>
				<form method="post" class="tc-shop-cat-stop" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm( '<?php echo esc_js( __( 'Clear every choice on this page? With Overwrite Categories & Tags on, a sync can then replace the website categories of a pushed listing.', 'tcgiant-sync' ) ); ?>' );">
					<input type="hidden" name="action" value="<?php echo esc_attr( TCGiant_Sync_Shop_Categories::CLEAR_FORM ); ?>">
					<?php wp_nonce_field( TCGiant_Sync_Shop_Categories::CLEAR_FORM ); ?>
					<p class="tc-section-desc"><?php esc_html_e( 'Your choices are still saved. To clear them all, so that a push sends no shop category:', 'tcgiant-sync' ); ?></p>
					<button type="submit" class="tc-button danger"><?php esc_html_e( 'Stop sending these choices', 'tcgiant-sync' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<?php
		$tc_options = array();
		foreach ( $tc_screen['options'] as $tc_option ) {
			$tc_options[] = array(
				'id'       => $tc_option['id'],
				'label'    => $tc_option['disabled']
					/* translators: %s: a shop category's full path */
					? sprintf( __( '%s (has subcategories: eBay would put the listing in Other)', 'tcgiant-sync' ), $tc_option['path'] )
					: $tc_option['path'],
				'disabled' => $tc_option['disabled'],
			);
		}
		$tc_data = array(
			'options'     => $tc_options,
			'suggestions' => (object) $tc_screen['suggestions'],
			'words'       => array(
				/* translators: 1: how many rows were filled, 2: how many are still empty */
				'matched' => __( 'Match by name filled %1$s and left %2$s empty. Nothing is saved until you press Save.', 'tcgiant-sync' ),
				'leave'   => __( 'Your changes on this page are not saved yet. Read the categories from eBay anyway?', 'tcgiant-sync' ),
			),
		);
		?>
		<div class="tc-card">
			<h2><span class="dashicons dashicons-category"></span> <?php esc_html_e( 'Your categories and your eBay shop\'s', 'tcgiant-sync' ); ?></h2>
			<p class="tc-section-desc"><?php esc_html_e( 'An Update moves a pushed listing to the mapped category, even if you moved it on eBay by hand; an imported listing that keeps eBay\'s values stays where it is. Listings already on eBay move at their next Update, so to move them all, select them and choose Push to eBay: each one is a full Update, and uses a call of your day\'s allowance.', 'tcgiant-sync' ); ?></p>
			<p class="tc-section-desc"><?php esc_html_e( 'A choice covers the categories inside it, unless one of them has a choice of its own. A product in several goes in two at most, the deepest first. Once anything is mapped here, a new listing imported from eBay also goes by the full path of its shop category, so two shop categories of one name stay apart. With Overwrite Categories & Tags on, an existing imported product also moves to a category at that exact path, if you have one, and a sync can replace the website categories of a pushed listing whose row is set to None.', 'tcgiant-sync' ); ?></p>

			<form method="post" id="tc-shop-cat-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( TCGiant_Sync_Shop_Categories::SAVE_FORM ); ?>">
				<input type="hidden" name="<?php echo esc_attr( TCGiant_Sync_Shop_Categories::MAP_FIELD ); ?>" id="tc-shop-cat-map" value="">
				<?php wp_nonce_field( TCGiant_Sync_Shop_Categories::SAVE_FORM ); ?>

				<div class="tc-shop-cat-actions">
					<button type="submit" class="tc-button"><?php esc_html_e( 'Save', 'tcgiant-sync' ); ?></button>
					<button type="button" class="tc-button secondary" id="tc-shop-cat-match"><?php esc_html_e( 'Match by name', 'tcgiant-sync' ); ?></button>
					<a href="<?php echo esc_url( $tc_refresh ); ?>" class="tc-button secondary" id="tc-shop-cat-refresh"><?php esc_html_e( 'Refresh from eBay', 'tcgiant-sync' ); ?></a>
					<span id="tc-shop-cat-status" class="tc-section-desc" aria-live="polite"></span>
				</div>

				<table class="widefat striped tc-shop-cat-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WooCommerce category', 'tcgiant-sync' ); ?></th>
							<th><?php esc_html_e( 'eBay shop category', 'tcgiant-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tc_screen['rows'] as $tc_row ) : ?>
							<tr>
								<td><label for="tc-shop-cat-<?php echo esc_attr( (string) $tc_row['term_id'] ); ?>"><?php echo esc_html( $tc_row['path'] ); ?></label></td>
								<td>
									<select class="tc-select" id="tc-shop-cat-<?php echo esc_attr( (string) $tc_row['term_id'] ); ?>" data-term="<?php echo esc_attr( (string) $tc_row['term_id'] ); ?>">
										<option value=""><?php esc_html_e( 'None', 'tcgiant-sync' ); ?></option>
										<?php if ( '' !== $tc_row['choice'] ) : ?>
											<option value="<?php echo esc_attr( $tc_row['choice'] ); ?>" selected><?php echo esc_html( $tc_row['label'] ); ?></option>
										<?php endif; ?>
									</select>
									<?php if ( '' !== $tc_row['problem'] ) : ?>
										<p class="tc-hint tc-shop-cat-problem"><?php echo esc_html( $tc_problems[ $tc_row['problem'] ] ?? '' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p><button type="submit" class="tc-button"><?php esc_html_e( 'Save', 'tcgiant-sync' ); ?></button></p>
			</form>
		</div>

		<script type="application/json" id="tc-shop-cat-data"><?php echo wp_json_encode( $tc_data, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON, its tags and ampersands hexed; read back with JSON.parse(). ?></script>
		<script>
		( function () {
			var form   = document.getElementById( 'tc-shop-cat-form' );
			var holder = document.getElementById( 'tc-shop-cat-data' );
			if ( ! form || ! holder ) {
				return;
			}

			var data    = JSON.parse( holder.textContent );
			var field   = document.getElementById( 'tc-shop-cat-map' );
			var status  = document.getElementById( 'tc-shop-cat-status' );
			var selects = Array.prototype.slice.call( form.querySelectorAll( 'select[data-term]' ) );
			var byId    = {};
			var changed = false;

			data.options.forEach( function ( option ) {
				byId[ option.id ] = option;
			} );

			function optionFor( option ) {
				var element = document.createElement( 'option' );
				element.value       = option.id;
				element.textContent = option.label;
				element.disabled    = option.disabled;
				return element;
			}

			// The list, once, behind the choice the row already shows: that
			// one stays where it is, even if the list no longer has it.
			function fill( select ) {
				if ( select.hasAttribute( 'data-filled' ) ) {
					return;
				}
				select.setAttribute( 'data-filled', '1' );

				var current = select.value;
				var list    = document.createDocumentFragment();
				data.options.forEach( function ( option ) {
					if ( option.id !== current ) {
						list.appendChild( optionFor( option ) );
					}
				} );
				select.appendChild( list );
			}

			function choose( select, id ) {
				if ( ! select.hasAttribute( 'data-filled' ) ) {
					select.appendChild( optionFor( byId[ id ] ) );
				}
				select.value = id;
			}

			[ 'focusin', 'mousedown', 'touchstart' ].forEach( function ( type ) {
				form.addEventListener( type, function ( event ) {
					if ( selects.indexOf( event.target ) > -1 ) {
						fill( event.target );
					}
				} );
			} );

			form.addEventListener( 'change', function () {
				changed = true;
			} );

			document.getElementById( 'tc-shop-cat-match' ).addEventListener( 'click', function () {
				var filled = 0;
				var left   = 0;

				selects.forEach( function ( select ) {
					if ( select.value ) {
						return;
					}
					var id = data.suggestions[ select.getAttribute( 'data-term' ) ];
					if ( id && byId[ id ] ) {
						choose( select, id );
						filled++;
					} else {
						left++;
					}
				} );

				changed = changed || filled > 0;
				status.textContent = data.words.matched.replace( '%1$s', filled ).replace( '%2$s', left );
			} );

			document.getElementById( 'tc-shop-cat-refresh' ).addEventListener( 'click', function ( event ) {
				if ( changed && ! window.confirm( data.words.leave ) ) {
					event.preventDefault();
				}
			} );

			form.addEventListener( 'submit', function () {
				var map = {};

				// Every row, None as "": a row not sent keeps what it has.
				selects.forEach( function ( select ) {
					map[ select.getAttribute( 'data-term' ) ] = select.value;
				} );

				field.value = JSON.stringify( map );
			} );
		}() );
		</script>
	<?php endif; ?>
</div>
