<?php
/**
 * Harness: the per-product condition, and eBay's category refusal.
 *
 * The condition control on the product screen existed until v1.5.1, when the
 * grading rewrite replaced it and nothing put it back. v1.7.5 then added the
 * "All Other" item type, whose condition is sent to eBay as a plain
 * ConditionID and nothing else - so from that release onwards a shop selling
 * anything but cards and coins had one condition for its entire catalogue, set
 * in Settings, with no way to say that this switch is used and that one is new.
 * A networking retailer told us their used equipment would have gone up
 * described as New.
 *
 * It was worse than absent. The Push and Verify buttons never stopped posting
 * the field, so with nothing rendering it they sent an empty string and wrote
 * it over whatever was stored.
 *
 * Most of this file reads the plugin's own source, because that is the only
 * thing that catches a control being removed again. A missing control throws no
 * error, fails no check, and looks exactly like a design decision.
 *
 * @package TCGiant_Sync
 */

$pass = 0;
$fail = 0;

function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = ( $got === $want );
	if ( $ok ) { $pass++; } else { $fail++; }
	printf( "  %s  %-66s got %-24s want %s\n", $ok ? 'PASS' : 'FAIL', $label, str_replace( "\n", ' ', var_export( $got, true ) ), str_replace( "\n", ' ', var_export( $want, true ) ) );
}

$root  = dirname( __DIR__ );
$admin = file_get_contents( $root . '/admin/class-tcgiant-sync-admin.php' );

echo "\nTHE PRODUCT CAN SAY WHAT CONDITION IT IS IN\n" . str_repeat( '=', 116 ) . "\n";

check( 'a control for the per-product condition is rendered',
	false !== strpos( $admin, "'id'          => '_ebay_export_condition_id'," ), true );
check( '  inside a wrapper the item type can show and hide',
	false !== strpos( $admin, 'id="tc-legacy-condition-wrapper"' ), true );
check( '  shown for All Other, which has no other way to say it',
	false !== strpos( $admin, "\$item_type ? '' : 'display:none;'" ), true );
check( '  and the stored value is read back into it',
	false !== strpos( $admin, "\$cond_id_override        = get_post_meta( \$product_id, '_ebay_export_condition_id', true );" ), true );

// The id has to match on all four sides or the control is decorative: the
// element the JS reads, the key the AJAX handlers map, the key the product save
// writes, and the meta the exporter applies.
check( 'the Push payload still reads that element',
	substr_count( $admin, "override_condition_id:\$('#_ebay_export_condition_id').val()" ), 2 );
check( 'the AJAX handlers still map it to the meta key',
	substr_count( $admin, "'override_condition_id'       => '_ebay_export_condition_id'," ), 2 );
check( 'the product save still writes it',
	false !== strpos( $admin, "isset( \$_POST['_ebay_export_condition_id'] )" ), true );

$exporter = file_get_contents( $root . '/includes/class-tcgiant-sync-exporter.php' );
check( 'and the exporter still applies it over the store default',
	false !== strpos( $exporter, "\$override_condition = \$product->get_meta( '_ebay_export_condition_id' );" )
	|| false !== strpos( $exporter, "get_meta( '_ebay_export_condition_id' )" ), true );

echo "\nTHE READINESS LIST STOPS GUESSING\n" . str_repeat( '=', 116 ) . "\n";

check( 'the All Other condition is no longer ticked without looking',
	false === strpos( $admin, "\$checks[] = array( 'ok' => true, 'label' => 'Condition: ' . \$cond_label );" ), true );
check( '  it now depends on the condition being one eBay publishes',
	false !== strpos( $admin, "\$cond_known = isset( \$conditions[ \$cond_id ] );" ), true );
check( '  and the four media-only conditions are called out',
	false !== strpos( $admin, 'CONDITIONS_MEDIA_ONLY' ), true );
check( 'a missing package weight is mentioned when the shop sends weights',
	false !== strpos( $admin, "! empty( \$merged_settings['send_package_details'] )" ), true );
check( '  but never as a check that can fail the box',
	false === strpos( $admin, "'label' => 'Package weight" ), true );

echo "\nA CATEGORY EBAY WILL NOT ACCEPT\n" . str_repeat( '=', 116 ) . "\n";

$settings_view = file_get_contents( $root . '/admin/views/settings.php' );
check( 'the Settings browser no longer offers the parent you just opened',
	false === strpos( $settings_view, 'tc-cat-use-this' ), true );
check( '  and says what to do instead',
	false !== strpos( $settings_view, 'eBay only accepts the bottom level' ), true );
check( 'the product panel still only selects a leaf, as it always did',
	false !== strpos( $admin, 'if(c.leaf){selectCat(' ), true );

/** The wording added to eBay's own refusal, verbatim. */
function tcg_explain( $message ) {
	$message = (string) $message;

	if ( false !== stripos( $message, 'PrimaryCategory.CategoryID' ) ) {
		return $message . ' ' . 'eBay accepts only a bottom-level category - one with nothing inside it. Open Browse eBay Categories and keep going down until there is nothing left to choose.';
	}

	return $message;
}

$refusal = 'Input data for tag <Item.PrimaryCategory.CategoryID> is invalid or missing. Please check API documentation.';

check( "eBay's category refusal gains an explanation",
	false !== strpos( tcg_explain( $refusal ), 'bottom-level category' ), true );
check( '  with the original wording kept, because that is what gets quoted to us',
	0 === strpos( tcg_explain( $refusal ), 'Input data for tag' ), true );
check( 'an unrelated error is passed through untouched',
	tcg_explain( 'The item specific Type is missing.' ), 'The item specific Type is missing.' );
check( 'and so is an empty one', tcg_explain( '' ), '' );

check( 'the push failure path runs errors through it',
	false !== strpos( $exporter, 'self::explain_ebay_error( $result->get_error_message() )' ), true );

printf( "\n  %d passed, %d failed\n\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
