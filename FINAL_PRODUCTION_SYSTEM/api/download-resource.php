<?php
/**
 * Public API: Download a client resource by key.
 * Used by the CMD launcher to download the PS7 MSI installer.
 * Requires a valid technician session token for authentication.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions/admin-helpers.php';

header('Content-Type: application/json');

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$resourceKey = $_GET['key'] ?? '';
$sessionToken = $_GET['token'] ?? '';

if (empty($resourceKey) || empty($sessionToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing key or token parameter']);
    exit;
}

try {
    // Validate session token
    $stmt = $pdo->prepare("
        SELECT s.technician_id
        FROM active_sessions s
        WHERE s.session_token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired session']);
        exit;
    }

    // Look up the resource
    $stmt = $pdo->prepare("SELECT * FROM client_resources WHERE resource_key = ?");
    $stmt->execute([$resourceKey]);
    $resource = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resource) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Resource not found']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/client-resources';
    $filePath = $uploadDir . '/' . $resource['filename'];

    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Resource file missing from server']);
        exit;
    }

    // Stream the file
    streamFileDownload($filePath, $resource['original_filename'], 'application/octet-stream', (int) $resource['file_size'], $resource['checksum_sha256']);

} catch (PDOException $e) {
    error_log("Download resource error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}
