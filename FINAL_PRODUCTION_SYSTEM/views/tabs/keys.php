            <div id="keys" class="tab-content">
                <div class="toolbar">
                    <input type="text" class="search-box" id="key-search" placeholder="<?= __('keys.search_placeholder') ?>">
                    <select class="filter-select" id="key-filter">
                        <option value="all"><?= __('keys.all_keys') ?></option>
                        <option value="unused"><?= __('keys.status_unused') ?></option>
                        <option value="allocated"><?= __('keys.status_allocated') ?></option>
                        <option value="good"><?= __('keys.status_good') ?></option>
                        <option value="bad"><?= __('keys.status_bad') ?></option>
                        <option value="retry"><?= __('keys.status_retry') ?></option>
                    </select>
                    <button class="btn btn-primary" onclick="loadKeys()"><?= __('keys.search') ?></button>
                    <?php if ($admin_session['role'] !== 'viewer'): ?>
                    <button class="btn btn-success" onclick="showImportKeysModal()"><?= __('keys.import_csv') ?></button>
                    <?php endif; ?>
                    <button class="btn btn-secondary" onclick="exportKeysToCSV()"><?= __('keys.export_csv') ?></button>
                    <button class="btn btn-info" onclick="showAdvancedSearch()"><?= __('keys.advanced_search') ?></button>
                    <button class="btn btn-warning" onclick="showKeyReports()"><?= __('keys.reports') ?></button>
                </div>

                <div id="keys-table"></div>
                <div id="keys-pagination" class="pagination"></div>
            </div>
