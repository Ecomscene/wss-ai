<?php
/**
 * Wat er verstuurd is. Ingeladen door WSFM_Flow_Admin_UI::render_postlog()
 * met: $doorlichting, $log, $aantallen, $stand, $zoek.
 *
 * WAAROM HIER WEL HET HELE ADRES STAAT
 * Op het overzicht staat een adres afgeschermd als j***@voorbeeld.nl. Hier
 * niet, en dat is met opzet. Dit scherm bestaat om de vraag "waar is mijn
 * mail" te beantwoorden, en met een afgeschermd adres kun je die vraag niet
 * beantwoorden: je kunt niet nakijken of jouw eigen adres erbij zat. Wie dit
 * scherm mag openen heeft manage_woocommerce en ziet dezelfde adressen in
 * elke bestelling.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_standen = WSFM_Postlog::standen();
$wsfm_basis   = admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_POSTLOG );
$wsfm_totaal  = array_sum( $aantallen );
?>
<div class="wrap wsfm-postlog">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Verstuurde mail', 'ws-flow-mailer' ); ?></h1>
	<hr class="wp-header-end" />

	<div class="postbox wsfm-doorlichting">
		<div class="inside">
			<p><strong><?php esc_html_e( 'Hoe staat de post ervoor?', 'ws-flow-mailer' ); ?></strong></p>

			<?php foreach ( $doorlichting as $wsfm_punt ) : ?>
				<div class="wsfm-schakel is-<?php echo esc_attr( $wsfm_punt['stand'] ); ?>">
					<span class="wsfm-schakel-kop"><?php echo esc_html( $wsfm_punt['kop'] ); ?></span>
					<?php if ( '' !== $wsfm_punt['uitleg'] ) : ?>
						<span class="wsfm-schakel-uitleg"><?php echo esc_html( $wsfm_punt['uitleg'] ); ?></span>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<ul class="subsubsub">
		<li>
			<a href="<?php echo esc_url( $wsfm_basis ); ?>" class="<?php echo '' === $stand ? 'current' : ''; ?>">
				<?php esc_html_e( 'Alles', 'ws-flow-mailer' ); ?>
				<span class="count">(<?php echo esc_html( number_format_i18n( $wsfm_totaal ) ); ?>)</span>
			</a>
		</li>
		<?php foreach ( $wsfm_standen as $wsfm_key => $wsfm_label ) : ?>
			<?php if ( empty( $aantallen[ $wsfm_key ] ) ) { continue; } ?>
			<li>
				|
				<a href="<?php echo esc_url( add_query_arg( 'stand', $wsfm_key, $wsfm_basis ) ); ?>"
					class="<?php echo $stand === $wsfm_key ? 'current' : ''; ?>">
					<?php echo esc_html( $wsfm_label ); ?>
					<span class="count">(<?php echo esc_html( number_format_i18n( $aantallen[ $wsfm_key ] ) ); ?>)</span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<form method="get" class="wsfm-postlog-zoek">
		<input type="hidden" name="page" value="<?php echo esc_attr( WSFM_Flow_Admin_UI::SLUG_POSTLOG ); ?>">
		<?php if ( '' !== $stand ) : ?>
			<input type="hidden" name="stand" value="<?php echo esc_attr( $stand ); ?>">
		<?php endif; ?>
		<label class="screen-reader-text" for="wsfm-zoek-adres"><?php esc_html_e( 'Zoek op e-mailadres', 'ws-flow-mailer' ); ?></label>
		<input type="search" id="wsfm-zoek-adres" name="zoek" value="<?php echo esc_attr( $zoek ); ?>"
			placeholder="<?php esc_attr_e( 'Zoek op e-mailadres', 'ws-flow-mailer' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Zoeken', 'ws-flow-mailer' ); ?></button>
		<?php if ( '' !== $zoek ) : ?>
			<a href="<?php echo esc_url( '' === $stand ? $wsfm_basis : add_query_arg( 'stand', $stand, $wsfm_basis ) ); ?>" class="button-link">
				<?php esc_html_e( 'Wissen', 'ws-flow-mailer' ); ?></a>
		<?php endif; ?>
	</form>

	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th style="width:140px;"><?php esc_html_e( 'Wanneer', 'ws-flow-mailer' ); ?></th>
				<th style="width:110px;"><?php esc_html_e( 'Stand', 'ws-flow-mailer' ); ?></th>
				<th><?php esc_html_e( 'Naar', 'ws-flow-mailer' ); ?></th>
				<th><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></th>
				<th style="width:180px;"><?php esc_html_e( 'Hoort bij', 'ws-flow-mailer' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $log['rijen'] ) ) : ?>
				<tr>
					<td colspan="5">
						<?php if ( '' !== $zoek || '' !== $stand ) : ?>
							<?php esc_html_e( 'Niets gevonden. Probeer het zonder filter.', 'ws-flow-mailer' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Er is nog geen mail verstuurd. Wat er aan de hand is staat hierboven.', 'ws-flow-mailer' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( $log['rijen'] as $wsfm_rij ) : ?>
				<tr>
					<td><?php echo esc_html( date_i18n( 'j M Y H:i', mysql2date( 'U', $wsfm_rij->sent_at ) ) ); ?></td>
					<td>
						<span class="wsfm-stand wsfm-stand-<?php echo esc_attr( $wsfm_rij->status ); ?>">
							<?php echo esc_html( isset( $wsfm_standen[ $wsfm_rij->status ] ) ? $wsfm_standen[ $wsfm_rij->status ] : $wsfm_rij->status ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $wsfm_rij->recipient ); ?></td>
					<td>
						<?php echo esc_html( '' !== (string) $wsfm_rij->subject ? $wsfm_rij->subject : '-' ); ?>
						<?php if ( $wsfm_rij->error_message ) : ?>
							<div class="wsfm-postlog-fout"><?php echo esc_html( $wsfm_rij->error_message ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<?php
						echo esc_html( $wsfm_rij->bron_naam ? $wsfm_rij->bron_naam : '-' );
						if ( 'nieuwsbrief' === $wsfm_rij->bron_soort ) {
							echo ' <span class="wsfm-merkje">' . esc_html__( 'nieuwsbrief', 'ws-flow-mailer' ) . '</span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $log['paginas'] > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: aantal regels. */
						esc_html( _n( '%s regel', '%s regels', $log['totaal'], 'ws-flow-mailer' ) ),
						esc_html( number_format_i18n( $log['totaal'] ) )
					);
					?>
				</span>
				<?php
				$wsfm_arg = array_filter(
					array(
						'page'  => WSFM_Flow_Admin_UI::SLUG_POSTLOG,
						'stand' => $stand,
						'zoek'  => $zoek,
					)
				);

				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => admin_url( 'admin.php' ) . '?' . http_build_query( $wsfm_arg ) . '&paged=%#%',
							'format'    => '',
							'current'   => $log['pagina'],
							'total'     => $log['paginas'],
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
</div>
