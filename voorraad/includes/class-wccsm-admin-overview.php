<?php
defined( 'ABSPATH' ) || exit;

/**
 * Central Stock Overview admin page.
 *
 * Provides a single-page view of all products and variations with inline
 * editing for stock, purchase price, sale price, supplier, supplier article
 * number and delivery time. Supports filtering by supplier, stock status, and
 * product type. Data loaded via AJAX for speed.
 */
class WCCSM_Admin_Overview {

    /** De meta-key van het artikelnummer dat de leverancier zelf gebruikt. */
    public const SUPPLIER_SKU_META = '_wccsm_supplier_sku';

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
            'ajax_url'  => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'wccsm_overview' ),
            'currency'  => get_woocommerce_currency_symbol(),
            'levertijd' => self::levertijd_voor_script(),
            'i18n'      => [
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
     * De levertijdkeuzes zoals het script ze nodig heeft.
     *
     * De lijst komt uit WCCSM_Admin_Product::levertijd_choices(), dus de
     * keuzelijst in deze tabel en die op de productpagina kunnen niet
     * uiteenlopen. Dat is het hele punt: twee lijsten die onafhankelijk
     * onderhouden worden gaan verschillen, en dan schrijft het ene scherm een
     * waarde weg die het andere niet aanbiedt.
     *
     * Een lijst met paren in plaats van een object: dan blijft de volgorde
     * staan zoals hij hier is bedoeld, ook bij waarden die op een getal lijken.
     *
     * @return array
     */
    public static function levertijd_voor_script(): array {
        $keuzes = [];

        if ( class_exists( 'WCCSM_Admin_Product' ) ) {
            foreach ( WCCSM_Admin_Product::levertijd_choices() as $waarde => $label ) {
                $keuzes[] = [
                    'waarde' => (string) $waarde,
                    'label'  => (string) $label,
                ];
            }
        }

        return [
            'keuzes' => $keuzes,
            'leeg'   => __( 'Niet ingesteld', 'wccsm' ),
            /* translators: %s: de levertijd zoals hij op het product staat. */
            'eigen'  => __( '%s (eigen waarde)', 'wccsm' ),
            'erfelijk' => __( 'De levertijd staat op het hoofdproduct; kies hem op de bovenste regel.', 'wccsm' ),
        ];
    }

    /**
     * Render the overview page shell (content loaded via AJAX).
     */
    public function render_page(): void {
        include WCCSM_PLUGIN_DIR . 'templates/admin-overview.php';
    }

    /** Meer dan dit haalt een gewone shophosting niet binnen zijn tijdslimiet. */
    public const MAX_PER_PAGE = 1000;

    /**
     * Hoeveel producten er op een pagina passen.
     *
     * Je mag hier zelf een getal intypen, want soms wil je gewoon alles op een
     * scherm hebben. Er zit wel een dak op: per regel worden de voorraad, de
     * prijzen en de leverancier opgehaald, en dat loopt hard op. Boven de
     * duizend loopt een gewone shophosting tegen zijn tijdslimiet aan, en dan
     * krijg je geen lange lijst maar een halve pagina. Vandaar die grens, en
     * vandaar de waarschuwing in het scherm.
     *
     * De keuze wordt bij de gebruiker bewaard, niet bij de site: twee mensen
     * die samen een winkel doen hebben ieder hun eigen scherm en hun eigen
     * geduld.
     *
     * @param mixed $gevraagd Wat de browser meestuurde.
     * @return int
     */
    public static function per_page( $gevraagd = 0 ) {
        /* Casten en niet absint(): die maakt van -50 een keurige 50, en dan zou
           een verzoek met onzin erin alsnog de voorkeur van de gebruiker
           veranderen. */
        $gevraagd = is_numeric( $gevraagd ) ? (int) $gevraagd : 0;

        if ( $gevraagd >= 1 ) {
            $gevraagd = min( $gevraagd, self::MAX_PER_PAGE );
            update_user_meta( get_current_user_id(), 'wccsm_per_page', $gevraagd );
            return $gevraagd;
        }

        $bewaard = (int) get_user_meta( get_current_user_id(), 'wccsm_per_page', true );

        if ( $bewaard >= 1 ) {
            return min( $bewaard, self::MAX_PER_PAGE );
        }

        return 50;
    }

    /**
     * De zoekopdracht die bij deze filters hoort, zonder paginering.
     *
     * Staat apart omdat de export precies dezelfde selectie moet opleveren als
     * wat er op het scherm staat. Twee keer hetzelfde filter uitschrijven is hoe
     * je een export krijgt die net iets anders is dan de lijst, en dat merk je
     * pas als iemand op de verkeerde cijfers gaat bestellen.
     *
     * Hier zitten alleen de dingen in die de database in één keer kan
     * beantwoorden. De voorraadstatus en de LEVERANCIER niet: zie
     * filter_op_voorraad() en filter_op_leverancier().
     *
     * @param array $f search | product_type.
     * @return array
     */
    public static function bouw_args( array $f ): array {
        $args = [
            'limit'   => -1,
            'orderby' => 'name',
            'order'   => 'ASC',
            'return'  => 'ids',
            'status'  => 'publish',
        ];

        if ( ! empty( $f['search'] ) ) {
            $args['s'] = $f['search'];
        }

        $args['type'] = ! empty( $f['product_type'] )
            ? $f['product_type']
            : [ 'simple', 'variable' ];

        /* DE LEVERANCIER ZIT HIER BEWUST NIET IN
           Hij stond hier als meta_query op `_wccsm_supplier`, en dat is een
           filter op PRODUCT-niveau. De leverancier wordt door deze plugin ook
           per VARIATIE aangeboden, dus zodra hij daar staat vindt een
           meta_query het bovenliggende product niet en krijg je een filter dat
           niets doet. Nu gaat hij door filter_op_leverancier(), die een rij op
           een variatie terugrekent naar het hoofdproduct. Dezelfde aanpak als
           bij de voorraadstatus, en met hetzelfde voordeel: totaal, aantal
           pagina's en de regels op het scherm zeggen daarna hetzelfde. */

        return $args;
    }

    /**
     * Alle product-ids die bij deze filters horen, op naam gesorteerd.
     *
     * @param array $f search | supplier | product_type | stock_status.
     * @return array
     */
    public static function zoek_ids( array $f ): array {
        $ids = wc_get_products( self::bouw_args( $f ) );

        /* Zoeken op het artikelnummer van de leverancier. De `s` van
           WooCommerce kijkt naar de titel en de SKU en niet naar onze eigen
           meta, dus die treffers worden er apart bij gezocht. Daarna wordt de
           hele verzameling nog één keer opgehaald met dezelfde status- en
           typefilters, zodat de sortering op naam blijft staan in plaats van
           dat de aangevulde ids er achteraan komen te hangen. */
        if ( ! empty( $f['search'] ) ) {
            $extra = array_diff( self::zoek_op_leveranciersnummer( (string) $f['search'] ), $ids );

            if ( ! empty( $extra ) ) {
                $args = self::bouw_args( $f );
                unset( $args['s'] );
                $args['include'] = array_values( array_unique( array_merge( $ids, $extra ) ) );

                $ids = wc_get_products( $args );
            }
        }

        if ( ! empty( $f['supplier'] ) ) {
            $ids = self::filter_op_leverancier( $ids, (string) $f['supplier'] );
        }

        if ( empty( $f['stock_status'] ) ) {
            return $ids;
        }

        return self::filter_op_voorraad( $ids, (string) $f['stock_status'] );
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
        $per_page = self::per_page( $_POST['per_page'] ?? 0 );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $supplier = sanitize_text_field( $_POST['supplier'] ?? '' );
        $stock_status = sanitize_text_field( $_POST['stock_status'] ?? '' );
        $product_type = sanitize_text_field( $_POST['product_type'] ?? '' );

        /**
         * EERST UITZOEKEN WAT ER PAST, DAN PAS IN PLAKJES SNIJDEN
         *
         * Dit liep eerder uit elkaar: een deel van de filters zat in de query
         * (en telde dus mee voor het totaal en het aantal pagina's) en een deel
         * werd pas toegepast nadat de pagina al was opgehaald, door regels weg
         * te gooien. Dan telt het totaal alle producten, rekent de paginabalk
         * daarop, en blijven er op het scherm een paar over: 1317 producten,
         * zeven pagina's, zes regels.
         *
         * Nu loopt alles via zoek_ids(), dezelfde weg als de export. Daarmee
         * zeggen het totaal, de pagina's, de regels en het bestand hetzelfde.
         */
        $passend = self::zoek_ids(
            [
                'search'       => $search,
                'supplier'     => $supplier,
                'product_type' => $product_type,
                'stock_status' => $stock_status,
            ]
        );

        $total       = count( $passend );
        $product_ids = array_slice( $passend, ( $page - 1 ) * $per_page, $per_page );

        $rows = [];

        foreach ( $product_ids as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) {
                continue;
            }

            if ( $product->is_type( 'variable' ) ) {
                // Add parent row (non-editable stock - stock lives on variations).
                $parent_row = $this->build_row( $product, true );

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
                if ( ! empty( $var_rows ) || ( ! $supplier && ! $stock_status ) ) {
                    $rows[] = $parent_row;
                    $rows   = array_merge( $rows, $var_rows );
                }
            } else {
                // Simple product.
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
     * Welke van deze producten horen bij deze leverancier?
     *
     * De leverancier kan op het product staan EN op een variatie eronder. Een
     * gewone meta_query op het product mist dat tweede geval, en dan doet het
     * filter in het scherm niets. Daarom één zoekopdracht die een rij op een
     * variatie terugrekent naar het hoofdproduct, net als bij de voorraad.
     *
     * @param array  $kandidaten  Product-ids in de gewenste volgorde.
     * @param string $leverancier De gekozen leverancier.
     * @return array Dezelfde ids, in dezelfde volgorde, alleen de passende.
     */
    public static function filter_op_leverancier( array $kandidaten, string $leverancier ): array {
        global $wpdb;

        if ( empty( $kandidaten ) || '' === $leverancier ) {
            return $kandidaten;
        }

        // De ids komen uit wc_get_products en zijn dus al gehele getallen; door
        // absint() halen is de goedkoopste manier om dat ook zo te houden.
        $lijst = implode( ',', array_map( 'absint', $kandidaten ) );

        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $lijst is door absint() gehaald.
            "SELECT DISTINCT CASE WHEN p.post_type = 'product_variation'
                THEN p.post_parent ELSE p.ID END AS pid
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = p.ID AND m.meta_key = '_wccsm_supplier'
            WHERE p.post_type IN ( 'product', 'product_variation' )
                AND m.meta_value = %s
                AND ( p.ID IN ({$lijst}) OR p.post_parent IN ({$lijst}) )",
            $leverancier
        );

        $treffers = array_map( 'absint', (array) $wpdb->get_col( $sql ) );

        // array_intersect houdt de volgorde van de eerste lijst aan, en die staat
        // al op naam gesorteerd.
        return array_values( array_intersect( $kandidaten, $treffers ) );
    }

    /**
     * Product-ids waarvan het artikelnummer van de leverancier op deze tekst lijkt.
     *
     * Een rij op een variatie wordt teruggerekend naar het hoofdproduct, zodat
     * je het product in de lijst terugvindt door het nummer van één maat in te
     * typen. Er wordt hier niet op status of type gefilterd; dat doet zoek_ids()
     * erna met dezelfde argumenten als de rest van de lijst.
     *
     * @param string $term Waar op gezocht wordt.
     * @return array
     */
    public static function zoek_op_leveranciersnummer( string $term ): array {
        global $wpdb;

        $term = trim( $term );

        if ( '' === $term ) {
            return [];
        }

        $sql = $wpdb->prepare(
            "SELECT DISTINCT CASE WHEN p.post_type = 'product_variation'
                THEN p.post_parent ELSE p.ID END AS pid
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} m
                ON m.post_id = p.ID AND m.meta_key = %s
            WHERE p.post_type IN ( 'product', 'product_variation' )
                AND m.meta_value LIKE %s",
            self::SUPPLIER_SKU_META,
            '%' . $wpdb->esc_like( $term ) . '%'
        );

        return array_map( 'absint', (array) $wpdb->get_col( $sql ) );
    }

    /**
     * Welke van deze producten passen bij de gekozen voorraadstatus?
     *
     * Bij een variabel product telt het mee zodra ÉÉN variatie past: dat is ook
     * wat je in de tabel te zien krijgt, namelijk de ouder met de passende
     * variaties eronder.
     *
     * Producten zonder voorraadbeheer hebben geen aantal en vallen dus overal
     * buiten. Dat is hoe het altijd al werkte; het staat hier alleen nu op één
     * plek in plaats van verspreid over drie stukken PHP.
     *
     * @param array  $kandidaten Product-ids in de gewenste volgorde.
     * @param string $status     outofstock | lowstock | instock.
     * @return array Dezelfde ids, in dezelfde volgorde, alleen de passende.
     */
    public static function filter_op_voorraad( array $kandidaten, string $status ): array {
        global $wpdb;

        if ( empty( $kandidaten ) ) {
            return [];
        }

        $laag = absint( get_option( 'woocommerce_notify_low_stock_amount', 2 ) );

        if ( 'outofstock' === $status ) {
            $voorwaarde = 'aantal <= 0';
        } elseif ( 'lowstock' === $status ) {
            $voorwaarde = 'aantal <= ' . $laag;
        } else {
            $voorwaarde = 'aantal > 0';
        }

        // De ids komen uit wc_get_products en zijn dus al gehele getallen; door
        // absint() halen is de goedkoopste manier om dat ook zo te houden.
        $lijst = implode( ',', array_map( 'absint', $kandidaten ) );

        $sql = "SELECT DISTINCT CASE WHEN p.post_type = 'product_variation'
                THEN p.post_parent ELSE p.ID END AS pid,
                CAST( ms.meta_value AS DECIMAL(20,4) ) AS aantal
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} ms
                ON ms.post_id = p.ID AND ms.meta_key = '_stock'
            WHERE p.post_type IN ( 'product', 'product_variation' )
                AND ms.meta_value <> ''
                AND ( p.ID IN ({$lijst}) OR p.post_parent IN ({$lijst}) )
            HAVING {$voorwaarde}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- alle waarden zijn hierboven door absint() gehaald.
        $treffers = array_map( 'absint', (array) $wpdb->get_col( $sql ) );

        // array_intersect houdt de volgorde van de eerste lijst aan, en die staat
        // al op naam gesorteerd.
        return array_values( array_intersect( $kandidaten, $treffers ) );
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

        /* Het artikelnummer van de leverancier: hetzelfde patroon als de
           leverancier zelf, dus bij een variatie terugvallen op het
           hoofdproduct. Veel leveranciers hebben één bestelnummer voor het hele
           product en niet per maat. */
        $supplier_sku = $product->get_meta( self::SUPPLIER_SKU_META );
        if ( ! $supplier_sku && $parent ) {
            $supplier_sku = $parent->get_meta( self::SUPPLIER_SKU_META );
        }

        // Use WooCommerce native Global Unique ID (GTIN/EAN/UPC/ISBN).
        $gtin = $product->get_global_unique_id();
        if ( ! $gtin && $parent ) {
            $gtin = $parent->get_global_unique_id();
        }

        $purchase_price = $product->get_meta( '_wccsm_purchase_price' );

        /* De levertijd komt uit dezelfde meta-key als de keuzelijst op de
           productpagina (standaard `levertijd`, zonder onderstreepje), dus de
           waarden die een shop al via ACF of een import heeft staan er gewoon.
           get_post_meta en niet get_meta(): bij een sleutel zonder
           onderstreepje is dat de kortste weg en kan een datastore-laag er
           niets tussen krijgen.

           Bij een variatie terugvallen op het hoofdproduct, net als bij de
           leverancier en de EAN hierboven. */
        $levertijd_key = class_exists( 'WCCSM_Admin_Product' )
            ? WCCSM_Admin_Product::levertijd_meta_key()
            : 'levertijd';

        $levertijd = (string) get_post_meta( $id, $levertijd_key, true );
        if ( '' === $levertijd && $parent ) {
            $levertijd = (string) get_post_meta( $parent->get_id(), $levertijd_key, true );
        }

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
            'supplier_sku'    => $supplier_sku ?: '',
            'gtin'            => $gtin ?: '',
            'purchase_price'  => $purchase_price ?: '',
            'regular_price'   => $product->get_regular_price(),
            'sale_price'      => $product->get_sale_price(),
            'stock'           => $has_components ? $computed_stock : $product->get_stock_quantity(),
            'manage_stock'    => $product->managing_stock() || $has_components,
            'stock_status'    => $product->get_stock_status(),
            'supplier'        => $supplier ?: '',
            'levertijd'       => $levertijd,
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

            case 'supplier_sku':
                /* Het nummer waarmee bij de leverancier besteld wordt. Vrije
                   tekst: er zitten streepjes, punten en letters in, dus alleen
                   opschonen en niet omvormen. */
                update_post_meta( $product_id, self::SUPPLIER_SKU_META, wc_clean( $value ) );
                break;

            case 'levertijd':
                /* De levertijd is een eigenschap van het PRODUCT. Op een variatie
                   staat in de tabel daarom alleen de waarde van het hoofdproduct en
                   geen keuzelijst; komt er toch een verzoek voor een variatie
                   binnen, dan wordt het geweigerd in plaats van op de variatie
                   weggeschreven. Een levertijd per variatie zou een nieuw veld
                   zijn, en dat is niet gevraagd. */
                if ( $product->is_type( 'variation' ) ) {
                    wp_send_json_error( 'Levertijd staat op het hoofdproduct' );
                }

                $meta_key = class_exists( 'WCCSM_Admin_Product' )
                    ? WCCSM_Admin_Product::levertijd_meta_key()
                    : 'levertijd';
                $keuzes   = class_exists( 'WCCSM_Admin_Product' )
                    ? WCCSM_Admin_Product::levertijd_choices()
                    : [];

                $huidig = (string) get_post_meta( $product_id, $meta_key, true );
                $nieuw  = wc_clean( $value );

                /* Dezelfde controle als op de productpagina: leeg, één van de
                   keuzes, of precies de waarde die er al stond (de afwijkende
                   schrijfwijze die de lijst als "eigen waarde" terug aanbiedt).
                   Al het andere wordt geweigerd in plaats van weggeschreven, zodat
                   een gesleutelde keuzelijst geen onzin in de meta kan zetten. */
                if ( '' !== $nieuw && ! isset( $keuzes[ $nieuw ] ) && $nieuw !== $huidig ) {
                    wp_send_json_error( 'Onbekende levertijd' );
                }

                if ( $nieuw !== $huidig ) {
                    update_post_meta( $product_id, $meta_key, $nieuw );

                    /** Zie WCCSM_Admin_Product::save_delivery_time(): alles wat op
                     * een wijziging meeluistert (zoals een shopfilter) hoort hetzelfde
                     * gedrag te krijgen, of de wijziging nu van de productpagina of
                     * van dit overzicht komt. */
                    do_action( 'wccsm_levertijd_saved', $product_id, $nieuw, $huidig );
                }
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
     *
     * Alleen leveranciers die op een gepubliceerd product staan, of op een
     * variatie daarvan. Dit was een DISTINCT over heel wp_postmeta, dus er
     * konden namen in de lijst staan die alleen nog op een concept of op iets
     * in de prullenbak voorkomen. Die kun je dan kiezen en dan vindt het filter
     * per definitie niets, en dat is niet van een kapot filter te onderscheiden.
     */
    public function ajax_get_suppliers(): void {
        check_ajax_referer( 'wccsm_overview', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;

        $suppliers = $wpdb->get_col(
            "SELECT DISTINCT m.meta_value
             FROM {$wpdb->postmeta} m
             INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
             LEFT JOIN {$wpdb->posts} ouder ON ouder.ID = p.post_parent
             WHERE m.meta_key = '_wccsm_supplier'
               AND m.meta_value <> ''
               AND (
                    ( p.post_type = 'product' AND p.post_status = 'publish' )
                 OR ( p.post_type = 'product_variation' AND ouder.post_status = 'publish' )
               )
             ORDER BY m.meta_value ASC"
        );

        wp_send_json_success( $suppliers );
    }
}
