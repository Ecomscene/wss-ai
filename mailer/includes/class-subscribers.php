<?php
/**
 * Inschrijvingen: mensen die zich uit zichzelf hebben aangemeld.
 *
 * WAAROM DIT EEN EIGEN LIJST IS EN NIET "DE KLANTEN"
 * Een klant mag je mailen omdat hij bij je gekocht heeft. Iemand die alleen zijn
 * adres in een popup typt is geen klant, maar heeft wel zelf om post gevraagd.
 * Dat zijn twee verschillende gronden, en ze horen dus ook niet op één hoop:
 * gaat het ooit mis, dan moet je kunnen laten zien waarom je iemand mailde.
 *
 * De afmeldlijst blijft er bovenop liggen. Wie zich afmeldt verdwijnt uit alles.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Subscribers {

	/**
	 * Tabelnaam.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_subscribers';
	}

	/**
	 * Iemand toevoegen.
	 *
	 * Bestaat het adres al, dan wordt er niets overschreven: de eerste
	 * inschrijving telt, inclusief de code die toen is uitgegeven. Anders zou
	 * iemand het formulier tien keer kunnen invullen voor tien kortingscodes.
	 *
	 * @param string $email  E-mailadres.
	 * @param string $bron   Waar hij vandaan komt (popup, afrekenen).
	 * @param string $code   Uitgegeven kortingscode.
	 * @param string $naam   Voornaam, als we die hebben.
	 * @return array { nieuw: bool, code: string }
	 */
	public static function add( $email, $bron = 'popup', $code = '', $naam = '' ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );

		$bestaand = self::get( $email );
		if ( $bestaand ) {
			return array(
				'nieuw' => false,
				'code'  => (string) $bestaand->coupon_code,
			);
		}

		$wpdb->insert(
			self::table(),
			array(
				'email'       => $email,
				'first_name'  => sanitize_text_field( $naam ),
				'source'      => sanitize_key( $bron ),
				'coupon_code' => sanitize_text_field( $code ),
				'created_at'  => current_time( 'mysql' ),
			)
		);

		return array(
			'nieuw' => true,
			'code'  => (string) $code,
		);
	}

	/**
	 * Eén inschrijving.
	 *
	 * @param string $email E-mailadres.
	 * @return object|null
	 */
	public static function get( $email ) {
		global $wpdb;

		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", strtolower( trim( (string) $email ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Alle inschrijvingen als e-mail => voornaam.
	 *
	 * @param int $limiet Bovengrens.
	 * @return array
	 */
	public static function alles( $limiet = 20000 ) {
		global $wpdb;

		$table = self::table();
		$rijen = (array) $wpdb->get_results( $wpdb->prepare( "SELECT email, first_name FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limiet ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$uit = array();
		foreach ( $rijen as $rij ) {
			$uit[ strtolower( $rij->email ) ] = (string) $rij->first_name;
		}

		return $uit;
	}

	/**
	 * Hoeveel er zijn.
	 *
	 * @return int
	 */
	public static function aantal() {
		global $wpdb;

		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * De laatste inschrijvingen, voor het beheerscherm.
	 *
	 * @param int $limiet Aantal.
	 * @return object[]
	 */
	public static function recent( $limiet = 20 ) {
		global $wpdb;

		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limiet ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
