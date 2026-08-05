<?php
/**
 * Unsubscribe endpoint.
 *
 * Every outgoing mail carries an {unsubscribe_url} with a non-guessable
 * HMAC token bound to the recipient address. Visiting it adds the address
 * to the suppression list (reason: manual) and shows a plain confirmation
 * page.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Unsubscribe {

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
			'/unsubscribe',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Public by design; the HMAC token is the auth.
				'args'                => array(
					'email' => array( 'required' => true ),
					'token' => array( 'required' => true ),
				),
			)
		);
	}

	/**
	 * HMAC key for tokens, derived from the nonce salt.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'nonce' ) . '|wsfm-unsub-v1', true );
	}

	/**
	 * Deterministic, non-guessable token for an address.
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function token( $email ) {
		return hash_hmac( 'sha256', strtolower( trim( (string) $email ) ), self::key() );
	}

	/**
	 * Full unsubscribe URL for an address.
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function url( $email ) {
		return add_query_arg(
			array(
				'email' => rawurlencode( strtolower( trim( (string) $email ) ) ),
				'token' => self::token( $email ),
			),
			rest_url( self::REST_NAMESPACE . '/unsubscribe' )
		);
	}

	/**
	 * Handle a click on the unsubscribe link. Responds with a minimal
	 * human-readable HTML page rather than JSON.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( $request ) {
		$email = strtolower( trim( rawurldecode( (string) $request->get_param( 'email' ) ) ) );
		$token = (string) $request->get_param( 'token' );

		$valid = is_email( $email ) && hash_equals( self::token( $email ), $token );

		if ( $valid ) {
			WSFM_Suppression::add( $email, 'manual' );
		}

		$title   = $valid ? __( 'Je bent afgemeld', 'ws-flow-mailer' ) : __( 'Ongeldige afmeldlink', 'ws-flow-mailer' );
		$message = $valid
			? sprintf( __( 'Het adres %s ontvangt geen flow-e-mails meer van deze webshop.', 'ws-flow-mailer' ), esc_html( $email ) )
			: __( 'Deze link is niet (meer) geldig. Neem contact op met de webshop als je je wilt afmelden.', 'ws-flow-mailer' );

		header( 'Content-Type: text/html; charset=utf-8' );
		status_header( $valid ? 200 : 400 );
		echo '<!DOCTYPE html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. '<title>' . esc_html( $title ) . '</title>'
			. '<style>body{font-family:Arial,Helvetica,sans-serif;background:#f6f6f6;margin:0;padding:40px 16px;}'
			. '.wsfm-card{max-width:480px;margin:0 auto;background:#ffffff;border-radius:8px;padding:32px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.08);}'
			. 'h1{font-size:22px;color:#222222;} p{color:#555555;line-height:1.6;} a{color:#2271b1;}</style></head><body>'
			. '<div class="wsfm-card"><h1>' . esc_html( $title ) . '</h1><p>' . wp_kses_post( $message ) . '</p>'
			. '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p></div></body></html>';
		exit;
	}
}
