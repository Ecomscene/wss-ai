<?php
/**
 * Template editor (new + edit). Included from
 * WSFM_Flow_Admin_UI::render_templates(), which provides: $template (object|null).
 *
 * Two-column layout in WordPress post-editor style: wp_editor on the left,
 * a merge-tag cheatsheet postbox on the right.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_is_edit = ( null !== $template );
?>
<div class="wrap wsfm-template-edit">
	<h1><?php echo $wsfm_is_edit ? esc_html__( 'Template bewerken', 'ws-flow-mailer' ) : esc_html__( 'Nieuwe template', 'ws-flow-mailer' ); ?></h1>

	<?php if ( isset( $_GET['wsfm-saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template opgeslagen.', 'ws-flow-mailer' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-template-form">
		<input type="hidden" name="action" value="wsfm_save_template" />
		<input type="hidden" name="template_id" value="<?php echo $wsfm_is_edit ? (int) $template->id : 0; ?>" />
		<?php wp_nonce_field( 'wsfm_save_template' ); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="wsfm_template_name"><?php esc_html_e( 'Naam', 'ws-flow-mailer' ); ?></label></th>
							<td>
								<input type="text" id="wsfm_template_name" name="template_name" class="regular-text" required
									value="<?php echo esc_attr( $wsfm_is_edit ? $template->name : '' ); ?>"
									placeholder="<?php esc_attr_e( 'Interne naam, bijv. Herinnering #1', 'ws-flow-mailer' ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wsfm_template_subject"><?php esc_html_e( 'Onderwerp', 'ws-flow-mailer' ); ?></label></th>
							<td>
								<input type="text" id="wsfm_template_subject" name="template_subject" class="large-text wsfm-tag-target" required
									value="<?php echo esc_attr( $wsfm_is_edit ? $template->subject : '' ); ?>"
									placeholder="<?php esc_attr_e( 'Bijv. Je bent nog iets vergeten, {first_name}', 'ws-flow-mailer' ); ?>" />
							</td>
						</tr>
					</table>

					<h2 class="title"><?php esc_html_e( 'E-mail inhoud (HTML)', 'ws-flow-mailer' ); ?></h2>
					<?php
					wp_editor(
						$wsfm_is_edit ? $template->html_body : '',
						'wsfm_template_body',
						array(
							'textarea_name' => 'template_body',
							'textarea_rows' => 18,
							'media_buttons' => false,
							'tinymce'       => array(
								'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,alignleft,aligncenter,forecolor,removeformat,code',
							),
						)
					);
					?>

					<p class="submit">
						<?php submit_button( __( 'Template opslaan', 'ws-flow-mailer' ), 'primary', 'submit', false ); ?>
						<button type="button" class="button" id="wsfm-preview-template"><?php esc_html_e( 'Preview met testdata', 'ws-flow-mailer' ); ?></button>
						<button type="button" class="button" id="wsfm-send-test"><?php esc_html_e( 'Verstuur test-mail', 'ws-flow-mailer' ); ?></button>
						<span id="wsfm-template-result" role="status"></span>
					</p>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="postbox">
						<h2 class="hndle" style="padding:8px 12px;margin:0;"><?php esc_html_e( 'Merge tags', 'ws-flow-mailer' ); ?></h2>
						<div class="inside">
							<p class="description"><?php esc_html_e( 'Klik op een tag om die op de cursorpositie in te voegen (in het onderwerp of de inhoud, afhankelijk van waar je het laatst klikte).', 'ws-flow-mailer' ); ?></p>
							<ul class="wsfm-tag-list">
								<?php foreach ( WSFM_Template_Engine::supported_tags() as $wsfm_tag => $wsfm_description ) : ?>
									<li>
										<button type="button" class="button button-small wsfm-insert-tag" data-tag="<?php echo esc_attr( $wsfm_tag ); ?>"><?php echo esc_html( $wsfm_tag ); ?></button>
										<span class="description"><?php echo esc_html( $wsfm_description ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
							<p class="description">
								<strong><?php esc_html_e( 'Tip:', 'ws-flow-mailer' ); ?></strong>
								<?php esc_html_e( 'Zet {unsubscribe_url} altijd in de footer — zonder afmeldlink beschadig je je verzendreputatie.', 'ws-flow-mailer' ); ?>
							</p>
						</div>
					</div>

					<?php if ( $wsfm_is_edit ) : ?>
						<div class="postbox">
							<h2 class="hndle" style="padding:8px 12px;margin:0;"><?php esc_html_e( 'Verwijderen', 'ws-flow-mailer' ); ?></h2>
							<div class="inside">
								<p class="description"><?php esc_html_e( 'Verwijderen kan alleen als geen enkele flow deze template gebruikt.', 'ws-flow-mailer' ); ?></p>
								<button type="button" class="button-link-delete" id="wsfm-delete-template-btn"><?php esc_html_e( 'Template verwijderen', 'ws-flow-mailer' ); ?></button>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</form>

	<?php if ( $wsfm_is_edit ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wsfm-delete-template-form">
			<input type="hidden" name="action" value="wsfm_delete_template" />
			<input type="hidden" name="template_id" value="<?php echo (int) $template->id; ?>" />
			<?php wp_nonce_field( 'wsfm_delete_template' ); ?>
		</form>
	<?php endif; ?>
</div>
