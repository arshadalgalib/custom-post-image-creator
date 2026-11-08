/**
 * Jobs & Logs UI – list jobs, filter by status, retry, process, delete.
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

    function statusBadge(status) {
        var map = {
            pending: 'Pending', processing: 'Processing', generated: 'Generated',
            publishing: 'Publishing', published: 'Published', failed: 'Failed'
        };
        return map[status] || status;
    }

    function renderJobs(data) {
        var $body = $('#cpic-jobs-body');
        var jobs = data.jobs || [];
        $body.empty();

        if (!jobs.length) {
            $body.append('<tr><td colspan="8">No jobs found.</td></tr>');
            return;
        }

        jobs.forEach(function (job) {
            var cls = job.status === 'failed' ? 'cpic-disconnected' : 'cpic-connected';
            var imageUrl = safeHttpUrl(job.image_url);
            var img = imageUrl ? '<a href="' + escapeHtml(imageUrl) + '" target="_blank" rel="noopener">View</a>' : '—';
            var fb = job.facebook_post_id ? job.facebook_post_id : '—';
            var err = job.last_error ? job.last_error : '—';
            var actions =
                '<button type="button" class="button button-small cpic-job-retry" data-id="' + job.id + '">Retry</button> ' +
                '<button type="button" class="button button-small cpic-job-process" data-id="' + job.id + '">Process</button> ' +
                '<button type="button" class="button button-small button-link-delete cpic-job-delete" data-id="' + job.id + '">Delete</button>';

            $body.append(
                '<tr>' +
                '<td><strong>' + escapeHtml(job.post_title) + '</strong><br /><small>#' + job.post_id + '</small></td>' +
                '<td>' + escapeHtml(job.template_name || '—') + '</td>' +
                '<td><span class="' + cls + '">' + escapeHtml(statusBadge(job.status)) + '</span><br /><small>' + img + '</small></td>' +
                '<td>' + job.attempts + ' / ' + job.max_retries + '</td>' +
                '<td>' + escapeHtml(fb) + '<br /><small>Comment: ' + escapeHtml(job.facebook_comment_id || '—') + '</small></td>' +
                '<td>' + escapeHtml(err) + '</td>' +
                '<td>' + escapeHtml(job.updated_at || '') + '</td>' +
                '<td>' + actions + '</td>' +
                '</tr>'
            );
        });
    }

    function safeHttpUrl(value) {
        if (!value) return '';
        try {
            var url = new URL(String(value), window.location.href);
            return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : '';
        } catch (error) {
            return '';
        }
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str == null ? '' : String(str)));
        return div.innerHTML;
    }

    function loadJobs() {
        var status = $('#cpic-jobs-filter').val() || '';
        cpicAjax('cpic_get_jobs', { status: status }, function (d) {
            renderJobs(d);
        });
    }

    $(document).ready(function () {
        $('#cpic-jobs-refresh').on('click', loadJobs);
        $('#cpic-jobs-filter').on('change', loadJobs);

        $(document).on('click', '.cpic-job-retry', function () {
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_job_retry', { job_id: id }, function (d) {
                alert(d.message);
                loadJobs();
            });
        });

        $(document).on('click', '.cpic-job-process', function () {
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_job_process', { job_id: id }, function (d) {
                loadJobs();
            });
        });

        $(document).on('click', '.cpic-job-delete', function () {
            if (!confirm('Delete this job?')) return;
            var id = parseInt($(this).data('id'), 10);
            cpicAjax('cpic_job_delete', { job_id: id }, function (d) {
                loadJobs();
            });
        });

        loadJobs();
        window.setInterval(loadJobs, 30000);
    });

})(jQuery);