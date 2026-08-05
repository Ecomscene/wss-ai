<?php
/**
 * Settings page markup. Included from WSFM_Admin_Settings::render_settings_page(),
 * which provides $settings (array of non-secret settings).
 *
 * Secrets are never echoed — only their masks, as placeholder text.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

$wsfm_access_key_mask = WSFM_Credentials::get_mask( 'ses_access_key' );
$wsfm_secret_key_set  = WSFM_Credentials::has_secret( 'ses_secret_key' );
$wsfm_brevo_key_set   = WSFM_Credentials::has_secret( 'brevo_api_key' );
?>
<div class="wrap wsfm-settings">
	<h1><?php esc_html_e( 'WS Flow Mailer — Instellingen', 'ws-flow-mailer' ); ?></h1>

	<?php if ( isset( $_GET['wsfm-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Instellingen opgeslagen.', 'ws-flow-mailer' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="wsfm_save_settings" />
		<?php wp_nonce_field( WSFM_Admin_Settings::NONCE ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="wsfm_provider"><?php esc_html_e( 'E-mailprovider', 'ws-flow-mailer' ); ?></label>
				</th>
				<td>
					<select name="wsfm_provider" id="wsfm_provider">
						<option value="ses" <?php selected( $settings['provider'], 'ses' ); ?>>Amazon SES</option>
						<option value="brevo" <?php selected( $settings['provider'], 'brevo' ); ?>>Brevo (binnenkort)</option>
					</select>
					<p class="description"><?php esc_html_e( 'De provider waarmee alle flow-e-mails worden verzonden.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
		</table>

		<!-- Amazon SES -->
		<div class="wsfm-provider-fields" data-provider="ses">
			<h2><?php esc_html_e( 'Amazon SES', 'ws-flow-mailer' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wsfm_ses_access_key"><?php esc_html_e( 'Access Key ID', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="text" id="wsfm_ses_access_key" name="wsfm_ses_access_key" class="regular-text" value="" autocomplete="off"
							placeholder="<?php echo esc_attr( $wsfm_access_key_mask ? $wsfm_access_key_mask : 'AKIA…' ); ?>" />
						<?php if ( $wsfm_access_key_mask ) : ?>
							<p class="description"><?php esc_html_e( 'Er is een sleutel opgeslagen. Laat leeg om die te behouden.', 'ws-flow-mailer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wsfm_ses_secret_key"><?php esc_html_e( 'Secret Access Key', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="password" id="wsfm_ses_secret_key" name="wsfm_ses_secret_key" class="regular-text" value="" autocomplete="new-password"
							placeholder="<?php echo $wsfm_secret_key_set ? esc_attr( str_repeat( '•', 12 ) ) : ''; ?>" />
						<?php if ( $wsfm_secret_key_set ) : ?>
							<p class="description"><?php esc_html_e( 'Er is een secret opgeslagen (wordt nooit getoond). Laat leeg om die te behouden.', 'ws-flow-mailer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wsfm_ses_region"><?php esc_html_e( 'Regio', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<select name="wsfm_ses_region" id="wsfm_ses_region">
							<?php foreach ( WSFM_Admin_Settings::ses_regions() as $wsfm_region => $wsfm_label ) : ?>
								<option value="<?php echo esc_attr( $wsfm_region ); ?>" <?php selected( $settings['ses_region'], $wsfm_region ); ?>>
									<?php echo esc_html( $wsfm_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wsfm_ses_from_email"><?php esc_html_e( 'Geverifieerd afzenderadres', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="email" id="wsfm_ses_from_email" name="wsfm_ses_from_email" class="regular-text"
							value="<?php echo esc_attr( $settings['ses_from_email'] ); ?>" placeholder="shop@voorbeeld.nl" />
						<p class="description"><?php esc_html_e( 'Moet als identiteit geverifieerd zijn in Amazon SES.', 'ws-flow-mailer' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wsfm_ses_from_name"><?php esc_html_e( 'Afzendernaam (optioneel)', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="text" id="wsfm_ses_from_name" name="wsfm_ses_from_name" class="regular-text"
							value="<?php echo esc_attr( $settings['ses_from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
					</td>
				</tr>
			</table>
		</div>

		<!-- Brevo -->
		<div class="wsfm-provider-fields" data-provider="brevo">
			<h2><?php esc_html_e( 'Brevo', 'ws-flow-mailer' ); ?></h2>
			<p><em><?php esc_html_e( 'De Brevo-provider wordt in een volgende versie geactiveerd. Je kunt de gegevens alvast opslaan.', 'ws-flow-mailer' ); ?></em></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wsfm_brevo_api_key"><?php esc_html_e( 'API-sleutel', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="password" id="wsfm_brevo_api_key" name="wsfm_brevo_api_key" class="regular-text" value="" autocomplete="new-password"
							placeholder="<?php echo $wsfm_brevo_key_set ? esc_attr( str_repeat( '•', 12 ) ) : ''; ?>" />
						<?php if ( $wsfm_brevo_key_set ) : ?>
							<p class="description"><?php esc_html_e( 'Er is een sleutel opgeslagen. Laat leeg om die te behouden.', 'ws-flow-mailer' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wsfm_brevo_from_email"><?php esc_html_e( 'Afzenderadres', 'ws-flow-mailer' ); ?></label>
					</th>
					<td>
						<input type="email" id="wsfm_brevo_from_email" name="wsfm_brevo_from_email" class="regular-text"
							value="<?php echo esc_attr( $settings['brevo_from_email'] ); ?>" />
					</td>
				</tr>
			</table>
		</div>

		<h2><?php esc_html_e( 'Identity stitching (cookie-herkenning)', 'ws-flow-mailer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bezoekers herkennen', 'ws-flow-mailer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wsfm_identity_stitching" value="1" <?php checked( ! empty( $settings['identity_stitching'] ) ); ?> />
						<?php esc_html_e( 'Herken terugkerende bezoekers via een first-party cookie zodra ze ergens een e-mailadres achterlaten (checkout, account).', 'ws-flow-mailer' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Hiermee vang je ook verlaten winkelwagens van gasten die deze keer geen e-mailadres invullen. Het cookie wordt alléén gezet met marketing-consent: Complianz en CookieYes worden automatisch herkend, andere consent-plugins koppel je via de filter wsfm_has_marketing_consent.', 'ws-flow-mailer' ); ?>
					</p>
					<label style="display:block;margin-top:12px;">
						<input type="checkbox" name="wsfm_identity_assume_consent" value="1" <?php checked( ! empty( $settings['identity_assume_consent'] ) ); ?> />
						<?php esc_html_e( 'Geen consent-plugin actief: ga er toch vanuit dat er consent is.', 'ws-flow-mailer' ); ?>
					</label>
					<p class="description" style="color:#d63638;">
						<?php esc_html_e( '⚠ Let op: dit cookie valt onder marketing/tracking-cookies (AVG/ePrivacy). Zonder consent-plugin ben jij er als site-eigenaar zelf verantwoordelijk voor dat bezoekers hiervoor toestemming hebben gegeven. Alleen aanzetten als je weet wat je doet.', 'ws-flow-mailer' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Gegevensbeheer', 'ws-flow-mailer' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Bij deïnstallatie', 'ws-flow-mailer' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="wsfm_delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?> />
						<?php esc_html_e( 'Verwijder alle flows, wachtrij- en logdata als de plugin wordt verwijderd.', 'ws-flow-mailer' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Standaard uit: je data blijft bewaard, ook na verwijderen van de plugin.', 'ws-flow-mailer' ); ?></p>
				</td>
			</tr>
		</table>

		<p class="submit">
			<?php submit_button( __( 'Instellingen opslaan', 'ws-flow-mailer' ), 'primary', 'submit', false ); ?>
			<button type="button" class="button" id="wsfm-test-connection">
				<?php esc_html_e( 'Test verbinding', 'ws-flow-mailer' ); ?>
			</button>
			<span id="wsfm-test-result" role="status"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'De testknop controleert de opgeslagen credentials en stuurt een testmail naar het admin-emailadres van deze site. Sla je instellingen eerst op.', 'ws-flow-mailer' ); ?>
		</p>
	</form>

	<hr />

	<!-- SNS bounce & complaint webhook -->
	<h2><?php esc_html_e( 'Bounce- en klachtafhandeling (Amazon SNS)', 'ws-flow-mailer' ); ?></h2>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Zonder bounce-afhandeling blijf je mailen naar niet-bestaande adressen en beschadig je je verzendreputatie. Koppel SES daarom eenmalig aan deze webhook — bounces en klachten komen dan automatisch op de suppressielijst.', 'ws-flow-mailer' ); ?>
	</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Webhook-URL', 'ws-flow-mailer' ); ?></th>
			<td>
				<code id="wsfm-webhook-url"><?php echo esc_html( WSFM_SNS_Webhook::webhook_url() ); ?></code>
				<button type="button" class="button button-small" id="wsfm-copy-webhook"><?php esc_html_e( 'Kopieer', 'ws-flow-mailer' ); ?></button>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'ws-flow-mailer' ); ?></th>
			<td>
				<?php if ( ! empty( $sns_status['confirmed'] ) ) : ?>
					<span class="wsfm-badge wsfm-badge-active"><?php esc_html_e( 'Gekoppeld', 'ws-flow-mailer' ); ?></span>
					<span class="description"><?php echo esc_html( sprintf( __( 'Topic: %1$s (bevestigd op %2$s)', 'ws-flow-mailer' ), $sns_status['topic_arn'], $sns_status['time'] ) ); ?></span>
				<?php else : ?>
					<span class="wsfm-badge"><?php esc_html_e( 'Nog niet gekoppeld', 'ws-flow-mailer' ); ?></span>
					<?php if ( get_option( 'wsfm_sns_subscribe_url' ) ) : ?>
						<p class="description"><?php esc_html_e( 'Er is wel een bevestigingsverzoek ontvangen maar automatisch bevestigen lukte niet. Open deze URL eenmalig zelf:', 'ws-flow-mailer' ); ?><br />
						<code style="word-break:break-all;"><?php echo esc_html( get_option( 'wsfm_sns_subscribe_url' ) ); ?></code></p>
					<?php endif; ?>
				<?php endif; ?>
			</td>
		</tr>
	</table>

	<details class="wsfm-sns-help">
		<summary><?php esc_html_e( 'Stap-voor-stap: SES koppelen aan deze webhook (eenmalig, ±10 minuten)', 'ws-flow-mailer' ); ?></summary>
		<ol>
			<li><?php esc_html_e( 'Log in op de AWS-console en open SNS (Simple Notification Service) in dezelfde regio als je SES-instelling hierboven.', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'Maak een topic aan: type "Standard", naam bijv. "ses-bounces". Klik op "Create topic".', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'Klik in het topic op "Create subscription". Kies protocol "HTTPS" en plak bij "Endpoint" de webhook-URL hierboven. Klik op "Create subscription".', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'De plugin bevestigt het abonnement automatisch — herlaad deze pagina en de status hierboven springt op "Gekoppeld".', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'Open nu SES → Configuration sets. Maak een configuration set aan (naam bijv. "wsfm") of gebruik een bestaande.', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'Voeg in die configuration set een "Event destination" toe: type "Amazon SNS", vink de events "Bounce" en "Complaint" aan en kies het topic uit stap 2.', 'ws-flow-mailer' ); ?></li>
			<li><?php esc_html_e( 'Alternatief zonder configuration set: open in SES je geverifieerde identiteit → tabblad "Notifications" → stel voor "Bounce feedback" en "Complaint feedback" het SNS-topic in.', 'ws-flow-mailer' ); ?></li>
		</ol>
	</details>

	<hr />

	<!-- Suppression list management -->
	<h2 id="wsfm-suppression"><?php esc_html_e( 'Suppressielijst', 'ws-flow-mailer' ); ?></h2>
	<p class="description" style="max-width:720px;">
		<?php esc_html_e( 'Adressen op deze lijst ontvangen nooit flow-e-mails. Ze komen hier via bounces, klachten of afmeldingen. Handmatig verwijderen kan, bijv. na een fout-positieve klacht — doe dat alleen als je zeker weet dat het adres bereikbaar is en wíl blijven ontvangen.', 'ws-flow-mailer' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:12px;">
		<input type="hidden" name="action" value="wsfm_suppression_add" />
		<?php wp_nonce_field( 'wsfm_suppression' ); ?>
		<input type="email" name="wsfm_suppress_email" class="regular-text" placeholder="adres@voorbeeld.nl" required />
		<button class="button"><?php esc_html_e( 'Handmatig toevoegen', 'ws-flow-mailer' ); ?></button>
	</form>

	<?php if ( empty( $suppression ) ) : ?>
		<p><?php esc_html_e( 'De suppressielijst is leeg.', 'ws-flow-mailer' ); ?></p>
	<?php else :
		$wsfm_reason_labels = array(
			'bounce'    => __( 'Bounce', 'ws-flow-mailer' ),
			'complaint' => __( 'Klacht', 'ws-flow-mailer' ),
			'manual'    => __( 'Handmatig / afgemeld', 'ws-flow-mailer' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped" style="max-width:720px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'E-mailadres', 'ws-flow-mailer' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Reden', 'ws-flow-mailer' ); ?></th>
					<th style="width:160px;"><?php esc_html_e( 'Toegevoegd', 'ws-flow-mailer' ); ?></th>
					<th style="width:120px;"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $suppression as $wsfm_entry ) : ?>
					<tr>
						<td><?php echo esc_html( $wsfm_entry->email ); ?></td>
						<td><?php echo esc_html( isset( $wsfm_reason_labels[ $wsfm_entry->reason ] ) ? $wsfm_reason_labels[ $wsfm_entry->reason ] : $wsfm_entry->reason ); ?></td>
						<td><?php echo esc_html( $wsfm_entry->created_at ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="wsfm_suppression_remove" />
								<input type="hidden" name="wsfm_suppress_email" value="<?php echo esc_attr( $wsfm_entry->email ); ?>" />
								<?php wp_nonce_field( 'wsfm_suppression' ); ?>
								<button class="button-link-delete"><?php esc_html_e( 'Verwijderen', 'ws-flow-mailer' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
