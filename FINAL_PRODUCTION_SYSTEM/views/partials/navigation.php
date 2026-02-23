            <div class="tab-buttons">
                <button class="tab-button active" data-tab="dashboard"><?= __('nav.dashboard') ?></button>
                <button class="tab-button" data-tab="keys"><?= __('nav.keys') ?></button>
                <button class="tab-button" data-tab="technicians"><?= __('nav.technicians') ?></button>
                <button class="tab-button" data-tab="usb-devices"><?= __('nav.usb_devices') ?></button>
                <button class="tab-button" data-tab="history"><?= __('nav.history') ?></button>
                <button class="tab-button" data-tab="logs"><?= __('nav.logs') ?></button>
                <button class="tab-button" data-tab="settings"><?= __('nav.settings') ?></button>
                <button class="tab-button" data-tab="notifications"><?= __('nav.notifications') ?></button>
                <?php if ($admin_session['role'] === 'super_admin' || $admin_session['role'] === 'admin'): ?>
                <button class="tab-button" data-tab="2fa-settings"><?= __('nav.2fa_settings') ?></button>
                <?php endif; ?>
                <?php if ($admin_session['role'] === 'super_admin'): ?>
                <button class="tab-button" data-tab="roles"><?= __('nav.roles') ?></button>
                <button class="tab-button" data-tab="trusted-networks"><?= __('nav.trusted_networks') ?></button>
                <button class="tab-button" data-tab="backups"><?= __('nav.backups') ?></button>
                <?php endif; ?>
            </div>
