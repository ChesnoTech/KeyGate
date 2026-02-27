    <!-- Key Reports Modal -->
    <div id="keyReportsModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header"><?= __('keys.reports_title') ?></div>
            <div style="margin: 20px 0;">
                <div class="toolbar" style="margin-bottom: 20px;">
                    <select id="report_type" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="summary"><?= __('keys.report_summary') ?></option>
                        <option value="usage"><?= __('keys.report_usage') ?></option>
                        <option value="failed"><?= __('keys.report_failed') ?></option>
                        <option value="monthly"><?= __('keys.report_monthly') ?></option>
                    </select>
                    <button class="btn btn-primary" onclick="generateReport()"><?= __('keys.report_generate') ?></button>
                    <button class="btn btn-success" onclick="downloadReport()"><?= __('keys.report_download') ?></button>
                </div>
                <div id="report-content" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 4px; min-height: 400px;">
                    <div class="loading"><?= __('keys.report_select') ?></div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('keyReportsModal')"><?= __('common.close') ?></button>
            </div>
        </div>
    </div>
