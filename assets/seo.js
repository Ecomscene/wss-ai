/**
 * De knoppen op het productscherm.
 *
 * HET LASTIGSTE STUK ZIT IN HET INVULLEN
 * Yoast en Rank Math tekenen hun velden met React. Een waarde in het invoerveld
 * zetten is niet genoeg: React houdt zijn eigen administratie bij en overschrijft
 * hem bij de eerste de beste hertekening. Daarom zetten we de waarde via de
 * setter van de browser zelf en sturen we daarna de gebeurtenis die React
 * verwacht.
 *
 * En omdat dat per versie van die plugins kan veranderen, CONTROLEREN we daarna
 * of het gelukt is. Zo niet, dan zeggen we dat en zetten we de tekst met een
 * kopieerknop in beeld. Een knop die groen kleurt terwijl er niets gebeurde is
 * erger dan een knop die eerlijk zegt dat het niet lukte.
 */
(function ($) {
	'use strict';

	var T = (window.wssAi && window.wssAi.taal) || {};

	/** Waar de tekst heen moet bij oudere versies, die nog een gewoon veld hebben. */
	var VELDEN = {
		'seo-titel': ['#yoast_wpseo_title', '#rank_math_title'],
		'seo-omschrijving': ['#yoast_wpseo_metadesc', '#rank_math_description'],
	};

	/**
	 * De SEO-titel en SEO-omschrijving invullen.
	 *
	 * DIT GING EERST MIS EN HET IS LEERZAAM WAAROM
	 * We vulden #yoast_wpseo_title. Bij Yoast 14 en nieuwer is dat een VERBORGEN
	 * veld: Yoast tekent het echte veld met React en houdt de waarde in zijn eigen
	 * store bij. Het vullen lukte dus wel, de controle erna ("staat mijn tekst
	 * erin?") keek naar datzelfde verborgen veld en zei ja, en de knop meldde
	 * vrolijk "klaar" terwijl er op het scherm niets veranderde.
	 *
	 * Daarom nu: eerst de store van de SEO-plugin zelf, en pas daarna een gewoon
	 * veld. En een veld telt alleen als het ook zichtbaar is, want anders hebben we
	 * precies dezelfde leugen terug.
	 *
	 * We kijken per functie of hij bestaat in plaats van te vertrouwen op een
	 * versienummer. Verandert Yoast of Rank Math iets, dan valt hij terug op het
	 * kopieervak in plaats van stil te falen.
	 */
	function zetSeoVeld(soort, tekst) {
		var isTitel = soort === 'seo-titel';
		var d = window.wp && window.wp.data;

		if (d && d.dispatch && d.select) {
			/* Yoast 14 en nieuwer. updateData voegt samen met wat er al staat, dus
			   we kunnen de titel zetten zonder de omschrijving te wissen. */
			try {
				var yz = d.dispatch('yoast-seo/editor');
				var yl = d.select('yoast-seo/editor');
				if (yz && yl && typeof yz.updateData === 'function') {
					yz.updateData(isTitel ? { title: tekst } : { description: tekst });
					var lees = isTitel ? yl.getSnippetEditorTitle : yl.getSnippetEditorDescription;
					if (typeof lees === 'function' && lees.call(yl) === tekst) {
						return true;
					}
				}
			} catch (e) {
				/* Yoast staat er niet of doet het anders; we proberen de volgende. */
			}

			/* Rank Math. */
			try {
				var rz = d.dispatch('rank-math');
				var rl = d.select('rank-math');
				var zetten = isTitel ? 'updateSerpTitle' : 'updateSerpDescription';
				var lezen = isTitel ? 'getSerpTitle' : 'getSerpDescription';
				if (rz && typeof rz[zetten] === 'function') {
					rz[zetten](tekst);
					if (rl && typeof rl[lezen] === 'function' && rl[lezen]() === tekst) {
						return true;
					}
				}
			} catch (e) {
				/* Ook niet. Dan het gewone veld. */
			}
		}

		/* Oudere versies, waar het veld nog gewoon in beeld staat. */
		var doelen = VELDEN[soort] || [];
		for (var i = 0; i < doelen.length; i++) {
			var el = document.querySelector(doelen[i]);
			if (!el || el.type === 'hidden' || el.offsetParent === null) {
				continue;
			}
			zetInVeld(el, tekst);
			if (el.value === tekst) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Een waarde in een React-veld zetten zodat hij ook echt blijft staan.
	 *
	 * De truc: React onthoudt de vorige waarde op het element zelf. Zet je die
	 * met .value, dan denkt React dat er niets veranderd is en negeert hij de
	 * gebeurtenis. Via de oorspronkelijke setter van het prototype omzeil je die
	 * administratie.
	 */
	function zetInVeld(el, waarde) {
		var proto = el.tagName === 'TEXTAREA' ? window.HTMLTextAreaElement : window.HTMLInputElement;
		var setter = Object.getOwnPropertyDescriptor(proto.prototype, 'value');
		if (setter && setter.set) {
			setter.set.call(el, waarde);
		} else {
			el.value = waarde;
		}
		el.dispatchEvent(new Event('input', { bubbles: true }));
		el.dispatchEvent(new Event('change', { bubbles: true }));
	}

	/**
	 * De omschrijving: TinyMCE als die aanstaat, anders het gewone tekstvak.
	 *
	 * De server levert de HTML al kant en klaar aan, inclusief de tussenkopjes.
	 * Krijgen we toch platte tekst binnen (een oudere server, of het veld html
	 * ontbreekt), dan maken we er hier alsnog alinea's van.
	 */
	function zetOmschrijving(inhoud) {
		var html = /^\s*</.test(inhoud)
			? inhoud
			: inhoud
					.split(/\n\s*\n/)
					.filter(function (r) {
						return r.trim();
					})
					.map(function (r) {
						return '<p>' + r.trim().replace(/\n/g, '<br>') + '</p>';
					})
					.join('\n');

		var ed = window.tinymce && window.tinymce.get('content');
		if (ed && !ed.isHidden()) {
			ed.setContent(html);
			ed.fire('change');
			return true;
		}
		var vak = document.getElementById('content');
		if (vak) {
			zetInVeld(vak, html);
			return true;
		}
		return false;
	}

	/** Wat er nu in de omschrijving staat, opgeslagen of niet. */
	function huidigeOmschrijving() {
		var ed = window.tinymce && window.tinymce.get('content');
		if (ed && !ed.isHidden()) {
			return ed.getContent({ format: 'text' });
		}
		var vak = document.getElementById('content');
		return vak ? vak.value : '';
	}

	function toonUitkomst($vak, tekst, uitleg) {
		$vak
			.empty()
			.append($('<p/>').text(uitleg))
			.append($('<textarea readonly rows="4"/>').val(tekst))
			.append(
				$('<button type="button" class="button"/>')
					.text(T.kopieer || 'Kopieer')
					.on('click', function () {
						var $t = $vak.find('textarea');
						$t.trigger('select');
						try {
							document.execCommand('copy');
							$(this).text(T.gekopieerd || 'Gekopieerd');
						} catch (e) {
							/* Kopiëren mag mislukken; de tekst staat er nog. */
						}
					})
			)
			.prop('hidden', false);
	}

	$(function () {
		var $blok = $('.wss-ai-seo');
		if (!$blok.length) {
			return;
		}
		var postId = $blok.data('post');
		var $melding = $blok.find('.wss-ai-melding');
		var $uitkomst = $blok.find('.wss-ai-uitkomst');

		$blok.on('click', '[data-wss-ai]', function () {
			var $knop = $(this);
			var soort = $knop.data('wss-ai');
			var $alle = $blok.find('[data-wss-ai]');

			$alle.prop('disabled', true);
			$uitkomst.prop('hidden', true).empty();
			$melding.removeClass('wss-ai-fout').text(T.bezig || 'Bezig…');

			$.post(window.wssAi.ajax, {
				action: 'wss_ai_tekst',
				nonce: window.wssAi.nonce,
				post: postId,
				soort: soort,
				/* De lengte gaat altijd mee, maar de server gebruikt hem alleen
				   bij de omschrijving. De SEO-velden hebben een lengte die Google
				   bepaalt. */
				lengte: $blok.find('.wss-ai-lengte-keuze').val() || '',
				/* De ruwe aantekeningen gaan altijd mee, ook bij de SEO-velden:
				   daar staat vaak precies waar het product over gaat. */
				ruw: huidigeOmschrijving(),
			})
				.done(function (res) {
					$alle.prop('disabled', false);

					if (!res || !res.success || !res.data || !res.data.tekst) {
						$melding
							.addClass('wss-ai-fout')
							.text((res && res.data && res.data.error) || T.mislukt);
						return;
					}

					var tekst = res.data.tekst;
					var gelukt =
						soort === 'omschrijving'
							? zetOmschrijving(res.data.html || tekst)
							: zetSeoVeld(soort, tekst);

					if (gelukt) {
						$melding.text(T.klaar || '');
						/* Bij weinig informatie zeggen we dat erbij, met de tekst
						   er gewoon in. Anders denkt iemand dat dit het beste is
						   wat eruit te halen valt. */
						if (res.data.mager) {
							$uitkomst
								.empty()
								.append($('<p class="wss-ai-let-op"/>').text(T.mager))
								.prop('hidden', false);
						}
					} else {
						$melding.text('');
						toonUitkomst($uitkomst, tekst, T.nietGevuld);
					}
				})
				.fail(function () {
					$alle.prop('disabled', false);
					$melding.addClass('wss-ai-fout').text(T.mislukt || 'Dit lukte niet.');
				});
		});
	});
})(jQuery);
