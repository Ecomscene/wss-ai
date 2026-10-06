<?php
/**
 * Laat het me weten als dit product er weer is.
 *
 * WAT DIT DOET
 * Staat een product op uitverkocht, dan komt er onder de (uitgeschakelde)
 * bestelknop een vakje waar een bezoeker zijn e-mailadres kan laten. Zodra de
 * winkelier de voorraad weer bijzet, gaat er automatisch een mail naar iedereen
 * die op dat product wachtte, met de productfoto, de prijs en een knop erbij.
 *
 * WAAROM DIT GEEN FLOW IS
 * Een flow hoort bij een PERSOON en begint bij iets wat die persoon doet: hij
 * rekent af, of hij laat zijn wagen staan. Dit begint bij iets wat de WINKEL
 * doet, namelijk voorraad bijzetten, en het hoort bij een PRODUCT waar een
 * wachtlijst aan hangt. Dat in het flow-model persen zou een trigger opleveren
 * die bij niemand begint en nergens stopt.
 *
 * WAAROM HET VERSTUREN WEL DOOR DE BESTAANDE WACHTRIJ GAAT
 * Omdat daar alles al in zit wat je bij post niet zelf wil nabouwen: porties van
 * vijftig per vijf minuten (zodat een product met driehonderd wachtenden geen
 * spamgolf wordt), de afmeldlijst die voor elke verzending geldt, drie pogingen
 * bij een storing, en het logboek dat "Verstuurde mail" vult. Een tweede
 * verzendweg ernaast is een tweede weg die apart stuk kan gaan, en dan is de
 * vraag "waar is mijn mail" niet meer te beantwoorden.
 *
 * WAAROM DE MAIL PAS EEN MINUUT LATER WORDT KLAARGEZET
 * Voorraad bijzetten gebeurt zelden één keer. Bij een import of een
 * voorraadsynchronisatie wordt elke variatie los opgeslagen, en bij het
 * nakijken van een levering gaat een aantal soms heen en weer van 0 naar 3 naar
 * 0. Wie bij de eerste wijziging meteen verstuurt, mailt driehonderd mensen over
 * een product dat een seconde later weer weg is. Een minuut wachten en per
 * product maar één taak inplannen vangt dat allemaal op.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Voorraadmail {

	const OPTIE          = 'wsfm_voorraad';
	const REST_NAMESPACE = 'wsfm/v1';

	/** De achtergrondtaak die de mails voor één product klaarzet. */
	const HOOK_MELDEN = 'wsfm_voorraad_melden';

	/** Zoveel seconden na een voorraadwijziging gaat de taak lopen. */
	const WACHT = 60;

	/** Zoveel wachtenden per product per taakronde in de wachtrij. */
	const PER_RONDE = 200;

	/** Zoveel open aanvragen mag één adres hebben. */
	const MAX_PER_ADRES = 50;

	/** Het queryargument van de stoplink in de mail. */
	const ARG_STOP = 'wsfm-voorraad-stop';

	/** Het queryargument met de sleutel. */
	const ARG_KEY = 'wsfm-sleutel';

	/* ---------------------------------------------------------------------
	 * Aanhaken
	 * ------------------------------------------------------------------- */

	/**
	 * Hook registration.
	 *
	 * DE VOLGORDE VAN DE DRIE DREMPELS HIER IS NIET WILLEKEURIG.
	 *
	 * De stoplink staat bovenaan en geldt altijd. Een link in een mail van
	 * vorige week hoort te blijven werken, ook als de module daarna is
	 * uitgezet; hij raakt alleen onze eigen tabel en heeft WooCommerce niet
	 * nodig.
	 *
	 * Daarna de achtergrondtaak en de REST-route, nog steeds OOK als de module
	 * uitstaat. Dat is met opzet: staat er een taak in de planner en luistert er
	 * niemand naar de haak, dan loopt die taak elke keer vast met "no callbacks
	 * registered". Dat is in september precies gebeurd, 747 keer, en dat wil ik
	 * niet nog eens.
	 *
	 * Pas daarna het vakje op de winkel en het volgen van de voorraad, en die
	 * staan alleen aan als de winkelier het heeft aangezet.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'misschien_stoppen' ), 5 );

		/* Zonder WooCommerce is er geen voorraad, geen product en geen
		   wc_get_product. Dan hoort hier niets te gebeuren in plaats van een
		   fatale fout bij de eerste aanroep. */
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action( self::HOOK_MELDEN, array( __CLASS__, 'verwerk' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		if ( ! self::aan() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'toon_formulier' ), 31 );

		/* Vijf haken, want voorraad verandert bij WooCommerce op vijf plekken en
		   ze vuren niet allemaal bij elkaar. set_stock is het aantal (een
		   bestelling, een handmatige correctie), set_stock_status is de
		   schakelaar op voorraad/uitverkocht, en update_product is het vangnet
		   voor iemand die het product in het beheerscherm opslaat zonder dat de
		   andere vier afgaan. Dat laatste gebeurt vaker dan je denkt, en één
		   gemiste haak betekent een wachtlijst die nooit iets krijgt. */
		add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'bij_voorraad' ) );
		add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'bij_voorraad' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'bij_voorraadstand' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'bij_voorraadstand' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'bij_opslaan' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Instellingen
	 * ------------------------------------------------------------------- */

	/**
	 * De standaardinstellingen: precies het vakje dat we meestal willen.
	 *
	 * @return array
	 */
	public static function standaard() {
		return array(
			'aan'            => 0,

			'kop'            => __( 'Tijdelijk uitverkocht', 'ws-flow-mailer' ),
			'tekst'          => __( 'Laat je e-mailadres achter en je krijgt bericht zodra dit product er weer is.', 'ws-flow-mailer' ),
			'plaatshouder'   => __( 'E-mailadres', 'ws-flow-mailer' ),
			'knop'           => __( 'Houd me op de hoogte', 'ws-flow-mailer' ),
			'kleine_letters' => __( 'Je krijgt alleen bericht over dit product, verder niets.', 'ws-flow-mailer' ),
			'gelukt'         => __( 'Gelukt! Je hoort van ons zodra dit product weer op voorraad is.', 'ws-flow-mailer' ),

			/* Ook aanmelden voor de nieuwsbrief: standaard UIT, en als hij
			   aangaat met een vinkje dat de bezoeker zelf moet aanzetten. Een
			   wachtlijst is geen inschrijving, en iemand stilletjes op de
			   nieuwsbrief zetten omdat hij op een jurkje wacht is precies hoe je
			   klachten en een slechte afzenderreputatie oogst. */
			'ook_nieuwsbrief' => 0,
			'nieuwsbrief_label' => __( 'Houd me ook op de hoogte van nieuwe producten en acties', 'ws-flow-mailer' ),
			'lijst_id'       => 0,

			'mail_onderwerp' => __( '{product_name} is weer op voorraad', 'ws-flow-mailer' ),
			'mail_kop'       => __( 'Hij is er weer!', 'ws-flow-mailer' ),
			'mail_tekst'     => __( "Je wilde bericht zodra {product_name} weer op voorraad zou zijn. Dat is nu zo.\n\nWees er snel bij: we hebben er een beperkt aantal van.", 'ws-flow-mailer' ),
			'mail_knop'      => __( 'Bekijk het product', 'ws-flow-mailer' ),
			'mail_sjabloon'  => 'rustig',
		);
	}

	/**
	 * De instellingen zoals ze nu zijn.
	 *
	 * @return array
	 */
	public static function instellingen() {
		$opgeslagen = get_option( self::OPTIE, array() );
		return array_merge( self::standaard(), is_array( $opgeslagen ) ? $opgeslagen : array() );
	}

	/**
	 * Staat hij aan?
	 *
	 * @return bool
	 */
	public static function aan() {
		$i = self::instellingen();
		return ! empty( $i['aan'] );
	}

	/**
	 * Opslaan vanuit het beheerformulier.
	 *
	 * @param array $ruw $_POST.
	 * @return void
	 */
	public static function opslaan( array $ruw ) {
		$oud = self::instellingen();

		$tekst = function ( $sleutel, $max = 200 ) use ( $ruw, $oud ) {
			return isset( $ruw[ $sleutel ] )
				? mb_substr( sanitize_text_field( wp_unslash( $ruw[ $sleutel ] ) ), 0, $max )
				: $oud[ $sleutel ];
		};

		$sjablonen = WSFM_Newsletter_Render::templates();
		$sjabloon  = isset( $ruw['mail_sjabloon'] ) ? sanitize_key( $ruw['mail_sjabloon'] ) : '';

		update_option(
			self::OPTIE,
			array(
				'aan'            => empty( $ruw['aan'] ) ? 0 : 1,

				'kop'            => $tekst( 'kop', 80 ),
				'tekst'          => $tekst( 'tekst', 300 ),
				'plaatshouder'   => $tekst( 'plaatshouder', 60 ),
				'knop'           => $tekst( 'knop', 40 ),
				'kleine_letters' => $tekst( 'kleine_letters', 200 ),
				'gelukt'         => $tekst( 'gelukt', 300 ),

				'ook_nieuwsbrief'   => empty( $ruw['ook_nieuwsbrief'] ) ? 0 : 1,
				'nieuwsbrief_label' => $tekst( 'nieuwsbrief_label', 200 ),
				'lijst_id'          => class_exists( 'WSFM_Lijsten' )
					? WSFM_Lijsten::geldig( isset( $ruw['lijst_id'] ) ? $ruw['lijst_id'] : 0 )
					: 0,

				'mail_onderwerp' => $tekst( 'mail_onderwerp', 150 ),
				'mail_kop'       => $tekst( 'mail_kop', 80 ),
				'mail_tekst'     => isset( $ruw['mail_tekst'] )
					? mb_substr( sanitize_textarea_field( wp_unslash( $ruw['mail_tekst'] ) ), 0, 1500 )
					: $oud['mail_tekst'],
				'mail_knop'      => $tekst( 'mail_knop', 40 ),
				'mail_sjabloon'  => isset( $sjablonen[ $sjabloon ] ) ? $sjabloon : 'rustig',
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * De gegevenslaag
	 * ------------------------------------------------------------------- */

	/**
	 * Tabelnaam.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_stock_requests';
	}

	/**
	 * De sleutel waarmee product, variatie en adres samen uniek zijn.
	 *
	 * @param int    $product_id   Product.
	 * @param int    $variation_id Variatie, of 0.
	 * @param string $email        E-mailadres.
	 * @return string
	 */
	private static function rij_sleutel( $product_id, $variation_id, $email ) {
		return md5( (int) $product_id . '|' . (int) $variation_id . '|' . strtolower( trim( (string) $email ) ) );
	}

	/**
	 * Eén aanvraag ophalen.
	 *
	 * @param int $id Rij-id.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$tabel = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabel} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Iemand op de wachtlijst zetten.
	 *
	 * @param int    $product_id   Product.
	 * @param int    $variation_id Variatie, of 0.
	 * @param string $email        E-mailadres.
	 * @param string $naam         Naam, mag leeg.
	 * @return array|WP_Error { id, nieuw }
	 */
	public static function aanmelden( $product_id, $variation_id, $email, $naam = '' ) {
		global $wpdb;

		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$email        = strtolower( trim( (string) $email ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'wsfm_voorraad_email', __( 'Dat lijkt geen geldig e-mailadres.', 'ws-flow-mailer' ) );
		}
		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) || ! wc_get_product( $product_id ) ) {
			return new WP_Error( 'wsfm_voorraad_product', __( 'Dit product bestaat niet meer.', 'ws-flow-mailer' ) );
		}

		$tabel   = self::table();
		$sleutel = self::rij_sleutel( $product_id, $variation_id, $email );

		$bestaand = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabel} WHERE sleutel = %s", $sleutel ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $bestaand ) {
			/* Al aangemeld. Staat hij op gestopt of op verstuurd, dan wil deze
			   bezoeker het blijkbaar opnieuw weten en zetten we hem terug op
			   wachten; stond hij al te wachten, dan gebeurt er niets. */
			if ( 'wacht' !== $bestaand->status && 'in_wachtrij' !== $bestaand->status ) {
				$wpdb->update(
					$tabel,
					array(
						'status'      => 'wacht',
						'naam'        => $naam ? sanitize_text_field( $naam ) : $bestaand->naam,
						'notified_at' => null,
						'created_at'  => current_time( 'mysql' ),
					),
					array( 'id' => (int) $bestaand->id )
				);
			}

			return array(
				'id'    => (int) $bestaand->id,
				'nieuw' => false,
			);
		}

		/* Een rem per adres. Zonder dit kan één iemand met een scriptje duizend
		   regels in de tabel van een klantwinkel zetten. */
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabel} WHERE email = %s AND status IN ('wacht','in_wachtrij')", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $open >= self::MAX_PER_ADRES ) {
			return new WP_Error( 'wsfm_voorraad_veel', __( 'Je staat al op heel veel wachtlijsten. Wacht even tot je daar bericht over hebt.', 'ws-flow-mailer' ) );
		}

		$wpdb->insert(
			$tabel,
			array(
				'sleutel'      => $sleutel,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'email'        => $email,
				'naam'         => sanitize_text_field( $naam ),
				'status'       => 'wacht',
				'bron'         => 'product',
				'created_at'   => current_time( 'mysql' ),
			)
		);

		return array(
			'id'    => (int) $wpdb->insert_id,
			'nieuw' => true,
		);
	}

	/**
	 * Hoeveel mensen er op dit product wachten.
	 *
	 * @param int $product_id Product.
	 * @return int
	 */
	public static function wachtenden( $product_id ) {
		global $wpdb;

		$tabel = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabel} WHERE product_id = %d AND status = 'wacht'", (int) $product_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * De wachtlijst per product, voor het beheerscherm.
	 *
	 * @param int $limiet Hoogste aantal producten.
	 * @return object[] Rijen met product_id, aantal, oudste.
	 */
	public static function wachtlijst( $limiet = 50 ) {
		global $wpdb;

		$tabel = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT product_id, COUNT(*) AS aantal, MIN(created_at) AS oudste FROM {$tabel} WHERE status IN ('wacht','in_wachtrij') GROUP BY product_id ORDER BY aantal DESC, oudste ASC LIMIT %d", (int) $limiet ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * De laatst verstuurde berichten, voor het beheerscherm.
	 *
	 * @param int $limiet Hoogste aantal regels.
	 * @return object[]
	 */
	public static function laatste( $limiet = 15 ) {
		global $wpdb;

		$tabel = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabel} WHERE status = 'verstuurd' ORDER BY notified_at DESC LIMIT %d", (int) $limiet ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Hoeveel er in totaal verstuurd zijn, en hoeveel er wachten.
	 *
	 * @return array { wacht, verstuurd }
	 */
	public static function aantallen() {
		global $wpdb;

		$tabel = self::table();
		$rijen = (array) $wpdb->get_results( "SELECT status, COUNT(*) AS aantal FROM {$tabel} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$uit = array(
			'wacht'     => 0,
			'verstuurd' => 0,
		);

		foreach ( $rijen as $rij ) {
			if ( 'wacht' === $rij->status || 'in_wachtrij' === $rij->status ) {
				$uit['wacht'] += (int) $rij->aantal;
			}
			if ( 'verstuurd' === $rij->status ) {
				$uit['verstuurd'] += (int) $rij->aantal;
			}
		}

		return $uit;
	}

	/* ---------------------------------------------------------------------
	 * De voorkant
	 * ------------------------------------------------------------------- */

	/**
	 * Hoort er op dit product een vakje te komen?
	 *
	 * Bij een variabel product is het antwoord altijd ja, ook als de winkel nog
	 * voorraad heeft. is_in_stock() zegt daar namelijk alleen dat er ERGENS nog
	 * een maat is, en dat is geen antwoord op de vraag of de maat die deze
	 * bezoeker wil er is. Welke maat hij kiest gebeurt in de browser, dus staat
	 * het vakje er verborgen in en zet het script het aan.
	 *
	 * @param WC_Product|null $product Product.
	 * @return bool
	 */
	private static function hier_tonen( $product ) {
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		if ( $product->is_type( 'variable' ) ) {
			return true;
		}

		return ! $product->is_in_stock();
	}

	/**
	 * De opmaak en het script klaarzetten.
	 *
	 * Alleen op een productpagina waar het vakje ook echt kan verschijnen. Een
	 * stylesheet en een script op elke productpagina van de winkel zetten voor
	 * iets dat bij een product dat gewoon te koop is nooit in beeld komt, is
	 * laadtijd weggeven die de winkelier nodig heeft om te verkopen.
	 */
	public static function scripts() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		/* De globale $product staat er op dit moment nog niet; die wordt pas in
		   de lus gezet. Het product van deze pagina is wel bekend. */
		if ( ! self::hier_tonen( wc_get_product( get_queried_object_id() ) ) ) {
			return;
		}

		$i = self::instellingen();

		wp_enqueue_style( 'wsfm-voorraad', WSFM_PLUGIN_URL . 'assets/voorraad.css', array(), WSFM_VERSION );
		wp_enqueue_script( 'wsfm-voorraad', WSFM_PLUGIN_URL . 'assets/voorraad.js', array(), WSFM_VERSION, true );
		wp_localize_script(
			'wsfm-voorraad',
			'wsfmVoorraad',
			array(
				'url'    => rest_url( self::REST_NAMESPACE . '/voorraad-melding' ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'bezig'  => __( 'Momentje...', 'ws-flow-mailer' ),
				'gelukt' => (string) $i['gelukt'],
				'fout'   => __( 'Er ging iets mis. Probeer het zo nog eens.', 'ws-flow-mailer' ),
			)
		);
	}

	/**
	 * Het vakje onder de bestelknop.
	 */
	public static function toon_formulier() {
		global $product;

		if ( ! self::hier_tonen( $product ) ) {
			return;
		}

		echo self::html( self::instellingen(), (int) $product->get_id(), $product->is_type( 'variable' ) ); // phpcs:ignore WordPress.Security.EscapingOutput -- opgebouwd met esc_* hieronder.
	}

	/**
	 * De opmaak van het vakje.
	 *
	 * @param array $i          Instellingen.
	 * @param int   $product_id Product.
	 * @param bool  $variabel   Variabel product; dan begint hij verborgen en
	 *                          zet het script hem aan bij de gekozen maat.
	 * @return string
	 */
	public static function html( array $i, $product_id = 0, $variabel = false ) {
		$verborgen = $variabel ? ' hidden' : '';

		$uit = '<div class="wsfm-voorraad" data-product="' . (int) $product_id . '"'
			. ' data-variabel="' . ( $variabel ? '1' : '0' ) . '"' . $verborgen . '>';

		if ( '' !== trim( (string) $i['kop'] ) ) {
			$uit .= '<p class="wsfm-voorraad-kop">' . esc_html( $i['kop'] ) . '</p>';
		}
		if ( '' !== trim( (string) $i['tekst'] ) ) {
			$uit .= '<p class="wsfm-voorraad-tekst">' . esc_html( $i['tekst'] ) . '</p>';
		}

		$uit .= '<form class="wsfm-voorraad-form">'
			. '<input type="hidden" class="wsfm-voorraad-variatie" value="0">'
			. '<label class="screen-reader-text" for="wsfm-voorraad-email-' . (int) $product_id . '">'
			. esc_html( $i['plaatshouder'] ) . '</label>'
			. '<div class="wsfm-voorraad-regel">'
			. '<input type="email" id="wsfm-voorraad-email-' . (int) $product_id . '" class="wsfm-voorraad-email"'
			. ' required autocomplete="email" placeholder="' . esc_attr( $i['plaatshouder'] ) . '">'
			. '<button type="submit" class="wsfm-voorraad-knop">' . esc_html( $i['knop'] ) . '</button>'
			. '</div>';

		if ( ! empty( $i['ook_nieuwsbrief'] ) && '' !== trim( (string) $i['nieuwsbrief_label'] ) ) {
			$uit .= '<label class="wsfm-voorraad-vinkje">'
				. '<input type="checkbox" class="wsfm-voorraad-nieuwsbrief" value="1"> '
				. esc_html( $i['nieuwsbrief_label'] )
				. '</label>';
		}

		$uit .= '</form>'
			. '<p class="wsfm-voorraad-melding" role="status"></p>';

		if ( '' !== trim( (string) $i['kleine_letters'] ) ) {
			$uit .= '<p class="wsfm-voorraad-klein">' . esc_html( $i['kleine_letters'] ) . '</p>';
		}

		return $uit . '</div>';
	}

	/* ---------------------------------------------------------------------
	 * De aanmelding
	 * ------------------------------------------------------------------- */

	/**
	 * De route waar het formulier naartoe schrijft.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/voorraad-melding',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'melding_aanvragen' ),
				'permission_callback' => '__return_true', // Openbaar met opzet; het is een aanmeldformulier.
				'args'                => array(
					'email'      => array( 'required' => true ),
					'product_id' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * Iemand vraagt een bericht aan.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public static function melding_aanvragen( $request ) {
		$i = self::instellingen();

		if ( empty( $i['aan'] ) || ! function_exists( 'wc_get_product' ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'melding' => __( 'Dat kan op dit moment niet.', 'ws-flow-mailer' ),
				),
				403
			);
		}

		$email        = sanitize_email( (string) $request->get_param( 'email' ) );
		$product_id   = (int) $request->get_param( 'product_id' );
		$variation_id = (int) $request->get_param( 'variation_id' );

		if ( ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'melding' => __( 'Dat lijkt geen geldig e-mailadres.', 'ws-flow-mailer' ),
				),
				400
			);
		}

		/* Alleen voor iets dat echt uitverkocht is. Anders kan deze route
		   gebruikt worden om een adressenlijst op te bouwen op producten die
		   gewoon te koop zijn, en dan staat er straks een wachtlijst van
		   duizend man op een product dat nooit een mail oplevert. */
		$object = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $object ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'melding' => __( 'Dit product bestaat niet meer.', 'ws-flow-mailer' ),
				),
				404
			);
		}
		if ( $object->is_in_stock() ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'melding' => __( 'Goed nieuws: dit product is er weer. Verversen en bestellen kan meteen.', 'ws-flow-mailer' ),
				),
				409
			);
		}

		/* Een variatie hangt onder zijn eigen product. Wie een ander product_id
		   meestuurt dan waar de variatie bij hoort, krijgt hier het echte. */
		if ( $variation_id && $object->get_parent_id() ) {
			$product_id = (int) $object->get_parent_id();
		}

		$uit = self::aanmelden( $product_id, $variation_id, $email, '' );

		if ( is_wp_error( $uit ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'melding' => $uit->get_error_message(),
				),
				400
			);
		}

		/* Het vinkje voor de nieuwsbrief is een losse keuze met een eigen grond,
		   dus ook een eigen toestemmingstekst. Wie zich ooit heeft afgemeld komt
		   er niet stilletjes weer in; dat is dezelfde regel als bij de popup. */
		if ( ! empty( $i['ook_nieuwsbrief'] ) && $request->get_param( 'nieuwsbrief' ) && class_exists( 'WSFM_Subscribers' ) ) {
			if ( ! WSFM_Suppression::is_suppressed( $email ) ) {
				WSFM_Subscribers::add(
					$email,
					'voorraad',
					'',
					'',
					isset( $i['lijst_id'] ) ? $i['lijst_id'] : 0,
					(string) $i['nieuwsbrief_label']
				);
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'melding' => (string) $i['gelukt'],
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Voorraad in de gaten houden
	 * ------------------------------------------------------------------- */

	/**
	 * Haak: het aantal van een product of variatie is gewijzigd.
	 *
	 * @param WC_Product $product Product of variatie.
	 */
	public static function bij_voorraad( $product ) {
		self::misschien_inplannen( $product );
	}

	/**
	 * Haak: de voorraadstand (op voorraad / uitverkocht) is gezet.
	 *
	 * @param int        $id      Product- of variatie-id.
	 * @param string     $stand   Nieuwe stand.
	 * @param WC_Product $product Product, als WooCommerce het meegeeft.
	 */
	public static function bij_voorraadstand( $id, $stand, $product = null ) {
		if ( 'instock' !== $stand && 'onbackorder' !== $stand ) {
			return;
		}

		self::misschien_inplannen( $product ? $product : $id );
	}

	/**
	 * Haak: het product is in het beheerscherm opgeslagen.
	 *
	 * Het vangnet. Wie de voorraad met de hand bijzet en opslaat raakt niet
	 * altijd een van de vier andere haken, en dan zou de wachtlijst blijven
	 * staan zonder dat iemand ziet waarom.
	 *
	 * @param int        $id      Product-id.
	 * @param WC_Product $product Product.
	 */
	public static function bij_opslaan( $id, $product = null ) {
		self::misschien_inplannen( $product ? $product : $id );
	}

	/**
	 * Een taak inplannen als dit product weer te koop is en er mensen wachten.
	 *
	 * @param WC_Product|int $product Product, variatie of id.
	 */
	private static function misschien_inplannen( $product ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		$object = is_object( $product ) ? $product : wc_get_product( (int) $product );
		if ( ! $object || ! is_a( $object, 'WC_Product' ) ) {
			return;
		}

		/* Bij een variatie gaat het om de wachtlijst van het hoofdproduct: daar
		   staan de aanvragen onder, ook die voor deze ene maat. */
		$product_id = $object->get_parent_id() ? (int) $object->get_parent_id() : (int) $object->get_id();
		if ( $product_id < 1 ) {
			return;
		}

		if ( ! $object->is_in_stock() ) {
			return;
		}

		/* Eerst kijken of er iemand wacht. Dit is één tellende vraag op een
		   geïndexeerde kolom en staat met opzet vóór al het andere werk: deze
		   haken gaan bij een voorraadsynchronisatie honderden keren af, en dan
		   hoort er bij een product zonder wachtlijst niets te gebeuren. */
		if ( self::wachtenden( $product_id ) < 1 ) {
			return;
		}

		if ( as_next_scheduled_action( self::HOOK_MELDEN, array( $product_id ), WSFM_Flow_Engine::AS_GROUP ) ) {
			return; // Staat al klaar; niet nog een keer.
		}

		as_schedule_single_action( time() + self::WACHT, self::HOOK_MELDEN, array( $product_id ), WSFM_Flow_Engine::AS_GROUP );
	}

	/**
	 * De achtergrondtaak: zet de mails voor één product in de wachtrij.
	 *
	 * @param int $product_id Product.
	 */
	public static function verwerk( $product_id ) {
		global $wpdb;

		$product_id = (int) $product_id;
		if ( $product_id < 1 || ! function_exists( 'wc_get_product' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );

		/* Weer weg in die minuut, of het product is verwijderd: niets doen en de
		   wachtlijst laten staan. Ze krijgen bericht als hij echt terug is. */
		if ( ! $product || ! $product->is_in_stock() ) {
			return;
		}

		$tabel  = self::table();
		$rijen  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$tabel} WHERE product_id = %d AND status = 'wacht' ORDER BY id ASC LIMIT %d", $product_id, self::PER_RONDE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$gedaan = 0;

		foreach ( $rijen as $rij ) {
			/* Wacht iemand op één maat, dan hoort hij geen mail te krijgen omdat
			   een ANDERE maat terug is. Dat is de meest gemaakte fout bij dit
			   soort berichten en precies waarom mensen zich eraan ergeren. */
			if ( (int) $rij->variation_id > 0 ) {
				$variatie = wc_get_product( (int) $rij->variation_id );
				if ( ! $variatie || ! $variatie->is_in_stock() ) {
					continue;
				}
			}

			/* Claimen voordat we hem in de wachtrij zetten. Zou de taak twee keer
			   draaien, dan pakt de tweede ronde deze rij niet meer op en krijgt
			   niemand dezelfde mail dubbel. */
			$geclaimd = $wpdb->query( $wpdb->prepare( "UPDATE {$tabel} SET status = 'in_wachtrij' WHERE id = %d AND status = 'wacht'", (int) $rij->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 !== (int) $geclaimd ) {
				continue;
			}

			if ( WSFM_Queue::enqueue_stock_request( $rij ) ) {
				$gedaan++;
			} else {
				// Niet in de wachtrij gekomen: terugzetten, dan proberen we het later opnieuw.
				$wpdb->update(
					$tabel,
					array(
						'status' => 'wacht',
					),
					array( 'id' => (int) $rij->id )
				);
			}
		}

		/* Zijn er meer wachtenden dan één ronde aankan, dan gaat de volgende
		 * portie over een minuut. Zo blijft het werk in stukken die een webshop
		 * aankan.
		 *
		 * `$gedaan` moet daarvoor boven nul staan, en dat is geen detail. Zonder
		 * die voorwaarde loopt dit eindeloos rond: bij een variabel product waar
		 * tweehonderd mensen op een maat wachten die NIET terug is, wordt elke
		 * rij overgeslagen, blijft het aantal wachtenden gelijk, en plant de taak
		 * zichzelf elke minuut opnieuw in. Dat is een taak die voor altijd op de
		 * webshop van een klant blijft draaien zonder ooit een mail te versturen.
		 */
		if ( $gedaan > 0 && count( $rijen ) >= self::PER_RONDE && self::wachtenden( $product_id ) > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_MELDEN, array( $product_id ), WSFM_Flow_Engine::AS_GROUP );
		}
	}

	/**
	 * Vastleggen dat het bericht de deur uit is.
	 *
	 * @param int $id Rij-id.
	 */
	public static function afgehandeld( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status'      => 'verstuurd',
				'notified_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Terugzetten op wachten, bijvoorbeeld omdat het product tussendoor
	 * alweer uitverkocht was toen de mail eruit zou gaan.
	 *
	 * @param int $id Rij-id.
	 */
	public static function terug_in_de_wacht( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table(),
			array(
				'status' => 'wacht',
			),
			array( 'id' => (int) $id )
		);
	}

	/* ---------------------------------------------------------------------
	 * De mail
	 * ------------------------------------------------------------------- */

	/**
	 * De mail voor één aanvraag.
	 *
	 * @param object $rij     Rij uit wsfm_stock_requests.
	 * @param array  $context Merge-gegevens, met in elk geval de stoplink.
	 * @return array|WP_Error { subject, html_body }
	 */
	public static function mail_html( $rij, array $context = array() ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'wsfm_voorraad_wc', __( 'WooCommerce is niet actief.', 'ws-flow-mailer' ) );
		}

		$variatie = (int) $rij->variation_id ? wc_get_product( (int) $rij->variation_id ) : null;
		$product  = wc_get_product( (int) $rij->product_id );

		if ( ! $product ) {
			return new WP_Error( 'wsfm_voorraad_weg', __( 'Dit product bestaat niet meer.', 'ws-flow-mailer' ) );
		}

		$i = self::instellingen();

		/* De naam van de variatie als die er is: "Zomerjurk - maat 38" zegt de
		   ontvanger wat hij wilde weten, "Zomerjurk" laat hem zoeken. */
		$naam = $variatie ? $variatie->get_name() : $product->get_name();
		$link = get_permalink( (int) $rij->product_id );

		if ( $variatie && method_exists( $variatie, 'add_to_cart_url' ) ) {
			$link = $variatie->add_to_cart_url();
		}

		$vervang = array( '{product_name}' => $naam );

		$blokken = array(
			array(
				'soort' => 'tekst',
				'kop'   => strtr( (string) $i['mail_kop'], $vervang ),
				'tekst' => strtr( (string) $i['mail_tekst'], $vervang ),
			),
			array(
				'soort'     => 'producten',
				'producten' => array( (int) $rij->product_id ),
			),
		);

		if ( '' !== trim( (string) $i['mail_knop'] ) ) {
			$blokken[] = array(
				'soort'    => 'tekst',
				'tekst'    => '',
				'knop'     => (string) $i['mail_knop'],
				'knop_url' => $link,
			);
		}

		$brief = (object) array(
			'subject'  => strtr( (string) $i['mail_onderwerp'], $vervang ),
			'template' => $i['mail_sjabloon'],
			'blocks'   => $blokken,
			/* De voetregel van een nieuwsbrief ("je krijgt deze omdat je bij ons
			   besteld hebt") is hier niet waar: deze ontvanger heeft misschien
			   nooit iets gekocht, hij heeft om dit ene bericht gevraagd. Dat
			   hoort er dan ook te staan, want anders leest een terechte mail als
			   ongevraagde post. */
			'voet_regel' => __( 'Je krijgt deze e-mail omdat je bericht wilde zodra dit product weer op voorraad zou zijn.', 'ws-flow-mailer' ),
		);

		return WSFM_Template_Engine::render_string(
			$brief->subject,
			WSFM_Newsletter_Render::render( $brief ),
			$context
		);
	}

	/**
	 * De link waarmee iemand van al zijn wachtlijsten af kan.
	 *
	 * WAAROM DIT GEEN GEWONE AFMELDLINK IS
	 * De afmeldlink van de nieuwsbrief zet een adres op de afmeldlijst, en dan
	 * krijgt diegene ook geen bestelbevestiging-achtige flowmail meer. Iemand die
	 * alleen van een wachtlijst af wil, hoort niet in één klik alle post van deze
	 * winkel kwijt te zijn. Deze link doet precies wat er staat en niets meer.
	 *
	 * @param object $rij Rij uit wsfm_stock_requests.
	 * @return string
	 */
	public static function stoplink( $rij ) {
		if ( ! is_object( $rij ) || empty( $rij->id ) ) {
			return home_url( '/' );
		}

		return add_query_arg(
			array(
				self::ARG_STOP => (int) $rij->id,
				self::ARG_KEY  => self::stopsleutel( $rij ),
			),
			home_url( '/' )
		);
	}

	/**
	 * De sleutel die bij deze rij hoort.
	 *
	 * Zonder sleutel zou ?wsfm-voorraad-stop=41 de wachtlijst van een ander
	 * kunnen opzeggen. Het aanmaakmoment zit erin zodat een link niet blijft
	 * werken als een rij ooit wordt opgeruimd en hetzelfde id later opnieuw
	 * wordt uitgedeeld.
	 *
	 * @param object $rij Rij.
	 * @return string
	 */
	private static function stopsleutel( $rij ) {
		$basis = 'wsfm-voorraad|' . (int) $rij->id . '|' . strtolower( (string) $rij->email ) . '|' . (string) ( isset( $rij->created_at ) ? $rij->created_at : '' );

		return substr( wp_hash( $basis ), 0, 20 );
	}

	/**
	 * Staat er een stoplink in de URL? Dan de wachtlijsten van dit adres af.
	 */
	public static function misschien_stoppen() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- eigen sleutel in de link; een nonce leeft twee dagen en deze link langer.
		if ( is_admin() || empty( $_GET[ self::ARG_STOP ] ) || empty( $_GET[ self::ARG_KEY ] ) ) {
			return;
		}

		$rij_id  = (int) $_GET[ self::ARG_STOP ];
		$sleutel = sanitize_text_field( wp_unslash( $_GET[ self::ARG_KEY ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$rij = self::get( $rij_id );

		/* Geen rij of een sleutel die niet klopt: gewoon naar de voorpagina,
		   zonder uitleg over wat er niet klopte. hash_equals zodat de
		   vergelijking niets verklapt over hoe ver iemand met raden was. */
		if ( ! $rij || ! hash_equals( self::stopsleutel( $rij ), $sleutel ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		global $wpdb;
		$tabel = self::table();

		$aantal = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$tabel} SET status = 'gestopt' WHERE email = %s AND status IN ('wacht','in_wachtrij')", strtolower( (string) $rij->email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice(
				$aantal > 0
					? __( 'Gelukt, je krijgt geen voorraadberichten meer van ons.', 'ws-flow-mailer' )
					: __( 'Je stond al niet meer op een wachtlijst.', 'ws-flow-mailer' ),
				'success'
			);
		}

		$naar = get_permalink( (int) $rij->product_id );

		wp_safe_redirect( $naar ? $naar : home_url( '/' ) );
		exit;
	}
}
