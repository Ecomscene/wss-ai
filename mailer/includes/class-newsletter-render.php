<?php
/**
 * De drie nieuwsbriefsjablonen en de blokken die erin passen.
 *
 * WAAROM MAAR DRIE SJABLONEN EN MAAR DRIE BLOKSOORTEN
 * Een nieuwsbrief die alles kan, wordt zelden mooi. De winkelier die er niet
 * dagelijks mee werkt bouwt dan iets scheefs, en dat gaat naar zijn hele
 * klantenbestand. Daarom staat de vormgeving vast en gaat de keuze alleen over
 * de inhoud: een afbeelding, een stuk tekst, een rij producten. Meer heeft een
 * nieuwsbrief van een kleine webshop niet nodig.
 *
 * WAAROM DIT HTML UIT DE VORIGE EEUW IS
 * Tabellen, inline stijlen, geen flexbox, geen grid, geen externe stylesheet.
 * Niet uit nostalgie: Outlook rendert met de opmaakmotor van Word, en Gmail
 * gooit een <style>-blok weg. Wat hier modern geschreven wordt, komt bij een
 * deel van de ontvangers als een kolom losse woorden aan.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Newsletter_Render {

	/**
	 * De sjablonen, met een omschrijving voor de keuzelijst.
	 *
	 * @return array key => { naam, kort }
	 */
	public static function templates() {
		return array(
			'rustig' => array(
				'naam' => __( 'Rustig', 'ws-flow-mailer' ),
				'kort' => __( 'Wit, veel ruimte, rechte hoeken. Past bij bijna elke shop.', 'ws-flow-mailer' ),
			),
			'warm'   => array(
				'naam' => __( 'Warm', 'ws-flow-mailer' ),
				'kort' => __( 'Zachte achtergrond, ronde hoeken, klassieke koppen.', 'ws-flow-mailer' ),
			),
			'strak'  => array(
				'naam' => __( 'Strak', 'ws-flow-mailer' ),
				'kort' => __( 'Foto\'s over de volle breedte, kleine hoofdletters, drie producten naast elkaar.', 'ws-flow-mailer' ),
			),
		);
	}

	/**
	 * De vormgeving van een sjabloon als losse waarden.
	 *
	 * Alles wat de drie van elkaar onderscheidt staat hier, en nergens anders.
	 * Zo blijft er één opbouw voor alle drie, en kan een vierde sjabloon later
	 * een tabelregel zijn in plaats van een nieuwe renderfunctie.
	 *
	 * @param string $sjabloon Sjabloonsleutel.
	 * @return array
	 */
	private static function stijl( $sjabloon ) {
		$sjablonen = array(
			'rustig' => array(
				'pagina'      => '#ffffff',
				'kaart'       => '#ffffff',
				'tekst'       => '#1f1f1f',
				'zacht'       => '#767676',
				'lijn'        => '#e6e6e6',
				'font'        => 'Helvetica, Arial, sans-serif',
				'kopfont'     => 'Helvetica, Arial, sans-serif',
				'kopgrootte'  => '24px',
				'kopgewicht'  => '600',
				'kopspatie'   => 'normal',
				'kophoofdlet' => 'none',
				'knopbg'      => '#1f1f1f',
				'knoptekst'   => '#ffffff',
				'knoprond'    => '4px',
				'rond'        => '0',
				'zijmarge'    => 32,
				'blokruimte'  => 32,
				'kolommen'    => 2,
				'vollebreed'  => false,
			),
			'warm'   => array(
				'pagina'      => '#f6f1ea',
				'kaart'       => '#ffffff',
				'tekst'       => '#33302c',
				'zacht'       => '#8a827a',
				'lijn'        => '#ece5db',
				'font'        => 'Helvetica, Arial, sans-serif',
				'kopfont'     => 'Georgia, "Times New Roman", serif',
				'kopgrootte'  => '26px',
				'kopgewicht'  => '400',
				'kopspatie'   => 'normal',
				'kophoofdlet' => 'none',
				'knopbg'      => '#a3604a',
				'knoptekst'   => '#ffffff',
				'knoprond'    => '999px',
				'rond'        => '10px',
				'zijmarge'    => 32,
				'blokruimte'  => 32,
				'kolommen'    => 2,
				'vollebreed'  => false,
			),
			'strak'  => array(
				'pagina'      => '#ffffff',
				'kaart'       => '#ffffff',
				'tekst'       => '#000000',
				'zacht'       => '#7a7a7a',
				'lijn'        => '#000000',
				'font'        => 'Helvetica, Arial, sans-serif',
				'kopfont'     => 'Helvetica, Arial, sans-serif',
				'kopgrootte'  => '15px',
				'kopgewicht'  => '700',
				'kopspatie'   => '1.5px',
				'kophoofdlet' => 'uppercase',
				'knopbg'      => '#000000',
				'knoptekst'   => '#ffffff',
				'knoprond'    => '0',
				'rond'        => '0',
				'zijmarge'    => 24,
				'blokruimte'  => 24,
				'kolommen'    => 3,
				'vollebreed'  => true,
			),
		);

		return isset( $sjablonen[ $sjabloon ] ) ? $sjablonen[ $sjabloon ] : $sjablonen['rustig'];
	}

	/**
	 * De hele nieuwsbrief als HTML-body.
	 *
	 * De merge-tags blijven staan; die worden pas per ontvanger ingevuld door
	 * WSFM_Template_Engine. Zo wordt deze zware opbouw één keer gedaan en niet
	 * duizend keer.
	 *
	 * @param object $brief Rij uit wsfm_newsletters (blokken al gedecodeerd).
	 * @return string
	 */
	public static function render( $brief ) {
		$s       = self::stijl( isset( $brief->template ) ? $brief->template : 'rustig' );
		$blokken = isset( $brief->blocks ) && is_array( $brief->blocks ) ? $brief->blocks : array();

		$binnen = '';
		foreach ( $blokken as $blok ) {
			$binnen .= self::blok( $blok, $s );
		}

		if ( '' === $binnen ) {
			$binnen = self::rij(
				'<p style="margin:0;color:' . $s['zacht'] . ';">' . esc_html__( 'Deze nieuwsbrief is nog leeg.', 'ws-flow-mailer' ) . '</p>',
				$s
			);
		}

		return '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:' . $s['pagina'] . ';">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $s['pagina'] . ';">'
			. '<tr><td align="center" style="padding:24px 12px;">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:' . $s['kaart'] . ';border-radius:' . $s['rond'] . ';overflow:hidden;font-family:' . $s['font'] . ';color:' . $s['tekst'] . ';">'
			. self::kop( $s )
			. $binnen
			. self::voet( $s )
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * De kop: het logo van de shop als dat er is, anders de naam.
	 *
	 * Geen keuzeveld in het scherm. Een shop heeft één logo, dat staat al in
	 * WordPress, en iemand die het per nieuwsbrief wil wisselen heeft een ander
	 * probleem dan een nieuwsbrief.
	 *
	 * @param array $s Stijlwaarden.
	 * @return string
	 */
	private static function kop( array $s ) {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		$midden  = '';

		if ( $logo_id ) {
			$src = wp_get_attachment_image_url( $logo_id, 'medium' );
			if ( $src ) {
				$midden = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" width="150" style="display:block;margin:0 auto;max-width:150px;height:auto;border:0;">';
			}
		}

		if ( '' === $midden ) {
			$midden = '<span style="font-family:' . $s['kopfont'] . ';font-size:20px;font-weight:600;color:' . $s['tekst'] . ';">'
				. esc_html( get_bloginfo( 'name' ) ) . '</span>';
		}

		return '<tr><td align="center" style="padding:28px ' . $s['zijmarge'] . 'px 4px ' . $s['zijmarge'] . 'px;">'
			. '<a href="{shop_url}" style="text-decoration:none;color:' . $s['tekst'] . ';">' . $midden . '</a>'
			. '</td></tr>';
	}

	/**
	 * De voet met de afmeldlink.
	 *
	 * Die link is geen nette bijkomstigheid maar wettelijk verplicht, en hij
	 * staat daarom in de opbouw en niet in een blok dat je kunt weglaten.
	 *
	 * @param array $s Stijlwaarden.
	 * @return string
	 */
	private static function voet( array $s ) {
		return '<tr><td style="padding:8px ' . $s['zijmarge'] . 'px 32px ' . $s['zijmarge'] . 'px;">'
			. '<div style="border-top:1px solid ' . $s['lijn'] . ';padding-top:16px;font-size:12px;line-height:1.6;color:' . $s['zacht'] . ';">'
			. esc_html__( 'Je krijgt deze e-mail omdat je bij ons besteld hebt.', 'ws-flow-mailer' ) . ' '
			. '<a href="{unsubscribe_url}" style="color:' . $s['zacht'] . ';">' . esc_html__( 'Afmelden', 'ws-flow-mailer' ) . '</a><br>'
			. '<a href="{shop_url}" style="color:' . $s['zacht'] . ';">{shop_name}</a>'
			. '</div></td></tr>';
	}

	/**
	 * Een gewone rij met zijmarge.
	 *
	 * @param string $inhoud HTML.
	 * @param array  $s      Stijlwaarden.
	 * @param bool   $vol    Zonder zijmarge (voor beeld over de volle breedte).
	 * @return string
	 */
	private static function rij( $inhoud, array $s, $vol = false ) {
		$zij = $vol ? 0 : $s['zijmarge'];
		return '<tr><td style="padding:' . ( $s['blokruimte'] / 2 ) . 'px ' . $zij . 'px;">' . $inhoud . '</td></tr>';
	}

	/**
	 * Eén blok naar HTML.
	 *
	 * @param array $blok Blokgegevens.
	 * @param array $s    Stijlwaarden.
	 * @return string
	 */
	private static function blok( array $blok, array $s ) {
		$soort = isset( $blok['soort'] ) ? $blok['soort'] : '';

		if ( 'afbeelding' === $soort ) {
			return self::blok_afbeelding( $blok, $s );
		}
		if ( 'tekst' === $soort ) {
			return self::blok_tekst( $blok, $s );
		}
		if ( 'producten' === $soort ) {
			return self::blok_producten( $blok, $s );
		}
		if ( 'code' === $soort ) {
			return self::blok_code( $blok, $s );
		}

		return '';
	}

	/**
	 * Afbeeldingsblok.
	 *
	 * @param array $blok Blokgegevens.
	 * @param array $s    Stijlwaarden.
	 * @return string
	 */
	private static function blok_afbeelding( array $blok, array $s ) {
		$id = isset( $blok['afbeelding'] ) ? (int) $blok['afbeelding'] : 0;
		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_url( $id, 'large' );
		if ( ! $src ) {
			return '';
		}

		$alt   = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$breed = $s['vollebreed'] ? 600 : ( 600 - 2 * $s['zijmarge'] );
		$rond  = $s['vollebreed'] ? '0' : $s['rond'];

		$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" width="' . $breed . '"'
			. ' style="display:block;width:100%;max-width:' . $breed . 'px;height:auto;border:0;border-radius:' . $rond . ';">';

		$link = isset( $blok['link'] ) ? trim( (string) $blok['link'] ) : '';
		if ( '' !== $link ) {
			$img = '<a href="' . esc_url( $link ) . '" style="text-decoration:none;">' . $img . '</a>';
		}

		return self::rij( $img, $s, $s['vollebreed'] );
	}

	/**
	 * Een kortingscode, groot en om over te typen.
	 *
	 * Staat niet in de blokkenkiezer van de nieuwsbrief: hij komt alleen uit de
	 * welkomstmail van de popup. Wel hier, want die mail hoort er hetzelfde uit
	 * te zien als de rest van wat deze winkel verstuurt.
	 *
	 * Een gestippelde rand en veel ruimte eromheen: dit is het enige wat de
	 * ontvanger moet zien, en het moet leesbaar zijn op een telefoon in de zon.
	 *
	 * @param array $blok Blokgegevens.
	 * @param array $s    Stijlwaarden.
	 * @return string
	 */
	private static function blok_code( array $blok, array $s ) {
		$code = isset( $blok['code'] ) ? trim( (string) $blok['code'] ) : '';
		if ( '' === $code ) {
			return '';
		}

		$onder = isset( $blok['onder'] ) ? trim( (string) $blok['onder'] ) : '';

		$uit = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
			. '<tr><td align="center" style="border:2px dashed ' . $s['lijn'] . ';border-radius:' . $s['rond'] . ';padding:22px 16px;">'
			. '<span style="display:block;font-family:' . $s['font'] . ';font-size:26px;font-weight:700;letter-spacing:2px;color:' . $s['tekst'] . ';">'
			. esc_html( $code ) . '</span>'
			. ( '' === $onder ? '' : '<span style="display:block;margin-top:8px;font-size:13px;color:' . $s['zacht'] . ';">' . esc_html( $onder ) . '</span>' )
			. '</td></tr></table>';

		return self::rij( $uit, $s );
	}

	/**
	 * Tekstblok: kop, alinea's en een knop.
	 *
	 * Alinea's worden op lege regels gesplitst en niet op enters. Wie in een
	 * tekstvak op enter drukt bedoelt meestal een nieuwe regel, niet een nieuwe
	 * alinea met witruimte erboven.
	 *
	 * @param array $blok Blokgegevens.
	 * @param array $s    Stijlwaarden.
	 * @return string
	 */
	private static function blok_tekst( array $blok, array $s ) {
		$uit = '';

		$kop = isset( $blok['kop'] ) ? trim( (string) $blok['kop'] ) : '';
		if ( '' !== $kop ) {
			$uit .= '<h2 style="margin:0 0 12px 0;font-family:' . $s['kopfont'] . ';font-size:' . $s['kopgrootte']
				. ';font-weight:' . $s['kopgewicht'] . ';letter-spacing:' . $s['kopspatie']
				. ';text-transform:' . $s['kophoofdlet'] . ';color:' . $s['tekst'] . ';line-height:1.3;">'
				. esc_html( $kop ) . '</h2>';
		}

		$tekst = isset( $blok['tekst'] ) ? trim( (string) $blok['tekst'] ) : '';
		if ( '' !== $tekst ) {
			$alineas = preg_split( '/\n\s*\n/', str_replace( "\r\n", "\n", $tekst ) );
			foreach ( $alineas as $alinea ) {
				$alinea = trim( $alinea );
				if ( '' === $alinea ) {
					continue;
				}
				$uit .= '<p style="margin:0 0 14px 0;font-size:15px;line-height:1.7;color:' . $s['tekst'] . ';">'
					. nl2br( esc_html( $alinea ) ) . '</p>';
			}
		}

		$knop = isset( $blok['knop'] ) ? trim( (string) $blok['knop'] ) : '';
		$url  = isset( $blok['knop_url'] ) ? trim( (string) $blok['knop_url'] ) : '';
		if ( '' !== $knop && '' !== $url ) {
			$uit .= '<div style="margin:20px 0 4px 0;">'
				. '<a href="' . esc_url( $url ) . '" style="background:' . $s['knopbg'] . ';color:' . $s['knoptekst']
				. ';padding:13px 26px;text-decoration:none;border-radius:' . $s['knoprond']
				. ';display:inline-block;font-size:14px;font-weight:600;">' . esc_html( $knop ) . '</a></div>';
		}

		return '' === $uit ? '' : self::rij( $uit, $s );
	}

	/**
	 * Productenblok: een raster met foto, naam en prijs.
	 *
	 * Het aantal kolommen hoort bij het sjabloon en niet bij de winkelier: vier
	 * producten naast elkaar in een mail van 600 pixels breed levert postzegels
	 * op, en dat is geen keuze die je iemand moet laten maken.
	 *
	 * @param array $blok Blokgegevens.
	 * @param array $s    Stijlwaarden.
	 * @return string
	 */
	private static function blok_producten( array $blok, array $s ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$ids = isset( $blok['producten'] ) && is_array( $blok['producten'] ) ? $blok['producten'] : array();
		if ( empty( $ids ) ) {
			return '';
		}

		$kolommen = (int) $s['kolommen'];
		$binnen   = 600 - 2 * $s['zijmarge'];
		$tussen   = 12;
		$cel      = (int) floor( ( $binnen - ( $kolommen - 1 ) * $tussen ) / $kolommen );

		$uit   = '';
		$kop   = isset( $blok['kop'] ) ? trim( (string) $blok['kop'] ) : '';
		if ( '' !== $kop ) {
			$uit .= '<h2 style="margin:0 0 14px 0;font-family:' . $s['kopfont'] . ';font-size:' . $s['kopgrootte']
				. ';font-weight:' . $s['kopgewicht'] . ';letter-spacing:' . $s['kopspatie']
				. ';text-transform:' . $s['kophoofdlet'] . ';color:' . $s['tekst'] . ';line-height:1.3;">'
				. esc_html( $kop ) . '</h2>';
		}

		$rijen = array_chunk( $ids, $kolommen );

		foreach ( $rijen as $rij ) {
			$uit .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:' . $tussen . 'px;"><tr>';

			foreach ( $rij as $index => $product_id ) {
				$uit .= '<td width="' . $cel . '" valign="top" style="width:' . $cel . 'px;padding-right:'
					. ( $index === count( $rij ) - 1 ? 0 : $tussen ) . 'px;">'
					. self::product_cel( (int) $product_id, $s, $cel )
					. '</td>';
			}

			/* De laatste rij aanvullen met lege cellen, anders rekken drie
			   producten in een raster van drie zich uit tot halve pagina's. */
			for ( $i = count( $rij ); $i < $kolommen; $i++ ) {
				$uit .= '<td width="' . $cel . '" style="width:' . $cel . 'px;">&nbsp;</td>';
			}

			$uit .= '</tr></table>';
		}

		return self::rij( $uit, $s );
	}

	/**
	 * Eén product in het raster.
	 *
	 * @param int   $product_id Product-id.
	 * @param array $s          Stijlwaarden.
	 * @param int   $breedte    Celbreedte in pixels.
	 * @return string
	 */
	private static function product_cel( $product_id, array $s, $breedte ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '&nbsp;';
		}

		$link = get_permalink( $product_id );
		$beeld = '';

		$beeld_id = (int) $product->get_image_id();
		if ( $beeld_id ) {
			$src = wp_get_attachment_image_url( $beeld_id, 'medium_large' );
			if ( $src ) {
				$beeld = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $product->get_name() ) . '" width="' . $breedte . '"'
					. ' style="display:block;width:100%;max-width:' . $breedte . 'px;height:auto;border:0;border-radius:' . $s['rond'] . ';margin-bottom:8px;">';
			}
		}

		$prijs = html_entity_decode( wp_strip_all_tags( (string) $product->get_price_html() ), ENT_QUOTES, 'UTF-8' );

		return '<a href="' . esc_url( $link ) . '" style="text-decoration:none;color:' . $s['tekst'] . ';">'
			. $beeld
			. '<span style="display:block;font-size:14px;line-height:1.4;color:' . $s['tekst'] . ';">' . esc_html( $product->get_name() ) . '</span>'
			. ( '' === $prijs ? '' : '<span style="display:block;font-size:14px;line-height:1.6;color:' . $s['zacht'] . ';">' . esc_html( $prijs ) . '</span>' )
			. '</a>';
	}
}
