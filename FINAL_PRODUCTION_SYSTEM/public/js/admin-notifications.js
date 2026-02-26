/**
 * OEM Activation System - Admin Panel: Notifications Module
 * Push notifications: bell dropdown, service worker, subscription, preferences
 */

let notifDropdownOpen = false;

function toggleNotificationDropdown(e) {
    e.stopPropagation();
    const dropdown = document.getElementById('notifDropdown');
    notifDropdownOpen = !notifDropdownOpen;
    dropdown.style.display = notifDropdownOpen ? 'block' : 'none';
    if (notifDropdownOpen) {
        loadNotifications();
    }
}

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
    if (notifDropdownOpen && !e.target.closest('.notification-bell')) {
        document.getElementById('notifDropdown').style.display = 'none';
        notifDropdownOpen = false;
    }
});

function loadNotifications() {
    secureGet('?action=get_notifications')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            updateNotifBadge(data.unread_count);
            renderNotifications(data.notifications || []);
        })
        .catch(err => console.warn('loadNotifications error:', err));
}

function updateNotifBadge(count) {
    const badge = document.getElementById('notifBadge');
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function renderNotifications(notifs) {
    const body = document.getElementById('notifDropdownBody');
    if (!notifs.length) {
        body.innerHTML = '<div class="notif-empty">' + (LANG['notif.no_notifications'] || 'No notifications') + '</div>';
        return;
    }
    body.innerHTML = notifs.map(n => {
        const catLabel = LANG['notif.cat.' + n.category] || n.category;
        const timeAgo = formatNotifTime(n.created_at);
        const unreadClass = n.is_read === '0' || n.is_read === 0 ? ' unread' : '';
        return '<div class="notif-item' + unreadClass + '" onclick="handleNotifClick(' + n.id + ',\'' + escapeHtml(n.action_url || '') + '\')">' +
            '<span class="notif-item-cat ' + escapeHtml(n.category) + '">' + escapeHtml(catLabel) + '</span>' +
            '<div class="notif-item-body">' + escapeHtml(n.body || '') + '</div>' +
            '<div class="notif-item-time">' + escapeHtml(timeAgo) + '</div>' +
        '</div>';
    }).join('');
}

function formatNotifTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr.replace(' ', 'T') + 'Z');
    const now = new Date();
    const diffMs = now - d;
    const diffMin = Math.floor(diffMs / 60000);
    if (diffMin < 1) return LANG['notif.just_now'] || 'Just now';
    if (diffMin < 60) return diffMin + (LANG['notif.min_ago'] || 'm ago');
    const diffHr = Math.floor(diffMin / 60);
    if (diffHr < 24) return diffHr + (LANG['notif.hr_ago'] || 'h ago');
    const diffDay = Math.floor(diffHr / 24);
    return diffDay + (LANG['notif.day_ago'] || 'd ago');
}

function handleNotifClick(id, url) {
    // Mark as read
    securePost('?action=mark_notifications_read', { ids: [id] })
        .then(r => r.json())
        .then(() => loadNotifications())
        .catch(() => {});
    // Navigate via hash
    if (url) {
        const hash = url.split('#')[1];
        if (hash) {
            const btn = document.querySelector('.tab-button[data-tab="' + hash + '"]');
            if (btn) btn.click();
        }
    }
    document.getElementById('notifDropdown').style.display = 'none';
    notifDropdownOpen = false;
}

function markAllRead(e) {
    e.preventDefault();
    e.stopPropagation();
    securePost('?action=mark_notifications_read', { ids: null })
        .then(r => r.json())
        .then(() => loadNotifications())
        .catch(() => {});
}

function switchToNotifTab(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('notifDropdown').style.display = 'none';
    notifDropdownOpen = false;
    const btn = document.querySelector('.tab-button[data-tab="notifications"]');
    if (btn) btn.click();
}

// Service Worker & Push Subscription
// Detect push notification platform capabilities (Phase 8B: iOS PWA support)
function detectPushPlatform() {
    const ua = navigator.userAgent;
    const isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    const isIOSSafari = isIOS && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
    const hasPushAPI = 'serviceWorker' in navigator && 'PushManager' in window;

    if (!isIOS) {
        return hasPushAPI ? 'supported' : 'unsupported';
    }
    if (!isIOSSafari) {
        return 'ios_wrong_browser';
    }
    if (!isStandalone) {
        return 'ios_not_installed';
    }
    return hasPushAPI ? 'supported' : 'ios_old_version';
}

function registerServiceWorker() {
    if (detectPushPlatform() !== 'supported') {
        return;
    }
    navigator.serviceWorker.register('sw.js', { scope: './' })
        .then(reg => {
            // Check existing subscription
            return reg.pushManager.getSubscription().then(sub => {
                updatePushStatus(!!sub);
            });
        })
        .catch(err => {
            console.warn('SW registration failed:', err);
        });
}

function togglePushSubscription() {
    const platform = detectPushPlatform();
    if (platform !== 'supported') {
        const msgs = {
            unsupported: LANG['notif.push_not_supported'] || 'Push notifications are not supported in this browser.',
            ios_wrong_browser: LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.',
            ios_not_installed: LANG['notif.ios_not_installed'] || 'To receive push notifications on iOS, install this app first by tapping Share > Add to Home Screen.',
            ios_old_version: LANG['notif.ios_old_version'] || 'Push notifications require iOS 16.4 or later. Please update your device.'
        };
        alert(msgs[platform] || msgs.unsupported);
        return;
    }

    navigator.serviceWorker.ready.then(reg => {
        reg.pushManager.getSubscription().then(sub => {
            if (sub) {
                // Unsubscribe
                sub.unsubscribe().then(() => {
                    securePost('?action=push_unsubscribe', { endpoint: sub.endpoint })
                        .then(() => updatePushStatus(false));
                });
            } else {
                // Subscribe
                const vapidKey = APP_CONFIG.vapidPublicKey;
                if (!vapidKey) {
                    // Fetch VAPID key first
                    secureGet('?action=push_get_vapid_key')
                        .then(r => r.json())
                        .then(data => {
                            if (data.success && data.vapidPublicKey) {
                                subscribePush(reg, data.vapidPublicKey);
                            }
                        });
                } else {
                    subscribePush(reg, vapidKey);
                }
            }
        });
    });
}

function subscribePush(reg, vapidKey) {
    const applicationServerKey = urlBase64ToUint8Array(vapidKey);
    reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: applicationServerKey
    }).then(sub => {
        const rawKey = sub.getKey('p256dh');
        const rawAuth = sub.getKey('auth');
        securePost('?action=push_subscribe', {
            endpoint: sub.endpoint,
            p256dh: rawKey ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawKey))) : '',
            auth: rawAuth ? btoa(String.fromCharCode.apply(null, new Uint8Array(rawAuth))) : ''
        }).then(() => updatePushStatus(true));
    }).catch(err => {
        console.warn('Push subscription failed:', err);
        if (Notification.permission === 'denied') {
            alert(LANG['notif.push_denied'] || 'Notification permission was denied. Please enable it in browser settings.');
        }
    });
}

function updatePushStatus(subscribed) {
    const btn = document.getElementById('pushToggleBtn');
    const statusText = document.getElementById('pushStatusText');
    if (!btn) return;
    btn.disabled = false;

    if (subscribed) {
        btn.textContent = LANG['notif.disable_push'] || 'Disable Push Notifications';
        btn.className = 'btn btn-danger';
        if (statusText) statusText.textContent = LANG['notif.push_active'] || 'Push notifications are active.';
    } else {
        btn.textContent = LANG['notif.enable_push'] || 'Enable Push Notifications';
        btn.className = 'btn btn-primary';
        if (statusText) statusText.textContent = LANG['notif.push_inactive'] || 'Push notifications are disabled.';
    }
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

function loadPushPreferences() {
    secureGet('?action=get_push_preferences')
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            updatePushStatus(data.subscribed);

            // Platform-aware push status (Phase 8B)
            const platform = detectPushPlatform();
            const statusText = document.getElementById('pushStatusText');
            const btn = document.getElementById('pushToggleBtn');
            const iosGuide = document.getElementById('iosInstallGuide');

            if (platform !== 'supported') {
                const statusMsgs = {
                    unsupported: LANG['notif.push_not_supported'] || 'Push notifications are not supported in this browser.',
                    ios_wrong_browser: LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.',
                    ios_not_installed: LANG['notif.ios_not_installed'] || 'To receive push notifications on iOS, install this app first:',
                    ios_old_version: LANG['notif.ios_old_version'] || 'Push notifications require iOS 16.4 or later. Please update your device.'
                };
                if (statusText) statusText.textContent = statusMsgs[platform] || statusMsgs.unsupported;
                if (btn) btn.disabled = true;

                // Show iOS install guide for relevant platforms
                if (iosGuide) {
                    if (platform === 'ios_not_installed') {
                        iosGuide.style.display = 'block';
                        iosGuide.innerHTML =
                            '<div class="ios-install-steps">' +
                            '<div class="ios-step"><span class="ios-step-num">1</span> ' + (LANG['notif.ios_install_step1'] || 'Tap the Share button (\u2399) in Safari') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">2</span> ' + (LANG['notif.ios_install_step2'] || 'Tap "Add to Home Screen"') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">3</span> ' + (LANG['notif.ios_install_step3'] || 'Open the app from your Home Screen') + '</div>' +
                            '<div class="ios-step"><span class="ios-step-num">4</span> ' + (LANG['notif.ios_install_step4'] || 'Enable push notifications') + '</div>' +
                            '</div>';
                    } else if (platform === 'ios_wrong_browser') {
                        iosGuide.style.display = 'block';
                        iosGuide.innerHTML =
                            '<div class="ios-install-steps">' +
                            '<div class="ios-step">' + (LANG['notif.ios_wrong_browser'] || 'Push notifications on iOS require Safari. Open this page in Safari.') + '</div>' +
                            '</div>';
                    } else {
                        iosGuide.style.display = 'none';
                    }
                }
            } else {
                if (iosGuide) iosGuide.style.display = 'none';
            }

            const container = document.getElementById('notifCategoryToggles');
            if (!container) return;
            const categories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];
            container.innerHTML = categories.map(cat => {
                const label = LANG['notif.cat.' + cat] || cat;
                const checked = data.preferences[cat] !== false ? 'checked' : '';
                return '<div class="notif-category-row">' +
                    '<input type="checkbox" id="notif-cat-' + cat + '" data-category="' + cat + '" ' + checked + '>' +
                    '<label for="notif-cat-' + cat + '">' + escapeHtml(label) + '</label>' +
                '</div>';
            }).join('');
        })
        .catch(err => console.warn('loadPushPreferences error:', err));
}

function savePushPreferences() {
    const categories = ['security', 'keys', 'technicians', 'system', 'devices', 'activation'];
    const prefs = {};
    categories.forEach(cat => {
        const cb = document.getElementById('notif-cat-' + cat);
        prefs[cat] = cb ? cb.checked : true;
    });
    securePost('?action=save_push_preferences', { preferences: prefs })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                alert(LANG['common.saved'] || 'Saved!');
            }
        })
        .catch(err => console.warn('savePushPreferences error:', err));
}

function sendTestPush() {
    const btn = document.getElementById('testPushBtn');
    const status = document.getElementById('testNotifStatus');
    if (btn) btn.disabled = true;
    if (status) { status.style.display = 'block'; status.textContent = LANG['notif.test_sending'] || 'Sending...'; }

    securePost('?action=send_test_notification', { type: 'push' })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                if (status) status.textContent = LANG['notif.test_push_sent'] || 'Test push notification sent! Check your browser notifications.';
                loadNotifications();
            } else {
                if (status) status.textContent = (LANG['notif.test_failed'] || 'Test failed: ') + (data.error || 'Unknown error');
            }
        })
        .catch(err => {
            if (status) status.textContent = (LANG['notif.test_failed'] || 'Test failed: ') + err.message;
        })
        .finally(() => { if (btn) btn.disabled = false; });
}

function testNotificationSound() {
    const btn = document.getElementById('testSoundBtn');
    const status = document.getElementById('testNotifStatus');
    if (btn) btn.disabled = true;
    if (status) { status.style.display = 'block'; status.textContent = LANG['notif.test_playing'] || 'Playing notification sound...'; }

    // Generate a notification-style tone using Web Audio API
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();

        // Two-tone chime: rising pair of notes
        function playTone(freq, startTime, duration) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.3, startTime);
            gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start(startTime);
            osc.stop(startTime + duration);
        }

        const now = ctx.currentTime;
        playTone(587.33, now, 0.2);       // D5
        playTone(880, now + 0.15, 0.3);   // A5

        // Also create a bell notification entry
        securePost('?action=send_test_notification', { type: 'sound' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (status) status.textContent = LANG['notif.test_sound_played'] || 'Notification sound played! Bell notification created.';
                    loadNotifications();
                }
            })
            .catch(() => {});

        setTimeout(() => { ctx.close(); if (btn) btn.disabled = false; }, 1000);
    } catch (e) {
        if (status) status.textContent = (LANG['notif.test_sound_error'] || 'Could not play sound: ') + e.message;
        if (btn) btn.disabled = false;
    }
}

// Poll for notification badge updates every 30 seconds
setInterval(() => {
    secureGet('?action=get_notifications')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                updateNotifBadge(data.unread_count);
            }
        })
        .catch(() => {});
}, 30000);

// Initial notification load + service worker registration
(function initNotifications() {
    loadNotifications();
    if (APP_CONFIG.pushEnabled) {
        registerServiceWorker();
    }
})();
