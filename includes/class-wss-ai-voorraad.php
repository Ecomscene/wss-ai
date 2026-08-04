<?php
/**
 * Voorraadbeheer, overgenomen uit de losse plugin WC Central Stock Manager.
 *
 * WAAROM DE CODE ONGEWIJZIGD IS OVERGENOMEN
 * Dit draait bij klanten en het werkt. De verleiding om het bij de overname
 * meteen op te schonen is groot en precies verkeerd: elke wijziging is een kans
 * dat er iets stukgaat aan iets wat vandaag goed is, en dan sta je te zoeken of
 * dat aan de verhuizing lag of aan de opschoning. Er zijn dus maar twee dingen
 * veranderd, allebei omdat ze wel moesten:
 *
 *  1. Het menu. Was een subpagina onder WooCommerce, is nu een eigen item onder
 *     Producten, met de bijbehorende andere haaknaam voor de stijlen.
 *  2. De eigen updater is eruit. Deze plugin werkt zichzelf al bij; twee
 *     updaters die naar verschillende repositories kijken is vragen om een
 *     bestand dat door de een wordt overschreven en door de ander teruggezet.
 *
 * DE OUDE PLUGIN MAG NIET TEGELIJK AANSTAAN
 * Dan zouden dezelfde klassen, menu-items en ajax-acties twee keer geregistreerd
 * worden. PHP stopt bij een dubbele klassenaam met een fatale fout, en dat is een
 * witte pagina op de webshop van een klant. Vandaar de controle hieronder: staat
 * de oude er nog, dan doen wij niets en zeggen we wat er moet gebeuren.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Voorraad {

	const SLUG = 'wccsm-stock-manager';

	/** Draait het? Gebruikt door de overzichtspagina om de tegel te tonen. */
	private static $aan = false;

	public static function beschikbaar() {
		return self::$aan;
	}

	public static function init() {
		/* Na WooCommerce, want zonder WooCommerce valt er geen voorraad te
		   beheren. Prioriteit 25 zet ons ná de losse plugin, zodat we hem kunnen
		   zien staan in plaats van ernaast te gaan draaien. */
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 25 );
	}

	public static function laden() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		if ( defined( 'WCCSM_VERSION' ) || class_exists( 'WCCSM_Core' ) ) {
			/* De losse plugin staat nog aan. Niets laden, wel zeggen waarom het
			   voorraadbeheer niet in dit menu staat. Stil blijven zou een klant
			   laten zoeken naar een pagina die er bewust niet is. */
			add_action( 'admin_notices', array( __CLASS__, 'melding_oude_plugin' ) );
			return;
		}

		define( 'WCCSM_VERSION', WSS_AI_VERSIE );
		define( 'WCCSM_PLUGIN_DIR', WSS_AI_MAP . 'voorraad/' );
		define( 'WCCSM_PLUGIN_URL', plugin_dir_url( WSS_AI_BESTAND ) . 'voorraad/' );

		require_once WCCSM_PLUGIN_DIR . 'includes/class-wccsm-core.php';
		WCCSM_Core::instance();
		self::$aan = true;
	}

	public static function melding_oude_plugin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>WSS AI:</strong> ';
		echo esc_html__(
			'het voorraadbeheer zit nu in deze plugin, maar de losse plugin "WC Central Stock Manager" staat nog aan. Zolang die aanstaat blijft die de baas en verandert er niets voor je. Zet hem uit om het voorraadbeheer van WSS AI te gebruiken; je gegevens blijven staan, het is dezelfde tabel.',
			'wss-ai'
		);
		echo '</p></div>';
	}

	/**
	 * Meldt de HPOS-compatibiliteit aan, net als de losse plugin deed.
	 *
	 * Zonder dit zet WooCommerce een waarschuwing in het scherm van de klant dat
	 * een plugin misschien niet werkt met de nieuwe orderopslag. Wij raken orders
	 * niet aan, dus die waarschuwing zou nergens over gaan.
	 */
	public static function hpos() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WSS_AI_BESTAND,
				true
			);
		}
	}
}
