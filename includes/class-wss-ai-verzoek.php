<?php
/**
 * Een vraag of verzoek naar Webshopschool sturen.
 *
 * WAAROM HET NIET MET wp_mail GAAT
 * Een webshop verstuurt zelf zelden betrouwbaar post. Vaak staat er geen SMTP
 * ingesteld, is het adres van de shop niet geldig als afzender, en belandt wat er
 * wél de deur uitgaat in de spammap. Een verzoek dat niemand ziet is erger dan
 * een verzoek dat niet verstuurd kan worden, want de klant denkt dat hij
 * geholpen wordt. Dus versturen wij hem, en de klant hoort of het gelukt is.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Verzoek {

	public static function init() {
		add_action( 'admin_post_wss_ai_verzoek', array( __CLASS__, 'versturen' ) );
	}

	public static function versturen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_verzoek' );

		$onderwerp = isset( $_POST['onderwerp'] ) ? sanitize_text_field( wp_unslash( $_POST['onderwerp'] ) ) : '';
		$bericht   = isset( $_POST['bericht'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bericht'] ) ) : '';
		$antwoord  = isset( $_POST['antwoord'] ) ? sanitize_email( wp_unslash( $_POST['antwoord'] ) ) : '';

		$terug = admin_url( 'admin.php?page=wss-ai-verzoeken' );

		if ( strlen( trim( $bericht ) ) < 5 ) {
			wp_safe_redirect( add_query_arg( 'wss_ai_verzoek', 'leeg', $terug ) );
			exit;
		}

		$uit = WSS_AI_Koppeling::vraag(
			'/verzoek',
			array(
				'onderwerp'    => $onderwerp,
				'bericht'      => $bericht,
				'antwoordNaar' => $antwoord ? $antwoord : get_option( 'admin_email' ),
				'naam'         => get_bloginfo( 'name' ),
				'versie'       => WSS_AI_VERSIE,
			),
			45
		);

		if ( is_wp_error( $uit ) ) {
			/* De tekst niet weggooien: iemand die net vijf regels heeft getypt
			   hoort die terug te zien, niet een leeg formulier met een foutmelding. */
			set_transient( 'wss_ai_verzoek_concept_' . get_current_user_id(), compact( 'onderwerp', 'bericht', 'antwoord' ), HOUR_IN_SECONDS );
			set_transient( 'wss_ai_verzoek_fout_' . get_current_user_id(), $uit->get_error_message(), MINUTE_IN_SECONDS * 5 );
			wp_safe_redirect( add_query_arg( 'wss_ai_verzoek', 'mislukt', $terug ) );
			exit;
		}

		delete_transient( 'wss_ai_verzoek_concept_' . get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'wss_ai_verzoek', 'gelukt', $terug ) );
		exit;
	}

	public static function kaart() {
		$concept = get_transient( 'wss_ai_verzoek_concept_' . get_current_user_id() );
		$concept = is_array( $concept ) ? $concept : array();
		$stand   = isset( $_GET['wss_ai_verzoek'] ) ? sanitize_key( wp_unslash( $_GET['wss_ai_verzoek'] ) ) : '';

		if ( 'gelukt' === $stand ) {
			echo '<div class="notice notice-success"><p>'
				. esc_html__( 'Verstuurd. We lezen het mee en komen bij je terug.', 'wss-ai' )
				. '</p></div>';
		} elseif ( 'leeg' === $stand ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'Schrijf even wat er aan de hand is, dan kunnen we ermee aan de slag.', 'wss-ai' )
				. '</p></div>';
		} elseif ( 'mislukt' === $stand ) {
			$fout = get_transient( 'wss_ai_verzoek_fout_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>'
				. esc_html( $fout ? $fout : __( 'Het versturen lukte niet.', 'wss-ai' ) ) . ' '
				. esc_html__( 'Je tekst staat er nog. Lukt het nu weer niet, mail ons dan rechtstreeks op beheer@webshopschool.nl.', 'wss-ai' )
				. '</p></div>';
		}
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'Een vraag over je webshop, een idee, of werkt er iets niet? Schrijf het hier op. Het komt rechtstreeks binnen bij Webshopschool en je hoeft er geen apart account of ticketsysteem voor.', 'wss-ai' ); ?>
		</p>

		<div class="wss-ai-kaart">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wss_ai_verzoek">
				<?php wp_nonce_field( 'wss_ai_verzoek' ); ?>

				<p>
					<label for="wss-ai-onderwerp"><strong><?php esc_html_e( 'Waar gaat het over?', 'wss-ai' ); ?></strong></label><br>
					<input type="text" id="wss-ai-onderwerp" name="onderwerp" class="large-text"
						value="<?php echo esc_attr( isset( $concept['onderwerp'] ) ? $concept['onderwerp'] : '' ); ?>"
						placeholder="<?php esc_attr_e( 'Bijvoorbeeld: vraag over mijn productfoto\'s', 'wss-ai' ); ?>">
				</p>

				<p>
					<label for="wss-ai-bericht"><strong><?php esc_html_e( 'Je bericht', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein">
						<?php esc_html_e( 'Schrijf gerust zoals je het zou vertellen. Hoe concreter, hoe sneller we je kunnen helpen: bij welk product, wat je verwachtte en wat er gebeurde.', 'wss-ai' ); ?>
					</span>
					<textarea id="wss-ai-bericht" name="bericht" rows="8" class="large-text"><?php echo esc_textarea( isset( $concept['bericht'] ) ? $concept['bericht'] : '' ); ?></textarea>
				</p>

				<p>
					<label for="wss-ai-antwoord"><strong><?php esc_html_e( 'Antwoord naar', 'wss-ai' ); ?></strong></label><br>
					<input type="email" id="wss-ai-antwoord" name="antwoord" class="regular-text"
						value="<?php echo esc_attr( isset( $concept['antwoord'] ) && $concept['antwoord'] ? $concept['antwoord'] : get_option( 'admin_email' ) ); ?>">
				</p>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Versturen', 'wss-ai' ); ?></button>
				</p>

				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'We zien erbij van welke webshop het komt en welke versie van de plugin je gebruikt. Verder sturen we niets mee: geen bestellingen, geen klantgegevens, geen inhoud van je website.', 'wss-ai' ); ?>
				</p>
			</form>
		</div>
		<?php
	}
}
