<?php
/**
 * Admin UI: menu structure, dashboard, flow builder and template editor.
 * Uses WordPress's own admin styling (.wrap, widefat tables, postboxes).
 *
 * All actions are guarded by nonce + manage_woocommerce.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Flow_Admin_UI {

	const CAPABILITY     = 'manage_woocommerce';
	const SLUG_DASHBOARD = 'ws-flow-mailer';
	const SLUG_BRIEVEN   = 'ws-flow-mailer-nieuwsbrieven';
	const SLUG_POPUP     = 'ws-flow-mailer-popup';
	const SLUG_ABONNEES  = 'ws-flow-mailer-inschrijvingen';
	const SLUG_FLOWS     = 'ws-flow-mailer-flows';
	const SLUG_TEMPLATES = 'ws-flow-mailer-templates';

	/** Inschrijvingen per pagina in het overzicht. */
	const PER_PAGINA = 50;

	/** Hoogste aantal regels dat één import verwerkt. */
	const MAX_IMPORT = 20000;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );

		add_action( 'admin_post_wsfm_save_newsletter', array( $this, 'handle_save_newsletter' ) );
		add_action( 'admin_post_wsfm_delete_newsletter', array( $this, 'handle_delete_newsletter' ) );
		add_action( 'admin_post_wsfm_duplicate_newsletter', array( $this, 'handle_duplicate_newsletter' ) );
		add_action( 'admin_post_wsfm_send_newsletter', array( $this, 'handle_send_newsletter' ) );

		add_action( 'admin_post_wsfm_save_popup', array( $this, 'handle_save_popup' ) );

		add_action( 'admin_post_wsfm_import_inschrijvingen', array( $this, 'handle_import_inschrijvingen' ) );
		add_action( 'admin_post_wsfm_delete_inschrijving', array( $this, 'handle_delete_inschrijving' ) );
		add_action( 'admin_post_wsfm_export_inschrijvingen', array( $this, 'handle_export_inschrijvingen' ) );

		add_action( 'admin_post_wsfm_save_flow', array( $this, 'handle_save_flow' ) );
		add_action( 'admin_post_wsfm_delete_flow', array( $this, 'handle_delete_flow' ) );
		add_action( 'admin_post_wsfm_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_wsfm_delete_template', array( $this, 'handle_delete_template' ) );

		add_action( 'wp_ajax_wsfm_toggle_flow', array( $this, 'ajax_toggle_flow' ) );
		add_action( 'wp_ajax_wsfm_preview_template', array( $this, 'ajax_preview_template' ) );
		add_action( 'wp_ajax_wsfm_send_test_template', array( $this, 'ajax_send_test_template' ) );
		add_action( 'wp_ajax_wsfm_preview_newsletter', array( $this, 'ajax_preview_newsletter' ) );
		add_action( 'wp_ajax_wsfm_test_newsletter', array( $this, 'ajax_test_newsletter' ) );
		add_action( 'wp_ajax_wsfm_count_audience', array( $this, 'ajax_count_audience' ) );
		add_action( 'wp_ajax_wsfm_preview_popup_mail', array( $this, 'ajax_preview_popup_mail' ) );
		add_action( 'wp_ajax_wsfm_test_popup_mail', array( $this, 'ajax_test_popup_mail' ) );
	}

	/**
	 * Menu: WS Flow Mailer → Dashboard / Flows / Templates
	 * (the settings submenu is registered by WSFM_Admin_Settings).
	 */
	public function register_menu() {
		/* Een eigen hoofdmenu-item, net onder Voorraadbeheer.
		   Onder WSS Tools staat alleen een LINK hierheen. Dat is met opzet geen
		   tweede registratie van dezelfde pagina: twee ouders voor een slug maakt
		   de haaknaam die WordPress opzoekt afhankelijk van welke ouder hij
		   toevallig vindt, en dan krijg je een lege pagina op de helft van de
		   klikken. Diezelfde val kostte eerder het bulkscherm.

		   Positie 56.2, dus direct onder het voorraadbeheer op 56.1, en daarmee
		   allebei onder Producten. */
		add_menu_page(
			__( 'Nieuwsbrief & Flows', 'ws-flow-mailer' ),
			__( 'Nieuwsbrief & Flows', 'ws-flow-mailer' ),
			self::CAPABILITY,
			self::SLUG_DASHBOARD,
			array( $this, 'render_dashboard' ),
			'dashicons-email-alt',
			'56.2'
		);
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Overzicht', 'ws-flow-mailer' ), __( 'Overzicht', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_DASHBOARD, array( $this, 'render_dashboard' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Nieuwsbrieven', 'ws-flow-mailer' ), __( 'Nieuwsbrieven', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_BRIEVEN, array( $this, 'render_newsletters' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Popup', 'ws-flow-mailer' ), __( 'Popup', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_POPUP, array( $this, 'render_popup' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Inschrijvingen', 'ws-flow-mailer' ), __( 'Inschrijvingen', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_ABONNEES, array( $this, 'render_inschrijvingen' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Flows', 'ws-flow-mailer' ), __( 'Flows', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_FLOWS, array( $this, 'render_flows' ) );
		add_submenu_page( self::SLUG_DASHBOARD, __( 'Templates', 'ws-flow-mailer' ), __( 'Templates', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_TEMPLATES, array( $this, 'render_templates' ) );
	}

	/**
	 * Capability gate for every page/action.
	 */
	private function require_capability() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'ws-flow-mailer' ) );
		}
	}

	/**
	 * Mask a recipient for display: j***@voorbeeld.nl.
	 *
	 * @param string $email E-mail address.
	 * @return string
	 */
	public static function mask_email( $email ) {
		$at = strpos( (string) $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}
		return substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/* ---------------------------------------------------------------------
	 * Page renderers
	 * ------------------------------------------------------------------- */

	/**
	 * Dashboard: stat cards + per-flow table + recent log.
	 */
	public function render_dashboard() {
		$this->require_capability();

		$trigger_filter = isset( $_GET['trigger'] ) ? sanitize_key( wp_unslash( $_GET['trigger'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $trigger_filter, WSFM_Flows::TRIGGER_TYPES, true ) ) {
			$trigger_filter = '';
		}

		$stats      = WSFM_Queue::get_stats( 30 );
		$flow_stats = WSFM_Queue::get_stats_per_flow( 30 );
		$recent     = WSFM_Queue::get_recent_log( 20, $trigger_filter ? $trigger_filter : null );

		include WSFM_PLUGIN_DIR . 'admin/dashboard-page.php';
	}

	/**
	 * Nieuwsbrieven: lijst of samensteller, afhankelijk van ?action.
	 */
	public function render_newsletters() {
		$this->require_capability();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'new' === $action || 'edit' === $action ) {
			$brief_id = isset( $_GET['nieuwsbrief'] ) ? (int) $_GET['nieuwsbrief'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$brief    = $brief_id ? WSFM_Newsletters::get( $brief_id ) : null;

			if ( 'edit' === $action && ! $brief ) {
				wp_die( esc_html__( 'Nieuwsbrief niet gevonden.', 'ws-flow-mailer' ) );
			}

			$sjablonen   = WSFM_Newsletter_Render::templates();
			$doelgroepen = WSFM_Newsletters::doelgroepen();
			$voortgang   = $brief && 'concept' !== $brief->status ? WSFM_Newsletters::voortgang( $brief->id ) : null;

			include WSFM_PLUGIN_DIR . 'admin/newsletter-edit-page.php';
			return;
		}

		$brieven = WSFM_Newsletters::get_all();
		include WSFM_PLUGIN_DIR . 'admin/newsletters-page.php';
	}

	/**
	 * De popup instellen.
	 */
	public function render_popup() {
		$this->require_capability();

		$i         = WSFM_Popup::instellingen();
		$sjablonen = WSFM_Newsletter_Render::templates();
		$recent    = WSFM_Subscribers::recent( 15 );
		$aantal    = WSFM_Subscribers::aantal();

		include WSFM_PLUGIN_DIR . 'admin/popup-page.php';
	}

	/**
	 * De popup-instellingen opslaan.
	 */
	public function handle_save_popup() {
		$this->require_capability();
		check_admin_referer( 'wsfm_save_popup' );

		WSFM_Popup::opslaan( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- veld voor veld opgeschoond in opslaan().

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_POPUP, 'wsfm-saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Inschrijvingen
	 * ------------------------------------------------------------------- */

	/**
	 * De lijst met inschrijvingen, met zoeken, bladeren en importeren.
	 */
	public function render_inschrijvingen() {
		$this->require_capability();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- alleen lezen en bladeren.
		$zoek   = isset( $_GET['zoek'] ) ? sanitize_text_field( wp_unslash( $_GET['zoek'] ) ) : '';
		$pagina = isset( $_GET['paged'] ) && is_numeric( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$totaal    = WSFM_Subscribers::aantal( $zoek );
		$paginas   = max( 1, (int) ceil( $totaal / self::PER_PAGINA ) );
		$pagina    = min( $pagina, $paginas );
		$rijen     = WSFM_Subscribers::lijst( self::PER_PAGINA, $pagina, $zoek );
		$alle      = WSFM_Subscribers::aantal();

		include WSFM_PLUGIN_DIR . 'admin/inschrijvingen-page.php';
	}

	/**
	 * Adressen erbij zetten, uit een bestand of geplakt.
	 */
	public function handle_import_inschrijvingen() {
		$this->require_capability();
		check_admin_referer( 'wsfm_import_inschrijvingen' );

		/* Zonder dit vinkje niet importeren. Een lijst inladen betekent dat er
		   post uitgaat naar mensen die daar bij DEZE winkel niet om gevraagd
		   hebben, en dat is geen technische maar een juridische fout: die kost
		   de ondernemer een boete en ons zijn afzenderreputatie. */
		if ( empty( $_POST['toestemming'] ) ) {
			$this->terug_naar_inschrijvingen(
				array( 'wsfm-error' => rawurlencode( __( 'Zet eerst het vinkje dat deze mensen zich bij jou hebben aangemeld.', 'ws-flow-mailer' ) ) )
			);
		}

		$regels = array();

		if ( isset( $_POST['plakken'] ) ) {
			$plak   = sanitize_textarea_field( wp_unslash( $_POST['plakken'] ) );
			$regels = preg_split( '/\r\n|\r|\n/', $plak );
		}

		$uit_bestand = $this->regels_uit_upload();
		if ( is_wp_error( $uit_bestand ) ) {
			$this->terug_naar_inschrijvingen( array( 'wsfm-error' => rawurlencode( $uit_bestand->get_error_message() ) ) );
		}

		$regels = array_merge( (array) $regels, $uit_bestand );

		if ( count( $regels ) > self::MAX_IMPORT ) {
			$regels = array_slice( $regels, 0, self::MAX_IMPORT );
		}

		$telling = WSFM_Subscribers::importeer( WSFM_Subscribers::ontleed( $regels ) );

		$this->terug_naar_inschrijvingen(
			array(
				'wsfm-toegevoegd' => (int) $telling['toegevoegd'],
				'wsfm-bestond'    => (int) $telling['bestond'],
				'wsfm-ongeldig'   => (int) $telling['ongeldig'],
				'wsfm-afgemeld'   => (int) $telling['afgemeld'],
			)
		);
	}

	/**
	 * De regels uit het geüploade bestand.
	 *
	 * Het bestand wordt gelezen waar PHP het al heeft neergezet en nergens
	 * heen gekopieerd. Deze plugin draait op winkels van klanten en hoort daar
	 * geen bestanden achter te laten.
	 *
	 * @return array|WP_Error
	 */
	private function regels_uit_upload() {
		if ( ! isset( $_FILES['bestand'] ) || ! isset( $_FILES['bestand']['error'] ) ) {
			return array();
		}

		$fout = (int) $_FILES['bestand']['error'];

		if ( UPLOAD_ERR_NO_FILE === $fout ) {
			return array();
		}
		if ( UPLOAD_ERR_OK !== $fout ) {
			return new WP_Error( 'wsfm_upload', __( 'Het bestand kwam niet goed binnen. Probeer het nog eens, of plak de adressen in het vak eronder.', 'ws-flow-mailer' ) );
		}

		$pad = isset( $_FILES['bestand']['tmp_name'] ) ? sanitize_text_field( $_FILES['bestand']['tmp_name'] ) : '';

		if ( '' === $pad || ! is_uploaded_file( $pad ) ) {
			return new WP_Error( 'wsfm_upload', __( 'Het bestand kon niet gelezen worden.', 'ws-flow-mailer' ) );
		}

		/* Twee megabyte is ruim honderdduizend adressen. Wat groter is, is geen
		   adressenlijst maar een vergissing, en die hoeven we niet in het
		   geheugen van een webshop te trekken. */
		if ( filesize( $pad ) > 2 * MB_IN_BYTES ) {
			return new WP_Error( 'wsfm_upload', __( 'Dat bestand is te groot. Splits het op, of laat er alleen de e-mailadressen in staan.', 'ws-flow-mailer' ) );
		}

		$handvat = fopen( $pad, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- tijdelijk bestand van PHP zelf, geen bestand op de site.
		if ( ! $handvat ) {
			return new WP_Error( 'wsfm_upload', __( 'Het bestand kon niet gelezen worden.', 'ws-flow-mailer' ) );
		}

		$regels = array();

		while ( count( $regels ) < self::MAX_IMPORT ) {
			$regel = fgets( $handvat ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			if ( false === $regel ) {
				break;
			}
			$regels[] = $regel;
		}

		fclose( $handvat ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		/* Excel zet drie onzichtbare tekens vooraan het bestand. Zonder dit
		   hoort het eerste adres bij niemand, want het staat er met rommel
		   ervoor en is dan geen geldig adres meer. */
		if ( ! empty( $regels ) ) {
			$regels[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $regels[0] );
		}

		return $regels;
	}

	/**
	 * Eén inschrijving weghalen.
	 */
	public function handle_delete_inschrijving() {
		$this->require_capability();
		check_admin_referer( 'wsfm_delete_inschrijving' );

		$id = isset( $_POST['inschrijving_id'] ) && is_numeric( $_POST['inschrijving_id'] ) ? (int) $_POST['inschrijving_id'] : 0;
		if ( $id > 0 ) {
			WSFM_Subscribers::verwijder( $id );
		}

		$this->terug_naar_inschrijvingen( array( 'wsfm-deleted' => '1' ) );
	}

	/**
	 * De hele lijst als bestand.
	 *
	 * Puntkomma's als scheidingsteken: Excel opent een bestand met komma's in
	 * Nederland als één kolom, en dan denkt de winkelier dat de export stuk is.
	 */
	public function handle_export_inschrijvingen() {
		$this->require_capability();
		check_admin_referer( 'wsfm_export_inschrijvingen' );

		$bestand = 'inschrijvingen-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $bestand );

		$uit = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- geen bestand, maar het antwoord zelf.

		/* Dezelfde onzichtbare tekens die we bij het inlezen weghalen, zetten we
		   er bij het uitschrijven juist voor: zonder die is een e met een accent
		   in Excel een blokje. */
		fwrite( $uit, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $uit, array( 'email', 'voornaam', 'bron', 'kortingscode', 'ingeschreven op' ), ';' );

		$pagina = 1;
		do {
			$rijen = WSFM_Subscribers::lijst( 500, $pagina );
			foreach ( $rijen as $rij ) {
				fputcsv(
					$uit,
					array( $rij->email, $rij->first_name, $rij->source, $rij->coupon_code, $rij->created_at ),
					';'
				);
			}
			$pagina++;
		} while ( count( $rijen ) === 500 );

		fclose( $uit ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Terug naar de inschrijvingen, met een boodschap in de URL.
	 *
	 * @param array $args Extra queryargumenten.
	 */
	private function terug_naar_inschrijvingen( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG_ABONNEES ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Flows: list or edit view depending on ?action.
	 */
	public function render_flows() {
		$this->require_capability();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'new' === $action || 'edit' === $action ) {
			$flow_id = isset( $_GET['flow'] ) ? (int) $_GET['flow'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$flow    = $flow_id ? WSFM_Flows::get( $flow_id ) : null;

			if ( 'edit' === $action && ! $flow ) {
				wp_die( esc_html__( 'Flow niet gevonden.', 'ws-flow-mailer' ) );
			}

			$templates     = WSFM_Templates::get_all();
			$pending_count = $flow ? $this->pending_count_for_flow( $flow->id ) : 0;

			include WSFM_PLUGIN_DIR . 'admin/flow-edit-page.php';
			return;
		}

		$flows = WSFM_Flows::get_all();
		include WSFM_PLUGIN_DIR . 'admin/flows-page.php';
	}

	/**
	 * Templates: list or edit view depending on ?action.
	 */
	public function render_templates() {
		$this->require_capability();

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'new' === $action || 'edit' === $action ) {
			$template_id = isset( $_GET['template'] ) ? (int) $_GET['template'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$template    = $template_id ? WSFM_Templates::get( $template_id ) : null;

			if ( 'edit' === $action && ! $template ) {
				wp_die( esc_html__( 'Template niet gevonden.', 'ws-flow-mailer' ) );
			}

			include WSFM_PLUGIN_DIR . 'admin/template-edit-page.php';
			return;
		}

		$templates = WSFM_Templates::get_all();
		include WSFM_PLUGIN_DIR . 'admin/templates-page.php';
	}

	/**
	 * Pending/processing queue items for a flow (used for the trigger-type
	 * change warning in the editor).
	 *
	 * @param int $flow_id Flow id.
	 * @return int
	 */
	private function pending_count_for_flow( $flow_id ) {
		global $wpdb;

		$table = WSFM_Queue::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE flow_id = %d AND status IN ('pending','processing')", $flow_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Form handlers (admin-post.php)
	 * ------------------------------------------------------------------- */

	/**
	 * Een nieuwsbrief opslaan.
	 */
	public function handle_save_newsletter() {
		$this->require_capability();
		check_admin_referer( 'wsfm_save_newsletter' );

		$blokken = isset( $_POST['blokken'] ) && is_array( $_POST['blokken'] )
			? wp_unslash( $_POST['blokken'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- opgeschoond in WSFM_Newsletters::schoon_blokken().
			: array();

		$result = WSFM_Newsletters::save(
			array(
				'id'       => isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0,
				'name'     => isset( $_POST['nieuwsbrief_naam'] ) ? sanitize_text_field( wp_unslash( $_POST['nieuwsbrief_naam'] ) ) : '',
				'subject'  => isset( $_POST['nieuwsbrief_onderwerp'] ) ? sanitize_text_field( wp_unslash( $_POST['nieuwsbrief_onderwerp'] ) ) : '',
				'template' => isset( $_POST['nieuwsbrief_sjabloon'] ) ? sanitize_key( wp_unslash( $_POST['nieuwsbrief_sjabloon'] ) ) : '',
				'audience' => isset( $_POST['nieuwsbrief_doelgroep'] ) ? sanitize_key( wp_unslash( $_POST['nieuwsbrief_doelgroep'] ) ) : '',
				'blocks'   => $blokken,
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->terug_naar_brieven( array( 'wsfm-error' => rawurlencode( $result->get_error_message() ) ) );
		}

		$this->terug_naar_brieven(
			array(
				'action'      => 'edit',
				'nieuwsbrief' => $result,
				'wsfm-saved'  => '1',
			)
		);
	}

	/**
	 * Een concept weggooien.
	 */
	public function handle_delete_newsletter() {
		$this->require_capability();
		check_admin_referer( 'wsfm_delete_newsletter' );

		$id     = isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0;
		$result = $id ? WSFM_Newsletters::delete( $id ) : true;

		if ( is_wp_error( $result ) ) {
			$this->terug_naar_brieven( array( 'wsfm-error' => rawurlencode( $result->get_error_message() ) ) );
		}

		$this->terug_naar_brieven( array( 'wsfm-deleted' => '1' ) );
	}

	/**
	 * Een kopie maken. Zo kun je een verstuurde brief hergebruiken zonder de
	 * geschiedenis aan te tasten.
	 */
	public function handle_duplicate_newsletter() {
		$this->require_capability();
		check_admin_referer( 'wsfm_duplicate_newsletter' );

		$id     = isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0;
		$result = $id ? WSFM_Newsletters::duplicate( $id ) : new WP_Error( 'wsfm_nb_weg', __( 'Onbekende nieuwsbrief.', 'ws-flow-mailer' ) );

		if ( is_wp_error( $result ) ) {
			$this->terug_naar_brieven( array( 'wsfm-error' => rawurlencode( $result->get_error_message() ) ) );
		}

		$this->terug_naar_brieven( array( 'action' => 'edit', 'nieuwsbrief' => $result ) );
	}

	/**
	 * Versturen.
	 */
	public function handle_send_newsletter() {
		$this->require_capability();
		check_admin_referer( 'wsfm_send_newsletter' );

		$id     = isset( $_POST['nieuwsbrief_id'] ) ? (int) $_POST['nieuwsbrief_id'] : 0;
		$result = $id ? WSFM_Newsletters::verstuur( $id ) : new WP_Error( 'wsfm_nb_weg', __( 'Onbekende nieuwsbrief.', 'ws-flow-mailer' ) );

		if ( is_wp_error( $result ) ) {
			$this->terug_naar_brieven(
				array(
					'action'      => 'edit',
					'nieuwsbrief' => $id,
					'wsfm-error'  => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->terug_naar_brieven(
			array(
				'action'      => 'edit',
				'nieuwsbrief' => $id,
				'wsfm-sent'   => (int) $result,
			)
		);
	}

	/**
	 * Terug naar het nieuwsbriefscherm, met een boodschap in de URL.
	 *
	 * @param array $args Extra queryargumenten.
	 */
	private function terug_naar_brieven( array $args ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::SLUG_BRIEVEN ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save a flow (create or update).
	 */
	public function handle_save_flow() {
		$this->require_capability();
		check_admin_referer( 'wsfm_save_flow' );

		$steps = array();
		if ( isset( $_POST['steps'] ) && is_array( $_POST['steps'] ) ) {
			foreach ( wp_unslash( $_POST['steps'] ) as $step ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$value = isset( $step['wait_value'] ) ? max( 0, (int) $step['wait_value'] ) : 0;
				$unit  = isset( $step['wait_unit'] ) ? $step['wait_unit'] : 'hours';

				$multipliers = array(
					'minutes' => 1,
					'hours'   => 60,
					'days'    => 1440,
				);
				$multiplier  = isset( $multipliers[ $unit ] ) ? $multipliers[ $unit ] : 60;

				$steps[] = array(
					'wait_minutes'  => $value * $multiplier,
					'template_id'   => isset( $step['template_id'] ) ? (int) $step['template_id'] : 0,
					'stop_on_order' => ! empty( $step['stop_on_order'] ),
				);
			}
		}

		$result = WSFM_Flows::save(
			array(
				'id'           => isset( $_POST['flow_id'] ) ? (int) $_POST['flow_id'] : 0,
				'name'         => isset( $_POST['flow_name'] ) ? sanitize_text_field( wp_unslash( $_POST['flow_name'] ) ) : '',
				'trigger_type' => isset( $_POST['trigger_type'] ) ? sanitize_key( wp_unslash( $_POST['trigger_type'] ) ) : '',
				'status'       => ! empty( $_POST['flow_active'] ) ? 'active' : 'paused',
				'steps'        => $steps,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_FLOWS, 'wsfm-error' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_FLOWS, 'wsfm-saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Delete a flow.
	 */
	public function handle_delete_flow() {
		$this->require_capability();
		check_admin_referer( 'wsfm_delete_flow' );

		$flow_id = isset( $_POST['flow_id'] ) ? (int) $_POST['flow_id'] : 0;
		if ( $flow_id ) {
			WSFM_Flows::delete( $flow_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_FLOWS, 'wsfm-deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Save a template (create or update).
	 */
	public function handle_save_template() {
		$this->require_capability();
		check_admin_referer( 'wsfm_save_template' );

		$result = WSFM_Templates::save(
			array(
				'id'        => isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0,
				'name'      => isset( $_POST['template_name'] ) ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '',
				'subject'   => isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '',
				'html_body' => isset( $_POST['template_body'] ) ? wp_unslash( $_POST['template_body'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in WSFM_Templates::save().
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_TEMPLATES, 'wsfm-error' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_TEMPLATES, 'action' => 'edit', 'template' => $result, 'wsfm-saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Delete a template (refused while a flow uses it).
	 */
	public function handle_delete_template() {
		$this->require_capability();
		check_admin_referer( 'wsfm_delete_template' );

		$template_id = isset( $_POST['template_id'] ) ? (int) $_POST['template_id'] : 0;
		$result      = $template_id ? WSFM_Templates::delete( $template_id ) : true;

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_TEMPLATES, 'wsfm-error' => rawurlencode( $result->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG_TEMPLATES, 'wsfm-deleted' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------- */

	/**
	 * Toggle a flow active/paused without a page reload.
	 */
	public function ajax_toggle_flow() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$flow_id    = isset( $_POST['flow_id'] ) ? (int) $_POST['flow_id'] : 0;
		$new_status = WSFM_Flows::toggle( $flow_id );

		if ( ! $new_status ) {
			wp_send_json_error( array( 'message' => __( 'Flow niet gevonden.', 'ws-flow-mailer' ) ) );
		}

		wp_send_json_success( array( 'status' => $new_status ) );
	}

	/**
	 * Render the (unsaved) template content with test data and return a
	 * full HTML document for the preview window.
	 */
	public function ajax_preview_template() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$rendered = $this->render_posted_template();

		wp_send_json_success(
			array(
				'subject' => $rendered['subject'],
				'html'    => $rendered['html_body'],
			)
		);
	}

	/**
	 * Send the (unsaved) template content as a test mail to the admin address.
	 */
	public function ajax_send_test_template() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$rendered = $this->render_posted_template();
		$provider = WSFM_Provider_Factory::create();

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
		}

		$admin_email = get_option( 'admin_email' );
		$result      = $provider->send( $admin_email, '[TEST] ' . $rendered['subject'], $rendered['html_body'], array() );

		if ( $result->success ) {
			wp_send_json_success( array( 'message' => sprintf( __( 'Testmail verzonden naar %s.', 'ws-flow-mailer' ), $admin_email ) ) );
		}

		wp_send_json_error( array( 'message' => $result->error ) );
	}

	/* ---------------------------------------------------------------------
	 * AJAX voor de nieuwsbrief
	 * ------------------------------------------------------------------- */

	/**
	 * De nieuwsbrief zoals hij nu op het scherm staat, opgemaakt.
	 *
	 * Dit gaat over de ONGEOPGESLAGEN inhoud. Wie eerst moet opslaan om te
	 * kunnen kijken, slaat halve dingen op en verstuurt uiteindelijk de
	 * verkeerde versie.
	 */
	public function ajax_preview_newsletter() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$rendered = $this->render_posted_newsletter();

		wp_send_json_success(
			array(
				'subject' => $rendered['subject'],
				'html'    => $rendered['html_body'],
			)
		);
	}

	/**
	 * Een proefmail naar één adres.
	 */
	public function ajax_test_newsletter() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$naar = isset( $_POST['naar'] ) ? sanitize_email( wp_unslash( $_POST['naar'] ) ) : '';
		if ( ! is_email( $naar ) ) {
			$naar = get_option( 'admin_email' );
		}

		$provider = WSFM_Provider_Factory::create();
		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
		}

		$rendered = $this->render_posted_newsletter();
		$result   = $provider->send( $naar, '[TEST] ' . $rendered['subject'], $rendered['html_body'], array() );

		if ( $result->success ) {
			/* translators: %s: e-mailadres. */
			wp_send_json_success( array( 'message' => sprintf( __( 'Proefmail verstuurd naar %s. Kijk ook even in je spamfolder.', 'ws-flow-mailer' ), $naar ) ) );
		}

		wp_send_json_error( array( 'message' => $result->error ) );
	}

	/**
	 * Hoeveel klanten er in een doelgroep zitten.
	 */
	public function ajax_count_audience() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$doelgroep = isset( $_POST['doelgroep'] ) ? sanitize_key( wp_unslash( $_POST['doelgroep'] ) ) : '';
		$aantal    = WSFM_Newsletters::aantal_ontvangers( $doelgroep );

		wp_send_json_success(
			array(
				'aantal' => $aantal,
				/* translators: %d: aantal klanten. */
				'tekst'  => sprintf( _n( '%d klant', '%d klanten', $aantal, 'ws-flow-mailer' ), $aantal ),
			)
		);
	}

	/**
	 * De welkomstmail zoals hij nu op het scherm staat.
	 */
	public function ajax_preview_popup_mail() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$rendered = WSFM_Popup::mail_html(
			$this->popup_uit_post(),
			$this->proefcode(),
			array( 'unsubscribe_url' => home_url( '/?voorbeeld-afmeldlink' ) )
		);

		wp_send_json_success(
			array(
				'subject' => $rendered['subject'],
				'html'    => $rendered['html_body'],
			)
		);
	}

	/**
	 * De welkomstmail als proef naar de beheerder.
	 */
	public function ajax_test_popup_mail() {
		check_ajax_referer( 'wsfm_admin' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$naar = get_option( 'admin_email' );

		/* Een verzonnen code, geen echte coupon. Bij het bekijken van een
		   voorbeeld hoort niets in WooCommerce aangemaakt te worden. */
		if ( WSFM_Popup::stuur_welkomstmail( $naar, $this->proefcode(), $this->popup_uit_post() ) ) {
			/* translators: %s: e-mailadres. */
			wp_send_json_success( array( 'message' => sprintf( __( 'Verstuurd naar %s.', 'ws-flow-mailer' ), $naar ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Versturen lukte niet. Staan de instellingen van je mailprovider goed?', 'ws-flow-mailer' ) ) );
	}

	/**
	 * Een herkenbaar nepcode voor voorbeelden.
	 *
	 * @return string
	 */
	private function proefcode() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- geverifieerd door de aanroepers.
		$voor = isset( $_POST['code_voorvoegsel'] ) ? sanitize_text_field( wp_unslash( $_POST['code_voorvoegsel'] ) ) : 'WELKOM';
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $voor ) ) . '-4KP7HQ';
	}

	/**
	 * De popup-instellingen met de nog niet opgeslagen mailvelden eroverheen.
	 *
	 * @return array
	 */
	private function popup_uit_post() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- geverifieerd door de aanroepers.
		$i = WSFM_Popup::instellingen();

		foreach ( array( 'mail_onderwerp', 'mail_kop', 'mail_sjabloon', 'korting_soort' ) as $veld ) {
			if ( isset( $_POST[ $veld ] ) ) {
				$i[ $veld ] = sanitize_text_field( wp_unslash( $_POST[ $veld ] ) );
			}
		}
		if ( isset( $_POST['mail_tekst'] ) ) {
			$i['mail_tekst'] = sanitize_textarea_field( wp_unslash( $_POST['mail_tekst'] ) );
		}
		foreach ( array( 'korting_waarde', 'minimaal', 'geldig_dagen' ) as $veld ) {
			if ( isset( $_POST[ $veld ] ) ) {
				$i[ $veld ] = (float) $_POST[ $veld ];
			}
		}

		/* Het scherm stuurt het vinkje altijd mee, ook als het uit staat, dus
		   hier telt de waarde en niet of het veld er is. */
		if ( isset( $_POST['mail_eigen_kleuren'] ) ) {
			$i['mail_eigen_kleuren'] = '1' === (string) $_POST['mail_eigen_kleuren'] ? 1 : 0;
		}
		foreach ( array( 'mail_kleur_achtergrond', 'mail_kleur_tekst', 'mail_kleur_knop', 'mail_kleur_knoptekst' ) as $veld ) {
			if ( ! isset( $_POST[ $veld ] ) ) {
				continue;
			}
			$kleur = sanitize_hex_color( wp_unslash( $_POST[ $veld ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_hex_color doet het werk.
			if ( $kleur ) {
				$i[ $veld ] = $kleur;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $i;
	}

	/**
	 * De ingezonden nieuwsbrief opmaken met verzonnen gegevens.
	 *
	 * @return array { subject, html_body }
	 */
	private function render_posted_newsletter() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- geverifieerd door de aanroepers.
		$blokken = isset( $_POST['blokken'] ) && is_array( $_POST['blokken'] )
			? wp_unslash( $_POST['blokken'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- opgeschoond hieronder.
			: array();

		$brief = (object) array(
			'subject'  => isset( $_POST['onderwerp'] ) ? sanitize_text_field( wp_unslash( $_POST['onderwerp'] ) ) : '',
			'template' => isset( $_POST['sjabloon'] ) ? sanitize_key( wp_unslash( $_POST['sjabloon'] ) ) : 'rustig',
			'blocks'   => WSFM_Newsletters::schoon_blokken( $blokken ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return WSFM_Newsletters::render(
			$brief,
			array(
				'first_name'      => 'Jan',
				'unsubscribe_url' => home_url( '/?voorbeeld-afmeldlink' ),
			)
		);
	}

	/**
	 * Render posted subject/body with the fictional test context.
	 *
	 * @return array { subject, html_body }
	 */
	private function render_posted_template() {
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by callers.
		$body    = isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$body = wp_kses_post( $body );
		}

		return WSFM_Template_Engine::render_string( $subject, $body, WSFM_Template_Engine::test_context() );
	}
}
