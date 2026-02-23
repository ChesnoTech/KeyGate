    <!-- Import Keys Modal -->
    <div id="importKeysModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Import Keys from CSV</div>
            <form id="importKeysForm" onsubmit="return importKeys(event)">
                <div class="form-group">
                    <label>CSV File*</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                    <small>Supported formats: Standard (Key, OEM ID, Status) or Comprehensive (with usage history)</small>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="update_existing" id="update_existing">
                        Update existing keys if found
                    </label>
                </div>
                <div id="import-progress" style="display: none; margin: 15px 0;">
                    <div style="background: #f0f0f0; border-radius: 4px; height: 20px; overflow: hidden;">
                        <div id="import-progress-bar" style="background: #28a745; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <div id="import-status" style="margin-top: 10px; font-size: 14px;"></div>
                </div>
                <div id="import-results" style="display: none; margin: 15px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;"></div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('importKeysModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="import-submit-btn">Import Keys</button>
                </div>
            </form>
        </div>
    </div>
