<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central Stock Overview admin page.
 *
 * Provides a single-page view of all products and variations with inline
 * editing for stock, purchase price, sale price, and supplier.
 * Supports filtering by supplier, stock status, and product type.
 * Data loaded via AJAX for speed.
 */
class WCCSM_Admin_Overview {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoints.
        add_action( 'wp_ajax_wccsm_load_products', [ $this, 'ajax_load_products' ] );
        add_action( 'wp_ajax_wccsm_update_field', [ $this, 'ajax_update_field' ] );
        add_action( 'wp_ajax_wccsm_get_suppliers', [ $this, 'ajax_get_suppliers' ] );
    }

    /**
     * Register admin menu.
     *
     * Aangepast bij de overname in WSS AI: dit was een subpagina onder
     * WooCommerce, waar hij tussen twintig andere regels stond. Nu is het een
     * eigen menu-item op positie 56.1, dus direct onder Producten. Dat is waar
     * je hem zoekt als je aan je voorraad denkt.
     */
    public function add_menu_page(): void {
        add_menu_page(
            __( 'Voorraadbeheer', 'wccsm' ),
            __( 'Voorraadbeheer', 'wccsm' ),
            'manage_woocommerce',
            'wccsm-stock-manager',
            [ $this, 'render_page' ],
            'dashicons-archive',
            '56.1'
        );
    }

    /**
     * Enqueue admin assets only on our page.
     *
     * De haaknaam veranderde mee met het menu: een subpagina van WooCommerce
     * heette woocommerce_page_..., een eigen menu-item heet toplevel_page_...
     * Vergeten aan te passen betekent een pagina zonder opmaak en zonder
     * JavaScript, en dus een lege tabel.
     */
    public function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_wccsm-stock-manager' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'wccsm-admin',
            WCCSM_PLUGIN_URL . 'assets/css/wccsm-admin.css',
            [],
            WCCSM_VERSION
        );

        wp_enqueue_script(
            'wccsm-admin',
            WCCSM_PLUGIN_URL . 'assets/js/wccsm-admin.js',
            [ 'jquery' ],
            WCCSM_VERSION,
            true
        );

        wp_localize_script( 'wccsm-admin', 'wccsm', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wccsm_overview' ),
            'currency' => get_woocommerce_currency_symbol(),
            'i18n'     => [
                'saving'       => __( 'Opslaan...', 'wccsm' ),
                'saved'        => __( 'Opgeslagen', 'wccsm' ),
                'error'        => __( 'Fout bij opslaan', 'wccsm' ),
                'loading'      => __( 'Laden...', 'wccsm' ),
                'no_results'   => __( 'Geen producten gevonden.', 'wccsm' ),
                'confirm_bulk' => __( 'Prijswijziging toepassen op alle variaties?', 'wccsm' ),
            ],
        ] );
    }

    /**
     * Render the overview page shell (content loaded via AJAX).
     */
    public function render_page(): void {
        include WCCSM_PLUGIN_DIR . 'templates/admin-overview.php';
    }

    /**
     * AJAX: Load products for the overview table.
     */
    public function ajax_load_products(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $page     = max( 1, absint( $_POST['page'] ?? 1 ) );
        $per_page = 50;
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $supplier = sanitize_text_field( $_POST['supplier'] ?? '' );
        $stock_status = sanitize_text_field( $_POST['stock_status'] ?? '' );
        $product_type = sanitize_text_field( $_POST['product_type'] ?? '' );

        $args = [
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'name',
            'order'   => 'ASC',
            'return'  => 'ids',
            'status'  => 'publish',
        ];

        if ( $search ) {
            $args['s'] = $search;
        }

        if ( $product_type ) {
            $args['type'] = $product_type;
        } else {
            $args['type'] = [ 'simple', 'variable' ];
        }

        // Supplier filter via meta query.
        if ( $supplier ) {
            $args['meta_query'] = [
                [
                    'key'   => '_wccsm_supplier',
                    'value' => $supplier,
                ],
            ];
        }

        $product_ids = wc_get_products( $args );

        // Count total for pagination.
        $count_args = $args;
        $count_args['limit']  = -1;
        $count_args['return'] = 'ids';
        $total = count( wc_get_products( $count_args ) );

        $rows = [];

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                // Add parent row (non-editable stock - stock lives on variations).
                $parent_row = $this->build_row( $product, true );

                // Stock status filter for variations.
                $skip_parent = false;

                $variations = $product->get_children();
                $var_rows   = [];

                foreach ( $variations as $var_id ) {
                    $variation = wc_get_product( $var_id );
                    if ( ! $variation ) {
                        continue;
                    }

                    // Apply supplier filter to variations too.
                    if ( $supplier ) {
                        $var_supplier = $variation->get_meta( '_wccsm_supplier' );
                        // Fall back to parent supplier.
                        if ( ! $var_supplier ) {
                            $var_supplier = $product->get_meta( '_wccsm_supplier' );
                        }
                        if ( $var_supplier !== $supplier ) {
                            continue;
                        }
                    }

                    // Stock status filter.
                    if ( $stock_status ) {
                        $stock_qty = $variation->get_stock_quantity();
                        if ( 'outofstock' === $stock_status && ( $stock_qty === null || $stock_qty > 0 ) ) {
                            continue;
                        }
                        if ( 'lowstock' === $stock_status ) {
                            $low = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
                            if ( $stock_qty === null || $stock_qty > $low ) {
                                continue;
                            }
                        }
                        if ( 'instock' === $stock_status && ( $stock_qty === null || $stock_qty <= 0 ) ) {
                            continue;
                        }
                    }

                    $var_rows[] = $this->build_row( $variation, false, $product );
                }

                // Only include parent + variations if there are matching variations.
                if ( ! empty( $var_rows ) || ! $supplier && ! $stock_status ) {
                    $rows[] = $parent_row;
                    $rows   = array_merge( $rows, $var_rows );
                }
            } else {
                // Simple product.
                // Stock status filter.
                if ( $stock_status ) {
                    $stock_qty = $product->get_stock_quantity();
                    if ( 'outofstock' === $stock_status && ( $stock_qty === null || $stock_qty > 0 ) ) {
                        continue;
                    }
                    if ( 'lowstock' === $stock_status ) {
                        $low = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
                        if ( $stock_qty === null || $stock_qty > $low ) {
                            continue;
                        }
                    }
                    if ( 'instock' === $stock_status && ( $stock_qty === null || $stock_qty <= 0 ) ) {
                        continue;
                    }
                }

                $rows[] = $this->build_row( $product );
            }
        }

        wp_send_json_success( [
            'rows'       => $rows,
            'total'      => $total,
            'pages'      => ceil( $total / $per_page ),
            'page'       => $page,
            'per_page'   => $per_page,
        ] );
    }

    /**
     * Build a row array for the overview table.
     *
     * @param \WC_Product      $product
     * @param bool             $is_parent  Whether this is a variable product parent row.
     * @param \WC_Product|null $parent     Parent product if this is a variation.
     */
    private function build_row( \WC_Product $product, bool $is_parent = false, ?\WC_Product $parent = null ): array {
        $id   = $product->get_id();
        $type = $product->get_type();

        // For variations, fall back to parent for supplier if not set.
        $supplier = $product->get_meta( '_wccsm_supplier' );
        if ( ! $supplier && $parent ) {
            $supplier = $parent->get_meta( '_wccsm_supplier' );
        }

        // Use WooCommerce native Global Unique ID (GTIN/EAN/UPC/ISBN).
        $gtin = $product->get_global_unique_id();
        if ( ! $gtin && $parent ) {
            $gtin = $parent->get_global_unique_id();
        }

        $purchase_price = $product->get_meta( '_wccsm_purchase_price' );

        $name = $product->get_name();
        if ( 'variation' === $type && $parent ) {
            $attrs = $product->get_attributes();
            $attr_parts = [];
            foreach ( $attrs as $key => $val ) {
                if ( $val ) {
                    $attr_parts[] = ucfirst( $val );
                }
            }
            $name = implode( ' / ', $attr_parts );
        }

        // Component data with stock details for highlighting.
        $has_components  = WCCSM_Components::has_components( $id );
        $comp_details    = [];
        $computed_stock  = null;

        if ( $has_components ) {
            $comp_with_stock = WCCSM_Components::get_components_with_stock( $id );
            foreach ( $comp_with_stock as $c ) {
                $comp_details[] = [
                    'name'  => $c['name'] . ' ×' . $c['qty'],
                    'stock' => $c['stock'],
                    'out'   => $c['out'],
                ];
            }
            $computed_stock = WCCSM_Components::compute_stock( $id );
        }

        return [
            'id'              => $id,
            'parent_id'       => $parent ? $parent->get_id() : 0,
            'name'            => $name,
            'type'            => $type,
            'is_parent'       => $is_parent,
            'is_variation'    => 'variation' === $type,
            'sku'             => $product->get_sku(),
            'gtin'            => $gtin ?: '',
            'purchase_price'  => $purchase_price ?: '',
            'regular_price'   => $product->get_regular_price(),
            'sale_price'      => $product->get_sale_price(),
            'stock'           => $has_components ? $computed_stock : $product->get_stock_quantity(),
            'manage_stock'    => $product->managing_stock() || $has_components,
            'stock_status'    => $product->get_stock_status(),
            'supplier'        => $supplier ?: '',
            'has_components'  => $has_components,
            'computed_stock'  => $computed_stock,
            'components'      => $comp_details,
            'edit_url'        => get_edit_post_link( $parent ? $parent->get_id() : $id, 'raw' ),
        ];
    }

    /**
     * AJAX: Update a single field on a product/variation.
     */
    public function ajax_update_field(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $field      = sanitize_text_field( $_POST['field'] ?? '' );
        $value      = sanitize_text_field( $_POST['value'] ?? '' );

        if ( ! $product_id || ! $field ) {
            wp_send_json_error( 'Missing data' );
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            wp_send_json_error( 'Product not found' );
        }

        switch ( $field ) {
            case 'stock':
                // Empty value = no change (prevents accidentally zeroing stock when
                // an empty field loses focus).
                if ( '' === trim( $value ) ) {
                    break;
                }

                $new_stock = (int) $value;

                // Auto-enable stock management if it's currently off. For variations
                // this enables management at the variation level, so each variation
                // can be edited directly from this overview without first opening the
                // product editor to tick "Voorraad beheer inschakelen".
                if ( ! $product->managing_stock() ) {
                    $product->set_manage_stock( true );
                }

                $product->set_stock_quantity( $new_stock );

                // Derive the stock status from the new quantity + backorder policy.
                if ( $new_stock > 0 ) {
                    $product->set_stock_status( 'instock' );
                } elseif ( 'no' !== $product->get_backorders() ) {
                    $product->set_stock_status( 'onbackorder' );
                } else {
                    $product->set_stock_status( 'outofstock' );
                }

                $product->save();
                break;

            case 'regular_price':
                $product->set_regular_price( wc_format_decimal( $value ) );
                $product->save();
                break;

            case 'sale_price':
                $product->set_sale_price( wc_format_decimal( $value ) );
                $product->save();
                break;

            case 'purchase_price':
                update_post_meta( $product_id, '_wccsm_purchase_price', wc_format_decimal( $value ) );
                break;

            case 'sku':
                $product->set_sku( wc_clean( $value ) );
                $product->save();
                break;

            case 'gtin':
                $product->set_global_unique_id( wc_clean( $value ) );
                $product->save();
                break;

            case 'supplier':
                update_post_meta( $product_id, '_wccsm_supplier', wc_clean( $value ) );
                break;

            default:
                wp_send_json_error( 'Unknown field' );
        }

        wp_send_json_success( [
            'product_id' => $product_id,
            'field'      => $field,
            'value'      => $value,
        ] );
    }

    /**
     * AJAX: Get distinct supplier values for the filter dropdown.
     */
    public function ajax_get_suppliers(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        $suppliers = $wpdb->get_col(
            "SELECT DISTINCT meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = '_wccsm_supplier'
             AND meta_value != ''
             ORDER BY meta_value ASC"
        );

        wp_send_json_success( $suppliers );
    }
}
