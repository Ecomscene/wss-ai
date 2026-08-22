<?php
/**
 * Het aanmeldvinkje op de afrekenpagina.
 *
 * WAAROM DIT DE NETSTE BRON VOOR JE LIJST IS
 * Iemand die hier een vinkje zet, vraagt er zelf om. Dat is een andere en veel
 * stevigere grond dan "hij heeft ooit iets gekocht", en het levert een lijst op
 * die opent en klikt in plaats van op spam drukt.
 *
 * HET STAAT NOOIT VOORGEVINKT, EN DAT IS GEEN INSTELLING
 * Een voorgevinkt vakje is geen toestemming; dat is de klant iets laten doen
 * zonder dat hij het doorheeft. Daarom kun je dat hier niet aanzetten. Wat wel
 * instelbaar is: of het vinkje er staat, wat er naast staat, en op welke lijst
 * iemand terechtkomt.
 *
 * WAT ER BEWAARD WORDT
 * Niet alleen dat er een vinkje stond, maar ook de tekst die er op dat moment
 * naast stond en wanneer het gebeurde. Toestemming moet je kunnen aantonen, en
 * "hij heeft ooit ja gezegd" is geen bewijs als je niet weet waar hij ja op zei.
 *
 * DE TWEE AFREKENPAGINA'S
 * WooCommerce heeft er twee: de klassieke, waar dit met een gewone hook tussen
 * te zetten is, en de blokkenversie, waar die hooks helemaal niets doen en het
 * veld via de velden-API geregistreerd moet worden. Welke een winkel draait
 * weten we hier niet, dus wordt allebei aangehaakt. De shop die er maar één
 * heeft, merkt niets van de andere.
 *
 * ALLES HIER IS BREEKBAAR EN DUS INGEPAKT
 * Dit draait op de afrekenpagina. Wat hier stukgaat, kost een bestelling en niet
 * een blokje opmaak. Elke haak vangt dus zijn eigen fouten af: liever geen
 * vinkje dan geen afrekenpagina.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Afrekenen {

	const OPTIE = 'wsfm_afrekenen';

	/** De sleutel waaronder het veld in een bestelling terechtkomt. */
	const VELD = '_wsfm_aanmelding';

	/** De naam waaronder het veld bij de blokkencheckout bekendstaat. */
	const BLOK_VELD = 'wsfm/nieuwsbrief';

	/**
	 * De standaardinstellingen.
	 *
	 * Uit, want dit zet iets op de afrekenpagina van een winkel. Dat lever je
	 * niet mee omdat het toevallig in dezelfde plugin zit.
	 *
	 * @return array
	 */
	public static function standaard() {
		return array(
			'aan'      => 0,
			'label'    => __( 'Houd me op de hoogte van nieuwe producten en aanbiedingen', 'ws-flow-mailer' ),
			'lijst_id' => 0,
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
	 * Staat het vinkje aan?
	 *
	 * @return bool
	 */
	public static function aan() {
		$i = self::instellingen();
		return ! empty( $i['aan'] ) && '' !== trim( (string) $i['label'] );
	}

	/**
	 * Opslaan vanuit het beheerformulier.
	 *
	 * @param array $ruw $_POST.
	 * @return void
	 */
	public static function opslaan( array $ruw ) {
		$label = isset( $ruw['afrekenen_label'] )
			? mb_substr( sanitize_text_field( wp_unslash( $ruw['afrekenen_label'] ) ), 0, 200 )
			: self::standaard()['label'];

		update_option(
			self::OPTIE,
			array(
				'aan'      => empty( $ruw['afrekenen_aan'] ) ? 0 : 1,
				'label'    => $label,
				'lijst_id' => class_exists( 'WSFM_Lijsten' )
					? WSFM_Lijsten::geldig( isset( $ruw['afrekenen_lijst'] ) ? $ruw['afrekenen_lijst'] : 0 )
					: 0,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Aanhaken
	 * ------------------------------------------------------------------- */

	public static function init() {
		if ( ! self::aan() ) {
			return;
		}

		/* De klassieke afrekenpagina. Vlak boven de bestelknop: daar staat de
		   klant toch al te lezen wat hij accepteert. */
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'toon_klassiek' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'na_bestelling_klassiek' ), 20, 1 );

		/* De blokkenversie. Die kent de hooks hierboven niet en wil een
		   geregistreerd veld. Bestaat die functie niet, dan draait de winkel een
		   oudere WooCommerce en is er niets te registreren. */
		if ( function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			add_action( 'woocommerce_init', array( __CLASS__, 'registreer_blokveld' ) );
		}
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'na_bestelling_blok' ), 20, 1 );
	}

	/**
	 * Het vinkje op de klassieke afrekenpagina.
	 *
	 * @return void
	 */
	public static function toon_klassiek() {
		try {
			$i = self::instellingen();

			woocommerce_form_field(
				self::VELD,
				array(
					'type'    => 'checkbox',
					'class'   => array( 'form-row', 'wsfm-aanmelding' ),
					'label'   => $i['label'],
					/* Nooit voorgevinkt. Zie de uitleg bovenaan. */
					'default' => 0,
				),
				0
			);
		} catch ( Exception $e ) {
			/* Geen vinkje is vervelend; een afrekenpagina die niet laadt is een
			   misgelopen bestelling. */
			return;
		}
	}

	/**
	 * Het veld registreren voor de blokkencheckout.
	 *
	 * @return void
	 */
	public static function registreer_blokveld() {
		try {
			$i = self::instellingen();

			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::BLOK_VELD,
					'label'    => $i['label'],
					'location' => 'contact',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( Exception $e ) {
			return;
		}
	}

	/* ---------------------------------------------------------------------
	 * Na de bestelling
	 * ------------------------------------------------------------------- */

	/**
	 * Klassieke checkout: het vinkje staat gewoon in het formulier.
	 *
	 * @param int $order_id Bestelling-id.
	 * @return void
	 */
	public static function na_bestelling_klassiek( $order_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce heeft het afrekenformulier zelf al gecontroleerd.
		$gevinkt = ! empty( $_POST[ self::VELD ] );
		self::verwerk( $order_id, $gevinkt );
	}

	/**
	 * Blokkencheckout: het veld hangt aan de bestelling.
	 *
	 * @param mixed $order Bestelling.
	 * @return void
	 */
	public static function na_bestelling_blok( $order ) {
		try {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				return;
			}

			/* WooCommerce bewaart een geregistreerd veld onder zijn eigen naam met
			   een voorvoegsel. Niet zelf uitrekenen wat dat voorvoegsel is: dat
			   verschilt per versie, dus we vragen het op als het kan en vallen
			   anders terug op de meta zoals hij er staat. */
			$waarde = $order->get_meta( '_wc_other/' . self::BLOK_VELD );
			if ( '' === $waarde || null === $waarde ) {
				$waarde = $order->get_meta( self::BLOK_VELD );
			}

			$gevinkt = ! empty( $waarde ) && 'false' !== $waarde && '0' !== (string) $waarde;
			self::verwerk( $order->get_id(), $gevinkt );
		} catch ( Exception $e ) {
			return;
		}
	}

	/**
	 * Iemand op de lijst zetten na een bestelling met een vinkje.
	 *
	 * @param int  $order_id Bestelling-id.
	 * @param bool $gevinkt  Stond het vinkje aan?
	 * @return void
	 */
	private static function verwerk( $order_id, $gevinkt ) {
		try {
			if ( ! $gevinkt || ! function_exists( 'wc_get_order' ) ) {
				return;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$email = sanitize_email( (string) $order->get_billing_email() );
			if ( ! is_email( $email ) ) {
				return;
			}

			/* Wie zich ooit heeft afgemeld schrijven we niet stilletjes weer in,
			   ook niet via een vinkje bij het afrekenen. Dat is dezelfde regel als
			   bij de popup en bij de import. */
			if ( class_exists( 'WSFM_Suppression' ) && WSFM_Suppression::is_suppressed( $email ) ) {
				return;
			}

			$i = self::instellingen();

			WSFM_Subscribers::add(
				$email,
				'afrekenen',
				'',
				(string) $order->get_billing_first_name(),
				$i['lijst_id'],
				$i['label']
			);

			/* Ook aan de bestelling hangen. Vraagt een klant later waarom hij post
			   krijgt, dan staat het antwoord bij de bestelling waar het gebeurde en
			   hoef je niet in een aparte lijst te gaan zoeken. */
			$order->update_meta_data( self::VELD, $i['label'] );
			$order->save();
		} catch ( Exception $e ) {
			/* Een mislukte aanmelding mag nooit een bestelling in de weg zitten.
			   De klant heeft betaald; dat is wat telt. */
			return;
		}
	}
}
