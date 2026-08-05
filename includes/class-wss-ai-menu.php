<?php
/**
 * Het menu en de indeling van de beheerpagina's.
 *
 * WAAROM SUBPAGINA'S
 * Alles op één pagina werkte zolang er twee kaarten stonden. Met teksten,
 * foto's, een fotostijl en verzoeken erbij wordt het een lap waarin je moet
 * zoeken. Nu is er een hoofdpagina die vertelt wat er is en waar je moet zijn,
 * en drie pagina's waar je iets doet.
 *
 * De hoofdpagina is bewust geen dashboard met cijfers. Wie hier komt wil weten
 * wat hij met deze plugin kan en waar de knop zit; getallen die niemand nodig
 * heeft staan dat in de weg.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Menu {

	const HOOFD = 'wss-ai';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
	}

	/**
	 * De pagina's aanmelden.
	 *
	 * Op prioriteit 9, dus vóór de standaard. Het voorraadbeheer meldt zich op de
	 * gewone prioriteit aan en komt daardoor netjes ná ons in de lijst, in plaats
	 * van ertussen te schuiven op een plek die per WordPress-versie verschilt.
	 *
	 * POSITIE 3, DUS DIRECT ONDER DASHBOARD
	 * WordPress zet Dashboard op 2 en een scheidingslijn op 4 (nagelopen in
	 * wp-admin/menu.php). Alles daartussen komt er dus tussen te staan, boven
	 * Berichten. Dat is waar dit hoort: je klant moet er tegenaan lopen, niet
	 * ernaar zoeken tussen de dingen die hij nooit gebruikt.
	 */
	public static function menu() {
		add_menu_page(
			__( 'WSS Tools', 'wss-ai' ),
			__( 'WSS Tools', 'wss-ai' ),
			'manage_options',
			self::HOOFD,
			array( __CLASS__, 'toon_hoofd' ),
			wss_ai_icoon(),
			3
		);

		/* Een onderdeel dat Webshopschool heeft uitgezet krijgt geen pagina. Een
		   menu-item dat naar een lege pagina leidt is vervelender dan een
		   menu-item dat er niet is. */
		$paginas = array(
			array( self::HOOFD, __( 'Overzicht', 'wss-ai' ), array( __CLASS__, 'toon_hoofd' ), '' ),
			array( 'wss-ai-teksten', __( 'Teksten', 'wss-ai' ), array( __CLASS__, 'toon_teksten' ), 'teksten' ),
			array( 'wss-ai-afbeeldingen', __( 'Afbeeldingen', 'wss-ai' ), array( __CLASS__, 'toon_afbeeldingen' ), 'afbeeldingen' ),
		);
		foreach ( $paginas as $p ) {
			if ( $p[3] && ! WSS_AI_Koppeling::module_aan( $p[3] ) ) {
				continue;
			}
			add_submenu_page( self::HOOFD, $p[1], $p[1], 'manage_options', $p[0], $p[2] );
		}

		self::verwijzingen();

		$rest = array(
			array( 'wss-ai-verzoeken', __( 'Tickets & verzoeken', 'wss-ai' ), array( __CLASS__, 'toon_verzoeken' ), 'verzoeken' ),
			/* De titel in het menu is hier anders dan de titel van de pagina: in
			   het menu staat er opmaak omheen zodat hij opvalt. Bovenaan de
			   pagina zou diezelfde opmaak alleen maar schreeuwen. */
			array( WSS_AI_Upgrades::SLUG, __( 'Upgrades', 'wss-ai' ), array( __CLASS__, 'toon_upgrades' ), 'upgrades', WSS_AI_Upgrades::menutitel() ),
		);
		foreach ( $rest as $p ) {
			if ( $p[3] && ! WSS_AI_Koppeling::module_aan( $p[3] ) ) {
				continue;
			}
			$menutitel = isset( $p[4] ) ? $p[4] : $p[1];
			add_submenu_page( self::HOOFD, $p[1], $menutitel, 'manage_options', $p[0], $p[2] );
		}
	}

	/**
	 * Voorraadbeheer en de nieuwsbrief: hier alleen een LINK, geen pagina.
	 *
	 * Allebei hebben ze een eigen hoofdmenu-item onder Producten, want dat is
	 * waar je ze zoekt als je aan voorraad of aan mail denkt. Onder WSS Tools
	 * staan ze er ook bij, zodat je vanaf één plek alles kunt vinden.
	 *
	 * WAAROM DIT RECHTSTREEKS IN $submenu GAAT
	 * Met add_submenu_page zou dezelfde slug twee ouders krijgen. WordPress
	 * leidt de naam waaronder hij een pagina opzoekt af van die ouder, en met
	 * twee ouders hangt het van de volgorde af welke hij pakt. Dan krijg je een
	 * lege pagina op een deel van de klikken. Precies die val kostte eerder het
	 * bulkscherm.
	 *
	 * Een regel met "admin.php?page=..." erin wordt door WordPress als adres
	 * gebruikt in plaats van als paginanaam (nagelopen in menu-header.php). Zo
	 * is het een gewone link en blijft er één plek waar die pagina hoort.
	 */
	private static function verwijzingen() {
		global $submenu;

		if ( WSS_AI_Voorraad::beschikbaar() ) {
			$submenu[ self::HOOFD ][] = array(
				__( 'Voorraadbeheer', 'wss-ai' ),
				'manage_woocommerce',
				'admin.php?page=' . WSS_AI_Voorraad::SLUG,
			);
		}

		if ( WSS_AI_Mailer::beschikbaar() ) {
			$submenu[ self::HOOFD ][] = array(
				__( 'Nieuwsbrief & Flows', 'wss-ai' ),
				'manage_woocommerce',
				'admin.php?page=ws-flow-mailer',
			);
		}
	}

	/** Alle onderdelen op één plek, zodat de knoppen en de uitleg niet uiteenlopen. */
	public static function onderdelen() {
		return array(
			array(
				'slug'   => 'wss-ai-teksten',
				'module' => 'teksten',
				'naam'   => __( 'Teksten', 'wss-ai' ),
				'kort'   => __( 'Productomschrijvingen en SEO-teksten met één knop, in jouw schrijfstijl.', 'wss-ai' ),
				'waar'   => __( 'Je vindt de knoppen bij een product, onder de productgegevens.', 'wss-ai' ),
				'icoon'  => 'edit',
			),
			array(
				'slug'   => 'wss-ai-afbeeldingen',
				'module' => 'afbeeldingen',
				'naam'   => __( 'Afbeeldingen', 'wss-ai' ),
				'kort'   => __( 'Betere productfoto\'s: een foto vernieuwen, een variant maken of de achtergrond weghalen.', 'wss-ai' ),
				'waar'   => __( 'Je vindt de knoppen bij een product, bij de hoofdfoto en bij de galerij. Meerdere producten tegelijk kan via het productenoverzicht.', 'wss-ai' ),
				'icoon'  => 'format-image',
			),
			array(
				'slug'   => WSS_AI_Upgrades::SLUG,
				'module' => 'upgrades',
				'naam'   => __( 'Upgrades', 'wss-ai' ),
				'kort'   => __( 'Dingen die we voor je kunnen doen naast wat er in deze plugin zit.', 'wss-ai' ),
				'waar'   => __( 'Je vraagt ze hier aan; wij nemen contact met je op.', 'wss-ai' ),
				'icoon'  => 'star-filled',
			),
			array(
				'slug'   => 'wss-ai-verzoeken',
				'module' => 'verzoeken',
				'naam'   => __( 'Tickets & verzoeken', 'wss-ai' ),
				'kort'   => __( 'Een vraag, een idee of iets dat niet werkt? Stuur het hiervandaan naar Webshopschool.', 'wss-ai' ),
				'waar'   => __( 'Je krijgt antwoord op het e-mailadres van je website.', 'wss-ai' ),
				'icoon'  => 'email',
			),
		);
	}

	/* ---------------- de pagina's ---------------- */

	private static function kop( $titel ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		echo '<div class="wrap wss-ai"><h1>' . esc_html( $titel ) . '</h1>';

		if ( isset( $_GET['wss_ai_bewaard'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Opgeslagen.', 'wss-ai' ) . '</p></div>';
		}
	}

	private static function voet() {
		echo '</div>';
	}

	public static function toon_hoofd() {
		self::kop( __( 'WSS Tools', 'wss-ai' ) );
		$stand = WSS_AI_Updater::stand();
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'WSS Tools is het gereedschap van Webshopschool in je eigen webshop. Je schrijft er teksten mee, je maakt er betere productfoto\'s mee, en je bereikt ons ermee als er iets is. Het rekenwerk gebeurt bij ons; jij ziet alleen het resultaat en beslist zelf wat je ermee doet.', 'wss-ai' ); ?>
		</p>

		<div class="wss-ai-tegels">
			<?php
			foreach ( self::onderdelen() as $o ) :
				if ( ! WSS_AI_Koppeling::module_aan( $o['module'] ) ) {
					continue;
				}
				?>
				<a class="wss-ai-tegel" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $o['slug'] ) ); ?>">
					<span class="dashicons dashicons-<?php echo esc_attr( $o['icoon'] ); ?>"></span>
					<strong><?php echo esc_html( $o['naam'] ); ?></strong>
					<span class="wss-ai-mut"><?php echo esc_html( $o['kort'] ); ?></span>
					<span class="wss-ai-mut wss-ai-klein"><?php echo esc_html( $o['waar'] ); ?></span>
				</a>
			<?php endforeach; ?>

			<?php if ( WSS_AI_Mailer::beschikbaar() ) : ?>
					<a class="wss-ai-tegel" href="<?php echo esc_url( admin_url( 'admin.php?page=ws-flow-mailer' ) ); ?>">
						<span class="dashicons dashicons-email-alt"></span>
						<strong><?php esc_html_e( 'Nieuwsbrief', 'wss-ai' ); ?></strong>
						<span class="wss-ai-mut"><?php esc_html_e( 'Automatische mails: een herinnering bij een vergeten winkelwagen, een berichtje na een bestelling.', 'wss-ai' ); ?></span>
						<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Staat in het menu links, net onder Voorraadbeheer.', 'wss-ai' ); ?></span>
					</a>
				<?php endif; ?>

				<?php if ( WSS_AI_Voorraad::beschikbaar() ) : ?>
				<a class="wss-ai-tegel" href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSS_AI_Voorraad::SLUG ) ); ?>">
					<span class="dashicons dashicons-archive"></span>
					<strong><?php esc_html_e( 'Voorraadbeheer', 'wss-ai' ); ?></strong>
					<span class="wss-ai-mut"><?php esc_html_e( 'Voorraad, inkoopprijs en leverancier van al je producten op één scherm.', 'wss-ai' ); ?></span>
					<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Staat in het menu links, net onder Producten.', 'wss-ai' ); ?></span>
				</a>
			<?php endif; ?>
		</div>

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
							   gelukt, anders denk je dat updates binnenkomen terwijl er
							   niets gebeurt. */
							if ( 'ok' === $stand['soort'] ) {
								echo '<span class="wss-ai-goed">' . esc_html__( 'Werkt', 'wss-ai' ) . '</span> ';
							} elseif ( 'nieuw' === $stand['soort'] ) {
								echo '<span class="wss-ai-let-op">' . esc_html__( 'Update beschikbaar', 'wss-ai' ) . '</span> ';
							} else {
								echo '<span class="wss-ai-onbekend">' . esc_html__( 'Niet gecontroleerd', 'wss-ai' ) . '</span> ';
							}
							echo esc_html( $stand['tekst'] );
							?>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wss-ai&wss_ai_koppel=1' ), 'wss_ai_koppel' ) ); ?>" class="button">
					<?php esc_html_e( 'Opnieuw koppelen', 'wss-ai' ); ?>
				</a>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=wss-ai&wss_ai_check=1' ), 'wss_ai_check' ) ); ?>" class="button">
					<?php esc_html_e( 'Nu op updates controleren', 'wss-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
		self::voet();
	}

	public static function toon_teksten() {
		self::kop( __( 'Teksten', 'wss-ai' ) );
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'Open een product in je webshop. Onder de productgegevens staat het blok "WSS Tools - teksten schrijven" met drie knoppen: een productomschrijving, een SEO-titel en een SEO-omschrijving.', 'wss-ai' ); ?>
		</p>
		<p class="wss-ai-mut">
			<?php esc_html_e( 'Hoe meer er van een product is ingevuld en opgeslagen, hoe beter de tekst wordt. Weet je het even niet? Tik dan ruw in het omschrijvingsveld wat je erover kunt vertellen: steekwoorden, halve zinnen, typefouten maken niet uit. Dat is voer voor de AI.', 'wss-ai' ); ?>
		</p>
		<?php
		WSS_AI_Instellingen::kaart();
		self::voet();
	}

	public static function toon_afbeeldingen() {
		self::kop( __( 'Afbeeldingen', 'wss-ai' ) );
		?>
		<p class="wss-ai-inleiding">
			<?php esc_html_e( 'Bij een product staat een knop bij de hoofdfoto en bij de galerij. Daar kies je wat er moet gebeuren: de foto vernieuwen, een variant maken, of de achtergrond weghalen. Er verandert pas iets aan je product als jij het resultaat goedkeurt.', 'wss-ai' ); ?>
		</p>
		<p class="wss-ai-mut">
			<?php
			printf(
				/* translators: %s wordt de link naar het productenoverzicht. */
				esc_html__( 'Voor meerdere producten tegelijk: vink ze aan in %s en kies bij de bulkacties "Bulk afbeeldingen".', 'wss-ai' ),
				'<a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'je productenoverzicht', 'wss-ai' ) . '</a>'
			);
			?>
		</p>
		<?php
		WSS_AI_Fotostudio::kaart();
		self::voet();
	}

	public static function toon_upgrades() {
		self::kop( __( 'Upgrades', 'wss-ai' ) );
		WSS_AI_Upgrades::pagina();
		self::voet();
	}

	public static function toon_verzoeken() {
		self::kop( __( 'Tickets & verzoeken', 'wss-ai' ) );
		WSS_AI_Verzoek::kaart();
		self::voet();
	}
}
