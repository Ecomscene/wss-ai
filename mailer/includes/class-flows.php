<?php
/**
 * Flow definitions: CRUD + steps JSON parsing.
 *
 * A flow's `steps` column holds a JSON array of steps:
 * [ { "wait_minutes": 60, "template_id": 3, "stop_on_order": true }, ... ]
 * Wait times are relative to the PREVIOUS step (cumulative from the trigger).
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Flows {

	/**
	 * De momenten waarop een flow kan beginnen.
	 *
	 * Drie van de zes gaan over een order, en dat zijn niet dezelfde momenten:
	 *  - order_placed    de klant heeft net afgerekend, betaald of niet (een
	 *                    overboeking staat dan nog open). Dit is het moment voor
	 *                    "bedankt voor je bestelling".
	 *  - order_paid      het geld is binnen. Dit is het moment voor een mail die
	 *                    niet mag uitgaan bij een bestelling die nooit betaald
	 *                    wordt, bijvoorbeeld een cadeautip of een bestelling die
	 *                    een klant zelf nog moet overmaken.
	 *  - order_completed de winkelier heeft de order afgerond, dus verzonden.
	 *                    Dit is het moment voor een reviewverzoek.
	 *
	 * Dat ze alle drie bestaan is met opzet: veel shops zetten een order nooit
	 * op "afgerond", en dan ging onder de oude opzet nooit een enkele ordermail
	 * de deur uit zonder dat iemand kon zien waarom.
	 *
	 * De laatste twee gaan over een PERSOON en niet over iets dat hij gekocht
	 * heeft:
	 *  - subscriber_added  iemand is nieuw op de inschrijvingenlijst gekomen, via
	 *                      de popup, het vinkje bij het afrekenen of met de hand.
	 *                      Dit is het moment voor een welkomstmail en voor de
	 *                      mails die daarna komen.
	 *  - account_created   iemand heeft een account in de winkel aangemaakt, ook
	 *                      zonder bestelling.
	 *
	 * WAAROM DIE TWEE ERBIJ MOESTEN
	 * Shops die van een ander mailpakket naar deze tool gaan hebben daar bijna
	 * altijd een welkomstreeks op "nieuwe inschrijving" staan. Zonder dit moment
	 * was die reeks hier niet na te bouwen, en dan moest het oude pakket aan
	 * blijven staan voor die ene mail. Dat is precies hoe je twee mailsystemen
	 * naast elkaar houdt die elkaars afmeldingen niet kennen.
	 */
	const TRIGGER_TYPES = array( 'abandoned_cart', 'order_placed', 'order_paid', 'order_completed', 'subscriber_added', 'account_created' );

	/** De triggers die bij een order horen; die bouwen hun context uit de order. */
	const ORDER_TRIGGERS = array( 'order_placed', 'order_paid', 'order_completed' );

	/**
	 * De triggers die aan een persoon hangen en niet aan een order of een
	 * winkelwagen. Ze hebben dus geen bron die kan verdwijnen: de gegevens voor
	 * de mail komen uit de wachtrij-rij zelf en uit de inschrijving.
	 */
	const CONTACT_TRIGGERS = array( 'subscriber_added', 'account_created' );

	const STATUSES = array( 'active', 'paused' );

	/**
	 * Hoe de triggers op het scherm heten.
	 *
	 * Eén plek, want dit lijstje stond eerder drie keer in de admin-bestanden en
	 * liep daardoor uit elkaar: een nieuwe trigger kwam dan wel in de keuzelijst
	 * maar bleef in het overzicht als `order_paid` staan.
	 *
	 * @return array sleutel => label
	 */
	public static function trigger_labels() {
		return array(
			'abandoned_cart'   => __( 'Verlaten winkelwagen', 'ws-flow-mailer' ),
			'order_placed'     => __( 'Bestelling geplaatst', 'ws-flow-mailer' ),
			'order_paid'       => __( 'Bestelling betaald', 'ws-flow-mailer' ),
			'order_completed'  => __( 'Order afgerond (verzonden)', 'ws-flow-mailer' ),
			'subscriber_added' => __( 'Nieuwe inschrijving op de lijst', 'ws-flow-mailer' ),
			'account_created'  => __( 'Nieuw klantaccount', 'ws-flow-mailer' ),
		);
	}

	/**
	 * Het label van één trigger, of de sleutel zelf als we hem niet kennen.
	 *
	 * @param string $trigger_type Trigger.
	 * @return string
	 */
	public static function trigger_label( $trigger_type ) {
		$labels = self::trigger_labels();
		return isset( $labels[ $trigger_type ] ) ? $labels[ $trigger_type ] : (string) $trigger_type;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_flows';
	}

	/**
	 * Fetch one flow (steps decoded).
	 *
	 * @param int $flow_id Flow id.
	 * @return object|null Flow row with ->steps as array.
	 */
	public static function get( $flow_id ) {
		global $wpdb;

		$table = self::table();
		$flow  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $flow_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $flow ? self::decode_steps( $flow ) : null;
	}

	/**
	 * All flows, optionally filtered.
	 *
	 * @param string|null $trigger_type Filter by trigger type.
	 * @param string|null $status       Filter by status.
	 * @return object[]
	 */
	public static function get_all( $trigger_type = null, $status = null ) {
		global $wpdb;

		$table = self::table();
		$where = array( '1=1' );
		$args  = array();

		if ( $trigger_type ) {
			$where[] = 'trigger_type = %s';
			$args[]  = $trigger_type;
		}
		if ( $status ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';
		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		return array_map( array( __CLASS__, 'decode_steps' ), (array) $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Active flows for a trigger type.
	 *
	 * @param string $trigger_type Trigger type.
	 * @return object[]
	 */
	public static function get_active( $trigger_type ) {
		return self::get_all( $trigger_type, 'active' );
	}

	/**
	 * Decode the steps JSON into a sanitised array of step objects.
	 *
	 * @param object $flow Raw row.
	 * @return object Flow with ->steps as array of arrays.
	 */
	private static function decode_steps( $flow ) {
		$steps       = json_decode( (string) $flow->steps, true );
		$flow->steps = array();

		if ( is_array( $steps ) ) {
			foreach ( $steps as $step ) {
				if ( ! is_array( $step ) || empty( $step['template_id'] ) ) {
					continue;
				}
				$flow->steps[] = array(
					'wait_minutes'  => max( 0, (int) $step['wait_minutes'] ),
					'template_id'   => (int) $step['template_id'],
					'stop_on_order' => ! empty( $step['stop_on_order'] ),
				);
			}
		}

		return $flow;
	}

	/**
	 * Create or update a flow.
	 *
	 * @param array $data { id?, name, trigger_type, status, steps (array) }.
	 * @return int|WP_Error Flow id.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$name         = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		$trigger_type = isset( $data['trigger_type'] ) ? $data['trigger_type'] : '';
		$status       = isset( $data['status'] ) ? $data['status'] : 'paused';

		if ( '' === $name ) {
			return new WP_Error( 'wsfm_flow_name', __( 'Geef de flow een naam.', 'ws-flow-mailer' ) );
		}
		if ( ! in_array( $trigger_type, self::TRIGGER_TYPES, true ) ) {
			return new WP_Error( 'wsfm_flow_trigger', __( 'Ongeldig trigger-type.', 'ws-flow-mailer' ) );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'paused';
		}

		$steps = array();
		foreach ( (array) ( isset( $data['steps'] ) ? $data['steps'] : array() ) as $step ) {
			$template_id = isset( $step['template_id'] ) ? (int) $step['template_id'] : 0;
			if ( $template_id < 1 ) {
				continue;
			}
			$steps[] = array(
				'wait_minutes'  => max( 0, isset( $step['wait_minutes'] ) ? (int) $step['wait_minutes'] : 0 ),
				'template_id'   => $template_id,
				'stop_on_order' => ! empty( $step['stop_on_order'] ),
			);
		}

		if ( empty( $steps ) ) {
			return new WP_Error( 'wsfm_flow_steps', __( 'Een flow heeft minimaal één stap met een template nodig.', 'ws-flow-mailer' ) );
		}

		$row = array(
			'name'         => $name,
			'trigger_type' => $trigger_type,
			'status'       => $status,
			'steps'        => wp_json_encode( $steps ),
			'updated_at'   => current_time( 'mysql' ),
		);

		$flow_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( $flow_id > 0 ) {
			/* Wordt het trigger-type gewijzigd, dan moeten de items die nog in de
			   wachtrij staan STOPPEN.

			   WAAROM DIT MOEST
			   Het bewerkscherm waarschuwde hier al voor ("die items worden
			   gestopt"), maar er gebeurde niets: de rijen bleven staan met hun
			   oude bron. Een item van een verlaten winkelwagen dat straks door
			   een order-flow wordt opgepakt zoekt dan een order die er niet is,
			   en andersom. In het ergste geval krijgt iemand een mail over een
			   winkelwagen die hij vier dagen geleden al heeft afgerekend.

			   Alleen bij een ECHTE wijziging, en alleen pending/processing: wat
			   verstuurd is blijft staan, want dat is geschiedenis. */
			$oud = $wpdb->get_var( $wpdb->prepare( 'SELECT trigger_type FROM ' . self::table() . ' WHERE id = %d', $flow_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

			$wpdb->update( self::table(), $row, array( 'id' => $flow_id ) );

			if ( $oud && $oud !== $trigger_type ) {
				self::stop_pending( $flow_id );
			}

			return $flow_id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Alles wat van deze flow nog in de wachtrij staat stopzetten.
	 *
	 * @param int $flow_id Flow id.
	 * @return int Aantal gestopte items.
	 */
	public static function stop_pending( $flow_id ) {
		global $wpdb;

		$queue = $wpdb->prefix . 'wsfm_queue';

		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$queue} SET status = 'stopped' WHERE flow_id = %d AND status IN ('pending','processing')", $flow_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Toggle a flow between active and paused.
	 *
	 * @param int $flow_id Flow id.
	 * @return string|false New status, or false when the flow is missing.
	 */
	public static function toggle( $flow_id ) {
		global $wpdb;

		$flow = self::get( $flow_id );
		if ( ! $flow ) {
			return false;
		}

		$new_status = ( 'active' === $flow->status ) ? 'paused' : 'active';
		$wpdb->update(
			self::table(),
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $flow_id )
		);

		return $new_status;
	}

	/**
	 * Delete a flow (pending queue items are stopped, history stays).
	 *
	 * @param int $flow_id Flow id.
	 */
	public static function delete( $flow_id ) {
		global $wpdb;

		self::stop_pending( $flow_id );
		$wpdb->delete( self::table(), array( 'id' => $flow_id ) );
	}
}
