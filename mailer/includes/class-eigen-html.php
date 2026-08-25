<?php
/**
 * Een nieuwsbrief die de klant zelf als HTML aanlevert.
 *
 * WAAROM DIT APART STAAT
 * De samensteller bouwt een mail op uit blokken en zet daar zelf een kop, een
 * voet en een afmeldlink omheen. Een aangeleverde mail is al af: eigen <head>,
 * eigen stijlen, eigen voet. Daar mogen we niets omheen zetten, want dan staat
 * er een tweede <html> in het document en verschilt wat de klant in zijn
 * ontwerpprogramma zag van wat er in de inbox ligt.
 *
 * Er blijven dan drie dingen over die wij wel moeten doen.
 *
 * 1. DE AFMELDLINK. Die is wettelijk verplicht en staat in de aangeleverde
 *    HTML meestal wel, maar niet in ons dialect. Wie uit Laposta of Mailchimp
 *    komt heeft %UNSUBSCRIBELINK% of *|UNSUB|* staan. Vertalen wij dat niet,
 *    dan staat die tekst er letterlijk in en kan niemand zich afmelden. Staat
 *    er helemaal geen afmeldlink, dan plakken we er zelf een voet onder. Beter
 *    een voet die de klant niet ontworpen heeft dan post die niet mag.
 *
 * 2. DE ROMMEL ERUIT. Scriptjes horen niet in een mail; elke mailclient gooit
 *    ze weg en een spamfilter kijkt er scheef naar. Let op wat dit niet is: een
 *    waterdichte grens. Wie dit invult heeft manage_woocommerce en kan op de
 *    meeste shops toch al plugins installeren. Het echte slot zit op het
 *    voorbeeldvenster, dat in een afgeschermd frame staat.
 *
 * 3. VERTELLEN WAT ER STRAKS MISGAAT. Een pad als /wp-content/foto.jpg werkt
 *    prima in een browser en levert in een inbox een leeg vlak op. Dat merk je
 *    normaal pas nadat de post weg is. Daarom controle(): die kijkt naar de
 *    dingen die een verstuurde mail stukmaken en zegt het terwijl je nog kunt
 *    bijsturen.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Eigen_Html {

	/**
	 * Boven deze grootte knipt Gmail de mail af en zet er "bericht ingekort"
	 * onder. De afmeldlink staat onderaan en valt dan dus weg.
	 */
	const GMAIL_GRENS = 102400;

	/**
	 * Merge-tags van andere pakketten, vertaald naar de onze.
	 *
	 * Iemand die overstapt neemt zijn oude mails mee. Die tags herkennen kost
	 * ons een regel en scheelt hem een middag zoeken naar waarom er
	 * "%FIRSTNAME%" in de inbox van zijn klanten staat.
	 *
	 * @return array vreemde tag => onze tag
	 */
	public static function vreemde_tags() {
		return array(
			/* Laposta en MailerLite. */
			'%FIRSTNAME%'                => '{first_name}',
			'%FIRST_NAME%'               => '{first_name}',
			'%NAME%'                     => '{first_name}',
			'%UNSUBSCRIBELINK%'          => '{unsubscribe_url}',
			'%UNSUBSCRIBE_URL%'          => '{unsubscribe_url}',
			'%UNSUBSCRIBE%'              => '{unsubscribe_url}',
			'%SENDER-INFO-SINGLELINE%'   => '{sender_info}',
			'%SENDER_INFO_SINGLELINE%'   => '{sender_info}',
			/* Wij kennen geen webversie van een nieuwsbrief. Naar de winkel
			   wijzen is beter dan de tag laten staan, want dan wordt het een
			   relatief adres en loopt de klant op een 404. controle() zegt er
			   wel wat van, want "bekijk in je browser" hoort niet op de
			   homepage uit te komen. */
			/* Wij kennen geen webversie van een nieuwsbrief. Naar de winkel
			   wijzen is beter dan de tag laten staan, want dan wordt het een
			   relatief adres en loopt de lezer op een 404. controle() zegt er
			   wel wat van, want "bekijk in je browser" hoort niet zomaar op
			   de homepage uit te komen. */
			'%WEBVERSION%'               => '{shop_url}',
			/* Mailchimp. */
			'*|FNAME|*'                  => '{first_name}',
			'*|FIRSTNAME|*'              => '{first_name}',
			'*|UNSUB|*'                  => '{unsubscribe_url}',
			'*|LIST:COMPANY|*'           => '{shop_name}',
			'*|HTML:LIST_ADDRESS_HTML|*' => '{sender_info}',
			/* Brevo. */
			'{{ contact.FIRSTNAME }}'    => '{first_name}',
			'{{ unsubscribe }}'          => '{unsubscribe_url}',
		);
	}

	/**
	 * Vreemde tags omzetten naar de onze.
	 *
	 * @param string $html Aangeleverde HTML.
	 * @return string
	 */
	public static function vertaal_tags( $html ) {
		$kaart = self::vreemde_tags();

		return str_replace( array_keys( $kaart ), array_values( $kaart ), (string) $html );
	}

	/**
	 * Staat er ergens een afmeldlink in?
	 *
	 * Wordt aangeroepen na het vertalen, dus we hoeven maar op een vorm te
	 * kijken.
	 *
	 * @param string $html HTML met onze tags erin.
	 * @return bool
	 */
	public static function heeft_afmeldlink( $html ) {
		return false !== strpos( (string) $html, '{unsubscribe_url}' );
	}

	/**
	 * De voet die we eronder zetten als de klant er zelf geen heeft.
	 *
	 * Bewust saai en zonder eigen kleuren: hij hoort op te vallen als iets dat
	 * er niet bij hoort, zodat de klant hem zelf gaat vervangen.
	 *
	 * @return string
	 */
	public static function noodvoet() {
		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="border-collapse:collapse;background:#ffffff;"><tr>'
			. '<td align="center" style="padding:20px 24px;font-family:Arial,Helvetica,sans-serif;'
			. 'font-size:11px;line-height:1.7;color:#8c8c8c;">'
			. esc_html__( 'Je ontvangt deze mail omdat je je hebt ingeschreven bij', 'ws-flow-mailer' )
			. ' {shop_name}.<br>'
			. '<a href="{unsubscribe_url}" style="color:#8c8c8c;text-decoration:underline;">'
			. esc_html__( 'Uitschrijven', 'ws-flow-mailer' )
			. '</a><br>{sender_info}'
			. '</td></tr></table>';
	}

	/**
	 * De voet erin zetten, het liefst nog binnen de body.
	 *
	 * Achter </body> plakken werkt in de meeste clients ook, maar Outlook zet
	 * zulke inhoud soms buiten de opmaak van de rest. Dus liever ervoor.
	 *
	 * @param string $html Aangeleverde HTML.
	 * @return string
	 */
	private static function plak_voet( $html ) {
		$voet = self::noodvoet();
		$plek = strripos( $html, '</body>' );

		if ( false === $plek ) {
			return $html . $voet;
		}

		return substr( $html, 0, $plek ) . $voet . substr( $html, $plek );
	}

	/**
	 * Scriptjes en klik-handlers eruit.
	 *
	 * Alleen binnen tags kijken en niet in de tekst: "on air = altijd" is een
	 * gewone zin en die mag niet stiekem veranderen. Attributen die zelf een
	 * > bevatten lopen hier mis, maar dat is in een mail zo zeldzaam dat de
	 * eenvoud het waard is. Zie ook de uitleg bovenaan: dit is netheid, geen
	 * beveiliging.
	 *
	 * @param string $html Aangeleverde HTML.
	 * @return string
	 */
	public static function schoon( $html ) {
		$html = (string) $html;

		/* Eerst de scriptblokken in hun geheel, inhoud en al. */
		$html = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $html );
		$html = preg_replace( '#</?script\b[^>]*>#i', '', (string) $html );

		/* En dan per tag de handlers en de javascript:-adressen. */
		$html = preg_replace_callback(
			'#<[a-z][^>]*>#i',
			static function ( $treffer ) {
				$tag = preg_replace( '#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $treffer[0] );

				return (string) preg_replace(
					'#\s(href|src|background)\s*=\s*(["\']?)\s*javascript:[^"\'>\s]*\2#i',
					'',
					(string) $tag
				);
			},
			(string) $html
		);

		return null === $html ? '' : $html;
	}

	/**
	 * De HTML zoals hij de deur uit gaat, nog zonder ingevulde tags.
	 *
	 * Opschonen gebeurt hier nog een keer en niet alleen bij het opslaan. Een
	 * rij die al in de database stond voordat schoon() bestond gaat anders
	 * ongezien de post in.
	 *
	 * @param object $brief Nieuwsbrief.
	 * @return string
	 */
	public static function body( $brief ) {
		$html = isset( $brief->eigen_html ) ? (string) $brief->eigen_html : '';
		$html = self::vertaal_tags( self::schoon( $html ) );

		if ( ! self::heeft_afmeldlink( $html ) ) {
			$html = self::plak_voet( $html );
		}

		return $html;
	}

	/**
	 * Wat er straks misgaat, terwijl je er nog wat aan kunt doen.
	 *
	 * @param string $ruw Aangeleverde HTML, zoals ingetypt.
	 * @return array lijst van array( soort, tekst )
	 */
	public static function controle( $ruw ) {
		$ruw = (string) $ruw;
		$uit = array();

		if ( '' === trim( $ruw ) ) {
			return $uit;
		}

		$html = self::vertaal_tags( $ruw );

		if ( ! self::heeft_afmeldlink( $html ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er staat geen afmeldlink in. We zetten er zelf een voet onder, maar mooier is het als je zelf {unsubscribe_url} in je eigen ontwerp zet.', 'ws-flow-mailer' ),
			);
		}

		/* Een pad zonder domein wijst in een inbox nergens heen. Dit is de fout
		   die het vaakst voorkomt: in de browser zag alles er goed uit. */
		if ( preg_match( '#\ssrc\s*=\s*["\'](?!https?://|cid:|data:)[^"\']+#i', $html ) ) {
			$uit[] = array(
				'soort' => 'fout',
				'tekst' => __( 'Er staan afbeeldingen met een pad in plaats van een volledig adres. Die blijven in de mail leeg. Gebruik overal het hele adres, dus https://jouwshop.nl/...', 'ws-flow-mailer' ),
			);
		}

		if ( preg_match( '#\ssrc\s*=\s*["\']http://#i', $html ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er staan afbeeldingen op http:// in plaats van https://. Sommige mailprogramma\'s laden die niet.', 'ws-flow-mailer' ),
			);
		}

		if ( false !== strpos( $ruw, '%WEBVERSION%' ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er staat een link naar de webversie in. Die kennen wij niet, dus die komt op je homepage uit. Haal dat regeltje weg.', 'ws-flow-mailer' ),
			);
		}

		if ( preg_match( '#<link\b[^>]*stylesheet#i', $html ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er wordt een los stijlbestand geladen. Mailprogramma\'s doen dat niet. Zet de opmaak in de tags zelf.', 'ws-flow-mailer' ),
			);
		}

		if ( preg_match( '#<form\b#i', $html ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er staat een formulier in. De meeste mailprogramma\'s halen dat weg. Werk liever met een knop die naar je website linkt.', 'ws-flow-mailer' ),
			);
		}

		if ( preg_match( '#<script\b#i', $ruw ) ) {
			$uit[] = array(
				'soort' => 'let-op',
				'tekst' => __( 'Er stond een scriptje in. Dat hebben we weggehaald, want in een mail werkt het toch niet.', 'ws-flow-mailer' ),
			);
		}

		$grootte = strlen( $html );
		if ( $grootte > self::GMAIL_GRENS ) {
			$uit[] = array(
				'soort' => 'fout',
				'tekst' => sprintf(
					/* translators: %s: grootte in kilobytes. */
					__( 'De mail is %s kB. Boven de 100 kB knipt Gmail hem af en valt de onderkant weg, inclusief de afmeldlink. Kort de tekst in of haal opmaak weg.', 'ws-flow-mailer' ),
					number_format_i18n( round( $grootte / 1024 ) )
				),
			);
		}

		return $uit;
	}
}
