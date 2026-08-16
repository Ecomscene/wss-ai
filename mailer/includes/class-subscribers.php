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
	 * @param string $zoek Alleen tellen wat hierop lijkt. Leeg is alles.
	 * @return int
	 */
	public static function aantal( $zoek = '' ) {
		global $wpdb;

		$table = self::table();
		$zoek  = trim( (string) $zoek );

		if ( '' === $zoek ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$als = '%' . $wpdb->esc_like( $zoek ) . '%';
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email LIKE %s OR first_name LIKE %s", $als, $als ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	/**
	 * Eén pagina uit de lijst, voor het overzichtsscherm.
	 *
	 * Per pagina en niet alles in één keer: een winkel met twintigduizend
	 * inschrijvingen zou anders twintigduizend rijen in een tabel zetten en het
	 * geheugen van wp-admin opeten.
	 *
	 * @param int    $per_pagina Rijen per pagina.
	 * @param int    $pagina     Paginanummer, vanaf 1.
	 * @param string $zoek       Zoekterm op adres of voornaam.
	 * @return object[]
	 */
	public static function lijst( $per_pagina = 50, $pagina = 1, $zoek = '' ) {
		global $wpdb;

		$table = self::table();

		/* absint() zou van -50 gewoon 50 maken en van "abc" een 0, en dan staat
		   er ineens LIMIT 0 in de query. Dus zelf begrenzen. */
		$per_pagina = is_numeric( $per_pagina ) ? (int) $per_pagina : 50;
		$per_pagina = max( 1, min( 500, $per_pagina ) );

		$pagina = is_numeric( $pagina ) ? (int) $pagina : 1;
		$vanaf  = max( 0, ( max( 1, $pagina ) - 1 ) * $per_pagina );

		$zoek = trim( (string) $zoek );

		if ( '' !== $zoek ) {
			$als = '%' . $wpdb->esc_like( $zoek ) . '%';
			return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email LIKE %s OR first_name LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d", $als, $als, $per_pagina, $vanaf ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_pagina, $vanaf ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Iemand van de lijst halen.
	 *
	 * Dit haalt hem alleen uit de inschrijvingen. Het zet hem NIET op de
	 * afmeldlijst: dat is een ander besluit met een ander gevolg, en wie een
	 * dubbele regel opruimt bedoelt niet dat dat adres nooit meer post mag
	 * krijgen. Wil de winkelier dat wel, dan is daar de afmeldlijst voor.
	 *
	 * @param int $id Rij-id.
	 * @return bool
	 */
	public static function verwijder( $id ) {
		global $wpdb;

		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id < 1 ) {
			return false;
		}

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/**
	 * Losse regels omzetten naar adressen met een voornaam.
	 *
	 * Wat er binnenkomt is geplakt uit een ander pakket of uit Excel, dus het is
	 * van alles: "jan@voorbeeld.nl", "Jan;jan@voorbeeld.nl", een regel met
	 * aanhalingstekens, of een kopregel die "email,naam" heet. Daarom wordt er
	 * niet op kolomvolgorde gerekend maar gezocht naar het veld dat een
	 * e-mailadres IS. Alles wat overblijft en geen adres is, is de voornaam.
	 *
	 * @param array $regels Ruwe regels of al gesplitste velden.
	 * @return array Lijst van array( email, naam ), ontdubbeld.
	 */
	public static function ontleed( array $regels ) {
		$uit = array();

		foreach ( $regels as $regel ) {
			/* Lege regels tellen niet mee als fout. Een geplakte lijst eindigt
			   bijna altijd op een enter, en dan zou er "1 ongeldig" onder staan
			   bij een import die perfect ging. */
			if ( '' === trim( is_array( $regel ) ? implode( '', $regel ) : (string) $regel ) ) {
				continue;
			}

			$velden = is_array( $regel ) ? $regel : preg_split( '/[;,\t]/', (string) $regel );

			$email = '';
			$naam  = '';

			foreach ( (array) $velden as $veld ) {
				$veld = trim( trim( (string) $veld ), "\"' " );

				if ( '' === $veld ) {
					continue;
				}
				if ( '' === $email && is_email( $veld ) ) {
					$email = strtolower( $veld );
					continue;
				}
				if ( '' === $naam && ! is_email( $veld ) ) {
					$naam = $veld;
				}
			}

			if ( '' === $email ) {
				/* Ook de ongeldige regels teruggeven, want de teller op het scherm
				   moet kunnen zeggen hoeveel er zijn afgevallen. Een import die
				   stilletjes de helft weglaat is erger dan een import die weigert. */
				$uit[] = array( '', '' );
				continue;
			}

			$uit[ $email ] = array( $email, $naam );
		}

		return array_values( $uit );
	}

	/**
	 * Een lijst adressen toevoegen.
	 *
	 * @param array  $paren Lijst van array( email, naam ), uit ontleed().
	 * @param string $bron  Waar ze vandaan komen.
	 * @return array { toegevoegd, bestond, ongeldig, afgemeld }
	 */
	public static function importeer( array $paren, $bron = 'import' ) {
		$telling = array(
			'toegevoegd' => 0,
			'bestond'    => 0,
			'ongeldig'   => 0,
			'afgemeld'   => 0,
		);

		foreach ( $paren as $paar ) {
			$email = isset( $paar[0] ) ? (string) $paar[0] : '';
			$naam  = isset( $paar[1] ) ? (string) $paar[1] : '';

			if ( ! is_email( $email ) ) {
				$telling['ongeldig']++;
				continue;
			}

			/* Wie zich ooit heeft afgemeld komt er niet via een bestand weer in.
			   Dat is precies het gat waar een importknop voor gemaakt lijkt, en
			   het is het enige gat dat echt niet mag. */
			if ( WSFM_Suppression::is_suppressed( $email ) ) {
				$telling['afgemeld']++;
				continue;
			}

			$uitkomst = self::add( $email, $bron, '', $naam );

			if ( ! empty( $uitkomst['nieuw'] ) ) {
				$telling['toegevoegd']++;
			} else {
				$telling['bestond']++;
			}
		}

		return $telling;
	}
}
