            <!-- 2FA Settings Tab -->
            <div id="2fa-settings" class="tab-content">
                <h2><?= __('twofa.title') ?></h2>
                <p style="color: #666;"><?= __('twofa.desc') ?></p>

                <div id="2fa-status-container" style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <div id="2fa-loading"><?= __('twofa.loading') ?></div>
                    <div id="2fa-enabled-status" style="display: none;">
                        <h3 style="color: green;"><?= __('twofa.enabled') ?></h3>
                        <p><?= __('twofa.enabled_desc') ?></p>
                        <p><strong><?= __('twofa.last_verified') ?></strong> <span id="2fa-last-used"><?= __('usb.never_used') ?></span></p>
                        <p><strong><?= __('twofa.backup_codes') ?></strong> <span id="2fa-backup-count">0</span></p>
                        <div style="margin-top: 15px;">
                            <button class="btn btn-primary" onclick="regenerateBackupCodes()"><?= __('twofa.regenerate') ?></button>
                            <button class="btn btn-danger" onclick="disable2FA()" style="margin-left: 10px;"><?= __('twofa.disable') ?></button>
                        </div>
                    </div>
                    <div id="2fa-disabled-status" style="display: none;">
                        <h3><?= __('twofa.disabled') ?></h3>
                        <p><?= __('twofa.disabled_desc') ?></p>
                        <button class="btn btn-primary btn-large" onclick="enable2FA()"><?= __('twofa.enable_now') ?></button>
                    </div>
                </div>

                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #17a2b8;">
                    <h4><?= __('twofa.about') ?></h4>
                    <ul style="margin: 10px 0;">
                        <li><?= __('twofa.requires_app') ?></li>
                        <li><?= __('twofa.extra_security') ?></li>
                        <li><?= __('twofa.backup_recovery') ?></li>
                        <li><?= __('twofa.network_bypass') ?></li>
                    </ul>
                </div>
            </div>
