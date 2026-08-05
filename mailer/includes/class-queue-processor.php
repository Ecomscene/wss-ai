<?php
/**
 * Queue processor — the heart of the plugin.
 *
 * Runs every 5 minutes via Action Scheduler. Idempotent: each item is
 * claimed with a conditional UPDATE to the 'processing' status, so two
 * overlapping runs can never send the same mail twice.
 *
 * Per item: claim → suppression check → stop-condition check → render
 * template → send via configured provider → log. Failures retry up to
 * 3 times with a 15-minute backoff.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Queue_Processor {

	const BATCH_SIZE    = 50;
	const MAX_ATTEMPTS  = 3;
	const RETRY_MINUTES = 15;

	/**
	 * Recurring Action Scheduler callback.
	 */
	public static function process() {
		global $wpdb;

		self::recover_stale_items();

		$table = WSFM_Queue::table();
		$now   = current_time( 'mysql' );

		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC LIMIT %d", $now, self::BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $ids as $id ) {
			self::process_item( (int) $id );
		}
	}

	/**
	 * Items stuck in 'processing' (a run crashed mid-batch) go back to
	 * pending after an hour so they are retried, with attempts counted.
	 */
	private static function recover_stale_items() {
		global $wpdb;

		$table = WSFM_Queue::table();
		$limit = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) );

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'pending', attempts = attempts + 1 WHERE status = 'processing' AND scheduled_at < %s", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Process one queue item. Public so a manual "verwerk nu" admin action
	 * or a test can call it directly.
	 *
	 * @param int $queue_id Queue row id.
	 * @return string Resulting status (sent|stopped|failed|pending|skipped).
	 */
	public static function process_item( $queue_id ) {
		global $wpdb;

		$table = WSFM_Queue::table();

		// Claim: only one runner can flip pending → processing.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing' WHERE id = %d AND status = 'pending'", $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $claimed ) {
			return 'skipped';
		}

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $queue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $item ) {
			return 'skipped';
		}

		// Flow or step no longer exists, or the flow is paused → stop.
		$flow = WSFM_Flows::get( $item->flow_id );
		$step = ( $flow && isset( $flow->steps[ $item->step_index ] ) ) ? $flow->steps[ $item->step_index ] : null;

		if ( ! $flow || ! $step || 'active' !== $flow->status ) {
			return self::finish( $item, 'stopped' );
		}

		// 1. Suppression list — the safety layer, always first.
		if ( WSFM_Suppression::is_suppressed( $item->customer_email ) ) {
			$reason = WSFM_Suppression::get_reason( $item->customer_email );
			WSFM_Queue::log( $item, $step['template_id'], 'failed', '', '', sprintf( __( 'Niet verzonden: adres staat op de suppressielijst (%s).', 'ws-flow-mailer' ), $reason ? $reason : 'onbekend' ) );
			return self::finish( $item, 'stopped' );
		}

		// 2. Stop condition (e.g. customer ordered after all).
		if ( WSFM_Flow_Conditions::should_stop( $item, $flow, $step ) ) {
			return self::finish( $item, 'stopped' );
		}

		// 3. Build merge context.
		$context = self::build_context( $item, $flow );
		if ( is_wp_error( $context ) ) {
			// Source data is gone (order/cart deleted) — permanent stop.
			WSFM_Queue::log( $item, $step['template_id'], 'failed', '', '', $context->get_error_message() );
			return self::finish( $item, 'stopped' );
		}

		// 4. Render template.
		$rendered = WSFM_Template_Engine::render( $step['template_id'], $context );
		if ( is_wp_error( $rendered ) ) {
			WSFM_Queue::log( $item, $step['template_id'], 'failed', '', '', $rendered->get_error_message() );
			return self::finish( $item, 'stopped' );
		}

		// 5. Send.
		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			return self::handle_failure( $item, $step, $provider->get_error_message() );
		}

		$result = $provider->send( $item->customer_email, $rendered['subject'], $rendered['html_body'], $context );

		// 6/7. Success or retry.
		if ( $result->success ) {
			WSFM_Queue::log( $item, $step['template_id'], 'sent', $rendered['subject'], $result->message_id );
			return self::finish( $item, 'sent' );
		}

		return self::handle_failure( $item, $step, $result->error, $rendered['subject'] );
	}

	/**
	 * Retry with backoff, or fail permanently after MAX_ATTEMPTS.
	 *
	 * @param object $item    Queue row.
	 * @param array  $step    Step config.
	 * @param string $error   Error message.
	 * @param string $subject Rendered subject (when available).
	 * @return string
	 */
	private static function handle_failure( $item, array $step, $error, $subject = '' ) {
		global $wpdb;

		$table    = WSFM_Queue::table();
		$attempts = (int) $item->attempts + 1;

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$retry_at = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + self::RETRY_MINUTES * MINUTE_IN_SECONDS ) );
			$wpdb->update(
				$table,
				array(
					'status'       => 'pending',
					'attempts'     => $attempts,
					'scheduled_at' => $retry_at,
				),
				array( 'id' => $item->id )
			);
			return 'pending';
		}

		$wpdb->update( $table, array( 'status' => 'failed', 'attempts' => $attempts ), array( 'id' => $item->id ) );
		WSFM_Queue::log( $item, $step['template_id'], 'failed', $subject, '', $error );
		return 'failed';
	}

	/**
	 * Set the final status of a claimed item.
	 *
	 * @param object $item   Queue row.
	 * @param string $status Final status.
	 * @return string
	 */
	private static function finish( $item, $status ) {
		global $wpdb;

		$wpdb->update( WSFM_Queue::table(), array( 'status' => $status ), array( 'id' => $item->id ) );
		return $status;
	}

	/**
	 * Build the merge context for a queue item from its source data.
	 *
	 * @param object $item Queue row.
	 * @param object $flow Flow row.
	 * @return array|WP_Error
	 */
	private static function build_context( $item, $flow ) {
		$unsubscribe_url = WSFM_Unsubscribe::url( $item->customer_email );

		if ( 'order_completed' === $flow->trigger_type ) {
			$order = $item->order_id ? wc_get_order( $item->order_id ) : false;
			if ( ! $order ) {
				return new WP_Error( 'wsfm_order_gone', sprintf( __( 'Order %d bestaat niet meer.', 'ws-flow-mailer' ), $item->order_id ) );
			}
			return WSFM_Template_Engine::build_order_context( $order, $unsubscribe_url );
		}

		// Abandoned cart: look up the tracking row (by hash, then e-mail).
		$tracking = WSFM_Cart_Tracking::get_by_hash_or_email( $item->cart_hash, $item->customer_email );
		if ( ! $tracking ) {
			return new WP_Error( 'wsfm_cart_gone', __( 'De winkelwagen-data van dit queue-item is niet meer beschikbaar.', 'ws-flow-mailer' ) );
		}

		return WSFM_Template_Engine::build_cart_context( $tracking, $unsubscribe_url );
	}
}
