<?php
/**
 * Shopify-koppeling voor een nieuwsbrief-WordPress op een subdomein.
 *
 * WAAROM DIT BESTAAT
 * Sommige klanten verkopen op Shopify, maar mailen via onze eigen mailer. Die
 * draait dan op een kale WordPress op een subdomein (nieuwsbrief.winkel.nl).
 * Een bezoeker van de Shopify-winkel moet zich toch kunnen inschrijven, en
 * mensen die marketingtoestemming gaven in Shopify moeten vanzelf op de lijst
 * komen. Dit bestand doet beide, zonder dat de klant iets met WordPress hoeft.
 *
 * DRIE DINGEN
 * 1. embed.js: een enkel scriptje dat de popup van deze mailer (zelfde opmaak en
 *    instellingen als de popup-pagina) op de Shopify-winkel zet. In het Shopify-
 *    thema staat alleen een regel <script src=".../wsfm/v1/embed.js" defer>.
 *    Verander je de popup hier, dan verandert hij daar mee.
 * 2. CORS: alleen de winkel zelf mag de inschrijfroute vanuit een browser
 *    aanroepen. WordPress laat standaard elke herkomst toe.
 * 3. Webhook: Shopify meldt "klant aangemaakt/bijgewerkt" en "bestelling
 *    geplaatst". Wie marketingtoestemming heeft komt op de lijst, met de
 *    toestemming erbij vastgelegd. Handtekening (HMAC) wordt gecontroleerd.
 *
 * AAN/UIT
 * Alles staat uit zolang de optie wsfm_shopify geen herkomsten bevat. Op een
 * gewone WooCommerce-shop verandert er dus niets.
 *
 * OPTIE wsfm_shopify
 *   origins  array  toegestane herkomsten, bv. https://mysweetlifestyle.nl
 *   secret   string de webhook-sleutel van Shopify (Admin > Meldingen > Webhooks)
 *   lijst_id int    op welke lijst Shopify-klanten komen (0 = hoofdlijst)
 *   shop_url string de Shopify-winkel zelf (voor {shop_url} en {cart_url}); leeg = eerste herkomst
 *   flow_toestemming string marketing (standaard: flows alleen voor wie marketingtoestemming gaf
 *                    of al op de lijst staat) of alle (ook zonder; dan is de winkelier verantwoordelijk)
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Shopify {

	const OPTIE = 'wsfm_shopify';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'cors' ), 99, 4 );
	}

	/**
	 * @return array { origins: string[], secret: string, lijst_id: int }
	 */
	public static function instellingen() {
		$o = get_option( self::OPTIE, array() );
		$o = is_array( $o ) ? $o : array();

		$origins = array();
		foreach ( (array) ( isset( $o['origins'] ) ? $o['origins'] : array() ) as $herkomst ) {
			$herkomst = untrailingslashit( esc_url_raw( trim( (string) $herkomst ) ) );
			if ( '' !== $herkomst ) {
				$origins[] = $herkomst;
			}
		}

		return array(
			'origins'  => $origins,
			'secret'   => isset( $o['secret'] ) ? (string) $o['secret'] : '',
			'lijst_id' => isset( $o['lijst_id'] ) ? (int) $o['lijst_id'] : 0,
	'shop_url' => isset( $o['shop_url'] ) ? untrailingslashit( esc_url_raw( trim( (string) $o['shop_url'] ) ) ) : '',
	'flow_toestemming' => ( isset( $o['flow_toestemming'] ) && 'alle' === $o['flow_toestemming'] ) ? 'alle' : 'marketing',
		);
	}

	/**
	 * De Shopify-winkel zelf, met slash erachter, of leeg als we hem niet weten.
	 *
	 * @return string
	 */
	public static function winkel_url() {
		$i = self::instellingen();
		if ( '' !== $i['shop_url'] ) {
			return trailingslashit( $i['shop_url'] );
		}
		return ! empty( $i['origins'] ) ? trailingslashit( $i['origins'][0] ) : '';
	}

	public static function aan() {
		$i = self::instellingen();
		return ! empty( $i['origins'] );
	}

	public static function routes() {
		register_rest_route(
			WSFM_Popup::REST_NAMESPACE,
			'/embed.js',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'embed' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			WSFM_Popup::REST_NAMESPACE,
			'/shopify/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true', // Eigen controle: HMAC.
			)
		);
	}

	/* ------------------------------------------------------------------ CORS */

	/**
	 * Maak van "elke herkomst" (WordPress-standaard) "alleen de winkel".
	 * Draait na de standaardkoppen van WordPress (prioriteit 99).
	 */
	public static function cors( $geserveerd, $resultaat, $verzoek, $server ) {
		if ( ! self::aan() || 0 !== strpos( (string) $verzoek->get_route(), '/' . WSFM_Popup::REST_NAMESPACE . '/' ) ) {
			return $geserveerd;
		}

		$herkomst = isset( $_SERVER['HTTP_ORIGIN'] ) ? untrailingslashit( esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $herkomst ) {
			return $geserveerd;
		}

		$eigen = untrailingslashit( home_url() );
		$i     = self::instellingen();

		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );

		/* Ook www. en zonder www. van dezelfde winkel. */
		$toegestaan = $herkomst === $eigen || in_array( $herkomst, $i['origins'], true );
		if ( $toegestaan ) {
			header( 'Access-Control-Allow-Origin: ' . $herkomst );
			header( 'Vary: Origin', false );
		}

		return $geserveerd;
	}

	/* --------------------------------------------------------------- embed.js */

	/**
	 * Het scriptje voor in het Shopify-thema.
	 *
	 * Geeft de hele popup (opmaak, stijl en gedrag) in een bestand terug, zodat
	 * het thema niets van WordPress hoeft te weten. Staat de popup uit, dan is
	 * het bestand leeg.
	 */
	public static function embed() {
		$js = '';

		if ( self::aan() && WSFM_Popup::aan() ) {
			$i    = WSFM_Popup::instellingen();
			$beeld = $i['afbeelding'] ? wp_get_attachment_image_url( (int) $i['afbeelding'], 'large' ) : '';

			$html = WSFM_Popup::html( $i, $beeld );
			$css  = (string) @file_get_contents( WSFM_PLUGIN_DIR . 'assets/popup.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$kern = (string) @file_get_contents( WSFM_PLUGIN_DIR . 'assets/popup.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			$config = array(
				'url'           => rest_url( WSFM_Popup::REST_NAMESPACE . '/inschrijven' ),
				'nonce'         => '',
				'seconden'      => (int) $i['na_seconden'],
				'dagen'         => (int) $i['dagen_verbergen'],
				'koekje'        => WSFM_Popup::KOEKJE,
				'erfLettertype' => 'website' === WSFM_Popup::lettertype_of( $i['lettertype'] ),
				'bezig'         => __( 'Momentje...', 'ws-flow-mailer' ),
				'fout'          => __( 'Er ging iets mis. Probeer het zo nog eens.', 'ws-flow-mailer' ),
			);

			if ( '' !== $kern && '' !== $css ) {
				/* Niet op winkelwagen en afrekenen (Shopify-paden), net als bij WooCommerce. */
				$js = "(function(){\n"
					. "if(/^\\/(cart|checkouts?)(\\/|\$)/.test(location.pathname)||document.querySelector('.wsfm-popup-laag')){return;}\n"
					. "var s=document.createElement('style');s.textContent=" . wp_json_encode( $css ) . ";document.head.appendChild(s);\n"
					. "var d=document.createElement('div');d.innerHTML=" . wp_json_encode( $html ) . ";document.body.appendChild(d);\n"
					. 'window.wsfmPopup=' . wp_json_encode( $config ) . ";\n"
					. $kern . "\n})();\n";
			}
		}

		/* Zelf uitserveren als JavaScript: de REST-server zou er anders JSON van maken. */
		add_filter(
			'rest_pre_serve_request',
			function ( $geserveerd ) use ( $js ) {
				header( 'Content-Type: application/javascript; charset=utf-8' );
				header( 'Cache-Control: public, max-age=300' );
				header( 'X-Content-Type-Options: nosniff' );
				echo $js; // phpcs:ignore WordPress.Security.EscapingOutput -- eigen opgebouwd, JSON-gecodeerd.
				return true;
			},
			1
		);

		return new WP_REST_Response( null, 200 );
	}

	/* ---------------------------------------------------------------- webhook */

	/**
	 * Shopify meldt een klant, een bestelling of een checkout.
	 *
	 * Topics: customers/*, orders/create|paid|fulfilled, checkouts/create|update.
	 * Alles gaat door dezelfde HMAC-controle.
	 *
	 * @param WP_REST_Request $verzoek Verzoek.
	 * @return WP_REST_Response
	 */
	public static function webhook( $verzoek ) {
		$i = self::instellingen();

		if ( '' === $i['secret'] ) {
			return new WP_REST_Response( array( 'ok' => false ), 503 );
		}

		$ruw       = $verzoek->get_body();
		$ontvangen = (string) $verzoek->get_header( 'x_shopify_hmac_sha256' );
		$verwacht  = base64_encode( hash_hmac( 'sha256', $ruw, $i['secret'], true ) );

		if ( '' === $ontvangen || ! hash_equals( $verwacht, $ontvangen ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 401 );
		}

		$onderwerp = (string) $verzoek->get_header( 'x_shopify_topic' );
		$data      = json_decode( $ruw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		if ( in_array( $onderwerp, array( 'checkouts/create', 'checkouts/update' ), true ) ) {
			return new WP_REST_Response( self::verwerk_checkout( $data, $i ) );
		}

		if ( in_array( $onderwerp, array( 'orders/create', 'orders/paid', 'orders/fulfilled' ), true ) ) {
			return new WP_REST_Response( self::verwerk_order( $data, $onderwerp, $i ) );
		}

		$uit = self::verwerk_klant( $data, $onderwerp, $i );
		return new WP_REST_Response( array( 'ok' => true, 'actie' => $uit['actie'] ) );
	}

	/**
	 * Een klant op de lijst zetten (met toestemming) of afmelden.
	 *
	 * @param array  $klant     Klantgegevens.
	 * @param string $onderwerp Shopify-topic.
	 * @param array  $i         Instellingen.
	 * @return array { actie: string, ja: bool, email: string }
	 */
	private static function verwerk_klant( array $klant, $onderwerp, array $i ) {
		$email = isset( $klant['email'] ) ? sanitize_email( (string) $klant['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return array( 'actie' => 'geen adres', 'ja' => false, 'email' => '' );
		}

		/* Alleen met toestemming. Nieuwe API: email_marketing_consent.state; oude: accepts_marketing. */
		$toestemming = isset( $klant['email_marketing_consent'] ) && is_array( $klant['email_marketing_consent'] ) ? $klant['email_marketing_consent'] : array();
		$ja          = ( isset( $toestemming['state'] ) && 'subscribed' === $toestemming['state'] )
			|| ( ! isset( $toestemming['state'] ) && ! empty( $klant['accepts_marketing'] ) );

		if ( ! $ja ) {
			/* Uitschrijven in Shopify (not_subscribed/unsubscribed) respecteren we ook. */
			if ( isset( $toestemming['state'] ) && in_array( $toestemming['state'], array( 'unsubscribed' ), true ) ) {
				WSFM_Suppression::add( $email, 'manual' );
				return array( 'actie' => 'afgemeld', 'ja' => false, 'email' => $email );
			}
			return array( 'actie' => 'geen toestemming', 'ja' => false, 'email' => $email );
		}

		if ( WSFM_Suppression::is_suppressed( $email ) ) {
			return array( 'actie' => 'stond op afmeldlijst', 'ja' => false, 'email' => $email );
		}

		$tekst = 'Shopify marketingtoestemming (' . ( isset( $toestemming['consent_updated_at'] ) ? (string) $toestemming['consent_updated_at'] : 'datum onbekend' ) . ', ' . $onderwerp . ')';

		$uit = WSFM_Subscribers::add(
			$email,
			'shopify',
			'',
			isset( $klant['first_name'] ) ? (string) $klant['first_name'] : '',
			$i['lijst_id'],
			$tekst,
			isset( $klant['last_name'] ) ? (string) $klant['last_name'] : ''
		);

		return array( 'actie' => $uit['nieuw'] ? 'toegevoegd' : 'bestond', 'ja' => true, 'email' => $email );
	}

	/**
	 * Een bedrag zoals de mails het tonen: "€ 49,95".
	 *
	 * @param mixed  $bedrag Bedrag.
	 * @param string $valuta ISO-code.
	 * @return string
	 */
	public static function geld( $bedrag, $valuta = 'EUR' ) {
		$valuta  = strtoupper( (string) $valuta );
		$symbool = array( 'EUR' => '€', 'USD' => '$', 'GBP' => '£' );
		$voor    = isset( $symbool[ $valuta ] ) ? $symbool[ $valuta ] : ( '' === $valuta ? '€' : $valuta );
		return $voor . ' ' . number_format( (float) $bedrag, 2, ',', '.' );
	}

	/**
	 * Staat dit adres op onze lijst (en dus: heeft het toestemming gegeven)?
	 *
	 * @param string $email Adres.
	 * @return bool
	 */
	private static function staat_op_lijst( $email ) {
		return (bool) WSFM_Subscribers::get( $email );
	}

	/**
	 * Mag dit adres de flows in? Afmeldlijst wint altijd; verder hangt het af van
	 * de instelling flow_toestemming.
	 *
	 * @param string $email   Adres.
	 * @param bool   $gegeven Gaf de klant in Shopify marketingtoestemming?
	 * @param array  $i       Instellingen.
	 * @return string Leeg als het mag, anders de reden.
	 */
	private static function flow_weigering( $email, $gegeven, array $i ) {
		if ( WSFM_Suppression::is_suppressed( $email ) ) {
			return 'stond op afmeldlijst';
		}
		if ( 'alle' !== $i['flow_toestemming'] && ! $gegeven && ! self::staat_op_lijst( $email ) ) {
			return 'geen toestemming voor flows';
		}
		return '';
	}

	/**
	 * Regels uit een checkout of bestelling omzetten naar mailregels.
	 *
	 * @param array  $regels Shopify line_items.
	 * @param string $valuta ISO-code.
	 * @return array
	 */
	private static function regels( $regels, $valuta ) {
		$uit = array();
		foreach ( (array) $regels as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$naam = isset( $r['title'] ) ? (string) $r['title'] : ( isset( $r['name'] ) ? (string) $r['name'] : '' );
			if ( ! empty( $r['variant_title'] ) ) {
				$naam .= ' - ' . $r['variant_title'];
			}
			$aantal = isset( $r['quantity'] ) ? max( 1, (int) $r['quantity'] ) : 1;
			$regel  = isset( $r['line_price'] ) ? (float) $r['line_price'] : ( isset( $r['price'] ) ? (float) $r['price'] * $aantal : 0 );

			$item = array(
				'name'  => sanitize_text_field( $naam ),
				'qty'   => $aantal,
				'price' => self::geld( $regel, $valuta ),
			);
			$foto = isset( $r['image_url'] ) ? (string) $r['image_url'] : ( isset( $r['image'] ) && is_string( $r['image'] ) ? $r['image'] : '' );
			if ( '' !== $foto ) {
				$item['image'] = esc_url_raw( $foto );
			}
			$uit[] = $item;
		}
		return $uit;
	}

	/**
	 * Voornaam uit klant of adressen.
	 *
	 * @param array $data Checkout of bestelling.
	 * @return string
	 */
	private static function voornaam( array $data ) {
		foreach ( array( 'customer', 'billing_address', 'shipping_address' ) as $sleutel ) {
			if ( isset( $data[ $sleutel ] ) && is_array( $data[ $sleutel ] ) && ! empty( $data[ $sleutel ]['first_name'] ) ) {
				return sanitize_text_field( (string) $data[ $sleutel ]['first_name'] );
			}
		}
		return '';
	}

	/**
	 * checkouts/create en checkouts/update: de verlaten-winkelwagenflow.
	 *
	 * Een checkout met een e-mailadres wordt bewaard zoals een WooCommerce-wagen:
	 * een rij per adres, de laatste wagen wint. De gewone controle (elke 15
	 * minuten) zet er daarna flows voor in de wachtrij. {cart_url} wordt de
	 * abandoned_checkout_url van Shopify zelf.
	 *
	 * @param array $data Checkout.
	 * @param array $i    Instellingen.
	 * @return array
	 */
	private static function verwerk_checkout( array $data, array $i ) {
		$klant = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$email = sanitize_email( (string) ( ! empty( $data['email'] ) ? $data['email'] : ( isset( $klant['email'] ) ? $klant['email'] : '' ) ) );

		if ( ! is_email( $email ) ) {
			return array( 'ok' => true, 'actie' => 'geen adres' );
		}

		/* Afgerekend: dit is geen verlaten wagen meer. */
		if ( ! empty( $data['completed_at'] ) ) {
			WSFM_Cart_Tracking::mark_extern_order( $email );
			return array( 'ok' => true, 'actie' => 'afgerekend' );
		}

		$toestemming = isset( $klant['email_marketing_consent'] ) && is_array( $klant['email_marketing_consent'] ) ? $klant['email_marketing_consent'] : array();
		$gegeven     = ! empty( $data['buyer_accepts_marketing'] ) || ( isset( $toestemming['state'] ) && 'subscribed' === $toestemming['state'] );

		$reden = self::flow_weigering( $email, $gegeven, $i );
		if ( '' !== $reden ) {
			return array( 'ok' => true, 'actie' => 'checkout niet bewaard: ' . $reden );
		}

		$herstel = isset( $data['abandoned_checkout_url'] ) ? esc_url_raw( (string) $data['abandoned_checkout_url'] ) : '';
		if ( '' === $herstel ) {
			return array( 'ok' => true, 'actie' => 'geen herstellink' );
		}

		$valuta = (string) ( isset( $data['presentment_currency'] ) ? $data['presentment_currency'] : ( isset( $data['currency'] ) ? $data['currency'] : 'EUR' ) );
		$items  = self::regels( isset( $data['line_items'] ) ? $data['line_items'] : array(), $valuta );
		if ( empty( $items ) ) {
			return array( 'ok' => true, 'actie' => 'lege checkout' );
		}

		$totaal = isset( $data['total_price'] ) ? (float) $data['total_price'] : 0;
		$token  = isset( $data['token'] ) ? (string) $data['token'] : ( isset( $data['id'] ) ? (string) $data['id'] : '' );

		WSFM_Cart_Tracking::capture_extern(
			array(
				'email'       => $email,
				'name'        => self::voornaam( $data ),
				'contents'    => wp_json_encode( array( 'items' => $items, 'total' => $totaal, 'total_text' => self::geld( $totaal, $valuta ) ) ),
				'hash'        => md5( $token . wp_json_encode( $items ) ),
				'herstel_url' => $herstel,
				'bron'        => 'shopify',
			)
		);

		return array( 'ok' => true, 'actie' => 'checkout bewaard' );
	}

	/**
	 * orders/create, orders/paid en orders/fulfilled.
	 *
	 * Eerst de klant op de lijst (zoals altijd, met toestemming), dan de bestelling
	 * bewaren en de order-flows in de wachtrij zetten. Dezelfde bestelling twee
	 * keer melden levert niets dubbel op.
	 *
	 * @param array  $data      Bestelling.
	 * @param string $onderwerp Topic.
	 * @param array  $i         Instellingen.
	 * @return array
	 */
	private static function verwerk_order( array $data, $onderwerp, array $i ) {
		$klant = isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array();
		$email = sanitize_email( (string) ( ! empty( $data['email'] ) ? $data['email'] : ( ! empty( $data['contact_email'] ) ? $data['contact_email'] : ( isset( $klant['email'] ) ? $klant['email'] : '' ) ) ) );

		if ( ! is_email( $email ) ) {
			return array( 'ok' => true, 'actie' => 'geen adres' );
		}

		$klant['email'] = $email;
		if ( ! isset( $klant['accepts_marketing'] ) && isset( $data['buyer_accepts_marketing'] ) ) {
			$klant['accepts_marketing'] = $data['buyer_accepts_marketing'];
		}
		$lijst = self::verwerk_klant( $klant, $onderwerp, $i );

		/* Een testbestelling start geen flows. */
		if ( ! empty( $data['test'] ) ) {
			return array( 'ok' => true, 'actie' => 'testbestelling, ' . $lijst['actie'] );
		}

		WSFM_Cart_Tracking::mark_extern_order( $email );

		$reden = self::flow_weigering( $email, $lijst['ja'], $i );
		if ( '' !== $reden ) {
			return array( 'ok' => true, 'actie' => $lijst['actie'] . '; geen flows: ' . $reden );
		}

		$valuta = (string) ( isset( $data['presentment_currency'] ) ? $data['presentment_currency'] : ( isset( $data['currency'] ) ? $data['currency'] : 'EUR' ) );
		$aantal = isset( $klant['orders_count'] ) ? (int) $klant['orders_count'] : 0;
		$nummer = isset( $data['name'] ) && '' !== (string) $data['name'] ? (string) $data['name'] : ( isset( $data['order_number'] ) ? '#' . $data['order_number'] : '' );

		/* Shopify telt de bestelling zelf mee in orders_count; dat is zo gelezen
	       uit de payload en moet bij de eerste echte webhook nagekeken worden. */
		$rij = WSFM_Ext_Orders::opslaan(
			array(
				'bron'         => 'shopify',
				'ext_id'       => isset( $data['id'] ) ? (string) $data['id'] : '',
				'email'        => $email,
				'first_name'   => self::voornaam( $data ),
				'order_number' => $nummer,
				'total_text'   => self::geld( isset( $data['total_price'] ) ? $data['total_price'] : 0, $valuta ),
				'items'        => self::regels( isset( $data['line_items'] ) ? $data['line_items'] : array(), $valuta ),
				'prior_orders' => max( 0, $aantal - 1 ),
			)
		);
		$id      = (int) $rij['id'];
		$bewaard = WSFM_Ext_Orders::get( $id );
		$eerder  = max( $bewaard ? (int) $bewaard->prior_orders : 0, WSFM_Ext_Orders::aantal_eerder( $email, $id ) );

		$trigger = array(
			'orders/create'    => 'order_placed',
			'orders/paid'      => 'order_paid',
			'orders/fulfilled' => 'order_completed',
		);
		$soort = $trigger[ $onderwerp ];

		$gezet = 0;
		foreach ( WSFM_Flows::get_active( $soort ) as $flow ) {
			if ( ! WSFM_Flow_Conditions::voorwaarde_geldt( $flow, $eerder ) ) {
				continue;
			}
			$gezet += WSFM_Queue::enqueue_flow(
				$flow,
				array(
					'customer_email' => $email,
					'customer_name'  => self::voornaam( $data ),
					'ext_order_id'   => $id,
					'base_timestamp' => time(),
				)
			);
		}

		return array( 'ok' => true, 'actie' => $lijst['actie'] . '; ' . $gezet . ' mails ingepland (eerdere bestellingen: ' . $eerder . ')' );
	}
}
