            <div id="technicians" class="tab-content">
                <div class="toolbar">
                    <input type="text" class="search-box" id="tech-search" placeholder="<?= __('tech.search_placeholder') ?>">
                    <button class="btn btn-primary" onclick="loadTechnicians()"><?= __('tech.search') ?></button>
                    <?php if ($admin_session['role'] !== 'viewer'): ?>
                    <button class="btn btn-primary" onclick="showAddTechModal()"><?= __('tech.add') ?></button>
                    <?php endif; ?>
                </div>

                <div id="techs-table"></div>
                <div id="techs-pagination" class="pagination"></div>
            </div>
