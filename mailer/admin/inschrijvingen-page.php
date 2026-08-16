<?php
/**
 * De lijst met inschrijvingen. Ingeladen door
 * WSFM_Flow_Admin_UI::render_inschrijvingen() met: $rijen, $totaal, $alle,
 * $pagina, $paginas, $zoek.
 *
 * WAAROM DE ADRESSEN HIER WEL VOLUIT STAAN
 * In het blokje op de Popup-pagina staan ze afgeschermd; dat is een kijkje,
 * geen lijst. Hier is het wel de lijst, en een adressenlijst waar je de
 * adressen niet van kunt lezen kun je niet controleren, niet opzoeken en niet
 * opschonen. Het is de eigen klantenlijst van de winkelier, en de pagina zit
 * achter dezelfde rechten als de rest van de nieuwsbrief.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_bronnen = array(
	'popup'     => __( 'Popup', 'ws-flow-mailer' ),
	'afrekenen' => __( 'Bij het afrekenen', 'ws-flow-mailer' ),
	'import'    => __( 'Zelf toegevoegd', 'ws-flow-mailer' ),
);

$wsfm_basis = add_query_arg( array( 'page' => WSFM_Flow_Admin_UI::SLUG_ABONNEES ), admin_url( 'admin.php' ) );
if ( '' !== $zoek ) {
	$wsfm_basis = add_query_arg( 'zoek', rawurlencode( $zoek ), $wsfm_basis );
}
?>
<div class="wrap wsfm-inschrijvingen-beheer">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Inschrijvingen', 'ws-flow-mailer' ); ?></h1>
	<hr class="wp-header-end" />

	<?php
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen meldingen tonen.
	if ( isset( $_GET['wsfm-toegevoegd'] ) ) :
		$wsfm_toe   = (int) $_GET['wsfm-toegevoegd'];
		$wsfm_best  = isset( $_GET['wsfm-bestond'] ) ? (int) $_GET['wsfm-bestond'] : 0;
		$wsfm_ong   = isset( $_GET['wsfm-ongeldig'] ) ? (int) $_GET['wsfm-ongeldig'] : 0;
		$wsfm_afge  = isset( $_GET['wsfm-afgemeld'] ) ? (int) $_GET['wsfm-afgemeld'] : 0;
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

	<?php if ( isset( $_GET['wsfm-deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Weggehaald.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>

	<?php if ( isset( $_GET['wsfm-error'] ) ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); ?></p></div>
	<?php endif; ?>
	<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

	<p class="wsfm-inschrijvingen-uitleg">
		<?php esc_html_e( 'Iedereen die zich via de popup heeft ingeschreven of die je zelf hebt toegevoegd. Je kunt ze een nieuwsbrief sturen: kies bij Nieuwsbrieven de doelgroep met de inschrijvingen erin.', 'ws-flow-mailer' ); ?>
	</p>

	<div class="wsfm-inschrijvingen-balk">
		<p class="wsfm-aantal-regel">
			<strong><?php echo esc_html( number_format_i18n( $alle ) ); ?></strong>
			<?php echo esc_html( _n( 'adres op je lijst', 'adressen op je lijst', $alle, 'ws-flow-mailer' ) ); ?>
		</p>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wsfm-zoekvak">
			<input type="hidden" name="page" value="<?php echo esc_attr( WSFM_Flow_Admin_UI::SLUG_ABONNEES ); ?>">
			<label class="screen-reader-text" for="wsfm-zoek"><?php esc_html_e( 'Zoek een adres', 'ws-flow-mailer' ); ?></label>
			<input type="search" id="wsfm-zoek" name="zoek" value="<?php echo esc_attr( $zoek ); ?>" placeholder="<?php esc_attr_e( 'Zoek op adres of naam', 'ws-flow-mailer' ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Zoeken', 'ws-flow-mailer' ); ?></button>
			<?php if ( '' !== $zoek ) : ?>
				<a class="button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => WSFM_Flow_Admin_UI::SLUG_ABONNEES ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Alles tonen', 'ws-flow-mailer' ); ?></a>
			<?php endif; ?>
		</form>

		<?php if ( $alle > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wsfm-exportvak">
				<input type="hidden" name="action" value="wsfm_export_inschrijvingen">
				<?php wp_nonce_field( 'wsfm_export_inschrijvingen' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Download de lijst', 'ws-flow-mailer' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( empty( $rijen ) ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php if ( '' !== $zoek ) : ?>
					<?php esc_html_e( 'Niets gevonden. Probeer een ander stuk van het adres.', 'ws-flow-mailer' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Er staat nog niemand op je lijst. Zet de popup aan, of voeg hieronder zelf adressen toe.', 'ws-flow-mailer' ); ?>
				<?php endif; ?>
			</p>
		</div>
	<?php else : ?>
		<table class="widefat striped wsfm-inschrijvingen-tabel">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'E-mailadres', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Voornaam', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Waar vandaan', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Kortingscode', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ingeschreven op', 'ws-flow-mailer' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Acties', 'ws-flow-mailer' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rijen as $wsfm_rij ) : ?>
					<tr>
						<td><?php echo esc_html( $wsfm_rij->email ); ?></td>
						<td><?php echo esc_html( '' !== $wsfm_rij->first_name ? $wsfm_rij->first_name : '-' ); ?></td>
						<td>
							<?php
							echo esc_html(
								isset( $wsfm_bronnen[ $wsfm_rij->source ] )
									? $wsfm_bronnen[ $wsfm_rij->source ]
									: $wsfm_rij->source
							);
							?>
						</td>
						<td>
							<?php if ( '' !== $wsfm_rij->coupon_code ) : ?>
								<code><?php echo esc_html( $wsfm_rij->coupon_code ); ?></code>
							<?php else : ?>
								<span class="wsfm-leeg">-</span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( date_i18n( 'j M Y', strtotime( $wsfm_rij->created_at ) ) ); ?></td>
						<td class="wsfm-rij-actie">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
					<span class="displaying-num">
						<?php
						printf(
							/* translators: %s: aantal adressen. */
							esc_html( _n( '%s adres', '%s adressen', $totaal, 'ws-flow-mailer' ) ),
							esc_html( number_format_i18n( $totaal ) )
						);
						?>
					</span>
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

	<div class="postbox wsfm-importvak">
		<h2 class="hndle"><span><?php esc_html_e( 'Adressen toevoegen', 'ws-flow-mailer' ); ?></span></h2>
		<div class="inside">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="wsfm_import_inschrijvingen">
				<?php wp_nonce_field( 'wsfm_import_inschrijvingen' ); ?>

				<p>
					<label for="wsfm-import-bestand"><strong><?php esc_html_e( 'Een bestand uit een ander pakket', 'ws-flow-mailer' ); ?></strong></label><br>
					<input type="file" id="wsfm-import-bestand" name="bestand" accept=".csv,.txt,text/csv,text/plain"><br>
					<span class="description"><?php esc_html_e( 'Een CSV uit Mailchimp, Excel of je oude nieuwsbriefpakket. Het maakt niet uit in welke kolom het adres staat; we zoeken zelf welk veld een e-mailadres is. Staat er een voornaam bij, dan nemen we die mee.', 'ws-flow-mailer' ); ?></span>
				</p>

				<p>
					<label for="wsfm-import-plakken"><strong><?php esc_html_e( 'Of plak ze hier, een per regel', 'ws-flow-mailer' ); ?></strong></label><br>
					<textarea id="wsfm-import-plakken" name="plakken" rows="6" class="large-text code" placeholder="jan@voorbeeld.nl&#10;Marieke;marieke@voorbeeld.nl"></textarea><br>
					<span class="description"><?php esc_html_e( 'Je mag ook een naam ernaast zetten, gescheiden door een puntkomma of een komma.', 'ws-flow-mailer' ); ?></span>
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

				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Toevoegen', 'ws-flow-mailer' ); ?></button>
				</p>
			</form>
		</div>
	</div>
</div>
