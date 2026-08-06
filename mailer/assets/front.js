/**
 * WS Flow Mailer front-end: capture the checkout e-mail field on blur so
 * abandoned carts of guests can be recognised (identity stitching).
 * Only loaded when identity stitching is enabled; the server double-checks
 * consent before storing anything.
 */
( function () {
	'use strict';

	if ( typeof window.wsfmFront === 'undefined' ) {
		return;
	}

	var lastSent = '';

	function isValidEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( value );
	}

	function capture( value ) {
		value = ( value || '' ).trim();
		if ( ! isValidEmail( value ) || value === lastSent ) {
			return;
		}
		lastSent = value;

		var body = new URLSearchParams();
		body.append( 'action', 'wsfm_capture_email' );
		body.append( '_ajax_nonce', window.wsfmFront.nonce );
		body.append( 'email', value );

		fetch( window.wsfmFront.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).catch( function () {
			// Silently ignore - tracking must never break checkout.
		} );
	}

	// Delegated blur listener: works for classic checkout (#billing_email),
	// block checkout (email step) and any other e-mail field on the page.
	document.addEventListener(
		'blur',
		function ( event ) {
			var target = event.target;
			if ( target && target.matches && target.matches( 'input[type="email"], #billing_email' ) ) {
				capture( target.value );
			}
		},
		true
	);
} )();
