<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wccsm-wrap">
    <h1><?php esc_html_e( 'Voorraadbeheer', 'wccsm' ); ?></h1>

    <!-- Filters Bar -->
    <div class="wccsm-filters">
        <input type="text" id="wccsm-search" placeholder="<?php esc_attr_e( 'Zoeken op naam of SKU...', 'wccsm' ); ?>" class="wccsm-filter-input" />

        <select id="wccsm-filter-supplier" class="wccsm-filter-select">
            <option value=""><?php esc_html_e( 'Alle leveranciers', 'wccsm' ); ?></option>
        </select>

        <select id="wccsm-filter-stock" class="wccsm-filter-select">
            <option value=""><?php esc_html_e( 'Alle voorraadstatussen', 'wccsm' ); ?></option>
            <option value="outofstock"><?php esc_html_e( 'Niet op voorraad', 'wccsm' ); ?></option>
            <option value="lowstock"><?php esc_html_e( 'Lage voorraad', 'wccsm' ); ?></option>
            <option value="instock"><?php esc_html_e( 'Op voorraad', 'wccsm' ); ?></option>
        </select>

        <select id="wccsm-filter-type" class="wccsm-filter-select">
            <option value=""><?php esc_html_e( 'Alle typen', 'wccsm' ); ?></option>
            <option value="simple"><?php esc_html_e( 'Eenvoudig', 'wccsm' ); ?></option>
            <option value="variable"><?php esc_html_e( 'Variabel', 'wccsm' ); ?></option>
        </select>

        <select id="wccsm-per-page" class="wccsm-filter-select" title="<?php esc_attr_e( 'Aantal producten per pagina', 'wccsm' ); ?>">
            <?php
            $wccsm_nu = WCCSM_Admin_Overview::per_page();
            foreach ( [ 25, 50, 100, 200 ] as $wccsm_aantal ) :
            ?>
                <option value="<?php echo esc_attr( $wccsm_aantal ); ?>" <?php selected( $wccsm_nu, $wccsm_aantal ); ?>>
                    <?php
                    /* translators: %d: aantal producten per pagina. */
                    printf( esc_html__( '%d per pagina', 'wccsm' ), (int) $wccsm_aantal );
                    ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="button" class="button" id="wccsm-refresh">
            <?php esc_html_e( 'Vernieuwen', 'wccsm' ); ?>
        </button>
    </div>

    <!-- Table -->
    <div class="wccsm-table-wrap">
        <table class="wccsm-table widefat striped">
            <thead>
                <tr>
                    <th class="wccsm-col-name"><?php esc_html_e( 'Product', 'wccsm' ); ?></th>
                    <th class="wccsm-col-sku"><?php esc_html_e( 'SKU', 'wccsm' ); ?></th>
                    <th class="wccsm-col-ean"><?php esc_html_e( 'GTIN / EAN', 'wccsm' ); ?></th>
                    <th class="wccsm-col-supplier"><?php esc_html_e( 'Leverancier', 'wccsm' ); ?></th>
                    <th class="wccsm-col-purchase"><?php esc_html_e( 'Inkoopprijs', 'wccsm' ); ?></th>
                    <th class="wccsm-col-regular"><?php esc_html_e( 'Normaal', 'wccsm' ); ?></th>
                    <th class="wccsm-col-sale"><?php esc_html_e( 'Actie', 'wccsm' ); ?></th>
                    <th class="wccsm-col-stock"><?php esc_html_e( 'Voorraad', 'wccsm' ); ?></th>
                    <th class="wccsm-col-components"><?php esc_html_e( 'Componenten', 'wccsm' ); ?></th>
                    <th class="wccsm-col-actions"><?php esc_html_e( 'Acties', 'wccsm' ); ?></th>
                </tr>
            </thead>
            <tbody id="wccsm-table-body">
                <tr><td colspan="10" class="wccsm-loading"><?php esc_html_e( 'Laden...', 'wccsm' ); ?></td></tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="wccsm-pagination" id="wccsm-pagination"></div>

    <!-- Bulk Price Modal -->
    <div id="wccsm-bulk-modal" class="wccsm-modal" style="display:none;">
        <div class="wccsm-modal-backdrop"></div>
        <div class="wccsm-modal-content">
            <div class="wccsm-modal-header">
                <h2><?php esc_html_e( 'Bulk prijswijziging', 'wccsm' ); ?></h2>
                <button type="button" class="wccsm-modal-close">&times;</button>
            </div>
            <div class="wccsm-modal-body">
                <p id="wccsm-bulk-product-name" style="font-weight:600;"></p>

                <table class="form-table">
                    <tr>
                        <th><label for="wccsm-bulk-target"><?php esc_html_e( 'Prijsveld', 'wccsm' ); ?></label></th>
                        <td>
                            <select id="wccsm-bulk-target">
                                <option value="regular_price"><?php esc_html_e( 'Normale prijs', 'wccsm' ); ?></option>
                                <option value="sale_price"><?php esc_html_e( 'Actieprijs', 'wccsm' ); ?></option>
                                <option value="purchase_price"><?php esc_html_e( 'Inkoopprijs', 'wccsm' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wccsm-bulk-action"><?php esc_html_e( 'Actie', 'wccsm' ); ?></label></th>
                        <td>
                            <select id="wccsm-bulk-action">
                                <option value="percent_increase"><?php esc_html_e( 'Verhogen met %', 'wccsm' ); ?></option>
                                <option value="percent_decrease"><?php esc_html_e( 'Verlagen met %', 'wccsm' ); ?></option>
                                <option value="fixed_increase"><?php esc_html_e( 'Verhogen met vast bedrag', 'wccsm' ); ?></option>
                                <option value="fixed_decrease"><?php esc_html_e( 'Verlagen met vast bedrag', 'wccsm' ); ?></option>
                                <option value="set_fixed"><?php esc_html_e( 'Vaste prijs instellen', 'wccsm' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wccsm-bulk-amount"><?php esc_html_e( 'Bedrag', 'wccsm' ); ?></label></th>
                        <td>
                            <input type="number" id="wccsm-bulk-amount" step="0.01" min="0" value="" class="regular-text" />
                        </td>
                    </tr>
                </table>

                <div id="wccsm-bulk-preview" style="margin-top:12px;"></div>
            </div>
            <div class="wccsm-modal-footer">
                <button type="button" class="button button-primary" id="wccsm-bulk-apply">
                    <?php esc_html_e( 'Toepassen', 'wccsm' ); ?>
                </button>
                <button type="button" class="button wccsm-modal-close">
                    <?php esc_html_e( 'Annuleren', 'wccsm' ); ?>
                </button>
                <span id="wccsm-bulk-status" style="margin-left:12px;"></span>
            </div>
        </div>
    </div>
</div>
