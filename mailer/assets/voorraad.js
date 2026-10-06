/**
 * Het "laat het me weten"-vakje op een uitverkocht product.
 *
 * WAAROM HIER JAVASCRIPT VOOR NODIG IS
 * Bij een gewoon product niet: dat vakje staat er of staat er niet, en een
 * gewone formulierverzending zou volstaan. Bij een VARIABEL product wel. Welke
 * maat uitverkocht is, weet je pas als de bezoeker er een kiest, en dat gebeurt
 * in de browser. Zonder dit zou er op een jurk met vijf maten waarvan er een
 * weg is helemaal geen vakje staan (het product is immers "op voorraad"), of bij
 * elke maat een vakje dat ook bij de maten verschijnt die gewoon te koop zijn.
 *
 * Het verzenden gaat ook via JavaScript, zodat de bezoeker niet van de
 * productpagina af hoeft. Wie net besloot dat hij dit product wil, moet je niet
 * naar een andere pagina sturen.
 */
( function () {
	'use strict';

	var inst = window.wsfmVoorraad || {};

	/**
	 * Alle vakjes op deze pagina.
	 *
	 * @return {Array} Elementen.
	 */
	function vakken() {
		return Array.prototype.slice.call( document.querySelectorAll( '.wsfm-voorraad' ) );
	}

	/**
	 * Een boodschap onder het vakje zetten.
	 *
	 * @param {Element} vak   Het vakje.
	 * @param {string}  tekst Wat er moet staan.
	 * @param {string}  soort gelukt, fout of leeg.
	 */
	function melding( vak, tekst, soort ) {
		var p = vak.querySelector( '.wsfm-voorraad-melding' );

		if ( ! p ) {
			return;
		}

		p.textContent = tekst || '';
		p.className = 'wsfm-voorraad-melding' + ( soort ? ' is-' + soort : '' );
	}

	/**
	 * De aanmelding versturen.
	 *
	 * @param {Element} vak Het vakje.
	 */
	function verstuur( vak ) {
		var email = vak.querySelector( '.wsfm-voorraad-email' );
		var knop = vak.querySelector( '.wsfm-voorraad-knop' );
		var variatie = vak.querySelector( '.wsfm-voorraad-variatie' );
		var nieuwsbrief = vak.querySelector( '.wsfm-voorraad-nieuwsbrief' );

		if ( ! email || ! email.value || ! inst.url ) {
			return;
		}

		if ( knop ) {
			knop.disabled = true;
		}
		melding( vak, inst.bezig, '' );

		window.fetch( inst.url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': inst.nonce
			},
			body: JSON.stringify( {
				email: email.value,
				product_id: vak.getAttribute( 'data-product' ),
				variation_id: variatie ? variatie.value : 0,
				nieuwsbrief: ( nieuwsbrief && nieuwsbrief.checked ) ? 1 : 0
			} )
		} ).then( function ( antwoord ) {
			return antwoord.json().catch( function () {
				return {};
			} );
		} ).then( function ( uit ) {
			if ( knop ) {
				knop.disabled = false;
			}

			if ( uit && uit.ok ) {
				/* Het formulier weg en de bevestiging ervoor in de plaats. Blijft
				   het staan, dan drukt de helft van de mensen nog een keer omdat
				   ze niet zeker weten of het gelukt is. */
				var formulier = vak.querySelector( '.wsfm-voorraad-form' );
				if ( formulier ) {
					formulier.setAttribute( 'hidden', 'hidden' );
				}
				melding( vak, uit.melding || inst.gelukt, 'gelukt' );
				return;
			}

			melding( vak, ( uit && uit.melding ) || inst.fout, 'fout' );
		} ).catch( function () {
			if ( knop ) {
				knop.disabled = false;
			}
			melding( vak, inst.fout, 'fout' );
		} );
	}

	/**
	 * Het vakje aan- of uitzetten voor de gekozen variatie.
	 *
	 * @param {number}  variatie_id Variatie, of 0 als er niets gekozen is.
	 * @param {boolean} toon        Of het vakje zichtbaar moet zijn.
	 */
	function zetVariatie( variatie_id, toon ) {
		vakken().forEach( function ( vak ) {
			if ( '1' !== vak.getAttribute( 'data-variabel' ) ) {
				return;
			}

			var veld = vak.querySelector( '.wsfm-voorraad-variatie' );
			if ( veld ) {
				veld.value = variatie_id || 0;
			}

			/* Een andere maat kiezen is een andere vraag. Dus het formulier weer
			   tevoorschijn en de vorige boodschap weg, anders staat er "gelukt"
			   onder een maat waarvoor je je nog niet hebt aangemeld. */
			var formulier = vak.querySelector( '.wsfm-voorraad-form' );
			if ( formulier ) {
				formulier.removeAttribute( 'hidden' );
			}
			melding( vak, '', '' );

			if ( toon ) {
				vak.removeAttribute( 'hidden' );
			} else {
				vak.setAttribute( 'hidden', 'hidden' );
			}
		} );
	}

	document.addEventListener( 'submit', function ( e ) {
		var formulier = e.target;

		if ( ! formulier || ! formulier.classList || ! formulier.classList.contains( 'wsfm-voorraad-form' ) ) {
			return;
		}

		e.preventDefault();

		var vak = formulier.closest( '.wsfm-voorraad' );
		if ( vak ) {
			verstuur( vak );
		}
	} );

	/* De variatiekeuze van WooCommerce meelezen. Dat gaat via jQuery-events,
	   want zo geeft WooCommerce ze af; er is geen gewone browsergebeurtenis
	   voor. Staat jQuery er niet, dan is het geen variabel product of een thema
	   zonder de standaard variatiekiezer, en dan doet dit stuk niets. */
	if ( window.jQuery ) {
		window.jQuery( function ( $ ) {
			$( '.variations_form' )
				.on( 'found_variation', function ( e, variatie ) {
					if ( ! variatie ) {
						return;
					}
					zetVariatie( variatie.variation_id, ! variatie.is_in_stock );
				} )
				.on( 'reset_data hide_variation', function () {
					zetVariatie( 0, false );
				} );
		} );
	}
} )();
