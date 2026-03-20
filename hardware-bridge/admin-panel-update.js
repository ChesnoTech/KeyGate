// ADD THIS CODE TO admin_v2.php BEFORE requestUSBAccess() function
// This adds Hardware Bridge extension detection and usage

// ========================================
// HARDWARE BRIDGE EXTENSION DETECTION
// ========================================

let hardwareBridgeAvailable = false;
let hardwareBridgeChecked = false;

// Wait for Hardware Bridge to be ready
window.addEventListener('OEMHardwareBridgeReady', (event) => {
    console.log('[Admin Panel] Hardware Bridge detected:', event.detail);
    hardwareBridgeAvailable = true;
    hardwareBridgeChecked = true;
    updateUSBDetectionUI();
});

// Check for Hardware Bridge availability
async function checkHardwareBridge() {
    if (hardwareBridgeChecked) return hardwareBridgeAvailable;

    // Wait up to 2 seconds for bridge to load
    return new Promise((resolve) => {
        let attempts = 0;
        const checkInterval = setInterval(() => {
            attempts++;
            if (window.OEMHardwareBridge) {
                clearInterval(checkInterval);
                hardwareBridgeAvailable = true;
                hardwareBridgeChecked = true;
                console.log('[Admin Panel] Hardware Bridge found');
                resolve(true);
            } else if (attempts >= 20) { // 2 seconds (20 * 100ms)
                clearInterval(checkInterval);
                hardwareBridgeChecked = true;
                console.log('[Admin Panel] Hardware Bridge not found');
                resolve(false);
            }
        }, 100);
    });
}

// Update UI based on available detection methods
function updateUSBDetectionUI() {
    const button = document.querySelector('button[onclick*="requestUSBAccess"]');
    if (!button) return;

    if (hardwareBridgeAvailable) {
        button.innerHTML = '🔌 Detect USB Devices (Hardware Bridge)';
        button.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
        button.onclick = detectUSBViaHardwareBridge;
    } else if (navigator.usb) {
        button.innerHTML = '🔌 Detect USB Devices (WebUSB)';
        button.onclick = requestUSBAccess;
    } else {
        button.innerHTML = '📋 Show PowerShell Method';
        button.onclick = showPowerShellFallback;
    }
}

// Detect USB devices via Hardware Bridge extension
async function detectUSBViaHardwareBridge() {
    const resultsDiv = document.getElementById('usb-detection-results');

    resultsDiv.innerHTML = `
        <div style="background: #e3f2fd; border: 1px solid #2196F3; border-radius: 6px; padding: 15px; margin-top: 10px;">
            <p style="text-align: center; margin: 0; color: #1565C0;">
                🔍 <strong>Scanning USB devices via Hardware Bridge...</strong>
            </p>
        </div>
    `;

    try {
        const devices = await window.OEMHardwareBridge.getUSBDevices();

        if (devices && devices.length > 0) {
            displayUSBDeviceList(devices);
        } else {
            resultsDiv.innerHTML = `
                <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; padding: 15px; margin-top: 10px;">
                    <strong style="color: #856404;">⚠️ No USB Storage Devices Found</strong><br><br>
                    <p style="color: #856404; margin: 10px 0;">
                        The Hardware Bridge didn't detect any USB flash drives or external drives.
                    </p>
                    <p style="color: #856404; margin: 10px 0;">
                        Please ensure:
                    </p>
                    <ul style="color: #856404; margin-left: 20px;">
                        <li>USB device is properly connected</li>
                        <li>Device appears in Windows File Explorer</li>
                        <li>Device is not locked or encrypted</li>
                    </ul>
                    <button class="btn btn-secondary" onclick="showPowerShellFallback()" style="width: 100%; margin-top: 10px;">
                        📋 Use PowerShell Method Instead
                    </button>
                </div>
            `;
        }
    } catch (error) {
        console.error('[Admin Panel] Hardware Bridge error:', error);

        resultsDiv.innerHTML = `
            <div style="background: #ffebee; border: 1px solid #f44336; border-radius: 6px; padding: 15px; margin-top: 10px;">
                <strong style="color: #c62828;">❌ Hardware Bridge Error</strong><br><br>
                <p style="color: #c62828; margin: 10px 0;">${error.message}</p>
                <p style="color: #c62828; margin: 10px 0; font-size: 13px;">
                    ${error.message.includes('native application')
                        ? 'The native application may not be installed or properly configured.'
                        : 'An unexpected error occurred while accessing the Hardware Bridge.'}
                </p>
                <button class="btn btn-secondary" onclick="showPowerShellFallback()" style="width: 100%; margin-top: 10px;">
                    📋 Use PowerShell Method Instead
                </button>
            </div>
        `;
    }
}

// Display list of detected USB devices
function displayUSBDeviceList(devices) {
    const resultsDiv = document.getElementById('usb-detection-results');

    let devicesHTML = devices.map((device, index) => {
        const sizeGB = device.size ? (parseInt(device.size) / (1024*1024*1024)).toFixed(2) : 'Unknown';

        return `
            <div style="background: white; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s;"
                 onmouseover="this.style.borderColor='#007bff'; this.style.boxShadow='0 2px 8px rgba(0,123,255,0.2)';"
                 onmouseout="this.style.borderColor='#ddd'; this.style.boxShadow='none';"
                 onclick="selectUSBDevice(${index}, ${escapeHtml(JSON.stringify(device))})">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="font-size: 32px;">💾</div>
                    <div style="flex: 1;">
                        <strong style="color: #2c3e50; font-size: 15px;">${escapeHtml(device.model)}</strong><br>
                        <span style="color: #7f8c8d; font-size: 13px;">
                            ${escapeHtml(device.manufacturer)} • ${sizeGB} GB • Serial: ${escapeHtml(device.serialNumber)}
                        </span>
                    </div>
                    <div style="color: #007bff; font-size: 20px;">▶</div>
                </div>
            </div>
        `;
    }).join('');

    resultsDiv.innerHTML = `
        <div style="background: #d4edda; border: 2px solid #28a745; border-radius: 8px; padding: 15px; margin-top: 10px;">
            <h4 style="color: #155724; margin: 0 0 15px 0;">✅ Found ${devices.length} USB Device(s)</h4>
            <p style="margin: 0 0 10px 0; color: #155724; font-size: 14px;">
                Click on a device to use it for registration:
            </p>
            ${devicesHTML}
        </div>
    `;
}

// Select a USB device and fill form
function selectUSBDevice(index, device) {
    displayUSBDevice({
        productName: device.model,
        manufacturerName: device.manufacturer,
        serialNumber: device.serialNumber,
        vendorId: 'N/A',
        productId: 'N/A'
    });

    // Auto-fill form
    fillFormWithUSBInfo(device.serialNumber, device.model, device.manufacturer);
}

// Show PowerShell fallback method
function showPowerShellFallback() {
    const resultsDiv = document.getElementById('usb-detection-results');

    resultsDiv.innerHTML = `
        <div style="background: #e3f2fd; border: 1px solid #2196F3; border-radius: 6px; padding: 15px; margin-top: 10px;">
            <strong style="color: #1565C0;">💻 PowerShell Detection Method</strong><br><br>
            <p style="color: #1565C0; margin: 10px 0;">
                Copy and run this command in PowerShell to get your USB device's serial number:
            </p>
            <div style="background: #2d2d2d; color: #f8f8f2; padding: 12px; border-radius: 4px; font-family: monospace; font-size: 12px; margin: 10px 0; overflow-x: auto;">
Get-CimInstance Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | Select SerialNumber,Model
            </div>
            <button class="btn btn-primary" onclick="copyPowerShellCommand()" style="width: 100%; margin-top: 10px;">
                📋 Copy PowerShell Command
            </button>
            <div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-top: 10px;">
                <p style="margin: 0; font-size: 13px; color: #856404;">
                    <strong>How to run:</strong><br>
                    1. Press <kbd>Win+X</kbd> then <kbd>A</kbd> to open PowerShell as Admin<br>
                    2. Paste the command (Ctrl+V) and press Enter<br>
                    3. Copy the SerialNumber from the output<br>
                    4. Paste it into the "USB Serial Number" field below
                </p>
            </div>
        </div>
    `;
}

// Initialize detection on page load
document.addEventListener('DOMContentLoaded', async () => {
    console.log('[Admin Panel] Initializing USB detection...');

    // Check for Hardware Bridge
    await checkHardwareBridge();

    // Update UI
    updateUSBDetectionUI();

    // Show detection method info
    showDetectionMethodInfo();
});

// Show info about available detection methods
function showDetectionMethodInfo() {
    const infoDiv = document.getElementById('usb-detection-info');
    if (!infoDiv) return;

    let method = 'PowerShell (Manual)';
    let color = '#ffc107';
    let icon = '📋';
    let description = 'Manual PowerShell command required';

    if (hardwareBridgeAvailable) {
        method = 'Hardware Bridge Extension';
        color = '#667eea';
        icon = '🔌';
        description = 'Professional USB detection via native application';
    } else if (navigator.usb) {
        method = 'WebUSB API';
        color = '#2196F3';
        icon = '🌐';
        description = 'Browser-based USB detection (limited compatibility)';
    }

    infoDiv.innerHTML = `
        <div style="background: white; border-left: 4px solid ${color}; padding: 12px; margin-bottom: 15px; border-radius: 4px;">
            <strong style="color: ${color};">${icon} Detection Method: ${method}</strong><br>
            <span style="font-size: 13px; color: #666;">${description}</span>
        </div>
    `;
}
