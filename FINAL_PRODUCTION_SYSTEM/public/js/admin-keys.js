/**
 * OEM Activation System - Admin Panel: Keys Module
 * Key management: list, search, import, export, recycle, delete, reports
 */

let currentKeyPage = 1;

function loadKeys(page = 1) {
    currentKeyPage = page;
    const search = document.getElementById('key-search').value;
    const filter = document.getElementById('key-filter').value;

    // Build URL with basic and advanced search parameters
    let url = `?action=list_keys&page=${page}&filter=${filter}&search=${encodeURIComponent(search)}`;

    // Add advanced search parameters if they exist
    if (window.advancedSearchCriteria) {
        const adv = window.advancedSearchCriteria;
        if (adv.key_pattern) url += `&key_pattern=${encodeURIComponent(adv.key_pattern)}`;
        if (adv.oem_pattern) url += `&oem_pattern=${encodeURIComponent(adv.oem_pattern)}`;
        if (adv.roll_pattern) url += `&roll_pattern=${encodeURIComponent(adv.roll_pattern)}`;
        if (adv.status) url += `&adv_status=${encodeURIComponent(adv.status)}`;
        if (adv.date_from) url += `&date_from=${encodeURIComponent(adv.date_from)}`;
        if (adv.date_to) url += `&date_to=${encodeURIComponent(adv.date_to)}`;
    }

    fetch(url, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderKeysTable(data.keys);
                renderPagination('keys-pagination', data.page, data.pages, loadKeys);
            }
        })
        .catch(err => console.error('Error loading keys:', err));
}

function renderKeysTable(keys) {
    const container = document.getElementById('keys-table');
    if (keys.length === 0) {
        container.innerHTML = `<div class="loading">${LANG['keys.no_keys_found']}</div>`;
        return;
    }

    let html = `<table><thead><tr><th>${LANG['common.id']}</th><th>${LANG['keys.product_key']}</th><th>${LANG['keys.order_number']}</th><th>${LANG['keys.oem_id']}</th><th>${LANG['keys.roll_serial']}</th><th>${LANG['keys.status']}</th><th>${LANG['keys.last_used']}</th><th>${LANG['keys.hardware']}</th><th>${LANG['keys.actions']}</th></tr></thead><tbody>`;
    keys.forEach(key => {
        const statusBadge = getStatusBadge(key.key_status);
        const lastUse = key.last_use_date ? `${key.last_use_date} ${key.last_use_time || ''}` : '-';
        const orderNumber = key.order_number || `<em style="color: #999;">${LANG['keys.not_used']}</em>`;
        const oemId = key.oem_identifier || `<em style="color: #999;">${LANG['keys.not_provided']}</em>`;
        const rollSerial = key.roll_serial || `<em style="color: #999;">${LANG['keys.not_provided']}</em>`;

        // Hardware button (if order number exists, check for hardware)
        let hardwareBtn = `<em style="color: #999;">${LANG['keys.not_used']}</em>`;
        if (key.order_number) {
            hardwareBtn = `<button class="btn btn-sm btn-info" onclick="viewHardwareByOrder('${key.order_number}')">📋 ${LANG['common.view']}</button>`;
        }

        // Build action buttons based on role
        let actions = '';
        if (canModify) {
            actions += `<button class="btn btn-sm btn-secondary" onclick="recycleKey(${key.id})">${LANG['keys.recycle']}</button> `;
            if (canDelete) {
                actions += `<button class="btn btn-sm btn-danger" onclick="deleteKey(${key.id})">${LANG['keys.delete']}</button>`;
            }
        } else {
            actions = `<em>${LANG['keys.view_only']}</em>`;
        }

        html += `<tr>
            <td>${key.id}</td>
            <td><code>${key.product_key}</code></td>
            <td>${orderNumber}</td>
            <td>${oemId}</td>
            <td>${rollSerial}</td>
            <td>${statusBadge}</td>
            <td>${lastUse}</td>
            <td>${hardwareBtn}</td>
            <td>${actions}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}

function getStatusBadge(status) {
    const badges = {
        'unused': `<span class="badge badge-secondary">${LANG['keys.status_unused']}</span>`,
        'allocated': `<span class="badge badge-info">${LANG['keys.status_allocated']}</span>`,
        'good': `<span class="badge badge-success">${LANG['keys.status_good']}</span>`,
        'bad': `<span class="badge badge-danger">${LANG['keys.status_bad']}</span>`,
        'retry': `<span class="badge badge-warning">${LANG['keys.status_retry']}</span>`
    };
    return badges[status] || status;
}

function recycleKey(id) {
    if (!confirm(LANG['keys.recycle_confirm'])) return;

    securePost('?action=recycle_key', new URLSearchParams({id: id}))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['keys.recycle_success']);
            loadKeys(currentKeyPage);
        } else {
            alert(LANG['common.error'] + ': ' + data.error);
        }
    });
}

function deleteKey(id) {
    if (!confirm(LANG['keys.delete_confirm'])) return;

    securePost('?action=delete_key', new URLSearchParams({id: id}))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['keys.delete_success']);
            loadKeys(currentKeyPage);
        } else {
            alert(LANG['common.error'] + ': ' + data.error);
        }
    });
}

function showImportKeysModal() {
    document.getElementById('importKeysModal').classList.add('active');
    document.getElementById('import-progress').style.display = 'none';
    document.getElementById('import-results').style.display = 'none';
}

function showAdvancedSearch() {
    document.getElementById('advancedSearchModal').classList.add('active');
}

function showKeyReports() {
    document.getElementById('keyReportsModal').classList.add('active');
}

function importKeys(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'import_keys');

    const submitBtn = document.getElementById('import-submit-btn');
    const progressDiv = document.getElementById('import-progress');
    const resultsDiv = document.getElementById('import-results');

    submitBtn.disabled = true;
    submitBtn.textContent = LANG['common.loading'];
    progressDiv.style.display = 'block';
    resultsDiv.style.display = 'none';

    securePost('', formData)
    .then(r => r.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.textContent = LANG['keys.import_csv'];
        progressDiv.style.display = 'none';
        resultsDiv.style.display = 'block';

        if (data.success) {
            resultsDiv.innerHTML = `
                <h4 style="color: #28a745; margin-bottom: 10px;">${LANG['keys.import_success']}</h4>
                <p><strong>${LANG['keys.import_imported']}:</strong> ${data.imported} keys</p>
                <p><strong>${LANG['keys.import_updated']}:</strong> ${data.updated} keys</p>
                <p><strong>${LANG['keys.import_skipped']}:</strong> ${data.skipped} keys</p>
                ${data.errors.length > 0 ? `<p><strong>${LANG['keys.import_errors']}:</strong></p><ul>` + data.errors.map(e => `<li>${e}</li>`).join('') + '</ul>' : ''}
            `;
            loadKeys(); // Reload keys table
        } else {
            resultsDiv.innerHTML = `<p style="color: #dc3545;"><strong>Error:</strong> ${data.error}</p>`;
        }
    })
    .catch(err => {
        submitBtn.disabled = false;
        submitBtn.textContent = LANG['keys.import_csv'];
        progressDiv.style.display = 'none';
        resultsDiv.style.display = 'block';
        resultsDiv.innerHTML = `<p style="color: #dc3545;"><strong>Error:</strong> ${err.message}</p>`;
    });

    return false;
}

function exportKeysToCSV() {
    const search = document.getElementById('key-search').value;
    const filter = document.getElementById('key-filter').value;

    const params = new URLSearchParams({
        action: 'export_keys',
        search: search,
        filter: filter
    });

    window.location.href = `?${params.toString()}`;
}

function performAdvancedSearch(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    // Store advanced search criteria
    window.advancedSearchCriteria = {
        key_pattern: formData.get('key_pattern'),
        oem_pattern: formData.get('oem_pattern'),
        roll_pattern: formData.get('roll_pattern'),
        status: formData.get('status'),
        date_from: formData.get('date_from'),
        date_to: formData.get('date_to')
    };

    closeModal('advancedSearchModal');
    loadKeys(); // This will use the advanced criteria
}

function clearAdvancedSearch() {
    delete window.advancedSearchCriteria;
    document.getElementById('advancedSearchForm').reset();
    closeModal('advancedSearchModal');
    loadKeys();
}

function generateReport() {
    const reportType = document.getElementById('report_type').value;
    const reportContent = document.getElementById('report-content');

    reportContent.innerHTML = '<div class="loading">' + (LANG['keys.report_generating'] || 'Generating report...') + '</div>';

    fetch(`?action=generate_report&type=${reportType}`, {
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            reportContent.innerHTML = data.html;
        } else {
            reportContent.innerHTML = `<p style="color: #dc3545;">Error: ${data.error}</p>`;
        }
    })
    .catch(err => {
        reportContent.innerHTML = `<p style="color: #dc3545;">Error: ${err.message}</p>`;
    });
}

function downloadReport() {
    const reportType = document.getElementById('report_type').value;
    window.open(`?action=download_report&type=${reportType}`, '_blank');
}
