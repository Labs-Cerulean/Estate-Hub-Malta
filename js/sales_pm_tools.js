/**
 * Sales Management — daily sync, frame CSV upload, media upload, ignored ledger.
 * Loaded by sales_project_manager.php only (manager roles).
 */
(function () {
    'use strict';

    let currentSyncPayload = { translations: [], prices: [], statuses: [] };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function showToast(message, type) {
        type = type || 'success';
        const container = document.getElementById('pm-toast-container');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = 'pm-toast';
        toast.style.background = type === 'success' ? '#10B981' : '#EF4444';
        const icon = type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle';
        toast.innerHTML = '<i class="fas ' + icon + ' fa-lg"></i> ' + escapeHtml(message);
        container.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            setTimeout(function () { toast.remove(); }, 300);
        }, 3000);
    }

    window.pmShowToast = showToast;

    window.toggleFloorInput = function () {
        const typeEl = document.getElementById('mediaTypeSelect');
        const group = document.getElementById('floorInputGroup');
        if (!typeEl || !group) return;
        group.style.display = (typeEl.value === 'Floor Plan') ? 'block' : 'none';
    };

    window.openUploadMediaModal = function () {
        const modal = document.getElementById('uploadMediaModal');
        const select = document.querySelector('#uploadMediaForm select[name="project_id"]');
        const pid = document.getElementById('projectSelect') && document.getElementById('projectSelect').value;
        if (select && pid) {
            select.value = pid;
        }
        if (modal) modal.style.display = 'block';
    };

    window.openUploadFrameModal = function () {
        const modal = document.getElementById('uploadFrameModal');
        if (modal) modal.style.display = 'block';
    };

    window.selectFrameProject = function (cardEl) {
        document.querySelectorAll('#frameProjectPicker .pm-project-picker-card').forEach(function (card) {
            card.classList.remove('selected');
        });
        cardEl.classList.add('selected');
        const hidden = document.getElementById('frameProjectId');
        if (hidden) hidden.value = cardEl.getAttribute('data-project-id') || '';
    };

    window.selectProjectFromGrid = function (cardEl) {
        document.querySelectorAll('#pmProjectGrid .pm-project-picker-card').forEach(function (card) {
            card.classList.remove('selected');
        });
        cardEl.classList.add('selected');
        const pid = cardEl.getAttribute('data-project-id') || '';
        const hidden = document.getElementById('projectSelect');
        if (hidden) hidden.value = pid;
        if (typeof window.loadProjectData === 'function') {
            window.loadProjectData();
        }
    };

    window.processDailySync = function (input) {
        if (!input.files.length) return;
        const file = input.files[0];
        const formData = new FormData();
        formData.append('sync_csv', file);
        formData.append('action', 'analyze');

        showToast('Analyzing CSV... Please wait.', 'success');

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
            .then(async function (r) {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('SERVER CRASH REPORT:', text);
                    throw new Error('Server crashed or returned invalid data. Press F12 to check the console.');
                }
            })
            .then(function (data) {
                input.value = '';
                if (data.success) {
                    currentSyncPayload.statuses = data.status_changes;
                    showUnifiedMatrixModal(data);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(function (err) {
                input.value = '';
                console.error(err);
                alert('Sync Failed: ' + err.message);
            });
    };

    function showUnifiedMatrixModal(data) {
        let optionsHtml =
            '<option value="">-- Skip for today --</option>' +
            '<option value="-1" style="color: var(--pm-danger); font-weight: bold;">🛑 Permanently Ignore</option>';
        let currentProject = '';
        data.all_db_units.forEach(function (u) {
            if (u.project_name !== currentProject) {
                if (currentProject !== '') optionsHtml += '</optgroup>';
                optionsHtml += '<optgroup label="' + escapeHtml(u.project_name) + '">';
                currentProject = u.project_name;
            }
            optionsHtml += '<option value="' + u.id + '">' + escapeHtml(u.project_name) + ' - ' + escapeHtml(u.unit_name) + '</option>';
        });
        if (currentProject !== '') optionsHtml += '</optgroup>';

        let notFoundHtml = '';
        if (data.not_found.length > 0) {
            let rowsHtml = '';
            data.not_found.forEach(function (item) {
                let rowOptionsHtml = optionsHtml;
                let borderColor = 'var(--pm-danger)';
                let badgeHtml = '';

                if (item.recommended_id) {
                    rowOptionsHtml = rowOptionsHtml.replace(
                        'value="' + item.recommended_id + '"',
                        'value="' + item.recommended_id + '" selected'
                    );
                    borderColor = 'var(--pm-proc)';
                    badgeHtml = '<div style="font-size: 0.75rem; color: var(--pm-proc); font-weight: bold; margin-bottom: 5px;"><i class="fas fa-magic"></i> AI Suggested: ' + escapeHtml(item.recommended_full_name) + '</div>';
                }

                const safeCsvName = escapeHtml(item.csv_name);

                rowsHtml +=
                    '<tr class="unmapped-row" style="border-bottom: 1px solid var(--pm-border-light);">' +
                    '<td style="padding: 12px; color: var(--pm-danger); font-weight: bold;">' + escapeHtml(item.csv_name) + '</td>' +
                    '<td style="padding: 12px; border-left: 1px solid var(--pm-border);">' +
                    badgeHtml +
                    '<div style="display: flex; gap: 10px;">' +
                    '<select class="pm-select sync-trans-select" data-csv="' + safeCsvName + '" style="margin:0; flex: 1; border-color: ' + borderColor + ';">' + rowOptionsHtml + '</select>' +
                    '<button type="button" class="btn-heavy btn-red" style="margin:0; padding: 0 15px;" title="Permanently Ignore this row" onclick="this.closest(\'.unmapped-row\').style.opacity=\'0.3\'; this.closest(\'.unmapped-row\').querySelector(\'select\').value=\'-1\';"><i class="fas fa-eye-slash"></i> Ignore Forever</button>' +
                    '</div></td></tr>';
            });

            notFoundHtml =
                '<div style="margin-bottom: 30px; border: 1px solid var(--pm-danger); border-radius: 8px; overflow: hidden;">' +
                '<div style="background: rgba(239, 68, 68, 0.1); padding: 15px; border-bottom: 1px solid var(--pm-danger); font-weight: bold; color: #fff;">' +
                '<i class="fas fa-link"></i> Unmapped CSV Rows (' + data.not_found.length + ')' +
                '<div style="font-size: 0.8rem; color: var(--pm-text-muted); font-weight: normal; margin-top: 5px;">Link these to a database unit, or click Ignore to safely discard them.</div>' +
                '</div><div style="overflow-x: auto; background: var(--pm-bg-base);">' +
                '<table style="width: 100%; text-align: left; border-collapse: collapse; color: #fff; font-size: 0.9rem;">' +
                '<thead><tr style="border-bottom: 1px solid var(--pm-border); background: rgba(0,0,0,0.2);">' +
                '<th style="padding: 12px; width: 50%;">CSV Upload Data</th>' +
                '<th style="padding: 12px; width: 50%; border-left: 1px solid var(--pm-border);">Database Link</th>' +
                '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div></div>';
        }

        let conflictsHtml = '';
        if (data.price_conflicts.length > 0) {
            let rowsHtml = '';
            data.price_conflicts.forEach(function (c, i) {
                rowsHtml +=
                    '<tr style="border-bottom: 1px solid var(--pm-border-light);">' +
                    '<td style="padding: 12px;"><strong>' + escapeHtml(c.csv_source_name) + '</strong><br>' +
                    '<span style="color:var(--pm-avail);">Sh: €' + escapeHtml(c.csv_shell) + ' | Fin: €' + escapeHtml(c.csv_fin) + '</span></td>' +
                    '<td style="padding: 12px; border-left: 1px solid var(--pm-border);">' +
                    '<strong>' + escapeHtml(c.project_name) + ' - ' + escapeHtml(c.unit_name) + '</strong><br>' +
                    '<span style="color:var(--pm-text-muted);">Sh: €' + escapeHtml(c.db_shell) + ' | Fin: €' + escapeHtml(c.db_fin) + '</span></td>' +
                    '<td style="padding: 12px; text-align: right; border-left: 1px solid var(--pm-border); white-space: nowrap;">' +
                    '<label style="margin-right: 15px; cursor: pointer; color: var(--pm-avail);"><input type="radio" name="price_res_' + i + '" class="sync-price-radio" value="csv" checked data-id="' + escapeHtml(c.id) + '" data-shell="' + escapeHtml(c.csv_shell) + '" data-fin="' + escapeHtml(c.csv_fin) + '"> Use CSV</label>' +
                    '<label style="cursor: pointer;"><input type="radio" name="price_res_' + i + '" class="sync-price-radio" value="db"> Keep DB</label>' +
                    '</td></tr>';
            });

            conflictsHtml =
                '<div style="margin-bottom: 30px; border: 1px solid var(--pm-proc); border-radius: 8px; overflow: hidden;">' +
                '<div style="background: rgba(245, 158, 11, 0.1); padding: 15px; border-bottom: 1px solid var(--pm-proc); font-weight: bold; color: #fff;">' +
                '<i class="fas fa-euro-sign"></i> Price Mismatches (' + data.price_conflicts.length + ')</div>' +
                '<div style="overflow-x: auto; background: var(--pm-bg-base);">' +
                '<table style="width: 100%; font-size: 0.9rem; border-collapse: collapse; text-align: left; color: #fff;">' +
                '<thead><tr style="border-bottom: 1px solid var(--pm-border); background: rgba(0,0,0,0.2);">' +
                '<th style="padding: 12px; width: 35%;">CSV Upload Data</th>' +
                '<th style="padding: 12px; width: 35%; border-left: 1px solid var(--pm-border);">Database Match</th>' +
                '<th style="padding: 12px; width: 30%; text-align: right; border-left: 1px solid var(--pm-border);">Resolution</th>' +
                '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div></div>';
        }

        let statusesHtml = '';
        if (data.status_changes.length > 0) {
            let rowsHtml = '';
            data.status_changes.forEach(function (s) {
                rowsHtml +=
                    '<tr style="border-bottom: 1px solid var(--pm-border-light);">' +
                    '<td style="padding: 12px;"><strong>' + escapeHtml(s.csv_source_name) + '</strong><br>' +
                    '<span style="color:var(--pm-avail);">New Status: ' + escapeHtml(s.new_status) + '</span></td>' +
                    '<td style="padding: 12px; border-left: 1px solid var(--pm-border);">' +
                    '<strong>' + escapeHtml(s.project_name) + ' - ' + escapeHtml(s.unit_name) + '</strong><br>' +
                    '<span style="color:var(--pm-text-muted);">Current: ' + escapeHtml(s.old_status) + '</span></td>' +
                    '<td style="padding: 12px; text-align: right; border-left: 1px solid var(--pm-border);">' +
                    '<span style="color:var(--pm-avail); font-weight:bold;">Update to ' + escapeHtml(s.new_status) + ' <i class="fas fa-check"></i></span>' +
                    '</td></tr>';
            });

            statusesHtml =
                '<div style="border: 1px solid var(--pm-avail); border-radius: 8px; overflow: hidden;">' +
                '<div style="background: rgba(16, 185, 129, 0.1); padding: 15px; border-bottom: 1px solid var(--pm-avail); font-weight: bold; color: #fff;">' +
                '<i class="fas fa-sync"></i> Status Updates To Apply (' + data.status_changes.length + ')</div>' +
                '<div style="overflow-x: auto; background: var(--pm-bg-base);">' +
                '<table style="width: 100%; font-size: 0.9rem; border-collapse: collapse; text-align: left; color: #fff;">' +
                '<thead><tr style="border-bottom: 1px solid var(--pm-border); background: rgba(0,0,0,0.2);">' +
                '<th style="padding: 12px; width: 35%;">CSV Upload Data</th>' +
                '<th style="padding: 12px; width: 35%; border-left: 1px solid var(--pm-border);">Database Match</th>' +
                '<th style="padding: 12px; width: 30%; text-align: right; border-left: 1px solid var(--pm-border);">Action</th>' +
                '</tr></thead><tbody>' + rowsHtml + '</tbody></table></div></div>';
        }

        let successHtml = '';
        if (data.not_found.length === 0 && data.price_conflicts.length === 0 && data.status_changes.length === 0) {
            successHtml =
                '<div style="text-align: center; padding: 50px; color: var(--pm-avail);">' +
                '<i class="fas fa-check-circle fa-4x mb-3"></i><h3>100% Match! No updates required.</h3></div>';
        }

        const html =
            '<div id="unifiedMatrixModal" class="pm-modal" style="display:block; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.95); z-index:9999;">' +
            '<div class="pm-modal-content large" style="width: 95%; max-width: 1200px; height: 90vh; margin: 5vh auto; display: flex; flex-direction: column; padding: 0; overflow: hidden; border-radius: 12px;">' +
            '<div style="padding: 20px; border-bottom: 1px solid var(--pm-border); display: flex; justify-content: space-between; align-items: center; background: var(--pm-bg-base);">' +
            '<h3 style="margin: 0; color: #fff;"><i class="fas fa-file-csv"></i> CSV Sync Analysis Report</h3>' +
            '<div style="font-size: 0.9rem; color: var(--pm-text-muted);">Scanned: ' + data.stats.scanned + ' | Mapped: ' + data.stats.mapped + '</div></div>' +
            '<div style="flex: 1; overflow-y: auto; padding: 20px;">' + notFoundHtml + conflictsHtml + statusesHtml + successHtml + '</div>' +
            '<div style="padding: 20px; border-top: 1px solid var(--pm-border); background: var(--pm-bg-base); display: flex; justify-content: flex-end; gap: 15px;">' +
            '<button type="button" class="btn-heavy btn-red" onclick="document.getElementById(\'unifiedMatrixModal\').remove()">Cancel</button>' +
            '<button type="button" class="btn-heavy btn-green" onclick="window.commitSyncMatrix(this)">Commit Approved Changes</button>' +
            '</div></div></div>';

        const oldModal = document.getElementById('unifiedMatrixModal');
        if (oldModal) oldModal.remove();
        document.body.insertAdjacentHTML('beforeend', html);
    }

    window.commitSyncMatrix = function (btn) {
        currentSyncPayload.translations = [];
        document.querySelectorAll('.sync-trans-select').forEach(function (sel) {
            if (sel.value) {
                currentSyncPayload.translations.push({
                    csv_name: sel.getAttribute('data-csv'),
                    db_unit_id: sel.value
                });
            }
        });

        currentSyncPayload.prices = [];
        document.querySelectorAll('.sync-price-radio:checked').forEach(function (rad) {
            if (rad.value === 'csv') {
                currentSyncPayload.prices.push({
                    id: rad.getAttribute('data-id'),
                    shell: rad.getAttribute('data-shell'),
                    finishes: rad.getAttribute('data-fin')
                });
            }
        });

        const formData = new FormData();
        formData.append('action', 'commit');
        formData.append('payload', JSON.stringify(currentSyncPayload));

        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        btn.disabled = true;

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
            .then(async function (r) {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('Server crashed during commit. Response: ' + text);
                }
            })
            .then(function (data) {
                if (data.success) {
                    alert('Sync successfully committed!');
                    location.reload();
                } else {
                    alert('Database Error: ' + data.message);
                    btn.innerHTML = 'Commit Approved Changes';
                    btn.disabled = false;
                }
            })
            .catch(function (err) {
                alert('Commit Failed: ' + err.message);
                btn.innerHTML = 'Commit Approved Changes';
                btn.disabled = false;
            });
    };

    window.openIgnoredLedger = function () {
        const formData = new FormData();
        formData.append('action', 'get_ignored_ledger');

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    showToast('Error loading ledger', 'error');
                    return;
                }

                let html =
                    '<div style="position: sticky; top: 0; background: var(--pm-bg-panel); padding-bottom: 15px; z-index: 10; border-bottom: 1px solid var(--pm-border); margin-bottom: 15px;">' +
                    '<h3 style="color: #fff; margin: 0;"><i class="fas fa-eye-slash"></i> Permanently Ignored CSV Rows</h3>' +
                    '<p style="color: var(--pm-text-muted); font-size: 0.85rem; margin-top: 5px;">These CSV rows are currently skipped during the Daily Sync. Restore them to map them again.</p></div>' +
                    '<table style="width: 100%; text-align: left; border-collapse: collapse; color: #fff;">' +
                    '<thead><tr style="border-bottom: 2px solid var(--pm-border);">' +
                    '<th style="padding: 10px;">CSV Source Name</th>' +
                    '<th style="padding: 10px; text-align: right;">Action</th></tr></thead><tbody>';

                if (data.ignored.length === 0) {
                    html += '<tr><td colspan="2" style="padding: 20px; text-align:center; color: var(--pm-text-muted);">No ignored rows found.</td></tr>';
                } else {
                    data.ignored.forEach(function (item) {
                        html +=
                            '<tr style="border-bottom: 1px solid var(--pm-border-light);">' +
                            '<td style="padding: 12px 10px; font-weight: bold; color: var(--pm-danger);">' + escapeHtml(item.csv_name) + '</td>' +
                            '<td style="padding: 12px 10px; text-align: right;">' +
                            '<button type="button" class="btn-heavy btn-green" style="padding: 6px 12px; font-size: 0.8rem;" onclick="window.restoreIgnoredRow(' + item.id + ')">' +
                            '<i class="fas fa-trash-restore"></i> Restore</button></td></tr>';
                    });
                }

                html += '</tbody></table>';
                document.getElementById('ignoredLedgerContent').innerHTML = html;
                document.getElementById('ignoredLedgerModal').style.display = 'block';
            });
    };

    window.restoreIgnoredRow = function (id) {
        if (!confirm("Restore this row? It will appear as 'Unmapped' in your next Daily Sync.")) return;

        const formData = new FormData();
        formData.append('action', 'restore_ignored_row');
        formData.append('translation_id', id);

        fetch('api/sync_daily_report.php', { method: 'POST', body: formData })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    showToast('Row restored successfully!', 'success');
                    openIgnoredLedger();
                } else {
                    showToast('Error: ' + (data.message || 'Could not update'), 'error');
                }
            });
    };

    function initUploadForms() {
        const frameForm = document.getElementById('uploadFrameForm');
        if (frameForm) {
            frameForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (!document.getElementById('frameProjectId').value) {
                    showToast('Please select a project thumbnail first.', 'error');
                    return;
                }
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                submitBtn.disabled = true;

                fetch('api/upload_project_frame.php', { method: 'POST', body: formData })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            alert(data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + data.message);
                            submitBtn.disabled = false;
                        }
                    });
            });
        }

        const mediaForm = document.getElementById('uploadMediaForm');
        const dropZone = document.getElementById('drop-zone');
        const mediaFileInput = document.getElementById('mediaFileInput');
        const fileList = document.getElementById('file-list');

        if (dropZone && mediaFileInput) {
            dropZone.addEventListener('click', function () { mediaFileInput.click(); });
            dropZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                dropZone.style.borderColor = 'var(--pm-avail)';
            });
            dropZone.addEventListener('dragleave', function () {
                dropZone.style.borderColor = 'var(--pm-border)';
            });
            dropZone.addEventListener('drop', function (e) {
                e.preventDefault();
                dropZone.style.borderColor = 'var(--pm-border)';
                mediaFileInput.files = e.dataTransfer.files;
                updateFileList();
            });
            mediaFileInput.addEventListener('change', updateFileList);
        }

        function updateFileList() {
            if (!fileList || !mediaFileInput) return;
            fileList.innerHTML = Array.from(mediaFileInput.files)
                .map(function (f) { return '<div><i class="fas fa-check"></i> ' + escapeHtml(f.name) + '</div>'; })
                .join('');
        }

        if (mediaForm && mediaFileInput) {
            mediaForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                if (mediaFileInput.files.length === 0) {
                    alert('Please select at least one file to upload.');
                    return;
                }

                const btn = this.querySelector('button[type="submit"]');
                const originalText = btn.innerHTML;
                btn.innerHTML = 'Connecting...';
                btn.disabled = true;

                try {
                    for (let i = 0; i < mediaFileInput.files.length; i++) {
                        const file = mediaFileInput.files[i];
                        btn.innerHTML = 'Uploading (' + (i + 1) + '/' + mediaFileInput.files.length + ')...';

                        const authData = new FormData();
                        authData.append('action', 'get_upload_url');
                        authData.append('filename', file.name);
                        authData.append('mime_type', file.type || 'application/octet-stream');

                        const authRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: authData });
                        const authJson = await authRes.json();
                        if (!authJson.success) throw new Error(authJson.message);

                        await new Promise(function (resolve, reject) {
                            const xhr = new XMLHttpRequest();
                            xhr.open('PUT', authJson.url, true);
                            xhr.setRequestHeader('Content-Type', file.type || 'application/octet-stream');
                            xhr.onload = function () {
                                if (xhr.status >= 200 && xhr.status < 300) resolve();
                                else reject(new Error('Cloudflare rejected the upload.'));
                            };
                            xhr.onerror = function () { reject(new Error('Network Error during upload.')); };
                            xhr.send(file);
                        });

                        const dbData = new FormData(this);
                        dbData.delete('media_file[]');
                        dbData.append('action', 'save_record');
                        dbData.append('file_key', authJson.key);
                        dbData.append('filename', file.name);

                        const dbRes = await fetch('api/upload_sales_media.php', { method: 'POST', body: dbData });
                        const dbJson = await dbRes.json();
                        if (!dbJson.success) throw new Error(dbJson.message);
                    }

                    alert('Successfully uploaded ' + mediaFileInput.files.length + ' file(s)!');
                    location.reload();
                } catch (err) {
                    alert('Error: ' + err.message);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initUploadForms);
    } else {
        initUploadForms();
    }
})();
