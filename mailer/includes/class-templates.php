<?php
/**
 * Template CRUD for the admin UI.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Templates {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'wsfm_templates';
	}

	/**
	 * Fetch one template.
	 *
	 * @param int $template_id Template id.
	 * @return object|null
	 */
	public static function get( $template_id ) {
		return WSFM_Template_Engine::get_template( $template_id );
	}

	/**
	 * All templates, newest last.
	 *
	 * @return object[]
	 */
	public static function get_all() {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Create or update a template.
	 *
	 * @param array $data { id?, name, subject, html_body }.
	 * @return int|WP_Error Template id.
	 */
	public static function save( array $data ) {
		global $wpdb;

		$name    = sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' );
		$subject = sanitize_text_field( isset( $data['subject'] ) ? $data['subject'] : '' );
		$body    = (string) ( isset( $data['html_body'] ) ? $data['html_body'] : '' );

		if ( '' === $name ) {
			return new WP_Error( 'wsfm_template_name', __( 'Geef de template een naam.', 'ws-flow-mailer' ) );
		}
		if ( '' === $subject ) {
			return new WP_Error( 'wsfm_template_subject', __( 'Vul een onderwerp in.', 'ws-flow-mailer' ) );
		}

		// Admins with unfiltered_html may store raw e-mail HTML; everyone
		// else goes through wp_kses_post.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$body = wp_kses_post( $body );
		}

		$row = array(
			'name'       => $name,
			'subject'    => $subject,
			'html_body'  => $body,
			'updated_at' => current_time( 'mysql' ),
		);

		$template_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( $template_id > 0 ) {
			$wpdb->update( self::table(), $row, array( 'id' => $template_id ) );
			return $template_id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( self::table(), $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a template (blocked while a flow still uses it).
	 *
	 * @param int $template_id Template id.
	 * @return true|WP_Error
	 */
	public static function delete( $template_id ) {
		global $wpdb;

		$used_in = self::flows_using( $template_id );
		if ( ! empty( $used_in ) ) {
			$names = implode( ', ', wp_list_pluck( $used_in, 'name' ) );
			return new WP_Error( 'wsfm_template_in_use', sprintf( __( 'Deze template wordt nog gebruikt door: %s. Pas eerst die flow(s) aan.', 'ws-flow-mailer' ), $names ) );
		}

		$wpdb->delete( self::table(), array( 'id' => (int) $template_id ) );
		return true;
	}

	/**
	 * Flows that reference a template in one of their steps.
	 *
	 * @param int $template_id Template id.
	 * @return object[]
	 */
	public static function flows_using( $template_id ) {
		$template_id = (int) $template_id;
		$used_in     = array();

		foreach ( WSFM_Flows::get_all() as $flow ) {
			foreach ( $flow->steps as $step ) {
				if ( $step['template_id'] === $template_id ) {
					$used_in[] = $flow;
					break;
				}
			}
		}

		return $used_in;
	}
}
