<?php
// API endpoint to import keys from CSV file (for migration)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

ApiMiddleware::requirePowerShell();
ApiMiddleware::requireMethod('POST');

// Check if file was uploaded
if (!isset($_FILES['csv_file'])) {
    jsonResponse(['error' => 'No CSV file uploaded'], 400);
}

$file = $_FILES['csv_file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'File upload error'], 400);
}

// Validate file type
$file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($file_ext !== 'csv') {
    jsonResponse(['error' => 'Only CSV files allowed'], 400);
}

try {
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        jsonResponse(['error' => 'Cannot open CSV file'], 500);
    }
    
    $pdo->beginTransaction();
    
    // Skip header row
    $header = fgetcsv($handle);
    
    $imported = 0;
    $skipped = 0;
    $errors = [];
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) < 4) {
            $skipped++;
            continue;
        }
        
        $product_key = trim($row[0]);
        $oem_identifier = trim($row[1]);
        $barcode = trim($row[2]);
        $usage_status = trim($row[3]);
        
        // Validate product key format
        if (!preg_match(PRODUCT_KEY_PATTERN, $product_key)) {
            $skipped++;
            $errors[] = "Invalid key format: {$product_key}";
            continue;
        }
        
        // Convert usage status to new format
        $key_status = 'unused';
        if ($usage_status === 'Used') {
            $key_status = 'good';
        } elseif ($usage_status === 'Failed') {
            $key_status = 'bad';
        }
        
        // Try to insert key
        try {
            $stmt = $pdo->prepare("
                INSERT INTO oem_keys (product_key, oem_identifier, barcode, key_status, roll_serial)
                VALUES (?, ?, ?, ?, 'imported')
            ");
            $stmt->execute([$product_key, $oem_identifier, $barcode, $key_status]);
            $imported++;
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') { // Duplicate key
                $skipped++;
                $errors[] = "Duplicate key skipped: {$product_key}";
            } else {
                throw $e;
            }
        }
    }
    
    fclose($handle);
    $pdo->commit();
    
    jsonResponse([
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    if (isset($handle)) {
        fclose($handle);
    }
    $pdo->rollback();
    error_log("CSV import error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonResponse(['error' => 'Import failed due to a server error. Check server logs for details.'], 500);
}
?>