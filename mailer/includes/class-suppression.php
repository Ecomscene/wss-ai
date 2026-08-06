<?php
/**
 * Suppression list - the safety layer.
 *
 * Every send MUST pass WSFM_Suppression::is_suppressed() first. Addresses
 * land here via SNS bounces/complaints, the unsubscribe endpoint, or
 * manual admin entry.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Suppression {

	const REASONS = array( 'bounce', 'complaint', 'manual' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_suppression';
	}

	/**
	 * Normalise an address for storage/lookup.
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	private static function normalize( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * Whether an address is on the suppression list.
	 *
	 * @param string $email E-mail address.
	 * @return bool
	 */
	public static function is_suppressed( $email ) {
		global $wpdb;

		$email = self::normalize( $email );
		if ( '' === $email ) {
			// No address = never sendable; treat as suppressed.
			return true;
		}

		$table = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Reason an address is suppressed, or '' when it is not.
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function get_reason( $email ) {
		global $wpdb;

		$table = self::table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT reason FROM {$table} WHERE email = %s LIMIT 1", self::normalize( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Add an address. Idempotent: an existing entry keeps its original
	 * reason unless the new reason is more severe (manual < bounce < complaint).
	 *
	 * @param string $email  E-mail address.
	 * @param string $reason bounce | complaint | manual.
	 * @return bool Whether the address is now suppressed.
	 */
	public static function add( $email, $reason = 'manual' ) {
		global $wpdb;

		$email = self::normalize( $email );
		if ( ! is_email( $email ) ) {
			return false;
		}
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = 'manual';
		}

		$table    = self::table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, reason FROM {$table} WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $existing ) {
			$rank = array( 'manual' => 0, 'bounce' => 1, 'complaint' => 2 );
			if ( $rank[ $reason ] > $rank[ $existing->reason ] ) {
				$wpdb->update( $table, array( 'reason' => $reason ), array( 'id' => $existing->id ) );
			}
			return true;
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'email'      => $email,
				'reason'     => $reason,
				'created_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * Remove an address (e.g. after a false-positive complaint).
	 *
	 * @param string $email E-mail address.
	 * @return bool
	 */
	public static function remove( $email ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'email' => self::normalize( $email ) ) );
	}

	/**
	 * Paged list for the admin UI.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array[] Rows with email, reason, created_at.
	 */
	public static function get_list( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT id, email, reason, created_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d", $limit, $offset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Total number of suppressed addresses.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
