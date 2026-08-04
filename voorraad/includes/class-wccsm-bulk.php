<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bulk price update for all variations of a variable product.
 *
 * Supports:
 * - Percentage increase/decrease on regular price
 * - Fixed amount increase/decrease on regular price
 * - Set a fixed regular price for all variations
 * - Same for sale price
 */
class WCCSM_Bulk {

    public function __construct() {
        add_action( 'wp_ajax_wccsm_bulk_price', [ $this, 'ajax_bulk_price' ] );
        add_action( 'wp_ajax_wccsm_get_variations', [ $this, 'ajax_get_variations' ] );
    }

    /**
     * AJAX: Get all variations of a variable product (for the bulk modal).
     */
    public function ajax_get_variations(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $product    = wc_get_product( $product_id );

        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            wp_send_json_error( 'Not a variable product' );
        }

        $variations = [];
        foreach ( $product->get_children() as $var_id ) {
            $var = wc_get_product( $var_id );
            if ( ! $var ) continue;

            $attrs = $var->get_attributes();
            $attr_label = implode( ' / ', array_filter( array_map( 'ucfirst', $attrs ) ) );

            $variations[] = [
                'id'             => $var_id,
                'name'           => $attr_label ?: '#' . $var_id,
                'regular_price'  => $var->get_regular_price(),
                'sale_price'     => $var->get_sale_price(),
                'purchase_price' => $var->get_meta( '_wccsm_purchase_price' ) ?: '',
                'sku'            => $var->get_sku(),
            ];
        }

        wp_send_json_success( [
            'product_name' => $product->get_name(),
            'variations'   => $variations,
        ] );
    }

    /**
     * AJAX: Apply bulk price change to all variations.
     */
    public function ajax_bulk_price(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $target     = sanitize_text_field( $_POST['target'] ?? 'regular_price' ); // regular_price, sale_price, or purchase_price
        $action     = sanitize_text_field( $_POST['bulk_action'] ?? '' );
        $amount     = floatval( $_POST['amount'] ?? 0 );

        $product = wc_get_product( $product_id );
        if ( ! $product || ! $product->is_type( 'variable' ) ) {
            wp_send_json_error( 'Not a variable product' );
        }

        if ( ! in_array( $action, [ 'percent_increase', 'percent_decrease', 'fixed_increase', 'fixed_decrease', 'set_fixed' ], true ) ) {
            wp_send_json_error( 'Invalid action' );
        }

        if ( $amount <= 0 && 'set_fixed' !== $action ) {
            wp_send_json_error( 'Amount must be greater than 0' );
        }

        $updated = 0;
        foreach ( $product->get_children() as $var_id ) {
            $var = wc_get_product( $var_id );
            if ( ! $var ) continue;

            if ( 'purchase_price' === $target ) {
                $current = floatval( $var->get_meta( '_wccsm_purchase_price' ) );
            } elseif ( 'sale_price' === $target ) {
                $current = floatval( $var->get_sale_price() );
            } else {
                $current = floatval( $var->get_regular_price() );
            }

            $new_price = $this->calculate_price( $current, $action, $amount );

            if ( 'purchase_price' === $target ) {
                $var->update_meta_data( '_wccsm_purchase_price', wc_format_decimal( $new_price ) );
            } elseif ( 'sale_price' === $target ) {
                $var->set_sale_price( wc_format_decimal( $new_price ) );
            } else {
                $var->set_regular_price( wc_format_decimal( $new_price ) );
            }

            $var->save();
            $updated++;
        }

        wp_send_json_success( [
            'updated' => $updated,
            'message' => sprintf(
                /* translators: %d: number of variations updated */
                __( '%d variaties bijgewerkt.', 'wccsm' ),
                $updated
            ),
        ] );
    }

    /**
     * Calculate new price based on action.
     */
    private function calculate_price( float $current, string $action, float $amount ): float {
        switch ( $action ) {
            case 'percent_increase':
                return round( $current * ( 1 + $amount / 100 ), 2 );

            case 'percent_decrease':
                return max( 0, round( $current * ( 1 - $amount / 100 ), 2 ) );

            case 'fixed_increase':
                return round( $current + $amount, 2 );

            case 'fixed_decrease':
                return max( 0, round( $current - $amount, 2 ) );

            case 'set_fixed':
                return max( 0, round( $amount, 2 ) );

            default:
                return $current;
        }
    }
}
