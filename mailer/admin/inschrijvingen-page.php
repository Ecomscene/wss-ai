<?php
/**
 * Inschrijvingen: je lijsten, en wie erop staan.
 *
 * Ingeladen door WSFM_Flow_Admin_UI::render_inschrijvingen() met: $lijsten,
 * $afrekenen, $rijen, $lidmaatschap, $totaal, $alle, $pagina, $paginas, $zoek,
 * $lijst_filter.
 *
 * DE VOLGORDE OP DIT SCHERM IS DE VOLGORDE VAN HET WERK
 * Eerst je lijsten, want dat is waar alles in valt. Dan iemand toevoegen, want
 * dat is wat je het vaakst doet. Dan pas de adressen zelf. Het vinkje bij het
 * afrekenen staat onderaan: dat stel je één keer in en daarna nooit meer.
 *
 * WAAROM DE ADRESSEN HIER VOLUIT STAAN
 * In het blokje op de Popup-pagina staan ze afgeschermd; dat is een kijkje,
 * geen lijst. Hier is het wel de lijst, en een adressenlijst waar je de adressen
 * niet van kunt lezen kun je niet controleren, niet opzoeken en niet opschonen.
 * Het is de eigen lijst van de winkelier, achter dezelfde rechten als de rest.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_bronnen = array(
	'popup'     => __( 'Popup', 'ws-flow-mailer' ),
	'afrekenen' => __( 'Bij het afrekenen', 'ws-flow-mailer' ),
	'import'    => __( 'Bestand of geplakt', 'ws-flow-mailer' ),
	'handmatig' => __( 'Zelf toegevoegd', 'ws-flow-mailer' ),
);

/** Het webadres van dit scherm, met filter en zoekterm erin. */
$wsfm_basis = add_query_arg( array( 'page' => WSFM_Flow_Admin_UI::SLUG_ABONNEES ), admin_url( 'admin.php' ) );
if ( '' !== $zoek ) {
	$wsfm_basis = add_query_arg( 'zoek', rawurlencode( $zoek ), $wsfm_basis );
}
if ( $lijst_filter ) {
	$wsfm_basis = add_query_arg( 'lijst', $lijst_filter, $wsfm_basis );
}

$wsfm_schoon = add_query_arg( array( 'page' => WSFM_Flow_Admin_UI::SLUG_ABONNEES ), admin_url( 'admin.php' ) );

/** De lijst waar nu op gefilterd wordt, als object. */
$wsfm_open = null;
foreach ( $lijsten as $wsfm_l ) {
	if ( (int) $wsfm_l->id === (int) $lijst_filter ) {
		$wsfm_open = $wsfm_l;
	}
}
?>
<div class="wrap wsfm-inschrijvingen-beheer">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Inschrijvingen', 'ws-flow-mailer' ); ?></h1>
	<hr class="wp-header-end" />

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen meldingen tonen.
	if ( isset( $_GET['wsfm-contact'] ) ) :
		$wsfm_nieuw = 'nieuw' === $_GET['wsfm-contact'];
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				echo esc_html(
					$wsfm_nieuw
						? __( 'Toegevoegd.', 'ws-flow-mailer' )
						: __( 'Dit adres stond er al op. Hij is wel aan de gekozen lijst toegevoegd.', 'ws-flow-mailer' )
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wsfm-toegevoegd'] ) ) : ?>
		<?php
		$wsfm_toe  = (int) $_GET['wsfm-toegevoegd'];
		$wsfm_best = isset( $_GET['wsfm-bestond'] ) ? (int) $_GET['wsfm-bestond'] : 0;
		$wsfm_ong  = isset( $_GET['wsfm-ongeldig'] ) ? (int) $_GET['wsfm-ongeldig'] : 0;
		$wsfm_afge = isset( $_GET['wsfm-afgemeld'] ) ? (int) $_GET['wsfm-afgemeld'] : 0;
		?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: aantal adressen. */
					esc_html( _n( '%s adres toegevoegd.', '%s adressen toegevoegd.', $wsfm_toe, 'ws-flow-mailer' ) ),
					esc_html( number_format_i18n( $wsfm_toe ) )
				);
				?>
				<?php if ( $wsfm_best ) : ?>
					<?php
					printf(
						/* translators: %s: aantal adressen. */
						esc_html( _n( '%s stond er al op.', '%s stonden er al op.', $wsfm_best, 'ws-flow-mailer' ) ),
						esc_html( number_format_i18n( $wsfm_best ) )
					);
					?>
				<?php endif; ?>
				<?php if ( $wsfm_ong ) : ?>
					<?php
					printf(
						/* translators: %s: aantal regels. */
						esc_html( _n( '%s regel was geen e-mailadres.', '%s regels waren geen e-mailadres.', $wsfm_ong, 'ws-flow-mailer' ) ),
						esc_html( number_format_i18n( $wsfm_ong ) )
					);
					?>
				<?php endif; ?>
				<?php if ( $wsfm_afge ) : ?>
					<?php
					printf(
						/* translators: %s: aantal adressen. */
						esc_html( _n( '%s adres is overgeslagen omdat het zich had afgemeld.', '%s adressen zijn overgeslagen omdat ze zich hadden afgemeld.', $wsfm_afge, 'ws-flow-mailer' ) ),
						esc_html( number_format_i18n( $wsfm_afge ) )
					);
					?>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wsfm-lijst'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Opgeslagen.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wsfm-deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Weggehaald.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wsfm-error'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); ?></p></div>
	<?php endif; ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>


	<!-- ============ je lijsten ============ -->
	<div class="postbox wsfm-vak">
		<div class="inside">
			<h2 class="wsfm-vak-kop"><?php esc_html_e( 'Je lijsten', 'ws-flow-mailer' ); ?></h2>
			<p class="wsfm-vak-uitleg">
				<?php esc_html_e( 'Meestal heb je aan je hoofdlijst genoeg. Een losse lijst is handig om iets te proberen voordat het naar iedereen gaat, of voor een groep vaste klanten.', 'ws-flow-mailer' ); ?>
			</p>

			<table class="widefat striped wsfm-lijstentabel">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Lijst', 'ws-flow-mailer' ); ?></th>
						<th scope="col" class="wsfm-kol-getal"><?php esc_html_e( 'Mensen', 'ws-flow-mailer' ); ?></th>
						<th scope="col" class="wsfm-kol-acties"><span class="screen-reader-text"><?php esc_html_e( 'Acties', 'ws-flow-mailer' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $lijsten as $wsfm_l ) : ?>
						<?php $wsfm_actief = (int) $wsfm_l->id === (int) $lijst_filter; ?>
						<tr class="<?php echo $wsfm_actief ? 'wsfm-rij-actief' : ''; ?>">
							<td>
								<a class="wsfm-lijstnaam" href="<?php echo esc_url( add_query_arg( 'lijst', (int) $wsfm_l->id, $wsfm_schoon ) ); ?>">
									<?php echo esc_html( $wsfm_l->naam ); ?>
								</a>
								<?php if ( ! empty( $wsfm_l->is_hoofdlijst ) ) : ?>
									<span class="wsfm-hoofdlijst"><?php esc_html_e( 'hoofdlijst', 'ws-flow-mailer' ); ?></span>
								<?php endif; ?>
								<?php if ( '' !== $wsfm_l->omschrijving ) : ?>
									<div class="wsfm-mut"><?php echo esc_html( $wsfm_l->omschrijving ); ?></div>
								<?php endif; ?>
							</td>
							<td class="wsfm-kol-getal"><strong><?php echo esc_html( number_format_i18n( (int) $wsfm_l->aantal ) ); ?></strong></td>
							<td class="wsfm-kol-acties">
								<details class="wsfm-hernoem">
									<summary><?php esc_html_e( 'Hernoemen', 'ws-flow-mailer' ); ?></summary>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="wsfm_lijst_hernoem">
										<input type="hidden" name="lijst_id" value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>">
										<?php wp_nonce_field( 'wsfm_lijst' ); ?>
										<input type="text" name="lijst_naam" value="<?php echo esc_attr( $wsfm_l->naam ); ?>" required
											aria-label="<?php esc_attr_e( 'Naam van de lijst', 'ws-flow-mailer' ); ?>">
										<input type="text" name="lijst_omschrijving" value="<?php echo esc_attr( $wsfm_l->omschrijving ); ?>"
											placeholder="<?php esc_attr_e( 'waar hij voor is (mag leeg)', 'ws-flow-mailer' ); ?>"
											aria-label="<?php esc_attr_e( 'Waar de lijst voor is', 'ws-flow-mailer' ); ?>">
										<button type="submit" class="button button-small"><?php esc_html_e( 'Bewaren', 'ws-flow-mailer' ); ?></button>
									</form>
								</details>

								<?php if ( empty( $wsfm_l->is_hoofdlijst ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-inline">
										<input type="hidden" name="action" value="wsfm_lijst_weg">
										<input type="hidden" name="lijst_id" value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>">
										<?php wp_nonce_field( 'wsfm_lijst' ); ?>
										<button type="submit" class="button-link wsfm-weghalen"><?php esc_html_e( 'Weghalen', 'ws-flow-mailer' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-rij-form">
				<input type="hidden" name="action" value="wsfm_lijst_maak">
				<?php wp_nonce_field( 'wsfm_lijst' ); ?>
				<label>
					<span><?php esc_html_e( 'Nieuwe lijst', 'ws-flow-mailer' ); ?></span>
					<input type="text" name="lijst_naam" placeholder="<?php esc_attr_e( 'Vaste klanten', 'ws-flow-mailer' ); ?>" required>
				</label>
				<label class="wsfm-breed">
					<span><?php esc_html_e( 'Waar is hij voor?', 'ws-flow-mailer' ); ?></span>
					<input type="text" name="lijst_omschrijving" placeholder="<?php esc_attr_e( 'mag leeg blijven', 'ws-flow-mailer' ); ?>">
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Lijst toevoegen', 'ws-flow-mailer' ); ?></button>
			</form>
		</div>
	</div>


	<!-- ============ iemand toevoegen ============ -->
	<div class="postbox wsfm-vak">
		<div class="inside">
			<h2 class="wsfm-vak-kop"><?php esc_html_e( 'Iemand toevoegen', 'ws-flow-mailer' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-rij-form">
				<input type="hidden" name="action" value="wsfm_contact">
				<?php wp_nonce_field( 'wsfm_contact' ); ?>
				<label>
					<span><?php esc_html_e( 'Voornaam', 'ws-flow-mailer' ); ?></span>
					<input type="text" name="contact_voornaam" autocomplete="off">
				</label>
				<label>
					<span><?php esc_html_e( 'Achternaam', 'ws-flow-mailer' ); ?></span>
					<input type="text" name="contact_achternaam" autocomplete="off">
				</label>
				<label class="wsfm-breed">
					<span><?php esc_html_e( 'E-mailadres', 'ws-flow-mailer' ); ?></span>
					<input type="email" name="contact_email" required autocomplete="off" placeholder="naam@voorbeeld.nl">
				</label>
				<label>
					<span><?php esc_html_e( 'Op welke lijst', 'ws-flow-mailer' ); ?></span>
					<select name="contact_lijst">
						<?php foreach ( $lijsten as $wsfm_l ) : ?>
							<option value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>"
								<?php selected( $lijst_filter ? (int) $wsfm_l->id === (int) $lijst_filter : ! empty( $wsfm_l->is_hoofdlijst ) ); ?>>
								<?php echo esc_html( $wsfm_l->naam ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Toevoegen', 'ws-flow-mailer' ); ?></button>
			</form>

			<details class="wsfm-uitklap">
				<summary><?php esc_html_e( 'Meerdere tegelijk toevoegen', 'ws-flow-mailer' ); ?></summary>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="wsfm-importvorm">
					<input type="hidden" name="action" value="wsfm_import_inschrijvingen">
					<?php wp_nonce_field( 'wsfm_import_inschrijvingen' ); ?>

					<p>
						<label for="wsfm-import-bestand"><strong><?php esc_html_e( 'Een bestand uit een ander pakket', 'ws-flow-mailer' ); ?></strong></label><br>
						<input type="file" id="wsfm-import-bestand" name="bestand" accept=".csv,.txt,text/csv,text/plain"><br>
						<span class="description"><?php esc_html_e( 'Een CSV uit Mailchimp, Excel of je oude nieuwsbriefpakket. Het maakt niet uit in welke kolom het adres staat; we zoeken zelf welk veld een e-mailadres is. Staan er namen bij, dan nemen we die mee.', 'ws-flow-mailer' ); ?></span>
					</p>

					<p>
						<label for="wsfm-import-plakken"><strong><?php esc_html_e( 'Of plak ze hier, een per regel', 'ws-flow-mailer' ); ?></strong></label><br>
						<textarea id="wsfm-import-plakken" name="plakken" rows="5" class="large-text code" placeholder="jan@voorbeeld.nl&#10;Marieke;de Vries;marieke@voorbeeld.nl"></textarea>
					</p>

					<p>
						<label for="wsfm-import-lijst"><strong><?php esc_html_e( 'Op welke lijst komen ze', 'ws-flow-mailer' ); ?></strong></label><br>
						<select id="wsfm-import-lijst" name="import_lijst">
							<?php foreach ( $lijsten as $wsfm_l ) : ?>
								<option value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>"
									<?php selected( $lijst_filter ? (int) $wsfm_l->id === (int) $lijst_filter : ! empty( $wsfm_l->is_hoofdlijst ) ); ?>>
									<?php echo esc_html( $wsfm_l->naam ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>

					<p class="wsfm-toestemming">
						<label>
							<input type="checkbox" name="toestemming" value="1">
							<strong><?php esc_html_e( 'Deze mensen hebben zich bij mij aangemeld voor post', 'ws-flow-mailer' ); ?></strong>
						</label><br>
						<span class="description">
							<?php esc_html_e( 'Een gekochte of ergens anders vandaan geplukte lijst mag je niet mailen. Dat kost je een boete en het zorgt ervoor dat je mail bij iedereen in de spam belandt, ook bij je echte klanten.', 'ws-flow-mailer' ); ?>
						</span>
					</p>

					<p class="description">
						<?php esc_html_e( 'Adressen die er al op staan blijven zoals ze zijn, en wie zich ooit heeft afgemeld slaan we over.', 'ws-flow-mailer' ); ?>
					</p>

					<p><button type="submit" class="button"><?php esc_html_e( 'Toevoegen', 'ws-flow-mailer' ); ?></button></p>
				</form>
			</details>
		</div>
	</div>


	<!-- ============ de adressen ============ -->
	<h2 class="wsfm-sectiekop">
		<?php if ( $wsfm_open ) : ?>
			<?php echo esc_html( $wsfm_open->naam ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Alle adressen', 'ws-flow-mailer' ); ?>
		<?php endif; ?>
	</h2>

	<div class="wsfm-balk">
		<p class="wsfm-telling">
			<strong><?php echo esc_html( number_format_i18n( $totaal ) ); ?></strong>
			<?php
			if ( $wsfm_open || '' !== $zoek ) {
				printf(
					/* translators: %s: totaal aantal adressen op de hele lijst. */
					esc_html__( 'van je %s adressen', 'ws-flow-mailer' ),
					esc_html( number_format_i18n( $alle ) )
				);
			} else {
				echo esc_html( _n( 'adres', 'adressen', $totaal, 'ws-flow-mailer' ) );
			}
			?>
			<?php if ( $wsfm_open || '' !== $zoek ) : ?>
				<a class="wsfm-filter-weg" href="<?php echo esc_url( $wsfm_schoon ); ?>"><?php esc_html_e( 'toon alles', 'ws-flow-mailer' ); ?></a>
			<?php endif; ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wsfm-zoekvak">
			<input type="hidden" name="page" value="<?php echo esc_attr( WSFM_Flow_Admin_UI::SLUG_ABONNEES ); ?>">
			<?php if ( $lijst_filter ) : ?>
				<input type="hidden" name="lijst" value="<?php echo esc_attr( $lijst_filter ); ?>">
			<?php endif; ?>
			<label class="screen-reader-text" for="wsfm-zoek"><?php esc_html_e( 'Zoek een adres', 'ws-flow-mailer' ); ?></label>
			<input type="search" id="wsfm-zoek" name="zoek" value="<?php echo esc_attr( $zoek ); ?>" placeholder="<?php esc_attr_e( 'Zoek op naam of adres', 'ws-flow-mailer' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Zoeken', 'ws-flow-mailer' ); ?></button>
		</form>

		<?php if ( $alle > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-inline">
				<input type="hidden" name="action" value="wsfm_export_inschrijvingen">
				<?php wp_nonce_field( 'wsfm_export_inschrijvingen' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Download alles', 'ws-flow-mailer' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( empty( $rijen ) ) : ?>
		<div class="wsfm-niets">
			<?php if ( '' !== $zoek ) : ?>
				<?php esc_html_e( 'Niets gevonden. Probeer een ander stuk van de naam of het adres.', 'ws-flow-mailer' ); ?>
			<?php elseif ( $wsfm_open ) : ?>
				<?php esc_html_e( 'Op deze lijst staat nog niemand. Voeg hierboven iemand toe.', 'ws-flow-mailer' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'Er staat nog niemand op je lijst. Zet de popup aan, zet het vinkje bij het afrekenen aan, of voeg hierboven zelf iemand toe.', 'ws-flow-mailer' ); ?>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<table class="widefat striped wsfm-adrestabel">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'E-mailadres', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Lijsten', 'ws-flow-mailer' ); ?></th>
					<th scope="col" class="wsfm-kol-bron"><?php esc_html_e( 'Waar vandaan', 'ws-flow-mailer' ); ?></th>
					<th scope="col" class="wsfm-kol-datum"><?php esc_html_e( 'Sinds', 'ws-flow-mailer' ); ?></th>
					<th scope="col" class="wsfm-kol-acties"><span class="screen-reader-text"><?php esc_html_e( 'Acties', 'ws-flow-mailer' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rijen as $wsfm_rij ) : ?>
					<?php $wsfm_op = isset( $lidmaatschap[ (int) $wsfm_rij->id ] ) ? $lidmaatschap[ (int) $wsfm_rij->id ] : array(); ?>
					<tr>
						<td><?php echo esc_html( WSFM_Subscribers::naam_van( $wsfm_rij ) ); ?></td>
						<td class="wsfm-kol-adres"><?php echo esc_html( $wsfm_rij->email ); ?></td>
						<td>
							<?php if ( empty( $wsfm_op ) ) : ?>
								<span class="wsfm-mut"><?php esc_html_e( 'geen', 'ws-flow-mailer' ); ?></span>
							<?php else : ?>
								<?php foreach ( $wsfm_op as $wsfm_naam ) : ?>
									<span class="wsfm-merkje"><?php echo esc_html( $wsfm_naam ); ?></span>
								<?php endforeach; ?>
							<?php endif; ?>

							<details class="wsfm-lidbeheer">
								<summary><?php esc_html_e( 'Wijzig', 'ws-flow-mailer' ); ?></summary>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="wsfm_lijst_lid">
									<input type="hidden" name="inschrijving_id" value="<?php echo esc_attr( (int) $wsfm_rij->id ); ?>">
									<?php wp_nonce_field( 'wsfm_lijst_lid' ); ?>
									<select name="lijst_id" aria-label="<?php esc_attr_e( 'Kies een lijst', 'ws-flow-mailer' ); ?>">
										<?php foreach ( $lijsten as $wsfm_l ) : ?>
											<option value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>"><?php echo esc_html( $wsfm_l->naam ); ?></option>
										<?php endforeach; ?>
									</select>
									<button type="submit" class="button button-small"><?php esc_html_e( 'Erbij', 'ws-flow-mailer' ); ?></button>
									<button type="submit" name="eraf" value="1" class="button button-small"><?php esc_html_e( 'Eraf', 'ws-flow-mailer' ); ?></button>
								</form>
							</details>
						</td>
						<td class="wsfm-kol-bron">
							<?php
							echo esc_html(
								isset( $wsfm_bronnen[ $wsfm_rij->source ] )
									? $wsfm_bronnen[ $wsfm_rij->source ]
									: $wsfm_rij->source
							);
							?>
							<?php if ( ! empty( $wsfm_rij->coupon_code ) ) : ?>
								<div class="wsfm-mut"><code><?php echo esc_html( $wsfm_rij->coupon_code ); ?></code></div>
							<?php endif; ?>
						</td>
						<td class="wsfm-kol-datum"><?php echo esc_html( date_i18n( 'j M Y', strtotime( $wsfm_rij->created_at ) ) ); ?></td>
						<td class="wsfm-kol-acties">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-inline">
								<input type="hidden" name="action" value="wsfm_delete_inschrijving">
								<input type="hidden" name="inschrijving_id" value="<?php echo esc_attr( (int) $wsfm_rij->id ); ?>">
								<?php wp_nonce_field( 'wsfm_delete_inschrijving' ); ?>
								<button type="submit" class="button-link wsfm-weghalen"><?php esc_html_e( 'Weghalen', 'ws-flow-mailer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $paginas > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<span class="pagination-links">
						<?php if ( $pagina > 1 ) : ?>
							<a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pagina - 1, $wsfm_basis ) ); ?>">&lsaquo;</a>
						<?php endif; ?>
						<span class="paging-input">
							<?php
							printf(
								/* translators: 1: huidige pagina, 2: aantal paginas. */
								esc_html__( '%1$s van %2$s', 'ws-flow-mailer' ),
								esc_html( number_format_i18n( $pagina ) ),
								esc_html( number_format_i18n( $paginas ) )
							);
							?>
						</span>
						<?php if ( $pagina < $paginas ) : ?>
							<a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $pagina + 1, $wsfm_basis ) ); ?>">&rsaquo;</a>
						<?php endif; ?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>


	<!-- ============ het vinkje bij het afrekenen ============ -->
	<div class="postbox wsfm-vak wsfm-afrekenvak">
		<div class="inside">
			<h2 class="wsfm-vak-kop"><?php esc_html_e( 'Aanmelden bij het afrekenen', 'ws-flow-mailer' ); ?></h2>
			<p class="wsfm-vak-uitleg">
				<?php esc_html_e( 'Een vinkje op je afrekenpagina waarmee klanten zich kunnen aanmelden. Dit is de netste manier om je lijst te laten groeien: deze mensen vragen er zelf om, en dat merk je aan hoe vaak ze je post openen.', 'ws-flow-mailer' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wsfm_afrekenen">
				<?php wp_nonce_field( 'wsfm_afrekenen' ); ?>

				<p>
					<label>
						<input type="checkbox" name="afrekenen_aan" value="1" <?php checked( ! empty( $afrekenen['aan'] ) ); ?>>
						<strong><?php esc_html_e( 'Zet het vinkje op mijn afrekenpagina', 'ws-flow-mailer' ); ?></strong>
					</label>
				</p>

				<div class="wsfm-rij-form">
					<label class="wsfm-breed">
						<span><?php esc_html_e( 'Wat er naast het vinkje staat', 'ws-flow-mailer' ); ?></span>
						<input type="text" name="afrekenen_label" maxlength="200" value="<?php echo esc_attr( $afrekenen['label'] ); ?>">
					</label>
					<label>
						<span><?php esc_html_e( 'Op welke lijst', 'ws-flow-mailer' ); ?></span>
						<select name="afrekenen_lijst">
							<?php foreach ( $lijsten as $wsfm_l ) : ?>
								<option value="<?php echo esc_attr( (int) $wsfm_l->id ); ?>" <?php selected( (int) $afrekenen['lijst_id'], (int) $wsfm_l->id ); ?>>
									<?php echo esc_html( $wsfm_l->naam ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Opslaan', 'ws-flow-mailer' ); ?></button>
				</div>

				<p class="description wsfm-smal">
					<?php esc_html_e( 'Zeg wat ze kunnen verwachten: "Houd me op de hoogte" werkt beter dan "Schrijf me in voor de nieuwsbrief". Het vinkje staat nooit vooraf aangevinkt, want een vakje dat al aanstaat is geen toestemming. We bewaren ook de tekst zoals die op dat moment naast het vinkje stond, zodat je later kunt laten zien waar iemand ja op zei.', 'ws-flow-mailer' ); ?>
				</p>
			</form>
		</div>
	</div>
</div>
