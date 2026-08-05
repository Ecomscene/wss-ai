<?php
/**
 * Amazon SES provider.
 *
 * Talks directly to the SES query API (email.{region}.amazonaws.com) with
 * our own SigV4 signer — no AWS SDK dependency.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Provider_SES implements WSFM_Mail_Provider {

	const API_VERSION = '2010-12-01';

	/** @var string */
	private $access_key;

	/** @var string */
	private $secret_key;

	/** @var string */
	private $region;

	/** @var string */
	private $from_email;

	/** @var string */
	private $from_name;

	/** @var string Last error, for the settings UI. */
	private $last_error = '';

	/**
	 * @param string $access_key AWS access key id.
	 * @param string $secret_key AWS secret access key.
	 * @param string $region     AWS region.
	 * @param string $from_email Verified sender address.
	 * @param string $from_name  Optional sender display name.
	 */
	public function __construct( $access_key, $secret_key, $region, $from_email, $from_name = '' ) {
		$this->access_key = $access_key;
		$this->secret_key = $secret_key;
		$this->region     = sanitize_key( $region );
		$this->from_email = $from_email;
		$this->from_name  = $from_name;
	}

	/**
	 * SES endpoint host for the configured region.
	 *
	 * @return string
	 */
	private function host() {
		return 'email.' . $this->region . '.amazonaws.com';
	}

	/**
	 * Execute a signed SES query API call.
	 *
	 * @param array $params Action parameters incl. 'Action'.
	 * @return array|WP_Error Array with 'code' and 'body' on HTTP success.
	 */
	private function request( array $params ) {
		$params['Version'] = self::API_VERSION;
		$body              = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );

		$headers = WSFM_SigV4::get_headers(
			$this->access_key,
			$this->secret_key,
			$this->region,
			'ses',
			$this->host(),
			$body
		);

		$response = wp_remote_post(
			'https://' . $this->host() . '/',
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => 20,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'code' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Extract a tag value from an SES XML response without full XML parsing
	 * dependencies. SES responses are flat and trusted enough for this.
	 *
	 * @param string $xml XML body.
	 * @param string $tag Tag name.
	 * @return string
	 */
	private function xml_value( $xml, $tag ) {
		if ( preg_match( '#<' . preg_quote( $tag, '#' ) . '>(.*?)</' . preg_quote( $tag, '#' ) . '>#s', $xml, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Send a single e-mail via the SendEmail action.
	 *
	 * @param string $to         Recipient.
	 * @param string $subject    Subject line.
	 * @param string $html_body  HTML body.
	 * @param array  $merge_data Unused by SES (rendering happens upstream).
	 * @return WSFM_Send_Result
	 */
	public function send( $to, $subject, $html_body, array $merge_data ) {
		$source = $this->from_name
			? sprintf( '%s <%s>', $this->from_name, $this->from_email )
			: $this->from_email;

		$params = array(
			'Action'                           => 'SendEmail',
			'Source'                           => $source,
			'Destination.ToAddresses.member.1' => $to,
			'Message.Subject.Data'             => $subject,
			'Message.Subject.Charset'          => 'UTF-8',
			'Message.Body.Html.Data'           => $html_body,
			'Message.Body.Html.Charset'        => 'UTF-8',
			// Plain-text alternative improves deliverability.
			'Message.Body.Text.Data'           => wp_strip_all_tags( $html_body ),
			'Message.Body.Text.Charset'        => 'UTF-8',
		);

		$response = $this->request( $params );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return new WSFM_Send_Result( false, '', $this->last_error );
		}

		if ( 200 === $response['code'] ) {
			$message_id = $this->xml_value( $response['body'], 'MessageId' );
			return new WSFM_Send_Result( true, $message_id );
		}

		$this->last_error = $this->format_error( $response );
		return new WSFM_Send_Result( false, '', $this->last_error );
	}

	/**
	 * Validate credentials via GetSendQuota — no e-mail is sent.
	 *
	 * @return bool
	 */
	public function test_connection() {
		$response = $this->request( array( 'Action' => 'GetSendQuota' ) );

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		if ( 200 === $response['code'] ) {
			return true;
		}

		$this->last_error = $this->format_error( $response );
		return false;
	}

	/**
	 * Human-readable error out of an SES error response.
	 *
	 * @param array $response Array with 'code' and 'body'.
	 * @return string
	 */
	private function format_error( array $response ) {
		$code    = $this->xml_value( $response['body'], 'Code' );
		$message = $this->xml_value( $response['body'], 'Message' );

		if ( $code || $message ) {
			return trim( $code . ': ' . $message, ': ' ) . ' (HTTP ' . $response['code'] . ')';
		}

		return sprintf( __( 'Onverwacht antwoord van Amazon SES (HTTP %d).', 'ws-flow-mailer' ), $response['code'] );
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
