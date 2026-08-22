<?php
/**
 * De nieuwsbriefmodule, overgenomen uit WS Flow Mailer.
 *
 * WAAROM DEZE ALS ENIGE STANDAARD UIT STAAT
 * De andere onderdelen doen niets tot iemand op een knop drukt. Deze niet. Zodra
 * hij draait haakt hij in op de winkelwagen en de afrekenpagina, legt hij
 * e-mailadressen en winkelwagens van bezoekers vast, en verstuurt hij om de vijf
 * minuten post naar klanten van onze klant.
 *
 * Dat is geen gereedschap dat je even meelevert. Het verzamelt persoonsgegevens
 * op een winkel die daar niet om gevraagd heeft, en het stuurt mail namens
 * iemand anders. Vandaar: aan de kant van Webshopschool aanzetten, per shop, en
 * pas nadat er met die ondernemer over gesproken is.
 *
 * UIT BETEKENT HIER ECHT UIT
 * Niet "hooks die niets doen", maar geen enkele hook. Er wordt geen bestand
 * ingeladen, geen tabel aangemaakt, geen script op de winkel gezet en geen
 * terugkerende taak ingepland. Een module die uitstaat hoort onvindbaar te zijn,
 * ook in de laadtijd van de pagina.
 *
 * WAT ER IS VERANDERD AAN DE OVERGENOMEN CODE
 * Twee dingen, allebei omdat het moest:
 *  1. De menu's hingen aan een eigen hoofditem en hangen nu onder WSS Tools.
 *  2. De eigen updater is eruit; deze plugin werkt zichzelf al bij.
 * De rest staat er zoals het stond. Wat werkt hoort niet te veranderen tijdens
 * een verhuizing, anders weet je bij een storing niet of het aan het een of aan
 * het ander lag.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Mailer {

	/** Draait hij? Gebruikt door de overzichtspagina voor de tegel. */
	private static $aan = false;

	public static function beschikbaar() {
		return self::$aan;
	}

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 20 );
	}

	public static function laden() {
		/* De schakelaar staat aan de kant van Webshopschool en is opt-in: een
		   onbekend antwoord betekent hier UIT, niet AAN. Bij de andere modules
		   is dat andersom, en dat verschil is precies het punt. */
		if ( ! WSS_AI_Koppeling::module_aan_optin( 'nieuwsbrief' ) ) {
			return;
		}

		if ( defined( 'WSFM_VERSION' ) || class_exists( 'WSFM_Install' ) ) {
			/* De losse plugin staat nog aan. Twee keer dezelfde klassen laden is
			   een fatale fout, en dat is een witte pagina op een webshop. */
			add_action( 'admin_notices', array( __CLASS__, 'melding_oude_plugin' ) );
			return;
		}

		define( 'WSFM_VERSION', WSS_AI_VERSIE );
		define( 'WSFM_PLUGIN_FILE', WSS_AI_BESTAND );
		define( 'WSFM_PLUGIN_DIR', WSS_AI_MAP . 'mailer/' );
		define( 'WSFM_PLUGIN_URL', plugin_dir_url( WSS_AI_BESTAND ) . 'mailer/' );

		$map = WSFM_PLUGIN_DIR . 'includes/';
		foreach ( array(
			'class-install', 'class-credentials', 'class-mail-provider', 'class-sigv4',
			'providers/class-provider-ses', 'providers/class-provider-brevo',
			'providers/class-provider-hub',
			'class-suppression', 'class-template-engine', 'class-templates',
			'class-newsletter-render', 'class-newsletters',
			'class-subscribers', 'class-popup',
			'class-flows', 'class-flow-conditions', 'class-queue', 'class-queue-processor',
			'class-cart-tracking', 'class-flow-engine',
			'class-unsubscribe', 'class-sns-webhook', 'class-identity',
			'class-admin-settings', 'class-flow-admin-ui',
		) as $bestand ) {
			require_once $map . $bestand . '.php';
		}

		/* De tabellen. In de losse plugin gebeurde dit bij het activeren; hier is
		   er geen activering, dus doet maybe_upgrade het werk. Die kijkt naar een
		   opgeslagen versienummer en doet niets als er niets veranderd is. */
		WSFM_Install::maybe_upgrade();

		WSFM_Flow_Engine::init();
		WSFM_Unsubscribe::init();
		WSFM_SNS_Webhook::init();
		WSFM_Identity::init();
		WSFM_Popup::init();

		if ( is_admin() ) {
			new WSFM_Flow_Admin_UI();
			new WSFM_Admin_Settings();
		}

		self::$aan = true;
	}

	/**
	 * Alles stilzetten als de module wordt uitgezet.
	 *
	 * De terugkerende taak in Action Scheduler blijft anders staan, en die zou
	 * over vijf minuten gewoon weer een ronde mail versturen terwijl de module
	 * volgens het paneel uit is. Een schakelaar die de post niet stopt is geen
	 * schakelaar.
	 */
	public static function stilzetten() {
		if ( class_exists( 'WSFM_Flow_Engine' ) && method_exists( 'WSFM_Flow_Engine', 'unschedule_recurring_actions' ) ) {
			WSFM_Flow_Engine::unschedule_recurring_actions();
		}
	}

	/**
	 * Het kaartje bovenaan de mailerinstellingen: van welk adres mag deze shop
	 * mailen, en is dat al rond?
	 *
	 * WAAROM DIT HIER STAAT EN NIET IN DE MAILER ZELF
	 * De mailer is overgenomen zoals hij was, en dat blijft zo. Dit is iets van
	 * WSS Tools: het gaat over wat Webshopschool voor deze webshop heeft
	 * ingericht. In de mailerpagina staat er dus maar één regel die hierheen
	 * wijst.
	 *
	 * WAAROM ER GEEN DNS-REGELS IN STAAN
	 * De winkelier kan die niet zetten; Joey beheert de DNS. Ze hier tonen zou
	 * hem ongerust maken over iets waar hij niets aan kan doen. Ze staan in het
	 * beheerpaneel, bij degene die ze wel kan plaatsen.
	 */
	public static function afzenderkaart() {
		$a      = WSS_AI_Koppeling::afzender();
		$domein = $a['domein'];

		/* Niets weten is geen boodschap. Een kaartje dat zegt dat we het even
		   niet konden ophalen helpt niemand en staat er alleen maar. */
		if ( 'onbekend' === $a['stand'] ) {
			return;
		}

		if ( 'gelukt' === $a['stand'] ) {
			$soort = 'notice-success';
			$kop   = __( 'Je afzender is klaar', 'wss-ai' );
			$tekst = $domein
				/* translators: %s: het domein van de webshop. */
				? sprintf( __( 'Je nieuwsbrief en je automatische mails gaan uit vanaf %s, via Webshopschool. Je hoeft hieronder geen sleutels of afzenderadres in te vullen; dat is allemaal geregeld.', 'wss-ai' ), $domein )
				: __( 'Je mail gaat via Webshopschool. Je hoeft hieronder geen sleutels in te vullen; dat is allemaal geregeld.', 'wss-ai' );
		} elseif ( 'wacht' === $a['stand'] ) {
			$soort = 'notice-warning';
			$kop   = __( 'We zijn je afzender aan het instellen', 'wss-ai' );
			$tekst = $domein
				/* translators: %s: het domein van de webshop. */
				? sprintf( __( 'We regelen dat je mail vanaf %s verstuurd mag worden. Dat duurt meestal een paar uur. Je hoeft niets te doen; zodra het rond is staat het hier.', 'wss-ai' ), $domein )
				: __( 'We regelen op dit moment vanaf welk adres je mail verstuurd mag worden. Je hoeft niets te doen.', 'wss-ai' );
		} elseif ( 'mislukt' === $a['stand'] ) {
			$soort = 'notice-error';
			$kop   = __( 'Het instellen van je afzender is niet gelukt', 'wss-ai' );
			$tekst = __( 'Webshopschool kijkt hiernaar. Je hoeft zelf niets te doen, en je bestaande mail blijft gewoon werken.', 'wss-ai' );
		} else {
			$soort = 'notice-info';
			$kop   = __( 'Je afzender is nog niet ingesteld', 'wss-ai' );
			$tekst = __( 'Webshopschool regelt dit voor je voordat er post uitgaat. Je hoeft zelf niets te doen.', 'wss-ai' );
		}

		echo '<div class="notice ' . esc_attr( $soort ) . ' inline" style="margin:16px 0;padding:10px 12px">';
		echo '<p style="margin:0"><strong>' . esc_html( $kop ) . '</strong><br>' . esc_html( $tekst ) . '</p>';
		echo '</div>';
	}

	public static function melding_oude_plugin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>WSS Tools:</strong> ';
		echo esc_html__(
			'de nieuwsbrief zit nu in deze plugin, maar de losse plugin "WS Flow Mailer" staat nog aan. Zolang die aanstaat blijft die de baas. Zet hem uit om de versie van WSS Tools te gebruiken; je flows, templates en gegevens blijven staan, het zijn dezelfde tabellen.',
			'wss-ai'
		);
		echo '</p></div>';
	}
}
