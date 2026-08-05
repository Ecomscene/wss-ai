<?php
/**
 * Flow engine: WooCommerce triggers + Action Scheduler wiring.
 *
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
	 * Trigger: an order reached the "completed" status.
	 * Enqueue every active order_completed flow for this customer.
	 *
	 * @param int $order_id Order id.
	 */
	public static function on_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}

		foreach ( WSFM_Flows::get_active( 'order_completed' ) as $flow ) {
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
