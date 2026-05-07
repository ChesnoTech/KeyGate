<?php
/**
 * KeyGate — Server-side hardware fingerprint (P1 anti-piracy)
 *
 * Computes a cross-OS composite SHA256 fingerprint of host-stable
 * hardware identifiers. Bound into license_info.hardware_fingerprint
 * by registerLicense() and verified on every getEffectiveLicense().
 *
 * Components (each best-effort; missing components contribute the
 * empty string, never crash the install):
 *   - machine_id      — /etc/machine-id (Linux), MachineGuid registry (Windows)
 *   - system_uuid     — dmidecode -s system-uuid (Linux), Win32_ComputerSystemProduct.UUID (Windows)
 *   - primary_mac     — first non-loopback NIC MAC, normalised lowercase
 *   - root_volume_uuid — root volume UUID (blkid Linux, vol C: Windows)
 *   - host_os         — 'linux' or 'windows' (so Linux↔Windows host migration is detected)
 *
 * Composite: hash('sha256', join('|', [machine_id, system_uuid, primary_mac, root_volume_uuid, host_os]))
 *
 * Cache: stored in system_config('server_hwfp') as JSON. Recompute only
 * on force=true (admin clicks "Re-detect hardware") or after a successful
 * Worker /api/rebind. The shell-out cost (~50–200ms) is non-trivial and
 * hardware identifiers don't change on reboot.
 *
 * Spoof resistance: a 3-of-5 component-match threshold (compareHwfp())
 * tolerates legitimate single-component changes (NIC swap during RMA,
 * disk replaced) without forcing a rebind. Patching all 5 components
 * requires admin tooling on every clone — a meaningful work factor.
 */

/**
 * Read /etc/machine-id (Linux) or Windows MachineGuid registry value.
 * Returns lowercase hex string with no dashes/braces, or '' if unreachable.
 */
function hwfpMachineId(): string {
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('reg query "HKLM\\SOFTWARE\\Microsoft\\Cryptography" /v MachineGuid 2>nul');
        if ($out && preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-fA-F-]+)/', $out, $m)) {
            return strtolower(str_replace(['-', '{', '}'], '', $m[1]));
        }
        return '';
    }
    // Linux: /etc/machine-id is the canonical 32-char id
    foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $p) {
        if (is_readable($p)) {
            $v = trim((string)@file_get_contents($p));
            if ($v !== '' && preg_match('/^[0-9a-f]{32}$/', $v)) return $v;
        }
    }
    return '';
}

/**
 * System UUID — DMI / SMBIOS provided. Stable across reboots, OS reinstalls.
 */
function hwfpSystemUuid(): string {
    if (PHP_OS_FAMILY === 'Windows') {
        // PowerShell is far cleaner than wmic for parsing.
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-CimInstance Win32_ComputerSystemProduct).UUID"';
        $out = @shell_exec($cmd);
        if ($out) {
            $v = strtolower(trim($out));
            if (preg_match('/^[0-9a-f-]{32,36}$/', $v)) return str_replace('-', '', $v);
        }
        return '';
    }
    // Linux: prefer /sys/class/dmi over shell-out for unprivileged hosts.
    $sys = '/sys/class/dmi/id/product_uuid';
    if (is_readable($sys)) {
        $v = strtolower(trim((string)@file_get_contents($sys)));
        if ($v !== '' && $v !== '00000000-0000-0000-0000-000000000000') {
            return str_replace('-', '', $v);
        }
    }
    // Fallback: dmidecode (often requires root, may fail silently)
    $out = @shell_exec('dmidecode -s system-uuid 2>/dev/null');
    if ($out) {
        $v = strtolower(trim($out));
        if (preg_match('/^[0-9a-f-]{32,36}$/', $v)) return str_replace('-', '', $v);
    }
    return '';
}

/**
 * Primary network MAC — first non-loopback, non-virtual interface.
 * Normalised to lowercase no-separator hex.
 */
function hwfpPrimaryMac(): string {
    if (PHP_OS_FAMILY === 'Windows') {
        // Build PS command using a heredoc-like approach: PowerShell sees
        // single-quoted 'Up'; PHP doesn't escape it (we use double-quoted PHP
        // strings with no $-vars or escapes that PowerShell would misread).
        $psScript = "(Get-NetAdapter | Where-Object { \$_.Status -eq 'Up' -and \$_.HardwareInterface -eq \$true }"
                  . " | Sort-Object -Property ifIndex | Select-Object -First 1).MacAddress";
        $cmd = 'powershell -NoProfile -ExecutionPolicy Bypass -Command "' . $psScript . '"';
        $out = @shell_exec($cmd);
        if ($out) {
            $v = strtolower(trim($out));
            $v = preg_replace('/[^0-9a-f]/', '', $v);
            if (strlen($v) === 12) return $v;
        }
        return '';
    }
    // Linux: walk /sys/class/net, skip loopback / docker / virtual.
    $skipPrefixes = ['lo', 'docker', 'br-', 'veth', 'virbr', 'kube'];
    $dirs = @glob('/sys/class/net/*');
    if (!$dirs) return '';
    sort($dirs);
    foreach ($dirs as $d) {
        $name = basename($d);
        foreach ($skipPrefixes as $p) {
            if (strpos($name, $p) === 0) continue 2;
        }
        $macFile = $d . '/address';
        if (is_readable($macFile)) {
            $v = strtolower(trim((string)@file_get_contents($macFile)));
            $v = preg_replace('/[^0-9a-f]/', '', $v);
            if (strlen($v) === 12 && $v !== '000000000000') return $v;
        }
    }
    return '';
}

/**
 * Root volume UUID. On Linux: blkid for the device backing /. On Windows:
 * `vol C:` — first volume serial in 8-hex format.
 */
function hwfpRootVolumeUuid(): string {
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('vol C: 2>nul');
        if ($out && preg_match('/Serial Number is\s+([0-9A-F-]+)/i', $out, $m)) {
            return strtolower(str_replace('-', '', $m[1]));
        }
        return '';
    }
    // Linux: findmnt → blkid
    $src = trim((string)@shell_exec("findmnt -no SOURCE / 2>/dev/null"));
    if ($src !== '') {
        $u = trim((string)@shell_exec('blkid -s UUID -o value ' . escapeshellarg($src) . ' 2>/dev/null'));
        if ($u !== '') return strtolower(str_replace('-', '', $u));
    }
    return '';
}

/**
 * Compute the full hwfp tuple (components + composite hash).
 * Pure function — no PDO, no caching.
 */
function computeServerHwfp(): array {
    $components = [
        'machine_id'       => hwfpMachineId(),
        'system_uuid'      => hwfpSystemUuid(),
        'primary_mac'      => hwfpPrimaryMac(),
        'root_volume_uuid' => hwfpRootVolumeUuid(),
        'host_os'          => strtolower(PHP_OS_FAMILY),
    ];
    $material = implode('|', [
        $components['machine_id'],
        $components['system_uuid'],
        $components['primary_mac'],
        $components['root_volume_uuid'],
        $components['host_os'],
    ]);
    return [
        'components'    => $components,
        'composite'     => hash('sha256', $material),
        'computed_at'   => date('c'),
    ];
}

/**
 * Cached server hwfp; recompute on $force=true. Stored as JSON in
 * system_config('server_hwfp').
 */
function getServerHardwareFingerprint(PDO $pdo, bool $force = false): array {
    if (!$force) {
        $cached = getConfig('server_hwfp');
        if (!empty($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && !empty($decoded['composite'])) {
                return $decoded;
            }
        }
    }
    $hwfp = computeServerHwfp();
    if (function_exists('saveConfigBatch')) {
        saveConfigBatch($pdo, ['server_hwfp' => json_encode($hwfp)]);
    }
    return $hwfp;
}

/**
 * Compare two hwfp arrays component-by-component. Returns a struct:
 *   match_count    — int 0..5
 *   total          — int 5
 *   composite_eq   — bool
 *   accepted       — bool (composite_eq OR match_count >= 3)
 *   diff_components — list of names that differ
 *
 * The 3-of-5 soft threshold is a deliberate trade-off: legitimate
 * hardware events (NIC swap, disk replacement, BIOS reset clearing UUID)
 * rarely change more than 2 components. Forging all 5 requires admin
 * tooling on every clone.
 */
function compareHwfp(array $a, array $b): array {
    $ac = $a['components'] ?? [];
    $bc = $b['components'] ?? [];
    $names = ['machine_id', 'system_uuid', 'primary_mac', 'root_volume_uuid', 'host_os'];
    $matches = 0;
    $diff = [];
    foreach ($names as $n) {
        $av = (string)($ac[$n] ?? '');
        $bv = (string)($bc[$n] ?? '');
        // Empty-string components on both sides count as a non-match
        // (defensively ignore them rather than treat them as agreement).
        if ($av !== '' && $av === $bv) {
            $matches++;
        } else {
            $diff[] = $n;
        }
    }
    $compositeEq = !empty($a['composite']) && !empty($b['composite'])
                   && hash_equals($a['composite'], $b['composite']);
    return [
        'match_count'    => $matches,
        'total'          => count($names),
        'composite_eq'   => $compositeEq,
        'accepted'       => $compositeEq || $matches >= 3,
        'diff_components'=> $diff,
    ];
}
