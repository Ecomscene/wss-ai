<?php
/**
 * Wat staat er aan na een verse installatie, en klopt elke modulenaam?
 *
 * WAAROM DIT BESTAAT
 * Sinds versie 0.40.0 staat bijna alles uit tot Webshopschool het aanzet. Dat
 * maakt één soort fout ineens gevaarlijk: een typefout in een modulenaam.
 *
 *   WSS_AI_Koppeling::module_aan( 'voorraden' )
 *
 * Dat is geldige PHP, er komt geen waarschuwing, en het antwoord is nee. Bij de
 * oude "aan tenzij uitgezet" viel dat mee: een onbekende naam stond gewoon aan.
 * Nu staat het onderdeel voorgoed uit, op elke webshop, en er is niets kapot om
 * naar te zoeken. Daarom worden hier alle namen die de code opvraagt naast de
 * lijst gelegd die de koppeling kent.
 *
 * En het belangrijkste: na een verse installatie hoort de klant Overzicht en
 * Upgrades te zien en verder niets. Dat is een uitspraak over gedrag, dus die
 * is na te tellen door de schakelaars echt te bevragen met lege instellingen.
 *
 * Draaien: php tools/check-modules.php
 */

define( 'ABSPATH', '/' );

/* ---------------- WordPress nagebootst ---------------- */

$GLOBALS['opties'] = array();

function get_option( $naam, $standaard = false ) {
	return array_key_exists( $naam, $GLOBALS['opties'] ) ? $GLOBALS['opties'][ $naam ] : $standaard;
}

function update_option( $naam, $waarde, $autoload = null ) {
	$GLOBALS['opties'][ $naam ] = $waarde;
	return true;
}

function get_transient( $n ) { return false; }
function set_transient( $n, $w, $t = 0 ) { return true; }
function add_action() {}
function add_filter() {}
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function sanitize_key( $t ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $t ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

/* Het tegoed staat hier op "we weten het niet", en dan geeft mag_ai() ja. Zo
   meet dit bestand de schakelaars en niet het budget; dat heeft zijn eigen
   controle. */
class WSS_AI_Budget {
	const BETAALD = array( 'teksten', 'afbeeldingen' );
	public static $mag = true;
	public static function mag_ai() { return self::$mag; }
}

$wortel = dirname( __DIR__ );
require_once $wortel . '/includes/class-wss-ai-koppeling.php';

/* ---------------- de controle ---------------- */

$fouten = 0;

/**
 * @param string $wat   Wat er getoetst wordt.
 * @param bool   $goed  Klopt het.
 * @param string $extra Bij een fout.
 * @return void
 */
function eis( $wat, $goed, $extra = '' ) {
	global $fouten;

	printf( "  %s %s%s\n", $goed ? ' ok ' : 'MIS ', $wat, $goed || '' === $extra ? '' : '  ' . $extra );
	if ( ! $goed ) {
		$fouten++;
	}
}

/** Welke modules staan er nu aan? */
function aanstaand() {
	$uit = array();
	foreach ( WSS_AI_Koppeling::MODULES as $m ) {
		if ( WSS_AI_Koppeling::module_aan( $m ) ) {
			$uit[] = $m;
		}
	}
	return $uit;
}

echo "een verse installatie, Webshopschool heeft nog niets gezegd\n";

$GLOBALS['opties'] = array();
$nu                = aanstaand();

eis(
	'alleen Upgrades staat aan',
	array( 'upgrades' ) === $nu,
	'aan: ' . ( $nu ? implode( ', ', $nu ) : 'niets' )
);

foreach ( array( 'teksten', 'afbeeldingen', 'voorraad', 'nieuwsbrief', 'verzoeken', 'seoplan' ) as $m ) {
	eis( $m . ' staat uit', ! WSS_AI_Koppeling::module_aan( $m ) );
}

echo "\nen als de hub onbereikbaar is verandert daar niets aan\n";

/* Geen antwoord van Webshopschool betekent geen lijst, en dan hoort er niets
   aan te springen. Dit is de reden dat opt-in de veilige kant is. */
$GLOBALS['opties'] = array( 'wss_ai_modules_uit' => array(), 'wss_ai_modules_aan' => array() );
eis( 'nog steeds alleen Upgrades', array( 'upgrades' ) === aanstaand() );

echo "\nJoey zet er onderdelen bij aan\n";

$GLOBALS['opties'] = array( 'wss_ai_modules_aan' => array( 'teksten', 'voorraad' ) );
$nu                = aanstaand();

eis( 'teksten staat aan', in_array( 'teksten', $nu, true ) );
eis( 'voorraad staat aan', in_array( 'voorraad', $nu, true ) );
eis( 'afbeeldingen blijft uit', ! in_array( 'afbeeldingen', $nu, true ) );
eis( 'nieuwsbrief blijft uit', ! in_array( 'nieuwsbrief', $nu, true ) );
eis( 'Upgrades staat er nog', in_array( 'upgrades', $nu, true ) );

echo "\nen Upgrades kan hij uitzetten\n";

$GLOBALS['opties'] = array( 'wss_ai_modules_uit' => array( 'upgrades' ) );
eis( 'dan staat er helemaal niets aan', array() === aanstaand(), 'aan: ' . implode( ', ', aanstaand() ) );

echo "\nzonder tegoed blijven de betaalde onderdelen weg, ook als ze aanstaan\n";

$GLOBALS['opties'] = array( 'wss_ai_modules_aan' => array( 'teksten', 'afbeeldingen', 'voorraad' ) );
WSS_AI_Budget::$mag = false;
$nu                 = aanstaand();

eis( 'teksten weg', ! in_array( 'teksten', $nu, true ) );
eis( 'afbeeldingen weg', ! in_array( 'afbeeldingen', $nu, true ) );
eis( 'voorraad kost niets en blijft', in_array( 'voorraad', $nu, true ) );
WSS_AI_Budget::$mag = true;

echo "\nde twee schakelaars geven hetzelfde antwoord\n";

/* Twee methodes die uit elkaar lopen zou betekenen dat het menu iets anders
   vindt dan de pagina zelf: een menu-item naar een lege pagina, of erger, een
   pagina die open staat terwijl hij uit hoort te staan. */
$GLOBALS['opties'] = array( 'wss_ai_modules_aan' => array( 'nieuwsbrief' ) );
$gelijk            = true;
foreach ( WSS_AI_Koppeling::MODULES as $m ) {
	if ( WSS_AI_Koppeling::module_aan( $m ) !== WSS_AI_Koppeling::module_aan_optin( $m ) ) {
		$gelijk = false;
		printf( "    verschil bij %s\n", $m );
	}
}
eis( 'module_aan en module_aan_optin zijn het eens', $gelijk );

echo "\nelke naam die de code opvraagt bestaat ook echt\n";

/* Dit is de fout die je niet ziet gebeuren: een naam die nergens op slaat geeft
   geen foutmelding, alleen een onderdeel dat voorgoed uit staat.

   Met de tokenizer en niet met een reguliere expressie op de ruwe tekst. Dat
   laatste stond hier eerst, en die las het voorbeeld in een commentaarregel als
   een echte aanroep. Een controle die over uitleg struikelt leer je wegkijken,
   en dan mis je de keer dat hij gelijk heeft. */
$gevonden = array();
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wortel . '/includes' ) );
foreach ( $iterator as $bestand ) {
	if ( 'php' !== $bestand->getExtension() ) {
		continue;
	}

	$tokens = array_values(
		array_filter(
			token_get_all( file_get_contents( $bestand->getPathname() ) ),
			static function ( $t ) {
				return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);

	for ( $i = 0, $n = count( $tokens ) - 2; $i < $n; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] ) {
			continue;
		}
		if ( ! in_array( $t[1], array( 'module_aan', 'module_aan_optin' ), true ) ) {
			continue;
		}
		if ( '(' !== $tokens[ $i + 1 ] ) {
			continue;
		}

		$arg = $tokens[ $i + 2 ];
		if ( is_array( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0] ) {
			$gevonden[ trim( $arg[1], "'\"" ) ][] = basename( $bestand->getPathname() );
		}
	}
}

eis( 'er wordt überhaupt naar modules gevraagd', count( $gevonden ) > 0, count( $gevonden ) . ' namen' );

foreach ( $gevonden as $naam => $waar ) {
	eis(
		sprintf( '"%s" staat in de lijst', $naam ),
		in_array( $naam, WSS_AI_Koppeling::MODULES, true ),
		'gebruikt in ' . implode( ', ', array_unique( $waar ) )
	);
}

echo "\nen de lijsten zelf kloppen\n";

$onbekend = array_diff( WSS_AI_Koppeling::OPTIN, WSS_AI_Koppeling::MODULES );
eis( 'elke opt-in module bestaat', array() === $onbekend, implode( ', ', $onbekend ) );
eis( 'Upgrades is geen opt-in', ! in_array( 'upgrades', WSS_AI_Koppeling::OPTIN, true ) );

/* De menutegels praten over dezelfde namen. Staat daar iets in wat de koppeling
   niet kent, dan toont het overzicht een tegel die nooit verschijnt. */
$menu = file_get_contents( $wortel . '/includes/class-wss-ai-menu.php' );
if ( preg_match_all( "/'module'\s*=>\s*'([^']+)'/", $menu, $m ) ) {
	foreach ( array_unique( $m[1] ) as $naam ) {
		eis( sprintf( 'tegel "%s" hoort bij een bestaande module', $naam ), in_array( $naam, WSS_AI_Koppeling::MODULES, true ) );
	}
}

printf(
	"\n%s\n",
	0 === $fouten ? 'na een installatie staat alles uit behalve Upgrades' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
