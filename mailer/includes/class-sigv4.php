<?php
/**
 * Minimal AWS Signature Version 4 signer.
 *
 * Only supports what the SES query API needs: a POST to "/" with a
 * form-encoded body and no query string. Deliberately tiny - no AWS SDK.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_SigV4 {

	/**
	 * Build signed request headers for a POST to https://{$host}/.
	 *
	 * @param string $access_key   AWS access key id.
	 * @param string $secret_key   AWS secret access key.
	 * @param string $region       AWS region, e.g. 'eu-west-1'.
	 * @param string $service      Service name in the credential scope, e.g. 'ses'.
	 * @param string $host         Endpoint host, e.g. 'email.eu-west-1.amazonaws.com'.
	 * @param string $body         Raw request body (must be sent byte-identical).
	 * @param string $content_type Content-Type header value.
	 * @return array Headers including Authorization and X-Amz-Date.
	 */
	public static function get_headers( $access_key, $secret_key, $region, $service, $host, $body, $content_type = 'application/x-www-form-urlencoded' ) {
		$amz_date     = gmdate( 'Ymd\THis\Z' );
		$date_stamp   = gmdate( 'Ymd' );
		$payload_hash = hash( 'sha256', $body );

		// Task 1: canonical request. Headers must be lowercase and sorted.
		$canonical_headers = 'content-type:' . $content_type . "\n"
			. 'host:' . $host . "\n"
			. 'x-amz-date:' . $amz_date . "\n";
		$signed_headers    = 'content-type;host;x-amz-date';

		$canonical_request = "POST\n"
			. "/\n"
			. "\n" // Empty canonical query string.
			. $canonical_headers . "\n"
			. $signed_headers . "\n"
			. $payload_hash;

		// Task 2: string to sign.
		$credential_scope = $date_stamp . '/' . $region . '/' . $service . '/aws4_request';
		$string_to_sign   = "AWS4-HMAC-SHA256\n"
			. $amz_date . "\n"
			. $credential_scope . "\n"
			. hash( 'sha256', $canonical_request );

		// Task 3: derive the signing key.
		$k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
		$k_region  = hash_hmac( 'sha256', $region, $k_date, true );
		$k_service = hash_hmac( 'sha256', $service, $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		// Task 4: signature + authorization header.
		$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

		$authorization = 'AWS4-HMAC-SHA256 '
			. 'Credential=' . $access_key . '/' . $credential_scope . ', '
			. 'SignedHeaders=' . $signed_headers . ', '
			. 'Signature=' . $signature;

		return array(
			'Content-Type'  => $content_type,
			'X-Amz-Date'    => $amz_date,
			'Authorization' => $authorization,
		);
	}
}
