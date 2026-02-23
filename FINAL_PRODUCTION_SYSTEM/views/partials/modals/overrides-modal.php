    <!-- User Overrides Modal -->
    <div id="overridesModal" class="modal">
        <div class="modal-content" style="max-width:750px;max-height:85vh;overflow-y:auto;">
            <div class="modal-header" id="overridesModalTitle"><?= __('roles.overrides_modal') ?></div>
            <input type="hidden" id="overrideUserType" value="">
            <input type="hidden" id="overrideUserId" value="">

            <div style="margin-bottom:15px;padding:10px;background:#e8f4fd;border-radius:6px;">
                <strong id="overrideUserName"><?= __('roles.user_role') ?></strong> &mdash; <?= __('roles.role_label') ?>: <span id="overrideUserRole">None</span>
            </div>

            <div id="overridesPermissionList" style="max-height:500px;overflow-y:auto;">
                <?= __('common.loading') ?>
            </div>

            <div class="modal-footer" style="margin-top:15px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('overridesModal')"><?= __('common.close') ?></button>
            </div>
        </div>
    </div>
