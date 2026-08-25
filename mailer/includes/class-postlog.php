<?php
/**
 * Wat er verstuurd is, en waar het bleef als het niet aankwam.
 *
 * WAAROM DIT MEER IS DAN EEN TABEL
 * Een lijst met verstuurde mail is makkelijk. Het probleem doet zich voor als
 * die lijst leeg is, want dan vertelt hij niets. Er zijn dan minstens vijf
 * verschillende oorzaken en ze zien er van buiten allemaal hetzelfde uit:
 *
 *  - er is nooit iets in de wachtrij gezet
 *  - er staat wel iets, maar de achtergrondtaak draait niet
 *  - de taak draait, maar er is geen verzendmethode ingesteld
 *  - er wordt verstuurd, maar naar adressen die geblokkeerd staan
 *  - het gaat de deur uit en komt daarna pas ergens anders vast te zitten
 *
 * Daarom staat de doorlichting boven de tabel en niet ergens weggestopt. Wie
 * hier komt kijken heeft een vraag ("waar is mijn post"), en het scherm hoort
 * die vraag te beantwoorden, ook als het antwoord is dat er nooit iets
 * verstuurd is.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Postlog {

	const PER_PAGINA = 30;

	/**
	 * Blijft de wachtrij hier langer dan staan, dan loopt hij niet leeg.
	 *
	 * De taak draait elke vijf minuten en werkt vijftig regels per keer, dus
	 * een half uur is ruim: ook een grote verzending is dan al lang op gang.
	 */
	const TE_LANG = 1800;

	/**
	 * De standen die we kennen, met een leesbare naam.
	 *
	 * @return array
	 */
	public static function standen() {
		return array(
			'sent'       => __( 'Verstuurd', 'ws-flow-mailer' ),
			'failed'     => __( 'Mislukt', 'ws-flow-mailer' ),
			'stopped'    => __( 'Niet verstuurd', 'ws-flow-mailer' ),
			'bounced'    => __( 'Bounce', 'ws-flow-mailer' ),
			'complained' => __( 'Klacht', 'ws-flow-mailer' ),
		);
	}

	/**
	 * Hoeveel regels er per stand zijn.
	 *
	 * @return array stand => aantal
	 */
	public static function aantallen() {
		global $wpdb;

		$log   = WSFM_Queue::log_table();
		$rijen = $wpdb->get_results( "SELECT status, COUNT(*) AS aantal FROM {$log} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$uit = array();
		foreach ( (array) $rijen as $rij ) {
			$uit[ $rij->status ] = (int) $rij->aantal;
		}

		return $uit;
	}

	/**
	 * Regels uit het log, gefilterd en per pagina.
	 *
	 * @param array $opties stand, zoek, pagina.
	 * @return array { rijen, totaal, paginas, pagina }
	 */
	public static function regels( array $opties = array() ) {
		global $wpdb;

		$stand  = isset( $opties['stand'] ) ? (string) $opties['stand'] : '';
		$zoek   = isset( $opties['zoek'] ) ? trim( (string) $opties['zoek'] ) : '';
		$pagina = max( 1, isset( $opties['pagina'] ) ? (int) $opties['pagina'] : 1 );

		$log         = WSFM_Queue::log_table();
		$queue       = WSFM_Queue::table();
		$flows       = $wpdb->prefix . 'wsfm_flows';
		$templates   = $wpdb->prefix . 'wsfm_templates';
		$newsletters = $wpdb->prefix . 'wsfm_newsletters';

		$waar = array();
		$args = array();

		if ( '' !== $stand && isset( self::standen()[ $stand ] ) ) {
			$waar[] = 'l.status = %s';
			$args[] = $stand;
		}

		if ( '' !== $zoek ) {
			$waar[] = 'l.recipient LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $zoek ) . '%';
		}

		$waar_sql = $waar ? 'WHERE ' . implode( ' AND ', $waar ) : '';

		$telling = "SELECT COUNT(*) FROM {$log} l {$waar_sql}";
		$totaal  = (int) ( $args
			? $wpdb->get_var( $wpdb->prepare( $telling, $args ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
			: $wpdb->get_var( $telling ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		$paginas = max( 1, (int) ceil( $totaal / self::PER_PAGINA ) );
		$pagina  = min( $pagina, $paginas );

		$haal   = $args;
		$haal[] = self::PER_PAGINA;
		$haal[] = ( $pagina - 1 ) * self::PER_PAGINA;

		/* COALESCE zodat een nieuwsbriefregel zijn eigen naam toont in plaats
		   van een leeg vakje dat op een fout lijkt. */
		$rijen = $wpdb->get_results( $wpdb->prepare( "SELECT l.*, COALESCE(f.name, n.name) AS bron_naam,
				COALESCE(f.trigger_type, IF(n.id IS NULL, NULL, 'nieuwsbrief')) AS bron_soort,
				COALESCE(t.name, n.name) AS sjabloon_naam
			FROM {$log} l
			LEFT JOIN {$queue} q ON q.id = l.queue_id
			LEFT JOIN {$flows} f ON f.id = q.flow_id
			LEFT JOIN {$newsletters} n ON n.id = q.newsletter_id
			LEFT JOIN {$templates} t ON t.id = l.template_id
			{$waar_sql}
			ORDER BY l.id DESC LIMIT %d OFFSET %d", $haal ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rijen'   => (array) $rijen,
			'totaal'  => $totaal,
			'paginas' => $paginas,
			'pagina'  => $pagina,
		);
	}

	/**
	 * Hoe de wachtrij ervoor staat.
	 *
	 * @return array { wacht, bezig, oudste (unix of 0) }
	 */
	public static function wachtrij() {
		global $wpdb;

		$queue = WSFM_Queue::table();

		$oudste = $wpdb->get_var( "SELECT MIN(scheduled_at) FROM {$queue} WHERE status IN ('pending','processing')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'wacht'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'pending'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'bezig'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue} WHERE status = 'processing'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'oudste' => $oudste ? (int) mysql2date( 'U', $oudste ) : 0,
		);
	}

	/**
	 * Wanneer er voor het laatst iets echt de deur uit ging.
	 *
	 * @return int Unix-tijd, of 0.
	 */
	public static function laatst_verstuurd() {
		global $wpdb;

		$log = WSFM_Queue::log_table();
		$op  = $wpdb->get_var( "SELECT MAX(sent_at) FROM {$log} WHERE status = 'sent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $op ? (int) mysql2date( 'U', $op ) : 0;
	}

	/**
	 * De hele keten langslopen en per schakel zeggen hoe hij ervoor staat.
	 *
	 * Losgekoppeld van het scherm zodat hij na te tellen is zonder browser.
	 * De volgorde is de volgorde van de keten zelf: verzendmethode, planner,
	 * taak, wachtrij. Wie van boven naar beneden leest komt de eerste kapotte
	 * schakel het eerst tegen, en dat is waar hij moet zijn.
	 *
	 * @param array $gegevens Overschrijft de metingen; alleen voor de controle.
	 * @return array lijst van array( stand, kop, uitleg )
	 */
	public static function doorlichting( array $gegevens = array() ) {
		$nu = isset( $gegevens['nu'] ) ? (int) $gegevens['nu'] : time();

		/* Een voor een, en alleen wat niet is meegegeven. Met array_merge zou
		   elke meting alsnog gedaan worden voordat de meegegeven waarde hem
		   overschrijft: vier queries om ze daarna weg te gooien, en niet na te
		   tellen zonder database. */
		$meting = $gegevens;

		if ( ! isset( $meting['provider_fout'] ) ) {
			$meting['provider_fout'] = self::provider_fout();
		}
		if ( ! isset( $meting['planner'] ) ) {
			$meting['planner'] = function_exists( 'as_has_scheduled_action' );
		}
		if ( ! isset( $meting['volgende_run'] ) ) {
			$meting['volgende_run'] = self::volgende_run();
		}
		if ( ! isset( $meting['wachtrij'] ) ) {
			$meting['wachtrij'] = self::wachtrij();
		}
		if ( ! isset( $meting['laatst'] ) ) {
			$meting['laatst'] = self::laatst_verstuurd();
		}
		if ( ! isset( $meting['geblokkeerd'] ) ) {
			$meting['geblokkeerd'] = class_exists( 'WSFM_Suppression' ) ? WSFM_Suppression::count() : 0;
		}

		$uit = array();

		/* 1. Zonder verzendmethode gebeurt er niets, en dan zegt de rest niets. */
		if ( '' !== $meting['provider_fout'] ) {
			$uit[] = array(
				'stand'  => 'fout',
				'kop'    => __( 'Er is geen verzendmethode ingesteld', 'ws-flow-mailer' ),
				'uitleg' => $meting['provider_fout'],
			);
		} else {
			$uit[] = array(
				'stand'  => 'goed',
				'kop'    => __( 'De verzendmethode staat klaar', 'ws-flow-mailer' ),
				'uitleg' => '',
			);
		}

		/* 2. De planner komt van WooCommerce mee. Ontbreekt hij, dan draait er
		   niets op de achtergrond en blijft alles staan waar het staat. */
		if ( ! $meting['planner'] ) {
			$uit[] = array(
				'stand'  => 'fout',
				'kop'    => __( 'De achtergrondplanner ontbreekt', 'ws-flow-mailer' ),
				'uitleg' => __( 'Action Scheduler komt met WooCommerce mee. Zonder die planner wordt er nooit iets verstuurd. Controleer of WooCommerce actief is.', 'ws-flow-mailer' ),
			);
		} elseif ( ! $meting['volgende_run'] ) {
			$uit[] = array(
				'stand'  => 'fout',
				'kop'    => __( 'De verzendtaak staat niet ingepland', 'ws-flow-mailer' ),
				'uitleg' => __( 'De taak die de wachtrij leegmaakt is er niet. Meestal is hij weg na een probleem met de planner. Zet de plugin uit en weer aan, dan wordt hij opnieuw aangemeld.', 'ws-flow-mailer' ),
			);
		} else {
			$uit[] = array(
				'stand'  => 'goed',
				'kop'    => sprintf(
					/* translators: %s: hoe lang nog, bijvoorbeeld "3 minuten". */
					__( 'De verzendtaak draait weer over %s', 'ws-flow-mailer' ),
					human_time_diff( $nu, max( $meting['volgende_run'], $nu ) )
				),
				'uitleg' => '',
			);
		}

		/* 3. En dan de wachtrij zelf: staat er iets, en zakt het ook weg? */
		$wachtrij = $meting['wachtrij'];
		$totaal   = (int) $wachtrij['wacht'] + (int) $wachtrij['bezig'];
		$staat_al = $wachtrij['oudste'] ? $nu - (int) $wachtrij['oudste'] : 0;

		if ( 0 === $totaal ) {
			$uit[] = array(
				'stand'  => 'goed',
				'kop'    => __( 'Er staat niets in de wachtrij', 'ws-flow-mailer' ),
				'uitleg' => $meting['laatst']
					? sprintf(
						/* translators: %s: hoe lang geleden. */
						__( 'De laatste mail ging %s geleden de deur uit.', 'ws-flow-mailer' ),
						human_time_diff( (int) $meting['laatst'], $nu )
					)
					: __( 'Er is met deze plugin nog nooit een mail verstuurd.', 'ws-flow-mailer' ),
			);
		} elseif ( $staat_al > self::TE_LANG ) {
			$uit[] = array(
				'stand'  => 'fout',
				'kop'    => sprintf(
					/* translators: 1: aantal, 2: hoe lang. */
					__( '%1$s mails staan te wachten, de oudste al %2$s', 'ws-flow-mailer' ),
					number_format_i18n( $totaal ),
					human_time_diff( (int) $wachtrij['oudste'], $nu )
				),
				'uitleg' => __( 'De wachtrij loopt niet leeg. Dat komt bijna altijd doordat de achtergrondtaken van WooCommerce stilstaan; kijk bij WooCommerce, Status, Geplande acties of daar iets vastloopt.', 'ws-flow-mailer' ),
			);
		} else {
			$uit[] = array(
				'stand'  => 'goed',
				'kop'    => sprintf(
					/* translators: %s: aantal. */
					__( '%s mails staan klaar om verstuurd te worden', 'ws-flow-mailer' ),
					number_format_i18n( $totaal )
				),
				'uitleg' => __( 'Ze gaan er in porties uit, zodat ze niet als spam gezien worden.', 'ws-flow-mailer' ),
			);
		}

		/* 4. Geblokkeerde adressen: geen fout, wel de verklaring als iemand
		   zegt dat juist hij niets krijgt. */
		if ( $meting['geblokkeerd'] > 0 ) {
			$uit[] = array(
				'stand'  => 'let-op',
				'kop'    => sprintf(
					/* translators: %s: aantal. */
					__( '%s adressen zijn geblokkeerd', 'ws-flow-mailer' ),
					number_format_i18n( (int) $meting['geblokkeerd'] )
				),
				'uitleg' => __( 'Naar die adressen sturen we niets meer, omdat de mail eerder weigerde of als spam gemeld werd. Ze staan hieronder als "Niet verstuurd".', 'ws-flow-mailer' ),
			);
		}

		return $uit;
	}

	/**
	 * Wat er mis is met de verzendmethode, of een lege tekst.
	 *
	 * @return string
	 */
	private static function provider_fout() {
		if ( ! class_exists( 'WSFM_Provider_Factory' ) ) {
			return __( 'De verzendmodule is niet geladen.', 'ws-flow-mailer' );
		}

		$provider = WSFM_Provider_Factory::create();

		return is_wp_error( $provider ) ? $provider->get_error_message() : '';
	}

	/**
	 * Wanneer de verzendtaak weer draait.
	 *
	 * @return int Unix-tijd, of 0.
	 */
	private static function volgende_run() {
		if ( ! function_exists( 'as_next_scheduled_action' ) ) {
			return 0;
		}

		$wanneer = as_next_scheduled_action( WSFM_Flow_Engine::HOOK_PROCESS_QUEUE, array(), WSFM_Flow_Engine::AS_GROUP );

		/* Action Scheduler geeft true terug als er wel een actie staat maar hij
		   het tijdstip niet kan vertellen. Dat is geen tijd, en als getal
		   gebruikt zou het 1970 worden. */
		return is_numeric( $wanneer ) ? (int) $wanneer : 0;
	}
}
