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
	 * @param object $item Queue row.
	 * @param object $flow Flow (with decoded steps).
	 * @param array  $step Step config for this item.
	 * @return bool
	 */
	public static function should_stop( $item, $flow, array $step ) {
		if ( 'abandoned_cart' === $flow->trigger_type && ! empty( $step['stop_on_order'] ) ) {
			return self::has_ordered_since( $item->customer_email, $item->created_at );
		}

		return false;
	}

	/**
	 * Whether the customer placed an order after the given moment.
	 * Uses wc_get_orders (HPOS-safe) and also checks the cart-tracking flag
	 * that the order hook sets, so it works even before order indexing.
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
