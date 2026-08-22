<?php
/**
 * Lijsten: groepen mensen die post van deze winkel willen.
 *
 * WAAROM DIT NAAST DE INSCHRIJVINGEN STAAT
 * De inschrijvingen zijn de mensen; de lijsten zijn de indeling. Eén adres kan
 * op meer dan één lijst staan, en dat is geen luxe: "nieuwsbrief" en "vaste
 * klanten" is geen keuze tussen twee dingen. Vandaar een koppeltabel en geen
 * kolom op de inschrijving.
 *
 * DE HOOFDLIJST
 * Er is er altijd precies één, en die kun je niet weggooien. Dat is waar de
 * popup en het vinkje bij het afrekenen standaard naartoe schrijven. Zonder zo'n
 * vaste bestemming zou een winkelier zijn enige lijst kunnen verwijderen en
 * daarna inschrijvingen binnenkrijgen die nergens terechtkomen.
 *
 * WIE ER OP EEN LIJST HOORT
 * Alleen mensen die er zelf om gevraagd hebben: via de popup, of via het vinkje
 * bij het afrekenen. Klanten die alleen besteld hebben staan hier niet op; die
 * zijn een aparte doelgroep met een andere juridische grond, en die twee horen
 * niet op één hoop.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Lijsten {

	/** Hoogste aantal adressen dat we in één keer uit een lijst halen. */
	const MAX = 20000;

	/**
	 * Tabelnaam.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_lijsten';
	}

	/**
	 * Tabel met wie op welke lijst staat.
	 *
	 * @return string
	 */
	public static function leden_table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_lijst_leden';
	}

	/* ---------------------------------------------------------------------
	 * Lezen
	 * ------------------------------------------------------------------- */

	/**
	 * Alle lijsten, met het aantal mensen erop.
	 *
	 * Het tellen gebeurt in dezelfde query en niet per lijst. Anders krijg je
	 * een scherm dat bij tien lijsten elf keer de database bevraagt, en dat is
	 * precies het soort traagheid dat later niemand meer kan verklaren.
	 *
	 * @return object[]
	 */
	public static function alles() {
		global $wpdb;

		$lijsten = self::table();
		$leden   = self::leden_table();

		return (array) $wpdb->get_results( "SELECT l.*, COUNT(m.id) AS aantal FROM {$lijsten} l LEFT JOIN {$leden} m ON m.lijst_id = l.id GROUP BY l.id ORDER BY l.is_hoofdlijst DESC, l.naam ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Eén lijst.
	 *
	 * @param int $id Lijst-id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Het id van de hoofdlijst.
	 *
	 * @return int Nul als er nog geen is, wat alleen kan voordat de installer
	 *             heeft gedraaid.
	 */
	public static function hoofdlijst() {
		global $wpdb;

		$table = self::table();
		return (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_hoofdlijst = 1 ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Een id dat echt bestaat, of anders de hoofdlijst.
	 *
	 * Gebruikt overal waar een lijstkeuze uit een formulier komt. Een verwijderde
	 * lijst in een opgeslagen instelling zou anders betekenen dat inschrijvingen
	 * in het niets verdwijnen, en dat merk je pas als iemand vraagt waarom hij
	 * geen post krijgt.
	 *
	 * @param mixed $id Wat er binnenkwam.
	 * @return int
	 */
	public static function geldig( $id ) {
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id > 0 && self::get( $id ) ) {
			return $id;
		}
		return self::hoofdlijst();
	}

	/**
	 * Hoeveel mensen er op een lijst staan.
	 *
	 * @param int $lijst_id Lijst-id.
	 * @return int
	 */
	public static function aantal( $lijst_id ) {
		global $wpdb;

		$leden = self::leden_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leden} WHERE lijst_id = %d", (int) $lijst_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * De adressen op een lijst, als e-mail => voornaam.
	 *
	 * Wie zich heeft afgemeld valt hier al af en niet pas bij het versturen.
	 * Anders belooft het scherm "gaat naar 412 mensen" terwijl er 380 vertrekken.
	 *
	 * @param int $lijst_id Lijst-id.
	 * @param int $limiet   Bovengrens.
	 * @return array
	 */
	public static function adressen( $lijst_id, $limiet = self::MAX ) {
		global $wpdb;

		$leden = self::leden_table();
		$subs  = WSFM_Subscribers::table();

		$rijen = (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.email, s.first_name FROM {$leden} m INNER JOIN {$subs} s ON s.id = m.subscriber_id WHERE m.lijst_id = %d ORDER BY m.id DESC LIMIT %d", (int) $lijst_id, (int) $limiet ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$uit = array();
		foreach ( $rijen as $rij ) {
			$adres = strtolower( trim( (string) $rij->email ) );
			if ( ! is_email( $adres ) || WSFM_Suppression::is_suppressed( $adres ) ) {
				continue;
			}
			$uit[ $adres ] = (string) $rij->first_name;
		}

		return $uit;
	}

	/**
	 * Op welke lijsten een hele pagina mensen staat, in EEN query.
	 *
	 * Voor het overzichtsscherm. Per rij vragen zou bij vijftig regels vijftig
	 * keer de database bevragen, en dat is precies het soort traagheid dat later
	 * niemand meer kan verklaren.
	 *
	 * @param int[] $ids Inschrijving-ids.
	 * @return array id => array van lijstnamen
	 */
	public static function van_meerdere( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$leden   = self::leden_table();
		$lijsten = self::table();
		$plek    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$rijen = (array) $wpdb->get_results( $wpdb->prepare( "SELECT m.subscriber_id, l.naam FROM {$leden} m INNER JOIN {$lijsten} l ON l.id = m.lijst_id WHERE m.subscriber_id IN ({$plek}) ORDER BY l.is_hoofdlijst DESC, l.naam ASC", $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$uit = array();
		foreach ( $rijen as $rij ) {
			$uit[ (int) $rij->subscriber_id ][] = (string) $rij->naam;
		}

		return $uit;
	}

	/**
	 * Op welke lijsten iemand staat.
	 *
	 * @param int $subscriber_id Inschrijving-id.
	 * @return int[] Lijst-ids.
	 */
	public static function lijsten_van( $subscriber_id ) {
		global $wpdb;

		$leden = self::leden_table();
		$ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT lijst_id FROM {$leden} WHERE subscriber_id = %d", (int) $subscriber_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', $ids );
	}

	/* ---------------------------------------------------------------------
	 * Schrijven
	 * ------------------------------------------------------------------- */

	/**
	 * Een lijst aanmaken.
	 *
	 * @param string $naam         Naam.
	 * @param string $omschrijving Waar hij voor is.
	 * @return int|WP_Error Nieuw id.
	 */
	public static function maak( $naam, $omschrijving = '' ) {
		global $wpdb;

		$naam = trim( sanitize_text_field( $naam ) );
		if ( '' === $naam ) {
			return new WP_Error( 'wsfm_lijst_naam', __( 'Geef de lijst een naam, anders weet je later niet meer wie erop staan.', 'ws-flow-mailer' ) );
		}

		$wpdb->insert(
			self::table(),
			array(
				'naam'          => mb_substr( $naam, 0, 190 ),
				'omschrijving'  => mb_substr( sanitize_text_field( $omschrijving ), 0, 255 ),
				'is_hoofdlijst' => 0,
				'created_at'    => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Naam en omschrijving wijzigen.
	 *
	 * @param int    $id           Lijst-id.
	 * @param string $naam         Naam.
	 * @param string $omschrijving Omschrijving.
	 * @return true|WP_Error
	 */
	public static function hernoem( $id, $naam, $omschrijving = '' ) {
		global $wpdb;

		if ( ! self::get( $id ) ) {
			return new WP_Error( 'wsfm_lijst_weg', __( 'Die lijst bestaat niet meer.', 'ws-flow-mailer' ) );
		}

		$naam = trim( sanitize_text_field( $naam ) );
		if ( '' === $naam ) {
			return new WP_Error( 'wsfm_lijst_naam', __( 'Geef de lijst een naam.', 'ws-flow-mailer' ) );
		}

		$wpdb->update(
			self::table(),
			array(
				'naam'         => mb_substr( $naam, 0, 190 ),
				'omschrijving' => mb_substr( sanitize_text_field( $omschrijving ), 0, 255 ),
			),
			array( 'id' => (int) $id )
		);

		return true;
	}

	/**
	 * Een lijst weggooien.
	 *
	 * De hoofdlijst kan niet weg: daar schrijven de popup en het afrekenvinkje
	 * naartoe, en een bestemming die kan verdwijnen is geen bestemming.
	 *
	 * De mensen zelf blijven bestaan. Ze staan alleen niet meer op deze lijst.
	 * Iemand van een lijst halen is iets anders dan hem afmelden, en dat verschil
	 * hoort niet te vervagen omdat het toevallig in dezelfde handeling zit.
	 *
	 * @param int $id Lijst-id.
	 * @return true|WP_Error
	 */
	public static function verwijder( $id ) {
		global $wpdb;

		$lijst = self::get( $id );
		if ( ! $lijst ) {
			return true;
		}
		if ( ! empty( $lijst->is_hoofdlijst ) ) {
			return new WP_Error( 'wsfm_lijst_hoofd', __( 'Je hoofdlijst kan niet weg. Daar komen nieuwe inschrijvingen binnen.', 'ws-flow-mailer' ) );
		}

		$wpdb->delete( self::leden_table(), array( 'lijst_id' => (int) $id ) );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );

		return true;
	}

	/**
	 * Iemand op een lijst zetten.
	 *
	 * Staat hij er al op, dan gebeurt er niets en is dat geen fout: twee keer
	 * hetzelfde formulier invullen hoort geen foutmelding op te leveren.
	 *
	 * @param int $lijst_id      Lijst-id.
	 * @param int $subscriber_id Inschrijving-id.
	 * @return bool
	 */
	public static function schrijf_in( $lijst_id, $subscriber_id ) {
		global $wpdb;

		$lijst_id      = (int) $lijst_id;
		$subscriber_id = (int) $subscriber_id;

		if ( $lijst_id < 1 || $subscriber_id < 1 ) {
			return false;
		}

		$leden = self::leden_table();
		$staat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leden} WHERE lijst_id = %d AND subscriber_id = %d", $lijst_id, $subscriber_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $staat ) {
			return true;
		}

		$wpdb->insert(
			$leden,
			array(
				'lijst_id'      => $lijst_id,
				'subscriber_id' => $subscriber_id,
				'created_at'    => current_time( 'mysql' ),
			)
		);

		return true;
	}

	/**
	 * Iemand van een lijst halen.
	 *
	 * @param int $lijst_id      Lijst-id.
	 * @param int $subscriber_id Inschrijving-id.
	 * @return bool
	 */
	public static function schrijf_uit( $lijst_id, $subscriber_id ) {
		global $wpdb;

		return (bool) $wpdb->delete(
			self::leden_table(),
			array(
				'lijst_id'      => (int) $lijst_id,
				'subscriber_id' => (int) $subscriber_id,
			)
		);
	}

	/**
	 * Alle koppelingen van iemand weghalen.
	 *
	 * Nodig als een inschrijving zelf verdwijnt: anders blijven er regels staan
	 * die naar niemand meer wijzen, en tellen die mee in het aantal op het
	 * scherm.
	 *
	 * @param int $subscriber_id Inschrijving-id.
	 * @return void
	 */
	public static function vergeet_lid( $subscriber_id ) {
		global $wpdb;

		$wpdb->delete( self::leden_table(), array( 'subscriber_id' => (int) $subscriber_id ) );
	}
}
