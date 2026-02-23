            <!-- Backups Tab (Super Admin Only) -->
            <div id="backups" class="tab-content">
                <h2><?= __('backup.title') ?></h2>
                <p style="color: #666;"><?= __('backup.desc') ?></p>

                <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <h3><?= __('backup.quick_actions') ?></h3>
                    <button class="btn btn-primary btn-large" onclick="triggerManualBackup()" style="margin-right: 10px;">
                        <?= __('backup.run_now') ?>
                    </button>
                    <button class="btn btn-secondary" onclick="loadBackupHistory()">
                        <?= __('backup.refresh') ?>
                    </button>
                </div>

                <div style="background: #d1ecf1; padding: 15px; border-radius: 8px; border-left: 4px solid #0c5460; margin-bottom: 20px;">
                    <h4><?= __('backup.configuration') ?></h4>
                    <p><?= __('backup.schedule') ?></p>
                    <p><?= __('backup.retention') ?></p>
                    <p><?= __('backup.location') ?></p>
                    <p><?= __('backup.compression') ?></p>
                </div>

                <h3><?= __('backup.history') ?></h3>
                <div id="backup-history-loading" style="padding: 20px; text-align: center;">
                    <?= __('backup.loading') ?>
                </div>
                <div id="backup-history-table-container"></div>
            </div>
