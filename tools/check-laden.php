<?php
/**
 * Controleert of elke klasse die de mailer gebruikt ook echt ingeladen wordt.
 *
 * WAAROM DIT BESTAAT
 * De popup ging bijna de deur uit met de bestanden er wel in maar de twee regels
 * die ze inladen er niet bij: die zaten in een bestand dat buiten de commit
 * viel. PHP lintte schoon, alle tests liepen, en de storing zou pas zichtbaar
 * zijn bij de eerste klik op het popup-scherm. Dan is het een witte pagina op
 * een webshop.
 *
 * Dezelfde familie fout als een kolom die niet in de CREATE TABLE staat: het
 * ontbrekende deel valt niet op omdat er niets kapot is aan wat er wél staat.
 *
 * Draaien: php tools/check-laden.php
 */

$wortel = dirname( __DIR__ );
$lader  = $wortel . '/includes/class-wss-ai-mailer.php';
$code   = file_get_contents( $lader );

if ( false === $code ) {
	fwrite( STDERR, "class-wss-ai-mailer.php niet gevonden\n" );
	exit( 1 );
}

/* ---------------- 1. wat wordt er ingeladen ---------------- */

if ( ! preg_match( '/foreach \( array\((.*?)\) as \$bestand \)/s', $code, $m ) ) {
	fwrite( STDERR, "de lijst met bestanden is niet herkend, is de lader verbouwd?\n" );
	exit( 1 );
}

preg_match_all( "/'([a-z0-9\/\-]+)'/", $m[1], $treffers );
$geladen = $treffers[1];

/* Van bestandsnaam naar klassenaam: het bestand zegt het zelf. */
$beschikbaar = array();
foreach ( $geladen as $bestand ) {
	$pad = $wortel . '/mailer/includes/' . $bestand . '.php';
	if ( ! file_exists( $pad ) ) {
		printf( "  FOUT de lader noemt %s, maar dat bestand bestaat niet\n", $bestand );
		exit( 1 );
	}
	if ( preg_match( '/class\s+(WSFM_\w+)/', file_get_contents( $pad ), $k ) ) {
		$beschikbaar[] = $k[1];
	}
}

/* Klassen die WordPress zelf of de plugin elders levert. */
$elders = array( 'WSFM_Provider_Factory', 'WSFM_Send_Result', 'WSFM_Mail_Provider', 'WSFM_Provider_SES', 'WSFM_Provider_Brevo' );

/* ---------------- 2. wat wordt er gebruikt ---------------- */

$bestanden = array_merge(
	glob( $wortel . '/mailer/includes/*.php' ),
	glob( $wortel . '/mailer/includes/providers/*.php' ),
	glob( $wortel . '/mailer/admin/*.php' ),
	array( $lader )
);

$fouten  = 0;
$gezien  = array();

foreach ( $bestanden as $bestand ) {
	$inhoud = file_get_contents( $bestand );
	$kort   = basename( $bestand );

	/* Een klasse die in dit bestand zelf gedefinieerd staat telt niet mee. */
	preg_match_all( '/class\s+(WSFM_\w+)/', $inhoud, $eigen );

	preg_match_all( '/\b(WSFM_\w+)::/', $inhoud, $statisch );
	preg_match_all( '/new\s+(WSFM_\w+)/', $inhoud, $nieuw );

	foreach ( array_unique( array_merge( $statisch[1], $nieuw[1] ) ) as $klasse ) {
		if ( in_array( $klasse, $eigen[1], true ) || in_array( $klasse, $elders, true ) ) {
			continue;
		}

		$gezien[ $klasse ] = true;

		if ( ! in_array( $klasse, $beschikbaar, true ) ) {
			printf( "  FOUT %s gebruikt %s, maar die klasse wordt niet ingeladen\n", $kort, $klasse );
			$fouten++;
		}
	}
}

/* ---------------- 3. en wat er wel geladen maar nooit gestart wordt ---------------- */

/* Klassen met een init() horen ook gestart te worden. Een module die je
   inlaadt maar niet start doet net zo weinig als een module die er niet is.

   Zoeken doen we in de lader EN in de mailerbestanden zelf: een module mag
   ook door een andere module gestart worden. WSFM_Cart_Tracking hangt zo aan
   de flow-engine, en dat is geen fout maar een keuze. */
$alle_code = $code;
foreach ( $bestanden as $bestand ) {
	$alle_code .= file_get_contents( $bestand );
}

foreach ( $beschikbaar as $klasse ) {
	$pad = '';
	foreach ( $geladen as $bestand ) {
		$kandidaat = $wortel . '/mailer/includes/' . $bestand . '.php';
		if ( preg_match( '/class\s+' . preg_quote( $klasse, '/' ) . '\b/', (string) file_get_contents( $kandidaat ) ) ) {
			$pad = $kandidaat;
			break;
		}
	}

	if ( '' === $pad ) {
		continue;
	}

	$heeft_init = preg_match( '/public static function init\s*\(/', file_get_contents( $pad ) );
	if ( $heeft_init && false === strpos( $alle_code, $klasse . '::init()' ) ) {
		printf( "  LET OP %s heeft een init() die nergens aangeroepen wordt\n", $klasse );
		$fouten++;
	}
}

printf(
	"\n%d bestanden ingeladen, %d klassen gebruikt: %s\n",
	count( $geladen ),
	count( $gezien ),
	0 === $fouten ? 'alles wordt ingeladen en gestart' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
