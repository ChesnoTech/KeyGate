<?php
/**
 * Detect USB Devices API
 *
 * Uses WMI to enumerate physically attached USB flash drives.
 * Works on native Windows servers (PowerShell/COM) and returns a helpful
 * error on Linux/Docker containers where WMI is unavailable.
 *
 * Detection methods (in priority order):
 *   1. Hardware Bridge extension (browser-side, not handled here)
 *   2. PowerShell WMI query (Windows only)
 *   3. COM WMI query (Windows + COM extension)
 *   4. Fallback: returns instructions to use client-side detection
 */

header('Content-Type: application/json');

// Security check - only allow from localhost, local network, or Docker bridge
$allowed_hosts = ['localhost', '127.0.0.1', '::1'];
$remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

// Allow localhost, private 192.168.x.x, and Docker bridge 172.x.x.x ranges
$isAllowed = in_array($remote_addr, $allowed_hosts)
    || str_starts_with($remote_addr, '192.168.')
    || str_starts_with($remote_addr, '172.16.')
    || str_starts_with($remote_addr, '172.17.')
    || str_starts_with($remote_addr, '172.18.')
    || str_starts_with($remote_addr, '172.19.')
    || str_starts_with($remote_addr, '172.2')
    || str_starts_with($remote_addr, '172.3')
    || str_starts_with($remote_addr, '10.');

if (!$isAllowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// CORS - restrict to known origins only (no wildcard)
$allowedOrigins = [
    'http://localhost:8080',
    'https://localhost:8443',
    'http://127.0.0.1:8080',
    'https://127.0.0.1:8443'
];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    // For same-origin requests (no Origin header), allow by default
    // but don't set a wildcard CORS header
}

/**
 * Check if the server environment supports Windows-based detection
 */
function isWindowsServer() {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

/**
 * Detect USB flash drives using WMI via PowerShell
 *
 * @return array List of USB devices with their properties
 */
function detectUSBDevices() {
    $devices = [];

    if (!isWindowsServer()) {
        return $devices; // PowerShell/WMI not available on Linux
    }

    // PowerShell script to enumerate USB drives
    $psScript = <<<'POWERSHELL'
Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | ForEach-Object {
    $disk = $_
    $partition = Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskDrive.DeviceID='$($disk.DeviceID)'} WHERE AssocClass = Win32_DiskDriveToDiskPartition"
    $volume = $partition | ForEach-Object { Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskPartition.DeviceID='$($_.DeviceID)'} WHERE AssocClass = Win32_LogicalDiskToPartition" }

    # Extract real serial from PNPDeviceID (WMI SerialNumber is often unreliable for USB)
    # PNPDeviceID format: USBSTOR\DISK&VEN_xxx&PROD_xxx&REV_xxx\SERIAL&0
    $pnpSerial = $disk.SerialNumber.Trim()
    if ($disk.PNPDeviceID -match '\\([^\\]+)$') {
        $extracted = $matches[1] -replace '&\d+$', ''
        if ($extracted.Length -gt 2) { $pnpSerial = $extracted }
    }

    $device = @{
        SerialNumber = $pnpSerial
        Model = $disk.Model.Trim()
        Manufacturer = $disk.Caption -replace $disk.Model, '' -replace 'USB Device', '' -replace '\s+', ' ' | ForEach-Object { $_.Trim() }
        Size = [Math]::Round($disk.Size / 1GB, 2)
        InterfaceType = $disk.InterfaceType
        MediaType = $disk.MediaType
        DriveLetter = if ($volume) { $volume.DeviceID } else { '' }
        VolumeName = if ($volume) { $volume.VolumeName } else { '' }
        Status = $disk.Status
    }

    # Output as JSON
    $device | ConvertTo-Json -Compress
}
POWERSHELL;

    try {
        // Execute PowerShell script
        $command = 'powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . str_replace('"', '\"', $psScript) . '"';
        $output = shell_exec($command);

        if ($output) {
            // Parse each line as JSON
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                $line = trim($line);
                if (!empty($line) && $line[0] === '{') {
                    $device = json_decode($line, true);
                    if ($device && isset($device['SerialNumber'])) {
                        // Clean up manufacturer name
                        $manufacturer = $device['Manufacturer'];
                        if (empty($manufacturer) || $manufacturer === 'USB Device') {
                            $modelParts = explode(' ', $device['Model']);
                            $manufacturer = $modelParts[0] ?? 'Unknown';
                        }

                        $devices[] = [
                            'serial_number' => $device['SerialNumber'],
                            'model' => $device['Model'],
                            'manufacturer' => $manufacturer,
                            'capacity_gb' => $device['Size'],
                            'drive_letter' => $device['DriveLetter'],
                            'volume_name' => $device['VolumeName'],
                            'status' => $device['Status'],
                            'suggested_name' => !empty($device['VolumeName'])
                                ? $device['VolumeName']
                                : $device['Model']
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("USB detection error: " . $e->getMessage());
    }

    return $devices;
}

// Alternative method using COM (if PowerShell fails)
function detectUSBDevicesCOM() {
    $devices = [];

    if (!isWindowsServer() || !class_exists('COM')) {
        return $devices;
    }

    try {
        $wmi = new COM('WbemScripting.SWbemLocator');
        $service = $wmi->ConnectServer('.', 'root\cimv2');
        $disks = $service->ExecQuery("SELECT * FROM Win32_DiskDrive WHERE InterfaceType = 'USB'");

        foreach ($disks as $disk) {
            $devices[] = [
                'serial_number' => trim($disk->SerialNumber),
                'model' => trim($disk->Model),
                'manufacturer' => trim($disk->Caption),
                'capacity_gb' => round($disk->Size / (1024 * 1024 * 1024), 2),
                'status' => $disk->Status,
                'suggested_name' => trim($disk->Model)
            ];
        }
    } catch (Exception $e) {
        error_log("USB detection COM error: " . $e->getMessage());
    }

    return $devices;
}

// Check if running on a platform that supports server-side USB detection
if (!isWindowsServer()) {
    // Running in Docker/Linux — server-side USB detection is not possible
    // Return helpful guidance to use client-side detection methods
    echo json_encode([
        'success' => true,
        'devices' => [],
        'count' => 0,
        'method' => 'unavailable',
        'server_os' => PHP_OS,
        'message' => 'Server-side USB detection requires Windows. Use the Hardware Bridge extension or PowerShell on the admin workstation for USB device detection.',
        'alternatives' => [
            'hardware_bridge' => 'Install the OEM Hardware Bridge Chrome extension for automatic detection',
            'powershell' => 'Run: Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq \'USB\' } | Select SerialNumber,Model',
            'local_script' => 'Run detect-local-usb.ps1 on the admin workstation'
        ]
    ]);
    exit;
}

// Windows server: detect devices
$devices = detectUSBDevices();

// If PowerShell failed, try COM method
if (empty($devices) && class_exists('COM')) {
    $devices = detectUSBDevicesCOM();
}

// Determine which method succeeded
$method = 'none';
if (!empty($devices)) {
    $method = class_exists('COM') && empty(detectUSBDevices()) ? 'com' : 'powershell';
}

// Return results
echo json_encode([
    'success' => true,
    'devices' => $devices,
    'count' => count($devices),
    'method' => $method
]);
