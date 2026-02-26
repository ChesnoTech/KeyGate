<!DOCTYPE html>
<html lang="<?= getCurrentLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="OEM Activate">
    <title><?= __('dashboard.title') ?></title>
    <link rel="stylesheet" href="public/css/admin.css?v=<?= filemtime(__DIR__ . '/../public/css/admin.css') ?>">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="public/img/apple-touch-icon.png">
</head>
<body>
    <?php include __DIR__ . '/partials/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <?php include __DIR__ . '/partials/navigation.php'; ?>

            <?php include __DIR__ . '/tabs/dashboard.php'; ?>
            <?php include __DIR__ . '/tabs/keys.php'; ?>
            <?php include __DIR__ . '/tabs/technicians.php'; ?>
            <?php include __DIR__ . '/tabs/history.php'; ?>
            <?php include __DIR__ . '/tabs/logs.php'; ?>
            <?php include __DIR__ . '/tabs/usb-devices.php'; ?>
            <?php include __DIR__ . '/tabs/settings.php'; ?>
            <?php include __DIR__ . '/tabs/notifications.php'; ?>
            <?php include __DIR__ . '/tabs/2fa-settings.php'; ?>
            <?php include __DIR__ . '/tabs/trusted-networks.php'; ?>
            <?php include __DIR__ . '/tabs/backups.php'; ?>
            <?php include __DIR__ . '/tabs/roles.php'; ?>
        </div>
    </div>

    <?php include __DIR__ . '/partials/modals/role-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/overrides-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/add-tech-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/edit-tech-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/import-keys-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/advanced-search-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/key-reports-modal.php'; ?>
    <?php include __DIR__ . '/partials/modals/hardware-modal.php'; ?>

    <script>
    window.APP_CONFIG = {
        csrfToken: '<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES) ?>',
        adminRole: '<?= htmlspecialchars($admin_session["role"], ENT_QUOTES) ?>',
        adminId: <?= (int)$admin_session["admin_id"] ?>,
        lang: <?= getLanguageJSON() ?>,
        currentLang: '<?= getCurrentLanguage() ?>',
        pushEnabled: <?= json_encode(getConfig('push_notifications_enabled') === '1') ?>,
        vapidPublicKey: '<?= htmlspecialchars(getConfig('vapid_public_key') ?: '', ENT_QUOTES) ?>'
    };
    </script>
    <script src="public/js/admin-core.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-core.js') ?>" charset="utf-8"></script>
    <script src="public/js/admin-keys.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-keys.js') ?>" charset="utf-8"></script>
    <script src="public/js/admin-techs.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-techs.js') ?>" charset="utf-8"></script>
    <script src="public/js/admin-acl.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-acl.js') ?>" charset="utf-8"></script>
    <script src="public/js/admin-misc.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-misc.js') ?>" charset="utf-8"></script>
    <script src="public/js/admin-notifications.js?v=<?= filemtime(__DIR__ . '/../public/js/admin-notifications.js') ?>" charset="utf-8"></script>
</body>
</html>
