<?php
/**
 * Dashboard page. Included from WSFM_Flow_Admin_UI::render_dashboard(),
 * which provides: $stats, $flow_stats, $recent, $trigger_filter.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_trigger_labels = array(
	'abandoned_cart'  => __( 'Verlaten winkelwagen', 'ws-flow-mailer' ),
	'order_completed' => __( 'Order afgerond', 'ws-flow-mailer' ),
);

$wsfm_status_labels = array(
	'sent'       => __( 'Verzonden', 'ws-flow-mailer' ),
	'failed'     => __( 'Mislukt', 'ws-flow-mailer' ),
	'bounced'    => __( 'Gebounced', 'ws-flow-mailer' ),
	'complained' => __( 'Klacht', 'ws-flow-mailer' ),
);
?>
<div class="wrap wsfm-dashboard">
	<h1><?php esc_html_e( 'WS Flow Mailer — Dashboard', 'ws-flow-mailer' ); ?></h1>
	<p class="description"><?php esc_html_e( 'Overzicht van de afgelopen 30 dagen.', 'ws-flow-mailer' ); ?></p>

	<div class="wsfm-cards">
		<div class="wsfm-card">
			<span class="wsfm-card-number"><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></span>
			<span class="wsfm-card-label"><?php esc_html_e( 'Verzonden', 'ws-flow-mailer' ); ?></span>
		</div>
		<div class="wsfm-card <?php echo $stats['failed'] > 0 ? 'wsfm-card-warn' : ''; ?>">
			<span class="wsfm-card-number"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
			<span class="wsfm-card-label"><?php esc_html_e( 'Mislukt / bounces', 'ws-flow-mailer' ); ?></span>
		</div>
		<div class="wsfm-card">
			<span class="wsfm-card-number"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></span>
			<span class="wsfm-card-label"><?php esc_html_e( 'In wachtrij', 'ws-flow-mailer' ); ?></span>
		</div>
		<div class="wsfm-card">
			<span class="wsfm-card-number"><?php echo esc_html( number_format_i18n( $stats['suppressed'] ) ); ?></span>
			<span class="wsfm-card-label"><?php esc_html_e( 'Suppressielijst', 'ws-flow-mailer' ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Per flow', 'ws-flow-mailer' ); ?></h2>
	<?php if ( empty( $flow_stats ) ) : ?>
		<p><?php esc_html_e( 'Nog geen flows aangemaakt.', 'ws-flow-mailer' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_FLOWS . '&action=new' ) ); ?>"><?php esc_html_e( 'Maak je eerste flow', 'ws-flow-mailer' ); ?></a>
		</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Flow', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Trigger', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Verzonden (30d)', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Mislukt (30d)', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'In wachtrij', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $flow_stats as $wsfm_row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_FLOWS . '&action=edit&flow=' . (int) $wsfm_row->id ) ); ?>"><?php echo esc_html( $wsfm_row->name ); ?></a></td>
						<td><?php echo esc_html( isset( $wsfm_trigger_labels[ $wsfm_row->trigger_type ] ) ? $wsfm_trigger_labels[ $wsfm_row->trigger_type ] : $wsfm_row->trigger_type ); ?></td>
						<td><?php echo 'active' === $wsfm_row->status ? '<span class="wsfm-badge wsfm-badge-active">' . esc_html__( 'Actief', 'ws-flow-mailer' ) . '</span>' : '<span class="wsfm-badge">' . esc_html__( 'Gepauzeerd', 'ws-flow-mailer' ) . '</span>'; ?></td>
						<td><?php echo esc_html( number_format_i18n( $wsfm_row->sent ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $wsfm_row->failed ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $wsfm_row->pending ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2 style="margin-top:2em;"><?php esc_html_e( 'Laatste e-mails', 'ws-flow-mailer' ); ?></h2>

	<form method="get" style="margin-bottom:8px;">
		<input type="hidden" name="page" value="<?php echo esc_attr( WSFM_Flow_Admin_UI::SLUG_DASHBOARD ); ?>" />
		<label for="wsfm-trigger-filter" class="screen-reader-text"><?php esc_html_e( 'Filter op trigger', 'ws-flow-mailer' ); ?></label>
		<select name="trigger" id="wsfm-trigger-filter">
			<option value=""><?php esc_html_e( 'Alle flow-types', 'ws-flow-mailer' ); ?></option>
			<?php foreach ( $wsfm_trigger_labels as $wsfm_key => $wsfm_label ) : ?>
				<option value="<?php echo esc_attr( $wsfm_key ); ?>" <?php selected( $trigger_filter, $wsfm_key ); ?>><?php echo esc_html( $wsfm_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button"><?php esc_html_e( 'Filter', 'ws-flow-mailer' ); ?></button>
	</form>

	<?php if ( empty( $recent ) ) : ?>
		<p><?php esc_html_e( 'Nog geen e-mails verzonden.', 'ws-flow-mailer' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Ontvanger', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Flow / template', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Tijdstip', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recent as $wsfm_log_row ) : ?>
					<tr>
						<td>
							<span class="wsfm-badge <?php echo 'sent' === $wsfm_log_row->status ? 'wsfm-badge-active' : 'wsfm-badge-error'; ?>">
								<?php echo esc_html( isset( $wsfm_status_labels[ $wsfm_log_row->status ] ) ? $wsfm_status_labels[ $wsfm_log_row->status ] : $wsfm_log_row->status ); ?>
							</span>
							<?php if ( $wsfm_log_row->error_message ) : ?>
								<span class="dashicons dashicons-info-outline" title="<?php echo esc_attr( $wsfm_log_row->error_message ); ?>"></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( WSFM_Flow_Admin_UI::mask_email( $wsfm_log_row->recipient ) ); ?></td>
						<td><?php echo esc_html( $wsfm_log_row->subject ); ?></td>
						<td><?php echo esc_html( trim( $wsfm_log_row->flow_name . ' / ' . $wsfm_log_row->template_name, ' /' ) ); ?></td>
						<td><?php echo esc_html( $wsfm_log_row->sent_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
