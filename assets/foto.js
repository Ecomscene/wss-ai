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
		$p.data('doel', doel).data('handmatig', false);
		vulBronkiezer(doel);
		$p.find('.wss-ai-nieuw-vak, .wss-ai-bijwerk-vak').prop('hidden', true);
		$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('hidden', true);
		$p.find('.wss-ai-maak').prop('hidden', false).prop('disabled', false);
		$p.find('.wss-ai-paneel-melding').removeClass('wss-ai-fout').text('');
		$p.find('.wss-ai-paneel-kop').text(
			doel === 'galerij' ? 'Foto voor de galerij' : 'Nieuwe hoofdfoto'
		);

		/* Bij vernieuwen staat er weer wat je de vorige keer typte; dat gaat over
		   dit product en blijft gelden. Bij een variant juist niet: daar is het
		   de plek waar hij komt te staan, en die wil je nu een andere hebben. */
		$p.find('#wss-ai-extra').val(doel === 'galerij' ? '' : C.prompt || '');
		/**
		 * Welke taak er klaarstaat hangt af van waar je vandaan komt.
		 *
		 * Klik je bij de galerij, dan wil je een EXTRA foto: een variant. Dat
		 * eerst zelf moeten omzetten voelt als een omweg, want je hebt net al
		 * gezegd wat je wilde door op die knop te drukken. Bij de hoofdfoto is
		 * vernieuwen wel de logische start: daar gaat het om die ene foto.
		 */
		var wens = doel === 'galerij' ? 'variant' : 'vernieuwen';
		var $wens = $p.find('input[name="wss-ai-taak"][value="' + wens + '"]');
		($wens.length ? $wens : $p.find('input[name="wss-ai-taak"]').first()).prop('checked', true);

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
		var variant = $gekozen.val() === 'variant';
		var metStijl = $gekozen.data('stijl') !== 0 && $gekozen.data('stijl') !== '0';

		$p.find('.wss-ai-extra-vak, .wss-ai-stijlregel').toggle(metStijl);

		/* De knop zegt wat er gaat gebeuren. "Maak de foto" onder een keuze die
		   "variant" heet laat je twijfelen of je wel het goede aanklikte. */
		$p.find('.wss-ai-maak').text(variant ? 'Maak de variant' : 'Maak de foto');

		/**
		 * Bij een variant vraagt hetzelfde veld iets anders, en is het verplicht.
		 *
		 * Dat is geen pesterij maar wat werkt. Een model dat zelf een omgeving mag
		 * verzinnen kiest bijna altijd de omgeving die al op de foto stond; noem
		 * je een plek, dan is het in een keer raak. Eén regel typen is een kleine
		 * prijs voor het verschil tussen een variant en dezelfde foto.
		 */
		$p.find('.wss-ai-extra-kop').text(variant ? T.plekKop : T.extraKop);
		$p.find('.wss-ai-extra-uitleg').text(variant ? T.plekUit : T.extraUit);
		$p.find('#wss-ai-extra').attr(
			'placeholder',
			variant ? 'op een bank in een woonkamer' : ''
		);

		/* Een variant hoort van de hoofdfoto uit te gaan: dat is de foto waarop
		   het product het duidelijkst te zien is, en hier is de bronfoto alleen
		   een voorbeeld en geen beginpunt. Heeft de winkelier zelf een foto
		   aangeklikt, dan laten we zijn keuze staan. */
		if (variant && !$p.data('handmatig')) {
			var fotos = beschikbareFotos();
			if (fotos.length) {
				kiesBron(fotos[0]);
			}
		}
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
		var variant = $p.find('input[name="wss-ai-taak"]:checked').val() === 'variant';

		/* Zelf tegenhouden in plaats van de server laten weigeren: dat scheelt
		   een rondje wachten voor iets wat je hier al kunt zien. */
		if (variant && !$.trim($p.find('#wss-ai-extra').val() || '')) {
			melding(T.plekLeeg || '', true);
			$p.find('#wss-ai-extra').trigger('focus');
			return;
		}

		$p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', true);
		$p.find('.wss-ai-bijwerk-vak').prop('hidden', true);
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
				toonResultaat(res.data);
				$p.find('.wss-ai-maak').prop('hidden', true);
				$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('hidden', false);
				melding(res.data.uitgeweken || '');
			})
			.fail(function () {
				$p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				melding(T.mislukt, true);
			});
	}

	/** Het resultaat in beeld zetten, met het bijschaafveld eronder. */
	function toonResultaat(data) {
		var $p = paneel();
		$p.data('foto', data);
		$p.find('.wss-ai-nieuw').attr('src', data.url);
		$p.find('.wss-ai-nieuw-vak').prop('hidden', false);
		$p.find('.wss-ai-bijwerk-vak').prop('hidden', false);
		$p.find('#wss-ai-bijwerk').val('');
	}

	/**
	 * Eén ding aan de gemaakte foto veranderen.
	 *
	 * Het resultaat vervangt wat er stond, en je kunt meteen weer verder
	 * schaven. De vorige versie raak je daarmee kwijt in beeld; hij staat nog
	 * wel bij Webshopschool, maar niemand gaat hier een geschiedenis van
	 * pogingen zitten doorbladeren.
	 */
	function bijwerken() {
		var $p = paneel();
		var foto = $p.data('foto');
		var opdracht = $.trim($p.find('#wss-ai-bijwerk').val() || '');
		if (!foto || !opdracht) {
			melding(T.bijwerkLeeg || '', true);
			$p.find('#wss-ai-bijwerk').trigger('focus');
			return;
		}

		var $knoppen = $p.find('.wss-ai-maak, .wss-ai-gebruik, .wss-ai-opnieuw, .wss-ai-bijwerk-knop');
		$knoppen.prop('disabled', true);
		melding(T.bijwerkBezig || 'Bezig…');

		$.post(C.ajax, {
			action: 'wss_ai_foto_bijwerken',
			nonce: C.nonce,
			post: C.post,
			id: foto.id,
			url: foto.url,
			opdracht: opdracht,
		})
			.done(function (res) {
				$knoppen.prop('disabled', false);
				if (!res || !res.success || !res.data || !res.data.url) {
					melding((res && res.data && res.data.error) || T.mislukt, true);
					return;
				}
				toonResultaat(res.data);
				melding(T.bijgewerkt || '');
			})
			.fail(function () {
				$knoppen.prop('disabled', false);
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
				if ($p.data('doel') === 'galerij') {
					/* Bij de galerij werken we het scherm bij in plaats van te
					   herladen, zodat je er meteen nog een kunt maken. Dat moet
					   ook wel: het verborgen veld van WooCommerce wint bij het
					   opslaan van het product, dus laten we dat achter met de
					   oude lijst, dan verdwijnt onze foto bij de volgende klik
					   op Bijwerken. */
					zetGalerijBij(res.data);
					melding('Toegevoegd. Je kunt er meteen nog een maken.');
					$p.find('.wss-ai-nieuw-vak, .wss-ai-bijwerk-vak').prop('hidden', true);
					$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('hidden', true);
					$p.find('.wss-ai-maak').prop('hidden', false);
					vulBronkiezer('galerij');
					return;
				}

				melding(T.geplaatst || '');
				/* De hoofdfoto zit in een blok van WordPress zelf, met een eigen
				   verborgen veld en eigen knoppen. Dat halfslachtig bijwerken
				   levert een scherm op dat iets anders toont dan er is
				   opgeslagen; herladen is daar de eerlijke keuze. */
				window.setTimeout(function () {
					window.location.reload();
				}, 900);
			})
			.fail(function () {
				$p.find('.wss-ai-gebruik, .wss-ai-opnieuw').prop('disabled', false);
				melding(T.mislukt, true);
			});
	}

	/**
	 * De galerij op het scherm laten kloppen met wat er is opgeslagen.
	 *
	 * Precies de opbouw die WooCommerce zelf gebruikt als je via "Foto's aan de
	 * productgalerij toevoegen" iets kiest: een li met data-attachment_id, en het
	 * verborgen veld met alle nummers. Dat veld is het belangrijkste stuk, want
	 * dat is wat er wordt opgeslagen als de winkelier op Bijwerken klikt.
	 */
	function zetGalerijBij(data) {
		var $veld = $('#product_image_gallery');
		var $lijst = $('#product_images_container ul.product_images');
		if (!$veld.length || !$lijst.length) {
			return;
		}
		$veld.val((data.galerij || []).join(','));

		if (!data.attachment || $lijst.find('[data-attachment_id="' + data.attachment + '"]').length) {
			return;
		}
		$lijst.append(
			$('<li class="image"/>')
				.attr('data-attachment_id', data.attachment)
				.append($('<img alt=""/>').attr('src', data.thumb || ''))
				.append(
					$('<ul class="actions"/>').append(
						$('<li/>').append($('<a href="#" class="delete"/>').text('Verwijderen'))
					)
				)
		);
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
			paneel().data('handmatig', true);
			kiesBron($(this).data('foto'));
		});
		$(document).on('click', '.wss-ai-maak', maak);
		$(document).on('click', '.wss-ai-opnieuw', maak);
		$(document).on('click', '.wss-ai-gebruik', gebruik);
		$(document).on('click', '.wss-ai-bijwerk-knop', bijwerken);
		$(document).on('keydown', '#wss-ai-bijwerk', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				bijwerken();
			}
		});
	});
})(jQuery);
