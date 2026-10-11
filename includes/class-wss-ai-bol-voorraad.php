<?php
/**
 * De bol.com-kolom in het Voorraadbeheer-scherm.
 *
 * WAAROM DIT ERNAAST STAAT EN NIET IN DE VOORRAADMODULE ZELF
 * Dat scherm bouwt zijn tabel in JavaScript op, met een vast aantal kolommen in
 * de template en in het script. Een kolom erin zetten betekent drie bestanden
 * van die module aanpassen, en die draaien bij alle shops die dagelijks hun
 * voorraad bijhouden. Een typefout daarin is dan geen bol-probleem maar een
 * leeg voorraadscherm bij iemand anders.
 *
 * Daarom hangt deze kolom er van buitenaf aan: de bestaande voorraadcode wordt
 * met geen letter geraakt, en alles hier staat achter dezelfde schakelaar als de
 * rest van de bol-module. Staat bol uit, dan wordt er niets ingeladen en ziet
 * het voorraadscherm er precies uit zoals het altijd deed.
 *
 * Zie class-wss-ai-bol.php voor de regels en voor wat deze module bewust NIET
 * doet (praten met bol).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Bol_Voorraad {

	/** De haaknaam van het Voorraadbeheer-scherm. Zie WCCSM_Admin_Overview. */
	const SCHERM = 'toplevel_page_wccsm-stock-manager';

	public static function init() {
		/* Ná WSS_AI_Bol zelf, zodat de schakelaar al bekend is. */
		add_action( 'plugins_loaded', array( __CLASS__, 'laden' ), 26 );
	}

	public static function laden() {
		if ( ! class_exists( 'WSS_AI_Bol' ) || ! WSS_AI_Bol::aan() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'laad_script' ) );
		add_action( 'wp_ajax_wss_bol_rijen', array( __CLASS__, 'ajax_rijen' ) );
		add_action( 'wp_ajax_wss_bol_zet', array( __CLASS__, 'ajax_zet' ) );
	}

	public static function laad_script( $hook ) {
		if ( self::SCHERM !== $hook ) {
			return;
		}

		wp_register_style( 'wss-bol-voorraad', false, array(), WSS_AI_VERSIE );
		wp_enqueue_style( 'wss-bol-voorraad' );
		wp_add_inline_style(
			'wss-bol-voorraad',
			'.wss-bol-cel{white-space:nowrap}'
			. '.wss-bol-cel select{max-width:130px;margin-bottom:2px}'
			. '.wss-bol-cel input{width:80px}'
			. '.wss-bol-vlag{margin-left:4px;cursor:help}'
			. '.wss-bol-goed{color:#00701a}'
			. '.wss-bol-let-op{color:#8a6100}'
			. '.wss-bol-bezig{opacity:.5}'
		);

		wp_register_script( 'wss-bol-voorraad', '', array( 'jquery' ), WSS_AI_VERSIE, true );
		wp_enqueue_script( 'wss-bol-voorraad' );
		wp_localize_script(
			'wss-bol-voorraad',
			'wssBol',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wss_bol_voorraad' ),
				'kop'   => __( 'bol.com', 'wss-ai' ),
				'volg'  => __( 'Volg product', 'wss-ai' ),
				'ja'    => __( 'Wel op bol', 'wss-ai' ),
				'nee'   => __( 'Niet op bol', 'wss-ai' ),
				'prijs' => __( 'Bol-prijs', 'wss-ai' ),
			)
		);
		wp_add_inline_script( 'wss-bol-voorraad', self::js() );
	}

	/**
	 * Het script dat de kolom aanhangt.
	 *
	 * De tabel wordt na elk filter en elke bladzijde opnieuw opgebouwd, dus er
	 * valt niet één keer iets aan te plakken. Een MutationObserver op de tbody
	 * kijkt mee; het toevoegen van een cel aan een RIJ is geen wijziging van de
	 * tbody zelf, dus dat roept zichzelf niet opnieuw op.
	 */
	private static function js() {
		return <<<'JS'
( function ( $ ) {
	if ( typeof wssBol === 'undefined' ) {
		return;
	}

	function zetKop() {
		var $rij = $( '.wccsm-table thead tr' );
		if ( ! $rij.length || $rij.find( '.wss-bol-kop' ).length ) {
			return;
		}
		$rij.append( '<th class="wss-bol-kop">' + wssBol.kop + '</th>' );
	}

	function esc( tekst ) {
		return $( '<div/>' ).text( tekst === null || tekst === undefined ? '' : tekst ).html();
	}

	function vul( $td, d ) {
		var html = '<select class="wss-bol-stand">';

		if ( d.is_variation ) {
			html += '<option value=""' + ( '' === d.stand ? ' selected' : '' ) + '>' + esc( wssBol.volg ) + '</option>';
		}
		html += '<option value="yes"' + ( 'yes' === d.stand ? ' selected' : '' ) + '>' + esc( wssBol.ja ) + '</option>';
		html += '<option value="no"' + ( 'no' === d.stand ? ' selected' : '' ) + '>' + esc( wssBol.nee ) + '</option>';
		html += '</select>';

		html += '<br /><input type="number" step="0.01" min="0" class="wss-bol-prijs"'
			+ ' value="' + esc( d.prijs ) + '"'
			+ ' placeholder="' + esc( d.erf_prijs ? d.erf_prijs : wssBol.prijs ) + '" />';

		html += '<span class="wss-bol-vlag"></span>';

		$td.html( html );
		vlag( $td, d );
	}

	function vlag( $td, d ) {
		var $vlag = $td.find( '.wss-bol-vlag' );
		if ( ! $vlag.length ) {
			return;
		}
		if ( d.ok === null ) {
			$vlag.attr( 'title', '' ).removeClass( 'wss-bol-goed wss-bol-let-op' ).text( '' );
			return;
		}
		if ( d.ok ) {
			$vlag.attr( 'title', d.reden ).removeClass( 'wss-bol-let-op' ).addClass( 'wss-bol-goed' ).html( '&#10003;' );
		} else {
			$vlag.attr( 'title', d.reden ).removeClass( 'wss-bol-goed' ).addClass( 'wss-bol-let-op' ).html( '&#9888;' );
		}
	}

	function verwerk() {
		zetKop();

		var ids = [];

		$( '#wccsm-table-body tr[data-id]' ).each( function () {
			var $rij = $( this );
			if ( $rij.find( '.wss-bol-cel' ).length ) {
				return;
			}
			var id = $rij.attr( 'data-id' );
			$rij.append( '<td class="wss-bol-cel" data-id="' + id + '">&hellip;</td>' );
			ids.push( id );
		} );

		if ( ! ids.length ) {
			return;
		}

		$.post( wssBol.url, {
			action: 'wss_bol_rijen',
			nonce: wssBol.nonce,
			ids: ids
		}, function ( r ) {
			if ( ! r || ! r.success ) {
				return;
			}
			for ( var i = 0; i < r.data.length; i++ ) {
				var d = r.data[ i ];
				var $td = $( '#wccsm-table-body .wss-bol-cel[data-id="' + d.id + '"]' );
				if ( $td.length ) {
					vul( $td, d );
				}
			}
		} );
	}

	function bewaar( $veld, veld, waarde ) {
		var $td = $veld.closest( '.wss-bol-cel' );
		$td.addClass( 'wss-bol-bezig' );

		$.post( wssBol.url, {
			action: 'wss_bol_zet',
			nonce: wssBol.nonce,
			id: $td.attr( 'data-id' ),
			veld: veld,
			waarde: waarde
		}, function ( r ) {
			$td.removeClass( 'wss-bol-bezig' );
			if ( r && r.success ) {
				vlag( $td, r.data );
				if ( 'prijs' === veld ) {
					$veld.val( r.data.prijs );
				}
			}
		} ).fail( function () {
			$td.removeClass( 'wss-bol-bezig' );
		} );
	}

	$( document ).on( 'change', '.wss-bol-stand', function () {
		bewaar( $( this ), 'stand', $( this ).val() );
	} );

	$( document ).on( 'change', '.wss-bol-prijs', function () {
		bewaar( $( this ), 'prijs', $( this ).val() );
	} );

	$( document ).ready( function () {
		var lijf = document.getElementById( 'wccsm-table-body' );
		if ( lijf && window.MutationObserver ) {
			new MutationObserver( function () {
				verwerk();
			} ).observe( lijf, { childList: true } );
		}
		verwerk();
	} );
} )( jQuery );
JS;
	}

	/* =====================================================================
	 * De gegevens
	 * ===================================================================== */

	/** Wat er van één rij te weten valt. */
	private static function regel( $product ) {
		$id       = $product->get_id();
		$is_ouder = $product->is_type( 'variable' );

		$ok    = null;
		$reden = '';

		/* Bij een variabel hoofdproduct zegt de keuring niets: bol verkoopt de
		   maat en niet het product, dus daar hoort geen vlaggetje bij. De
		   variaties eronder hebben hun eigen regel in deze tabel. */
		if ( ! $is_ouder ) {
			list( $ok, $reden ) = WSS_AI_Bol::geschikt( $product );
		}

		return array(
			'id'           => $id,
			'is_variation' => $product->is_type( 'variation' ),
			'is_parent'    => $is_ouder,
			'stand'        => WSS_AI_Bol::stand( $id ),
			'prijs'        => (string) get_post_meta( $id, WSS_AI_Bol::META_PRIJS, true ),
			'erf_prijs'    => (string) WSS_AI_Bol::prijs( $product ),
			'ok'           => $ok,
			'reden'        => $reden,
		);
	}

	public static function ajax_rijen() {
		check_ajax_referer( 'wss_bol_voorraad', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$uit = array();

		/* Het voorraadscherm laat er hooguit duizend per bladzijde zien; met deze
		   grens kan een verzoek van buiten de pagina niet de hele catalogus
		   oplopen. */
		foreach ( array_slice( array_unique( $ids ), 0, 1200 ) as $id ) {
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				continue;
			}
			$uit[] = self::regel( $product );
		}

		wp_send_json_success( $uit );
	}

	public static function ajax_zet() {
		check_ajax_referer( 'wss_bol_voorraad', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$id     = absint( $_POST['id'] ?? 0 );
		$veld   = sanitize_text_field( wp_unslash( $_POST['veld'] ?? '' ) );
		$waarde = sanitize_text_field( wp_unslash( $_POST['waarde'] ?? '' ) );

		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			wp_send_json_error( 'Product not found' );
		}
		if ( ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		if ( 'stand' === $veld ) {
			if ( ! in_array( $waarde, array( '', 'yes', 'no' ), true ) ) {
				wp_send_json_error( 'Onbekende stand' );
			}
			/* Leeg betekent "volg het hoofdproduct", en dat bestaat alleen bij een
			   variatie. Op een product zelf is leeg hetzelfde als nee; dat
			   opschrijven in plaats van een lege waarde laten staan scheelt een
			   raadsel bij het uitlezen. */
			if ( '' === $waarde && ! $product->is_type( 'variation' ) ) {
				$waarde = 'no';
			}
			update_post_meta( $id, WSS_AI_Bol::META_ACTIEF, $waarde );
			do_action( 'wss_bol_selectie_gewijzigd', $id, $waarde );
		} elseif ( 'prijs' === $veld ) {
			update_post_meta( $id, WSS_AI_Bol::META_PRIJS, wc_format_decimal( $waarde ) );
			do_action( 'wss_bol_selectie_gewijzigd', $id, WSS_AI_Bol::stand( $id ) );
		} else {
			wp_send_json_error( 'Unknown field' );
		}

		/* De verse stand terug, zodat het vlaggetje meteen meeloopt in plaats van
		   pas na een keer vernieuwen. */
		wp_send_json_success( self::regel( wc_get_product( $id ) ) );
	}
}
