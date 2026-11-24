<?php
/**
 * Stop conditions, evaluated by the queue processor right before a send.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Flow_Conditions {

	/**
	 * Should this queue item be stopped instead of sent?
	 *
	 * "Stop als er alsnog een order is geplaatst" geldt voor elke flow die NIET
	 * uit een order komt: een verlaten winkelwagen, maar net zo goed een
	 * welkomstreeks of een reeks na het aanmaken van een account. Ook daar is
	 * "kom eens kijken" een rare mail aan iemand die gisteren besteld heeft.
	 *
	 * Bij de order-triggers zou het vinkje zichzelf tegenspreken: die flow
	 * begint juist bij een order, dus hij zou elke mail meteen stoppen.
	 *
	 * @param object $item Queue row.
	 * @param object $flow Flow (with decoded steps).
	 * @param array  $step Step config for this item.
	 * @return bool
	 */
	public static function should_stop( $item, $flow, array $step ) {
		if ( empty( $step['stop_on_order'] ) ) {
			return false;
		}

		if ( in_array( $flow->trigger_type, WSFM_Flows::ORDER_TRIGGERS, true ) ) {
			return false;
		}

		if ( 'abandoned_cart' === $flow->trigger_type ) {
			/* Een herinnering voor een wagen is alleen zinvol voor iemand die
			   NIET net besteld heeft, ook niet op een andere manier of bij een
			   betaling die nog loopt. */
			if ( self::recent_besteld( $item->customer_email ) ) {
				return true;
			}
			if ( self::is_eigen_domein( $item->customer_email ) ) {
				return true;
			}
		}

		return self::has_ordered_since( $item->customer_email, $item->created_at );
	}

	/**
	 * Is er de afgelopen dagen een order op dit adres, in welke bruikbare
	 * status dan ook (ook 'wachtend op betaling' en 'in de wacht')?
	 *
	 * 'Wachtend op betaling' hoort niet in de verlaten-winkelwagenflow: die
	 * klant heeft wel besteld en moet een betaalherinnering krijgen, geen
	 * 'je bent iets vergeten'. Dat is een aparte functie.
	 *
	 * @param string $email E-mailadres.
	 * @return bool
	 */
	public static function recent_besteld( $email ) {
		$dagen = class_exists( 'WSFM_Cart_Tracking' ) ? WSFM_Cart_Tracking::bestel_venster_dagen() : 2;
		$sinds = gmdate( 'Y-m-d H:i:s', time() - $dagen * DAY_IN_SECONDS );
		return self::has_ordered_since( $email, get_date_from_gmt( $sinds ), false );
	}

	/**
	 * Staat dit adres op het domein van de shop zelf (of een subdomein)?
	 * Dat zijn testbestellingen en medewerkers, geen klanten.
	 *
	 * @param string $email E-mailadres.
	 * @return bool
	 */
	public static function is_eigen_domein( $email ) {
		$domein = strtolower( (string) substr( strrchr( (string) $email, '@' ), 1 ) );
		$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$host   = preg_replace( '/^www\./', '', $host );
		if ( '' === $domein || '' === $host ) {
			return false;
		}
		return $domein === $host || substr( $domein, -strlen( '.' . $host ) ) === '.' . $host;
	}

	/**
	 * Whether the customer placed an order after the given moment.
	 * Uses wc_get_orders (HPOS-safe) and also checks the cart-tracking flag
	 * that the order hook sets, so it works even before order indexing.
	 *
	 * LET OP BIJ DE SNELLE WEG
	 * Die vlag op de winkelwagen-rij zegt "deze klant heeft besteld", zonder
	 * datum. Bij een verlaten winkelwagen kan dat niet oud zijn, want de rij
	 * wordt na een bestelling opgeruimd. Bij een welkomstreeks voor iemand die
	 * ook klant is kan hij ruimer uitvallen dan de vraag, en dan stopt een mail
	 * eerder dan nodig. Dat is de kant waar het mag misgaan: een mail te weinig
	 * aan iemand die net besteld heeft is beter dan een mail te veel.
	 *
	 * @param string $email E-mail address.
	 * @param string $since MySQL datetime (site timezone).
	 * @param bool   $snelle_weg Eerst de vlag op de winkelwagenrij bekijken.
	 * @return bool
	 */
	public static function has_ordered_since( $email, $since, $snelle_weg = true ) {
		global $wpdb;

		// Fast path: the order hook flags the tracking row. Niet bij de
		// 'recent besteld'-vraag: die kijkt alleen naar echte orders in het venster.
		if ( $snelle_weg ) {
			$tracking = $wpdb->prefix . 'wsfm_cart_tracking';
			$flagged  = $wpdb->get_var( $wpdb->prepare( "SELECT order_placed_flag FROM {$tracking} WHERE customer_email = %s", strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $flagged ) {
				return true;
			}
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return false;
		}

		// Any order in a non-cancelled/failed status counts as "ordered".
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>' . strtotime( get_gmt_from_date( $since ) ),
				'status'        => array( 'pending', 'on-hold', 'processing', 'completed' ),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);

		return ! empty( $orders );
	}
}
