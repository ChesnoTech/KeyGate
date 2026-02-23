    <!-- Edit Technician Modal -->
    <div id="editTechModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><?= __('tech.edit_modal') ?></div>
            <form id="editTechForm" onsubmit="return updateTechnician(event)">
                <input type="hidden" id="edit_tech_id" name="tech_id">
                <div class="form-group">
                    <label><?= __('tech.id_label') ?></label>
                    <input type="text" id="edit_technician_id" name="technician_id" readonly style="background: #f5f5f5;">
                </div>
                <div class="form-group">
                    <label><?= __('tech.name_label') ?></label>
                    <input type="text" id="edit_full_name" name="full_name" required>
                </div>
                <div class="form-group">
                    <label><?= __('tech.email_label') ?></label>
                    <input type="email" id="edit_email" name="email">
                </div>
                <div class="form-group">
                    <label for="edit_preferred_server"><?= __('tech.server_label') ?></label>
                    <select id="edit_preferred_server" name="preferred_server" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="oem"><?= __('tech.oem_primary') ?></option>
                        <option value="alternative"><?= __('tech.alt_backup') ?></option>
                    </select>
                    <small style="display: block; color: #666; margin-top: 5px;"><?= __('tech.server_desc') ?></small>
                </div>
                <div class="form-group">
                    <label for="edit_preferred_language"><?= __('tech.language_label') ?></label>
                    <select id="edit_preferred_language" name="preferred_language" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <?php foreach (getAvailableLanguages() as $code => $name): ?>
                        <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="edit_is_active" name="is_active">
                        <?= __('tech.is_active') ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('editTechModal')"><?= __('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= __('tech.update_button') ?></button>
                </div>
            </form>
        </div>
    </div>
