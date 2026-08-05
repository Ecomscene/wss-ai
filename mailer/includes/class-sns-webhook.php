<?php
/**
 * Amazon SNS webhook for SES bounce & complaint notifications.
 *
 * Endpoint: POST /wp-json/wsfm/v1/sns-webhook
 *
 * Every message is verified against the SNS signature (SigningCertURL is
 * restricted to *.amazonaws.com and the RSA signature is checked with
 * openssl) — fail closed. SubscriptionConfirmation messages are confirmed
 * automatically; bounce/complaint notifications land on the suppression
 * list and update the send log.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_SNS_Webhook {

	const REST_NAMESPACE = 'wsfm/v1';

	/**
	 * Register the REST route.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Route registration.
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/sns-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // AWS calls this; auth = signature verification.
			)
		);
	}

	/**
	 * The public webhook URL (shown in the settings UI).
	 *
	 * @return string
	 */
	public static function webhook_url() {
		return rest_url( self::REST_NAMESPACE . '/sns-webhook' );
	}

	/**
	 * Handle an incoming SNS message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$message = json_decode( $request->get_body(), true );

		// Structure check: a real SNS message always carries these.
		if ( ! is_array( $message ) || empty( $message['Type'] ) || empty( $message['MessageId'] ) || empty( $message['TopicArn'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid message structure' ), 400 );
		}

		// Signature check — fail closed.
		if ( ! self::verify_signature( $message ) ) {
			return new WP_REST_Response( array( 'error' => 'signature verification failed' ), 403 );
		}

		switch ( $message['Type'] ) {
			case 'SubscriptionConfirmation':
				return self::handle_subscription_confirmation( $message );

			case 'Notification':
				return self::handle_notification( $message );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Auto-confirm the SNS subscription by fetching the SubscribeURL, and
	 * keep the URL in an option as fallback for manual confirmation.
	 *
	 * @param array $message SNS message.
	 * @return WP_REST_Response
	 */
	private static function handle_subscription_confirmation( array $message ) {
		if ( empty( $message['SubscribeURL'] ) ) {
			return new WP_REST_Response( array( 'error' => 'missing SubscribeURL' ), 400 );
		}

		update_option( 'wsfm_sns_subscribe_url', $message['SubscribeURL'], false );

		$response  = wp_remote_get( $message['SubscribeURL'], array( 'timeout' => 15 ) );
		$confirmed = ! is_wp_error( $response ) && 200 === wp_remote_retrieve_response_code( $response );

		update_option(
			'wsfm_sns_status',
			array(
				'confirmed' => $confirmed,
				'topic_arn' => $message['TopicArn'],
				'time'      => current_time( 'mysql' ),
			),
			false
		);

		return new WP_REST_Response( array( 'confirmed' => $confirmed ), 200 );
	}

	/**
	 * Process a bounce/complaint notification from SES.
	 *
	 * @param array $message SNS message.
	 * @return WP_REST_Response
	 */
	private static function handle_notification( array $message ) {
		$payload = json_decode( isset( $message['Message'] ) ? $message['Message'] : '', true );

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid notification payload' ), 400 );
		}

		// SES event publishing uses eventType; classic notifications use notificationType.
		$type       = strtolower( isset( $payload['eventType'] ) ? $payload['eventType'] : ( isset( $payload['notificationType'] ) ? $payload['notificationType'] : '' ) );
		$message_id = isset( $payload['mail']['messageId'] ) ? $payload['mail']['messageId'] : '';
		$handled    = 0;

		if ( 'bounce' === $type ) {
			$bounce     = isset( $payload['bounce'] ) ? $payload['bounce'] : array();
			$permanent  = isset( $bounce['bounceType'] ) && 'Permanent' === $bounce['bounceType'];
			$recipients = isset( $bounce['bouncedRecipients'] ) ? $bounce['bouncedRecipients'] : array();

			foreach ( $recipients as $recipient ) {
				if ( empty( $recipient['emailAddress'] ) ) {
					continue;
				}
				// Only permanent bounces suppress; transient ones (e.g. full
				// mailbox) may recover.
				if ( $permanent ) {
					WSFM_Suppression::add( $recipient['emailAddress'], 'bounce' );
				}
				self::update_log_status( $message_id, 'bounced' );
				$handled++;
			}
		} elseif ( 'complaint' === $type ) {
			$recipients = isset( $payload['complaint']['complainedRecipients'] ) ? $payload['complaint']['complainedRecipients'] : array();

			foreach ( $recipients as $recipient ) {
				if ( empty( $recipient['emailAddress'] ) ) {
					continue;
				}
				WSFM_Suppression::add( $recipient['emailAddress'], 'complaint' );
				self::update_log_status( $message_id, 'complained' );
				$handled++;
			}
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'handled' => $handled,
			),
			200
		);
	}

	/**
	 * Flip the log entry of a sent mail to bounced/complained.
	 *
	 * @param string $provider_message_id SES message id.
	 * @param string $status              New status.
	 */
	private static function update_log_status( $provider_message_id, $status ) {
		global $wpdb;

		if ( '' === $provider_message_id ) {
			return;
		}

		$wpdb->update(
			$wpdb->prefix . 'wsfm_log',
			array( 'status' => $status ),
			array( 'provider_message_id' => $provider_message_id )
		);
	}

	/**
	 * Verify the SNS message signature per AWS's documented process:
	 * rebuild the canonical string, fetch the signing certificate (host
	 * restricted to sns.*.amazonaws.com) and check the RSA signature.
	 *
	 * @param array $message SNS message.
	 * @return bool
	 */
	public static function verify_signature( array $message ) {
		if ( empty( $message['Signature'] ) || empty( $message['SigningCertURL'] ) || empty( $message['SignatureVersion'] ) ) {
			return false;
		}
		if ( ! function_exists( 'openssl_verify' ) ) {
			return false; // Fail closed — never process unverifiable input.
		}

		$string_to_sign = self::build_string_to_sign( $message );
		if ( '' === $string_to_sign ) {
			return false;
		}

		$certificate = self::fetch_certificate( $message['SigningCertURL'] );
		if ( '' === $certificate ) {
			return false;
		}

		$public_key = openssl_pkey_get_public( $certificate );
		if ( ! $public_key ) {
			return false;
		}

		$algorithm = ( '2' === (string) $message['SignatureVersion'] ) ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;
		$signature = base64_decode( $message['Signature'], true );

		return 1 === openssl_verify( $string_to_sign, (string) $signature, $public_key, $algorithm );
	}

	/**
	 * Canonical string for SNS signature verification. The field list and
	 * order are fixed per message type (AWS documented).
	 *
	 * @param array $message SNS message.
	 * @return string
	 */
	private static function build_string_to_sign( array $message ) {
		if ( 'Notification' === $message['Type'] ) {
			$fields = array( 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' );
		} elseif ( in_array( $message['Type'], array( 'SubscriptionConfirmation', 'UnsubscribeConfirmation' ), true ) ) {
			$fields = array( 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' );
		} else {
			return '';
		}

		$string = '';
		foreach ( $fields as $field ) {
			if ( ! isset( $message[ $field ] ) ) {
				continue; // Subject is optional on notifications.
			}
			$string .= $field . "\n" . $message[ $field ] . "\n";
		}

		return $string;
	}

	/**
	 * Fetch (and cache) the SNS signing certificate. The URL must be
	 * HTTPS on an sns.<region>.amazonaws.com host — anything else is
	 * rejected outright.
	 *
	 * @param string $url SigningCertURL.
	 * @return string PEM certificate or ''.
	 */
	private static function fetch_certificate( $url ) {
		$parts = wp_parse_url( $url );

		if ( ! $parts || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== $parts['scheme'] ) {
			return '';
		}
		if ( ! preg_match( '/^sns\.[a-z0-9\-]+\.amazonaws\.com(\.cn)?$/', $parts['host'] ) ) {
			return '';
		}

		$cache_key = 'wsfm_sns_cert_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$certificate = (string) wp_remote_retrieve_body( $response );
		set_transient( $cache_key, $certificate, DAY_IN_SECONDS );

		return $certificate;
	}
}
