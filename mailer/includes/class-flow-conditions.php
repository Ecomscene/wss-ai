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
	/* Flows die met een order beginnen hebben een EIGEN vinkel:
       "stop als de klant intussen opnieuw bestelt" (stop_on_new_order). Het
       oude vinkje (stop_on_order) bleef bij ordertriggers altijd zonder effect
       en doet dat nog steeds, zodat bestaande flows op live shops niet anders
       gaan werken. */
	if ( in_array( $flow->trigger_type, WSFM_Flows::ORDER_TRIGGERS, true ) ) {
		return ! empty( $step['stop_on_new_order'] ) && self::heeft_nieuwere_order( $item );
	}

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

		/* Bestellingen die via een webhook binnenkwamen (Shopify). */
if ( class_exists( 'WSFM_Ext_Orders' ) && WSFM_Ext_Orders::sinds( $email, $since ) ) {
	return true;
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

	/**
	 * Heeft deze klant na de bestelling waarmee de flow begon opnieuw besteld?
	 * Kijkt naar WooCommerce-orders en naar bestellingen via een webhook.
	 *
	 * @param object $item Wachtrij-rij.
	 * @return bool
	 */
	public static function heeft_nieuwere_order( $item ) {
		$email = strtolower( (string) $item->customer_email );

		if ( ! empty( $item->ext_order_id ) && class_exists( 'WSFM_Ext_Orders' ) ) {
			if ( WSFM_Ext_Orders::nieuwere_dan( $email, (int) $item->ext_order_id ) ) {
				return true;
			}
			$rij = WSFM_Ext_Orders::get( (int) $item->ext_order_id );
			if ( $rij && function_exists( 'wc_get_orders' ) ) {
				return self::wc_order_na( $email, strtotime( get_gmt_from_date( $rij->created_at ) ), 0 );
			}
			return false;
		}

		if ( ! empty( $item->order_id ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $item->order_id );
			$datum = $order ? $order->get_date_created() : null;
			if ( ! $datum ) {
				return false;
			}
			if ( self::wc_order_na( $email, $datum->getTimestamp(), (int) $item->order_id ) ) {
				return true;
			}
			return class_exists( 'WSFM_Ext_Orders' ) && WSFM_Ext_Orders::sinds( $email, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $datum->getTimestamp() ) ) );
		}

		return false;
	}

	/**
	 * Is er een WooCommerce-order van dit adres NA dit moment?
	 *
	 * @param string $email   Adres.
	 * @param int    $ts      Tijdstempel.
	 * @param int    $behalve Order die niet meetelt (0 = geen).
	 * @return bool
	 */
	private static function wc_order_na( $email, $ts, $behalve ) {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'date_created'  => '>' . (int) $ts,
				'status'        => array( 'pending', 'on-hold', 'processing', 'completed' ),
				'exclude'       => $behalve ? array( (int) $behalve ) : array(),
				'limit'         => 1,
				'return'        => 'ids',
			)
		);
		return ! empty( $orders );
	}

	/**
	 * Hoeveel bestellingen had de klant vóór deze WooCommerce-order?
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function eerdere_orders_wc( $order ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$datum = $order->get_date_created();
		$ids   = wc_get_orders(
			array(
				'billing_email' => strtolower( (string) $order->get_billing_email() ),
				'date_created'  => '<' . ( $datum ? $datum->getTimestamp() : time() ),
				'status'        => array( 'on-hold', 'processing', 'completed' ),
				'exclude'       => array( (int) $order->get_id() ),
				'limit'         => 2,
				'return'        => 'ids',
			)
		);
		return count( (array) $ids );
	}

	/**
	 * Past de bestelling bij de voorwaarde van de flow?
	 *
	 * @param object $flow   Flow.
	 * @param int    $eerder Aantal eerdere bestellingen van de klant.
	 * @return bool
	 */
	public static function voorwaarde_geldt( $flow, $eerder ) {
		$voorwaarde = isset( $flow->voorwaarde ) ? (string) $flow->voorwaarde : '';
		if ( 'eerste_bestelling' === $voorwaarde ) {
			return 0 === (int) $eerder;
		}
		if ( 'terugkerende_klant' === $voorwaarde ) {
			return (int) $eerder > 0;
		}
		return true;
	}
}
