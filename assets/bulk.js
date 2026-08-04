/**
 * Het bulkscherm: producten een voor een langs.
 *
 * DE LUS IS BEWUST NIET AUTOMATISCH
 * Na een goedgekeurde foto begint de volgende vanzelf, maar er wordt nooit meer
 * dan één foto gemaakt zonder dat er iemand ja heeft gezegd. Zo kost een stijl
 * die niet valt je één foto en geen vijftig, en kun je halverwege de opdracht
 * bijschaven en hetzelfde product opnieuw proberen.
 *
 * Dit scherm belt dezelfde ajax-acties als de knop bij één product. Er is dus
 * geen tweede manier waarop een foto gemaakt of geplaatst wordt.
 */
(function ($) {
	'use strict';

	var C = window.wssAiFoto || {};
	var B = window.wssAiBulk || {};
	var T = B.taal || {};

	var rij = B.producten || [];
	var bij = -1;
	var loopt = false;
	var huidig = null;

	function $vak() {
		return $('.wss-ai-bulk-werk');
	}

	function melding(tekst, fout) {
		$('.wss-ai-bulk-melding').toggleClass('wss-ai-fout', !!fout).text(tekst || '');
	}

	function zetStand(id, tekst) {
		$('.wss-ai-bulk-lijst tr[data-id="' + id + '"] .wss-ai-stand').text(tekst);
	}

	function opties() {
		return {
			taak: $('input[name="wss-ai-bulk-taak"]:checked').val() || 'vernieuwen',
			doel: $('#wss-ai-bulk-doel').val() || 'hoofd',
			extra: $('#wss-ai-bulk-extra').val() || '',
		};
	}

	function knoppen(aan) {
		$vak().find('button').prop('disabled', !aan);
	}

	/** Naar het eerstvolgende product dat nog wat te doen heeft. */
	function volgende() {
		if (!loopt) {
			return;
		}
		bij++;
		while (bij < rij.length && !rij[bij].kan) {
			zetStand(rij[bij].id, T.geenFoto);
			bij++;
		}
		if (bij >= rij.length) {
			stop(T.afgerond);
			return;
		}
		maak();
	}

	function maak() {
		var p = rij[bij];
		if (!p) {
			return;
		}
		huidig = null;

		$vak().prop('hidden', false);
		$vak().find('.wss-ai-bulk-naam').text(p.naam);
		$vak().find('.wss-ai-bulk-bron').attr('src', p.thumb || '');
		$vak().find('.wss-ai-bulk-nieuw-vak, .wss-ai-bijwerk-vak').prop('hidden', true);
		knoppen(false);

		zetStand(p.id, T.bezig);
		melding(T.maken);

		var o = opties();
		$.post(C.ajax, {
			action: 'wss_ai_foto_genereer',
			nonce: C.nonce,
			post: p.id,
			taak: o.taak,
			doel: o.doel,
			extra: o.extra,
			bulk: 1,
		})
			.done(function (res) {
				if (!loopt) {
					return;
				}
				knoppen(true);
				if (!res || !res.success || !res.data || !res.data.url) {
					zetStand(p.id, T.mislukt);
					melding((res && res.data && res.data.error) || T.mislukking, true);
					return;
				}
				huidig = res.data;
				$vak().find('.wss-ai-bulk-nieuw').attr('src', res.data.url);
				$vak().find('.wss-ai-bulk-nieuw-vak, .wss-ai-bijwerk-vak').prop('hidden', false);
				$vak().find('#wss-ai-bulk-bijwerk').val('');
				melding('');
			})
			.fail(function () {
				knoppen(true);
				zetStand(p.id, T.mislukt);
				melding(T.mislukking, true);
			});
	}

	function goedkeuren() {
		var p = rij[bij];
		if (!p || !huidig) {
			return;
		}
		knoppen(false);
		melding(T.plaatsen);

		$.post(C.ajax, {
			action: 'wss_ai_foto_toepassen',
			nonce: C.nonce,
			post: p.id,
			doel: opties().doel,
			id: huidig.id,
			url: huidig.url,
			mime: huidig.mime || 'image/jpeg',
		})
			.done(function (res) {
				knoppen(true);
				if (!res || !res.success) {
					zetStand(p.id, T.mislukt);
					melding((res && res.data && res.data.error) || T.mislukking, true);
					return;
				}
				zetStand(p.id, T.klaar);
				/* De miniatuur in de lijst meteen bijwerken, zodat je aan het eind
				   ziet wat je hebt gekregen zonder de pagina te verversen. */
				if (res.data && res.data.thumb) {
					$('.wss-ai-bulk-lijst tr[data-id="' + p.id + '"] img').attr('src', res.data.thumb);
				}
				melding('');
				volgende();
			})
			.fail(function () {
				knoppen(true);
				zetStand(p.id, T.mislukt);
				melding(T.mislukking, true);
			});
	}

	function bijwerken() {
		var opdracht = $.trim($('#wss-ai-bulk-bijwerk').val() || '');
		if (!huidig || !opdracht) {
			return;
		}
		knoppen(false);
		melding(T.plaatsen);

		$.post(C.ajax, {
			action: 'wss_ai_foto_bijwerken',
			nonce: C.nonce,
			post: rij[bij].id,
			id: huidig.id,
			url: huidig.url,
			opdracht: opdracht,
		})
			.done(function (res) {
				knoppen(true);
				if (!res || !res.success || !res.data || !res.data.url) {
					melding((res && res.data && res.data.error) || T.mislukking, true);
					return;
				}
				huidig = res.data;
				$vak().find('.wss-ai-bulk-nieuw').attr('src', res.data.url);
				$('#wss-ai-bulk-bijwerk').val('');
				melding('');
			})
			.fail(function () {
				knoppen(true);
				melding(T.mislukking, true);
			});
	}

	function stop(tekst) {
		loopt = false;
		huidig = null;
		$vak().prop('hidden', true);
		$('.wss-ai-bulk-stop').prop('hidden', true);
		$('.wss-ai-bulk-start').prop('hidden', false).text('Verder gaan');
		melding(tekst || T.gestopt);
	}

	$(function () {
		if (!$('.wss-ai-bulk').length) {
			return;
		}

		$(document).on('click', '.wss-ai-bulk-start', function () {
			loopt = true;
			$(this).prop('hidden', true);
			$('.wss-ai-bulk-stop').prop('hidden', false);
			/* Verder gaan pakt de draad op waar hij lag; opnieuw beginnen begint
			   vooraan. Dat verschil zie je aan de standen in de lijst. */
			if (bij < 0) {
				bij = -1;
			} else {
				bij--;
			}
			volgende();
		});

		$(document).on('click', '.wss-ai-bulk-stop', function () {
			stop();
		});

		$(document).on('click', '.wss-ai-bulk-ja', goedkeuren);
		$(document).on('click', '.wss-ai-bulk-opnieuw', maak);
		$(document).on('click', '.wss-ai-bulk-bijwerk-knop', bijwerken);
		$(document).on('keydown', '#wss-ai-bulk-bijwerk', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				bijwerken();
			}
		});

		$(document).on('click', '.wss-ai-bulk-over', function () {
			var p = rij[bij];
			if (p) {
				zetStand(p.id, T.over);
			}
			volgende();
		});
	});
})(jQuery);
