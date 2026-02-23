    <!-- Key Reports Modal -->
    <div id="keyReportsModal" class="modal">
        <div class="modal-content" style="max-width: 900px;">
            <div class="modal-header">Key Usage Reports</div>
            <div style="margin: 20px 0;">
                <div class="toolbar" style="margin-bottom: 20px;">
                    <select id="report_type" style="padding: 8px; border-radius: 4px; border: 1px solid #ddd;">
                        <option value="summary">Summary Report</option>
                        <option value="usage">Usage Report</option>
                        <option value="failed">Failed Activations Report</option>
                        <option value="monthly">Monthly Statistics</option>
                    </select>
                    <button class="btn btn-primary" onclick="generateReport()">Generate Report</button>
                    <button class="btn btn-success" onclick="downloadReport()">Download PDF</button>
                </div>
                <div id="report-content" style="background: white; padding: 20px; border: 1px solid #ddd; border-radius: 4px; min-height: 400px;">
                    <div class="loading">Select a report type and click Generate Report</div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal('keyReportsModal')">Close</button>
            </div>
        </div>
    </div>
