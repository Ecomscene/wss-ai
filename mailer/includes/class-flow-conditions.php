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

		return self::has_ordered_since( $item->customer_email, $item->created_at );
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
	 * @return bool
	 */
	public static function has_ordered_since( $email, $since ) {
		global $wpdb;

		// Fast path: the order hook flags the tracking row.
		$tracking = $wpdb->prefix . 'wsfm_cart_tracking';
		$flagged  = $wpdb->get_var( $wpdb->prepare( "SELECT order_placed_flag FROM {$tracking} WHERE customer_email = %s", strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $flagged ) {
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
}
