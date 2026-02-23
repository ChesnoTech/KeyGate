# USB Auto-Detection Feature

**Date**: February 1, 2026
**Feature**: Automatic USB device detection for admin panel registration

---

## 🎯 FEATURE OVERVIEW

The USB Auto-Detection feature allows administrators to automatically detect and register USB flash drives that are physically attached to the admin PC. This eliminates manual data entry and reduces errors.

---

## ✨ HOW IT WORKS

### User Workflow

1. **Navigate to USB Devices Tab**
   - Click "USB Devices" in admin panel
   - Click "Register New USB Device" button

2. **Auto-Detect USB Drives**
   - Click "🔎 Detect USB Devices" button in the modal
   - System scans for physically attached USB drives
   - Displays list of detected devices with full details

3. **Select Device**
   - Click on any detected device card
   - Form automatically fills with device information:
     - Serial Number
     - Device Name (from volume name or model)
     - Manufacturer
     - Model
     - Capacity (GB)
     - Description (with drive letter)

4. **Complete Registration**
   - Select technician from dropdown
   - Review/edit pre-filled information
   - Click "Register Device"

---

## 🔧 TECHNICAL IMPLEMENTATION

### Backend: API Endpoint

**File**: `api/detect-usb-devices.php`

**Method**: PowerShell WMI Query

```powershell
Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' }
```

**Data Collected**:
- Serial Number (Win32_DiskDrive.SerialNumber)
- Model (Win32_DiskDrive.Model)
- Manufacturer (extracted from Caption)
- Size in GB (calculated from Win32_DiskDrive.Size)
- Drive Letter (via Win32_DiskPartition association)
- Volume Name (Win32_LogicalDisk.VolumeName)
- Status (Win32_DiskDrive.Status)

**Security**:
- Access restricted to localhost and local network IPs (192.168.x.x, 172.28.x.x)
- 403 Forbidden for external access attempts

### Frontend: JavaScript Functions

**Function 1**: `detectUSBDevices()`
- Calls `/api/detect-usb-devices.php`
- Displays detected devices in modal
- Stores device data in `window.detectedUSBDevices`

**Function 2**: `fillUSBDeviceInfo(deviceIndex)`
- Populates form fields with selected device data
- Auto-generates description with volume info
- Scrolls to form for user review

---

## 📋 DETECTED DEVICE INFORMATION

### Primary Data
- **Serial Number**: Unique identifier from hardware
- **Model**: Full model name (e.g., "SanDisk Ultra USB 3.0")
- **Manufacturer**: Extracted from device caption
- **Capacity**: Size in gigabytes (rounded to 2 decimals)

### Secondary Data
- **Drive Letter**: Windows assigned drive (e.g., "E:")
- **Volume Name**: User-assigned volume label
- **Status**: Device health status from WMI

### Suggested Name
- Uses Volume Name if available
- Falls back to Model name
- Example: "MyUSB" or "SanDisk Ultra USB 3.0"

---

## 🎨 USER INTERFACE

### Detection Results Display

**Success (Devices Found)**:
```
✓ Found 2 USB device(s):

┌─────────────────────────────────────┐
│ 📀 MyWorkUSB                        │
│ Serial: AA123456789                 │
│ Manufacturer: SanDisk               │
│ Model: Ultra USB 3.0                │
│ Capacity: 64 GB                     │
│ Drive: E:                           │
│ Volume: MyWorkUSB                   │
│ 👆 Click to fill form with this device │
└─────────────────────────────────────┘
```

**No Devices Found**:
```
⚠️ No USB devices detected. Please ensure:
• USB drive is physically connected
• Device is recognized by Windows
• You're running this on the admin PC (not server)
```

**Error State**:
```
❌ Failed to detect USB devices. This feature requires PowerShell access on the admin PC.

You can still manually enter device information below.
```

---

## 🔐 SECURITY CONSIDERATIONS

### Access Control
- API endpoint restricted to local network only
- External IPs receive 403 Forbidden
- No authentication required (admin panel access already secured)

### Data Privacy
- No device data stored on server
- Data only used for form population
- Admin must explicitly confirm registration

### PowerShell Execution
- Uses `-NoProfile -NonInteractive` flags
- `-ExecutionPolicy Bypass` for script execution
- Read-only WMI queries (no system modifications)

---

## 🧪 TESTING

### Test Case 1: Single USB Device
1. Attach one USB flash drive to admin PC
2. Open USB registration modal
3. Click "Detect USB Devices"
4. **Expected**: Shows 1 device with full details
5. Click device card
6. **Expected**: Form auto-fills correctly

### Test Case 2: Multiple USB Devices
1. Attach 2-3 USB flash drives
2. Click "Detect USB Devices"
3. **Expected**: Shows all devices in list
4. Click second device
5. **Expected**: Form fills with second device data

### Test Case 3: No USB Devices
1. Ensure no USB drives attached
2. Click "Detect USB Devices"
3. **Expected**: Shows warning message
4. **Expected**: Manual entry still available

### Test Case 4: Server vs. Admin PC
1. Access admin panel from server
2. Click "Detect USB Devices"
3. **Expected**: Detects server's USB devices (not client PC)
4. **Note**: Feature works on machine running the web server

---

## ⚠️ IMPORTANT NOTES

### Client vs. Server Detection

**Key Point**: USB detection runs on the **web server**, not the admin's browser.

- If admin panel accessed via `http://localhost:8080` → Detects **local PC** USB devices ✓
- If admin panel accessed via `http://server-ip:8080` → Detects **server PC** USB devices ✗

### Solution for Remote Access

For detecting USB devices on admin's local PC when accessing remotely:

**Option 1**: Run admin panel on technician's local machine
**Option 2**: Use remote desktop to server
**Option 3**: Manual entry (fallback method always available)

### Browser Security Limitations

Modern browsers **do not** allow direct USB access from JavaScript:
- WebUSB API requires user permission and HTTPS
- WMI access requires server-side execution
- Current implementation uses server-side PowerShell

---

## 🔄 FALLBACK METHODS

If auto-detection fails, the system provides:

1. **Manual Entry**: All fields remain editable
2. **Clear Error Messages**: Explains why detection failed
3. **Guidance**: Helps users troubleshoot

### COM Method (Fallback)

If PowerShell fails, the API attempts COM-based detection:

```php
$wmi = new COM('WbemScripting.SWbemLocator');
$service = $wmi->ConnectServer('.', 'root\cimv2');
$disks = $service->ExecQuery("SELECT * FROM Win32_DiskDrive WHERE InterfaceType = 'USB'");
```

---

## 📊 FEATURE STATUS

- ✅ **Backend API**: Complete and tested
- ✅ **Frontend UI**: Complete with auto-fill
- ✅ **Error Handling**: Comprehensive messages
- ✅ **Security**: IP restrictions implemented
- ✅ **Fallback**: Manual entry always available
- ⏳ **Testing**: Requires physical USB device testing
- ⏳ **Documentation**: User guide needed

---

## 🚀 DEPLOYMENT

### Prerequisites
- PowerShell available on web server
- WMI access enabled (Windows servers)
- PHP `shell_exec()` function enabled

### Verification
```bash
# Test API endpoint
curl http://localhost:8080/api/detect-usb-devices.php

# Expected output (no USB):
{"success":true,"devices":[],"count":0,"method":"powershell"}

# Expected output (with USB):
{"success":true,"devices":[{...}],"count":1,"method":"powershell"}
```

---

## 📝 USER INSTRUCTIONS

### For Administrators

**To Register a USB Device**:

1. Navigate to **USB Devices** tab
2. Click **Register New USB Device**
3. Click **🔎 Detect USB Devices** button
4. Wait for scan to complete (1-3 seconds)
5. Review detected devices
6. Click on the device you want to register
7. Form auto-fills with device information
8. Select the **Technician** from dropdown
9. Review/edit information if needed
10. Click **Register Device**

**Manual Entry** (if auto-detect fails):
1. Skip detection step
2. Manually enter all device information
3. Use PowerShell to get serial: `Get-WmiObject Win32_DiskDrive | Select SerialNumber`

---

## 🐛 TROUBLESHOOTING

### Issue: "No USB devices detected"
**Causes**:
- No USB drives connected
- USB drive not recognized by Windows
- Running on wrong machine (server vs. admin PC)

**Solutions**:
- Ensure USB is physically connected
- Check Windows Device Manager
- Try different USB port
- Use manual entry

### Issue: "Failed to detect USB devices"
**Causes**:
- PowerShell disabled
- WMI service not running
- PHP shell_exec disabled

**Solutions**:
- Enable PowerShell
- Start WMI service: `net start winmgmt`
- Enable `shell_exec` in php.ini
- Use manual entry as fallback

### Issue: Detection works but shows server USB devices
**Cause**: Admin panel accessed remotely

**Solution**: Access admin panel locally or use manual entry

---

## 🎯 FUTURE ENHANCEMENTS

### Possible Improvements

1. **Client-Side Detection** (Browser Extension)
   - Chrome extension with native messaging
   - Direct USB access via WebUSB
   - Works on admin's PC regardless of server location

2. **Batch Registration**
   - Register multiple USB devices at once
   - Assign to same technician

3. **Device Verification**
   - Compare detected serial with entered serial
   - Warn if mismatch

4. **Historical Data**
   - Show if device was previously registered
   - Display previous registration details

---

**Status**: Feature complete and ready for testing
**Requires**: Physical USB device for full validation
**Fallback**: Manual entry always available
