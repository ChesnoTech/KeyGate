/**
 * KeyGate - Admin Panel: Core Module
 * Shared globals, utilities, tab switching, and initialization
 */

// Config injected from PHP via window.APP_CONFIG
const { csrfToken, adminRole, lang: LANG, currentLang, adminId } = window.APP_CONFIG;
const L = (key) => LANG[key] || key;
const canModify = adminRole !== 'viewer';
const canDelete = adminRole === 'super_admin';

// =============================================
// Translation helpers for DB-sourced strings
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

// =============================================
// HTTP helpers
// =============================================

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

// =============================================
// Shared utilities
// =============================================

function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
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

// =============================================
// Tab switching
// =============================================

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

// =============================================
// DOMContentLoaded: search + tab auto-load
// =============================================

document.addEventListener('DOMContentLoaded', () => {
    // Load dashboard on page load (must be inside DOMContentLoaded
    // because loadDashboard() is defined in admin-misc.js which loads after this file)
    loadDashboard();
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
