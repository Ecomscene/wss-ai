<?php
/**
 * Doet de reparatie van oude welkomstcodes wat hij belooft, en niet meer?
 *
 * WAAROM DIT BESTAAT
 * Dit is het enige stuk van de plugin dat uit zichzelf klantdata aanpast, op
 * tientallen live webshops, zonder dat iemand erom vraagt. Er zijn eerder twee
 * klantsites plat gegaan door een PHP-write. Een routine die coupons opent en
 * opslaat verdient dus dat je hem natelt voordat hij weggaat, en niet daarna.
 *
 * Vier dingen moeten kloppen:
 *
 *  1. Hij raakt alleen coupons aan die in onze eigen inschrijvingentabel staan.
 *     Een handmatig gemaakte kortingscode van de winkelier hoort hij niet te
 *     zien, laat staan aan te passen.
 *  2. Hij haalt alleen de e-mailbeperking weg. Bedrag, limiet en vervaldatum
 *     blijven precies zoals ze waren.
 *  3. Hij loopt af. Een portie per beheerpagina, en op een gegeven moment is
 *     het stil. Een lus die blijft draaien op elke beheerpagina van dertig
 *     webshops is erger dan de fout die hij repareert.
 *  4. Hij slaat niets over. Als de portiegrootte niet precies opgaat mag er
 *     geen gat vallen, want die klant krijgt dan alsnog de foutmelding.
 *
 * Draaien: php tools/check-couponreparatie.php
 */

define( 'ABSPATH', '/' );

/* ---------------- WordPress en WooCommerce nagebootst ---------------- */

$GLOBALS['opties'] = array();

function get_option( $naam, $standaard = false ) {
	return array_key_exists( $naam, $GLOBALS['opties'] ) ? $GLOBALS['opties'][ $naam ] : $standaard;
}

function update_option( $naam, $waarde, $autoload = null ) {
	$GLOBALS['opties'][ $naam ] = $waarde;
	return true;
}

function add_action() {}
function __( $t, $d = '' ) { return $t; }

/**
 * De coupons zoals ze in de shop staan.
 *
 * Een zetter verandert hier alleen het exemplaar in het geheugen; pas save()
 * schrijft het naar de winkel. Dat is niet netjes doen om het netjes doen: een
 * nabootsing die meteen wegschrijft laat een vergeten save() ongemerkt door, en
 * dan slaagt de controle terwijl er in het echt niets gerepareerd wordt.
 */
class Proef_Coupon {
	public static $winkel = array();
	public static $opgeslagen = 0;

	private $id;
	private $email;

	public function __construct( $id ) {
		$this->id    = $id;
		$this->email = self::$winkel[ $id ]['email'];
	}

	public function get_email_restrictions() {
		return $this->email;
	}

	public function set_email_restrictions( $lijst ) {
		$this->email = $lijst;
	}

	public function save() {
		self::$opgeslagen++;
		self::$winkel[ $this->id ]['email'] = $this->email;
		return $this->id;
	}
}

class_alias( 'Proef_Coupon', 'WC_Coupon' );

function wc_get_coupon_id_by_code( $code ) {
	foreach ( Proef_Coupon::$winkel as $id => $c ) {
		if ( $c['code'] === $code ) {
			return $id;
		}
	}
	return 0;
}

/** Een wpdb die alleen de ene query kent die de reparatie stelt. */
class Proef_Wpdb {
	public $prefix = 'wp_';
	public static $inschrijvingen = array();
	public $queries = 0;

	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
		}
		return $sql;
	}

	public function get_results( $sql ) {
		$this->queries++;

		if ( ! preg_match( '/id > (\d+).*LIMIT (\d+)/s', $sql, $m ) ) {
			throw new Exception( 'onverwachte query: ' . $sql );
		}

		$vanaf = (int) $m[1];
		$limit = (int) $m[2];

		$uit = array();
		foreach ( self::$inschrijvingen as $rij ) {
			if ( $rij['id'] > $vanaf && '' !== $rij['coupon_code'] ) {
				$uit[] = (object) $rij;
			}
		}
		usort( $uit, static function ( $a, $b ) { return $a->id - $b->id; } );

		return array_slice( $uit, 0, $limit );
	}
}

require_once dirname( __DIR__ ) . '/mailer/includes/class-popup.php';

/* ---------------- de controle ---------------- */

$fouten = 0;

/**
 * @param string $wat  Wat er getoetst wordt.
 * @param bool   $goed Klopt het.
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

/**
 * De proefshop opnieuw opzetten.
 *
 * @param int $aantal Hoeveel welkomstcodes er zijn.
 * @return void
 */
function zet_klaar( $aantal ) {
	$GLOBALS['opties']            = array();
	Proef_Coupon::$winkel         = array();
	Proef_Coupon::$opgeslagen     = 0;
	Proef_Wpdb::$inschrijvingen   = array();

	for ( $n = 1; $n <= $aantal; $n++ ) {
		Proef_Coupon::$winkel[ 100 + $n ] = array(
			'code'    => 'WELKOM-' . $n,
			'email'   => array( 'klant' . $n . '@voorbeeld.nl' ),
			'bedrag'  => 10.0,
			'limiet'  => 1,
			'vervalt' => 1790000000,
		);
		Proef_Wpdb::$inschrijvingen[] = array( 'id' => $n, 'coupon_code' => 'WELKOM-' . $n );
	}

	/* Een kortingscode die de winkelier zelf heeft gemaakt. Die staat niet in
	   onze tabel en hoort dus onaangeraakt te blijven. */
	Proef_Coupon::$winkel[ 999 ] = array(
		'code'    => 'ZOMER25',
		'email'   => array( 'vaste.klant@voorbeeld.nl' ),
		'bedrag'  => 25.0,
		'limiet'  => 0,
		'vervalt' => 0,
	);

	$GLOBALS['wpdb'] = new Proef_Wpdb();
}

/**
 * De reparatie draaien tot hij zegt dat hij klaar is.
 *
 * @param int $maximaal Noodrem.
 * @return int Aantal rondes.
 */
function draai_tot_klaar( $maximaal = 200 ) {
	$rondes = 0;
	while ( $rondes < $maximaal && 'klaar' !== get_option( 'wsfm_coupon_reparatie', '0' ) ) {
		WSFM_Popup::repareer_codes();
		$rondes++;
	}
	return $rondes;
}

echo "een shop met 70 welkomstcodes, portie van 30\n";

zet_klaar( 70 );
$rondes = draai_tot_klaar();

eis( 'hij loopt af', 'klaar' === get_option( 'wsfm_coupon_reparatie' ), 'stand: ' . var_export( get_option( 'wsfm_coupon_reparatie' ), true ) );
eis( 'in drie rondes, dus 30 per keer', 3 === $rondes, 'rondes: ' . $rondes );

$blijft = 0;
foreach ( Proef_Coupon::$winkel as $id => $c ) {
	if ( 999 !== $id && $c['email'] ) {
		$blijft++;
	}
}
eis( 'geen enkele welkomstcode heeft nog een beperking', 0 === $blijft, $blijft . ' over' );

/* Dit is de val bij porties: 70 gaat niet op in 30. Valt er een gat, dan krijgt
   die klant alsnog de foutmelding, en juist die vindt niemand terug. */
eis( 'ook de laatste tien zijn meegenomen', ! Proef_Coupon::$winkel[170]['email'] );

echo "\nwat hij met rust laat\n";

eis(
	'de code van de winkelier zelf is niet aangeraakt',
	array( 'vaste.klant@voorbeeld.nl' ) === Proef_Coupon::$winkel[999]['email']
);
eis( 'het kortingsbedrag staat er nog', 10.0 === Proef_Coupon::$winkel[101]['bedrag'] );
eis( 'de gebruikslimiet staat er nog', 1 === Proef_Coupon::$winkel[101]['limiet'] );
eis( 'de vervaldatum staat er nog', 1790000000 === Proef_Coupon::$winkel[101]['vervalt'] );

echo "\nen daarna is het stil\n";

$voor    = Proef_Coupon::$opgeslagen;
$queries = $GLOBALS['wpdb']->queries;
for ( $n = 0; $n < 5; $n++ ) {
	WSFM_Popup::repareer_codes();
}
eis( 'geen enkele opslag meer', $voor === Proef_Coupon::$opgeslagen );
eis( 'en zelfs geen query meer', $queries === $GLOBALS['wpdb']->queries );

echo "\neen shop die nooit een popup heeft gehad\n";

zet_klaar( 0 );
$rondes = draai_tot_klaar();
eis( 'is meteen klaar', 1 === $rondes, 'rondes: ' . $rondes );
eis( 'en heeft niets opgeslagen', 0 === Proef_Coupon::$opgeslagen );

echo "\nprecies een volle portie\n";

/* De grens: bij precies 30 denkt hij "misschien is er meer" en doet nog een
   ronde. Dat mag, als hij daarna maar stopt. */
zet_klaar( 30 );
$rondes = draai_tot_klaar();
eis( 'loopt ook dan af', 'klaar' === get_option( 'wsfm_coupon_reparatie' ) );
eis( 'en heeft alles gehad', ! Proef_Coupon::$winkel[130]['email'] );

echo "\nals een code al weg is uit de shop\n";

zet_klaar( 5 );
unset( Proef_Coupon::$winkel[103] );
$rondes = draai_tot_klaar();
eis( 'dat houdt de rest niet op', 'klaar' === get_option( 'wsfm_coupon_reparatie' ) );
eis( 'de codes erna zijn wel gedaan', ! Proef_Coupon::$winkel[105]['email'] );

printf(
	"\n%s\n",
	0 === $fouten ? 'de reparatie doet wat hij belooft en niets daarbuiten' : $fouten . ' probleem(en)'
);

exit( 0 === $fouten ? 0 : 1 );
