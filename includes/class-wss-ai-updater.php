<?php
/**
 * Automatisch bijwerken via GitHub-releases.
 *
 * WordPress kijkt voor updates standaard op wordpress.org. Deze plugin staat
 * daar niet, dus haken we in op de plek waar WordPress zijn lijstje met
 * beschikbare updates samenstelt en zetten we onze eigen release erin.
 *
 * WAAROM DIT HET EERSTE IS DAT AF MOET
 * Dit is de noodrem. Gaat er ooit een versie uit die stukgaat op klantsites, dan
 * is een nieuwe release het enige dat je nog kunt doen -- je kunt niet bij hun
 * wp-admin. Werkt dit niet, dan zit een fout vast bij iedereen die hem heeft.
 *
 * DRIE DINGEN DIE HIER MISGAAN ALS JE ER NIET OP LET
 *  1. De map. GitHub pakt een release uit als "wss-ai-1.2.0"; WordPress verwacht
 *     "wss-ai". Zonder hernoemen komt de plugin naast zichzelf te staan en heb je
 *     hem ineens twee keer -- en de oude blijft actief.
 *  2. Rate limits. GitHub staat zonder inloggen 60 vragen per uur per IP toe.
 *     Vandaar de cache: zonder die cache vraagt elke paginaweergave het opnieuw.
 *  3. Stilte. Een mislukte controle mag nooit als "je bent bij" tellen. Dan zie
 *     je een groen vinkje terwijl er al drie versies zijn uitgekomen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSS_AI_Updater {

	/** Hoe lang we een antwoord van GitHub bewaren. */
	const CACHE_UREN = 6;

	private static $eigenaar = '';
	private static $repo     = '';

	public static function init( $eigenaar, $repo ) {
		self::$eigenaar = $eigenaar;
		self::$repo     = $repo;

		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'bied_update_aan' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'hernoem_map' ), 10, 4 );
	}

	/** Het pad waaronder WordPress deze plugin kent, bijv. "wss-ai/wss-ai.php". */
	private static function sleutel() {
		return plugin_basename( WSS_AI_BESTAND );
	}

	private static function cache_sleutel() {
		return 'wss_ai_release_' . md5( self::$eigenaar . '/' . self::$repo );
	}

	public static function vergeet_cache() {
		delete_site_transient( self::cache_sleutel() );
	}

	/**
	 * De laatste release ophalen.
	 *
	 * Geeft een array terug met wat we nodig hebben, of een WP_Error met een
	 * uitlegbare reden. Nooit gewoon `false`: dan weet de aanroeper niet of er
	 * geen release is of dat we er niet bij konden, en dat zijn twee heel
	 * verschillende dingen.
	 */
	private static function release( $vers = false ) {
		if ( ! $vers ) {
			$cache = get_site_transient( self::cache_sleutel() );
			if ( is_array( $cache ) ) {
				return $cache;
			}
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( self::$eigenaar ),
			rawurlencode( self::$repo )
		);

		$antwoord = wp_remote_get(
			$url,
			array(
				'timeout' => 12,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					/* GitHub weigert verzoeken zonder herkenbare afzender. */
					'User-Agent' => 'WSS-AI/' . WSS_AI_VERSIE . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $antwoord ) ) {
			return new WP_Error( 'geen-verbinding', $antwoord->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $antwoord );
		if ( 404 === $code ) {
			return new WP_Error( 'geen-release', __( 'Er staat nog geen release klaar.', 'wss-ai' ) );
		}
		if ( 403 === $code ) {
			return new WP_Error( 'te-druk', __( 'GitHub liet ons even niet toe. Probeer het later nog eens.', 'wss-ai' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP-statuscode */
			return new WP_Error( 'onverwacht', sprintf( __( 'GitHub antwoordde met code %d.', 'wss-ai' ), $code ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $antwoord ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return new WP_Error( 'onleesbaar', __( 'Het antwoord van GitHub was niet te lezen.', 'wss-ai' ) );
		}

		/* Een release heet meestal "v1.2.0"; WordPress vergelijkt op "1.2.0". */
		$versie = ltrim( (string) $data['tag_name'], 'vV' );

		/* Liever een meegeleverd zip-bestand dan de automatische zipball: dat
		   eerste bevat precies wat je hebt vrijgegeven, het tweede de hele repo
		   inclusief wat er niet in een plugin thuishoort. */
		$pakket = '';
		if ( ! empty( $data['assets'] ) && is_array( $data['assets'] ) ) {
			foreach ( $data['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] ) && substr( $asset['name'], -4 ) === '.zip' ) {
					$pakket = $asset['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $pakket && ! empty( $data['zipball_url'] ) ) {
			$pakket = $data['zipball_url'];
		}

		$uit = array(
			'versie'    => $versie,
			'pakket'    => $pakket,
			'uitgebracht' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'notities'  => isset( $data['body'] ) ? (string) $data['body'] : '',
			'url'       => isset( $data['html_url'] ) ? (string) $data['html_url'] : '',
		);

		set_site_transient( self::cache_sleutel(), $uit, self::CACHE_UREN * HOUR_IN_SECONDS );
		return $uit;
	}

	/**
	 * Wat er op de beheerpagina komt te staan.
	 *
	 * Drie soorten, en het verschil telt: 'ok' (bij), 'nieuw' (er is een update)
	 * en 'onbekend' (we konden het niet nakijken). Die laatste is met opzet geen
	 * variant van 'ok'.
	 */
	public static function stand() {
		$release = self::release();

		if ( is_wp_error( $release ) ) {
			return array(
				'soort' => 'onbekend',
				'tekst' => $release->get_error_message(),
			);
		}

		if ( version_compare( $release['versie'], WSS_AI_VERSIE, '>' ) ) {
			return array(
				'soort' => 'nieuw',
				/* translators: %s: versienummer */
				'tekst' => sprintf( __( 'Versie %s staat klaar. Je kunt hem bijwerken via Plugins.', 'wss-ai' ), $release['versie'] ),
			);
		}

		return array(
			'soort' => 'ok',
			'tekst' => __( 'Je hebt de laatste versie.', 'wss-ai' ),
		);
	}

	/**
	 * Onze release in het updatelijstje van WordPress zetten.
	 */
	public static function bied_update_aan( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = self::release();
		if ( is_wp_error( $release ) || empty( $release['pakket'] ) ) {
			return $transient;
		}

		$sleutel = self::sleutel();

		if ( version_compare( $release['versie'], WSS_AI_VERSIE, '>' ) ) {
			$item = new stdClass();
			$item->slug        = self::$repo;
			$item->plugin      = $sleutel;
			$item->new_version = $release['versie'];
			$item->url         = $release['url'];
			$item->package     = $release['pakket'];
			$item->tested      = get_bloginfo( 'version' );

			$transient->response[ $sleutel ] = $item;
			unset( $transient->no_update[ $sleutel ] );
		} else {
			/* Ook melden dat er GEEN update is. Zonder dit toont WordPress deze
			   plugin als "onbekend" op het updatescherm, en dat leest als kapot. */
			$item = new stdClass();
			$item->slug        = self::$repo;
			$item->plugin      = $sleutel;
			$item->new_version = WSS_AI_VERSIE;
			$item->url         = $release['url'];
			$item->package     = '';

			$transient->no_update[ $sleutel ] = $item;
		}

		return $transient;
	}

	/** De "details bekijken"-popup vullen. */
	public static function details( $resultaat, $actie, $args ) {
		if ( 'plugin_information' !== $actie || empty( $args->slug ) || self::$repo !== $args->slug ) {
			return $resultaat;
		}

		$release = self::release();
		if ( is_wp_error( $release ) ) {
			return $resultaat;
		}

		$info               = new stdClass();
		$info->name         = 'WSS AI';
		$info->slug         = self::$repo;
		$info->version      = $release['versie'];
		$info->author       = '<a href="https://webshopschool.nl">Webshopschool</a>';
		$info->homepage     = $release['url'];
		$info->download_link = $release['pakket'];
		$info->last_updated = $release['uitgebracht'];
		$info->sections     = array(
			'description' => wpautop( esc_html__( 'AI-gereedschap voor je webshop, beheerd door Webshopschool.', 'wss-ai' ) ),
			'changelog'   => wpautop( esc_html( $release['notities'] ) ),
		);

		return $info;
	}

	/**
	 * De uitgepakte map hernoemen naar de mapnaam die WordPress verwacht.
	 *
	 * Dit is het klassieke struikelblok bij updaten vanaf GitHub. Een zipball
	 * pakt uit als "Ecomscene-wss-ai-a1b2c3"; laat je dat staan, dan
	 * installeert WordPress een TWEEDE plugin naast de bestaande. De klant houdt
	 * dan de oude actief en snapt niet waarom de update niets deed.
	 */
	public static function hernoem_map( $bron, $bron_selectie, $upgrader, $extra = array() ) {
		global $wp_filesystem;

		if ( ! isset( $extra['plugin'] ) || $extra['plugin'] !== self::sleutel() ) {
			return $bron;
		}
		if ( ! $wp_filesystem ) {
			return $bron;
		}

		$gewenst = trailingslashit( $bron_selectie ) . dirname( self::sleutel() );
		if ( trailingslashit( $bron ) === trailingslashit( $gewenst ) ) {
			return $bron;
		}

		if ( $wp_filesystem->move( $bron, $gewenst, true ) ) {
			return trailingslashit( $gewenst );
		}

		return new WP_Error(
			'wss-ai-hernoemen',
			__( 'De update kon niet op de juiste plek worden gezet. Er is niets veranderd.', 'wss-ai' )
		);
	}
}
