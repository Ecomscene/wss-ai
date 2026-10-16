/**
 * WC Central Stock Manager - Admin JS (V2)
 *
 * Handles the central stock overview page:
 * - AJAX table loading with filters and pagination
 * - Inline field editing (stock, prices, supplier, supplier article number,
 *   SKU, GTIN, delivery time)
 * - Composed product stock display (computed, read-only, component highlighting)
 * - Bulk price update modal (regular, sale, purchase price)
 * - Row selection (per product / per size, "select all" = visible rows) and a
 *   bulk bar that works on the selected rows in portions via AJAX
 */
(function ($) {
    'use strict';

    if (typeof wccsm === 'undefined') return;

    /* Aantal kolommen in de tabel. Staat hier als één getal omdat de melding
       "Laden...", "Geen producten gevonden" en de foutregel er allemaal over
       moeten lopen; drie losse getallen in de code is hoe er na een nieuwe
       kolom één meldingsregel scheef onder de tabel komt te hangen.

       Loopt mee met templates/admin-overview.php: staat daar een kolom bij, dan
       hoort dit getal mee omhoog. 13 sinds de selectiekolom vooraan. */
    var KOLOMMEN = 13;

    var state = {
        page: 1,
        search: '',
        supplier: '',
        stock_status: '',
        product_type: '',
        per_page: 0,
        bulkProductId: 0,
        debounceTimer: null,
        bulkBusy: false
    };

    /* ========================================================================
       Initialization
       ======================================================================== */

    $(document).ready(function () {
        loadSuppliers();
        loadProducts();
        bindEvents();
        bindSelection();
        bindBulkBar();
        updateSelectionUi();
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

        // Keuzelijst in de tabel: opslaan zodra je kiest, en niet pas bij blur.
        // Bij een keuzelijst IS kiezen de handeling; wachten tot je ergens anders
        // klikt voelt alsof het niet is aangekomen.
        $(document).on('change', '.wccsm-inline-select', function () {
            saveField($(this));
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

    /**
     * De exportlink laten meelopen met de filters.
     *
     * Staat hier en niet in de ready-functie: loadProducts() roept hem aan, en
     * die staat op dit niveau. Een functie die binnen ready gedefinieerd is,
     * bestaat daarbuiten niet, en dan valt het laden van de lijst stil op een
     * fout die je alleen in de console ziet.
     */
    function ververExportLink() {
        var $link = $('#wccsm-export');
        if (!$link.length) {
            return;
        }

        // De kale link met de beveiligingssleutel erin bewaren we één keer;
        // daarna wordt hij telkens opnieuw met de filters aangevuld.
        var basis = $link.data('basis');
        if (!basis) {
            basis = $link.attr('href');
            $link.data('basis', basis);
        }

        $link.attr('href', basis +
            '&search=' + encodeURIComponent(state.search) +
            '&supplier=' + encodeURIComponent(state.supplier) +
            '&stock_status=' + encodeURIComponent(state.stock_status) +
            '&product_type=' + encodeURIComponent(state.product_type));
    }

    /** Een melding die over de hele breedte van de tabel loopt. */
    function meldingRij(tekst) {
        return '<tr><td colspan="' + KOLOMMEN + '" class="wccsm-loading">' + escHtml(tekst) + '</td></tr>';
    }

    /**
     * @param {Object} [keep] Map of selected item ids to re-tick after loading
     *                        (only used after a bulk action; filters/paging clear it).
     */
    function loadProducts(keep) {
        // Elke keer dat de lijst opnieuw geladen wordt, zijn de filters de
        // actuele. Dan hoort de exportlink daar ook bij te staan; dit is de enige
        // plek waar dat gegarandeerd is.
        ververExportLink();

        var $body = $('#wccsm-table-body');
        $body.html(meldingRij(wccsm.i18n.loading));
        updateSelectionUi();

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
                $body.html(meldingRij(wccsm.i18n.no_results));
                $('#wccsm-pagination').html('');
                updateSelectionUi();
                return;
            }

            renderTable(response.data.rows);
            renderPagination(response.data);
            if (keep) restoreSelection(keep);
            updateSelectionUi();
        }).fail(function () {
            $body.html(meldingRij(wccsm.i18n.error));
            updateSelectionUi();
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

            html += '<tr class="' + rowClass + '" data-id="' + r.id + '"'
                + (r.parent_id ? ' data-parent-id="' + r.parent_id + '"' : '') + '>';

            // Selectie: op een hoofdproduct vinkt het vakje alle (zichtbare) maten
            // eronder aan; op een maat of enkelvoudig product alleen die regel.
            html += '<td class="wccsm-cell-select">';
            if (r.is_parent) {
                html += '<input type="checkbox" class="wccsm-sel-parent" data-sel-parent="' + r.id + '"'
                    + ' title="Product met alle maten selecteren" />';
            } else {
                html += '<input type="checkbox" class="wccsm-sel-item" data-sel-id="' + r.id + '"'
                    + ' data-sel-parent-id="' + (r.parent_id || 0) + '" />';
            }
            html += '</td>';

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

            // Art.nr. leverancier. Op de regel van een variatie staat de waarde
            // van het hoofdproduct voorgevuld; wie er een eigen nummer per maat
            // intypt, overschrijft alleen die ene variatie.
            html += '<td>' + editableField(r, 'supplier_sku', r.supplier_sku, 'text', 'wccsm-input-wide') + '</td>';

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

            // Levertijd
            html += '<td class="wccsm-cell-levertijd">' + levertijdVeld(r) + '</td>';

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
     * De levertijd-cel: een keuzelijst op het product, en op een variatie de
     * waarde van het hoofdproduct in het grijs.
     *
     * De levertijd hoort bij het product, dus hij wordt op de bovenste regel
     * gekozen. Op elke variatieregel ook een keuzelijst zetten zou suggereren
     * dat je er per maat een andere levertijd in kunt zetten, en dat kan niet:
     * dat zou een nieuw veld zijn.
     *
     * Staat er een waarde op het product die niet in de lijst voorkomt (een
     * afwijkende schrijfwijze, iets uit een import), dan komt die er als eigen
     * optie bij en staat hij gekozen. Zo kan één klik in deze tabel nooit
     * stilzwijgend een bestaande waarde weggooien.
     */
    function levertijdVeld(row) {
        var teksten = wccsm.levertijd || {};
        var keuzes = teksten.keuzes || [];
        var leeg = teksten.leeg || '-';
        var huidig = row.levertijd || '';

        if (row.is_variation) {
            return '<span class="wccsm-levertijd-geerfd" title="' + escAttr(teksten.erfelijk || '') + '">'
                + escHtml(huidig || leeg) + '</span>';
        }

        var bekend = false;
        for (var i = 0; i < keuzes.length; i++) {
            if (keuzes[i].waarde === huidig) {
                bekend = true;
                break;
            }
        }

        var html = '<select class="wccsm-inline-select"'
            + ' data-product-id="' + row.id + '"'
            + ' data-field="levertijd"'
            + ' data-original="' + escAttr(huidig) + '">';

        html += '<option value=""' + (huidig === '' ? ' selected' : '') + '>' + escHtml(leeg) + '</option>';

        for (var k = 0; k < keuzes.length; k++) {
            var geselecteerd = (keuzes[k].waarde === huidig) ? ' selected' : '';
            html += '<option value="' + escAttr(keuzes[k].waarde) + '"' + geselecteerd + '>'
                + escHtml(keuzes[k].label) + '</option>';
        }

        if (huidig !== '' && !bekend) {
            var eigenLabel = (teksten.eigen || '%s').replace('%s', huidig);
            html += '<option value="' + escAttr(huidig) + '" selected>' + escHtml(eigenLabel) + '</option>';
        }

        html += '</select>';

        return html;
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

    /**
     * Vult het leveranciersfilter en de suggestielijst van de bulkbalk.
     *
     * Kan opnieuw aangeroepen worden (na een bulk "leverancier zetten" komt er
     * misschien een naam bij), dus eerst leegmaken en de keuze in het filter
     * terugzetten.
     */
    function loadSuppliers() {
        $.post(wccsm.ajax_url, {
            action: 'wccsm_get_suppliers',
            nonce: wccsm.nonce
        }, function (response) {
            if (!response.success) return;
            var $select = $('#wccsm-filter-supplier');
            var current = $select.val();
            var $list = $('#wccsm-supplier-list');
            var suppliers = response.data;

            $select.find('option:not(:first)').remove();
            $list.empty();
            for (var i = 0; i < suppliers.length; i++) {
                $select.append('<option value="' + escAttr(suppliers[i]) + '">' + escHtml(suppliers[i]) + '</option>');
                $list.append('<option value="' + escAttr(suppliers[i]) + '"></option>');
            }
            $select.val(current);
        });
    }

    /* ========================================================================
       Selection (per product, per size, select all visible)
       ======================================================================== */

    function bindSelection() {
        // Alles selecteren = alles wat nu in beeld staat (dus na filter/zoeken).
        $(document).on('change', '#wccsm-select-all', function () {
            var on = $(this).is(':checked');
            $('#wccsm-table-body .wccsm-sel-item, #wccsm-table-body .wccsm-sel-parent').prop('checked', on).prop('indeterminate', false);
            updateSelectionUi();
        });

        // Productregel = al zijn (zichtbare) maten.
        $(document).on('change', '.wccsm-sel-parent', function () {
            var pid = $(this).data('sel-parent');
            $('#wccsm-table-body .wccsm-sel-item[data-sel-parent-id="' + pid + '"]').prop('checked', $(this).is(':checked'));
            $(this).prop('indeterminate', false);
            updateSelectionUi();
        });

        $(document).on('change', '.wccsm-sel-item', function () {
            updateSelectionUi();
        });
    }

    /** Re-derive parent/header checkbox state, row highlight and the counter. */
    function updateSelectionUi() {
        var $body = $('#wccsm-table-body');

        $body.find('.wccsm-sel-parent').each(function () {
            var pid = $(this).data('sel-parent');
            var $kids = $body.find('.wccsm-sel-item[data-sel-parent-id="' + pid + '"]');
            if (!$kids.length) return; // Parent without visible sizes keeps its own state.
            var n = $kids.filter(':checked').length;
            $(this).prop('checked', n === $kids.length).prop('indeterminate', n > 0 && n < $kids.length);
        });

        $body.find('tr').each(function () {
            var $cb = $(this).find('.wccsm-sel-item, .wccsm-sel-parent').first();
            $(this).toggleClass('wccsm-row-selected', $cb.length > 0 && $cb.is(':checked'));
        });

        var $all = $body.find('.wccsm-sel-item, .wccsm-sel-parent');
        var checked = $all.filter(':checked').length;
        $('#wccsm-select-all')
            .prop('checked', $all.length > 0 && checked === $all.length)
            .prop('indeterminate', checked > 0 && checked < $all.length);

        var count = selectedItemIds().length;
        $('#wccsm-bulk-count').text(count + ' geselecteerd');
        $('#wccsm-bulkbar').toggleClass('is-empty', count === 0);
        $('#wccsm-bulk-run').prop('disabled', count === 0 || state.bulkBusy);
    }

    /** IDs of selected simple products and sizes (variations). */
    function selectedItemIds() {
        var ids = [];
        $('#wccsm-table-body .wccsm-sel-item:checked').each(function () {
            var id = parseInt($(this).data('sel-id'), 10);
            if (id && ids.indexOf(id) < 0) ids.push(id);
        });
        return ids;
    }

    /** IDs of fully selected variable products. */
    function selectedParentIds() {
        var ids = [];
        $('#wccsm-table-body .wccsm-sel-parent:checked').each(function () {
            var id = parseInt($(this).data('sel-parent'), 10);
            if (id && ids.indexOf(id) < 0) ids.push(id);
        });
        return ids;
    }

    function snapshotSelection() {
        var keep = { items: {}, parents: {} };
        $.each(selectedItemIds(), function (i, id) { keep.items[id] = true; });
        $.each(selectedParentIds(), function (i, id) { keep.parents[id] = true; });
        return keep;
    }

    function restoreSelection(keep) {
        $('#wccsm-table-body .wccsm-sel-item').each(function () {
            if (keep.items[$(this).data('sel-id')]) $(this).prop('checked', true);
        });
        $('#wccsm-table-body .wccsm-sel-parent').each(function () {
            if (keep.parents[$(this).data('sel-parent')]) $(this).prop('checked', true);
        });
    }

    /* ========================================================================
       Bulk bar (works on the selected rows)
       ======================================================================== */

    function bindBulkBar() {
        $('#wccsm-bulk-op').on('change', function () {
            var op = $(this).val();
            $('.wccsm-bulk-fields').hide();
            $('.wccsm-bulk-fields[data-op="' + op + '"]').css('display', 'inline-flex');
            updateBolPriceLabel();
        });
        $('#wccsm-bulk-bolmode').on('change', updateBolPriceLabel);

        $('#wccsm-bulk-run').on('click', function () {
            runBulk();
        });
    }

    function updateBolPriceLabel() {
        var mode = $('#wccsm-bulk-bolmode').val();
        $('#wccsm-bulk-bolunit').text(mode === 'set_fixed' ? wccsm.currency : '%');
    }

    /** Collect + validate the parameters of the chosen bulk action. */
    function bulkRequest() {
        var op = $('#wccsm-bulk-op').val();
        var amount;
        switch (op) {
            case 'bol_on':
                return { op: op, params: {}, label: 'Bol AAN zetten', note: 'Artikelen zonder EAN of op de uitsluitlijst worden overgeslagen.' };
            case 'bol_off':
                return { op: op, params: {}, label: 'Bol UIT zetten' };
            case 'bol_price':
                amount = parseFloat($('#wccsm-bulk-bolamount').val());
                if (!(amount > 0)) return { error: 'Vul een bedrag of percentage groter dan 0 in.' };
                var mode = $('#wccsm-bulk-bolmode').val();
                var desc = mode === 'set_fixed' ? wccsm.currency + ' ' + amount.toFixed(2)
                    : (mode === 'percent_up' ? amount + '% boven' : amount + '% onder') + ' de normale prijs';
                return { op: op, params: { mode: mode, amount: amount }, label: 'Bol-prijs zetten op ' + desc };
            case 'supplier':
                var v = $.trim($('#wccsm-bulk-supplier').val());
                if (!v) return { error: 'Vul een leverancier in.' };
                return { op: op, params: { value: v }, label: 'Leverancier zetten op "' + v + '"' };
            case 'price':
                amount = parseFloat($('#wccsm-bulk-priceamount').val());
                if (!(amount > 0)) return { error: 'Vul een bedrag groter dan 0 in.' };
                return {
                    op: op,
                    params: { target: $('#wccsm-bulk-pricetarget').val(), bulk_action: $('#wccsm-bulk-priceaction').val(), amount: amount },
                    label: $('#wccsm-bulk-pricetarget option:selected').text() + ': '
                        + $('#wccsm-bulk-priceaction option:selected').text().toLowerCase() + ' ' + amount
                };
        }
        return { error: 'Kies eerst een bulkactie.' };
    }

    function runBulk() {
        if (state.bulkBusy) return;

        var $result = $('#wccsm-bulk-result');
        var req = bulkRequest();
        if (req.error) {
            setResult('is-error', escHtml(req.error));
            return;
        }

        var items = selectedItemIds();
        if (!items.length) {
            setResult('is-error', 'Er is niets geselecteerd.');
            return;
        }

        // De leverancier gaat ook op volledig aangevinkte variabele producten,
        // zodat het leveranciersfilter (dat naar het hoofdproduct kijkt) ze blijft vinden.
        var ids = items.slice();
        var parents = req.op === 'supplier' ? selectedParentIds() : [];
        $.each(parents, function (i, id) { if (ids.indexOf(id) < 0) ids.push(id); });

        var msg = req.label + ' voor ' + items.length + ' geselecteerde artikel' + (items.length === 1 ? '' : 'en')
            + (parents.length ? ' (+ ' + parents.length + ' hoofdproduct' + (parents.length === 1 ? '' : 'en') + ')' : '') + '?'
            + (req.note ? '\n\n' + req.note : '');
        if (!confirm(msg)) return;

        var chunk = parseInt(wccsm.bulk_chunk, 10) || 25;
        var portions = [];
        for (var i = 0; i < ids.length; i += chunk) portions.push(ids.slice(i, i + chunk));

        var total = { done: 0, skipped: [] };
        var keep = snapshotSelection();
        var processed = 0;

        state.bulkBusy = true;
        $('#wccsm-bulk-run, #wccsm-bulk-op').prop('disabled', true);
        $result.removeClass('is-ok is-warn is-error').text('Bezig… 0 / ' + ids.length);

        function next(n) {
            if (n >= portions.length) {
                finish();
                return;
            }
            var data = $.extend({
                action: 'wccsm_bulk_apply',
                nonce: wccsm.nonce,
                op: req.op,
                ids: portions[n]
            }, req.params);

            $.post(wccsm.ajax_url, data, function (response) {
                if (!response || !response.success) {
                    fail((response && response.data) ? String(response.data) : wccsm.i18n.error);
                    return;
                }
                total.done += response.data.done;
                total.skipped = total.skipped.concat(response.data.skipped || []);
                processed += portions[n].length;
                $result.text('Bezig… ' + processed + ' / ' + ids.length);
                next(n + 1);
            }).fail(function () {
                fail(wccsm.i18n.error);
            });
        }

        function fail(error) {
            done();
            setResult('is-error', 'Gestopt: ' + escHtml(error) + '. ' + total.done + ' artikelen waren al bijgewerkt.');
            loadProducts(keep);
        }

        function finish() {
            done();
            var html = '<strong>' + total.done + ' bijgewerkt</strong>';
            if (total.skipped.length) {
                html += ', <strong>' + total.skipped.length + ' overgeslagen</strong>:';
                var groups = {};
                var order = [];
                $.each(total.skipped, function (i, s) {
                    if (!groups[s.reason]) { groups[s.reason] = []; order.push(s.reason); }
                    groups[s.reason].push(s.name);
                });
                html += '<ul>';
                $.each(order, function (i, reason) {
                    var names = groups[reason];
                    html += '<li>' + escHtml(reason) + ': ' + names.length
                        + ' <small>(' + escHtml(names.slice(0, 8).join(', ')) + (names.length > 8 ? ', …' : '') + ')</small></li>';
                });
                html += '</ul>';
            }
            setResult(total.skipped.length ? 'is-warn' : 'is-ok', html);
            if (req.op === 'supplier') loadSuppliers();
            loadProducts(keep);
        }

        function done() {
            state.bulkBusy = false;
            $('#wccsm-bulk-op').prop('disabled', false);
            updateSelectionUi();
        }

        next(0);
    }

    function setResult(cls, html) {
        $('#wccsm-bulk-result').removeClass('is-ok is-warn is-error').addClass(cls).html(html);
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
