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
	 * Set while capture_now() runs from the e-mail capture AJAX call, so
	 * capture_cart() does not bail out on is_admin() (true for admin-ajax).
	 *
	 * @var bool
	 */
	private static $allow_ajax = false;

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
	 * Capture the cart right now, also from an AJAX request. Used by the
	 * checkout e-mail capture: a guest who types his address and leaves at
	 * once would otherwise only be tracked on a next page load that never
	 * comes.
	 */
	public static function capture_now() {
		self::$allow_ajax = true;
		try {
			self::capture_cart();
		} finally {
			self::$allow_ajax = false;
		}
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

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( is_admin() && ! ( self::$allow_ajax && wp_doing_ajax() ) ) {
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

			/* De variatie gaat mee, en dat is niet voor de mail maar voor de
			   herstellink: zonder variatie-id en zonder de gekozen maat of kleur
			   kan WSFM_Cart_Recovery een variabel product niet terugleggen, en
			   dan klikt de klant op "terug naar mijn wagen" en mist hij juist
			   dat ene product. */
			$items[] = array(
				'product_id'   => (int) $cart_item['product_id'],
				'variation_id' => isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0,
				'variation'    => ( isset( $cart_item['variation'] ) && is_array( $cart_item['variation'] ) ) ? $cart_item['variation'] : array(),
				'name'         => $product ? $product->get_name() : '',
				'qty'          => (int) $cart_item['quantity'],
				'price'        => WSFM_Template_Engine::format_price( (float) $cart_item['line_total'] ),
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
		$existing  = $wpdb->get_row( $wpdb->prepare( "SELECT id, cart_hash, queued_flag, last_activity, order_placed_flag, order_placed_at FROM {$table} WHERE customer_email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			// woocommerce_cart_updated fires on every page load with a cart;
			// skip the write when nothing changed within the last 5 minutes.
			$age = strtotime( $now ) - strtotime( $existing->last_activity );
			if ( $existing->cart_hash === $cart_hash && $age < 5 * MINUTE_IN_SECONDS ) {
				return;
			}
			$queued_flag = (int) $existing->queued_flag;

			/* Een order van minder dan twee dagen geleden blijft 'besteld'.
			   Bij Mollie (en elke gateway die na de order nog iets met de wagen
			   doet) vuurt woocommerce_cart_updated NA de order nog een keer; de
			   vlag ging dan weer uit en de klant kreeg alsnog een herinnering
			   voor een wagen die hij net had afgerekend. Pas na het venster telt
			   nieuwe winkelwagenactiviteit weer als een nieuwe wagen. */
			$besteld_vlag = ( (int) $existing->order_placed_flag && self::recent_besteld_binnen_venster( $existing ) ) ? 1 : 0;

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
					'order_placed_flag' => $besteld_vlag,
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
		$wpdb->update(
			$table,
			array(
				'order_placed_flag' => 1,
				'order_placed_at'   => current_time( 'mysql' ),
			),
			array( 'customer_email' => $email )
		);
	}

	/**
	 * Hoeveel dagen een bestelling een herinnering tegenhoudt.
	 *
	 * @return int
	 */
	public static function bestel_venster_dagen() {
		return max( 1, (int) apply_filters( 'wsfm_cart_bestel_venster_dagen', 2 ) );
	}

	/**
	 * Valt de bestelling op deze rij nog binnen het venster?
	 *
	 * Een rij met vlag maar zonder tijdstip (van voor deze versie) telt als
	 * binnen het venster: liever een mail te weinig dan een mail te veel.
	 *
	 * @param object $rij Tracking-rij.
	 * @return bool
	 */
	private static function recent_besteld_binnen_venster( $rij ) {
		if ( empty( $rij->order_placed_at ) ) {
			return true;
		}
		return strtotime( (string) $rij->order_placed_at ) >= strtotime( current_time( 'mysql' ) ) - self::bestel_venster_dagen() * DAY_IN_SECONDS;
	}

	/**
	 * Fetch a tracking row for context building: ALLEEN op het e-mailadres
	 * van de ontvanger (er is per adres precies een rij, unieke sleutel).
	 *
	 * Vroeger werd eerst op cart_hash gezocht. Die hash hangt alleen van de
	 * INHOUD van de wagen af: twee klanten met dezelfde producten delen hem.
	 * De mail kon dan de wagen, het bedrag en de herstellink van een ander
	 * tonen, en die link vult diens e-mailadres in. De hash-parameter blijft
	 * bestaan zodat bestaande aanroepen niet breken, maar wordt genegeerd.
	 *
	 * @param string $cart_hash Genegeerd (alleen voor compatibiliteit).
	 * @param string $email     Customer e-mail.
	 * @return object|null
	 */
	public static function get_by_hash_or_email( $cart_hash, $email ) {
		global $wpdb;

		$table = self::table();

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

		/* Een wagen die al dagen stilligt is geen verlaten wagen meer maar een
		   oude. Bij het aanzetten van een flow werden anders ALLE oude wagens
		   in een keer gemaild (Rockstyle, 21-11: wagens van 1 t/m 16 nov). De
		   grens is 48 uur, of de wachttijd van de eerste stap plus een dag als
		   die langer is. */
		$max_minuten = max( 48 * 60, $threshold_minutes + 24 * 60 );
		$max_minuten = (int) apply_filters( 'wsfm_cart_max_leeftijd_minuten', $max_minuten );
		$oudste      = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - $max_minuten * MINUTE_IN_SECONDS ) );

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE last_activity <= %s AND last_activity >= %s AND order_placed_flag = 0 AND queued_flag = 0", $cutoff, $oudste ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
