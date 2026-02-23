            <div id="logs" class="tab-content">
                <div class="toolbar">
                    <input type="text" class="search-box" id="logs-search" placeholder="<?= __('logs.search_placeholder') ?>">
                    <button class="btn btn-primary" onclick="loadLogs()"><?= __('logs.search') ?></button>
                </div>

                <div id="logs-table"></div>
                <div id="logs-pagination" class="pagination"></div>
            </div>
