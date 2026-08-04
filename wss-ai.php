<?php
/**
 * Plugin Name:       WSS AI
 * Plugin URI:        https://github.com/Ecomscene/wss-ai
 * Description:       AI-gereedschap voor je webshop: een medewerker die meedenkt, betere productfoto's en teksten die vindbaar zijn. Beheerd door Webshopschool.
 * Version:           0.9.1
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

define( 'WSS_AI_VERSIE', '0.9.1' );
define( 'WSS_AI_BESTAND', __FILE__ );
define( 'WSS_AI_MAP', plugin_dir_path( __FILE__ ) );

require_once WSS_AI_MAP . 'includes/class-wss-ai-updater.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-koppeling.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-instellingen.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-seo.php';
require_once WSS_AI_MAP . 'includes/class-wss-ai-fotostudio.php';

/**
 * Het menu-item in wp-admin.
 *
 * Positie 58 zet hem net onder WooCommerce en boven Weergave -- in het blok waar
 * de dingen staan waar je iets mee doet, niet tussen de instellingen. Wie de
 * plugin installeert moet hem kunnen vinden zonder ernaar te zoeken.
 */
function wss_ai_menu() {
	add_menu_page(
		__( 'WSS AI', 'wss-ai' ),
		__( 'WSS AI', 'wss-ai' ),
		'manage_options',
		'wss-ai',
		'wss_ai_pagina',
		wss_ai_icoon(),
		58
	);
}
add_action( 'admin_menu', 'wss_ai_menu' );

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
 * De pagina zelf.
 *
 * Bewust nog leeg op de inhoud na die vertelt wat er komt. Wat er wél staat is
 * het versienummer en of automatisch bijwerken werkt: dat is precies wat je moet
 * kunnen zien voordat je hier iets echts op zet.
 */
function wss_ai_pagina() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
	}

	$stand = WSS_AI_Updater::stand();
	?>
	<div class="wrap wss-ai">
		<h1><?php esc_html_e( 'WSS AI', 'wss-ai' ); ?></h1>

		<?php if ( isset( $_GET['wss_ai_bewaard'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p><?php esc_html_e( 'Opgeslagen. De volgende tekst wordt zo geschreven.', 'wss-ai' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="wss-ai-kaart">
			<h2><?php esc_html_e( 'Teksten schrijven met AI', 'wss-ai' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'Open een product in je webshop. Onder de productgegevens staat het blok "WSS AI - teksten schrijven" met drie knoppen: een productomschrijving, een SEO-titel en een SEO-omschrijving.',
					'wss-ai'
				);
				?>
			</p>
			<p class="wss-ai-mut">
				<?php
				esc_html_e(
					'Hoe meer er van een product is ingevuld en opgeslagen, hoe beter de tekst wordt. Weet je het even niet? Tik dan ruw in het omschrijvingsveld wat je erover kunt vertellen - steekwoorden, halve zinnen, typefouten maken niet uit. Dat is voer voor de AI.',
					'wss-ai'
				);
				?>
			</p>
			<p class="wss-ai-mut">
				<?php esc_html_e( 'Wat eraan komt: een AI-medewerker die je vragen over je webshop beantwoordt.', 'wss-ai' ); ?>
			</p>
		</div>

		<?php WSS_AI_Instellingen::kaart(); ?>

		<?php WSS_AI_Fotostudio::kaart(); ?>

		<div class="wss-ai-kaart">
			<h2><?php esc_html_e( 'Koppeling met Webshopschool', 'wss-ai' ); ?></h2>
			<table class="widefat striped wss-ai-tabel">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'wss-ai' ); ?></th>
						<td>
							<?php
							if ( WSS_AI_Koppeling::is_actief() ) {
								echo '<span class="wss-ai-goed">' . esc_html__( 'Gekoppeld', 'wss-ai' ) . '</span> ';
							} else {
								echo '<span class="wss-ai-let-op">' . esc_html__( 'Nog niet aan', 'wss-ai' ) . '</span> ';
							}
							echo esc_html( WSS_AI_Koppeling::uitleg() );
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wss-ai&wss_ai_koppel=1' ), 'wss_ai_koppel' ) ); ?>" class="button">
					<?php esc_html_e( 'Opnieuw koppelen', 'wss-ai' ); ?>
				</a>
			</p>
		</div>

		<div class="wss-ai-kaart">
			<h2><?php esc_html_e( 'Over deze plugin', 'wss-ai' ); ?></h2>
			<table class="widefat striped wss-ai-tabel">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Versie', 'wss-ai' ); ?></th>
						<td><?php echo esc_html( WSS_AI_VERSIE ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatisch bijwerken', 'wss-ai' ); ?></th>
						<td>
							<?php
							/* Nooit een groen vinkje als we het niet weten. Zolang de
							   controle niet is gelukt hoort er te staan dát hij niet is
							   gelukt -- anders denk je dat updates binnenkomen terwijl
							   er niets gebeurt. */
							if ( 'ok' === $stand['soort'] ) {
								echo '<span class="wss-ai-goed">' . esc_html__( 'Werkt', 'wss-ai' ) . '</span> ';
								echo esc_html( $stand['tekst'] );
							} elseif ( 'nieuw' === $stand['soort'] ) {
								echo '<span class="wss-ai-let-op">' . esc_html__( 'Update beschikbaar', 'wss-ai' ) . '</span> ';
								echo esc_html( $stand['tekst'] );
							} else {
								echo '<span class="wss-ai-onbekend">' . esc_html__( 'Niet gecontroleerd', 'wss-ai' ) . '</span> ';
								echo esc_html( $stand['tekst'] );
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wss-ai&wss_ai_check=1' ), 'wss_ai_check' ) ); ?>" class="button">
					<?php esc_html_e( 'Nu op updates controleren', 'wss-ai' ); ?>
				</a>
			</p>
		</div>
	</div>
	<?php
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
 * Een beetje opmaak. Bewust weinig: dit moet bij wp-admin passen, niet opvallen.
 *
 * Op twee schermen: onze eigen pagina en het productscherm, want daar staat het
 * blok met de knoppen. Dat laatste stond er eerst niet bij, waardoor die knoppen
 * onder elkaar vielen in plaats van naast elkaar.
 */
function wss_ai_stijl( $hook ) {
	$eigen_pagina = ( 'toplevel_page_wss-ai' === $hook );
	$productscherm = false;
	if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		$post = get_post();
		$productscherm = ( $post && 'product' === $post->post_type );
	}
	if ( ! $eigen_pagina && ! $productscherm ) {
		return;
	}
	$css ='.wss-ai-kaart{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:760px}'
		. '.wss-ai-kaart h2{margin-top:0}'
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
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wss_ai_dagelijks' );
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
	}
);

/* De updater aanzetten. Zie includes/class-wss-ai-updater.php. */
WSS_AI_Updater::init( 'Ecomscene', 'wss-ai' );
WSS_AI_Instellingen::init();
WSS_AI_SEO::init();
WSS_AI_Fotostudio::init();
