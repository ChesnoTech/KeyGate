<?php
/**
 * CSV Import Functions
 * Extracted from secure-admin.php (Phase 16 refactoring)
 *
 * Provides: handleCSVImport(), detectCSVFormat(), importKeyRow(),
 *           importComprehensiveKeyRow(), importStandardKeyRow(),
 *           importActivationAttempts(), parseDate(), parseTime()
 */

function handleCSVImport($uploaded_file) {
    global $pdo;

    $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
    if ($file_ext !== 'csv') {
        return ['error' => 'Only CSV files allowed'];
    }

    if ($uploaded_file['size'] > CSV_MAX_SIZE_BYTES) {
        return ['error' => 'File too large (max 10MB)'];
    }

    $handle = fopen($uploaded_file['tmp_name'], 'r');
    if (!$handle) {
        return ['error' => 'Cannot open CSV file'];
    }

    try {
        $pdo->beginTransaction();

        // Read header to detect format
        $header = fgetcsv($handle);
        $format = detectCSVFormat($header);

        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        while (($row = fgetcsv($handle)) !== FALSE) {
            $result = importKeyRow($row, $format);

            if ($result['status'] === 'imported') {
                $imported++;
            } elseif ($result['status'] === 'updated') {
                $updated++;
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
                if (isset($result['error'])) {
                    $errors[] = $result['error'];
                }
            }
        }

        fclose($handle);
        $pdo->commit();

        return [
            'success' => true,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, CSV_MAX_ERRORS_DISPLAY)
        ];

    } catch (Exception $e) {
        if (isset($handle)) fclose($handle);
        $pdo->rollback();
        return ['error' => 'Import failed: ' . $e->getMessage()];
    }
}

function detectCSVFormat($header) {
    $header = array_map('strtolower', array_map('trim', $header));

    // Check for comprehensive format (New CSV Database structure)
    if (in_array('productkey', $header) && in_array('keystatus', $header) && in_array('oemidentifier', $header) && in_array('rollserial', $header)) {
        return [
            'format' => 'comprehensive',
            'key_col' => array_search('productkey', $header),
            'oem_col' => array_search('oemidentifier', $header),
            'roll_serial_col' => array_search('rollserial', $header),
            'status_col' => array_search('keystatus', $header),
            'fail_counter_col' => array_search('failcounter', $header),
            'last_use_date_col' => array_search('lastusedate', $header),
            'last_use_time_col' => array_search('lastusetime', $header),
            'first_usage_date_col' => array_search('1stusagedate', $header),
            'first_usage_time_col' => array_search('1stusagetime', $header),
            'first_order_col' => array_search('1stordern', $header),
            'first_user_col' => array_search('1stuserid', $header),
            'first_status_col' => array_search('1sttrystatus', $header),
            'second_usage_date_col' => array_search('2ndusagedate', $header),
            'second_usage_time_col' => array_search('2ndusagetime', $header),
            'second_order_col' => array_search('2ndordern', $header),
            'second_user_col' => array_search('2nduserid', $header),
            'second_status_col' => array_search('2ndtrystatus', $header),
            'third_usage_date_col' => array_search('3rdusagedate', $header),
            'third_usage_time_col' => array_search('3rdusagetime', $header),
            'third_order_col' => array_search('3rdordern', $header),
            'third_user_col' => array_search('3rduserid', $header),
            'third_status_col' => array_search('3rtrystatus', $header)
        ];
    }

    // Standard format: ProductKey,OEMIdentifier,Barcode,Status
    if (in_array('productkey', $header) || in_array('product_key', $header)) {
        $key_col = array_search('productkey', $header);
        if ($key_col === FALSE) $key_col = array_search('product_key', $header);

        $oem_col = array_search('oemidentifier', $header);
        if ($oem_col === FALSE) $oem_col = array_search('oem_identifier', $header);

        $status_col = array_search('status', $header);
        if ($status_col === FALSE) $status_col = array_search('usage_status', $header);

        return [
            'format' => 'standard',
            'key_col' => $key_col,
            'oem_col' => $oem_col,
            'barcode_col' => array_search('barcode', $header),
            'status_col' => $status_col
        ];
    }

    // Legacy format (no headers): assume order ProductKey,OEMIdentifier,Barcode,Status
    return [
        'format' => 'legacy',
        'key_col' => 0,
        'oem_col' => 1,
        'barcode_col' => 2,
        'status_col' => 3
    ];
}

function importKeyRow($row, $format) {
    global $pdo;

    if (count($row) < 3) {
        return ['status' => 'skipped', 'error' => 'Insufficient columns'];
    }

    $product_key = trim($row[$format['key_col']] ?? '');
    $oem_identifier = trim($row[$format['oem_col']] ?? '');

    // Validate product key format
    if (!preg_match('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}$/', $product_key)) {
        return ['status' => 'skipped', 'error' => "Invalid key format: {$product_key}"];
    }

    if ($format['format'] === 'comprehensive') {
        return importComprehensiveKeyRow($row, $format);
    } else {
        return importStandardKeyRow($row, $format);
    }
}

function importComprehensiveKeyRow($row, $format) {
    global $pdo;

    $product_key = trim($row[$format['key_col']] ?? '');
    $oem_identifier = trim($row[$format['oem_col']] ?? '');
    $roll_serial = trim($row[$format['roll_serial_col']] ?? '');
    $status = trim($row[$format['status_col']] ?? 'unused');
    $fail_counter = (int)($row[$format['fail_counter_col']] ?? 0);
    $last_use_date = parseDate($row[$format['last_use_date_col']] ?? '');
    $last_use_time = parseTime($row[$format['last_use_time_col']] ?? '');
    $first_usage_date = parseDate($row[$format['first_usage_date_col']] ?? '');
    $first_usage_time = parseTime($row[$format['first_usage_time_col']] ?? '');

    // Convert status to database format
    $key_status = 'unused';
    $status_lower = strtolower($status);
    if ($status_lower === 'good') {
        $key_status = 'good';
    } elseif ($status_lower === 'bad') {
        $key_status = 'bad';
    } elseif ($status_lower === 'retry') {
        $key_status = 'retry';
    }

    try {
        // Check if key already exists
        $stmt = $pdo->prepare("SELECT id, key_status FROM oem_keys WHERE product_key = ?");
        $stmt->execute([$product_key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing key
            $stmt = $pdo->prepare("
                UPDATE oem_keys
                SET key_status = ?, oem_identifier = ?, roll_serial = ?, fail_counter = ?,
                    last_use_date = ?, last_use_time = ?, first_usage_date = ?, first_usage_time = ?,
                    updated_at = NOW()
                WHERE product_key = ?
            ");
            $stmt->execute([$key_status, $oem_identifier, $roll_serial, $fail_counter,
                          $last_use_date, $last_use_time, $first_usage_date, $first_usage_time, $product_key]);
            $key_id = $existing['id'];
            $result = ['status' => 'updated'];
        } else {
            // Insert new key
            $stmt = $pdo->prepare("
                INSERT INTO oem_keys (product_key, oem_identifier, roll_serial, key_status, fail_counter,
                                     last_use_date, last_use_time, first_usage_date, first_usage_time, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$product_key, $oem_identifier, $roll_serial, $key_status, $fail_counter,
                          $last_use_date, $last_use_time, $first_usage_date, $first_usage_time]);
            $key_id = $pdo->lastInsertId();
            $result = ['status' => 'imported'];
        }

        // Import activation attempts
        importActivationAttempts($key_id, $row, $format);

        return $result;

    } catch (PDOException $e) {
        error_log("CSV Import Database Error: " . $e->getMessage() . " in " . __FILE__ . ":" . __LINE__);
        return ['status' => 'skipped', 'error' => "Database operation failed"];
    }
}

function importStandardKeyRow($row, $format) {
    global $pdo;

    $product_key = trim($row[$format['key_col']] ?? '');
    $oem_identifier = trim($row[$format['oem_col']] ?? '');
    $barcode = trim($row[$format['barcode_col']] ?? '');
    $status = trim($row[$format['status_col']] ?? 'unused');

    // Convert status to database format
    $key_status = 'unused';
    $status_lower = strtolower($status);
    if ($status_lower === 'used' || $status_lower === 'good' || $status_lower === 'success') {
        $key_status = 'good';
    } elseif ($status_lower === 'failed' || $status_lower === 'bad' || $status_lower === 'error') {
        $key_status = 'bad';
    } elseif ($status_lower === 'retry') {
        $key_status = 'retry';
    }

    try {
        // Check if key already exists
        $stmt = $pdo->prepare("SELECT id, key_status FROM oem_keys WHERE product_key = ?");
        $stmt->execute([$product_key]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing key if status changed
            if ($existing['key_status'] !== $key_status) {
                $stmt = $pdo->prepare("
                    UPDATE oem_keys
                    SET key_status = ?, oem_identifier = ?, barcode = ?, updated_at = NOW()
                    WHERE product_key = ?
                ");
                $stmt->execute([$key_status, $oem_identifier, $barcode, $product_key]);
                return ['status' => 'updated'];
            } else {
                return ['status' => 'skipped', 'error' => "Duplicate key: {$product_key}"];
            }
        } else {
            // Insert new key
            $stmt = $pdo->prepare("
                INSERT INTO oem_keys (product_key, oem_identifier, barcode, key_status, roll_serial, created_at)
                VALUES (?, ?, ?, ?, 'imported', NOW())
            ");
            $stmt->execute([$product_key, $oem_identifier, $barcode, $key_status]);
            return ['status' => 'imported'];
        }

    } catch (PDOException $e) {
        error_log("CSV Import Database Error: " . $e->getMessage() . " in " . __FILE__ . ":" . __LINE__);
        return ['status' => 'skipped', 'error' => "Database operation failed"];
    }
}

function importActivationAttempts($key_id, $row, $format) {
    global $pdo;

    $attempts = [];

    // First attempt
    if (!empty($row[$format['first_usage_date_col']] ?? '')) {
        $attempts[] = [
            'attempt_number' => 1,
            'order_number' => trim($row[$format['first_order_col']] ?? ''),
            'technician_id' => trim($row[$format['first_user_col']] ?? ''),
            'attempt_result' => strtolower(trim($row[$format['first_status_col']] ?? '')) === 'activated' ? 'activated' : 'failed',
            'attempted_date' => parseDate($row[$format['first_usage_date_col']] ?? ''),
            'attempted_time' => parseTime($row[$format['first_usage_time_col']] ?? '')
        ];
    }

    // Second attempt
    if (!empty($row[$format['second_usage_date_col']] ?? '')) {
        $attempts[] = [
            'attempt_number' => 2,
            'order_number' => trim($row[$format['second_order_col']] ?? ''),
            'technician_id' => trim($row[$format['second_user_col']] ?? ''),
            'attempt_result' => strtolower(trim($row[$format['second_status_col']] ?? '')) === 'activated' ? 'activated' : 'failed',
            'attempted_date' => parseDate($row[$format['second_usage_date_col']] ?? ''),
            'attempted_time' => parseTime($row[$format['second_usage_time_col']] ?? '')
        ];
    }

    // Third attempt
    if (!empty($row[$format['third_usage_date_col']] ?? '')) {
        $attempts[] = [
            'attempt_number' => 3,
            'order_number' => trim($row[$format['third_order_col']] ?? ''),
            'technician_id' => trim($row[$format['third_user_col']] ?? ''),
            'attempt_result' => strtolower(trim($row[$format['third_status_col']] ?? '')) === 'activated' ? 'activated' : 'failed',
            'attempted_date' => parseDate($row[$format['third_usage_date_col']] ?? ''),
            'attempted_time' => parseTime($row[$format['third_usage_time_col']] ?? '')
        ];
    }

    // Insert activation attempts
    foreach ($attempts as $attempt) {
        if (!empty($attempt['attempted_date']) && !empty($attempt['technician_id'])) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO activation_attempts
                    (key_id, technician_id, order_number, attempt_number, attempt_result,
                     attempted_date, attempted_time, attempted_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $key_id, $attempt['technician_id'], $attempt['order_number'],
                    $attempt['attempt_number'], $attempt['attempt_result'],
                    $attempt['attempted_date'], $attempt['attempted_time']
                ]);
            } catch (PDOException $e) {
                error_log("Failed to import activation attempt: " . $e->getMessage());
            }
        }
    }
}

function parseDate($date_str) {
    if (empty($date_str)) return null;

    // Handle M/d/yyyy format (4/7/2025)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date_str, $matches)) {
        return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
    }

    return null;
}

function parseTime($time_str) {
    if (empty($time_str)) return null;

    // Handle h:mm:ss AM/PM format (2:30:00 PM) - convert to 24-hour
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})\s*(AM|PM)$/i', $time_str, $matches)) {
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];
        $second = (int)$matches[3];
        $ampm = strtoupper($matches[4]);

        if ($ampm === 'PM' && $hour !== 12) {
            $hour += 12;
        } elseif ($ampm === 'AM' && $hour === 12) {
            $hour = 0;
        }

        return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
    }

    // Handle 24-hour format (HH:mm:ss)
    if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $time_str, $matches)) {
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];
        $second = (int)$matches[3];

        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59 && $second >= 0 && $second <= 59) {
            return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        }
    }

    // Handle HH:mm format (add :00 seconds)
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time_str, $matches)) {
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];

        if ($hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
            return sprintf('%02d:%02d:00', $hour, $minute);
        }
    }

    return null;
}
