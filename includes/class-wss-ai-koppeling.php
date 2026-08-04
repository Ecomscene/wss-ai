<?php
/**
 * De koppeling met de server van Webshopschool.
 *
 * De plugin meldt zich bij het activeren aan met zijn eigen webadres en krijgt
 * een sleutel terug. Herkent Webshopschool dat adres als een bestaande klant,
 * dan staat hij meteen aan; anders blijft hij op wachten staan.
 *
 * De klant hoeft dus niets in te vullen. Dat is met opzet: een veld met "plak
 * hier je sleutel" is precies de stap waar mensen op afhaken, en hij zou toch
 * uit een e-mail moeten komen die ze kwijt zijn.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Koppeling {

	const OPTIE_TOKEN  = 'wss_ai_token';
	const OPTIE_STATUS = 'wss_ai_status';
	const OPTIE_UITLEG = 'wss_ai_uitleg';

	/** Waar de server staat. Eén plek, zodat testen op een andere server kan. */
	public static function api() {
		$basis = defined( 'WSS_AI_API' ) ? WSS_AI_API : 'https://api-chat.webshopschool.nl';
		return rtrim( $basis, '/' ) . '/api/portal/plugin';
	}

	public static function token() {
		return (string) get_option( self::OPTIE_TOKEN, '' );
	}

	public static function status() {
		return (string) get_option( self::OPTIE_STATUS, 'onbekend' );
	}

	public static function uitleg() {
		return (string) get_option( self::OPTIE_UITLEG, '' );
	}

	public static function is_actief() {
		return 'actief' === self::status() && '' !== self::token();
	}

	/**
	 * Aanmelden bij Webshopschool.
	 *
	 * Draait bij het activeren en daarna dagelijks. Dat tweede is niet overbodig:
	 * een site die op 'wacht' stond en later door Joey wordt aangezet, hoort dat
	 * vanzelf te merken zonder dat iemand de plugin uit en aan moet zetten.
	 */
	public static function meld_aan() {
		$antwoord = wp_remote_post(
			self::api() . '/registreer',
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'site'   => home_url(),
						'naam'   => get_bloginfo( 'name' ),
						'versie' => WSS_AI_VERSIE,
					)
				),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			/* De sleutel die er al staat NIET weggooien. Een storing van een minuut
			   mag geen werkende koppeling wissen. */
			update_option( self::OPTIE_UITLEG, __( 'We konden Webshopschool even niet bereiken.', 'wss-ai' ) );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['data']['token'] ) ) {
			update_option( self::OPTIE_UITLEG, __( 'Het aanmelden gaf een onverwacht antwoord.', 'wss-ai' ) );
			return false;
		}

		update_option( self::OPTIE_TOKEN, (string) $data['data']['token'] );
		update_option( self::OPTIE_STATUS, (string) $data['data']['status'] );
		update_option( self::OPTIE_UITLEG, (string) $data['data']['uitleg'] );
		return true;
	}

	/**
	 * Iets aan de server vragen.
	 *
	 * Geeft de tekst terug, of een WP_Error met een uitlegbare reden. Nooit een
	 * kale `false`: de klant hoort te horen wat er aan de hand is.
	 */
	public static function vraag( $pad, $body, $wachttijd = 90 ) {
		if ( ! self::is_actief() ) {
			return new WP_Error( 'niet-actief', self::uitleg() ? self::uitleg() : __( 'Je webshop is nog niet gekoppeld.', 'wss-ai' ) );
		}

		$antwoord = wp_remote_post(
			self::api() . $pad,
			array(
				/* Ruim: een tekst schrijven duurt bij een lang product tientallen
				   seconden, en een time-out halverwege kost wel de credits. Een
				   foto duurt nog langer; die geeft zijn eigen wachttijd mee. */
				'timeout' => max( 15, (int) $wachttijd ),
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . self::token(),
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			return new WP_Error( 'geen-verbinding', __( 'We konden Webshopschool niet bereiken. Probeer het zo nog eens.', 'wss-ai' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'onleesbaar', __( 'Er kwam een onverwacht antwoord terug.', 'wss-ai' ) );
		}
		if ( empty( $data['ok'] ) ) {
			$fout = ! empty( $data['error'] ) ? (string) $data['error'] : __( 'Dit lukte niet.', 'wss-ai' );
			/* Zegt de server dat we niet aanstaan, dan onthouden we dat -- anders
			   blijft de knop uitnodigen tot iets dat toch niet werkt. */
			if ( 403 === (int) wp_remote_retrieve_response_code( $antwoord ) ) {
				update_option( self::OPTIE_STATUS, 'wacht' );
				update_option( self::OPTIE_UITLEG, $fout );
			}
			return new WP_Error( 'geweigerd', $fout );
		}

		return isset( $data['data'] ) ? $data['data'] : array();
	}

	/**
	 * Wat de fotostudio kan: welke taken er zijn en welke AI ze kan uitvoeren.
	 *
	 * Die lijsten staan bij Webshopschool en niet in deze plugin, zodat een nieuw
	 * of beter model bij iedereen tegelijk verschijnt zonder dat er een update
	 * langs alle webshops moet.
	 *
	 * Een uur onthouden. Lukt het ophalen niet, dan komen er LEGE lijsten terug
	 * en geen verzonnen standaardlijst: de beheerpagina hoort te zeggen dat het
	 * niet gelukt is, in plaats van keuzes te tonen die er misschien niet zijn.
	 */
	/**
	 * Het onthouden lijstje weggooien.
	 *
	 * Nodig na een update van de plugin. De lijst met taken en motoren komt van
	 * de server en wordt een uur bewaard, maar dat lijstje overleeft een update:
	 * je draait dan de nieuwe plugin met de mogelijkheden van de oude, ziet een
	 * knop niet verschijnen die er wel is, en denkt dat de update niet werkte.
	 * Dat is precies wat er gebeurde bij het uitbrengen van "Variant maken".
	 */
	public static function vergeet_opties() {
		delete_transient( 'wss_ai_foto_opties' );
		delete_transient( 'wss_ai_fotomodellen' );
	}

	private static function foto_opties() {
		$onthouden = get_transient( 'wss_ai_foto_opties' );
		if ( is_array( $onthouden ) ) {
			return $onthouden;
		}
		$leeg = array( 'taken' => array(), 'motoren' => array() );
		if ( ! self::is_actief() ) {
			return $leeg;
		}

		$antwoord = wp_remote_get(
			self::api() . '/foto/opties',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . self::token() ),
			)
		);
		if ( is_wp_error( $antwoord ) ) {
			return $leeg;
		}
		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) || empty( $data['ok'] ) || empty( $data['data'] ) ) {
			return $leeg;
		}

		$uit = array( 'taken' => array(), 'motoren' => array(), 'varianten' => array() );
		foreach ( array( 'taken', 'motoren', 'varianten' ) as $soort ) {
			foreach ( (array) ( isset( $data['data'][ $soort ] ) ? $data['data'][ $soort ] : array() ) as $m ) {
				if ( empty( $m['key'] ) ) {
					continue;
				}
				$uit[ $soort ][] = array(
					'key'           => sanitize_key( $m['key'] ),
					'label'         => isset( $m['label'] ) ? sanitize_text_field( $m['label'] ) : $m['key'],
					'hint'          => isset( $m['hint'] ) ? sanitize_text_field( $m['hint'] ) : '',
					'gemeten'       => ! empty( $m['gemeten'] ),
					'gebruiktStijl' => ! empty( $m['gebruiktStijl'] ),
					'heeftSoorten'  => ! empty( $m['heeftSoorten'] ),
				);
			}
		}
		set_transient( 'wss_ai_foto_opties', $uit, HOUR_IN_SECONDS );
		return $uit;
	}

	public static function fototaken() {
		$o = self::foto_opties();
		return $o['taken'];
	}

	public static function fotomotoren() {
		$o = self::foto_opties();
		return $o['motoren'];
	}

	public static function fotovarianten() {
		$o = self::foto_opties();
		return isset( $o['varianten'] ) ? $o['varianten'] : array();
	}
}
