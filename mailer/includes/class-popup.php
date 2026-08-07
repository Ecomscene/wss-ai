<?php
/**
 * De inschrijfpopup, met kortingscode.
 *
 * WAT HIER GEBEURT
 * Bezoeker typt zijn adres, krijgt een eigen kortingscode terug, en die code
 * staat meteen op het scherm én in een mail. Dat scherm is geen luxe: e-mail is
 * traag en belandt in spamfolders, en een korting die je pas over tien minuten
 * ziet werkt niet meer op het moment dat je aan het winkelen bent.
 *
 * WAAROM ELKE INSCHRIJVING ZIJN EIGEN CODE KRIJGT
 * Eén vaste code beland binnen een week op een kortingscodesite, en dan geeft de
 * winkel tien procent weg aan iedereen in plaats van aan nieuwe inschrijvers.
 * Een code per adres die één keer te gebruiken is, kan dat niet. Wie het toch
 * liever met één vaste code doet kan dat instellen; het staat er dan bij wat dat
 * betekent.
 *
 * WAT DE POPUP NIET DOET
 * Hij verschijnt niet op de winkelwagen en de afrekenpagina. Iemand die zijn
 * gegevens aan het invullen is, moet je niet onderbreken met een formulier: dat
 * kost meer omzet dan de inschrijving oplevert.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Popup {

	const OPTIE          = 'wsfm_popup';
	const REST_NAMESPACE = 'wsfm/v1';

	/** Hoe lang de browser onthoudt dat hij hem al gezien heeft. */
	const KOEKJE = 'wsfm_popup_gezien';

	/**
	 * Aanhaken. Alleen als de popup aanstaat wordt er iets op de winkel gezet.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		if ( ! self::aan() ) {
			return;
		}

		/* De stylesheet en het script gaan op wp_enqueue_scripts en niet in de
		   voettekst. WordPress print de footer-scripts op wp_footer prioriteit 20;
		   wie daarna nog iets in de wachtrij zet, zet het in een wachtrij die al
		   leeg is gemaakt. De popup stond dan wel in de pagina maar bleef verborgen,
		   want er was geen opmaak en geen JavaScript om hem te openen. */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		/* Prioriteit 5: de opmaak moet in de pagina staan voordat WordPress op
		   prioriteit 20 de footer-scripts afdrukt. Het script wacht ook zelf op
		   het document, dus het is dubbelop, maar een volgorde die klopt is
		   beter dan een volgorde die goedgemaakt moet worden. */
		add_action( 'wp_footer', array( __CLASS__, 'toon' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * Instellingen
	 * ------------------------------------------------------------------- */

	/**
	 * De standaardinstellingen: precies de popup die we meestal maken.
	 *
	 * @return array
	 */
	public static function standaard() {
		return array(
			'aan'             => 0,
			'kop'             => __( '10% korting', 'ws-flow-mailer' ),
			'tekst'           => __( 'Schrijf je in voor de nieuwsbrief en ontvang korting op je eerste bestelling', 'ws-flow-mailer' ),
			'knop'            => __( 'Inschrijven', 'ws-flow-mailer' ),
			'plaatshouder'    => __( 'E-mailadres', 'ws-flow-mailer' ),
			'kleine_letters'  => __( 'Je kunt je op elk moment weer afmelden.', 'ws-flow-mailer' ),
			'gelukt_kop'      => __( 'Gelukt!', 'ws-flow-mailer' ),
			'gelukt_tekst'    => __( 'Dit is je kortingscode. Hij staat ook in je mail.', 'ws-flow-mailer' ),
			'afbeelding'      => 0,

			'kleur_achtergrond' => '#ffffff',
			'kleur_tekst'       => '#111111',
			'kleur_knop'        => '#c08b7d',
			'kleur_knoptekst'   => '#ffffff',
			'rond'              => 0,
			'lettertype'        => 'website',

			'na_seconden'     => 5,
			'dagen_verbergen' => 14,
			'niet_bij_afrekenen' => 1,

			/* De korting. Percentage is wat we in de praktijk altijd doen, maar
			   een vast bedrag moet kunnen: bij dure producten is tien procent
			   soms meer weggeven dan de bedoeling is. */
			'korting_soort'   => 'percent',
			'korting_waarde'  => 10,
			'minimaal'        => 0,
			'geldig_dagen'    => 30,
			'unieke_code'     => 1,
			'vaste_code'      => '',
			'code_voorvoegsel' => 'WELKOM',

			'mail_onderwerp'  => __( 'Hier is je kortingscode', 'ws-flow-mailer' ),
			'mail_kop'        => __( 'Welkom!', 'ws-flow-mailer' ),
			'mail_tekst'      => __( "Leuk dat je erbij bent. Met de code hieronder krijg je korting op je eerste bestelling.\n\nVeel plezier met kijken!", 'ws-flow-mailer' ),
			'mail_sjabloon'   => 'rustig',
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

		$kleur = function ( $sleutel ) use ( $ruw, $oud ) {
			$waarde = isset( $ruw[ $sleutel ] ) ? sanitize_hex_color( wp_unslash( $ruw[ $sleutel ] ) ) : '';
			return $waarde ? $waarde : $oud[ $sleutel ];
		};

		$getal = function ( $sleutel, $min, $max ) use ( $ruw, $oud ) {
			if ( ! isset( $ruw[ $sleutel ] ) || '' === $ruw[ $sleutel ] ) {
				return $oud[ $sleutel ];
			}
			return max( $min, min( $max, (float) $ruw[ $sleutel ] ) );
		};

		$soort = isset( $ruw['korting_soort'] ) && 'bedrag' === $ruw['korting_soort'] ? 'bedrag' : 'percent';

		$sjablonen = WSFM_Newsletter_Render::templates();
		$sjabloon  = isset( $ruw['mail_sjabloon'] ) ? sanitize_key( $ruw['mail_sjabloon'] ) : '';

		update_option(
			self::OPTIE,
			array(
				'aan'            => empty( $ruw['aan'] ) ? 0 : 1,
				'kop'            => $tekst( 'kop', 80 ),
				'tekst'          => $tekst( 'tekst', 300 ),
				'knop'           => $tekst( 'knop', 40 ),
				'plaatshouder'   => $tekst( 'plaatshouder', 60 ),
				'kleine_letters' => $tekst( 'kleine_letters', 200 ),
				'gelukt_kop'     => $tekst( 'gelukt_kop', 80 ),
				'gelukt_tekst'   => $tekst( 'gelukt_tekst', 300 ),
				'afbeelding'     => isset( $ruw['afbeelding'] ) ? absint( $ruw['afbeelding'] ) : $oud['afbeelding'],

				'kleur_achtergrond' => $kleur( 'kleur_achtergrond' ),
				'kleur_tekst'       => $kleur( 'kleur_tekst' ),
				'kleur_knop'        => $kleur( 'kleur_knop' ),
				'kleur_knoptekst'   => $kleur( 'kleur_knoptekst' ),
				'rond'              => empty( $ruw['rond'] ) ? 0 : 1,
				'lettertype'        => self::lettertype_of( isset( $ruw['lettertype'] ) ? $ruw['lettertype'] : '' ),

				'na_seconden'        => (int) $getal( 'na_seconden', 0, 120 ),
				'dagen_verbergen'    => (int) $getal( 'dagen_verbergen', 1, 365 ),
				'niet_bij_afrekenen' => empty( $ruw['niet_bij_afrekenen'] ) ? 0 : 1,

				'korting_soort'    => $soort,
				'korting_waarde'   => $getal( 'korting_waarde', 1, 'percent' === $soort ? 90 : 10000 ),
				'minimaal'         => $getal( 'minimaal', 0, 100000 ),
				'geldig_dagen'     => (int) $getal( 'geldig_dagen', 0, 3650 ),
				'unieke_code'      => empty( $ruw['unieke_code'] ) ? 0 : 1,
				'vaste_code'       => strtoupper( $tekst( 'vaste_code', 40 ) ),
				'code_voorvoegsel' => strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $tekst( 'code_voorvoegsel', 12 ) ) ),

				'mail_onderwerp' => $tekst( 'mail_onderwerp', 150 ),
				'mail_kop'       => $tekst( 'mail_kop', 80 ),
				'mail_tekst'     => isset( $ruw['mail_tekst'] )
					? mb_substr( sanitize_textarea_field( wp_unslash( $ruw['mail_tekst'] ) ), 0, 1500 )
					: $oud['mail_tekst'],
				'mail_sjabloon'  => isset( $sjablonen[ $sjabloon ] ) ? $sjabloon : 'rustig',
			)
		);
	}

	/**
	 * De lettertypes waar de winkelier uit kan kiezen.
	 *
	 * Vier, en niet een lijst met alles wat er bestaat. Een popup hoort bij de
	 * winkel te passen, en de veiligste keuze is dus het lettertype dat de rest
	 * van de site al gebruikt. Dat staat vooraan en is de standaard.
	 *
	 * Geen webfonts die we zelf ophalen: dat is een extra verzoek naar een
	 * externe server op elke pagina van de winkel, en dat is een prijs die je
	 * niet voor een popup wilt betalen.
	 *
	 * @return array sleutel => array( naam, stapel )
	 */
	public static function lettertypes() {
		return array(
			'website'     => array(
				'naam'   => __( 'Van je website', 'ws-flow-mailer' ),
				'stapel' => 'inherit',
			),
			'schreefloos' => array(
				'naam'   => __( 'Schreefloos', 'ws-flow-mailer' ),
				'stapel' => 'Helvetica, Arial, sans-serif',
			),
			'schreef'     => array(
				'naam'   => __( 'Met schreef', 'ws-flow-mailer' ),
				'stapel' => 'Georgia, "Times New Roman", serif',
			),
			'modern'      => array(
				'naam'   => __( 'Modern', 'ws-flow-mailer' ),
				'stapel' => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
			),
		);
	}

	/**
	 * Een geldige lettertypesleutel, of de standaard.
	 *
	 * @param string $sleutel Wat er binnenkwam.
	 * @return string
	 */
	public static function lettertype_of( $sleutel ) {
		$sleutel     = sanitize_key( (string) $sleutel );
		$lettertypes = self::lettertypes();
		return isset( $lettertypes[ $sleutel ] ) ? $sleutel : 'website';
	}

	/* ---------------------------------------------------------------------
	 * De voorkant
	 * ------------------------------------------------------------------- */

	/**
	 * Mag hij op deze pagina verschijnen?
	 *
	 * @return bool
	 */
	private static function hier_tonen() {
		$i = self::instellingen();

		if ( is_admin() || is_feed() || is_404() ) {
			return false;
		}

		/* Iemand die zijn adres en bezorggegevens aan het invullen is, is precies
		   de bezoeker die je NIET moet onderbreken. */
		if ( ! empty( $i['niet_bij_afrekenen'] ) && function_exists( 'is_checkout' ) ) {
			if ( is_checkout() || is_cart() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * De stylesheet en het script klaarzetten.
	 */
	public static function scripts() {
		if ( ! self::hier_tonen() ) {
			return;
		}

		$i = self::instellingen();

		wp_enqueue_style( 'wsfm-popup', WSFM_PLUGIN_URL . 'assets/popup.css', array(), WSFM_VERSION );
		wp_enqueue_script( 'wsfm-popup', WSFM_PLUGIN_URL . 'assets/popup.js', array(), WSFM_VERSION, true );
		wp_localize_script(
			'wsfm-popup',
			'wsfmPopup',
			array(
				'url'      => rest_url( self::REST_NAMESPACE . '/inschrijven' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'seconden' => (int) $i['na_seconden'],
				'dagen'    => (int) $i['dagen_verbergen'],
				'koekje'   => self::KOEKJE,
				/* Bij "van je website" zoekt het script het lettertype op bij een
				   echt stuk tekst. Erven van body werkt niet bij thema's die hun
				   lettertype op de elementen zelf zetten; dan staat op body nog de
				   standaard van de browser en werd de popup ineens een schreef. */
				'erfLettertype' => 'website' === self::lettertype_of( $i['lettertype'] ),
				'bezig'    => __( 'Momentje...', 'ws-flow-mailer' ),
				'fout'     => __( 'Er ging iets mis. Probeer het zo nog eens.', 'ws-flow-mailer' ),
			)
		);
	}

	/**
	 * De popup in de voettekst zetten.
	 */
	public static function toon() {
		if ( ! self::hier_tonen() ) {
			return;
		}

		$i     = self::instellingen();
		$beeld = $i['afbeelding'] ? wp_get_attachment_image_url( (int) $i['afbeelding'], 'large' ) : '';

		echo self::html( $i, $beeld ); // phpcs:ignore WordPress.Security.EscapingOutput -- opgebouwd met esc_* hieronder.
	}

	/**
	 * De opmaak van de popup.
	 *
	 * Ook gebruikt door het voorbeeld in het beheerscherm, zodat wat je daar
	 * ziet echt hetzelfde is als wat je bezoeker krijgt en geen nagemaakte
	 * versie die langzaam uit elkaar groeit.
	 *
	 * WAAROM HET VOORBEELD GEEN FORMULIER IS
	 * Op de winkel is dit een echt form. In het beheerscherm staat het voorbeeld
	 * binnen het instellingenformulier, en geneste formulieren bestaan niet in
	 * HTML: de browser gooit de binnenste weg. Het e-mailveld hoorde daardoor
	 * ineens bij de instellingen, inclusief zijn required, en je moest het
	 * invullen voordat je kon opslaan. De klasse blijft er wel op staan, dus de
	 * opmaak en het JavaScript merken er niets van.
	 *
	 * @param array  $i         Instellingen.
	 * @param string $beeld     URL van de afbeelding.
	 * @param bool   $voorbeeld Meteen zichtbaar, en zonder formulier.
	 * @return string
	 */
	public static function html( array $i, $beeld = '', $voorbeeld = false ) {
		$lettertypes = self::lettertypes();
		$letter      = $lettertypes[ self::lettertype_of( isset( $i['lettertype'] ) ? $i['lettertype'] : '' ) ]['stapel'];
		$vak         = $voorbeeld ? 'div' : 'form';
		$knopsoort   = $voorbeeld ? 'button' : 'submit';

		$stijl = sprintf(
			'--wsfm-bg:%s;--wsfm-tekst:%s;--wsfm-knop:%s;--wsfm-knoptekst:%s;--wsfm-rond:%s;--wsfm-font:%s;',
			esc_attr( $i['kleur_achtergrond'] ),
			esc_attr( $i['kleur_tekst'] ),
			esc_attr( $i['kleur_knop'] ),
			esc_attr( $i['kleur_knoptekst'] ),
			empty( $i['rond'] ) ? '0px' : '14px',
			esc_attr( $letter )
		);

		$uit = '<div class="wsfm-popup-laag' . ( $voorbeeld ? ' is-open' : '' ) . '" style="' . $stijl . '" hidden>'
			. '<div class="wsfm-popup" role="dialog" aria-modal="true" aria-label="' . esc_attr( $i['kop'] ) . '">'
			. '<button type="button" class="wsfm-popup-sluit" aria-label="' . esc_attr__( 'Sluiten', 'ws-flow-mailer' ) . '">&times;</button>';

		$uit .= '<div class="wsfm-popup-tekst">'
			. '<div class="wsfm-popup-vraag">'
			. '<h2>' . esc_html( $i['kop'] ) . '</h2>'
			. ( '' === trim( (string) $i['tekst'] ) ? '' : '<p>' . esc_html( $i['tekst'] ) . '</p>' )
			. '<' . $vak . ' class="wsfm-popup-form">'
			. '<label class="screen-reader-text" for="wsfm-popup-email">' . esc_html( $i['plaatshouder'] ) . '</label>'
			. '<input type="email" id="wsfm-popup-email"' . ( $voorbeeld ? '' : ' required' ) . ' autocomplete="email" placeholder="' . esc_attr( $i['plaatshouder'] ) . '">'
			. '<button type="' . $knopsoort . '">' . esc_html( $i['knop'] ) . '</button>'
			. '</' . $vak . '>'
			. '<p class="wsfm-popup-melding" role="status"></p>'
			. ( '' === trim( (string) $i['kleine_letters'] ) ? '' : '<p class="wsfm-popup-klein">' . esc_html( $i['kleine_letters'] ) . '</p>' )
			. '</div>'

			/* Het bedankscherm staat er al in en wordt alleen zichtbaar gemaakt.
			   Zo hoeft er na het versturen niets opgebouwd te worden en kan de
			   code direct in beeld. */
			. '<div class="wsfm-popup-gelukt" hidden>'
			. '<h2>' . esc_html( $i['gelukt_kop'] ) . '</h2>'
			. ( '' === trim( (string) $i['gelukt_tekst'] ) ? '' : '<p>' . esc_html( $i['gelukt_tekst'] ) . '</p>' )
			. '<p class="wsfm-popup-code"></p>'
			. '</div>'
			. '</div>';

		if ( $beeld ) {
			$uit .= '<div class="wsfm-popup-beeld"><img src="' . esc_url( $beeld ) . '" alt="" loading="lazy"></div>';
		}

		return $uit . '</div></div>';
	}

	/* ---------------------------------------------------------------------
	 * De inschrijving
	 * ------------------------------------------------------------------- */

	/**
	 * De route waar het formulier naartoe schrijft.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/inschrijven',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'inschrijven' ),
				'permission_callback' => '__return_true', // Openbaar met opzet; het is een inschrijfformulier.
				'args'                => array(
					'email' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * Iemand schrijft zich in.
	 *
	 * @param WP_REST_Request $request Verzoek.
	 * @return WP_REST_Response
	 */
	public static function inschrijven( $request ) {
		if ( ! self::aan() ) {
			return new WP_REST_Response( array( 'ok' => false, 'melding' => __( 'Inschrijven kan op dit moment niet.', 'ws-flow-mailer' ) ), 403 );
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! is_email( $email ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'melding' => __( 'Dat lijkt geen geldig e-mailadres.', 'ws-flow-mailer' ) ), 400 );
		}

		/* Wie zich ooit heeft afgemeld schrijven we niet stilletjes weer in.
		   Naar buiten toe zeggen we wel dat het gelukt is: of een adres op die
		   lijst staat is niets wat een willekeurige bezoeker mag uitvragen. */
		if ( WSFM_Suppression::is_suppressed( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'code'    => '',
					'melding' => __( 'Je staat al bij ons genoteerd.', 'ws-flow-mailer' ),
				)
			);
		}

		$i        = self::instellingen();
		$bestaand = WSFM_Subscribers::get( $email );

		if ( $bestaand ) {
			/* Al ingeschreven: dezelfde code terug, geen tweede. Wel vriendelijk
			   blijven, want de bezoeker doet niets fout. */
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'code'    => (string) $bestaand->coupon_code,
					'melding' => __( 'Je was al ingeschreven. Dit is je code.', 'ws-flow-mailer' ),
				)
			);
		}

		$code = self::code_voor( $email, $i );

		WSFM_Subscribers::add( $email, 'popup', $code );
		self::stuur_welkomstmail( $email, $code, $i );

		return new WP_REST_Response( array( 'ok' => true, 'code' => $code, 'melding' => '' ) );
	}

	/**
	 * De kortingscode voor dit adres.
	 *
	 * @param string $email E-mailadres.
	 * @param array  $i     Instellingen.
	 * @return string Lege tekst als er geen coupon gemaakt kon worden.
	 */
	private static function code_voor( $email, array $i ) {
		if ( empty( $i['unieke_code'] ) ) {
			return (string) $i['vaste_code'];
		}

		if ( ! function_exists( 'wc_get_coupon_id_by_code' ) || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}

		$code = self::verzin_code( $i['code_voorvoegsel'] );
		if ( '' === $code ) {
			return '';
		}

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( 'bedrag' === $i['korting_soort'] ? 'fixed_cart' : 'percent' );
			$coupon->set_amount( (float) $i['korting_waarde'] );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit( 1 );
			$coupon->set_email_restrictions( array( $email ) );
			$coupon->set_description( sprintf( /* translators: %s: e-mailadres. */ __( 'Inschrijving nieuwsbrief: %s', 'ws-flow-mailer' ), $email ) );

			if ( $i['minimaal'] > 0 ) {
				$coupon->set_minimum_amount( (float) $i['minimaal'] );
			}
			if ( $i['geldig_dagen'] > 0 ) {
				$coupon->set_date_expires( time() + (int) $i['geldig_dagen'] * DAY_IN_SECONDS );
			}

			$coupon->save();
		} catch ( Exception $e ) {
			return '';
		}

		return $code;
	}

	/**
	 * Een code verzinnen die nog niet bestaat.
	 *
	 * @param string $voorvoegsel Voorvoegsel.
	 * @return string
	 */
	private static function verzin_code( $voorvoegsel ) {
		$voorvoegsel = $voorvoegsel ? $voorvoegsel : 'WELKOM';

		/* Zonder 0/O en 1/I: die worden overgetypt van een scherm en verwisseld,
		   en een code die niet werkt kost een mailtje aan de klantenservice. */
		$tekens = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

		for ( $poging = 0; $poging < 10; $poging++ ) {
			$staart = '';
			for ( $n = 0; $n < 6; $n++ ) {
				$staart .= $tekens[ wp_rand( 0, strlen( $tekens ) - 1 ) ];
			}
			$code = $voorvoegsel . '-' . $staart;

			if ( ! wc_get_coupon_id_by_code( $code ) ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * De welkomstmail met de code.
	 *
	 * Meteen versturen en niet via de wachtrij: die loopt om de vijf minuten, en
	 * iemand die net op Inschrijven heeft gedrukt kijkt nu in zijn mail.
	 *
	 * @param string $email E-mailadres.
	 * @param string $code  Kortingscode.
	 * @param array  $i     Instellingen.
	 * @return bool
	 */
	public static function stuur_welkomstmail( $email, $code, array $i ) {
		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			return false;
		}

		/* De afmeldlink gaat MEE de opmaak in en wordt er niet achteraf in
		   geplakt. De opmaakmotor vervangt elke tag die hij kent, ook als er
		   niets voor is meegegeven; dan blijft er een lege href over. Een
		   welkomstmail zonder werkende afmeldlink is niet alleen slordig maar
		   ook in strijd met de wet. */
		$rendered = self::mail_html(
			$i,
			$code,
			array( 'unsubscribe_url' => WSFM_Unsubscribe::url( $email ) )
		);

		$result = $provider->send( $email, $rendered['subject'], $rendered['html_body'], array() );

		return $result->success;
	}

	/**
	 * De welkomstmail opmaken.
	 *
	 * Gebruikt dezelfde sjablonen als de nieuwsbrief, dus de mail past bij de
	 * rest van wat er uit deze winkel komt.
	 *
	 * @param array  $i       Instellingen.
	 * @param string $code    Kortingscode.
	 * @param array  $context Merge-gegevens, met in elk geval de afmeldlink.
	 * @return array { subject, html_body }
	 */
	public static function mail_html( array $i, $code, array $context = array() ) {
		$blokken = array(
			array(
				'soort' => 'tekst',
				'kop'   => $i['mail_kop'],
				'tekst' => $i['mail_tekst'],
			),
		);

		if ( '' !== (string) $code ) {
			$blokken[] = array(
				'soort' => 'code',
				'code'  => $code,
				'onder' => self::korting_omschrijving( $i ),
			);
		}

		$blokken[] = array(
			'soort'    => 'tekst',
			'tekst'    => '',
			'knop'     => __( 'Bekijk de collectie', 'ws-flow-mailer' ),
			'knop_url' => home_url( '/' ),
		);

		$brief = (object) array(
			'subject'  => $i['mail_onderwerp'],
			'template' => $i['mail_sjabloon'],
			'blocks'   => $blokken,
		);

		return WSFM_Template_Engine::render_string(
			$brief->subject,
			WSFM_Newsletter_Render::render( $brief ),
			$context
		);
	}

	/**
	 * De korting in gewone taal, voor onder de code in de mail.
	 *
	 * @param array $i Instellingen.
	 * @return string
	 */
	public static function korting_omschrijving( array $i ) {
		$waarde = 'percent' === $i['korting_soort']
			? rtrim( rtrim( number_format_i18n( (float) $i['korting_waarde'], 2 ), '0' ), ',' ) . '%'
			: WSFM_Template_Engine::format_price( (float) $i['korting_waarde'] );

		$regels = array( sprintf( /* translators: %s: kortingsbedrag of percentage. */ __( '%s korting op je bestelling', 'ws-flow-mailer' ), $waarde ) );

		if ( $i['minimaal'] > 0 ) {
			/* translators: %s: minimaal bestelbedrag. */
			$regels[] = sprintf( __( 'vanaf %s', 'ws-flow-mailer' ), WSFM_Template_Engine::format_price( (float) $i['minimaal'] ) );
		}
		if ( $i['geldig_dagen'] > 0 ) {
			/* translators: %d: aantal dagen. */
			$regels[] = sprintf( _n( 'geldig %d dag', 'geldig %d dagen', (int) $i['geldig_dagen'], 'ws-flow-mailer' ), (int) $i['geldig_dagen'] );
		}

		return implode( ', ', $regels );
	}
}
