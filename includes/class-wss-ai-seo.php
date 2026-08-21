<?php
/**
 * "Genereer met AI" op het productscherm.
 *
 * Eén blok naast de productgegevens met drie knoppen: de productomschrijving, de
 * SEO-titel en de SEO-omschrijving.
 *
 * WAAROM EEN EIGEN BLOK EN GEEN KNOP IN YOAST ZELF
 * Yoast en Rank Math bouwen hun velden met React. Een knop daar tussenwurmen
 * werkt tot hun volgende update, en dan is hij weg of zit hij op de verkeerde
 * plek. Een eigen blok blijft staan. Het INVULLEN gaat wel gewoon naar hun veld;
 * lukt dat niet, dan zeggen we dat en kun je de tekst kopiëren -- nooit stil
 * mislukken terwijl de knop groen kleurt.
 *
 * WAT ER MEEGAAT NAAR DE SERVER
 * Alles wat er van het product is OPGESLAGEN: naam, categorieën, tags,
 * eigenschappen, merk, varianten en de bestaande teksten. Plus wat er op dit
 * moment in de omschrijving staat, ook al is dat nog niet opgeslagen -- juist die
 * ruwe aantekeningen zijn vaak het beste voer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_SEO {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'blok' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'scripts' ) );
		add_action( 'wp_ajax_wss_ai_tekst', array( __CLASS__, 'ajax' ) );
	}

	public static function blok() {
		if ( ! post_type_exists( 'product' ) || ! WSS_AI_Koppeling::module_aan( 'teksten' ) ) {
			return;
		}
		add_meta_box(
			'wss-ai-teksten',
			__( 'WSS Tools - teksten schrijven', 'wss-ai' ),
			array( __CLASS__, 'toon' ),
			'product',
			'normal',
			'high'
		);
	}

	public static function toon( $post ) {
		$actief = WSS_AI_Koppeling::is_actief();
		?>
		<div class="wss-ai-seo" data-post="<?php echo esc_attr( $post->ID ); ?>">

			<?php if ( ! $actief ) : ?>
				<div class="notice notice-warning inline" style="margin:0 0 12px">
					<p><?php echo esc_html( WSS_AI_Koppeling::uitleg() ? WSS_AI_Koppeling::uitleg() : __( 'Je webshop is nog niet gekoppeld.', 'wss-ai' ) ); ?></p>
				</div>
			<?php endif; ?>

			<p class="wss-ai-mut" style="margin-top:0">
				<?php
				esc_html_e(
					'Hoe meer er van je product is ingevuld en opgeslagen, hoe beter de tekst wordt. Denk aan categorieën, merk, materiaal en maten.',
					'wss-ai'
				);
				?>
				<br>
				<?php
				esc_html_e(
					'Weet je het even niet? Tik dan gewoon ruw in het omschrijvingsveld wat je erover kunt vertellen - steekwoorden, halve zinnen, typefouten, het maakt niet uit. Dat is voer voor de AI; hij maakt er nette tekst van.',
					'wss-ai'
				);
				?>
			</p>

			<p class="wss-ai-knoppen">
				<button type="button" class="button button-primary" data-wss-ai="omschrijving" <?php disabled( ! $actief ); ?>>
					<?php esc_html_e( 'Genereer productomschrijving', 'wss-ai' ); ?>
				</button>
				<label class="wss-ai-lengte">
					<?php esc_html_e( 'lengte', 'wss-ai' ); ?>
					<select class="wss-ai-lengte-keuze" <?php disabled( ! $actief ); ?>>
						<?php foreach ( WSS_AI_Instellingen::lengtes() as $waarde => $label ) : ?>
							<option value="<?php echo esc_attr( $waarde ); ?>" <?php selected( WSS_AI_Instellingen::lengte(), $waarde ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="button" class="button" data-wss-ai="seo-titel" <?php disabled( ! $actief ); ?>>
					<?php esc_html_e( 'Genereer SEO-titel', 'wss-ai' ); ?>
				</button>
				<button type="button" class="button" data-wss-ai="seo-omschrijving" <?php disabled( ! $actief ); ?>>
					<?php esc_html_e( 'Genereer SEO-omschrijving', 'wss-ai' ); ?>
				</button>
				<span class="wss-ai-melding" aria-live="polite"></span>
			</p>

			<?php
			/* Wat er nog aan tegoed over is. Staat er geen tegoed van kracht,
			   dan komt hier niets en merkt niemand dat dit bestaat. */
			$wss_ai_tegoed = WSS_AI_Budget::regel();
			if ( '' !== $wss_ai_tegoed ) {
				echo '<p class="wss-ai-klein">' . wp_kses_post( $wss_ai_tegoed ) . '</p>';
			}
			?>

			<div class="wss-ai-uitkomst" hidden></div>

			<p class="wss-ai-mut wss-ai-klein">
				<?php esc_html_e( 'Er wordt niets gepubliceerd. De tekst komt in het veld te staan; jij bepaalt of je opslaat.', 'wss-ai' ); ?>
				<?php if ( WSS_AI_Instellingen::stijl() ) : ?>
					<br><?php esc_html_e( 'Er wordt geschreven in de stijl die je bij WSS Tools hebt ingesteld.', 'wss-ai' ); ?>
				<?php else : ?>
					<br>
					<?php
					printf(
						/* translators: %s wordt de link naar de instellingenpagina. */
						esc_html__( 'Wil je dat er in jouw eigen stijl geschreven wordt? Dat stel je in bij %s.', 'wss-ai' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=wss-ai' ) ) . '">' . esc_html__( 'WSS Tools', 'wss-ai' ) . '</a>'
					);
					?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	public static function scripts( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( ! $post || 'product' !== $post->post_type ) {
			return;
		}

		wp_enqueue_script(
			'wss-ai-seo',
			plugins_url( 'assets/seo.js', WSS_AI_BESTAND ),
			array( 'jquery' ),
			WSS_AI_VERSIE,
			true
		);
		wp_localize_script(
			'wss-ai-seo',
			'wssAi',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wss_ai_tekst' ),
				'taal'  => array(
					'bezig'     => __( 'Bezig met schrijven…', 'wss-ai' ),
					'klaar'     => __( 'Klaar. Kijk hem even na en sla op als je tevreden bent.', 'wss-ai' ),
					'mager'     => __( 'Er stond weinig informatie bij dit product, dus de tekst is kort gebleven. Vul meer in of tik ruwe aantekeningen in de omschrijving voor een beter resultaat.', 'wss-ai' ),
					'nietGevuld' => __( 'Het veld kon niet automatisch gevuld worden. Kopieer de tekst hieronder.', 'wss-ai' ),
					'kopieer'   => __( 'Kopieer', 'wss-ai' ),
					'gekopieerd' => __( 'Gekopieerd', 'wss-ai' ),
					'mislukt'   => __( 'Dit lukte niet.', 'wss-ai' ),
				),
			)
		);
	}

	/**
	 * De productgegevens verzamelen en de tekst opvragen.
	 */
	public static function ajax() {
		check_ajax_referer( 'wss_ai_tekst', 'nonce' );

		$post_id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'error' => __( 'Je mag dit product niet bewerken.', 'wss-ai' ) ) );
		}

		$soort = isset( $_POST['soort'] ) ? sanitize_key( wp_unslash( $_POST['soort'] ) ) : '';
		if ( ! in_array( $soort, array( 'omschrijving', 'seo-titel', 'seo-omschrijving' ), true ) ) {
			wp_send_json_error( array( 'error' => __( 'Onbekend soort tekst.', 'wss-ai' ) ) );
		}

		/* Is er nog tegoed? De echte weigering staat op de server, maar een
		   aanvraag die daar toch afketst hoeft niet eerst dertig seconden te
		   duren. Zie class-wss-ai-budget.php. */
		$tegoed = WSS_AI_Budget::controleer();
		if ( is_wp_error( $tegoed ) ) {
			wp_send_json_error( array( 'error' => $tegoed->get_error_message() ) );
		}

		/* De tekst die nu in het scherm staat en misschien nog niet is opgeslagen.
		   wp_kses_post haalt scripts eruit; de rest strippen we tot platte tekst
		   omdat het model niets met opmaak doet. */
		$ruw = isset( $_POST['ruw'] ) ? wp_strip_all_tags( wp_kses_post( wp_unslash( $_POST['ruw'] ) ) ) : '';

		$product = self::gegevens( $post_id, $ruw );
		if ( is_wp_error( $product ) ) {
			wp_send_json_error( array( 'error' => $product->get_error_message() ) );
		}

		/* De lengte kiest de winkelier per product; valt hij buiten de lijst, dan
		   pakken we zijn eigen standaard. De schrijfstijl komt NIET uit het scherm
		   maar uit de instellingen van deze site: die hoort niet uit een formulier
		   te komen dat iemand anders kan meesturen. */
		$lengte = isset( $_POST['lengte'] ) ? sanitize_key( wp_unslash( $_POST['lengte'] ) ) : '';
		if ( ! array_key_exists( $lengte, WSS_AI_Instellingen::lengtes() ) ) {
			$lengte = WSS_AI_Instellingen::lengte();
		}

		$uit = WSS_AI_Koppeling::vraag(
			'/seo',
			array(
				'soort'   => $soort,
				'lengte'  => $lengte,
				'stijl'   => WSS_AI_Instellingen::stijl(),
				'product' => $product,
			)
		);
		if ( is_wp_error( $uit ) ) {
			wp_send_json_error( array( 'error' => $uit->get_error_message() ) );
		}

		/* De HTML komt van onze eigen server en is daar al ontdaan van tekens die
		   iets betekenen. Toch gaat hij hier nog een keer door wp_kses_post: wat
		   in de editor van een klant belandt hoort niet op vertrouwen te rusten. */
		$html = isset( $uit['html'] ) ? wp_kses_post( (string) $uit['html'] ) : '';

		wp_send_json_success(
			array(
				'tekst' => isset( $uit['tekst'] ) ? (string) $uit['tekst'] : '',
				'html'  => $html,
				'mager' => ! empty( $uit['mager'] ),
			)
		);
	}

	/** Alles wat er van dit product bekend is. */
	private static function gegevens( $post_id, $ruw ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'geen-woo', __( 'WooCommerce staat niet aan op deze website.', 'wss-ai' ) );
		}
		$p = wc_get_product( $post_id );
		if ( ! $p ) {
			return new WP_Error( 'geen-product', __( 'Dit product kon niet gelezen worden. Sla het eerst een keer op.', 'wss-ai' ) );
		}

		$termen = function ( $taxonomie ) use ( $post_id ) {
			$t = wp_get_post_terms( $post_id, $taxonomie, array( 'fields' => 'names' ) );
			return is_wp_error( $t ) ? array() : array_values( $t );
		};

		/* Eigenschappen zijn waar de bruikbare details zitten: materiaal, maat,
		   kleur. Zonder deze wordt een tekst al snel algemeen. */
		$eigenschappen = array();
		foreach ( $p->get_attributes() as $attr ) {
			if ( ! is_object( $attr ) ) {
				continue;
			}
			$naam = wc_attribute_label( $attr->get_name() );
			$waarden = $attr->is_taxonomy()
				? wp_get_post_terms( $post_id, $attr->get_name(), array( 'fields' => 'names' ) )
				: $attr->get_options();
			if ( is_wp_error( $waarden ) ) {
				continue;
			}
			if ( $naam && ! empty( $waarden ) ) {
				$eigenschappen[] = array( 'naam' => $naam, 'waarden' => array_values( $waarden ) );
			}
		}

		$varianten = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( array_slice( $p->get_children(), 0, 20 ) as $vid ) {
				$v = wc_get_product( $vid );
				if ( $v ) {
					$varianten[] = $v->get_name();
				}
			}
		}

		/* Het merk kan uit drie hoeken komen, afhankelijk van welke plugin de
		   klant gebruikt. Pak de eerste die iets oplevert. */
		$merk = '';
		foreach ( array( 'product_brand', 'pwb-brand', 'pa_merk' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$m = $termen( $tax );
				if ( ! empty( $m ) ) {
					$merk = implode( ', ', $m );
					break;
				}
			}
		}

		return array(
			'winkel'          => get_bloginfo( 'name' ),
			'winkelOver'      => get_bloginfo( 'description' ),
			'naam'            => $p->get_name(),
			'soort'           => $p->get_type(),
			'sku'             => $p->get_sku(),
			'merk'            => $merk,
			'categorieen'     => $termen( 'product_cat' ),
			'tags'            => $termen( 'product_tag' ),
			'eigenschappen'   => $eigenschappen,
			'varianten'       => $varianten,
			'kort'            => wp_strip_all_tags( $p->get_short_description() ),
			'lang'            => wp_strip_all_tags( $p->get_description() ),
			'ruw'             => $ruw,
			'zoekwoord'       => self::seo_veld( $post_id, 'zoekwoord' ),
			'seoTitel'        => self::seo_veld( $post_id, 'titel' ),
			'seoOmschrijving' => self::seo_veld( $post_id, 'omschrijving' ),
		);
	}

	/** Bestaande SEO-velden, van Yoast of van Rank Math -- wat er ook staat. */
	private static function seo_veld( $post_id, $welk ) {
		$sleutels = array(
			'titel'        => array( '_yoast_wpseo_title', 'rank_math_title' ),
			'omschrijving' => array( '_yoast_wpseo_metadesc', 'rank_math_description' ),
			'zoekwoord'    => array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ),
		);
		foreach ( $sleutels[ $welk ] as $sleutel ) {
			$w = get_post_meta( $post_id, $sleutel, true );
			if ( is_string( $w ) && '' !== trim( $w ) ) {
				return $w;
			}
		}
		return '';
	}
}
