<?php
/**
 * De popup instellen. Ingeladen door WSFM_Flow_Admin_UI::render_popup()
 * met: $i (instellingen), $sjablonen, $recent, $aantal.
 *
 * Links de velden, rechts het voorbeeld. Dat voorbeeld is niet nagemaakt maar
 * dezelfde HTML als op de winkel, met dezelfde stylesheet: anders groeien ze
 * uit elkaar en klopt wat je hier ziet op een dag niet meer.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_beeld = $i['afbeelding'] ? wp_get_attachment_image_url( (int) $i['afbeelding'], 'medium' ) : '';
?>
<div class="wrap wsfm-popup-beheer">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Popup', 'ws-flow-mailer' ); ?></h1>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['wsfm-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Opgeslagen.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
	<?php endif; ?>

	<?php if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'WooCommerce is niet actief. De popup werkt wel, maar er kan geen kortingscode gemaakt worden.', 'ws-flow-mailer' ); ?></p>
		</div>
	<?php endif; ?>

	<p class="wsfm-popup-uitleg">
		<?php esc_html_e( 'Bezoekers laten hun e-mailadres achter en krijgen meteen een kortingscode op het scherm, plus dezelfde code per mail. Ze komen daarna op je lijst te staan, zodat je ze een nieuwsbrief kunt sturen.', 'ws-flow-mailer' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-popup-form">
		<input type="hidden" name="action" value="wsfm_save_popup">
		<?php wp_nonce_field( 'wsfm_save_popup' ); ?>

		<div class="wsfm-popup-kolommen">
			<div class="wsfm-popup-links">

				<div class="postbox">
					<div class="inside">
						<p>
							<label class="wsfm-aanknop">
								<input type="checkbox" name="aan" value="1" <?php checked( ! empty( $i['aan'] ) ); ?>>
								<strong><?php esc_html_e( 'De popup staat aan', 'ws-flow-mailer' ); ?></strong>
							</label><br>
							<span class="description"><?php esc_html_e( 'Zolang dit uit staat ziet niemand op je winkel iets.', 'ws-flow-mailer' ); ?></span>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Wat er in de popup staat', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p>
							<label><?php esc_html_e( 'Kop', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="large-text wsfm-live" data-doel="kop" name="kop" value="<?php echo esc_attr( $i['kop'] ); ?>"></label>
						</p>
						<p>
							<label><?php esc_html_e( 'Tekst eronder', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="large-text wsfm-live" data-doel="tekst" name="tekst" value="<?php echo esc_attr( $i['tekst'] ); ?>"></label>
						</p>
						<p class="wsfm-tweeling">
							<label><?php esc_html_e( 'Tekst in het invulvak', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="wsfm-live" data-doel="plaatshouder" data-attr="placeholder" name="plaatshouder" value="<?php echo esc_attr( $i['plaatshouder'] ); ?>"></label>
							<label><?php esc_html_e( 'Tekst op de knop', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="wsfm-live" data-doel="knop" name="knop" value="<?php echo esc_attr( $i['knop'] ); ?>"></label>
						</p>
						<p>
							<label><?php esc_html_e( 'Kleine tekst onderaan', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="large-text wsfm-live" data-doel="klein" name="kleine_letters" value="<?php echo esc_attr( $i['kleine_letters'] ); ?>"></label><br>
							<span class="description"><?php esc_html_e( 'Mag leeg blijven.', 'ws-flow-mailer' ); ?></span>
						</p>

						<hr>

						<p><strong><?php esc_html_e( 'Na het inschrijven', 'ws-flow-mailer' ); ?></strong></p>
						<p class="wsfm-tweeling">
							<label><?php esc_html_e( 'Kop', 'ws-flow-mailer' ); ?><br>
								<input type="text" name="gelukt_kop" value="<?php echo esc_attr( $i['gelukt_kop'] ); ?>"></label>
							<label><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?><br>
								<input type="text" name="gelukt_tekst" value="<?php echo esc_attr( $i['gelukt_tekst'] ); ?>"></label>
						</p>
						<p>
							<button type="button" class="button" id="wsfm-toon-gelukt"><?php esc_html_e( 'Laat het bedankscherm zien', 'ws-flow-mailer' ); ?></button>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Hoe hij eruitziet', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p>
							<button type="button" class="button" id="wsfm-popup-beeld-kies"><?php esc_html_e( 'Kies een foto', 'ws-flow-mailer' ); ?></button>
							<button type="button" class="button-link" id="wsfm-popup-beeld-weg"<?php echo $wsfm_beeld ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Foto weghalen', 'ws-flow-mailer' ); ?></button>
							<input type="hidden" name="afbeelding" id="wsfm-popup-beeld-id" value="<?php echo esc_attr( (int) $i['afbeelding'] ); ?>">
						</p>
						<p class="description"><?php esc_html_e( 'De foto staat rechts naast de tekst. Op een telefoon valt hij weg, want daar past hij niet naast de tekst en zou het formulier onder de vouw belanden.', 'ws-flow-mailer' ); ?></p>

						<hr>

						<p class="wsfm-kleuren">
							<label><?php esc_html_e( 'Achtergrond', 'ws-flow-mailer' ); ?><br>
								<input type="color" class="wsfm-live-kleur" data-var="--wsfm-bg" name="kleur_achtergrond" value="<?php echo esc_attr( $i['kleur_achtergrond'] ); ?>"></label>
							<label><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?><br>
								<input type="color" class="wsfm-live-kleur" data-var="--wsfm-tekst" name="kleur_tekst" value="<?php echo esc_attr( $i['kleur_tekst'] ); ?>"></label>
							<label><?php esc_html_e( 'Knop', 'ws-flow-mailer' ); ?><br>
								<input type="color" class="wsfm-live-kleur" data-var="--wsfm-knop" name="kleur_knop" value="<?php echo esc_attr( $i['kleur_knop'] ); ?>"></label>
							<label><?php esc_html_e( 'Tekst op de knop', 'ws-flow-mailer' ); ?><br>
								<input type="color" class="wsfm-live-kleur" data-var="--wsfm-knoptekst" name="kleur_knoptekst" value="<?php echo esc_attr( $i['kleur_knoptekst'] ); ?>"></label>
						</p>
						<p>
							<label>
								<input type="checkbox" name="rond" value="1" id="wsfm-rond" <?php checked( ! empty( $i['rond'] ) ); ?>>
								<?php esc_html_e( 'Ronde hoeken', 'ws-flow-mailer' ); ?>
							</label>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Wanneer hij verschijnt', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p class="wsfm-tweeling">
							<label><?php esc_html_e( 'Na hoeveel seconden?', 'ws-flow-mailer' ); ?><br>
								<input type="number" name="na_seconden" min="0" max="120" value="<?php echo esc_attr( (int) $i['na_seconden'] ); ?>"></label>
							<label><?php esc_html_e( 'Daarna hoeveel dagen niet meer tonen?', 'ws-flow-mailer' ); ?><br>
								<input type="number" name="dagen_verbergen" min="1" max="365" value="<?php echo esc_attr( (int) $i['dagen_verbergen'] ); ?>"></label>
						</p>
						<p class="description">
							<?php esc_html_e( 'Hij komt ook tevoorschijn als iemand met de muis de pagina uit beweegt. Wie hem wegklikt of zich inschrijft, ziet hem niet opnieuw.', 'ws-flow-mailer' ); ?>
						</p>
						<p>
							<label>
								<input type="checkbox" name="niet_bij_afrekenen" value="1" <?php checked( ! empty( $i['niet_bij_afrekenen'] ) ); ?>>
								<?php esc_html_e( 'Niet tonen in de winkelwagen en bij het afrekenen', 'ws-flow-mailer' ); ?>
							</label><br>
							<span class="description"><?php esc_html_e( 'Aan laten staan. Iemand die zijn adres invult moet je niet onderbreken.', 'ws-flow-mailer' ); ?></span>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'De korting', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p class="wsfm-tweeling">
							<label><?php esc_html_e( 'Soort korting', 'ws-flow-mailer' ); ?><br>
								<select name="korting_soort">
									<option value="percent" <?php selected( $i['korting_soort'], 'percent' ); ?>><?php esc_html_e( 'Percentage', 'ws-flow-mailer' ); ?></option>
									<option value="bedrag" <?php selected( $i['korting_soort'], 'bedrag' ); ?>><?php esc_html_e( 'Vast bedrag', 'ws-flow-mailer' ); ?></option>
								</select></label>
							<label><?php esc_html_e( 'Hoeveel?', 'ws-flow-mailer' ); ?><br>
								<input type="number" name="korting_waarde" min="1" step="0.01" value="<?php echo esc_attr( $i['korting_waarde'] ); ?>"></label>
						</p>
						<p class="notice notice-warning" id="wsfm-belofte" style="display:none;padding:8px 12px;margin:0 0 12px;"></p>
						<p class="wsfm-tweeling">
							<label><?php esc_html_e( 'Minimaal bestelbedrag', 'ws-flow-mailer' ); ?><br>
								<input type="number" name="minimaal" min="0" step="0.01" value="<?php echo esc_attr( $i['minimaal'] ); ?>"></label>
							<label><?php esc_html_e( 'Hoeveel dagen geldig?', 'ws-flow-mailer' ); ?><br>
								<input type="number" name="geldig_dagen" min="0" max="3650" value="<?php echo esc_attr( (int) $i['geldig_dagen'] ); ?>"></label>
						</p>
						<p class="description"><?php esc_html_e( 'Nul dagen betekent: blijft altijd geldig.', 'ws-flow-mailer' ); ?></p>

						<hr>

						<p>
							<label>
								<input type="checkbox" name="unieke_code" value="1" id="wsfm-uniek" <?php checked( ! empty( $i['unieke_code'] ) ); ?>>
								<strong><?php esc_html_e( 'Iedereen krijgt zijn eigen code', 'ws-flow-mailer' ); ?></strong>
							</label><br>
							<span class="description">
								<?php esc_html_e( 'Aanbevolen. De code werkt één keer en alleen op het adres waarmee is ingeschreven. Zet je dit uit, dan krijgt iedereen dezelfde code; die staat binnen een paar weken op een kortingssite en dan geef je korting weg aan iedereen.', 'ws-flow-mailer' ); ?>
							</span>
						</p>
						<p class="wsfm-tweeling">
							<label class="wsfm-alleen-uniek"><?php esc_html_e( 'Begin van de code', 'ws-flow-mailer' ); ?><br>
								<input type="text" name="code_voorvoegsel" maxlength="12" value="<?php echo esc_attr( $i['code_voorvoegsel'] ); ?>">
								<span class="description"><?php esc_html_e( 'Bijvoorbeeld WELKOM-4KP7HQ', 'ws-flow-mailer' ); ?></span></label>
							<label class="wsfm-alleen-vast"><?php esc_html_e( 'De vaste code', 'ws-flow-mailer' ); ?><br>
								<input type="text" name="vaste_code" maxlength="40" value="<?php echo esc_attr( $i['vaste_code'] ); ?>">
								<span class="description"><?php esc_html_e( 'Deze coupon moet je zelf in WooCommerce aanmaken.', 'ws-flow-mailer' ); ?></span></label>
						</p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'De mail met de code', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p>
							<label><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="large-text" name="mail_onderwerp" value="<?php echo esc_attr( $i['mail_onderwerp'] ); ?>"></label>
						</p>
						<p>
							<label><?php esc_html_e( 'Kop in de mail', 'ws-flow-mailer' ); ?><br>
								<input type="text" class="large-text" name="mail_kop" value="<?php echo esc_attr( $i['mail_kop'] ); ?>"></label>
						</p>
						<p>
							<label><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?><br>
								<textarea class="large-text" rows="5" name="mail_tekst"><?php echo esc_textarea( $i['mail_tekst'] ); ?></textarea></label><br>
							<span class="description"><?php esc_html_e( 'De kortingscode zetten we er zelf onder, in een kader. Die hoef je hier niet te noemen.', 'ws-flow-mailer' ); ?></span>
						</p>
						<p>
							<label><?php esc_html_e( 'Vormgeving', 'ws-flow-mailer' ); ?><br>
								<select name="mail_sjabloon">
									<?php foreach ( $sjablonen as $wsfm_key => $wsfm_sj ) : ?>
										<option value="<?php echo esc_attr( $wsfm_key ); ?>" <?php selected( $i['mail_sjabloon'], $wsfm_key ); ?>>
											<?php echo esc_html( $wsfm_sj['naam'] ); ?>
										</option>
									<?php endforeach; ?>
								</select></label><br>
							<span class="description"><?php esc_html_e( 'Dezelfde drie als bij de nieuwsbrief.', 'ws-flow-mailer' ); ?></span>
						</p>
						<p>
							<button type="button" class="button" id="wsfm-popup-mail-bekijk"><?php esc_html_e( 'Bekijk de mail', 'ws-flow-mailer' ); ?></button>
							<button type="button" class="button" id="wsfm-popup-mail-proef"><?php esc_html_e( 'Stuur hem naar mezelf', 'ws-flow-mailer' ); ?></button>
						</p>
						<p id="wsfm-popup-mail-melding" class="wsfm-melding"></p>
					</div>
				</div>

				<p>
					<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Opslaan', 'ws-flow-mailer' ); ?></button>
				</p>
			</div>

			<div class="wsfm-popup-rechts">
				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Voorbeeld', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p class="wsfm-breedtes">
							<button type="button" class="button button-small is-actief" data-breed="desktop"><?php esc_html_e( 'Computer', 'ws-flow-mailer' ); ?></button>
							<button type="button" class="button button-small" data-breed="mobiel"><?php esc_html_e( 'Telefoon', 'ws-flow-mailer' ); ?></button>
						</p>

						<div class="wsfm-voorbeeldvak" data-breed="desktop">
							<?php
							/* Dezelfde HTML en dezelfde stylesheet als op de winkel. */
							echo WSFM_Popup::html( $i, $wsfm_beeld, true ); // phpcs:ignore WordPress.Security.EscapingOutput
							?>
						</div>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle"><span><?php esc_html_e( 'Inschrijvingen', 'ws-flow-mailer' ); ?></span></h2>
					<div class="inside">
						<p class="wsfm-aantal"><?php echo esc_html( number_format_i18n( $aantal ) ); ?></p>
						<p class="description"><?php esc_html_e( 'Je kunt ze een nieuwsbrief sturen: kies bij Nieuwsbrieven de doelgroep met de inschrijvingen erin.', 'ws-flow-mailer' ); ?></p>

						<?php if ( ! empty( $recent ) ) : ?>
							<table class="widefat striped wsfm-inschrijvingen">
								<tbody>
									<?php foreach ( $recent as $wsfm_r ) : ?>
										<tr>
											<td><?php echo esc_html( WSFM_Flow_Admin_UI::mask_email( $wsfm_r->email ) ); ?></td>
											<td><code><?php echo esc_html( $wsfm_r->coupon_code ? $wsfm_r->coupon_code : '-' ); ?></code></td>
											<td><?php echo esc_html( date_i18n( 'j M', strtotime( $wsfm_r->created_at ) ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</form>
</div>
