<?php
/**
 * De herstellink van een verlaten winkelwagen.
 *
 * WAAROM DIT ER MOEST KOMEN
 * {cart_url} wees naar de winkelwagenpagina van de shop. Dat is in een mail
 * precies de verkeerde link: de ontvanger klikt, komt op /winkelwagen/ en ziet
 * een LEGE wagen. Zijn wagen zat in zijn eigen sessie van een dag eerder, en
 * die is er vaak niet meer: een andere browser, een telefoon in plaats van een
 * laptop, of simpelweg een verlopen sessie. De mail kwam dus aan, deed precies
 * wat hij moest doen, en zette de klant daarna op een lege pagina. Dat is
 * erger dan geen mail sturen.
 *
 * Wat deze link doet: hij zoekt de bewaarde wagen erbij en legt de producten
 * terug in de wagen van de bezoeker die klikt, en stuurt hem dan door naar de
 * winkelwagenpagina.
 *
 * WAAROM ER EEN SLEUTEL IN DE LINK ZIT
 * Zonder sleutel zou /?wsfm-wagen=41 de wagen van een ander openen, en daarmee
 * zijn producten en (via het voorvullen hieronder) zijn e-mailadres. De sleutel
 * is een hash van het rij-id, het adres en het aanmaakmoment, met de
 * geheime sleutels van de site erin; hij staat nergens opgeslagen en is dus
 * niet uit de database te halen.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Cart_Recovery {

	/** Het queryargument met het rij-id van de bewaarde wagen. */
	const ARG = 'wsfm-wagen';

	/** Het queryargument met de sleutel. */
	const ARG_KEY = 'wsfm-sleutel';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'misschien_herstellen' ), 5 );
	}

	/**
	 * De link die in een verlaten-winkelwagenmail hoort.
	 *
	 * Valt terug op de gewone winkelwagenpagina als we geen bewaarde wagen
	 * hebben; een mail met een lege link erin is erger dan een mail met een
	 * link die alleen maar naar de winkelwagen gaat.
	 *
	 * @param object|null $tracking Rij uit wsfm_cart_tracking.
	 * @return string
	 */
	public static function link( $tracking = null ) {
		$winkelwagen = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );

		if ( ! is_object( $tracking ) || empty( $tracking->id ) || empty( $tracking->customer_email ) ) {
			return $winkelwagen;
		}

		return add_query_arg(
			array(
				self::ARG     => (int) $tracking->id,
				self::ARG_KEY => self::sleutel( $tracking ),
			),
			$winkelwagen
		);
	}

	/**
	 * De sleutel die bij deze rij hoort.
	 *
	 * Het aanmaakmoment zit erin zodat een link niet blijft werken als een rij
	 * ooit wordt opgeruimd en hetzelfde id later opnieuw wordt uitgedeeld aan
	 * iemand anders.
	 *
	 * @param object $tracking Tracking-rij.
	 * @return string
	 */
	private static function sleutel( $tracking ) {
		$basis = 'wsfm-wagen|' . (int) $tracking->id . '|' . strtolower( (string) $tracking->customer_email ) . '|' . (string) ( isset( $tracking->created_at ) ? $tracking->created_at : '' );

		return substr( wp_hash( $basis ), 0, 20 );
	}

	/**
	 * Staat er een herstellink in de URL? Dan de wagen weer vullen.
	 */
	public static function misschien_herstellen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- eigen sleutel in de link; een nonce kan niet, want die leeft twee dagen en deze link een week.
		if ( is_admin() || empty( $_GET[ self::ARG ] ) || empty( $_GET[ self::ARG_KEY ] ) ) {
			return;
		}

		$rij_id  = (int) $_GET[ self::ARG ];
		$sleutel = sanitize_text_field( wp_unslash( $_GET[ self::ARG_KEY ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		global $wpdb;
		$tabel = WSFM_Cart_Tracking::table();
		$rij   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabel} WHERE id = %d", $rij_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/* Geen rij of een sleutel die niet klopt: gewoon doorsturen naar de
		   winkelwagen zonder uitleg over wat er niet klopte. De bezoeker kan er
		   niets mee, en wie aan de link zit te sleutelen hoort niets te horen.
		   hash_equals, zodat de vergelijking geen hint geeft over hoe ver je
		   met raden was. */
		if ( ! $rij || ! hash_equals( self::sleutel( $rij ), $sleutel ) ) {
			self::doorsturen();
		}

		$aantal = self::leg_terug( $rij );

		/* Het adres in de sessie zetten, zodat het afrekenen al is voorgevuld
		   en de wagen opnieuw gevolgd wordt als hij wéér weggaat. Alleen als er
		   nog niets staat: een ingelogde klant of iemand die al aan het
		   afrekenen was overschrijven we niet. */
		if ( ! is_user_logged_in() && WC()->customer && ! WC()->customer->get_billing_email() ) {
			WC()->customer->set_billing_email( (string) $rij->customer_email );
			if ( ! WC()->customer->get_billing_first_name() && $rij->customer_name ) {
				$delen = preg_split( '/\s+/', trim( (string) $rij->customer_name ) );
				WC()->customer->set_billing_first_name( $delen[0] );
			}
			WC()->customer->save();
		}

		if ( $aantal > 0 && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'We hebben je winkelwagen weer voor je klaargezet.', 'ws-flow-mailer' ), 'success' );
		}

		self::doorsturen();
	}

	/**
	 * De bewaarde producten terug in de wagen leggen.
	 *
	 * Wat er al in de wagen zit blijft staan en wordt niet opgehoogd. Iemand kan
	 * tussendoor zelf iets hebben toegevoegd, en van een link uit een mail mag
	 * nooit iets dubbel in zijn wagen komen.
	 *
	 * @param object $rij Tracking-rij.
	 * @return int Aantal producten dat erbij is gezet.
	 */
	private static function leg_terug( $rij ) {
		$inhoud   = json_decode( (string) $rij->cart_contents, true );
		$producten = ( is_array( $inhoud ) && ! empty( $inhoud['items'] ) ) ? $inhoud['items'] : array();
		$erbij    = 0;

		foreach ( $producten as $product ) {
			$product_id   = isset( $product['product_id'] ) ? (int) $product['product_id'] : 0;
			$variatie_id  = isset( $product['variation_id'] ) ? (int) $product['variation_id'] : 0;
			$aantal       = isset( $product['qty'] ) ? max( 1, (int) $product['qty'] ) : 1;
			$variatie     = isset( $product['variation'] ) && is_array( $product['variation'] ) ? $product['variation'] : array();

			if ( $product_id < 1 ) {
				continue;
			}

			/* Een variabel product zonder bewaarde variatie kan niet terug: dan
			   weten we de maat of de kleur niet, en WooCommerce weigert het
			   terecht. Dat gebeurt alleen bij wagens die vóór deze versie zijn
			   vastgelegd. */
			$object = wc_get_product( $variatie_id ? $variatie_id : $product_id );
			if ( ! $object || ! $object->is_purchasable() || ! $object->is_in_stock() ) {
				continue;
			}
			if ( $object->is_type( 'variable' ) && ! $variatie_id ) {
				continue;
			}

			if ( self::zit_er_al_in( $product_id, $variatie_id ) ) {
				continue;
			}

			$gelukt = WC()->cart->add_to_cart( $product_id, $aantal, $variatie_id, $variatie );
			if ( $gelukt ) {
				$erbij++;
			}
		}

		return $erbij;
	}

	/**
	 * Zit dit product (eventueel deze variatie) al in de wagen?
	 *
	 * @param int $product_id  Product.
	 * @param int $variatie_id Variatie, of 0.
	 * @return bool
	 */
	private static function zit_er_al_in( $product_id, $variatie_id ) {
		foreach ( WC()->cart->get_cart() as $regel ) {
			$zelfde_product  = (int) $regel['product_id'] === (int) $product_id;
			$zelfde_variatie = (int) ( isset( $regel['variation_id'] ) ? $regel['variation_id'] : 0 ) === (int) $variatie_id;

			if ( $zelfde_product && ( ! $variatie_id || $zelfde_variatie ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Doorsturen naar de winkelwagen, zonder de sleutel in de adresbalk.
	 */
	private static function doorsturen() {
		$naar = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );

		wp_safe_redirect( $naar );
		exit;
	}
}
