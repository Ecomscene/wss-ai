<?php
/**
 * Brevo provider - placeholder.
 *
 * The settings UI already stores the API key + from address so switching
 * later is seamless; the actual API calls land in a future version.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Provider_Brevo implements WSFM_Mail_Provider {

	/** @var string */
	private $api_key;

	/** @var string */
	private $from_email;

	/** @var string */
	private $last_error = '';

	/**
	 * @param string $api_key    Brevo API key.
	 * @param string $from_email Sender address.
	 */
	public function __construct( $api_key, $from_email ) {
		$this->api_key    = $api_key;
		$this->from_email = $from_email;
	}

	/**
	 * Not implemented yet.
	 *
	 * @param string $to         Recipient.
	 * @param string $subject    Subject.
	 * @param string $html_body  HTML body.
	 * @param array  $merge_data Merge data.
	 * @return WSFM_Send_Result
	 */
	public function send( $to, $subject, $html_body, array $merge_data ) {
		$this->last_error = __( 'De Brevo-provider is nog niet beschikbaar in deze versie.', 'ws-flow-mailer' );
		return new WSFM_Send_Result( false, '', $this->last_error );
	}

	/**
	 * Not implemented yet.
	 *
	 * @return bool
	 */
	public function test_connection() {
		$this->last_error = __( 'De Brevo-provider is nog niet beschikbaar in deze versie.', 'ws-flow-mailer' );
		return false;
	}

	/**
	 * Last error for display on the settings page.
	 *
	 * @return string
	 */
	public function get_last_error() {
		return $this->last_error;
	}
}
