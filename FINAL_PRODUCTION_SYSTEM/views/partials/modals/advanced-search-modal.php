    <!-- Advanced Search Modal -->
    <div id="advancedSearchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">Advanced Key Search</div>
            <form id="advancedSearchForm" onsubmit="return performAdvancedSearch(event)">
                <div class="form-group">
                    <label>Product Key Pattern</label>
                    <input type="text" name="key_pattern" id="adv_key_pattern" placeholder="e.g., KEY01-*">
                </div>
                <div class="form-group">
                    <label>OEM ID Pattern</label>
                    <input type="text" name="oem_pattern" id="adv_oem_pattern" placeholder="e.g., TEST-OEM-*">
                </div>
                <div class="form-group">
                    <label>Roll Serial Pattern</label>
                    <input type="text" name="roll_pattern" id="adv_roll_pattern" placeholder="e.g., ROLL*">
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="adv_status">
                        <option value="">All Statuses</option>
                        <option value="unused">Unused</option>
                        <option value="allocated">Allocated</option>
                        <option value="good">Good</option>
                        <option value="bad">Bad</option>
                        <option value="retry">Retry</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Last Used - From</label>
                    <input type="date" name="date_from" id="adv_date_from">
                </div>
                <div class="form-group">
                    <label>Last Used - To</label>
                    <input type="date" name="date_to" id="adv_date_to">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('advancedSearchModal')">Cancel</button>
                    <button type="button" class="btn btn-warning" onclick="clearAdvancedSearch()">Clear</button>
                    <button type="submit" class="btn btn-primary">Search</button>
                </div>
            </form>
        </div>
    </div>
