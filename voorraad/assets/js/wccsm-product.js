/**
 * WC Central Stock Manager — Product Edit Page JS
 *
 * Handles the component rows on the product edit screen:
 * - Add new component row (parent product level)
 * - Add new component row (per variation)
 * - Remove component row
 * - Initialize WooCommerce enhanced select on new rows
 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        // ── Parent product: Add component row ──
        $('#wccsm-add-component').on('click', function (e) {
            e.preventDefault();

            var tmpl = $('#tmpl-wccsm-component-row').html();
            if (!tmpl) {
                console.warn('WCCSM: Component row template not found.');
                return;
            }

            var index = Date.now();
            tmpl = tmpl.replace(/\{\{INDEX\}\}/g, index);

            var $row = $(tmpl);
            $('#wccsm-components-list').append($row);

            $(document.body).trigger('wc-enhanced-select-init');
        });

        // ── Variation: Add component row ──
        // Use delegated event since variation panels are loaded via AJAX.
        $(document).on('click', '.wccsm-add-var-component', function (e) {
            e.preventDefault();

            var loop = $(this).data('loop');
            var $tmplScript = $('script.tmpl-wccsm-var-component-row[data-loop="' + loop + '"]');

            if (!$tmplScript.length) {
                console.warn('WCCSM: Variation component template not found for loop ' + loop);
                return;
            }

            var tmpl = $tmplScript.html();
            var index = Date.now();
            tmpl = tmpl.replace(/\{\{INDEX\}\}/g, index);

            var $row = $(tmpl);
            var $list = $('.wccsm-var-components-list[data-loop="' + loop + '"]');
            $list.append($row);

            $(document.body).trigger('wc-enhanced-select-init');
        });

        // ── Remove component row (works for both parent and variation) ──
        $(document).on('click', '.wccsm-comp-remove', function (e) {
            e.preventDefault();
            $(this).closest('.wccsm-component-row').remove();
        });

    });

})(jQuery);
