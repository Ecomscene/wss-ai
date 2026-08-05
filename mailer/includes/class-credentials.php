<?php
/**
 * Encrypted credential storage.
 *
 * Secrets are encrypted with AES-256-CBC (encrypt-then-MAC) using keys
 * derived from the WordPress salts, so nothing is ever stored in plaintext.
 * Values are only decrypted at runtime when a provider actually sends;
 * the admin UI only ever sees a mask.
 *
 * Note: because the keys derive from wp_salt(), rotating the salts in
 * wp-config.php (or moving the database to another install) invalidates
 * stored secrets — the user then simply re-enters them on the settings page.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Credentials {

	const OPTION_SETTINGS = 'wsfm_settings'; // Non-secret settings (provider, region, from address...).
	const OPTION_SECRETS  = 'wsfm_secrets';  // Encrypted secrets + display masks.

	const CIPHER = 'aes-256-cbc';

	/**
	 * Derive the 256-bit encryption key from the auth salt.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function encryption_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|wsfm-enc-v1', true );
	}

	/**
	 * Derive a separate key for the integrity HMAC.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function mac_key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|wsfm-mac-v1', true );
	}

	/**
	 * Encrypt a secret. Returns base64( iv | hmac | ciphertext ).
	 *
	 * @param string $plaintext Secret value.
	 * @return string|false Encrypted blob, or false on failure/empty input.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === (string) $plaintext ) {
			return false;
		}

		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, self::encryption_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return false;
		}

		$hmac = hash_hmac( 'sha256', $iv . $ciphertext, self::mac_key(), true );

		return base64_encode( $iv . $hmac . $ciphertext );
	}

	/**
	 * Decrypt a stored secret. Verifies the HMAC before decrypting.
	 *
	 * @param string $blob Output of encrypt().
	 * @return string|false Plaintext, or false when missing/tampered/invalid key.
	 */
	public static function decrypt( $blob ) {
		if ( '' === (string) $blob ) {
			return false;
		}

		$raw = base64_decode( $blob, true );
		if ( false === $raw || strlen( $raw ) <= 48 ) {
			return false;
		}

		$iv         = substr( $raw, 0, 16 );
		$hmac       = substr( $raw, 16, 32 );
		$ciphertext = substr( $raw, 48 );

		$expected = hash_hmac( 'sha256', $iv . $ciphertext, self::mac_key(), true );
		if ( ! hash_equals( $expected, $hmac ) ) {
			return false;
		}

		return openssl_decrypt( $ciphertext, self::CIPHER, self::encryption_key(), OPENSSL_RAW_DATA, $iv );
	}

	/**
	 * Build a display mask like "AKIA••••••••1234". Never reveals the middle.
	 *
	 * @param string $value Plaintext secret.
	 * @param bool   $show_edges Whether to show first/last 4 characters (safe
	 *                           for key IDs, not for secret keys).
	 * @return string
	 */
	public static function mask( $value, $show_edges = true ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return '';
		}
		if ( ! $show_edges || strlen( $value ) < 12 ) {
			return str_repeat( "\u{2022}", 12 );
		}
		return substr( $value, 0, 4 ) . str_repeat( "\u{2022}", 8 ) . substr( $value, -4 );
	}

	/**
	 * Get all non-secret settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'provider'         => 'ses',
			'ses_region'       => 'eu-west-1',
			'ses_from_email'   => '',
			'ses_from_name'    => '',
			'brevo_from_email' => '',
			'delete_on_uninstall' => 0,
		);
		return wp_parse_args( get_option( self::OPTION_SETTINGS, array() ), $defaults );
	}

	/**
	 * Persist non-secret settings.
	 *
	 * @param array $settings Settings to merge in.
	 */
	public static function save_settings( array $settings ) {
		update_option( self::OPTION_SETTINGS, array_merge( self::get_settings(), $settings ), false );
	}

	/**
	 * Store a secret (encrypted) together with its display mask.
	 * Passing an empty value keeps the existing secret untouched.
	 *
	 * @param string $key        Secret key name, e.g. 'ses_access_key'.
	 * @param string $plaintext  New plaintext value ('' = keep current).
	 * @param bool   $show_edges Whether the mask may show first/last 4 chars.
	 */
	public static function save_secret( $key, $plaintext, $show_edges = true ) {
		if ( '' === (string) $plaintext ) {
			return;
		}

		$secrets         = get_option( self::OPTION_SECRETS, array() );
		$secrets[ $key ] = array(
			'value' => self::encrypt( $plaintext ),
			'mask'  => self::mask( $plaintext, $show_edges ),
		);
		update_option( self::OPTION_SECRETS, $secrets, false );
	}

	/**
	 * Get the decrypted value of a secret. Runtime use only — never echo this.
	 *
	 * @param string $key Secret key name.
	 * @return string Plaintext, or '' when not set / not decryptable.
	 */
	public static function get_secret( $key ) {
		$secrets = get_option( self::OPTION_SECRETS, array() );
		if ( empty( $secrets[ $key ]['value'] ) ) {
			return '';
		}
		$plain = self::decrypt( $secrets[ $key ]['value'] );
		return ( false === $plain ) ? '' : $plain;
	}

	/**
	 * Get the display mask for a secret (safe for the admin UI).
	 *
	 * @param string $key Secret key name.
	 * @return string Mask, or '' when the secret is not set.
	 */
	public static function get_mask( $key ) {
		$secrets = get_option( self::OPTION_SECRETS, array() );
		return isset( $secrets[ $key ]['mask'] ) ? (string) $secrets[ $key ]['mask'] : '';
	}

	/**
	 * Whether a secret has been stored.
	 *
	 * @param string $key Secret key name.
	 * @return bool
	 */
	public static function has_secret( $key ) {
		$secrets = get_option( self::OPTION_SECRETS, array() );
		return ! empty( $secrets[ $key ]['value'] );
	}
}
