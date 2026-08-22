<?php
/**
 * De lijst met nieuwsbrieven. Wordt ingeladen door
 * WSFM_Flow_Admin_UI::render_newsletters(), die $brieven meegeeft.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_nieuw = admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_BRIEVEN . '&action=new' );
?>
<div class="wrap wsfm-brieven">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Nieuwsbrieven', 'ws-flow-mailer' ); ?></h1>
	<a href="<?php echo esc_url( $wsfm_nieuw ); ?>" class="page-title-action"><?php esc_html_e( 'Nieuwsbrief maken', 'ws-flow-mailer' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['wsfm-deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Nieuwsbrief verwijderd.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $brieven ) ) : ?>
		<div class="wsfm-leeg">
			<h2><?php esc_html_e( 'Nog geen nieuwsbrieven', 'ws-flow-mailer' ); ?></h2>
			<p>
				<?php esc_html_e( 'Een nieuwsbrief is een eenmalig bericht aan je klanten. Je kiest een vormgeving, zet er wat foto\'s, tekst en producten in, en verstuurt hem. Flows zijn iets anders: die gaan vanzelf de deur uit als iemand iets bestelt of zijn winkelwagen laat staan.', 'ws-flow-mailer' ); ?>
			</p>
			<p><a href="<?php echo esc_url( $wsfm_nieuw ); ?>" class="button button-primary button-hero"><?php esc_html_e( 'Maak je eerste nieuwsbrief', 'ws-flow-mailer' ); ?></a></p>
		</div>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></th>
					<th style="width:200px;"><?php esc_html_e( 'Naar wie', 'ws-flow-mailer' ); ?></th>
					<th style="width:130px;"><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
					<th style="width:110px;"><?php esc_html_e( 'Ontvangers', 'ws-flow-mailer' ); ?></th>
					<th style="width:170px;"><?php esc_html_e( 'Verstuurd', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $brieven as $wsfm_brief ) :
					$wsfm_link = admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_BRIEVEN . '&action=edit&nieuwsbrief=' . (int) $wsfm_brief->id );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $wsfm_link ); ?>"><?php echo esc_html( $wsfm_brief->name ); ?></a></strong>
						</td>
						<td><?php echo esc_html( $wsfm_brief->subject ); ?></td>
						<td>
							<?php
							/* De doelgroep zoals hij nu heet. Een lijst kan hernoemd of weggegooid
							   zijn; dan tonen we de opgeslagen sleutel en niet een naam die we
							   verzinnen, want dan zou er iets anders staan dan waar hij heen ging. */
							echo isset( $doelgroepen[ $wsfm_brief->audience ] )
								? esc_html( $doelgroepen[ $wsfm_brief->audience ] )
								: '<span class="wsfm-mut">' . esc_html( $wsfm_brief->audience ) . '</span>';
							?>
						</td>
						<td>
							<?php if ( 'concept' === $wsfm_brief->status ) : ?>
								<span class="wsfm-badge"><?php esc_html_e( 'Concept', 'ws-flow-mailer' ); ?></span>
							<?php else : ?>
								<span class="wsfm-badge wsfm-badge-active"><?php esc_html_e( 'Verstuurd', 'ws-flow-mailer' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo $wsfm_brief->recipients ? esc_html( number_format_i18n( $wsfm_brief->recipients ) ) : '-'; ?></td>
						<td>
							<?php
							echo $wsfm_brief->sent_at
								? esc_html( date_i18n( 'j M Y H:i', strtotime( $wsfm_brief->sent_at ) ) )
								: '-';
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
