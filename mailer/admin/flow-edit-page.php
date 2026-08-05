<?php
/**
 * Flow editor (new + edit). Included from WSFM_Flow_Admin_UI::render_flows(),
 * which provides: $flow (object|null), $templates, $pending_count.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_is_edit = ( null !== $flow );
$wsfm_steps   = $wsfm_is_edit ? $flow->steps : array();
?>
<div class="wrap wsfm-flow-edit">
	<h1><?php echo $wsfm_is_edit ? esc_html__( 'Flow bewerken', 'ws-flow-mailer' ) : esc_html__( 'Nieuwe flow', 'ws-flow-mailer' ); ?></h1>

	<?php if ( empty( $templates ) ) : ?>
		<div class="notice notice-warning"><p>
			<?php esc_html_e( 'Er zijn nog geen templates. Maak eerst een template aan voordat je een flow bouwt.', 'ws-flow-mailer' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_TEMPLATES . '&action=new' ) ); ?>"><?php esc_html_e( 'Nieuwe template maken', 'ws-flow-mailer' ); ?></a>
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-flow-form"
		data-pending-count="<?php echo (int) $pending_count; ?>"
		data-original-trigger="<?php echo esc_attr( $wsfm_is_edit ? $flow->trigger_type : '' ); ?>">
		<input type="hidden" name="action" value="wsfm_save_flow" />
		<input type="hidden" name="flow_id" value="<?php echo $wsfm_is_edit ? (int) $flow->id : 0; ?>" />
		<?php wp_nonce_field( 'wsfm_save_flow' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wsfm_flow_name"><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<input type="text" id="wsfm_flow_name" name="flow_name" class="regular-text" required
						value="<?php echo esc_attr( $wsfm_is_edit ? $flow->name : '' ); ?>"
						placeholder="<?php esc_attr_e( 'Bijv. Verlaten winkelwagen — 2 herinneringen', 'ws-flow-mailer' ); ?>" />
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm_trigger_type"><?php esc_html_e( 'Trigger type', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<select name="trigger_type" id="wsfm_trigger_type">
						<option value="abandoned_cart" <?php selected( $wsfm_is_edit ? $flow->trigger_type : 'abandoned_cart', 'abandoned_cart' ); ?>><?php esc_html_e( 'Verlaten winkelwagen', 'ws-flow-mailer' ); ?></option>
						<option value="order_completed" <?php selected( $wsfm_is_edit ? $flow->trigger_type : '', 'order_completed' ); ?>><?php esc_html_e( 'Order afgerond', 'ws-flow-mailer' ); ?></option>
					</select>
					<p class="description" id="wsfm-trigger-warning" style="display:none;color:#d63638;">
						<?php echo esc_html( sprintf( __( 'Let op: deze flow heeft %d actieve wachtrij-items. Als je het trigger-type wijzigt, kunnen die items niet meer correct worden verwerkt en worden ze gestopt.', 'ws-flow-mailer' ), (int) $pending_count ) ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="flow_active" value="1" <?php checked( $wsfm_is_edit && 'active' === $flow->status ); ?> />
						<?php esc_html_e( 'Flow actief (e-mails worden verzonden)', 'ws-flow-mailer' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Stappen', 'ws-flow-mailer' ); ?></h2>
		<p class="description"><?php esc_html_e( 'De wachttijd van elke stap telt vanaf de vorige stap (de eerste stap telt vanaf het trigger-moment, bijv. het verlaten van de winkelwagen).', 'ws-flow-mailer' ); ?></p>

		<div id="wsfm-steps"
			data-step-count="<?php echo esc_attr( count( $wsfm_steps ) ); ?>">
			<?php foreach ( $wsfm_steps as $wsfm_i => $wsfm_step ) :
				// Present the stored minutes in the friendliest unit.
				$wsfm_wait_value = $wsfm_step['wait_minutes'];
				$wsfm_wait_unit  = 'minutes';
				if ( $wsfm_wait_value >= 1440 && 0 === $wsfm_wait_value % 1440 ) {
					$wsfm_wait_value /= 1440;
					$wsfm_wait_unit   = 'days';
				} elseif ( $wsfm_wait_value >= 60 && 0 === $wsfm_wait_value % 60 ) {
					$wsfm_wait_value /= 60;
					$wsfm_wait_unit   = 'hours';
				}
				?>
				<div class="wsfm-step postbox">
					<div class="wsfm-step-inner">
						<span class="wsfm-step-number"></span>
						<label><?php esc_html_e( 'Wacht', 'ws-flow-mailer' ); ?>
							<input type="number" min="0" step="1" name="steps[<?php echo (int) $wsfm_i; ?>][wait_value]" value="<?php echo esc_attr( $wsfm_wait_value ); ?>" class="small-text" />
						</label>
						<select name="steps[<?php echo (int) $wsfm_i; ?>][wait_unit]">
							<option value="minutes" <?php selected( $wsfm_wait_unit, 'minutes' ); ?>><?php esc_html_e( 'minuten', 'ws-flow-mailer' ); ?></option>
							<option value="hours" <?php selected( $wsfm_wait_unit, 'hours' ); ?>><?php esc_html_e( 'uren', 'ws-flow-mailer' ); ?></option>
							<option value="days" <?php selected( $wsfm_wait_unit, 'days' ); ?>><?php esc_html_e( 'dagen', 'ws-flow-mailer' ); ?></option>
						</select>
						<label><?php esc_html_e( 'en verstuur', 'ws-flow-mailer' ); ?>
							<select name="steps[<?php echo (int) $wsfm_i; ?>][template_id]" required>
								<option value=""><?php esc_html_e( '— kies template —', 'ws-flow-mailer' ); ?></option>
								<?php foreach ( $templates as $wsfm_template ) : ?>
									<option value="<?php echo (int) $wsfm_template->id; ?>" <?php selected( $wsfm_step['template_id'], (int) $wsfm_template->id ); ?>><?php echo esc_html( $wsfm_template->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label class="wsfm-step-stop">
							<input type="checkbox" name="steps[<?php echo (int) $wsfm_i; ?>][stop_on_order]" value="1" <?php checked( $wsfm_step['stop_on_order'] ); ?> />
							<?php esc_html_e( 'Stop als er alsnog een order is geplaatst', 'ws-flow-mailer' ); ?>
						</label>
						<button type="button" class="button-link-delete wsfm-remove-step"><?php esc_html_e( 'Verwijderen', 'ws-flow-mailer' ); ?></button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<p>
			<button type="button" class="button" id="wsfm-add-step"><?php esc_html_e( 'Stap toevoegen', 'ws-flow-mailer' ); ?></button>
		</p>

		<!-- Row blueprint for new steps; JS replaces __INDEX__. -->
		<template id="wsfm-step-template">
			<div class="wsfm-step postbox">
				<div class="wsfm-step-inner">
					<span class="wsfm-step-number"></span>
					<label><?php esc_html_e( 'Wacht', 'ws-flow-mailer' ); ?>
						<input type="number" min="0" step="1" name="steps[__INDEX__][wait_value]" value="1" class="small-text" />
					</label>
					<select name="steps[__INDEX__][wait_unit]">
						<option value="minutes"><?php esc_html_e( 'minuten', 'ws-flow-mailer' ); ?></option>
						<option value="hours" selected><?php esc_html_e( 'uren', 'ws-flow-mailer' ); ?></option>
						<option value="days"><?php esc_html_e( 'dagen', 'ws-flow-mailer' ); ?></option>
					</select>
					<label><?php esc_html_e( 'en verstuur', 'ws-flow-mailer' ); ?>
						<select name="steps[__INDEX__][template_id]" required>
							<option value=""><?php esc_html_e( '— kies template —', 'ws-flow-mailer' ); ?></option>
							<?php foreach ( $templates as $wsfm_template ) : ?>
								<option value="<?php echo (int) $wsfm_template->id; ?>"><?php echo esc_html( $wsfm_template->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label class="wsfm-step-stop">
						<input type="checkbox" name="steps[__INDEX__][stop_on_order]" value="1" class="wsfm-stop-default" />
						<?php esc_html_e( 'Stop als er alsnog een order is geplaatst', 'ws-flow-mailer' ); ?>
					</label>
					<button type="button" class="button-link-delete wsfm-remove-step"><?php esc_html_e( 'Verwijderen', 'ws-flow-mailer' ); ?></button>
				</div>
			</div>
		</template>

		<p class="submit">
			<?php submit_button( __( 'Flow opslaan', 'ws-flow-mailer' ), 'primary', 'submit', false ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_FLOWS ) ); ?>" class="button"><?php esc_html_e( 'Annuleren', 'ws-flow-mailer' ); ?></a>
		</p>
	</form>

	<?php if ( $wsfm_is_edit ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-delete-flow-form">
			<input type="hidden" name="action" value="wsfm_delete_flow" />
			<input type="hidden" name="flow_id" value="<?php echo (int) $flow->id; ?>" />
			<?php wp_nonce_field( 'wsfm_delete_flow' ); ?>
			<button type="submit" class="button-link-delete" id="wsfm-delete-flow"><?php esc_html_e( 'Flow verwijderen', 'ws-flow-mailer' ); ?></button>
		</form>
	<?php endif; ?>
</div>
