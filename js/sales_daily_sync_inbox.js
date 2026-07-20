/**
 * Daily sync inbox — pending CSV list + analyze via shared matrix modal.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function formatMaltaDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso.replace(' ', 'T') + 'Z');
        if (Number.isNaN(d.getTime())) return escapeHtml(iso);
        return d.toLocaleString('en-GB', { timeZone: 'Europe/Malta' });
    }

    function statusBadge(status) {
        const map = {
            ready: ['Ready', 'var(--pm-proc)'],
            applied: ['Applied', 'var(--pm-avail)'],
            superseded: ['Superseded', 'var(--pm-text-muted)'],
        };
        const pair = map[status] || [status, 'var(--pm-text-muted)'];
        return '<span style="color:' + pair[1] + '; font-weight:700;">' + escapeHtml(pair[0]) + '</span>';
    }

    function renderFiles(files) {
        const tbody = document.getElementById('syncInboxFilesBody');
        if (!tbody) return;
        if (!files.length) {
            tbody.innerHTML =
                '<tr><td colspan="5" style="padding:24px;text-align:center;color:var(--pm-text-muted);">No reports in the inbox yet. Email ingest will appear here; until then use manual upload below.</td></tr>';
            return;
        }
        tbody.innerHTML = files
            .map(function (f) {
                const syncBtn =
                    f.file_status === 'ready'
                        ? '<button type="button" class="btn-heavy btn-green sync-inbox-run" data-file-id="' +
                          escapeHtml(f.id) +
                          '"><i class="fas fa-sync-alt"></i> Review &amp; Sync</button>'
                        : f.applied_run_id
                          ? '<span style="color:var(--pm-text-muted);font-size:0.85rem;">Run #' +
                            escapeHtml(f.applied_run_id) +
                            (f.applied_by_name ? ' · ' + escapeHtml(f.applied_by_name) : '') +
                            '</span>'
                          : '—';
                return (
                    '<tr style="border-bottom:1px solid var(--pm-border-light);">' +
                    '<td style="padding:12px;">' +
                    escapeHtml(f.report_date) +
                    '</td>' +
                    '<td style="padding:12px;">' +
                    formatMaltaDate(f.received_at) +
                    '</td>' +
                    '<td style="padding:12px;">' +
                    escapeHtml(f.ingest_source) +
                    '</td>' +
                    '<td style="padding:12px;">' +
                    statusBadge(f.file_status) +
                    '</td>' +
                    '<td style="padding:12px;text-align:right;">' +
                    syncBtn +
                    '</td></tr>'
                );
            })
            .join('');
    }

    function renderRuns(runs) {
        const tbody = document.getElementById('syncInboxRunsBody');
        if (!tbody) return;
        if (!runs.length) {
            tbody.innerHTML =
                '<tr><td colspan="6" style="padding:16px;text-align:center;color:var(--pm-text-muted);">No completed sync runs yet.</td></tr>';
            return;
        }
        tbody.innerHTML = runs
            .map(function (r) {
                const summary =
                    'P:' +
                    r.price_updates_count +
                    ' · S:' +
                    r.status_updates_count +
                    ' · M:' +
                    r.translation_updates_count;
                return (
                    '<tr style="border-bottom:1px solid var(--pm-border-light);">' +
                    '<td style="padding:10px;font-weight:700;">#' +
                    escapeHtml(r.id) +
                    '</td>' +
                    '<td style="padding:10px;">' +
                    escapeHtml(r.started_by_name || '') +
                    '</td>' +
                    '<td style="padding:10px;">' +
                    formatMaltaDate(r.started_at) +
                    '</td>' +
                    '<td style="padding:10px;">' +
                    escapeHtml(r.file_report_date || '—') +
                    '</td>' +
                    '<td style="padding:10px;">' +
                    escapeHtml(r.outcome) +
                    '</td>' +
                    '<td style="padding:10px;font-size:0.85rem;color:var(--pm-text-muted);">' +
                    escapeHtml(summary) +
                    '</td></tr>'
                );
            })
            .join('');
    }

    function loadInbox() {
        fetch('api/sales_daily_sync_inbox.php?action=list')
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                const banner = document.getElementById('syncSchemaBanner');
                if (!data.success) {
                    if (banner && data.schema_ready === false) {
                        banner.style.display = 'block';
                    }
                    return;
                }
                if (banner) banner.style.display = 'none';
                renderFiles(data.files || []);
                renderRuns(data.runs || []);
            })
            .catch(function () {
                if (window.pmShowToast) {
                    window.pmShowToast('Could not load inbox', 'error');
                }
            });
    }

    function runPendingSync(fileId) {
        window.pendingSyncFileId = fileId;
        const fd = new FormData();
        fd.append('action', 'analyze_pending');
        fd.append('sync_file_id', String(fileId));

        if (window.pmShowToast) {
            window.pmShowToast('Analyzing stored CSV…', 'success');
        }

        fetch('api/sales_daily_sync_inbox.php', { method: 'POST', body: fd })
            .then(async function (r) {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid server response.');
                }
            })
            .then(function (data) {
                if (data.success && typeof window.analyzeDailySyncResult === 'function') {
                    window.analyzeDailySyncResult(data, fileId);
                } else if (!data.success) {
                    alert(data.message || 'Analyze failed.');
                    window.pendingSyncFileId = null;
                }
            })
            .catch(function (err) {
                alert('Sync failed: ' + err.message);
                window.pendingSyncFileId = null;
            });
    }

    document.addEventListener('click', function (ev) {
        const btn = ev.target.closest('.sync-inbox-run');
        if (btn) {
            runPendingSync(btn.getAttribute('data-file-id'));
        }
    });

    const uploadForm = document.getElementById('syncInboxUploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (ev) {
            ev.preventDefault();
            const fd = new FormData(uploadForm);
            fd.append('action', 'upload_pending');
            fetch('api/sales_daily_sync_inbox.php', { method: 'POST', body: fd })
                .then(function (r) {
                    return r.json();
                })
                .then(function (data) {
                    if (data.success) {
                        uploadForm.reset();
                        const dateEl = document.getElementById('syncReportDate');
                        if (dateEl) {
                            dateEl.value = new Date().toISOString().slice(0, 10);
                        }
                        if (window.pmShowToast) {
                            window.pmShowToast('Report stored in inbox', 'success');
                        }
                        loadInbox();
                    } else {
                        alert(data.message || 'Upload failed.');
                    }
                })
                .catch(function () {
                    alert('Upload failed.');
                });
        });
    }

    loadInbox();
})();
