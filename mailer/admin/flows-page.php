<?php
/**
 * Flows list. Included from WSFM_Flow_Admin_UI::render_flows(),
 * which provides: $flows.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_trigger_labels = array(
	'abandoned_cart'  => __( 'Verlaten winkelwagen', 'ws-flow-mailer' ),
	'order_completed' => __( 'Order afgerond', 'ws-flow-mailer' ),
);
?>
<div class="wrap wsfm-flows">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Flows', 'ws-flow-mailer' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_FLOWS . '&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nieuwe flow toevoegen', 'ws-flow-mailer' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['wsfm-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Flow opgeslagen.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Flow verwijderd.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $flows ) ) : ?>
		<p><?php esc_html_e( 'Nog geen flows. Maak je eerste flow aan — er staan al kant-en-klare templates voor je klaar.', 'ws-flow-mailer' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Trigger type', 'ws-flow-mailer' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Stappen', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Laatst bewerkt', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $flows as $wsfm_flow ) : ?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_FLOWS . '&action=edit&flow=' . (int) $wsfm_flow->id ) ); ?>"><?php echo esc_html( $wsfm_flow->name ); ?></a></strong>
						</td>
						<td><?php echo esc_html( isset( $wsfm_trigger_labels[ $wsfm_flow->trigger_type ] ) ? $wsfm_trigger_labels[ $wsfm_flow->trigger_type ] : $wsfm_flow->trigger_type ); ?></td>
						<td>
							<button type="button"
								class="wsfm-toggle <?php echo 'active' === $wsfm_flow->status ? 'wsfm-toggle-on' : ''; ?>"
								data-flow="<?php echo (int) $wsfm_flow->id; ?>"
								role="switch"
								aria-checked="<?php echo 'active' === $wsfm_flow->status ? 'true' : 'false'; ?>"
								aria-label="<?php esc_attr_e( 'Flow actief', 'ws-flow-mailer' ); ?>">
								<span class="wsfm-toggle-knob"></span>
							</button>
							<span class="wsfm-toggle-label"><?php echo 'active' === $wsfm_flow->status ? esc_html__( 'Actief', 'ws-flow-mailer' ) : esc_html__( 'Gepauzeerd', 'ws-flow-mailer' ); ?></span>
						</td>
						<td><?php echo esc_html( count( $wsfm_flow->steps ) ); ?></td>
						<td><?php echo esc_html( $wsfm_flow->updated_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
