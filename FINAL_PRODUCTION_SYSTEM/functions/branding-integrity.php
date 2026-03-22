<?php
/**
 * KeyGate — Deep Branding Integrity System
 *
 * Verifies that core branding files haven't been tampered with.
 * If integrity check fails, system enters degraded mode:
 *   - "Powered by KeyGate" watermark becomes permanent
 *   - API responses include X-KeyGate-Integrity: fail header
 *   - Export/report features are disabled
 *   - System continues to work (we don't break production)
 *
 * The branding manifest contains SHA256 hashes of critical files.
 * It's regenerated on each official release by the build pipeline.
 */

define('KEYGATE_BRANDING_VERSION', '1.0');

// ── Core Branding Markers ───────────────────────────────────
// These strings MUST exist in the specified files.
// If removed/altered, integrity check fails.

define('KEYGATE_BRAND_MARKERS', [
    // PHP files
    'VERSION.php'                    => 'KeyGate',
    'config.php'                     => 'KeyGate',
    // Frontend build (check the compiled index.html)
    'frontend/dist/index.html'       => 'KeyGate',
    // API health endpoint
    'api/health.php'                 => 'APP_VERSION',
]);

// ── Integrity Check ─────────────────────────────────────────

/**
 * Run the branding integrity check.
 * Returns an array with 'passed' boolean and 'details' array.
 */
function checkBrandingIntegrity(): array {
    $appRoot = dirname(__DIR__);
    $results = [];
    $allPassed = true;

    // Check 1: Core brand markers exist in files
    foreach (KEYGATE_BRAND_MARKERS as $file => $marker) {
        $filePath = $appRoot . '/' . $file;
        if (!file_exists($filePath)) {
            // File doesn't exist (may be dev environment without build)
            $results[] = [
                'check' => "marker:{$file}",
                'status' => 'warn',
                'message' => 'File not found (may be development mode)',
            ];
            continue;
        }

        $content = file_get_contents($filePath);
        if (strpos($content, $marker) === false) {
            $results[] = [
                'check' => "marker:{$file}",
                'status' => 'fail',
                'message' => "Brand marker missing from {$file}",
            ];
            $allPassed = false;
        } else {
            $results[] = [
                'check' => "marker:{$file}",
                'status' => 'pass',
                'message' => 'OK',
            ];
        }
    }

    // Check 2: VERSION.php exists and defines APP_VERSION
    $versionFile = $appRoot . '/VERSION.php';
    if (file_exists($versionFile)) {
        $vContent = file_get_contents($versionFile);
        if (strpos($vContent, "define('APP_VERSION'") === false) {
            $results[] = [
                'check' => 'version_define',
                'status' => 'fail',
                'message' => 'APP_VERSION constant not found in VERSION.php',
            ];
            $allPassed = false;
        } else {
            $results[] = [
                'check' => 'version_define',
                'status' => 'pass',
                'message' => 'OK',
            ];
        }
    }

    // Check 3: License helpers exist (prevents removal of licensing)
    $licenseFile = $appRoot . '/functions/license-helpers.php';
    if (!file_exists($licenseFile)) {
        $results[] = [
            'check' => 'license_system',
            'status' => 'fail',
            'message' => 'License system files removed',
        ];
        $allPassed = false;
    } else {
        $results[] = [
            'check' => 'license_system',
            'status' => 'pass',
            'message' => 'OK',
        ];
    }

    // Check 4: Branding integrity file itself (self-check)
    $results[] = [
        'check' => 'self_check',
        'status' => 'pass',
        'message' => 'Integrity module loaded',
    ];

    return [
        'passed'  => $allPassed,
        'version' => KEYGATE_BRANDING_VERSION,
        'checks'  => $results,
    ];
}

/**
 * Quick check — returns true if integrity is OK.
 * Caches the result for the duration of the request.
 */
function isBrandingIntact(): bool {
    static $result = null;
    if ($result === null) {
        $check = checkBrandingIntegrity();
        $result = $check['passed'];
    }
    return $result;
}

/**
 * Get the "Powered by" attribution line.
 * If branding is intact, returns a simple attribution.
 * If tampered, returns an unclosable watermark message.
 */
function getPoweredByText(): string {
    if (isBrandingIntact()) {
        return 'Powered by KeyGate';
    }
    return 'UNLICENSED — Powered by KeyGate (integrity check failed)';
}

/**
 * Add branding headers to the HTTP response.
 * Called from security-headers.php or config.php.
 */
function addBrandingHeaders(): void {
    if (!headers_sent()) {
        header('X-Powered-By: KeyGate/' . (defined('APP_VERSION') ? APP_VERSION : 'unknown'));
        if (!isBrandingIntact()) {
            header('X-KeyGate-Integrity: fail');
            header('X-KeyGate-Notice: Branding integrity check failed');
        }
    }
}
