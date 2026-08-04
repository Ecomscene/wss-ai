/**
 * De fotoknoppen op het productscherm, en de stijlkiezer op de beheerpagina.
 *
 * DE KNOP BIJ DE GALERIJ
 * Onder de hoofdfoto hangt onze knop via een filter van WordPress zelf. Voor het
 * galerijblok van WooCommerce bestaat zo'n filter niet, dus die knop wordt hier
 * toegevoegd. Dat gebeurt met een schuchtere hand: we voegen iets TOE aan een
 * blok, we veranderen er niets in. Staat dat blok er niet (een oudere
 * WooCommerce, een aangepast scherm), dan verschijnt de knop niet en werkt de
 * rest gewoon door.
 */
(function ($) {
	'use strict';

	var C = window.wssAiFoto || {};
	var T = C.taal || {};

	/* ---------------- het paneel ---------------- */

	function paneel() {
		return $('.wss-ai-paneel');
	}

	function open(doel) {
		var $p = paneel();
		if (!$p.length) {
			return;
		}
		$p.data('doel', doel);
		vulBronkiezer(doel);
		$p.find('.wss-ai-nieuw-vak').prop('hidden', true);
		$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('hidden', true);
		$p.find('.wss-ai-maak').prop('hidden', false).prop('disabled', false);
		$p.find('.wss-ai-paneel-melding').removeClass('wss-ai-fout').text('');
		$p.find('#wss-ai-extra').val(C.prompt || '');
		$p.find('.wss-ai-paneel-kop').text(
			doel === 'galerij' ? 'Foto voor de galerij' : 'Nieuwe hoofdfoto'
		);
		$p.find('input[name="wss-ai-taak"]').first().prop('checked', true);
		toonBijTaak();
		$p.prop('hidden', false);
	}

	/**
	 * Bij het knippen van een achtergrond valt er niets te beschrijven.
	 *
	 * Het opdrachtveld en de stijlregel gaan dan weg. Ze laten staan zou een veld
	 * tonen dat je wel kunt invullen maar dat niets doet, en dat is de soort
	 * knop waarvan je gaat denken dat je iets verkeerd doet.
	 */
	function toonBijTaak() {
		var $p = paneel();
		var $gekozen = $p.find('input[name="wss-ai-taak"]:checked');
		var metStijl = $gekozen.data('stijl') !== 0 && $gekozen.data('stijl') !== '0';
		$p.find('.wss-ai-extra-vak, .wss-ai-stijlregel').toggle(metStijl);
	}

	function sluit() {
		paneel().prop('hidden', true);
	}

	/**
	 * Welke foto's van dit product er zijn om mee te beginnen.
	 *
	 * Uit het scherm, niet uit de database. Heeft de winkelier net een foto
	 * toegevoegd zonder op te slaan, dan staat die er wel bij: dat is wat hij
	 * voor zich ziet, en daar hoort de keuze op te slaan.
	 */
	function beschikbareFotos() {
		var lijst = [];

		var hoofdId = parseInt($('#_thumbnail_id').val(), 10);
		var hoofdImg = document.querySelector('#postimagediv .inside img');
		if (hoofdId > 0 && hoofdImg) {
			lijst.push({ id: hoofdId, src: hoofdImg.src, label: 'Hoofdfoto' });
		}

		$('#product_images_container li[data-attachment_id]').each(function (i) {
			var id = parseInt($(this).data('attachment_id'), 10);
			var img = this.querySelector('img');
			if (id > 0 && img) {
				lijst.push({ id: id, src: img.src, label: 'Galerij ' + (i + 1) });
			}
		});

		return lijst;
	}

	/**
	 * Het rijtje met foto's om uit te kiezen.
	 *
	 * Bij een galerijfoto beginnen we niet vanzelf bij de hoofdfoto maar bij de
	 * laatste galerijfoto, als die er is. Anders krijg je bij elke klik weer een
	 * variant op diezelfde ene foto, en dat was precies de klacht.
	 */
	function vulBronkiezer(doel) {
		var $p = paneel();
		var $vak = $p.find('.wss-ai-bronkiezer').empty();
		var fotos = beschikbareFotos();

		if (!fotos.length) {
			$p.find('.wss-ai-bron').attr('src', '');
			$p.data('bron', 0);
			return;
		}

		var start = fotos[0];
		if (doel === 'galerij' && fotos.length > 1) {
			start = fotos[fotos.length - 1];
		}
		kiesBron(start);

		if (fotos.length < 2) {
			return;
		}
		fotos.forEach(function (f) {
			$vak.append(
				$('<button type="button" class="wss-ai-bronknop"/>')
					.attr('title', f.label)
					.toggleClass('is-gekozen', f.id === start.id)
					.data('foto', f)
					.append($('<img alt=""/>').attr('src', f.src))
			);
		});
	}

	function kiesBron(f) {
		var $p = paneel();
		$p.data('bron', f.id);
		$p.find('.wss-ai-bron').attr('src', f.src);
		$p.find('.wss-ai-bronknop').each(function () {
			$(this).toggleClass('is-gekozen', $(this).data('foto').id === f.id);
		});
	}

	function melding(tekst, fout) {
		paneel()
			.find('.wss-ai-paneel-melding')
			.toggleClass('wss-ai-fout', !!fout)
			.text(tekst || '');
	}

	function maak() {
		var $p = paneel();
		$p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', true);
		melding(T.bezig || 'Bezig…');

		$.post(C.ajax, {
			action: 'wss_ai_foto_genereer',
			nonce: C.nonce,
			post: C.post,
			taak: $p.find('input[name="wss-ai-taak"]:checked').val() || 'vernieuwen',
			doel: $p.data('doel') || 'hoofd',
			bron: $p.data('bron') || 0,
			extra: $p.find('#wss-ai-extra').val() || '',
		})
			.done(function (res) {
				$p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				if (!res || !res.success || !res.data || !res.data.url) {
					melding((res && res.data && res.data.error) || T.mislukt, true);
					return;
				}
				$p.data('foto', res.data);
				$p.find('.wss-ai-nieuw').attr('src', res.data.url);
				$p.find('.wss-ai-nieuw-vak').prop('hidden', false);
				$p.find('.wss-ai-maak').prop('hidden', true);
				$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('hidden', false);
				melding('');
			})
			.fail(function () {
				$p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				melding(T.mislukt, true);
			});
	}

	function gebruik() {
		var $p = paneel();
		var foto = $p.data('foto');
		if (!foto) {
			return;
		}
		$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', true);
		melding(T.toepassen || 'Bezig…');

		$.post(C.ajax, {
			action: 'wss_ai_foto_toepassen',
			nonce: C.nonce,
			post: C.post,
			doel: $p.data('doel') || 'hoofd',
			id: foto.id,
			url: foto.url,
			mime: foto.mime || 'image/jpeg',
		})
			.done(function (res) {
				$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				if (!res || !res.success || !res.data) {
					melding((res && res.data && res.data.error) || T.mislukt, true);
					return;
				}
				melding(T.geplaatst || '');
				/* Het scherm laten kloppen met wat er nu op het product staat.
				   Herladen is hier eerlijker dan het plaatje in beeld vervangen:
				   WooCommerce houdt zijn galerij in een verborgen veld bij, en
				   dat wil je niet met de hand bijwerken. */
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			})
			.fail(function () {
				$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				melding(T.mislukt, true);
			});
	}

	/* ---------------- de stijlkiezer op de beheerpagina ---------------- */

	var gekozenMedia = [];

	function toonGekozen() {
		var $t = $('.wss-ai-gekozen-media');
		if (!$t.length) {
			return;
		}
		$t.text(
			gekozenMedia.length
				? gekozenMedia.length + (gekozenMedia.length === 1 ? ' foto gekozen' : ' foto\'s gekozen')
				: ''
		);
	}

	function kiesMedia() {
		if (!window.wp || !window.wp.media) {
			return;
		}
		var kiezer = window.wp.media({
			title: T.kiesMedia || 'Kies foto\'s',
			button: { text: T.kiesKnop || 'Gebruiken' },
			library: { type: 'image' },
			multiple: true,
		});
		kiezer.on('select', function () {
			gekozenMedia = kiezer
				.state()
				.get('selection')
				.map(function (m) {
					return m.id;
				});
			toonGekozen();
		});
		kiezer.open();
	}

	function beschrijf() {
		var $knop = $('.wss-ai-beschrijf');
		var $m = $('.wss-ai-stijl-melding');
		var producten = $('#wss-ai-producten').val() || [];

		$knop.prop('disabled', true);
		$m.removeClass('wss-ai-fout').text(T.stijlBezig || 'Bezig…');

		$.post(C.ajax, {
			action: 'wss_ai_foto_stijl',
			nonce: C.nonce,
			media: gekozenMedia,
			producten: producten,
		})
			.done(function (res) {
				$knop.prop('disabled', false);
				if (!res || !res.success || !res.data) {
					$m.addClass('wss-ai-fout').text((res && res.data && res.data.error) || T.mislukt);
					return;
				}
				$('#wss_ai_beschrijving').val(res.data.beschrijving || '');
				$m.text(
					'Gelukt, gebaseerd op ' +
						res.data.aantal +
						(res.data.aantal === 1 ? ' foto' : ' foto\'s') +
						'. Kijk de tekst na en sla op.'
				);
			})
			.fail(function () {
				$knop.prop('disabled', false);
				$m.addClass('wss-ai-fout').text(T.mislukt);
			});
	}

	/* ---------------- opstarten ---------------- */

	$(function () {
		if (C.opPagina) {
			$(document).on('click', '.wss-ai-kies-media', function (e) {
				e.preventDefault();
				kiesMedia();
			});
			$(document).on('click', '.wss-ai-beschrijf', function (e) {
				e.preventDefault();
				beschrijf();
			});
			return;
		}

		if (!paneel().length) {
			return;
		}

		/* De knop bij de galerij. Alleen als dat blok er is. */
		var $galerij = $('#woocommerce-product-images .inside');
		if ($galerij.length) {
			$galerij.append(
				$('<p class="wss-ai-fotoknop"/>').append(
					$('<button type="button" class="button" data-wss-foto="galerij"/>').text(
						'Nieuwe foto met AI'
					)
				)
			);
		}

		$(document).on('click', '[data-wss-foto]', function (e) {
			e.preventDefault();
			open($(this).data('wss-foto'));
		});
		$(document).on('click', '.wss-ai-sluit', sluit);
		$(document).on('click', '.wss-ai-paneel', function (e) {
			if (e.target === this) {
				sluit();
			}
		});
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape' && !paneel().prop('hidden')) {
				sluit();
			}
		});
		$(document).on('change', 'input[name="wss-ai-taak"]', toonBijTaak);
		$(document).on('click', '.wss-ai-bronknop', function () {
			kiesBron($(this).data('foto'));
		});
		$(document).on('click', '.wss-ai-maak', maak);
		$(document).on('click', '.wss-ai-opnieuw', maak);
		$(document).on('click', '.wss-ai-gebruik', gebruik);
	});
})(jQuery);
