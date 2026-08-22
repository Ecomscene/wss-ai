<?php
/**
 * Plugin Name:       WSS Tools
 * Plugin URI:        https://github.com/Ecomscene/wss-ai
 * Description:       Gereedschap voor je webshop: teksten, productfoto's, voorraadbeheer en nieuwsbrieven. Beheerd door Webshopschool.
 * Version:           0.27.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Webshopschool
 * Author URI:        https://webshopschool.nl
 * License:           GPL-2.0-or-later
 * Text Domain:       wss-ai
 * Update URI:        https://github.com/Ecomscene/wss-ai
 *
 * ---------------------------------------------------------------------------
 * DE REGEL VOOR DEZE PLUGIN: HOU HEM DUN.
 *
 * Alles wat rekent, met een AI-model praat of geld kost hoort op de server van
 * Webshopschool, niet hier. Deze plugin is een schermpje dat die server belt.
 *
 * Twee redenen, en ze zijn allebei uit ervaring:
 *  1. Een fout op onze server is binnen een minuut gerepareerd. Een fout in deze
 *     plugin moet langs tientallen webshops die allemaal moeten bijwerken.
 *  2. Deze code draait in de PHP van een klant. Wat hier stukgaat, gaat op zijn
 *     winkel stuk -- en dat is twee keer eerder gebeurd.
 *
 * Dus: geen zware verwerking, geen schrijfacties buiten onze eigen instellingen,
 * en alles wat mis kan gaan in een try/catch met een nette melding.
 * ---------------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WSS_AI_VERSIE', '0.27.0' );
define( 'WSS_AI_BESTAND', __FILE__ );
define( 'WSS_AI_MAP', plugin_dir_path( __FILE__ ) );

require_once WSS_AI_MAP . 'includes/class-wss-ai-updater.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-budget.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-koppeling.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-instellingen.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-seo.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-seoplan.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-fotostudio.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-bulk.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-upgrades.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-verzoek.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-voorraad.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-mailer.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-menu.php';

/**
 * Het icoon in het menu.
 *
 * WordPress kleurt een menu-icoon zelf mee met het gekozen kleurenschema, maar
 * alleen als het een SVG is die op `currentColor` staat en als data-URI wordt
 * meegegeven. Een PNG zou in de donkere balk grijs blijven terwijl de rest
 * oplicht, en dan valt hij op als het buitenbeentje dat hij niet moet zijn.
 */
function wss_ai_icoon() {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">'
		. '<path d="M10 1.6l1.5 4.1a3.2 3.2 0 0 0 1.9 1.9l4.1 1.5-4.1 1.5a3.2 3.2 0 0 0-1.9 1.9L10 16.6l-1.5-4.1a3.2 3.2 0 0 0-1.9-1.9L2.5 9.1l4.1-1.5a3.2 3.2 0 0 0 1.9-1.9L10 1.6z"/>'
		. '<path d="M16.1 13.4l.6 1.7 1.7.6-1.7.6-.6 1.7-.6-1.7-1.7-.6 1.7-.6.6-1.7z"/>'
		. '</svg>';

	return 'data:image/svg+xml;base64,' . base64_encode( $svg );
}

/**
 * Handmatig op updates controleren.
 *
 * De automatische controle draait maar een paar keer per dag. Wil je na een
 * nieuwe release meteen zien of hij aankomt, dan is wachten geen optie.
 */
function wss_ai_handmatige_controle() {
	if ( ! isset( $_GET['wss_ai_check'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wss_ai_check' ) ) {
		return;
	}
	WSS_AI_Updater::vergeet_cache();
	delete_site_transient( 'update_plugins' );
	wp_safe_redirect( admin_url( 'admin.php?page=wss-ai&wss_ai_gecheckt=1' ) );
	exit;
}
add_action( 'admin_init', 'wss_ai_handmatige_controle' );

/**
 * Op welke schermen onze opmaak nodig is.
 *
 * Onze eigen pagina's, het productscherm (daar staan de knoppen) en het
 * productenoverzicht (daar zit de bulkactie). Een pagina vergeten betekent geen
 * foutmelding maar een blok zonder opmaak, en dat merk je pas als een klant het
 * ziet: precies wat er gebeurde toen het productscherm er niet bij stond.
 */
function wss_ai_eigen_scherm( $hook ) {
	/* Onze eigen pagina's herkennen we aan wat WordPress ons teruggaf, niet aan
	   een naam die we zelf in elkaar zetten. Zie de uitleg in
	   class-wss-ai-menu.php: die naam hangt af van de menutitel, en veranderde
	   stilletjes mee toen de plugin WSS Tools ging heten. */
	if ( WSS_AI_Menu::is_eigen_scherm( $hook ) ) {
		return true;
	}
	/* Het bulkscherm staat niet in het menu en heet daardoor anders. Zie de
	   uitleg in class-wss-ai-bulk.php. */
	if ( WSS_AI_Bulk::is_scherm( $hook ) ) {
		return true;
	}
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		$post = get_post();
		return $post && 'product' === $post->post_type;
	}
	if ( 'edit.php' === $hook ) {
		return isset( $_GET['post_type'] ) && 'product' === $_GET['post_type'];
	}
	return false;
}

/**
 * Een beetje opmaak. Bewust weinig: dit moet bij wp-admin passen, niet opvallen.
 */
function wss_ai_stijl( $hook ) {
	if ( ! wss_ai_eigen_scherm( $hook ) ) {
		return;
	}
	$css ='.wss-ai-kaart{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:760px}'
		. '.wss-ai-kaart h2{margin-top:0}'
		. '.wss-ai-inleiding{max-width:760px;font-size:14px}'
		/* De tegels op de overzichtspagina. Een hele kaart is klikbaar, niet
		   alleen de titel: je richt niet op een woord van drie letters. */
		. '.wss-ai-tegels{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));'
		. 'gap:14px;max-width:1040px;margin:18px 0}'
		. '.wss-ai-tegel{display:flex;flex-direction:column;gap:6px;background:#fff;border:1px solid #c3c4c7;'
		. 'border-radius:6px;padding:16px 18px;text-decoration:none;color:inherit}'
		. '.wss-ai-tegel:hover{border-color:#2271b1;box-shadow:0 1px 6px rgba(0,0,0,.08)}'
		. '.wss-ai-tegel:focus{outline:2px solid #2271b1;outline-offset:1px}'
		. '.wss-ai-tegel .dashicons{color:#2271b1;font-size:26px;width:26px;height:26px}'
		. '.wss-ai-tegel strong{font-size:15px}'
		/* De upgradekaarten. Rustig maar niet vlak: een zachte rand, een lichte
		   schaduw die bij aanwijzen iets opkomt, en een gekleurd vlakje om het
		   icoon zodat de kaart een aanknopingspunt heeft. Alles in de kleuren
		   die wp-admin zelf gebruikt, zodat het erbij hoort en niet naast staat. */
		. '.wss-ai-upgrades{display:grid;grid-template-columns:repeat(auto-fit,minmax(330px,1fr));'
		. 'gap:20px;max-width:1100px;margin:20px 0}'
		. '.wss-ai-upgrade{display:flex;flex-direction:column;background:#fff;'
		. 'border:1px solid #dcdcde;border-radius:10px;padding:22px 24px 20px;'
		. 'box-shadow:0 1px 2px rgba(0,0,0,.04);transition:box-shadow .15s ease,border-color .15s ease}'
		. '.wss-ai-upgrade:hover{border-color:#c3c4c7;box-shadow:0 4px 16px rgba(0,0,0,.08)}'
		. '.wss-ai-upgrade-kop{display:flex;gap:14px;align-items:flex-start}'
		. '.wss-ai-upgrade-icoon{flex:0 0 auto;width:42px;height:42px;border-radius:10px;'
		. 'background:#f0f6fc;display:flex;align-items:center;justify-content:center}'
		. '.wss-ai-upgrade-icoon .dashicons{color:#2271b1;font-size:22px;width:22px;height:22px}'
		. '.wss-ai-upgrade-titels{min-width:0}'
		. '.wss-ai-upgrade h2{margin:0;font-size:16px;line-height:1.35;font-weight:600}'
		. '.wss-ai-prijs{display:block;margin-top:2px;font-size:20px;font-weight:700;color:#1d2327;'
		. 'letter-spacing:-.01em}'
		/* De tekst duwt de knop naar beneden, zodat kaarten naast elkaar hun
		   knoppen op dezelfde hoogte houden ook als de ene tekst langer is. */
		. '.wss-ai-upgrade-tekst{flex:1 1 auto;margin:14px 0 0;color:#50575e;line-height:1.55}'
		. '.wss-ai-upgrade-voet{margin-top:18px;padding-top:16px;border-top:1px solid #f0f0f1}'
		. '.wss-ai-upgrade-veld{display:block;margin-bottom:12px}'
		. '.wss-ai-upgrade-veld span{display:block;font-size:12px;color:#646970;margin-bottom:4px}'
		. '.wss-ai-upgrade-veld textarea{width:100%;border:1px solid #dcdcde;border-radius:6px;'
		. 'padding:8px 10px;font-size:13px;resize:vertical;box-shadow:none}'
		. '.wss-ai-upgrade-veld textarea:focus{border-color:#2271b1;outline:2px solid transparent;'
		. 'box-shadow:0 0 0 1px #2271b1}'
		. '.wss-ai-upgrade-knop{width:100%;border:0;border-radius:6px;background:#2271b1;color:#fff;'
		. 'padding:11px 16px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s ease}'
		. '.wss-ai-upgrade-knop:hover{background:#135e96}'
		. '.wss-ai-upgrade-knop:focus{outline:2px solid #2271b1;outline-offset:2px}'
		/* Het SEO-plan. Vier cijfers boven een balk, en daaronder de weken als
		   een tijdlijn: een rondje met het weeknummer, ernaast wat er gebeurt.
		   De kleur zegt waar je bent (groen is af, blauw is nu), zodat je het
		   ziet zonder te lezen. */
		. '.wss-ai-seoplan .wss-ai-cijfers{display:flex;gap:28px;flex-wrap:wrap;margin:0 0 14px}'
		. '.wss-ai-cijfer b{display:block;font-size:22px;line-height:1.25;font-weight:700}'
		. '.wss-ai-cijfer span{color:#646970;font-size:12px}'
		. '.wss-ai-balk{height:8px;border-radius:4px;background:#f0f0f1;overflow:hidden}'
		. '.wss-ai-balk i{display:block;height:100%;background:#2271b1}'
		/* Het maandtegoed. Het bedrag groot, want dat is waar iemand naar kijkt,
		   en de balk eronder omdat een bedrag weinig zegt zonder het geheel.
		   Rood zodra het op is: dat is geen fout maar wel iets om te zien. */
		. '.wss-ai-tegoedbedrag{margin:0 0 10px;display:flex;align-items:baseline;gap:8px}'
		. '.wss-ai-tegoedbedrag strong{font-size:26px;line-height:1.2;letter-spacing:-.01em}'
		. '.wss-ai-tegoedkaart .wss-ai-balk{max-width:420px}'
		. '.wss-ai-tegoedlijst{margin:8px 0 4px 18px;list-style:disc}'
		. '.wss-ai-tegoedlijst li{margin:0 0 2px}'
		. '.wss-ai-tegoed{color:#646970}'
		. '.wss-ai-tegoed.is-op{color:#b32d2e}'
		. '.wss-ai-weken{margin:14px 0 4px}'
		. '.wss-ai-week{display:flex;gap:14px;padding:14px 0;border-top:1px solid #f0f0f1}'
		. '.wss-ai-week:first-child{border-top:0;padding-top:4px}'
		. '.wss-ai-week-nr{flex:0 0 auto;width:46px;height:46px;border-radius:50%;background:#f0f0f1;'
		. 'color:#646970;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700}'
		. '.wss-ai-week.is-af .wss-ai-week-nr{background:#e3f2e6;color:#00701a}'
		. '.wss-ai-week.is-nu .wss-ai-week-nr{background:#f0f6fc;color:#2271b1}'
		. '.wss-ai-week-inhoud{flex:1 1 auto;min-width:0}'
		. '.wss-ai-week-inhoud strong{display:block;font-size:14px}'
		. '.wss-ai-week-stand{display:block;font-size:12px;color:#646970}'
		. '.wss-ai-week-tekst{margin:8px 0 0;color:#3c434a;line-height:1.55}'
		. '.wss-ai-mut{color:#646970}'
		. '.wss-ai-tabel{max-width:640px}'
		. '.wss-ai-tabel th{width:220px}'
		. '.wss-ai-goed{color:#00701a;font-weight:600}'
		. '.wss-ai-let-op{color:#8a6100;font-weight:600}'
		. '.wss-ai-onbekend{color:#646970;font-weight:600}'
		. '.wss-ai-seo .wss-ai-knoppen{display:flex;gap:8px;flex-wrap:wrap;align-items:center}'
		. '.wss-ai-seo .wss-ai-melding{color:#646970;font-size:13px}'
		. '.wss-ai-seo .wss-ai-lengte{display:inline-flex;align-items:center;gap:6px;color:#646970;font-size:13px}'
		. '.wss-ai-seo .wss-ai-fout{color:#b32d2e}'
		. '.wss-ai-klein{font-size:12px}'
		. '.wss-ai-seo .wss-ai-klein{margin-bottom:0}'
		. '.wss-ai-voorbeeld{margin-right:12px}'
		. '.wss-ai-uitkomst textarea{width:100%;margin:6px 0}'
		/* Het paneel van de fotostudio. Bewust een eigen laag over het scherm:
		   het productscherm van WooCommerce is vol, en een blok dat ertussen
		   schuift duwt alles weg terwijl je net stond te kijken. */
		. '.wss-ai-paneel{position:fixed;inset:0;z-index:160000;background:rgba(0,0,0,.55);'
		. 'display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:40px 16px}'
		. '.wss-ai-paneel[hidden]{display:none}'
		. '.wss-ai-paneel-vak{position:relative;background:#fff;border-radius:6px;padding:20px 24px;'
		. 'width:100%;max-width:720px;box-shadow:0 8px 40px rgba(0,0,0,.3)}'
		. '.wss-ai-paneel-vak h2{margin-top:0}'
		. '.wss-ai-sluit{position:absolute;top:8px;right:10px;border:0;background:none;'
		. 'font-size:24px;line-height:1;cursor:pointer;color:#646970}'
		. '.wss-ai-paneel-beelden{display:flex;gap:16px;flex-wrap:wrap}'
		. '.wss-ai-paneel-beelden figure{margin:0;flex:1 1 240px;min-width:0}'
		. '.wss-ai-paneel-beelden figcaption{font-size:12px;color:#646970;margin-bottom:4px}'
		. '.wss-ai-paneel-beelden img{width:100%;height:auto;border:1px solid #dcdcde;border-radius:4px;'
		. 'background:#f6f7f7;display:block}'
		. '.wss-ai-paneel-knoppen{display:flex;gap:8px;align-items:center;flex-wrap:wrap}'
		. '.wss-ai-paneel-melding{color:#646970;font-size:13px}'
		. '.wss-ai-paneel-melding.wss-ai-fout{color:#b32d2e}'
		. '.wss-ai-fotoknop{margin:8px 0 0}'
		. '.wss-ai-bijwerk-vak{margin:12px 0 0}'
		. '.wss-ai-bijwerk-rij{display:flex;gap:8px;align-items:center;margin-top:6px}'
		. '.wss-ai-bijwerk-rij input{flex:1 1 auto;min-width:0}'
		. '.wss-ai-bronkiezer{display:flex;gap:6px;flex-wrap:wrap;margin-top:6px}'
		. '.wss-ai-bronknop{padding:0;border:2px solid transparent;border-radius:4px;background:none;'
		. 'cursor:pointer;line-height:0}'
		. '.wss-ai-bronknop img{width:44px;height:44px;object-fit:cover;border-radius:2px;display:block}'
		. '.wss-ai-bronknop.is-gekozen{border-color:#2271b1}'
		. '.wss-ai-taken{border:0;padding:0;margin:16px 0 4px}'
		. '.wss-ai-taken legend{padding:0;margin-bottom:6px}'
		. '.wss-ai-taak{display:flex;gap:8px;align-items:flex-start;padding:8px 10px;border:1px solid #dcdcde;'
		. 'border-radius:4px;margin-bottom:6px;cursor:pointer}'
		. '.wss-ai-taak input{margin-top:3px}'
		/* Een doorzichtige achtergrond op een witte kaart is niet te zien. Het
		   ruitjespatroon maakt zichtbaar wat er weggeknipt is. */
		. '.wss-ai-nieuw-vak img{background-image:linear-gradient(45deg,#e7e7e7 25%,transparent 25%),'
		. 'linear-gradient(-45deg,#e7e7e7 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#e7e7e7 75%),'
		. 'linear-gradient(-45deg,transparent 75%,#e7e7e7 75%);background-size:16px 16px;'
		. 'background-position:0 0,0 8px,8px -8px,-8px 0}';
	wp_register_style( 'wss-ai', false, array(), WSS_AI_VERSIE );
	wp_enqueue_style( 'wss-ai' );
	wp_add_inline_style( 'wss-ai', $css );

	/* De voorbeeldknoppen vullen alleen het tekstvak. Geen opslaan, geen ajax:
	   wie op "Warm en persoonlijk" klikt hoort daarna nog zelf te kunnen redigeren. */
	$js = '(function(){document.addEventListener("click",function(e){'
		. 'var b=e.target&&e.target.closest?e.target.closest(".wss-ai-voorbeeld"):null;'
		. 'if(!b){return;}e.preventDefault();'
		. 'var v=document.getElementById("wss_ai_stijl");'
		. 'if(v){v.value=b.getAttribute("data-tekst");v.focus();}'
		. '});})();';
	wp_register_script( 'wss-ai-admin', '', array(), WSS_AI_VERSIE, true );
	wp_enqueue_script( 'wss-ai-admin' );
	wp_add_inline_script( 'wss-ai-admin', $js );
}
add_action( 'admin_enqueue_scripts', 'wss_ai_stijl' );

/* De schakelaars vers ophalen als iemand op een WSS-scherm staat. Zie de uitleg
   bij vers_ophalen(): zonder dit moest je na elke wijziging in het paneel op de
   webshop nog op "Opnieuw koppelen" drukken. */
add_action( 'admin_enqueue_scripts', array( 'WSS_AI_Koppeling', 'vers_ophalen' ), 5 );

/* ---------------------------------------------------------------------------
 * Aanmelden bij Webshopschool
 *
 * Bij het activeren, en daarna dagelijks. Dat tweede is niet overbodig: een site
 * die op 'wacht' stond en later wordt aangezet hoort dat vanzelf te merken,
 * zonder dat iemand de plugin uit en aan moet zetten.
 * --------------------------------------------------------------------------- */
register_activation_hook(
	__FILE__,
	function () {
		WSS_AI_Koppeling::meld_aan();
		if ( ! wp_next_scheduled( 'wss_ai_dagelijks' ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wss_ai_dagelijks' );
		}
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'wss_ai_dagelijks' );
	}
);
add_action( 'wss_ai_dagelijks', array( 'WSS_AI_Koppeling', 'meld_aan' ) );

/* Opnieuw aanmelden vanaf de beheerpagina. Nodig zodra Joey een site aanzet die
   op wachten stond, en handig als er iets is misgegaan. */
add_action(
	'admin_init',
	function () {
		if ( ! isset( $_GET['wss_ai_koppel'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'wss_ai_koppel' ) ) {
			return;
		}
		WSS_AI_Koppeling::meld_aan();
		/* Ook het onthouden lijstje met mogelijkheden vernieuwen. Wie op deze
		   knop drukt wil dat alles opnieuw wordt opgehaald, niet de helft. */
		WSS_AI_Koppeling::vergeet_opties();
		wp_safe_redirect( admin_url( 'admin.php?page=wss-ai' ) );
		exit;
	}
);

/**
 * Na een update: opnieuw bij de server vragen wat er kan.
 *
 * De plugin onthoudt een uur lang welke taken en motoren er zijn. Dat lijstje
 * overleeft een update, dus zonder deze controle draai je de nieuwe versie met
 * de mogelijkheden van de oude: een knop die er wel is verschijnt niet, en je
 * denkt dat de update niet werkte. Dat is precies wat er gebeurde toen
 * "Variant maken" erbij kwam.
 */
add_action(
	'admin_init',
	function () {
		if ( get_option( 'wss_ai_draaiende_versie' ) === WSS_AI_VERSIE ) {
			return;
		}
		update_option( 'wss_ai_draaiende_versie', WSS_AI_VERSIE );
		WSS_AI_Koppeling::vergeet_opties();

		/* Het ritme van de aanmelding opnieuw zetten.
		   Op bestaande webshops staat die taak al ingepland, en wp_schedule_event
		   doet niets als er al een staat. Zonder dit zou een shop die vandaag
		   bijwerkt tot in lengte van dagen op het oude ritme blijven lopen. */
		wp_clear_scheduled_hook( 'wss_ai_dagelijks' );
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'wss_ai_dagelijks' );

		/* En meteen één keer, want wie net heeft bijgewerkt wil niet nog een uur
		   wachten voordat hij ziet wat er voor hem aanstaat. */
		WSS_AI_Koppeling::meld_aan();
	}
);

/* De updater aanzetten. Zie includes/class-wss-ai-updater.php. */
WSS_AI_Updater::init( 'Ecomscene', 'wss-ai' );
WSS_AI_Instellingen::init();
WSS_AI_SEO::init();
WSS_AI_Seoplan::init();
WSS_AI_Fotostudio::init();
WSS_AI_Bulk::init();
WSS_AI_Upgrades::init();
WSS_AI_Verzoek::init();
WSS_AI_Voorraad::init();
WSS_AI_Mailer::init();
WSS_AI_Menu::init();

/* Het voorraadbeheer raakt geen orders aan, maar WooCommerce waarschuwt de klant
   over elke plugin die niets zegt over de nieuwe orderopslag. */
add_action( 'before_woocommerce_init', array( 'WSS_AI_Voorraad', 'hpos' ) );
