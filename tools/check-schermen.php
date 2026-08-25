<?php
/**
 * Bouwt de beheerschermen echt op en telt na of ze heel zijn.
 *
 * WAAROM DIT BESTAAT
 * php -l zegt alleen dat de PHP klopt. Het zegt niets over een <div> die in de
 * ene tak wel en in de andere niet gesloten wordt, over een <form> dat binnen
 * een ander <form> belandt, of over een blok dat verdwijnt op het moment dat er
 * even geen gegevens zijn. Dat merk je pas als een klant naar een scheef scherm
 * kijkt.
 *
 * Dat is dezelfde familie fout als een kolom die niet in de CREATE TABLE staat:
 * er is niets kapot aan wat er wél staat, dus niemand ziet het.
 *
 * HET GENESTE FORMULIER IS HIER GEEN THEORIE
 * De browser gooit een <form> binnen een <form> weg. De velden komen dan bij het
 * buitenste formulier terecht, inclusief hun required, en dat kostte eerder een
 * opslaan-knop die een e-mailadres eiste. Deze schermen staan vol losse
 * formuliertjes per rij, dus die val ligt hier open.
 *
 * HOE HET WERKT
 * WordPress wordt niet geladen; de handvol functies die de sjablonen gebruiken
 * worden hier nagebootst. Dat is genoeg om de HTML op te bouwen, en het scheelt
 * een hele WordPress-installatie in een controle die in een seconde moet lopen.
 *
 * EN DE MAIL ZELF
 * Onderaan wordt ook de nieuwsbrief opgebouwd. Die HTML zie je pas als hij in
 * een inbox ligt, en Outlook vat een tabel die niet uitkomt heel anders op dan
 * een browser. Het productenraster stond er eerder scheef in doordat foto's
 * met verschillende verhoudingen de naam en de prijs meetrokken; sindsdien is
 * dat drie tabelrijen en wordt hier nageteld dat het zo blijft.
 *
 * Draaien: php tools/check-schermen.php
 */

define( 'ABSPATH', '/' );

$wortel = dirname( __DIR__ );
$map    = $wortel . '/mailer/admin/';

/* ---------------- WordPress nagebootst ---------------- */

function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return (string) $t; }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_js( $t ) { return (string) $t; }
function __( $t, $d = '' ) { return $t; }
function _n( $s, $p, $n, $d = '' ) { return 1 === (int) $n ? $s : $p; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function esc_html_e( $t, $d = '' ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = '' ) { echo esc_attr( $t ); }
function admin_url( $p = '' ) { return 'https://voorbeeld.nl/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://voorbeeld.nl' . $p; }
function add_query_arg( $a, $u = '' ) { return $u . '?' . http_build_query( (array) $a ); }
function wp_nonce_field( $a = '', $b = '_wpnonce' ) { echo '<input type="hidden" name="_wpnonce" value="x" />'; }
function selected( $a, $b = true, $echo = true ) { $r = (string) $a === (string) $b ? ' selected' : ''; if ( $echo ) { echo $r; } return $r; }
function checked( $a, $b = true, $echo = true ) { $r = $a == $b ? ' checked' : ''; if ( $echo ) { echo $r; } return $r; }
function number_format_i18n( $n, $d = 0 ) { return (string) $n; }
function date_i18n( $f, $t = null ) { return '22 aug 2026'; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function get_option( $n, $d = false ) { return 'beheerder@voorbeeld.nl'; }
function get_bloginfo( $w = '' ) { return 'Voorbeeldshop'; }
/* Alleen het catalogusformaat bestaat, zodat ook de terugval naar een andere
   maat langskomt. */
function wp_get_attachment_image_url( $id, $maat = '' ) {
	return 'woocommerce_thumbnail' === $maat ? 'https://voorbeeld.nl/foto-' . $id . '.jpg' : '';
}
function wp_list_pluck( $l, $f ) { $u = array(); foreach ( $l as $r ) { $u[] = is_object( $r ) ? $r->$f : $r[ $f ]; } return $u; }
function submit_button( $t = null ) { echo '<p class="submit"><button>Opslaan</button></p>'; }

function get_theme_mod( $n, $d = false ) { return 0; }
function get_permalink( $id ) { return 'https://voorbeeld.nl/product/' . $id; }
function get_post_meta( $id, $k, $enkel = false ) { return ''; }
function wp_strip_all_tags( $t ) { return strip_tags( (string) $t ); }

/* Een product zoals WooCommerce het teruggeeft, met precies de methodes die de
   opbouw aanroept. Nummer 4 heeft geen foto, 3 geen prijs en een lange naam,
   en 5 bestaat niet: samen de gevallen waar een raster op stukloopt. */
class WSFM_Proef_Product {
	public $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_image_id() { return 4 === $this->id ? 0 : $this->id; }
	public function get_name() {
		return 3 === $this->id ? 'Een naam die zo lang is dat hij over twee regels valt' : 'Product ' . $this->id;
	}
	public function get_price_html() { return 3 === $this->id ? '' : '&euro; 4,95'; }
}
function wc_get_product( $id ) { return 5 === $id ? false : new WSFM_Proef_Product( $id ); }

class WSFM_Flow_Admin_UI {
	const SLUG_DASHBOARD = 'ws-flow-mailer';
	const SLUG_BRIEVEN   = 'ws-flow-mailer-nieuwsbrieven';
	const SLUG_POPUP     = 'ws-flow-mailer-popup';
	const SLUG_ABONNEES  = 'ws-flow-mailer-inschrijvingen';
	const SLUG_FLOWS     = 'ws-flow-mailer-flows';
	const SLUG_TEMPLATES = 'ws-flow-mailer-templates';
	public static function mask_email( $e ) { return 'j***@voorbeeld.nl'; }
}
class WSFM_Flows { const TRIGGER_TYPES = array(); }
/* Deze vier zijn niet nagebootst maar echt ingeladen: ze raken de database niet
   en het gaat hier juist om wat zij opleveren. Een nabootsing die toevallig het
   goede antwoord geeft controleert alleen zichzelf. */
require_once dirname( __DIR__ ) . '/mailer/includes/class-eigen-html.php';
require_once dirname( __DIR__ ) . '/mailer/includes/class-template-engine.php';
require_once dirname( __DIR__ ) . '/mailer/includes/class-newsletter-render.php';
require_once dirname( __DIR__ ) . '/mailer/includes/class-newsletters.php';
class WSFM_Subscribers {
	public static function naam_van( $r ) {
		$n = trim( ( isset( $r->first_name ) ? $r->first_name : '' ) . ' ' . ( isset( $r->last_name ) ? $r->last_name : '' ) );
		return '' === $n ? '-' : $n;
	}
}

/* ---------------- de controle zelf ---------------- */

$fouten = 0;

/**
 * Een scherm opbouwen en natellen.
 *
 * @param string $naam Naam voor op het scherm.
 * @param string $pad  Pad naar het sjabloon.
 * @return string De opgebouwde HTML.
 */
function scherm( $naam, $pad ) {
	global $fouten;

	/* De sjablonen verwachten deze variabelen in hun eigen bereik. Een include
	   binnen een functie ziet niets van buiten, dus ze moeten hier met zoveel
	   woorden binnengehaald worden. Zonder dit rendert elk scherm leeg en meldt
	   de controle dat alles heel is, wat erger is dan geen controle. */
	global $lijsten, $afrekenen, $doelgroepen, $sjablonen;
	global $rijen, $lidmaatschap, $totaal, $alle, $pagina, $paginas, $zoek, $lijst_filter;
	global $brief, $voortgang, $brieven;
	global $stats, $flow_stats, $recent, $trigger_filter;

	ob_start();
	include $pad;
	$html = ob_get_clean();

	printf( "\n  %s\n", $naam );

	foreach ( array( 'div', 'table', 'tbody', 'form', 'select' ) as $tag ) {
		$open  = substr_count( $html, '<' . $tag );
		$dicht = substr_count( $html, '</' . $tag . '>' );
		if ( $open !== $dicht ) {
			printf( "    FOUT %s: %d geopend, %d gesloten\n", $tag, $open, $dicht );
			$fouten++;
		}
	}

	/* Een formulier in een formulier. Zie de uitleg bovenaan. */
	if ( preg_match( '/<form[^>]*>(?:(?!<\/form>).)*<form/s', $html ) ) {
		printf( "    FOUT er staat een formulier binnen een ander formulier\n" );
		$fouten++;
	}

	return $html;
}

/**
 * Staat er iets in wat er hoort te staan?
 *
 * @param string $html  De opgebouwde HTML.
 * @param string $stuk  Wat erin moet.
 * @param string $waar  Voor de foutmelding.
 * @return void
 */
function moet( $html, $stuk, $waar ) {
	global $fouten;

	if ( false === strpos( $html, $stuk ) ) {
		printf( "    FOUT %s mist \"%s\"\n", $waar, $stuk );
		$fouten++;
	}
}

/**
 * Staat er iets in wat er juist niet in mag?
 *
 * @param string $html   De opgebouwde HTML.
 * @param string $stuk   Wat er niet in mag.
 * @param string $klacht Voor de foutmelding.
 * @return void
 */
function magniet( $html, $stuk, $klacht ) {
	global $fouten;

	if ( false !== strpos( $html, $stuk ) ) {
		printf( "    FOUT %s\n", $klacht );
		$fouten++;
	}
}

echo "de beheerschermen opbouwen";

/* ---- gegevens waar de sjablonen om vragen ---- */

$lijsten = array(
	(object) array( 'id' => 1, 'naam' => 'Nieuwsbrief', 'omschrijving' => 'Aanmeldingen', 'is_hoofdlijst' => 1, 'aantal' => 412 ),
	(object) array( 'id' => 2, 'naam' => 'Testers', 'omschrijving' => '', 'is_hoofdlijst' => 0, 'aantal' => 3 ),
);
$afrekenen   = array( 'aan' => 1, 'label' => 'Houd me op de hoogte', 'lijst_id' => 1 );
$doelgroepen = array( 'lijst_1' => 'Nieuwsbrief (412 personen)', 'klanten_jaar' => 'Klanten dit jaar' );
$sjablonen   = array(
	'rustig' => array( 'naam' => 'Rustig', 'kort' => 'Wit en ruim.' ),
	'warm'   => array( 'naam' => 'Warm', 'kort' => 'Zacht.' ),
	'strak'  => array( 'naam' => 'Strak', 'kort' => 'Volle breedte.' ),
);

/* ---- inschrijvingen, gevuld ---- */

$rijen = array(
	(object) array(
		'id' => 9, 'email' => 'jan@voorbeeld.nl', 'first_name' => 'Jan', 'last_name' => 'de Vries',
		'source' => 'popup', 'coupon_code' => 'WELKOM-4KP7HQ', 'created_at' => '2026-08-01 10:00:00',
	),
);
$lidmaatschap = array( 9 => array( 'Nieuwsbrief', 'Testers' ) );
$totaal       = 1;
$alle         = 415;
$pagina       = 1;
$paginas      = 1;
$zoek         = '';
$lijst_filter = 0;

$html = scherm( 'inschrijvingen, gevuld', $map . 'inschrijvingen-page.php' );
moet( $html, 'wsfm_contact', 'inschrijvingen' );
moet( $html, 'contact_achternaam', 'inschrijvingen' );
moet( $html, 'Jan de Vries', 'inschrijvingen' );
moet( $html, 'wsfm_lijst_hernoem', 'inschrijvingen' );
moet( $html, 'wsfm_lijst_lid', 'inschrijvingen' );

/* ---- inschrijvingen, lege shop ---- */

$lijsten      = array( (object) array( 'id' => 1, 'naam' => 'Nieuwsbrief', 'omschrijving' => '', 'is_hoofdlijst' => 1, 'aantal' => 0 ) );
$rijen        = array();
$lidmaatschap = array();
$totaal       = 0;
$alle         = 0;

$html = scherm( 'inschrijvingen, lege shop', $map . 'inschrijvingen-page.php' );
if ( false !== strpos( $html, 'wsfm_lijst_weg' ) ) {
	echo "    FOUT de hoofdlijst heeft een weghaalknop, en die kan niet weg\n";
	$fouten++;
}

/* ---- de samensteller, in vier standen ---- */

$lijsten = array(
	(object) array( 'id' => 1, 'naam' => 'Nieuwsbrief', 'omschrijving' => '', 'is_hoofdlijst' => 1, 'aantal' => 412 ),
);

foreach ( array( 'nieuw', 'concept', 'verzonden', 'eigen' ) as $stand ) {
	$brief = 'nieuw' === $stand ? null : (object) array(
		'id'         => 5,
		'name'       => 'Zomeractie',
		'subject'    => '20% korting',
		'status'     => 'verzonden' === $stand ? 'verzonden' : 'concept',
		'template'   => 'warm',
		'audience'   => 'lijst_1',
		'blocks'     => array( array( 'soort' => 'tekst', 'kop' => 'Hoi', 'tekst' => 'Tekst', 'knop' => '', 'knop_url' => '' ) ),
		'soort'      => 'eigen' === $stand ? 'eigen' : 'blokken',
		'eigen_html' => '<!DOCTYPE html><html><body><p>Hoi %FIRSTNAME% &amp; "tot ziens"</p></body></html>',
	);
	$voortgang = 'verzonden' === $stand ? array( 'verzonden' => 400, 'wacht' => 0, 'mislukt' => 2 ) : null;

	$html = scherm( 'samensteller, ' . $stand, $map . 'newsletter-edit-page.php' );
	moet( $html, 'wsfm-briefvoorbeeld', 'samensteller' );
	moet( $html, 'wsfm-klantgroep-let-op', 'samensteller' );

	/* Het tekstvak hoort de aangeleverde HTML ontweken terug te geven. Zou
	   die er rauw in staan, dan sluit de eerste </p> het tekstvak af en valt
	   de rest van het scherm uit elkaar. */
	if ( 'eigen' === $stand ) {
		/* Niet op een vaste tekst zoeken: tussen het attribuut en checked staat
		   witruimte uit het sjabloon en uit checked() zelf. */
		if ( ! preg_match( '/value="eigen"\s+checked/', $html )
			|| preg_match( '/value="blokken"\s+checked/', $html ) ) {
			printf( "    FOUT de keuze staat niet op eigen HTML\n" );
			$fouten++;
		}
		moet( $html, '&lt;!DOCTYPE html&gt;', 'samensteller' );
		magniet( $html, '<p>Hoi %FIRSTNAME%', 'de aangeleverde HTML staat onontweken in het tekstvak' );
	}
}

/* ---- de nieuwsbrieflijst ---- */

$brieven = array(
	(object) array( 'id' => 5, 'name' => 'Zomeractie', 'subject' => '20%', 'status' => 'verzonden', 'sent_at' => '2026-08-20 09:30:00', 'recipients' => 400, 'audience' => 'lijst_1' ),
	(object) array( 'id' => 6, 'name' => 'Oud', 'subject' => 'x', 'status' => 'verzonden', 'sent_at' => '2026-01-01 09:00:00', 'recipients' => 10, 'audience' => 'lijst_99' ),
);
$html = scherm( 'nieuwsbrieflijst', $map . 'newsletters-page.php' );
moet( $html, 'Nieuwsbrief (412 personen)', 'nieuwsbrieflijst' );
/* Een lijst die weg is: dan de opgeslagen sleutel tonen en geen naam verzinnen,
   want dan zou er iets anders staan dan waar hij heen ging. */
moet( $html, 'lijst_99', 'nieuwsbrieflijst' );

$brieven = array();
$html    = scherm( 'nieuwsbrieflijst, leeg', $map . 'newsletters-page.php' );
moet( $html, 'Maak je eerste nieuwsbrief', 'nieuwsbrieflijst' );

/* ---- het dashboard ---- */

$stats = array(
	'sent' => 10, 'failed' => 0, 'pending' => 2, 'opened' => 0, 'bounced' => 0,
	'complained' => 0, 'processing' => 0, 'stopped' => 0, 'suppressed' => 0,
	'total' => 12, 'queued' => 2,
);
$flow_stats     = array();
$recent         = array();
$trigger_filter = '';
$lijsten        = array(
	(object) array( 'id' => 1, 'naam' => 'Nieuwsbrief', 'omschrijving' => 'Aanmeldingen', 'is_hoofdlijst' => 1, 'aantal' => 412 ),
);
$brieven = array(
	(object) array( 'id' => 5, 'name' => 'Zomeractie', 'subject' => '20%', 'status' => 'verzonden', 'sent_at' => '2026-08-20 09:30:00', 'recipients' => 400, 'audience' => 'lijst_1' ),
);

$html = scherm( 'dashboard', $map . 'dashboard-page.php' );
moet( $html, 'Je lijsten', 'dashboard' );
moet( $html, 'Laatste nieuwsbrieven', 'dashboard' );

$lijsten = array();
$brieven = array();
$html    = scherm( 'dashboard, niets aangemaakt', $map . 'dashboard-page.php' );
moet( $html, 'Er zijn nog geen lijsten', 'dashboard' );

/* ---------------- en de mail zelf ---------------- */

/**
 * Een nieuwsbrief opbouwen en het productenraster natellen.
 *
 * @param string $sjabloon Sjabloonsleutel.
 * @param int    $kolommen Wat dat sjabloon aan kolommen doet.
 * @return void
 */
function mailraster( $sjabloon, $kolommen ) {
	global $fouten;

	printf( "\n  nieuwsbrief, %s\n", $sjabloon );

	$brief = (object) array(
		'subject'  => 'Nieuw',
		'template' => $sjabloon,
		'blocks'   => array(
			array( 'soort' => 'producten', 'kop' => 'Nieuw', 'producten' => array( 1, 2, 3, 4, 5 ) ),
		),
	);

	$html = WSFM_Newsletter_Render::render( $brief );
	$leeg = WSFM_Newsletter_Render::render(
		(object) array( 'subject' => 'x', 'template' => $sjabloon, 'blocks' => array() )
	);

	/* Per groep producten horen er precies drie rijen bij te komen: de foto's,
	   de namen en de prijzen. Dat is wat het raster recht houdt. */
	$erbij    = substr_count( $html, '<tr>' ) - substr_count( $leeg, '<tr>' );
	$verwacht = ( (int) ceil( 5 / $kolommen ) ) * 3;

	if ( $erbij !== $verwacht ) {
		printf( "    FOUT %d rijen erbij, verwacht %d: het raster is geen drie rijen per groep meer\n", $erbij, $verwacht );
		$fouten++;
	}

	foreach ( array(
		'valign="bottom"'    => 'de fotorij staat niet meer op de onderkant uitgelijnd',
		'valign="top"'       => 'namen en prijzen staan niet meer bovenaan',
		'foto-1.jpg'         => 'het catalogusformaat wordt niet meer gebruikt',
		'Product 4'          => 'een product zonder foto valt weg',
		'table-layout:fixed' => 'de kolombreedte ligt niet meer vast',
		'object-fit:cover'   => 'de foto wordt niet meer bijgesneden',
		'[if mso]'           => 'de uitzondering voor Outlook is weg, daar rekt de foto nu uit',
	) as $stuk => $klacht ) {
		if ( false === strpos( $html, $stuk ) ) {
			printf( "    FOUT %s\n", $klacht );
			$fouten++;
		}
	}

	/* Alle productcellen even breed. Dit is waar het echt op misging: zonder
	   table-layout:fixed verdeelt de browser de kolommen naar INHOUD, en dan
	   krijgt een intrinsiek grotere foto meer ruimte. Het width-attribuut is in
	   die stand niet meer dan een suggestie, dus stond er bij allebei 262 terwijl
	   de ene kolom zichtbaar breder was. */
	preg_match_all( '/<td width="(\d+)"/', $html, $treffers );
	$breed = array_values( array_filter( array_map( 'intval', $treffers[1] ), function ( $b ) { return $b > 20; } ) );

	if ( count( array_unique( $breed ) ) > 1 ) {
		printf( "    FOUT de productkolommen zijn niet even breed: %s
", implode( ', ', array_unique( $breed ) ) );
		$fouten++;
	}

	/* De bijgesneden foto hoort vierkant te zijn. Staat er een hoogte die niet
	   bij de breedte past, dan snijdt hij wel bij maar naar een verhouding die
	   per sjabloon verschilt, en dan is het raster alsnog ongelijk. */
	preg_match_all( '/width:(\d+)px;height:(\d+)px;object-fit/', $html, $vlakken, PREG_SET_ORDER );

	if ( empty( $vlakken ) ) {
		printf( "    FOUT er staat geen bijgesneden vlak in de mail
" );
		$fouten++;
	}

	foreach ( $vlakken as $vlak ) {
		if ( (int) $vlak[1] !== (int) $vlak[2] ) {
			printf( "    FOUT de foto is niet vierkant: %s bij %s
", $vlak[1], $vlak[2] );
			$fouten++;
		}
	}

	/* Outlook vat een tabel die niet uitkomt heel anders op dan een browser: daar
	   valt het hele raster uit elkaar in plaats van dat er een randje scheef staat. */
	foreach ( array( 'table', 'tr', 'td' ) as $tag ) {
		$open  = substr_count( $html, '<' . $tag );
		$dicht = substr_count( $html, '</' . $tag . '>' );
		if ( $open !== $dicht ) {
			printf( "    FOUT %s in de mail: %d geopend, %d gesloten\n", $tag, $open, $dicht );
			$fouten++;
		}
	}
}

/**
 * Staat er iets in de mail wat erin hoort?
 *
 * Apart van moet(): daar is het tweede argument de naam van een scherm, hier
 * de klacht zelf. Bij een mail zegt "mist &lt;style&gt;" niets, en "de eigen
 * stijlen zijn weg" alles.
 *
 * @param string $html   Opgemaakte mail.
 * @param string $stuk   Wat erin moet.
 * @param string $klacht Voor de foutmelding.
 * @return void
 */
function moetmail( $html, $stuk, $klacht ) {
	global $fouten;

	if ( false === strpos( $html, $stuk ) ) {
		printf( "    FOUT %s\n", $klacht );
		$fouten++;
	}
}

/**
 * Een aangeleverde nieuwsbrief natellen.
 *
 * Waar het hier om gaat: de mail van de klant hoort er aan de andere kant
 * precies zo uit te komen als hij erin ging. Alles wat wij ertussen doen is
 * tags vertalen en rommel weghalen, en beide kunnen stil te veel pakken.
 *
 * @return void
 */
function maileigen() {
	global $fouten;

	printf( "\n  nieuwsbrief, eigen HTML\n" );

	$context = array(
		'first_name'      => 'Jan',
		'unsubscribe_url' => 'https://voorbeeld.nl/afmelden?t=abc',
	);

	/* Een mail zoals hij binnenkomt: compleet document, tags uit een ander
	   pakket, en twee dingen die eruit moeten. De zin met "onbeperkt =" staat
	   er met opzet in. Het opschonen zoekt naar dingen als onclick="...", en
	   een woord dat met "on" begint gevolgd door een isgelijkteken ziet er voor
	   een regel precies zo uit. Wie dat buiten de tags laat lopen haalt hier
	   stilletjes een halve zin uit de mail van de klant. */
	$aangeleverd = '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
		. '<title>Najaarsnieuws</title><style>body{background:#faf8f3}</style></head>'
		. '<body style="margin:0">'
		. '<p>Hoi lieve %FIRSTNAME%,</p>'
		. '<p>Bij ons geldt: onbeperkt = gratis verzenden.</p>'
		. '<script>alert(1)</script>'
		. '<a href="https://voorbeeld.nl/shop" onclick="stelen()">Bekijk de collectie</a>'
		. '<p><a href="%UNSUBSCRIBELINK%">Uitschrijven</a><br>%SENDER-INFO-SINGLELINE%</p>'
		. '</body></html>';

	$brief = (object) array(
		'subject'    => 'Hoi %FIRSTNAME%, het najaar is begonnen',
		'soort'      => 'eigen',
		'template'   => 'rustig',
		'blocks'     => array(),
		'eigen_html' => $aangeleverd,
	);

	$uit  = WSFM_Newsletters::render( $brief, $context );
	$html = $uit['html_body'];

	/* Er mag niets vooraf gaan aan het document van de klant. Zou de opbouw van
	   de samensteller er toch omheen komen, dan staat er een tweede <html> in
	   en klopt geen enkele stijl meer. */
	if ( 0 !== strpos( $html, '<!DOCTYPE html>' ) ) {
		printf( "    FOUT er staat iets vóór het document van de klant\n" );
		$fouten++;
	}

	moetmail( $html, '<style>body{background:#faf8f3}</style>', 'de eigen stijlen zijn weg' );
	moetmail( $html, 'Hoi lieve Jan,', 'de voornaam is niet ingevuld' );
	moetmail( $html, 'https://voorbeeld.nl/afmelden?t=abc', 'de afmeldlink is niet ingevuld' );
	moetmail( $html, 'Voorbeeldshop', 'de afzendergegevens zijn niet ingevuld' );
	moetmail( $html, 'onbeperkt = gratis verzenden', 'er is gewone tekst meegeschoond die op een klik-handler lijkt' );

	magniet( $html, '%FIRSTNAME%', 'de tag %FIRSTNAME% staat er nog letterlijk in' );
	magniet( $html, '%UNSUBSCRIBELINK%', 'de tag %UNSUBSCRIBELINK% staat er nog letterlijk in' );
	magniet( $html, '%SENDER', 'de afzendertag staat er nog letterlijk in' );
	magniet( $html, 'alert(1)', 'het scriptje zit er nog in' );
	magniet( $html, '<script', 'er staat nog een script-tag in' );
	magniet( $html, 'onclick', 'de klik-handler zit er nog in' );

	/* Het onderwerp is platte tekst en gaat door dezelfde vertaling heen. */
	if ( 'Hoi Jan, het najaar is begonnen' !== $uit['subject'] ) {
		printf( "    FOUT het onderwerp werd \"%s\"\n", $uit['subject'] );
		$fouten++;
	}

	/* Hij had zelf een afmeldlink, dus die van ons hoort er niet bij te komen. */
	magniet( $html, 'Je ontvangt deze mail omdat', 'onze noodvoet is erbij gezet terwijl er al een afmeldlink was' );

	/* ---- en nu dezelfde mail zonder afmeldlink ---- */

	$zonder = (object) array(
		'subject'    => 'Zonder',
		'soort'      => 'eigen',
		'template'   => 'rustig',
		'blocks'     => array(),
		'eigen_html' => '<html><body><p>Hoi %FIRSTNAME%</p></body></html>',
	);

	$kaal = WSFM_Newsletters::render( $zonder, $context );
	$kaal = $kaal['html_body'];

	moetmail( $kaal, 'Je ontvangt deze mail omdat', 'zonder afmeldlink wordt er geen voet bijgezet, en dan mag die post niet weg' );
	moetmail( $kaal, 'https://voorbeeld.nl/afmelden?t=abc', 'de voet bevat geen werkende afmeldlink' );

	/* Binnen de body en niet erachter: Outlook zet inhoud na </body> soms
	   buiten de opmaak van de rest. */
	if ( strpos( $kaal, 'Je ontvangt deze mail omdat' ) > strrpos( $kaal, '</body>' ) ) {
		printf( "    FOUT de noodvoet staat achter </body>\n" );
		$fouten++;
	}

	/* ---- en wat we de klant vooraf vertellen ---- */

	$soorten = function ( $lijst ) {
		$uit = array();
		foreach ( $lijst as $punt ) {
			$uit[] = $punt['soort'];
		}
		return $uit;
	};

	$schoon = WSFM_Eigen_Html::controle( $aangeleverd );
	if ( array( 'let-op' ) !== $soorten( $schoon ) ) {
		printf( "    FOUT een nette mail levert %d waarschuwing(en) op in plaats van alleen die over het script\n", count( $schoon ) );
		$fouten++;
	}

	/* Dit is de fout die het vaakst voorkomt: in de browser zag alles er goed
	   uit, want daar bestaat dat pad wel. */
	$paden = WSFM_Eigen_Html::controle( '<img src="/wp-content/uploads/foto.jpg"> {unsubscribe_url}' );
	if ( ! in_array( 'fout', $soorten( $paden ), true ) ) {
		printf( "    FOUT een afbeelding met een pad in plaats van een adres wordt niet gemeld\n" );
		$fouten++;
	}

	/* Een adres met domein hoort juist géén melding te geven, anders leert de
	   klant de waarschuwingen weg te kijken. */
	$goed = WSFM_Eigen_Html::controle( '<img src="https://voorbeeld.nl/foto.jpg"> {unsubscribe_url}' );
	if ( array() !== $goed ) {
		printf( "    FOUT een mail zonder problemen levert toch %d waarschuwing(en) op\n", count( $goed ) );
		$fouten++;
	}

	$groot = WSFM_Eigen_Html::controle( '{unsubscribe_url}' . str_repeat( 'x', 110000 ) );
	if ( ! in_array( 'fout', $soorten( $groot ), true ) ) {
		printf( "    FOUT een mail boven de Gmail-grens wordt niet gemeld\n" );
		$fouten++;
	}

	/* Of een nieuwsbrief leeg is hangt af van de manier waarop hij gemaakt is.
	   Op blokken kijken bij een aangeleverde mail betekent dat iemand zijn
	   ontwerp plakt, het in het voorbeeld ziet staan, en bij versturen te
	   horen krijgt dat hij eerst een afbeelding moet toevoegen. */
	$standen = array(
		array( 'eigen', '<html></html>', array(), false, 'een aangeleverde mail met HTML' ),
		array( 'eigen', '', array( array( 'soort' => 'tekst' ) ), true, 'een aangeleverde mail zonder HTML' ),
		array( 'eigen', '   ', array(), true, 'een aangeleverde mail met alleen witruimte' ),
		array( 'blokken', '', array( array( 'soort' => 'tekst' ) ), false, 'een samengestelde mail met blokken' ),
		array( 'blokken', '<html></html>', array(), true, 'een samengestelde mail zonder blokken' ),
	);

	foreach ( $standen as $stand ) {
		$proef = (object) array(
			'soort'      => $stand[0],
			'eigen_html' => $stand[1],
			'blocks'     => $stand[2],
		);

		if ( WSFM_Newsletters::leeg( $proef ) !== $stand[3] ) {
			printf(
				"    FOUT %s wordt %s genoemd\n",
				$stand[4],
				$stand[3] ? 'niet leeg' : 'leeg'
			);
			$fouten++;
		}
	}
}

mailraster( 'rustig', 2 );
mailraster( 'strak', 3 );
maileigen();


printf(
	"\n%s\n",
	0 === $fouten ? 'de schermen en de mail komen heel uit de bouw' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
