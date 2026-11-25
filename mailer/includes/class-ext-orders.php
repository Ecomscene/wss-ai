<?php
/**
 * Bestellingen die niet in WooCommerce staan maar via een webhook binnenkomen
 * (nu: Shopify, voor de nieuwsbrief-WP van My Sweet Lifestyle).
 *
 * WAAROM EEN EIGEN TABEL
 * De flow-motor haalt zijn gegevens voor een ordermail uit de order zelf, en
 * zonder WooCommerce bestaat die order hier niet. De webhook bewaart daarom wat
 * de mail nodig heeft (naam, ordernummer, totaal, regels) en hoeveel
 * bestellingen die klant eerder had. Dat laatste is nodig voor de voorwaarde
 * "eerste bestelling" of "terugkerende klant", en het moment van binnenkomen
 * voor "stop als de klant opnieuw bestelt".
 *
 * Id's lopen op in volgorde van binnenkomst, dus "nieuwer" is een hoger id.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Ext_Orders {

	/**
     * @return string
     */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_ext_orders';
	}

	/**
     * Een bestelling bewaren. Dezelfde bestelling twee keer (Shopify probeert
     * opnieuw bij een trage reactie) levert dezelfde rij op.
     *
     * @param array $d { bron, ext_id, email, first_name, order_number, total_text, items, prior_orders }.
     * @return array { id: int, nieuw: bool }
     */
	public static function opslaan( array $d ) {
		global $wpdb;

		$t    = self::table();
		$bron = sanitize_key( isset( $d['bron'] ) ? $d['bron'] : '' );
		$ext  = substr( (string) ( isset( $d['ext_id'] ) ? $d['ext_id'] : '' ), 0, 40 );

		$bestaand = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$t} WHERE bron = %s AND ext_id = %s", $bron, $ext ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $bestaand ) {
			return array( 'id' => (int) $bestaand->id, 'nieuw' => false );
		}

		$wpdb->insert(
			$t,
			array(
				'bron'           => $bron,
				'ext_id'         => $ext,
				'customer_email' => strtolower( trim( (string) $d['email'] ) ),
				'first_name'     => sanitize_text_field( isset( $d['first_name'] ) ? $d['first_name'] : '' ),
				'order_number'   => sanitize_text_field( isset( $d['order_number'] ) ? $d['order_number'] : '' ),
				'total_text'     => sanitize_text_field( isset( $d['total_text'] ) ? $d['total_text'] : '' ),
				'items'          => wp_json_encode( isset( $d['items'] ) ? $d['items'] : array() ),
				'prior_orders'   => max( 0, (int) ( isset( $d['prior_orders'] ) ? $d['prior_orders'] : 0 ) ),
				'created_at'     => current_time( 'mysql' ),
			)
		);

		return array( 'id' => (int) $wpdb->insert_id, 'nieuw' => true );
	}

	/**
     * @param int $id Rij-id.
     * @return object|null
     */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
     * Hoeveel eerdere bestellingen kent deze tabel van dit adres?
     *
     * @param string $email Adres.
     * @param int    $id    Rij waarvoor we kijken.
     * @return int
     */
	public static function aantal_eerder( $email, $id ) {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE customer_email = %s AND id < %d", strtolower( $email ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
     * Is er na deze bestelling een nieuwere van hetzelfde adres binnengekomen?
     *
     * @param string $email Adres.
     * @param int    $id    Rij van de bestelling die de flow startte.
     * @return bool
     */
	public static function nieuwere_dan( $email, $id ) {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE customer_email = %s AND id > %d LIMIT 1", strtolower( $email ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
     * Is er sinds dit moment een bestelling van dit adres binnengekomen?
     *
     * @param string $email Adres.
     * @param string $since MySQL-tijd van de site.
     * @return bool
     */
	public static function sinds( $email, $since ) {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE customer_email = %s AND created_at > %s LIMIT 1", strtolower( $email ), $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
