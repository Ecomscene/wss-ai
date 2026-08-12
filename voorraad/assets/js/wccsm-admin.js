/**
 * WC Central Stock Manager - Admin JS (V2)
 *
 * Handles the central stock overview page:
 * - AJAX table loading with filters and pagination
 * - Inline field editing (stock, prices, supplier, SKU, GTIN)
 * - Composed product stock display (computed, read-only, component highlighting)
 * - Bulk price update modal (regular, sale, purchase price)
 */
(function ($) {
    'use strict';

    if (typeof wccsm === 'undefined') return;

    var state = {
        page: 1,
        search: '',
        supplier: '',
        stock_status: '',
        product_type: '',
        per_page: 0,
        bulkProductId: 0,
        debounceTimer: null
    };

    /* ========================================================================
       Initialization
       ======================================================================== */

    $(document).ready(function () {
        loadSuppliers();
        loadProducts();
        bindEvents();
    });

    /* ========================================================================
       Event Binding
       ======================================================================== */

    function bindEvents() {
        // Search with debounce.
        $('#wccsm-search').on('input', function () {
            clearTimeout(state.debounceTimer);
            state.debounceTimer = setTimeout(function () {
                state.search = $('#wccsm-search').val();
                state.page = 1;
                loadProducts();
            }, 400);
        });

        // Aantal per pagina. Terug naar bladzijde 1: wie van 25 naar 500 gaat en
        // op pagina 7 stond, komt anders in het niets uit.
        //
        // Op 'change' en niet op 'input': anders vuurt hij bij elke aanslag, en
        // haalt hij tijdens het typen van 1000 eerst 1, dan 10 en dan 100 op.
        $('#wccsm-per-page').on('change', function () {
            var gevraagd = parseInt($(this).val(), 10);
            var max = parseInt($(this).attr('max'), 10) || 1000;

            if (!gevraagd || gevraagd < 1) {
                gevraagd = 50;
            }
            if (gevraagd > max) {
                gevraagd = max;
            }

            // Terugzetten wat er echt gebruikt wordt, anders staat er 5000 in beeld
            // terwijl er 1000 opgehaald wordt.
            $(this).val(gevraagd);

            state.per_page = gevraagd;
            state.page = 1;
            loadProducts();
        });

        // Enter in het vakje betekent: doen. Zonder dit moet je eerst ergens
        // anders klikken voordat er iets gebeurt.
        $('#wccsm-per-page').on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).trigger('change');
            }
        });

        // Filter changes.
        $('#wccsm-filter-supplier, #wccsm-filter-stock, #wccsm-filter-type').on('change', function () {
            state.supplier = $('#wccsm-filter-supplier').val();
            state.stock_status = $('#wccsm-filter-stock').val();
            state.product_type = $('#wccsm-filter-type').val();
            state.page = 1;
            loadProducts();
        });

        // Refresh button.
        $('#wccsm-refresh').on('click', function () {
            loadProducts();
        });

        // Inline edit - save on blur or Enter.
        $(document).on('blur', '.wccsm-inline-input', function () {
            saveField($(this));
        });

        $(document).on('keydown', '.wccsm-inline-input', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).blur();
            }
        });

        // Pagination.
        $(document).on('click', '.wccsm-page-btn', function () {
            state.page = parseInt($(this).data('page'), 10);
            loadProducts();
        });

        // Bulk price button.
        $(document).on('click', '.wccsm-bulk-btn', function () {
            openBulkModal(parseInt($(this).data('product-id'), 10));
        });

        // Modal close.
        $(document).on('click', '.wccsm-modal-close, .wccsm-modal-backdrop', function () {
            closeBulkModal();
        });

        // Bulk apply.
        $('#wccsm-bulk-apply').on('click', function () {
            applyBulkPrice();
        });

        // Bulk preview on change.
        $('#wccsm-bulk-target, #wccsm-bulk-action, #wccsm-bulk-amount').on('change input', function () {
            updateBulkPreview();
        });
    }

    /* ========================================================================
       Load Products
       ======================================================================== */

    function loadProducts() {
        var $body = $('#wccsm-table-body');
        $body.html('<tr><td colspan="10" class="wccsm-loading">' + wccsm.i18n.loading + '</td></tr>');

        $.post(wccsm.ajax_url, {
            action: 'wccsm_load_products',
            nonce: wccsm.nonce,
            page: state.page,
            search: state.search,
            supplier: state.supplier,
            stock_status: state.stock_status,
            product_type: state.product_type,
            per_page: state.per_page
        }, function (response) {
            if (!response.success || !response.data.rows.length) {
                $body.html('<tr><td colspan="10" class="wccsm-loading">' + wccsm.i18n.no_results + '</td></tr>');
                $('#wccsm-pagination').html('');
                return;
            }

            renderTable(response.data.rows);
            renderPagination(response.data);
        }).fail(function () {
            $body.html('<tr><td colspan="10" class="wccsm-loading">' + wccsm.i18n.error + '</td></tr>');
        });
    }

    /* ========================================================================
       Render Table
       ======================================================================== */

    function renderTable(rows) {
        var html = '';

        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            var rowClass = '';
            if (r.is_parent) rowClass = 'wccsm-row-parent';
            else if (r.is_variation) rowClass = 'wccsm-row-variation';

            // Out-of-stock row highlighting.
            var stockVal = r.stock;
            var stockClass = '';
            if (stockVal !== null && stockVal !== '') {
                var stockNum = parseInt(stockVal, 10);
                if (stockNum <= 0) {
                    stockClass = 'wccsm-stock-out';
                    if (!r.is_parent) rowClass += ' wccsm-row-outofstock';
                } else if (stockNum <= getLowStockThreshold()) {
                    stockClass = 'wccsm-stock-low';
                } else {
                    stockClass = 'wccsm-stock-ok';
                }
            }

            html += '<tr class="' + rowClass + '" data-id="' + r.id + '">';

            // Name
            html += '<td class="wccsm-cell-name">';
            if (r.edit_url) {
                html += '<a href="' + r.edit_url + '" target="_blank">' + escHtml(r.name) + '</a>';
            } else {
                html += escHtml(r.name);
            }
            if (r.type === 'simple' && !r.has_components) html += ' <small style="color:#888;">(Eenvoudig)</small>';
            if (r.type === 'simple' && r.has_components) html += ' <small style="color:#1a56db;">(Samengesteld)</small>';
            if (r.is_parent) html += ' <small style="color:#888;">(Variabel)</small>';
            html += '</td>';

            // SKU
            html += '<td>' + editableField(r, 'sku', r.sku, 'text', 'wccsm-input-wide') + '</td>';

            // GTIN / EAN
            html += '<td>' + editableField(r, 'gtin', r.gtin, 'text', 'wccsm-input-wide') + '</td>';

            // Supplier
            html += '<td>' + editableField(r, 'supplier', r.supplier, 'text', 'wccsm-input-wide') + '</td>';

            // Purchase price
            html += '<td>' + editableField(r, 'purchase_price', r.purchase_price, 'number') + '</td>';

            // Regular price
            if (r.is_parent) {
                html += '<td>-</td>';
            } else {
                html += '<td>' + editableField(r, 'regular_price', r.regular_price, 'number') + '</td>';
            }

            // Sale price
            if (r.is_parent) {
                html += '<td>-</td>';
            } else {
                html += '<td>' + editableField(r, 'sale_price', r.sale_price, 'number') + '</td>';
            }

            // Stock
            html += '<td>';
            if (r.is_parent) {
                html += '-';
            } else if (r.has_components) {
                // Composed product: show computed stock as read-only badge.
                var compStock = (r.computed_stock !== null) ? r.computed_stock : '?';
                var compStockClass = 'wccsm-computed-stock';
                if (compStock <= 0) compStockClass += ' wccsm-stock-out';
                else if (compStock <= getLowStockThreshold()) compStockClass += ' wccsm-stock-low';
                else compStockClass += ' wccsm-stock-ok';

                html += '<span class="' + compStockClass + '" title="Berekend op basis van componenten">'
                    + compStock + ' &#9881;</span>';
            } else {
                // Editable stock input - works whether or not stock management is
                // currently enabled. For unmanaged products the field is empty with
                // the current status as placeholder; typing a number auto-enables
                // stock management on save (also per variation).
                var managed = !!r.manage_stock;
                var inputVal = (managed && stockVal !== null && stockVal !== '') ? stockVal : '';
                var inputClass = 'wccsm-inline-input ' + (managed ? stockClass : 'wccsm-stock-unmanaged');
                var placeholder = managed ? '' : (r.stock_status || '');
                var hint = managed ? '' : 'Voorraadbeheer staat uit - typ een aantal om het in te schakelen';

                html += '<input type="number" class="' + inputClass + '"'
                    + ' data-product-id="' + r.id + '" data-field="stock"'
                    + ' data-original="' + escAttr(inputVal) + '"'
                    + ' value="' + escAttr(inputVal) + '"'
                    + ' placeholder="' + escAttr(placeholder) + '"'
                    + ' title="' + escAttr(hint) + '"'
                    + ' step="1" />';
            }
            html += '</td>';

            // Components with stock highlighting
            html += '<td>';
            if (r.components && r.components.length) {
                for (var c = 0; c < r.components.length; c++) {
                    var comp = r.components[c];
                    var badgeClass = 'wccsm-comp-badge';
                    if (comp.out) badgeClass += ' wccsm-comp-out';
                    else if (comp.stock !== null && comp.stock <= getLowStockThreshold()) badgeClass += ' wccsm-comp-low';

                    var stockLabel = (comp.stock !== null) ? ' [' + comp.stock + ']' : '';
                    html += '<span class="' + badgeClass + '">'
                        + escHtml(comp.name) + stockLabel + '</span>';
                }
            }
            html += '</td>';

            // Actions
            html += '<td>';
            if (r.is_parent) {
                html += '<button type="button" class="button wccsm-btn-small wccsm-bulk-btn" data-product-id="' + r.id + '">'
                    + 'Bulk Prijs</button>';
            }
            html += '</td>';

            html += '</tr>';
        }

        $('#wccsm-table-body').html(html);
    }

    /**
     * Build an editable input field, or dash for parent rows.
     */
    function editableField(row, field, value, type, extraClass) {
        if (row.is_parent) return '-';

        var cls = 'wccsm-inline-input' + (extraClass ? ' ' + extraClass : '');
        var step = type === 'number' ? ' step="0.01"' : '';

        return '<input type="' + type + '" class="' + cls + '"'
            + ' data-product-id="' + row.id + '"'
            + ' data-field="' + field + '"'
            + ' data-original="' + escAttr(value || '') + '"'
            + ' value="' + escAttr(value || '') + '"'
            + step + ' />';
    }

    /* ========================================================================
       Save Field
       ======================================================================== */

    function saveField($input) {
        var newVal = $input.val();
        var origVal = $input.data('original') || '';

        if (String(newVal) === String(origVal)) return;

        var productId = $input.data('product-id');
        var field = $input.data('field');

        $input.prop('disabled', true);

        $.post(wccsm.ajax_url, {
            action: 'wccsm_update_field',
            nonce: wccsm.nonce,
            product_id: productId,
            field: field,
            value: newVal
        }, function (response) {
            $input.prop('disabled', false);
            if (response.success) {
                $input.data('original', newVal);
                flashClass($input, 'wccsm-saved');

                if (field === 'stock') {
                    $input.removeClass('wccsm-stock-out wccsm-stock-low wccsm-stock-ok');
                    var num = parseInt(newVal, 10);
                    if (num <= 0) $input.addClass('wccsm-stock-out');
                    else if (num <= getLowStockThreshold()) $input.addClass('wccsm-stock-low');
                    else $input.addClass('wccsm-stock-ok');
                }
            } else {
                $input.val(origVal);
                flashClass($input, 'wccsm-error');
            }
        }).fail(function () {
            $input.prop('disabled', false);
            $input.val(origVal);
            flashClass($input, 'wccsm-error');
        });
    }

    function flashClass($el, cls) {
        $el.addClass(cls);
        setTimeout(function () {
            $el.removeClass(cls);
        }, 1200);
    }

    /* ========================================================================
       Pagination
       ======================================================================== */

    function renderPagination(data) {
        // Ook bij één pagina het aantal tonen: dat is precies het getal waar je
        // naar zoekt als je net een filter hebt aangezet.
        var telling = '<span class="wccsm-page-info">' + data.total +
            (data.total === 1 ? ' product' : ' producten') + '</span>';

        if (data.pages <= 1) {
            $('#wccsm-pagination').html(telling);
            return;
        }

        var html = telling +
            '<span class="wccsm-page-info">Pagina ' + data.page + ' van ' + data.pages + '</span>';

        if (data.page > 1) {
            html += '<button class="button wccsm-page-btn" data-page="' + (data.page - 1) + '">&laquo; Vorige</button>';
        }

        var start = Math.max(1, data.page - 3);
        var end = Math.min(data.pages, data.page + 3);

        for (var p = start; p <= end; p++) {
            var current = p === data.page ? ' current' : '';
            html += '<button class="button wccsm-page-btn' + current + '" data-page="' + p + '">' + p + '</button>';
        }

        if (data.page < data.pages) {
            html += '<button class="button wccsm-page-btn" data-page="' + (data.page + 1) + '">Volgende &raquo;</button>';
        }

        $('#wccsm-pagination').html(html);
    }

    /* ========================================================================
       Suppliers Dropdown
       ======================================================================== */

    function loadSuppliers() {
        $.post(wccsm.ajax_url, {
            action: 'wccsm_get_suppliers',
            nonce: wccsm.nonce
        }, function (response) {
            if (!response.success) return;
            var $select = $('#wccsm-filter-supplier');
            var suppliers = response.data;
            for (var i = 0; i < suppliers.length; i++) {
                $select.append('<option value="' + escAttr(suppliers[i]) + '">' + escHtml(suppliers[i]) + '</option>');
            }
        });
    }

    /* ========================================================================
       Bulk Price Modal
       ======================================================================== */

    var bulkVariations = [];

    function openBulkModal(productId) {
        state.bulkProductId = productId;
        bulkVariations = [];

        $('#wccsm-bulk-modal').show();
        $('#wccsm-bulk-product-name').text('Laden...');
        $('#wccsm-bulk-preview').html('');
        $('#wccsm-bulk-status').text('');
        $('#wccsm-bulk-amount').val('');

        $.post(wccsm.ajax_url, {
            action: 'wccsm_get_variations',
            nonce: wccsm.nonce,
            product_id: productId
        }, function (response) {
            if (!response.success) {
                $('#wccsm-bulk-product-name').text('Fout bij laden van product.');
                return;
            }
            $('#wccsm-bulk-product-name').text(response.data.product_name + ' (' + response.data.variations.length + ' variaties)');
            bulkVariations = response.data.variations;
            updateBulkPreview();
        });
    }

    function closeBulkModal() {
        $('#wccsm-bulk-modal').hide();
        state.bulkProductId = 0;
        bulkVariations = [];
    }

    function updateBulkPreview() {
        if (!bulkVariations.length) return;

        var target = $('#wccsm-bulk-target').val();
        var action = $('#wccsm-bulk-action').val();
        var amount = parseFloat($('#wccsm-bulk-amount').val()) || 0;

        var html = '<table><thead><tr><th>Variatie</th><th>Huidig</th><th>Nieuw</th></tr></thead><tbody>';

        for (var i = 0; i < bulkVariations.length; i++) {
            var v = bulkVariations[i];
            var current;
            if (target === 'purchase_price') {
                current = parseFloat(v.purchase_price) || 0;
            } else if (target === 'sale_price') {
                current = parseFloat(v.sale_price) || 0;
            } else {
                current = parseFloat(v.regular_price) || 0;
            }
            var newPrice = calcPrice(current, action, amount);

            var changedClass = (newPrice !== current) ? ' class="wccsm-price-changed"' : '';

            html += '<tr>';
            html += '<td>' + escHtml(v.name) + '</td>';
            html += '<td>' + wccsm.currency + ' ' + current.toFixed(2) + '</td>';
            html += '<td' + changedClass + '>' + wccsm.currency + ' ' + newPrice.toFixed(2) + '</td>';
            html += '</tr>';
        }

        html += '</tbody></table>';
        $('#wccsm-bulk-preview').html(html);
    }

    function calcPrice(current, action, amount) {
        switch (action) {
            case 'percent_increase':
                return Math.round((current * (1 + amount / 100)) * 100) / 100;
            case 'percent_decrease':
                return Math.max(0, Math.round((current * (1 - amount / 100)) * 100) / 100);
            case 'fixed_increase':
                return Math.round((current + amount) * 100) / 100;
            case 'fixed_decrease':
                return Math.max(0, Math.round((current - amount) * 100) / 100);
            case 'set_fixed':
                return Math.max(0, Math.round(amount * 100) / 100);
            default:
                return current;
        }
    }

    function applyBulkPrice() {
        if (!confirm(wccsm.i18n.confirm_bulk)) return;

        var $btn = $('#wccsm-bulk-apply');
        var $status = $('#wccsm-bulk-status');

        $btn.prop('disabled', true);
        $status.text(wccsm.i18n.saving);

        $.post(wccsm.ajax_url, {
            action: 'wccsm_bulk_price',
            nonce: wccsm.nonce,
            product_id: state.bulkProductId,
            target: $('#wccsm-bulk-target').val(),
            bulk_action: $('#wccsm-bulk-action').val(),
            amount: $('#wccsm-bulk-amount').val()
        }, function (response) {
            $btn.prop('disabled', false);
            if (response.success) {
                $status.text(response.data.message).css('color', '#00a32a');
                setTimeout(function () {
                    closeBulkModal();
                    loadProducts();
                }, 1000);
            } else {
                $status.text(response.data || wccsm.i18n.error).css('color', '#d63638');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.text(wccsm.i18n.error).css('color', '#d63638');
        });
    }

    /* ========================================================================
       Helpers
       ======================================================================== */

    function getLowStockThreshold() {
        return 5;
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

})(jQuery);
