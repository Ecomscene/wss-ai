<?php
/**
 * Het SEO-traject van deze webshop, in het wp-admin van de ondernemer.
 *
 * WAT DIT WEL EN NIET IS
 * Een venster, geen bedieningspaneel. Je ziet welk plan er loopt, wat er af is
 * en wat eraan komt. Starten, pauzeren en goedkeuren gebeurt bij Webshopschool:
 * een traject dat de klant zelf kan bijsturen is een traject zonder plan.
 *
 * WAAROM HIER GEEN ENKELE BEREKENING STAAT
 * De weken, de voortgang en de samenvattingen komen kant en klaar van de
 * server. Deze klasse haalt op, bewaart dat tien minuten, en zet het neer. Zou
 * de plugin zelf tellen welke week het is, dan moest bij elke wijziging in de
 * telling iedere webshop bijwerken. Zie de regel bovenaan wss-ai.php.
 *
 * DE NAAM
 * WSS_AI_SEO bestaat al: dat is de module die SEO-teksten bij een product
 * schrijft. Deze heet daarom Seoplan. Twee dingen met "SEO" in de naam die iets
 * anders doen is verwarrend, maar een fatale fout bij het inladen is erger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Seoplan {

	const SLUG  = 'wss-ai-seoplan';
	const CACHE = 'wss_ai_seoplan';

	/** Draait hij? Gebruikt door het menu voor het item en de tegel. */
	private static $aan = false;

	public static function beschikbaar() {
		return self::$aan;
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 20 );
	}

	/**
	 * Aanzetten, maar alleen als Webshopschool dat met zoveel woorden heeft
	 * gedaan. Dit is een opt-in module: weten we het niet, dan is het antwoord
	 * nee. Zie module_aan_optin() in class-wss-ai-koppeling.php.
	 */
	public static function laden() {
		if ( ! WSS_AI_Koppeling::module_aan_optin( 'seoplan' ) ) {
			return;
		}
		self::$aan = true;
		add_action( 'admin_init', array( __CLASS__, 'verversen' ) );
	}

	/**
	 * Het plan ophalen, tien minuten onthouden.
	 *
	 * Lukt het niet, dan komt er een WP_Error terug en geen lege lijst. "Er
	 * loopt geen traject" en "we konden het niet ophalen" zijn twee
	 * verschillende dingen, en het eerste tonen terwijl het tweede waar is laat
	 * een klant denken dat wij niets voor hem doen.
	 */
	public static function plan() {
		$onthouden = get_transient( self::CACHE );
		if ( is_array( $onthouden ) ) {
			return $onthouden;
		}

		$uit = WSS_AI_Koppeling::vraag_get( '/seoplan' );
		if ( is_wp_error( $uit ) ) {
			return $uit;
		}

		set_transient( self::CACHE, $uit, 10 * MINUTE_IN_SECONDS );
		return $uit;
	}

	/**
	 * Handmatig opnieuw ophalen.
	 *
	 * Tien minuten is kort, maar niet als je net van ons hoort dat er iets
	 * klaar is en je meteen gaat kijken. Dan wil je niet wachten op een klok
	 * die je niet ziet.
	 */
	public static function verversen() {
		if ( ! isset( $_GET['wss_ai_seoplan_vers'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wss_ai_seoplan_vers' ) ) {
			return;
		}
		delete_transient( self::CACHE );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/* ---------------- kleine hulpjes voor de opmaak ---------------- */

	/** Een datum uit het antwoord van de server, in de taal en tijdzone van de site. */
	private static function datum( $iso ) {
		$t = strtotime( (string) $iso );
		return $t ? wp_date( 'j F Y', $t ) : '';
	}

	/** Hoe deze week ervoor staat, in gewone taal. */
	private static function stand_van_week( $week, $nu ) {
		if ( ! empty( $week['af'] ) ) {
			$wanneer = self::datum( isset( $week['wanneer'] ) ? $week['wanneer'] : '' );
			return $wanneer
				/* translators: %s is een datum. */
				? sprintf( __( 'Afgerond op %s', 'wss-ai' ), $wanneer )
				: __( 'Afgerond', 'wss-ai' );
		}
		$nummer = (int) $week['week'];
		if ( $nu && $nummer === $nu ) {
			return __( 'Hier zijn we nu mee bezig', 'wss-ai' );
		}
		if ( $nu && $nummer < $nu ) {
			return __( 'Loopt nog', 'wss-ai' );
		}
		$wanneer = self::datum( isset( $week['wanneer'] ) ? $week['wanneer'] : '' );
		return $wanneer
			/* translators: %s is een datum. */
			? sprintf( __( 'Staat gepland voor %s', 'wss-ai' ), $wanneer )
			: __( 'Komt eraan', 'wss-ai' );
	}

	/* ---------------- de pagina ---------------- */

	public static function pagina() {
		$plan = self::plan();

		if ( is_wp_error( $plan ) ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'We konden je SEO-plan even niet ophalen.', 'wss-ai' ) . ' '
				. esc_html( $plan->get_error_message() )
				. '</p></div>';
			return;
		}

		if ( empty( $plan['heeftPlan'] ) ) {
			echo '<div class="wss-ai-kaart"><p>'
				. esc_html(
					! empty( $plan['uitleg'] )
						? (string) $plan['uitleg']
						: __( 'Er loopt op dit moment geen SEO-traject voor je webshop.', 'wss-ai' )
				)
				. '</p></div>';
			return;
		}

		$weken   = isset( $plan['weken'] ) && is_array( $plan['weken'] ) ? $plan['weken'] : array();
		$totaal  = max( 1, (int) ( isset( $plan['looptijdWeken'] ) ? $plan['looptijdWeken'] : count( $weken ) ) );
		$af      = (int) ( isset( $plan['afgerond'] ) ? $plan['afgerond'] : 0 );
		$nu      = (int) ( isset( $plan['huidigeWeek'] ) ? $plan['huidigeWeek'] : 0 );
		$maanden = (int) ( isset( $plan['maanden'] ) ? $plan['maanden'] : 0 );
		$stand   = isset( $plan['stand'] ) ? (string) $plan['stand'] : '';
		$gestart = self::datum( isset( $plan['gestart'] ) ? $plan['gestart'] : '' );
		$deel    = min( 100, (int) round( $af / $totaal * 100 ) );
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'Webshopschool werkt aan je vindbaarheid in Google. Hieronder zie je het plan: wat we deze maanden doen, wat er af is en wat eraan komt. Je hoeft hier zelf niets te doen.', 'wss-ai' ); ?>
		</p>

		<?php if ( 'gepauzeerd' === $stand ) : ?>
			<div class="notice notice-warning"><p>
				<?php esc_html_e( 'Je traject staat even stil. We hebben daar contact met je over; er gebeurt zolang niets aan je site.', 'wss-ai' ); ?>
			</p></div>
		<?php elseif ( 'afgerond' === $stand ) : ?>
			<div class="notice notice-success"><p>
				<?php esc_html_e( 'Dit traject is afgerond. Alles hieronder blijft staan zodat je kunt teruglezen wat er gedaan is.', 'wss-ai' ); ?>
			</p></div>
		<?php endif; ?>

		<div class="wss-ai-kaart wss-ai-seoplan">
			<h2><?php esc_html_e( 'Je traject', 'wss-ai' ); ?></h2>
			<div class="wss-ai-cijfers">
				<div class="wss-ai-cijfer">
					<b><?php echo esc_html( $maanden ? $maanden : '-' ); ?></b>
					<span><?php esc_html_e( 'maanden looptijd', 'wss-ai' ); ?></span>
				</div>
				<div class="wss-ai-cijfer">
					<b><?php echo esc_html( $nu ? $nu . ' / ' . $totaal : '-' ); ?></b>
					<span><?php esc_html_e( 'week waar we nu zijn', 'wss-ai' ); ?></span>
				</div>
				<div class="wss-ai-cijfer">
					<b><?php echo esc_html( $af . ' / ' . $totaal ); ?></b>
					<span><?php esc_html_e( 'weken afgerond', 'wss-ai' ); ?></span>
				</div>
				<div class="wss-ai-cijfer">
					<b><?php echo esc_html( $gestart ? $gestart : '-' ); ?></b>
					<span><?php esc_html_e( 'gestart op', 'wss-ai' ); ?></span>
				</div>
			</div>
			<div class="wss-ai-balk"><i style="width:<?php echo esc_attr( $deel ); ?>%"></i></div>
		</div>

		<div class="wss-ai-kaart wss-ai-seoplan">
			<h2><?php esc_html_e( 'Week voor week', 'wss-ai' ); ?></h2>

			<?php if ( ! $weken ) : ?>
				<p class="wss-ai-mut">
					<?php esc_html_e( 'De weekindeling staat nog niet klaar. Wij zien dat ook; je hoeft niets te doen.', 'wss-ai' ); ?>
				</p>
			<?php else : ?>
				<div class="wss-ai-weken">
					<?php
					foreach ( $weken as $w ) :
						if ( ! is_array( $w ) ) {
							continue;
						}
						$nummer = (int) ( isset( $w['week'] ) ? $w['week'] : 0 );
						$klasse = 'wss-ai-week';
						if ( ! empty( $w['af'] ) ) {
							$klasse .= ' is-af';
						} elseif ( $nu && $nummer === $nu ) {
							$klasse .= ' is-nu';
						}
						?>
						<div class="<?php echo esc_attr( $klasse ); ?>">
							<span class="wss-ai-week-nr">
								<?php
								/* translators: %d is het weeknummer binnen het traject. */
								printf( esc_html__( 'wk %d', 'wss-ai' ), (int) $nummer );
								?>
							</span>
							<div class="wss-ai-week-inhoud">
								<strong><?php echo esc_html( isset( $w['titel'] ) ? (string) $w['titel'] : '' ); ?></strong>
								<span class="wss-ai-week-stand"><?php echo esc_html( self::stand_van_week( $w, $nu ) ); ?></span>
								<?php if ( ! empty( $w['samenvatting'] ) ) : ?>
									<p class="wss-ai-week-tekst"><?php echo nl2br( esc_html( (string) $w['samenvatting'] ) ); ?></p>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="wss-ai-mut wss-ai-klein">
				<?php esc_html_e( 'Deze pagina wordt elke tien minuten bijgewerkt.', 'wss-ai' ); ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::SLUG . '&wss_ai_seoplan_vers=1' ), 'wss_ai_seoplan_vers' ) ); ?>">
					<?php esc_html_e( 'Nu bijwerken', 'wss-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}
