<?php
// Admin Dashboard v2.0 - OEM Activation System
// Orchestrator: session, language, CSRF, action routing, view rendering

require_once 'security-headers.php';
require_once 'config.php';
require_once 'functions/rbac.php';
require_once 'functions/network-utils.php';
require_once 'functions/i18n.php';
require_once 'functions/admin-helpers.php';
require_once 'functions/push-helpers.php';

// Start secure session
session_start();

// Validate session or redirect
$admin_session = validateAdminSession();
if (!$admin_session) {
    header('Location: secure-admin.php');
    exit;
}

// Language loading
$adminLang = $admin_session['preferred_language'] ?? getConfig('default_language') ?? 'en';
loadLanguage($adminLang);

// Handle language change request
if (isset($_POST['action']) && $_POST['action'] === 'change_language' && isset($_POST['language'])) {
    $newLang = preg_replace('/[^a-z]/', '', strtolower($_POST['language']));
    if (in_array($newLang, ['en', 'ru'])) {
        $stmt = $pdo->prepare("UPDATE admin_users SET preferred_language = ? WHERE id = ?");
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

// State-changing actions that require CSRF validation
$csrf_write_actions = [
    'acl_create_role', 'acl_update_role', 'acl_delete_role', 'acl_clone_role',
    'acl_set_user_override', 'acl_remove_user_override',
    'add_tech', 'update_tech', 'delete_tech', 'reset_password', 'toggle_tech',
    'recycle_key', 'delete_key', 'import_keys',
    'save_alt_server_settings',
    'add_trusted_network', 'delete_trusted_network',
    'register_usb_device', 'update_usb_device_status', 'delete_usb_device',
    'trigger_manual_backup',
    'push_subscribe', 'push_unsubscribe', 'save_push_preferences', 'mark_notifications_read',
    'send_test_notification',
    'upload_client_resource', 'delete_client_resource'
];

// Handle AJAX requests
$json_input = json_decode(file_get_contents('php://input'), true);
if (isset($_GET['action']) || isset($_POST['action']) || isset($json_input['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'] ?? $json_input['action'] ?? '';

    // CSRF validation for state-changing actions
    if (in_array($action, $csrf_write_actions)) {
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
        switch ($action) {
            // dashboard
            case 'get_stats':
                require_once __DIR__ . '/controllers/admin/DashboardController.php';
                handle_get_stats($pdo, $admin_session);
                break;
            case 'generate_report':
                require_once __DIR__ . '/controllers/admin/DashboardController.php';
                handle_generate_report($pdo, $admin_session);
                break;
            case 'download_report':
                require_once __DIR__ . '/controllers/admin/DashboardController.php';
                handle_download_report($pdo, $admin_session);
                break;

            // keys
            case 'list_keys':
                require_once __DIR__ . '/controllers/admin/KeysController.php';
                handle_list_keys($pdo, $admin_session);
                break;
            case 'recycle_key':
                require_once __DIR__ . '/controllers/admin/KeysController.php';
                handle_recycle_key($pdo, $admin_session);
                break;
            case 'delete_key':
                require_once __DIR__ . '/controllers/admin/KeysController.php';
                handle_delete_key($pdo, $admin_session);
                break;
            case 'import_keys':
                require_once __DIR__ . '/controllers/admin/KeysController.php';
                handle_import_keys($pdo, $admin_session);
                break;
            case 'export_keys':
                require_once __DIR__ . '/controllers/admin/KeysController.php';
                handle_export_keys($pdo, $admin_session);
                break;

            // technicians
            case 'list_techs':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_list_techs($pdo, $admin_session);
                break;
            case 'list_technicians':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_list_technicians($pdo, $admin_session);
                break;
            case 'add_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_add_tech($pdo, $admin_session);
                break;
            case 'edit_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_edit_tech($pdo, $admin_session);
                break;
            case 'get_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_get_tech($pdo, $admin_session);
                break;
            case 'update_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_update_tech($pdo, $admin_session, $json_input);
                break;
            case 'reset_password':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_reset_password($pdo, $admin_session);
                break;
            case 'toggle_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_toggle_tech($pdo, $admin_session);
                break;
            case 'delete_tech':
                require_once __DIR__ . '/controllers/admin/TechniciansController.php';
                handle_delete_tech($pdo, $admin_session);
                break;

            // history
            case 'list_history':
                require_once __DIR__ . '/controllers/admin/HistoryController.php';
                handle_list_history($pdo, $admin_session);
                break;
            case 'get_hardware':
                require_once __DIR__ . '/controllers/admin/HistoryController.php';
                handle_get_hardware($pdo, $admin_session);
                break;
            case 'get_hardware_by_order':
                require_once __DIR__ . '/controllers/admin/HistoryController.php';
                handle_get_hardware_by_order($pdo, $admin_session);
                break;

            // logs
            case 'list_logs':
                require_once __DIR__ . '/controllers/admin/LogsController.php';
                handle_list_logs($pdo, $admin_session);
                break;

            // settings
            case 'get_alt_server_settings':
                require_once __DIR__ . '/controllers/admin/SettingsController.php';
                handle_get_alt_server_settings($pdo, $admin_session);
                break;
            case 'save_alt_server_settings':
                require_once __DIR__ . '/controllers/admin/SettingsController.php';
                handle_save_alt_server_settings($pdo, $admin_session, $json_input);
                break;

            // usb devices
            case 'list_usb_devices':
                require_once __DIR__ . '/controllers/admin/UsbDevicesController.php';
                handle_list_usb_devices($pdo, $admin_session);
                break;
            case 'register_usb_device':
                require_once __DIR__ . '/controllers/admin/UsbDevicesController.php';
                handle_register_usb_device($pdo, $admin_session, $json_input);
                break;
            case 'update_usb_device_status':
                require_once __DIR__ . '/controllers/admin/UsbDevicesController.php';
                handle_update_usb_device_status($pdo, $admin_session, $json_input);
                break;
            case 'delete_usb_device':
                require_once __DIR__ . '/controllers/admin/UsbDevicesController.php';
                handle_delete_usb_device($pdo, $admin_session, $json_input);
                break;

            // 2fa & security
            case 'get_2fa_status':
                require_once __DIR__ . '/controllers/admin/SecurityController.php';
                handle_get_2fa_status($pdo, $admin_session);
                break;
            case 'list_trusted_networks':
                require_once __DIR__ . '/controllers/admin/SecurityController.php';
                handle_list_trusted_networks($pdo, $admin_session);
                break;
            case 'add_trusted_network':
                require_once __DIR__ . '/controllers/admin/SecurityController.php';
                handle_add_trusted_network($pdo, $admin_session, $json_input);
                break;
            case 'delete_trusted_network':
                require_once __DIR__ . '/controllers/admin/SecurityController.php';
                handle_delete_trusted_network($pdo, $admin_session, $json_input);
                break;

            // backups
            case 'list_backups':
                require_once __DIR__ . '/controllers/admin/BackupsController.php';
                handle_list_backups($pdo, $admin_session);
                break;
            case 'trigger_manual_backup':
                require_once __DIR__ . '/controllers/admin/BackupsController.php';
                handle_trigger_manual_backup($pdo, $admin_session);
                break;

            // push notifications
            case 'push_get_vapid_key':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_push_get_vapid_key($pdo, $admin_session);
                break;
            case 'push_subscribe':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_push_subscribe($pdo, $admin_session, $json_input);
                break;
            case 'push_unsubscribe':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_push_unsubscribe($pdo, $admin_session, $json_input);
                break;
            case 'get_push_preferences':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_get_push_preferences($pdo, $admin_session);
                break;
            case 'save_push_preferences':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_save_push_preferences($pdo, $admin_session, $json_input);
                break;
            case 'get_notifications':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_get_notifications($pdo, $admin_session);
                break;
            case 'mark_notifications_read':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_mark_notifications_read($pdo, $admin_session, $json_input);
                break;
            case 'send_test_notification':
                require_once __DIR__ . '/controllers/admin/NotificationsController.php';
                handle_send_test_notification($pdo, $admin_session, $json_input);
                break;

            // client resources (Phase 9: PS7 migration)
            case 'list_client_resources':
                require_once __DIR__ . '/controllers/admin/ClientResourcesController.php';
                handle_list_client_resources($pdo, $admin_session);
                break;
            case 'upload_client_resource':
                require_once __DIR__ . '/controllers/admin/ClientResourcesController.php';
                handle_upload_client_resource($pdo, $admin_session);
                break;
            case 'delete_client_resource':
                require_once __DIR__ . '/controllers/admin/ClientResourcesController.php';
                handle_delete_client_resource($pdo, $admin_session, $json_input);
                break;
            // acl / roles
            case 'acl_list_roles':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_list_roles($pdo, $admin_session);
                break;
            case 'acl_get_role':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_get_role($pdo, $admin_session);
                break;
            case 'acl_list_permissions':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_list_permissions($pdo, $admin_session);
                break;
            case 'acl_create_role':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_create_role($pdo, $admin_session, $json_input);
                break;
            case 'acl_update_role':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_update_role($pdo, $admin_session, $json_input);
                break;
            case 'acl_delete_role':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_delete_role($pdo, $admin_session);
                break;
            case 'acl_clone_role':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_clone_role($pdo, $admin_session, $json_input);
                break;
            case 'acl_get_user_effective':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_get_user_effective($pdo, $admin_session);
                break;
            case 'acl_set_user_override':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_set_user_override($pdo, $admin_session, $json_input);
                break;
            case 'acl_remove_user_override':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_remove_user_override($pdo, $admin_session, $json_input);
                break;
            case 'acl_get_changelog':
                require_once __DIR__ . '/controllers/admin/AclController.php';
                handle_acl_get_changelog($pdo, $admin_session);
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        error_log("Admin action error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'An error occurred: ' . $e->getMessage()]);
    }

    exit;
}

// Handle logout
if (isset($_GET['logout'])) {
    $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE session_token = ?");
    $stmt->execute([$_SESSION['admin_token']]);

    session_destroy();
    header('Location: secure-admin.php');
    exit;
}

// Render the admin interface
include __DIR__ . '/views/layout.php';
