<?php
/**
 * Het maandtegoed voor teksten en afbeeldingen.
 *
 * WAAROM DIT HIER EEN METER IS EN GEEN SLOT
 * Wat hier staat komt uit een optie in de database van de klant. Die kan hij
 * zelf wijzigen, en elke andere plugin op zijn site ook. Zou dit het enige slot
 * zijn, dan was het geen slot maar een gordijn. Het echte weigeren gebeurt bij
 * Webshopschool, net als bij modulesUit.
 *
 * Wat het hier wel doet zijn twee dingen die de server niet kan:
 *  1. Vertellen hoeveel er nog over is, voordat iemand op een knop drukt.
 *  2. Een aanvraag die toch niets zou opleveren niet eens versturen, zodat de
 *     klant meteen antwoord heeft in plaats van na dertig seconden wachten.
 *
 * WAAROM ALLES BLIJFT ZOALS HET WAS ZOLANG DE SERVER NIETS ZEGT
 * Zolang Webshopschool geen tegoed meestuurt is er geen tegoed, staat er geen
 * meter en verandert er niets aan wie wat mag. Dat is met opzet: anders zou
 * elke webshop die deze versie binnenkrijgt zijn teksten en afbeeldingen
 * kwijtraken op het moment dat hij bijwerkt, en ze pas terugkrijgen als de
 * server bijgepraat is. Een update hoort niets af te pakken.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Budget {

	const OPTIE = 'wss_ai_budget';

	/** De onderdelen die per keer geld kosten. */
	const BETAALD = array( 'teksten', 'afbeeldingen' );

	/**
	 * Wat we van de server weten.
	 *
	 * @return array
	 */
	private static function gegevens() {
		$g = get_option( self::OPTIE, array() );
		return is_array( $g ) ? $g : array();
	}

	/**
	 * Heeft Webshopschool ons ooit iets over tegoed verteld?
	 *
	 * Zolang dit nee is doet deze hele klasse niets. Zie de uitleg bovenaan.
	 *
	 * @return bool
	 */
	public static function bekend() {
		$g = self::gegevens();
		return ! empty( $g['bekend'] );
	}

	/**
	 * Is dit een beheerklant?
	 *
	 * @return bool
	 */
	public static function beheer() {
		$g = self::gegevens();
		return ! empty( $g['beheer'] );
	}

	/**
	 * Mag deze webshop de onderdelen gebruiken die per keer geld kosten?
	 *
	 * Weten we niets, dan ja: zie de uitleg bovenaan. Weten we het wel, dan
	 * alleen bij een beheerklant. Een gewone klant ziet die onderdelen dus
	 * helemaal niet staan, en hoort ook niet te merken dat ze bestaan.
	 *
	 * @return bool
	 */
	public static function mag_ai() {
		return ! self::bekend() || self::beheer();
	}

	/** Het tegoed per maand. */
	public static function bedrag() {
		$g = self::gegevens();
		return isset( $g['bedrag'] ) ? (float) $g['bedrag'] : 0.0;
	}

	/** Wat er deze maand af is. */
	public static function gebruikt() {
		$g = self::gegevens();
		return isset( $g['gebruikt'] ) ? (float) $g['gebruikt'] : 0.0;
	}

	/** Wat er nog over is. */
	public static function over() {
		$g = self::gegevens();
		if ( isset( $g['over'] ) ) {
			return max( 0.0, (float) $g['over'] );
		}
		return max( 0.0, self::bedrag() - self::gebruikt() );
	}

	/** De datum waarop er weer een nieuw tegoed klaarstaat. */
	public static function reset_op() {
		$g = self::gegevens();
		return isset( $g['reset'] ) ? (string) $g['reset'] : '';
	}

	/**
	 * Is er een tegoed van kracht waar we iets over kunnen zeggen?
	 *
	 * @return bool
	 */
	public static function aan() {
		return self::bekend() && self::beheer() && self::bedrag() > 0;
	}

	/**
	 * Is er nog ruimte?
	 *
	 * Geen tegoed van kracht betekent hier ja. Wie geen meter heeft wordt door
	 * deze klasse niet tegengehouden; dat blijft aan de server.
	 *
	 * @return bool
	 */
	public static function ruimte() {
		return ! self::aan() || self::over() > 0;
	}

	/**
	 * Wat een tekst of een afbeelding ongeveer kost.
	 *
	 * Die prijzen staan bij Webshopschool en niet hier: ze hangen af van het
	 * model dat op dat moment gebruikt wordt, en dat verandert vaker dan de
	 * plugin. Weten we het niet, dan komt er nul terug en laten we het onderwerp
	 * met rust in plaats van een getal te verzinnen.
	 *
	 * @param string $soort tekst of afbeelding.
	 * @return float Nul als we het niet weten.
	 */
	public static function prijs( $soort ) {
		$g = self::gegevens();
		$p = isset( $g['prijzen'] ) && is_array( $g['prijzen'] ) ? $g['prijzen'] : array();
		return isset( $p[ $soort ] ) ? (float) $p[ $soort ] : 0.0;
	}

	/**
	 * Hoeveel je er met wat er over is ongeveer nog kunt maken.
	 *
	 * @param string $soort tekst of afbeelding.
	 * @return int Nul als we het niet kunnen zeggen.
	 */
	public static function ongeveer( $soort ) {
		$prijs = self::prijs( $soort );
		if ( $prijs <= 0 ) {
			return 0;
		}
		return self::rond( (int) floor( self::over() / $prijs ) );
	}

	/**
	 * Een getal waar je "ongeveer" voor kunt zetten zonder te liegen.
	 *
	 * 283 teksten is geen belofte die we kunnen waarmaken, want de ene tekst
	 * kost meer dan de andere. 280 leest als een schatting, en dat is het ook.
	 *
	 * @param int $n Getal.
	 * @return int
	 */
	private static function rond( $n ) {
		if ( $n < 10 ) {
			return $n;
		}
		if ( $n < 100 ) {
			return (int) ( floor( $n / 5 ) * 5 );
		}
		return (int) ( floor( $n / 10 ) * 10 );
	}

	/**
	 * Een bedrag zoals je het opschrijft. Platte tekst, dus escapen bij gebruik.
	 *
	 * @param float $bedrag Bedrag.
	 * @return string
	 */
	public static function euro( $bedrag ) {
		return '€ ' . number_format_i18n( (float) $bedrag, 2 );
	}

	/**
	 * De datum waarop het tegoed opnieuw begint, in gewone taal.
	 *
	 * @return string Leeg als de server geen datum meegaf.
	 */
	public static function reset_tekst() {
		$datum = self::reset_op();
		if ( '' === $datum ) {
			return '';
		}
		$tijd = strtotime( $datum );
		return $tijd ? date_i18n( 'j F', $tijd ) : '';
	}

	/* ---------------------------------------------------------------------
	 * Onthouden wat de server zegt
	 * ------------------------------------------------------------------- */

	/**
	 * Het antwoord van Webshopschool opslaan.
	 *
	 * WAAROM DIT NIET LEEGGEMAAKT WORDT ALS DE SERVER ER NIETS OVER ZEGT
	 * Bij modulesUit gebeurt dat wel: geen lijst is een lege lijst. Hier niet.
	 * Zou een antwoord zonder tegoed het opgeslagen tegoed wissen, dan werd
	 * bekend() weer nee en kwamen de betaalde onderdelen bij een gewone klant
	 * weer tevoorschijn. Bij twijfel is dit de kant die geen geld kost.
	 *
	 * @param mixed $ruw Wat er in het antwoord stond.
	 * @return void
	 */
	public static function onthoud( $ruw ) {
		if ( ! is_array( $ruw ) ) {
			return;
		}

		$prijzen = array();
		if ( isset( $ruw['prijzen'] ) && is_array( $ruw['prijzen'] ) ) {
			foreach ( array( 'tekst', 'afbeelding' ) as $soort ) {
				if ( isset( $ruw['prijzen'][ $soort ] ) && is_numeric( $ruw['prijzen'][ $soort ] ) ) {
					$prijzen[ $soort ] = max( 0.0, (float) $ruw['prijzen'][ $soort ] );
				}
			}
		}

		/* Een datum en niets anders. Wat hier binnenkomt gaat straks door
		   strtotime en daarna in beeld bij de klant. */
		$reset = '';
		if ( isset( $ruw['reset'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $ruw['reset'] ) ) {
			$reset = (string) $ruw['reset'];
		}

		$getal = function ( $sleutel ) use ( $ruw ) {
			return isset( $ruw[ $sleutel ] ) && is_numeric( $ruw[ $sleutel ] )
				? max( 0.0, (float) $ruw[ $sleutel ] )
				: 0.0;
		};

		$bedrag   = $getal( 'bedrag' );
		$gebruikt = $getal( 'gebruikt' );

		update_option(
			self::OPTIE,
			array(
				'bekend'   => true,
				'beheer'   => ! empty( $ruw['beheer'] ),
				'bedrag'   => $bedrag,
				'gebruikt' => $gebruikt,
				'over'     => isset( $ruw['over'] ) ? $getal( 'over' ) : max( 0.0, $bedrag - $gebruikt ),
				'reset'    => $reset,
				'prijzen'  => $prijzen,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Voordat er iets verstuurd wordt
	 * ------------------------------------------------------------------- */

	/**
	 * Mag er nog iets gemaakt worden?
	 *
	 * Zo nee, dan komt er een uitleg terug die zo aan de klant getoond kan
	 * worden. Hij hoort te horen dat zijn tegoed op is en wanneer er weer
	 * ruimte is, niet dat het "niet lukte".
	 *
	 * @return true|WP_Error
	 */
	public static function controleer() {
		if ( self::ruimte() ) {
			return true;
		}

		$wanneer = self::reset_tekst();

		if ( '' !== $wanneer ) {
			return new WP_Error(
				'tegoed-op',
				sprintf(
					/* translators: 1: datum, 2: bedrag. */
					__( 'Je tegoed voor deze maand is op. Op %1$s staat er weer %2$s voor je klaar.', 'wss-ai' ),
					$wanneer,
					self::euro( self::bedrag() )
				)
			);
		}

		return new WP_Error(
			'tegoed-op',
			__( 'Je tegoed voor deze maand is op. Volgende maand staat er weer een nieuw tegoed voor je klaar.', 'wss-ai' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Wat de klant ziet
	 * ------------------------------------------------------------------- */

	/**
	 * Een regel voor naast de knoppen.
	 *
	 * @return string HTML, of leeg als er geen tegoed van kracht is.
	 */
	public static function regel() {
		if ( ! self::aan() ) {
			return '';
		}

		if ( self::over() <= 0 ) {
			$wanneer = self::reset_tekst();
			$uit     = '<span class="wss-ai-tegoed is-op">' . esc_html__( 'Je tegoed voor deze maand is op.', 'wss-ai' );
			if ( '' !== $wanneer ) {
				$uit .= ' ' . sprintf(
					/* translators: %s: datum. */
					esc_html__( 'Op %s begint er een nieuw tegoed.', 'wss-ai' ),
					esc_html( $wanneer )
				);
			}
			return $uit . '</span>';
		}

		return '<span class="wss-ai-tegoed">'
			. sprintf(
				/* translators: 1: bedrag dat over is, 2: tegoed per maand. */
				esc_html__( 'Nog %1$s van je %2$s over deze maand.', 'wss-ai' ),
				'<strong>' . esc_html( self::euro( self::over() ) ) . '</strong>',
				esc_html( self::euro( self::bedrag() ) )
			)
			. '</span>';
	}

	/**
	 * Het blok op de pagina van Teksten en Afbeeldingen.
	 *
	 * Met een balk erbij, want een bedrag zegt weinig als je niet ziet hoe groot
	 * het geheel was.
	 *
	 * @return void
	 */
	public static function kaart() {
		if ( ! self::aan() ) {
			return;
		}

		$deel         = (int) round( self::over() / self::bedrag() * 100 );
		$deel         = max( 0, min( 100, $deel ) );
		$teksten      = self::ongeveer( 'tekst' );
		$afbeeldingen = self::ongeveer( 'afbeelding' );
		$wanneer      = self::reset_tekst();
		?>
		<div class="wss-ai-kaart wss-ai-tegoedkaart">
			<h2><?php esc_html_e( 'Je tegoed deze maand', 'wss-ai' ); ?></h2>

			<p class="wss-ai-tegoedbedrag">
				<strong><?php echo esc_html( self::euro( self::over() ) ); ?></strong>
				<span class="wss-ai-mut">
					<?php
					printf(
						/* translators: %s: tegoed per maand. */
						esc_html__( 'van je %s', 'wss-ai' ),
						esc_html( self::euro( self::bedrag() ) )
					);
					?>
				</span>
			</p>

			<div class="wss-ai-balk"><i style="width:<?php echo esc_attr( $deel ); ?>%"></i></div>

			<?php if ( $teksten || $afbeeldingen ) : ?>
				<p class="wss-ai-mut"><?php esc_html_e( 'Daarmee kun je ongeveer nog:', 'wss-ai' ); ?></p>
				<ul class="wss-ai-tegoedlijst">
					<?php if ( $teksten ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: aantal. */
								esc_html( _n( '%s tekst schrijven', '%s teksten schrijven', $teksten, 'wss-ai' ) ),
								esc_html( number_format_i18n( $teksten ) )
							);
							?>
						</li>
					<?php endif; ?>
					<?php if ( $afbeeldingen ) : ?>
						<li>
							<?php
							printf(
								/* translators: %s: aantal. */
								esc_html( _n( '%s afbeelding maken', '%s afbeeldingen maken', $afbeeldingen, 'wss-ai' ) ),
								esc_html( number_format_i18n( $afbeeldingen ) )
							);
							?>
						</li>
					<?php endif; ?>
				</ul>
				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Dat is een schatting, en het is het een of het ander, niet allebei. De ene tekst kost wat meer dan de andere, en bij een afbeelding hangt het af van wat je ermee laat doen.', 'wss-ai' ); ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $wanneer ) : ?>
				<p class="wss-ai-mut wss-ai-klein">
					<?php
					printf(
						/* translators: 1: datum, 2: bedrag. */
						esc_html__( 'Op %1$s staat er weer %2$s voor je klaar. Wat je deze maand niet gebruikt gaat niet mee naar volgende maand.', 'wss-ai' ),
						esc_html( $wanneer ),
						esc_html( self::euro( self::bedrag() ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
