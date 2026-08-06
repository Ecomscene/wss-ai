/**
 * Het beheerscherm van de popup: het voorbeeld beweegt mee terwijl je typt.
 *
 * WAAROM DIT DE MOEITE WAARD IS
 * Kleuren en teksten kiezen zonder te zien wat het wordt, is een kwestie van
 * opslaan, naar de winkel gaan, koekje weggooien, wachten tot hij verschijnt,
 * en dan weer terug. Dat doet niemand vaker dan één keer, en dan blijft de
 * popup staan zoals hij per ongeluk werd.
 *
 * Het voorbeeld is dezelfde HTML als op de winkel, met dezelfde stylesheet.
 * Hier wordt alleen tekst en kleur vervangen, nooit opnieuw opgebouwd.
 */
( function ( $ ) {
	'use strict';

	var $vak = $( '.wsfm-voorbeeldvak' );
	if ( ! $vak.length ) {
		return;
	}

	var T = window.wsfmPopupBeheer || {};
	var $laag = $vak.find( '.wsfm-popup-laag' );

	/* ---------------- tekst ---------------- */

	var doelen = {
		kop: '.wsfm-popup-vraag h2',
		tekst: '.wsfm-popup-vraag > p:first-of-type',
		knop: '.wsfm-popup-form button',
		klein: '.wsfm-popup-klein',
		plaatshouder: '.wsfm-popup-form input[type="email"]',
	};

	$( '.wsfm-live' ).on( 'input', function () {
		var $veld = $( this );
		var kies = doelen[ $veld.data( 'doel' ) ];
		if ( ! kies ) {
			return;
		}

		var $doel = $laag.find( kies );
		var waarde = $veld.val() || '';

		if ( 'placeholder' === $veld.data( 'attr' ) ) {
			$doel.attr( 'placeholder', waarde );
			return;
		}

		/* Leeg betekent weg, niet een lege regel die ruimte blijft innemen. */
		$doel.text( waarde ).toggle( '' !== waarde.trim() );
	} );

	/* ---------------- kleuren en hoeken ---------------- */

	$( '.wsfm-live-kleur' ).on( 'input change', function () {
		$laag[ 0 ].style.setProperty( $( this ).data( 'var' ), $( this ).val() );
	} );

	$( '#wsfm-rond' ).on( 'change', function () {
		$laag[ 0 ].style.setProperty( '--wsfm-rond', $( this ).is( ':checked' ) ? '14px' : '0px' );
	} );

	/* ---------------- de foto ---------------- */

	function zetBeeld( url ) {
		var $beeld = $laag.find( '.wsfm-popup-beeld' );

		if ( ! url ) {
			$beeld.remove();
			return;
		}

		if ( ! $beeld.length ) {
			$beeld = $( '<div class="wsfm-popup-beeld"><img alt=""></div>' );
			$laag.find( '.wsfm-popup' ).append( $beeld );
		}

		$beeld.find( 'img' ).attr( 'src', url );
	}

	$( '#wsfm-popup-beeld-kies' ).on( 'click', function () {
		var kiezer = wp.media( {
			title: T.kiesBeeld || 'Kies een foto',
			library: { type: 'image' },
			button: { text: T.gebruikBeeld || 'Gebruiken' },
			multiple: false,
		} );

		kiezer.on( 'select', function () {
			var beeld = kiezer.state().get( 'selection' ).first().toJSON();
			var url = beeld.sizes && beeld.sizes.medium ? beeld.sizes.medium.url : beeld.url;

			$( '#wsfm-popup-beeld-id' ).val( beeld.id );
			$( '#wsfm-popup-beeld-weg' ).show();
			zetBeeld( url );
		} );

		kiezer.open();
	} );

	$( '#wsfm-popup-beeld-weg' ).on( 'click', function () {
		$( '#wsfm-popup-beeld-id' ).val( 0 );
		$( this ).hide();
		zetBeeld( '' );
	} );

	/* ---------------- computer of telefoon ---------------- */

	$( '.wsfm-breedtes .button' ).on( 'click', function () {
		$( '.wsfm-breedtes .button' ).removeClass( 'is-actief' );
		$( this ).addClass( 'is-actief' );
		$vak.attr( 'data-breed', $( this ).data( 'breed' ) );
	} );

	/* ---------------- het bedankscherm bekijken ---------------- */

	$( '#wsfm-toon-gelukt' ).on( 'click', function () {
		var $vraag = $laag.find( '.wsfm-popup-vraag' );
		var $gelukt = $laag.find( '.wsfm-popup-gelukt' );
		var terug = ! $gelukt.prop( 'hidden' );

		if ( terug ) {
			$gelukt.prop( 'hidden', true );
			$vraag.prop( 'hidden', false );
			$( this ).text( T.toonGelukt || 'Laat het bedankscherm zien' );
			return;
		}

		$gelukt.find( 'h2' ).text( $( 'input[name="gelukt_kop"]' ).val() || '' );
		$gelukt.find( 'p' ).not( '.wsfm-popup-code' ).text( $( 'input[name="gelukt_tekst"]' ).val() || '' );
		$gelukt.find( '.wsfm-popup-code' ).text( ( $( 'input[name="code_voorvoegsel"]' ).val() || 'WELKOM' ) + '-4KP7HQ' );

		$vraag.prop( 'hidden', true );
		$gelukt.prop( 'hidden', false );
		$( this ).text( T.toonFormulier || 'Terug naar het formulier' );
	} );

	/* ---------------- vaste of eigen code ---------------- */

	function codeVelden() {
		var uniek = $( '#wsfm-uniek' ).is( ':checked' );
		$( '.wsfm-alleen-uniek' ).toggle( uniek );
		$( '.wsfm-alleen-vast' ).toggle( ! uniek );
	}

	$( '#wsfm-uniek' ).on( 'change', codeVelden );
	codeVelden();

	/* ---------------- belooft de kop wat de coupon geeft? ---------------- */

	/**
	 * Dit is de duurste fout die je hier kunt maken.
	 *
	 * De kop is losse tekst en de korting is een apart getal. Wie de kop op
	 * "20% korting" zet en het percentage op tien laat staan, belooft zijn
	 * bezoekers iets wat de code niet doet. Dat merk je pas als er iemand boos
	 * mailt, en dan staat het al een maand zo.
	 *
	 * Alleen waarschuwen, niet tegenhouden: "10% korting op alles boven de 50"
	 * is een prima kop waar geen enkel getal in hoeft te kloppen.
	 */
	function controleerBelofte() {
		var $waarschuwing = $( '#wsfm-belofte' );
		var kop = $( 'input[name="kop"]' ).val() || '';
		var soort = $( 'select[name="korting_soort"]' ).val();
		var waarde = parseFloat( $( 'input[name="korting_waarde"]' ).val() || '0' );

		if ( 'percent' !== soort || ! waarde ) {
			$waarschuwing.hide();
			return;
		}

		var genoemd = kop.match( /(\d+(?:[.,]\d+)?)\s*%/ );
		if ( ! genoemd ) {
			$waarschuwing.hide();
			return;
		}

		var uitKop = parseFloat( genoemd[ 1 ].replace( ',', '.' ) );
		if ( uitKop === waarde ) {
			$waarschuwing.hide();
			return;
		}

		$waarschuwing
			.text(
			( T.belofte || 'In je kop staat %1$s%, maar de code geeft %2$s%.' )
				.replace( '%1$s', uitKop )
				.replace( '%2$s', waarde )
			)
			.show();
	}

	$( 'input[name="kop"], select[name="korting_soort"], input[name="korting_waarde"]' ).on(
		'input change',
		controleerBelofte
	);
	controleerBelofte();

	/* ---------------- de mail bekijken en proberen ---------------- */

	function mailGegevens( actie ) {
		return {
			action: actie,
			_ajax_nonce: window.wsfmAdmin.adminNonce,
			mail_onderwerp: $( 'input[name="mail_onderwerp"]' ).val() || '',
			mail_kop: $( 'input[name="mail_kop"]' ).val() || '',
			mail_tekst: $( 'textarea[name="mail_tekst"]' ).val() || '',
			mail_sjabloon: $( 'select[name="mail_sjabloon"]' ).val() || 'rustig',
			korting_soort: $( 'select[name="korting_soort"]' ).val() || 'percent',
			korting_waarde: $( 'input[name="korting_waarde"]' ).val() || '10',
			minimaal: $( 'input[name="minimaal"]' ).val() || '0',
			geldig_dagen: $( 'input[name="geldig_dagen"]' ).val() || '0',
			code_voorvoegsel: $( 'input[name="code_voorvoegsel"]' ).val() || 'WELKOM',
		};
	}

	$( '#wsfm-popup-mail-bekijk' ).on( 'click', function () {
		/* Het venster meteen openen, anders ziet de browser het niet meer als
		   iets wat de gebruiker deed en blokkeert hij het. */
		var venster = window.open( '', 'wsfm-mail', 'width=700,height=900,scrollbars=yes' );

		$.post( window.wsfmAdmin.ajaxUrl, mailGegevens( 'wsfm_preview_popup_mail' ) )
			.done( function ( res ) {
				if ( ! venster ) {
					window.alert( T.popup || '' );
					return;
				}
				if ( ! res || ! res.success ) {
					venster.close();
					window.alert( ( res && res.data && res.data.message ) || T.fout );
					return;
				}
				venster.document.open();
				venster.document.write( res.data.html );
				venster.document.close();
				venster.document.title = res.data.subject;
			} )
			.fail( function () {
				if ( venster ) {
					venster.close();
				}
				window.alert( T.fout || '' );
			} );
	} );

	$( '#wsfm-popup-mail-proef' ).on( 'click', function () {
		var $knop = $( this );
		var $melding = $( '#wsfm-popup-mail-melding' );

		$knop.prop( 'disabled', true );
		$melding.removeClass( 'wsfm-test-error wsfm-test-success' ).addClass( 'wsfm-test-pending' ).text( T.versturen || '' );

		$.post( window.wsfmAdmin.ajaxUrl, mailGegevens( 'wsfm_test_popup_mail' ) )
			.done( function ( res ) {
				$knop.prop( 'disabled', false );
				$melding.removeClass( 'wsfm-test-pending' );

				if ( res && res.success ) {
					$melding.addClass( 'wsfm-test-success' ).text( res.data.message );
				} else {
					$melding.addClass( 'wsfm-test-error' ).text( ( res && res.data && res.data.message ) || T.fout );
				}
			} )
			.fail( function () {
				$knop.prop( 'disabled', false );
				$melding.removeClass( 'wsfm-test-pending' ).addClass( 'wsfm-test-error' ).text( T.fout || '' );
			} );
	} );
} )( jQuery );
