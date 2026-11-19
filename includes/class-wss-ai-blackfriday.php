<?php
/**
 * Black Friday: een eigen plek in wp-admin voor de Black Friday-actie.
 *
 * WAAROM EEN EIGEN HOOFDMENU-ITEM
 * Joey wil (19-11-2026) bij alle maandklanten dezelfde plek waar de Black
 * Friday-actie straks wordt klaargezet, met structuur en bij iedereen gelijk.
 * Daarom een hoofdmenu-item bovenin, direct onder WSS Tools, en niet ergens
 * onder een submenu waar niemand het vindt.
 *
 * VOOR NU LEEG
 * De pagina is met opzet blanco. Wat erop komt bouwen we later; eerst moet
 * iedereen de plek hebben.
 *
 * OPT-IN
 * Alleen maandklanten krijgen dit, geen proefperiodes. Daarom staat de module
 * in de OPTIN-lijst van class-wss-ai-koppeling.php: hij verschijnt pas als
 * Webshopschool hem voor deze webshop aanzet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Blackfriday {

	const SLUG = 'wss-ai-blackfriday';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
	}

	/** Mag deze webshop de Black Friday-pagina zien? */
	public static function beschikbaar() {
		return WSS_AI_Koppeling::module_aan( 'blackfriday' );
	}

	/**
	 * Het menu-item.
	 *
	 * Positie 3.1: direct onder WSS Tools (3) en boven de scheidingslijn (4).
	 * Een kommagetal als tekst, zodat WordPress hem niet op een bezette plek
	 * zet en een ander item verdringt.
	 */
	public static function menu() {
		if ( ! self::beschikbaar() ) {
			return;
		}
		add_menu_page(
			__( 'Black Friday', 'wss-ai' ),
			__( 'Black Friday', 'wss-ai' ),
			self::recht(),
			self::SLUG,
			array( __CLASS__, 'pagina' ),
			'dashicons-tag',
			'3.1'
		);
	}

	/** Een winkelbeheerder mag erbij, net als bij voorraad en nieuwsbrief. */
	private static function recht() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	public static function pagina() {
		if ( ! current_user_can( self::recht() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		echo '<div class="wrap wss-ai-blackfriday"><h1>' . esc_html__( 'Black Friday', 'wss-ai' ) . '</h1></div>';
	}
}
