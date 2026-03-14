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
function qcGetEffectiveRules(PDO $pdo, ?string $manufacturer, ?string $product, ?string $orderNumber = null): array {
    // Start with global defaults
    $globalSettings = qcGetGlobalSettings($pdo);
    $rules = [
        'secure_boot_required'    => 1,
        'secure_boot_enforcement' => (int) ($globalSettings['default_secure_boot_enforcement'] ?? 1),
        'min_bios_version'        => null,
        'recommended_bios_version' => null,
        'bios_enforcement'        => (int) ($globalSettings['default_bios_enforcement'] ?? 1),
        'hackbgrt_enforcement'    => (int) ($globalSettings['default_hackbgrt_enforcement'] ?? 1),
        'partition_enforcement'   => (int) ($globalSettings['default_partition_enforcement'] ?? 2),
        'missing_drivers_enforcement' => (int) ($globalSettings['default_missing_drivers_enforcement'] ?? 2),
    ];
    $source = 'global';

    // Overlay product line enforcement (matched by order number pattern)
    if (!empty($orderNumber) && mb_strlen($orderNumber) <= 50) {
        $stmt = $pdo->query("SELECT id, name, order_pattern, secure_boot_enforcement, bios_enforcement, hackbgrt_enforcement, partition_enforcement, missing_drivers_enforcement FROM product_lines WHERE is_active = 1 ORDER BY LENGTH(order_pattern) DESC");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $pattern = $line['order_pattern'] ?? '';
            if (!preg_match('/^[\p{L}\p{N}#*\-\s]+$/u', $pattern) || mb_strlen($pattern) > 50) continue;
            $regex = '/^' . preg_replace_callback('/[#*]|[^#*]+/', function ($m) {
                if ($m[0] === '#') return '\\d';
                if ($m[0] === '*') return '.+';
                return preg_quote($m[0], '/');
            }, $pattern) . '$/u';
            if (preg_match($regex, $orderNumber)) {
                $source = 'product_line';
                foreach (['secure_boot_enforcement', 'bios_enforcement', 'hackbgrt_enforcement', 'partition_enforcement', 'missing_drivers_enforcement'] as $key) {
                    if (isset($line[$key]) && $line[$key] !== null && $line[$key] !== '') {
                        $rules[$key] = (int) $line[$key];
                    }
                }
                $rules['_product_line_id'] = (int) $line['id'];
                $rules['_product_line_name'] = $line['name'];
                break;
            }
        }
    }

    // Overlay manufacturer defaults
    if (!empty($manufacturer)) {
        $stmt = $pdo->prepare("SELECT * FROM qc_manufacturer_defaults WHERE manufacturer = ? LIMIT 1");
        $stmt->execute([$manufacturer]);
        $mfr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($mfr) {
            foreach (['secure_boot_required', 'secure_boot_enforcement', 'min_bios_version', 'recommended_bios_version', 'bios_enforcement', 'hackbgrt_enforcement', 'missing_drivers_enforcement'] as $key) {
                if (isset($mfr[$key]) && $mfr[$key] !== null && $mfr[$key] !== '') {
                    $source = ($source === 'product_line') ? 'product_line+manufacturer' : 'manufacturer';
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
            foreach (['secure_boot_required', 'secure_boot_enforcement', 'min_bios_version', 'recommended_bios_version', 'bios_enforcement', 'hackbgrt_enforcement', 'missing_drivers_enforcement'] as $key) {
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

    // Get effective rules (cascade: global → product line → manufacturer → model)
    $rules = qcGetEffectiveRules($pdo, $manufacturer, $product, $orderNumber);
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

    // --- Check 4: Missing Drivers ---
    $driverCheck = qcCheckMissingDrivers($pdo, $hardwareInfoId, $orderNumber, $hw, $rules, $ruleSource, $registryId);
    if ($driverCheck !== null) {
        $checks[] = $driverCheck;
        if ($driverCheck['check_result'] === 'fail') $hasBlocking = true;
        if ($driverCheck['check_result'] === 'warning') $hasWarnings = true;
    }

    // --- Check 5: Partition Layout ---
    $partitionCheck = qcCheckPartitionLayout($pdo, $hardwareInfoId, $orderNumber, $hw, $registryId, $rules);
    if ($partitionCheck !== null) {
        $checks[] = $partitionCheck;
        if ($partitionCheck['check_result'] === 'fail') $hasBlocking = true;
        if ($partitionCheck['check_result'] === 'warning') $hasWarnings = true;
    }

    return [
        'checks'       => $checks,
        'has_blocking'  => $hasBlocking,
        'has_warnings'  => $hasWarnings,
    ];
}

/**
 * Check partition layout against product variant template.
 * Returns a single check array or null if no template applies.
 */
/**
 * Check 4: Missing / Problematic Drivers
 * Verifies that no devices have missing or error-state drivers.
 * PS1 client sends:
 *   missing_drivers       — JSON array of {device_name, device_id, status, class, error_code}
 *   missing_drivers_count — integer count
 */
function qcCheckMissingDrivers(PDO $pdo, int $hardwareInfoId, string $orderNumber, array $hw, array $rules, string $ruleSource, ?int $registryId): ?array {
    $enforcement = (int) ($rules['missing_drivers_enforcement'] ?? 0);
    if ($enforcement === 0) {
        return null; // Check disabled
    }

    $missingCount = isset($hw['missing_drivers_count']) ? (int) $hw['missing_drivers_count'] : null;
    $missingDrivers = $hw['missing_drivers'] ?? null;

    // Parse JSON if string
    if (is_string($missingDrivers) && $missingDrivers !== '') {
        $missingDrivers = json_decode($missingDrivers, true);
    }

    // If no driver data was submitted, record as info (can't verify)
    if ($missingCount === null && empty($missingDrivers)) {
        $check = [
            'check_type'        => 'missing_drivers',
            'check_result'      => 'info',
            'enforcement_level' => $enforcement,
            'expected_value'    => '0 missing drivers',
            'actual_value'      => 'No driver data collected',
            'message'           => 'Driver status was not collected from this machine',
            'rule_source'       => $ruleSource,
        ];
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
        return $check;
    }

    // Calculate count from array if not provided separately
    if ($missingCount === null && is_array($missingDrivers)) {
        $missingCount = count($missingDrivers);
    }

    if ($missingCount > 0) {
        $result = qcEnforcementToResult($enforcement);

        // Build detailed message with individual device info
        $details = [];
        if (is_array($missingDrivers)) {
            foreach (array_slice($missingDrivers, 0, 10) as $d) { // Max 10 devices in message
                $name = $d['device_name'] ?? $d['name'] ?? 'Unknown Device';
                $cls  = $d['class'] ?? $d['device_class'] ?? '';
                $code = $d['error_code'] ?? $d['config_manager_error_code'] ?? '';
                $status = $d['status'] ?? '';
                $line = $name;
                if ($cls) $line .= " [{$cls}]";
                if ($code) $line .= " (error {$code})";
                if ($status && $status !== 'Error' && $status !== 'Unknown') $line .= " — {$status}";
                $details[] = $line;
            }
            if (count($missingDrivers) > 10) {
                $details[] = '... and ' . (count($missingDrivers) - 10) . ' more';
            }
        }

        $detailStr = implode("\n", $details);
        $message = "{$missingCount} missing or problematic driver(s) detected";
        if ($detailStr) {
            $message .= ":\n{$detailStr}";
        }
    } else {
        $result = 'pass';
        $message = 'All device drivers installed and functional';
    }

    $check = [
        'check_type'        => 'missing_drivers',
        'check_result'      => $result,
        'enforcement_level' => $enforcement,
        'expected_value'    => '0 missing drivers',
        'actual_value'      => "{$missingCount} missing driver(s)",
        'message'           => $message,
        'rule_source'       => $ruleSource,
    ];
    qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
    return $check;
}

function qcCheckPartitionLayout(PDO $pdo, int $hardwareInfoId, string $orderNumber, array $hw, ?int $registryId, array $rules = []): ?array {
    // Use partition_enforcement from rules (cascaded: global → product line → mfr → model)
    $enforcement = (int) ($rules['partition_enforcement'] ?? 2);
    if ($enforcement === 0) {
        return null; // Partition check disabled
    }

    if (empty($orderNumber)) {
        return null;
    }

    // 1. Match order number to a product line by pattern
    // Patterns: # = single digit, * = any characters (e.g. ЭЛ00-######)
    // Limit order number length to prevent ReDoS
    if (mb_strlen($orderNumber) > 50) {
        return null;
    }
    $stmt = $pdo->query("SELECT id, name, order_pattern, enforcement_level FROM product_lines WHERE is_active = 1 ORDER BY LENGTH(order_pattern) DESC");
    $matchedLine = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $pattern = $line['order_pattern'] ?? '';
        // Validate pattern: only allow alphanumeric, Cyrillic, #, *, -, space
        if (!preg_match('/^[\p{L}\p{N}#*\-\s]+$/u', $pattern) || mb_strlen($pattern) > 50) {
            continue; // Skip malformed patterns
        }
        $regex = '/^' . preg_replace_callback('/[#*]|[^#*]+/', function ($m) {
            if ($m[0] === '#') return '\\d';
            if ($m[0] === '*') return '.+';
            return preg_quote($m[0], '/');
        }, $pattern) . '$/u';
        if (preg_match($regex, $orderNumber)) {
            $matchedLine = $line;
            break;
        }
    }

    if (!$matchedLine) {
        return null; // No product line configured for this order pattern
    }

    // enforcement is already set from $rules['partition_enforcement'] at the top

    // 2. Parse disk layout from hardware data
    $diskLayout = $hw['complete_disk_layout'] ?? null;
    if (is_string($diskLayout)) {
        $diskLayout = json_decode($diskLayout, true);
    }
    if (empty($diskLayout) || !is_array($diskLayout)) {
        $check = [
            'check_type'        => 'partition_layout',
            'check_result'      => qcEnforcementToResult($enforcement),
            'enforcement_level' => $enforcement,
            'expected_value'    => 'Partition data from ' . $matchedLine['name'],
            'actual_value'      => 'No disk layout data collected',
            'message'           => 'Cannot check partition layout: no complete_disk_layout data submitted',
            'rule_source'       => 'global',
        ];
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
        return $check;
    }

    // 3. Find boot disk (disk index 0 or first disk with EFI partition)
    // PS1 sends: partition_purpose, partition_type — check all naming variants
    $bootDisk = null;
    foreach ($diskLayout as $disk) {
        $partitions = $disk['partitions'] ?? [];
        foreach ($partitions as $p) {
            $purpose = strtolower($p['partition_purpose'] ?? $p['purpose'] ?? '');
            $pType   = strtolower($p['partition_type'] ?? $p['type'] ?? '');
            if (strpos($purpose, 'efi') !== false || strpos($purpose, 'system') !== false
                || strpos($pType, 'efi') !== false || strpos($pType, 'system') !== false) {
                $bootDisk = $disk;
                break 2;
            }
        }
    }
    if (!$bootDisk && !empty($diskLayout)) {
        $bootDisk = $diskLayout[0]; // Fallback to first disk
    }
    if (!$bootDisk) {
        return null;
    }

    // 4. Calculate boot disk size in MB
    // PS1 sends disk_size_gb; also support size_gb, size_bytes
    $diskSizeMb = 0;
    if (isset($bootDisk['disk_size_gb'])) {
        $diskSizeMb = (int) round((float) $bootDisk['disk_size_gb'] * 1024);
    } elseif (isset($bootDisk['size_gb'])) {
        $diskSizeMb = (int) round((float) $bootDisk['size_gb'] * 1024);
    } elseif (isset($bootDisk['size_bytes'])) {
        $diskSizeMb = (int) round((float) $bootDisk['size_bytes'] / 1048576);
    }

    // 5. Find matching variant by disk size within this product line
    if ($diskSizeMb <= 0) {
        $check = [
            'check_type'        => 'partition_layout',
            'check_result'      => qcEnforcementToResult($enforcement),
            'enforcement_level' => $enforcement,
            'expected_value'    => 'Valid disk size for ' . $matchedLine['name'],
            'actual_value'      => 'Disk size not detected (0 MB)',
            'message'           => 'Cannot check partition layout: boot disk size could not be determined',
            'rule_source'       => 'global',
        ];
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
        return $check;
    }
    $stmt = $pdo->prepare("
        SELECT id, name, disk_size_min_mb, disk_size_max_mb
        FROM product_variants
        WHERE line_id = ? AND is_active = 1
          AND disk_size_min_mb <= ? AND disk_size_max_mb >= ?
        ORDER BY disk_size_min_mb DESC
        LIMIT 1
    ");
    $stmt->execute([$matchedLine['id'], $diskSizeMb, $diskSizeMb]);
    $variant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$variant) {
        $check = [
            'check_type'        => 'partition_layout',
            'check_result'      => qcEnforcementToResult($enforcement),
            'enforcement_level' => $enforcement,
            'expected_value'    => 'Known disk size for ' . $matchedLine['name'],
            'actual_value'      => $diskSizeMb . ' MB',
            'message'           => "Boot disk size {$diskSizeMb} MB does not match any variant in product line '{$matchedLine['name']}'",
            'rule_source'       => 'global',
        ];
        qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
        return $check;
    }

    // 6. Store detected variant in hardware_info
    $stmt = $pdo->prepare("
        UPDATE hardware_info
        SET detected_variant_id = ?, detected_variant_name = ?, detected_line_name = ?
        WHERE id = ?
    ");
    $stmt->execute([$variant['id'], $variant['name'], $matchedLine['name'], $hardwareInfoId]);

    // 7. Load expected partitions
    $stmt = $pdo->prepare("
        SELECT * FROM product_variant_partitions
        WHERE variant_id = ?
        ORDER BY partition_order
    ");
    $stmt->execute([$variant['id']]);
    $expectedPartitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expectedPartitions)) {
        return null; // Variant exists but no partition template defined
    }

    // 8. Get actual partitions from boot disk
    $actualPartitions = $bootDisk['partitions'] ?? [];

    // 9. Compare partition by partition
    $details = [];
    $allPass = true;
    $expectedCount = count($expectedPartitions);
    $actualCount = count($actualPartitions);

    if ($actualCount !== $expectedCount) {
        $allPass = false;
        $details[] = "Partition count mismatch: expected {$expectedCount}, found {$actualCount}";
    }

    foreach ($expectedPartitions as $i => $exp) {
        $act = $actualPartitions[$i] ?? null;
        $expName = $exp['partition_name'];
        $expSizeMb = (int) $exp['expected_size_mb'];
        $tolerancePct = (float) $exp['tolerance_percent'];
        $isFlexible = (int) $exp['is_flexible'];

        if (!$act) {
            $details[] = "#{$exp['partition_order']} {$expName}: MISSING (expected {$expSizeMb} MB)";
            $allPass = false;
            continue;
        }

        // Calculate actual size in MB
        $actualSizeMb = 0;
        if (isset($act['size_mb'])) {
            $actualSizeMb = (int) round((float) $act['size_mb']);
        } elseif (isset($act['size_bytes'])) {
            $actualSizeMb = (int) round((float) $act['size_bytes'] / 1048576);
        } elseif (isset($act['size_gb'])) {
            $actualSizeMb = (int) round((float) $act['size_gb'] * 1024);
        }

        if ($actualSizeMb <= 0 && $expSizeMb > 0) {
            $details[] = "#{$exp['partition_order']} {$expName}: FAIL (size unknown, expected {$expSizeMb} MB)";
            $allPass = false;
            continue;
        }

        // Check size tolerance
        if ($isFlexible) {
            // Flexible partition (Data): actual must be >= expected
            if ($actualSizeMb >= $expSizeMb) {
                $details[] = "#{$exp['partition_order']} {$expName}: OK ({$actualSizeMb} MB >= {$expSizeMb} MB)";
            } else {
                $deficit = $expSizeMb - $actualSizeMb;
                $details[] = "#{$exp['partition_order']} {$expName}: FAIL ({$actualSizeMb} MB < {$expSizeMb} MB, short by {$deficit} MB)";
                $allPass = false;
            }
        } else {
            // Fixed partition: check within tolerance %
            if ($expSizeMb > 0) {
                $deviation = abs($actualSizeMb - $expSizeMb);
                $deviationPct = ($deviation / $expSizeMb) * 100;
                if ($deviationPct <= $tolerancePct) {
                    $details[] = "#{$exp['partition_order']} {$expName}: OK ({$actualSizeMb} MB, deviation " . round($deviationPct, 1) . "% within {$tolerancePct}%)";
                } else {
                    $details[] = "#{$exp['partition_order']} {$expName}: FAIL ({$actualSizeMb} MB, deviation " . round($deviationPct, 1) . "% exceeds {$tolerancePct}%)";
                    $allPass = false;
                }
            } else {
                $details[] = "#{$exp['partition_order']} {$expName}: OK (size check skipped, expected 0)";
            }
        }
    }

    // 10. Build result
    $result = $allPass ? 'pass' : qcEnforcementToResult($enforcement);
    $detailStr = implode("\n", $details);
    $message = $allPass
        ? "Partition layout matches '{$variant['name']}' template ({$expectedCount} partitions)"
        : "Partition layout does not match '{$variant['name']}' template:\n{$detailStr}";

    $check = [
        'check_type'        => 'partition_layout',
        'check_result'      => $result,
        'enforcement_level' => $enforcement,
        'expected_value'    => $matchedLine['name'] . ' / ' . $variant['name'] . ' (' . $expectedCount . ' partitions)',
        'actual_value'      => $actualCount . ' partitions on ' . $diskSizeMb . ' MB disk',
        'message'           => $message,
        'rule_source'       => 'global',
    ];
    qcInsertResult($pdo, $hardwareInfoId, $orderNumber, $check, $registryId);
    return $check;
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
 * Count how many hardware records would be affected by a recheck.
 * Used for preview/confirmation before running the actual recheck.
 */
function qcRecheckCount(PDO $pdo, ?string $manufacturer = null, ?string $product = null): int {
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
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM hardware_info $whereClause");
    $stmt->execute($params);
    return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
}

/**
 * Re-run compliance checks on historical hardware records.
 * Processes in batches with a hard limit per call for safety.
 * Returns batch stats + cursor for pagination.
 *
 * @param int $batchSize Max records per call (capped at 100)
 * @param int $afterId   Process records with id > afterId (cursor for batching)
 */
function qcRecheckHistorical(PDO $pdo, ?string $manufacturer = null, ?string $product = null, int $batchSize = 50, int $afterId = 0): array {
    // Hard cap batch size to prevent abuse
    $batchSize = min(max($batchSize, 1), 100);

    // Build query to find matching hardware records
    $where = ["id > ?"];
    $params = [$afterId];
    if (!empty($manufacturer)) {
        $where[] = "motherboard_manufacturer = ?";
        $params[] = $manufacturer;
    }
    if (!empty($product)) {
        $where[] = "motherboard_product = ?";
        $params[] = $product;
    }
    $whereClause = "WHERE " . implode(" AND ", $where);

    $stmt = $pdo->prepare("SELECT id, order_number, motherboard_manufacturer, motherboard_product, bios_version, secure_boot_enabled, hackbgrt_installed, hackbgrt_first_boot, complete_disk_layout, missing_drivers, missing_drivers_count FROM hardware_info $whereClause ORDER BY id ASC LIMIT ?");
    $params[] = $batchSize;
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = ['rechecked' => 0, 'passed' => 0, 'failed' => 0, 'warnings' => 0];
    $lastId = $afterId;

    foreach ($records as $hw) {
        $hwId = (int) $hw['id'];
        $lastId = $hwId;

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
            'complete_disk_layout'   => $hw['complete_disk_layout'],
            'missing_drivers'        => $hw['missing_drivers'],
            'missing_drivers_count'  => $hw['missing_drivers_count'],
        ];

        // Use a modified version that marks results as retroactive
        $result = qcRunChecksRetroactive($pdo, $hwId, $hwData);
        $stats['rechecked']++;
        if ($result['has_blocking']) $stats['failed']++;
        elseif ($result['has_warnings']) $stats['warnings']++;
        else $stats['passed']++;
    }

    $stats['last_id'] = $lastId;
    $stats['has_more'] = count($records) === $batchSize;

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

    $rules = qcGetEffectiveRules($pdo, $manufacturer, $product, $orderNumber);
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

    // Missing Drivers check (retroactive)
    $driverCheck = qcCheckMissingDrivers($pdo, $hardwareInfoId, $orderNumber, $hw, $rules, $ruleSource, $registryId);
    if ($driverCheck !== null) {
        $checks[] = $driverCheck;
        if ($driverCheck['check_result'] === 'fail') $hasBlocking = true;
        if ($driverCheck['check_result'] === 'warning') $hasWarnings = true;
    }

    // Partition Layout check (retroactive — uses stored complete_disk_layout)
    $partitionCheck = qcCheckPartitionLayout($pdo, $hardwareInfoId, $orderNumber, $hw, $registryId, $rules);
    if ($partitionCheck !== null) {
        $checks[] = $partitionCheck;
        if ($partitionCheck['check_result'] === 'fail') $hasBlocking = true;
        if ($partitionCheck['check_result'] === 'warning') $hasWarnings = true;
    }

    return ['checks' => $checks, 'has_blocking' => $hasBlocking, 'has_warnings' => $hasWarnings];
}
