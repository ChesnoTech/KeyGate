<?php
/**
 * Branding Controller — White-label customization
 * Manages company name, logo, favicon, and custom colors stored in system_config.
 */

function handle_get_branding(PDO $pdo, array $admin_session): void {
    $config = [
        'brand_company_name'  => getConfig('brand_company_name') ?? 'KeyGate',
        'brand_app_version'   => getConfig('brand_app_version') ?? 'System v2.0',
        'brand_logo_path'     => getConfig('brand_logo_path') ?? '',
        'brand_favicon_path'  => getConfig('brand_favicon_path') ?? '',
        'brand_login_title'   => getConfig('brand_login_title') ?? '',
        'brand_login_subtitle'=> getConfig('brand_login_subtitle') ?? '',
        'brand_primary_color' => getConfig('brand_primary_color') ?? '',
        'brand_sidebar_color' => getConfig('brand_sidebar_color') ?? '',
        'brand_accent_color'  => getConfig('brand_accent_color') ?? '',
    ];

    jsonResponse(['success' => true, 'config' => $config]);
}

function handle_save_branding(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);

    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input']);
        return;
    }

    $companyName = trim($json_input['brand_company_name'] ?? '');
    if ($companyName === '') {
        jsonResponse(['success' => false, 'error' => 'Company name cannot be empty']);
        return;
    }

    // Validate color values (hex format or empty)
    $colorKeys = ['brand_primary_color', 'brand_sidebar_color', 'brand_accent_color'];
    foreach ($colorKeys as $ck) {
        $val = trim($json_input[$ck] ?? '');
        if ($val !== '' && !preg_match('/^#[0-9a-fA-F]{3,8}$/', $val)) {
            jsonResponse(['success' => false, 'error' => "Invalid color format for $ck"]);
            return;
        }
    }

    $configs = [
        'brand_company_name'   => $companyName,
        'brand_app_version'    => trim($json_input['brand_app_version'] ?? 'System v2.0'),
        'brand_login_title'    => trim($json_input['brand_login_title'] ?? ''),
        'brand_login_subtitle' => trim($json_input['brand_login_subtitle'] ?? ''),
        'brand_primary_color'  => trim($json_input['brand_primary_color'] ?? ''),
        'brand_sidebar_color'  => trim($json_input['brand_sidebar_color'] ?? ''),
        'brand_accent_color'   => trim($json_input['brand_accent_color'] ?? ''),
    ];

    saveConfigBatch($pdo, $configs);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPDATE_BRANDING',
        "Updated branding settings (company: $companyName)"
    );

    // Return updated config
    jsonResponse(['success' => true, 'config' => $configs]);
}

function handle_upload_brand_asset(PDO $pdo, array $admin_session): void {
    requirePermission('system_settings', $admin_session);

    $assetType = $_POST['asset_type'] ?? '';
    if (!in_array($assetType, ['logo', 'favicon'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset type (must be logo or favicon)']);
        return;
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['file']['error'] ?? -1;
        jsonResponse(['success' => false, 'error' => getUploadErrorMessage($errCode)]);
        return;
    }

    $file = $_FILES['file'];
    $fileSize = $file['size'];
    $tmpPath  = $file['tmp_name'];
    $originalName = basename($file['name']);

    // Max 2MB
    if ($fileSize > 2 * 1024 * 1024) {
        jsonResponse(['success' => false, 'error' => 'File too large (max 2MB)']);
        return;
    }

    // Validate extension
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExts = ($assetType === 'favicon')
        ? ['png', 'ico', 'svg']
        : ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    if (!in_array($ext, $allowedExts)) {
        jsonResponse(['success' => false, 'error' => 'Unsupported file type. Allowed: ' . implode(', ', $allowedExts)]);
        return;
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    $allowedMimes = ['image/png', 'image/jpeg', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes)) {
        jsonResponse(['success' => false, 'error' => 'Invalid file content type']);
        return;
    }

    // Create upload directory
    $uploadDir = dirname(__DIR__, 2) . '/uploads/branding';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    // Delete old file if exists
    $configKey = ($assetType === 'logo') ? 'brand_logo_path' : 'brand_favicon_path';
    $oldPath = getConfig($configKey);
    if ($oldPath) {
        $oldFullPath = dirname(__DIR__, 2) . '/' . ltrim($oldPath, '/');
        if (file_exists($oldFullPath)) {
            unlink($oldFullPath);
        }
    }

    // Save with deterministic name: logo.{ext} or favicon.{ext}
    $storedFilename = $assetType . '.' . $ext;
    $destPath = $uploadDir . '/' . $storedFilename;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        jsonResponse(['success' => false, 'error' => 'Failed to save uploaded file']);
        return;
    }

    // Store relative path in system_config
    $relativePath = 'uploads/branding/' . $storedFilename;
    $stmt = $pdo->prepare("
        INSERT INTO system_config (config_key, config_value, description, updated_at)
        VALUES (?, ?, '', NOW())
        ON DUPLICATE KEY UPDATE config_value = ?, updated_at = NOW()
    ");
    $stmt->execute([$configKey, $relativePath, $relativePath]);

    // Clear config cache
    global $configCache;
    unset($configCache[$configKey]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPLOAD_BRAND_ASSET',
        "Uploaded branding $assetType: $originalName"
    );

    jsonResponse(['success' => true, 'path' => $relativePath]);
}

function handle_delete_brand_asset(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('system_settings', $admin_session);

    $assetType = $json_input['asset_type'] ?? '';
    if (!in_array($assetType, ['logo', 'favicon'])) {
        jsonResponse(['success' => false, 'error' => 'Invalid asset type']);
        return;
    }

    $configKey = ($assetType === 'logo') ? 'brand_logo_path' : 'brand_favicon_path';
    $currentPath = getConfig($configKey);

    if ($currentPath) {
        $fullPath = dirname(__DIR__, 2) . '/' . ltrim($currentPath, '/');
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    // Clear config value
    $stmt = $pdo->prepare("
        INSERT INTO system_config (config_key, config_value, description, updated_at)
        VALUES (?, '', '', NOW())
        ON DUPLICATE KEY UPDATE config_value = '', updated_at = NOW()
    ");
    $stmt->execute([$configKey]);

    global $configCache;
    unset($configCache[$configKey]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_BRAND_ASSET',
        "Deleted branding $assetType"
    );

    jsonResponse(['success' => true]);
}
