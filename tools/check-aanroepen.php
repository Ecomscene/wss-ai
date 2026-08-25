<?php
/**
 * Kloppen de aanroepen met de methodes die er zijn?
 *
 * WAAROM DIT BESTAAT
 * Twee echte fouten, allebei maandenlang onzichtbaar, allebei van dezelfde
 * familie: de aanroep en de methode waren het oneens en niemand merkte het.
 *
 *  1. process_newsletter_item() bestond, was netjes uitgewerkt, en werd nooit
 *     aangeroepen. Elke nieuwsbrief liep daardoor door de flow-tak, vond geen
 *     flow, en werd stil op "gestopt" gezet. Er kwam geen foutmelding, want er
 *     was geen fout: er was alleen een tak die niemand nam.
 *
 *  2. handle_failure( $item, array $step, ... ) werd op alle vier de plekken
 *     met een getal aangeroepen. Dat is een TypeError, en die valt pas op het
 *     moment dat er iets misgaat met versturen. Precies dan wil je juist dat
 *     de foutafhandeling het doet.
 *
 * php -l ziet dit allebei niet: elk bestand op zich is geldige PHP. De andere
 * controles ook niet, want die kijken naar schermen, schema en inladen.
 *
 * WAT DIT NIET IS
 * Geen typechecker. Er wordt alleen gekeken naar argumenten die letterlijk in
 * de code staan (een getal, een tekst, een array). Een variabele meegeven is
 * niet na te gaan zonder de code te draaien, dus daar zegt dit niets over. Dat
 * is met opzet: een controle die soms vals alarm geeft leert je hem wegkijken.
 *
 * Draaien: php tools/check-aanroepen.php
 */

$wortel = dirname( __DIR__ );

$bestanden = array();
foreach ( array( '/mailer/includes', '/includes', '/voorraad' ) as $map ) {
	if ( ! is_dir( $wortel . $map ) ) {
		continue;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wortel . $map ) );
	foreach ( $iterator as $bestand ) {
		if ( 'php' === $bestand->getExtension() ) {
			$bestanden[] = $bestand->getPathname();
		}
	}
}
sort( $bestanden );

$fouten  = 0;
$gezien  = 0;
$methode = 0;

/**
 * De betekenisvolle tokens, zonder witruimte en commentaar.
 *
 * @param string $code PHP-broncode.
 * @return array
 */
function tokens( $code ) {
	$uit = array();
	foreach ( token_get_all( $code ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}
		$uit[] = is_array( $t ) ? array( $t[0], $t[1], $t[2] ) : array( null, $t, 0 );
	}
	return $uit;
}

/**
 * De haakjes vanaf $i overslaan en teruggeven wat er per argument in stond.
 *
 * @param array $t Tokens.
 * @param int   $i Index van het openingshaakje.
 * @return array { eind, args } waarbij args een lijst van tokenlijsten is.
 */
function argumenten( array $t, $i ) {
	$diepte = 0;
	$args   = array();
	$nu     = array();

	for ( $n = count( $t ); $i < $n; $i++ ) {
		$tekst = $t[ $i ][1];

		if ( in_array( $tekst, array( '(', '[', '{' ), true ) ) {
			$diepte++;
			if ( 1 === $diepte ) {
				continue;
			}
		} elseif ( in_array( $tekst, array( ')', ']', '}' ), true ) ) {
			$diepte--;
			if ( 0 === $diepte ) {
				if ( $nu ) {
					$args[] = $nu;
				}
				return array( 'eind' => $i, 'args' => $args );
			}
		} elseif ( ',' === $tekst && 1 === $diepte ) {
			$args[] = $nu;
			$nu     = array();
			continue;
		}

		$nu[] = $t[ $i ];
	}

	return array( 'eind' => $i, 'args' => $args );
}

/**
 * Welke soort waarde staat hier letterlijk, of een lege tekst als het niet
 * met zekerheid te zeggen is.
 *
 * @param array $arg Tokens van een argument.
 * @return string int | string | array | bool | ''
 */
function soort_van( array $arg ) {
	if ( ! $arg ) {
		return '';
	}

	/* Een cast telt als de soort waar hij naartoe gaat: (int) $x is een int. */
	if ( T_INT_CAST === $arg[0][0] ) {
		return 'int';
	}
	if ( T_ARRAY_CAST === $arg[0][0] ) {
		return 'array';
	}

	/* Verder alleen als het argument uit precies een stuk bestaat; anders is
	   het een som, een aanroep of een variabele en weten we het niet. */
	if ( 1 !== count( $arg ) ) {
		return '';
	}

	if ( T_LNUMBER === $arg[0][0] || T_DNUMBER === $arg[0][0] ) {
		return 'int';
	}
	if ( T_CONSTANT_ENCAPSED_STRING === $arg[0][0] ) {
		return 'string';
	}
	if ( T_STRING === $arg[0][0] && in_array( strtolower( $arg[0][1] ), array( 'true', 'false' ), true ) ) {
		return 'bool';
	}

	return '';
}

foreach ( $bestanden as $bestand ) {
	$code = file_get_contents( $bestand );
	$kort = basename( $bestand );
	$t    = tokens( $code );
	$n    = count( $t );

	/* ---- de methodes in dit bestand ---- */

	$methodes = array();

	for ( $i = 0; $i < $n; $i++ ) {
		if ( T_FUNCTION !== $t[ $i ][0] || ! isset( $t[ $i + 1 ] ) || T_STRING !== $t[ $i + 1 ][0] ) {
			continue;
		}

		$naam = $t[ $i + 1 ][1];

		/* De zichtbaarheid staat vlak ervoor, eventueel met static ertussen. */
		$zichtbaar = 'public';
		for ( $k = $i - 1; $k >= 0 && $k > $i - 4; $k-- ) {
			if ( T_PRIVATE === $t[ $k ][0] ) {
				$zichtbaar = 'private';
				break;
			}
			if ( T_PROTECTED === $t[ $k ][0] ) {
				$zichtbaar = 'protected';
				break;
			}
			if ( T_PUBLIC === $t[ $k ][0] ) {
				break;
			}
		}

		$lijst  = argumenten( $t, $i + 2 );
		$hints  = array();
		foreach ( $lijst['args'] as $arg ) {
			$hint = '';
			foreach ( $arg as $stuk ) {
				if ( T_VARIABLE === $stuk[0] ) {
					break;
				}
				if ( T_ARRAY === $stuk[0] || ( T_STRING === $stuk[0] && in_array( strtolower( $stuk[1] ), array( 'int', 'string', 'bool', 'float', 'array' ), true ) ) ) {
					$hint = strtolower( $stuk[1] );
				}
			}
			$hints[] = $hint;
		}

		$methodes[ $naam ] = array(
			'regel'     => $t[ $i + 1 ][2],
			'zichtbaar' => $zichtbaar,
			'hints'     => $hints,
			'gebruikt'  => 0,
		);
		$methode++;
	}

	/* ---- en de aanroepen ---- */

	for ( $i = 1; $i < $n; $i++ ) {
		if ( T_STRING !== $t[ $i ][0] || ! isset( $t[ $i + 1 ] ) || '(' !== $t[ $i + 1 ][1] ) {
			continue;
		}
		if ( T_DOUBLE_COLON !== $t[ $i - 1 ][0] && T_OBJECT_OPERATOR !== $t[ $i - 1 ][0] ) {
			continue;
		}

		$naam = $t[ $i ][1];
		if ( ! isset( $methodes[ $naam ] ) ) {
			continue; // Een methode van een andere klasse; die staat elders.
		}

		$methodes[ $naam ]['gebruikt']++;

		$lijst = argumenten( $t, $i + 1 );
		foreach ( $lijst['args'] as $pos => $arg ) {
			if ( ! isset( $methodes[ $naam ]['hints'][ $pos ] ) || '' === $methodes[ $naam ]['hints'][ $pos ] ) {
				continue;
			}

			$hint  = $methodes[ $naam ]['hints'][ $pos ];
			$soort = soort_van( $arg );
			$gezien++;

			if ( '' === $soort || $soort === $hint ) {
				continue;
			}

			/* int waar float mag is in PHP prima. */
			if ( 'float' === $hint && 'int' === $soort ) {
				continue;
			}

			printf(
				"  FOUT %s regel %d: %s() krijgt argument %d als %s, maar de methode eist %s\n",
				$kort,
				$t[ $i ][2],
				$naam,
				$pos + 1,
				$soort,
				$hint
			);
			$fouten++;
		}
	}

	/* ---- methodes die niemand aanroept ---- */

	foreach ( $methodes as $naam => $m ) {
		if ( 'public' === $m['zichtbaar'] || $m['gebruikt'] > 0 ) {
			continue;
		}

		/* De magische methodes roept PHP zelf aan. Een private __construct is
		   juist de bedoeling bij een klasse die maar een keer mag bestaan. */
		if ( 0 === strpos( $naam, '__' ) ) {
			continue;
		}

		/* Een haak of callback noemt de methode bij naam in een tekst. Dat is
		   een echte aanroep, alleen niet een die je aan de tokens ziet. */
		if ( false !== strpos( $code, "'" . $naam . "'" ) || false !== strpos( $code, '"' . $naam . '"' ) ) {
			continue;
		}

		printf(
			"  FOUT %s regel %d: %s() wordt nergens aangeroepen, dus die tak wordt nooit genomen\n",
			$kort,
			$m['regel'],
			$naam
		);
		$fouten++;
	}
}

printf(
	"\n%d methodes en %d argumenten nagelopen: %s\n",
	$methode,
	$gezien,
	0 === $fouten ? 'elke aanroep past bij zijn methode' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
