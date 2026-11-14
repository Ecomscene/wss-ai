/**
 * WS Flow Mailer front-end: capture the checkout e-mail field so abandoned
 * carts of guests can be recognised (identity stitching).
 * Only loaded when identity stitching is enabled; the server double-checks
 * consent before storing anything.
 *
 * Captured on blur and on change, and once more when the page is hidden
 * (tab closed, app switched): a guest who types the address as the very
 * last thing and leaves straight away never blurs the field, so without
 * that last moment his cart was never tracked.
 */
( function () {
	'use strict';

	if ( typeof window.wsfmFront === 'undefined' ) {
		return;
	}

	var lastSent = '';
	var SELECTOR = 'input[type="email"], #billing_email';

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

		try {
			fetch( window.wsfmFront.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true, // survives the page being closed.
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} ).catch( function () {
				// Silently ignore - tracking must never break checkout.
			} );
		} catch ( e ) {
			// Never break checkout.
		}
	}

	function onField( event ) {
		var target = event.target;
		if ( target && target.matches && target.matches( SELECTOR ) ) {
			capture( target.value );
		}
	}

	function captureVisibleFields() {
		var fields = document.querySelectorAll( SELECTOR );
		for ( var i = 0; i < fields.length; i++ ) {
			if ( isValidEmail( ( fields[ i ].value || '' ).trim() ) ) {
				capture( fields[ i ].value );
				return;
			}
		}
	}

	// Delegated listeners: classic checkout (#billing_email), block checkout
	// (email step) and any other e-mail field on the page.
	document.addEventListener( 'blur', onField, true );
	document.addEventListener( 'change', onField, true );

	// Last chance when the visitor leaves.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) {
			captureVisibleFields();
		}
	} );
	window.addEventListener( 'pagehide', captureVisibleFields );
} )();
