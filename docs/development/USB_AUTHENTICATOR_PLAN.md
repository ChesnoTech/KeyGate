# USB Authenticator Implementation Plan
## USB Device Serial Number Verification for Technician Authentication

**Feature**: USB Hardware Authentication
**Type**: Security Enhancement - Two-Factor Authentication
**Priority**: High Security
**Complexity**: Medium

---

## Overview

Add USB device serial number verification as a mandatory authentication method for technicians. Each technician will be assigned one or more USB devices (identified by their unique serial numbers) that must be inserted into the computer during activation. This provides physical security and prevents unauthorized access even if passwords are compromised.

**Key Benefits**:
- **Physical Security**: Requires physical possession of authorized USB device
- **Two-Factor Authentication**: Something you know (password) + something you have (USB device)
- **Audit Trail**: Track which USB device was used for each activation
- **Revocation**: Instantly disable lost/stolen USB devices
- **Multi-Device Support**: Each technician can have multiple authorized USB devices

---

## User Requirements

### Core Features
1. **USB Device Registration**: Admin can register USB devices and assign them to technicians
2. **Mandatory USB Check**: PowerShell client must detect authorized USB device before allowing login
3. **Serial Number Verification**: Verify USB device serial number matches authorized list
4. **Multi-Device Support**: Technicians can have multiple authorized USB devices (work USB, backup USB)
5. **Device Revocation**: Admin can disable lost/stolen USB devices instantly
6. **Activation Logging**: Record which USB device was used for each activation
7. **Device Nickname**: Admin can assign friendly names to USB devices (e.g., "John's Primary USB", "John's Backup USB")

### Security Requirements
1. **No Password Bypass**: USB authentication cannot be bypassed or disabled by technician
2. **Admin Override**: Optional setting to allow admin to disable USB auth globally (for emergency)
3. **Device Tampering Detection**: Detect if USB serial number is spoofed or cloned
4. **Session Binding**: USB must remain inserted during entire activation process
5. **Audit Logging**: All USB authentication attempts logged (success and failure)

### User Experience
1. **Clear Error Messages**: Tell technician exactly which USB device is expected
2. **Multi-Device Flexibility**: If technician has multiple authorized USBs, any one works
3. **Device Status Display**: Show technician which USB device was detected
4. **Graceful Degradation**: If USB auth disabled, fall back to password-only

---

## Technical Architecture

### Database Schema Changes

#### New Table: `usb_devices`
Stores registered USB devices and their assignments to technicians.

```sql
CREATE TABLE usb_devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_serial_number VARCHAR(255) UNIQUE NOT NULL COMMENT 'USB device serial number (from WMI)',
    device_name VARCHAR(100) NOT NULL COMMENT 'Friendly name (e.g., "John Primary USB")',
    technician_id VARCHAR(50) NOT NULL COMMENT 'Assigned technician',
    device_status ENUM('active', 'disabled', 'lost', 'stolen') DEFAULT 'active',
    device_description TEXT COMMENT 'Additional notes about device',
    registered_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    registered_by VARCHAR(50) COMMENT 'Admin who registered this device',
    last_used_date DATETIME NULL COMMENT 'Last time this USB was used for activation',
    last_used_ip VARCHAR(45) NULL COMMENT 'IP address of last use',
    disabled_date DATETIME NULL COMMENT 'When device was disabled',
    disabled_by VARCHAR(50) NULL COMMENT 'Admin who disabled this device',
    disabled_reason TEXT NULL COMMENT 'Why device was disabled',

    INDEX idx_serial (device_serial_number),
    INDEX idx_technician (technician_id),
    INDEX idx_status (device_status),

    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='USB devices for hardware authentication';
```

#### Modify Table: `activation_attempts`
Add field to track which USB device was used.

```sql
ALTER TABLE activation_attempts
ADD COLUMN usb_device_id INT NULL COMMENT 'USB device used for this activation',
ADD INDEX idx_usb_device (usb_device_id),
ADD FOREIGN KEY (usb_device_id) REFERENCES usb_devices(device_id) ON DELETE SET NULL;
```

#### Modify Table: `active_sessions`
Track USB device for session validation.

```sql
ALTER TABLE active_sessions
ADD COLUMN usb_device_id INT NULL COMMENT 'USB device used for this session',
ADD INDEX idx_usb_device (usb_device_id),
ADD FOREIGN KEY (usb_device_id) REFERENCES usb_devices(device_id) ON DELETE SET NULL;
```

#### System Configuration
Add global USB authentication settings.

```sql
INSERT INTO system_config (config_key, config_value, description) VALUES
('usb_auth_enabled', '1', 'Require USB device authentication (0=disabled, 1=enabled)'),
('usb_auth_mode', 'required', 'USB auth mode: required, optional, disabled'),
('usb_auth_session_check', '1', 'Verify USB still present during session (0=no, 1=yes)'),
('usb_auth_allow_admin_override', '0', 'Allow admin to bypass USB auth in emergency (0=no, 1=yes)');
```

---

## PowerShell Client Implementation

### 1. USB Device Detection Function

```powershell
function Get-USBDeviceSerialNumber {
    <#
    .SYNOPSIS
    Retrieves serial numbers of all connected USB storage devices
    .OUTPUTS
    Array of hashtables with DeviceID, SerialNumber, FriendlyName, Size
    #>

    try {
        # Get all USB storage devices
        $usbDevices = Get-CimInstance -Query "SELECT * FROM Win32_DiskDrive WHERE InterfaceType='USB'" -ErrorAction Stop

        $deviceList = @()

        foreach ($device in $usbDevices) {
            # Extract serial number (clean format)
            $serialNumber = $device.SerialNumber

            # Some manufacturers include extra characters, clean it up
            if ($serialNumber) {
                $serialNumber = $serialNumber.Trim()

                $deviceInfo = @{
                    DeviceID = $device.DeviceID
                    SerialNumber = $serialNumber
                    FriendlyName = $device.Caption
                    Model = $device.Model
                    Size = [math]::Round($device.Size / 1GB, 2)
                }

                $deviceList += $deviceInfo
            }
        }

        return $deviceList

    } catch {
        Write-Host "❌ Error detecting USB devices: $($_.Exception.Message)" -ForegroundColor Red
        return @()
    }
}
```

### 2. USB Authentication Function

```powershell
function Invoke-USBAuthentication {
    <#
    .SYNOPSIS
    Authenticates technician using USB device serial number
    .PARAMETER TechnicianID
    Technician ID attempting to authenticate
    .PARAMETER APIBaseURL
    Base URL for API calls
    .OUTPUTS
    Hashtable with success status and USB device info
    #>

    param(
        [string]$TechnicianID,
        [string]$APIBaseURL
    )

    Write-Host "`n╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║         USB DEVICE AUTHENTICATION             ║" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan
    Write-Host ""

    # Step 1: Check if USB auth is enabled
    try {
        $configBody = @{ technician_id = $TechnicianID }
        $configResponse = Invoke-APICall -Endpoint "get-usb-auth-config.php" -Body $configBody

        if (-not $configResponse.success) {
            Write-Host "⚠️ Cannot retrieve USB authentication configuration" -ForegroundColor Yellow
            Write-Host "Error: $($configResponse.error)" -ForegroundColor Red
            return @{ success = $false; error = "Config error" }
        }

        # If USB auth is disabled globally, skip
        if (-not $configResponse.config.usb_auth_enabled) {
            Write-Host "ℹ️ USB authentication is disabled" -ForegroundColor Gray
            return @{ success = $true; usb_required = $false }
        }

    } catch {
        Write-Host "❌ Error checking USB auth config: $($_.Exception.Message)" -ForegroundColor Red
        return @{ success = $false; error = "Network error" }
    }

    # Step 2: Detect connected USB devices
    Write-Host "🔍 Scanning for USB devices..." -ForegroundColor Cyan
    $connectedUSBs = Get-USBDeviceSerialNumber

    if ($connectedUSBs.Count -eq 0) {
        Write-Host "❌ No USB devices detected" -ForegroundColor Red
        Write-Host ""
        Write-Host "Please insert your authorized USB device and press Enter to retry..." -ForegroundColor Yellow
        Read-Host

        # Retry detection
        $connectedUSBs = Get-USBDeviceSerialNumber

        if ($connectedUSBs.Count -eq 0) {
            Write-Host "❌ Still no USB devices detected" -ForegroundColor Red
            return @{ success = $false; error = "No USB devices" }
        }
    }

    Write-Host "✓ Found $($connectedUSBs.Count) USB device(s)" -ForegroundColor Green

    # Step 3: Display detected USB devices
    Write-Host "`nDetected USB Devices:" -ForegroundColor Cyan
    for ($i = 0; $i -lt $connectedUSBs.Count; $i++) {
        $usb = $connectedUSBs[$i]
        Write-Host "  [$($i+1)] $($usb.FriendlyName)" -ForegroundColor Gray
        Write-Host "      Serial: $($usb.SerialNumber)" -ForegroundColor DarkGray
        Write-Host "      Size: $($usb.Size) GB" -ForegroundColor DarkGray
    }
    Write-Host ""

    # Step 4: Verify with API
    Write-Host "🔐 Verifying USB authorization..." -ForegroundColor Cyan

    foreach ($usb in $connectedUSBs) {
        $verifyBody = @{
            technician_id = $TechnicianID
            usb_serial_number = $usb.SerialNumber
        }

        try {
            $verifyResponse = Invoke-APICall -Endpoint "verify-usb-device.php" -Body $verifyBody

            if ($verifyResponse.success -and $verifyResponse.authorized) {
                Write-Host "✅ Authorized USB device detected!" -ForegroundColor Green
                Write-Host "   Device: $($verifyResponse.device_name)" -ForegroundColor Green
                Write-Host "   Serial: $($usb.SerialNumber)" -ForegroundColor Gray
                Write-Host ""

                return @{
                    success = $true
                    usb_required = $true
                    usb_device_id = $verifyResponse.device_id
                    usb_serial_number = $usb.SerialNumber
                    device_name = $verifyResponse.device_name
                }
            }

        } catch {
            Write-Host "⚠️ Error verifying USB: $($_.Exception.Message)" -ForegroundColor Yellow
        }
    }

    # Step 5: No authorized USB found
    Write-Host "❌ None of the detected USB devices are authorized for your account" -ForegroundColor Red
    Write-Host ""
    Write-Host "Expected USB devices for technician '$TechnicianID':" -ForegroundColor Yellow

    # Get list of authorized devices for this technician
    try {
        $listBody = @{ technician_id = $TechnicianID }
        $listResponse = Invoke-APICall -Endpoint "list-technician-usb-devices.php" -Body $listBody

        if ($listResponse.success -and $listResponse.devices.Count -gt 0) {
            foreach ($device in $listResponse.devices) {
                if ($device.device_status -eq 'active') {
                    Write-Host "  • $($device.device_name)" -ForegroundColor Cyan
                    Write-Host "    Serial: $($device.device_serial_number)" -ForegroundColor Gray
                }
            }
        } else {
            Write-Host "  (No USB devices registered for your account)" -ForegroundColor Red
            Write-Host "  Please contact your administrator to register a USB device." -ForegroundColor Yellow
        }

    } catch {
        Write-Host "  (Unable to retrieve authorized device list)" -ForegroundColor Red
    }

    Write-Host ""
    Write-Host "Please insert an authorized USB device and restart the activation tool." -ForegroundColor Yellow

    return @{ success = $false; error = "Unauthorized USB" }
}
```

### 3. Session USB Verification Function

```powershell
function Test-USBStillPresent {
    <#
    .SYNOPSIS
    Checks if the authenticated USB device is still connected
    .PARAMETER USBSerialNumber
    Serial number of USB that was used for authentication
    .OUTPUTS
    Boolean - True if USB still present
    #>

    param(
        [string]$USBSerialNumber
    )

    $connectedUSBs = Get-USBDeviceSerialNumber

    foreach ($usb in $connectedUSBs) {
        if ($usb.SerialNumber -eq $USBSerialNumber) {
            return $true
        }
    }

    return $false
}
```

### 4. Modified Authentication Flow

```powershell
function Authenticate-Technician {
    <#
    .SYNOPSIS
    Authenticates technician with username, password, AND USB device
    .OUTPUTS
    Session info including USB device details
    #>

    # Step 1: Get Technician ID
    Write-Host "`n╔════════════════════════════════════════════════╗" -ForegroundColor Cyan
    Write-Host "║         OEM ACTIVATION SYSTEM v2.0            ║" -ForegroundColor Cyan
    Write-Host "╚════════════════════════════════════════════════╝" -ForegroundColor Cyan

    $TechnicianID = Read-Host "`nEnter your Technician ID"

    if ([string]::IsNullOrWhiteSpace($TechnicianID)) {
        Write-Host "❌ Technician ID cannot be empty" -ForegroundColor Red
        return $null
    }

    # Step 2: USB Authentication (before password)
    $usbAuth = Invoke-USBAuthentication -TechnicianID $TechnicianID -APIBaseURL $script:APIBaseURL

    if (-not $usbAuth.success) {
        Write-Host "`n❌ USB authentication failed. Cannot continue." -ForegroundColor Red
        Start-Sleep -Seconds 5
        return $null
    }

    # Step 3: Password Authentication
    Write-Host "`n🔐 Password Authentication" -ForegroundColor Cyan
    $SecurePassword = Read-Host "Enter your password" -AsSecureString
    $Password = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto(
        [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecurePassword)
    )

    if ([string]::IsNullOrWhiteSpace($Password)) {
        Write-Host "❌ Password cannot be empty" -ForegroundColor Red
        return $null
    }

    # Step 4: Login API call with USB info
    $loginBody = @{
        technician_id = $TechnicianID
        password = $Password
        usb_device_id = $usbAuth.usb_device_id
        usb_serial_number = $usbAuth.usb_serial_number
    }

    try {
        $response = Invoke-APICall -Endpoint "login.php" -Body $loginBody

        if ($response.success) {
            Write-Host "✅ Authentication successful!" -ForegroundColor Green
            Write-Host "   Technician: $($response.full_name)" -ForegroundColor Gray
            if ($usbAuth.usb_required) {
                Write-Host "   USB Device: $($usbAuth.device_name)" -ForegroundColor Gray
            }

            # Store USB info in session
            $script:USBSerialNumber = $usbAuth.usb_serial_number
            $script:USBDeviceID = $usbAuth.usb_device_id

            return $response
        } else {
            Write-Host "❌ Login failed: $($response.error)" -ForegroundColor Red
            return $null
        }

    } catch {
        Write-Host "❌ Login error: $($_.Exception.Message)" -ForegroundColor Red
        return $null
    }
}
```

### 5. Periodic USB Presence Check

```powershell
# In main execution loop, periodically check USB presence
function Start-USBPresenceMonitor {
    <#
    .SYNOPSIS
    Starts background job to monitor USB presence during activation
    #>

    if (-not $script:USBSerialNumber) {
        return # USB auth not enabled
    }

    $script:USBMonitorJob = Start-Job -ScriptBlock {
        param($serialNumber)

        while ($true) {
            Start-Sleep -Seconds 5

            $usbDevices = Get-CimInstance -Query "SELECT * FROM Win32_DiskDrive WHERE InterfaceType='USB'"
            $found = $false

            foreach ($device in $usbDevices) {
                if ($device.SerialNumber -eq $serialNumber) {
                    $found = $true
                    break
                }
            }

            if (-not $found) {
                return "USB_REMOVED"
            }
        }

    } -ArgumentList $script:USBSerialNumber
}

function Stop-USBPresenceMonitor {
    if ($script:USBMonitorJob) {
        Stop-Job -Job $script:USBMonitorJob
        Remove-Job -Job $script:USBMonitorJob
    }
}

function Test-USBPresenceMonitor {
    if (-not $script:USBMonitorJob) {
        return $true # Monitoring not active
    }

    $result = Receive-Job -Job $script:USBMonitorJob -ErrorAction SilentlyContinue

    if ($result -eq "USB_REMOVED") {
        Write-Host "`n❌ USB DEVICE REMOVED!" -ForegroundColor Red
        Write-Host "Your authorized USB device has been disconnected." -ForegroundColor Yellow
        Write-Host "Activation process terminated for security." -ForegroundColor Yellow

        Stop-USBPresenceMonitor

        Start-Sleep -Seconds 5
        exit 1
    }

    return $true
}
```

---

## API Endpoint Implementation

### 1. Get USB Auth Configuration
**File**: `api/get-usb-auth-config.php` (NEW)

```php
<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$technicianId = $data['technician_id'] ?? '';

if (empty($technicianId)) {
    echo json_encode(['success' => false, 'error' => 'Missing technician_id']);
    exit;
}

try {
    // Get USB auth configuration
    $config = [
        'usb_auth_enabled' => (bool)getConfig('usb_auth_enabled'),
        'usb_auth_mode' => getConfig('usb_auth_mode') ?? 'required',
        'usb_auth_session_check' => (bool)getConfig('usb_auth_session_check')
    ];

    echo json_encode([
        'success' => true,
        'config' => $config
    ]);

} catch (PDOException $e) {
    error_log("USB auth config error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
```

### 2. Verify USB Device
**File**: `api/verify-usb-device.php` (NEW)

```php
<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$technicianId = $data['technician_id'] ?? '';
$usbSerialNumber = $data['usb_serial_number'] ?? '';

if (empty($technicianId) || empty($usbSerialNumber)) {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
    exit;
}

try {
    // Check if this USB device is authorized for this technician
    $stmt = $pdo->prepare("
        SELECT device_id, device_name, device_serial_number, device_status
        FROM usb_devices
        WHERE technician_id = ? AND device_serial_number = ?
    ");
    $stmt->execute([$technicianId, $usbSerialNumber]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$device) {
        echo json_encode([
            'success' => true,
            'authorized' => false,
            'error' => 'USB device not registered for this technician'
        ]);
        exit;
    }

    if ($device['device_status'] !== 'active') {
        echo json_encode([
            'success' => true,
            'authorized' => false,
            'error' => "USB device status: {$device['device_status']}"
        ]);
        exit;
    }

    // Update last used timestamp
    $stmt = $pdo->prepare("
        UPDATE usb_devices
        SET last_used_date = NOW(), last_used_ip = ?
        WHERE device_id = ?
    ");
    $stmt->execute([$_SERVER['REMOTE_ADDR'], $device['device_id']]);

    echo json_encode([
        'success' => true,
        'authorized' => true,
        'device_id' => $device['device_id'],
        'device_name' => $device['device_name']
    ]);

} catch (PDOException $e) {
    error_log("USB verification error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
```

### 3. List Technician USB Devices
**File**: `api/list-technician-usb-devices.php` (NEW)

```php
<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$technicianId = $data['technician_id'] ?? '';

if (empty($technicianId)) {
    echo json_encode(['success' => false, 'error' => 'Missing technician_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT device_id, device_name, device_serial_number, device_status,
               device_description, registered_date, last_used_date
        FROM usb_devices
        WHERE technician_id = ?
        ORDER BY device_status ASC, device_name ASC
    ");
    $stmt->execute([$technicianId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'devices' => $devices
    ]);

} catch (PDOException $e) {
    error_log("List USB devices error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
```

### 4. Modify Login API
**File**: `api/login.php` (MODIFY)

Add USB device tracking to login:

```php
// After successful password verification
$usbDeviceId = $data['usb_device_id'] ?? null;
$usbSerialNumber = $data['usb_serial_number'] ?? null;

// Create session with USB info
$sessionToken = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+8 hours'));

$stmt = $pdo->prepare("
    INSERT INTO active_sessions (
        session_token, technician_id, order_number, key_id,
        created_at, expires_at, client_ip, usb_device_id
    ) VALUES (?, ?, NULL, NULL, NOW(), ?, ?, ?)
");
$stmt->execute([
    $sessionToken,
    $technicianId,
    $expiresAt,
    $_SERVER['REMOTE_ADDR'],
    $usbDeviceId
]);

// Log USB authentication attempt
if ($usbDeviceId) {
    error_log("USB auth success: Technician $technicianId using device $usbDeviceId (serial: $usbSerialNumber)");
}
```

### 5. Modify Report Result API
**File**: `api/report-result.php` (MODIFY)

Track USB device in activation attempts:

```php
// Get USB device from session
$stmt = $pdo->prepare("
    SELECT usb_device_id FROM active_sessions
    WHERE session_token = ?
");
$stmt->execute([$sessionToken]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
$usbDeviceId = $session['usb_device_id'] ?? null;

// Include in activation_attempts INSERT
$stmt = $pdo->prepare("
    INSERT INTO activation_attempts (
        ..., usb_device_id
    ) VALUES (..., ?)
");
$stmt->execute([..., $usbDeviceId]);
```

---

## Admin Panel Implementation

### 1. USB Devices Tab (NEW)

Add new tab to admin panel for USB device management.

**HTML** (add to admin_v2.php):

```html
<button class="tab-button" data-tab="usb-devices">USB Devices</button>

<!-- USB Devices Tab Content -->
<div id="usb-devices" class="tab-content">
    <h2>USB Device Management</h2>

    <div class="usb-controls">
        <button class="btn btn-primary" onclick="showAddUSBDeviceModal()">
            ➕ Register New USB Device
        </button>

        <div class="filter-group">
            <label>Filter by Technician:</label>
            <select id="usb-filter-technician" onchange="loadUSBDevices()">
                <option value="">All Technicians</option>
                <!-- Populated dynamically -->
            </select>

            <label>Filter by Status:</label>
            <select id="usb-filter-status" onchange="loadUSBDevices()">
                <option value="">All Statuses</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
                <option value="lost">Lost</option>
                <option value="stolen">Stolen</option>
            </select>
        </div>
    </div>

    <div id="usb-devices-list"></div>
</div>
```

**JavaScript Functions**:

```javascript
function loadUSBDevices() {
    const filterTechnician = document.getElementById('usb-filter-technician').value;
    const filterStatus = document.getElementById('usb-filter-status').value;

    fetch(`?action=list_usb_devices&technician=${filterTechnician}&status=${filterStatus}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderUSBDevicesTable(data.devices);
            } else {
                alert('Error loading USB devices: ' + data.error);
            }
        });
}

function renderUSBDevicesTable(devices) {
    let html = '<table class="data-table"><thead><tr>';
    html += '<th>Device Name</th><th>Serial Number</th><th>Technician</th>';
    html += '<th>Status</th><th>Registered</th><th>Last Used</th><th>Actions</th>';
    html += '</tr></thead><tbody>';

    devices.forEach(device => {
        const statusBadges = {
            'active': '<span class="badge badge-success">Active</span>',
            'disabled': '<span class="badge badge-secondary">Disabled</span>',
            'lost': '<span class="badge badge-warning">Lost</span>',
            'stolen': '<span class="badge badge-danger">Stolen</span>'
        };
        const statusBadge = statusBadges[device.device_status] || '<span class="badge">Unknown</span>';

        html += `<tr>
            <td><strong>${device.device_name}</strong></td>
            <td><code>${device.device_serial_number}</code></td>
            <td>${device.full_name} (${device.technician_id})</td>
            <td>${statusBadge}</td>
            <td>${device.registered_date}</td>
            <td>${device.last_used_date || 'Never'}</td>
            <td>
                <button onclick="editUSBDevice(${device.device_id})" class="btn btn-sm btn-primary">Edit</button>
                ${device.device_status === 'active' ?
                    `<button onclick="disableUSBDevice(${device.device_id})" class="btn btn-sm btn-warning">Disable</button>` :
                    `<button onclick="enableUSBDevice(${device.device_id})" class="btn btn-sm btn-success">Enable</button>`
                }
                <button onclick="deleteUSBDevice(${device.device_id})" class="btn btn-sm btn-danger">Delete</button>
            </td>
        </tr>`;
    });

    html += '</tbody></table>';
    document.getElementById('usb-devices-list').innerHTML = html;
}

function showAddUSBDeviceModal() {
    // Show modal with form:
    // - Select Technician (dropdown)
    // - Device Name (text input)
    // - Device Serial Number (text input with "Detect" button)
    // - Device Description (textarea)
}

function detectUSBSerial() {
    // Call API to get USB devices from client computer
    // (requires client-side tool or manual entry)
}
```

**PHP Handlers** (add to admin_v2.php):

```php
// List USB devices
if ($action === 'list_usb_devices') {
    $filterTechnician = $_GET['technician'] ?? '';
    $filterStatus = $_GET['status'] ?? '';

    $sql = "
        SELECT u.*, t.full_name
        FROM usb_devices u
        INNER JOIN technicians t ON u.technician_id = t.technician_id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($filterTechnician)) {
        $sql .= " AND u.technician_id = ?";
        $params[] = $filterTechnician;
    }

    if (!empty($filterStatus)) {
        $sql .= " AND u.device_status = ?";
        $params[] = $filterStatus;
    }

    $sql .= " ORDER BY u.registered_date DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'devices' => $devices]);
    exit;
}

// Add USB device
if ($action === 'add_usb_device') {
    $data = json_decode(file_get_contents('php://input'), true);

    $technicianId = $data['technician_id'];
    $deviceName = $data['device_name'];
    $deviceSerial = $data['device_serial_number'];
    $deviceDescription = $data['device_description'] ?? '';

    // Check if serial already exists
    $stmt = $pdo->prepare("SELECT device_id FROM usb_devices WHERE device_serial_number = ?");
    $stmt->execute([$deviceSerial]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'error' => 'USB device serial number already registered']);
        exit;
    }

    // Insert new device
    $stmt = $pdo->prepare("
        INSERT INTO usb_devices (
            device_serial_number, device_name, technician_id,
            device_description, registered_by
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $deviceSerial,
        $deviceName,
        $technicianId,
        $deviceDescription,
        $_SESSION['admin_id']
    ]);

    logAdminActivity(
        $_SESSION['admin_id'],
        $_SESSION['session_id'],
        'ADD_USB_DEVICE',
        "Registered USB device '$deviceName' for technician $technicianId",
        getClientIP()
    );

    echo json_encode(['success' => true]);
    exit;
}

// Disable USB device
if ($action === 'disable_usb_device') {
    $data = json_decode(file_get_contents('php://input'), true);

    $deviceId = $data['device_id'];
    $reason = $data['reason'] ?? 'Disabled by admin';

    $stmt = $pdo->prepare("
        UPDATE usb_devices
        SET device_status = 'disabled',
            disabled_date = NOW(),
            disabled_by = ?,
            disabled_reason = ?
        WHERE device_id = ?
    ");
    $stmt->execute([$_SESSION['admin_id'], $reason, $deviceId]);

    logAdminActivity(
        $_SESSION['admin_id'],
        $_SESSION['session_id'],
        'DISABLE_USB_DEVICE',
        "Disabled USB device ID $deviceId: $reason",
        getClientIP()
    );

    echo json_encode(['success' => true]);
    exit;
}
```

### 2. Settings Tab - USB Auth Configuration

Add USB authentication settings to existing Settings tab:

```html
<div class="settings-section">
    <h3>USB Device Authentication</h3>
    <p class="text-muted">Require physical USB devices for technician authentication (two-factor)</p>

    <form id="usbAuthForm">
        <div class="form-group">
            <label>
                <input type="checkbox" id="usb_auth_enabled" name="usb_auth_enabled">
                Enable USB Device Authentication
            </label>
            <small>Require technicians to insert authorized USB device during login</small>
        </div>

        <div id="usb_auth_config_group">
            <div class="form-group">
                <label for="usb_auth_mode">Authentication Mode</label>
                <select id="usb_auth_mode" name="usb_auth_mode" class="form-control">
                    <option value="required">Required (USB + Password)</option>
                    <option value="optional">Optional (Either USB or Password)</option>
                </select>
                <small>Choose whether USB is mandatory or optional</small>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="usb_auth_session_check" name="usb_auth_session_check">
                    Monitor USB During Session
                </label>
                <small>Terminate session if USB device is removed during activation</small>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="usb_auth_allow_admin_override" name="usb_auth_allow_admin_override">
                    Allow Emergency Admin Override
                </label>
                <small>⚠️ Allow admins to bypass USB auth in emergency (not recommended)</small>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Save USB Settings</button>
    </form>
</div>
```

### 3. Activation History - Display USB Device

Modify activation history to show which USB device was used:

```javascript
// Add USB Device column
html += '<th>USB Device</th>';

// In table body
const usbDevice = item.usb_device_name || '<span class="text-muted">N/A</span>';
html += `<td>${usbDevice}</td>`;
```

---

## Deployment Steps

### 1. Database Migration

```bash
# Create migration file
cat > database/usb_authenticator_migration.sql << 'EOF'
-- USB Authenticator Migration
-- Adds USB device authentication support

CREATE TABLE usb_devices (
    device_id INT AUTO_INCREMENT PRIMARY KEY,
    device_serial_number VARCHAR(255) UNIQUE NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    technician_id VARCHAR(50) NOT NULL,
    device_status ENUM('active', 'disabled', 'lost', 'stolen') DEFAULT 'active',
    device_description TEXT,
    registered_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    registered_by VARCHAR(50),
    last_used_date DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    disabled_date DATETIME NULL,
    disabled_by VARCHAR(50) NULL,
    disabled_reason TEXT NULL,

    INDEX idx_serial (device_serial_number),
    INDEX idx_technician (technician_id),
    INDEX idx_status (device_status),

    FOREIGN KEY (technician_id) REFERENCES technicians(technician_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE activation_attempts
ADD COLUMN usb_device_id INT NULL,
ADD INDEX idx_usb_device (usb_device_id),
ADD FOREIGN KEY (usb_device_id) REFERENCES usb_devices(device_id) ON DELETE SET NULL;

ALTER TABLE active_sessions
ADD COLUMN usb_device_id INT NULL,
ADD INDEX idx_usb_device (usb_device_id),
ADD FOREIGN KEY (usb_device_id) REFERENCES usb_devices(device_id) ON DELETE SET NULL;

INSERT INTO system_config (config_key, config_value, description) VALUES
('usb_auth_enabled', '0', 'Require USB device authentication'),
('usb_auth_mode', 'required', 'USB auth mode: required or optional'),
('usb_auth_session_check', '1', 'Monitor USB presence during session'),
('usb_auth_allow_admin_override', '0', 'Allow admin emergency bypass');
EOF

# Apply migration
docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation < database/usb_authenticator_migration.sql
```

### 2. Deploy API Endpoints

```bash
# Deploy new API endpoints
docker cp api/get-usb-auth-config.php oem-activation-web:/var/www/html/activate/api/
docker cp api/verify-usb-device.php oem-activation-web:/var/www/html/activate/api/
docker cp api/list-technician-usb-devices.php oem-activation-web:/var/www/html/activate/api/

# Verify
docker exec oem-activation-web ls -la /var/www/html/activate/api/ | grep usb
```

### 3. Deploy PowerShell Client

```bash
# Deploy updated main_v3.PS1 with USB auth functions
docker cp activation/main_v3.PS1 oem-activation-web:/var/www/html/activate/activation/
```

### 4. Deploy Admin Panel

```bash
# Deploy updated admin_v2.php with USB Devices tab
docker cp admin_v2.php oem-activation-web:/var/www/html/activate/
```

---

## Testing Plan

### Test 1: Register USB Device
1. Login to admin panel
2. Go to USB Devices tab
3. Click "Register New USB Device"
4. Select technician, enter device name and serial
5. Save
6. **Expected**: Device appears in list with "Active" status

### Test 2: Technician Login with USB
1. Enable USB auth in Settings
2. Insert registered USB device
3. Run PowerShell client
4. Enter technician ID
5. **Expected**: USB detected and verified
6. Enter password
7. **Expected**: Login successful

### Test 3: Technician Login WITHOUT USB
1. Remove USB device
2. Run PowerShell client
3. Enter technician ID
4. **Expected**: Error "No USB devices detected"
5. Insert USB device
6. **Expected**: Retry successful

### Test 4: Unauthorized USB Device
1. Insert USB device NOT registered for technician
2. Run PowerShell client
3. **Expected**: Error showing list of authorized devices

### Test 5: USB Removed During Activation
1. Enable "Monitor USB During Session"
2. Start activation
3. During activation, remove USB device
4. **Expected**: Activation terminates with security error

### Test 6: Disable USB Device
1. Admin marks USB device as "Lost" or "Stolen"
2. Technician tries to use that USB
3. **Expected**: Authentication fails with "Device status: lost"

### Test 7: Multi-Device Support
1. Register 2 USB devices for same technician
2. Test login with first USB
3. **Expected**: Success
4. Test login with second USB
5. **Expected**: Success

---

## Security Considerations

### Threats Mitigated
- ✅ **Password Compromise**: Even with password, attacker needs physical USB
- ✅ **Remote Access**: Cannot activate remotely without USB device
- ✅ **Insider Threat**: Disgruntled technician cannot use system if USB revoked
- ✅ **Lost Password**: USB provides additional authentication factor

### Threats NOT Mitigated
- ⚠️ **USB Cloning**: Advanced attacker could clone USB serial number
- ⚠️ **Physical Theft**: Stolen USB + password = full access
- ⚠️ **Social Engineering**: Technician could be tricked into sharing USB

### Recommendations
1. **USB Device Policy**: Technicians should keep USB devices secure
2. **Immediate Revocation**: Report lost/stolen USB devices immediately
3. **Audit Logging**: Monitor unusual USB authentication patterns
4. **Backup Devices**: Each technician should have backup USB registered

---

## Success Criteria

✅ USB devices can be registered and assigned to technicians
✅ PowerShell client detects connected USB devices
✅ PowerShell client verifies USB serial number with API
✅ Login requires both password AND authorized USB device
✅ Admin can disable USB devices instantly
✅ Admin can view USB device usage history
✅ Activation attempts track which USB device was used
✅ Session terminates if USB removed during activation
✅ Multiple USB devices can be registered per technician
✅ Clear error messages when USB authentication fails
✅ Backward compatible (USB auth can be disabled)

---

## Future Enhancements (Optional)

1. **USB Expiration Dates**: Auto-disable USB devices after date
2. **USB Device Rotation Policy**: Force technicians to re-register periodically
3. **Biometric Integration**: Combine USB with fingerprint/facial recognition
4. **FIDO2/U2F Support**: Use standardized hardware security keys
5. **Mobile Authenticator**: Use smartphone as alternative to USB
6. **Geographic Restrictions**: Lock USB devices to specific locations

---

**Implementation Priority**: Medium-High
**Estimated Implementation Time**: 8-12 hours
**Dependencies**: None (works alongside existing authentication)
**Breaking Changes**: None (USB auth can be disabled for gradual rollout)
