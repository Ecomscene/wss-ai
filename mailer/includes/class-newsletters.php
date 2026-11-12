<?php
/**
 * Nieuwsbrieven: opslaan, ontvangers bepalen, versturen en inplannen.
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
 * INPLANNEN, EN WAAROM ALTIJD NEDERLANDSE TIJD
 * Een winkelier kiest een datum en een tijd, en bedoelt daarmee de klok aan
 * zijn eigen muur. De tijdzone in WordPress staat bij lang niet elke shop goed
 * (UTC, of "UTC+1" zonder zomertijd), en dan zou een brief voor 09:00 om 08:00
 * of 10:00 de deur uitgaan. Daarom rekenen we hier met een VASTE tijdzone,
 * Europe/Amsterdam, en slaan we het moment op in UTC. De instelling van de
 * site doet voor het inplannen dus niet mee.
 *
 * MAATWERK PER WINKEL
 * Drie filters laten een losse snippet eigen doelgroepen toevoegen zonder dat
 * de plugin zelf aangepast hoeft te worden (eerste gebruik: Loop&Label, die
 * particulier en wholesale apart wil mailen):
 * - wsfm_newsletter_doelgroepen ( $doelgroepen )             extra keuzes
 * - wsfm_newsletter_ontvangers  ( $ontvangers, $doelgroep )   wie erin zit
 * - wsfm_newsletter_is_klantgroep ( $is, $doelgroep )          waarschuwing
 * Een eigen doelgroep begint LEEG: de snippet stelt hem zelf samen, bijvoorbeeld
 * uit ontvangers_ruw( 'klanten_alle' ). Staat de snippet uit, dan levert de
 * groep niemand op en weigert verstuur(), in plaats van stil terug te vallen op
 * een grotere groep.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Newsletters {

	/** Bestelstatussen die iemand tot klant maken. Geannuleerd en mislukt niet. */
	const KLANT_STATUSSEN = array( 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' );

	/** Hoeveel ontvangers we hoogstens in één keer in de wachtrij zetten. */
	const MAX_ONTVANGERS = 20000;

	/** De doelgroepen die de plugin zelf kent (naast lijst_<id>). */
	const VASTE_DOELGROEPEN = array( 'inschrijvingen', 'klanten_jaar', 'klanten_alle', 'alles' );

	/** De klok waarmee de winkelier inplant. Vast, los van de site. */
	const TIJDZONE = 'Europe/Amsterdam';

	/** Action Scheduler-haak die op het geplande moment afgaat. */
	const HOOK_GEPLAND = 'wsfm_verstuur_geplande_nieuwsbrieven';

	/** Statussen waarin een brief nog aangepast en verstuurd kan worden. */
	const BEWERKBAAR = array( 'concept', 'gepland' );

	/**
	 * Haken: de formulieren voor inplannen en de wekker zelf.
	 *
	 * Los van WooCommerce geregistreerd, zodat het formulier nooit op een lege
	 * pagina uitkomt. Het versturen zelf heeft Action Scheduler nodig, en dat
	 * komt met WooCommerce mee.
	 */
	public static function init() {
		add_action( 'admin_post_wsfm_plan_newsletter', array( __CLASS__, 'handle_plan' ) );
		add_action( 'admin_post_wsfm_unplan_newsletter', array( __CLASS__, 'handle_unplan' ) );
		add_action( self::HOOK_GEPLAND, array( __CLASS__, 'wekker_af' ) );
	}

	/**
	 * De wekker gaat af: in de wachtrij zetten en meteen beginnen met versturen.
	 *
	 * Zonder dat tweede deel zou de brief op het geplande moment wel klaarstaan,
	 * maar pas bij de volgende wachtrijronde vertrekken, tot vijf minuten later.
	 * Wie 09:00 kiest, verwacht om 09:00 post.
	 */
	public static function wekker_af() {
		self::verstuur_geplande();

		if ( class_exists( 'WSFM_Queue_Processor' ) ) {
			WSFM_Queue_Processor::process();
		}
	}

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
	 * TWEE SOORTEN, EN DAT VERSCHIL IS GEEN DETAIL
	 * Een LIJST bestaat uit mensen die er zelf om gevraagd hebben: via de
	 * popup, of via het vinkje bij het afrekenen. Bij een KLANTGROEP is dat
	 * niet gevraagd; die mag je mailen omdat er een koopovereenkomst ligt en
	 * er een afmeldlink in staat, en dat geldt alleen voor je eigen,
	 * soortgelijke producten.
	 *
	 * De lijsten staan daarom bovenaan en de klantgroepen eronder, met een
	 * waarschuwing in het scherm. Wie snel klikt hoort niet per ongeluk zijn
	 * hele klantenbestand te pakken.
	 *
	 * @return array key => label
	 */
	public static function doelgroepen() {
		$uit = array();

		if ( class_exists( 'WSFM_Lijsten' ) ) {
			foreach ( WSFM_Lijsten::alles() as $lijst ) {
				$uit[ 'lijst_' . (int) $lijst->id ] = sprintf(
					/* translators: 1: naam van de lijst, 2: aantal mensen erop. */
					_n( '%1$s (%2$s persoon)', '%1$s (%2$s personen)', (int) $lijst->aantal, 'ws-flow-mailer' ),
					$lijst->naam,
					number_format_i18n( (int) $lijst->aantal )
				);
			}
		}

		/* Blijft bestaan voor nieuwsbrieven die al op deze doelgroep stonden
		   voordat er lijsten waren. */
		$uit['inschrijvingen'] = __( 'Iedereen die zich ooit heeft aangemeld', 'ws-flow-mailer' );

		$uit['klanten_jaar'] = __( 'Klanten die het afgelopen jaar besteld hebben', 'ws-flow-mailer' );
		$uit['klanten_alle'] = __( 'Alle klanten die ooit besteld hebben', 'ws-flow-mailer' );
		$uit['alles']        = __( 'Klanten en aanmeldingen samen', 'ws-flow-mailer' );

		/**
		 * Extra doelgroepen van een winkel zelf (snippet).
		 *
		 * Omdat save() hiermee controleert, blijft een toegevoegde keuze bij
		 * het opslaan gewoon staan. Sleutels gaan door sanitize_key(), dus
		 * alleen kleine letters, cijfers, - en _.
		 *
		 * @param array $uit key => label
		 */
		$gefilterd = apply_filters( 'wsfm_newsletter_doelgroepen', $uit );

		return is_array( $gefilterd ) ? $gefilterd : $uit;
	}

	/**
	 * Is dit een doelgroep waarvoor niemand om post gevraagd heeft?
	 *
	 * Gebruikt door het scherm om er een waarschuwing bij te zetten. Het
	 * blokkeert niets: mailen naar je eigen klanten mag, maar het is wel een
	 * andere beslissing dan mailen naar een lijst, en die hoort zichtbaar te
	 * zijn op het moment dat je hem neemt.
	 *
	 * @param string $doelgroep Doelgroepsleutel.
	 * @return bool
	 */
	public static function is_klantgroep( $doelgroep ) {
		$is = in_array( $doelgroep, array( 'klanten_jaar', 'klanten_alle', 'alles' ), true );

		/**
		 * Zodat een eigen klantgroep (snippet) dezelfde waarschuwing krijgt.
		 *
		 * @param bool   $is        Klantgroep of niet.
		 * @param string $doelgroep Doelgroepsleutel.
		 */
		return (bool) apply_filters( 'wsfm_newsletter_is_klantgroep', $is, $doelgroep );
	}

	/**
	 * Kan deze brief nog aangepast worden? Een concept en een ingeplande brief
	 * wel; wat al de deur uit is niet.
	 *
	 * @param object $brief Nieuwsbrief.
	 * @return bool
	 */
	public static function bewerkbaar( $brief ) {
		return is_object( $brief ) && isset( $brief->status ) && in_array( $brief->status, self::BEWERKBAAR, true );
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
		   hun inbox hebben, en dan klopt het verzendlog niet meer. Een ingeplande
		   brief mag wel nog: wat er op het moment van versturen staat, gaat eruit. */
		if ( $id > 0 ) {
			$bestaand = self::get( $id );
			if ( $bestaand && ! self::bewerkbaar( $bestaand ) ) {
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

		/* Zelf samengesteld of aangeleverd. Alles wat we niet herkennen valt
		   terug op de samensteller: dat is de stand waarin de rest van het
		   scherm klopt. */
		$soort = isset( $data['soort'] ) && 'eigen' === $data['soort'] ? 'eigen' : 'blokken';
		$eigen = isset( $data['eigen_html'] ) ? WSFM_Eigen_Html::schoon( (string) $data['eigen_html'] ) : '';

		if ( 'eigen' === $soort && '' === trim( $eigen ) ) {
			return new WP_Error( 'wsfm_nb_leeg', __( 'Je hebt gekozen voor een eigen HTML-nieuwsbrief, maar er staat nog niets in. Plak je HTML of kies een bestand.', 'ws-flow-mailer' ) );
		}

		$doelgroepen = self::doelgroepen();
		$doelgroep   = isset( $data['audience'] ) ? sanitize_key( $data['audience'] ) : '';
		if ( ! isset( $doelgroepen[ $doelgroep ] ) ) {
			/* Terugvallen op de hoofdlijst en NIET op een klantgroep. Een lijst die
			   is weggegooid zou anders bij het volgende opslaan stilletjes veranderen
			   in "alle klanten van het afgelopen jaar", en dat merk je pas nadat de
			   post weg is. Bij twijfel de kleinste groep, niet de grootste. */
			$hoofd     = class_exists( 'WSFM_Lijsten' ) ? WSFM_Lijsten::hoofdlijst() : 0;
			$doelgroep = $hoofd ? 'lijst_' . $hoofd : 'inschrijvingen';
		}

		$row = array(
			'name'       => $naam,
			'subject'    => $onderwerp,
			'template'   => $sjabloon,
			'audience'   => $doelgroep,
			'soort'      => $soort,
			'eigen_html' => $eigen,
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
	 * Een ingeplande brief mag weg: de wekker vindt daarna gewoon niets meer.
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

		if ( ! self::bewerkbaar( $brief ) ) {
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
				'name'       => sprintf( __( 'Kopie van %s', 'ws-flow-mailer' ), $brief->name ),
				'subject'    => $brief->subject,
				'template'   => $brief->template,
				'audience'   => $brief->audience,
				'soort'      => isset( $brief->soort ) ? $brief->soort : 'blokken',
				'eigen_html' => isset( $brief->eigen_html ) ? $brief->eigen_html : '',
				'blocks'     => $brief->blocks,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Ontvangers
	 * ------------------------------------------------------------------- */

	/**
	 * De ontvangers voor een doelgroep: adres plus voornaam, ontdubbeld.
	 *
	 * Het getal in het scherm en de echte verzending lopen allebei hierlangs,
	 * dus wat een snippet via het filter verandert klopt op beide plekken.
	 *
	 * @param string $doelgroep Doelgroepsleutel.
	 * @return array e-mail => voornaam
	 */
	public static function ontvangers( $doelgroep ) {
		$doelgroep = (string) $doelgroep;
		$eigen     = 0 !== strpos( $doelgroep, 'lijst_' ) && ! in_array( $doelgroep, self::VASTE_DOELGROEPEN, true );

		/* Een eigen doelgroep begint leeg. Zonder snippet die hem vult gaat er
		   dus niemand iets heen, en dat is de bedoeling: stil terugvallen op
		   "alle klanten van het afgelopen jaar" zou de verkeerde mensen mailen. */
		$uit = $eigen ? array() : self::ontvangers_ruw( $doelgroep );

		/**
		 * Ontvangers aanpassen, of voor een eigen doelgroep samenstellen.
		 *
		 * @param array  $uit       e-mail => voornaam, suppressielijst al verwerkt.
		 *                          Leeg bij een eigen doelgroep.
		 * @param string $doelgroep Doelgroepsleutel.
		 */
		$gefilterd = apply_filters( 'wsfm_newsletter_ontvangers', $uit, $doelgroep );
		if ( ! is_array( $gefilterd ) ) {
			return $uit;
		}

		/* Een filter mag nooit iemand van de suppressielijst terugzetten. */
		foreach ( array_keys( $gefilterd ) as $email ) {
			if ( ! is_email( $email ) || WSFM_Suppression::is_suppressed( $email ) ) {
				unset( $gefilterd[ $email ] );
			}
		}

		return array_slice( $gefilterd, 0, self::MAX_ONTVANGERS, true );
	}

	/**
	 * De ontvangers zoals de plugin ze zelf bepaalt, zonder filter.
	 *
	 * Adressen op de suppressielijst vallen hier al af, en niet pas bij het
	 * versturen. Anders belooft het scherm "gaat naar 412 klanten" terwijl er
	 * 380 vertrekken, en dan klopt het getal dat iemand op knopdruk ziet niet.
	 *
	 * Een snippet die een eigen doelgroep maakt kan hier de basis ophalen,
	 * bijvoorbeeld ontvangers_ruw( 'klanten_alle' ), en die dan uitdunnen.
	 *
	 * @param string $doelgroep Doelgroepsleutel.
	 * @return array e-mail => voornaam
	 */
	public static function ontvangers_ruw( $doelgroep ) {
		global $wpdb;

		/* Een eigen lijst: dat zijn precies de mensen die erop staan, en verder
		   niemand. Geen bestellingen erbij, geen slimmigheden. */
		if ( 0 === strpos( (string) $doelgroep, 'lijst_' ) ) {
			if ( ! class_exists( 'WSFM_Lijsten' ) ) {
				return array();
			}
			return WSFM_Lijsten::adressen( (int) substr( $doelgroep, 6 ), self::MAX_ONTVANGERS );
		}

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
	 * @param int  $id             Nieuwsbrief-id.
	 * @param bool $alleen_gepland Vanuit de wekker: alleen versturen als hij
	 *                             nog steeds ingepland staat en het moment er is.
	 *                             Zo gaat een brief die net is teruggezet naar
	 *                             concept er niet alsnog uit.
	 * @return int|WP_Error Aantal ontvangers in de wachtrij.
	 */
	public static function verstuur( $id, $alleen_gepland = false ) {
		global $wpdb;

		$brief = self::get( $id );
		if ( ! $brief ) {
			return new WP_Error( 'wsfm_nb_weg', __( 'Deze nieuwsbrief bestaat niet meer.', 'ws-flow-mailer' ) );
		}
		if ( self::leeg( $brief ) ) {
			return new WP_Error(
				'wsfm_nb_leeg',
				self::is_eigen( $brief )
					? __( 'Er staat nog geen HTML in deze nieuwsbrief. Plak je eigen ontwerp of kies een bestand.', 'ws-flow-mailer' )
					: __( 'Er staat nog niets in deze nieuwsbrief. Voeg eerst een afbeelding, tekst of producten toe.', 'ws-flow-mailer' )
			);
		}

		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$ontvangers = self::ontvangers( $brief->audience );
		if ( empty( $ontvangers ) ) {
			return new WP_Error( 'wsfm_nb_niemand', __( 'Er zijn geen klanten gevonden voor deze doelgroep. Probeer "Alle klanten" of controleer of er bestellingen zijn.', 'ws-flow-mailer' ) );
		}

		/**
		 * Mag deze verzending doorgaan?
		 *
		 * Standaard ja, en in WS Flow Mailer en in WSS Tools luistert hier
		 * niemand naar. In de losse betaalde plugin hangt hier het tegoed aan:
		 * die reserveert de mails bij Webshopschool en geeft een WP_Error terug
		 * als er te weinig staat.
		 *
		 * DE PLEK IS HET HELE PUNT. Hier is het aantal ontvangers bekend en
		 * staat er nog niets in de wachtrij. Een regel eerder weet je niet
		 * hoeveel het er zijn; een regel later is de nieuwsbrief al geclaimd en
		 * blijft hij op 'bezig' staan terwijl er geen enkele mail is verstuurd,
		 * en dan kan de winkelier hem ook niet opnieuw versturen.
		 *
		 * @param bool|WP_Error $mag     Of het mag.
		 * @param array         $context { soort, aantal, ref }.
		 */
		$mag = apply_filters(
			'wsfm_mag_versturen',
			true,
			array(
				'soort'  => 'nieuwsbrief',
				'aantal' => count( $ontvangers ),
				/* Een kenmerk dat bij twee keer klikken hetzelfde blijft, zodat
				   de tegenpartij kan zien dat hij dit al heeft afgeboekt. */
				'ref'    => 'nieuwsbrief-' . (int) $id,
			)
		);
		if ( is_wp_error( $mag ) ) {
			return $mag;
		}

		$table = self::table();

		if ( $alleen_gepland ) {
			$geclaimd = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'bezig' WHERE id = %d AND status = 'gepland' AND gepland_op IS NOT NULL AND gepland_op <= %s", (int) $id, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$geclaimd = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'bezig' WHERE id = %d AND status IN ('concept','gepland')", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( 1 !== $geclaimd ) {
			return new WP_Error( 'wsfm_nb_bezig', __( 'Deze nieuwsbrief is al verstuurd of wordt op dit moment verstuurd.', 'ws-flow-mailer' ) );
		}

		$aantal = WSFM_Queue::enqueue_newsletter( (int) $id, $ontvangers );

		$wpdb->update(
			$table,
			array(
				'status'     => 'verzonden',
				'recipients' => $aantal,
				'plan_fout'  => '',
				'sent_at'    => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);

		return $aantal;
	}

	/* ---------------------------------------------------------------------
	 * Inplannen
	 * ------------------------------------------------------------------- */

	/**
	 * De vaste Nederlandse klok.
	 *
	 * @return DateTimeZone
	 */
	public static function tijdzone() {
		return new DateTimeZone( self::TIJDZONE );
	}

	/**
	 * Een datum en tijd zoals de winkelier ze invult, als tijdstempel.
	 *
	 * Gelezen in Europe/Amsterdam, wat de site ook vindt. Zomer- en wintertijd
	 * regelt PHP zelf.
	 *
	 * @param string $datum JJJJ-MM-DD.
	 * @param string $tijd  UU:MM.
	 * @return int|WP_Error Unix-tijdstempel.
	 */
	public static function nl_naar_tijdstempel( $datum, $tijd ) {
		$datum = trim( (string) $datum );
		$tijd  = trim( (string) $tijd );

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $datum ) || ! preg_match( '/^\d{1,2}:\d{2}$/', $tijd ) ) {
			return new WP_Error( 'wsfm_nb_moment', __( 'Kies een datum en een tijd.', 'ws-flow-mailer' ) );
		}

		$moment = DateTime::createFromFormat( '!Y-m-d H:i', $datum . ' ' . $tijd, self::tijdzone() );
		$fouten = DateTime::getLastErrors();

		if ( ! $moment || ( is_array( $fouten ) && ( $fouten['warning_count'] > 0 || $fouten['error_count'] > 0 ) ) ) {
			return new WP_Error( 'wsfm_nb_moment', __( 'Die datum of tijd bestaat niet. Kies een ander moment.', 'ws-flow-mailer' ) );
		}

		return $moment->getTimestamp();
	}

	/**
	 * Een moment uit de database (UTC) als Nederlandse tijd, om te tonen.
	 *
	 * @param string $utc    JJJJ-MM-DD UU:MM:SS in UTC.
	 * @param string $format Datumformaat.
	 * @return string Leeg als er geen moment is.
	 */
	public static function nl_tijd( $utc, $format = 'j F Y \o\m H:i' ) {
		if ( empty( $utc ) || '0000-00-00 00:00:00' === $utc ) {
			return '';
		}

		$ts = strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return '';
		}

		if ( function_exists( 'wp_date' ) ) {
			return (string) wp_date( $format, $ts, self::tijdzone() );
		}

		$moment = new DateTime( '@' . $ts );
		$moment->setTimezone( self::tijdzone() );
		return $moment->format( $format );
	}

	/**
	 * Een nieuwsbrief inplannen, of het moment van een ingeplande verzetten.
	 *
	 * Wat later toch mis zou gaan (geen inhoud, niemand in de doelgroep, geen
	 * verzendkoppeling) wordt NU gecontroleerd. Een fout die pas om negen uur
	 * 's ochtends opduikt terwijl niemand kijkt, is een nieuwsbrief die niet
	 * kwam zonder dat iemand het wist.
	 *
	 * @param int    $id    Nieuwsbrief-id.
	 * @param string $datum JJJJ-MM-DD, Nederlandse tijd.
	 * @param string $tijd  UU:MM, Nederlandse tijd.
	 * @return int|WP_Error Tijdstempel van het geplande moment.
	 */
	public static function plan( $id, $datum, $tijd ) {
		global $wpdb;

		$brief = self::get( $id );
		if ( ! $brief ) {
			return new WP_Error( 'wsfm_nb_weg', __( 'Deze nieuwsbrief bestaat niet meer.', 'ws-flow-mailer' ) );
		}
		if ( ! self::bewerkbaar( $brief ) ) {
			return new WP_Error( 'wsfm_nb_verzonden', __( 'Deze nieuwsbrief is al verstuurd en kan niet meer ingepland worden.', 'ws-flow-mailer' ) );
		}
		if ( self::leeg( $brief ) ) {
			return new WP_Error( 'wsfm_nb_leeg', __( 'Er staat nog niets in deze nieuwsbrief. Vul hem eerst en sla hem op.', 'ws-flow-mailer' ) );
		}

		$ts = self::nl_naar_tijdstempel( $datum, $tijd );
		if ( is_wp_error( $ts ) ) {
			return $ts;
		}

		if ( $ts < time() + 2 * MINUTE_IN_SECONDS ) {
			return new WP_Error( 'wsfm_nb_moment', __( 'Dat moment is al voorbij of te dichtbij. Kies een later moment, of verstuur hem meteen met de knop hierboven.', 'ws-flow-mailer' ) );
		}
		if ( $ts > time() + YEAR_IN_SECONDS ) {
			return new WP_Error( 'wsfm_nb_moment', __( 'Je kunt hooguit een jaar vooruit inplannen.', 'ws-flow-mailer' ) );
		}

		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		if ( empty( self::ontvangers( $brief->audience ) ) ) {
			return new WP_Error( 'wsfm_nb_niemand', __( 'Er zit nu niemand in deze doelgroep. Kies een andere groep voordat je hem inplant.', 'ws-flow-mailer' ) );
		}

		$table = self::table();
		$gezet = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'gepland', gepland_op = %s, plan_fout = '' WHERE id = %d AND status IN ('concept','gepland')", gmdate( 'Y-m-d H:i:s', $ts ), (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$na = self::get( $id );
		if ( false === $gezet || ! $na || 'gepland' !== $na->status ) {
			return new WP_Error( 'wsfm_nb_bezig', __( 'Inplannen lukte niet; misschien is hij net verstuurd. Ververs de pagina en kijk het na.', 'ws-flow-mailer' ) );
		}

		self::wekker( $ts );

		return $ts;
	}

	/**
	 * Een ingeplande brief terug naar concept.
	 *
	 * @param int $id Nieuwsbrief-id.
	 * @return true|WP_Error
	 */
	public static function plan_intrekken( $id ) {
		global $wpdb;

		$table = self::table();
		$terug = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'concept', gepland_op = NULL, plan_fout = '' WHERE id = %d AND status = 'gepland'", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( 1 !== $terug ) {
			return new WP_Error( 'wsfm_nb_bezig', __( 'Deze nieuwsbrief stond niet (meer) ingepland. Misschien is hij net verstuurd.', 'ws-flow-mailer' ) );
		}

		return true;
	}

	/**
	 * Een losse wekker op het geplande moment.
	 *
	 * Action Scheduler rekent in tijdstempels en dus los van de tijdzone van de
	 * site. Gaat de wekker om wat voor reden ook niet af, dan pakt de gewone
	 * wachtrijronde (elke vijf minuten) hem op; zie verstuur_geplande().
	 *
	 * @param int $ts Tijdstempel.
	 */
	private static function wekker( $ts ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		$groep = class_exists( 'WSFM_Flow_Engine' ) ? WSFM_Flow_Engine::AS_GROUP : 'wsfm';
		as_schedule_single_action( (int) $ts, self::HOOK_GEPLAND, array(), $groep );
	}

	/**
	 * Alles versturen waarvan het geplande moment er is.
	 *
	 * Aangeroepen door de wekker en door elke wachtrijronde. Twee tegelijk is
	 * geen probleem: verstuur() claimt met een voorwaardelijke UPDATE, dus de
	 * tweede vindt niets meer.
	 *
	 * Lukt het niet, dan gaat de brief terug naar concept met de reden erbij.
	 * Op 'gepland' laten staan zou betekenen dat hij elke vijf minuten opnieuw
	 * probeert, en dat hij om drie uur 's middags alsnog vertrekt terwijl de
	 * actie om negen uur begon.
	 */
	public static function verstuur_geplande() {
		global $wpdb;

		static $bezig = false;
		if ( $bezig ) {
			return;
		}
		$bezig = true;

		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE status = 'gepland' AND gepland_op IS NOT NULL AND gepland_op <= %s ORDER BY gepland_op ASC LIMIT 5", gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $ids as $id ) {
			$id    = (int) $id;
			$brief = self::get( $id );
			$uit   = self::verstuur( $id, true );

			if ( ! is_wp_error( $uit ) || 'wsfm_nb_bezig' === $uit->get_error_code() ) {
				continue;
			}

			$reden = sprintf(
				/* translators: 1: geplande moment, 2: reden. */
				__( 'De geplande verzending van %1$s ging niet door: %2$s', 'ws-flow-mailer' ),
				$brief ? self::nl_tijd( $brief->gepland_op ) : '',
				$uit->get_error_message()
			);

			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'concept', plan_fout = %s WHERE id = %d AND status = 'gepland'", substr( $reden, 0, 250 ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$bezig = false;
	}

	/**
	 * Formulier: inplannen.
	 */
	public static function handle_plan() {
		if ( ! current_user_can( WSFM_Flow_Admin_UI::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'ws-flow-mailer' ) );
		}
		check_admin_referer( 'wsfm_plan_newsletter' );

		$id    = isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0;
		$datum = isset( $_POST['plan_datum'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_datum'] ) ) : '';
		$tijd  = isset( $_POST['plan_tijd'] ) ? sanitize_text_field( wp_unslash( $_POST['plan_tijd'] ) ) : '';

		$uit = $id ? self::plan( $id, $datum, $tijd ) : new WP_Error( 'wsfm_nb_weg', __( 'Onbekende nieuwsbrief.', 'ws-flow-mailer' ) );

		self::terug(
			$id,
			is_wp_error( $uit )
				? array( 'wsfm-error' => rawurlencode( $uit->get_error_message() ) )
				: array( 'wsfm-gepland' => '1' )
		);
	}

	/**
	 * Formulier: inplannen intrekken.
	 */
	public static function handle_unplan() {
		if ( ! current_user_can( WSFM_Flow_Admin_UI::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'ws-flow-mailer' ) );
		}
		check_admin_referer( 'wsfm_unplan_newsletter' );

		$id  = isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0;
		$uit = $id ? self::plan_intrekken( $id ) : new WP_Error( 'wsfm_nb_weg', __( 'Onbekende nieuwsbrief.', 'ws-flow-mailer' ) );

		self::terug(
			$id,
			is_wp_error( $uit )
				? array( 'wsfm-error' => rawurlencode( $uit->get_error_message() ) )
				: array( 'wsfm-ingetrokken' => '1' )
		);
	}

	/**
	 * Terug naar de brief, met een boodschap.
	 *
	 * @param int   $id   Nieuwsbrief-id.
	 * @param array $args Extra queryargumenten.
	 */
	private static function terug( $id, array $args ) {
		wp_safe_redirect(
			add_query_arg(
				array_merge(
					array(
						'page'        => WSFM_Flow_Admin_UI::SLUG_BRIEVEN,
						'action'      => 'edit',
						'nieuwsbrief' => (int) $id,
					),
					$args
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * De opgemaakte nieuwsbrief, klaar om te versturen.
	 *
	 * @param object $brief   Nieuwsbrief.
	 * @param array  $context Merge-gegevens.
	 * @return array { subject, html_body }
	 */
	public static function render( $brief, array $context ) {
		/* Een aangeleverde mail is al een compleet document. Daar de kop en de
		   voet van de samensteller omheen zetten zou een tweede <html> in het
		   bestand opleveren en de opmaak van de klant overschrijven. */
		$body = self::is_eigen( $brief )
			? WSFM_Eigen_Html::body( $brief )
			: WSFM_Newsletter_Render::render( $brief );

		return WSFM_Template_Engine::render_string( $brief->subject, $body, $context );
	}

	/**
	 * Levert de klant deze nieuwsbrief zelf aan?
	 *
	 * @param object $brief Nieuwsbrief.
	 * @return bool
	 */
	public static function is_eigen( $brief ) {
		return is_object( $brief ) && isset( $brief->soort ) && 'eigen' === $brief->soort;
	}

	/**
	 * Staat er nog niets in deze nieuwsbrief?
	 *
	 * Apart van verstuur() zodat het na te tellen is zonder database. Het is
	 * ook precies het stuk dat stil verkeerd stond: op blokken kijken bij een
	 * aangeleverde mail betekent dat iemand vijftien kilobyte HTML kan plakken,
	 * hem in het voorbeeld ziet staan, en bij versturen te horen krijgt dat er
	 * niets in staat.
	 *
	 * @param object $brief Nieuwsbrief.
	 * @return bool
	 */
	public static function leeg( $brief ) {
		if ( self::is_eigen( $brief ) ) {
			return '' === trim( isset( $brief->eigen_html ) ? (string) $brief->eigen_html : '' );
		}

		return empty( $brief->blocks );
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
