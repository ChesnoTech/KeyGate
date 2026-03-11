<?php
/**
 * BIOS Version Comparison Helpers
 *
 * Handles version comparison across all major motherboard/OEM manufacturers.
 * Each brand uses a different versioning scheme, so we detect the brand
 * from WMI fields and apply brand-specific parsing.
 *
 * Primary comparison: bios_release_date (universal, always reliable)
 * Secondary: manufacturer-specific version string parsing
 */

/**
 * Detect the board manufacturer brand from BIOS/motherboard fields.
 *
 * @param string|null $biosManufacturer   Win32_BIOS.Manufacturer (e.g. "American Megatrends Inc.")
 * @param string|null $biosVersion        Win32_BIOS.SMBIOSBIOSVersion
 * @param string|null $mbManufacturer     Win32_BaseBoard.Manufacturer
 * @param string|null $sysManufacturer    Win32_ComputerSystem.Manufacturer
 * @return string  Brand identifier (asus, msi, gigabyte, dell, hp, lenovo, etc.)
 */
function detectBiosBrand(?string $biosManufacturer, ?string $biosVersion, ?string $mbManufacturer = null, ?string $sysManufacturer = null): string {
    $hints = strtolower(implode(' ', array_filter([$mbManufacturer, $sysManufacturer, $biosManufacturer, $biosVersion])));

    if (preg_match('/\basus\b|republic of gamers|rog\b/', $hints)) return 'asus';
    if (preg_match('/\bmsi\b|micro[- ]?star/', $hints)) return 'msi';
    if (preg_match('/\bgigabyte\b|giga-byte|aorus/', $hints)) return 'gigabyte';
    if (preg_match('/\basrock\b/', $hints)) return 'asrock';
    if (preg_match('/\bbiostar\b/', $hints)) return 'biostar';
    if (preg_match('/\bevga\b/', $hints)) return 'evga';
    if (preg_match('/\bdell\b/', $hints)) return 'dell';
    if (preg_match('/\bhp\b|\bhewlett[- ]?packard\b/', $hints)) return 'hp';
    if (preg_match('/\blenovo\b/', $hints)) return 'lenovo';
    if (preg_match('/\bacer\b/', $hints)) return 'acer';
    if (preg_match('/\btoshiba\b|\bdynabook\b/', $hints)) return 'toshiba';
    if (preg_match('/\bsupermicro\b/', $hints)) return 'supermicro';
    if (preg_match('/\bintel\b/', $hints) && !preg_match('/\bamerican megatrends\b/', $hints)) return 'intel';

    // Version-string heuristics
    $v = trim($biosVersion ?? '');
    if (preg_match('/^F\d+/i', $v)) return 'gigabyte';
    if (preg_match('/^A\d{2}$/', $v)) return 'dell';
    if (preg_match('/^[PL]\d+\.\d+$/i', $v)) return 'asrock';
    if (preg_match('/^V\d+\.\d+$/', $v)) return 'acer';

    return 'unknown';
}

/**
 * Parse a BIOS version string into a numeric sortable value.
 *
 * @param string      $version         The raw BIOS version string
 * @param string      $brand           Brand identifier from detectBiosBrand()
 * @return array{numeric: float, beta: bool, display: string}
 */
function parseBiosVersionNumeric(string $version, string $brand): array {
    $v = trim($version);
    $result = ['numeric' => 0.0, 'beta' => false, 'display' => $v];

    switch ($brand) {
        case 'asus':
            // 4-digit integer: "0405", "2803"
            $result['numeric'] = (float)intval($v, 10);
            break;

        case 'msi':
            // "1.80" or "E7C94AMS.180"
            if (preg_match('/(\d+\.\d+)/', $v, $m)) {
                $result['numeric'] = (float)$m[1] * 100;
                $result['display'] = $m[1];
            } elseif (preg_match('/\.(\d{3})$/', $v, $m)) {
                $dec = intval($m[1]) / 100;
                $result['numeric'] = $dec * 100;
                $result['display'] = number_format($dec, 2);
            }
            break;

        case 'gigabyte':
            // "F15", "F31d" (lowercase = beta), "FB" (legacy)
            if (preg_match('/^F(\d+)([a-z])?$/i', $v, $m)) {
                $major = intval($m[1]);
                $letter = isset($m[2]) ? strtolower($m[2]) : null;
                $result['numeric'] = $major * 100 + ($letter ? ord($letter) - 96 : 99);
                $result['beta'] = $letter !== null;
                $result['display'] = $letter ? "F{$major} (beta {$letter})" : "F{$major}";
            } elseif (preg_match('/^F([A-Z])$/i', $v, $m)) {
                $result['numeric'] = ord(strtoupper($m[1])) - 64;
            }
            break;

        case 'asrock':
            // "P1.40", "L2.62", or plain "4.03"
            if (preg_match('/^([A-Z])?(\d+\.\d+)$/i', $v, $m)) {
                $prefix = strtoupper($m[1] ?? '');
                $decimal = (float)$m[2];
                $prefixWeight = $prefix ? (ord($prefix) - 64) * 10000 : 0;
                $result['numeric'] = $prefixWeight + $decimal * 100;
            }
            break;

        case 'biostar':
            // "VKB0618B" — middle 4 digits
            if (preg_match('/^[A-Z]{3}(\d{4})[A-Z]?$/i', $v, $m)) {
                $result['numeric'] = (float)intval($m[1]);
            }
            break;

        case 'evga':
            // "P08", "P10"
            if (preg_match('/^P(\d+)$/i', $v, $m)) {
                $result['numeric'] = (float)intval($m[1]);
            }
            break;

        case 'dell':
            // "A00", "A25"
            if (preg_match('/^A(\d+)$/i', $v, $m)) {
                $result['numeric'] = (float)intval($m[1]);
            }
            break;

        case 'hp':
            // Modern "01.06.00" or Legacy "F.1B"
            if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $v, $m)) {
                $result['numeric'] = intval($m[1]) * 10000 + intval($m[2]) * 100 + intval($m[3]);
            } elseif (preg_match('/^F\.([0-9A-Fa-f]+)$/', $v, $m)) {
                $result['numeric'] = (float)hexdec($m[1]);
            }
            break;

        case 'lenovo':
            // "N2XET27W (1.17)" or 9-char "FBKTD7AUS"
            if (preg_match('/\((\d+\.\d+)\)/', $v, $m)) {
                $result['numeric'] = (float)$m[1] * 100;
                $result['display'] = $m[1];
            } elseif (preg_match('/^[A-Z0-9]{7,9}$/i', $v) && strlen($v) >= 7) {
                $verChars = substr($v, 4, 3);
                $num = 0;
                for ($i = 0; $i < strlen($verChars); $i++) {
                    $c = ord($verChars[$i]);
                    if ($c >= 48 && $c <= 57) $digit = $c - 48;
                    elseif ($c >= 65 && $c <= 90) $digit = $c - 55;
                    elseif ($c >= 97 && $c <= 122) $digit = $c - 87;
                    else $digit = 0;
                    $num = $num * 36 + $digit;
                }
                $result['numeric'] = (float)$num;
                $result['display'] = "$v [$verChars]";
            }
            break;

        case 'acer':
            // "V1.08"
            if (preg_match('/^V?(\d+\.\d+)$/i', $v, $m)) {
                $result['numeric'] = (float)$m[1] * 100;
            }
            break;

        case 'toshiba':
        case 'supermicro':
            // "6.80" or "3.7"
            if (preg_match('/^(\d+)\.(\d+)$/', $v, $m)) {
                $result['numeric'] = intval($m[1]) * 100 + intval($m[2]);
            }
            break;

        case 'intel':
            // "JYGLKCPX.86A.0049.2019.0401.1038"
            $parts = explode('.', $v);
            if (count($parts) >= 5) {
                $build = intval($parts[2]);
                $year = intval($parts[3]);
                $mmdd = intval($parts[4]);
                $result['numeric'] = $year * 100000 + $mmdd * 10 + $build;
                $result['display'] = "Build {$parts[2]} ({$parts[3]}.{$parts[4]})";
            }
            break;

        default:
            // Generic: try to extract any number
            if (preg_match('/(\d+(?:\.\d+)?)/', $v, $m)) {
                $result['numeric'] = (float)$m[1] * 100;
            }
            break;
    }

    return $result;
}

/**
 * Parse a WMI CIM_DATETIME string into a Unix timestamp.
 * Format: "20240327000000.000000+000"
 * Also handles "YYYY-MM-DD" and "MM/DD/YYYY".
 *
 * @param string|null $wmiDate
 * @return int|null  Unix timestamp or null
 */
function parseBiosReleaseDate(?string $wmiDate): ?int {
    if (!$wmiDate) return null;
    $d = trim($wmiDate);
    if (!$d) return null;

    // WMI CIM_DATETIME: YYYYMMDDHHMMSS.MMMMMM±OOO
    if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $d, $m)) {
        $ts = mktime(0, 0, 0, intval($m[2]), intval($m[3]), intval($m[1]));
        return $ts !== false ? $ts : null;
    }

    // ISO: YYYY-MM-DD or YYYY/MM/DD
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $d, $m)) {
        $ts = mktime(0, 0, 0, intval($m[2]), intval($m[3]), intval($m[1]));
        return $ts !== false ? $ts : null;
    }

    // US format: MM/DD/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $d, $m)) {
        $ts = mktime(0, 0, 0, intval($m[1]), intval($m[2]), intval($m[3]));
        return $ts !== false ? $ts : null;
    }

    return null;
}

/**
 * Format a BIOS release date for human display.
 *
 * @param string|null $wmiDate  WMI CIM_DATETIME or other date format
 * @return string|null  "YYYY-MM-DD" or null
 */
function formatBiosDatePHP(?string $wmiDate): ?string {
    $ts = parseBiosReleaseDate($wmiDate);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Compare two BIOS versions.
 * Returns negative if A is older, positive if A is newer, 0 if equal.
 *
 * @param array $a  ['bios_manufacturer', 'bios_version', 'bios_release_date', 'motherboard_manufacturer'?, 'system_manufacturer'?]
 * @param array $b  Same structure
 * @return int
 */
function compareBiosVersions(array $a, array $b): int {
    // Step 1: Compare by release date (most reliable)
    $dateA = parseBiosReleaseDate($a['bios_release_date'] ?? null);
    $dateB = parseBiosReleaseDate($b['bios_release_date'] ?? null);

    if ($dateA !== null && $dateB !== null) {
        $diff = $dateA - $dateB;
        if ($diff !== 0) return $diff;
    }

    // Step 2: Compare by version string (brand-specific)
    $brandA = detectBiosBrand(
        $a['bios_manufacturer'] ?? null,
        $a['bios_version'] ?? null,
        $a['motherboard_manufacturer'] ?? null,
        $a['system_manufacturer'] ?? null
    );
    $brandB = detectBiosBrand(
        $b['bios_manufacturer'] ?? null,
        $b['bios_version'] ?? null,
        $b['motherboard_manufacturer'] ?? null,
        $b['system_manufacturer'] ?? null
    );

    if ($brandA === $brandB && ($a['bios_version'] ?? null) && ($b['bios_version'] ?? null)) {
        $parsedA = parseBiosVersionNumeric($a['bios_version'], $brandA);
        $parsedB = parseBiosVersionNumeric($b['bios_version'], $brandB);
        $diff = $parsedA['numeric'] - $parsedB['numeric'];
        if ($diff != 0) return $diff > 0 ? 1 : -1;
    }

    // Step 3: Fallback — prefer the one with a date
    if ($dateA !== null && $dateB === null) return 1;
    if ($dateA === null && $dateB !== null) return -1;

    return 0;
}
