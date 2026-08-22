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
	 * Iemand toevoegen, en op een lijst zetten.
	 *
	 * Bestaat het adres al, dan wordt er niets overschreven: de eerste
	 * inschrijving telt, inclusief de code die toen is uitgegeven. Anders zou
	 * iemand het formulier tien keer kunnen invullen voor tien kortingscodes.
	 *
	 * Hij komt WEL op de gevraagde lijst te staan, ook als hij al bestond. Wie
	 * zich via de popup had aangemeld en later bij het afrekenen een vinkje
	 * zet, hoort op allebei de lijsten te belanden; dat is geen dubbele
	 * inschrijving maar een tweede plek waar hij thuishoort.
	 *
	 * @param string $email       E-mailadres.
	 * @param string $bron        Waar hij vandaan komt (popup, afrekenen, import).
	 * @param string $code        Uitgegeven kortingscode.
	 * @param string $naam        Voornaam, als we die hebben.
	 * @param string $achternaam  Achternaam, als we die hebben.
	 * @param int    $lijst_id    Op welke lijst. Nul betekent de hoofdlijst.
	 * @param string $toestemming De tekst waar hij ja op zei, als die er is.
	 * @return array { nieuw: bool, code: string, id: int }
	 */
	public static function add( $email, $bron = 'popup', $code = '', $naam = '', $lijst_id = 0, $toestemming = '', $achternaam = '' ) {
		global $wpdb;

		$email = strtolower( trim( (string) $email ) );
		$lijst = class_exists( 'WSFM_Lijsten' ) ? WSFM_Lijsten::geldig( $lijst_id ) : 0;

		$bestaand = self::get( $email );
		if ( $bestaand ) {
			if ( $lijst ) {
				WSFM_Lijsten::schrijf_in( $lijst, (int) $bestaand->id );
			}
			return array(
				'nieuw' => false,
				'code'  => (string) $bestaand->coupon_code,
				'id'    => (int) $bestaand->id,
			);
		}

		/* De tekst waar iemand ja op zei bewaren we mee. Toestemming moet je
		   kunnen aantonen, en "hij heeft ooit een vinkje gezet" is geen bewijs
		   als je niet weet waar dat vinkje bij stond. */
		$toestemming = mb_substr( sanitize_text_field( (string) $toestemming ), 0, 255 );

		$wpdb->insert(
			self::table(),
			array(
				'email'        => $email,
				'first_name'   => sanitize_text_field( $naam ),
				'last_name'    => sanitize_text_field( $achternaam ),
				'source'       => sanitize_key( $bron ),
				'coupon_code'  => sanitize_text_field( $code ),
				'consent_text' => $toestemming,
				'consent_at'   => '' === $toestemming ? null : current_time( 'mysql' ),
				'created_at'   => current_time( 'mysql' ),
			)
		);

		$id = (int) $wpdb->insert_id;

		if ( $lijst && $id ) {
			WSFM_Lijsten::schrijf_in( $lijst, $id );
		}

		return array(
			'nieuw' => true,
			'code'  => (string) $code,
			'id'    => $id,
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
	 * De WHERE en de waarden die bij een zoekterm en een lijstfilter horen.
	 *
	 * Eén plek, want tellen en ophalen moeten hetzelfde filter gebruiken. Liep
	 * dat uiteen, dan stond er boven de tabel een ander getal dan er rijen in
	 * staan, en dat is precies het soort verschil waar niemand meer uitkomt.
	 *
	 * @param string $zoek     Zoekterm.
	 * @param int    $lijst_id Alleen deze lijst. Nul is alles.
	 * @return array { sql: string, waarden: array }
	 */
	private static function filter( $zoek, $lijst_id ) {
		global $wpdb;

		$waar    = array();
		$waarden = array();

		$zoek = trim( (string) $zoek );
		if ( '' !== $zoek ) {
			$als = '%' . $wpdb->esc_like( $zoek ) . '%';
			$waar[] = '( s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s )';
			$waarden[] = $als;
			$waarden[] = $als;
			$waarden[] = $als;
		}

		$lijst_id = is_numeric( $lijst_id ) ? (int) $lijst_id : 0;
		if ( $lijst_id > 0 ) {
			$leden = WSFM_Lijsten::leden_table();
			$waar[] = "s.id IN ( SELECT subscriber_id FROM {$leden} WHERE lijst_id = %d )";
			$waarden[] = $lijst_id;
		}

		return array(
			'sql'     => empty( $waar ) ? '' : ' WHERE ' . implode( ' AND ', $waar ),
			'waarden' => $waarden,
		);
	}

	/**
	 * Hoeveel er zijn.
	 *
	 * @param string $zoek     Alleen tellen wat hierop lijkt. Leeg is alles.
	 * @param int    $lijst_id Alleen deze lijst. Nul is alles.
	 * @return int
	 */
	public static function aantal( $zoek = '', $lijst_id = 0 ) {
		global $wpdb;

		$table = self::table();
		$f     = self::filter( $zoek, $lijst_id );

		if ( '' === $f['sql'] ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} s" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} s" . $f['sql'], $f['waarden'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @param string $zoek       Zoekterm op adres of naam.
	 * @param int    $lijst_id   Alleen deze lijst. Nul is alles.
	 * @return object[]
	 */
	public static function lijst( $per_pagina = 50, $pagina = 1, $zoek = '', $lijst_id = 0 ) {
		global $wpdb;

		$table = self::table();

		/* absint() zou van -50 gewoon 50 maken en van "abc" een 0, en dan staat
		   er ineens LIMIT 0 in de query. Dus zelf begrenzen. */
		$per_pagina = is_numeric( $per_pagina ) ? (int) $per_pagina : 50;
		$per_pagina = max( 1, min( 500, $per_pagina ) );

		$pagina = is_numeric( $pagina ) ? (int) $pagina : 1;
		$vanaf  = max( 0, ( max( 1, $pagina ) - 1 ) * $per_pagina );

		$f       = self::filter( $zoek, $lijst_id );
		$waarden = array_merge( $f['waarden'], array( $per_pagina, $vanaf ) );

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT s.* FROM {$table} s" . $f['sql'] . ' ORDER BY s.id DESC LIMIT %d OFFSET %d', $waarden ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * De naam zoals je hem opschrijft.
	 *
	 * Voornaam en achternaam aan elkaar, en een streepje als er niets staat.
	 * Op een scherm vol adressen is een lege cel niet te onderscheiden van een
	 * cel die nog moet laden.
	 *
	 * @param object $rij Rij uit de tabel.
	 * @return string
	 */
	public static function naam_van( $rij ) {
		$naam = trim(
			( isset( $rij->first_name ) ? $rij->first_name : '' ) . ' ' .
			( isset( $rij->last_name ) ? $rij->last_name : '' )
		);

		return '' === $naam ? '-' : $naam;
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

		/* Ook van alle lijsten af. Blijven die regels staan, dan wijzen ze naar
		   niemand meer en tellen ze wel mee in het aantal op het scherm. */
		if ( class_exists( 'WSFM_Lijsten' ) ) {
			WSFM_Lijsten::vergeet_lid( $id );
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
	 * @return array Lijst van array( email, voornaam, achternaam ), ontdubbeld.
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
			$achternaam = '';

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
					continue;
				}
				/* Een derde veld dat geen adres is, is de achternaam. Zo werkt een
				   export met voornaam en achternaam in aparte kolommen ook. */
				if ( '' === $achternaam && ! is_email( $veld ) ) {
					$achternaam = $veld;
				}
			}

			if ( '' === $email ) {
				/* Ook de ongeldige regels teruggeven, want de teller op het scherm
				   moet kunnen zeggen hoeveel er zijn afgevallen. Een import die
				   stilletjes de helft weglaat is erger dan een import die weigert. */
				$uit[] = array( '', '', '' );
				continue;
			}

			$uit[ $email ] = array( $email, $naam, $achternaam );
		}

		return array_values( $uit );
	}

	/**
	 * Een lijst adressen toevoegen.
	 *
	 * @param array  $paren Lijst van array( email, naam ), uit ontleed().
	 * @param string $bron  Waar ze vandaan komen.
	 * @param int    $lijst_id Op welke lijst ze komen. Nul is de hoofdlijst.
	 * @return array { toegevoegd, bestond, ongeldig, afgemeld }
	 */
	public static function importeer( array $paren, $bron = 'import', $lijst_id = 0 ) {
		$telling = array(
			'toegevoegd' => 0,
			'bestond'    => 0,
			'ongeldig'   => 0,
			'afgemeld'   => 0,
		);

		foreach ( $paren as $paar ) {
			$email = isset( $paar[0] ) ? (string) $paar[0] : '';
			$naam  = isset( $paar[1] ) ? (string) $paar[1] : '';
			$achternaam = isset( $paar[2] ) ? (string) $paar[2] : '';

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

			$uitkomst = self::add( $email, $bron, '', $naam, $lijst_id, '', $achternaam );

			if ( ! empty( $uitkomst['nieuw'] ) ) {
				$telling['toegevoegd']++;
			} else {
				$telling['bestond']++;
			}
		}

		return $telling;
	}
}
