<?php
/**
 * Logs Page View
 *
 * @package TCGiant_Sync
 * @license GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
	exit;
}

// Fetch up to 1000 logs for the dedicated logs page.
$log_entries = TCGiant_Sync_Logger::get_recent_entries(1000);
$license_ui = TCGiant_Sync_License::instance()->get_status_for_ui();
?>

<div class="wrap tc-dashboard-wrap">
	<div class="tc-header">
		<h1>
			<?php esc_html_e('Activity Logs', 'tcgiant-sync'); ?>
			<?php if ($license_ui['is_pro']): ?>
				<span class="tc-pro-badge">PRO</span>
			<?php else: ?>
				<span class="tc-free-badge">FREE</span>
			<?php endif; ?>
		</h1>
		<p class="tc-subtitle">
			<?php esc_html_e('Review your historical sync activity and errors here.', 'tcgiant-sync'); ?>
		</p>
	</div>

	<?php TCGiant_Sync_Admin::instance()->render_tabs('logs'); ?>

	<div class="tc-card">
		<div class="tc-log-header">
			<h2 style="margin-bottom:0;"><span class="dashicons dashicons-list-view"></span>
				<?php esc_html_e('Full Activity Log', 'tcgiant-sync'); ?></h2>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
				<input type="hidden" name="action" value="tcgiant_clear_log">
				<?php wp_nonce_field('tcgiant_clear_log'); ?>
				<button type="submit" class="tc-button secondary tc-button-danger"
					style="font-size:11px;padding:4px 10px;">
					<span class="dashicons dashicons-trash" style="font-size:14px;vertical-align:text-bottom;"></span>
					<?php esc_html_e('Clear All Logs', 'tcgiant-sync'); ?>
				</button>
			</form>
		</div>

		<p style="font-size:12px; color:var(--tc-text-muted); margin-top:-10px; margin-bottom:15px;">
			<?php esc_html_e('More detailed activity logs', 'tcgiant-sync'); ?>
		</p>

		<div class="tc-log-viewer" id="tc-log-content" style="max-height: 600px;">
			<?php if (empty($log_entries)): ?>
				<div class="tc-premium-empty-state" style="margin-top:20px;border-color:rgba(255,255,255,0.1);">
					<span class="dashicons dashicons-welcome-write-blog" style="color:#64748b;"></span>
					<p style="color:#64748b;"><?php esc_html_e('No activity recorded yet.', 'tcgiant-sync'); ?></p>
				</div>
			<?php else: ?>
				<?php
				// Timestamps are written with current_time(), so they are already on
				// the site's clock rather than UTC. Naming that clock removes the
				// guesswork: if it reads UTC here, that is the WordPress setting
				// under Settings -> General, not the plugin.
				$tc_tz  = wp_timezone();
				$tc_now = new DateTime( 'now', $tc_tz );
				?>
				<div class="tc-log-entry" style="opacity:.7;">
					<span class="tc-log-icon">[i]</span>
					<span class="tc-log-msg">
						<?php
						printf(
							/* translators: 1: timezone name, 2: UTC offset, 3: current local time */
							esc_html__( 'Times are shown in your own timezone. The log is written in the site timezone, %1$s (UTC%2$s), where it is currently %3$s — hover any time to see it.', 'tcgiant-sync' ),
							esc_html( $tc_tz->getName() ),
							esc_html( $tc_now->format( 'P' ) ),
							esc_html( $tc_now->format( 'Y-m-d H:i:s' ) )
						);
						?>
					</span>
				</div>
				<?php foreach ($log_entries as $entry):
					$level_class = '';
					$icon = '[Log]';
					switch ($entry['level']) {
						case 'error':
							$level_class = 'tc-is-error';
							$icon = '[X]';
							break;
						case 'success':
							$level_class = 'tc-is-success';
							$icon = '[OK]';
							break;
						case 'warning':
							$level_class = 'tc-is-warning';
							$icon = '[!]';
							break;
					}
					?>
					<div class="tc-log-entry <?php echo esc_attr($level_class); ?>">
						<span class="tc-log-icon"><?php echo esc_html($icon); ?></span>
						<span class="tc-log-time" data-tc-utc="<?php echo esc_attr( tcgiant_log_time_to_utc( $entry['timestamp'] ) ); ?>" title="<?php echo esc_attr( sprintf( __( 'Site time: %s', 'tcgiant-sync' ), $entry['timestamp'] ) ); ?>"><?php echo esc_html($entry['timestamp']); ?></span>
						<span class="tc-log-msg"><?php echo esc_html($entry['message']); ?></span>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<script>
/* The log file stays on the site's clock, which is right for a file several
   people may read. What is on screen is restated in whoever is looking at it —
   their machine already knows its own offset, so nothing is stored per user.
   Hovering still shows the site time the file actually recorded. */
( function () {
	var nodes = document.querySelectorAll( '.tc-log-time[data-tc-utc]' );
	if ( ! nodes.length ) {
		return;
	}

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }

	Array.prototype.forEach.call( nodes, function ( el ) {
		var iso = el.getAttribute( 'data-tc-utc' );
		if ( ! iso ) {
			return;
		}
		var when = new Date( iso );
		if ( isNaN( when.getTime() ) ) {
			return;
		}
		el.textContent = when.getFullYear() + '-' + pad( when.getMonth() + 1 ) + '-' + pad( when.getDate() )
			+ ' ' + pad( when.getHours() ) + ':' + pad( when.getMinutes() ) + ':' + pad( when.getSeconds() );
	} );
} )();
</script>