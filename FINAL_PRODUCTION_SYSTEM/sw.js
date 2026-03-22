/**
 * Service Worker for KeyGate Push Notifications
 * Handles push events and notification click actions.
 */

const TRANSLATIONS = {
    en: {
        'notif.title.security': 'Security Alert',
        'notif.title.keys': 'Key Management',
        'notif.title.technicians': 'Technician Update',
        'notif.title.system': 'System Event',
        'notif.title.devices': 'USB Device Update',
        'notif.title.activation': 'Activation Event',
    },
    ru: {
        'notif.title.security': '\u0423\u0432\u0435\u0434\u043e\u043c\u043b\u0435\u043d\u0438\u0435 \u0431\u0435\u0437\u043e\u043f\u0430\u0441\u043d\u043e\u0441\u0442\u0438',
        'notif.title.keys': '\u0423\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u0435 \u043a\u043b\u044e\u0447\u0430\u043c\u0438',
        'notif.title.technicians': '\u041e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435 \u0442\u0435\u0445\u043d\u0438\u043a\u0430',
        'notif.title.system': '\u0421\u0438\u0441\u0442\u0435\u043c\u043d\u043e\u0435 \u0441\u043e\u0431\u044b\u0442\u0438\u0435',
        'notif.title.devices': '\u041e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0435 USB',
        'notif.title.activation': '\u0421\u043e\u0431\u044b\u0442\u0438\u0435 \u0430\u043a\u0442\u0438\u0432\u0430\u0446\u0438\u0438',
    }
};

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', (event) => {
    if (!event.data) return;

    let data;
    try {
        data = event.data.json();
    } catch (e) {
        data = { titleKey: 'notif.title.system', body: event.data.text(), category: 'system', lang: 'en' };
    }

    const lang = data.lang || 'en';
    const titles = TRANSLATIONS[lang] || TRANSLATIONS.en;
    const title = titles[data.titleKey] || titles['notif.title.system'];

    const options = {
        body: data.body || '',
        icon: 'public/img/icon-192.png',
        badge: 'public/img/badge-72.png',
        tag: data.category || 'general',
        renotify: true,
        requireInteraction: data.category === 'security',
        data: {
            actionUrl: data.actionUrl || 'admin_v2.php',
        }
    };

    event.waitUntil(
        self.registration.showNotification(title, options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data?.actionUrl || 'admin_v2.php';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            // Focus existing admin tab if found
            for (const client of clients) {
                if (client.url.includes('admin_v2') && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            // Open new tab
            return self.clients.openWindow(url);
        })
    );
});
