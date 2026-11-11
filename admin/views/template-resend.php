<?php
/**
 * Listing Templates: Re-send to eBay (3.34.0).
 *
 * How many listings already on eBay a template-side change would give a new
 * description, by template, with what is left out and why; Test on 5 and
 * Re-send all for each; and where a Re-send all has got to. Every button is
 * a form with the box's nonce, handled by TCGiant_Sync_Template_Admin::
 * handle_resend(), which needs manage_options.
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$tc_rs      = TCGiant_Sync_Template_Resend::screen();
$tc_rs_done = $tc_rs['done'];
$tc_rs_run  = $tc_rs['state'];
$tc_rs_can  = '' === $tc_rs['blocked'] && $tc_rs['connected'];

$tc_rs_total = 0;
foreach ( $tc_rs['groups'] as $tc_rs_group ) {
	$tc_rs_total += (int) $tc_rs_group['count'];
}

$tc_rs_date = function ( $time ) {
	return function_exists( 'wp_date' ) ? wp_date( 'j M Y, H:i', (int) $time ) : gmdate( 'j M Y, H:i', (int) $time ) . ' UTC';
};

// One button: a form of its own, with the nonce. Disabled, with why beside it.
$tc_rs_button = function ( $do, $group, $label, $class = 'secondary', $why = '', $confirm = '' ) {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tc-resend-form"<?php echo '' !== $confirm ? ' onsubmit="return confirm(\'' . esc_js( $confirm ) . '\');"' : ''; ?>>
		<input type="hidden" name="action" value="tcgiant_tpl_resend">
		<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>">
		<input type="hidden" name="group" value="<?php echo esc_attr( $group ); ?>">
		<?php wp_nonce_field( 'tcgiant_tpl_resend' ); ?>
		<button type="submit" class="tc-button <?php echo esc_attr( $class ); ?>"<?php echo '' !== $why ? ' disabled' : ''; ?>><?php echo esc_html( $label ); ?></button>
		<?php if ( '' !== $why ) : ?>
			<span class="tc-hint tc-resend-why"><?php echo esc_html( $why ); ?></span>
		<?php endif; ?>
	</form>
	<?php
};
?>

<div class="tc-card tc-resend" id="tc-resend" style="margin-top:20px;">
	<h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Re-send to eBay', 'tcgiant-sync' ); ?></h2>
	<p class="tc-hint"><?php esc_html_e( 'Listings already on eBay keep the description they were last sent until their next push. Re-send sends them the new description and nothing else: one eBay call a listing. Changes to the title, price, photos or item specifics still need a push, and a push - or a bulk push - sends the template too.', 'tcgiant-sync' ); ?></p>

	<?php if ( '' !== $tc_rs['blocked'] ) : ?>
		<p class="tc-warning-text"><?php echo esc_html( $tc_rs['blocked'] ); ?></p>
	<?php elseif ( ! $tc_rs['connected'] ) : ?>
		<p class="tc-warning-text"><?php esc_html_e( 'This site is not connected to eBay, so nothing can be sent. Connect it on the Settings screen.', 'tcgiant-sync' ); ?></p>
	<?php endif; ?>

	<p class="tc-resend-count">
		<?php if ( ! $tc_rs_done ) : ?>
			<?php if ( $tc_rs['counting'] ) : ?>
				<?php esc_html_e( 'Counting the listings a template reaches. Nothing is sent to eBay while it counts; reload this page in a minute or two.', 'tcgiant-sync' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Not counted yet. Press Count again to see which listings would change.', 'tcgiant-sync' ); ?>
			<?php endif; ?>
		<?php else : ?>
			<strong>
				<?php
				if ( $tc_rs_total > 0 ) {
					/* translators: %s: number of listings */
					echo esc_html( sprintf( _n( '%s listing would change. It costs 1 eBay call.', '%s listings would change. Each costs 1 eBay call.', $tc_rs_total, 'tcgiant-sync' ), number_format_i18n( $tc_rs_total ) ) );
				} else {
					esc_html_e( 'No listings would change.', 'tcgiant-sync' );
				}
				?>
			</strong>
			<?php
			/* translators: %s: a date and time */
			echo esc_html( sprintf( __( 'Counted %s.', 'tcgiant-sync' ), $tc_rs_date( (int) $tc_rs_done['finished'] ) ) );
			if ( $tc_rs['stale'] || $tc_rs['counting'] ) {
				echo ' ' . esc_html__( 'Something has changed since: it is counting again, and the buttons wait for it.', 'tcgiant-sync' );
			}
			?>
		<?php endif; ?>
	</p>

	<?php if ( $tc_rs['groups'] ) : ?>
		<table class="widefat striped tc-templates-table tc-resend-groups">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Template', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Listings', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Test on 5', 'tcgiant-sync' ); ?></th>
					<th><?php esc_html_e( 'Re-send all', 'tcgiant-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tc_rs['groups'] as $tc_rs_key => $tc_rs_group ) :
					$tc_rs_wait = '';
					if ( ! $tc_rs_can ) {
						$tc_rs_wait = __( 'Nothing can be sent from this site now.', 'tcgiant-sync' );
					} elseif ( $tc_rs['stale'] || $tc_rs['counting'] ) {
						$tc_rs_wait = __( 'Waiting for the count.', 'tcgiant-sync' );
					} elseif ( $tc_rs['running'] ) {
						$tc_rs_wait = __( 'A Re-send all is going.', 'tcgiant-sync' );
					}
					$tc_rs_test = $tc_rs_group['test'];
					?>
					<tr>
						<td data-label="<?php esc_attr_e( 'Template', 'tcgiant-sync' ); ?>"><strong><?php echo esc_html( $tc_rs_group['label'] ); ?></strong></td>
						<td data-label="<?php esc_attr_e( 'Listings', 'tcgiant-sync' ); ?>">
							<?php echo esc_html( number_format_i18n( (int) $tc_rs_group['count'] ) ); ?>
							<?php if ( (int) $tc_rs_group['last'] > 0 ) : ?>
								<br><span class="tc-hint">
									<?php
									/* translators: %s: number of listings */
									echo esc_html( sprintf( _n( '%s at quantity 0, sent last', '%s at quantity 0, sent last', (int) $tc_rs_group['last'], 'tcgiant-sync' ), number_format_i18n( (int) $tc_rs_group['last'] ) ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Test on 5', 'tcgiant-sync' ); ?>">
							<?php $tc_rs_button( 'test', $tc_rs_key, __( 'Test on 5', 'tcgiant-sync' ), 'secondary', $tc_rs_wait, __( 'This sends the new description to 5 of these listings now: 5 eBay calls. Continue?', 'tcgiant-sync' ) ); ?>
							<?php if ( $tc_rs_test ) :
								$tc_rs_ok = 0;
								foreach ( (array) $tc_rs_test['rows'] as $tc_rs_row ) {
									$tc_rs_ok += ! empty( $tc_rs_row['ok'] ) ? 1 : 0;
								}
								?>
								<p class="tc-hint tc-resend-test">
									<?php
									/* translators: 1: a date and time, 2: listings OK, 3: listings sent */
									echo esc_html( sprintf( __( 'Tested %1$s: %2$d of %3$d OK.', 'tcgiant-sync' ), $tc_rs_date( (int) $tc_rs_test['at'] ), $tc_rs_ok, count( (array) $tc_rs_test['rows'] ) ) );
									if ( ! $tc_rs_group['unlocked'] && ! empty( $tc_rs_test['clean'] ) ) {
										echo ' ' . esc_html__( 'The template has been saved since, so test it again.', 'tcgiant-sync' );
									}
									?>
								</p>
								<ul class="tc-resend-test-rows">
									<?php foreach ( (array) $tc_rs_test['rows'] as $tc_rs_row ) : ?>
										<li>
											<?php echo esc_html( (string) $tc_rs_row['name'] ); ?>
											- <a href="<?php echo esc_url( 'https://www.ebay.com/itm/' . rawurlencode( (string) $tc_rs_row['item_id'] ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) $tc_rs_row['item_id'] ); ?> &nearr;</a>:
											<?php if ( ! empty( $tc_rs_row['ok'] ) ) : ?>
												<span class="tc-resend-ok"><?php esc_html_e( 'OK', 'tcgiant-sync' ); ?></span>
												<?php if ( '' !== (string) ( $tc_rs_row['message'] ?? '' ) ) : ?>
													<span class="tc-warning-text">
														<?php
														/* translators: %s: eBay's warning, in its own words */
														echo esc_html( sprintf( __( 'eBay took it with a warning: %s', 'tcgiant-sync' ), (string) $tc_rs_row['message'] ) );
														?>
													</span>
												<?php endif; ?>
											<?php else : ?>
												<span class="tc-warning-text"><?php echo esc_html( '' !== (string) $tc_rs_row['message'] ? (string) $tc_rs_row['message'] : __( 'Not sent.', 'tcgiant-sync' ) ); ?></span>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td data-label="<?php esc_attr_e( 'Re-send all', 'tcgiant-sync' ); ?>">
							<?php
							$tc_rs_all = '' !== $tc_rs_wait ? $tc_rs_wait : ( $tc_rs_group['unlocked'] ? '' : __( 'Test on 5 first.', 'tcgiant-sync' ) );
							/* translators: %s: number of listings */
							$tc_rs_button( 'start', $tc_rs_key, __( 'Re-send all', 'tcgiant-sync' ), '', $tc_rs_all, sprintf( _n( 'This sends the new description to %s listing, one eBay call, in the background. Continue?', 'This sends the new description to %s listings, one eBay call each, in the background over the coming hours or days. Continue?', (int) $tc_rs_group['count'], 'tcgiant-sync' ), number_format_i18n( (int) $tc_rs_group['count'] ) ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $tc_rs['reasons'] ) : ?>
		<h3 class="tc-resend-heading"><?php esc_html_e( 'Left out', 'tcgiant-sync' ); ?></h3>
		<ul class="tc-resend-reasons">
			<?php foreach ( TCGiant_Sync_Template_Resend::reasons_in_order( $tc_rs['reasons'] ) as $tc_rs_reason => $tc_rs_n ) : ?>
				<li<?php echo 'unlinked' === (string) $tc_rs_reason ? ' class="tc-resend-quiet"' : ''; ?>><strong><?php echo esc_html( number_format_i18n( (int) $tc_rs_n ) ); ?></strong> <?php echo esc_html( TCGiant_Sync_Template_Resend::reason_words( (string) $tc_rs_reason ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php if ( (int) ( $tc_rs['reasons']['held'] ?? 0 ) > 0 ) : ?>
			<p class="tc-hint tc-resend-held">
				<?php
				echo esc_html( sprintf(
					/* translators: %s: number of listings */
					_n(
						'%s listing is held only because its product was saved since its listing was last sent: a sale, a stock change or a sync is enough. A push sends it the template with the rest of the listing, and so does a bulk push (Products, Bulk actions, Push to eBay).',
						'%s listings are held only because their product was saved since their listing was last sent: a sale, a stock change or a sync is enough. A push sends them the template with the rest of the listing, and so does a bulk push (Products, Bulk actions, Push to eBay).',
						(int) $tc_rs['reasons']['held'],
						'tcgiant-sync'
					),
					number_format_i18n( (int) $tc_rs['reasons']['held'] )
				) );
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $tc_rs['running'] ) : ?>
		<div class="tc-resend-run">
			<p>
				<strong>
					<?php
					/* translators: %s: the template */
					echo esc_html( sprintf( __( 'Re-sending %s.', 'tcgiant-sync' ), (string) $tc_rs_run['name'] ) );
					?>
				</strong>
				<?php
				echo esc_html( sprintf(
					/* translators: 1: sent, 2: still to send, 3: refused, 4: waiting to try again, 5: need attention */
					__( 'Sent: %1$s. Still to send: %2$s. Refused by eBay: %3$s, of which %4$s wait to be tried again on a later day and %5$s need attention.', 'tcgiant-sync' ),
					number_format_i18n( (int) $tc_rs_run['sent'] ),
					number_format_i18n( max( 0, (int) $tc_rs_run['total'] - (int) $tc_rs_run['cursor'] ) ),
					number_format_i18n( (int) $tc_rs_run['failed'] ),
					number_format_i18n( (int) $tc_rs['retry'] ),
					number_format_i18n( (int) $tc_rs_run['attention'] )
				) );
				if ( (int) $tc_rs_run['dropped'] > 0 ) {
					/* translators: %s: listings */
					echo ' ' . esc_html( sprintf( __( 'Dropped because they no longer qualified: %s.', 'tcgiant-sync' ), number_format_i18n( (int) $tc_rs_run['dropped'] ) ) );
				}
				?>
			</p>
			<?php if ( 'waiting_retry' === $tc_rs_run['status'] ) : ?>
				<p class="tc-hint"><?php esc_html_e( 'Every listing has been tried once. Those eBay refused are tried again from tomorrow.', 'tcgiant-sync' ); ?></p>
			<?php elseif ( 'paused' === $tc_rs_run['status'] && ! empty( $tc_rs_run['pause'] ) ) : ?>
				<p class="tc-warning-text"><?php echo esc_html( __( 'Paused.', 'tcgiant-sync' ) . ' ' . TCGiant_Sync_Template_Resend::pause_words( (array) $tc_rs_run['pause'] ) ); ?></p>
			<?php endif; ?>
			<?php $tc_rs_button( 'stop', '', __( 'Stop', 'tcgiant-sync' ), 'danger', '', __( 'Stop Re-send all? Listings already sent keep their new description; the rest keep the one they have until their next push.', 'tcgiant-sync' ) ); ?>
		</div>
	<?php elseif ( ! empty( $tc_rs_run['ended'] ) ) : ?>
		<p class="tc-hint tc-resend-last">
			<?php
			$tc_rs_end = array(
				'done'          => __( 'finished', 'tcgiant-sync' ),
				'stop'          => __( 'was stopped', 'tcgiant-sync' ),
				'emergency'     => __( 'was ended by Emergency Stop', 'tcgiant-sync' ),
				'changed'       => __( 'ended because the template was saved again since its Test on 5', 'tcgiant-sync' ),
				'template_gone' => __( 'ended because the template was deleted or set to Draft', 'tcgiant-sync' ),
			);
			echo esc_html( sprintf(
				/* translators: 1: the template, 2: how it ended, 3: a date and time, 4: sent, 5: refused */
				__( 'The last Re-send all, for %1$s, %2$s on %3$s: %4$s sent, %5$s refused by eBay.', 'tcgiant-sync' ),
				(string) $tc_rs_run['name'],
				$tc_rs_end[ (string) ( $tc_rs_run['end_why'] ?? '' ) ] ?? __( 'ended', 'tcgiant-sync' ),
				$tc_rs_date( (int) $tc_rs_run['ended'] ),
				number_format_i18n( (int) $tc_rs_run['sent'] ),
				number_format_i18n( (int) $tc_rs_run['failed'] )
			) );
			?>
		</p>
	<?php endif; ?>

	<p class="tc-hint">
		<?php
		echo esc_html( $tc_rs['bulk'] ) . '. ';
		/* translators: %s: re-sends allowed in any two hours */
		echo esc_html( sprintf( __( 'Re-sends count against this limit, as bulk pushes do, and go %s at most in any two hours. They wait while eBay calls are short: when this site has used half of its own for the day, when every shop together has less than a quarter left, and when the day\'s figure is not known.', 'tcgiant-sync' ), number_format_i18n( (int) $tc_rs['window'] ) ) );
		?>
	</p>

	<?php $tc_rs_button( 'count', '', __( 'Count again', 'tcgiant-sync' ) ); ?>
</div>
