<?php
/**
 * Controleert of elke kolom die de code wegschrijft ook echt in het schema staat.
 *
 * WAAROM DIT BESTAAT
 * Bij de nieuwsbrief kwam de kolom newsletter_id wel in de insert terecht maar
 * niet in de CREATE TABLE. Alles lintte schoon, alles laadde, en pas bij het
 * eerste echte verzendmoment zou blijken dat er nul regels in de wachtrij
 * kwamen. Dat soort gemis ziet een mens niet en een script wel.
 *
 * HOE HET DE TABEL VINDT
 * De code schrijft nooit naar een letterlijke tabelnaam maar naar
 * WSFM_Queue::table() of naar een $table die daar een paar regels eerder uit
 * gevuld is. Dus worden eerst die helpers en die variabelen opgezocht, en pas
 * daarna de inserts. Een doel dat niet te herleiden is wordt overgeslagen EN
 * genoemd; een controle die stilletjes niets doet is erger dan geen controle.
 *
 * Draaien: php tools/check-schema.php
 */

$wortel     = dirname( __DIR__ );
$bestanden  = glob( $wortel . '/mailer/includes/*.php' );
$schema_php = file_get_contents( $wortel . '/mailer/includes/class-install.php' );

if ( false === $schema_php || empty( $bestanden ) ) {
	fwrite( STDERR, "de mailer-bestanden zijn niet gevonden\n" );
	exit( 1 );
}

/* ---------------- 1. de kolommen per tabel uit het schema ---------------- */

$tabellen = array();
if ( preg_match_all( '/CREATE TABLE \{\$prefix\}(\w+) \((.*?)\) \$charset_collate/s', $schema_php, $treffers, PREG_SET_ORDER ) ) {
	foreach ( $treffers as $t ) {
		$kolommen = array();
		foreach ( explode( "\n", $t[2] ) as $regel ) {
			$regel = trim( $regel );
			if ( '' === $regel || preg_match( '/^(PRIMARY KEY|KEY|UNIQUE KEY)/i', $regel ) ) {
				continue;
			}
			if ( preg_match( '/^(\w+)\s/', $regel, $k ) ) {
				$kolommen[] = $k[1];
			}
		}
		$tabellen[ $t[1] ] = $kolommen;
	}
}

if ( empty( $tabellen ) ) {
	fwrite( STDERR, "geen enkele CREATE TABLE herkend, klopt het patroon nog?\n" );
	exit( 1 );
}

/* ---------------- 2. welke helper welke tabel oplevert ---------------- */

/**
 * Een uitdrukking als "$wpdb->prefix . 'wsfm_queue'" naar de naam brengen.
 *
 * @param string $expr Stukje PHP.
 * @return string Tabelnaam, of leeg.
 */
function tabel_uit( $expr ) {
	return preg_match( "/'(wsfm_\w+)'/", (string) $expr, $m ) ? $m[1] : '';
}

$helpers = array(); // "WSFM_Queue::table" => "wsfm_queue"
$klassen = array(); // bestandspad => klassenaam

foreach ( $bestanden as $bestand ) {
	$code = file_get_contents( $bestand );

	if ( ! preg_match( '/class\s+(WSFM_\w+)/', $code, $k ) ) {
		continue;
	}
	$klasse                 = $k[1];
	$klassen[ $bestand ]    = $klasse;

	if ( preg_match_all( '/function\s+(\w*table)\s*\(\s*\)\s*\{(.*?)\n\t\}/s', $code, $treffers, PREG_SET_ORDER ) ) {
		foreach ( $treffers as $t ) {
			$naam = tabel_uit( $t[2] );
			if ( '' !== $naam ) {
				$helpers[ $klasse . '::' . $t[1] ] = $naam;
			}
		}
	}
}

/* ---------------- 3. elke insert en update nalopen ---------------- */

$fouten      = 0;
$gezien      = 0;
$overgeslagen = array();

foreach ( $bestanden as $bestand ) {
	$code   = file_get_contents( $bestand );
	$kort   = basename( $bestand );
	$klasse = isset( $klassen[ $bestand ] ) ? $klassen[ $bestand ] : '';

	/* De $table-variabelen in dit bestand, zodat $wpdb->insert( $table, ... )
	   ook herleidbaar is. */
	$variabelen = array();
	if ( preg_match_all( '/\$(\w+)\s*=\s*([^;]+);/', $code, $treffers, PREG_SET_ORDER ) ) {
		foreach ( $treffers as $t ) {
			$rechts = $t[2];
			$naam   = tabel_uit( $rechts );

			if ( '' === $naam && preg_match( '/(self|WSFM_\w+)::(\w*table)\s*\(/', $rechts, $h ) ) {
				$sleutel = ( 'self' === $h[1] ? $klasse : $h[1] ) . '::' . $h[2];
				$naam    = isset( $helpers[ $sleutel ] ) ? $helpers[ $sleutel ] : '';
			}

			if ( '' !== $naam ) {
				$variabelen[ $t[1] ] = $naam;
			}
		}
	}

	if ( ! preg_match_all( '/\$wpdb->(insert|update)\(\s*([^,]+),\s*array\((.*?)\n\s*\)/s', $code, $treffers, PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $treffers as $t ) {
		$doel  = trim( $t[2] );
		$tabel = tabel_uit( $doel );

		if ( '' === $tabel && preg_match( '/(self|WSFM_\w+)::(\w*table)\s*\(/', $doel, $h ) ) {
			$sleutel = ( 'self' === $h[1] ? $klasse : $h[1] ) . '::' . $h[2];
			$tabel   = isset( $helpers[ $sleutel ] ) ? $helpers[ $sleutel ] : '';
		}

		if ( '' === $tabel && preg_match( '/^\$(\w+)$/', $doel, $v ) ) {
			$tabel = isset( $variabelen[ $v[1] ] ) ? $variabelen[ $v[1] ] : '';
		}

		if ( '' === $tabel || ! isset( $tabellen[ $tabel ] ) ) {
			$overgeslagen[] = $kort . ': ' . $doel;
			continue;
		}

		preg_match_all( "/'(\w+)'\s*=>/", $t[3], $sleutels );

		foreach ( $sleutels[1] as $kolom ) {
			$gezien++;
			if ( ! in_array( $kolom, $tabellen[ $tabel ], true ) ) {
				printf( "  FOUT %s schrijft naar %s.%s, maar die kolom staat niet in het schema\n", $kort, $tabel, $kolom );
				$fouten++;
			}
		}
	}
}

foreach ( array_unique( $overgeslagen ) as $regel ) {
	printf( "  overgeslagen (doel niet te herleiden): %s\n", $regel );
}

printf(
	"\n%d tabellen, %d kolomverwijzingen gecontroleerd, %d overgeslagen: %s\n",
	count( $tabellen ),
	$gezien,
	count( array_unique( $overgeslagen ) ),
	0 === $fouten ? 'alles staat in het schema' : $fouten . ' ontbreken'
);

exit( 0 === $fouten ? 0 : 1 );
