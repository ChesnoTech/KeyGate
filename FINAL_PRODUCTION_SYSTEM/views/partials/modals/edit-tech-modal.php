    <!-- Edit Technician Modal -->
    <div id="editTechModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><?= __('tech.edit_modal') ?></div>
            <form id="editTechForm" onsubmit="return updateTechnician(event)">
                <input type="hidden" id="edit_tech_id" name="tech_id">
                <div class="form-group">
                    <label><?= __('tech.id_label') ?></label>
                    <input type="text" id="edit_technician_id" name="technician_id" readonly class="input-readonly">
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
                    <select id="edit_preferred_server" name="preferred_server" class="form-select">
                        <option value="oem"><?= __('tech.oem_primary') ?></option>
                        <option value="alternative"><?= __('tech.alt_backup') ?></option>
                    </select>
                    <small class="form-hint"><?= __('tech.server_desc') ?></small>
                </div>
                <div class="form-group">
                    <label for="edit_preferred_language"><?= __('tech.language_label') ?></label>
                    <select id="edit_preferred_language" name="preferred_language" class="form-select">
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
