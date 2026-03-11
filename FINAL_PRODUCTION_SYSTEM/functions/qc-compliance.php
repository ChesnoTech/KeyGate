<?php
/**
 * QC Hardware Compliance Engine
 * Core compliance logic: auto-registration, rule resolution, check execution
 */

/**
 * Check if QC compliance system is enabled
 */
function qcIsEnabled(PDO $pdo): bool {
    $stmt = $pdo->prepare("SELECT setting_value FROM qc_global_settings WHERE setting_key = 'qc_enabled' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['setting_value'] === '1';
}

/**
 * Return all global QC settings as key-value array
 */
function qcGetGlobalSettings(PDO $pdo): array {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM qc_global_settings");
    $settings = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

/**
 * Auto-register or update a motherboard in the registry.
 * Returns the registry row ID.
 */
function qcAutoRegisterMotherboard(PDO $pdo, ?string $manufacturer, ?string $product, ?string $biosVersion): ?int {
    if (empty($manufacturer) || empty($product)) {
        return null;
    }

    // Check if already exists
    $stmt = $pdo->prepare("SELECT id, known_bios_versions FROM qc_motherboard_registry WHERE manufacturer = ? AND product = ? LIMIT 1");
    $stmt->execute([$manufacturer, $product]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update: increment times_seen, append BIOS version if new
        $knownVersions = json_decode($existing['known_bios_versions'] ?: '[]', true) ?: [];
        if (!empty($biosVersion) && !in_array($biosVersion, $knownVersions)) {
            $knownVersions[] = $biosVersion;
        }

        $stmt = $pdo->prepare("
            UPDATE qc_motherboard_registry
            SET times_seen = times_seen + 1,
                last_seen_at = NOW(),
                known_bios_versions = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($knownVersions), $existing['id']]);
        return (int) $existing['id'];
    }

    // Insert new motherboard
    $knownVersions = !empty($biosVersion) ? json_encode([$biosVersion]) : '[]';
    $stmt = $pdo->prepare("
        INSERT INTO qc_motherboard_registry (manufacturer, product, known_bios_versions, first_seen_at, last_seen_at, times_seen)
        VALUES (?, ?, ?, NOW(), NOW(), 1)
    ");
    $stmt->execute([$manufacturer, $product, $knownVersions]);
    return (int) $pdo->lastInsertId();
}

/**
 * Resolve effective compliance rules for a motherboard.
 * Inheritance: global defaults -> manufacturer defaults -> model overrides (non-NULL only)
 */
function qcGetEffectiveRules(PDO $pdo, ?string $manufacturer, ?string $product): array {
    // Start with global defaults
    $globalSettings = qcGetGlobalSettings($pdo);
    $rules = [
        'secure_boot_required'    => 1,
        'secure_boot_enforcement' => (int) ($globalSettings['default_secure_boot_enforcement'] ?? 1),
        'min_bios_version'        => null,
        'recommended_bios_version' => null,
        'bios_enforcement'        => (int) ($globalSettings['default_bios_enforcement'] ?? 1),
        'hackbgrt_enforcement'    => (int) ($globalSettings['default_hackbgrt_enforcement'] ?? 1),
    ];
    $source = 'global';

    // Overlay manufacturer defaults
    if (!empty($manufacturer)) {
        $stmt = $pdo->prepare("SELECT * FROM qc_manufacturer_defaults WHERE manufacturer = ? LIMIT 1");
        $stmt->execute([$manufacturer]);
        $mfr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mfr) {
            $source = 'manufacturer';
            foreach (['secure_boot_required', 'secure_boot_enforcement', 'min_bios_version', 'recommended_bios_version', 'bios_enforcement', 'hackbgrt_enforcement'] as $key) {
                if (isset($mfr[$key]) && $mfr[$key] !== null && $mfr[$key] !== '') {
                    $rules[$key] = is_numeric($mfr[$key]) ? (int) $mfr[$key] : $mfr[$key];
                }
            }
        }
    }

    // Overlay model-specific overrides (non-NULL only)
    if (!empty($manufacturer) && !empty($product)) {
        $stmt = $pdo->prepare("SELECT * FROM qc_motherboard_registry WHERE manufacturer = ? AND product = ? LIMIT 1");
        $stmt->execute([$manufacturer, $product]);
        $model = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($model) {
            foreach (['secure_boot_required', 'secure_boot_enforcement', 'min_bios_version', 'recommended_bios_version', 'bios_enforcement', 'hackbgrt_enforcement'] as $key) {
                if (isset($model[$key]) && $model[$key] !== null && $model[$key] !== '') {
                    $source = 'model';
                    $rules[$key] = is_numeric($model[$key]) ? (int) $model[$key] : $model[$key];
                }
            }
        }
    }

    $rules['_source'] = $source;
    return $rules;
}

/**
 * Compare two BIOS version strings.
 * Returns: -1 (actual < expected), 0 (equal), 1 (actual > expected)
 */
function qcCompareBiosVersions(string $actual, string $expected): int {
    // Try PHP's version_compare first (handles dotted versions well)
    $result = version_compare($actual, $expected);
    if ($result !== 0) {
        return $result;
    }

    // Both purely numeric? Compare as integers
    if (ctype_digit($actual) && ctype_digit($expected)) {
        return (int) $actual <=> (int) $expected;
    }

    // Fallback to case-insensitive string comparison
    return strcasecmp($actual, $expected);
}

/**
 * Map enforcement level to check_result string.
 */
function qcEnforcementToResult(int $enforcement): string {
    switch ($enforcement) {
        case 0: return 'pass';  // Disabled = always pass
        case 1: return 'info';
        case 2: return 'warning';
        case 3: return 'fail';
        default: return 'info';
    }
}

/**
 * Run all compliance checks for a hardware submission.
 * Inserts results into qc_compliance_results.
 * Returns array with checks and blocking/warning flags.
 */
function qcRunChecks(PDO $pdo, int $hardwareInfoId, array $hw): array {
    $manufacturer = $hw['motherboard_manufacturer'] ?? null;
    $product      = $hw['motherboard_product'] ?? null;
    $biosVersion  = $hw['bios_version'] ?? null;
    $secureBoot   = isset($hw['secure_boot_enabled']) ? (int) $hw['secure_boot_enabled'] : null;
    $hackbgrtInst = isset($hw['hackbgrt_installed']) ? (int) $hw['hackbgrt_installed'] : null;
    $hackbgrtFirst = isset($hw['hackbgrt_first_boot']) ? (int) $hw['hackbgrt_first_boot'] : null;
    $orderNumber  = $hw['order_number'] ?? '';

    // Auto-register motherboard
    $registryId = qcAutoRegisterMotherboard($pdo, $manufacturer, $product, $biosVersion);

    // Get effective rules
    $rules = qcGetEffectiveRules($pdo, $manufacturer, $product);
    $ruleSource = $rules['_source'];

    $checks = [];
    $hasBlocking = false;
    $hasWarnings = false;

    // --- Check 1: Secure Boot ---
    $sbEnforcement = (int) $rules['secure_boot_enforcement'];
    if ($sbEnforcement > 0) {
        $sbRequired = (bool) $rules['secure_boot_required'];
        if ($sbRequired && $secureBoot !== 1) {
            $result = qcEnforcementToResult($sbEnforcement);
            $message = 'Secure Boot is disabled but required';
            if ($secureBoot === null) {
                $message = 'Secure Boot status unknown (Legacy BIOS?)';
            }
        } else {
            $result = 'pass';
            $message = 'Secure Boot is enabled';
        }

        $check = [
            'check_type'       => 'secure_boot',
            'check_result'     => $result,
            'enforcement_level' => $sbEnforcement,
            'expected_value'   => $sbRequired ? 'Enabled' : 'Any',
            'actual_value'     => $secureBoot === 1 ? 'Enabled' : ($secureBoot === 0 ? 'Disabled' : 'Unknown'),
            'message'          => $message,
            'rule_source'      => $ruleSource,
        ];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;

        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
    }

    // --- Check 2: BIOS Version ---
    $biosEnforcement = (int) $rules['bios_enforcement'];
    if ($biosEnforcement > 0 && !empty($biosVersion)) {
        $minBios = $rules['min_bios_version'];
        $recBios = $rules['recommended_bios_version'];

        if (!empty($minBios)) {
            $cmpMin = qcCompareBiosVersions($biosVersion, $minBios);
            if ($cmpMin < 0) {
                // Below minimum
                $result = qcEnforcementToResult($biosEnforcement);
                $message = "BIOS version $biosVersion is below minimum ($minBios)";
            } elseif (!empty($recBios) && qcCompareBiosVersions($biosVersion, $recBios) < 0) {
                // Between min and recommended
                $result = $biosEnforcement >= 2 ? 'info' : 'info';
                $message = "BIOS version $biosVersion meets minimum ($minBios) but below recommended ($recBios)";
            } else {
                $result = 'pass';
                $message = "BIOS version $biosVersion meets requirements";
            }
        } elseif (!empty($recBios)) {
            // Only recommended set (no minimum)
            if (qcCompareBiosVersions($biosVersion, $recBios) < 0) {
                $result = 'info';
                $message = "BIOS version $biosVersion is below recommended ($recBios)";
            } else {
                $result = 'pass';
                $message = "BIOS version $biosVersion meets recommended";
            }
        } else {
            // No versions configured for this board yet
            $result = 'pass';
            $message = "No BIOS version requirements configured";
        }

        $check = [
            'check_type'       => 'bios_version',
            'check_result'     => $result,
            'enforcement_level' => $biosEnforcement,
            'expected_value'   => $minBios ?: ($recBios ?: 'Not set'),
            'actual_value'     => $biosVersion,
            'message'          => $message,
            'rule_source'      => $ruleSource,
        ];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;

        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
    }

    // --- Check 3: HackBGRT Boot Priority ---
    $hbEnforcement = (int) $rules['hackbgrt_enforcement'];
    if ($hbEnforcement > 0 && $hackbgrtInst === 1) {
        // HackBGRT is installed — check if it's first boot entry
        if ($hackbgrtFirst !== 1) {
            $result = qcEnforcementToResult($hbEnforcement);
            $message = 'HackBGRT is installed but NOT the first boot entry';
        } else {
            $result = 'pass';
            $message = 'HackBGRT is correctly set as first boot entry';
        }

        $check = [
            'check_type'       => 'hackbgrt_boot_priority',
            'check_result'     => $result,
            'enforcement_level' => $hbEnforcement,
            'expected_value'   => 'First boot entry',
            'actual_value'     => $hackbgrtFirst === 1 ? 'First' : 'Not first',
            'message'          => $message,
            'rule_source'      => $ruleSource,
        ];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;

        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
    }

    return [
        'checks'       => $checks,
        'has_blocking'  => $hasBlocking,
        'has_warnings'  => $hasWarnings,
    ];
}

/**
 * Insert a single compliance check result.
 */
function qcInsertResult(PDO $pdo, int $hardwareInfoId, string $orderNumber, array $check, ?int $registryId, bool $retroactive = false): void {
    $stmt = $pdo->prepare("
        INSERT INTO qc_compliance_results (
            hardware_info_id, order_number, check_type, check_result,
            enforcement_level, expected_value, actual_value, message,
            rule_source, motherboard_registry_id, is_retroactive, checked_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $hardwareInfoId,
        $orderNumber,
        $check['check_type'],
        $check['check_result'],
        $check['enforcement_level'],
        $check['expected_value'] ?? null,
        $check['actual_value'] ?? null,
        $check['message'] ?? null,
        $check['rule_source'] ?? 'global',
        $registryId,
        $retroactive ? 1 : 0,
    ]);
}

/**
 * Check if a hardware submission has any unresolved blocking issues.
 */
function qcHasBlockingIssues(PDO $pdo, int $hardwareInfoId): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM qc_compliance_results
        WHERE hardware_info_id = ?
          AND enforcement_level = 3
          AND check_result = 'fail'
    ");
    $stmt->execute([$hardwareInfoId]);
    return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
}

/**
 * Re-run compliance checks on historical hardware records.
 * Deletes old results and re-evaluates against current rules.
 */
function qcRecheckHistorical(PDO $pdo, ?string $manufacturer = null, ?string $product = null): array {
    // Build query to find matching hardware records
    $where = [];
    $params = [];
    if (!empty($manufacturer)) {
        $where[] = "motherboard_manufacturer = ?";
        $params[] = $manufacturer;
    }
    if (!empty($product)) {
        $where[] = "motherboard_product = ?";
        $params[] = $product;
    }
    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    $stmt = $pdo->prepare("SELECT id, order_number, motherboard_manufacturer, motherboard_product, bios_version, secure_boot_enabled, hackbgrt_installed, hackbgrt_first_boot FROM hardware_info $whereClause ORDER BY id ASC");
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['rechecked' => 0, 'passed' => 0, 'failed' => 0, 'warnings' => 0];

    foreach ($records as $hw) {
        $hwId = (int) $hw['id'];

        // Delete existing compliance results for this record
        $stmt2 = $pdo->prepare("DELETE FROM qc_compliance_results WHERE hardware_info_id = ?");
        $stmt2->execute([$hwId]);

        // Re-run checks
        $hwData = [
            'order_number'           => $hw['order_number'],
            'motherboard_manufacturer' => $hw['motherboard_manufacturer'],
            'motherboard_product'    => $hw['motherboard_product'],
            'bios_version'           => $hw['bios_version'],
            'secure_boot_enabled'    => $hw['secure_boot_enabled'],
            'hackbgrt_installed'     => $hw['hackbgrt_installed'],
            'hackbgrt_first_boot'    => $hw['hackbgrt_first_boot'],
        ];

        // Use a modified version that marks results as retroactive
        $result = qcRunChecksRetroactive($pdo, $hwId, $hwData);
        $stats['rechecked']++;
        if ($result['has_blocking']) $stats['failed']++;
        elseif ($result['has_warnings']) $stats['warnings']++;
        else $stats['passed']++;
    }

    return $stats;
}

/**
 * Run checks with retroactive flag set.
 */
function qcRunChecksRetroactive(PDO $pdo, int $hardwareInfoId, array $hw): array {
    $manufacturer = $hw['motherboard_manufacturer'] ?? null;
    $product      = $hw['motherboard_product'] ?? null;
    $biosVersion  = $hw['bios_version'] ?? null;
    $secureBoot   = isset($hw['secure_boot_enabled']) ? (int) $hw['secure_boot_enabled'] : null;
    $hackbgrtInst = isset($hw['hackbgrt_installed']) ? (int) $hw['hackbgrt_installed'] : null;
    $hackbgrtFirst = isset($hw['hackbgrt_first_boot']) ? (int) $hw['hackbgrt_first_boot'] : null;
    $orderNumber  = $hw['order_number'] ?? '';

    // Get registry ID (don't auto-register for retroactive)
    $stmt = $pdo->prepare("SELECT id FROM qc_motherboard_registry WHERE manufacturer = ? AND product = ? LIMIT 1");
    $stmt->execute([$manufacturer, $product]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);
    $registryId = $reg ? (int) $reg['id'] : null;

    $rules = qcGetEffectiveRules($pdo, $manufacturer, $product);
    $ruleSource = $rules['_source'];

    $checks = [];
    $hasBlocking = false;
    $hasWarnings = false;

    // Secure Boot check
    $sbEnforcement = (int) $rules['secure_boot_enforcement'];
    if ($sbEnforcement > 0) {
        $sbRequired = (bool) $rules['secure_boot_required'];
        if ($sbRequired && $secureBoot !== 1) {
            $result = qcEnforcementToResult($sbEnforcement);
            $message = 'Secure Boot is disabled but required';
        } else {
            $result = 'pass';
            $message = 'Secure Boot is enabled';
        }
        $check = ['check_type' => 'secure_boot', 'check_result' => $result, 'enforcement_level' => $sbEnforcement,
                   'expected_value' => $sbRequired ? 'Enabled' : 'Any', 'actual_value' => $secureBoot === 1 ? 'Enabled' : ($secureBoot === 0 ? 'Disabled' : 'Unknown'),
                   'message' => $message, 'rule_source' => $ruleSource];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId, true);
    }

    // BIOS version check
    $biosEnforcement = (int) $rules['bios_enforcement'];
    if ($biosEnforcement > 0 && !empty($biosVersion) && !empty($rules['min_bios_version'])) {
        $minBios = $rules['min_bios_version'];
        $cmpMin = qcCompareBiosVersions($biosVersion, $minBios);
        if ($cmpMin < 0) {
            $result = qcEnforcementToResult($biosEnforcement);
            $message = "BIOS version $biosVersion is below minimum ($minBios)";
        } else {
            $result = 'pass';
            $message = "BIOS version $biosVersion meets minimum ($minBios)";
        }
        $check = ['check_type' => 'bios_version', 'check_result' => $result, 'enforcement_level' => $biosEnforcement,
                   'expected_value' => $minBios, 'actual_value' => $biosVersion,
                   'message' => $message, 'rule_source' => $ruleSource];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId, true);
    }

    // HackBGRT check
    $hbEnforcement = (int) $rules['hackbgrt_enforcement'];
    if ($hbEnforcement > 0 && $hackbgrtInst === 1) {
        if ($hackbgrtFirst !== 1) {
            $result = qcEnforcementToResult($hbEnforcement);
            $message = 'HackBGRT installed but NOT first boot entry';
        } else {
            $result = 'pass';
            $message = 'HackBGRT correctly set as first boot entry';
        }
        $check = ['check_type' => 'hackbgrt_boot_priority', 'check_result' => $result, 'enforcement_level' => $hbEnforcement,
                   'expected_value' => 'First boot entry', 'actual_value' => $hackbgrtFirst === 1 ? 'First' : 'Not first',
                   'message' => $message, 'rule_source' => $ruleSource];
        $checks[] = $check;
        if ($result === 'fail') $hasBlocking = true;
        if ($result === 'warning') $hasWarnings = true;
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId, true);
    }

    return ['checks' => $checks, 'has_blocking' => $hasBlocking, 'has_warnings' => $hasWarnings];
}
