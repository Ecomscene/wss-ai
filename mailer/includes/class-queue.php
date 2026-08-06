<?php
/**
 * Queue data layer: enqueueing flow instances and log writes.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Queue {

	/**
	 * Queue table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_queue';
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_log';
	}

	/**
	 * Enqueue every step of a flow for one customer. Step waits are
	 * relative to the previous step, so scheduled_at is cumulative
	 * from $base_timestamp.
	 *
	 * @param object $flow Flow with decoded steps.
	 * @param array  $args {
	 *     @type string $customer_email Required.
	 *     @type string $customer_name  Optional.
	 *     @type int    $order_id       For order_completed flows.
	 *     @type string $cart_hash      For abandoned_cart flows.
	 *     @type int    $base_timestamp Unix timestamp the waits count from (default now).
	 * }
	 * @return int Number of queue rows created.
	 */
	public static function enqueue_flow( $flow, array $args ) {
		global $wpdb;

		$email = strtolower( trim( isset( $args['customer_email'] ) ? $args['customer_email'] : '' ) );
		if ( ! is_email( $email ) || empty( $flow->steps ) ) {
			return 0;
		}

		// Dedupe: never enqueue the same flow twice for the same order or,
		// for cart flows, while this customer already has items for it.
		if ( self::already_enqueued( $flow->id, $email, isset( $args['order_id'] ) ? (int) $args['order_id'] : 0 ) ) {
			return 0;
		}

		$base       = isset( $args['base_timestamp'] ) ? (int) $args['base_timestamp'] : time();
		$now_mysql  = current_time( 'mysql' );
		$cumulative = 0;
		$created    = 0;

		foreach ( $flow->steps as $index => $step ) {
			$cumulative += $step['wait_minutes'];

			$inserted = $wpdb->insert(
				self::table(),
				array(
					'flow_id'        => $flow->id,
					'step_index'     => $index,
					'order_id'       => ! empty( $args['order_id'] ) ? (int) $args['order_id'] : null,
					'cart_hash'      => ! empty( $args['cart_hash'] ) ? $args['cart_hash'] : null,
					'customer_email' => $email,
					'customer_name'  => sanitize_text_field( isset( $args['customer_name'] ) ? $args['customer_name'] : '' ),
					'status'         => 'pending',
					'scheduled_at'   => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $base + $cumulative * MINUTE_IN_SECONDS ) ),
					'attempts'       => 0,
					'created_at'     => $now_mysql,
				)
			);

			if ( $inserted ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Queue one row per recipient for a newsletter.
	 *
	 * All rows are scheduled for right now; the processor takes 50 every five
	 * minutes, so a list of 400 leaves over about forty minutes. That pacing is
	 * a feature, not a limit we forgot to raise: a shop that suddenly dumps its
	 * whole list on a provider gets throttled, and throttled mail lands in spam.
	 *
	 * @param int   $newsletter_id Newsletter id.
	 * @param array $recipients    email => first name.
	 * @return int Rows created.
	 */
	public static function enqueue_newsletter( $newsletter_id, array $recipients ) {
		global $wpdb;

		$now     = current_time( 'mysql' );
		$created = 0;

		foreach ( $recipients as $email => $name ) {
			$email = strtolower( trim( (string) $email ) );
			if ( ! is_email( $email ) ) {
				continue;
			}

			$inserted = $wpdb->insert(
				self::table(),
				array(
					'flow_id'        => 0,
					'newsletter_id'  => (int) $newsletter_id,
					'step_index'     => 0,
					'customer_email' => $email,
					'customer_name'  => sanitize_text_field( (string) $name ),
					'status'         => 'pending',
					'scheduled_at'   => $now,
					'attempts'       => 0,
					'created_at'     => $now,
				)
			);

			if ( $inserted ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Whether this flow already has queue rows for this order/customer.
	 *
	 * @param int    $flow_id  Flow id.
	 * @param string $email    Customer e-mail.
	 * @param int    $order_id Order id (0 for cart flows).
	 * @return bool
	 */
	public static function already_enqueued( $flow_id, $email, $order_id = 0 ) {
		global $wpdb;

		$table = self::table();

		if ( $order_id > 0 ) {
			return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE flow_id = %d AND order_id = %d LIMIT 1", $flow_id, $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Cart flows: block while any pending/processing item exists for
		// this customer (a finished run may be re-triggered by a new cart).
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE flow_id = %d AND customer_email = %s AND status IN ('pending','processing') LIMIT 1", $flow_id, $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Stop all pending items for a customer within one trigger type
	 * (used when a new cart supersedes an old abandoned-cart run).
	 *
	 * @param string $email        Customer e-mail.
	 * @param string $trigger_type Flow trigger type.
	 * @return int Rows stopped.
	 */
	public static function stop_pending_for_customer( $email, $trigger_type ) {
		global $wpdb;

		$queue = self::table();
		$flows = $wpdb->prefix . 'wsfm_flows';

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$queue} q INNER JOIN {$flows} f ON f.id = q.flow_id SET q.status = 'stopped' WHERE q.customer_email = %s AND q.status = 'pending' AND f.trigger_type = %s", strtolower( $email ), $trigger_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Write a log entry.
	 *
	 * @param object $item   Queue row.
	 * @param int    $template_id Template id used.
	 * @param string $status  sent | failed.
	 * @param string $subject Rendered subject.
	 * @param string $message_id Provider message id.
	 * @param string $error   Error message.
	 * @return int Log row id.
	 */
	public static function log( $item, $template_id, $status, $subject = '', $message_id = '', $error = '' ) {
		global $wpdb;

		$wpdb->insert(
			self::log_table(),
			array(
				'queue_id'            => $item->id,
				'template_id'         => (int) $template_id,
				'recipient'           => $item->customer_email,
				'subject'             => $subject,
				'status'              => $status,
				'provider_message_id' => $message_id ? $message_id : null,
				'error_message'       => $error ? $error : null,
				'sent_at'             => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Dashboard counts for the last N days.
	 *
	 * @param int $days Days to look back.
	 * @return array { sent, failed, pending, suppressed }
	 */
	public static function get_stats( $days = 30 ) {
		global $wpdb;

		$log   = self::log_table();
		$queue = self::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return array(
			'sent'       => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE status = 'sent' AND sent_at >= %s", $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'failed'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} WHERE status IN ('failed','bounced','complained') AND sent_at >= %s", $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status IN ('pending','processing')" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'suppressed' => WSFM_Suppression::count(),
		);
	}

	/**
	 * Per-flow stats for the dashboard, last N days.
	 *
	 * @param int $days Days to look back.
	 * @return object[] Rows: flow name, trigger_type, sent, failed, pending.
	 */
	public static function get_stats_per_flow( $days = 30 ) {
		global $wpdb;

		$log   = self::log_table();
		$queue = self::table();
		$flows = $wpdb->prefix . 'wsfm_flows';
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		return $wpdb->get_results( $wpdb->prepare( "SELECT f.id, f.name, f.trigger_type, f.status,
				(SELECT COUNT(*) FROM {$log} l INNER JOIN {$queue} q2 ON q2.id = l.queue_id WHERE q2.flow_id = f.id AND l.status = 'sent' AND l.sent_at >= %s) AS sent,
				(SELECT COUNT(*) FROM {$log} l INNER JOIN {$queue} q2 ON q2.id = l.queue_id WHERE q2.flow_id = f.id AND l.status IN ('failed','bounced','complained') AND l.sent_at >= %s) AS failed,
				(SELECT COUNT(*) FROM {$queue} q3 WHERE q3.flow_id = f.id AND q3.status IN ('pending','processing')) AS pending
			FROM {$flows} f ORDER BY f.id ASC", $since, $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Latest log rows for the dashboard.
	 *
	 * @param int         $limit        Max rows.
	 * @param string|null $trigger_type Optional flow-trigger filter.
	 * @return object[]
	 */
	public static function get_recent_log( $limit = 20, $trigger_type = null ) {
		global $wpdb;

		$log         = self::log_table();
		$queue       = self::table();
		$flows       = $wpdb->prefix . 'wsfm_flows';
		$templates   = $wpdb->prefix . 'wsfm_templates';
		$newsletters = $wpdb->prefix . 'wsfm_newsletters';

		$where = '';
		$args  = array();
		if ( $trigger_type ) {
			$where  = 'WHERE f.trigger_type = %s';
			$args[] = $trigger_type;
		}
		$args[] = $limit;

		// COALESCE so a newsletter row shows its own name in the flow column
		// instead of an empty cell that reads like a bug.
		return $wpdb->get_results( $wpdb->prepare( "SELECT l.*, COALESCE(f.name, n.name) AS flow_name,
				COALESCE(f.trigger_type, IF(n.id IS NULL, NULL, 'nieuwsbrief')) AS trigger_type,
				COALESCE(t.name, n.name) AS template_name
			FROM {$log} l
			LEFT JOIN {$queue} q ON q.id = l.queue_id
			LEFT JOIN {$flows} f ON f.id = q.flow_id
			LEFT JOIN {$newsletters} n ON n.id = q.newsletter_id
			LEFT JOIN {$templates} t ON t.id = l.template_id
			{$where}
			ORDER BY l.id DESC LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
