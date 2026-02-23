# Hardware Bridge - Quick Start Guide

## What You're Building

A professional USB hardware detection system similar to HP Support Assistant and Lenovo Vantage, consisting of:

1. **Browser Extension** - Communicates with native app
2. **Native Windows App** - Reads USB hardware info via WMI
3. **Admin Panel Integration** - Seamless detection experience

## Installation (15 minutes)

### Step 1: Install .NET SDK (if not already installed)

Check if you have .NET:
```cmd
dotnet --version
```

If not installed, download from: https://dotnet.microsoft.com/download/dotnet/6.0
- Select: ".NET 6.0 SDK" for Windows x64
- Run installer, accept defaults
- Restart terminal after installation

### Step 2: Build Native Application

```cmd
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System\hardware-bridge\native-app
build.cmd
```

You should see: "Build successful!"

### Step 3: Install Native Application

**Run as Administrator:**
```powershell
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System\hardware-bridge\native-app
.\install.ps1
```

You should see:
- ✓ Copied executable to C:\Program Files\OEMHardwareBridge
- ✓ Registered for Chrome
- ✓ Registered for Edge

### Step 4: Load Browser Extension

1. Open Chrome (or Edge)
2. Go to: `chrome://extensions`
3. Enable "Developer mode" (toggle top-right)
4. Click "Load unpacked"
5. Navigate to: `C:\Users\ChesnoTechAdmin\OEM_Activation_System\hardware-bridge\extension`
6. Click "Select Folder"

**IMPORTANT:** Copy the Extension ID shown (looks like: `abcdefghijklmnopqrstuvwxyz123456`)

### Step 5: Configure Manifest

1. Open File Explorer
2. Paste in address bar: `%LOCALAPPDATA%\OEMHardwareBridge`
3. Open `chrome_manifest.json` in Notepad
4. Find: `"YOUR_EXTENSION_ID_HERE"`
5. Replace with your actual extension ID (from Step 4)
6. Save file

Example:
```json
{
  "name": "com.oem.hardware_bridge",
  "description": "OEM Hardware Bridge for USB device detection",
  "path": "C:\\Program Files\\OEMHardwareBridge\\OEMHardwareBridge.exe",
  "type": "stdio",
  "allowed_origins": [
    "chrome-extension://abcdefghijklmnopqrstuvwxyz123456/"
  ]
}
```

### Step 6: Test Installation

1. Click the extension icon in Chrome toolbar (puzzle piece)
2. You should see popup showing: **Status: ✅ Connected**
3. Click "🔍 Test Connection"
4. Should show your USB devices!

If you see "❌ Native app not found":
- Double-check extension ID in manifest matches exactly
- Re-run install.ps1 as Administrator
- Restart browser

### Step 7: Integrate with Admin Panel

Open `admin_v2.php` and add this code before the existing `requestUSBAccess()` function:

1. Open: `C:\Users\ChesnoTechAdmin\OEM_Activation_System\FINAL_PRODUCTION_SYSTEM\admin_v2.php`
2. Find line: `// AUTO-DETECT USB DEVICES (WebUSB API)`
3. Insert the contents of `hardware-bridge\admin-panel-update.js` BEFORE that line
4. Save file

Alternatively, use this command to auto-insert:
```powershell
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System
# Manual edit recommended for safety
```

### Step 8: Restart Containers

```cmd
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System
restart-containers.cmd
```

## Testing

1. Open: `https://127.0.0.1:8443/admin_v2.php`
2. Login as admin
3. Go to "USB Devices" tab
4. Click "Register New USB Device"
5. You should see: **"🔌 Detect USB Devices (Hardware Bridge)"** button
6. Click it
7. Your USB flash drives should appear instantly!

## Troubleshooting

### Extension shows "Not Connected"

**Solution 1:** Verify extension ID in manifest
```powershell
# Check manifest file
notepad %LOCALAPPDATA%\OEMHardwareBridge\chrome_manifest.json

# Get your extension ID
# Open chrome://extensions and copy the ID shown under your extension
```

**Solution 2:** Verify native app is installed
```powershell
# Check if executable exists
Test-Path "C:\Program Files\OEMHardwareBridge\OEMHardwareBridge.exe"

# Should return: True
```

**Solution 3:** Check registry entries
```powershell
# Check Chrome registry
Get-ItemProperty "HKCU:\Software\Google\Chrome\NativeMessagingHosts\com.oem.hardware_bridge"

# Check Edge registry
Get-ItemProperty "HKCU:\Software\Microsoft\Edge\NativeMessagingHosts\com.oem.hardware_bridge"

# Both should show the manifest path
```

### No USB devices found

**Verify device is recognized:**
```powershell
Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber, Model
```

If PowerShell shows device but extension doesn't:
- Check error log: `%APPDATA%\OEMHardwareBridge\error.log`
- Try restarting the browser
- Re-run the native app test from extension popup

### Admin panel doesn't show Hardware Bridge option

**Check console:**
1. Open admin panel
2. Press F12 (DevTools)
3. Go to Console tab
4. Look for: `[Admin Panel] Hardware Bridge found` or `Hardware Bridge not found`

**If "not found":**
- Verify extension is loaded and enabled
- Refresh the page (Ctrl+F5)
- Check extension content script is injected (Console should show logs)

## Uninstall

```powershell
# Run as Administrator
cd C:\Users\ChesnoTechAdmin\OEM_Activation_System\hardware-bridge\native-app
.\install.ps1 -Uninstall
```

Then remove extension from `chrome://extensions`

## Next Steps

Once working:
1. Test with multiple USB devices
2. Update admin panel UI to show "Hardware Bridge" badge
3. Add logging for diagnostics
4. Consider signing the executable for production

## Support

Check README.md for:
- API documentation
- Security details
- Development guide
- Full troubleshooting section
