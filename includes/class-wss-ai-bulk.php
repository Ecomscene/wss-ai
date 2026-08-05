<?php
/**
 * Meerdere producten achter elkaar een nieuwe foto geven.
 *
 * EEN VOOR EEN, MET EEN AKKOORD PER FOTO
 * De verleiding is om vijftig producten aan te vinken en weg te lopen. Dat is
 * precies wat je niet wilt: als de stijl niet goed valt, heb je vijftig foto's
 * betaald die je allemaal weggooit. Dus maakt hij er één, laat hem zien, en pas
 * als jij ja zegt begint de volgende. Stoppen kan altijd, en de opdracht
 * bijschaven en opnieuw proberen ook.
 *
 * GEEN TWEEDE PIJPLIJN
 * Dit scherm stuurt de knoppen aan die er al zijn: dezelfde ajax-acties als bij
 * één product. Wat we daar leren over het maken en plaatsen van een foto geldt
 * hier dus vanzelf, en een reparatie hoeft maar op één plek.
 *
 * WAAROM GEEN VARIANTEN IN BULK
 * Een variant vraagt om een plek die je zelf bedenkt, en die is per product
 * anders. "Op een bank in een woonkamer" voor vijftig verschillende artikelen is
 * geen serie maar vijftig keer dezelfde vergissing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Bulk {

	const SLUG = 'wss-ai-bulk';
	const ACTIE = 'wss_ai_fotos';

	/** Hoeveel producten we in één keer aannemen. */
	const MAX = 100;

	public static function init() {
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'actie_toevoegen' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'actie_afhandelen' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'pagina' ), 11 );
	}

	public static function actie_toevoegen( $acties ) {
		if ( WSS_AI_Koppeling::is_actief() && WSS_AI_Koppeling::module_aan( 'afbeeldingen' ) ) {
			$acties['wss_ai_fotos'] = __( 'Bulk afbeeldingen', 'wss-ai' );
		}
		return $acties;
	}

	/**
	 * De aangevinkte producten doorgeven aan ons scherm.
	 *
	 * Via een transient en niet via de URL: honderd product-ids in een adresbalk
	 * loopt tegen de grens van wat een webserver aanneemt, en dan krijg je een
	 * lege pagina zonder uitleg. Het mandje hoort bij deze gebruiker en verloopt
	 * vanzelf.
	 */
	public static function actie_afhandelen( $terug, $actie, $ids ) {
		if ( self::ACTIE !== $actie ) {
			return $terug;
		}
		if ( ! current_user_can( 'edit_products' ) ) {
			return $terug;
		}

		$ids = array_slice( array_map( 'absint', (array) $ids ), 0, self::MAX );
		set_transient( 'wss_ai_bulk_' . get_current_user_id(), $ids, HOUR_IN_SECONDS );

		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * De pagina bestaat wel, maar staat niet in het menu.
	 *
	 * Je komt hier via de bulkactie, met een mandje producten. Een menu-item zou
	 * je op een leeg scherm laten uitkomen met de vraag wat je hier doet.
	 */
	public static function pagina() {
		add_submenu_page(
			'wss-ai',
			__( 'Bulk afbeeldingen', 'wss-ai' ),
			__( 'Bulk afbeeldingen', 'wss-ai' ),
			'edit_products',
			self::SLUG,
			array( __CLASS__, 'toon' )
		);
		remove_submenu_page( 'wss-ai', self::SLUG );
	}

	private static function producten() {
		$ids = get_transient( 'wss_ai_bulk_' . get_current_user_id() );
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$uit = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'product' !== $post->post_type || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$thumb = (int) get_post_thumbnail_id( $id );
			$uit[] = array(
				'id'    => (int) $id,
				'naam'  => get_the_title( $id ),
				'thumb' => $thumb ? wp_get_attachment_image_url( $thumb, 'thumbnail' ) : '',
				/* Zonder foto valt er niets te vernieuwen. Dat zeggen we hier al,
				   zodat je niet halverwege de rij op een verrassing stuit. */
				'kan'   => (bool) $thumb,
			);
		}
		return $uit;
	}

	public static function toon() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'Je hebt geen toegang tot deze pagina.', 'wss-ai' ) );
		}

		$producten = self::producten();
		$stijl = WSS_AI_Fotostudio::stijl();
		$zonder = 0;
		foreach ( $producten as $p ) {
			if ( ! $p['kan'] ) {
				$zonder++;
			}
		}
		?>
		<div class="wrap wss-ai wss-ai-bulk">
			<h1><?php esc_html_e( 'Bulk afbeeldingen', 'wss-ai' ); ?></h1>

			<?php if ( ! $producten ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %s wordt de link naar het productenoverzicht. */
							esc_html__( 'Er staan geen producten klaar. Ga naar %s, vink de producten aan die je wilt doen, en kies bij de bulkacties "Bulk afbeeldingen".', 'wss-ai' ),
							'<a href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'je productenoverzicht', 'wss-ai' ) . '</a>'
						);
						?>
					</p>
				</div>
				<?php
				echo '</div>';
				return;
			endif;
			?>

			<p class="wss-ai-inleiding">
				<?php
				printf(
					/* translators: %d is het aantal producten. */
					esc_html( _n( 'Er staat %d product klaar.', 'Er staan %d producten klaar.', count( $producten ), 'wss-ai' ) ),
					count( $producten )
				);
				?>
				<?php esc_html_e( 'Ze worden één voor één gedaan. Je ziet elke foto voordat er iets verandert, en pas als jij hem goedkeurt begint de volgende. Stoppen kan altijd.', 'wss-ai' ); ?>
			</p>

			<?php if ( $zonder ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						printf(
							/* translators: %d is het aantal producten zonder foto. */
							esc_html( _n( '%d product heeft nog geen hoofdfoto en wordt overgeslagen. We bewerken een bestaande foto; we maken er geen uit het niets.', '%d producten hebben nog geen hoofdfoto en worden overgeslagen. We bewerken een bestaande foto; we maken er geen uit het niets.', $zonder, 'wss-ai' ) ),
							$zonder
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="wss-ai-kaart">
				<h2><?php esc_html_e( 'Wat er moet gebeuren', 'wss-ai' ); ?></h2>

				<p>
					<label><input type="radio" name="wss-ai-bulk-taak" value="vernieuwen" checked>
						<strong><?php esc_html_e( 'Foto vernieuwen', 'wss-ai' ); ?></strong>
						<span class="wss-ai-mut"><?php esc_html_e( 'dezelfde opname, mooiere omgeving en beter licht', 'wss-ai' ); ?></span>
					</label><br>
					<label><input type="radio" name="wss-ai-bulk-taak" value="achtergrond">
						<strong><?php esc_html_e( 'Achtergrond weghalen', 'wss-ai' ); ?></strong>
						<span class="wss-ai-mut"><?php esc_html_e( 'je product vrijstaand', 'wss-ai' ); ?></span>
					</label>
				</p>
				<p class="wss-ai-mut wss-ai-klein">
					<?php esc_html_e( 'Varianten kunnen niet in bulk: daar hoort per product een eigen plek bij, en dan is het geen serie meer.', 'wss-ai' ); ?>
				</p>

				<p>
					<label for="wss-ai-bulk-doel"><strong><?php esc_html_e( 'Waar komt de nieuwe foto?', 'wss-ai' ); ?></strong></label><br>
					<select id="wss-ai-bulk-doel">
						<option value="hoofd"><?php esc_html_e( 'Als hoofdfoto (de oude schuift naar de galerij)', 'wss-ai' ); ?></option>
						<option value="galerij"><?php esc_html_e( 'Erbij in de galerij (de hoofdfoto blijft staan)', 'wss-ai' ); ?></option>
					</select>
				</p>

				<p class="wss-ai-extra-vak">
					<label for="wss-ai-bulk-extra"><strong><?php esc_html_e( 'Iets erbij, voor al deze producten', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Optioneel. Valt de stijl niet goed? Dan pas je dit aan en probeer je hetzelfde product opnieuw.', 'wss-ai' ); ?></span>
					<textarea id="wss-ai-bulk-extra" rows="2" class="large-text"></textarea>
				</p>

				<p class="wss-ai-mut wss-ai-klein">
					<?php
					if ( ! empty( $stijl['beschrijving'] ) || ! empty( $stijl['eigen'] ) ) {
						esc_html_e( 'Je eigen fotostijl wordt gebruikt.', 'wss-ai' );
						echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wss-ai-afbeeldingen' ) ) . '">'
							. esc_html__( 'Aanpassen', 'wss-ai' ) . '</a>';
					} else {
						printf(
							/* translators: %s wordt de link naar de instellingen. */
							esc_html__( 'Je hebt nog geen fotostijl ingesteld. Dat doe je bij %s.', 'wss-ai' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=wss-ai-afbeeldingen' ) ) . '">' . esc_html__( 'Afbeeldingen', 'wss-ai' ) . '</a>'
						);
					}
					?>
				</p>

				<p>
					<button type="button" class="button button-primary wss-ai-bulk-start"><?php esc_html_e( 'Beginnen', 'wss-ai' ); ?></button>
					<button type="button" class="button wss-ai-bulk-stop" hidden><?php esc_html_e( 'Stoppen', 'wss-ai' ); ?></button>
					<span class="wss-ai-bulk-melding wss-ai-mut"></span>
				</p>
			</div>

			<div class="wss-ai-kaart wss-ai-bulk-werk" hidden>
				<h2 class="wss-ai-bulk-naam"></h2>
				<div class="wss-ai-paneel-beelden">
					<figure>
						<figcaption><?php esc_html_e( 'Waar we mee beginnen', 'wss-ai' ); ?></figcaption>
						<img class="wss-ai-bulk-bron" src="" alt="">
					</figure>
					<figure class="wss-ai-bulk-nieuw-vak" hidden>
						<figcaption><?php esc_html_e( 'Wat eruit kwam', 'wss-ai' ); ?></figcaption>
						<img class="wss-ai-bulk-nieuw" src="" alt="">
					</figure>
				</div>

				<div class="wss-ai-bijwerk-vak" hidden>
					<label for="wss-ai-bulk-bijwerk"><strong><?php esc_html_e( 'Nog iets aanpassen aan deze foto?', 'wss-ai' ); ?></strong></label><br>
					<span class="wss-ai-mut wss-ai-klein"><?php esc_html_e( 'Alleen dit ene ding verandert; de rest blijft staan.', 'wss-ai' ); ?></span>
					<div class="wss-ai-bijwerk-rij">
						<input type="text" id="wss-ai-bulk-bijwerk" class="regular-text" placeholder="<?php esc_attr_e( 'haal het takje links weg', 'wss-ai' ); ?>">
						<button type="button" class="button wss-ai-bulk-bijwerk-knop"><?php esc_html_e( 'Pas aan', 'wss-ai' ); ?></button>
					</div>
				</div>

				<p class="wss-ai-paneel-knoppen">
					<button type="button" class="button button-primary wss-ai-bulk-ja"><?php esc_html_e( 'Gebruiken en door', 'wss-ai' ); ?></button>
					<button type="button" class="button wss-ai-bulk-opnieuw"><?php esc_html_e( 'Opnieuw proberen', 'wss-ai' ); ?></button>
					<button type="button" class="button wss-ai-bulk-over"><?php esc_html_e( 'Overslaan', 'wss-ai' ); ?></button>
				</p>
			</div>

			<table class="widefat striped wss-ai-bulk-lijst">
				<thead>
					<tr>
						<th style="width:60px"></th>
						<th><?php esc_html_e( 'Product', 'wss-ai' ); ?></th>
						<th style="width:220px"><?php esc_html_e( 'Stand', 'wss-ai' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $producten as $p ) : ?>
						<tr data-id="<?php echo esc_attr( $p['id'] ); ?>">
							<td>
								<?php if ( $p['thumb'] ) : ?>
									<img src="<?php echo esc_url( $p['thumb'] ); ?>" alt="" width="44" height="44" style="object-fit:cover;border-radius:3px">
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $p['naam'] ); ?></td>
							<td class="wss-ai-stand">
								<?php echo $p['kan'] ? esc_html__( 'wacht', 'wss-ai' ) : esc_html__( 'geen hoofdfoto, wordt overgeslagen', 'wss-ai' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** De gegevens die het scherm nodig heeft. */
	public static function scriptgegevens() {
		return array(
			'producten' => self::producten(),
			'taal'      => array(
				'wacht'     => __( 'wacht', 'wss-ai' ),
				'bezig'     => __( 'bezig…', 'wss-ai' ),
				'klaar'     => __( 'klaar', 'wss-ai' ),
				'over'      => __( 'overgeslagen', 'wss-ai' ),
				'mislukt'   => __( 'mislukt', 'wss-ai' ),
				'geenFoto'  => __( 'geen hoofdfoto, wordt overgeslagen', 'wss-ai' ),
				'maken'     => __( 'Bezig met maken. Dit duurt ongeveer een halve minuut.', 'wss-ai' ),
				'plaatsen'  => __( 'Bezig met plaatsen…', 'wss-ai' ),
				'gestopt'   => __( 'Gestopt. Je kunt verder waar je gebleven was.', 'wss-ai' ),
				'afgerond'  => __( 'Alle producten zijn langsgeweest.', 'wss-ai' ),
				'mislukking' => __( 'Dit lukte niet.', 'wss-ai' ),
			),
		);
	}
}
