<?php
/**
 * Cart tracking for the abandoned-cart trigger.
 *
 * One row per customer e-mail (the latest cart wins). Rows are written on
 * cart activity for customers whose e-mail address is known: logged-in
 * users, returning customers with session billing data, or visitors
 * recognised via identity stitching (opt-in).
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Cart_Tracking {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_cart_tracking';
	}

	/**
	 * Hook registration (front-end cart activity + order placement).
	 */
	public static function init() {
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'capture_cart' ), 20 );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'capture_cart' ), 20 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'mark_order_placed' ), 10, 2 );
	}

	/**
	 * Resolve the current visitor's e-mail address + name, or empty strings.
	 *
	 * @return array { email, name }
	 */
	private static function resolve_customer() {
		// 1. Logged-in user.
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return array(
				'email' => $user->user_email,
				'name'  => trim( $user->first_name . ' ' . $user->last_name ),
			);
		}

		// 2. Guest with known billing data in the WooCommerce session
		//    (returning customer or checkout already partially filled).
		if ( function_exists( 'WC' ) && WC()->customer ) {
			$email = WC()->customer->get_billing_email();
			if ( $email ) {
				return array(
					'email' => $email,
					'name'  => trim( WC()->customer->get_billing_first_name() . ' ' . WC()->customer->get_billing_last_name() ),
				);
			}
		}

		// 3. Identity stitching (only with marketing consent, opt-in).
		$identity = WSFM_Identity::resolve_from_cookie();
		if ( $identity ) {
			return array(
				'email' => $identity->email,
				'name'  => '',
			);
		}

		return array(
			'email' => '',
			'name'  => '',
		);
	}

	/**
	 * Capture the current cart state. Runs on woocommerce_add_to_cart and
	 * woocommerce_cart_updated.
	 */
	public static function capture_cart() {
		global $wpdb;

		if ( ! function_exists( 'WC' ) || ! WC()->cart || is_admin() ) {
			return;
		}

		$customer = self::resolve_customer();
		if ( '' === $customer['email'] ) {
			return; // No known address - nothing to track.
		}

		$email = strtolower( $customer['email'] );
		$table = self::table();

		// Empty cart → the customer checked out or emptied it deliberately.
		if ( WC()->cart->is_empty() ) {
			$wpdb->delete( $table, array( 'customer_email' => $email ) );
			return;
		}

		$items = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
			$items[] = array(
				'product_id' => (int) $cart_item['product_id'],
				'name'       => $product ? $product->get_name() : '',
				'qty'        => (int) $cart_item['quantity'],
				'price'      => WSFM_Template_Engine::format_price( (float) $cart_item['line_total'] ),
			);
		}

		$contents = wp_json_encode(
			array(
				'items' => $items,
				'total' => (float) WC()->cart->get_total( 'edit' ),
			)
		);

		$cart_hash = WC()->cart->get_cart_hash();
		$now       = current_time( 'mysql' );
		$existing  = $wpdb->get_row( $wpdb->prepare( "SELECT id, cart_hash, queued_flag, last_activity FROM {$table} WHERE customer_email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			// woocommerce_cart_updated fires on every page load with a cart;
			// skip the write when nothing changed within the last 5 minutes.
			$age = strtotime( $now ) - strtotime( $existing->last_activity );
			if ( $existing->cart_hash === $cart_hash && $age < 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			$queued_flag = (int) $existing->queued_flag;

			// A NEW cart after an earlier abandoned-cart run: stop the old
			// pending reminders and let this cart start a fresh run.
			if ( $queued_flag && $existing->cart_hash !== $cart_hash ) {
				WSFM_Queue::stop_pending_for_customer( $email, 'abandoned_cart' );
				$queued_flag = 0;
			}

			$wpdb->update(
				$table,
				array(
					'cart_hash'         => $cart_hash,
					'customer_name'     => sanitize_text_field( $customer['name'] ),
					'cart_contents'     => $contents,
					'last_activity'     => $now,
					'order_placed_flag' => 0,
					'queued_flag'       => $queued_flag,
				),
				array( 'id' => $existing->id )
			);
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'cart_hash'         => $cart_hash,
				'customer_email'    => $email,
				'customer_name'     => sanitize_text_field( $customer['name'] ),
				'cart_contents'     => $contents,
				'last_activity'     => $now,
				'order_placed_flag' => 0,
				'queued_flag'       => 0,
				'created_at'        => $now,
			)
		);
	}

	/**
	 * An order was placed: flag the tracking row so the abandoned-cart
	 * check skips it and the stop condition has a fast path.
	 *
	 * @param int      $order_id Order id.
	 * @param WC_Order $order    Order object (may be null on some paths).
	 */
	public static function mark_order_placed( $order_id, $order = null ) {
		global $wpdb;

		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}

		$email = strtolower( (string) $order->get_billing_email() );
		if ( '' === $email ) {
			return;
		}

		$table = self::table();
		$wpdb->update( $table, array( 'order_placed_flag' => 1 ), array( 'customer_email' => $email ) );
	}

	/**
	 * Fetch a tracking row for context building: by cart hash first,
	 * falling back to the customer e-mail.
	 *
	 * @param string $cart_hash Cart hash (nullable).
	 * @param string $email     Customer e-mail.
	 * @return object|null
	 */
	public static function get_by_hash_or_email( $cart_hash, $email ) {
		global $wpdb;

		$table = self::table();

		if ( $cart_hash ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_hash = %s LIMIT 1", $cart_hash ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				return $row;
			}
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_email = %s LIMIT 1", strtolower( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Abandoned carts that passed the wait threshold and are not yet
	 * queued or converted.
	 *
	 * @param int $threshold_minutes Minutes of inactivity.
	 * @return object[]
	 */
	public static function get_abandoned( $threshold_minutes ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $threshold_minutes * MINUTE_IN_SECONDS ) );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_activity <= %s AND order_placed_flag = 0 AND queued_flag = 0", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Mark a tracking row as queued.
	 *
	 * @param int $row_id Tracking row id.
	 */
	public static function mark_queued( $row_id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'queued_flag' => 1 ), array( 'id' => $row_id ) );
	}

	/**
	 * Purge tracking rows older than N days (housekeeping).
	 *
	 * @param int $days Age in days.
	 */
	public static function purge_old( $days = 30 ) {
		global $wpdb;

		$table  = self::table();
		$cutoff = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_activity < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
