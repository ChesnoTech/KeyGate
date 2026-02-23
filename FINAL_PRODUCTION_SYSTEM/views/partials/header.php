    <div class="header">
        <div style="float: right; display: flex; gap: 10px; align-items: center;">
            <select class="lang-select" onchange="changeLanguage(this.value)" style="background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); border-radius: 5px; padding: 8px 12px; font-size: 13px; cursor: pointer;">
                <?php foreach (getAvailableLanguages() as $code => $name): ?>
                <option value="<?= $code ?>" <?= $code === getCurrentLanguage() ? 'selected' : '' ?> style="color: #333;"><?= $name ?></option>
                <?php endforeach; ?>
            </select>
            <div class="notification-bell" id="notifBell" onclick="toggleNotificationDropdown(event)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                    <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span class="notification-badge" id="notifBadge" style="display:none;">0</span>
                <div class="notification-dropdown" id="notifDropdown" style="display:none;">
                    <div class="notif-dropdown-header">
                        <strong><?= __('notif.title') ?></strong>
                        <a href="#" onclick="markAllRead(event)"><?= __('notif.mark_all_read') ?></a>
                    </div>
                    <div class="notif-dropdown-body" id="notifDropdownBody">
                    </div>
                    <div class="notif-dropdown-footer">
                        <a href="#" onclick="switchToNotifTab(event)"><?= __('notif.preferences') ?></a>
                    </div>
                </div>
            </div>
            <button class="logout-btn" onclick="window.location.href='?logout=1'"><?= __('nav.logout') ?></button>
        </div>
        <h1><?= __('dashboard.title') ?></h1>
        <p><?= __('dashboard.welcome', htmlspecialchars($admin_session['full_name']), htmlspecialchars($admin_session['role'])) ?></p>
    </div>
