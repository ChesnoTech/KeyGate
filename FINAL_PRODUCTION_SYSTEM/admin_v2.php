<?php
// Admin Dashboard v2.0 - KeyGate
// Orchestrator: session, language, CSRF, action routing, view rendering

require_once 'security-headers.php';
require_once 'config.php';
require_once 'functions/acl.php';
require_once 'functions/network-utils.php';
require_once 'functions/i18n.php';
require_once 'functions/admin-helpers.php';
require_once 'functions/push-helpers.php';

// Start secure session
session_start();

// Support X-Admin-Token header for CI/API testing (bypasses PHP session persistence)
if (!empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
    $_SESSION['admin_token'] = $_SERVER['HTTP_X_ADMIN_TOKEN'];
    // Also inject CSRF token to avoid CSRF failures on state-changing requests
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Handle SPA session/CSRF checks (pre-auth — must work for unauthenticated users too)
$pre_auth_action = $_GET['action'] ?? '';
if ($pre_auth_action === 'check_session') {
    header('Content-Type: application/json');
    $admin_session = validateAdminSession();
    if ($admin_session) {
        // Load effective permissions via ACL
        require_once __DIR__ . '/functions/acl.php';
        $permsRaw = aclGetEffectivePermissions('admin', $admin_session['admin_id']);
        $perms = [];
        foreach ($permsRaw as $key => $info) {
            $perms[$key] = !empty($info['granted']);
        }
        echo json_encode([
            'authenticated' => true,
            'user' => [
                'id'             => (int) $admin_session['admin_id'],
                'username'       => $admin_session['username'],
                'full_name'      => $admin_session['full_name'],
                'role'           => $admin_session['role'],
                'preferred_language' => $admin_session['preferred_language'] ?? 'en',
            ],
            'permissions' => $perms,
            'csrf_token'  => $_SESSION['csrf_token'] ?? bin2hex(random_bytes(CSRF_TOKEN_BYTES)),
        ]);
    } else {
        echo json_encode(['authenticated' => false]);
    }
    exit;
}

if ($pre_auth_action === 'get_csrf') {
    header('Content-Type: application/json');
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    }
    echo json_encode(['csrf_token' => $_SESSION['csrf_token']]);
    exit;
}

// JSON login endpoint (replaces HTML-parsing login via secure-admin.php)
if ($pre_auth_action === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $json_login = json_decode(file_get_contents('php://input'), true);
    $username = trim($json_login['username'] ?? '');
    $password = $json_login['password'] ?? '';

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Username and password are required', 'error_code' => 'MISSING_CREDENTIALS']);
        exit;
    }

    $result = authenticateAdmin($username, $password);
    if ($result === false) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid credentials', 'error_code' => 'INVALID_CREDENTIALS']);
        exit;
    }
    if (is_array($result) && isset($result['error'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => $result['error'], 'error_code' => 'ACCOUNT_LOCKED']);
        exit;
    }

    // Login succeeded — build session response
    require_once __DIR__ . '/functions/acl.php';
    $permsRaw = aclGetEffectivePermissions('admin', $result['id']);
    $perms = [];
    foreach ($permsRaw as $key => $info) {
        $perms[$key] = !empty($info['granted']);
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'id'                 => (int) $result['id'],
            'username'           => $result['username'],
            'full_name'          => $result['full_name'],
            'role'               => $result['role'],
            'preferred_language' => $result['preferred_language'] ?? 'en',
        ],
        'permissions' => $perms,
        'csrf_token'  => $_SESSION['csrf_token'],
    ]);
    exit;
}

// Public branding — no auth required so login page can display custom branding
if ($pre_auth_action === 'get_public_branding') {
    header('Content-Type: application/json');
    $config = [
        'brand_company_name'   => getConfig('brand_company_name') ?? 'KeyGate',
        'brand_app_version'    => getConfig('brand_app_version') ?? 'System v2.0',
        'brand_logo_path'      => getConfig('brand_logo_path') ?? '',
        'brand_favicon_path'   => getConfig('brand_favicon_path') ?? '',
        'brand_login_title'    => getConfig('brand_login_title') ?? '',
        'brand_login_subtitle' => getConfig('brand_login_subtitle') ?? '',
        'brand_primary_color'  => getConfig('brand_primary_color') ?? '',
        'brand_sidebar_color'  => getConfig('brand_sidebar_color') ?? '',
        'brand_accent_color'   => getConfig('brand_accent_color') ?? '',
    ];
    echo json_encode(['success' => true, 'config' => $config]);
    exit;
}

// Validate session or redirect
$admin_session = validateAdminSession();
if (!$admin_session) {
    // For AJAX requests, return 401 JSON instead of redirect
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_GET['action']) || isset($_POST['action']))) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['authenticated' => false, 'error' => 'Session expired']);
        exit;
    }
    header('Location: /');
    exit;
}

// Language loading
$adminLang = $admin_session['preferred_language'] ?? getConfig('default_language') ?? 'en';
loadLanguage($adminLang);

// Handle language change request
if (isset($_POST['action']) && $_POST['action'] === 'change_language' && isset($_POST['language'])) {
    $newLang = preg_replace('/[^a-z]/', '', strtolower($_POST['language']));
    if (in_array($newLang, ['en', 'ru'])) {
        $stmt = $pdo->prepare("UPDATE `" . t('admin_users') . "` SET preferred_language = ? WHERE id = ?");
        $stmt->execute([$newLang, $admin_session['admin_id']]);
        loadLanguage($newLang);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// CSRF token management
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
}

// ── Action Registry ──────────────────────────────────────────
// Each entry: 'action_name' => [controller_file, handler_function, requires_csrf, accepts_json]
// Controller files are loaded lazily — only the needed controller is included.

$action_registry = [
    // dashboard
    'get_stats'            => ['DashboardController.php',      'handle_get_stats',                false, false],
    'generate_report'      => ['DashboardController.php',      'handle_generate_report',          false, false],
    'download_report'      => ['DashboardController.php',      'handle_download_report',          false, false],

    // keys
    'list_keys'            => ['KeysController.php',           'handle_list_keys',                false, false],
    'recycle_key'          => ['KeysController.php',           'handle_recycle_key',              true,  false],
    'delete_key'           => ['KeysController.php',           'handle_delete_key',               true,  false],
    'import_keys'          => ['KeysController.php',           'handle_import_keys',              true,  false],
    'export_keys'          => ['KeysController.php',           'handle_export_keys',              false, false],
    'add_keys'             => ['KeysController.php',           'handle_add_keys',                 true,  true],

    // technicians
    'list_techs'           => ['TechniciansController.php',    'handle_list_techs',               false, false],
    'list_technicians'     => ['TechniciansController.php',    'handle_list_technicians',         false, false],
    'add_tech'             => ['TechniciansController.php',    'handle_add_tech',                 true,  false],
    'edit_tech'            => ['TechniciansController.php',    'handle_edit_tech',                false, false],
    'get_tech'             => ['TechniciansController.php',    'handle_get_tech',                 false, false],
    'update_tech'          => ['TechniciansController.php',    'handle_update_tech',              true,  true],
    'reset_password'       => ['TechniciansController.php',    'handle_reset_password',           true,  false],
    'toggle_tech'          => ['TechniciansController.php',    'handle_toggle_tech',              true,  false],
    'delete_tech'          => ['TechniciansController.php',    'handle_delete_tech',              true,  false],

    // history
    'list_history'         => ['HistoryController.php',        'handle_list_history',             false, false],
    'get_hardware'         => ['HistoryController.php',        'handle_get_hardware',             false, false],
    'get_hardware_by_order'=> ['HistoryController.php',        'handle_get_hardware_by_order',    false, false],

    // logs
    'list_logs'            => ['LogsController.php',           'handle_list_logs',                false, false],

    // settings
    'get_alt_server_settings'  => ['SettingsController.php',   'handle_get_alt_server_settings',  false, false],
    'save_alt_server_settings' => ['SettingsController.php',   'handle_save_alt_server_settings', true,  true],
    'get_order_field_settings' => ['SettingsController.php',   'handle_get_order_field_settings', false, false],
    'save_order_field_settings'=> ['SettingsController.php',   'handle_save_order_field_settings',true,  true],
    'get_session_settings'     => ['SettingsController.php',   'handle_get_session_settings',     false, false],
    'save_session_settings'    => ['SettingsController.php',   'handle_save_session_settings',    true,  true],
    'get_client_config_settings'  => ['SettingsController.php', 'handle_get_client_config_settings',  false, false],
    'save_client_config_settings' => ['SettingsController.php', 'handle_save_client_config_settings', true,  true],
    'get_language_settings'       => ['SettingsController.php', 'handle_get_language_settings',       false, false],
    'save_language_settings'      => ['SettingsController.php', 'handle_save_language_settings',      true,  true],

    // smtp / email
    'get_smtp_settings'        => ['SmtpController.php',      'handle_get_smtp_settings',        false, false],
    'save_smtp_settings'       => ['SmtpController.php',      'handle_save_smtp_settings',       true,  true],
    'test_smtp_connection'     => ['SmtpController.php',      'handle_test_smtp_connection',      true,  true],

    // usb devices
    'list_usb_devices'         => ['UsbDevicesController.php', 'handle_list_usb_devices',         false, false],
    'register_usb_device'      => ['UsbDevicesController.php', 'handle_register_usb_device',      true,  true],
    'update_usb_device_status' => ['UsbDevicesController.php', 'handle_update_usb_device_status', true,  true],
    'delete_usb_device'        => ['UsbDevicesController.php', 'handle_delete_usb_device',        true,  true],

    // 2fa & security
    'get_2fa_status'           => ['SecurityController.php',   'handle_get_2fa_status',           false, false],
    'list_trusted_networks'    => ['SecurityController.php',   'handle_list_trusted_networks',    false, false],
    'add_trusted_network'      => ['SecurityController.php',   'handle_add_trusted_network',      true,  true],
    'delete_trusted_network'   => ['SecurityController.php',   'handle_delete_trusted_network',   true,  true],

    // backups
    'list_backups'             => ['BackupsController.php',    'handle_list_backups',             false, false],
    'trigger_manual_backup'    => ['BackupsController.php',    'handle_trigger_manual_backup',    true,  false],

    // push notifications
    'push_get_vapid_key'       => ['NotificationsController.php', 'handle_push_get_vapid_key',   false, false],
    'push_subscribe'           => ['NotificationsController.php', 'handle_push_subscribe',       true,  true],
    'push_unsubscribe'         => ['NotificationsController.php', 'handle_push_unsubscribe',     true,  true],
    'get_push_preferences'     => ['NotificationsController.php', 'handle_get_push_preferences', false, false],
    'save_push_preferences'    => ['NotificationsController.php', 'handle_save_push_preferences',true,  true],
    'get_notifications'        => ['NotificationsController.php', 'handle_get_notifications',    false, false],
    'mark_notifications_read'  => ['NotificationsController.php', 'handle_mark_notifications_read', true, true],
    'send_test_notification'   => ['NotificationsController.php', 'handle_send_test_notification',  true, true],

    // client resources / downloads
    'list_client_resources'      => ['ClientResourcesController.php', 'handle_list_client_resources',      false, false],
    'upload_client_resource'     => ['ClientResourcesController.php', 'handle_upload_client_resource',     true,  false],
    'delete_client_resource'     => ['ClientResourcesController.php', 'handle_delete_client_resource',     true,  true],
    'download_client_resource'   => ['ClientResourcesController.php', 'handle_download_client_resource',   false, false],

    // acl / roles
    'acl_list_roles'           => ['AclController.php',        'handle_acl_list_roles',           false, false],
    'acl_get_role'             => ['AclController.php',        'handle_acl_get_role',             false, false],
    'acl_list_permissions'     => ['AclController.php',        'handle_acl_list_permissions',     false, false],
    'acl_create_role'          => ['AclController.php',        'handle_acl_create_role',          true,  true],
    'acl_update_role'          => ['AclController.php',        'handle_acl_update_role',          true,  true],
    'acl_delete_role'          => ['AclController.php',        'handle_acl_delete_role',          true,  false],
    'acl_clone_role'           => ['AclController.php',        'handle_acl_clone_role',           true,  true],
    'acl_get_user_effective'   => ['AclController.php',        'handle_acl_get_user_effective',   false, false],
    'acl_set_user_override'    => ['AclController.php',        'handle_acl_set_user_override',    true,  true],
    'acl_remove_user_override' => ['AclController.php',        'handle_acl_remove_user_override', true,  true],
    'acl_get_changelog'        => ['AclController.php',        'handle_acl_get_changelog',        false, false],

    // branding
    'get_branding'             => ['BrandingController.php',   'handle_get_branding',             false, false],
    'save_branding'            => ['BrandingController.php',   'handle_save_branding',            true,  true],
    'upload_brand_asset'       => ['BrandingController.php',   'handle_upload_brand_asset',       true,  false],
    'delete_brand_asset'       => ['BrandingController.php',   'handle_delete_brand_asset',       true,  true],

    // integrations
    'list_integrations'        => ['IntegrationController.php', 'handle_list_integrations',       false, false],
    'get_integration'          => ['IntegrationController.php', 'handle_get_integration',         false, false],
    'save_integration'         => ['IntegrationController.php', 'handle_save_integration',        true,  true],
    'test_integration'         => ['IntegrationController.php', 'handle_test_integration',        true,  true],
    'retry_integration_events' => ['IntegrationController.php', 'handle_retry_integration_events',true,  true],

    // quality control / compliance
    'qc_get_settings'          => ['ComplianceController.php', 'handle_qc_get_settings',          false, true],
    'qc_save_settings'         => ['ComplianceController.php', 'handle_qc_save_settings',         true,  true],
    'qc_list_motherboards'     => ['ComplianceController.php', 'handle_qc_list_motherboards',     false, true],
    'qc_get_motherboard'       => ['ComplianceController.php', 'handle_qc_get_motherboard',       false, true],
    'qc_update_motherboard'    => ['ComplianceController.php', 'handle_qc_update_motherboard',    true,  true],
    'qc_list_manufacturers'    => ['ComplianceController.php', 'handle_qc_list_manufacturers',    false, true],
    'qc_update_manufacturer'   => ['ComplianceController.php', 'handle_qc_update_manufacturer',   true,  true],
    'qc_list_compliance_results' => ['ComplianceController.php', 'handle_qc_list_compliance_results', false, true],
    'qc_list_compliance_grouped' => ['ComplianceController.php', 'handle_qc_list_compliance_grouped', false, true],
    'qc_get_stats'             => ['ComplianceController.php', 'handle_qc_get_stats',             false, true],
    'qc_recheck_count'         => ['ComplianceController.php', 'handle_qc_recheck_count',         false, true],
    'qc_recheck_historical'    => ['ComplianceController.php', 'handle_qc_recheck_historical',    true,  true],

    // product lines & variants (partition QC)
    'get_product_lines'        => ['ProductVariantsController.php', 'handle_get_product_lines',        false, true],
    'get_product_line'         => ['ProductVariantsController.php', 'handle_get_product_line',         false, true],
    'save_product_line'        => ['ProductVariantsController.php', 'handle_save_product_line',        true,  true],
    'delete_product_line'      => ['ProductVariantsController.php', 'handle_delete_product_line',      true,  true],
    'save_product_variant'     => ['ProductVariantsController.php', 'handle_save_product_variant',     true,  true],
    'delete_product_variant'   => ['ProductVariantsController.php', 'handle_delete_product_variant',   true,  true],

    // production tracking & enterprise key management
    'list_build_reports'           => ['ProductionController.php', 'handle_list_build_reports',           false, true],
    'get_build_report'             => ['ProductionController.php', 'handle_get_build_report',             false, true],
    'export_build_report'          => ['ProductionController.php', 'handle_export_build_report',          false, true],
    'update_build_report_shipping' => ['ProductionController.php', 'handle_update_build_report_shipping', true,  true],
    'get_key_pool_status'          => ['ProductionController.php', 'handle_get_key_pool_status',          false, true],
    'save_key_pool_config'         => ['ProductionController.php', 'handle_save_key_pool_config',         true,  true],
    'check_hardware_binding'       => ['ProductionController.php', 'handle_check_hardware_binding',       false, true],
    'release_hardware_binding'     => ['ProductionController.php', 'handle_release_hardware_binding',     true,  true],
    'import_dpk_batch'             => ['ProductionController.php', 'handle_import_dpk_batch',             true,  false],
    'list_dpk_batches'             => ['ProductionController.php', 'handle_list_dpk_batches',             false, true],
    'list_work_orders'             => ['ProductionController.php', 'handle_list_work_orders',             false, true],
    'save_work_order'              => ['ProductionController.php', 'handle_save_work_order',              true,  true],
    'get_work_order'               => ['ProductionController.php', 'handle_get_work_order',               false, true],
    'delete_work_order'            => ['ProductionController.php', 'handle_delete_work_order',            true,  true],

    // task pipeline
    'list_task_templates'      => ['TaskPipelineController.php', 'handle_list_task_templates',      false, true],
    'save_task_template'       => ['TaskPipelineController.php', 'handle_save_task_template',       true,  true],
    'delete_task_template'     => ['TaskPipelineController.php', 'handle_delete_task_template',     true,  true],
    'get_product_line_tasks'   => ['TaskPipelineController.php', 'handle_get_product_line_tasks',   false, true],
    'save_product_line_tasks'  => ['TaskPipelineController.php', 'handle_save_product_line_tasks',  true,  true],
    'list_task_executions'     => ['TaskPipelineController.php', 'handle_list_task_executions',     false, true],
    'get_activation_pipeline'  => ['TaskPipelineController.php', 'handle_get_activation_pipeline',  false, true],
    'log_task_execution'       => ['TaskPipelineController.php', 'handle_log_task_execution',       true,  true],

    // licensing
    'license_status'           => ['LicenseController.php', 'handle_license_status',         false, true],
    'license_register'         => ['LicenseController.php', 'handle_license_register',       true,  true],
    'license_deactivate'       => ['LicenseController.php', 'handle_license_deactivate',     true,  true],
    'license_generate_dev'     => ['LicenseController.php', 'handle_license_generate_dev',   true,  true],
    'license_claim'            => ['LicenseController.php', 'handle_license_claim',          true,  true],
    'license_migrate'          => ['LicenseController.php', 'handle_license_migrate',        true,  true],
    'license_redetect_hw'      => ['LicenseController.php', 'handle_license_redetect_hw',    true,  true],
    'license_rebind'           => ['LicenseController.php', 'handle_license_rebind',         true,  true],

    // system upgrade
    'upgrade_check_github'     => ['UpgradeController.php', 'handle_upgrade_check_github',     false, true],
    'upgrade_download_github'  => ['UpgradeController.php', 'handle_upgrade_download_github',  true,  true],
    'upgrade_get_status'       => ['UpgradeController.php', 'handle_upgrade_get_status',       false, true],
    'upgrade_upload_package'   => ['UpgradeController.php', 'handle_upgrade_upload_package',   true,  false],
    'upgrade_preflight'        => ['UpgradeController.php', 'handle_upgrade_preflight',        true,  true],
    'upgrade_backup'           => ['UpgradeController.php', 'handle_upgrade_backup',           true,  true],
    'upgrade_apply'            => ['UpgradeController.php', 'handle_upgrade_apply',            true,  true],
    'upgrade_verify'           => ['UpgradeController.php', 'handle_upgrade_verify',           true,  true],
    'upgrade_rollback'         => ['UpgradeController.php', 'handle_upgrade_rollback',         true,  true],
    'upgrade_history'          => ['UpgradeController.php', 'handle_upgrade_history',          false, true],
];

// ── Action Dispatcher ────────────────────────────────────────
$json_input = json_decode(file_get_contents('php://input'), true);
if (isset($_GET['action']) || isset($_POST['action']) || isset($json_input['action'])) {
    $action = $_GET['action'] ?? $_POST['action'] ?? $json_input['action'] ?? '';

    // File-streaming actions set their own Content-Type; everything else is JSON
    $file_download_actions = ['download_report', 'download_client_resource', 'export_build_report'];
    if (!in_array($action, $file_download_actions, true)) {
        header('Content-Type: application/json');
    }

    if (!isset($action_registry[$action])) {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    [$controller_file, $handler_fn, $requires_csrf, $accepts_json] = $action_registry[$action];

    // CSRF validation for state-changing actions
    // Skip CSRF when X-Admin-Token is used (the token itself authenticates the request)
    if ($requires_csrf && empty($_SERVER['HTTP_X_ADMIN_TOKEN'])) {
        $csrf = $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $json_input['csrf_token']
            ?? $_POST['csrf_token']
            ?? $_GET['csrf_token']
            ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'CSRF token invalid or missing']);
            exit;
        }
    }

    try {
        require_once __DIR__ . '/controllers/admin/' . $controller_file;

        if ($accepts_json) {
            $handler_fn($pdo, $admin_session, $json_input);
        } else {
            $handler_fn($pdo, $admin_session);
        }
    } catch (Exception $e) {
        error_log("Admin action error ($action): " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'An internal server error occurred. Please try again or contact support.']);
    }

    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_token'])) {
        $stmt = $pdo->prepare("UPDATE `" . t('admin_sessions') . "` SET is_active = 0 WHERE session_token = ?");
        $stmt->execute([$_SESSION['admin_token']]);
    }
    session_destroy();
    // Return JSON for API calls, redirect for browser
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Logged out']);
    } else {
        header('Location: /');
    }
    exit;
}

// Render the admin interface
include __DIR__ . '/views/layout.php';
