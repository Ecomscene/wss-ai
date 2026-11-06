<?php
/**
 * Teksten en foto's op het EIGEN ChatGPT-abonnement van de winkelier.
 *
 * WAT DIT IS
 * Standaard schrijft en tekent Webshopschool met zijn eigen AI, betaald uit het
 * maandtegoed van de shop. Wie zijn eigen ChatGPT heeft gekoppeld op
 * platform.collegaonline.nl kan hier kiezen dat het werk via dat abonnement
 * loopt. Dezelfde koppeling gebruikt de WhatsApp-assistent al.
 *
 * WAT ER HIER GEBEURT EN WAT NIET
 * Deze plugin bewaart alleen de KEUZE. Geen sleutel, geen token, geen wachtwoord:
 * het koppelen gebeurt op het platform, en het praten met ChatGPT gebeurt op de
 * server van Webshopschool. Hier staat dus niets dat uit een gehackte site te
 * plukken valt.
 *
 * GEEN STILLE TERUGVAL
 * Kies je je eigen ChatGPT en werkt die even niet (niet gekoppeld, limiet op),
 * dan krijg je dat te horen. We schakelen dan niet ongemerkt over naar het
 * tegoed: je zou geld kwijt zijn terwijl je dacht dat het op je abonnement liep.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Eigen_AI {

	const OPTIE = 'wss_ai_ai_bron';
	const STAND = 'wss_ai_eigen_stand';

	/** Waar de winkelier zijn ChatGPT koppelt. */
	const PLATFORM = 'https://platform.collegaonline.nl';

	public static function init() {
		add_action( 'admin_post_wss_ai_ai_bron', array( __CLASS__, 'opslaan' ) );
	}

	/**
	 * Heeft de winkelier voor zijn eigen ChatGPT gekozen?
	 *
	 * @return bool
	 */
	public static function is_eigen() {
		return 'eigen' === get_option( self::OPTIE, 'webshopschool' );
	}

	/**
	 * Hoe staat de koppeling ervoor, volgens Webshopschool.
	 *
	 * Vijf minuten onthouden: dit verandert alleen als iemand op het platform
	 * iets doet, en dan is er een knop om het meteen opnieuw te laten bekijken.
	 * Lukt het ophalen niet, dan komt er 'onbekend' terug en geen verzonnen stand.
	 *
	 * @param bool $vers Niet uit het geheugen, opnieuw vragen.
	 * @return array { status, tekst, beelden, uitleg, klant }
	 */
	public static function stand( $vers = false ) {
		if ( ! $vers ) {
			$onthouden = get_transient( self::STAND );
			if ( is_array( $onthouden ) ) {
				return $onthouden;
			}
		}

		$leeg = array( 'status' => 'onbekend', 'tekst' => false, 'beelden' => false, 'uitleg' => '', 'klant' => true );
		if ( ! WSS_AI_Koppeling::is_actief() ) {
			return $leeg;
		}

		$uit = WSS_AI_Koppeling::vraag_get( '/ai-account', 10 );
		if ( is_wp_error( $uit ) || ! is_array( $uit ) ) {
			return $leeg;
		}

		$stand = array(
			'status'  => isset( $uit['status'] ) ? sanitize_key( $uit['status'] ) : 'onbekend',
			'tekst'   => ! empty( $uit['tekst'] ),
			'beelden' => ! empty( $uit['beelden'] ),
			'uitleg'  => isset( $uit['uitleg'] ) ? sanitize_text_field( $uit['uitleg'] ) : '',
			'klant'   => ! isset( $uit['klant'] ) || ! empty( $uit['klant'] ),
		);
		set_transient( self::STAND, $stand, 5 * MINUTE_IN_SECONDS );
		return $stand;
	}

	public static function opslaan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_ai_bron' );

		$bron = isset( $_POST['bron'] ) ? sanitize_key( wp_unslash( $_POST['bron'] ) ) : 'webshopschool';
		update_option( self::OPTIE, 'eigen' === $bron ? 'eigen' : 'webshopschool' );
		delete_transient( self::STAND );

		$terug = isset( $_POST['terug'] ) ? sanitize_key( wp_unslash( $_POST['terug'] ) ) : 'wss-ai-teksten';
		if ( ! in_array( $terug, array( 'wss-ai-teksten', 'wss-ai-afbeeldingen' ), true ) ) {
			$terug = 'wss-ai-teksten';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . $terug . '&wss_ai_bewaard=1#wss-ai-eigen' ) );
		exit;
	}

	/**
	 * De kaart op de pagina's Teksten en Afbeeldingen.
	 *
	 * @param string $pagina De slug van de pagina waar hij staat, om naar terug te gaan.
	 */
	public static function kaart( $pagina = 'wss-ai-teksten' ) {
		$eigen = self::is_eigen();
		$stand = $eigen ? self::stand( isset( $_GET['wss_ai_eigen_check'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wss-ai-kaart" id="wss-ai-eigen">
			<h2><?php esc_html_e( 'Welke AI doet het werk?', 'wss-ai' ); ?></h2>
			<p class="wss-ai-mut">
				<?php esc_html_e( 'Heb je zelf een ChatGPT-abonnement? Dan kun je je teksten en foto\'s daarmee laten maken in plaats van met het tegoed van Webshopschool. Koppelen doe je één keer op het platform; daarna kies je het hier.', 'wss-ai' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wss_ai_ai_bron">
				<input type="hidden" name="terug" value="<?php echo esc_attr( $pagina ); ?>">
				<?php wp_nonce_field( 'wss_ai_ai_bron' ); ?>

				<p>
					<label>
						<input type="radio" name="bron" value="webshopschool" <?php checked( ! $eigen ); ?>>
						<strong><?php esc_html_e( 'De AI van Webshopschool', 'wss-ai' ); ?></strong>
						<span class="wss-ai-mut"><?php esc_html_e( '(gaat van je tegoed af)', 'wss-ai' ); ?></span>
					</label><br>
					<label>
						<input type="radio" name="bron" value="eigen" <?php checked( $eigen ); ?>>
						<strong><?php esc_html_e( 'Mijn eigen ChatGPT', 'wss-ai' ); ?></strong>
						<span class="wss-ai-mut"><?php esc_html_e( '(loopt via je eigen abonnement)', 'wss-ai' ); ?></span>
					</label>
				</p>

				<?php if ( $eigen && $stand ) : ?>
					<p>
						<?php
						if ( $stand['tekst'] && $stand['beelden'] ) {
							echo '<span class="wss-ai-goed">' . esc_html__( 'Je ChatGPT is gekoppeld, voor teksten en foto\'s.', 'wss-ai' ) . '</span>';
						} elseif ( $stand['tekst'] ) {
							echo '<span class="wss-ai-goed">' . esc_html__( 'Je ChatGPT is gekoppeld voor teksten.', 'wss-ai' ) . '</span> ';
							echo '<span class="wss-ai-let-op">' . esc_html__( 'Foto\'s staan nog uit: zet de Fotostudio aan op het platform.', 'wss-ai' ) . '</span>';
						} elseif ( 'onbekend' === $stand['status'] ) {
							echo '<span class="wss-ai-onbekend">' . esc_html__( 'We konden niet nagaan of je ChatGPT gekoppeld is.', 'wss-ai' ) . '</span>';
						} else {
							echo '<span class="wss-ai-let-op">' . esc_html( $stand['uitleg'] ? $stand['uitleg'] : __( 'Je ChatGPT is nog niet gekoppeld.', 'wss-ai' ) ) . '</span>';
						}
						?>
					</p>
					<p class="wss-ai-mut wss-ai-klein">
						<?php esc_html_e( 'Werkt je eigen ChatGPT even niet, dan zeggen we dat. We schakelen dan niet stilletjes over naar het tegoed. Achtergrond weghalen gaat altijd via Webshopschool: dat is knippen, geen tekenen.', 'wss-ai' ); ?>
					</p>
				<?php endif; ?>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'wss-ai' ); ?></button>
					<a class="button" href="<?php echo esc_url( self::PLATFORM ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'ChatGPT koppelen op het platform', 'wss-ai' ); ?></a>
					<?php if ( $eigen ) : ?>
						<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $pagina . '&wss_ai_eigen_check=1#wss-ai-eigen' ) ); ?>"><?php esc_html_e( 'Opnieuw controleren', 'wss-ai' ); ?></a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}
}
