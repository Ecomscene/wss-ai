/**
 * De inschrijfpopup op de winkel. Zonder jQuery: dit draait op elke pagina van
 * de webshop en hoort niets te kosten.
 *
 * WACHTEN TOT DE PAGINA ER IS
 * Dit script staat in de voettekst, maar WordPress print footer-scripts op
 * wp_footer prioriteit 20 en de popup zelf komt daarna. Het script zocht de
 * popup dus voordat die bestond, vond niets en stopte. De opmaak stond wel in
 * de pagina en er was geen enkele foutmelding: hij bleef alleen onzichtbaar.
 *
 * Vandaar dat alles nu in start() zit, die pas draait als het document klaar
 * is. Daarmee maakt de volgorde waarin dingen worden afgedrukt niet meer uit,
 * en dat is precies wat je wilt bij code die op honderd verschillende thema's
 * moet werken.
 *
 * WANNEER HIJ VERSCHIJNT
 * Na een aantal seconden, of eerder als de bezoeker met zijn muis de pagina uit
 * beweegt. Dat tweede is geen trucje maar het beste moment: iemand die weg wil
 * heeft niets meer om te onderbreken.
 *
 * WANNEER HIJ NIET VERSCHIJNT
 * Als hij al eens weggeklikt is, en als er al ingeschreven is. Dat staat in een
 * koekje met een houdbaarheidsdatum. Een popup die elke pagina terugkomt is de
 * snelste manier om bezoekers kwijt te raken.
 */
( function () {
	'use strict';

	var C = window.wsfmPopup || {};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	function start() {
		var laag = document.querySelector( '.wsfm-popup-laag' );

		if ( ! laag || ! C.url ) {
			return;
		}

		/* ---------------- het lettertype van de winkel ---------------- */

		/**
		 * "Van je website" betekende: erven van body. Dat werkt alleen bij thema's
		 * die hun lettertype op body zetten, en veel thema's doen dat niet: die
		 * zetten het op de koppen en de tekstblokken zelf. Op zo'n site staat op
		 * body nog de standaard van de browser, en dan kreeg je een popup in Times
		 * New Roman terwijl de rest van de winkel er heel anders uitziet.
		 *
		 * Dus kijken we naar een echt stuk tekst op de pagina en nemen we dáár het
		 * lettertype van over. Levert dat niets bruikbaars op, dan een nette
		 * schreefloze reeks: liever iets neutraals dan per ongeluk een schreef.
		 */
		if ( C.erfLettertype ) {
			var bron = document.querySelector(
				'main p, article p, .entry-content p, .elementor-widget-text-editor p, p, li'
			);
			var stapel = bron ? window.getComputedStyle( bron ).fontFamily : '';

			if ( ! stapel || /^["']?(Times|serif)/i.test( stapel ) ) {
				stapel = 'Helvetica, Arial, sans-serif';
			}

			laag.style.setProperty( '--wsfm-font', stapel );
		}

		/* ---------------- het koekje ---------------- */

		function gezien() {
			return document.cookie.indexOf( C.koekje + '=1' ) !== -1;
		}

		function onthoud( dagen ) {
			var tot = new Date();
			tot.setTime( tot.getTime() + dagen * 24 * 60 * 60 * 1000 );
			document.cookie =
				C.koekje + '=1; expires=' + tot.toUTCString() + '; path=/; SameSite=Lax';
		}

		/* ---------------- openen en sluiten ---------------- */

		var geopend = false;
		var vorigeFocus = null;

		function open() {
			if ( geopend || gezien() ) {
				return;
			}
			geopend = true;
			vorigeFocus = document.activeElement;

			laag.hidden = false;
			/* Eén tik wachten voordat de klasse erop gaat, anders slaat de browser de
			   overgang over en klapt hij er ineens in. */
			window.requestAnimationFrame( function () {
				laag.classList.add( 'is-open' );
			} );

			var veld = laag.querySelector( 'input[type="email"]' );
			if ( veld && window.innerWidth > 780 ) {
				/* Op een telefoon niet: dan schiet het toetsenbord omhoog en zie je
				   de helft van de popup niet meer. */
				veld.focus();
			}
		}

		function sluit() {
			laag.classList.remove( 'is-open' );
			laag.hidden = true;
			onthoud( C.dagen || 14 );

			if ( vorigeFocus && vorigeFocus.focus ) {
				vorigeFocus.focus();
			}
		}

		laag.querySelector( '.wsfm-popup-sluit' ).addEventListener( 'click', sluit );

		/* Klikken naast de popup sluit hem ook. Wel controleren dat de klik echt op
		   de laag zelf was: anders sluit een klik op de foto hem ook. */
		laag.addEventListener( 'click', function ( e ) {
			if ( e.target === laag ) {
				sluit();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && geopend ) {
				sluit();
			}
		} );

		/* ---------------- het inschrijven ---------------- */

		var formulier = laag.querySelector( '.wsfm-popup-form' );
		var melding = laag.querySelector( '.wsfm-popup-melding' );
		var vraag = laag.querySelector( '.wsfm-popup-vraag' );
		var gelukt = laag.querySelector( '.wsfm-popup-gelukt' );
		var codevak = laag.querySelector( '.wsfm-popup-code' );

		formulier.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var veld = formulier.querySelector( 'input[type="email"]' );
			var knop = formulier.querySelector( 'button' );
			var adres = ( veld.value || '' ).trim();

			if ( ! adres ) {
				return;
			}

			knop.disabled = true;
			melding.classList.remove( 'is-fout' );
			melding.textContent = C.bezig || '';

			fetch( C.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': C.nonce,
				},
				body: JSON.stringify( { email: adres } ),
			} )
				.then( function ( r ) {
					return r.json().then( function ( data ) {
						return { ok: r.ok, data: data };
					} );
				} )
				.then( function ( res ) {
					knop.disabled = false;

					if ( ! res.data || ! res.data.ok ) {
						melding.classList.add( 'is-fout' );
						melding.textContent =
							( res.data && res.data.melding ) || C.fout || '';
						return;
					}

					/* Gelukt: het koekje meteen zetten, zodat iemand die net zijn adres
					   heeft achtergelaten hem morgen niet opnieuw krijgt. Ruim langer
					   dan de gewone termijn, want deze bezoeker staat nu op de lijst. */
					onthoud( 365 );

					melding.textContent = '';
					vraag.hidden = true;
					gelukt.hidden = false;

					if ( res.data.code ) {
						codevak.textContent = res.data.code;
					} else if ( res.data.melding ) {
						codevak.textContent = '';
						gelukt.querySelector( 'p' ).textContent = res.data.melding;
					}
				} )
				.catch( function () {
					knop.disabled = false;
					melding.classList.add( 'is-fout' );
					melding.textContent = C.fout || '';
				} );
		} );

		/* ---------------- wanneer hij tevoorschijn komt ---------------- */

		if ( gezien() ) {
			return;
		}

		window.setTimeout( open, Math.max( 0, ( C.seconden || 5 ) * 1000 ) );

		/* De muis die bovenlangs het scherm uit gaat. Alleen op een echte muis:
		   op een telefoon bestaat dit gebaar niet en zou het per ongeluk afgaan. */
		if ( window.matchMedia && window.matchMedia( '(pointer: fine)' ).matches ) {
			document.addEventListener( 'mouseout', function ( e ) {
				if ( ! e.relatedTarget && e.clientY <= 0 ) {
					open();
				}
			} );
		}
	}
} )();
