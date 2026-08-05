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
	const SLUG_FLOWS     = 'ws-flow-mailer-flows';
	const SLUG_TEMPLATES = 'ws-flow-mailer-templates';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 9 );

		add_action( 'admin_post_wsfm_save_flow', array( $this, 'handle_save_flow' ) );
		add_action( 'admin_post_wsfm_delete_flow', array( $this, 'handle_delete_flow' ) );
		add_action( 'admin_post_wsfm_save_template', array( $this, 'handle_save_template' ) );
		add_action( 'admin_post_wsfm_delete_template', array( $this, 'handle_delete_template' ) );

		add_action( 'wp_ajax_wsfm_toggle_flow', array( $this, 'ajax_toggle_flow' ) );
		add_action( 'wp_ajax_wsfm_preview_template', array( $this, 'ajax_preview_template' ) );
		add_action( 'wp_ajax_wsfm_send_test_template', array( $this, 'ajax_send_test_template' ) );
	}

	/**
	 * Menu: WS Flow Mailer → Dashboard / Flows / Templates
	 * (the settings submenu is registered by WSFM_Admin_Settings).
	 */
	public function register_menu() {
		/* Bij de overname in WSS Tools: dit was een eigen hoofdmenu-item.
		   Nu een subpagina, want anders staat er een tweede blok in het menu
		   voor iets wat bij dezelfde gereedschapskist hoort. De slugs blijven
		   wat ze waren, zodat opgeslagen links en formulieren blijven werken. */
		add_submenu_page( 'wss-ai', __( 'Nieuwsbrief', 'ws-flow-mailer' ), __( 'Nieuwsbrief', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_DASHBOARD, array( $this, 'render_dashboard' ) );
		add_submenu_page( 'wss-ai', __( 'Flows', 'ws-flow-mailer' ), __( 'Flows', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_FLOWS, array( $this, 'render_flows' ) );
		add_submenu_page( 'wss-ai', __( 'Templates', 'ws-flow-mailer' ), __( 'Templates', 'ws-flow-mailer' ), self::CAPABILITY, self::SLUG_TEMPLATES, array( $this, 'render_templates' ) );
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
