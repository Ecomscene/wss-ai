<?php
/**
 * De nieuwsbrief samenstellen. Ingeladen door
 * WSFM_Flow_Admin_UI::render_newsletters() met: $brief, $sjablonen,
 * $doelgroepen, $voortgang.
 *
 * WAAROM DIT GEEN HTML-VELD IS
 * De flow-templates hebben er wel een, want die worden één keer door ons
 * ingericht. Een nieuwsbrief maakt de winkelier zelf, elke maand opnieuw, en
 * die moet niet kunnen kiezen tussen honderd manieren om iets scheef te zetten.
 * Vandaar drie bloksoorten en verder niets.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_id        = $brief ? (int) $brief->id : 0;
$wsfm_verstuurd = $brief && 'concept' !== $brief->status;
$wsfm_blokken   = $brief ? $brief->blocks : array();
$wsfm_sjabloon  = $brief ? $brief->template : 'rustig';
$wsfm_doelgroep = $brief ? $brief->audience : 'klanten_jaar';
$wsfm_terug     = admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_BRIEVEN );

/**
 * Eén blok als formulierregels.
 *
 * Dezelfde functie levert de bestaande blokken én de lege bouwstenen voor de
 * knoppen "voeg toe". Twee keer hetzelfde formulier onderhouden is hoe de ene
 * helft velden krijgt die de andere mist.
 *
 * @param string $soort  afbeelding | tekst | producten.
 * @param string $index  Indexnummer, of __I__ voor een bouwsteen.
 * @param array  $blok   Ingevulde waarden.
 */
if ( ! function_exists( 'wsfm_blok_velden' ) ) {
	function wsfm_blok_velden( $soort, $index, array $blok = array() ) {
		$naam   = 'blokken[' . $index . ']';
		$labels = array(
			'afbeelding' => __( 'Afbeelding', 'ws-flow-mailer' ),
			'tekst'      => __( 'Tekst', 'ws-flow-mailer' ),
			'producten'  => __( 'Producten', 'ws-flow-mailer' ),
		);
		?>
		<div class="wsfm-blok" data-soort="<?php echo esc_attr( $soort ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $naam ); ?>[soort]" value="<?php echo esc_attr( $soort ); ?>">

			<div class="wsfm-blok-kop">
				<span class="wsfm-blok-naam"><?php echo esc_html( isset( $labels[ $soort ] ) ? $labels[ $soort ] : $soort ); ?></span>
				<span class="wsfm-blok-knoppen">
					<button type="button" class="button-link wsfm-omhoog" title="<?php esc_attr_e( 'Naar boven', 'ws-flow-mailer' ); ?>">&uarr;</button>
					<button type="button" class="button-link wsfm-omlaag" title="<?php esc_attr_e( 'Naar beneden', 'ws-flow-mailer' ); ?>">&darr;</button>
					<button type="button" class="button-link wsfm-weg" title="<?php esc_attr_e( 'Weghalen', 'ws-flow-mailer' ); ?>"><?php esc_html_e( 'Weghalen', 'ws-flow-mailer' ); ?></button>
				</span>
			</div>

			<?php if ( 'afbeelding' === $soort ) : ?>
				<?php
				$wsfm_beeld_id = isset( $blok['afbeelding'] ) ? (int) $blok['afbeelding'] : 0;
				$wsfm_beeld    = $wsfm_beeld_id ? wp_get_attachment_image_url( $wsfm_beeld_id, 'medium' ) : '';
				?>
				<div class="wsfm-beeld-vak">
					<input type="hidden" class="wsfm-beeld-id" name="<?php echo esc_attr( $naam ); ?>[afbeelding]" value="<?php echo esc_attr( $wsfm_beeld_id ); ?>">
					<div class="wsfm-beeld-voor"<?php echo $wsfm_beeld ? '' : ' style="display:none"'; ?>>
						<img src="<?php echo esc_url( $wsfm_beeld ); ?>" alt="">
					</div>
					<p>
						<button type="button" class="button wsfm-kies-beeld"><?php esc_html_e( 'Kies een afbeelding', 'ws-flow-mailer' ); ?></button>
					</p>
					<p>
						<label><?php esc_html_e( 'Waar gaat de foto heen als je erop klikt?', 'ws-flow-mailer' ); ?><br>
							<input type="url" class="regular-text" name="<?php echo esc_attr( $naam ); ?>[link]"
								value="<?php echo esc_attr( isset( $blok['link'] ) ? $blok['link'] : '' ); ?>"
								placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></label><br>
						<span class="description"><?php esc_html_e( 'Mag leeg blijven.', 'ws-flow-mailer' ); ?></span>
					</p>
				</div>

			<?php elseif ( 'tekst' === $soort ) : ?>
				<p>
					<label><?php esc_html_e( 'Kop', 'ws-flow-mailer' ); ?><br>
						<input type="text" class="large-text" name="<?php echo esc_attr( $naam ); ?>[kop]"
							value="<?php echo esc_attr( isset( $blok['kop'] ) ? $blok['kop'] : '' ); ?>"></label>
				</p>
				<p>
					<label><?php esc_html_e( 'Tekst', 'ws-flow-mailer' ); ?><br>
						<textarea class="large-text" rows="5" name="<?php echo esc_attr( $naam ); ?>[tekst]"><?php echo esc_textarea( isset( $blok['tekst'] ) ? $blok['tekst'] : '' ); ?></textarea></label><br>
					<span class="description"><?php esc_html_e( 'Een lege regel begint een nieuwe alinea. Wil je de voornaam van de klant gebruiken, typ dan {first_name}.', 'ws-flow-mailer' ); ?></span>
				</p>
				<p class="wsfm-knopveld">
					<label><?php esc_html_e( 'Knoptekst', 'ws-flow-mailer' ); ?><br>
						<input type="text" name="<?php echo esc_attr( $naam ); ?>[knop]"
							value="<?php echo esc_attr( isset( $blok['knop'] ) ? $blok['knop'] : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Bekijk de collectie', 'ws-flow-mailer' ); ?>"></label>
					<label><?php esc_html_e( 'Link van de knop', 'ws-flow-mailer' ); ?><br>
						<input type="url" name="<?php echo esc_attr( $naam ); ?>[knop_url]"
							value="<?php echo esc_attr( isset( $blok['knop_url'] ) ? $blok['knop_url'] : '' ); ?>"
							placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></label>
				</p>
				<p class="description"><?php esc_html_e( 'De knop verschijnt alleen als je allebei de velden invult.', 'ws-flow-mailer' ); ?></p>

			<?php else : ?>
				<p>
					<label><?php esc_html_e( 'Kop boven de producten', 'ws-flow-mailer' ); ?><br>
						<input type="text" class="large-text" name="<?php echo esc_attr( $naam ); ?>[kop]"
							value="<?php echo esc_attr( isset( $blok['kop'] ) ? $blok['kop'] : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Nieuw binnen', 'ws-flow-mailer' ); ?>"></label>
				</p>
				<p>
					<label><?php esc_html_e( 'Welke producten?', 'ws-flow-mailer' ); ?><br>
						<select class="wc-product-search wsfm-producten" multiple="multiple" style="width:100%;max-width:520px;"
							name="<?php echo esc_attr( $naam ); ?>[producten][]"
							data-placeholder="<?php esc_attr_e( 'Zoek een product', 'ws-flow-mailer' ); ?>"
							data-action="woocommerce_json_search_products_and_variations">
							<?php
							$wsfm_ids = isset( $blok['producten'] ) && is_array( $blok['producten'] ) ? $blok['producten'] : array();
							foreach ( $wsfm_ids as $wsfm_pid ) {
								$wsfm_product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $wsfm_pid ) : null;
								if ( $wsfm_product ) {
									echo '<option value="' . esc_attr( (int) $wsfm_pid ) . '" selected>' . esc_html( $wsfm_product->get_formatted_name() ) . '</option>';
								}
							}
							?>
						</select></label><br>
					<span class="description"><?php esc_html_e( 'Foto, naam en prijs worden er zelf bij gezocht. Hoogstens twaalf producten per blok.', 'ws-flow-mailer' ); ?></span>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
?>
<div class="wrap wsfm-brief-edit">
	<h1 class="wp-heading-inline">
		<?php echo $wsfm_id ? esc_html( $brief->name ) : esc_html__( 'Nieuwe nieuwsbrief', 'ws-flow-mailer' ); ?>
	</h1>
	<a href="<?php echo esc_url( $wsfm_terug ); ?>" class="page-title-action"><?php esc_html_e( 'Terug naar het overzicht', 'ws-flow-mailer' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['wsfm-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Opgeslagen.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-sent'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success"><p>
			<?php
			$wsfm_aantal = (int) $_GET['wsfm-sent']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				/* translators: %s: aantal ontvangers. */
				esc_html( _n( 'De nieuwsbrief staat klaar voor %s klant. Hij gaat er de komende minuten uit.', 'De nieuwsbrief staat klaar voor %s klanten. Die gaan er de komende minuten uit, in porties, zodat je e-mailprovider ze niet als spam ziet.', $wsfm_aantal, 'ws-flow-mailer' ) ),
				esc_html( number_format_i18n( $wsfm_aantal ) )
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
	<?php endif; ?>

	<?php if ( $wsfm_verstuurd ) : ?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Deze nieuwsbrief is verstuurd.', 'ws-flow-mailer' ); ?></strong>
				<?php if ( $voortgang ) : ?>
					<?php
					printf(
						/* translators: 1: verzonden, 2: in de wachtrij, 3: mislukt. */
						esc_html__( 'Aangekomen: %1$s. Nog onderweg: %2$s. Niet gelukt: %3$s.', 'ws-flow-mailer' ),
						esc_html( number_format_i18n( $voortgang['verzonden'] ) ),
						esc_html( number_format_i18n( $voortgang['wacht'] ) ),
						esc_html( number_format_i18n( $voortgang['mislukt'] ) )
					);
					?>
				<?php endif; ?>
			</p>
			<p>
				<?php esc_html_e( 'Je kunt hem niet meer aanpassen. Wil je iets soortgelijks sturen, maak dan een kopie.', 'ws-flow-mailer' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
				<input type="hidden" name="action" value="wsfm_duplicate_newsletter">
				<input type="hidden" name="nieuwsbrief_id" value="<?php echo esc_attr( $wsfm_id ); ?>">
				<?php wp_nonce_field( 'wsfm_duplicate_newsletter' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Maak een kopie', 'ws-flow-mailer' ); ?></button>
			</form>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-brief-form">
		<input type="hidden" name="action" value="wsfm_save_newsletter">
		<input type="hidden" name="nieuwsbrief_id" value="<?php echo esc_attr( $wsfm_id ); ?>">
		<?php wp_nonce_field( 'wsfm_save_newsletter' ); ?>

		<div class="wsfm-brief-kolommen">
			<div class="wsfm-brief-links">

				<div class="postbox">
					<div class="inside">
						<p>
							<label for="wsfm-naam"><strong><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></strong></label><br>
							<input type="text" id="wsfm-naam" class="large-text" name="nieuwsbrief_naam" required
								value="<?php echo esc_attr( $brief ? $brief->name : '' ); ?>"
								placeholder="<?php esc_attr_e( 'Nieuwsbrief mei', 'ws-flow-mailer' ); ?>"><br>
							<span class="description"><?php esc_html_e( 'Alleen voor jezelf, om hem terug te vinden.', 'ws-flow-mailer' ); ?></span>
						</p>
						<p>
							<label for="wsfm-onderwerp"><strong><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></strong></label><br>
							<input type="text" id="wsfm-onderwerp" class="large-text" name="nieuwsbrief_onderwerp" required
								value="<?php echo esc_attr( $brief ? $brief->subject : '' ); ?>"
								placeholder="<?php esc_attr_e( 'De nieuwe collectie is binnen', 'ws-flow-mailer' ); ?>"><br>
							<span class="description"><?php esc_html_e( 'Dit is de regel die je klant in zijn inbox ziet. Kort en concreet werkt het best.', 'ws-flow-mailer' ); ?></span>
						</p>
					</div>
				</div>

				<div class="postbox">
					<div class="inside">
						<p><strong><?php esc_html_e( 'Hoe moet hij eruitzien?', 'ws-flow-mailer' ); ?></strong></p>
						<div class="wsfm-sjablonen">
							<?php foreach ( $sjablonen as $wsfm_key => $wsfm_sj ) : ?>
								<label class="wsfm-sjabloon">
									<input type="radio" name="nieuwsbrief_sjabloon" value="<?php echo esc_attr( $wsfm_key ); ?>"
										<?php checked( $wsfm_sjabloon, $wsfm_key ); ?>>
									<span class="wsfm-sjabloon-beeld wsfm-sjabloon-<?php echo esc_attr( $wsfm_key ); ?>">
										<span class="wsfm-mini-kop"></span>
										<span class="wsfm-mini-beeld"></span>
										<span class="wsfm-mini-regel"></span>
										<span class="wsfm-mini-regel wsfm-mini-kort"></span>
										<span class="wsfm-mini-raster">
											<span></span><span></span><span></span>
										</span>
									</span>
									<span class="wsfm-sjabloon-naam"><?php echo esc_html( $wsfm_sj['naam'] ); ?></span>
									<span class="wsfm-sjabloon-kort"><?php echo esc_html( $wsfm_sj['kort'] ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<div class="postbox">
					<div class="inside">
						<p><strong><?php esc_html_e( 'De inhoud', 'ws-flow-mailer' ); ?></strong></p>
						<p class="description">
							<?php esc_html_e( 'Zet de blokken in de volgorde waarin je ze wilt hebben. Je logo en de afmeldlink zitten er automatisch bij.', 'ws-flow-mailer' ); ?>
						</p>

						<div id="wsfm-blokken">
							<?php foreach ( $wsfm_blokken as $wsfm_i => $wsfm_blok ) : ?>
								<?php wsfm_blok_velden( $wsfm_blok['soort'], (string) $wsfm_i, $wsfm_blok ); ?>
							<?php endforeach; ?>
						</div>

						<p class="wsfm-toevoegen">
							<button type="button" class="button wsfm-voeg-toe" data-soort="afbeelding"><?php esc_html_e( '+ Afbeelding', 'ws-flow-mailer' ); ?></button>
							<button type="button" class="button wsfm-voeg-toe" data-soort="tekst"><?php esc_html_e( '+ Tekst', 'ws-flow-mailer' ); ?></button>
							<button type="button" class="button wsfm-voeg-toe" data-soort="producten"><?php esc_html_e( '+ Producten', 'ws-flow-mailer' ); ?></button>
						</p>
					</div>
				</div>
			</div>

			<div class="wsfm-brief-rechts">
				<div class="postbox">
					<div class="inside">
						<p><strong><?php esc_html_e( 'Naar wie gaat hij?', 'ws-flow-mailer' ); ?></strong></p>
						<p>
							<select name="nieuwsbrief_doelgroep" id="wsfm-doelgroep" style="width:100%;">
								<?php foreach ( $doelgroepen as $wsfm_dk => $wsfm_dl ) : ?>
									<option value="<?php echo esc_attr( $wsfm_dk ); ?>" <?php selected( $wsfm_doelgroep, $wsfm_dk ); ?>>
										<?php echo esc_html( $wsfm_dl ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</p>
						<p id="wsfm-aantal" class="wsfm-aantal"><?php esc_html_e( 'Bezig met tellen...', 'ws-flow-mailer' ); ?></p>
						<p class="description">
							<?php esc_html_e( 'Alleen mensen die bij je besteld hebben. Wie zich heeft afgemeld valt er automatisch af.', 'ws-flow-mailer' ); ?>
						</p>
					</div>
				</div>

				<?php if ( ! $wsfm_verstuurd ) : ?>
					<div class="postbox">
						<div class="inside">
							<p>
								<button type="submit" class="button button-primary button-large" style="width:100%;">
									<?php esc_html_e( 'Opslaan', 'ws-flow-mailer' ); ?>
								</button>
							</p>
							<p>
								<button type="button" class="button" id="wsfm-bekijk" style="width:100%;">
									<?php esc_html_e( 'Bekijk hoe hij eruitziet', 'ws-flow-mailer' ); ?>
								</button>
							</p>
							<hr>
							<p>
								<label for="wsfm-testadres"><?php esc_html_e( 'Stuur eerst een proefmail naar:', 'ws-flow-mailer' ); ?></label><br>
								<input type="email" id="wsfm-testadres" class="widefat"
									value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
							</p>
							<p>
								<button type="button" class="button" id="wsfm-proef" style="width:100%;">
									<?php esc_html_e( 'Verstuur proefmail', 'ws-flow-mailer' ); ?>
								</button>
							</p>
							<p id="wsfm-proef-melding" class="wsfm-melding"></p>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</form>

	<?php if ( ! $wsfm_verstuurd && $wsfm_id ) : ?>
		<div class="postbox wsfm-verstuurvak">
			<div class="inside">
				<h2><?php esc_html_e( 'Versturen', 'ws-flow-mailer' ); ?></h2>
				<p>
					<?php esc_html_e( 'Sla eerst op, kijk hem na met een proefmail, en verstuur hem dan. Versturen kan niet ongedaan gemaakt worden.', 'ws-flow-mailer' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-verstuur-form">
					<input type="hidden" name="action" value="wsfm_send_newsletter">
					<input type="hidden" name="nieuwsbrief_id" value="<?php echo esc_attr( $wsfm_id ); ?>">
					<?php wp_nonce_field( 'wsfm_send_newsletter' ); ?>
					<button type="submit" class="button button-primary button-hero" id="wsfm-verstuur">
						<?php esc_html_e( 'Verstuur naar mijn klanten', 'ws-flow-mailer' ); ?>
					</button>
				</form>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-weggooi">
			<input type="hidden" name="action" value="wsfm_delete_newsletter">
			<input type="hidden" name="nieuwsbrief_id" value="<?php echo esc_attr( $wsfm_id ); ?>">
			<?php wp_nonce_field( 'wsfm_delete_newsletter' ); ?>
			<button type="submit" class="button-link wsfm-weggooi-knop"><?php esc_html_e( 'Deze nieuwsbrief weggooien', 'ws-flow-mailer' ); ?></button>
		</form>
	<?php endif; ?>

	<div id="wsfm-bouwstenen" style="display:none;">
		<?php foreach ( array( 'afbeelding', 'tekst', 'producten' ) as $wsfm_soort ) : ?>
			<div data-bouwsteen="<?php echo esc_attr( $wsfm_soort ); ?>">
				<?php wsfm_blok_velden( $wsfm_soort, '__I__' ); ?>
			</div>
		<?php endforeach; ?>
	</div>
</div>
