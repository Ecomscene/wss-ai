<?php
/**
 * Black Friday: een eigen plek in wp-admin voor de Black Friday-actie.
 *
 * WAAROM EEN EIGEN HOOFDMENU-ITEM
 * Joey wil (19-11-2026) bij alle maandklanten dezelfde plek waar de Black
 * Friday-actie wordt klaargezet, met structuur en bij iedereen gelijk.
 * Daarom een hoofdmenu-item bovenin, direct onder WSS Tools, en niet ergens
 * onder een submenu waar niemand het vindt.
 *
 * WAT ER OP STAAT
 *  1. De actie: per regel wat de actie is en van wanneer tot wanneer, met een
 *     indicator of hij live is. Die kijkt naar de datum EN naar de producten
 *     zelf: een actie die volgens de kalender loopt maar waarvan de prijzen
 *     niet op de producten staan is niet live, en dat wil je zien.
 *  2. Een popup, aan of uit.
 *  3. Een melding bovenin de winkel, aan of uit, met eigen tekst.
 *  4. Een vak waarin de winkelier in gewone taal vraagt om iets aan te passen.
 *     Dat loopt via het eigen ChatGPT van de winkel (zie
 *     class-wss-ai-eigen-ai.php) en verandert alleen wat op deze pagina staat.
 *
 * WAT DEZE MODULE NIET DOET
 * Hij verandert geen prijzen. De actieprijzen zet Webshopschool klaar als
 * ingeplande aanbieding in WooCommerce; deze pagina laat alleen zien of ze er
 * staan. Dat is de regel van deze plugin: geen schrijfacties buiten onze eigen
 * instellingen.
 *
 * ALLES STAAT UIT TOT IEMAND HET AANZET
 * Een lege of nieuwe pagina toont niets aan bezoekers. De popup en de melding
 * hebben elk een eigen schakelaar, en die staan standaard uit.
 *
 * LOS VAN DE NIEUWSBRIEF
 * De popup lijkt op die van de mailer, maar leunt er niet op: de mailer is een
 * eigen module die bij veel winkels uit staat. Daarom eigen opmaak en eigen
 * namen (wssbf-), zodat de twee elkaar ook niet in de weg zitten als ze
 * allebei aanstaan.
 *
 * OPT-IN
 * Alleen maandklanten krijgen dit, geen proefperiodes. Daarom staat de module
 * in de OPTIN-lijst van class-wss-ai-koppeling.php: hij verschijnt pas als
 * Webshopschool hem voor deze webshop aanzet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Blackfriday {

	const SLUG   = 'wss-ai-blackfriday';
	const OPTIE  = 'wss_ai_bf';
	const VORIGE = 'wss_ai_bf_vorige';

	/** Meer product-ID's dan dit tellen we niet na: de pagina moet vlot blijven. */
	const MAX_IDS = 400;

	/** Zodat de melding niet twee keer verschijnt als het thema wp_body_open kent. */
	private static $balk_getoond = false;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'beheer_opmaak' ) );
		add_action( 'admin_post_wss_ai_bf_opslaan', array( __CLASS__, 'opslaan' ) );
		add_action( 'admin_post_wss_ai_bf_prompt', array( __CLASS__, 'prompt' ) );
		add_action( 'admin_post_wss_ai_bf_terug', array( __CLASS__, 'terugzetten' ) );

		/* De voorkant. Pas op 'wp' beslissen: dan weet WordPress welke pagina
		   het is, en hoeft een winkel waar alles uit staat niets te laden. */
		add_action( 'wp', array( __CLASS__, 'voorkant' ) );
	}

	/** Mag deze webshop de Black Friday-pagina zien? */
	public static function beschikbaar() {
		return WSS_AI_Koppeling::module_aan( 'blackfriday' );
	}

	/* ------------------------------------------------------------------
	 * Instellingen
	 * ------------------------------------------------------------------ */

	public static function standaard() {
		return array(
			'acties' => array(),
			'popup'  => array(
				'aan'       => 0,
				'kop'       => 'Black Friday',
				'tekst'     => '',
				'knop'      => 'Bekijk de actie',
				'link'      => '',
				'beeld'     => '',
				'van'       => '',
				'tot'       => '',
				'bg'        => '#111111',
				'kleur'     => '#ffffff',
				'knopkleur' => '#ffffff',
				'knoptekst' => '#111111',
			),
			'balk'   => array(
				'aan'   => 0,
				'tekst' => '',
				'link'  => '',
				'van'   => '',
				'tot'   => '',
				'bg'    => '#111111',
				'kleur' => '#ffffff',
			),
		);
	}

	public static function instellingen() {
		$s = self::standaard();
		$o = get_option( self::OPTIE, array() );
		if ( ! is_array( $o ) ) {
			return $s;
		}
		return array(
			'acties' => isset( $o['acties'] ) && is_array( $o['acties'] ) ? array_values( $o['acties'] ) : array(),
			'popup'  => array_merge( $s['popup'], isset( $o['popup'] ) && is_array( $o['popup'] ) ? $o['popup'] : array() ),
			'balk'   => array_merge( $s['balk'], isset( $o['balk'] ) && is_array( $o['balk'] ) ? $o['balk'] : array() ),
		);
	}

	/** Een datum als 2026-11-23, of leeg. Al het andere is geen datum. */
	private static function datum( $v ) {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
	}

	private static function kleur( $v, $terugval ) {
		$k = sanitize_hex_color( is_scalar( $v ) ? (string) $v : '' );
		return $k ? $k : $terugval;
	}

	/** Een lijst product-ID's uit tekst ("12, 15 18") of uit een lijst. */
	private static function ids( $v ) {
		if ( ! is_array( $v ) ) {
			$v = preg_split( '/[^0-9]+/', is_scalar( $v ) ? (string) $v : '' );
		}
		$uit = array();
		foreach ( (array) $v as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$uit[ $id ] = $id;
			}
		}
		return array_values( $uit );
	}

	/**
	 * Maak van wat er binnenkomt nette instellingen.
	 *
	 * Eén functie voor het formulier en voor het antwoord van de AI. Wat de AI
	 * terugstuurt is tekst van buiten en krijgt dus precies dezelfde behandeling
	 * als wat iemand intikt.
	 *
	 * @param array $in        Wat er binnenkomt.
	 * @param array $oud       De huidige instellingen.
	 * @param bool  $ids_vast  Bij de AI: de gekoppelde producten blijven wat ze
	 *                         waren. Die koppeling bepaalt wat de indicator
	 *                         natelt, en dat laten we niet aan een taalmodel.
	 * @return array
	 */
	public static function schoon( $in, $oud, $ids_vast = false ) {
		$s   = self::standaard();
		$uit = array( 'acties' => array() );

		$oude_ids = array();
		foreach ( $oud['acties'] as $a ) {
			if ( ! empty( $a['sleutel'] ) ) {
				$oude_ids[ $a['sleutel'] ] = isset( $a['ids'] ) ? self::ids( $a['ids'] ) : array();
			}
		}

		$acties = isset( $in['acties'] ) && is_array( $in['acties'] ) ? $in['acties'] : array();
		foreach ( $acties as $a ) {
			if ( ! is_array( $a ) || ! empty( $a['weg'] ) ) {
				continue;
			}
			$titel = isset( $a['titel'] ) ? sanitize_text_field( $a['titel'] ) : '';
			if ( '' === $titel ) {
				continue;
			}
			$sleutel = isset( $a['sleutel'] ) ? sanitize_key( $a['sleutel'] ) : '';
			if ( '' === $sleutel ) {
				$sleutel = 'a' . substr( md5( $titel . wp_rand() ), 0, 8 );
			}
			if ( $ids_vast ) {
				$ids = isset( $oude_ids[ $sleutel ] ) ? $oude_ids[ $sleutel ] : array();
			} else {
				$ids = self::ids( isset( $a['ids'] ) ? $a['ids'] : '' );
			}
			$uit['acties'][] = array(
				'sleutel' => $sleutel,
				'titel'   => $titel,
				'van'     => self::datum( isset( $a['van'] ) ? $a['van'] : '' ),
				'tot'     => self::datum( isset( $a['tot'] ) ? $a['tot'] : '' ),
				'ids'     => array_slice( $ids, 0, self::MAX_IDS ),
			);
			if ( count( $uit['acties'] ) >= 20 ) {
				break;
			}
		}

		$p            = isset( $in['popup'] ) && is_array( $in['popup'] ) ? $in['popup'] : array();
		$op           = $oud['popup'];
		$uit['popup'] = array(
			'aan'       => empty( $p['aan'] ) ? 0 : 1,
			'kop'       => sanitize_text_field( isset( $p['kop'] ) ? $p['kop'] : $op['kop'] ),
			'tekst'     => sanitize_textarea_field( isset( $p['tekst'] ) ? $p['tekst'] : $op['tekst'] ),
			'knop'      => sanitize_text_field( isset( $p['knop'] ) ? $p['knop'] : $op['knop'] ),
			'link'      => esc_url_raw( isset( $p['link'] ) ? $p['link'] : $op['link'] ),
			'beeld'     => esc_url_raw( isset( $p['beeld'] ) ? $p['beeld'] : $op['beeld'] ),
			'van'       => self::datum( isset( $p['van'] ) ? $p['van'] : $op['van'] ),
			'tot'       => self::datum( isset( $p['tot'] ) ? $p['tot'] : $op['tot'] ),
			'bg'        => self::kleur( isset( $p['bg'] ) ? $p['bg'] : $op['bg'], $s['popup']['bg'] ),
			'kleur'     => self::kleur( isset( $p['kleur'] ) ? $p['kleur'] : $op['kleur'], $s['popup']['kleur'] ),
			'knopkleur' => self::kleur( isset( $p['knopkleur'] ) ? $p['knopkleur'] : $op['knopkleur'], $s['popup']['knopkleur'] ),
			'knoptekst' => self::kleur( isset( $p['knoptekst'] ) ? $p['knoptekst'] : $op['knoptekst'], $s['popup']['knoptekst'] ),
		);

		$b           = isset( $in['balk'] ) && is_array( $in['balk'] ) ? $in['balk'] : array();
		$ob          = $oud['balk'];
		$uit['balk'] = array(
			'aan'   => empty( $b['aan'] ) ? 0 : 1,
			'tekst' => sanitize_text_field( isset( $b['tekst'] ) ? $b['tekst'] : $ob['tekst'] ),
			'link'  => esc_url_raw( isset( $b['link'] ) ? $b['link'] : $ob['link'] ),
			'van'   => self::datum( isset( $b['van'] ) ? $b['van'] : $ob['van'] ),
			'tot'   => self::datum( isset( $b['tot'] ) ? $b['tot'] : $ob['tot'] ),
			'bg'    => self::kleur( isset( $b['bg'] ) ? $b['bg'] : $ob['bg'], $s['balk']['bg'] ),
			'kleur' => self::kleur( isset( $b['kleur'] ) ? $b['kleur'] : $ob['kleur'], $s['balk']['kleur'] ),
		);

		return $uit;
	}

	/**
	 * Opslaan, met de vorige stand ernaast.
	 *
	 * De vorige stand bewaren we omdat de AI hier ook schrijft. Een opdracht die
	 * anders uitpakt dan bedoeld moet met één klik terug kunnen.
	 */
	private static function bewaar( $nieuw ) {
		update_option( self::VORIGE, self::instellingen(), false );
		update_option( self::OPTIE, $nieuw, true );
		self::leeg_paginacache();
	}

	/**
	 * De popup en de melding staan in de pagina zelf. Bij een winkel met
	 * paginacache ziet een bezoeker een wijziging dus pas na het legen ervan.
	 * Alleen de bekende haken; kennen we de cache niet, dan doen we niets.
	 */
	private static function leeg_paginacache() {
		try {
			do_action( 'litespeed_purge_all' );
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/* ------------------------------------------------------------------
	 * De indicator
	 * ------------------------------------------------------------------ */

	private static function vandaag() {
		return function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
	}

	/** Valt vandaag binnen dit venster? Een lege datum is een open kant. */
	private static function binnen( $van, $tot ) {
		$nu = self::vandaag();
		if ( '' !== $van && $nu < $van ) {
			return false;
		}
		if ( '' !== $tot && $nu > $tot ) {
			return false;
		}
		return true;
	}

	/**
	 * Hoe staan de gekoppelde producten ervoor?
	 *
	 * 'actief' is wat een bezoeker nu met korting ziet. 'klaar' is of er een
	 * actieprijs op het product staat, ook als die pas later ingaat: zo zie je
	 * voor de start al of alles is klaargezet.
	 *
	 * @param int[] $ids Product-ID's.
	 * @return array { totaal, actief, klaar }
	 */
	private static function tel( $ids ) {
		$uit = array( 'totaal' => 0, 'actief' => 0, 'klaar' => 0 );
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $uit;
		}
		foreach ( array_slice( (array) $ids, 0, self::MAX_IDS ) as $id ) {
			try {
				$p = wc_get_product( $id );
				if ( ! $p ) {
					continue;
				}
				$uit['totaal']++;
				if ( $p->is_on_sale() ) {
					$uit['actief']++;
				}
				$klaar = '' !== (string) get_post_meta( $id, '_sale_price', true );
				if ( ! $klaar && $p->is_type( 'variable' ) ) {
					foreach ( $p->get_children() as $kind ) {
						if ( '' !== (string) get_post_meta( $kind, '_sale_price', true ) ) {
							$klaar = true;
							break;
						}
					}
				}
				if ( $klaar ) {
					$uit['klaar']++;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
		return $uit;
	}

	/**
	 * De stand van één actie.
	 *
	 * @param array $a De actie.
	 * @return array { soort: wacht|live|let-op|voorbij|onbekend, label, uitleg }
	 */
	public static function stand( $a ) {
		$van = isset( $a['van'] ) ? $a['van'] : '';
		$tot = isset( $a['tot'] ) ? $a['tot'] : '';
		$ids = isset( $a['ids'] ) ? (array) $a['ids'] : array();
		$t   = $ids ? self::tel( $ids ) : null;
		$nu  = self::vandaag();

		if ( '' === $van && '' === $tot ) {
			return array(
				'soort'  => 'onbekend',
				'label'  => __( 'Geen datum', 'wss-ai' ),
				'uitleg' => __( 'Vul een begin- en einddatum in.', 'wss-ai' ),
			);
		}

		if ( '' !== $van && $nu < $van ) {
			$uitleg = '';
			if ( $t ) {
				/* translators: 1: aantal klaar, 2: totaal */
				$uitleg = sprintf( __( 'Actieprijs staat klaar bij %1$d van %2$d producten.', 'wss-ai' ), $t['klaar'], $t['totaal'] );
				if ( $t['actief'] > 0 ) {
					/* translators: %d: aantal producten */
					$uitleg .= ' ' . sprintf( __( 'Let op: %d staan nu al in de aanbieding.', 'wss-ai' ), $t['actief'] );
				}
			}
			return array(
				'soort'  => $t && $t['klaar'] < $t['totaal'] ? 'let-op' : 'wacht',
				'label'  => __( 'Nog niet gestart', 'wss-ai' ),
				'uitleg' => $uitleg,
			);
		}

		if ( '' !== $tot && $nu > $tot ) {
			$uitleg = '';
			if ( $t && $t['actief'] > 0 ) {
				/* translators: %d: aantal producten */
				$uitleg = sprintf( __( 'Let op: %d producten staan nog in de aanbieding.', 'wss-ai' ), $t['actief'] );
			}
			return array(
				'soort'  => '' !== $uitleg ? 'let-op' : 'voorbij',
				'label'  => __( 'Afgelopen', 'wss-ai' ),
				'uitleg' => $uitleg,
			);
		}

		if ( $t && $t['actief'] < $t['totaal'] ) {
			return array(
				'soort'  => 'let-op',
				'label'  => __( 'Loopt, maar niet overal', 'wss-ai' ),
				/* translators: 1: aantal actief, 2: totaal */
				'uitleg' => sprintf( __( 'De actieprijs staat bij %1$d van %2$d producten aan.', 'wss-ai' ), $t['actief'], $t['totaal'] ),
			);
		}

		return array(
			'soort'  => 'live',
			'label'  => __( 'Live', 'wss-ai' ),
			'uitleg' => $t
				/* translators: %d: aantal producten */
				? sprintf( __( 'De actieprijs staat bij alle %d producten aan.', 'wss-ai' ), $t['totaal'] )
				: '',
		);
	}

	private static function nette_datum( $d ) {
		if ( '' === $d ) {
			return '';
		}
		$t = strtotime( $d . ' 12:00:00' );
		return $t ? date_i18n( 'j F Y', $t ) : $d;
	}

	/* ------------------------------------------------------------------
	 * De voorkant
	 * ------------------------------------------------------------------ */

	public static function voorkant() {
		if ( is_admin() || ! self::beschikbaar() ) {
			return;
		}
		$i     = self::instellingen();
		$popup = $i['popup']['aan'] && '' !== $i['popup']['kop'] && self::nog_niet_voorbij( $i['popup']['tot'] );
		$balk  = $i['balk']['aan'] && '' !== $i['balk']['tekst'] && self::nog_niet_voorbij( $i['balk']['tot'] );
		if ( ! $popup && ! $balk ) {
			return;
		}
		if ( ! self::hier_tonen() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'voorkant_opmaak' ) );
		if ( $balk ) {
			add_action( 'wp_body_open', array( __CLASS__, 'toon_balk' ), 1 );
			add_action( 'wp_footer', array( __CLASS__, 'toon_balk' ), 4 );
		}
		if ( $popup ) {
			add_action( 'wp_footer', array( __CLASS__, 'toon_popup' ), 5 );
		}
	}

	/**
	 * Na de einddatum zetten we niets meer in de pagina. Het begin regelt het
	 * script in de browser: een pagina uit de cache van gisteren moet vandaag
	 * de melding kunnen tonen zonder dat iemand om middernacht de cache leegt.
	 */
	private static function nog_niet_voorbij( $tot ) {
		return '' === $tot || self::vandaag() <= $tot;
	}

	/** Niet in de weg zitten waar iemand staat af te rekenen. */
	private static function hier_tonen() {
		if ( is_feed() || is_404() ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
			return false;
		}
		return true;
	}

	public static function voorkant_opmaak() {
		wp_register_style( 'wssbf', false, array(), WSS_AI_VERSIE );
		wp_enqueue_style( 'wssbf' );
		wp_add_inline_style( 'wssbf', self::css() );

		wp_register_script( 'wssbf', '', array(), WSS_AI_VERSIE, true );
		wp_enqueue_script( 'wssbf' );
		wp_add_inline_script( 'wssbf', self::js() );
	}

	/** De opmaak van popup en melding. Ook gebruikt voor het voorbeeld in wp-admin. */
	private static function css() {
		return '.wssbf-balk{display:block;box-sizing:border-box;width:100%;padding:10px 16px;text-align:center;'
			. 'font-size:14px;line-height:1.4;font-weight:600;text-decoration:none;'
			. 'background:var(--wssbf-bg,#111);color:var(--wssbf-kleur,#fff)}'
			. '.wssbf-balk[hidden]{display:none}'
			. 'a.wssbf-balk:hover,a.wssbf-balk:focus{color:var(--wssbf-kleur,#fff);text-decoration:underline}'
			. '.wssbf-laag{position:fixed;inset:0;z-index:999998;display:none;align-items:center;justify-content:center;'
			. 'padding:20px;background:rgba(0,0,0,.55);opacity:0;transition:opacity .25s ease}'
			. '.wssbf-laag.is-open{display:flex;opacity:1}'
			. '.wssbf-popup{position:relative;display:flex;width:100%;max-width:820px;max-height:calc(100vh - 40px);'
			. 'overflow:hidden;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.35);'
			. 'background:var(--wssbf-bg,#111);color:var(--wssbf-kleur,#fff)}'
			. '.wssbf-popup-tekst{flex:1 1 50%;box-sizing:border-box;padding:44px 40px;display:flex;flex-direction:column;'
			. 'justify-content:center;min-width:0}'
			. '.wssbf-popup-beeld{flex:1 1 50%;min-width:0}'
			. '.wssbf-popup-beeld img{display:block;width:100%;height:100%;object-fit:cover}'
			. '.wssbf-popup h2{margin:0 0 12px;font-size:38px;line-height:1.1;font-weight:800;color:inherit}'
			. '.wssbf-popup p{margin:0 0 22px;font-size:16px;line-height:1.55;color:inherit;white-space:pre-line}'
			. '.wssbf-popup-knop{display:block;box-sizing:border-box;width:100%;padding:14px 18px;border-radius:6px;'
			. 'text-align:center;font-size:16px;font-weight:700;text-decoration:none;'
			. 'background:var(--wssbf-knop,#fff);color:var(--wssbf-knoptekst,#111)}'
			. '.wssbf-popup-knop:hover,.wssbf-popup-knop:focus{color:var(--wssbf-knoptekst,#111);opacity:.9}'
			. '.wssbf-sluit{position:absolute;top:10px;right:10px;width:36px;height:36px;border:0;border-radius:50%;'
			. 'background:rgba(255,255,255,.9);color:#111;font-size:22px;line-height:1;cursor:pointer;padding:0}'
			. '@media (max-width:780px){.wssbf-popup{max-width:440px}.wssbf-popup-beeld{display:none}'
			. '.wssbf-popup-tekst{padding:40px 26px 30px}.wssbf-popup h2{font-size:30px}}'
			. '@media (prefers-reduced-motion:reduce){.wssbf-laag{transition:none}}';
	}

	/**
	 * Het script van de voorkant. Zonder jQuery: niet elk thema laadt dat.
	 *
	 * De datum wordt hier nog eens nagekeken, in de browser. De pagina kan uit
	 * de cache komen en dan is wat PHP gisteren besloot niet meer waar.
	 */
	private static function js() {
		return '(function(){'
			. 'function d(){var n=new Date();return n.getFullYear()+"-"+("0"+(n.getMonth()+1)).slice(-2)+"-"+("0"+n.getDate()).slice(-2);}'
			. 'function mag(e){var v=e.getAttribute("data-van")||"",t=e.getAttribute("data-tot")||"",n=d();'
			. 'return !(v&&n<v)&&!(t&&n>t);}'
			. 'function start(){'
			. 'var b=document.querySelector(".wssbf-balk");'
			. 'if(b){if(mag(b)){if(b.hasAttribute("data-onderaan")&&document.body.firstChild!==b){'
			. 'document.body.insertBefore(b,document.body.firstChild);}b.hidden=false;}else{b.hidden=true;}}'
			. 'var l=document.querySelector(".wssbf-laag");'
			. 'if(!l||!mag(l)){return;}'
			. 'if(document.cookie.indexOf("wssbf_popup=1")!==-1){return;}'
			. 'function sluit(){l.classList.remove("is-open");l.hidden=true;'
			. 'var e=new Date();e.setTime(e.getTime()+864e5);'
			. 'document.cookie="wssbf_popup=1; expires="+e.toUTCString()+"; path=/; SameSite=Lax";}'
			. 'function open(){l.hidden=false;window.requestAnimationFrame(function(){l.classList.add("is-open");});}'
			. 'l.addEventListener("click",function(e){'
			. 'if(e.target===l||(e.target.closest&&e.target.closest(".wssbf-sluit"))){e.preventDefault();sluit();}'
			. 'else if(e.target.closest&&e.target.closest(".wssbf-popup-knop")){sluit();}});'
			. 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&l.classList.contains("is-open")){sluit();}});'
			. 'window.setTimeout(open,3000);'
			. '}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",start);}else{start();}'
			. '})();';
	}

	/**
	 * De melding bovenin.
	 *
	 * @param array|null $i         Instellingen van de balk; leeg = de opgeslagen.
	 * @param bool       $voorbeeld In wp-admin: altijd zichtbaar, zonder datum.
	 * @return string
	 */
	public static function balk_html( $i = null, $voorbeeld = false ) {
		if ( ! is_array( $i ) ) {
			$alles = self::instellingen();
			$i     = $alles['balk'];
		}
		$stijl = sprintf( '--wssbf-bg:%s;--wssbf-kleur:%s', esc_attr( $i['bg'] ), esc_attr( $i['kleur'] ) );
		$data  = $voorbeeld ? '' : sprintf( ' data-van="%s" data-tot="%s"', esc_attr( $i['van'] ), esc_attr( $i['tot'] ) );
		/* Buiten het venster staat hij er wel, maar verborgen: het script zet
		   hem aan zodra de datum er is. Zie nog_niet_voorbij(). */
		$verborgen = ( ! $voorbeeld && ! self::binnen( $i['van'], $i['tot'] ) ) ? ' hidden' : '';

		if ( '' !== $i['link'] && ! $voorbeeld ) {
			return sprintf(
				'<a class="wssbf-balk" href="%s" style="%s"%s%s>%s</a>',
				esc_url( $i['link'] ),
				$stijl,
				$data,
				$verborgen,
				esc_html( $i['tekst'] )
			);
		}
		return sprintf( '<div class="wssbf-balk" style="%s"%s%s>%s</div>', $stijl, $data, $verborgen, esc_html( $i['tekst'] ) );
	}

	public static function toon_balk() {
		if ( self::$balk_getoond ) {
			return;
		}
		self::$balk_getoond = true;
		$html               = self::balk_html();
		/* Kent het thema wp_body_open niet, dan komt hij onderaan terecht en
		   tilt het script hem naar boven. */
		if ( doing_action( 'wp_footer' ) ) {
			$html = preg_replace( '/class="wssbf-balk"/', 'class="wssbf-balk" data-onderaan="1" hidden', $html, 1 );
			$html = str_replace( ' hidden hidden', ' hidden', $html );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * De popup. Dezelfde opmaak voor de winkel en voor het voorbeeld in
	 * wp-admin, zodat wat je daar ziet is wat de bezoeker krijgt.
	 *
	 * @param array|null $i         Instellingen van de popup.
	 * @param bool       $voorbeeld In wp-admin: zonder laag en zonder sluiten.
	 * @return string
	 */
	public static function popup_html( $i = null, $voorbeeld = false ) {
		if ( ! is_array( $i ) ) {
			$alles = self::instellingen();
			$i     = $alles['popup'];
		}
		$stijl = sprintf(
			'--wssbf-bg:%s;--wssbf-kleur:%s;--wssbf-knop:%s;--wssbf-knoptekst:%s',
			esc_attr( $i['bg'] ),
			esc_attr( $i['kleur'] ),
			esc_attr( $i['knopkleur'] ),
			esc_attr( $i['knoptekst'] )
		);

		$h  = '<div class="wssbf-popup" role="dialog" aria-modal="true" aria-label="' . esc_attr( $i['kop'] ) . '" style="' . $stijl . '">';
		$h .= '<div class="wssbf-popup-tekst">';
		$h .= '<h2>' . esc_html( $i['kop'] ) . '</h2>';
		if ( '' !== $i['tekst'] ) {
			$h .= '<p>' . esc_html( $i['tekst'] ) . '</p>';
		}
		if ( '' !== $i['knop'] ) {
			$doel = ( '' !== $i['link'] && ! $voorbeeld ) ? esc_url( $i['link'] ) : '#';
			$h   .= '<a class="wssbf-popup-knop" href="' . $doel . '">' . esc_html( $i['knop'] ) . '</a>';
		}
		$h .= '</div>';
		if ( '' !== $i['beeld'] ) {
			$h .= '<div class="wssbf-popup-beeld"><img src="' . esc_url( $i['beeld'] ) . '" alt="" loading="lazy"></div>';
		}
		if ( ! $voorbeeld ) {
			$h .= '<button type="button" class="wssbf-sluit" aria-label="' . esc_attr__( 'Sluiten', 'wss-ai' ) . '">&times;</button>';
		}
		$h .= '</div>';

		if ( $voorbeeld ) {
			return $h;
		}
		return sprintf(
			'<div class="wssbf-laag" hidden data-van="%s" data-tot="%s">%s</div>',
			esc_attr( $i['van'] ),
			esc_attr( $i['tot'] ),
			$h
		);
	}

	public static function toon_popup() {
		echo self::popup_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/* ------------------------------------------------------------------
	 * wp-admin
	 * ------------------------------------------------------------------ */

	/**
	 * Het menu-item.
	 *
	 * Positie 3.1: direct onder WSS Tools (3) en boven de scheidingslijn (4).
	 * Een kommagetal als tekst, zodat WordPress hem niet op een bezette plek
	 * zet en een ander item verdringt.
	 */
	public static function menu() {
		if ( ! self::beschikbaar() ) {
			return;
		}
		add_menu_page(
			__( 'Black Friday', 'wss-ai' ),
			__( 'Black Friday', 'wss-ai' ),
			self::recht(),
			self::SLUG,
			array( __CLASS__, 'pagina' ),
			'dashicons-tag',
			'3.1'
		);
	}

	/** Een winkelbeheerder mag erbij, net als bij voorraad en nieuwsbrief. */
	private static function recht() {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	private static function is_pagina() {
		return isset( $_GET['page'] ) && self::SLUG === sanitize_key( wp_unslash( $_GET['page'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * De opmaak van onze pagina. Eigen regels en niet die van wss_ai_stijl():
	 * dit is een hoofdmenu-item en geen onderdeel van het WSS Tools-menu, dus
	 * daar rekenen we niet op.
	 */
	public static function beheer_opmaak() {
		if ( ! self::is_pagina() ) {
			return;
		}
		wp_enqueue_media();
		$css = '.wssbf-kaart{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:18px 22px;margin:16px 0;max-width:980px}'
			. '.wssbf-kaart h2{margin:0 0 4px;font-size:16px}'
			. '.wssbf-mut{color:#646970}'
			. '.wssbf-acties{width:100%;border-collapse:collapse;margin-top:10px}'
			. '.wssbf-acties th{text-align:left;font-size:12px;color:#646970;font-weight:600;padding:6px 8px 6px 0}'
			. '.wssbf-acties td{padding:8px 8px 8px 0;vertical-align:top;border-top:1px solid #f0f0f1}'
			. '.wssbf-acties input[type=text]{width:100%}'
			. '.wssbf-ids{margin-top:6px}.wssbf-ids summary{cursor:pointer;color:#646970;font-size:12px}'
			. '.wssbf-ids textarea{width:100%;font-size:12px}'
			. '.wssbf-stand{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;white-space:nowrap}'
			. '.wssbf-stand.is-live{background:#e3f2e6;color:#00701a}'
			. '.wssbf-stand.is-wacht{background:#f0f6fc;color:#135e96}'
			. '.wssbf-stand.is-let-op{background:#fcf3e0;color:#8a6100}'
			. '.wssbf-stand.is-voorbij,.wssbf-stand.is-onbekend{background:#f0f0f1;color:#646970}'
			. '.wssbf-uitleg{display:block;font-size:12px;color:#646970;margin-top:4px}'
			. '.wssbf-rij{display:flex;gap:16px;flex-wrap:wrap;margin:10px 0}'
			. '.wssbf-rij label{display:block;flex:1 1 220px;min-width:0}'
			. '.wssbf-rij label.is-smal{flex:0 0 150px}'
			. '.wssbf-rij label span{display:block;font-size:12px;color:#646970;margin-bottom:3px}'
			. '.wssbf-rij input[type=text],.wssbf-rij input[type=url],.wssbf-rij textarea{width:100%}'
			. '.wssbf-schakel{display:flex;align-items:center;gap:8px;font-weight:600;margin:10px 0}'
			. '.wssbf-tip{background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin:12px 0 0}'
			. '.wssbf-voorbeeld{margin-top:14px;padding:18px;background:#f0f0f1;border-radius:6px}'
			. '.wssbf-voorbeeld>small{display:block;color:#646970;margin-bottom:8px}'
			. '.wssbf-voorbeeld .wssbf-popup{max-height:none;margin:0 auto}'
			. '.wssbf-prompt textarea{width:100%;min-height:90px}';
		wp_register_style( 'wssbf-beheer', false, array(), WSS_AI_VERSIE );
		wp_enqueue_style( 'wssbf-beheer' );
		wp_add_inline_style( 'wssbf-beheer', $css . self::css() );

		/* De knop om een afbeelding uit de mediabibliotheek te kiezen. */
		$js = '(function(){document.addEventListener("click",function(e){'
			. 'var k=e.target&&e.target.closest?e.target.closest(".wssbf-kies-beeld"):null;'
			. 'if(!k||!window.wp||!wp.media){return;}e.preventDefault();'
			. 'var f=wp.media({title:k.getAttribute("data-titel"),multiple:false,library:{type:"image"}});'
			. 'f.on("select",function(){var a=f.state().get("selection").first().toJSON();'
			. 'var v=document.getElementById("wssbf_beeld");if(v){v.value=a.url;}});f.open();'
			. '});})();';
		wp_register_script( 'wssbf-beheer', '', array(), WSS_AI_VERSIE, true );
		wp_enqueue_script( 'wssbf-beheer' );
		wp_add_inline_script( 'wssbf-beheer', $js );
	}

	private static function terug( $melding, $soort = 'success' ) {
		set_transient(
			'wss_ai_bf_melding_' . get_current_user_id(),
			array( 'tekst' => (string) $melding, 'soort' => $soort ),
			2 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private static function controleer( $actie ) {
		if ( ! self::beschikbaar() || ! current_user_can( self::recht() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( $actie );
	}

	public static function opslaan() {
		self::controleer( 'wss_ai_bf_opslaan' );
		$in = isset( $_POST['bf'] ) && is_array( $_POST['bf'] ) ? wp_unslash( $_POST['bf'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::bewaar( self::schoon( $in, self::instellingen() ) );
		self::terug( __( 'Opgeslagen.', 'wss-ai' ) );
	}

	public static function terugzetten() {
		self::controleer( 'wss_ai_bf_terug' );
		$vorige = get_option( self::VORIGE );
		if ( ! is_array( $vorige ) ) {
			self::terug( __( 'Er is geen vorige versie om terug te zetten.', 'wss-ai' ), 'warning' );
		}
		self::bewaar( self::schoon( $vorige, self::standaard() ) );
		self::terug( __( 'De vorige versie staat terug.', 'wss-ai' ) );
	}

	/**
	 * Aanpassen met een opdracht in gewone taal.
	 *
	 * De winkelier schrijft wat hij anders wil; de server van Webshopschool
	 * laat het eigen ChatGPT van de winkel de instellingen van deze pagina
	 * herschrijven en stuurt ze terug. Wij maken ze schoon en slaan ze op.
	 *
	 * ALLEEN VIA HET EIGEN CHATGPT
	 * Geen stille terugval op het tegoed van Webshopschool: is het eigen
	 * abonnement niet gekoppeld, dan zeggen we dat en gebeurt er niets.
	 *
	 * 'ai' => 'eigen' zetten we hier zelf mee. WSS_AI_Koppeling::vraag() doet
	 * dat alleen voor de paden van teksten en foto's, en die lijst hoort bij
	 * werk dat ook op ons tegoed kan lopen. Dit pad kan dat niet.
	 */
	public static function prompt() {
		self::controleer( 'wss_ai_bf_prompt' );

		$opdracht = isset( $_POST['opdracht'] ) ? sanitize_textarea_field( wp_unslash( $_POST['opdracht'] ) ) : '';
		if ( '' === trim( $opdracht ) ) {
			self::terug( __( 'Schrijf eerst wat je wilt aanpassen.', 'wss-ai' ), 'warning' );
		}
		if ( ! class_exists( 'WSS_AI_Eigen_AI' ) || ! WSS_AI_Eigen_AI::is_eigen() ) {
			self::terug( __( 'Koppel eerst je eigen ChatGPT onder WSS Tools. Daarna kun je hier met een opdracht aanpassen.', 'wss-ai' ), 'warning' );
		}

		$huidig = self::instellingen();
		$uit    = WSS_AI_Koppeling::vraag(
			'/blackfriday/prompt',
			array(
				'ai'       => 'eigen',
				'opdracht' => $opdracht,
				'huidig'   => $huidig,
				'vandaag'  => self::vandaag(),
				'winkel'   => get_bloginfo( 'name' ),
			),
			180
		);

		if ( is_wp_error( $uit ) ) {
			self::terug( $uit->get_error_message(), 'error' );
		}
		if ( ! is_array( $uit ) || empty( $uit['instellingen'] ) || ! is_array( $uit['instellingen'] ) ) {
			self::terug( __( 'Er kwam geen bruikbaar antwoord terug. Er is niets veranderd.', 'wss-ai' ), 'error' );
		}

		self::bewaar( self::schoon( $uit['instellingen'], $huidig, true ) );

		$uitleg = isset( $uit['uitleg'] ) ? sanitize_text_field( $uit['uitleg'] ) : '';
		self::terug(
			'' !== $uitleg ? $uitleg : __( 'Aangepast. Kijk hieronder of het klopt.', 'wss-ai' )
		);
	}

	private static function veld( $naam, $waarde, $label, $soort = 'text', $klasse = '' ) {
		printf(
			'<label class="%5$s"><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s"></label>',
			esc_html( $label ),
			esc_attr( $soort ),
			esc_attr( $naam ),
			esc_attr( $waarde ),
			esc_attr( $klasse )
		);
	}

	public static function pagina() {
		if ( ! current_user_can( self::recht() ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		$i = self::instellingen();

		echo '<div class="wrap wss-ai-blackfriday"><h1>' . esc_html__( 'Black Friday', 'wss-ai' ) . '</h1>';

		$melding = get_transient( 'wss_ai_bf_melding_' . get_current_user_id() );
		if ( is_array( $melding ) && ! empty( $melding['tekst'] ) ) {
			delete_transient( 'wss_ai_bf_melding_' . get_current_user_id() );
			$soort = in_array( $melding['soort'], array( 'success', 'warning', 'error' ), true ) ? $melding['soort'] : 'success';
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $soort ), esc_html( $melding['tekst'] ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wss_ai_bf_opslaan">';
		wp_nonce_field( 'wss_ai_bf_opslaan' );

		/* ---- De actie ---- */
		echo '<div class="wssbf-kaart"><h2>' . esc_html__( 'Jouw actie', 'wss-ai' ) . '</h2>';
		echo '<p class="wssbf-mut">' . esc_html__( 'Wat de actie is en wanneer hij geldt. De indicator laat zien of hij nu echt in je winkel loopt. De actieprijzen zelf zet Webshopschool voor je klaar.', 'wss-ai' ) . '</p>';
		echo '<table class="wssbf-acties"><thead><tr>';
		echo '<th>' . esc_html__( 'Actie', 'wss-ai' ) . '</th>';
		echo '<th style="width:140px">' . esc_html__( 'Van', 'wss-ai' ) . '</th>';
		echo '<th style="width:140px">' . esc_html__( 'Tot en met', 'wss-ai' ) . '</th>';
		echo '<th style="width:230px">' . esc_html__( 'Stand', 'wss-ai' ) . '</th>';
		echo '<th style="width:70px">' . esc_html__( 'Weg', 'wss-ai' ) . '</th>';
		echo '</tr></thead><tbody>';

		$rijen   = $i['acties'];
		$rijen[] = array( 'sleutel' => '', 'titel' => '', 'van' => '', 'tot' => '', 'ids' => array(), 'nieuw' => 1 );
		foreach ( $rijen as $n => $a ) {
			$naam  = 'bf[acties][' . (int) $n . ']';
			$nieuw = ! empty( $a['nieuw'] );
			echo '<tr><td>';
			printf( '<input type="hidden" name="%s[sleutel]" value="%s">', esc_attr( $naam ), esc_attr( $a['sleutel'] ) );
			printf(
				'<input type="text" name="%s[titel]" value="%s" placeholder="%s">',
				esc_attr( $naam ),
				esc_attr( $a['titel'] ),
				$nieuw ? esc_attr__( 'Nieuwe actie, bijvoorbeeld: 20% korting op alle jassen', 'wss-ai' ) : ''
			);
			printf(
				'<details class="wssbf-ids"><summary>%s</summary><textarea name="%s[ids]" rows="2">%s</textarea></details>',
				/* translators: %d: aantal gekoppelde producten */
				esc_html( sprintf( __( 'Gekoppelde producten: %d (vult Webshopschool in)', 'wss-ai' ), count( (array) $a['ids'] ) ) ),
				esc_attr( $naam ),
				esc_textarea( implode( ', ', (array) $a['ids'] ) )
			);
			echo '</td>';
			printf( '<td><input type="date" name="%s[van]" value="%s"></td>', esc_attr( $naam ), esc_attr( $a['van'] ) );
			printf( '<td><input type="date" name="%s[tot]" value="%s"></td>', esc_attr( $naam ), esc_attr( $a['tot'] ) );
			echo '<td>';
			if ( ! $nieuw ) {
				$st = self::stand( $a );
				printf( '<span class="wssbf-stand is-%s">%s</span>', esc_attr( $st['soort'] ), esc_html( $st['label'] ) );
				if ( '' !== $st['uitleg'] ) {
					echo '<span class="wssbf-uitleg">' . esc_html( $st['uitleg'] ) . '</span>';
				}
			}
			echo '</td><td>';
			if ( ! $nieuw ) {
				printf( '<input type="checkbox" name="%s[weg]" value="1" aria-label="%s">', esc_attr( $naam ), esc_attr__( 'Deze actie weghalen', 'wss-ai' ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		/* ---- De popup ---- */
		$p = $i['popup'];
		echo '<div class="wssbf-kaart"><h2>' . esc_html__( 'Popup', 'wss-ai' ) . '</h2>';
		echo '<p class="wssbf-mut">' . esc_html__( 'Een bezoeker ziet de popup hooguit één keer per dag, en niet in de winkelwagen of bij het afrekenen.', 'wss-ai' ) . '</p>';
		printf(
			'<label class="wssbf-schakel"><input type="checkbox" name="bf[popup][aan]" value="1" %s> %s <span class="wssbf-stand is-%s">%s</span></label>',
			checked( 1, (int) $p['aan'], false ),
			esc_html__( 'Popup tonen in de winkel', 'wss-ai' ),
			$p['aan'] ? 'live' : 'voorbij',
			$p['aan'] ? esc_html__( 'Aan', 'wss-ai' ) : esc_html__( 'Uit', 'wss-ai' )
		);
		echo '<div class="wssbf-rij">';
		self::veld( 'bf[popup][kop]', $p['kop'], __( 'Kop', 'wss-ai' ) );
		self::veld( 'bf[popup][knop]', $p['knop'], __( 'Tekst op de knop', 'wss-ai' ) );
		self::veld( 'bf[popup][link]', $p['link'], __( 'Waar de knop heen gaat', 'wss-ai' ), 'url' );
		echo '</div><div class="wssbf-rij">';
		printf(
			'<label><span>%s</span><textarea name="bf[popup][tekst]" rows="3">%s</textarea></label>',
			esc_html__( 'Tekst', 'wss-ai' ),
			esc_textarea( $p['tekst'] )
		);
		echo '</div><div class="wssbf-rij">';
		printf(
			'<label><span>%s</span><input type="url" id="wssbf_beeld" name="bf[popup][beeld]" value="%s"> <button type="button" class="button wssbf-kies-beeld" data-titel="%s" style="margin-top:6px">%s</button></label>',
			esc_html__( 'Afbeelding (mag leeg)', 'wss-ai' ),
			esc_attr( $p['beeld'] ),
			esc_attr__( 'Kies een afbeelding', 'wss-ai' ),
			esc_html__( 'Kies uit je media', 'wss-ai' )
		);
		self::veld( 'bf[popup][van]', $p['van'], __( 'Tonen vanaf (mag leeg)', 'wss-ai' ), 'date', 'is-smal' );
		self::veld( 'bf[popup][tot]', $p['tot'], __( 'Tot en met (mag leeg)', 'wss-ai' ), 'date', 'is-smal' );
		echo '</div><div class="wssbf-rij">';
		self::veld( 'bf[popup][bg]', $p['bg'], __( 'Achtergrond', 'wss-ai' ), 'color', 'is-smal' );
		self::veld( 'bf[popup][kleur]', $p['kleur'], __( 'Tekstkleur', 'wss-ai' ), 'color', 'is-smal' );
		self::veld( 'bf[popup][knopkleur]', $p['knopkleur'], __( 'Knop', 'wss-ai' ), 'color', 'is-smal' );
		self::veld( 'bf[popup][knoptekst]', $p['knoptekst'], __( 'Tekst op knop', 'wss-ai' ), 'color', 'is-smal' );
		echo '</div>';
		echo '<div class="wssbf-voorbeeld"><small>' . esc_html__( 'Voorbeeld (van wat er nu is opgeslagen)', 'wss-ai' ) . '</small>';
		echo self::popup_html( $p, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
		echo '<p class="wssbf-tip">' . esc_html__( 'Wil je de popup aanpassen? Stuur Bennie een appje erover.', 'wss-ai' ) . '</p>';
		echo '</div>';

		/* ---- De melding bovenin ---- */
		$b = $i['balk'];
		echo '<div class="wssbf-kaart"><h2>' . esc_html__( 'Melding bovenin', 'wss-ai' ) . '</h2>';
		echo '<p class="wssbf-mut">' . esc_html__( 'Een balk helemaal bovenaan je winkel, op elke pagina behalve de winkelwagen en het afrekenen.', 'wss-ai' ) . '</p>';
		printf(
			'<label class="wssbf-schakel"><input type="checkbox" name="bf[balk][aan]" value="1" %s> %s <span class="wssbf-stand is-%s">%s</span></label>',
			checked( 1, (int) $b['aan'], false ),
			esc_html__( 'Melding tonen in de winkel', 'wss-ai' ),
			$b['aan'] ? 'live' : 'voorbij',
			$b['aan'] ? esc_html__( 'Aan', 'wss-ai' ) : esc_html__( 'Uit', 'wss-ai' )
		);
		echo '<div class="wssbf-rij">';
		self::veld( 'bf[balk][tekst]', $b['tekst'], __( 'Tekst', 'wss-ai' ) );
		self::veld( 'bf[balk][link]', $b['link'], __( 'Link (mag leeg)', 'wss-ai' ), 'url' );
		echo '</div><div class="wssbf-rij">';
		self::veld( 'bf[balk][van]', $b['van'], __( 'Tonen vanaf (mag leeg)', 'wss-ai' ), 'date', 'is-smal' );
		self::veld( 'bf[balk][tot]', $b['tot'], __( 'Tot en met (mag leeg)', 'wss-ai' ), 'date', 'is-smal' );
		self::veld( 'bf[balk][bg]', $b['bg'], __( 'Achtergrond', 'wss-ai' ), 'color', 'is-smal' );
		self::veld( 'bf[balk][kleur]', $b['kleur'], __( 'Tekstkleur', 'wss-ai' ), 'color', 'is-smal' );
		echo '</div>';
		if ( '' !== $b['tekst'] ) {
			echo '<div class="wssbf-voorbeeld"><small>' . esc_html__( 'Voorbeeld (van wat er nu is opgeslagen)', 'wss-ai' ) . '</small>';
			echo self::balk_html( $b, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div>';
		}
		echo '</div>';

		submit_button( __( 'Opslaan', 'wss-ai' ) );
		echo '</form>';

		/* ---- Aanpassen met een opdracht ---- */
		$eigen = class_exists( 'WSS_AI_Eigen_AI' ) && WSS_AI_Eigen_AI::is_eigen();
		echo '<div class="wssbf-kaart wssbf-prompt"><h2>' . esc_html__( 'Aanpassen met een opdracht', 'wss-ai' ) . '</h2>';
		echo '<p class="wssbf-mut">' . esc_html__( 'Schrijf in gewone taal wat je anders wilt, bijvoorbeeld: "Maak de popup feestelijker en zet de melding bovenin aan met de tekst 20% korting op alles". Dit past de actieregels, de popup en de melding op deze pagina aan. De prijzen van je producten veranderen hier niet door.', 'wss-ai' ) . '</p>';
		if ( $eigen ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="wss_ai_bf_prompt">';
			wp_nonce_field( 'wss_ai_bf_prompt' );
			echo '<textarea name="opdracht" placeholder="' . esc_attr__( 'Wat wil je aanpassen?', 'wss-ai' ) . '"></textarea>';
			echo '<p class="wssbf-mut">' . esc_html__( 'Dit loopt via je eigen ChatGPT en kan een minuut duren.', 'wss-ai' ) . '</p>';
			submit_button( __( 'Aanpassen', 'wss-ai' ), 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p class="wssbf-tip">' . esc_html__( 'Dit werkt via je eigen ChatGPT-abonnement. Koppel dat eerst onder WSS Tools, dan verschijnt hier het invulvak. Tot die tijd kun je Bennie een appje sturen met wat je wilt aanpassen.', 'wss-ai' ) . '</p>';
		}
		if ( is_array( get_option( self::VORIGE ) ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
			echo '<input type="hidden" name="action" value="wss_ai_bf_terug">';
			wp_nonce_field( 'wss_ai_bf_terug' );
			submit_button( __( 'Vorige versie terugzetten', 'wss-ai' ), 'link', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		echo '</div>';
	}
}
