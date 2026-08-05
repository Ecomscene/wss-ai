<?php
/**
 * Identity stitching: recognise returning visitors who once left an
 * e-mail address, via a first-party cookie mapped to that address.
 *
 * Privacy: OFF by default. The cookie is only set when the visitor has
 * given marketing/tracking consent. Consent detection integrates with
 * Complianz and CookieYes out of the box and is filterable through
 * `wsfm_has_marketing_consent` for any other consent plugin.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Identity {

	const COOKIE = 'wsfm_visitor_id';

	/**
	 * Hook registration. Only active when the setting is enabled.
	 */
	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_capture_script' ) );
		add_action( 'wp_ajax_wsfm_capture_email', array( __CLASS__, 'ajax_capture_email' ) );
		add_action( 'wp_ajax_nopriv_wsfm_capture_email', array( __CLASS__, 'ajax_capture_email' ) );

		// Account creation and completed checkouts are e-mail moments too.
		add_action( 'user_register', array( __CLASS__, 'capture_registered_user' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'capture_checkout_order' ), 10, 3 );
	}

	/**
	 * Whether identity stitching is switched on in the settings.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = WSFM_Credentials::get_settings();
		return ! empty( $settings['identity_stitching'] );
	}

	/**
	 * Whether the current visitor gave marketing/tracking consent.
	 *
	 * Detection order: Complianz → CookieYes → the explicit "assume
	 * consent" site setting (default off). The result is filterable via
	 * `wsfm_has_marketing_consent` for any other consent setup.
	 *
	 * @return bool
	 */
	public static function has_marketing_consent() {
		$consent = null;

		if ( function_exists( 'cmplz_has_consent' ) ) {
			// Complianz.
			$consent = (bool) cmplz_has_consent( 'marketing' );
		} elseif ( isset( $_COOKIE['cookieyes-consent'] ) ) {
			// CookieYes stores "...,advertisement:yes,..." in its cookie.
			$consent = ( false !== strpos( sanitize_text_field( wp_unslash( $_COOKIE['cookieyes-consent'] ) ), 'advertisement:yes' ) );
		}

		if ( null === $consent ) {
			// No known consent plugin: only the explicit site setting
			// (with its AVG warning in the UI) can switch this on.
			$settings = WSFM_Credentials::get_settings();
			$consent  = ! empty( $settings['identity_assume_consent'] );
		}

		return (bool) apply_filters( 'wsfm_has_marketing_consent', $consent );
	}

	/**
	 * Current visitor id from the cookie, if any.
	 *
	 * @return string
	 */
	public static function get_visitor_id() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return '';
		}
		$visitor_id = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
		return preg_match( '/^[a-f0-9]{48}$/', $visitor_id ) ? $visitor_id : '';
	}

	/**
	 * Get or create the visitor cookie. Only call in a request phase where
	 * headers are not sent yet (AJAX, checkout hooks). Consent required.
	 *
	 * @return string Visitor id or '' when not allowed/possible.
	 */
	public static function ensure_cookie() {
		if ( ! self::has_marketing_consent() ) {
			return '';
		}

		$existing = self::get_visitor_id();
		if ( $existing ) {
			return $existing;
		}
		if ( headers_sent() ) {
			return '';
		}

		$visitor_id = bin2hex( random_bytes( 24 ) ); // 48 hex chars, non-guessable.

		setcookie(
			self::COOKIE,
			$visitor_id,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $visitor_id;

		return $visitor_id;
	}

	/**
	 * Store/update the visitor → e-mail mapping.
	 *
	 * @param string $email E-mail address.
	 * @return bool
	 */
	public static function map_email( $email ) {
		global $wpdb;

		$email      = strtolower( trim( (string) $email ) );
		$visitor_id = self::ensure_cookie();

		if ( '' === $visitor_id || ! is_email( $email ) ) {
			return false;
		}

		$table = $wpdb->prefix . 'wsfm_identity_map';
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE visitor_id = %s", $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $row ) {
			$wpdb->update(
				$table,
				array(
					'email'     => $email,
					'last_seen' => $now,
				),
				array( 'id' => $row->id )
			);
			return true;
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'visitor_id' => $visitor_id,
				'email'      => $email,
				'first_seen' => $now,
				'last_seen'  => $now,
			)
		);
	}

	/**
	 * Resolve the current visitor's known e-mail via the cookie.
	 *
	 * @return object|null Identity row (->email) or null.
	 */
	public static function resolve_from_cookie() {
		global $wpdb;

		if ( ! self::enabled() ) {
			return null;
		}

		$visitor_id = self::get_visitor_id();
		if ( '' === $visitor_id ) {
			return null;
		}

		$table = $wpdb->prefix . 'wsfm_identity_map';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE visitor_id = %s", $visitor_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Front-end capture script on cart/checkout pages.
	 */
	public static function enqueue_capture_script() {
		if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_cart() ) ) {
			return;
		}

		wp_enqueue_script( 'wsfm-front', WSFM_PLUGIN_URL . 'assets/front.js', array(), WSFM_VERSION, true );
		wp_localize_script(
			'wsfm-front',
			'wsfmFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wsfm_capture_email' ),
			)
		);
	}

	/**
	 * AJAX: an e-mail field on checkout was filled in (on blur).
	 */
	public static function ajax_capture_email() {
		check_ajax_referer( 'wsfm_capture_email' );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! is_email( $email ) || ! self::has_marketing_consent() ) {
			wp_send_json_success( array( 'stored' => false ) );
		}

		self::map_email( $email );

		// Also update the WooCommerce session so cart tracking can use the
		// address immediately.
		if ( function_exists( 'WC' ) && WC()->customer && ! WC()->customer->get_billing_email() ) {
			WC()->customer->set_billing_email( $email );
			WC()->customer->save();
		}

		wp_send_json_success( array( 'stored' => true ) );
	}

	/**
	 * New account registered → map the address.
	 *
	 * @param int $user_id User id.
	 */
	public static function capture_registered_user( $user_id ) {
		$user = get_userdata( $user_id );
		if ( $user && $user->user_email ) {
			self::map_email( $user->user_email );
		}
	}

	/**
	 * Checkout processed → map the billing address.
	 *
	 * @param int      $order_id Order id.
	 * @param array    $posted   Posted data.
	 * @param WC_Order $order    Order.
	 */
	public static function capture_checkout_order( $order_id, $posted, $order ) {
		if ( $order && $order->get_billing_email() ) {
			self::map_email( $order->get_billing_email() );
		}
	}
}

/**
 * Template/integration helper: does the current visitor have marketing
 * consent? Filterable via `wsfm_has_marketing_consent` so site owners can
 * wire in any consent plugin.
 *
 * @return bool
 */
function wsfm_has_marketing_consent() {
	return WSFM_Identity::has_marketing_consent();
}
