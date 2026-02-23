    <!-- Add Technician Modal -->
    <div id="addTechModal" class="modal">
        <div class="modal-content">
            <div class="modal-header"><?= __('tech.add_modal') ?></div>
            <form id="addTechForm" onsubmit="return addTechnician(event)">
                <div class="form-group">
                    <label><?= __('tech.id_label') ?></label>
                    <input type="text" name="technician_id" required pattern="[A-Z0-9]{5}" maxlength="5" placeholder="TECH1">
                </div>
                <div class="form-group">
                    <label><?= __('tech.password_label') ?></label>
                    <input type="password" name="password" required minlength="8">
                </div>
                <div class="form-group">
                    <label><?= __('tech.name_label') ?></label>
                    <input type="text" name="full_name" required>
                </div>
                <div class="form-group">
                    <label><?= __('tech.email_label') ?></label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label for="add_preferred_server"><?= __('tech.server_label') ?></label>
                    <select id="add_preferred_server" name="preferred_server" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="oem" selected><?= __('tech.oem_primary') ?></option>
                        <option value="alternative"><?= __('tech.alt_backup') ?></option>
                    </select>
                    <small style="display: block; color: #666; margin-top: 5px;"><?= __('tech.server_desc') ?></small>
                </div>
                <div class="form-group">
                    <label for="add_preferred_language"><?= __('tech.language_label') ?></label>
                    <select id="add_preferred_language" name="preferred_language" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <?php foreach (getAvailableLanguages() as $code => $name): ?>
                        <option value="<?= $code ?>"<?= $code === 'en' ? ' selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" checked>
                        <?= __('tech.is_active') ?>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addTechModal')"><?= __('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= __('tech.create_button') ?></button>
                </div>
            </form>
        </div>
    </div>
