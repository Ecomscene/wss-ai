<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds product-level fields:
 * - Components tab in product data metabox
 * - Supplier field
 * - Purchase price field
 * - EAN field
 */
class WCCSM_Admin_Product {

    public function __construct() {
        // Add custom product data tab.
        add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_product_tab' ] );
        add_action( 'woocommerce_product_data_panels', [ $this, 'render_product_panel' ] );

        // Save product meta.
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_meta' ] );

        // Add fields to variations.
        add_action( 'woocommerce_variation_options_pricing', [ $this, 'render_variation_fields' ], 10, 3 );
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_components' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_meta' ], 10, 2 );

        // Enqueue JS on product edit screens.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_product_assets' ] );

        // AJAX search for component products.
        add_action( 'wp_ajax_wccsm_search_products', [ $this, 'ajax_search_products' ] );
    }

    /**
     * Enqueue JS for the product edit screen.
     */
    public function enqueue_product_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_script(
            'wccsm-product',
            WCCSM_PLUGIN_URL . 'assets/js/wccsm-product.js',
            [ 'jquery', 'wc-enhanced-select' ],
            WCCSM_VERSION,
            true
        );
    }

    /**
     * Add "Components & Stock" tab to product data.
     */
    public function add_product_tab( array $tabs ): array {
        $tabs['wccsm'] = [
            'label'    => __( 'Componenten & Voorraad', 'wccsm' ),
            'target'   => 'wccsm_product_data',
            'class'    => [ 'show_if_simple', 'show_if_variable' ],
            'priority' => 65,
        ];
        return $tabs;
    }

    /**
     * Render the product data panel.
     */
    public function render_product_panel(): void {
        global $post;
        $product_id = $post->ID;
        $components = WCCSM_Components::get_components( $product_id );
        $supplier   = get_post_meta( $product_id, '_wccsm_supplier', true );
        $purchase   = get_post_meta( $product_id, '_wccsm_purchase_price', true );

        wp_nonce_field( 'wccsm_save_product', 'wccsm_product_nonce' );
        ?>
        <div id="wccsm_product_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <h4 style="padding-left:12px;"><?php esc_html_e( 'Productinformatie', 'wccsm' ); ?></h4>

                <?php
                woocommerce_wp_text_input( [
                    'id'          => '_wccsm_supplier',
                    'label'       => __( 'Leverancier', 'wccsm' ),
                    'value'       => $supplier,
                    'desc_tip'    => true,
                    'description' => __( 'De leverancier van dit product.', 'wccsm' ),
                ] );

                woocommerce_wp_text_input( [
                    'id'                => '_wccsm_purchase_price',
                    'label'             => __( 'Inkoopprijs', 'wccsm' ) . ' (' . get_woocommerce_currency_symbol() . ')',
                    'value'             => $purchase,
                    'type'              => 'number',
                    'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
                    'desc_tip'          => true,
                    'description'       => __( 'Kostprijs / inkoopprijs.', 'wccsm' ),
                ] );
                ?>
            </div>

            <div class="options_group">
                <h4 style="padding-left:12px;"><?php esc_html_e( 'Componenten', 'wccsm' ); ?></h4>
                <p style="padding-left:12px;color:#666;">
                    <?php esc_html_e( 'Definieer welke producten/onderdelen worden gebruikt voor dit product. Bij een bestelling wordt de voorraad van componenten automatisch verlaagd.', 'wccsm' ); ?>
                </p>

                <div id="wccsm-components-list" style="padding: 0 12px;">
                    <?php
                    if ( ! empty( $components ) ) {
                        foreach ( $components as $i => $comp ) {
                            $comp_product = wc_get_product( $comp['product_id'] );
                            $comp_name    = $comp_product ? $comp_product->get_formatted_name() : __( '(verwijderd product)', 'wccsm' );
                            $this->render_component_row( $i, $comp['product_id'], $comp_name, $comp['qty'] );
                        }
                    }
                    ?>
                </div>

                <p style="padding-left:12px;">
                    <button type="button" class="button" id="wccsm-add-component">
                        <?php esc_html_e( '+ Component toevoegen', 'wccsm' ); ?>
                    </button>
                </p>
            </div>
        </div>

        <script type="text/html" id="tmpl-wccsm-component-row">
            <?php $this->render_component_row( '{{INDEX}}', '', '', 1 ); ?>
        </script>

        <style>
            .wccsm-component-row {
                display: flex;
                align-items: center;
                gap: 8px;
                margin-bottom: 8px;
                padding: 8px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 3px;
            }
            .wccsm-component-row .wccsm-comp-search { flex: 1; min-width: 200px; }
            .wccsm-component-row .wccsm-comp-qty { width: 70px; }
            .wccsm-component-row .wccsm-comp-remove { color: #a00; cursor: pointer; text-decoration: none; }
            #wccsm_product_data h4 { margin: 12px 0 4px; }
        </style>
        <?php
    }

    /**
     * Render a single component row.
     *
     * @param string|int $index       Unique row index.
     * @param int|string $product_id  Component product ID (0 or '' for new empty row).
     * @param string     $product_name Display name of the component product.
     * @param int        $qty         Quantity needed.
     * @param string     $name_prefix Input name prefix. Default 'wccsm_components' (parent product).
     *                                For variations use 'wccsm_var_components[LOOP]'.
     */
    private function render_component_row( $index, $product_id, $product_name, $qty, string $name_prefix = 'wccsm_components' ): void {
        ?>
        <div class="wccsm-component-row">
            <select class="wc-product-search wccsm-comp-search"
                    name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][product_id]"
                    data-placeholder="<?php esc_attr_e( 'Zoek een product...', 'wccsm' ); ?>"
                    data-action="wccsm_search_products"
                    data-allow_clear="true">
                <?php if ( $product_id ) : ?>
                    <option value="<?php echo esc_attr( $product_id ); ?>" selected>
                        <?php echo esc_html( $product_name ); ?>
                    </option>
                <?php endif; ?>
            </select>
            <input type="number"
                   class="wccsm-comp-qty"
                   name="<?php echo esc_attr( $name_prefix ); ?>[<?php echo esc_attr( $index ); ?>][qty]"
                   value="<?php echo esc_attr( $qty ); ?>"
                   min="1" step="1"
                   placeholder="<?php esc_attr_e( 'Aantal', 'wccsm' ); ?>" />
            <a href="#" class="wccsm-comp-remove">&times;</a>
        </div>
        <?php
    }

    /**
     * Render variation-level fields (purchase price, EAN, supplier).
     */
    public function render_variation_fields( int $loop, array $variation_data, \WP_Post $variation ): void {
        $var_id   = $variation->ID;
        $purchase = get_post_meta( $var_id, '_wccsm_purchase_price', true );
        $supplier = get_post_meta( $var_id, '_wccsm_supplier', true );
        ?>
        <p class="form-row form-row-first">
            <label><?php esc_html_e( 'Inkoopprijs', 'wccsm' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label>
            <input type="number" step="0.01" min="0"
                   name="wccsm_var_purchase_price[<?php echo esc_attr( $loop ); ?>]"
                   value="<?php echo esc_attr( $purchase ); ?>" />
        </p>
        <p class="form-row form-row-last">
            <label><?php esc_html_e( 'Leverancier', 'wccsm' ); ?></label>
            <input type="text"
                   name="wccsm_var_supplier[<?php echo esc_attr( $loop ); ?>]"
                   value="<?php echo esc_attr( $supplier ); ?>" />
        </p>
        <?php
    }

    /**
     * Render component rows inside each variation panel.
     */
    public function render_variation_components( int $loop, array $variation_data, \WP_Post $variation ): void {
        $var_id      = $variation->ID;
        $components  = WCCSM_Components::get_components( $var_id );
        $name_prefix = 'wccsm_var_components[' . $loop . ']';
        ?>
        <div class="wccsm-var-components-wrap" style="padding: 0 16px 12px; width: 100%; clear: both;">
            <p style="margin: 8px 0 4px; font-weight: 600; font-size: 12px;">
                <?php esc_html_e( 'Componenten', 'wccsm' ); ?>
            </p>
            <div class="wccsm-var-components-list" data-loop="<?php echo esc_attr( $loop ); ?>">
                <?php
                if ( ! empty( $components ) ) {
                    foreach ( $components as $i => $comp ) {
                        $comp_product = wc_get_product( $comp['product_id'] );
                        $comp_name    = $comp_product ? $comp_product->get_formatted_name() : __( '(verwijderd product)', 'wccsm' );
                        $this->render_component_row( $i, $comp['product_id'], $comp_name, $comp['qty'], $name_prefix );
                    }
                }
                ?>
            </div>
            <p style="margin: 4px 0 0;">
                <button type="button" class="button wccsm-add-var-component" data-loop="<?php echo esc_attr( $loop ); ?>">
                    <?php esc_html_e( '+ Component toevoegen', 'wccsm' ); ?>
                </button>
            </p>
        </div>

        <script type="text/html" class="tmpl-wccsm-var-component-row" data-loop="<?php echo esc_attr( $loop ); ?>">
            <?php $this->render_component_row( '{{INDEX}}', '', '', 1, $name_prefix ); ?>
        </script>
        <?php
    }

    /**
     * Save product-level meta.
     */
    public function save_product_meta( int $product_id ): void {
        if ( ! isset( $_POST['wccsm_product_nonce'] ) ||
             ! wp_verify_nonce( $_POST['wccsm_product_nonce'], 'wccsm_save_product' ) ) {
            return;
        }

        // Supplier.
        if ( isset( $_POST['_wccsm_supplier'] ) ) {
            update_post_meta( $product_id, '_wccsm_supplier', sanitize_text_field( $_POST['_wccsm_supplier'] ) );
        }

        // Purchase price.
        if ( isset( $_POST['_wccsm_purchase_price'] ) ) {
            update_post_meta( $product_id, '_wccsm_purchase_price', wc_format_decimal( $_POST['_wccsm_purchase_price'] ) );
        }

        // Components.
        $components = [];
        if ( ! empty( $_POST['wccsm_components'] ) && is_array( $_POST['wccsm_components'] ) ) {
            foreach ( $_POST['wccsm_components'] as $comp ) {
                if ( ! empty( $comp['product_id'] ) ) {
                    $components[] = [
                        'product_id' => absint( $comp['product_id'] ),
                        'qty'        => max( 1, absint( $comp['qty'] ?? 1 ) ),
                    ];
                }
            }
        }
        WCCSM_Components::save_components( $product_id, $components );
    }

    /**
     * Save variation-level meta.
     */
    public function save_variation_meta( int $variation_id, int $loop ): void {
        if ( isset( $_POST['wccsm_var_purchase_price'][ $loop ] ) ) {
            update_post_meta( $variation_id, '_wccsm_purchase_price', wc_format_decimal( $_POST['wccsm_var_purchase_price'][ $loop ] ) );
        }
        if ( isset( $_POST['wccsm_var_supplier'][ $loop ] ) ) {
            update_post_meta( $variation_id, '_wccsm_supplier', sanitize_text_field( $_POST['wccsm_var_supplier'][ $loop ] ) );
        }

        // Save variation-level components.
        $components = [];
        if ( ! empty( $_POST['wccsm_var_components'][ $loop ] ) && is_array( $_POST['wccsm_var_components'][ $loop ] ) ) {
            foreach ( $_POST['wccsm_var_components'][ $loop ] as $comp ) {
                if ( ! empty( $comp['product_id'] ) ) {
                    $components[] = [
                        'product_id' => absint( $comp['product_id'] ),
                        'qty'        => max( 1, absint( $comp['qty'] ?? 1 ) ),
                    ];
                }
            }
        }
        WCCSM_Components::save_components( $variation_id, $components );
    }

    /**
     * AJAX product search for component selector.
     */
    public function ajax_search_products(): void {
        check_ajax_referer( 'search-products', 'security' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( -1 );
        }

        $term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
        if ( empty( $term ) ) {
            wp_die();
        }

        $products = wc_get_products( [
            'status'  => 'publish',
            's'       => $term,
            'limit'   => 20,
            'type'    => [ 'simple', 'variation' ],
            'return'  => 'objects',
        ] );

        $results = [];
        foreach ( $products as $product ) {
            $results[ $product->get_id() ] = rawurldecode( wp_strip_all_tags( $product->get_formatted_name() ) );
        }

        wp_send_json( $results );
    }
}
