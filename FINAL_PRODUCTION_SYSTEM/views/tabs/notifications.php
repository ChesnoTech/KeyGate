            <div id="notifications" class="tab-content">
                <h2><?= __('notif.pref_title') ?></h2>

                <div class="card" style="margin-bottom: 20px;">
                    <h3><?= __('notif.push_status') ?></h3>
                    <p id="pushStatusText" style="margin-bottom: 10px; color: #666;"></p>
                    <button id="pushToggleBtn" class="btn btn-primary" onclick="togglePushSubscription()" disabled>
                        <?= __('notif.enable_push') ?>
                    </button>
                    <div id="iosInstallGuide" style="display: none; margin-top: 15px;"></div>
                </div>

                <div class="card" style="margin-bottom: 20px;">
                    <h3><?= __('notif.test_title') ?></h3>
                    <p style="color: #666; margin-bottom: 15px;"><?= __('notif.test_desc') ?></p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button id="testPushBtn" class="btn btn-primary" onclick="sendTestPush()">
                            <?= __('notif.test_push') ?>
                        </button>
                        <button id="testSoundBtn" class="btn btn-primary" onclick="testNotificationSound()">
                            <?= __('notif.test_sound') ?>
                        </button>
                    </div>
                    <p id="testNotifStatus" style="margin-top: 10px; color: #666; display: none;"></p>
                </div>

                <div class="card">
                    <h3><?= __('notif.categories') ?></h3>
                    <p style="color: #666; margin-bottom: 15px;"><?= __('notif.categories_desc') ?></p>
                    <div id="notifCategoryToggles"></div>
                    <button class="btn btn-primary" onclick="savePushPreferences()" style="margin-top: 15px;">
                        <?= __('common.save') ?>
                    </button>
                </div>
            </div>
