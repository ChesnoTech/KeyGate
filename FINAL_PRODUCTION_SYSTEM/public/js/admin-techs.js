/**
 * OEM Activation System - Admin Panel: Technicians Module
 * Technician management: list, add, edit, toggle, reset password, delete
 */

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
