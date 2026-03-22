# OEM Hardware Bridge

Professional-grade USB hardware detection bridge for the KeyGate, similar to HP Support Assistant and Lenovo Vantage.

## Overview

This solution uses **Chrome Native Messaging** to securely communicate between the web browser and a native Windows application that can access hardware information.

### Architecture

```
┌─────────────────┐         ┌──────────────────┐         ┌────────────────────┐
│  Admin Panel    │────────▶│  Browser         │────────▶│  Native Windows    │
│  (Web Page)     │         │  Extension       │         │  Application       │
│                 │◀────────│  (JavaScript)    │◀────────│  (C# .NET)         │
└─────────────────┘         └──────────────────┘         └────────────────────┘
     JavaScript API         Native Messaging API              WMI Queries
```

### Components

1. **Browser Extension** (`extension/`)
   - Manifest V3 Chrome/Edge extension
   - Injects JavaScript API into admin panel
   - Communicates with native app via Chrome Native Messaging

2. **Native Windows Application** (`native-app/`)
   - C# .NET 6.0 console application
   - Queries USB devices using WMI (Windows Management Instrumentation)
   - Communicates with extension via stdin/stdout (Native Messaging protocol)

3. **Admin Panel Integration**
   - Detects if extension is installed
   - Falls back to PowerShell method if not available
   - Seamless user experience

## Installation

### Prerequisites

- **For Building:**
  - .NET 6.0 SDK or later ([Download](https://dotnet.microsoft.com/download))
  - Visual Studio 2022 or VS Code (optional, for development)

- **For Using:**
  - Windows 10/11
  - Google Chrome or Microsoft Edge browser
  - Administrator privileges (for installation only)

### Step 1: Build Native Application

```cmd
cd hardware-bridge\native-app
build.cmd
```

This creates `OEMHardwareBridge.exe` (self-contained, ~15MB)

### Step 2: Install Native Application

```powershell
# Run PowerShell as Administrator
cd hardware-bridge\native-app
.\install.ps1
```

This will:
- Copy executable to `C:\Program Files\OEMHardwareBridge\`
- Register native messaging host for Chrome and Edge
- Create manifest file in `%LOCALAPPDATA%\OEMHardwareBridge\`

### Step 3: Load Browser Extension

1. Open Chrome or Edge
2. Navigate to `chrome://extensions` (or `edge://extensions`)
3. Enable **Developer mode** (toggle in top-right)
4. Click **Load unpacked**
5. Select the `hardware-bridge\extension` folder
6. Copy the **Extension ID** displayed (e.g., `abcdefghijklmnopqrstuvwxyz123456`)

### Step 4: Update Manifest with Extension ID

1. Open: `%LOCALAPPDATA%\OEMHardwareBridge\chrome_manifest.json`
2. Replace `YOUR_EXTENSION_ID_HERE` with your actual extension ID
3. Save the file

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

### Step 5: Test Installation

1. Click the extension icon in browser toolbar
2. You should see: **Status: ✅ Connected**
3. Click **🔍 Test Connection** to verify USB device detection

## Usage in Admin Panel

The admin panel automatically detects the extension and provides three methods for USB detection:

### Method 1: Hardware Bridge Extension (Automatic)
- If extension is installed and working
- Click "🔌 Detect USB Devices" button
- Devices appear immediately
- **No browser permission prompts needed!**

### Method 2: WebUSB API (Fallback)
- If extension not installed
- Works for some USB devices (not flash drives)
- Requires browser permission grant

### Method 3: PowerShell Command (Manual Fallback)
- If neither extension nor WebUSB works
- Copy PowerShell command with one click
- Run in PowerShell to get serial number
- Paste into form

## API Documentation

### JavaScript API (Injected into Admin Panel)

```javascript
// Check if bridge is available
const status = await window.OEMHardwareBridge.checkStatus();
console.log(status.available); // true or false

// Get USB devices
const devices = await window.OEMHardwareBridge.getUSBDevices();
// Returns array of device objects:
// [
//   {
//     serialNumber: "1234567890ABCD",
//     model: "SanDisk Cruzer Glide",
//     manufacturer: "SanDisk",
//     size: "8000000000",
//     interfaceType: "USB",
//     deviceType: "DiskDrive"
//   }
// ]

// Get extension version
const version = window.OEMHardwareBridge.getVersion();
```

### Event Listener

```javascript
// Listen for bridge ready event
window.addEventListener('OEMHardwareBridgeReady', (event) => {
  console.log('Hardware Bridge ready!', event.detail.version);
});
```

## Uninstallation

```powershell
# Run PowerShell as Administrator
cd hardware-bridge\native-app
.\install.ps1 -Uninstall
```

Then remove the browser extension from `chrome://extensions`

## Troubleshooting

### "Native app not found" in extension popup

**Cause:** Native messaging host not registered or manifest file has wrong extension ID

**Solution:**
1. Verify manifest file exists: `%LOCALAPPDATA%\OEMHardwareBridge\chrome_manifest.json`
2. Check extension ID matches in manifest
3. Re-run `install.ps1` as Administrator

### "Failed to connect to native application"

**Cause:** Executable not found or permissions issue

**Solution:**
1. Verify executable exists: `C:\Program Files\OEMHardwareBridge\OEMHardwareBridge.exe`
2. Check registry entries:
   - `HKCU\Software\Google\Chrome\NativeMessagingHosts\com.oem.hardware_bridge`
   - `HKCU\Software\Microsoft\Edge\NativeMessagingHosts\com.oem.hardware_bridge`
3. Re-run installation

### No USB devices returned

**Cause:** USB device not recognized or WMI query failed

**Solution:**
1. Verify device appears in Device Manager
2. Check error log: `%APPDATA%\OEMHardwareBridge\error.log`
3. Try PowerShell fallback method

## Security

- ✅ **Native Messaging** is an official Chrome API (secure)
- ✅ **Extension only works on localhost** (host_permissions restricted)
- ✅ **Native app only responds to registered extension** (allowed_origins)
- ✅ **No network communication** (all local)
- ✅ **No elevated privileges required** (runs as current user)
- ✅ **Read-only access** (cannot modify hardware)

## Comparison with Alternatives

| Method | Security | Compatibility | User Experience | Setup Complexity |
|--------|----------|---------------|-----------------|------------------|
| **Native Messaging** ✅ | Highest | Chrome/Edge | Seamless | Medium (one-time install) |
| WebUSB | High | Chrome/Edge | Requires permission | None |
| PowerShell | Medium | All browsers | Manual copy-paste | None |
| WebSocket Server | Medium | All browsers | Seamless | High (background service) |

## Development

### Building from Source

```bash
# Native app
cd native-app
dotnet build

# Extension (no build needed - pure JavaScript)
cd extension
# Just load in browser as unpacked extension
```

### Testing

```bash
# Test native app standalone
cd native-app
echo {"command":"ping"} | .\OEMHardwareBridge.exe

# Test USB device query
echo {"command":"getUSBDevices"} | .\OEMHardwareBridge.exe
```

### Debug Logs

- **Extension logs:** Chrome DevTools Console
- **Native app logs:** `%APPDATA%\OEMHardwareBridge\error.log`

## License

Part of the KeyGate v2.0

## Support

For issues or questions, check:
1. Extension popup status
2. Native app error log
3. Chrome extension console (DevTools)
4. Windows Event Viewer (Application logs)
