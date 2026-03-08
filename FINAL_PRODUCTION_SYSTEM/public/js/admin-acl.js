/**
 * OEM Activation System - Admin Panel: ACL Module
 * Roles & permissions management: CRUD, overrides, changelog
 */

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
                alert(LANG['js.error_prefix'] + (data.error || LANG['js.unknown_error']));
            }
        })
        .catch(err => alert(LANG['js.error_prefix'] + err.message));

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
                alert(LANG['js.error_prefix'] + (data.error || LANG['js.delete_failed']));
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
                alert(LANG['js.error_prefix'] + (data.error || LANG['js.clone_failed']));
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
