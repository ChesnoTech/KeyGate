/**
 * OEM Activation System - Admin Panel JavaScript
 * Extracted from admin_v2.php (Phase 2 refactoring)
 */

// Config injected from PHP via window.APP_CONFIG
const { csrfToken, adminRole, lang: LANG, currentLang, adminId } = window.APP_CONFIG;
const L = (key) => LANG[key] || key;
const canModify = adminRole !== 'viewer';
const canDelete = adminRole === 'super_admin';

// =============================================
// Phase 7B: Translation helpers for DB-sourced strings
// =============================================

// Translate activity log action type (e.g. LOGIN_SUCCESS → "Успешный вход")
function translateAction(action) {
    return LANG['logs.action.' + action] || action;
}

// Translate activity log description (pattern matching for parameterized strings)
function translateDescription(desc) {
    if (!desc) return '-';

    // Exact matches
    const exactMap = {
        'Successful login': LANG['logs.desc.successful_login'],
        'Admin panel accessed': LANG['logs.desc.admin_panel_accessed'],
        'User logout': LANG['logs.desc.user_logout'],
        'Account locked': LANG['logs.desc.account_locked'],
        'Updated alternative server configuration': LANG['logs.desc.updated_alt_server'],
        'Triggered manual database backup': LANG['logs.desc.manual_backup'],
        'Access denied — insufficient permissions': LANG['logs.desc.access_denied'],
        'Started 2FA setup': LANG['logs.desc.started_2fa_setup'],
        '2FA successfully enabled': LANG['logs.desc.2fa_enabled'],
        '2FA disabled by user': LANG['logs.desc.2fa_disabled'],
        'Regenerated 2FA backup codes': LANG['logs.desc.2fa_backup_regen']
    };
    if (exactMap[desc]) return exactMap[desc];

    // Pattern matches (extract dynamic values via regex, substitute into translated template)
    const patterns = [
        { re: /^Toggled active status for technician ID: (.+)$/, key: 'logs.desc.toggled_active' },
        { re: /^Failed password attempt #(\d+)$/, key: 'logs.desc.failed_password' },
        { re: /^Invalid username: (.+)$/, key: 'logs.desc.invalid_username' },
        { re: /^IP not in whitelist: (.+)$/, key: 'logs.desc.ip_not_whitelist' },
        { re: /^Created technician: (.+)$/, key: 'logs.desc.created_tech' },
        { re: /^Updated technician ID:? (.+)$/, key: 'logs.desc.updated_tech' },
        { re: /^Deleted technician ID: (.+)$/, key: 'logs.desc.deleted_tech' },
        { re: /^Reset password for technician ID: (.+)$/, key: 'logs.desc.reset_password' },
        { re: /^Recycled key ID: (.+)$/, key: 'logs.desc.recycled_key' },
        { re: /^Deleted key ID: (.+)$/, key: 'logs.desc.deleted_key' },
        { re: /^Imported (\d+) keys from CSV$/, key: 'logs.desc.imported_keys' },
        { re: /^Exported (\d+) keys to CSV$/, key: 'logs.desc.exported_keys' },
        { re: /^Downloaded (.+) report as PDF$/, key: 'logs.desc.downloaded_report' },
        { re: /^No permission: (.+)$/, key: 'logs.desc.no_permission' },
    ];
    for (const p of patterns) {
        const m = desc.match(p.re);
        if (m && LANG[p.key]) return LANG[p.key].replace('%s', m[1]);
    }

    // Multi-capture patterns (2-3 placeholders)
    const multiPatterns = [
        { re: /^Registered USB device '([^']+)' for technician (.+)$/, key: 'logs.desc.registered_usb', captures: 2 },
        { re: /^Changed USB device '([^']+)' \(ID: (\d+)\) status to '([^']+)'$/, key: 'logs.desc.usb_status_changed', captures: 3 },
        { re: /^Deleted USB device '([^']+)' \(ID: (\d+)\)$/, key: 'logs.desc.deleted_usb', captures: 2 },
        { re: /^Added trusted network: (.+) \((.+)\)$/, key: 'logs.desc.added_network', captures: 2 },
        { re: /^Deleted trusted network: (.+) \(ID: (\d+)\)$/, key: 'logs.desc.deleted_network', captures: 2 },
    ];
    for (const p of multiPatterns) {
        const m = desc.match(p.re);
        if (m && LANG[p.key]) {
            let result = LANG[p.key];
            for (let i = 1; i <= p.captures; i++) {
                result = result.replace('%s', m[i]);
            }
            return result;
        }
    }

    return desc; // Fallback: show original if no translation found
}

// Translate ACL category display name
function translateAclCategory(categoryKey, displayName) {
    return LANG['acl.cat.' + categoryKey] || displayName;
}

// Translate ACL permission display name
function translateAclPerm(permKey, displayName) {
    return LANG['acl.perm.' + permKey] || displayName;
}

// Translate ACL permission description
function translateAclPermDesc(permKey, description) {
    return LANG['acl.perm.' + permKey + '.desc'] || description || '';
}

// Helper: POST with CSRF token
function securePost(url, body = {}, options = {}) {
    const headers = { 'X-CSRF-Token': csrfToken };

    // Plain object → JSON with CSRF in body + header
    if (typeof body === 'object' && !(body instanceof FormData) && !(body instanceof URLSearchParams)) {
        body.csrf_token = csrfToken;
        headers['Content-Type'] = options.contentType || 'application/json';
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(body)
        });
    }

    // URLSearchParams → append CSRF + header
    if (body instanceof URLSearchParams) {
        body.append('csrf_token', csrfToken);
        headers['Content-Type'] = 'application/x-www-form-urlencoded';
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: body
        });
    }

    // FormData or string → header only
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: body
    });
}

// Helper: GET (read-only, no CSRF needed)
function secureGet(url) {
    return fetch(url, { credentials: 'same-origin' });
}

// adminRole, canModify, canDelete, LANG, currentLang are set above from APP_CONFIG

// Language change function
function changeLanguage(lang) {
    const formData = new FormData();
    formData.append('action', 'change_language');
    formData.append('language', lang);
    fetch(window.location.pathname, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
    }).then(() => {
        window.location.reload();
    });
}

// Tab switching
document.querySelectorAll('.tab-button').forEach(button => {
    button.addEventListener('click', () => {
        const tabName = button.dataset.tab;

        // Update buttons
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        button.classList.add('active');

        // Update content
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.getElementById(tabName).classList.add('active');

        // Load data for tab
        switch(tabName) {
            case 'dashboard':
                loadDashboard();
                break;
            case 'keys':
                loadKeys();
                break;
            case 'technicians':
                loadTechnicians();
                break;
            case 'usb-devices':
                loadUSBDevices();
                break;
            case 'history':
                loadHistory();
                break;
            case 'logs':
                loadLogs();
                break;
            case 'settings':
                loadAltServerSettings();
                loadClientResources();
                break;
        }
    });
});

// Load dashboard on page load
loadDashboard();

// Dashboard functions
function loadDashboard() {
    fetch('?action=get_stats', {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderStats(data.stats);
                renderRecentActivity(data.stats.recent_activity);
            }
        })
        .catch(err => console.error('Error loading dashboard:', err));
}

function renderStats(stats) {
    const grid = document.getElementById('stats-grid');
    grid.innerHTML = `
        <div class="stat-card">
            <h3>${LANG['dashboard.total_keys']}</h3>
            <div class="value">${stats.keys.total}</div>
            <div class="label">${LANG['dashboard.unused']}: ${stats.keys.unused} | ${LANG['dashboard.allocated']}: ${stats.keys.allocated}</div>
        </div>
        <div class="stat-card" style="border-left-color: #17a2b8;">
            <h3>${LANG['dashboard.good_keys']}</h3>
            <div class="value">${stats.keys.good}</div>
            <div class="label">${LANG['dashboard.successfully_activated']}</div>
        </div>
        <div class="stat-card" style="border-left-color: #ffc107;">
            <h3>${LANG['dashboard.bad_keys']}</h3>
            <div class="value">${stats.keys.bad}</div>
            <div class="label">${LANG['dashboard.failed_activation']}</div>
        </div>
        <div class="stat-card" style="border-left-color: #6c757d;">
            <h3>${LANG['dashboard.technicians']}</h3>
            <div class="value">${stats.technicians.total}</div>
            <div class="label">${LANG['dashboard.active']}: ${stats.technicians.active} | ${LANG['dashboard.inactive']}: ${stats.technicians.inactive}</div>
        </div>
        <div class="stat-card" style="border-left-color: #dc3545;">
            <h3>${LANG['dashboard.today']}</h3>
            <div class="value">${stats.activations.today}</div>
            <div class="label">${LANG['dashboard.activations_today']}</div>
        </div>
        <div class="stat-card" style="border-left-color: #28a745;">
            <h3>${LANG['dashboard.this_month']}</h3>
            <div class="value">${stats.activations.month}</div>
            <div class="label">${LANG['dashboard.activations_this_month']}</div>
        </div>
    `;
}

function renderRecentActivity(activity) {
    const container = document.getElementById('recent-activity');
    if (activity.length === 0) {
        container.innerHTML = `<div class="loading">${LANG['dashboard.no_recent_activity']}</div>`;
        return;
    }

    let html = `<table><thead><tr><th>${LANG['common.time']}</th><th>${LANG['logs.user']}</th><th>${LANG['common.action']}</th><th>${LANG['common.description']}</th></tr></thead><tbody>`;
    activity.forEach(item => {
        html += `<tr>
            <td>${item.created_at}</td>
            <td>${item.username || LANG['logs.system']}</td>
            <td><span class="badge badge-info">${translateAction(item.action)}</span></td>
            <td>${translateDescription(item.description)}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}

// Keys functions
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

// Technicians functions
let currentTechPage = 1;

function loadTechnicians(page = 1) {
    currentTechPage = page;
    const searchBox = document.getElementById('tech-search');
    const search = searchBox ? searchBox.value : '';

    console.log('Loading technicians - page:', page, 'search:', search);

    fetch(`?action=list_techs&page=${page}&search=${encodeURIComponent(search)}`, {
        credentials: 'same-origin'
    })
        .then(r => {
            console.log('Response status:', r.status, r.statusText);
            console.log('Response content-type:', r.headers.get('content-type'));

            if (!r.ok) {
                return r.text().then(text => {
                    console.error('Error response body:', text.substring(0, 200));
                    throw new Error(`HTTP ${r.status}: ${r.statusText}`);
                });
            }

            return r.text().then(text => {
                console.log('Response text length:', text.length);
                console.log('Response preview:', text.substring(0, 200));
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('JSON parse error:', e);
                    console.error('Full response:', text);
                    throw new Error('Invalid JSON response');
                }
            });
        })
        .then(data => {
            console.log('Technicians loaded:', data);
            if (data.success) {
                renderTechsTable(data.technicians);
                renderPagination('techs-pagination', data.page, data.pages, loadTechnicians);
            } else {
                console.error('Error in response:', data);
                document.getElementById('techs-table').innerHTML = '<div class="loading">Error: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(err => {
            console.error('Error loading technicians:', err);
            document.getElementById('techs-table').innerHTML = '<div class="loading">Error: ' + err.message + '</div>';
        });
}

function renderTechsTable(techs) {
    const container = document.getElementById('techs-table');
    if (techs.length === 0) {
        container.innerHTML = `<div class="loading">${LANG['tech.no_technicians']}</div>`;
        return;
    }

    let html = `<table><thead><tr><th>${LANG['common.id']}</th><th>${LANG['tech.technician_id']}</th><th>${LANG['tech.full_name']}</th><th>${LANG['tech.email']}</th><th>${LANG['tech.status']}</th><th>${LANG['tech.preferred_server']}</th><th>${LANG['tech.last_login']}</th><th>${LANG['tech.actions']}</th></tr></thead><tbody>`;
    techs.forEach(tech => {
        const statusBadge = tech.is_active ? `<span class="badge badge-success">${LANG['tech.active']}</span>` : `<span class="badge badge-secondary">${LANG['tech.inactive']}</span>`;

        // Server badge
        const serverBadges = {
            'oem': `<span class="badge badge-primary">${LANG['tech.oem_server']}</span>`,
            'alternative': `<span class="badge badge-warning">${LANG['tech.alt_server']}</span>`
        };
        const serverBadge = serverBadges[tech.preferred_server] || `<span class="badge badge-primary">${LANG['tech.oem_server']}</span>`;

        // Build action buttons based on role
        let actions = '';
        if (canModify) {
            actions += `<button class="btn btn-sm btn-primary" onclick="editTech(${tech.id})">${LANG['tech.edit']}</button> `;
            actions += `<button class="btn btn-sm btn-secondary" onclick="toggleTech(${tech.id})">${LANG['tech.toggle']}</button> `;
            actions += `<button class="btn btn-sm btn-primary" onclick="resetPassword(${tech.id})">${LANG['tech.reset_pwd']}</button> `;
            if (canDelete) {
                actions += `<button class="btn btn-sm btn-danger" onclick="deleteTech(${tech.id})">${LANG['common.delete']}</button>`;
            }
        } else {
            actions = `<em>${LANG['keys.view_only']}</em>`;
        }

        html += `<tr>
            <td>${tech.id}</td>
            <td><strong>${tech.technician_id}</strong></td>
            <td>${tech.full_name || '-'}</td>
            <td>${tech.email || '-'}</td>
            <td>${statusBadge}</td>
            <td>${serverBadge}</td>
            <td>${tech.last_login || LANG['tech.never']}</td>
            <td>${actions}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}

function showAddTechModal() {
    document.getElementById('addTechModal').classList.add('active');
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

function closeModal(modalId) {
    if (modalId) {
        var el = document.getElementById(modalId);
        if (el) {
            el.classList.remove('active');
        }
        // Reset forms when closing
        if (modalId === 'importKeysModal') {
            document.getElementById('importKeysForm').reset();
            document.getElementById('import-progress').style.display = 'none';
            document.getElementById('import-results').style.display = 'none';
        }
    } else {
        // Close dynamically created modals (e.g. USB register modal)
        var modals = document.querySelectorAll('.modal');
        modals.forEach(function(m) {
            if (m.style.display === 'flex' || m.style.display === 'block') {
                m.style.display = 'none';
                m.remove();
            }
        });
    }
}

function addTechnician(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    formData.append('action', 'add_tech');

    securePost('', new URLSearchParams(formData))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['tech.create_success']);
            closeModal('addTechModal');
            form.reset();
            loadTechnicians();
        } else {
            alert('Error: ' + data.error);
        }
    });

    return false;
}

function editTech(techId) {
    // Fetch technician data
    fetch(`?action=get_tech&id=${techId}`, {
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const tech = data.technician;
            document.getElementById('edit_tech_id').value = tech.id;
            document.getElementById('edit_technician_id').value = tech.technician_id;
            document.getElementById('edit_full_name').value = tech.full_name || '';
            document.getElementById('edit_email').value = tech.email || '';
            document.getElementById('edit_preferred_server').value = tech.preferred_server || 'oem';
            document.getElementById('edit_preferred_language').value = tech.preferred_language || 'en';
            document.getElementById('edit_is_active').checked = tech.is_active == 1;
            document.getElementById('editTechModal').classList.add('active');
        } else {
            alert(LANG['common.error'] + ': ' + data.error);
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}

function updateTechnician(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);

    const data = {
        action: 'update_tech',
        tech_id: formData.get('tech_id'),
        technician_id: formData.get('technician_id'),
        full_name: formData.get('full_name'),
        email: formData.get('email'),
        preferred_server: formData.get('preferred_server'),
        preferred_language: formData.get('preferred_language') || 'en',
        is_active: formData.get('is_active') ? 1 : 0
    };

    securePost('', data)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['tech.update_success']);
            closeModal('editTechModal');
            loadTechnicians();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });

    return false;
}

// Keys Management Functions
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

    reportContent.innerHTML = '<div class="loading">Generating report...</div>';

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

function toggleTech(id) {
    securePost('?action=toggle_tech', new URLSearchParams({id: id}))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadTechnicians(currentTechPage);
        } else {
            alert('Error: ' + data.error);
        }
    });
}

function resetPassword(id) {
    const newPassword = prompt(LANG['tech.reset_pwd_prompt']);
    if (!newPassword || newPassword.length < 8) {
        alert(LANG['tech.pwd_min_8']);
        return;
    }

    securePost('?action=reset_password', new URLSearchParams({id: id, new_password: newPassword}))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['tech.reset_success']);
        } else {
            alert(LANG['common.error'] + ': ' + data.error);
        }
    });
}

function deleteTech(id) {
    if (!confirm(LANG['tech.delete_confirm'])) return;

    securePost('?action=delete_tech', new URLSearchParams({id: id}))
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['tech.delete_success']);
            loadTechnicians(currentTechPage);
        } else {
            alert(LANG['common.error'] + ': ' + data.error);
        }
    });
}

// History functions
let currentHistoryPage = 1;

function loadHistory(page = 1) {
    currentHistoryPage = page;
    const search = document.getElementById('history-search').value;
    const filter = document.getElementById('history-filter').value;

    fetch(`?action=list_history&page=${page}&filter=${filter}&search=${encodeURIComponent(search)}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderHistoryTable(data.history);
                renderPagination('history-pagination', data.page, data.pages, loadHistory);
            }
        })
        .catch(err => console.error('Error loading history:', err));
}

function renderHistoryTable(history) {
    const container = document.getElementById('history-table');
    if (history.length === 0) {
        container.innerHTML = `<div class="loading">${LANG['history.no_history']}</div>`;
        return;
    }

    let html = `<table><thead><tr><th>${LANG['history.activation_id']}</th><th>${LANG['history.date_time']}</th><th>${LANG['history.technician']}</th><th>${LANG['history.order']}</th><th>${LANG['history.key']}</th><th>${LANG['history.server']}</th><th>${LANG['history.result']}</th><th>${LANG['history.notes']}</th><th>${LANG['history.hardware']}</th></tr></thead><tbody>`;
    history.forEach(item => {
        const resultBadge = item.attempt_result === 'success' ? `<span class="badge badge-success">${LANG['history.success']}</span>` : `<span class="badge badge-danger">${LANG['history.failed']}</span>`;
        const hardwareBtn = item.hardware_collected == 1
            ? `<button class="btn btn-sm btn-info" onclick="viewHardware(${item.id})">📋 ${LANG['common.view']}</button>`
            : `<em style="color: #999;">${LANG['history.not_collected']}</em>`;

        // Server badge
        const serverBadges = {
            'oem': `<span class="badge badge-primary">${LANG['history.oem']}</span>`,
            'alternative': `<span class="badge badge-warning">${LANG['history.alternative']}</span>`,
            'manual': `<span class="badge badge-info">${LANG['history.manual_alt']}</span>`
        };
        const serverBadge = serverBadges[item.activation_server] || `<span class="badge badge-secondary">${LANG['history.unknown']}</span>`;

        // Unique ID (shortened with tooltip)
        const shortId = item.activation_unique_id ? item.activation_unique_id.substring(0, 8) : 'N/A';
        const fullIdTooltip = item.activation_unique_id ? `title="${item.activation_unique_id}"` : '';

        html += `<tr>
            <td><code ${fullIdTooltip}>${shortId}</code></td>
            <td>${item.attempted_date} ${item.attempted_time}</td>
            <td>${item.technician_id}</td>
            <td>${item.order_number}</td>
            <td><code>${item.product_key || '-'}</code></td>
            <td>${serverBadge}</td>
            <td>${resultBadge}</td>
            <td>${item.notes || '-'}</td>
            <td>${hardwareBtn}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}

function viewHardware(activationId) {
    document.getElementById('hardwareModal').classList.add('active');
    document.getElementById('hardware-details').innerHTML = `<div class="loading">${LANG['common.loading']}</div>`;

    fetch(`?action=get_hardware&activation_id=${activationId}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderHardwareInfo(data.hardware);
            } else {
                document.getElementById('hardware-details').innerHTML = `<div class="error">❌ ${data.error}</div>`;
            }
        })
        .catch(err => {
            document.getElementById('hardware-details').innerHTML = `<div class="error">❌ ${LANG['common.error']}</div>`;
            console.error('Error loading hardware:', err);
        });
}

function viewHardwareByOrder(orderNumber) {
    document.getElementById('hardwareModal').classList.add('active');
    document.getElementById('hardware-details').innerHTML = `<div class="loading">${LANG['common.loading']}</div>`;

    fetch(`?action=get_hardware_by_order&order_number=${encodeURIComponent(orderNumber)}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderHardwareInfo(data.hardware);
            } else {
                document.getElementById('hardware-details').innerHTML = `<div class="error">❌ ${data.error}<br><small>Order: ${orderNumber}</small></div>`;
            }
        })
        .catch(err => {
            document.getElementById('hardware-details').innerHTML = `<div class="error">❌ ${LANG['common.error']}</div>`;
            console.error('Error loading hardware:', err);
        });
}

function renderHardwareInfo(hw) {
    const parseJSON = (jsonStr) => {
        try {
            return jsonStr ? JSON.parse(jsonStr) : [];
        } catch {
            return [];
        }
    };

    const ramModules = parseJSON(hw.ram_modules);
    const videoCards = parseJSON(hw.video_cards);
    const storageDevices = parseJSON(hw.storage_devices);
    const partitions = parseJSON(hw.disk_partitions);
    const completeDiskLayout = parseJSON(hw.complete_disk_layout);
    const networkAdapters = parseJSON(hw.network_adapters);
    const audioDevices = parseJSON(hw.audio_devices);
    const monitors = parseJSON(hw.monitors);

    // Helper to create a key-value row
    const kvRow = (label, value, mono = false) => {
        const val = value || L('hardware.na');
        const valHtml = mono ? `<code style="background:#f1f3f5;padding:2px 6px;border-radius:3px;font-size:12px;">${val}</code>` : val;
        return `<tr><td style="padding:6px 10px;color:#555;white-space:nowrap;width:160px;vertical-align:top;font-size:13px;"><strong>${label}</strong></td><td style="padding:6px 10px;font-size:13px;">${valHtml}</td></tr>`;
    };

    // Section wrapper
    const section = (icon, title, content, collapsed = false) => `
        <div class="hw-section" style="margin-bottom:12px;border:1px solid #e0e0e0;border-radius:8px;overflow:hidden;">
            <div class="hw-section-header" onclick="this.parentElement.classList.toggle('collapsed')" style="background:#f8f9fa;padding:10px 15px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;user-select:none;">
                <span style="font-weight:600;font-size:14px;">${icon} ${title}</span>
                <span class="hw-toggle" style="font-size:11px;color:#888;">▼</span>
            </div>
            <div class="hw-section-body" style="padding:10px 5px;">
                <table style="width:100%;border-collapse:collapse;">${content}</table>
            </div>
        </div>
    `;

    // Hardware modal styles are in public/css/admin.css
    let html = `
        <div style="padding:15px;">
    `;

    // ═══════ HEADER: Activation + Quick Summary ═══════
    html += `
        <div style="background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:18px 20px;border-radius:10px;margin-bottom:15px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:18px;font-weight:700;margin-bottom:8px;">📋 ${hw.order_number}</div>
                    <div style="font-size:13px;opacity:0.85;">
                        <span>👤 ${hw.technician_name || L('hardware.unknown')}</span>
                        <span style="margin:0 8px;">|</span>
                        <span>🔑 <code style="background:rgba(255,255,255,0.15);padding:1px 6px;border-radius:3px;">${hw.product_key || L('hardware.na')}</code></span>
                    </div>
                    <div style="font-size:12px;opacity:0.7;margin-top:4px;">
                        🕐 ${hw.attempted_at || L('hardware.na')} &nbsp;|&nbsp; 💻 ${hw.computer_name || L('hardware.na')}
                    </div>
                </div>
                <div style="text-align:right;">
                    ${hw.device_fingerprint ? '<span class="hw-badge hw-badge-blue">🔒 ' + L('hardware.fingerprinted') + '</span>' : ''}
                </div>
            </div>
        </div>
    `;

    // ═══════ QUICK OVERVIEW CARDS ═══════
    const cpuShort = hw.cpu_name ? hw.cpu_name.replace(/\(R\)|\(TM\)|CPU|@.*$/gi, '').trim() : L('hardware.na');
    html += `
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:15px;">
            <div style="background:#e8f5e9;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#2e7d32;font-weight:600;">${L('hardware.label_cpu')}</div>
                <div style="font-size:12px;font-weight:700;margin-top:3px;">${cpuShort}</div>
                <div style="font-size:10px;color:#666;">${hw.cpu_cores || '?'}C / ${hw.cpu_logical_processors || '?'}T</div>
            </div>
            <div style="background:#e3f2fd;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#1565c0;font-weight:600;">${L('hardware.label_ram')}</div>
                <div style="font-size:16px;font-weight:700;margin-top:3px;">${hw.ram_total_capacity_gb || '?'} GB</div>
                <div style="font-size:10px;color:#666;">${hw.ram_slots_used || '?'} / ${hw.ram_slots_total || '?'} slots</div>
            </div>
            <div style="background:#fff3e0;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#e65100;font-weight:600;">${L('hardware.label_storage')}</div>
                <div style="font-size:16px;font-weight:700;margin-top:3px;">${storageDevices.length > 0 ? storageDevices.reduce((s,d) => s + (d.size_gb || 0), 0).toFixed(0) + ' GB' : L('hardware.na')}</div>
                <div style="font-size:10px;color:#666;">${storageDevices.length} ${L('hardware.drives')}</div>
            </div>
            <div style="background:#fce4ec;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#c62828;font-weight:600;">${L('hardware.label_gpu')}</div>
                <div style="font-size:12px;font-weight:700;margin-top:3px;">${videoCards.length > 0 ? videoCards[0].name || L('hardware.na') : L('hardware.na')}</div>
                <div style="font-size:10px;color:#666;">${videoCards.length > 0 && videoCards[0].adapter_ram_mb ? videoCards[0].adapter_ram_mb + ' MB' : ''}</div>
            </div>
            <div style="background:#f3e5f5;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#7b1fa2;font-weight:600;">${L('hardware.label_network')}</div>
                <div style="font-size:12px;font-weight:700;margin-top:3px;">${hw.public_ip || L('hardware.na')}</div>
                <div style="font-size:10px;color:#666;">${hw.local_ip || L('hardware.no_ip')}</div>
            </div>
            <div style="background:#e0f2f1;padding:10px 12px;border-radius:8px;text-align:center;">
                <div style="font-size:11px;color:#00695c;font-weight:600;">${L('hardware.label_security')}</div>
                <div style="margin-top:3px;">
                    ${hw.secure_boot_enabled === null ? '<span class="hw-badge hw-badge-gray">SB ?</span>' : (hw.secure_boot_enabled == 1 ? '<span class="hw-badge hw-badge-green">SB ✓</span>' : '<span class="hw-badge hw-badge-red">SB ✗</span>')}
                    ${hw.tpm_present == 1 ? '<span class="hw-badge hw-badge-green" style="margin-left:3px;">TPM ✓</span>' : (hw.tpm_present === null ? '' : '<span class="hw-badge hw-badge-red" style="margin-left:3px;">TPM ✗</span>')}
                </div>
            </div>
        </div>
    `;

    // ═══════ ALL SERIAL NUMBERS GRID ═══════
    let serialsHtml = '<div class="hw-serial-grid">';
    const serials = [
        [L('hardware.mb_sn'), hw.motherboard_serial],
        [L('hardware.bios_sn'), hw.bios_serial_number],
        [L('hardware.system_sn'), hw.system_serial],
        [L('hardware.system_uuid'), hw.system_uuid],
        [L('hardware.chassis_sn'), hw.chassis_serial],
        [L('hardware.cpu_id'), hw.cpu_serial],
        [L('hardware.primary_mac'), hw.primary_mac_address],
        [L('hardware.os_sn'), hw.os_serial_number]
    ];
    // Add storage serials
    storageDevices.forEach((d, i) => {
        if (d.serial_number) serials.push([L('hardware.disk_sn').replace('%s', i), d.serial_number]);
    });
    // Add RAM serials
    ramModules.forEach((m, i) => {
        if (m.serial_number && m.serial_number !== 'N/A' && m.serial_number.trim()) serials.push([L('hardware.ram_sn').replace('%s', i), m.serial_number]);
    });
    // Add monitor serials
    monitors.forEach((m, i) => {
        if (m.serial_number && m.serial_number !== 'N/A') serials.push([L('hardware.monitor_sn').replace('%s', i), m.serial_number]);
    });

    serials.forEach(([label, value]) => {
        if (value && value !== 'N/A' && value.trim()) {
            serialsHtml += `<div class="hw-serial-item"><div class="label">${label}</div><div class="value">${value}</div></div>`;
        }
    });
    serialsHtml += '</div>';

    html += section('🔑', L('hardware.all_serials'), `<tr><td colspan="2">${serialsHtml}</td></tr>`);

    // ═══════ NETWORK & IP SECTION ═══════
    let netContent = '';
    netContent += kvRow(L('hardware.public_ip'), hw.public_ip, true);
    netContent += kvRow(L('hardware.local_ip'), hw.local_ip, true);
    netContent += kvRow(L('hardware.primary_mac'), hw.primary_mac_address, true);

    if (networkAdapters.length > 0) {
        let adaptersHtml = '';
        networkAdapters.forEach(adapter => {
            adaptersHtml += `
                <div class="hw-net-card">
                    <div style="font-weight:600;margin-bottom:4px;">${adapter.description || L('hardware.unknown_adapter')}</div>
                    <div>MAC: <span class="mac">${adapter.mac_address || L('hardware.na')}</span></div>
                    ${adapter.ip_addresses ? `<div>IP: <strong>${adapter.ip_addresses}</strong></div>` : ''}
                    ${adapter.default_gateway ? `<div>${L('hardware.gateway')}: ${adapter.default_gateway}</div>` : ''}
                    ${adapter.dns_servers ? `<div>${L('hardware.dns')}: ${adapter.dns_servers}</div>` : ''}
                    <div>${L('hardware.dhcp')}: ${adapter.dhcp_enabled ? '✓ ' + L('hardware.dhcp_yes') : '✗ ' + L('hardware.dhcp_static')}</div>
                </div>`;
        });
        netContent += `<tr><td colspan="2" style="padding:6px 10px;">${adaptersHtml}</td></tr>`;
    }
    html += section('🌐', L('hardware.network_ip'), netContent);

    // ═══════ SYSTEM IDENTITY SECTION ═══════
    let sysContent = '';
    sysContent += kvRow(L('hardware.manufacturer'), hw.system_manufacturer);
    sysContent += kvRow(L('hardware.product_name'), hw.system_product_name);
    sysContent += kvRow(L('hardware.system_serial'), hw.system_serial, true);
    sysContent += kvRow(L('hardware.uuid'), hw.system_uuid, true);
    sysContent += kvRow(L('hardware.chassis_type'), hw.chassis_type);
    sysContent += kvRow(L('hardware.chassis_mfg'), hw.chassis_manufacturer);
    sysContent += kvRow(L('hardware.chassis_serial'), hw.chassis_serial, true);
    html += section('🏭', L('hardware.system_identity'), sysContent);

    // ═══════ OS SECTION ═══════
    let osContent = '';
    osContent += kvRow(L('hardware.os'), hw.os_name);
    osContent += kvRow(L('hardware.version'), hw.os_version);
    osContent += kvRow(L('hardware.build'), hw.os_build_number);
    osContent += kvRow(L('hardware.architecture'), hw.os_architecture);
    osContent += kvRow(L('hardware.install_date'), hw.os_install_date);
    osContent += kvRow(L('hardware.os_serial'), hw.os_serial_number, true);
    osContent += kvRow(L('hardware.secure_boot'), hw.secure_boot_enabled === null ? L('hardware.unknown') : (hw.secure_boot_enabled == 1 ? '✅ ' + L('hardware.enabled') : '❌ ' + L('hardware.disabled')));
    osContent += kvRow(L('hardware.computer_name'), hw.computer_name);
    html += section('🖥️', L('hardware.os_section'), osContent);

    // ═══════ MOTHERBOARD + BIOS ═══════
    let mbContent = '';
    mbContent += kvRow(L('hardware.manufacturer'), hw.motherboard_manufacturer);
    mbContent += kvRow(L('hardware.product_name'), hw.motherboard_product);
    mbContent += kvRow(L('hardware.version'), hw.motherboard_version);
    mbContent += kvRow(L('hardware.serial'), hw.motherboard_serial, true);
    mbContent += `<tr><td colspan="2" style="padding:8px 10px 4px;"><strong style="font-size:13px;">⚙️ BIOS</strong></td></tr>`;
    mbContent += kvRow(L('hardware.bios_mfg'), hw.bios_manufacturer);
    mbContent += kvRow(L('hardware.bios_version'), hw.bios_version);
    mbContent += kvRow(L('hardware.release_date'), hw.bios_release_date);
    mbContent += kvRow(L('hardware.bios_serial'), hw.bios_serial_number, true);
    html += section('🔧', L('hardware.mb_bios'), mbContent);

    // ═══════ CPU ═══════
    let cpuContent = '';
    cpuContent += kvRow(L('hardware.name'), hw.cpu_name);
    cpuContent += kvRow(L('hardware.manufacturer'), hw.cpu_manufacturer);
    cpuContent += kvRow(L('hardware.cores_threads'), `${hw.cpu_cores || '?'} cores / ${hw.cpu_logical_processors || '?'} threads`);
    cpuContent += kvRow(L('hardware.max_clock'), hw.cpu_max_clock_speed ? hw.cpu_max_clock_speed + ' MHz' : null);
    cpuContent += kvRow(L('hardware.processor_id'), hw.cpu_serial, true);
    html += section('💻', L('hardware.cpu_section'), cpuContent);

    // ═══════ TPM ═══════
    if (hw.tpm_present !== null && hw.tpm_present !== undefined) {
        let tpmContent = '';
        tpmContent += kvRow(L('hardware.present'), hw.tpm_present == 1 ? '✅ ' + L('hardware.yes') : '❌ ' + L('hardware.no'));
        if (hw.tpm_present == 1) {
            tpmContent += kvRow(L('hardware.version'), hw.tpm_version);
            tpmContent += kvRow(L('hardware.manufacturer'), hw.tpm_manufacturer);
        }
        html += section('🛡️', L('hardware.tpm_section'), tpmContent);
    }

    // ═══════ MEMORY ═══════
    let ramContent = '';
    ramContent += kvRow(L('hardware.total_capacity'), hw.ram_total_capacity_gb ? hw.ram_total_capacity_gb + ' GB' : null);
    ramContent += kvRow('Slots', `${hw.ram_slots_used || '?'} used / ${hw.ram_slots_total || '?'} total`);
    if (ramModules.length > 0) {
        let ramTableHtml = `<table style="width:100%;border-collapse:collapse;font-size:12px;margin-top:5px;">
            <thead><tr style="background:#e9ecef;">
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.slot')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.manufacturer')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:right;">${L('hardware.size')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:right;">${L('hardware.speed')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.part_number')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.serial')}</th>
            </tr></thead><tbody>`;
        ramModules.forEach((m, i) => {
            ramTableHtml += `<tr style="background:${i % 2 === 0 ? '#fff' : '#f8f9fa'};">
                <td style="padding:4px 8px;border:1px solid #dee2e6;">${m.device_locator || m.bank_label || 'Slot ' + i}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;">${m.manufacturer || 'N/A'}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;text-align:right;">${m.capacity_gb} GB</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;text-align:right;">${m.speed_mhz} MHz</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;font-size:11px;">${(m.part_number || 'N/A').trim()}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;font-family:monospace;font-size:11px;">${(m.serial_number || 'N/A').trim()}</td>
            </tr>`;
        });
        ramTableHtml += '</tbody></table>';
        ramContent += `<tr><td colspan="2" style="padding:6px 10px;">${ramTableHtml}</td></tr>`;
    }
    html += section('🧠', L('hardware.memory_section'), ramContent);

    // ═══════ GPU ═══════
    let gpuContent = '';
    if (videoCards.length > 0) {
        videoCards.forEach((card, i) => {
            if (i > 0) gpuContent += `<tr><td colspan="2" style="border-top:1px solid #eee;padding:4px;"></td></tr>`;
            gpuContent += kvRow(L('hardware.name'), card.name);
            gpuContent += kvRow(L('hardware.vram'), card.adapter_ram_mb ? card.adapter_ram_mb + ' MB' : null);
            gpuContent += kvRow('Processor', card.video_processor);
            gpuContent += kvRow(L('hardware.resolution'), card.resolution);
            gpuContent += kvRow(L('hardware.driver'), card.driver_version);
        });
    } else {
        gpuContent = '<tr><td colspan="2" style="padding:10px;color:#999;">' + L('hardware.no_video') + '</td></tr>';
    }
    html += section('🎮', L('hardware.gpu_section'), gpuContent);

    // ═══════ STORAGE ═══════
    let storageContent = '';
    if (storageDevices.length > 0) {
        let storageTableHtml = `<table style="width:100%;border-collapse:collapse;font-size:12px;">
            <thead><tr style="background:#e9ecef;">
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.model')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:right;">${L('hardware.size')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.interface')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.type')}</th>
                <th style="padding:5px 8px;border:1px solid #dee2e6;text-align:left;">${L('hardware.serial')}</th>
            </tr></thead><tbody>`;
        storageDevices.forEach((d, i) => {
            storageTableHtml += `<tr style="background:${i % 2 === 0 ? '#fff' : '#f8f9fa'};">
                <td style="padding:4px 8px;border:1px solid #dee2e6;font-weight:600;">${d.model || 'N/A'}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;text-align:right;">${d.size_gb} GB</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;">${d.interface_type || 'N/A'}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;">${d.media_type || 'N/A'}</td>
                <td style="padding:4px 8px;border:1px solid #dee2e6;font-family:monospace;font-size:11px;">${d.serial_number || 'N/A'}</td>
            </tr>`;
        });
        storageTableHtml += '</tbody></table>';
        storageContent = `<tr><td colspan="2" style="padding:6px 10px;">${storageTableHtml}</td></tr>`;
    } else {
        storageContent = '<tr><td colspan="2" style="padding:10px;color:#999;">' + L('hardware.no_storage') + '</td></tr>';
    }
    html += section('💾', L('hardware.storage_section'), storageContent);

    // ═══════ DISK LAYOUT / PARTITIONS ═══════
    if (completeDiskLayout && completeDiskLayout.length > 0) {
        let diskLayoutHtml = '';
        completeDiskLayout.forEach((disk) => {
            diskLayoutHtml += `
                <div style="border:1px solid #ddd;padding:12px;margin:6px 0;border-radius:6px;background:#fafafa;">
                    <div style="font-weight:600;margin-bottom:6px;">Disk ${disk.disk_number} - ${disk.disk_model} (${disk.disk_size_gb} GB)</div>
                    <div style="font-size:12px;color:#666;margin-bottom:6px;">${L('hardware.interface')}: ${disk.disk_interface || L('hardware.na')} | Style: ${disk.partition_style || L('hardware.na')} | ${L('hardware.serial')}: <code>${disk.disk_serial || L('hardware.na')}</code></div>`;
            if (disk.partitions && disk.partitions.length > 0) {
                diskLayoutHtml += `<table style="width:100%;font-size:11px;border-collapse:collapse;">
                    <thead><tr style="background:#e9ecef;">
                        <th style="padding:4px;border:1px solid #dee2e6;">#</th>
                        <th style="padding:4px;border:1px solid #dee2e6;">${L('hardware.purpose')}</th>
                        <th style="padding:4px;border:1px solid #dee2e6;">${L('hardware.drive')}</th>
                        <th style="padding:4px;border:1px solid #dee2e6;">${L('hardware.size')}</th>
                        <th style="padding:4px;border:1px solid #dee2e6;">${L('hardware.fs')}</th>
                        <th style="padding:4px;border:1px solid #dee2e6;">${L('hardware.free_used')}</th>
                    </tr></thead><tbody>`;
                disk.partitions.forEach((p, pi) => {
                    const fu = p.free_space_gb !== null ? `${p.used_space_gb || 0}G / ${p.free_space_gb || 0}G free` : '-';
                    diskLayoutHtml += `<tr style="background:${pi % 2 === 0 ? '#fff' : '#f8f9fa'};">
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${p.partition_number}</td>
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${p.partition_purpose || '-'}</td>
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${p.drive_letter || '-'}</td>
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${p.size_gb} GB</td>
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${p.file_system || '-'}</td>
                        <td style="padding:3px 4px;border:1px solid #dee2e6;">${fu}</td>
                    </tr>`;
                });
                diskLayoutHtml += '</tbody></table>';
            }
            diskLayoutHtml += '</div>';
        });
        html += section('💽', L('hardware.disk_layout'), `<tr><td colspan="2" style="padding:6px 10px;">${diskLayoutHtml}</td></tr>`);
    } else if (partitions.length > 0) {
        let partContent = '';
        partitions.forEach(p => {
            partContent += kvRow(p.drive_letter || '?', `${p.volume_name || 'Unnamed'} - ${p.file_system || '?'}, ${p.size_gb}GB total, ${p.free_space_gb}GB free`);
        });
        html += section('📁', L('hardware.disk_partitions'), partContent);
    }

    // ═══════ AUDIO DEVICES ═══════
    if (audioDevices.length > 0) {
        let audioContent = '';
        audioDevices.forEach(d => {
            audioContent += kvRow(d.manufacturer || 'Audio', d.name);
        });
        html += section('🔊', L('hardware.audio_section'), audioContent);
    }

    // ═══════ MONITORS ═══════
    if (monitors.length > 0) {
        let monContent = '';
        monitors.forEach((m, i) => {
            if (i > 0) monContent += `<tr><td colspan="2" style="border-top:1px solid #eee;padding:4px;"></td></tr>`;
            monContent += kvRow(L('hardware.name'), m.name);
            monContent += kvRow(L('hardware.manufacturer'), m.manufacturer);
            monContent += kvRow(L('hardware.serial'), m.serial_number, true);
            if (m.year_of_manufacture) monContent += kvRow('Year', m.year_of_manufacture);
        });
        html += section('🖥️', L('hardware.monitors_section'), monContent);
    }

    // ═══════ DEVICE FINGERPRINT ═══════
    if (hw.device_fingerprint) {
        html += `
            <div style="margin-top:12px;padding:10px 14px;background:#f0f4ff;border:1px solid #c5cae9;border-radius:8px;">
                <div style="font-size:11px;color:#3949ab;font-weight:600;margin-bottom:3px;">🔒 ${L('hardware.device_fp')}</div>
                <div style="font-family:monospace;font-size:11px;word-break:break-all;color:#283593;">${hw.device_fingerprint}</div>
            </div>
        `;
    }

    // ═══════ FOOTER ═══════
    html += `
            <div style="margin-top:15px;padding:8px 12px;background:#f5f5f5;border-radius:6px;font-size:11px;color:#888;display:flex;justify-content:space-between;">
                <span>📅 ${L('hardware.collected')}: ${hw.collected_at || L('hardware.na')}</span>
                <span>⚙️ ${L('hardware.method')}: ${hw.collection_method || 'PowerShell'}</span>
            </div>
        </div>
    `;

    document.getElementById('hardware-details').innerHTML = html;
}

// ═══════════════════════════════════════════════
// ROLES & PERMISSIONS (ACL) FUNCTIONS
// ═══════════════════════════════════════════════

let cachedPermissions = null; // Cache permission categories

function loadRoles() {
    fetch('?action=acl_list_roles', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const tbody = document.getElementById('roles-table-body');
            if (!data.roles || data.roles.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;">${LANG['roles.no_roles']}</td></tr>`;
                return;
            }
            tbody.innerHTML = data.roles.map(role => {
                const safeName = escapeHtml(role.display_name);
                const safeDesc = escapeHtml(role.description || '');
                const safeColor = /^#[0-9a-fA-F]{6}$/.test(role.color) ? role.color : '#6c757d';
                const typeBadge = role.role_type === 'admin'
                    ? `<span style="background:#e3f2fd;color:#1565c0;padding:2px 8px;border-radius:10px;font-size:11px;">${LANG['roles.admin_badge']}</span>`
                    : `<span style="background:#f3e5f5;color:#7b1fa2;padding:2px 8px;border-radius:10px;font-size:11px;">${LANG['roles.technician_badge']}</span>`;
                const systemBadge = role.is_system_role == 1
                    ? `<span style="background:#e8f5e9;color:#2e7d32;padding:2px 8px;border-radius:10px;font-size:11px;">${LANG['roles.system_badge']}</span>`
                    : `<span style="background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:10px;font-size:11px;">${LANG['roles.custom_badge']}</span>`;
                const statusBadge = role.is_active == 1
                    ? `<span style="color:#2e7d32;">${LANG['tech.active']}</span>`
                    : `<span style="color:#c62828;">${LANG['tech.inactive']}</span>`;
                const deleteBtn = role.is_system_role == 1
                    ? ''
                    : `<button class="btn btn-sm btn-danger" onclick="deleteRole(${parseInt(role.id)},this)" data-name="${safeName}" style="margin-left:4px;">${LANG['common.delete'] || 'Delete'}</button>`;

                return `<tr>
                    <td>
                        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${safeColor};margin-right:8px;vertical-align:middle;"></span>
                        <strong>${safeName}</strong>
                        <div style="font-size:11px;color:#888;margin-left:20px;">${safeDesc}</div>
                    </td>
                    <td>${typeBadge}</td>
                    <td><strong>${role.permission_count}</strong></td>
                    <td>${role.user_count || 0}</td>
                    <td>${statusBadge} ${systemBadge}</td>
                    <td style="white-space:nowrap;">
                        <button class="btn btn-sm" onclick="editRole(${parseInt(role.id)})">${LANG['common.edit'] || 'Edit'}</button>
                        <button class="btn btn-sm" onclick="cloneRole(${parseInt(role.id)},this)" data-name="${escapeHtml(role.role_name)}" style="margin-left:4px;">${LANG['roles.clone'] || 'Clone'}</button>
                        ${deleteBtn}
                    </td>
                </tr>`;
            }).join('');
        })
        .catch(err => console.error('Load roles error:', err));

}

function loadPermissionsForForm() {
    if (cachedPermissions) {
        renderPermissionCheckboxes(cachedPermissions, []);
        return Promise.resolve();
    }
    return fetch('?action=acl_list_permissions', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                cachedPermissions = data.categories;
                renderPermissionCheckboxes(data.categories, []);
            }
        });
}

function renderPermissionCheckboxes(categories, selectedIds) {
    const container = document.getElementById('rolePermissionsContainer');
    container.innerHTML = categories.map(cat => {
        const permsHtml = cat.permissions.map(p => {
            const checked = selectedIds.includes(p.id) ? 'checked' : '';
            const dangerBadge = p.is_dangerous ? `<span style="background:#f8d7da;color:#721c24;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:5px;">${LANG['acl.danger_badge'] || 'DANGER'}</span>` : '';
            return `<label style="display:flex;align-items:flex-start;gap:8px;padding:5px 8px;cursor:pointer;border-bottom:1px solid #f5f5f5;">
                <input type="checkbox" name="perm_ids" value="${parseInt(p.id)}" ${checked} style="margin-top:3px;">
                <div>
                    <div style="font-weight:500;font-size:13px;">${escapeHtml(translateAclPerm(p.permission_key, p.display_name))}${dangerBadge}</div>
                    <div style="font-size:11px;color:#888;">${escapeHtml(translateAclPermDesc(p.permission_key, p.description))}</div>
                </div>
            </label>`;
        }).join('');

        const permCount = cat.permissions.length;
        return `<div style="margin-bottom:8px;">
            <div onclick="this.nextElementSibling.style.display=this.nextElementSibling.style.display==='none'?'block':'none'"
                 style="background:#f0f2f5;padding:8px 12px;border-radius:6px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:13px;">
                <span>${escapeHtml(translateAclCategory(cat.category_key, cat.display_name))}</span>
                <span style="font-size:11px;color:#888;">${(LANG['acl.permissions_count'] || '%d permissions').replace('%d', permCount)}</span>
            </div>
            <div style="padding-left:10px;">${permsHtml}</div>
        </div>`;
    }).join('');
}

function toggleAllPermissions(selectAll) {
    document.querySelectorAll('#rolePermissionsContainer input[name="perm_ids"]').forEach(cb => cb.checked = selectAll);
}

function showCreateRoleModal() {
    document.getElementById('roleEditId').value = '';
    document.getElementById('roleModalTitle').textContent = LANG['roles.create_role'];
    document.getElementById('roleFormSubmitBtn').textContent = LANG['roles.create_button'];
    document.getElementById('roleFormName').value = '';
    document.getElementById('roleFormDisplayName').value = '';
    document.getElementById('roleFormDescription').value = '';
    document.getElementById('roleFormType').value = 'admin';
    document.getElementById('roleFormColor').value = '#6c757d';
    document.getElementById('roleFormName').readOnly = false;
    loadPermissionsForForm();
    document.getElementById('roleModal').classList.add('active');
}

function editRole(roleId) {
    fetch(`?action=acl_get_role&role_id=${roleId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) { alert(LANG['roles.not_found']); return; }
            const role = data.role;
            document.getElementById('roleEditId').value = role.id;
            document.getElementById('roleEditId').dataset.updatedAt = role.updated_at || '';
            document.getElementById('roleModalTitle').textContent = LANG['roles.edit_role_prefix'] + role.display_name;
            document.getElementById('roleFormSubmitBtn').textContent = LANG['roles.save_changes'];
            document.getElementById('roleFormName').value = role.role_name;
            document.getElementById('roleFormDisplayName').value = role.display_name;
            document.getElementById('roleFormDescription').value = role.description || '';
            document.getElementById('roleFormType').value = role.role_type;
            document.getElementById('roleFormColor').value = role.color || '#6c757d';
            document.getElementById('roleFormName').readOnly = !!role.is_system_role;

            const selectedIds = role.permission_ids.map(Number);
            if (cachedPermissions) {
                renderPermissionCheckboxes(cachedPermissions, selectedIds);
            } else {
                fetch('?action=acl_list_permissions', { credentials: 'same-origin' })
                    .then(r2 => r2.json())
                    .then(d2 => {
                        if (d2.success) {
                            cachedPermissions = d2.categories;
                            renderPermissionCheckboxes(d2.categories, selectedIds);
                        }
                    });
            }
            document.getElementById('roleModal').classList.add('active');
        });
}

function saveRole(event) {
    event.preventDefault();
    const editId = document.getElementById('roleEditId').value;
    const permIds = Array.from(document.querySelectorAll('#rolePermissionsContainer input[name="perm_ids"]:checked'))
        .map(cb => parseInt(cb.value));

    const body = {
        role_name: document.getElementById('roleFormName').value,
        display_name: document.getElementById('roleFormDisplayName').value,
        description: document.getElementById('roleFormDescription').value,
        role_type: document.getElementById('roleFormType').value,
        color: document.getElementById('roleFormColor').value,
        permission_ids: permIds
    };

    let url;
    if (editId) {
        body.role_id = parseInt(editId);
        body.expected_updated_at = document.getElementById('roleEditId').dataset.updatedAt || '';
        url = '?action=acl_update_role';
    } else {
        url = '?action=acl_create_role';
    }

    securePost(url, body)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeModal('roleModal');
                loadRoles();
                alert(data.message || LANG['roles.saved']);
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));

    return false;
}

function deleteRole(roleId, nameOrEl) {
    const roleName = (typeof nameOrEl === 'object' && nameOrEl.dataset) ? nameOrEl.dataset.name : String(nameOrEl || '');
    if (!confirm(LANG['roles.delete_confirm'].replace('%s', roleName))) return;
    securePost(`?action=acl_delete_role&role_id=${parseInt(roleId)}`, {})
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadRoles();
            } else {
                alert('Error: ' + (data.error || 'Delete failed'));
            }
        });
}

function cloneRole(roleId, nameOrEl) {
    const roleName = (typeof nameOrEl === 'object' && nameOrEl.dataset) ? nameOrEl.dataset.name : String(nameOrEl || '');
    const newName = prompt(LANG['roles.clone_name_prompt'], roleName + '_copy');
    if (!newName) return;
    const newDisplayName = prompt(LANG['roles.clone_display_prompt'], newName.replace(/_/g, ' '));
    if (!newDisplayName) return;

    securePost('?action=acl_clone_role', { source_role_id: roleId, new_name: newName, new_display_name: newDisplayName })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadRoles();
                alert(LANG['roles.clone_success']);
            } else {
                alert('Error: ' + (data.error || 'Clone failed'));
            }
        });
}

function showUserOverrides(userType, userId, userName, roleName) {
    document.getElementById('overrideUserType').value = userType;
    document.getElementById('overrideUserId').value = userId;
    document.getElementById('overrideUserName').textContent = userName;
    document.getElementById('overrideUserRole').textContent = roleName || (LANG['roles.no_role'] || 'No role assigned');
    document.getElementById('overridesModalTitle').textContent = (LANG['roles.permission_overrides'] || 'Permission Overrides') + ': ' + userName;

    fetch(`?action=acl_get_user_effective&user_type=${userType}&user_id=${userId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const perms = data.permissions;
            const keys = Object.keys(perms);

            let html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
            html += `<thead><tr style="background:#f0f2f5;"><th style="padding:6px 10px;text-align:left;">${LANG['roles.permission'] || 'Permission'}</th><th style="width:80px;text-align:center;">${LANG['roles.status'] || 'Status'}</th><th style="width:80px;text-align:center;">${LANG['roles.source'] || 'Source'}</th><th style="width:100px;text-align:center;">${LANG['roles.override'] || 'Override'}</th></tr></thead><tbody>`;

            keys.forEach(key => {
                const p = perms[key];
                const statusColor = p.granted ? '#2e7d32' : '#c62828';
                const statusIcon = p.granted ? '✅' : '❌';
                let sourceLabel = p.source;
                if (p.source === 'role') sourceLabel = `<span style="color:#1565c0;">${LANG['roles.source_role'] || 'Role'}</span>`;
                else if (p.source === 'override_grant') sourceLabel = `<span style="color:#2e7d32;font-weight:600;">+${LANG['roles.override'] || 'Override'}</span>`;
                else if (p.source === 'override_deny') sourceLabel = `<span style="color:#c62828;font-weight:600;">-${LANG['roles.override'] || 'Override'}</span>`;
                else if (p.source === 'super_admin') sourceLabel = '<span style="color:#ff6f00;">SuperAdmin</span>';
                else sourceLabel = `<span style="color:#999;">${LANG['roles.source_none'] || 'None'}</span>`;

                const overrideBtns = p.source === 'super_admin' ? '<span style="color:#999;font-size:11px;">N/A</span>' : `
                    <button class="btn btn-sm" style="font-size:10px;padding:2px 6px;${p.source === 'override_grant' ? 'background:#c8e6c9;' : ''}" onclick="setOverride('${key}',${p.granted ? 0 : 1})">
                        ${p.granted ? (LANG['roles.deny'] || 'Deny') : (LANG['roles.grant'] || 'Grant')}
                    </button>
                    ${p.source.startsWith('override') ? `<button class="btn btn-sm" style="font-size:10px;padding:2px 6px;margin-left:3px;" onclick="removeOverride('${key}')">${LANG['roles.reset'] || 'Reset'}</button>` : ''}
                `;

                html += `<tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:5px 10px;"><strong>${escapeHtml(translateAclPerm(key, p.display_name))}</strong>${p.is_dangerous ? ' ⚠️' : ''}</td>
                    <td style="text-align:center;">${statusIcon}</td>
                    <td style="text-align:center;font-size:11px;">${sourceLabel}</td>
                    <td style="text-align:center;">${overrideBtns}</td>
                </tr>`;
            });

            html += '</tbody></table>';
            document.getElementById('overridesPermissionList').innerHTML = html;
        });

    document.getElementById('overridesModal').classList.add('active');
}

function setOverride(permKey, isGranted) {
    const userType = document.getElementById('overrideUserType').value;
    const userId = document.getElementById('overrideUserId').value;

    // Find permission ID from cached permissions
    let permId = null;
    if (cachedPermissions) {
        cachedPermissions.forEach(cat => {
            cat.permissions.forEach(p => {
                if (p.permission_key === permKey) permId = p.id;
            });
        });
    }

    if (!permId) {
        // Fetch permissions if not cached
        fetch('?action=acl_list_permissions', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    cachedPermissions = data.categories;
                    data.categories.forEach(cat => {
                        cat.permissions.forEach(p => {
                            if (p.permission_key === permKey) permId = p.id;
                        });
                    });
                    if (permId) doSetOverride(userType, userId, permId, isGranted);
                }
            });
        return;
    }

    doSetOverride(userType, userId, permId, isGranted);
}

function doSetOverride(userType, userId, permId, isGranted) {
    securePost('?action=acl_set_user_override', {
            user_type: userType,
            user_id: parseInt(userId),
            permission_id: permId,
            is_granted: isGranted,
            reason: isGranted ? 'Manually granted' : 'Manually denied'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                // Refresh the overrides view
                showUserOverrides(
                    userType, userId,
                    document.getElementById('overrideUserName').textContent,
                    document.getElementById('overrideUserRole').textContent
                );
            }
        });
}

function removeOverride(permKey) {
    const userType = document.getElementById('overrideUserType').value;
    const userId = document.getElementById('overrideUserId').value;

    let permId = null;
    if (cachedPermissions) {
        cachedPermissions.forEach(cat => {
            cat.permissions.forEach(p => {
                if (p.permission_key === permKey) permId = p.id;
            });
        });
    }
    if (!permId) return;

    securePost('?action=acl_remove_user_override', { user_type: userType, user_id: parseInt(userId), permission_id: permId })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showUserOverrides(
                    userType, userId,
                    document.getElementById('overrideUserName').textContent,
                    document.getElementById('overrideUserRole').textContent
                );
            }
        });
}

function loadACLChangelog() {
    const section = document.getElementById('acl-changelog-section');
    section.style.display = section.style.display === 'none' ? 'block' : 'none';
    if (section.style.display === 'none') return;

    fetch('?action=acl_get_changelog&page=1', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            const entries = data.data.entries;
            const tbody = document.getElementById('acl-changelog-body');
            if (entries.length === 0) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px;color:#888;">${LANG['roles.no_changelog']}</td></tr>`;
                return;
            }
            tbody.innerHTML = entries.map(e => `<tr>
                <td style="font-size:12px;white-space:nowrap;">${escapeHtml(e.created_at)}</td>
                <td>${escapeHtml(e.actor_name || 'System')}</td>
                <td><code style="font-size:11px;background:#f1f3f5;padding:2px 6px;border-radius:3px;">${escapeHtml(e.action)}</code></td>
                <td>${escapeHtml(e.target_name || ((e.target_type || '') + ':' + (e.target_id || '')))}</td>
                <td style="font-size:11px;max-width:200px;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(e.new_value || '-')}</td>
            </tr>`).join('');
        });
}

// Auto-load roles when Roles tab is shown
document.addEventListener('DOMContentLoaded', function() {
    const rolesTab = document.querySelector('[data-tab="roles"]');
    if (rolesTab) {
        rolesTab.addEventListener('click', function() {
            loadRoles();
        });
    }
});

// Logs functions
let currentLogsPage = 1;

function loadLogs(page = 1) {
    currentLogsPage = page;
    const search = document.getElementById('logs-search').value;

    fetch(`?action=list_logs&page=${page}&search=${encodeURIComponent(search)}`, {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderLogsTable(data.logs);
                renderPagination('logs-pagination', data.page, data.pages, loadLogs);
            }
        })
        .catch(err => console.error('Error loading logs:', err));
}

function renderLogsTable(logs) {
    const container = document.getElementById('logs-table');
    if (logs.length === 0) {
        container.innerHTML = `<div class="loading">${LANG['logs.no_logs']}</div>`;
        return;
    }

    let html = `<table><thead><tr><th>${LANG['logs.time']}</th><th>${LANG['logs.user']}</th><th>${LANG['logs.action']}</th><th>${LANG['logs.description']}</th><th>${LANG['logs.ip_address']}</th></tr></thead><tbody>`;
    logs.forEach(log => {
        html += `<tr>
            <td>${log.created_at}</td>
            <td>${log.username || LANG['logs.system']}</td>
            <td><span class="badge badge-info">${translateAction(log.action)}</span></td>
            <td>${translateDescription(log.description)}</td>
            <td>${log.ip_address}</td>
        </tr>`;
    });
    html += '</tbody></table>';
    container.innerHTML = html;
}

// Settings Tab Functions
function loadAltServerSettings() {
    fetch('?action=get_alt_server_settings', {
        credentials: 'same-origin'
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const config = data.config;

                document.getElementById('alt_server_enabled').checked = config.alt_server_enabled === '1';
                document.getElementById('alt_server_script_path').value = config.alt_server_script_path || '';
                document.getElementById('alt_server_pre_command').value = config.alt_server_pre_command || '';
                document.getElementById('alt_server_script_args').value = config.alt_server_script_args || '';
                document.getElementById('alt_server_script_type').value = config.alt_server_script_type || 'cmd';
                document.getElementById('alt_server_timeout_seconds').value = config.alt_server_timeout_seconds || '300';
                document.getElementById('alt_server_prompt_technician').checked = config.alt_server_prompt_technician === '1';
                document.getElementById('alt_server_auto_failover').checked = config.alt_server_auto_failover === '1';
                document.getElementById('alt_server_verify_activation').checked = config.alt_server_verify_activation === '1';

                toggleAltServerConfig();
            } else {
                alert('Error loading settings: ' + data.error);
            }
        })
        .catch(err => console.error('Error loading alt server settings:', err));
}

function toggleAltServerConfig() {
    const enabled = document.getElementById('alt_server_enabled').checked;
    document.getElementById('alt_server_config_group').style.display = enabled ? 'block' : 'none';
}

function saveAltServerSettings(event) {
    event.preventDefault();

    const formData = new FormData(event.target);
    const data = {
        action: 'save_alt_server_settings',
        alt_server_enabled: formData.get('alt_server_enabled') ? '1' : '0',
        alt_server_script_path: formData.get('alt_server_script_path'),
        alt_server_pre_command: formData.get('alt_server_pre_command'),
        alt_server_script_args: formData.get('alt_server_script_args'),
        alt_server_script_type: formData.get('alt_server_script_type'),
        alt_server_timeout_seconds: formData.get('alt_server_timeout_seconds'),
        alt_server_prompt_technician: formData.get('alt_server_prompt_technician') ? '1' : '0',
        alt_server_auto_failover: formData.get('alt_server_auto_failover') ? '1' : '0',
        alt_server_verify_activation: formData.get('alt_server_verify_activation') ? '1' : '0'
    };

    securePost('', data)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['settings.save_success']);
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        alert('Error: ' + err.message);
    });
}

// =============================================
// CLIENT RESOURCES (Phase 9: PS7 Migration)
// =============================================

function loadClientResources() {
    secureGet('?action=list_client_resources')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;

            const card = document.getElementById('ps7InstallerCard');
            const uploadForm = document.getElementById('ps7UploadForm');
            const ps7Resource = data.resources.find(r => r.resource_key === 'ps7_installer');

            if (ps7Resource) {
                const sizeMB = (ps7Resource.file_size / (1024 * 1024)).toFixed(1);
                const shortHash = ps7Resource.checksum_sha256.substring(0, 16) + '...';
                card.innerHTML =
                    '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 8px; padding: 16px;">' +
                        '<div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">' +
                            '<span style="font-size: 20px;">📦</span>' +
                            '<strong>' + escapeHtml(ps7Resource.original_filename) + '</strong>' +
                        '</div>' +
                        '<div style="font-size: 13px; color: #666; margin-bottom: 10px;">' +
                            (LANG['settings.file_size'] || 'Size') + ': ' + sizeMB + ' MB &nbsp;|&nbsp; ' +
                            'SHA256: <code style="font-size: 11px;">' + escapeHtml(shortHash) + '</code> &nbsp;|&nbsp; ' +
                            (LANG['settings.uploaded_by'] || 'Uploaded') + ': ' + escapeHtml(ps7Resource.uploaded_by_name || 'admin') +
                            ' (' + escapeHtml(ps7Resource.created_at) + ')' +
                        '</div>' +
                        '<div style="display: flex; gap: 8px;">' +
                            '<button class="btn btn-primary" onclick="document.getElementById(\'ps7FileInput\').click()">' + (LANG['settings.replace_file'] || 'Replace') + '</button>' +
                            '<button class="btn btn-danger" onclick="deleteClientResource(\'ps7_installer\')">' + (LANG['common.delete'] || 'Delete') + '</button>' +
                        '</div>' +
                    '</div>';
                if (uploadForm) uploadForm.style.display = 'none';
            } else {
                card.innerHTML = '<p style="color: #999; font-style: italic;">' + (LANG['settings.no_installer'] || 'No installer uploaded yet.') + '</p>';
                if (uploadForm) uploadForm.style.display = 'block';
            }

        })
        .catch(err => console.warn('loadClientResources error:', err));
}

function uploadClientResource(resourceKey) {
    const fileInput = document.getElementById('ps7FileInput');
    if (!fileInput || !fileInput.files.length) {
        alert(LANG['settings.select_file'] || 'Please select a file first.');
        return;
    }

    const file = fileInput.files[0];
    const ext = file.name.split('.').pop().toLowerCase();
    if (!['msi', 'exe'].includes(ext)) {
        alert(LANG['settings.invalid_file_type'] || 'Only .msi and .exe files are allowed.');
        return;
    }

    const formData = new FormData();
    formData.append('resource_file', file);
    formData.append('resource_key', resourceKey);
    formData.append('csrf_token', csrfToken);

    const progressDiv = document.getElementById('ps7UploadProgress');
    const progressBar = document.getElementById('ps7ProgressBar');
    const progressText = document.getElementById('ps7ProgressText');
    if (progressDiv) progressDiv.style.display = 'block';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '?action=upload_client_resource');
    xhr.setRequestHeader('X-CSRF-Token', csrfToken);

    xhr.upload.onprogress = function(e) {
        if (e.lengthComputable) {
            const pct = Math.round((e.loaded / e.total) * 100);
            if (progressBar) progressBar.style.width = pct + '%';
            if (progressText) progressText.textContent = (LANG['settings.upload_progress'] || 'Uploading...') + ' ' + pct + '%';
        }
    };

    xhr.onload = function() {
        if (progressDiv) progressDiv.style.display = 'none';
        if (progressBar) progressBar.style.width = '0%';
        try {
            const data = JSON.parse(xhr.responseText);
            if (data.success) {
                alert(LANG['settings.upload_success'] || 'File uploaded successfully.');
                fileInput.value = '';
                loadClientResources();
            } else {
                alert((LANG['settings.upload_error'] || 'Upload failed: ') + (data.error || 'Unknown error'));
            }
        } catch (e) {
            alert((LANG['settings.upload_error'] || 'Upload failed: ') + 'Invalid server response');
        }
    };

    xhr.onerror = function() {
        if (progressDiv) progressDiv.style.display = 'none';
        alert((LANG['settings.upload_error'] || 'Upload failed: ') + 'Network error');
    };

    xhr.send(formData);
}

function deleteClientResource(resourceKey) {
    if (!confirm(LANG['settings.delete_resource_confirm'] || 'Are you sure you want to delete this resource?')) return;

    securePost('?action=delete_client_resource', { resource_key: resourceKey })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadClientResources();
            } else {
                alert('Error: ' + (data.error || 'Unknown error'));
            }
        })
        .catch(err => alert('Error: ' + err.message));
}

// Pagination helper
function renderPagination(containerId, currentPage, totalPages, loadFunction) {
    const container = document.getElementById(containerId);
    if (totalPages <= 1) {
        container.innerHTML = '';
        return;
    }

    let html = '';

    // Previous button
    html += `<button ${currentPage === 1 ? 'disabled' : ''} onclick="${loadFunction.name}(${currentPage - 1})">« ${LANG['common.previous']}</button>`;

    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
        if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
            html += `<button class="${i === currentPage ? 'active' : ''}" onclick="${loadFunction.name}(${i})">${i}</button>`;
        } else if (i === currentPage - 3 || i === currentPage + 3) {
            html += '<span>...</span>';
        }
    }

    // Next button
    html += `<button ${currentPage === totalPages ? 'disabled' : ''} onclick="${loadFunction.name}(${currentPage + 1})">${LANG['common.next']} »</button>`;

    container.innerHTML = html;
}

// ========================================
// USB DEVICE MANAGEMENT FUNCTIONS
// ========================================

function loadUSBDevices() {
    const filterTech = document.getElementById('usb-filter-technician').value;
    const filterStatus = document.getElementById('usb-filter-status').value;

    fetch(`?action=list_usb_devices&technician_id=${filterTech}&status=${filterStatus}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderUSBDevicesStats(data.stats);
                renderUSBDevicesTable(data.devices);
                populateUSBTechnicianFilter();
            } else {
                alert('Error loading USB devices: ' + data.error);
            }
        })
        .catch(err => {
            console.error('Error loading USB devices:', err);
            alert('Failed to load USB devices');
        });
}

function renderUSBDevicesStats(stats) {
    const html = `
        <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 15px;">
            <div class="stat-card">
                <div class="stat-value">${stats.active}</div>
                <div class="stat-label">${LANG['usb.active']}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.disabled}</div>
                <div class="stat-label">${LANG['usb.disabled']}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.lost}</div>
                <div class="stat-label">${LANG['usb.lost']}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.stolen}</div>
                <div class="stat-label">${LANG['usb.stolen']}</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">${stats.total}</div>
                <div class="stat-label">${LANG['usb.total']}</div>
            </div>
        </div>
    `;
    document.getElementById('usb-devices-stats').innerHTML = html;
}

function renderUSBDevicesTable(devices) {
    let html = '<table><thead><tr>';
    html += `<th>${LANG['usb.device_name']}</th>`;
    html += `<th>${LANG['usb.serial_number']}</th>`;
    html += `<th>${LANG['usb.technician']}</th>`;
    html += `<th>${LANG['usb.status']}</th>`;
    html += `<th>${LANG['usb.last_used']}</th>`;
    html += `<th>${LANG['usb.usage_count']}</th>`;
    html += `<th>${LANG['usb.registered_date']}</th>`;
    html += `<th>${LANG['usb.actions']}</th>`;
    html += '</tr></thead><tbody>';

    if (devices.length === 0) {
        html += `<tr><td colspan="8" style="text-align: center; padding: 40px; color: #999;">${LANG['usb.no_devices']}</td></tr>`;
    } else {
        devices.forEach(device => {
            const statusBadges = {
                'active': `<span class="badge badge-success">${LANG['usb.active']}</span>`,
                'disabled': `<span class="badge badge-secondary">${LANG['usb.disabled']}</span>`,
                'lost': `<span class="badge badge-warning">${LANG['usb.lost']}</span>`,
                'stolen': `<span class="badge badge-danger">${LANG['usb.stolen']}</span>`
            };

            const maskedSerial = device.device_serial_number.length > 10
                ? device.device_serial_number.substring(0, 8) + '***'
                : device.device_serial_number;

            const lastUsed = device.last_used_date
                ? new Date(device.last_used_date).toLocaleString()
                : `<span style="color: #999;">${LANG['usb.never_used']}</span>`;

            const registeredDate = new Date(device.registered_date).toLocaleDateString();

            html += `<tr>
                <td><strong>${escapeHtml(device.device_name)}</strong></td>
                <td><code>${maskedSerial}</code></td>
                <td>${escapeHtml(device.full_name)} <span style="color: #666;">(${device.technician_id})</span></td>
                <td>${statusBadges[device.device_status]}</td>
                <td>${lastUsed}</td>
                <td>${device.usage_count}</td>
                <td>${registeredDate}</td>
                <td>`;

            if (device.device_status === 'active') {
                html += `<button class="btn btn-sm" onclick="updateUSBDeviceStatus(${device.device_id}, 'disabled')" style="background: #6c757d; color: white;">${LANG['usb.disable_btn']}</button> `;
                html += `<button class="btn btn-sm" onclick="updateUSBDeviceStatus(${device.device_id}, 'lost')" style="background: #ffc107; color: black;">${LANG['usb.mark_lost']}</button> `;
                html += `<button class="btn btn-sm" onclick="updateUSBDeviceStatus(${device.device_id}, 'stolen')" style="background: #dc3545; color: white;">${LANG['usb.mark_stolen']}</button> `;
            } else {
                html += `<button class="btn btn-sm" onclick="updateUSBDeviceStatus(${device.device_id}, 'active')" style="background: #28a745; color: white;">${LANG['usb.enable']}</button> `;
            }

            html += `<button class="btn btn-sm btn-danger" onclick="deleteUSBDevice(${device.device_id}, '${escapeHtml(device.device_name)}')">${LANG['common.delete']}</button>`;

            html += `</td></tr>`;
        });
    }

    html += '</tbody></table>';
    document.getElementById('usb-devices-table').innerHTML = html;
}

function populateUSBTechnicianFilter() {
    fetch('?action=list_technicians')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('usb-filter-technician');
                const currentValue = select.value;

                let options = `<option value="">${LANG['usb.all_technicians']}</option>`;
                data.technicians.forEach(tech => {
                    options += `<option value="${tech.technician_id}">${tech.full_name} (${tech.technician_id})</option>`;
                });

                select.innerHTML = options;
                select.value = currentValue;
            }
        });
}

function showRegisterUSBModal() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 700px;">
            <h3>${LANG['usb.register_modal']}</h3>

            <!-- Auto-Detect Section -->
            <div style="background: #e3f2fd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2196f3;">
                <h4 style="margin: 0 0 10px 0; color: #1976d2;">🔍 ${LANG['usb.auto_detect']}</h4>
                <div id="usb-detection-info"></div>
                <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">
                    ${LANG['usb.detect_info']}
                </p>
                <button type="button" id="usb-detect-btn" class="btn btn-primary" onclick="requestUSBAccess()" style="width: 100%; margin-bottom: 10px;">
                    🔌 ${LANG['usb.detect_button']}
                </button>
                <div style="background: #fff9e6; border: 1px solid #ffc107; border-radius: 4px; padding: 8px; font-size: 12px;">
                    💡 <strong>${LANG['usb.how_it_works']}:</strong> ${LANG['usb.how_it_works_desc'] || 'Uses Hardware Bridge extension (best), WebUSB API, or PowerShell command.'}
                </div>
                <div id="usb-detection-results" style="margin-top: 10px;"></div>
            </div>

            <form id="registerUSBForm" onsubmit="registerUSBDevice(event); return false;">
                <div class="form-group">
                    <label for="usb-technician-id"><strong>${LANG['usb.technician_label']}</strong></label>
                    <select id="usb-technician-id" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">${LANG['usb.technician_select']}</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="usb-device-name"><strong>${LANG['usb.device_name_label']}</strong></label>
                    <input type="text" id="usb-device-name" required placeholder="${LANG['usb.device_name_placeholder']}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <small style="display: block; color: #666; margin-top: 5px;">${LANG['usb.device_name_desc']}</small>
                </div>

                <div class="form-group">
                    <label for="usb-serial-number"><strong>${LANG['usb.serial_label']}</strong></label>
                    <input type="text" id="usb-serial-number" required placeholder="${LANG['usb.serial_placeholder']}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                    <small style="display: block; color: #666; margin-top: 5px;">${LANG['usb.serial_desc']}</small>
                    <small style="display: block; color: #e65100; margin-top: 3px;">⚠️ <strong>${LANG['usb.serial_warning'] || 'Important'}:</strong> ${LANG['usb.serial_warning_desc'] || 'WebUSB and WMI may report different serial numbers for the same device. For reliable USB authentication, use Hardware Bridge or PowerShell detection (both use WMI).'}</small>
                </div>

                <div class="form-group">
                    <label for="usb-manufacturer"><strong>${LANG['usb.manufacturer_label']}</strong></label>
                    <input type="text" id="usb-manufacturer" placeholder="${LANG['usb.manufacturer_placeholder']}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group">
                    <label for="usb-model"><strong>${LANG['usb.model_label']}</strong></label>
                    <input type="text" id="usb-model" placeholder="${LANG['usb.model_placeholder']}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group">
                    <label for="usb-capacity"><strong>${LANG['usb.capacity_label']}</strong></label>
                    <input type="number" id="usb-capacity" placeholder="${LANG['usb.capacity_placeholder']}" step="0.01" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div class="form-group">
                    <label for="usb-description"><strong>${LANG['usb.description_label']}</strong></label>
                    <textarea id="usb-description" rows="3" placeholder="${LANG['usb.description_placeholder']}" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" class="btn" onclick="closeModal()">${LANG['common.cancel']}</button>
                    <button type="submit" class="btn btn-primary" style="margin-left: 10px;">${LANG['usb.register_btn']}</button>
                </div>
            </form>
        </div>
    `;

    document.body.appendChild(modal);
    modal.style.display = 'flex';

    // Update USB detection button based on Hardware Bridge availability
    updateUSBDetectionUI();

    // Populate technicians dropdown
    fetch('?action=list_technicians')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                let options = '<option value="">' + LANG['usb.technician_select'] + '</option>';
                data.technicians.forEach(tech => {
                    if (tech.is_active) {
                        options += `<option value="${tech.technician_id}">${tech.full_name} (${tech.technician_id})</option>`;
                    }
                });
                document.getElementById('usb-technician-id').innerHTML = options;
            }
        });
}

function registerUSBDevice(event) {
    event.preventDefault();

    const data = {
        technician_id: document.getElementById('usb-technician-id').value,
        device_name: document.getElementById('usb-device-name').value,
        device_serial_number: document.getElementById('usb-serial-number').value,
        device_manufacturer: document.getElementById('usb-manufacturer').value || null,
        device_model: document.getElementById('usb-model').value || null,
        device_capacity_gb: parseFloat(document.getElementById('usb-capacity').value) || null,
        device_description: document.getElementById('usb-description').value || null
    };

    data.action = 'register_usb_device';
    securePost('', data)
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert(LANG['usb.register_success']);
            closeModal();
            loadUSBDevices();
        } else {
            alert(LANG['common.error'] + ': ' + result.error);
        }
    })
    .catch(err => {
        console.error('Error registering USB device:', err);
        alert('Failed to register USB device');
    });
}

// ========================================
// UTILITY FUNCTIONS
// ========================================

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ========================================
// HARDWARE BRIDGE EXTENSION DETECTION
// ========================================

let hardwareBridgeAvailable = false;
let hardwareBridgeChecked = false;

// Wait for Hardware Bridge to be ready
window.addEventListener('OEMHardwareBridgeReady', (event) => {
    console.log('[Admin Panel] Hardware Bridge detected:', event.detail);
    hardwareBridgeAvailable = true;
    hardwareBridgeChecked = true;
    updateUSBDetectionUI();
});

// Check for Hardware Bridge availability
async function checkHardwareBridge() {
    if (hardwareBridgeChecked) return hardwareBridgeAvailable;

    return new Promise((resolve) => {
        let attempts = 0;
        const checkInterval = setInterval(() => {
            attempts++;
            if (window.OEMHardwareBridge) {
                clearInterval(checkInterval);
                hardwareBridgeAvailable = true;
                hardwareBridgeChecked = true;
                console.log('[Admin Panel] Hardware Bridge found');
                resolve(true);
            } else if (attempts >= 20) {
                clearInterval(checkInterval);
                hardwareBridgeChecked = true;
                console.log('[Admin Panel] Hardware Bridge not found, using fallback');
                resolve(false);
            }
        }, 100);
    });
}

// Update UI based on available detection methods
function updateUSBDetectionUI() {
    const button = document.getElementById('usb-detect-btn');
    if (!button) return;

    if (hardwareBridgeAvailable) {
        button.innerHTML = '🔌 ' + LANG['usb.hardware_bridge_detect'];
        button.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        button.style.border = 'none';
        button.onclick = detectUSBViaHardwareBridge;
    } else if (navigator.usb) {
        button.innerHTML = '🔌 ' + LANG['usb.webusb_detect'];
        button.onclick = requestUSBAccess;
    } else {
        button.innerHTML = '📋 ' + LANG['usb.powershell_fallback'];
        button.onclick = showPowerShellFallback;
    }

    showDetectionMethodInfo();
}

// Detect USB devices via Hardware Bridge extension
async function detectUSBViaHardwareBridge() {
    const resultsDiv = document.getElementById('usb-detection-results');

    resultsDiv.innerHTML = '<div style="background: #e3f2fd; border: 1px solid #2196F3; border-radius: 6px; padding: 15px; margin-top: 10px;"><p style="text-align: center; margin: 0; color: #1565C0;">🔍 <strong>' + LANG['usb.scanning'] + '</strong></p></div>';

    try {
        const devices = await window.OEMHardwareBridge.getUSBDevices();

        if (devices && devices.length > 0) {
            displayHardwareBridgeDeviceList(devices);
        } else {
            resultsDiv.innerHTML = '<div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-top: 10px;"><strong style="color: #856404;">⚠️ ' + LANG['usb.no_devices_detected'] + '</strong><br><br><p style="color: #856404; margin: 10px 0;">' + LANG['usb.bridge_not_detected'] + '</p><ul style="color: #856404; margin-left: 20px;"><li>' + LANG['usb.usb_connected'] + '</li><li>' + LANG['usb.device_explorer'] + '</li><li>' + LANG['usb.device_not_locked'] + '</li></ul><button class="btn btn-secondary" onclick="showPowerShellFallback()" style="width: 100%; margin-top: 10px;">📋 ' + LANG['usb.use_powershell'] + '</button></div>';
        }
    } catch (error) {
        console.error('[Admin Panel] Hardware Bridge error:', error);
        const hint = error.message && error.message.includes('native application')
            ? LANG['usb.native_app_error']
            : LANG['usb.unexpected_error'];
        resultsDiv.innerHTML = '<div style="background: #ffebee; border: 1px solid #f44336; border-radius: 6px; padding: 15px; margin-top: 10px;"><strong style="color: #c62828;">❌ ' + LANG['usb.hardware_error'] + '</strong><br><br><p style="color: #c62828; margin: 10px 0;">' + escapeHtml(error.message) + '</p><p style="color: #c62828; margin: 10px 0; font-size: 13px;">' + hint + '</p><button class="btn btn-secondary" onclick="showPowerShellFallback()" style="width: 100%; margin-top: 10px;">📋 ' + LANG['usb.use_powershell'] + '</button></div>';
    }
}

// Display list of detected USB devices from Hardware Bridge
function displayHardwareBridgeDeviceList(devices) {
    const resultsDiv = document.getElementById('usb-detection-results');

    let devicesHTML = '';
    for (let i = 0; i < devices.length; i++) {
        const device = devices[i];
        const sizeGB = device.size ? (parseInt(device.size) / (1024*1024*1024)).toFixed(2) : 'Unknown';
        const serial = escapeHtml(device.serialNumber || '');
        const model = escapeHtml(device.model || 'Unknown');
        const manufacturer = escapeHtml(device.manufacturer || 'Unknown');

        devicesHTML += '<div style="background: white; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s;" '
            + 'onmouseover="this.style.borderColor=\'#007bff\'; this.style.boxShadow=\'0 2px 8px rgba(0,123,255,0.2)\';" '
            + 'onmouseout="this.style.borderColor=\'#ddd\'; this.style.boxShadow=\'none\';" '
            + 'onclick="fillFormWithUSBInfo(\'' + serial.replace(/'/g, "\\'") + '\', \'' + model.replace(/'/g, "\\'") + '\', \'' + manufacturer.replace(/'/g, "\\'") + '\', \'' + sizeGB + '\')">'
            + '<div style="display: flex; align-items: center; gap: 12px;">'
            + '<div style="font-size: 32px;">💾</div>'
            + '<div style="flex: 1;">'
            + '<strong style="color: #2c3e50; font-size: 15px;">' + model + '</strong><br>'
            + '<span style="color: #7f8c8d; font-size: 13px;">' + manufacturer + ' • ' + sizeGB + ' GB • Serial: ' + serial + '</span>'
            + '</div>'
            + '<div style="color: #007bff; font-size: 20px;">▶</div>'
            + '</div></div>';
    }

    resultsDiv.innerHTML = '<div style="background: #d4edda; border: 2px solid #28a745; border-radius: 8px; padding: 15px; margin-top: 10px;">'
        + '<h4 style="color: #155724; margin: 0 0 15px 0;">✅ ' + LANG['usb.detected_devices'].replace('%d', devices.length) + '</h4>'
        + '<p style="margin: 0 0 10px 0; color: #155724; font-size: 14px;">' + LANG['usb.click_device'] + '</p>'
        + devicesHTML + '</div>';
}

// Show PowerShell fallback method
function showPowerShellFallback() {
    const resultsDiv = document.getElementById('usb-detection-results');
    resultsDiv.innerHTML = '<div style="background: #e3f2fd; border: 1px solid #2196F3; border-radius: 6px; padding: 15px; margin-top: 10px;">'
        + '<strong style="color: #1565C0;">💻 ' + LANG['usb.powershell_title'] + '</strong><br><br>'
        + '<p style="color: #1565C0; margin: 10px 0;">' + LANG['usb.powershell_run_info'] + '</p>'
        + '<div style="background: #2d2d2d; color: #f8f8f2; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; margin: 10px 0; overflow-x: auto;">'
        + 'Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq \'USB\' } | Select SerialNumber,Model</div>'
        + '<button class="btn btn-primary" onclick="copyPowerShellCommand()" style="width: 100%; margin-top: 10px;">📋 ' + LANG['usb.powershell_copy'] + '</button>'
        + '<div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">'
        + '<p style="margin: 0; font-size: 13px; color: #856404;"><strong>' + LANG['usb.powershell_howto'] + '</strong><br>'
        + LANG['usb.powershell_step1'] + '<br>'
        + LANG['usb.powershell_step2'] + '<br>'
        + LANG['usb.powershell_step3'] + '<br>'
        + LANG['usb.powershell_step4'] + '</p></div></div>';
}

// Show info about available detection methods
function showDetectionMethodInfo() {
    const infoDiv = document.getElementById('usb-detection-info');
    if (!infoDiv) return;

    let method = LANG['usb.method_powershell'];
    let color = '#ffc107';
    let icon = '📋';
    let description = LANG['usb.method_powershell_desc'];

    if (hardwareBridgeAvailable) {
        method = LANG['usb.method_hardware_bridge'];
        color = '#667eea';
        icon = '🔌';
        description = LANG['usb.method_hardware_bridge_desc'];
    } else if (navigator.usb) {
        method = LANG['usb.method_webusb'];
        color = '#2196F3';
        icon = '🌐';
        description = LANG['usb.method_webusb_desc'];
    }

    infoDiv.innerHTML = '<div style="background: white; border-left: 4px solid ' + color + '; padding: 12px; margin-bottom: 15px; border-radius: 4px;">'
        + '<strong style="color: ' + color + ';">' + icon + ' ' + LANG['usb.detection_method'] + ': ' + method + '</strong><br>'
        + '<span style="font-size: 13px; color: #666;">' + description + '</span></div>';
}

// Initialize Hardware Bridge detection on page load
(async function initUSBDetection() {
    await checkHardwareBridge();
    updateUSBDetectionUI();
})();

// ========================================
// AUTO-DETECT USB DEVICES (WebUSB API)
// ========================================

async function requestUSBAccess() {
    const resultsDiv = document.getElementById('usb-detection-results');

    // Check if WebUSB is supported
    if (!navigator.usb) {
        resultsDiv.innerHTML = `
            <div style="background: #ffebee; border: 1px solid #f44336; border-radius: 6px; padding: 15px; margin-top: 10px;">
                <strong style="color: #c62828;">❌ WebUSB Not Supported</strong><br><br>
                <p style="color: #c62828; margin: 10px 0;">Your browser doesn't support WebUSB API. Please use:</p>
                <ul style="color: #c62828; margin-left: 20px;">
                    <li><strong>Google Chrome</strong> (recommended)</li>
                    <li><strong>Microsoft Edge</strong> (Chromium-based)</li>
                    <li><strong>Opera</strong></li>
                </ul>
                <p style="color: #c62828; margin-top: 10px;">Firefox and Safari don't support WebUSB yet.</p>
            </div>
        `;
        return;
    }

    resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 15px;">🔍 Requesting USB access...</p>';

    try {
        // Request USB device access - allow all USB devices to appear in selection
        // Note: Empty filters array shows all connected USB devices
        const device = await navigator.usb.requestDevice({
            filters: [] // Show all USB devices (flash drives, keyboards, mice, etc.)
        });

        resultsDiv.innerHTML = '<p style="text-align: center; color: #666; padding: 15px;">📊 Reading device information...</p>';

        // Open the device
        await device.open();

        if (device.configuration === null) {
            await device.selectConfiguration(1);
        }

        // Get device info
        const deviceInfo = {
            productName: device.productName || 'Unknown',
            manufacturerName: device.manufacturerName || 'Unknown',
            serialNumber: device.serialNumber || 'Not Available',
            vendorId: device.vendorId.toString(16).padStart(4, '0'),
            productId: device.productId.toString(16).padStart(4, '0')
        };

        await device.close();

        // Display the results
        displayUSBDevice(deviceInfo);

    } catch (error) {
        console.error('USB detection error:', error);

        if (error.name === 'NotFoundError' || error.message.includes('No device selected')) {
            resultsDiv.innerHTML = `
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-top: 10px;">
                    <strong style="color: #856404;">⚠️ USB Flash Drive Not Appearing?</strong><br><br>
                    <p style="color: #856404; margin: 10px 0;">WebUSB has limitations with USB flash drives because:</p>
                    <ul style="color: #856404; margin: 10px 0 10px 20px;">
                        <li>Windows claims exclusive access to storage devices</li>
                        <li>Many flash drives don't expose WebUSB descriptors</li>
                        <li>Security restrictions prevent direct storage access</li>
                    </ul>
                    <p style="color: #856404; margin: 10px 0;"><strong>Fallback Option:</strong> Use PowerShell to get the serial number:</p>
                    <div style="background: #2d2d2d; color: #f8f8f2; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; margin: 10px 0; overflow-x: auto;">
Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber,Model
                    </div>
                    <button class="btn btn-secondary" onclick="copyPowerShellCommand()" style="margin-top: 10px; width: 100%;">📋 Copy PowerShell Command</button>
                    <p style="color: #856404; margin: 10px 0; font-size: 12px;">Run in PowerShell (Win+X → A), then copy the SerialNumber and paste into the form.</p>
                </div>
            `;
        } else if (error.name === 'SecurityError') {
            resultsDiv.innerHTML = `
                <div style="background: #ffebee; border: 1px solid #f44336; border-radius: 6px; padding: 15px; margin-top: 10px;">
                    <strong style="color: #c62828;">🔒 Security Error</strong><br><br>
                    <p style="color: #c62828; margin: 10px 0;">WebUSB requires HTTPS (secure connection).</p>
                    <p style="color: #c62828;">Since you're on localhost, this should work. Try:</p>
                    <ul style="color: #c62828; margin-left: 20px;">
                        <li>Use <strong>Chrome</strong> or <strong>Edge</strong></li>
                        <li>Make sure URL is <code>http://localhost</code> (not IP)</li>
                    </ul>
                </div>
            `;
        } else {
            resultsDiv.innerHTML = `
                <div style="background: #ffebee; border: 1px solid #f44336; border-radius: 6px; padding: 15px; margin-top: 10px;">
                    <strong style="color: #c62828;">❌ Error: ${error.name}</strong><br><br>
                    <p style="color: #c62828; margin: 10px 0;">${error.message}</p>
                </div>
            `;
        }
    }
}

// Store last detected device for safe onclick handling
let _lastDetectedUSBDevice = null;

function displayUSBDevice(deviceInfo) {
    const resultsDiv = document.getElementById('usb-detection-results');

    // Store device info so onclick doesn't need inline-escaped JSON
    _lastDetectedUSBDevice = deviceInfo;

    // Create a device ID from serial number or vendor/product ID
    const deviceId = deviceInfo.serialNumber !== 'Not Available'
        ? deviceInfo.serialNumber
        : `${deviceInfo.vendorId}${deviceInfo.productId}`;

    resultsDiv.innerHTML = `
        <div style="background: #d4edda; border: 2px solid #28a745; border-radius: 8px; padding: 15px; margin-top: 10px;">
            <h4 style="color: #155724; margin: 0 0 10px 0;">✅ USB Device Detected!</h4>

            <div style="background: white; border-radius: 6px; padding: 12px; margin-bottom: 10px;">
                <div style="display: grid; grid-template-columns: 140px 1fr; gap: 8px; font-size: 14px;">
                    <strong>Product Name:</strong>
                    <span>${escapeHtml(deviceInfo.productName)}</span>

                    <strong>Manufacturer:</strong>
                    <span>${escapeHtml(deviceInfo.manufacturerName)}</span>

                    <strong>Serial Number:</strong>
                    <span style="color: #28a745; font-weight: bold;">${escapeHtml(deviceInfo.serialNumber)}</span>

                    <strong>Vendor ID:</strong>
                    <span>0x${deviceInfo.vendorId}</span>

                    <strong>Product ID:</strong>
                    <span>0x${deviceInfo.productId}</span>
                </div>
            </div>

            <button type="button" class="btn btn-primary" onclick="fillFormFromDetectedDevice()" style="width: 100%;">
                ✨ Fill Form with This Device
            </button>
        </div>
    `;
}

// Safe onclick handler that reads from stored variable instead of inline-escaped strings
function fillFormFromDetectedDevice() {
    if (!_lastDetectedUSBDevice) return;
    fillFormWithUSBInfo(
        _lastDetectedUSBDevice.serialNumber || '',
        _lastDetectedUSBDevice.productName || '',
        _lastDetectedUSBDevice.manufacturerName || ''
    );
}

function copyPowerShellCommand() {
    const command = "Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber,Model";

    navigator.clipboard.writeText(command).then(() => {
        alert('✅ PowerShell command copied to clipboard!\n\n1. Press Win+X then A (open PowerShell as Admin)\n2. Paste the command (Ctrl+V) and press Enter\n3. Copy the SerialNumber from the output\n4. Paste it into the form');
    }).catch(() => {
        // Fallback if clipboard API fails
        prompt('Copy this PowerShell command:', command);
    });
}

function fillFormWithUSBInfo(serialNumber, productName, manufacturer, capacityGB) {
    // Clean serial number - strip null bytes and non-printable characters
    serialNumber = serialNumber ? serialNumber.replace(/[\x00-\x1F\x7F-\x9F]/g, '').trim() : '';
    // Fill the form fields
    document.getElementById('usb-serial-number').value = serialNumber;
    document.getElementById('usb-device-name').value = productName;
    document.getElementById('usb-manufacturer').value = manufacturer;
    document.getElementById('usb-model').value = productName;
    if (capacityGB && capacityGB !== 'Unknown') {
        document.getElementById('usb-capacity').value = capacityGB;
    }

    // Show success message
    const resultsDiv = document.getElementById('usb-detection-results');
    resultsDiv.innerHTML = `
        <div style="background: #d4edda; border: 1px solid #28a745; border-radius: 6px; padding: 12px; margin-top: 10px;">
            <p style="margin: 0; color: #155724; font-weight: bold;">
                ✓ ${LANG['usb.form_filled']}
            </p>
            <p style="margin: 5px 0 0 0; font-size: 13px; color: #155724;">
                ${LANG['usb.review_info']}
            </p>
        </div>
    `;

    // Scroll to form
    document.getElementById('registerUSBForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function detectUSBDevicesLocal() {
    const resultsDiv = document.getElementById('usb-detection-results');

    // Show instructions for local detection
    resultsDiv.innerHTML = `
        <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-top: 10px;">
            <h4 style="margin: 0 0 10px 0; color: #856404;">📋 Detect USB on This PC</h4>
            <p style="margin: 0 0 10px 0; color: #856404; font-size: 14px;">
                Run this PowerShell command to detect USB devices:
            </p>
            <div style="background: #2d2d2d; color: #f8f8f2; padding: 12px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 11px; overflow-x: auto; margin-bottom: 10px;">
                <code>Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber,Model,@{N='GB';E={[Math]::Round($_.Size/1GB,2)}} | FL</code>
            </div>
            <button type="button" class="btn btn-primary" onclick="copyPowerShellCommand()" style="width: 100%; margin-bottom: 10px;">
                📋 Copy PowerShell Command
            </button>
            <div style="background: #e7f3ff; border: 1px solid #2196f3; border-radius: 4px; padding: 10px; font-size: 13px;">
                <strong>Quick Steps:</strong><br>
                1️⃣ Click "Copy PowerShell Command" above<br>
                2️⃣ Press <kbd>Win+X</kbd> → <kbd>A</kbd> to open PowerShell<br>
                3️⃣ Paste (<kbd>Ctrl+V</kbd>) and press <kbd>Enter</kbd><br>
                4️⃣ Copy the <strong>SerialNumber</strong> from output<br>
                5️⃣ Paste into form below
            </div>
        </div>
    `;
}

function copyPowerShellCommand() {
    const command = "Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber,Model,@{N='GB';E={[Math]::Round($_.Size/1GB,2)}} | FL";

    navigator.clipboard.writeText(command).then(() => {
        alert('✅ Command copied!\n\nNow:\n1. Open PowerShell (Win+X → A)\n2. Paste and run\n3. Copy the SerialNumber');
    }).catch(() => {
        prompt('Copy this command:', command);
    });
}

function detectUSBDevices() {
    const resultsDiv = document.getElementById('usb-detection-results');
    resultsDiv.innerHTML = '<p style="text-align: center; color: #666;">🔍 Scanning for USB devices on server...</p>';

    fetch('api/detect-usb-devices.php')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.devices.length > 0) {
                let html = '<div style="margin-top: 15px;">';
                html += '<p style="color: #28a745; font-weight: bold; margin-bottom: 10px;">✓ Found ' + data.count + ' USB device(s):</p>';

                data.devices.forEach((device, index) => {
                    html += `
                        <div style="background: white; border: 2px solid #28a745; border-radius: 6px; padding: 12px; margin-bottom: 10px; cursor: pointer; transition: background 0.2s;"
                             onclick="fillUSBDeviceInfo(${index})"
                             onmouseover="this.style.background='#f0f8f0'"
                             onmouseout="this.style.background='white'">
                            <div style="font-weight: bold; color: #333; margin-bottom: 5px;">
                                📀 ${escapeHtml(device.suggested_name || device.model)}
                            </div>
                            <div style="font-size: 13px; color: #666;">
                                <div><strong>Serial:</strong> ${escapeHtml(device.serial_number)}</div>
                                <div><strong>Manufacturer:</strong> ${escapeHtml(device.manufacturer)}</div>
                                <div><strong>Model:</strong> ${escapeHtml(device.model)}</div>
                                <div><strong>Capacity:</strong> ${device.capacity_gb} GB</div>
                                ${device.drive_letter ? '<div><strong>Drive:</strong> ' + device.drive_letter + '</div>' : ''}
                                ${device.volume_name ? '<div><strong>Volume:</strong> ' + escapeHtml(device.volume_name) + '</div>' : ''}
                            </div>
                            <div style="margin-top: 8px; font-size: 12px; color: #2196f3;">
                                👆 Click to fill form with this device
                            </div>
                        </div>
                    `;

                    // Store device data for later use
                    if (!window.detectedUSBDevices) window.detectedUSBDevices = {};
                    window.detectedUSBDevices[index] = device;
                });

                html += '</div>';
                resultsDiv.innerHTML = html;
            } else if (data.success && data.devices.length === 0) {
                resultsDiv.innerHTML = `
                    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 12px; margin-top: 10px;">
                        <p style="margin: 0; color: #856404;">
                            ⚠️ No USB devices detected. Please ensure:
                        </p>
                        <ul style="margin: 10px 0 0 20px; color: #856404; font-size: 13px;">
                            <li>USB drive is physically connected</li>
                            <li>Device is recognized by Windows</li>
                            <li>You're running this on the admin PC (not server)</li>
                        </ul>
                    </div>
                `;
            } else {
                resultsDiv.innerHTML = `
                    <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px; margin-top: 10px;">
                        <p style="margin: 0; color: #721c24;">
                            ❌ Error detecting USB devices: ${data.error || 'Unknown error'}
                        </p>
                    </div>
                `;
            }
        })
        .catch(err => {
            console.error('USB detection error:', err);
            resultsDiv.innerHTML = `
                <div style="background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 6px; padding: 12px; margin-top: 10px;">
                    <p style="margin: 0; color: #721c24;">
                        ❌ Failed to detect USB devices. This feature requires PowerShell access on the admin PC.
                    </p>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #721c24;">
                        You can still manually enter device information below.
                    </p>
                </div>
            `;
        });
}

function fillUSBDeviceInfo(deviceIndex) {
    const device = window.detectedUSBDevices[deviceIndex];
    if (!device) return;

    // Fill form fields
    document.getElementById('usb-serial-number').value = device.serial_number;
    document.getElementById('usb-device-name').value = device.suggested_name || device.model;
    document.getElementById('usb-manufacturer').value = device.manufacturer;
    document.getElementById('usb-model').value = device.model;
    document.getElementById('usb-capacity').value = device.capacity_gb;

    // Add description with volume info
    let description = 'Auto-detected USB device';
    if (device.drive_letter) {
        description += ' (Drive: ' + device.drive_letter + ')';
    }
    if (device.volume_name) {
        description += ' - ' + device.volume_name;
    }
    document.getElementById('usb-description').value = description;

    // Show success feedback
    const resultsDiv = document.getElementById('usb-detection-results');
    resultsDiv.innerHTML = `
        <div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 6px; padding: 12px; margin-top: 10px;">
            <p style="margin: 0; color: #155724; font-weight: bold;">
                ✓ Form filled with device information
            </p>
            <p style="margin: 5px 0 0 0; font-size: 13px; color: #155724;">
                Review the information below and select a technician to register this device.
            </p>
        </div>
    `;

    // Scroll to form
    document.getElementById('registerUSBForm').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function updateUSBDeviceStatus(deviceId, newStatus) {
    let confirmMessage = '';
    let reason = null;

    switch (newStatus) {
        case 'disabled':
            confirmMessage = 'Disable this USB device? The technician will not be able to use it for authentication.';
            reason = prompt('Optional: Enter reason for disabling this device');
            if (reason === null) return; // User cancelled
            break;
        case 'lost':
            confirmMessage = 'Mark this USB device as LOST? This will disable authentication immediately.';
            reason = prompt('Optional: Enter details about when/where device was lost');
            if (reason === null) return;
            break;
        case 'stolen':
            confirmMessage = 'Mark this USB device as STOLEN? This will disable authentication immediately.';
            reason = prompt('Optional: Enter details about the theft');
            if (reason === null) return;
            break;
        case 'active':
            confirmMessage = 'Re-enable this USB device for authentication?';
            break;
    }

    if (!confirm(confirmMessage)) return;

    const data = {
        device_id: deviceId,
        status: newStatus,
        reason: reason
    };

    data.action = 'update_usb_device_status';
    securePost('', data)
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            loadUSBDevices();
        } else {
            alert('Error: ' + result.error);
        }
    })
    .catch(err => {
        console.error('Error updating USB device status:', err);
        alert('Failed to update USB device status');
    });
}

function deleteUSBDevice(deviceId, deviceName) {
    if (!confirm(`PERMANENTLY DELETE USB device "${deviceName}"?\n\nThis action cannot be undone.\n\nThis will remove all records of this device from the database.`)) {
        return;
    }

    const data = {
        device_id: deviceId
    };

    data.action = 'delete_usb_device';
    securePost('', data)
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert(result.message);
            loadUSBDevices();
        } else {
            alert('Error: ' + result.error);
        }
    })
    .catch(err => {
        console.error('Error deleting USB device:', err);
        alert('Failed to delete USB device');
    });
}

// ========================================
// 2FA MANAGEMENT FUNCTIONS
// ========================================

function load2FAStatus() {
    fetch('?action=get_2fa_status')
        .then(r => r.json())
        .then(data => {
            document.getElementById('2fa-loading').style.display = 'none';

            if (data.enabled) {
                document.getElementById('2fa-enabled-status').style.display = 'block';
                document.getElementById('2fa-disabled-status').style.display = 'none';
                document.getElementById('2fa-last-used').textContent = data.verified_at || LANG['usb.never_used'];
                document.getElementById('2fa-backup-count').textContent = data.backup_codes_remaining || '0';
            } else {
                document.getElementById('2fa-enabled-status').style.display = 'none';
                document.getElementById('2fa-disabled-status').style.display = 'block';
            }
        })
        .catch(err => {
            console.error('Error loading 2FA status:', err);
            document.getElementById('2fa-loading').textContent = 'Error loading 2FA status';
        });
}

function enable2FA() {
    // Call the TOTP setup API to get QR code and backup codes
    fetch('api/totp-setup.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error + (data.message ? '\n' + data.message : ''));
            return;
        }

        // Build the 2FA setup modal
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.style.cssText = 'display:flex; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center; overflow-y:auto;';
        modal.innerHTML = `
            <div style="background:white; border-radius:12px; padding:30px; max-width:550px; width:95%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <h2 style="margin:0; color:#2c3e50;">🔒 ${LANG['twofa.setup_modal']}</h2>
                    <button onclick="this.closest('.modal').remove()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#999;">&times;</button>
                </div>

                <div id="2fa-step1">
                    <h3 style="color:#2c3e50;">${LANG['twofa.qr_label']}</h3>
                    <p style="color:#666;">${LANG['twofa.qr_desc']}</p>
                    <div style="text-align:center; background:#f8f9fa; padding:20px; border-radius:8px; margin:15px 0;">
                        ${data.qr_code_svg}
                    </div>

                    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:6px; padding:12px; margin:15px 0;">
                        <strong>${LANG['twofa.manual_entry']}</strong><br>
                        <code style="font-size:14px; background:#f8f9fa; padding:4px 8px; border-radius:4px; word-break:break-all; display:inline-block; margin-top:5px;">${data.secret}</code>
                        <button onclick="navigator.clipboard.writeText('${data.secret}').then(()=>this.textContent='${LANG['twofa.copied']}')" style="margin-left:8px; padding:2px 8px; border:1px solid #ccc; border-radius:4px; cursor:pointer; font-size:12px;">${LANG['twofa.copy_key']}</button>
                    </div>

                    <h3 style="color:#2c3e50; margin-top:20px;">${LANG['twofa.verify_title']}</h3>
                    <p style="color:#666;">${LANG['twofa.verify_desc']}</p>
                    <div style="display:flex; gap:10px; margin:15px 0;">
                        <input type="text" id="2fa-verify-code" placeholder="000000" maxlength="6"
                            style="flex:1; padding:12px; font-size:24px; text-align:center; letter-spacing:8px; border:2px solid #ddd; border-radius:8px; font-family:monospace;"
                            oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                        <button onclick="verify2FASetup(${data.admin_id || adminId})" class="btn btn-primary" style="padding:12px 24px; font-size:16px;">
                            ✅ ${LANG['twofa.verify_btn']}
                        </button>
                    </div>
                    <div id="2fa-verify-error" style="color:#dc3545; display:none; margin-top:10px;"></div>
                </div>

                <div id="2fa-step2" style="display:none;">
                    <div style="text-align:center; margin-bottom:20px;">
                        <span style="font-size:48px;">✅</span>
                        <h3 style="color:#28a745;">${LANG['twofa.success']}</h3>
                    </div>

                    <div style="background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:15px; margin:15px 0;">
                        <h4 style="margin-top:0; color:#856404;">⚠️ ${LANG['twofa.save_codes']}</h4>
                        <p style="color:#856404; font-size:13px;">${LANG['twofa.codes_desc']}</p>
                        <div style="background:white; padding:15px; border-radius:6px; font-family:monospace; display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            ${data.backup_codes.map((code, i) => `<div style="padding:4px 8px; background:#f8f9fa; border-radius:4px; text-align:center;">${i+1}. ${code}</div>`).join('')}
                        </div>
                        <button onclick="copy2FABackupCodes()" style="margin-top:10px; width:100%; padding:8px; border:1px solid #856404; background:white; color:#856404; border-radius:6px; cursor:pointer; font-weight:bold;">
                            📋 ${LANG['twofa.copy_codes']}
                        </button>
                    </div>

                    <button onclick="this.closest('.modal').remove(); load2FAStatus();" class="btn btn-primary" style="width:100%; padding:12px; font-size:16px; margin-top:15px;">
                        ${LANG['twofa.done']}
                    </button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Store backup codes for copy function
        window._2faBackupCodes = data.backup_codes;

        // Focus the code input
        setTimeout(() => document.getElementById('2fa-verify-code').focus(), 100);
    })
    .catch(err => {
        console.error('2FA setup error:', err);
        alert('Error setting up 2FA: ' + err.message);
    });
}

function verify2FASetup(adminId) {
    const code = document.getElementById('2fa-verify-code').value.trim();
    const errorDiv = document.getElementById('2fa-verify-error');

    if (code.length !== 6) {
        errorDiv.style.display = 'block';
        errorDiv.textContent = LANG['twofa.code_length'];
        return;
    }

    fetch('api/totp-verify.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            totp_code: code,
            admin_id: adminId,
            is_setup: true
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Show backup codes step
            document.getElementById('2fa-step1').style.display = 'none';
            document.getElementById('2fa-step2').style.display = 'block';
        } else {
            errorDiv.style.display = 'block';
            errorDiv.textContent = data.message || data.error || LANG['twofa.invalid_code'];
            document.getElementById('2fa-verify-code').value = '';
            document.getElementById('2fa-verify-code').focus();
        }
    })
    .catch(err => {
        errorDiv.style.display = 'block';
        errorDiv.textContent = 'Error: ' + err.message;
    });
}

function copy2FABackupCodes() {
    if (window._2faBackupCodes) {
        const text = window._2faBackupCodes.map((c, i) => (i+1) + '. ' + c).join('\n');
        navigator.clipboard.writeText(text).then(() => {
            event.target.textContent = '✅ Copied!';
            setTimeout(() => event.target.textContent = '📋 ' + LANG['twofa.copy_codes'], 2000);
        });
    }
}

function disable2FA() {
    if (!confirm(LANG['twofa.disable_confirm'])) {
        return;
    }
    alert('2FA disable will be implemented via the API endpoints. Please use the totp-disable.php API directly for now.');
    // TODO: Implement 2FA disable flow
}

function regenerateBackupCodes() {
    if (!confirm(LANG['twofa.regenerate_confirm'])) {
        return;
    }
    alert('Backup code regeneration will be implemented via the API endpoints. Please use the totp-regenerate-backup-codes.php API directly for now.');
    // TODO: Implement backup code regeneration flow
}

// ========================================
// TRUSTED NETWORKS FUNCTIONS
// ========================================

function loadTrustedNetworks() {
    document.getElementById('trusted-networks-loading').style.display = 'block';

    fetch('?action=list_trusted_networks')
        .then(r => r.json())
        .then(data => {
            document.getElementById('trusted-networks-loading').style.display = 'none';

            if (data.success) {
                renderTrustedNetworksTable(data.networks);
            } else {
                alert('Error loading trusted networks: ' + data.error);
            }
        })
        .catch(err => {
            console.error('Error loading trusted networks:', err);
            document.getElementById('trusted-networks-loading').style.display = 'none';
            alert('Failed to load trusted networks');
        });
}

function renderTrustedNetworksTable(networks) {
    let html = '<table class="data-table"><thead><tr>';
    html += '<th>' + LANG['network.name_col'] + '</th><th>' + LANG['network.ip_range'] + '</th><th>' + LANG['network.bypass_2fa'] + '</th><th>' + LANG['network.usb_auth'] + '</th><th>' + LANG['network.status'] + '</th><th>' + LANG['network.created'] + '</th><th>' + LANG['network.actions'] + '</th>';
    html += '</tr></thead><tbody>';

    if (networks.length === 0) {
        html += '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #999;">' + LANG['network.no_networks'] + '</td></tr>';
    } else {
        networks.forEach(network => {
            const statusBadge = network.is_active === '1' || network.is_active === 1
                ? '<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['network.active'] + '</span>'
                : '<span style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['network.inactive'] + '</span>';

            const bypass2FA = network.bypass_2fa === '1' || network.bypass_2fa === 1
                ? '<span style="color: green;">✅ ' + LANG['network.yes'] + '</span>'
                : '<span style="color: red;">❌ ' + LANG['network.no'] + '</span>';

            const allowUSB = network.allow_usb_auth === '1' || network.allow_usb_auth === 1
                ? '<span style="color: green;">✅ ' + LANG['network.yes'] + '</span>'
                : '<span style="color: red;">❌ ' + LANG['network.no'] + '</span>';

            html += `<tr>
                <td><strong>${escapeHtml(network.network_name)}</strong></td>
                <td><code>${network.ip_range}</code></td>
                <td>${bypass2FA}</td>
                <td>${allowUSB}</td>
                <td>${statusBadge}</td>
                <td>${new Date(network.created_at).toLocaleDateString()}</td>
                <td>
                    <button class="btn btn-danger" onclick="deleteTrustedNetwork(${network.id}, '${escapeHtml(network.network_name).replace(/'/g, "\\'")}')">${LANG['network.delete']}</button>
                </td>
            </tr>`;
        });
    }

    html += '</tbody></table>';
    document.getElementById('trusted-networks-table-container').innerHTML = html;
}

function showAddTrustedNetworkModal() {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.style.display = 'flex';
    modal.innerHTML = `
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">${LANG['network.add_modal']}</div>
            <form id="addTrustedNetworkForm" onsubmit="addTrustedNetwork(event); return false;">
                <div class="form-group">
                    <label><strong>${LANG['network.network_name']}</strong></label>
                    <input type="text" id="network-name" required placeholder="${LANG['network.network_placeholder']}" style="width: 100%; padding: 8px;">
                </div>
                <div class="form-group">
                    <label><strong>${LANG['network.ip_range_label']}</strong></label>
                    <input type="text" id="ip-range" required placeholder="${LANG['network.ip_placeholder']}" style="width: 100%; padding: 8px;">
                    <small style="color: #666;">${LANG['network.cidr_notation']}</small>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="bypass-2fa" checked>
                        <strong>${LANG['network.bypass_2fa_label']}</strong>
                    </label>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="allow-usb-auth" checked>
                        <strong>${LANG['network.allow_usb']}</strong>
                    </label>
                </div>
                <div class="form-group">
                    <label><strong>${LANG['network.description']}</strong></label>
                    <textarea id="network-description" rows="3" placeholder="${LANG['network.description_placeholder']}" style="width: 100%; padding: 8px;"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn" onclick="this.closest('.modal').remove()">${LANG['common.cancel']}</button>
                    <button type="submit" class="btn btn-primary">${LANG['network.add_network']}</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

function addTrustedNetwork(event) {
    event.preventDefault();

    const data = {
        network_name: document.getElementById('network-name').value,
        ip_range: document.getElementById('ip-range').value,
        bypass_2fa: document.getElementById('bypass-2fa').checked ? 1 : 0,
        allow_usb_auth: document.getElementById('allow-usb-auth').checked ? 1 : 0,
        description: document.getElementById('network-description').value || null
    };

    data.action = 'add_trusted_network';
    securePost('', data)
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            alert(LANG['network.add_success']);
            document.querySelector('.modal').remove();
            loadTrustedNetworks();
        } else {
            alert('Error: ' + result.error);
        }
    })
    .catch(err => {
        console.error('Error adding trusted network:', err);
        alert(LANG['network.add_error'] || 'Failed to add trusted network');
    });
}

function deleteTrustedNetwork(networkId, networkName) {
    if (!confirm(LANG['network.delete_confirm'].replace('%s', networkName))) {
        return;
    }

    securePost('', {action: 'delete_trusted_network', network_id: networkId})
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert(LANG['network.delete_success']);
            loadTrustedNetworks();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(err => {
        console.error('Error deleting trusted network:', err);
        alert(LANG['network.delete_error'] || 'Failed to delete trusted network');
    });
}

// ========================================
// BACKUP MANAGEMENT FUNCTIONS
// ========================================

function loadBackupHistory() {
    document.getElementById('backup-history-loading').style.display = 'block';

    fetch('?action=list_backups')
        .then(r => r.json())
        .then(data => {
            document.getElementById('backup-history-loading').style.display = 'none';

            if (data.success) {
                renderBackupHistoryTable(data.backups);
            } else {
                alert('Error loading backup history: ' + data.error);
            }
        })
        .catch(err => {
            console.error('Error loading backup history:', err);
            document.getElementById('backup-history-loading').style.display = 'none';
            alert(LANG['backup.load_error'] || 'Failed to load backup history');
        });
}

function renderBackupHistoryTable(backups) {
    let html = '<table class="data-table"><thead><tr>';
    html += '<th>' + LANG['backup.filename'] + '</th><th>' + LANG['backup.size_mb'] + '</th><th>' + LANG['backup.status_col'] + '</th><th>' + LANG['backup.duration'] + '</th><th>' + LANG['backup.tables'] + '</th><th>' + LANG['backup.type'] + '</th><th>' + LANG['backup.created'] + '</th>';
    html += '</tr></thead><tbody>';

    if (backups.length === 0) {
        html += '<tr><td colspan="7" style="text-align: center; padding: 20px; color: #999;">' + LANG['backup.no_backups'] + '</td></tr>';
    } else {
        backups.forEach(backup => {
            const statusBadge = backup.backup_status === 'success'
                ? '<span style="background: #28a745; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['backup.success_badge'] + '</span>'
                : '<span style="background: #dc3545; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['backup.failed_badge'] + '</span>';

            const typeBadge = backup.backup_type === 'manual'
                ? '<span style="background: #007bff; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['backup.manual_badge'] + '</span>'
                : '<span style="background: #6c757d; color: white; padding: 4px 8px; border-radius: 4px; font-size: 12px;">' + LANG['backup.scheduled_badge'] + '</span>';

            html += `<tr>
                <td><code>${backup.backup_filename}</code></td>
                <td>${backup.backup_size_mb || '0'}</td>
                <td>${statusBadge}</td>
                <td>${backup.backup_duration_seconds || '0'}s</td>
                <td>${backup.tables_count || '0'}</td>
                <td>${typeBadge}</td>
                <td>${new Date(backup.created_at).toLocaleString()}</td>
            </tr>`;
        });
    }

    html += '</tbody></table>';
    document.getElementById('backup-history-table-container').innerHTML = html;
}

function triggerManualBackup() {
    if (!confirm(LANG['backup.trigger_confirm'])) {
        return;
    }

    const btn = event.target;
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ ' + LANG['backup.running'];

    securePost('', {action: 'trigger_manual_backup'})
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.textContent = originalText;

        if (data.success) {
            alert(LANG['backup.success_msg'].replace('%s', data.message));
            loadBackupHistory();
        } else {
            alert(LANG['backup.failed_msg'].replace('%s', data.error));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.textContent = originalText;
        console.error('Error triggering backup:', err);
        alert('Error: ' + err.message);
    });
}

// ========================================
// PUSH NOTIFICATIONS
// ========================================

let notifDropdownOpen = false;

function toggleNotificationDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('notifDropdown');
    notifDropdownOpen = !notifDropdownOpen;
    dropdown.style.display = notifDropdownOpen ? 'block' : 'none';
    if (notifDropdownOpen) {
        loadNotifications();
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (notifDropdownOpen && !e.target.closest('.notification-bell')) {
        document.getElementById('notifDropdown').style.display = 'none';
        notifDropdownOpen = false;
    }
});

function loadNotifications() {
    secureGet('?action=get_notifications')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            updateNotifBadge(data.unread_count);
            renderNotifications(data.notifications || []);
        })
        .catch(err => console.warn('loadNotifications error:', err));
}

function updateNotifBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function renderNotifications(notifs) {
    const body = document.getElementById('notifDropdownBody');
    if (!notifs.length) {
        body.innerHTML = '<div class="notif-empty">' + (LANG['notif.no_notifications'] || 'No notifications') + '</div>';
        return;
    }
    body.innerHTML = notifs.map(n => {
        const catLabel = LANG['notif.cat.' + n.category] || n.category;
        const timeAgo = formatNotifTime(n.created_at);
        const unreadClass = n.is_read === '0' || n.is_read === 0 ? ' unread' : '';
        return '<div class="notif-item' + unreadClass + '" onclick="handleNotifClick(' + n.id + ',\'' + escapeHtml(n.action_url || '') + '\')">' +
            '<span class="notif-item-cat ' + escapeHtml(n.category) + '">' + escapeHtml(catLabel) + '</span>' +
            '<div class="notif-item-body">' + escapeHtml(n.body || '') + '</div>' +
            '<div class="notif-item-time">' + escapeHtml(timeAgo) + '</div>' +
        '</div>';
    }).join('');
}

function formatNotifTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T') + 'Z');
    const now = new Date();
    const diffMs = now - d;
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return LANG['notif.just_now'] || 'Just now';
    if (diffMin < 60) return diffMin + (LANG['notif.min_ago'] || 'm ago');
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return diffHr + (LANG['notif.hr_ago'] || 'h ago');
    const diffDay = Math.floor(diffHr / 24);
    return diffDay + (LANG['notif.day_ago'] || 'd ago');
}

function handleNotifClick(id, url) {
    // Mark as read
    securePost('?action=mark_notifications_read', { ids: [id] })
        .then(r => r.json())
        .then(() => loadNotifications())
        .catch(() => {});
    // Navigate via hash
    if (url) {
        const hash = url.split('#')[1];
        if (hash) {
            const btn = document.querySelector('.tab-button[data-tab="' + hash + '"]');
            if (btn) btn.click();
        }
    }
    document.getElementById('notifDropdown').style.display = 'none';
    notifDropdownOpen = false;
}

function markAllRead(e) {
    e.preventDefault();
    e.stopPropagation();
    securePost('?action=mark_notifications_read', { ids: null })
        .then(r => r.json())
        .then(() => loadNotifications())
        .catch(() => {});
}

function switchToNotifTab(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('notifDropdown').style.display = 'none';
    notifDropdownOpen = false;
    const btn = document.querySelector('.tab-button[data-tab="notifications"]');
    if (btn) btn.click();
}

// Service Worker & Push Subscription
// Detect push notification platform capabilities (Phase 8B: iOS PWA support)
function detectPushPlatform() {
    const ua = navigator.userAgent;
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    const isIOSSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
    const hasPushAPI = 'serviceWorker' in navigator && 'PushManager' in window;

    if (!isIOS) {
        return hasPushAPI ? 'supported' : 'unsupported';
    }
    if (!isIOSSafari) {
        return 'ios_wrong_browser';
    }
    if (!isStandalone) {
        return 'ios_not_installed';
    }
    return hasPushAPI ? 'supported' : 'ios_old_version';
}

function registerServiceWorker() {
    if (detectPushPlatform() !== 'supported') {
        return;
    }
    navigator.serviceWorker.register('sw.js', { scope: './' })
        .then(reg => {
            // Check existing subscription
            return reg.pushManager.getSubscription().then(sub => {
                updatePushStatus(!!sub);
            });
        })
        .catch(err => {
            console.warn('SW registration failed:', err);
        });
}

function togglePushSubscription() {
    const platform = detectPushPlatform();
    if (platform !== 'supported') {
        const msgs = {
            unsupported: LANG['notif.push_not_supported'] || 'Push notifications are not supported in this browser.',
            ios_wrong_browser: LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.',
            ios_not_installed: LANG['notif.ios_not_installed'] || 'To receive push notifications on iOS, install this app first by tapping Share > Add to Home Screen.',
            ios_old_version: LANG['notif.ios_old_version'] || 'Push notifications require iOS 16.4 or later. Please update your device.'
        };
        alert(msgs[platform] || msgs.unsupported);
        return;
    }

    navigator.serviceWorker.ready.then(reg => {
        reg.pushManager.getSubscription().then(sub => {
            if (sub) {
                // Unsubscribe
                sub.unsubscribe().then(() => {
                    securePost('?action=push_unsubscribe', { endpoint: sub.endpoint })
                        .then(() => updatePushStatus(false));
                });
            } else {
                // Subscribe
                const vapidKey = APP_CONFIG.vapidPublicKey;
                if (!vapidKey) {
                    // Fetch VAPID key first
                    secureGet('?action=push_get_vapid_key')
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.vapidPublicKey) {
                                subscribePush(reg, data.vapidPublicKey);
                            }
                        });
                } else {
                    subscribePush(reg, vapidKey);
                }
            }
        });
    });
}

function subscribePush(reg, vapidKey) {
    const applicationServerKey = urlBase64ToUint8Array(vapidKey);
    reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: applicationServerKey
    }).then(sub => {
        const rawKey = sub.getKey('p256dh');
        const rawAuth = sub.getKey('auth');
        securePost('?action=push_subscribe', {
            endpoint: sub.endpoint,
            p256dh: rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))) : '',
            auth: rawAuth ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuth))) : ''
        }).then(() => updatePushStatus(true));
    }).catch(err => {
        console.warn('Push subscription failed:', err);
        if (Notification.permission === 'denied') {
            alert(LANG['notif.push_denied'] || 'Notification permission was denied. Please enable it in browser settings.');
        }
    });
}

function updatePushStatus(subscribed) {
    const btn = document.getElementById('pushToggleBtn');
    const statusText = document.getElementById('pushStatusText');
    if (!btn) return;
    btn.disabled = false;

    if (subscribed) {
        btn.textContent = LANG['notif.disable_push'] || 'Disable Push Notifications';
        btn.className = 'btn btn-danger';
        if (statusText) statusText.textContent = LANG['notif.push_active'] || 'Push notifications are active.';
    } else {
        btn.textContent = LANG['notif.enable_push'] || 'Enable Push Notifications';
        btn.className = 'btn btn-primary';
        if (statusText) statusText.textContent = LANG['notif.push_inactive'] || 'Push notifications are disabled.';
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function loadPushPreferences() {
    secureGet('?action=get_push_preferences')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            updatePushStatus(data.subscribed);

            // Platform-aware push status (Phase 8B)
            const platform = detectPushPlatform();
            const statusText = document.getElementById('pushStatusText');
            const btn = document.getElementById('pushToggleBtn');
            const iosGuide = document.getElementById('iosInstallGuide');

            if (platform !== 'supported') {
                const statusMsgs = {
                    unsupported: LANG['notif.push_not_supported'] || 'Push notifications are not supported in this browser.',
                    ios_wrong_browser: LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.',
                    ios_not_installed: LANG['notif.ios_not_installed'] || 'To receive push notifications on iOS, install this app first:',
                    ios_old_version: LANG['notif.ios_old_version'] || 'Push notifications require iOS 16.4 or later. Please update your device.'
                };
                if (statusText) statusText.textContent = statusMsgs[platform] || statusMsgs.unsupported;
                if (btn) btn.disabled = true;

                // Show iOS install guide for relevant platforms
                if (iosGuide) {
                    if (platform === 'ios_not_installed') {
                        iosGuide.style.display = 'block';
                        iosGuide.innerHTML =
                            '<div class="ios-install-steps">' +
                            '<div class="ios-step"><span class="ios-step-num">1</span> ' + (LANG['notif.ios_install_step1'] || 'Tap the Share button (\u2399) in Safari') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">2</span> ' + (LANG['notif.ios_install_step2'] || 'Tap "Add to Home Screen"') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">3</span> ' + (LANG['notif.ios_install_step3'] || 'Open the app from your Home Screen') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">4</span> ' + (LANG['notif.ios_install_step4'] || 'Enable push notifications') + '</div>' +
                            '</div>';
                    } else if (platform === 'ios_wrong_browser') {
                        iosGuide.style.display = 'block';
                        iosGuide.innerHTML =
                            '<div class="ios-install-steps">' +
                            '<div class="ios-step">' + (LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.') + '</div>' +
                            '</div>';
                    } else {
                        iosGuide.style.display = 'none';
                    }
                }
            } else {
                if (iosGuide) iosGuide.style.display = 'none';
            }

            const container = document.getElementById('notifCategoryToggles');
            if (!container) return;
            const categories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];
            container.innerHTML = categories.map(cat => {
                const label = LANG['notif.cat.' + cat] || cat;
                const checked = data.preferences[cat] !== false ? 'checked' : '';
                return '<div class="notif-category-row">' +
                    '<input type="checkbox" id="notif-cat-' + cat + '" data-category="' + cat + '" ' + checked + '>' +
                    '<label for="notif-cat-' + cat + '">' + escapeHtml(label) + '</label>' +
                '</div>';
            }).join('');
        })
        .catch(err => console.warn('loadPushPreferences error:', err));
}

function savePushPreferences() {
    const categories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];
    const prefs = {};
    categories.forEach(cat => {
        const cb = document.getElementById('notif-cat-' + cat);
        prefs[cat] = cb ? cb.checked : true;
    });
    securePost('?action=save_push_preferences', { preferences: prefs })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(LANG['common.saved'] || 'Saved!');
            }
        })
        .catch(err => console.warn('savePushPreferences error:', err));
}

function sendTestPush() {
    const btn = document.getElementById('testPushBtn');
    const status = document.getElementById('testNotifStatus');
    if (btn) btn.disabled = true;
    if (status) { status.style.display = 'block'; status.textContent = LANG['notif.test_sending'] || 'Sending...'; }

    securePost('?action=send_test_notification', { type: 'push' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (status) status.textContent = LANG['notif.test_push_sent'] || 'Test push notification sent! Check your browser notifications.';
                loadNotifications();
            } else {
                if (status) status.textContent = (LANG['notif.test_failed'] || 'Test failed: ') + (data.error || 'Unknown error');
            }
        })
        .catch(err => {
            if (status) status.textContent = (LANG['notif.test_failed'] || 'Test failed: ') + err.message;
        })
        .finally(() => { if (btn) btn.disabled = false; });
}

function testNotificationSound() {
    const btn = document.getElementById('testSoundBtn');
    const status = document.getElementById('testNotifStatus');
    if (btn) btn.disabled = true;
    if (status) { status.style.display = 'block'; status.textContent = LANG['notif.test_playing'] || 'Playing notification sound...'; }

    // Generate a notification-style tone using Web Audio API
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();

        // Two-tone chime: rising pair of notes
        function playTone(freq, startTime, duration) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.3, startTime);
            gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(startTime);
            osc.stop(startTime + duration);
        }

        const now = ctx.currentTime;
        playTone(587.33, now, 0.2);       // D5
        playTone(880, now + 0.15, 0.3);   // A5

        // Also create a bell notification entry
        securePost('?action=send_test_notification', { type: 'sound' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (status) status.textContent = LANG['notif.test_sound_played'] || 'Notification sound played! Bell notification created.';
                    loadNotifications();
                }
            })
            .catch(() => {});

        setTimeout(() => { ctx.close(); if (btn) btn.disabled = false; }, 1000);
    } catch (e) {
        if (status) status.textContent = (LANG['notif.test_sound_error'] || 'Could not play sound: ') + e.message;
        if (btn) btn.disabled = false;
    }
}

// Poll for notification badge updates every 30 seconds
setInterval(() => {
    secureGet('?action=get_notifications')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateNotifBadge(data.unread_count);
            }
        })
        .catch(() => {});
}, 30000);

// Initial notification load + service worker registration
(function initNotifications() {
    loadNotifications();
    if (APP_CONFIG.pushEnabled) {
        registerServiceWorker();
    }
})();

// ========================================
// TAB SWITCHING WITH AUTO-LOAD
// ========================================

// Search on Enter key
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('key-search').addEventListener('keypress', e => {
        if (e.key === 'Enter') loadKeys();
    });
    document.getElementById('tech-search').addEventListener('keypress', e => {
        if (e.key === 'Enter') loadTechnicians();
    });
    document.getElementById('history-search').addEventListener('keypress', e => {
        if (e.key === 'Enter') loadHistory();
    });
    document.getElementById('logs-search').addEventListener('keypress', e => {
        if (e.key === 'Enter') loadLogs();
    });

    // Add tab switching event listeners for new tabs
    document.querySelectorAll('.tab-button').forEach(button => {
        button.addEventListener('click', function() {
            const tab = this.getAttribute('data-tab');

            // Load data when switching to new tabs
            if (tab === '2fa-settings') {
                load2FAStatus();
            } else if (tab === 'trusted-networks') {
                loadTrustedNetworks();
            } else if (tab === 'backups') {
                loadBackupHistory();
            } else if (tab === 'notifications') {
                loadPushPreferences();
            }
        });
    });
});
