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
		);
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
	 * Shopify meldt een klant of een bestelling.
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

		/* Een bestelling heeft de klant eronder hangen; een klant-melding IS de klant. */
		$klant = 0 === strpos( $onderwerp, 'orders/' ) ? ( isset( $data['customer'] ) && is_array( $data['customer'] ) ? $data['customer'] : array() ) : $data;

		$email = isset( $klant['email'] ) ? sanitize_email( (string) $klant['email'] ) : '';
		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'actie' => 'geen adres' ) );
		}

		/* Alleen met toestemming. Nieuwe API: email_marketing_consent.state; oude: accepts_marketing. */
		$toestemming = isset( $klant['email_marketing_consent'] ) && is_array( $klant['email_marketing_consent'] ) ? $klant['email_marketing_consent'] : array();
		$ja          = ( isset( $toestemming['state'] ) && 'subscribed' === $toestemming['state'] )
			|| ( ! isset( $toestemming['state'] ) && ! empty( $klant['accepts_marketing'] ) );

		if ( ! $ja ) {
			/* Uitschrijven in Shopify (not_subscribed/unsubscribed) respecteren we ook. */
			if ( isset( $toestemming['state'] ) && in_array( $toestemming['state'], array( 'unsubscribed' ), true ) ) {
				WSFM_Suppression::add( $email, 'manual' );
				return new WP_REST_Response( array( 'ok' => true, 'actie' => 'afgemeld' ) );
			}
			return new WP_REST_Response( array( 'ok' => true, 'actie' => 'geen toestemming' ) );
		}

		if ( WSFM_Suppression::is_suppressed( $email ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'actie' => 'stond op afmeldlijst' ) );
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

		return new WP_REST_Response( array( 'ok' => true, 'actie' => $uit['nieuw'] ? 'toegevoegd' : 'bestond' ) );
	}
}
