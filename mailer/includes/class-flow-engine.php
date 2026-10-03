<?php
/**
 * Flow engine: WooCommerce triggers + Action Scheduler wiring.
 *
 * - order_placed flows start when the checkout has created the order
 * - order_paid flows start when the payment came through
 * - order_completed flows start on woocommerce_order_status_completed
 * - abandoned_cart flows start via the recurring cart check (15 min)
 * - the queue processor runs every 5 minutes
 *
 * All recurring work runs through Action Scheduler (ships with
 * WooCommerce), never wp_cron directly.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Flow_Engine {

	const HOOK_PROCESS_QUEUE   = 'wsfm_process_queue';
	const HOOK_CHECK_ABANDONED = 'wsfm_check_abandoned_carts';
	const AS_GROUP             = 'wsfm';

	/**
	 * Register triggers and recurring actions.
	 */
	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		WSFM_Cart_Tracking::init();
		WSFM_Cart_Recovery::init();

		/* BESTELLING GEPLAATST
		   Twee haken, want een shop kan twee afrekenpagina's hebben: de oude
		   (checkout_order_processed) en de nieuwe blokken-checkout van
		   WooCommerce (store_api_...). Wie alleen de eerste neemt, mist elke
		   bestelling op een shop met blokken, en dat is niet te zien aan de
		   flow: die staat op actief en doet niets. */
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_placed' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_placed_object' ) );

		/* BESTELLING BETAALD
		   payment_complete is het echte betaalmoment bij een gateway. De twee
		   statushaken erbij zijn het vangnet voor een order die zonder gateway
		   op betaald komt te staan: een overboeking die de winkelier zelf
		   afvinkt, of een gateway die alleen de status zet. Dubbel vuren kan
		   dus, en dat is geen probleem: WSFM_Queue::enqueue_flow zet per flow
		   maar één keer iets in de wachtrij voor dezelfde order. */
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_paid' ) );

		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_completed' ) );

		add_action( self::HOOK_PROCESS_QUEUE, array( 'WSFM_Queue_Processor', 'process' ) );
		add_action( self::HOOK_CHECK_ABANDONED, array( __CLASS__, 'check_abandoned_carts' ) );

		add_action( 'init', array( __CLASS__, 'schedule_recurring_actions' ) );
	}

	/**
	 * Ensure the two recurring Action Scheduler actions exist.
	 */
	public static function schedule_recurring_actions() {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! as_has_scheduled_action( self::HOOK_PROCESS_QUEUE, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::HOOK_PROCESS_QUEUE, array(), self::AS_GROUP );
		}

		if ( ! as_has_scheduled_action( self::HOOK_CHECK_ABANDONED, array(), self::AS_GROUP ) ) {
			as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 15 * MINUTE_IN_SECONDS, self::HOOK_CHECK_ABANDONED, array(), self::AS_GROUP );
		}
	}

	/**
	 * Remove recurring actions (called on plugin deactivation).
	 */
	public static function unschedule_recurring_actions() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PROCESS_QUEUE, array(), self::AS_GROUP );
			as_unschedule_all_actions( self::HOOK_CHECK_ABANDONED, array(), self::AS_GROUP );
		}
	}

	/**
	 * Trigger: de afrekenpagina heeft de order aangemaakt.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order_placed( $order_id ) {
		self::enqueue_order_flows( $order_id, 'order_placed' );
	}

	/**
	 * Hetzelfde, maar dan vanuit de blokken-checkout, die het order-OBJECT
	 * meegeeft in plaats van het nummer.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public static function on_order_placed_object( $order ) {
		$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : (int) $order;
		self::enqueue_order_flows( $order_id, 'order_placed' );
	}

	/**
	 * Trigger: de betaling is binnen.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order_paid( $order_id ) {
		self::enqueue_order_flows( $order_id, 'order_paid' );
	}

	/**
	 * Trigger: an order reached the "completed" status.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order_completed( $order_id ) {
		self::enqueue_order_flows( $order_id, 'order_completed' );
	}

	/**
	 * Zet elke actieve flow van dit soort in de wachtrij voor deze order.
	 *
	 * @param int    $order_id     Order id.
	 * @param string $trigger_type order_placed | order_paid | order_completed.
	 */
	private static function enqueue_order_flows( $order_id, $trigger_type ) {
		$order_id = (int) $order_id;
		if ( $order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$flows = WSFM_Flows::get_active( $trigger_type );
		if ( empty( $flows ) ) {
			return; // Niets te doen; geen order inlezen voor niets.
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}

		foreach ( $flows as $flow ) {
			WSFM_Queue::enqueue_flow(
				$flow,
				array(
					'customer_email' => $email,
					'customer_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
					'order_id'       => $order_id,
					'base_timestamp' => time(),
				)
			);
		}
	}

	/**
	 * Recurring check (15 min): find carts whose inactivity passed the
	 * earliest flow threshold and enqueue ALL active abandoned_cart flows
	 * for them. Because scheduled_at is computed from last_activity plus
	 * each flow's own waits, flows with longer waits still fire at the
	 * right moment.
	 */
	public static function check_abandoned_carts() {
		$flows = WSFM_Flows::get_active( 'abandoned_cart' );

		if ( ! empty( $flows ) ) {
			// The earliest first-step wait is the abandonment threshold.
			$threshold = null;
			foreach ( $flows as $flow ) {
				if ( ! empty( $flow->steps ) ) {
					$wait      = max( 1, $flow->steps[0]['wait_minutes'] );
					$threshold = ( null === $threshold ) ? $wait : min( $threshold, $wait );
				}
			}

			if ( null !== $threshold ) {
				foreach ( WSFM_Cart_Tracking::get_abandoned( $threshold ) as $tracking ) {
					self::enqueue_abandoned_cart( $tracking, $flows );
				}
			}
		}

		WSFM_Cart_Tracking::purge_old( 30 );
	}

	/**
	 * Enqueue all active abandoned-cart flows for one tracked cart.
	 *
	 * @param object   $tracking Tracking row.
	 * @param object[] $flows    Active abandoned_cart flows.
	 */
	private static function enqueue_abandoned_cart( $tracking, array $flows ) {
		// Belt and braces: skip when an order arrived between the flag
		// update and this run.
		if ( WSFM_Flow_Conditions::has_ordered_since( $tracking->customer_email, $tracking->last_activity ) ) {
			WSFM_Cart_Tracking::mark_queued( $tracking->id );
			return;
		}

		$base = strtotime( get_gmt_from_date( $tracking->last_activity ) );

		foreach ( $flows as $flow ) {
			WSFM_Queue::enqueue_flow(
				$flow,
				array(
					'customer_email' => $tracking->customer_email,
					'customer_name'  => $tracking->customer_name,
					'cart_hash'      => $tracking->cart_hash,
					'base_timestamp' => $base,
				)
			);
		}

		WSFM_Cart_Tracking::mark_queued( $tracking->id );
	}
}
