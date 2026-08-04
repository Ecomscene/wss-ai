<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles:
 * 1. Component stock reduction/restoration on order status changes (V1 — preserved).
 * 2. Front-end stock computation for composed products: stock quantity, status,
 *    purchasability, and manage_stock are derived from components.
 * 3. Database sync: after component stock changes, all parent composed products
 *    get their _stock and _stock_status updated for WC catalog queries.
 */
class WCCSM_Stock {

    private const META_REDUCED = '_wccsm_components_reduced';

    /**
     * Guard against recursion when computing stock via filters.
     */
    private static bool $computing = false;

    public function __construct() {
        // --- Order stock adjustment (V1 — preserved) ---
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_change' ], 10, 4 );

        // --- Front-end stock filters for composed products ---
        // Stock quantity.
        add_filter( 'woocommerce_product_get_stock_quantity', [ $this, 'filter_stock_quantity' ], 10, 2 );
        add_filter( 'woocommerce_product_variation_get_stock_quantity', [ $this, 'filter_stock_quantity' ], 10, 2 );

        // Stock status.
        add_filter( 'woocommerce_product_get_stock_status', [ $this, 'filter_stock_status' ], 10, 2 );
        add_filter( 'woocommerce_product_variation_get_stock_status', [ $this, 'filter_stock_status' ], 10, 2 );

        // Is in stock (purchasability).
        add_filter( 'woocommerce_product_is_in_stock', [ $this, 'filter_is_in_stock' ], 10, 2 );
        add_filter( 'woocommerce_variation_is_in_stock', [ $this, 'filter_is_in_stock' ], 10, 2 );

        // Force manage_stock = true for composed products so WC shows the quantity.
        add_filter( 'woocommerce_product_get_manage_stock', [ $this, 'filter_manage_stock' ], 10, 2 );
        add_filter( 'woocommerce_product_variation_get_manage_stock', [ $this, 'filter_manage_stock' ], 10, 2 );
    }

    /* ========================================================================
       Front-end Stock Filters
       ======================================================================== */

    /**
     * Override stock quantity for composed products: return the computed value.
     *
     * @param mixed       $value   Current stock quantity.
     * @param \WC_Product $product
     * @return mixed
     */
    public function filter_stock_quantity( $value, $product ) {
        if ( self::$computing ) {
            return $value;
        }

        $product_id = $product->get_id();
        if ( ! WCCSM_Components::has_components( $product_id ) ) {
            return $value;
        }

        self::$computing = true;
        $computed = WCCSM_Components::compute_stock( $product_id );
        self::$computing = false;

        return ( null !== $computed ) ? $computed : $value;
    }

    /**
     * Override stock status for composed products.
     *
     * @param string      $status  Current stock status.
     * @param \WC_Product $product
     * @return string
     */
    public function filter_stock_status( $status, $product ) {
        if ( self::$computing ) {
            return $status;
        }

        $product_id = $product->get_id();
        if ( ! WCCSM_Components::has_components( $product_id ) ) {
            return $status;
        }

        self::$computing = true;
        $computed = WCCSM_Components::compute_stock( $product_id );
        self::$computing = false;

        if ( null === $computed ) {
            return $status;
        }

        if ( $computed <= 0 ) {
            return 'outofstock';
        }

        $low_threshold = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
        if ( $computed <= $low_threshold ) {
            return 'onbackorder'; // WC treats this as "low stock" display.
        }

        return 'instock';
    }

    /**
     * Override is_in_stock for composed products: false when computed stock <= 0.
     *
     * @param bool        $in_stock
     * @param \WC_Product $product
     * @return bool
     */
    public function filter_is_in_stock( $in_stock, $product ) {
        if ( self::$computing ) {
            return $in_stock;
        }

        $product_id = $product->get_id();
        if ( ! WCCSM_Components::has_components( $product_id ) ) {
            return $in_stock;
        }

        self::$computing = true;
        $computed = WCCSM_Components::compute_stock( $product_id );
        self::$computing = false;

        if ( null === $computed ) {
            return $in_stock;
        }

        return $computed > 0;
    }

    /**
     * Force manage_stock = true for composed products so WC displays stock quantity.
     *
     * @param mixed       $value
     * @param \WC_Product $product
     * @return mixed
     */
    public function filter_manage_stock( $value, $product ) {
        if ( self::$computing ) {
            return $value;
        }

        if ( WCCSM_Components::has_components( $product->get_id() ) ) {
            return true;
        }

        return $value;
    }

    /* ========================================================================
       Order Stock Adjustment (V1 — preserved)
       ======================================================================== */

    /**
     * Handle order status transitions.
     */
    public function on_status_change( int $order_id, string $old_status, string $new_status, \WC_Order $order ): void {
        $reduce_statuses  = [ 'processing', 'completed' ];
        $restore_statuses = [ 'cancelled', 'refunded' ];

        $was_reduced = $order->get_meta( self::META_REDUCED );

        if ( in_array( $new_status, $reduce_statuses, true ) && ! $was_reduced ) {
            $this->adjust_component_stock( $order, 'reduce' );
            $order->update_meta_data( self::META_REDUCED, '1' );
            $order->save();
        }

        if ( in_array( $new_status, $restore_statuses, true ) && $was_reduced ) {
            $this->adjust_component_stock( $order, 'restore' );
            $order->delete_meta_data( self::META_REDUCED );
            $order->save();
        }
    }

    /**
     * Adjust stock for all components of all items in an order.
     * After adjustment, sync composed product stock in the database.
     */
    private function adjust_component_stock( \WC_Order $order, string $action ): void {
        $affected_component_ids = [];

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id  = $item->get_product_id();
            $qty_ordered = $item->get_quantity();

            $components = WCCSM_Components::get_components( $product_id );

            $variation_id = $item->get_variation_id();
            if ( $variation_id ) {
                $var_components = WCCSM_Components::get_components( $variation_id );
                if ( ! empty( $var_components ) ) {
                    $components = $var_components;
                }
            }

            if ( empty( $components ) ) {
                continue;
            }

            foreach ( $components as $comp ) {
                $comp_product = wc_get_product( $comp['product_id'] );
                if ( ! $comp_product || ! $comp_product->managing_stock() ) {
                    continue;
                }

                $change = $qty_ordered * $comp['qty'];

                // Temporarily disable our filters to write raw stock.
                self::$computing = true;

                if ( 'reduce' === $action ) {
                    wc_update_product_stock( $comp_product, $change, 'decrease' );
                    $order->add_order_note(
                        sprintf(
                            __( 'WCCSM: Voorraad van "%1$s" verlaagd met %2$d (component van "%3$s").', 'wccsm' ),
                            $comp_product->get_name(),
                            $change,
                            $item->get_name()
                        )
                    );
                } else {
                    wc_update_product_stock( $comp_product, $change, 'increase' );
                    $order->add_order_note(
                        sprintf(
                            __( 'WCCSM: Voorraad van "%1$s" hersteld met %2$d (component van "%3$s").', 'wccsm' ),
                            $comp_product->get_name(),
                            $change,
                            $item->get_name()
                        )
                    );
                }

                self::$computing = false;

                $affected_component_ids[] = $comp['product_id'];
            }
        }

        // Sync all composed products that depend on the affected components.
        $this->sync_parent_products( array_unique( $affected_component_ids ) );
    }

    /* ========================================================================
       Database Sync — keep _stock and _stock_status consistent
       ======================================================================== */

    /**
     * After component stock changes, update all composed products that reference
     * the affected components so that _stock and _stock_status reflect reality
     * in the database (needed for WC catalog queries and admin listings).
     *
     * @param int[] $component_ids Component product IDs whose stock changed.
     */
    private function sync_parent_products( array $component_ids ): void {
        $parent_ids = [];
        foreach ( $component_ids as $cid ) {
            $parents    = WCCSM_Components::get_parent_products( $cid );
            $parent_ids = array_merge( $parent_ids, $parents );
        }

        $parent_ids = array_unique( $parent_ids );

        foreach ( $parent_ids as $pid ) {
            $this->sync_product_stock( $pid );
        }
    }

    /**
     * Sync a single composed product's _stock and _stock_status in the database.
     *
     * @param int $product_id
     */
    public function sync_product_stock( int $product_id ): void {
        self::$computing = true;
        $computed = WCCSM_Components::compute_stock( $product_id );
        self::$computing = false;

        if ( null === $computed ) {
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return;
        }

        // Write directly to avoid triggering our own filters.
        // Use the data store to update raw values.
        $new_status = $computed > 0 ? 'instock' : 'outofstock';

        // Update post meta directly for speed and to avoid filter loops.
        update_post_meta( $product_id, '_stock', $computed );
        update_post_meta( $product_id, '_stock_status', $new_status );

        // Clear WC product cache so next load reflects the update.
        wc_delete_product_transients( $product_id );

        // If HPOS is active, also update the product via the data store.
        if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            wp_cache_delete( 'product-' . $product_id, 'products' );
        }
    }
}
