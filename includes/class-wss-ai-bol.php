<?php
/**
 * bol.com: WELKE artikelen er heen mogen, en voor welke prijs.
 *
 * WAT DIT WEL DOET
 * Dit is de bedieningskant. Per product (en per variatie) een vinkje "Aanbieden
 * op bol.com" en een eigen bol-prijs, op het productscherm, in het
 * productenoverzicht, in snel/bulk bewerken en in het Voorraadbeheer-scherm.
 * Daarnaast een controlelijst die laat zien wat er met de gestelde regels
 * daadwerkelijk overblijft, en WAAROM een artikel afvalt.
 *
 * WAT DIT BEWUST NIET DOET
 * Praten met bol. Zie de regel bovenaan wss-ai.php: wat rekent, met een
 * koppeling praat of geld kost hoort op de server van Webshopschool en niet in
 * de PHP van een klant. Een aanbieding plaatsen, een prijs doorzetten of een
 * bol-order inlezen gebeurt daar. Deze module houdt alleen de SELECTIE bij en
 * roept bij elke wijziging `wss_bol_selectie_gewijzigd` af, zodat de serverkant
 * daar later op kan aanhaken zonder dat hier nog iets veranderen moet.
 *
 * DE REGELS, EN WAAROM ZE HARD IN DE CODE ZITTEN
 * Een artikel gaat alleen naar bol als het vinkje aanstaat, er een bol-prijs is
 * ingevuld, er een barcode (GTIN/EAN) op staat en het niet op de uitsluitlijst
 * staat. Die barcode is geen smaakkwestie: zonder barcode kan bol het artikel
 * niet aanmaken. Dat vooraf tegenhouden met een melding is beter dan een
 * aanbieding die bij bol stil afketst, want dat merkt niemand.
 *
 * Deze module staat UIT tenzij Webshopschool hem voor een shop aanzet. Zie de
 * OPTIN-lijst in class-wss-ai-koppeling.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Bol {

	const MODULE      = 'bol';
	const META_ACTIEF = '_wss_bol_actief';
	const META_PRIJS  = '_wss_bol_prijs';
	const OPTIE_UIT   = 'wss_bol_uitgesloten';
	const SLUG        = 'wss-ai-bol';

	/** Draait het? Gebruikt door het voorraadoverzicht om de kolom te tonen. */
	private static $aan = null;

	/** De haaknaam van onze eigen pagina, voor de opmaak. */
	private static $scherm = '';

	public static function init() {
		/* Na WooCommerce, net als het voorraadbeheer: zonder producten valt er
		   niets aan te bieden. */
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 25 );
	}

	/**
	 * Staat deze module aan voor deze webshop?
	 *
	 * Het antwoord wordt één keer per verzoek bepaald: module_aan() leest opties
	 * en dat gebeurt anders bij elke tabelrij opnieuw.
	 */
	public static function aan() {
		if ( null !== self::$aan ) {
			return self::$aan;
		}
		self::$aan = class_exists( 'WooCommerce' ) && WSS_AI_Koppeling::module_aan( self::MODULE );
		return self::$aan;
	}

	public static function laden() {
		if ( ! self::aan() ) {
			return;
		}

		/* Het productscherm. */
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'velden_product' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'opslaan_product' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'velden_variatie' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'opslaan_variatie' ), 10, 2 );

		/* Het productenoverzicht: kolom, snel bewerken en bulk bewerken. */
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'kolom' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'kolom_inhoud' ), 20, 2 );
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'snel_bewerken' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( __CLASS__, 'bulk_bewerken' ), 10, 2 );
		add_action( 'woocommerce_product_quick_edit_save', array( __CLASS__, 'snel_opslaan' ) );
		add_action( 'woocommerce_product_bulk_edit_save', array( __CLASS__, 'bulk_opslaan' ) );
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'script_overzicht' ) );

		/* De controlelijst, en de melding als er iets is aangevinkt dat niet kan.
		   Prioriteit 20 op het menu: het hoofdmenu van WSS Tools moet er al staan,
		   anders hangt deze pagina onder een ouder die nog niet bestaat. */
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'stijl' ) );
		add_action( 'admin_post_wss_bol_uitsluiten', array( __CLASS__, 'uitsluitlijst_opslaan' ) );
		add_action( 'admin_notices', array( __CLASS__, 'melding' ) );
	}

	/* =====================================================================
	 * De regels
	 * ===================================================================== */

	/**
	 * Staat het vinkje aan voor dit artikel?
	 *
	 * Een variatie kent drie standen: leeg betekent "volg het hoofdproduct", en
	 * met 'yes' of 'no' zet je er eentje apart aan of uit. Dat is nodig omdat je
	 * meestal het hele product op bol wilt, maar niet altijd elke maat.
	 */
	public static function actief( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}

		$eigen = self::stand( $product->get_id() );

		if ( 'yes' === $eigen ) {
			return true;
		}
		if ( 'no' === $eigen ) {
			return false;
		}

		if ( $product->is_type( 'variation' ) ) {
			$ouder = wc_get_product( $product->get_parent_id() );
			return $ouder ? self::actief( $ouder ) : false;
		}

		return false;
	}

	/** De ruwe waarde van het vinkje: '', 'yes' of 'no'. */
	public static function stand( $id ) {
		$waarde = (string) get_post_meta( $id, self::META_ACTIEF, true );
		return in_array( $waarde, array( 'yes', 'no' ), true ) ? $waarde : '';
	}

	/**
	 * De bol-prijs, met terugval op het hoofdproduct.
	 *
	 * Zo hoeft er bij een variabel product maar één prijs ingevuld te worden,
	 * terwijl een afwijkende maat er alsnog een eigen prijs kan krijgen.
	 */
	public static function prijs( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$eigen = (string) get_post_meta( $product->get_id(), self::META_PRIJS, true );
		if ( '' !== $eigen ) {
			return $eigen;
		}

		if ( $product->is_type( 'variation' ) ) {
			$ouder = wc_get_product( $product->get_parent_id() );
			if ( $ouder ) {
				return (string) get_post_meta( $ouder->get_id(), self::META_PRIJS, true );
			}
		}

		return '';
	}

	/**
	 * De barcode, met terugval op het hoofdproduct.
	 *
	 * Dit is de NATIVE Global Unique ID van WooCommerce (`_global_unique_id`) en
	 * met opzet niet `_alg_ean` van een oude plugin: daar staan bij deze shops
	 * plaatshouders in, en dezelfde waarde bij tientallen producten. Een
	 * barcode die bij 29 artikelen hetzelfde is, is geen barcode.
	 */
	public static function barcode( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$code = trim( (string) $product->get_global_unique_id() );
		if ( '' !== $code ) {
			return $code;
		}

		if ( $product->is_type( 'variation' ) ) {
			$ouder = wc_get_product( $product->get_parent_id() );
			if ( $ouder ) {
				return trim( (string) $ouder->get_global_unique_id() );
			}
		}

		return '';
	}

	/** Product-ids die nooit naar bol mogen, wat er ook aangevinkt staat. */
	public static function uitsluitlijst() {
		$lijst = get_option( self::OPTIE_UIT, array() );
		return is_array( $lijst ) ? array_map( 'absint', $lijst ) : array();
	}

	public static function uitgesloten( $id ) {
		return in_array( absint( $id ), self::uitsluitlijst(), true );
	}

	/**
	 * Mag dit artikel naar bol, en zo niet: waarom niet?
	 *
	 * Geeft altijd een reden terug, ook bij een ja. Die reden gaat één op één
	 * het scherm in; een lege uitkomst laat iemand zoeken naar iets wat wij al
	 * weten.
	 *
	 * @return array array( bool $ok, string $reden )
	 */
	public static function geschikt( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return array( false, __( 'Onbekend artikel', 'wss-ai' ) );
		}

		$ouder = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : null;

		if ( self::uitgesloten( $product->get_id() ) || ( $ouder && self::uitgesloten( $ouder->get_id() ) ) ) {
			return array( false, __( 'Staat op de uitsluitlijst', 'wss-ai' ) );
		}
		if ( ! self::actief( $product ) ) {
			return array( false, __( 'Niet aangevinkt voor bol', 'wss-ai' ) );
		}

		$status = $ouder ? $ouder->get_status() : $product->get_status();
		if ( 'publish' !== $status ) {
			return array( false, __( 'Staat niet gepubliceerd', 'wss-ai' ) );
		}
		if ( '' === self::barcode( $product ) ) {
			return array( false, __( 'Geen barcode (GTIN/EAN)', 'wss-ai' ) );
		}
		if ( '' === (string) self::prijs( $product ) ) {
			return array( false, __( 'Geen bol-prijs ingevuld', 'wss-ai' ) );
		}

		return array( true, __( 'Klaar voor bol', 'wss-ai' ) );
	}

	/* =====================================================================
	 * Het productscherm
	 * ===================================================================== */

	public static function velden_product() {
		global $post;

		$product = $post ? wc_get_product( $post->ID ) : null;
		if ( ! $product ) {
			return;
		}

		echo '<div class="options_group">';

		/* Hieraan ziet opslaan_product() dat onze velden echt zijn meegestuurd.
		   Zonder dat teken zou elke andere opslagronde die aan deze haak hangt
		   (een import, een koppeling) het vinkje uitzetten omdat het niet in de
		   POST stond, en dan gaat een hele catalogus stil van bol af. */
		echo '<input type="hidden" name="wss_bol_formulier" value="1" />';

		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_ACTIEF,
				'label'       => __( 'Aanbieden op bol.com', 'wss-ai' ),
				'description' => __( 'Aan betekent: dit artikel mag op bol.com verkocht worden.', 'wss-ai' ),
				'value'       => 'yes' === self::stand( $product->get_id() ) ? 'yes' : 'no',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'          => self::META_PRIJS,
				'label'       => __( 'Bol-prijs', 'wss-ai' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'De prijs waarvoor dit artikel op bol.com komt te staan. Bij een variabel product geldt deze prijs voor alle variaties; per variatie kun je er een andere invullen.', 'wss-ai' ),
				'value'       => (string) get_post_meta( $product->get_id(), self::META_PRIJS, true ),
			)
		);

		if ( ! $product->is_type( 'variable' ) && '' === self::barcode( $product ) ) {
			echo '<p class="form-field"><span style="color:#8a6100">';
			echo esc_html__( 'Let op: dit artikel heeft geen barcode (GTIN/EAN). Zonder barcode kan bol.com het niet aanmaken, dus het gaat niet mee zolang dat veld leeg is.', 'wss-ai' );
			echo '</span></p>';
		}

		echo '</div>';
	}

	public static function opslaan_product( $post_id ) {
		if ( ! isset( $_POST['wss_bol_formulier'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		/* WooCommerce heeft de nonce van het productscherm hiervoor al
		   nagekeken; dit loopt aan diens eigen opslaghaak. */
		$actief = isset( $_POST[ self::META_ACTIEF ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_ACTIEF, $actief );

		$prijs = isset( $_POST[ self::META_PRIJS ] )
			? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ self::META_PRIJS ] ) ) )
			: '';
		update_post_meta( $post_id, self::META_PRIJS, $prijs );

		self::na_wijziging( $post_id, $actief );
	}

	public static function velden_variatie( $loop, $data, $variation ) {
		$id = $variation->ID;

		woocommerce_wp_select(
			array(
				'id'            => 'wss_bol_actief_' . $loop,
				'name'          => 'wss_bol_actief[' . $loop . ']',
				'label'         => __( 'Op bol.com', 'wss-ai' ),
				'value'         => self::stand( $id ),
				'wrapper_class' => 'form-row form-row-first',
				'options'       => array(
					''    => __( 'Volg het product', 'wss-ai' ),
					'yes' => __( 'Ja, aanbieden', 'wss-ai' ),
					'no'  => __( 'Nee, niet aanbieden', 'wss-ai' ),
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => 'wss_bol_prijs_' . $loop,
				'name'          => 'wss_bol_prijs[' . $loop . ']',
				'label'         => __( 'Bol-prijs', 'wss-ai' ) . ' (' . get_woocommerce_currency_symbol() . ')',
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-last',
				'placeholder'   => __( 'Prijs van het product', 'wss-ai' ),
				'value'         => (string) get_post_meta( $id, self::META_PRIJS, true ),
			)
		);
	}

	public static function opslaan_variatie( $variation_id, $i ) {
		/* Alleen als onze velden echt zijn meegestuurd. Zie opslaan_product(). */
		if ( ! isset( $_POST['wss_bol_actief'] ) && ! isset( $_POST['wss_bol_prijs'] ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $variation_id ) ) {
			return;
		}

		$standen = isset( $_POST['wss_bol_actief'] ) ? (array) wp_unslash( $_POST['wss_bol_actief'] ) : array();
		$stand   = isset( $standen[ $i ] ) ? sanitize_text_field( $standen[ $i ] ) : '';
		if ( ! in_array( $stand, array( 'yes', 'no' ), true ) ) {
			$stand = '';
		}
		update_post_meta( $variation_id, self::META_ACTIEF, $stand );

		$prijzen = isset( $_POST['wss_bol_prijs'] ) ? (array) wp_unslash( $_POST['wss_bol_prijs'] ) : array();
		$prijs   = isset( $prijzen[ $i ] ) ? wc_format_decimal( sanitize_text_field( $prijzen[ $i ] ) ) : '';
		update_post_meta( $variation_id, self::META_PRIJS, $prijs );

		self::na_wijziging( $variation_id, $stand );
	}

	/* =====================================================================
	 * Het productenoverzicht
	 * ===================================================================== */

	public static function kolom( $kolommen ) {
		$nieuw = array();

		foreach ( $kolommen as $sleutel => $naam ) {
			$nieuw[ $sleutel ] = $naam;
			if ( 'price' === $sleutel ) {
				$nieuw['wss_bol'] = __( 'bol.com', 'wss-ai' );
			}
		}

		/* Heeft deze shop geen prijskolom (dat kan, een thema of plugin haalt
		   hem weg), dan komt onze kolom gewoon achteraan in plaats van nergens. */
		if ( ! isset( $nieuw['wss_bol'] ) ) {
			$nieuw['wss_bol'] = __( 'bol.com', 'wss-ai' );
		}

		return $nieuw;
	}

	public static function kolom_inhoud( $kolom, $post_id ) {
		if ( 'wss_bol' !== $kolom ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			echo '&ndash;';
			return;
		}

		$stand = self::stand( $post_id );
		$prijs = (string) get_post_meta( $post_id, self::META_PRIJS, true );

		if ( 'yes' !== $stand ) {
			echo '<span style="color:#646970">&ndash;</span>';
		} elseif ( $product->is_type( 'variable' ) ) {
			/* Bij een variabel product zegt de regel in dit overzicht alleen dat
			   het product meedoet; of elke maat er echt heen kan hangt van de
			   barcode per variatie af, en dat staat op de controlelijst. */
			echo '<span style="color:#00701a">&#10003;</span> ';
			echo esc_html( self::telling_variaties( $product ) );
		} else {
			list( $ok, $reden ) = self::geschikt( $product );
			if ( $ok ) {
				echo '<span style="color:#00701a">&#10003;</span> ';
				echo wp_kses_post( wc_price( (float) self::prijs( $product ) ) );
			} else {
				echo '<span style="color:#8a6100" title="' . esc_attr( $reden ) . '">&#9888; ';
				echo esc_html( $reden ) . '</span>';
			}
		}

		/* De waarden voor snel bewerken. WordPress vult daar alleen zijn eigen
		   velden; onze velden worden door het script hieronder uit deze regel
		   gelezen. Dezelfde aanpak als WooCommerce zelf gebruikt. */
		echo '<div class="hidden" id="wss_bol_inline_' . absint( $post_id ) . '"'
			. ' data-actief="' . esc_attr( $stand ) . '"'
			. ' data-prijs="' . esc_attr( $prijs ) . '"></div>';
	}

	/** Hoeveel variaties van dit product er klaarstaan voor bol. */
	private static function telling_variaties( $product ) {
		$klaar  = 0;
		$totaal = 0;

		foreach ( $product->get_children() as $kind_id ) {
			$kind = wc_get_product( $kind_id );
			if ( ! $kind ) {
				continue;
			}
			$totaal++;
			list( $ok ) = self::geschikt( $kind );
			if ( $ok ) {
				$klaar++;
			}
		}

		/* translators: 1: aantal variaties dat naar bol kan, 2: totaal aantal variaties. */
		return sprintf( __( '%1$d van %2$d variaties', 'wss-ai' ), $klaar, $totaal );
	}

	public static function snel_bewerken( $kolom, $post_type ) {
		if ( 'wss_bol' !== $kolom || 'product' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'bol.com', 'wss-ai' ); ?></span>
					<select name="wss_bol_actief">
						<option value="no"><?php esc_html_e( 'Niet aanbieden', 'wss-ai' ); ?></option>
						<option value="yes"><?php esc_html_e( 'Aanbieden', 'wss-ai' ); ?></option>
					</select>
				</label>
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Bol-prijs', 'wss-ai' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="wss_bol_prijs" class="text wc_input_price" value="" />
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	public static function bulk_bewerken( $kolom, $post_type ) {
		if ( 'wss_bol' !== $kolom || 'product' !== $post_type ) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-col">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'bol.com', 'wss-ai' ); ?></span>
					<select name="wss_bol_actief_bulk">
						<option value="">&mdash; <?php esc_html_e( 'Niet wijzigen', 'wss-ai' ); ?> &mdash;</option>
						<option value="yes"><?php esc_html_e( 'Aanbieden op bol.com', 'wss-ai' ); ?></option>
						<option value="no"><?php esc_html_e( 'Niet aanbieden op bol.com', 'wss-ai' ); ?></option>
					</select>
				</label>
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Bol-prijs', 'wss-ai' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="wss_bol_prijs_bulk" class="text wc_input_price" value=""
							placeholder="<?php esc_attr_e( 'Leeg laten = niet wijzigen', 'wss-ai' ); ?>" />
					</span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	public static function snel_opslaan( $product ) {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		/* Loopt aan de opslaghaak van WooCommerce; die heeft de nonce van
		   snel bewerken al nagekeken. */
		if ( isset( $_REQUEST['wss_bol_actief'] ) ) {
			$stand = 'yes' === sanitize_text_field( wp_unslash( $_REQUEST['wss_bol_actief'] ) ) ? 'yes' : 'no';
			update_post_meta( $product->get_id(), self::META_ACTIEF, $stand );
			self::na_wijziging( $product->get_id(), $stand );
		}

		if ( isset( $_REQUEST['wss_bol_prijs'] ) && '' !== trim( (string) $_REQUEST['wss_bol_prijs'] ) ) {
			update_post_meta(
				$product->get_id(),
				self::META_PRIJS,
				wc_format_decimal( sanitize_text_field( wp_unslash( $_REQUEST['wss_bol_prijs'] ) ) )
			);
		}
	}

	public static function bulk_opslaan( $product ) {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		$stand = isset( $_REQUEST['wss_bol_actief_bulk'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wss_bol_actief_bulk'] ) ) : '';
		if ( in_array( $stand, array( 'yes', 'no' ), true ) ) {
			update_post_meta( $product->get_id(), self::META_ACTIEF, $stand );
			self::na_wijziging( $product->get_id(), $stand );
		}

		/* Een leeg prijsveld betekent "niet wijzigen" en niet "maak leeg". Dat is
		   met opzet: bij bulk bewerken staat het veld altijd leeg, en de andere
		   lezing zou met één klik de prijzen van honderd artikelen wissen. */
		if ( isset( $_REQUEST['wss_bol_prijs_bulk'] ) && '' !== trim( (string) $_REQUEST['wss_bol_prijs_bulk'] ) ) {
			update_post_meta(
				$product->get_id(),
				self::META_PRIJS,
				wc_format_decimal( sanitize_text_field( wp_unslash( $_REQUEST['wss_bol_prijs_bulk'] ) ) )
			);
		}
	}

	/**
	 * Snel bewerken vullen met wat er nu op het product staat.
	 *
	 * Zonder dit opent snel bewerken met een leeg vinkje en een leeg prijsveld,
	 * en zet je met opslaan ongemerkt uit wat aanstond.
	 */
	public static function script_overzicht() {
		$scherm = get_current_screen();
		if ( ! $scherm || 'edit-product' !== $scherm->id ) {
			return;
		}
		?>
		<script>
		( function () {
			document.addEventListener( 'click', function ( e ) {
				var knop = e.target && e.target.closest ? e.target.closest( '.editinline' ) : null;
				if ( ! knop ) {
					return;
				}
				var rij = knop.closest( 'tr' );
				if ( ! rij || ! rij.id ) {
					return;
				}
				var id = rij.id.replace( 'post-', '' );
				var bron = document.getElementById( 'wss_bol_inline_' + id );
				if ( ! bron ) {
					return;
				}
				/* Het formulier van snel bewerken wordt pas na deze klik
				   neergezet, vandaar de korte vertraging. */
				setTimeout( function () {
					var vak = document.getElementById( 'edit-' + id );
					if ( ! vak ) {
						return;
					}
					var keuze = vak.querySelector( '[name="wss_bol_actief"]' );
					var prijs = vak.querySelector( '[name="wss_bol_prijs"]' );
					if ( keuze ) {
						keuze.value = 'yes' === bron.getAttribute( 'data-actief' ) ? 'yes' : 'no';
					}
					if ( prijs ) {
						prijs.value = bron.getAttribute( 'data-prijs' ) || '';
					}
				}, 50 );
			} );
		} )();
		</script>
		<?php
	}

	/* =====================================================================
	 * De controlelijst
	 * ===================================================================== */

	public static function menu() {
		self::$scherm = (string) add_submenu_page(
			'wss-ai',
			__( 'bol.com', 'wss-ai' ),
			__( 'bol.com', 'wss-ai' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'pagina' )
		);
	}

	/**
	 * Een beetje opmaak op onze eigen pagina.
	 *
	 * De algemene stijl van WSS Tools hangt aan de schermen die het menu zelf
	 * registreert; deze pagina staat daar niet in. Zonder dit zijn de kaarten
	 * randloze witte blokken.
	 */
	public static function stijl( $hook ) {
		if ( ! self::$scherm || $hook !== self::$scherm ) {
			return;
		}
		wp_register_style( 'wss-bol', false, array(), WSS_AI_VERSIE );
		wp_enqueue_style( 'wss-bol' );
		wp_add_inline_style(
			'wss-bol',
			'.wss-ai-kaart{background:#fff;border:1px solid #c3c4c7;border-radius:6px;'
			. 'padding:16px 20px;margin:16px 0;max-width:900px}'
			. '.wss-ai-kaart h2{margin-top:0}'
			. '.wss-ai-inleiding{max-width:760px;font-size:14px}'
			. '.wss-ai-tabel{max-width:640px}'
			. '.wss-ai-tabel th{width:300px}'
			. '.wss-ai-klein{font-size:12px;color:#646970}'
		);
	}

	/**
	 * Alles wat voor bol is aangevinkt, als losse aanbiedingen.
	 *
	 * Een variabel product is zelf geen aanbieding: bol verkoopt de maat en niet
	 * het product. Daarom staan hier eenvoudige producten en VARIATIES in, en
	 * geen variabele ouders.
	 *
	 * @return array WC_Product-objecten.
	 */
	public static function kandidaten( $maximaal = 2000 ) {
		global $wpdb;

		/* Alles waar het vinkje expliciet op 'yes' staat; de variaties van een
		   aangevinkt product komen er hieronder bij. Dat tweede kan niet met één
		   meta_query: een variatie zonder eigen waarde heeft helemaal geen regel
		   in postmeta. */
		$aangevinkt = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE m.meta_value = 'yes'
				   AND p.post_type IN ( 'product', 'product_variation' )
				 LIMIT %d",
				self::META_ACTIEF,
				absint( $maximaal )
			)
		);

		$kandidaten = array();

		foreach ( array_map( 'absint', (array) $aangevinkt ) as $id ) {
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $kind_id ) {
					if ( 'no' === self::stand( $kind_id ) || isset( $kandidaten[ $kind_id ] ) ) {
						continue;
					}
					$kind = wc_get_product( $kind_id );
					if ( $kind ) {
						$kandidaten[ $kind_id ] = $kind;
					}
				}
				continue;
			}

			if ( ! isset( $kandidaten[ $id ] ) ) {
				$kandidaten[ $id ] = $product;
			}
		}

		return array_values( $kandidaten );
	}

	/** Hoeveel artikelen er in deze shop überhaupt een barcode hebben. */
	private static function met_barcode() {
		global $wpdb;

		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_global_unique_id'
			 LEFT JOIN {$wpdb->posts} ouder ON ouder.ID = p.post_parent
			 WHERE TRIM( m.meta_value ) <> ''
			   AND (
			        ( p.post_type = 'product' AND p.post_status = 'publish' )
			     OR ( p.post_type = 'product_variation' AND ouder.post_status = 'publish' )
			   )"
		);
	}

	public static function pagina() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$kandidaten = self::kandidaten();
		$klaar      = array();
		$afgekeurd  = array();

		foreach ( $kandidaten as $product ) {
			list( $ok, $reden ) = self::geschikt( $product );
			if ( $ok ) {
				$klaar[] = $product;
			} else {
				$afgekeurd[] = array( $product, $reden );
			}
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'bol.com', 'wss-ai' ) . '</h1>';

		echo '<p class="wss-ai-inleiding">';
		echo esc_html__( 'Hier zie je wat er met de huidige instellingen naar bol.com zou gaan. Een artikel doet mee als het vinkje aanstaat, er een bol-prijs is ingevuld en er een barcode op staat.', 'wss-ai' );
		echo '</p>';

		echo '<div class="wss-ai-kaart">';
		echo '<h2>' . esc_html__( 'De stand van zaken', 'wss-ai' ) . '</h2>';
		echo '<table class="widefat striped wss-ai-tabel"><tbody>';
		printf(
			'<tr><th>%s</th><td>%d</td></tr>',
			esc_html__( 'Aangevinkt voor bol', 'wss-ai' ),
			count( $kandidaten )
		);
		printf(
			'<tr><th>%s</th><td><strong>%d</strong></td></tr>',
			esc_html__( 'Klaar om te plaatsen', 'wss-ai' ),
			count( $klaar )
		);
		printf(
			'<tr><th>%s</th><td>%d</td></tr>',
			esc_html__( 'Nog niet compleet', 'wss-ai' ),
			count( $afgekeurd )
		);
		printf(
			'<tr><th>%s</th><td>%d</td></tr>',
			esc_html__( 'Artikelen met een barcode in de shop', 'wss-ai' ),
			self::met_barcode()
		);
		echo '</tbody></table>';

		echo '<p class="wss-ai-klein">';
		echo esc_html__( 'De koppeling met bol.com staat nog niet aan: daarvoor zijn de API-gegevens van het bol-verkoopaccount nodig. Zolang die er niet zijn verandert er niets bij bol en is dit alleen een voorbereiding.', 'wss-ai' );
		echo '</p>';
		echo '</div>';

		if ( ! empty( $afgekeurd ) ) {
			echo '<div class="wss-ai-kaart">';
			echo '<h2>' . esc_html__( 'Deze gaan nog niet mee', 'wss-ai' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Artikel', 'wss-ai' ) . '</th>';
			echo '<th>' . esc_html__( 'Reden', 'wss-ai' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( array_slice( $afgekeurd, 0, 200 ) as $regel ) {
				list( $product, $reden ) = $regel;
				$link = get_edit_post_link( $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );

				echo '<tr><td>';
				if ( $link ) {
					echo '<a href="' . esc_url( $link ) . '">' . esc_html( $product->get_name() ) . '</a>';
				} else {
					echo esc_html( $product->get_name() );
				}
				echo '</td><td>' . esc_html( $reden ) . '</td></tr>';
			}

			echo '</tbody></table>';

			if ( count( $afgekeurd ) > 200 ) {
				echo '<p class="wss-ai-klein">';
				printf(
					/* translators: %d: aantal regels dat niet wordt getoond. */
					esc_html__( 'En nog %d andere.', 'wss-ai' ),
					count( $afgekeurd ) - 200
				);
				echo '</p>';
			}
			echo '</div>';
		}

		echo '<div class="wss-ai-kaart">';
		echo '<h2>' . esc_html__( 'Nooit naar bol.com', 'wss-ai' ) . '</h2>';
		echo '<p>' . esc_html__( 'Product-ids die altijd buiten bol blijven, ook als het vinkje per ongeluk aangaat. Gescheiden door komma\'s of een per regel.', 'wss-ai' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wss_bol_uitsluiten' );
		echo '<input type="hidden" name="action" value="wss_bol_uitsluiten" />';
		echo '<textarea name="ids" rows="4" class="large-text code">';
		echo esc_textarea( implode( ', ', self::uitsluitlijst() ) );
		echo '</textarea>';
		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Opslaan', 'wss-ai' ) . '</button></p>';
		echo '</form>';
		echo '</div>';

		echo '</div>';
	}

	public static function uitsluitlijst_opslaan() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Daar heb je geen rechten voor.', 'wss-ai' ) );
		}
		check_admin_referer( 'wss_bol_uitsluiten' );

		$ruw   = isset( $_POST['ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ids'] ) ) : '';
		$delen = preg_split( '/[^0-9]+/', $ruw );
		$ids   = array();

		foreach ( (array) $delen as $deel ) {
			$id = absint( $deel );
			if ( $id ) {
				$ids[ $id ] = $id;
			}
		}

		update_option( self::OPTIE_UIT, array_values( $ids ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/* =====================================================================
	 * Meldingen en doorgeven
	 * ===================================================================== */

	/**
	 * Na een wijziging: melden als het niet kan, en de serverkant een seintje geven.
	 *
	 * Het seintje is een action en geen verzoek naar buiten. Dat is bewust: deze
	 * plugin belt bol niet, zie de uitleg bovenaan dit bestand.
	 */
	private static function na_wijziging( $id, $stand ) {
		do_action( 'wss_bol_selectie_gewijzigd', $id, $stand );

		if ( 'yes' !== $stand ) {
			return;
		}

		$product = wc_get_product( $id );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			return;
		}

		list( $ok, $reden ) = self::geschikt( $product );
		if ( $ok ) {
			return;
		}

		/* Per gebruiker, want twee mensen in dezelfde winkel horen elkaars
		   meldingen niet te zien. */
		set_transient( 'wss_bol_melding_' . get_current_user_id(), $reden, 60 );
	}

	public static function melding() {
		$sleutel = 'wss_bol_melding_' . get_current_user_id();
		$reden   = get_transient( $sleutel );

		if ( ! $reden ) {
			return;
		}
		delete_transient( $sleutel );

		echo '<div class="notice notice-warning is-dismissible"><p><strong>bol.com:</strong> ';
		printf(
			/* translators: %s: de reden waarom het artikel nog niet naar bol kan. */
			esc_html__( 'dit artikel staat aangevinkt voor bol.com, maar gaat nog niet mee. %s.', 'wss-ai' ),
			esc_html( $reden )
		);
		echo '</p></div>';
	}
}
