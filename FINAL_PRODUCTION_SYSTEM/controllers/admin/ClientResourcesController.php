<?php
/**
 * Client Resources Controller
 * Manages uploaded client resources (launcher, PS7 installer, Chrome extension, etc.)
 * with ACL permission enforcement.
 */

/**
 * Upload a client resource file.
 * Permission: manage_downloads
 */
function handle_upload_client_resource(PDO $pdo, array $admin_session): void {
    requirePermission('manage_downloads', $admin_session);

    $adminId = (int)$admin_session['admin_id'];
    $resourceKey = $_POST['resource_key'] ?? '';

    if (empty($resourceKey) || !preg_match('/^[a-z0-9_]+$/', $resourceKey)) {
        jsonResponse(['success' => false, 'error' => 'Invalid resource key']);
        return;
    }

    if (!isset($_FILES['resource_file']) || $_FILES['resource_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['resource_file']['error'] ?? -1;
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        ];
        jsonResponse(['success' => false, 'error' => $errMap[$errCode] ?? 'Upload failed (code: ' . $errCode . ')']);
        return;
    }

    $file = $_FILES['resource_file'];
    $originalName = basename($file['name']);
    $fileSize = $file['size'];
    $tmpPath = $file['tmp_name'];

    // Validate file extension
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['msi', 'exe', 'zip', 'cmd', 'bat', 'ps1', 'txt', 'crx'];
    if (!in_array($ext, $allowedExtensions)) {
        jsonResponse(['success' => false, 'error' => 'Allowed file types: ' . implode(', ', $allowedExtensions)]);
        return;
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    $allowedMimes = [
        'application/x-msi', 'application/x-ole-storage', 'application/octet-stream',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program',
        'application/zip', 'application/x-zip-compressed',
        'application/x-chrome-extension',
        'text/plain', 'text/x-msdos-batch', 'text/x-shellscript',
    ];
    if (!in_array($mimeType, $allowedMimes)) {
        // Many file types report as application/octet-stream — allow it
        $mimeType = 'application/octet-stream';
    }

    // Compute SHA256 checksum
    $checksum = hash_file('sha256', $tmpPath);

    // Create upload directory
    $uploadDir = dirname(__DIR__, 2) . '/uploads/client-resources';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0750, true);
    }

    // Generate UUID-style filename
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $uploadDir . '/' . $storedFilename;

    // Delete existing resource with same key (replace mode)
    $existing = $pdo->prepare("SELECT filename FROM client_resources WHERE resource_key = ?");
    $existing->execute([$resourceKey]);
    $oldFile = $existing->fetchColumn();
    if ($oldFile) {
        $oldPath = $uploadDir . '/' . $oldFile;
        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
        $pdo->prepare("DELETE FROM client_resources WHERE resource_key = ?")->execute([$resourceKey]);
    }

    // Move uploaded file
    if (!move_uploaded_file($tmpPath, $destPath)) {
        jsonResponse(['success' => false, 'error' => 'Failed to store uploaded file']);
        return;
    }

    // Auto-generate description based on resource key
    $descriptionMap = [
        'ps7_installer'     => 'PowerShell 7 MSI Installer',
        'oem_activator_cmd' => 'OEM Activator Launcher (CMD)',
        'chrome_hw_bridge'  => 'Chrome Hardware Bridge Extension',
    ];
    $description = $descriptionMap[$resourceKey] ?? $originalName;

    // Insert DB record
    $stmt = $pdo->prepare("
        INSERT INTO client_resources (resource_key, filename, original_filename, file_size, mime_type, checksum_sha256, description, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$resourceKey, $storedFilename, $originalName, $fileSize, $mimeType, $checksum, $description, $adminId]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'UPLOAD_CLIENT_RESOURCE',
        "Uploaded client resource: $resourceKey ($originalName, " . round($fileSize / 1024) . " KB)"
    );

    jsonResponse([
        'success' => true,
        'resource' => [
            'resource_key' => $resourceKey,
            'original_filename' => $originalName,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
            'uploaded_by_name' => $admin_session['username'] ?? 'admin',
            'created_at' => date('Y-m-d H:i:s'),
        ]
    ]);
}

/**
 * Delete a client resource by key.
 * Permission: manage_downloads
 */
function handle_delete_client_resource(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_downloads', $admin_session);

    $resourceKey = $json_input['resource_key'] ?? '';

    if (empty($resourceKey)) {
        jsonResponse(['success' => false, 'error' => 'Missing resource_key']);
        return;
    }

    $stmt = $pdo->prepare("SELECT filename FROM client_resources WHERE resource_key = ?");
    $stmt->execute([$resourceKey]);
    $filename = $stmt->fetchColumn();

    if (!$filename) {
        jsonResponse(['success' => false, 'error' => 'Resource not found']);
        return;
    }

    // Delete file
    $uploadDir = dirname(__DIR__, 2) . '/uploads/client-resources';
    $filePath = $uploadDir . '/' . $filename;
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // Delete DB record
    $pdo->prepare("DELETE FROM client_resources WHERE resource_key = ?")->execute([$resourceKey]);

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DELETE_CLIENT_RESOURCE',
        "Deleted client resource: $resourceKey"
    );

    jsonResponse(['success' => true]);
}

/**
 * List all client resources.
 * Permission: view_downloads
 */
function handle_list_client_resources(PDO $pdo, array $admin_session): void {
    requirePermission('view_downloads', $admin_session);

    $stmt = $pdo->prepare("
        SELECT cr.*, au.username AS uploaded_by_name
        FROM client_resources cr
        LEFT JOIN admin_users au ON cr.uploaded_by = au.id
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute();
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse([
        'success' => true,
        'resources' => $resources,
    ]);
}

/**
 * Download a client resource (admin panel download via session auth).
 * Permission: view_downloads
 */
function handle_download_client_resource(PDO $pdo, array $admin_session): void {
    requirePermission('view_downloads', $admin_session);

    $resourceKey = $_GET['resource_key'] ?? '';

    if (empty($resourceKey) || !preg_match('/^[a-z0-9_]+$/', $resourceKey)) {
        http_response_code(400);
        jsonResponse(['success' => false, 'error' => 'Invalid resource key']);
        return;
    }

    $stmt = $pdo->prepare("SELECT filename, original_filename, mime_type, file_size, checksum_sha256 FROM client_resources WHERE resource_key = ?");
    $stmt->execute([$resourceKey]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resource) {
        http_response_code(404);
        jsonResponse(['success' => false, 'error' => 'Resource not found']);
        return;
    }

    $uploadDir = dirname(__DIR__, 2) . '/uploads/client-resources';
    $filePath = $uploadDir . '/' . $resource['filename'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        jsonResponse(['success' => false, 'error' => 'File not found on disk']);
        return;
    }

    // Sanitize filename for Content-Disposition header
    $safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $resource['original_filename']);

    header('Content-Type: ' . ($resource['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . $resource['file_size']);
    header('X-Checksum-SHA256: ' . $resource['checksum_sha256']);
    header('Cache-Control: no-store');

    readfile($filePath);
    exit;
}
