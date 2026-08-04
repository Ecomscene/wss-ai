<?php
/**
 * Wat de winkelier zelf instelt: schrijfstijl en standaardlengte.
 *
 * WAAROM DIT OP DE SITE STAAT EN NIET BIJ ONS
 * Dit zijn geen gegevens die wij nodig hebben om iets te beheren; het is een
 * voorkeur van deze ene winkel. Hier opslaan betekent dat de klant hem zelf kan
 * wijzigen zonder dat er iemand aan te pas komt, en dat een site die morgen van
 * ons afscheid neemt zijn eigen instelling gewoon houdt.
 *
 * De stijltekst gaat wel mee naar de server, want daar wordt de tekst gemaakt.
 * Hij komt daar achter de harde regels te staan: hij bepaalt de toon, niet of er
 * dingen verzonnen mogen worden.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Instellingen {

	const STIJL  = 'wss_ai_stijl';
	const LENGTE = 'wss_ai_lengte';

	/** Meer dan dit is geen schrijfstijl meer maar een verhaal. */
	const MAX_STIJL = 600;

	public static function init() {
		add_action( 'admin_post_wss_ai_instellingen', array( __CLASS__, 'opslaan' ) );
	}

	public static function stijl() {
		$s = get_option( self::STIJL, '' );
		return is_string( $s ) ? $s : '';
	}

	public static function lengte() {
		$l = get_option( self::LENGTE, 'normaal' );
		return in_array( $l, array( 'kort', 'normaal', 'lang' ), true ) ? $l : 'normaal';
	}

	/**
	 * De keuzes zoals ze in beeld staan. Ook gebruikt op het productscherm.
	 *
	 * Bewust zonder exact aantal woorden erbij. De echte lengte hangt ook af van
	 * hoeveel er over een product is ingevuld: bij een kaal product blijft de
	 * tekst korter, want liever kort dan opgerekt met verzinsels. Een label dat
	 * "250 tot 350 woorden" belooft zou dus regelmatig liegen.
	 */
	public static function lengtes() {
		return array(
			'kort'    => __( 'Kort', 'wss-ai' ),
			'normaal' => __( 'Normaal', 'wss-ai' ),
			'lang'    => __( 'Uitgebreid', 'wss-ai' ),
		);
	}

	/**
	 * Voorbeelden om mee te beginnen.
	 *
	 * Wie "schrijfstijl" leest weet vaak niet wat hij moet opschrijven. Deze
	 * vullen het veld alleen maar; ze zijn bedoeld om aangepast te worden, niet
	 * om aan te vinken.
	 */
	public static function voorbeelden() {
		return array(
			__( 'Warm en persoonlijk', 'wss-ai' )   => __( 'Schrijf warm en persoonlijk, alsof ik het zelf aan een klant in de winkel vertel. Korte zinnen, geen moeilijke woorden, af en toe een knipoog.', 'wss-ai' ),
			__( 'Zakelijk en duidelijk', 'wss-ai' ) => __( 'Schrijf zakelijk en to the point. Feiten voorop, geen uitroeptekens, geen gezellige praat. De klant wil weten wat hij koopt.', 'wss-ai' ),
			__( 'Rustig en verzorgd', 'wss-ai' )    => __( 'Schrijf rustig en verzorgd, met aandacht voor materiaal en afwerking. Beschrijvend, niet aanprijzend. Laat het product voor zich spreken.', 'wss-ai' ),
			__( 'Vlot en jong', 'wss-ai' )          => __( 'Schrijf vlot en informeel, korte zinnen, spreektaal mag. Enthousiast maar niet schreeuwerig.', 'wss-ai' ),
		);
	}

	public static function opslaan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_instellingen' );

		$stijl = isset( $_POST['wss_ai_stijl'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wss_ai_stijl'] ) ) : '';
		$stijl = self::kort_maken( $stijl );
		update_option( self::STIJL, $stijl );

		$lengte = isset( $_POST['wss_ai_lengte'] ) ? sanitize_key( wp_unslash( $_POST['wss_ai_lengte'] ) ) : 'normaal';
		if ( ! array_key_exists( $lengte, self::lengtes() ) ) {
			$lengte = 'normaal';
		}
		update_option( self::LENGTE, $lengte );

		wp_safe_redirect( admin_url( 'admin.php?page=wss-ai&wss_ai_bewaard=1' ) );
		exit;
	}

	/**
	 * Afkappen op een spatie in plaats van middenin een woord, en het kastlijntje
	 * eruit. Dat laatste is een harde regel voor alles wat deze plugin oplevert;
	 * zou het in de stijl staan, dan zou het model het overnemen.
	 */
	private static function kort_maken( $tekst ) {
		$tekst = str_replace( array( "\xE2\x80\x94", "\xE2\x80\x93" ), ',', $tekst );
		$tekst = preg_replace( '/\s*,\s*,/', ',', $tekst );
		$tekst = trim( preg_replace( '/[ \t]+/', ' ', $tekst ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $tekst ) > self::MAX_STIJL ) {
			$tekst = mb_substr( $tekst, 0, self::MAX_STIJL );
			$spatie = mb_strrpos( $tekst, ' ' );
			if ( $spatie > self::MAX_STIJL - 60 ) {
				$tekst = mb_substr( $tekst, 0, $spatie );
			}
		} elseif ( strlen( $tekst ) > self::MAX_STIJL ) {
			$tekst = substr( $tekst, 0, self::MAX_STIJL );
		}
		return $tekst;
	}

	/** De kaart op de beheerpagina. */
	public static function kaart() {
		$stijl  = self::stijl();
		$lengte = self::lengte();
		?>
		<div class="wss-ai-kaart">
			<h2><?php esc_html_e( 'Hoe moet er geschreven worden?', 'wss-ai' ); ?></h2>
			<p class="wss-ai-mut">
				<?php esc_html_e( 'Dit geldt voor alle teksten die de AI voor deze webshop schrijft. Je kunt het altijd aanpassen; bestaande teksten veranderen er niet van.', 'wss-ai' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wss_ai_instellingen">
				<?php wp_nonce_field( 'wss_ai_instellingen' ); ?>

				<p>
					<label for="wss_ai_stijl"><strong><?php esc_html_e( 'Schrijfstijl', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut">
						<?php esc_html_e( 'Vertel in je eigen woorden hoe je wilt dat er over je producten geschreven wordt. Toon, woordkeus, wat je juist niet wilt zien.', 'wss-ai' ); ?>
					</span>
				</p>
				<textarea
					id="wss_ai_stijl"
					name="wss_ai_stijl"
					rows="4"
					class="large-text"
					maxlength="<?php echo esc_attr( self::MAX_STIJL ); ?>"
					placeholder="<?php esc_attr_e( 'Bijvoorbeeld: schrijf warm en persoonlijk, korte zinnen, geen moeilijke woorden. Noem nooit dat iets duurzaam is, want dat kan ik niet hard maken.', 'wss-ai' ); ?>"><?php echo esc_textarea( $stijl ); ?></textarea>

				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Weet je het even niet? Kies een voorbeeld en pas het aan:', 'wss-ai' ); ?>
					<?php foreach ( self::voorbeelden() as $naam => $tekst ) : ?>
						<button type="button" class="button-link wss-ai-voorbeeld" data-tekst="<?php echo esc_attr( $tekst ); ?>"><?php echo esc_html( $naam ); ?></button>
					<?php endforeach; ?>
				</p>

				<p>
					<label for="wss_ai_lengte"><strong><?php esc_html_e( 'Standaardlengte van de productomschrijving', 'wss-ai' ); ?></strong></label><br>
					<select id="wss_ai_lengte" name="wss_ai_lengte">
						<?php foreach ( self::lengtes() as $waarde => $label ) : ?>
							<option value="<?php echo esc_attr( $waarde ); ?>" <?php selected( $lengte, $waarde ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Bij een product kun je hier per keer van afwijken. Hoe lang de tekst echt wordt hangt ook af van hoeveel er over dat product is ingevuld: staat er weinig, dan blijft hij korter, ook als je Uitgebreid kiest. Dat is expres, want anders wordt hij opgevuld met dingen die niet kloppen.', 'wss-ai' ); ?>
					<br>
					<?php esc_html_e( 'De SEO-titel en de SEO-omschrijving hebben een vaste lengte: die bepaalt Google, niet wij.', 'wss-ai' ); ?>
				</p>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'wss-ai' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
