/**
 * De nieuwsbrief-samensteller.
 *
 * Apart van admin.js omdat dit bestand wél jQuery nodig heeft: de mediakiezer
 * van WordPress en de productzoeker van WooCommerce draaien er allebei op. De
 * rest van de beheerschermen blijft daardoor zonder afhankelijkheden.
 *
 * DE NUMMERING VAN DE BLOKKEN
 * Elk blok heeft velden met een naam als blokken[3][tekst]. Bij het toevoegen
 * of verwijderen wordt er niet geprobeerd slim te zijn: alle blokken worden
 * daarna opnieuw doorgenummerd. Gaten in de nummering zou PHP netjes opvangen,
 * maar dan staat er iets in de HTML wat niet klopt met wat je ziet, en dat is
 * precies het soort verschil waar je een uur naar zoekt.
 */
( function ( $ ) {
	'use strict';

	var $blokken = $( '#wsfm-blokken' );
	if ( ! $blokken.length ) {
		return;
	}

	var T = window.wsfmBrief || {};

	/* ---------------- nummering ---------------- */

	function hernummer() {
		$blokken.children( '.wsfm-blok' ).each( function ( index ) {
			$( this )
				.find( '[name]' )
				.each( function () {
					this.name = this.name.replace( /blokken\[[^\]]*\]/, 'blokken[' + index + ']' );
				} );
		} );
	}

	/* ---------------- blokken toevoegen en ordenen ---------------- */

	$( '.wsfm-voeg-toe' ).on( 'click', function () {
		var soort = $( this ).data( 'soort' );
		var bron = $( '#wsfm-bouwstenen [data-bouwsteen="' + soort + '"] .wsfm-blok' );

		if ( ! bron.length ) {
			return;
		}

		var nieuw = bron.clone();
		$blokken.append( nieuw );
		hernummer();

		/* De productzoeker van WooCommerce hangt zichzelf op bij het laden van
		   de pagina. Een keuzelijst die er daarna bij komt moet zeggen dat hij
		   er is, anders blijft het een kaal selectveld. */
		$( document.body ).trigger( 'wc-enhanced-select-init' );

		nieuw.find( 'input[type="text"], textarea' ).first().trigger( 'focus' );
	} );

	$blokken.on( 'click', '.wsfm-weg', function () {
		if ( ! window.confirm( T.wegVragen || 'Dit blok weghalen?' ) ) {
			return;
		}
		$( this ).closest( '.wsfm-blok' ).remove();
		hernummer();
	} );

	$blokken.on( 'click', '.wsfm-omhoog', function () {
		var $blok = $( this ).closest( '.wsfm-blok' );
		$blok.prev( '.wsfm-blok' ).before( $blok );
		hernummer();
	} );

	$blokken.on( 'click', '.wsfm-omlaag', function () {
		var $blok = $( this ).closest( '.wsfm-blok' );
		$blok.next( '.wsfm-blok' ).after( $blok );
		hernummer();
	} );

	/* ---------------- de gekozen sjabloonkaart ---------------- */

	function markeerSjabloon() {
		$( '.wsfm-sjabloon' ).each( function () {
			$( this ).toggleClass( 'is-gekozen', $( this ).find( 'input' ).is( ':checked' ) );
		} );
	}

	$( 'input[name="nieuwsbrief_sjabloon"]' ).on( 'change', markeerSjabloon );
	markeerSjabloon();

	/* ---------------- afbeelding kiezen ---------------- */

	$blokken.on( 'click', '.wsfm-kies-beeld', function () {
		var $vak = $( this ).closest( '.wsfm-beeld-vak' );

		var kiezer = wp.media( {
			title: T.kiesBeeld || 'Kies een afbeelding',
			library: { type: 'image' },
			button: { text: T.gebruikBeeld || 'Gebruiken' },
			multiple: false,
		} );

		kiezer.on( 'select', function () {
			var beeld = kiezer.state().get( 'selection' ).first().toJSON();
			var klein =
				beeld.sizes && beeld.sizes.medium ? beeld.sizes.medium.url : beeld.url;

			$vak.find( '.wsfm-beeld-id' ).val( beeld.id );
			$vak.find( '.wsfm-beeld-voor' ).show().find( 'img' ).attr( 'src', klein );
		} );

		kiezer.open();
	} );

	/* ---------------- ontvangers tellen ---------------- */

	var $doelgroep = $( '#wsfm-doelgroep' );
	var $aantal = $( '#wsfm-aantal' );

	function tel() {
		if ( ! $doelgroep.length ) {
			return;
		}

		$aantal.text( T.tellen || 'Bezig met tellen...' );

		$.post( window.wsfmAdmin.ajaxUrl, {
			action: 'wsfm_count_audience',
			_ajax_nonce: window.wsfmAdmin.adminNonce,
			doelgroep: $doelgroep.val(),
		} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$aantal.text( res.data.tekst );
				} else {
					$aantal.text( T.telFout || 'Het aantal kon niet opgehaald worden.' );
				}
			} )
			.fail( function () {
				$aantal.text( T.telFout || 'Het aantal kon niet opgehaald worden.' );
			} );
	}

	$doelgroep.on( 'change', tel );
	tel();

	/* ---------------- bekijken en proefmail ---------------- */

	/**
	 * De hele samensteller als formuliergegevens.
	 *
	 * Uit het formulier en niet uit wat er is opgeslagen: je wilt zien wat je nu
	 * getypt hebt, niet wat er stond toen je voor het laatst opsloeg.
	 */
	function inhoud() {
		hernummer();

		var gegevens = $( '#wsfm-brief-form' )
			.serializeArray()
			.filter( function ( veld ) {
				return veld.name.indexOf( 'blokken[' ) === 0;
			} );

		gegevens.push( { name: 'onderwerp', value: $( '#wsfm-onderwerp' ).val() || '' } );
		gegevens.push( {
			name: 'sjabloon',
			value: $( 'input[name="nieuwsbrief_sjabloon"]:checked' ).val() || 'rustig',
		} );
		gegevens.push( { name: '_ajax_nonce', value: window.wsfmAdmin.adminNonce } );

		return gegevens;
	}

	$( '#wsfm-bekijk' ).on( 'click', function () {
		var $knop = $( this );
		var oud = $knop.text();

		$knop.prop( 'disabled', true ).text( T.bezig || 'Momentje...' );

		/* Het venster nu openen en niet in de .done(). Een popup die pas na een
		   serverantwoord opengaat, telt bij de browser niet meer als iets wat de
		   gebruiker deed, en wordt geblokkeerd. */
		var venster = window.open( '', 'wsfm-voorbeeld', 'width=700,height=900,scrollbars=yes' );

		$.post(
			window.wsfmAdmin.ajaxUrl,
			inhoud().concat( [ { name: 'action', value: 'wsfm_preview_newsletter' } ] )
		)
			.done( function ( res ) {
				$knop.prop( 'disabled', false ).text( oud );

				if ( ! venster ) {
					window.alert( T.popup || 'Je browser blokkeerde het voorbeeldvenster.' );
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
				$knop.prop( 'disabled', false ).text( oud );
				if ( venster ) {
					venster.close();
				}
				window.alert( T.fout || 'Er ging iets mis.' );
			} );
	} );

	$( '#wsfm-proef' ).on( 'click', function () {
		var $knop = $( this );
		var $melding = $( '#wsfm-proef-melding' );

		$knop.prop( 'disabled', true );
		$melding.removeClass( 'wsfm-test-error wsfm-test-success' ).addClass( 'wsfm-test-pending' ).text( T.versturen || 'Bezig met versturen...' );

		$.post(
			window.wsfmAdmin.ajaxUrl,
			inhoud().concat( [
				{ name: 'action', value: 'wsfm_test_newsletter' },
				{ name: 'naar', value: $( '#wsfm-testadres' ).val() || '' },
			] )
		)
			.done( function ( res ) {
				$knop.prop( 'disabled', false );
				$melding.removeClass( 'wsfm-test-pending' );

				if ( res && res.success ) {
					$melding.addClass( 'wsfm-test-success' ).text( res.data.message );
				} else {
					$melding
						.addClass( 'wsfm-test-error' )
						.text( ( res && res.data && res.data.message ) || T.fout );
				}
			} )
			.fail( function () {
				$knop.prop( 'disabled', false );
				$melding.removeClass( 'wsfm-test-pending' ).addClass( 'wsfm-test-error' ).text( T.fout );
			} );
	} );

	/* ---------------- de laatste drempel ---------------- */

	$( '#wsfm-verstuur-form' ).on( 'submit', function ( e ) {
		var wat = $aantal.text();
		var vraag = ( T.zekerWeten || 'Versturen naar %s? Dit kan niet ongedaan gemaakt worden.' ).replace( '%s', wat );

		if ( ! window.confirm( vraag ) ) {
			e.preventDefault();
			return;
		}

		$( '#wsfm-verstuur' ).prop( 'disabled', true ).text( T.versturen || 'Bezig met versturen...' );
	} );

	$( '.wsfm-weggooi' ).on( 'submit', function ( e ) {
		if ( ! window.confirm( T.weggooiVragen || 'Deze nieuwsbrief weggooien?' ) ) {
			e.preventDefault();
		}
	} );
} )( jQuery );
