<?php
/**
 * Auto-adres: postcode + huisnummer op de afrekenpagina, met postcode.tech.
 *
 * WAAROM
 * De standaard afrekenpagina van WooCommerce heeft één veld "Adres". Mensen
 * vullen daar hun straat in en vergeten het huisnummer, en dan ligt er een
 * pakket bij PostNL terug. Met een apart veld voor het huisnummer gebeurt dat
 * niet meer, en met postcode + huisnummer vullen we straat en plaats zelf in.
 *
 * WAT ER IN DE ORDER KOMT, EN WAAROM DAT NIET VERANDERT
 * Elke klant heeft een andere verzendkoppeling (MyParcel, Sendcloud, PostNL,
 * GLS, ...). Die lezen allemaal billing_address_1 / shipping_address_1. Dus
 * plakken we bij het afrekenen straat, huisnummer en toevoeging weer aan elkaar
 * in adresregel 1 ("Dorpsstraat 12-A"), precies zoals een klant het vroeger
 * zelf intikte. Daarnaast komen huisnummer en toevoeging als losse meta in de
 * order (_billing_house_number, _billing_house_number_suffix), voor wie ze los
 * wil hebben. Geen enkele koppeling hoeft iets anders te lezen dan voorheen.
 *
 * AFREKENEN MAG NOOIT AAN DEZE MODULE HANGEN
 * Ligt postcode.tech eruit, is de sleutel verlopen of zit de limiet erop, dan
 * vult hij gewoon niets in en typt de klant zelf straat en plaats. De velden
 * blijven altijd bewerkbaar en er is geen enkele controle die zegt "dit adres
 * bestaat niet". Bij een storing houdt een rem de aanvragen een kwartier tegen,
 * zodat elke bezoeker niet vier seconden op een time-out zit te wachten.
 *
 * Alleen de klassieke afrekenpagina (shortcode, Elementor-widget). De
 * blokken-checkout van WooCommerce kent deze filters niet; daar gebeurt dan
 * niets, en de beheerpagina zegt dat erbij.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Postcode {

	const SLUG       = 'wss-ai-auto-adres';
	const OPTIE      = 'wss_ai_postcode';
	const OPTIE_FOUT = 'wss_ai_postcode_fout';
	const REM        = 'wss_ai_postcode_rem';
	const API        = 'https://postcode.tech/api/v1/postcode/full';

	/** Postcode.tech kent alleen Nederlandse adressen. */
	const LANDEN = array( 'NL' );

	/** Hoeveel opzoekingen één bezoeker per tien minuten mag doen. */
	const PER_IP = 40;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_wss_ai_postcode_bewaar', array( __CLASS__, 'bewaar' ) );
		add_action( 'admin_post_wss_ai_postcode_test', array( __CLASS__, 'test' ) );

		add_action( 'wp_ajax_wss_ai_postcode', array( __CLASS__, 'ajax_zoek' ) );
		add_action( 'wp_ajax_nopriv_wss_ai_postcode', array( __CLASS__, 'ajax_zoek' ) );

		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'velden' ), 20 );
		add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'locale' ), 20 );
		add_filter( 'woocommerce_get_country_locale_default', array( __CLASS__, 'locale_standaard' ), 20 );
		/* De "default_checkout_"-filter krijgt de echte beginwaarde. De filter
		   woocommerce_checkout_get_value is een voorfilter die met null begint,
		   en daar valt niets op te schonen. */
		add_filter( 'default_checkout_billing_address_1', array( __CLASS__, 'beginwaarde' ), 20, 2 );
		add_filter( 'default_checkout_shipping_address_1', array( __CLASS__, 'beginwaarde' ), 20, 2 );
		add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'samenvoegen' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'controleer' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 20 );
	}

	/* ---------------- de schakelaars ---------------- */

	/** Mag deze webshop de module zien? Webshopschool zet hem aan, net als de rest. */
	public static function beschikbaar() {
		return class_exists( 'WooCommerce' ) && WSS_AI_Koppeling::module_aan( 'postcode' );
	}

	private static function instellingen() {
		$o = get_option( self::OPTIE, array() );
		return array(
			'aan'     => is_array( $o ) && ! empty( $o['aan'] ),
			'sleutel' => is_array( $o ) && isset( $o['sleutel'] ) ? (string) $o['sleutel'] : '',
		);
	}

	/** Staan de losse velden op de afrekenpagina? Dat kan ook zonder sleutel. */
	public static function actief() {
		if ( ! self::beschikbaar() ) {
			return false;
		}
		$i = self::instellingen();
		return $i['aan'];
	}

	/** Zoeken we straat en plaats op? Alleen met een sleutel. */
	private static function zoekt_op() {
		$i = self::instellingen();
		return self::actief() && '' !== $i['sleutel'];
	}

	/** Alleen op de afrekenpagina en tijdens het afrekenen zelf, niet in Mijn account. */
	private static function op_afrekenen() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Had het afrekenformulier onze velden voor dit adres?
	 *
	 * Een snelle betaalknop (Apple Pay, Google Pay) stuurt ze niet mee en levert
	 * zelf een hele adresregel; die laten we met rust. Bij "verzenden naar
	 * factuuradres" kopieert WooCommerce het factuuradres naar het verzendadres,
	 * en dan telt wat er bij het factuuradres stond, ook als het verzendblok
	 * niet eens op de pagina stond.
	 */
	private static function formulier_had( $t, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce heeft de nonce van het afrekenformulier al gecontroleerd.
		if ( 'shipping' === $t && empty( $data['ship_to_different_address'] ) ) {
			return isset( $_POST['billing_house_number'] );
		}
		return isset( $_POST[ $t . '_house_number' ] );
		// phpcs:enable
	}

	/* ---------------- de afrekenpagina ---------------- */

	/**
	 * Huisnummer en toevoeging erbij, voor factuur- en verzendadres.
	 *
	 * Niet verplicht in de definitie: WooCommerce zou dat dan ook eisen van een
	 * Belgische klant, en van een snelle betaalknop die deze velden nooit
	 * meestuurt. De controle doen we zelf, alleen voor Nederland en alleen als
	 * het formulier ze echt had.
	 */
	public static function velden( $velden ) {
		if ( ! self::actief() || ! is_array( $velden ) ) {
			return $velden;
		}
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			if ( ! isset( $velden[ $t ][ $t . '_address_1' ] ) ) {
				continue;
			}
			$velden[ $t ][ $t . '_house_number' ] = array(
				'type'              => 'text',
				'label'             => __( 'Huisnummer', 'wss-ai' ),
				'required'          => false,
				'class'             => array( 'form-row-first', 'wss-adres-nr' ),
				'priority'          => 44,
				'autocomplete'      => 'off',
				'custom_attributes' => array( 'inputmode' => 'numeric', 'maxlength' => '6' ),
			);
			$velden[ $t ][ $t . '_house_number_suffix' ] = array(
				'type'              => 'text',
				'label'             => __( 'Toevoeging', 'wss-ai' ),
				'required'          => false,
				'class'             => array( 'form-row-last', 'wss-adres-toev' ),
				'priority'          => 46,
				'autocomplete'      => 'off',
				'custom_attributes' => array( 'maxlength' => '10' ),
			);
		}
		return $velden;
	}

	/**
	 * Volgorde en labels voor Nederland: postcode, huisnummer, toevoeging,
	 * straat, plaats. WooCommerce schuift de velden zelf om als iemand van land
	 * wisselt; daarvoor moet het in de landinstellingen staan en niet alleen in
	 * de velden.
	 */
	public static function locale( $locale ) {
		if ( ! is_array( $locale ) || ! self::actief() || ! self::op_afrekenen() ) {
			return $locale;
		}
		foreach ( self::LANDEN as $land ) {
			$nu = isset( $locale[ $land ] ) && is_array( $locale[ $land ] ) ? $locale[ $land ] : array();

			$nu['postcode']            = array_merge( isset( $nu['postcode'] ) ? (array) $nu['postcode'] : array(), array( 'priority' => 42 ) );
			$nu['house_number']        = array( 'hidden' => false, 'required' => true, 'priority' => 44, 'label' => __( 'Huisnummer', 'wss-ai' ) );
			$nu['house_number_suffix'] = array( 'hidden' => false, 'required' => false, 'priority' => 46, 'label' => __( 'Toevoeging', 'wss-ai' ) );
			$nu['address_1']           = array_merge(
				isset( $nu['address_1'] ) ? (array) $nu['address_1'] : array(),
				array(
					'priority'    => 48,
					'label'       => __( 'Straat', 'wss-ai' ),
					'placeholder' => '',
				)
			);
			$nu['address_2']           = array_merge( isset( $nu['address_2'] ) ? (array) $nu['address_2'] : array(), array( 'hidden' => true, 'required' => false ) );

			$locale[ $land ] = $nu;
		}
		return $locale;
	}

	/** Buiten Nederland zijn de twee nieuwe velden er niet. */
	public static function locale_standaard( $standaard ) {
		if ( ! is_array( $standaard ) || ! self::actief() || ! self::op_afrekenen() ) {
			return $standaard;
		}
		$standaard['house_number']        = array( 'hidden' => true, 'required' => false, 'priority' => 44, 'label' => __( 'Huisnummer', 'wss-ai' ) );
		$standaard['house_number_suffix'] = array( 'hidden' => true, 'required' => false, 'priority' => 46, 'label' => __( 'Toevoeging', 'wss-ai' ) );
		return $standaard;
	}

	/**
	 * Een terugkerende klant heeft "Dorpsstraat 12-A" als adresregel opgeslagen
	 * en 12 als huisnummer. Zonder dit stond er straks "Dorpsstraat 12-A" in het
	 * straatveld en kwam er bij het afrekenen nog een keer 12-A achter.
	 */
	public static function beginwaarde( $waarde, $veld = '' ) {
		if ( ! in_array( $veld, array( 'billing_address_1', 'shipping_address_1' ), true ) || ! is_string( $waarde ) || '' === $waarde ) {
			return $waarde;
		}
		if ( ! self::actief() || ! function_exists( 'WC' ) || ! WC()->checkout() ) {
			return $waarde;
		}
		$t    = 0 === strpos( $veld, 'billing' ) ? 'billing' : 'shipping';
		$nr   = (string) WC()->checkout()->get_value( $t . '_house_number' );
		$toev = (string) WC()->checkout()->get_value( $t . '_house_number_suffix' );
		return '' === trim( $nr ) ? $waarde : self::zonder_nummer( $waarde, $nr, $toev );
	}

	/** Haal een huisnummer (en toevoeging) achter een straatnaam weg, als het er al achter staat. */
	private static function zonder_nummer( $straat, $nr, $toev ) {
		$straat = trim( (string) $straat );
		$nr     = trim( (string) $nr );
		$toev   = trim( (string) $toev );
		if ( '' === $nr ) {
			return $straat;
		}
		$patroon = '/\s+' . preg_quote( $nr, '/' );
		if ( '' !== $toev ) {
			$patroon .= '(\s*[-\/]?\s*' . preg_quote( $toev, '/' ) . ')?';
		}
		$patroon .= '\s*$/iu';
		$zonder   = preg_replace( $patroon, '', $straat );
		return ( is_string( $zonder ) && '' !== trim( $zonder ) ) ? trim( $zonder ) : $straat;
	}

	/** "12" + "A" wordt "12-A", zoals de verzendkoppelingen het al jaren lezen. */
	private static function nummer_tekst( $nr, $toev ) {
		$nr   = trim( (string) $nr );
		$toev = trim( (string) $toev, " \t\n\r\0\x0B-/" );
		return '' === $toev ? $nr : $nr . '-' . $toev;
	}

	/**
	 * Bij het afrekenen: straat + huisnummer + toevoeging terug in adresregel 1.
	 *
	 * Adresregel 2 laten we zoals hij binnenkwam: op een Nederlands adres is hij
	 * verborgen en dus leeg, en wat iemand er bij een kapot script toch in typte
	 * gooien we niet weg.
	 */
	public static function samenvoegen( $data ) {
		if ( ! is_array( $data ) || ! self::actief() ) {
			return $data;
		}
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			if ( ! self::formulier_had( $t, $data ) ) {
				continue;
			}
			$land = isset( $data[ $t . '_country' ] ) ? strtoupper( (string) $data[ $t . '_country' ] ) : '';
			if ( ! in_array( $land, self::LANDEN, true ) ) {
				continue;
			}
			$nr     = isset( $data[ $t . '_house_number' ] ) ? trim( (string) $data[ $t . '_house_number' ] ) : '';
			$toev   = isset( $data[ $t . '_house_number_suffix' ] ) ? trim( (string) $data[ $t . '_house_number_suffix' ] ) : '';
			$straat = isset( $data[ $t . '_address_1' ] ) ? trim( (string) $data[ $t . '_address_1' ] ) : '';
			if ( '' === $nr || '' === $straat ) {
				/* Laat het leeg, dan zegt WooCommerce of onze eigen controle wat er mist. */
				continue;
			}
			$straat = self::zonder_nummer( $straat, $nr, $toev );

			$data[ $t . '_address_1' ] = $straat . ' ' . self::nummer_tekst( $nr, $toev );
		}
		return $data;
	}

	/** Een Nederlands adres zonder huisnummer gaat niet door. Dat is de hele reden van deze module. */
	public static function controleer( $data, $fouten ) {
		if ( ! self::actief() || ! is_array( $data ) || ! is_object( $fouten ) ) {
			return;
		}
		$typen = array( 'billing' => __( 'factuuradres', 'wss-ai' ) );
		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$typen['shipping'] = __( 'verzendadres', 'wss-ai' );
		}
		foreach ( $typen as $t => $naam ) {
			if ( ! self::formulier_had( $t, $data ) ) {
				continue;
			}
			$land = isset( $data[ $t . '_country' ] ) ? strtoupper( (string) $data[ $t . '_country' ] ) : '';
			if ( ! in_array( $land, self::LANDEN, true ) ) {
				continue;
			}
			$nr = isset( $data[ $t . '_house_number' ] ) ? trim( (string) $data[ $t . '_house_number' ] ) : '';
			if ( '' === $nr ) {
				/* translators: %s is factuuradres of verzendadres. */
				$fouten->add( 'validation', sprintf( __( 'Vul het huisnummer van je %s in.', 'wss-ai' ), $naam ) );
			}
		}
	}

	/** Huisnummer en toevoeging ook los in de order, voor wie ze apart wil lezen. */
	public static function order_meta( $order, $data ) {
		if ( ! self::actief() || ! is_object( $order ) || ! is_array( $data ) ) {
			return;
		}
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			if ( ! self::formulier_had( $t, $data ) || empty( $data[ $t . '_house_number' ] ) ) {
				continue;
			}
			$order->update_meta_data( '_' . $t . '_house_number', sanitize_text_field( (string) $data[ $t . '_house_number' ] ) );
			$order->update_meta_data( '_' . $t . '_house_number_suffix', sanitize_text_field( isset( $data[ $t . '_house_number_suffix' ] ) ? (string) $data[ $t . '_house_number_suffix' ] : '' ) );
		}
	}

	/* ---------------- het opzoeken ---------------- */

	public static function script() {
		if ( ! self::actief() || ! self::op_afrekenen() ) {
			return;
		}
		$params = array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'zoekt'    => self::zoekt_op() ? 1 : 0,
			'landen'   => self::LANDEN,
			'onbekend' => __( 'We vinden dit adres niet. Vul straat en plaats zelf in.', 'wss-ai' ),
		);

		$js = '(function($){'
			. 'var p=window.wssPostcode||{};if(!$){return;}'
			. 'var timers={},laatste={};'
			. 'function meld(t,tekst){var id=t+"_wss_postcode_melding",$m=$("#"+id);'
			. 'if(!$m.length){var $na=$("#"+t+"_house_number_suffix_field");if(!$na.length){return;}'
			. '$m=$("<p/>",{id:id,"class":"form-row form-row-wide wss-postcode-melding"}).css({color:"#b32d2e",margin:"0 0 1em",fontSize:"0.9em"});$na.after($m);}'
			. 'if(tekst){$m.text(tekst).show();}else{$m.text("").hide();}}'
			. 'function zoek(t){if(!p.zoekt){return;}'
			. 'var $land=$("#"+t+"_country"),$pc=$("#"+t+"_postcode"),$nr=$("#"+t+"_house_number");'
			. 'if(!$pc.length||!$nr.length){return;}'
			. 'var land=String($land.val()||"").toUpperCase();'
			. 'if((p.landen||[]).indexOf(land)<0){meld(t,"");return;}'
			. 'var pc=String($pc.val()||"").replace(/\\s+/g,"").toUpperCase();'
			. 'var m=String($nr.val()||"").match(/^\\s*(\\d{1,5})/);var nr=m?m[1]:"";'
			. 'if(!/^[1-9][0-9]{3}[A-Z]{2}$/.test(pc)||!nr){return;}'
			. 'var sleutel=pc+"|"+nr;if(laatste[t]===sleutel){return;}laatste[t]=sleutel;'
			. '$.ajax({url:p.ajax,method:"POST",dataType:"json",timeout:5000,'
			. 'data:{action:"wss_ai_postcode",postcode:pc,nummer:nr}})'
			. '.done(function(a){if(laatste[t]!==sleutel){return;}'
			. 'if(a&&a.success&&a.data&&a.data.straat){'
			. '$("#"+t+"_address_1").val(a.data.straat).trigger("change");'
			. 'if(a.data.plaats){$("#"+t+"_city").val(a.data.plaats).trigger("change");}'
			. 'meld(t,"");$(document.body).trigger("update_checkout");}'
			. 'else if(a&&a.data&&a.data.reden==="onbekend"){meld(t,p.onbekend);}'
			. 'else{meld(t,"");}})'
			/* Een storing zegt de klant niets en mag hem niet ophouden: hij typt
			   gewoon verder. Bij de volgende wijziging proberen we het opnieuw. */
			. '.fail(function(){laatste[t]="";meld(t,"");});}'
			. 'function plan(t){clearTimeout(timers[t]);timers[t]=setTimeout(function(){zoek(t);},450);}'
			. '$(function(){["billing","shipping"].forEach(function(t){'
			. '$(document.body).on("input change","#"+t+"_postcode, #"+t+"_house_number",function(){plan(t);});'
			. '$(document.body).on("change","#"+t+"_country",function(){laatste[t]="";plan(t);});'
			. '});});'
			. '})(window.jQuery);';

		wp_register_script( 'wss-ai-postcode', '', array( 'jquery' ), WSS_AI_VERSIE, true );
		wp_enqueue_script( 'wss-ai-postcode' );
		wp_add_inline_script( 'wss-ai-postcode', 'window.wssPostcode=' . wp_json_encode( $params ) . ';', 'before' );
		wp_add_inline_script( 'wss-ai-postcode', $js );
	}

	/**
	 * Het opzoeken vanuit de afrekenpagina.
	 *
	 * Via onze eigen server, zodat de sleutel nooit in de browser komt. Zonder
	 * nonce, met opzet: een bezoeker zonder account deelt dezelfde nonce met
	 * iedere andere bezoeker, en op een gecachete pagina is hij na een dag
	 * verlopen, waarna het opzoeken stilletjes stopt. Wat dit endpoint echt
	 * beschermt is de rem per bezoeker en de cache.
	 */
	public static function ajax_zoek() {
		if ( ! self::zoekt_op() ) {
			wp_send_json_error( array( 'reden' => 'uit' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$pc = isset( $_POST['postcode'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) ) ) : '';
		$nr = isset( $_POST['nummer'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['nummer'] ) ) ) : '';
		// phpcs:enable
		$nr = substr( (string) $nr, 0, 5 );

		if ( ! preg_match( '/^[1-9][0-9]{3}[A-Z]{2}$/', (string) $pc ) || '' === $nr ) {
			wp_send_json_error( array( 'reden' => 'ongeldig' ) );
		}

		$cache = 'wss_ai_pc_' . md5( $pc . '|' . $nr );
		$was   = get_transient( $cache );
		if ( is_array( $was ) ) {
			self::stuur( $was );
		}

		if ( get_transient( self::REM ) ) {
			wp_send_json_error( array( 'reden' => 'storing' ) );
		}
		if ( ! self::mag_bezoeker() ) {
			wp_send_json_error( array( 'reden' => 'te-vaak' ) );
		}

		$uit = self::opzoeken( $pc, $nr, 4 );
		if ( is_wp_error( $uit ) ) {
			wp_send_json_error( array( 'reden' => 'storing' ) );
		}
		set_transient( $cache, $uit, 'gevonden' === $uit['stand'] ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
		self::stuur( $uit );
	}

	private static function stuur( $uit ) {
		if ( isset( $uit['stand'] ) && 'gevonden' === $uit['stand'] ) {
			wp_send_json_success(
				array(
					'straat' => isset( $uit['straat'] ) ? $uit['straat'] : '',
					'plaats' => isset( $uit['plaats'] ) ? $uit['plaats'] : '',
				)
			);
		}
		wp_send_json_error( array( 'reden' => 'onbekend' ) );
	}

	/** Hooguit PER_IP opzoekingen per bezoeker per tien minuten. */
	private static function mag_bezoeker() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$sl = 'wss_ai_pc_ip_' . md5( $ip );
		$n  = (int) get_transient( $sl );
		if ( $n >= self::PER_IP ) {
			return false;
		}
		set_transient( $sl, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * Eén adres opzoeken bij postcode.tech.
	 *
	 * Geeft array( stand => gevonden, straat, plaats ), array( stand => onbekend )
	 * of een WP_Error bij een storing. Een storing wordt onthouden voor de
	 * beheerpagina en zet de rem erop.
	 */
	public static function opzoeken( $pc, $nr, $wachttijd = 4 ) {
		$i = self::instellingen();
		if ( '' === $i['sleutel'] ) {
			return new WP_Error( 'geen-sleutel', __( 'Er is nog geen API-sleutel ingevuld.', 'wss-ai' ) );
		}

		$antwoord = wp_remote_get(
			add_query_arg(
				array(
					'postcode' => rawurlencode( $pc ),
					'number'   => rawurlencode( $nr ),
				),
				self::API
			),
			array(
				'timeout' => max( 2, (int) $wachttijd ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $i['sleutel'],
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			return self::storing( __( 'Postcode.tech was niet bereikbaar.', 'wss-ai' ), 5 );
		}

		$code = (int) wp_remote_retrieve_response_code( $antwoord );
		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );

		if ( 200 === $code && is_array( $data ) && ! empty( $data['street'] ) ) {
			delete_option( self::OPTIE_FOUT );
			return array(
				'stand'  => 'gevonden',
				'straat' => sanitize_text_field( (string) $data['street'] ),
				'plaats' => isset( $data['city'] ) ? sanitize_text_field( (string) $data['city'] ) : '',
			);
		}
		if ( in_array( $code, array( 200, 400, 404, 422 ), true ) ) {
			return array( 'stand' => 'onbekend' );
		}
		if ( in_array( $code, array( 401, 402, 403 ), true ) ) {
			return self::storing( __( 'Postcode.tech weigert de API-sleutel. Hij is onjuist of verlopen.', 'wss-ai' ), 15 );
		}
		if ( 429 === $code ) {
			return self::storing( __( 'De limiet van postcode.tech is bereikt.', 'wss-ai' ), 15 );
		}
		/* translators: %d is een HTTP-statuscode. */
		return self::storing( sprintf( __( 'Postcode.tech gaf een onverwacht antwoord (HTTP %d).', 'wss-ai' ), $code ), 5 );
	}

	private static function storing( $tekst, $minuten ) {
		update_option(
			self::OPTIE_FOUT,
			array(
				'tijd'  => time(),
				'tekst' => $tekst,
			),
			false
		);
		set_transient( self::REM, 1, (int) $minuten * MINUTE_IN_SECONDS );
		return new WP_Error( 'storing', $tekst );
	}

	/* ---------------- de beheerpagina ---------------- */

	public static function menu() {
		if ( ! self::beschikbaar() ) {
			return;
		}
		add_submenu_page(
			'woocommerce',
			__( 'Auto-adres', 'wss-ai' ),
			__( 'Auto-adres', 'wss-ai' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'pagina' )
		);
	}

	private static function blokken_checkout() {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'has_block' ) ) {
			return false;
		}
		$id = (int) wc_get_page_id( 'checkout' );
		return $id > 0 && has_block( 'woocommerce/checkout', $id );
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		$i    = self::instellingen();
		$fout = get_option( self::OPTIE_FOUT, array() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$bewaard = isset( $_GET['bewaard'] );
		// phpcs:enable
		$test = get_transient( 'wss_ai_pc_test_' . get_current_user_id() );
		if ( false !== $test ) {
			delete_transient( 'wss_ai_pc_test_' . get_current_user_id() );
		}

		$adres   = (string) get_option( 'woocommerce_store_address', '' );
		$test_pc = (string) get_option( 'woocommerce_store_postcode', '' );
		$test_nr = preg_match( '/(\d+)\s*\S*\s*$/', $adres, $m ) ? $m[1] : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Auto-adres', 'wss-ai' ); ?></h1>

			<?php if ( $bewaard ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Opgeslagen.', 'wss-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( is_array( $test ) ) : ?>
				<div class="notice <?php echo ! empty( $test['goed'] ) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html( $test['tekst'] ); ?></p></div>
			<?php endif; ?>

			<p style="max-width:760px;font-size:14px">
				<?php esc_html_e( 'Op de afrekenpagina vult een klant met een Nederlands adres zijn postcode en huisnummer in. Straat en plaats worden dan vanzelf ingevuld. Het huisnummer heeft een eigen veld, zodat niemand het meer vergeet. In de bestelling staat het adres daarna gewoon als één regel, zoals altijd, dus je verzendkoppeling merkt geen verschil.', 'wss-ai' ); ?>
			</p>

			<?php if ( self::blokken_checkout() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Je afrekenpagina gebruikt de blokken-checkout van WooCommerce. Auto-adres werkt alleen op de klassieke afrekenpagina; op deze pagina verandert er niets.', 'wss-ai' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wss_ai_postcode_bewaar">
				<?php wp_nonce_field( 'wss_ai_postcode_bewaar' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Inschakelen', 'wss-ai' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="aan" value="1" <?php checked( $i['aan'] ); ?>>
								<?php esc_html_e( 'Postcode en huisnummer op de afrekenpagina', 'wss-ai' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wss_ai_pc_sleutel"><?php esc_html_e( 'API-sleutel van postcode.tech', 'wss-ai' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="wss_ai_pc_sleutel" name="sleutel" value="" autocomplete="off"
								placeholder="<?php echo esc_attr( '' !== $i['sleutel'] ? __( 'Opgeslagen. Laat leeg om hem te houden.', 'wss-ai' ) : '' ); ?>">
							<?php if ( '' !== $i['sleutel'] ) : ?>
								<br><label><input type="checkbox" name="wis" value="1"> <?php esc_html_e( 'Sleutel weghalen', 'wss-ai' ); ?></label>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s is een link naar postcode.tech. */
									esc_html__( 'Een sleutel maak je aan op %s. Zonder sleutel staat het huisnummer wel apart, maar worden straat en plaats niet vanzelf ingevuld.', 'wss-ai' ),
									'<a href="https://postcode.tech" target="_blank" rel="noopener">postcode.tech</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stand', 'wss-ai' ); ?></th>
						<td>
							<?php
							if ( ! $i['aan'] ) {
								esc_html_e( 'Staat uit. De afrekenpagina is zoals WooCommerce hem standaard toont.', 'wss-ai' );
							} elseif ( '' === $i['sleutel'] ) {
								esc_html_e( 'Aan, zonder opzoeken: huisnummer apart, straat en plaats typt de klant zelf.', 'wss-ai' );
							} elseif ( is_array( $fout ) && ! empty( $fout['tekst'] ) ) {
								echo '<span style="color:#b32d2e;font-weight:600">' . esc_html__( 'Laatste storing:', 'wss-ai' ) . '</span> '
									. esc_html( $fout['tekst'] ) . ' '
									. esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $fout['tijd'] ) )
									. '<br><span class="description">' . esc_html__( 'Afrekenen gaat gewoon door; klanten vullen dan zelf straat en plaats in.', 'wss-ai' ) . '</span>';
							} else {
								echo '<span style="color:#00701a;font-weight:600">' . esc_html__( 'Aan', 'wss-ai' ) . '</span> ';
								esc_html_e( 'Straat en plaats worden opgezocht bij postcode.tech.', 'wss-ai' );
							}
							?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Opslaan', 'wss-ai' ) ); ?>
			</form>

			<?php if ( '' !== $i['sleutel'] ) : ?>
				<h2><?php esc_html_e( 'Sleutel testen', 'wss-ai' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wss_ai_postcode_test">
					<?php wp_nonce_field( 'wss_ai_postcode_test' ); ?>
					<input type="text" name="postcode" value="<?php echo esc_attr( $test_pc ); ?>" placeholder="1234AB" size="8">
					<input type="text" name="nummer" value="<?php echo esc_attr( $test_nr ); ?>" placeholder="<?php esc_attr_e( 'Huisnummer', 'wss-ai' ); ?>" size="6">
					<?php submit_button( __( 'Test de sleutel', 'wss-ai' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function bewaar() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_postcode_bewaar' );

		$oud     = self::instellingen();
		$sleutel = isset( $_POST['sleutel'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['sleutel'] ) ) ) : '';
		if ( '' === $sleutel ) {
			$sleutel = $oud['sleutel'];
		}
		if ( ! empty( $_POST['wis'] ) ) {
			$sleutel = '';
		}

		update_option(
			self::OPTIE,
			array(
				'aan'     => ! empty( $_POST['aan'] ),
				'sleutel' => $sleutel,
			)
		);
		if ( $sleutel !== $oud['sleutel'] ) {
			/* Een nieuwe sleutel verdient een schone lei: de oude storing en de
			   rem horen bij de oude sleutel. */
			delete_option( self::OPTIE_FOUT );
			delete_transient( self::REM );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&bewaard=1' ) );
		exit;
	}

	public static function test() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_postcode_test' );

		$pc = isset( $_POST['postcode'] ) ? strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['postcode'] ) ) ) ) : '';
		$nr = isset( $_POST['nummer'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['nummer'] ) ) ) : '';

		if ( ! preg_match( '/^[1-9][0-9]{3}[A-Z]{2}$/', (string) $pc ) || '' === $nr ) {
			$test = array( 'goed' => false, 'tekst' => __( 'Vul een geldige postcode (1234AB) en een huisnummer in.', 'wss-ai' ) );
		} else {
			/* De test gaat langs de rem heen: wie hier op de knop drukt wil weten of
			   het NU werkt, niet wat er een kwartier geleden misging. */
			delete_transient( self::REM );
			$uit = self::opzoeken( $pc, $nr, 8 );
			if ( is_wp_error( $uit ) ) {
				$test = array( 'goed' => false, 'tekst' => $uit->get_error_message() );
			} elseif ( 'gevonden' === $uit['stand'] ) {
				/* translators: 1: straat, 2: plaats. */
				$test = array( 'goed' => true, 'tekst' => sprintf( __( 'De sleutel werkt: %1$s, %2$s.', 'wss-ai' ), $uit['straat'], $uit['plaats'] ) );
			} else {
				$test = array( 'goed' => true, 'tekst' => __( 'De sleutel werkt, maar dit adres kent postcode.tech niet.', 'wss-ai' ) );
			}
		}
		set_transient( 'wss_ai_pc_test_' . get_current_user_id(), $test, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}
}
