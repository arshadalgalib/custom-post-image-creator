/**
 * Category Mapping UI – save per-row mappings and the default template.
 *
 * Priority rule (lowest number wins) is documented in the view itself.
 */

/* global jQuery, cpicData */
(function ($) {
    'use strict';

    var ajax  = cpicData.ajaxUrl;
    var nonce = cpicData.nonce;
    var nf    = cpicData.nonceField;
    var i18n  = cpicData.i18n;

    function cpicAjax(action, data, onSuccess, onError) {
        data = data || {};
        data.action = action;
        data[nf]    = nonce;
        $.post(ajax, data, function (res) {
            if (res.success) {
                if (onSuccess) onSuccess(res.data);
            } else {
                var msg = (res.data && res.data.message) ? res.data.message : i18n.error;
                if (onError) onError(msg); else alert(msg);
            }
        }).fail(function () {
            var msg = i18n.error;
            if (onError) onError(msg); else alert(msg);
        });
    }

    function collectMappings() {
        var mappings = [];
        $('.cpic-cat-template').each(function () {
            var termId = parseInt($(this).data('term'), 10);
            var templateId = parseInt($(this).val(), 10) || 0;
            var priority = parseInt($('.cpic-cat-priority[data-term="' + termId + '"]').val(), 10) || 1;
            mappings.push({ term_id: termId, template_id: templateId, priority: priority });
        });
        return mappings;
    }

    function saveAll(onDone) {
        cpicAjax('cpic_category_save', {
            mappings: collectMappings(),
            default_template_id: parseInt($('#cpic-default-template-id').val(), 10) || 0
        }, function (d) {
            var $s = $('#cpic-cat-status');
            $s.text(d.message).css('color', '#28a745');
            if (onDone) onDone();
        }, function (msg) {
            $('#cpic-cat-status').text(msg).css('color', '#dc3545');
            if (onDone) onDone();
        });
    }

    $(document).ready(function () {
        $('#cpic-cat-save-all').on('click', function () {
            saveAll();
        });

        $(document).on('click', '.cpic-cat-save', function () {
            var termId = parseInt($(this).data('term'), 10);
            var mapping = collectMappings().find(function (m) { return m.term_id === termId; });
            cpicAjax('cpic_category_save', {
                mappings: mapping ? [mapping] : [],
                default_template_id: parseInt($('#cpic-default-template-id').val(), 10) || 0
            }, function (d) {
                $('#cpic-cat-status').text(d.message).css('color', '#28a745');
            }, function (msg) {
                $('#cpic-cat-status').text(msg).css('color', '#dc3545');
            });
        });
    });

})(jQuery);