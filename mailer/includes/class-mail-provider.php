<?php
/**
 * Mail provider abstraction: interface, send result value object and a
 * small factory that builds the configured provider from stored settings.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Result of a single send attempt.
 */
class WSFM_Send_Result {

	/** @var bool */
	public $success;

	/** @var string Provider message id (when sent). */
	public $message_id;

	/** @var string Human-readable error (when failed). */
	public $error;

	/**
	 * @param bool   $success    Whether the mail was accepted by the provider.
	 * @param string $message_id Provider message id.
	 * @param string $error      Error description on failure.
	 */
	public function __construct( $success, $message_id = '', $error = '' ) {
		$this->success    = (bool) $success;
		$this->message_id = (string) $message_id;
		$this->error      = (string) $error;
	}
}

/**
 * Contract every mail provider must implement.
 */
interface WSFM_Mail_Provider {

	/**
	 * Send a single transactional e-mail.
	 *
	 * @param string $to         Recipient e-mail address.
	 * @param string $subject    Rendered subject line.
	 * @param string $html_body  Rendered HTML body.
	 * @param array  $merge_data Merge data used for the render (for providers
	 *                           that support native templating/metadata).
	 * @return WSFM_Send_Result
	 */
	public function send( $to, $subject, $html_body, array $merge_data );

	/**
	 * Verify the stored credentials without sending a real flow e-mail.
	 *
	 * @return bool
	 */
	public function test_connection();
}

/**
 * Builds the active provider from stored settings + decrypted secrets.
 */
class WSFM_Provider_Factory {

	/**
	 * Create a provider instance.
	 *
	 * @param string|null $provider Provider slug, defaults to the configured one.
	 * @return WSFM_Mail_Provider|WP_Error
	 */
	public static function create( $provider = null ) {
		$settings = WSFM_Credentials::get_settings();
		$provider = $provider ? $provider : $settings['provider'];

		switch ( $provider ) {
			case 'ses':
				$access_key = WSFM_Credentials::get_secret( 'ses_access_key' );
				$secret_key = WSFM_Credentials::get_secret( 'ses_secret_key' );

				if ( '' === $access_key || '' === $secret_key ) {
					return new WP_Error( 'wsfm_missing_credentials', __( 'Amazon SES credentials zijn nog niet (volledig) ingesteld.', 'ws-flow-mailer' ) );
				}
				if ( '' === $settings['ses_from_email'] ) {
					return new WP_Error( 'wsfm_missing_from', __( 'Er is nog geen geverifieerd afzenderadres ingesteld.', 'ws-flow-mailer' ) );
				}

				return new WSFM_Provider_SES(
					$access_key,
					$secret_key,
					$settings['ses_region'],
					$settings['ses_from_email'],
					$settings['ses_from_name']
				);

			case 'brevo':
				$api_key = WSFM_Credentials::get_secret( 'brevo_api_key' );
				return new WSFM_Provider_Brevo( $api_key, $settings['brevo_from_email'] );
		}

		return new WP_Error( 'wsfm_unknown_provider', __( 'Onbekende e-mailprovider.', 'ws-flow-mailer' ) );
	}
}
