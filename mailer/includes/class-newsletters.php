<?php
/**
 * Nieuwsbrieven: opslaan, ontvangers bepalen en versturen.
 *
 * HET VERSCHIL MET EEN FLOW
 * Een flow reageert op iemand: er is een bestelling of een winkelwagen, en
 * daaruit volgt een mail. Een nieuwsbrief begint aan de andere kant: er is een
 * bericht, en daar wordt een lijst ontvangers bij gezocht. Verder is het
 * dezelfde machinerie, dus de wachtrij, de suppressielijst, het opnieuw
 * proberen en het loggen zijn ongewijzigd overgenomen.
 *
 * WIE ER POST KRIJGT
 * Alleen mensen die bij deze winkel besteld hebben. Dat is geen technische maar
 * een juridische grens: voor eigen klanten mag een webshop ongevraagd reclame
 * sturen zolang er een afmeldlink in staat, voor willekeurige bezoekers niet.
 * Adressen die alleen in de winkelwagen-tracking staan zijn dus geen ontvangers.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Newsletters {

	/** Bestelstatussen die iemand tot klant maken. Geannuleerd en mislukt niet. */
	const KLANT_STATUSSEN = array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' );

	/** Hoeveel ontvangers we hoogstens in één keer in de wachtrij zetten. */
	const MAX_ONTVANGERS = 20000;

	/**
	 * Tabelnaam.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_newsletters';
	}

	/**
	 * De doelgroepen waar de winkelier uit kan kiezen.
	 *
	 * @return array key => label
	 */
	public static function doelgroepen() {
		return array(
			'klanten_jaar' => __( 'Klanten die het afgelopen jaar besteld hebben', 'ws-flow-mailer' ),
			'klanten_alle'   => __( 'Alle klanten die ooit besteld hebben', 'ws-flow-mailer' ),
			'inschrijvingen' => __( 'Iedereen die zich via de popup heeft ingeschreven', 'ws-flow-mailer' ),
			'alles'          => __( 'Klanten en inschrijvingen samen', 'ws-flow-mailer' ),
		);
	}

	/**
	 * Eén nieuwsbrief, met gedecodeerde blokken.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $row ) {
			return null;
		}

		return self::decode( $row );
	}

	/**
	 * Alle nieuwsbrieven, nieuwste eerst.
	 *
	 * @return object[]
	 */
	public static function get_all() {
		global $wpdb;

		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( array( __CLASS__, 'decode' ), $rows );
	}

	/**
	 * JSON-kolom uitpakken.
	 *
	 * @param object $row Databaserij.
	 * @return object
	 */
	private static function decode( $row ) {
		$blokken     = json_decode( (string) $row->blocks, true );
		$row->blocks = is_array( $blokken ) ? $blokken : array();
		return $row;
	}

	/**
	 * Opslaan (nieuw of bestaand).
	 *
	 * @param array $data { id?, name, subject, template, audience, blocks }.
	 * @return int|WP_Error Nieuwsbrief-id.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		/* Een verzonden nieuwsbrief staat vast. Hem daarna nog kunnen wijzigen
		   zou betekenen dat het scherm iets anders toont dan wat de klanten in
		   hun inbox hebben, en dan klopt het verzendlog niet meer. */
		if ( $id > 0 ) {
			$bestaand = self::get( $id );
			if ( $bestaand && 'concept' !== $bestaand->status ) {
				return new WP_Error( 'wsfm_nb_verzonden', __( 'Deze nieuwsbrief is al verstuurd en kan niet meer gewijzigd worden. Maak een kopie als je hem opnieuw wilt gebruiken.', 'ws-flow-mailer' ) );
			}
		}

		$naam = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		if ( '' === $naam ) {
			return new WP_Error( 'wsfm_nb_naam', __( 'Geef de nieuwsbrief een naam. Die zien alleen jij en je collega\'s.', 'ws-flow-mailer' ) );
		}

		$onderwerp = sanitize_text_field( isset( $data['subject'] ) ? $data['subject'] : '' );
		if ( '' === $onderwerp ) {
			return new WP_Error( 'wsfm_nb_onderwerp', __( 'Vul een onderwerp in. Dat is de regel die je klant in zijn inbox ziet.', 'ws-flow-mailer' ) );
		}

		$sjablonen = WSFM_Newsletter_Render::templates();
		$sjabloon  = isset( $data['template'] ) ? sanitize_key( $data['template'] ) : '';
		if ( ! isset( $sjablonen[ $sjabloon ] ) ) {
			$sjabloon = 'rustig';
		}

		$doelgroepen = self::doelgroepen();
		$doelgroep   = isset( $data['audience'] ) ? sanitize_key( $data['audience'] ) : '';
		if ( ! isset( $doelgroepen[ $doelgroep ] ) ) {
			$doelgroep = 'klanten_jaar';
		}

		$row = array(
			'name'       => $naam,
			'subject'    => $onderwerp,
			'template'   => $sjabloon,
			'audience'   => $doelgroep,
			'blocks'     => wp_json_encode( self::schoon_blokken( isset( $data['blocks'] ) ? $data['blocks'] : array() ) ),
			'updated_at' => current_time( 'mysql' ),
		);

		if ( $id > 0 ) {
			$wpdb->update( self::table(), $row, array( 'id' => $id ) );
			return $id;
		}

		$row['status']     = 'concept';
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Blokken uit het formulier omzetten naar iets wat we durven opslaan.
	 *
	 * Alles wat we niet kennen valt weg. Dat is strenger dan nodig lijkt, maar
	 * deze gegevens worden straks in HTML gezet die naar duizend mensen gaat;
	 * daar wil je geen veld in dat per ongeluk meelift.
	 *
	 * @param mixed $ruw Blokken uit $_POST.
	 * @return array
	 */
	public static function schoon_blokken( $ruw ) {
		if ( ! is_array( $ruw ) ) {
			return array();
		}

		$uit = array();

		foreach ( $ruw as $blok ) {
			if ( ! is_array( $blok ) ) {
				continue;
			}

			$soort = isset( $blok['soort'] ) ? sanitize_key( $blok['soort'] ) : '';

			if ( 'afbeelding' === $soort ) {
				$id = isset( $blok['afbeelding'] ) ? (int) $blok['afbeelding'] : 0;
				if ( ! $id ) {
					continue;
				}
				$uit[] = array(
					'soort'      => 'afbeelding',
					'afbeelding' => $id,
					'link'       => isset( $blok['link'] ) ? esc_url_raw( trim( (string) $blok['link'] ) ) : '',
				);
				continue;
			}

			if ( 'tekst' === $soort ) {
				$kop   = isset( $blok['kop'] ) ? sanitize_text_field( (string) $blok['kop'] ) : '';
				$tekst = isset( $blok['tekst'] ) ? sanitize_textarea_field( (string) $blok['tekst'] ) : '';
				if ( '' === $kop && '' === $tekst ) {
					continue;
				}
				$uit[] = array(
					'soort'    => 'tekst',
					'kop'      => $kop,
					'tekst'    => $tekst,
					'knop'     => isset( $blok['knop'] ) ? sanitize_text_field( (string) $blok['knop'] ) : '',
					'knop_url' => isset( $blok['knop_url'] ) ? esc_url_raw( trim( (string) $blok['knop_url'] ) ) : '',
				);
				continue;
			}

			if ( 'producten' === $soort ) {
				$ids = isset( $blok['producten'] ) ? $blok['producten'] : array();
				if ( is_string( $ids ) ) {
					$ids = explode( ',', $ids );
				}
				$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
				if ( empty( $ids ) ) {
					continue;
				}
				$uit[] = array(
					'soort'     => 'producten',
					'kop'       => isset( $blok['kop'] ) ? sanitize_text_field( (string) $blok['kop'] ) : '',
					'producten' => array_slice( $ids, 0, 12 ),
				);
			}
		}

		return $uit;
	}

	/**
	 * Verwijderen. Een verzonden nieuwsbrief blijft staan als geschiedenis.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;

		$brief = self::get( $id );
		if ( ! $brief ) {
			return true;
		}

		if ( 'concept' !== $brief->status ) {
			return new WP_Error( 'wsfm_nb_verzonden', __( 'Een verstuurde nieuwsbrief blijft bewaard, zodat je later kunt terugzien wat er gestuurd is.', 'ws-flow-mailer' ) );
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		return true;
	}

	/**
	 * Een kopie als nieuw concept.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return int|WP_Error Nieuw id.
	 */
	public static function duplicate( $id ) {
		$brief = self::get( $id );
		if ( ! $brief ) {
			return new WP_Error( 'wsfm_nb_weg', __( 'Deze nieuwsbrief bestaat niet meer.', 'ws-flow-mailer' ) );
		}

		return self::save(
			array(
				'name'     => sprintf( __( 'Kopie van %s', 'ws-flow-mailer' ), $brief->name ),
				'subject'  => $brief->subject,
				'template' => $brief->template,
				'audience' => $brief->audience,
				'blocks'   => $brief->blocks,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Ontvangers
	 * ------------------------------------------------------------------- */

	/**
	 * De ontvangers voor een doelgroep: adres plus voornaam, ontdubbeld.
	 *
	 * Adressen op de suppressielijst vallen hier al af, en niet pas bij het
	 * versturen. Anders belooft het scherm "gaat naar 412 klanten" terwijl er
	 * 380 vertrekken, en dan klopt het getal dat iemand op knopdruk ziet niet.
	 *
	 * @param string $doelgroep Doelgroepsleutel.
	 * @return array e-mail => voornaam
	 */
	public static function ontvangers( $doelgroep ) {
		global $wpdb;

		/* De inschrijvingen zijn een eigen lijst met een eigen grond: die mensen
		   hebben er zelf om gevraagd. Ze staan los van de bestellingen en worden
		   er hooguit bij opgeteld. */
		$ingeschreven = array();
		if ( in_array( $doelgroep, array( 'inschrijvingen', 'alles' ), true ) ) {
			foreach ( WSFM_Subscribers::alles( self::MAX_ONTVANGERS ) as $adres => $naam ) {
				if ( is_email( $adres ) && ! WSFM_Suppression::is_suppressed( $adres ) ) {
					$ingeschreven[ $adres ] = $naam;
				}
			}

			if ( 'inschrijvingen' === $doelgroep ) {
				return $ingeschreven;
			}
		}

		$vanaf = 'klanten_alle' === $doelgroep
			? '1970-01-01 00:00:00'
			: gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS );

		$statussen = "'" . implode( "','", array_map( 'esc_sql', self::KLANT_STATUSSEN ) ) . "'";
		$rijen     = array();

		if ( self::hpos() ) {
			$orders = $wpdb->prefix . 'wc_orders';
			$adres  = $wpdb->prefix . 'wc_order_addresses';

			$rijen = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT o.billing_email AS email, a.first_name AS voornaam, o.date_created_gmt AS besteld
				 FROM {$orders} o
				 LEFT JOIN {$adres} a ON a.order_id = o.id AND a.address_type = 'billing'
				 WHERE o.status IN ({$statussen})
				   AND o.billing_email <> ''
				   AND o.date_created_gmt >= %s
				 ORDER BY o.date_created_gmt DESC
				 LIMIT %d",
				$vanaf,
				self::MAX_ONTVANGERS * 3
			) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$posts = $wpdb->posts;
			$meta  = $wpdb->postmeta;

			$rijen = (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT em.meta_value AS email, vn.meta_value AS voornaam, p.post_date_gmt AS besteld
				 FROM {$posts} p
				 INNER JOIN {$meta} em ON em.post_id = p.ID AND em.meta_key = '_billing_email'
				 LEFT JOIN {$meta} vn ON vn.post_id = p.ID AND vn.meta_key = '_billing_first_name'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ({$statussen})
				   AND em.meta_value <> ''
				   AND p.post_date_gmt >= %s
				 ORDER BY p.post_date_gmt DESC
				 LIMIT %d",
				$vanaf,
				self::MAX_ONTVANGERS * 3
			) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$uit = array();

		foreach ( $rijen as $rij ) {
			$email = strtolower( trim( (string) $rij->email ) );

			if ( ! is_email( $email ) || isset( $uit[ $email ] ) ) {
				continue;
			}
			if ( WSFM_Suppression::is_suppressed( $email ) ) {
				continue;
			}

			$uit[ $email ] = sanitize_text_field( (string) $rij->voornaam );

			if ( count( $uit ) >= self::MAX_ONTVANGERS ) {
				break;
			}
		}

		/* Bij "samen" wint de klant van de inschrijving: die heeft een voornaam
		   uit zijn bestelling, en daar valt mee te schrijven. */
		return $uit + $ingeschreven;
	}

	/**
	 * Hoeveel mensen deze doelgroep oplevert.
	 *
	 * @param string $doelgroep Doelgroepsleutel.
	 * @return int
	 */
	public static function aantal_ontvangers( $doelgroep ) {
		return count( self::ontvangers( $doelgroep ) );
	}

	/**
	 * Draait WooCommerce op de nieuwe ordertabellen?
	 *
	 * @return bool
	 */
	private static function hpos() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return false;
		}
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/* ---------------------------------------------------------------------
	 * Versturen
	 * ------------------------------------------------------------------- */

	/**
	 * De nieuwsbrief in de wachtrij zetten.
	 *
	 * De statuswissel gebeurt met één voorwaardelijke UPDATE. Dat is geen
	 * franje: twee keer op de knop drukken is de meest gemaakte fout die er is,
	 * en de tweede klik hoort niet nog eens vierhonderd mails op te leveren.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return int|WP_Error Aantal ontvangers in de wachtrij.
	 */
	public static function verstuur( $id ) {
		global $wpdb;

		$brief = self::get( $id );
		if ( ! $brief ) {
			return new WP_Error( 'wsfm_nb_weg', __( 'Deze nieuwsbrief bestaat niet meer.', 'ws-flow-mailer' ) );
		}
		if ( empty( $brief->blocks ) ) {
			return new WP_Error( 'wsfm_nb_leeg', __( 'Er staat nog niets in deze nieuwsbrief. Voeg eerst een afbeelding, tekst of producten toe.', 'ws-flow-mailer' ) );
		}

		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$ontvangers = self::ontvangers( $brief->audience );
		if ( empty( $ontvangers ) ) {
			return new WP_Error( 'wsfm_nb_niemand', __( 'Er zijn geen klanten gevonden voor deze doelgroep. Probeer "Alle klanten" of controleer of er bestellingen zijn.', 'ws-flow-mailer' ) );
		}

		$table  = self::table();
		$geclaimd = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'bezig' WHERE id = %d AND status = 'concept'", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 1 !== $geclaimd ) {
			return new WP_Error( 'wsfm_nb_bezig', __( 'Deze nieuwsbrief is al verstuurd of wordt op dit moment verstuurd.', 'ws-flow-mailer' ) );
		}

		$aantal = WSFM_Queue::enqueue_newsletter( (int) $id, $ontvangers );

		$wpdb->update(
			$table,
			array(
				'status'     => 'verzonden',
				'recipients' => $aantal,
				'sent_at'    => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);

		return $aantal;
	}

	/**
	 * De opgemaakte nieuwsbrief, klaar om te versturen.
	 *
	 * @param object $brief   Nieuwsbrief.
	 * @param array  $context Merge-gegevens.
	 * @return array { subject, html_body }
	 */
	public static function render( $brief, array $context ) {
		return WSFM_Template_Engine::render_string(
			$brief->subject,
			WSFM_Newsletter_Render::render( $brief ),
			$context
		);
	}

	/**
	 * Hoe ver het versturen is.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return array { wacht, verzonden, mislukt }
	 */
	public static function voortgang( $id ) {
		global $wpdb;

		$queue = WSFM_Queue::table();
		$log   = WSFM_Queue::log_table();

		return array(
			'wacht'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$queue} WHERE newsletter_id = %d AND status IN ('pending','processing')", (int) $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'verzonden' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} l INNER JOIN {$queue} q ON q.id = l.queue_id WHERE q.newsletter_id = %d AND l.status = 'sent'", (int) $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'mislukt'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$log} l INNER JOIN {$queue} q ON q.id = l.queue_id WHERE q.newsletter_id = %d AND l.status IN ('failed','bounced','complained')", (int) $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
