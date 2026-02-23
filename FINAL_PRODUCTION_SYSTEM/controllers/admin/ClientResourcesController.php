<?php
/**
 * Client Resources Controller
 * Phase 9: Manages uploaded client resources (e.g., PowerShell 7 MSI installer)
 */

/**
 * Upload a client resource file (e.g., PS7 MSI installer).
 */
function handle_upload_client_resource(PDO $pdo, array $admin_session): void {
    $adminId = (int)$admin_session['admin_id'];
    $resourceKey = $_POST['resource_key'] ?? '';

    if (empty($resourceKey) || !preg_match('/^[a-z0-9_]+$/', $resourceKey)) {
        echo json_encode(['success' => false, 'error' => 'Invalid resource key']);
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
        echo json_encode(['success' => false, 'error' => $errMap[$errCode] ?? 'Upload failed (code: ' . $errCode . ')']);
        return;
    }

    $file = $_FILES['resource_file'];
    $originalName = basename($file['name']);
    $fileSize = $file['size'];
    $tmpPath = $file['tmp_name'];

    // Validate file extension (only MSI, EXE allowed)
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['msi', 'exe'])) {
        echo json_encode(['success' => false, 'error' => 'Only .msi and .exe files are allowed']);
        return;
    }

    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($tmpPath);
    $allowedMimes = [
        'application/x-msi', 'application/x-ole-storage', 'application/octet-stream',
        'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program'
    ];
    if (!in_array($mimeType, $allowedMimes)) {
        // MSI files often report as application/octet-stream or x-ole-storage — allow those
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
        echo json_encode(['success' => false, 'error' => 'Failed to store uploaded file']);
        return;
    }

    // Insert DB record
    $stmt = $pdo->prepare("
        INSERT INTO client_resources (resource_key, filename, original_filename, file_size, mime_type, checksum_sha256, description, uploaded_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $description = $resourceKey === 'ps7_installer' ? 'PowerShell 7 MSI Installer' : $originalName;
    $stmt->execute([$resourceKey, $storedFilename, $originalName, $fileSize, $mimeType, $checksum, $description, $adminId]);

    echo json_encode([
        'success' => true,
        'resource' => [
            'resource_key' => $resourceKey,
            'original_filename' => $originalName,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
            'uploaded_by' => $admin_session['username'] ?? 'admin',
            'created_at' => date('Y-m-d H:i:s'),
        ]
    ]);
}

/**
 * Delete a client resource by key.
 */
function handle_delete_client_resource(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    $resourceKey = $json_input['resource_key'] ?? '';

    if (empty($resourceKey)) {
        echo json_encode(['success' => false, 'error' => 'Missing resource_key']);
        return;
    }

    $stmt = $pdo->prepare("SELECT filename FROM client_resources WHERE resource_key = ?");
    $stmt->execute([$resourceKey]);
    $filename = $stmt->fetchColumn();

    if (!$filename) {
        echo json_encode(['success' => false, 'error' => 'Resource not found']);
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

    echo json_encode(['success' => true]);
}

/**
 * List all client resources.
 */
function handle_list_client_resources(PDO $pdo, array $admin_session): void {
    $stmt = $pdo->prepare("
        SELECT cr.*, au.username AS uploaded_by_name
        FROM client_resources cr
        LEFT JOIN admin_users au ON cr.uploaded_by = au.id
        ORDER BY cr.created_at DESC
    ");
    $stmt->execute();
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'resources' => $resources,
    ]);
}

