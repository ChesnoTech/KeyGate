            <div id="history" class="tab-content">
                <div class="toolbar">
                    <input type="text" class="search-box" id="history-search" placeholder="<?= __('history.search_placeholder') ?>">
                    <select class="filter-select" id="history-filter">
                        <option value="all"><?= __('history.all_results') ?></option>
                        <option value="success"><?= __('history.success') ?></option>
                        <option value="failed"><?= __('history.failed') ?></option>
                    </select>
                    <button class="btn btn-primary" onclick="loadHistory()"><?= __('history.search') ?></button>
                </div>

                <div id="history-table"></div>
                <div id="history-pagination" class="pagination"></div>
            </div>
