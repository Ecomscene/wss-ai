<?php
/**
 * Foto's maken vanuit het productscherm.
 *
 * TWEE KNOPPEN, EEN PANEEL
 * Bij de hoofdfoto en bij de galerij staat een knop. Allebei openen ze hetzelfde
 * paneel: je ziet de foto waarmee we beginnen, je kunt er iets bij typen dat
 * alleen voor dit product geldt, en daarna zie je het resultaat. Pas als je op
 * "gebruiken" klikt verandert er iets aan je product.
 *
 * WAAROM ER EERST EEN VOORBEELD KOMT
 * Een foto die meteen op de productpagina belandt is een foto die niemand heeft
 * gezien. Bij tekst kun je dat terugdraaien met een klik; bij een hoofdfoto zit
 * je met een winkel die er even anders uitzag. Dus: maken, kijken, dan pas
 * gebruiken.
 *
 * WAT ER NAAR ONS TOE GAAT
 * De foto zelf, verkleind. Niet het adres waar hij staat: bij een webshop achter
 * een firewall of op een testomgeving kunnen wij daar niet bij, en dan zou deze
 * knop het bij de ene klant wel doen en bij de andere niet zonder dat iemand
 * begrijpt waarom.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Fotostudio {

	/**
	 * Welke AI het werk doet. Eén keer kiezen, want dat is geen vraag die je bij
	 * elk product opnieuw wilt beantwoorden. WAT er moet gebeuren kies je wel per
	 * product; dat staat in het paneel.
	 */
	const MOTOR   = 'wss_ai_foto_motor';
	const STIJL   = 'wss_ai_foto_stijl';
	const BRONNEN = 'wss_ai_foto_bronnen';

	/** De extra opdracht die bij dit ene product hoort. */
	const PROMPT_META = '_wss_ai_foto_prompt';

	/** Waar we de foto op terugbrengen voor we hem opsturen. */
	const MAX_ZIJDE = 1536;

	public static function init() {
		add_action( 'admin_post_wss_ai_foto_instellingen', array( __CLASS__, 'opslaan' ) );
		add_filter( 'admin_post_thumbnail_html', array( __CLASS__, 'knop_bij_hoofdfoto' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		add_action( 'admin_footer', array( __CLASS__, 'paneel' ) );

		add_action( 'wp_ajax_wss_ai_foto_genereer', array( __CLASS__, 'ajax_genereer' ) );
		add_action( 'wp_ajax_wss_ai_foto_bijwerken', array( __CLASS__, 'ajax_bijwerken' ) );
		add_action( 'wp_ajax_wss_ai_foto_toepassen', array( __CLASS__, 'ajax_toepassen' ) );
		add_action( 'wp_ajax_wss_ai_foto_stijl', array( __CLASS__, 'ajax_stijl' ) );
	}

	/* ---------------- instellingen ---------------- */

	public static function motor() {
		$m = get_option( self::MOTOR, '' );
		return is_string( $m ) ? $m : '';
	}

	public static function stijl() {
		$s = get_option( self::STIJL, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Het recept dat met elke foto meegaat. Leeg is prima: dan geen stijl. */
	public static function recept() {
		$s = self::stijl();
		return isset( $s['recept'] ) ? (string) $s['recept'] : '';
	}

	public static function bronnen() {
		$b = get_option( self::BRONNEN, array() );
		return array(
			'media'     => isset( $b['media'] ) && is_array( $b['media'] ) ? array_map( 'absint', $b['media'] ) : array(),
			'producten' => isset( $b['producten'] ) && is_array( $b['producten'] ) ? array_map( 'absint', $b['producten'] ) : array(),
		);
	}

	public static function opslaan() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_ai_foto_instellingen' );

		$motor = isset( $_POST['motor'] ) ? sanitize_key( wp_unslash( $_POST['motor'] ) ) : '';
		update_option( self::MOTOR, $motor );

		/* De beschrijving mag met de hand aangepast worden. Het recept dat naar
		   het model gaat blijft dan staan zoals het was: dat is Engels en
		   technisch, en iemand die de Nederlandse tekst bijschaaft bedoelt niet
		   dat de opdracht aan het model overboord moet. */
		$stijl = self::stijl();
		if ( isset( $_POST['beschrijving'] ) ) {
			$stijl['beschrijving'] = sanitize_textarea_field( wp_unslash( $_POST['beschrijving'] ) );
		}
		if ( isset( $_POST['eigen'] ) ) {
			$stijl['eigen'] = sanitize_textarea_field( wp_unslash( $_POST['eigen'] ) );
		}
		update_option( self::STIJL, $stijl );

		wp_safe_redirect( admin_url( 'admin.php?page=wss-ai&wss_ai_bewaard=1#wss-ai-fotos' ) );
		exit;
	}

	/* ---------------- de knoppen ---------------- */

	/**
	 * De knop onder de hoofdfoto.
	 *
	 * Via de filter van WordPress zelf, niet door in het blok van iemand anders
	 * te prikken. Voor de galerij bestaat zo'n filter niet; die knop wordt er met
	 * JavaScript bij gezet, en als dat blok er niet is verschijnt hij gewoon niet.
	 */
	public static function knop_bij_hoofdfoto( $html, $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'product' !== $post->post_type || ! WSS_AI_Koppeling::is_actief() ) {
			return $html;
		}
		return $html . '<p class="wss-ai-fotoknop"><button type="button" class="button" data-wss-foto="hoofd">'
			. esc_html__( 'Nieuwe foto met AI', 'wss-ai' ) . '</button></p>';
	}

	public static function scripts( $hook ) {
		$post = get_post();
		$op_product = in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& $post && 'product' === $post->post_type;
		$op_pagina = ( 'wss-ai_page_wss-ai-afbeeldingen' === $hook );
		$op_bulk = ( 'wss-ai_page_' . WSS_AI_Bulk::SLUG === $hook );

		if ( ! $op_product && ! $op_pagina && ! $op_bulk ) {
			return;
		}

		/* wp.media is de kiezer van WordPress zelf. Op de instellingenpagina
		   hebben we hem nodig om voorbeeldfoto's te kiezen. */
		if ( $op_pagina ) {
			wp_enqueue_media();
		}

		wp_enqueue_script(
			'wss-ai-foto',
			plugins_url( 'assets/foto.js', WSS_AI_BESTAND ),
			array( 'jquery' ),
			WSS_AI_VERSIE,
			true
		);
		wp_localize_script(
			'wss-ai-foto',
			'wssAiFoto',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wss_ai_foto' ),
				'post'    => $post ? $post->ID : 0,
				'prompt'  => $post ? (string) get_post_meta( $post->ID, self::PROMPT_META, true ) : '',
				'opPagina' => $op_pagina,
				'taal'    => array(
					'bezig'      => __( 'Bezig met maken. Dit duurt ongeveer een halve minuut.', 'wss-ai' ),
					'stijlBezig' => __( 'Bezig met kijken naar je foto\'s…', 'wss-ai' ),
					'mislukt'    => __( 'Dit lukte niet.', 'wss-ai' ),
					'toepassen'  => __( 'Bezig met plaatsen…', 'wss-ai' ),
					'geplaatst'  => __( 'Gelukt. De foto staat op je product; vergeet niet op te slaan.', 'wss-ai' ),
					'kiesMedia'  => __( 'Kies foto\'s die de stijl laten zien', 'wss-ai' ),
					'kiesKnop'   => __( 'Gebruik deze foto\'s', 'wss-ai' ),
					/* Bij een variant vraagt hetzelfde veld iets anders, en is het
					   niet optioneel. Een model dat zelf een omgeving mag verzinnen
					   kiest meestal de omgeving die er al stond. */
					'extraKop'   => __( 'Iets erbij voor dit product', 'wss-ai' ),
					'extraUit'   => __( 'Optioneel. Bijvoorbeeld: op een houten plank, met een takje eucalyptus ernaast.', 'wss-ai' ),
					'plekKop'    => __( 'Waar moet je product komen te staan?', 'wss-ai' ),
					'plekUit'    => __( 'Beschrijf kort een plek of situatie. Bijvoorbeeld: op een bank in een woonkamer, op een houten bureau, op een plank in de badkamer. Wil je nog een variant? Noem dan een andere plek.', 'wss-ai' ),
					'plekLeeg'   => __( 'Vertel eerst waar je product moet komen te staan.', 'wss-ai' ),
					'bijwerkBezig' => __( 'Bezig met aanpassen…', 'wss-ai' ),
					'bijwerkLeeg'  => __( 'Vertel wat er anders moet aan deze foto.', 'wss-ai' ),
					'bijgewerkt'   => __( 'Aangepast. Nog niet goed? Je kunt het nog een keer proberen.', 'wss-ai' ),
				),
			)
		);

		/* Het bulkscherm stuurt dezelfde acties aan, dus het heeft bovenstaande
		   gegevens nodig. Vandaar dat het hier wordt aangehaakt en niet in zijn
		   eigen klasse: twee plekken die een nonce klaarzetten is één te veel. */
		if ( $op_bulk ) {
			wp_enqueue_script(
				'wss-ai-bulk',
				plugins_url( 'assets/bulk.js', WSS_AI_BESTAND ),
				array( 'jquery', 'wss-ai-foto' ),
				WSS_AI_VERSIE,
				true
			);
			wp_localize_script( 'wss-ai-bulk', 'wssAiBulk', WSS_AI_Bulk::scriptgegevens() );
		}
	}

	/** Het paneel staat één keer in de voettekst en is standaard verborgen. */
	public static function paneel() {
		$post = get_post();
		if ( ! $post || 'product' !== $post->post_type || ! WSS_AI_Koppeling::is_actief() ) {
			return;
		}
		$stijl = self::stijl();
		?>
		<div class="wss-ai-paneel" hidden>
			<div class="wss-ai-paneel-vak" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Nieuwe foto met AI', 'wss-ai' ); ?>">
				<button type="button" class="wss-ai-sluit" aria-label="<?php esc_attr_e( 'Sluiten', 'wss-ai' ); ?>">&times;</button>
				<h2 class="wss-ai-paneel-kop"><?php esc_html_e( 'Nieuwe foto met AI', 'wss-ai' ); ?></h2>

				<div class="wss-ai-paneel-beelden">
					<figure>
						<figcaption><?php esc_html_e( 'Waar we mee beginnen', 'wss-ai' ); ?></figcaption>
						<img class="wss-ai-bron" src="" alt="">
						<div class="wss-ai-bronkiezer"></div>
					</figure>
					<figure class="wss-ai-nieuw-vak" hidden>
						<figcaption><?php esc_html_e( 'Wat eruit kwam', 'wss-ai' ); ?></figcaption>
						<img class="wss-ai-nieuw" src="" alt="">
					</figure>
				</div>

				<?php
				/* Bijschaven kan pas als er iets ligt om te schaven. Bijna goed is
				   het vervelendste resultaat: opnieuw maken kost je ook alles wat
				   wel klopte. */
				?>
				<div class="wss-ai-bijwerk-vak" hidden>
					<label for="wss-ai-bijwerk"><strong><?php esc_html_e( 'Nog iets aanpassen aan deze foto?', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein">
						<?php esc_html_e( 'Alleen dit ene ding verandert; de rest van de foto blijft staan. Bijvoorbeeld: haal het takje links weg, of maak de achtergrond wat lichter.', 'wss-ai' ); ?>
					</span>
					<div class="wss-ai-bijwerk-rij">
						<input type="text" id="wss-ai-bijwerk" class="regular-text" placeholder="<?php esc_attr_e( 'haal het takje links weg', 'wss-ai' ); ?>">
						<button type="button" class="button wss-ai-bijwerk-knop"><?php esc_html_e( 'Pas aan', 'wss-ai' ); ?></button>
					</div>
				</div>

				<fieldset class="wss-ai-taken">
					<legend><strong><?php esc_html_e( 'Wat moet er gebeuren?', 'wss-ai' ); ?></strong></legend>
					<?php
					$taken = WSS_AI_Koppeling::fototaken();
					if ( empty( $taken ) ) {
						/* De lijst kon niet opgehaald worden. Dan tonen we de twee die er
						   altijd zijn, in plaats van een leeg blok waarin niets te kiezen
						   valt. De server beslist alsnog wat hij ermee doet. */
						$taken = array(
							array( 'key' => 'vernieuwen', 'label' => __( 'Foto vernieuwen', 'wss-ai' ), 'hint' => __( 'Dezelfde opname, mooiere omgeving en beter licht', 'wss-ai' ), 'gebruiktStijl' => true ),
							array( 'key' => 'variant', 'label' => __( 'Variant maken', 'wss-ai' ), 'hint' => __( 'Hetzelfde product in een andere omgeving, in jouw stijl', 'wss-ai' ), 'gebruiktStijl' => true ),
							array( 'key' => 'achtergrond', 'label' => __( 'Achtergrond weghalen', 'wss-ai' ), 'hint' => __( 'Je product vrijstaand, met een doorzichtige achtergrond', 'wss-ai' ), 'gebruiktStijl' => false ),
						);
					}
					foreach ( $taken as $i => $t ) :
						?>
						<label class="wss-ai-taak">
							<input type="radio" name="wss-ai-taak" value="<?php echo esc_attr( $t['key'] ); ?>"
								data-stijl="<?php echo empty( $t['gebruiktStijl'] ) ? '0' : '1'; ?>"
								<?php checked( 0, $i ); ?>>
							<span>
								<strong><?php echo esc_html( $t['label'] ); ?></strong><br>
								<span class="wss-ai-mut wss-ai-klein"><?php echo esc_html( isset( $t['hint'] ) ? $t['hint'] : '' ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>


				<p class="wss-ai-mut wss-ai-klein wss-ai-stijlregel">
					<?php
					if ( ! empty( $stijl['beschrijving'] ) || ! empty( $stijl['eigen'] ) ) {
						echo esc_html__( 'Je eigen fotostijl wordt gebruikt.', 'wss-ai' ) . ' ';
						printf(
							'<a href="%s">%s</a>',
							esc_url( admin_url( 'admin.php?page=wss-ai#wss-ai-fotos' ) ),
							esc_html__( 'Aanpassen', 'wss-ai' )
						);
					} else {
						printf(
							/* translators: %s wordt de link naar de instellingen. */
							esc_html__( 'Je hebt nog geen fotostijl ingesteld. Dat doe je bij %s.', 'wss-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wss-ai#wss-ai-fotos' ) ) . '">' . esc_html__( 'WSS AI', 'wss-ai' ) . '</a>'
						);
					}
					?>
				</p>

				<p class="wss-ai-extra-vak">
					<label for="wss-ai-extra"><strong class="wss-ai-extra-kop"><?php esc_html_e( 'Iets erbij voor dit product', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein wss-ai-extra-uitleg"><?php esc_html_e( 'Optioneel. Bijvoorbeeld: op een houten plank, met een takje eucalyptus ernaast.', 'wss-ai' ); ?></span>
					<textarea id="wss-ai-extra" rows="2" class="large-text"></textarea>
				</p>

				<p class="wss-ai-paneel-knoppen">
					<button type="button" class="button button-primary wss-ai-maak"><?php esc_html_e( 'Maak de foto', 'wss-ai' ); ?></button>
					<button type="button" class="button wss-ai-gebruik" hidden><?php esc_html_e( 'Gebruiken', 'wss-ai' ); ?></button>
					<button type="button" class="button wss-ai-opnieuw" hidden><?php esc_html_e( 'Nog een keer', 'wss-ai' ); ?></button>
					<span class="wss-ai-paneel-melding" aria-live="polite"></span>
				</p>
				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Er verandert pas iets aan je product als je op Gebruiken klikt.', 'wss-ai' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/* ---------------- de foto opsturen en terugkrijgen ---------------- */

	/**
	 * Welke afbeeldingen bij dit product horen: de hoofdfoto en de galerij.
	 *
	 * Dat is tegelijk de lijst waaruit gekozen mag worden. Een verzoek met een
	 * nummer dat er niet in staat wordt niet geweigerd maar teruggebracht tot de
	 * hoofdfoto: de winkelier heeft er niets aan om te horen dat zijn keuze
	 * ongeldig was, en wij hebben er niets aan om zomaar een willekeurige
	 * afbeelding uit de mediabibliotheek te versturen.
	 */
	public static function eigen_afbeeldingen( $post_id ) {
		$ids = array();
		$hoofd = (int) get_post_thumbnail_id( $post_id );
		if ( $hoofd ) {
			$ids[] = $hoofd;
		}
		$galerij = get_post_meta( $post_id, '_product_image_gallery', true );
		foreach ( array_filter( array_map( 'absint', explode( ',', (string) $galerij ) ) ) as $id ) {
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	private static function eigen_afbeelding( $post_id, $gevraagd ) {
		$eigen = self::eigen_afbeeldingen( $post_id );
		if ( $gevraagd && in_array( (int) $gevraagd, $eigen, true ) ) {
			return (int) $gevraagd;
		}
		return $eigen ? $eigen[0] : 0;
	}

	/**
	 * Een afbeelding klaarmaken om op te sturen: kleiner, en als base64.
	 *
	 * Kleiner omdat een productfoto van vier megapixel niets toevoegt voor het
	 * model en de verzending alleen traag maakt. Lukt het verkleinen niet, dan
	 * gaat het origineel mee zolang dat binnen de perken blijft; een foutmelding
	 * over een ontbrekende beeldbibliotheek helpt de winkelier niet.
	 */
	private static function beeld_klaar( $attachment_id ) {
		$pad = get_attached_file( $attachment_id );
		if ( ! $pad || ! file_exists( $pad ) ) {
			return null;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			$mime = 'image/jpeg';
		}

		$editor = wp_get_image_editor( $pad );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( self::MAX_ZIJDE, self::MAX_ZIJDE, false );
			$editor->set_quality( 82 );
			$tijdelijk = trailingslashit( get_temp_dir() ) . 'wss-ai-' . wp_generate_password( 8, false ) . '.jpg';
			$bewaard = $editor->save( $tijdelijk, 'image/jpeg' );
			if ( ! is_wp_error( $bewaard ) && ! empty( $bewaard['path'] ) && file_exists( $bewaard['path'] ) ) {
				$data = file_get_contents( $bewaard['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				wp_delete_file( $bewaard['path'] );
				if ( $data ) {
					return array( 'data' => base64_encode( $data ), 'mime' => 'image/jpeg' );
				}
			}
		}

		if ( filesize( $pad ) > 6 * 1024 * 1024 ) {
			return null;
		}
		$data = file_get_contents( $pad ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $data ? array( 'data' => base64_encode( $data ), 'mime' => $mime ) : null;
	}

	public static function ajax_genereer() {
		check_ajax_referer( 'wss_ai_foto', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Je mag dit product niet bewerken.', 'wss-ai' ) ) );
		}

		/* De winkelier kiest zelf met welke foto we beginnen: de hoofdfoto of een
		   van zijn galerijfoto's. Zonder die keuze werd altijd de hoofdfoto
		   gepakt, ook als je een galerijfoto wilde maken, en dan kreeg je dus
		   opnieuw een variant op dezelfde foto.

		   Wel controleren dat het een afbeelding is die bij dit product hoort.
		   Anders zou een verzoek met een willekeurig nummer elke afbeelding uit
		   de mediabibliotheek naar buiten kunnen sturen. */
		$gevraagd = isset( $_POST['bron'] ) ? absint( $_POST['bron'] ) : 0;
		$bron_id = self::eigen_afbeelding( $post_id, $gevraagd );
		if ( ! $bron_id ) {
			wp_send_json_error(
				array(
					'error' => __( 'Dit product heeft nog geen hoofdfoto. Zet er eerst een foto bij; die gebruiken we als vertrekpunt.', 'wss-ai' ),
				)
			);
		}

		$beeld = self::beeld_klaar( $bron_id );
		if ( ! $beeld ) {
			wp_send_json_error( array( 'error' => __( 'De foto van dit product kon niet gelezen worden.', 'wss-ai' ) ) );
		}

		/* De extra opdracht onthouden we bij het product, zodat hij er de
		   volgende keer nog staat. Dit is onze eigen sleutel; er wordt niets
		   van het product zelf gewijzigd. */
		$extra = isset( $_POST['extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra'] ) ) : '';
		$extra = mb_substr( $extra, 0, 800 );
		$taak = isset( $_POST['taak'] ) ? sanitize_key( wp_unslash( $_POST['taak'] ) ) : 'vernieuwen';

		/* Alleen bij vernieuwen onthouden, en niet bij bulk. Daar gaat de tekst
		   over het product en blijft hij gelden. Bij een variant is het de plek
		   waar hij komt te staan, en die wil je de volgende keer juist anders
		   hebben; bij bulk geldt hij voor de hele serie en hoort hij niet bij
		   elk product afzonderlijk te blijven plakken. */
		if ( 'variant' !== $taak && empty( $_POST['bulk'] ) ) {
			if ( '' === $extra ) {
				delete_post_meta( $post_id, self::PROMPT_META );
			} else {
				update_post_meta( $post_id, self::PROMPT_META, $extra );
			}
		}

		$stijl = self::stijl();
		$recept = trim( self::recept() . "\n" . ( isset( $stijl['eigen'] ) ? (string) $stijl['eigen'] : '' ) );

		/* Een beeldmodel doet er tientallen seconden over. Sommige servers
		   kappen een verzoek na dertig seconden af; waar het mag zetten we die
		   grens hoger. Mag het niet, dan valt het verzoek alsnog om, maar dan
		   ligt het aan de hosting en niet aan een grens die wij zelf zetten. */
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$taak = isset( $_POST['taak'] ) ? sanitize_key( wp_unslash( $_POST['taak'] ) ) : 'vernieuwen';
		$doel = isset( $_POST['doel'] ) ? sanitize_key( wp_unslash( $_POST['doel'] ) ) : 'hoofd';

		if ( 'variant' === $taak && '' === trim( $extra ) ) {
			wp_send_json_error(
				array( 'error' => __( 'Vertel eerst waar je product moet komen te staan.', 'wss-ai' ) )
			);
		}

		$uit = WSS_AI_Koppeling::vraag(
			'/foto/genereer',
			array(
				'bron'      => $beeld,
				'taak'      => $taak,
				/* Voor de galerij vragen we om een ander standpunt: die foto komt
				   náást de bestaande te staan, dus nog een keer vrijwel hetzelfde
				   beeld voegt daar niets aan toe. */
				'doel'      => $doel,
				'motor'     => self::motor(),
				'stijl'     => $recept,
				'extra'     => $extra,
				'productId' => $post_id,
			),
			120
		);
		if ( is_wp_error( $uit ) ) {
			wp_send_json_error( array( 'error' => $uit->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'id'   => isset( $uit['id'] ) ? (string) $uit['id'] : '',
				'url'  => isset( $uit['url'] ) ? esc_url_raw( (string) $uit['url'] ) : '',
				'mime' => isset( $uit['mime'] ) ? sanitize_text_field( (string) $uit['mime'] ) : 'image/jpeg',
				/* Heeft de server een andere AI moeten gebruiken dan de klant
				   koos, dan hoort hij dat te lezen. Stilzwijgend afwijken van een
				   instelling is hetzelfde als die instelling negeren. */
				'uitgeweken' => isset( $uit['uitgeweken'] ) ? sanitize_text_field( (string) $uit['uitgeweken'] ) : '',
			)
		);
	}

	/**
	 * Een gemaakte foto bijschaven.
	 *
	 * Er gaat geen beeld mee: de foto staat al bij Webshopschool en wordt daar
	 * opgezocht met het nummer en de sleutel uit het adres. Zo hoeft er geen
	 * megabyte heen en weer voor het weghalen van een takje.
	 */
	public static function ajax_bijwerken() {
		check_ajax_referer( 'wss_ai_foto', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Je mag dit product niet bewerken.', 'wss-ai' ) ) );
		}

		$opdracht = isset( $_POST['opdracht'] ) ? sanitize_textarea_field( wp_unslash( $_POST['opdracht'] ) ) : '';
		if ( '' === trim( $opdracht ) ) {
			wp_send_json_error( array( 'error' => __( 'Vertel wat er anders moet.', 'wss-ai' ) ) );
		}

		$id  = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $id || 0 !== strpos( $url, WSS_AI_Koppeling::api() . '/foto/bestand/' ) ) {
			wp_send_json_error( array( 'error' => __( 'Die foto kennen we niet meer.', 'wss-ai' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$uit = WSS_AI_Koppeling::vraag(
			'/foto/' . rawurlencode( $id ) . '/bijwerken',
			array(
				'sleutel'  => self::sleutel_uit( $url ),
				'opdracht' => mb_substr( $opdracht, 0, 500 ),
			),
			120
		);
		if ( is_wp_error( $uit ) ) {
			wp_send_json_error( array( 'error' => $uit->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'id'   => isset( $uit['id'] ) ? (string) $uit['id'] : '',
				'url'  => isset( $uit['url'] ) ? esc_url_raw( (string) $uit['url'] ) : '',
				'mime' => isset( $uit['mime'] ) ? sanitize_text_field( (string) $uit['mime'] ) : 'image/jpeg',
			)
		);
	}

	/* ---------------- de foto op het product zetten ---------------- */

	public static function ajax_toepassen() {
		check_ajax_referer( 'wss_ai_foto', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Je mag dit product niet bewerken.', 'wss-ai' ) ) );
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'error' => __( 'Je mag geen bestanden uploaden.', 'wss-ai' ) ) );
		}

		$doel = isset( $_POST['doel'] ) ? sanitize_key( wp_unslash( $_POST['doel'] ) ) : 'hoofd';
		$url  = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		/* Alleen van ons eigen adres downloaden. Zonder deze controle zou een
		   verzoek met een ander adres deze server elk bestand van internet laten
		   binnenhalen, en dat is precies het gat dat je niet wilt. */
		if ( ! $url || 0 !== strpos( $url, WSS_AI_Koppeling::api() . '/foto/bestand/' ) ) {
			wp_send_json_error( array( 'error' => __( 'Dat adres klopt niet.', 'wss-ai' ) ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tijdelijk = download_url( $url, 60 );
		if ( is_wp_error( $tijdelijk ) ) {
			wp_send_json_error( array( 'error' => __( 'De foto kon niet opgehaald worden. Probeer het zo nog eens.', 'wss-ai' ) ) );
		}

		/* De extensie volgt het soort bestand dat we terugkrijgen. Een foto met een
		   doorzichtige achtergrond is een png; die .jpg noemen levert een bestand
		   op waarvan de naam liegt, en WordPress weigert hem of maakt de
		   doorzichtige delen zwart. */
		$mime = isset( $_POST['mime'] ) ? sanitize_text_field( wp_unslash( $_POST['mime'] ) ) : 'image/jpeg';
		$extensies = array( 'image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg' );
		$ext = isset( $extensies[ $mime ] ) ? $extensies[ $mime ] : 'jpg';

		$naam = sanitize_file_name( get_the_title( $post_id ) );
		$naam = ( $naam ? $naam : 'product' ) . '-' . gmdate( 'Ymd-His' ) . '.' . $ext;

		$id = media_handle_sideload(
			array( 'name' => $naam, 'tmp_name' => $tijdelijk ),
			$post_id,
			get_the_title( $post_id )
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tijdelijk );
			wp_send_json_error( array( 'error' => __( 'De foto kon niet in je mediabibliotheek gezet worden.', 'wss-ai' ) ) );
		}

		$huidig = get_post_meta( $post_id, '_product_image_gallery', true );
		$galerij = array_filter( array_map( 'absint', explode( ',', (string) $huidig ) ) );

		if ( 'galerij' === $doel ) {
			$galerij[] = (int) $id;
		} else {
			/* De oude hoofdfoto gaat naar de galerij in plaats van uit beeld te
			   verdwijnen. Hij staat nog wel in de mediabibliotheek, maar die
			   raakt bij een drukke webshop zo vol dat je hem daar niet
			   terugvindt, en dan ben je een goede foto kwijt door één klik. */
			$oude = (int) get_post_thumbnail_id( $post_id );
			if ( $oude && $oude !== (int) $id ) {
				$galerij[] = $oude;
			}
			set_post_thumbnail( $post_id, $id );
		}

		$galerij = array_values( array_unique( $galerij ) );
		update_post_meta( $post_id, '_product_image_gallery', implode( ',', $galerij ) );

		/* Laten weten dat hij gebruikt is. Puur om te kunnen zien hoeveel van het
		   gemaakte werk ook echt landt; mislukt het, dan is dat geen reden om de
		   klant een fout te tonen. */
		WSS_AI_Koppeling::vraag(
			'/foto/' . rawurlencode( isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '' ) . '/gebruikt',
			array( 'sleutel' => self::sleutel_uit( $url ) )
		);

		/* De hele galerijlijst terug, zodat het scherm precies kan gaan tonen wat
		   er nu is opgeslagen.

		   Dat is niet alleen netjes maar noodzakelijk: het productscherm houdt de
		   galerij bij in een verborgen veld, en slaat de klant daarna het product
		   op, dan wint dat veld. Zonder bijwerken zou onze net toegevoegde foto
		   bij de eerstvolgende klik op Bijwerken weer verdwijnen. */
		wp_send_json_success(
			array(
				'attachment' => (int) $id,
				'thumb'      => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'doel'       => $doel,
				'galerij'    => array_map( 'intval', $galerij ),
				'hoofd'      => (int) get_post_thumbnail_id( $post_id ),
			)
		);
	}

	private static function sleutel_uit( $url ) {
		$vraag = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! $vraag ) {
			return '';
		}
		parse_str( $vraag, $delen );
		return isset( $delen['k'] ) ? (string) $delen['k'] : '';
	}

	/* ---------------- de stijl laten beschrijven ---------------- */

	public static function ajax_stijl() {
		check_ajax_referer( 'wss_ai_foto', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => __( 'Je hebt hier geen toegang toe.', 'wss-ai' ) ) );
		}

		$media = isset( $_POST['media'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['media'] ) ) : array();
		$producten = isset( $_POST['producten'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['producten'] ) ) : array();

		/* Van een product pakken we de hoofdfoto: dat is de foto die de klant
		   bedoelt als hij zegt "zo moeten ze eruitzien". */
		$ids = $media;
		foreach ( $producten as $pid ) {
			$thumb = (int) get_post_thumbnail_id( $pid );
			if ( $thumb ) {
				$ids[] = $thumb;
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( count( $ids ) < 2 ) {
			wp_send_json_error(
				array(
					'error' => __( 'Kies er minstens twee met een foto erbij. Met één foto valt er geen gedeelde stijl uit te halen.', 'wss-ai' ),
				)
			);
		}

		$beelden = array();
		foreach ( array_slice( $ids, 0, 8 ) as $id ) {
			$b = self::beeld_klaar( $id );
			if ( $b ) {
				$beelden[] = $b;
			}
		}
		if ( count( $beelden ) < 2 ) {
			wp_send_json_error( array( 'error' => __( 'Die foto\'s konden niet gelezen worden.', 'wss-ai' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$uit = WSS_AI_Koppeling::vraag( '/foto/stijl', array( 'beelden' => $beelden ), 120 );
		if ( is_wp_error( $uit ) ) {
			wp_send_json_error( array( 'error' => $uit->get_error_message() ) );
		}

		$stijl = self::stijl();
		$stijl['naam'] = isset( $uit['naam'] ) ? sanitize_text_field( (string) $uit['naam'] ) : '';
		$stijl['beschrijving'] = isset( $uit['beschrijving'] ) ? sanitize_textarea_field( (string) $uit['beschrijving'] ) : '';
		$stijl['recept'] = isset( $uit['recept'] ) ? sanitize_textarea_field( (string) $uit['recept'] ) : '';
		$stijl['aantal'] = count( $beelden );
		$stijl['op'] = gmdate( 'c' );
		update_option( self::STIJL, $stijl );
		update_option( self::BRONNEN, array( 'media' => $media, 'producten' => $producten ) );

		wp_send_json_success(
			array(
				'naam'         => $stijl['naam'],
				'beschrijving' => $stijl['beschrijving'],
				'aantal'       => $stijl['aantal'],
			)
		);
	}

	/* ---------------- de kaart op de beheerpagina ---------------- */

	public static function kaart() {
		$stijl = self::stijl();
		$bronnen = self::bronnen();
		$motoren = WSS_AI_Koppeling::fotomotoren();
		?>
		<div class="wss-ai-kaart" id="wss-ai-fotos">
			<h2><?php esc_html_e( 'Foto\'s maken', 'wss-ai' ); ?></h2>
			<p class="wss-ai-mut">
				<?php esc_html_e( 'Bij een product staat een knop bij de hoofdfoto en bij de galerij. Daar kies je per keer wat er moet gebeuren: de foto vernieuwen, of de achtergrond weghalen. Hieronder staat wat voor al je foto\'s geldt.', 'wss-ai' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wss_ai_foto_instellingen">
				<?php wp_nonce_field( 'wss_ai_foto_instellingen' ); ?>

				<p>
					<label for="wss_ai_foto_motor"><strong><?php esc_html_e( 'Welke AI maakt je foto\'s?', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein">
						<?php esc_html_e( 'Ze doen alle vier hetzelfde werk, alleen net even anders. Twijfel je? Laat hem op onze keuze staan.', 'wss-ai' ); ?>
					</span><br>
					<?php if ( empty( $motoren ) ) : ?>
						<span class="wss-ai-onbekend"><?php esc_html_e( 'Niet opgehaald', 'wss-ai' ); ?></span>
						<span class="wss-ai-mut"><?php esc_html_e( 'De lijst kon niet bij Webshopschool opgehaald worden. Je kunt wel gewoon foto\'s maken; er wordt dan onze standaardkeuze gebruikt.', 'wss-ai' ); ?></span>
					<?php else : ?>
						<select id="wss_ai_foto_motor" name="motor">
							<?php foreach ( $motoren as $m ) : ?>
								<option value="<?php echo esc_attr( $m['key'] ); ?>" <?php selected( self::motor(), $m['key'] ); ?>>
									<?php
									echo esc_html( $m['label'] );
									if ( ! empty( $m['hint'] ) ) {
										echo esc_html( ' - ' . $m['hint'] );
									}
									/* Zeggen wanneer we iets NIET zelf vergeleken hebben. Een
									   keuzelijst waarin alles even zelfverzekerd staat, doet
									   alsof we van alle vier evenveel weten. */
									if ( empty( $m['gemeten'] ) ) {
										echo esc_html__( ' (nog niet door ons vergeleken)', 'wss-ai' );
									}
									?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</p>
				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Achtergrond weghalen staat hier niet tussen: daar wordt niets getekend maar geknipt, en daar is één gereedschap voor.', 'wss-ai' ); ?>
				</p>

				<hr>

				<p>
					<strong><?php esc_html_e( 'Jouw fotostijl', 'wss-ai' ); ?></strong><br>
					<span class="wss-ai-mut wss-ai-klein">
						<?php esc_html_e( 'Kies een paar foto\'s die eruitzien zoals jij het wilt hebben. Wij kijken wat ze gemeen hebben en schrijven dat op; die beschrijving gaat daarna bij elke nieuwe foto mee.', 'wss-ai' ); ?>
					</span>
				</p>

				<p>
					<button type="button" class="button wss-ai-kies-media"><?php esc_html_e( 'Kies uit je mediabibliotheek', 'wss-ai' ); ?></button>
					<span class="wss-ai-gekozen-media wss-ai-mut wss-ai-klein"></span>
				</p>

				<p>
					<label for="wss-ai-producten"><?php esc_html_e( 'Of neem de hoofdfoto van deze producten:', 'wss-ai' ); ?></label><br>
					<select id="wss-ai-producten" multiple="multiple" style="width:100%;max-width:520px"
						class="wc-product-search"
						data-placeholder="<?php esc_attr_e( 'Zoek een product', 'wss-ai' ); ?>"
						data-action="woocommerce_json_search_products">
						<?php
						foreach ( $bronnen['producten'] as $pid ) {
							$p = get_post( $pid );
							if ( $p ) {
								echo '<option value="' . esc_attr( $pid ) . '" selected>' . esc_html( $p->post_title ) . '</option>';
							}
						}
						?>
					</select>
				</p>

				<p>
					<button type="button" class="button wss-ai-beschrijf"><?php esc_html_e( 'Beschrijf mijn stijl', 'wss-ai' ); ?></button>
					<span class="wss-ai-stijl-melding wss-ai-mut wss-ai-klein"></span>
				</p>

				<p>
					<label for="wss_ai_beschrijving"><strong><?php esc_html_e( 'De beschrijving', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Je mag hem aanpassen of zelf schrijven.', 'wss-ai' ); ?></span>
				</p>
				<textarea id="wss_ai_beschrijving" name="beschrijving" rows="4" class="large-text"
					placeholder="<?php esc_attr_e( 'Bijvoorbeeld: rustige lichte achtergronden, zacht daglicht van opzij, het product groot in beeld.', 'wss-ai' ); ?>"><?php echo esc_textarea( isset( $stijl['beschrijving'] ) ? $stijl['beschrijving'] : '' ); ?></textarea>

				<p>
					<label for="wss_ai_eigen"><strong><?php esc_html_e( 'Altijd meegeven', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Optioneel. Iets wat bij elke foto moet gelden, bijvoorbeeld: nooit mensen in beeld.', 'wss-ai' ); ?></span>
				</p>
				<textarea id="wss_ai_eigen" name="eigen" rows="2" class="large-text"><?php echo esc_textarea( isset( $stijl['eigen'] ) ? $stijl['eigen'] : '' ); ?></textarea>

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'wss-ai' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
