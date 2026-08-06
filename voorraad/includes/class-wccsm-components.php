<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages product-component relationships stored in post meta.
 *
 * Meta key: _wccsm_components
 * Format: array of [ 'product_id' => int, 'qty' => int ]
 *
 * This is stored on the parent/composed product.
 * A component is any other WooCommerce product (simple or variation).
 */
class WCCSM_Components {

    /**
     * Get components for a product.
     *
     * @param int $product_id
     * @return array Array of [ 'product_id' => int, 'qty' => int ]
     */
    public static function get_components( int $product_id ): array {
        $components = get_post_meta( $product_id, '_wccsm_components', true );
        return is_array( $components ) ? $components : [];
    }

    /**
     * Save components for a product.
     *
     * @param int   $product_id
     * @param array $components Array of [ 'product_id' => int, 'qty' => int ]
     */
    public static function save_components( int $product_id, array $components ): void {
        $sanitized = [];
        foreach ( $components as $comp ) {
            $pid = absint( $comp['product_id'] ?? 0 );
            $qty = absint( $comp['qty'] ?? 1 );
            if ( $pid > 0 && $qty > 0 ) {
                $sanitized[] = [
                    'product_id' => $pid,
                    'qty'        => $qty,
                ];
            }
        }
        update_post_meta( $product_id, '_wccsm_components', $sanitized );
    }

    /**
     * Check if a product has components.
     */
    public static function has_components( int $product_id ): bool {
        return ! empty( self::get_components( $product_id ) );
    }

    /**
     * Compute the effective stock for a composed product based on its components.
     *
     * Returns the minimum number of "sets" that can be assembled from current
     * component stocks: min( floor( component_stock / qty_needed ) ) across all.
     *
     * @param int $product_id The composed product ID.
     * @return int|null Computed stock, or null if no components defined.
     */
    public static function compute_stock( int $product_id ): ?int {
        $components = self::get_components( $product_id );
        if ( empty( $components ) ) {
            return null;
        }

        $min_sets = PHP_INT_MAX;

        foreach ( $components as $comp ) {
            $comp_product = wc_get_product( $comp['product_id'] );
            if ( ! $comp_product ) {
                // Component product deleted - treat as 0 stock.
                return 0;
            }

            // Read raw stock to avoid recursion if a component is also composed.
            $comp_stock = $comp_product->get_stock_quantity();
            if ( null === $comp_stock ) {
                // Component doesn't manage stock - skip (treat as unlimited).
                continue;
            }

            $qty_needed = max( 1, $comp['qty'] );
            $sets       = (int) floor( $comp_stock / $qty_needed );
            $min_sets   = min( $min_sets, $sets );
        }

        return ( PHP_INT_MAX === $min_sets ) ? null : max( 0, $min_sets );
    }

    /**
     * Get detailed component stock info for display purposes.
     *
     * @param int $product_id
     * @return array Array of [ 'name' => string, 'qty' => int, 'stock' => int|null, 'out' => bool ]
     */
    public static function get_components_with_stock( int $product_id ): array {
        $components = self::get_components( $product_id );
        $result     = [];

        foreach ( $components as $comp ) {
            $cp    = wc_get_product( $comp['product_id'] );
            $stock = $cp ? $cp->get_stock_quantity() : null;
            $out   = ( null !== $stock && $stock <= 0 );

            $result[] = [
                'product_id' => $comp['product_id'],
                'name'       => $cp ? $cp->get_name() : '(#' . $comp['product_id'] . ')',
                'qty'        => $comp['qty'],
                'stock'      => $stock,
                'out'        => $out,
            ];
        }

        return $result;
    }

    /**
     * Get all products that use a given component product_id.
     *
     * @param int $component_id
     * @return array Product IDs that reference this component.
     */
    public static function get_parent_products( int $component_id ): array {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_wccsm_components'
                 AND meta_value LIKE %s",
                '%' . $wpdb->esc_like( '"product_id";i:' . $component_id ) . '%'
            )
        );

        // Double-check in PHP to avoid serialization false positives.
        $parents = [];
        foreach ( $rows as $post_id ) {
            $components = self::get_components( (int) $post_id );
            foreach ( $components as $comp ) {
                if ( (int) $comp['product_id'] === $component_id ) {
                    $parents[] = (int) $post_id;
                    break;
                }
            }
        }

        return $parents;
    }
}
