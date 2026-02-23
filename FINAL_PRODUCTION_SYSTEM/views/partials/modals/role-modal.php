    <!-- Role Edit/Create Modal -->
    <div id="roleModal" class="modal">
        <div class="modal-content" style="max-width:700px;max-height:85vh;overflow-y:auto;">
            <div class="modal-header" id="roleModalTitle"><?= __('roles.create_role') ?></div>
            <form id="roleForm" onsubmit="return saveRole(event)">
                <input type="hidden" id="roleEditId" value="">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label><?= __('roles.role_name') ?></label>
                        <input type="text" id="roleFormName" required pattern="[a-z0-9_]+" placeholder="<?= __('roles.role_placeholder') ?>" title="<?= __('roles.role_title') ?>">
                    </div>
                    <div class="form-group">
                        <label><?= __('roles.display_name') ?></label>
                        <input type="text" id="roleFormDisplayName" required placeholder="<?= __('roles.display_placeholder') ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label><?= __('roles.description') ?></label>
                    <input type="text" id="roleFormDescription" placeholder="<?= __('roles.description_placeholder') ?>">
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                    <div class="form-group">
                        <label><?= __('roles.type_label') ?></label>
                        <select id="roleFormType" required>
                            <option value="admin"><?= __('roles.admin_role') ?></option>
                            <option value="technician"><?= __('roles.technician_role') ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?= __('roles.color') ?></label>
                        <input type="color" id="roleFormColor" value="#6c757d" style="height:38px;width:100%;">
                    </div>
                </div>

                <h3 style="margin:20px 0 10px;border-top:1px solid #eee;padding-top:15px;"><?= __('roles.permissions') ?></h3>
                <div style="margin-bottom:10px;">
                    <button type="button" class="btn btn-sm" onclick="toggleAllPermissions(true)"><?= __('roles.select_all') ?></button>
                    <button type="button" class="btn btn-sm" onclick="toggleAllPermissions(false)" style="margin-left:5px;"><?= __('roles.deselect_all') ?></button>
                </div>
                <div id="rolePermissionsContainer" style="max-height:350px;overflow-y:auto;border:1px solid #e9ecef;border-radius:8px;padding:10px;">
                    <?= __('roles.loading') ?>
                </div>

                <div class="modal-footer" style="margin-top:15px;display:flex;justify-content:flex-end;gap:10px;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('roleModal')"><?= __('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary" id="roleFormSubmitBtn"><?= __('roles.create_button') ?></button>
                </div>
            </form>
        </div>
    </div>
