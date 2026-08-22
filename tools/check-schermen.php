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
function wp_get_attachment_image_url( $id, $s = '' ) { return 'https://voorbeeld.nl/foto.jpg'; }
function wp_list_pluck( $l, $f ) { $u = array(); foreach ( $l as $r ) { $u[] = is_object( $r ) ? $r->$f : $r[ $f ]; } return $u; }
function submit_button( $t = null ) { echo '<p class="submit"><button>Opslaan</button></p>'; }

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
class WSFM_Newsletters {
	public static function is_klantgroep( $d ) {
		return in_array( $d, array( 'klanten_jaar', 'klanten_alle', 'alles' ), true );
	}
}
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

/* ---- de samensteller, in drie standen ---- */

$lijsten = array(
	(object) array( 'id' => 1, 'naam' => 'Nieuwsbrief', 'omschrijving' => '', 'is_hoofdlijst' => 1, 'aantal' => 412 ),
);

foreach ( array( 'nieuw', 'concept', 'verzonden' ) as $stand ) {
	$brief = 'nieuw' === $stand ? null : (object) array(
		'id'       => 5,
		'name'     => 'Zomeractie',
		'subject'  => '20% korting',
		'status'   => 'verzonden' === $stand ? 'verzonden' : 'concept',
		'template' => 'warm',
		'audience' => 'lijst_1',
		'blocks'   => array( array( 'soort' => 'tekst', 'kop' => 'Hoi', 'tekst' => 'Tekst', 'knop' => '', 'knop_url' => '' ) ),
	);
	$voortgang = 'verzonden' === $stand ? array( 'verzonden' => 400, 'wacht' => 0, 'mislukt' => 2 ) : null;

	$html = scherm( 'samensteller, ' . $stand, $map . 'newsletter-edit-page.php' );
	moet( $html, 'wsfm-briefvoorbeeld', 'samensteller' );
	moet( $html, 'wsfm-klantgroep-let-op', 'samensteller' );
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

printf(
	"\n%s\n",
	0 === $fouten ? 'alle schermen komen heel uit de bouw' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
