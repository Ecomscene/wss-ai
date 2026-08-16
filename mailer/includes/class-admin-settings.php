<?php
/**
 * Settings submenu: provider/credentials, suppression list management,
 * SNS webhook instructions and the identity-stitching toggle.
 * All actions are guarded by nonce + manage_woocommerce.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Admin_Settings {

	const CAPABILITY = 'manage_woocommerce';
	const MENU_SLUG  = 'ws-flow-mailer-settings';
	const NONCE      = 'wsfm_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_wsfm_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_wsfm_suppression_add', array( $this, 'handle_suppression_add' ) );
		add_action( 'admin_post_wsfm_suppression_remove', array( $this, 'handle_suppression_remove' ) );
		add_action( 'wp_ajax_wsfm_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Settings submenu under the WS Flow Mailer parent menu.
	 */
	public function register_menu() {
		/* Bij de overname in WSS Tools onder ons eigen menu gehangen.
		   "Instellingen" alleen zou daar te vaag zijn tussen de rest. */
		add_submenu_page(
			'wss-ai',
			__( 'Nieuwsbrief instellingen', 'ws-flow-mailer' ),
			__( 'Nieuwsbrief instellingen', 'ws-flow-mailer' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Load admin assets on all WS Flow Mailer pages.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'ws-flow-mailer' ) ) {
			return;
		}

		wp_enqueue_style( 'wsfm-admin', WSFM_PLUGIN_URL . 'assets/admin.css', array(), WSFM_VERSION );
		wp_enqueue_script( 'wsfm-admin', WSFM_PLUGIN_URL . 'assets/admin.js', array(), WSFM_VERSION, true );
		wp_localize_script(
			'wsfm-admin',
			'wsfmAdmin',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'testNonce'  => wp_create_nonce( 'wsfm_test_connection' ),
				'adminNonce' => wp_create_nonce( 'wsfm_admin' ),
				'testing'    => __( 'Bezig met testen…', 'ws-flow-mailer' ),
				'sending'    => __( 'Bezig met versturen…', 'ws-flow-mailer' ),
				'confirmDeleteFlow'     => __( 'Weet je zeker dat je deze flow wilt verwijderen? Actieve wachtrij-items worden gestopt.', 'ws-flow-mailer' ),
				'confirmDeleteTemplate' => __( 'Weet je zeker dat je deze template wilt verwijderen?', 'ws-flow-mailer' ),
				'confirmDeleteSubscriber' => __( 'Dit adres van je lijst halen? Diegene krijgt daarna geen nieuwsbrief meer.', 'ws-flow-mailer' ),
			)
		);

		/* De samensteller heeft meer nodig dan de rest: de mediakiezer van
		   WordPress en de productzoeker van WooCommerce. Die staan hier en niet
		   bij de andere schermen, want ze zijn zwaar en nergens anders nodig. */
		if ( false !== strpos( $hook, WSFM_Flow_Admin_UI::SLUG_BRIEVEN ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );

			wp_enqueue_script(
				'wsfm-nieuwsbrief',
				WSFM_PLUGIN_URL . 'assets/nieuwsbrief.js',
				/* wc-enhanced-select erbij, want dit bestand roept zijn trigger aan.
				   Zonder die afhankelijkheid is de laadvolgorde toeval. */
				array( 'jquery', 'wsfm-admin', 'wc-enhanced-select' ),
				WSFM_VERSION,
				true
			);
			wp_localize_script(
				'wsfm-nieuwsbrief',
				'wsfmBrief',
				array(
					'bezig'         => __( 'Momentje...', 'ws-flow-mailer' ),
					'versturen'     => __( 'Bezig met versturen...', 'ws-flow-mailer' ),
					'tellen'        => __( 'Bezig met tellen...', 'ws-flow-mailer' ),
					'telFout'       => __( 'Het aantal kon niet opgehaald worden.', 'ws-flow-mailer' ),
					'fout'          => __( 'Er ging iets mis. Probeer het nog eens.', 'ws-flow-mailer' ),
					'popup'         => __( 'Je browser blokkeerde het voorbeeldvenster. Sta pop-ups toe voor deze pagina.', 'ws-flow-mailer' ),
					'kiesBeeld'     => __( 'Kies een afbeelding', 'ws-flow-mailer' ),
					'gebruikBeeld'  => __( 'Deze gebruiken', 'ws-flow-mailer' ),
					'wegVragen'     => __( 'Dit blok weghalen?', 'ws-flow-mailer' ),
					'weggooiVragen' => __( 'Deze nieuwsbrief weggooien? Dat kan niet ongedaan gemaakt worden.', 'ws-flow-mailer' ),
					'zekerWeten'    => __( 'Versturen naar %s? Dit kan niet teruggedraaid worden.', 'ws-flow-mailer' ),
				)
			);
		}

		/* Het beheerscherm van de popup toont het echte ding, met dezelfde
		   stylesheet als op de winkel. Zo kan het voorbeeld niet uit de pas
		   gaan lopen met wat de bezoeker ziet. */
		if ( false !== strpos( $hook, WSFM_Flow_Admin_UI::SLUG_POPUP ) ) {
			wp_enqueue_media();
			wp_enqueue_style( 'wsfm-popup', WSFM_PLUGIN_URL . 'assets/popup.css', array(), WSFM_VERSION );
			wp_enqueue_script(
				'wsfm-popup-beheer',
				WSFM_PLUGIN_URL . 'assets/popup-admin.js',
				array( 'jquery', 'wsfm-admin' ),
				WSFM_VERSION,
				true
			);
			wp_localize_script(
				'wsfm-popup-beheer',
				'wsfmPopupBeheer',
				array(
					'kiesBeeld'     => __( 'Kies een foto', 'ws-flow-mailer' ),
					'gebruikBeeld'  => __( 'Deze gebruiken', 'ws-flow-mailer' ),
					'toonGelukt'    => __( 'Laat het bedankscherm zien', 'ws-flow-mailer' ),
					'toonFormulier' => __( 'Terug naar het formulier', 'ws-flow-mailer' ),
					'versturen'     => __( 'Bezig met versturen...', 'ws-flow-mailer' ),
					'fout'          => __( 'Er ging iets mis. Probeer het nog eens.', 'ws-flow-mailer' ),
					'popup'         => __( 'Je browser blokkeerde het voorbeeldvenster.', 'ws-flow-mailer' ),
				)
			);
		}
	}

	/**
	 * List of SES regions for the dropdown.
	 *
	 * @return array region => label
	 */
	public static function ses_regions() {
		return array(
			'eu-west-1'      => 'EU (Ierland) - eu-west-1',
			'eu-central-1'   => 'EU (Frankfurt) - eu-central-1',
			'eu-west-2'      => 'EU (Londen) - eu-west-2',
			'eu-west-3'      => 'EU (Parijs) - eu-west-3',
			'eu-north-1'     => 'EU (Stockholm) - eu-north-1',
			'eu-south-1'     => 'EU (Milaan) - eu-south-1',
			'us-east-1'      => 'US East (N. Virginia) - us-east-1',
			'us-east-2'      => 'US East (Ohio) - us-east-2',
			'us-west-2'      => 'US West (Oregon) - us-west-2',
			'ca-central-1'   => 'Canada (Central) - ca-central-1',
			'ap-southeast-1' => 'Azië (Singapore) - ap-southeast-1',
			'ap-southeast-2' => 'Azië (Sydney) - ap-southeast-2',
			'ap-northeast-1' => 'Azië (Tokio) - ap-northeast-1',
			'ap-south-1'     => 'Azië (Mumbai) - ap-south-1',
			'sa-east-1'      => 'Zuid-Amerika (São Paulo) - sa-east-1',
			'af-south-1'     => 'Afrika (Kaapstad) - af-south-1',
		);
	}

	/**
	 * Render the settings page (markup lives in admin/settings-page.php).
	 */
	public function render_settings_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze pagina te bekijken.', 'ws-flow-mailer' ) );
		}

		$settings    = WSFM_Credentials::get_settings();
		$suppression = WSFM_Suppression::get_list( 50 );
		$sns_status  = get_option( 'wsfm_sns_status', array() );

		include WSFM_PLUGIN_DIR . 'admin/settings-page.php';
	}

	/**
	 * Save handler for the settings form (admin-post.php).
	 */
	public function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', 'ws-flow-mailer' ) );
		}
		check_admin_referer( self::NONCE );

		$provider = isset( $_POST['wsfm_provider'] ) ? sanitize_key( wp_unslash( $_POST['wsfm_provider'] ) ) : 'ses';
		if ( ! in_array( $provider, array( 'ses', 'brevo' ), true ) ) {
			$provider = 'ses';
		}

		$region = isset( $_POST['wsfm_ses_region'] ) ? sanitize_key( wp_unslash( $_POST['wsfm_ses_region'] ) ) : 'eu-west-1';
		if ( ! array_key_exists( $region, self::ses_regions() ) ) {
			$region = 'eu-west-1';
		}

		WSFM_Credentials::save_settings(
			array(
				'provider'                => $provider,
				'ses_region'              => $region,
				'ses_from_email'          => isset( $_POST['wsfm_ses_from_email'] ) ? sanitize_email( wp_unslash( $_POST['wsfm_ses_from_email'] ) ) : '',
				'ses_from_name'           => isset( $_POST['wsfm_ses_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wsfm_ses_from_name'] ) ) : '',
				'brevo_from_email'        => isset( $_POST['wsfm_brevo_from_email'] ) ? sanitize_email( wp_unslash( $_POST['wsfm_brevo_from_email'] ) ) : '',
				'delete_on_uninstall'     => isset( $_POST['wsfm_delete_on_uninstall'] ) ? 1 : 0,
				'identity_stitching'      => isset( $_POST['wsfm_identity_stitching'] ) ? 1 : 0,
				'identity_assume_consent' => isset( $_POST['wsfm_identity_assume_consent'] ) ? 1 : 0,
			)
		);

		// Secrets: empty input means "keep the stored value".
		$access_key = isset( $_POST['wsfm_ses_access_key'] ) ? trim( (string) wp_unslash( $_POST['wsfm_ses_access_key'] ) ) : '';
		$secret_key = isset( $_POST['wsfm_ses_secret_key'] ) ? trim( (string) wp_unslash( $_POST['wsfm_ses_secret_key'] ) ) : '';
		$brevo_key  = isset( $_POST['wsfm_brevo_api_key'] ) ? trim( (string) wp_unslash( $_POST['wsfm_brevo_api_key'] ) ) : '';

		WSFM_Credentials::save_secret( 'ses_access_key', $access_key, true );
		WSFM_Credentials::save_secret( 'ses_secret_key', $secret_key, false );
		WSFM_Credentials::save_secret( 'brevo_api_key', $brevo_key, false );

		wp_safe_redirect( add_query_arg( 'wsfm-updated', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Manually add an address to the suppression list.
	 */
	public function handle_suppression_add() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', 'ws-flow-mailer' ) );
		}
		check_admin_referer( 'wsfm_suppression' );

		$email = isset( $_POST['wsfm_suppress_email'] ) ? sanitize_email( wp_unslash( $_POST['wsfm_suppress_email'] ) ) : '';
		if ( is_email( $email ) ) {
			WSFM_Suppression::add( $email, 'manual' );
		}

		wp_safe_redirect( add_query_arg( 'wsfm-updated', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '#wsfm-suppression' );
		exit;
	}

	/**
	 * Remove an address from the suppression list (e.g. false positive).
	 */
	public function handle_suppression_remove() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', 'ws-flow-mailer' ) );
		}
		check_admin_referer( 'wsfm_suppression' );

		$email = isset( $_POST['wsfm_suppress_email'] ) ? sanitize_email( wp_unslash( $_POST['wsfm_suppress_email'] ) ) : '';
		if ( $email ) {
			WSFM_Suppression::remove( $email );
		}

		wp_safe_redirect( add_query_arg( 'wsfm-updated', '1', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '#wsfm-suppression' );
		exit;
	}

	/**
	 * AJAX: validate credentials and send a test mail to the admin address.
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'wsfm_test_connection' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Geen toestemming.', 'ws-flow-mailer' ) ) );
		}

		$provider = WSFM_Provider_Factory::create();

		if ( is_wp_error( $provider ) ) {
			wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
		}

		// Step 1: cheap credential check (no e-mail sent).
		if ( ! $provider->test_connection() ) {
			$error = method_exists( $provider, 'get_last_error' ) ? $provider->get_last_error() : '';
			wp_send_json_error(
				array(
					'message' => $error ? $error : __( 'Verbinding met de provider is mislukt.', 'ws-flow-mailer' ),
				)
			);
		}

		// Step 2: real test mail to the site admin address.
		$admin_email = get_option( 'admin_email' );
		$subject     = sprintf( __( 'WS Flow Mailer testmail - %s', 'ws-flow-mailer' ), wp_parse_url( home_url(), PHP_URL_HOST ) );
		$body        = '<p>' . esc_html__( 'Dit is een testmail van WS Flow Mailer. Je provider-instellingen werken correct.', 'ws-flow-mailer' ) . '</p>'
			. '<p><small>' . esc_html( home_url() ) . ' - ' . esc_html( current_time( 'mysql' ) ) . '</small></p>';

		$result = $provider->send( $admin_email, $subject, $body, array() );

		if ( $result->success ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: admin e-mail address, 2: provider message id */
						__( 'Verbinding geslaagd! Testmail verzonden naar %1$s (message-id: %2$s).', 'ws-flow-mailer' ),
						$admin_email,
						$result->message_id ? $result->message_id : '-'
					),
				)
			);
		}

		wp_send_json_error( array( 'message' => $result->error ) );
	}
}
