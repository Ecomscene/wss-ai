<?php
/**
 * Template engine: renders stored templates by replacing merge tags with
 * real data from a context array.
 *
 * Supported tags: {first_name}, {cart_items}, {cart_total}, {cart_url},
 * {order_number}, {order_total}, {order_items}, {unsubscribe_url},
 * {shop_name}, {shop_url}.
 *
 * @package WS_Flow_Mailer
 */

defined( 'ABSPATH' ) || exit;

class WSFM_Template_Engine {

	/**
	 * Supported merge tags with a short Dutch description (used by the
	 * cheatsheet in the template editor).
	 *
	 * @return array tag => description
	 */
	public static function supported_tags() {
		return array(
			'{first_name}'      => __( 'Voornaam van de klant', 'ws-flow-mailer' ),
			'{cart_items}'      => __( 'Winkelwagen-producten als lijst (HTML)', 'ws-flow-mailer' ),
			'{cart_total}'      => __( 'Winkelwagen-totaal incl. valutasymbool', 'ws-flow-mailer' ),
			'{cart_url}'        => __( 'Link naar de winkelwagen', 'ws-flow-mailer' ),
			'{order_number}'    => __( 'Ordernummer', 'ws-flow-mailer' ),
			'{order_total}'     => __( 'Ordertotaal incl. valutasymbool', 'ws-flow-mailer' ),
			'{order_items}'     => __( 'Bestelde producten als lijst (HTML)', 'ws-flow-mailer' ),
			'{unsubscribe_url}' => __( 'Afmeldlink (verplicht in elke template)', 'ws-flow-mailer' ),
			'{shop_name}'       => __( 'Naam van de webshop', 'ws-flow-mailer' ),
			'{shop_url}'        => __( 'Link naar de webshop', 'ws-flow-mailer' ),
		);
	}

	/**
	 * Fetch a template row.
	 *
	 * @param int $template_id Template id.
	 * @return object|null
	 */
	public static function get_template( $template_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wsfm_templates';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $template_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Render a stored template with the given context.
	 *
	 * @param int   $template_id Template id.
	 * @param array $context     Merge data (keys without braces).
	 * @return array|WP_Error { subject, html_body }
	 */
	public static function render( $template_id, array $context ) {
		$template = self::get_template( $template_id );

		if ( ! $template ) {
			return new WP_Error( 'wsfm_template_missing', sprintf( __( 'Template %d bestaat niet (meer).', 'ws-flow-mailer' ), $template_id ) );
		}

		return self::render_string( $template->subject, $template->html_body, $context );
	}

	/**
	 * Replace merge tags in a subject + body pair.
	 *
	 * Every supported tag is always replaced - missing context values
	 * become an empty string so no raw {tags} ever reach a customer.
	 *
	 * @param string $subject Raw subject.
	 * @param string $body    Raw HTML body.
	 * @param array  $context Merge data (keys without braces).
	 * @return array { subject, html_body }
	 */
	public static function render_string( $subject, $body, array $context ) {
		$subject = (string) $subject;
		$body    = (string) $body;
		$context = array_merge( self::default_context(), $context );

		$search          = array();
		$replace         = array();
		$subject_replace = array();
		$html            = array( 'cart_items', 'order_items' ); // Tags whose value is HTML.

		foreach ( array_keys( self::supported_tags() ) as $tag ) {
			$key       = trim( $tag, '{}' );
			$value     = isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
			$search[]  = $tag;
			$replace[] = $value;

			// Subjects are plain text: strip HTML values there.
			$subject_replace[] = in_array( $key, $html, true ) ? wp_strip_all_tags( $value ) : $value;
		}

		return array(
			'subject'   => str_replace( $search, $subject_replace, $subject ),
			'html_body' => str_replace( $search, $replace, $body ),
		);
	}

	/**
	 * Context values that are always available.
	 *
	 * @return array
	 */
	public static function default_context() {
		return array(
			'shop_name' => get_bloginfo( 'name' ),
			'shop_url'  => home_url( '/' ),
		);
	}

	/**
	 * Build an HTML <ul> out of an items array.
	 *
	 * @param array $items Arrays with keys: name, qty, price (formatted, optional).
	 * @return string
	 */
	public static function items_html( array $items ) {
		if ( empty( $items ) ) {
			return '';
		}

		$out = '<ul style="padding-left:20px;margin:16px 0;">';
		foreach ( $items as $item ) {
			$name  = isset( $item['name'] ) ? $item['name'] : '';
			$qty   = isset( $item['qty'] ) ? (int) $item['qty'] : 1;
			$price = isset( $item['price'] ) ? $item['price'] : '';

			$out .= '<li style="margin-bottom:6px;">' . $qty . '&times; ' . esc_html( $name );
			if ( '' !== $price ) {
				$out .= ' - ' . esc_html( $price );
			}
			$out .= '</li>';
		}
		return $out . '</ul>';
	}

	/**
	 * Format a raw amount as a plain-text price ("€ 49,95") suitable for
	 * both HTML body and subject. Uses WooCommerce settings when present.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	public static function format_price( $amount ) {
		if ( function_exists( 'wc_price' ) ) {
			return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
		}
		return number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Build merge context from a WooCommerce order (for order_completed flows).
	 *
	 * @param WC_Order $order Order object (from wc_get_order, HPOS-safe).
	 * @param string   $unsubscribe_url Unsubscribe URL for the recipient.
	 * @return array
	 */
	public static function build_order_context( $order, $unsubscribe_url = '' ) {
		$items = array();
		foreach ( $order->get_items() as $line_item ) {
			$items[] = array(
				'name'  => $line_item->get_name(),
				'qty'   => $line_item->get_quantity(),
				'price' => self::format_price( (float) $line_item->get_total() ),
			);
		}

		return array(
			'first_name'      => $order->get_billing_first_name(),
			'order_number'    => $order->get_order_number(),
			'order_total'     => self::format_price( (float) $order->get_total() ),
			'order_items'     => self::items_html( $items ),
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}

	/**
	 * Build merge context from a cart-tracking row (for abandoned_cart flows).
	 *
	 * @param object $tracking Row from wsfm_cart_tracking (cart_contents = JSON).
	 * @param string $unsubscribe_url Unsubscribe URL for the recipient.
	 * @return array
	 */
	public static function build_cart_context( $tracking, $unsubscribe_url = '' ) {
		$contents = json_decode( (string) $tracking->cart_contents, true );
		$items    = ( is_array( $contents ) && ! empty( $contents['items'] ) ) ? $contents['items'] : array();
		$total    = ( is_array( $contents ) && isset( $contents['total'] ) ) ? (float) $contents['total'] : 0;

		$first_name = $tracking->customer_name ? preg_split( '/\s+/', trim( $tracking->customer_name ) )[0] : '';

		return array(
			'first_name'      => $first_name,
			'cart_items'      => self::items_html( $items ),
			'cart_total'      => self::format_price( $total ),
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
			'unsubscribe_url' => $unsubscribe_url,
		);
	}

	/**
	 * Fictional context for previews and test mails.
	 *
	 * @return array
	 */
	public static function test_context() {
		$items = array(
			array(
				'name'  => 'Product X',
				'qty'   => 2,
				'price' => self::format_price( 49.95 ),
			),
		);

		return array(
			'first_name'      => 'Jan',
			'cart_items'      => self::items_html( $items ),
			'cart_total'      => self::format_price( 49.95 ),
			'cart_url'        => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
			'order_number'    => '#12345',
			'order_total'     => self::format_price( 49.95 ),
			'order_items'     => self::items_html( $items ),
			'unsubscribe_url' => home_url( '/?voorbeeld-afmeldlink' ),
		);
	}
}
