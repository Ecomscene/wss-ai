<?php
/**
 * "Weer op voorraad" instellen. Ingeladen door
 * WSFM_Flow_Admin_UI::render_voorraad() met: $i, $sjablonen, $lijsten,
 * $wachtlijst, $laatste, $aantallen.
 *
 * WAAROM DE WACHTLIJST OP DIT SCHERM STAAT EN NIET ERGENS APART
 * Dit is het enige getal waar de winkelier iets mee kan: dertig mensen die op
 * hetzelfde jurkje wachten is een reden om bij te bestellen. Een schakelaar
 * zonder dat getal is een knop waarvan je nooit merkt of hij iets doet.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wsfm-voorraad-beheer">
	<h1><?php esc_html_e( 'Weer op voorraad', 'ws-flow-mailer' ); ?></h1>

	<?php if ( isset( $_GET['wsfm-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Opgeslagen.', 'ws-flow-mailer' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Staat dit aan, dan komt er bij een uitverkocht product een vakje waarin een bezoeker zijn e-mailadres kan laten. Zodra je de voorraad weer bijzet, krijgt iedereen die op dat product wachtte automatisch bericht. Jij hoeft daar niets voor te doen.', 'ws-flow-mailer' ); ?>
	</p>

	<div class="wsfm-voorraad-cijfers" style="display:flex;gap:16px;margin:16px 0 24px;">
		<div class="postbox" style="margin:0;min-width:160px;">
			<div class="inside">
				<p style="margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">
					<?php esc_html_e( 'Mensen die wachten', 'ws-flow-mailer' ); ?></p>
				<p style="margin:4px 0 0;font-size:24px;font-weight:600;">
					<?php echo esc_html( number_format_i18n( (int) $aantallen['wacht'] ) ); ?></p>
			</div>
		</div>
		<div class="postbox" style="margin:0;min-width:160px;">
			<div class="inside">
				<p style="margin:0;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#646970;">
					<?php esc_html_e( 'Berichten verstuurd', 'ws-flow-mailer' ); ?></p>
				<p style="margin:4px 0 0;font-size:24px;font-weight:600;">
					<?php echo esc_html( number_format_i18n( (int) $aantallen['verstuurd'] ) ); ?></p>
			</div>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wsfm_save_voorraad">
		<?php wp_nonce_field( 'wsfm_save_voorraad' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Aanzetten', 'ws-flow-mailer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="aan" value="1" <?php checked( ! empty( $i['aan'] ) ); ?>>
						<?php esc_html_e( 'Laat bezoekers zich aanmelden bij een uitverkocht product', 'ws-flow-mailer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Zet je dit uit, dan verdwijnt het vakje van je winkel. De mensen die al op een wachtlijst staan blijven staan, en krijgen weer bericht zodra je het aanzet.', 'ws-flow-mailer' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Het vakje op je product', 'ws-flow-mailer' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wsfm-v-kop"><?php esc_html_e( 'Kop', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-kop" name="kop" class="regular-text" value="<?php echo esc_attr( $i['kop'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-tekst"><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-tekst" name="tekst" class="large-text" value="<?php echo esc_attr( $i['tekst'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-plaats"><?php esc_html_e( 'Tekst in het invulvak', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-plaats" name="plaatshouder" class="regular-text" value="<?php echo esc_attr( $i['plaatshouder'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-knop"><?php esc_html_e( 'Op de knop', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-knop" name="knop" class="regular-text" value="<?php echo esc_attr( $i['knop'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-klein"><?php esc_html_e( 'Kleine letters', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-klein" name="kleine_letters" class="large-text" value="<?php echo esc_attr( $i['kleine_letters'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-gelukt"><?php esc_html_e( 'Na het aanmelden', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<input type="text" id="wsfm-v-gelukt" name="gelukt" class="large-text" value="<?php echo esc_attr( $i['gelukt'] ); ?>">
					<p class="description"><?php esc_html_e( 'Dit komt in de plaats van het invulvak zodra iemand zich heeft aangemeld.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Ook de nieuwsbrief', 'ws-flow-mailer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="ook_nieuwsbrief" value="1" <?php checked( ! empty( $i['ook_nieuwsbrief'] ) ); ?>>
						<?php esc_html_e( 'Zet er een vinkje bij waarmee iemand zich ook voor je nieuwsbrief kan aanmelden', 'ws-flow-mailer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Het vinkje staat niet voor hem aan: hij moet het zelf aanzetten. Iemand stilletjes op je nieuwsbrief zetten omdat hij op een product wacht levert klachten op, en daar heeft je bezorging last van.', 'ws-flow-mailer' ); ?>
					</p>
					<p>
						<label for="wsfm-v-nblabel"><?php esc_html_e( 'Tekst bij het vinkje', 'ws-flow-mailer' ); ?></label><br>
						<input type="text" id="wsfm-v-nblabel" name="nieuwsbrief_label" class="large-text" value="<?php echo esc_attr( $i['nieuwsbrief_label'] ); ?>">
					</p>
					<?php if ( ! empty( $lijsten ) ) : ?>
						<p>
							<label for="wsfm-v-lijst"><?php esc_html_e( 'In welke lijst', 'ws-flow-mailer' ); ?></label><br>
							<select id="wsfm-v-lijst" name="lijst_id">
								<?php foreach ( $lijsten as $wsfm_lijst ) : ?>
									<option value="<?php echo esc_attr( $wsfm_lijst->id ); ?>" <?php selected( (int) $i['lijst_id'], (int) $wsfm_lijst->id ); ?>>
										<?php echo esc_html( $wsfm_lijst->naam ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'De mail die eruit gaat', 'ws-flow-mailer' ); ?></h2>

		<p class="description">
			<?php
			printf(
				/* translators: %s: de merge-tag, letterlijk. */
				esc_html__( 'Gebruik %s in het onderwerp of de tekst; daar komt de naam van het product te staan. Bij een maat of kleur staat die er ook bij.', 'ws-flow-mailer' ),
				'<code>{product_name}</code>'
			);
			?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wsfm-v-onderwerp"><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-onderwerp" name="mail_onderwerp" class="large-text" value="<?php echo esc_attr( $i['mail_onderwerp'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-mailkop"><?php esc_html_e( 'Kop in de mail', 'ws-flow-mailer' ); ?></label></th>
				<td><input type="text" id="wsfm-v-mailkop" name="mail_kop" class="regular-text" value="<?php echo esc_attr( $i['mail_kop'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-mailtekst"><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<textarea id="wsfm-v-mailtekst" name="mail_tekst" rows="5" class="large-text"><?php echo esc_textarea( $i['mail_tekst'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Het product komt er met foto en prijs onder te staan, dus die hoef je er niet in te typen.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-mailknop"><?php esc_html_e( 'Op de knop', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<input type="text" id="wsfm-v-mailknop" name="mail_knop" class="regular-text" value="<?php echo esc_attr( $i['mail_knop'] ); ?>">
					<p class="description"><?php esc_html_e( 'Laat leeg als je geen knop in de mail wilt.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wsfm-v-sjabloon"><?php esc_html_e( 'Vormgeving', 'ws-flow-mailer' ); ?></label></th>
				<td>
					<select id="wsfm-v-sjabloon" name="mail_sjabloon">
						<?php foreach ( $sjablonen as $wsfm_key => $wsfm_sjabloon ) : ?>
							<option value="<?php echo esc_attr( $wsfm_key ); ?>" <?php selected( $i['mail_sjabloon'], $wsfm_key ); ?>>
								<?php echo esc_html( $wsfm_sjabloon['naam'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Dezelfde sjablonen als bij je nieuwsbrief, zodat de mail bij de rest past.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>

	<p>
		<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url(
			wp_nonce_url(
				add_query_arg( array( 'action' => 'wsfm_voorraad_voorbeeld' ), admin_url( 'admin-post.php' ) ),
				'wsfm_voorraad_voorbeeld'
			)
		); ?>"><?php esc_html_e( 'Bekijk de mail', 'ws-flow-mailer' ); ?></a>
		<span class="description"><?php esc_html_e( 'Opent de mail zoals hij verstuurd wordt, met een product uit je eigen winkel. Dit verstuurt niets.', 'ws-flow-mailer' ); ?></span>
	</p>

	<h2><?php esc_html_e( 'Waar mensen op wachten', 'ws-flow-mailer' ); ?></h2>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Product', 'ws-flow-mailer' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Wachtenden', 'ws-flow-mailer' ); ?></th>
				<th style="width:180px;"><?php esc_html_e( 'Langst wachtend sinds', 'ws-flow-mailer' ); ?></th>
				<th style="width:140px;"><?php esc_html_e( 'Voorraad', 'ws-flow-mailer' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $wachtlijst ) ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Er wacht nog niemand op een product.', 'ws-flow-mailer' ); ?></td></tr>
			<?php endif; ?>

			<?php foreach ( $wachtlijst as $wsfm_rij ) : ?>
				<?php
				$wsfm_product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $wsfm_rij->product_id ) : null;
				?>
				<tr>
					<td>
						<?php if ( $wsfm_product ) : ?>
							<a href="<?php echo esc_url( get_edit_post_link( (int) $wsfm_rij->product_id ) ); ?>">
								<?php echo esc_html( $wsfm_product->get_name() ); ?></a>
						<?php else : ?>
							<em><?php esc_html_e( 'Dit product bestaat niet meer', 'ws-flow-mailer' ); ?></em>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( number_format_i18n( (int) $wsfm_rij->aantal ) ); ?></td>
					<td><?php echo esc_html( date_i18n( 'j M Y H:i', mysql2date( 'U', $wsfm_rij->oudste ) ) ); ?></td>
					<td>
						<?php if ( ! $wsfm_product ) : ?>
							-
						<?php elseif ( $wsfm_product->is_in_stock() ) : ?>
							<?php esc_html_e( 'Op voorraad', 'ws-flow-mailer' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Uitverkocht', 'ws-flow-mailer' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( ! empty( $laatste ) ) : ?>
		<h2><?php esc_html_e( 'Laatst verstuurde berichten', 'ws-flow-mailer' ); ?></h2>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:160px;"><?php esc_html_e( 'Wanneer', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Naar', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Over', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $laatste as $wsfm_bericht ) : ?>
					<?php
					$wsfm_over = function_exists( 'wc_get_product' )
						? wc_get_product( (int) ( $wsfm_bericht->variation_id ? $wsfm_bericht->variation_id : $wsfm_bericht->product_id ) )
						: null;
					?>
					<tr>
						<td><?php echo esc_html( $wsfm_bericht->notified_at ? date_i18n( 'j M Y H:i', mysql2date( 'U', $wsfm_bericht->notified_at ) ) : '-' ); ?></td>
						<td><?php echo esc_html( WSFM_Flow_Admin_UI::mask_email( $wsfm_bericht->email ) ); ?></td>
						<td><?php echo esc_html( $wsfm_over ? $wsfm_over->get_name() : '-' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
