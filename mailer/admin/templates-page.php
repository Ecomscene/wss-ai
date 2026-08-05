<?php
/**
 * Templates list. Included from WSFM_Flow_Admin_UI::render_templates(),
 * which provides: $templates.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wsfm-templates">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Templates', 'ws-flow-mailer' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_TEMPLATES . '&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nieuwe template', 'ws-flow-mailer' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( isset( $_GET['wsfm-deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template verwijderd.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['wsfm-error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['wsfm-error'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
	<?php endif; ?>

	<?php if ( empty( $templates ) ) : ?>
		<p><?php esc_html_e( 'Nog geen templates.', 'ws-flow-mailer' ); ?></p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Gebruikt in', 'ws-flow-mailer' ); ?></th>
					<th><?php esc_html_e( 'Laatst bewerkt', 'ws-flow-mailer' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $templates as $wsfm_template ) :
					$wsfm_used_in = WSFM_Templates::flows_using( $wsfm_template->id );
					?>
					<tr>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WSFM_Flow_Admin_UI::SLUG_TEMPLATES . '&action=edit&template=' . (int) $wsfm_template->id ) ); ?>"><?php echo esc_html( $wsfm_template->name ); ?></a></strong></td>
						<td><?php echo esc_html( $wsfm_template->subject ); ?></td>
						<td>
							<?php
							if ( empty( $wsfm_used_in ) ) {
								echo '<span class="description">' . esc_html__( 'Geen flow', 'ws-flow-mailer' ) . '</span>';
							} else {
								echo esc_html( implode( ', ', wp_list_pluck( $wsfm_used_in, 'name' ) ) );
							}
							?>
						</td>
						<td><?php echo esc_html( $wsfm_template->updated_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
