// Background Service Worker for OEM Hardware Bridge
// Handles communication between webpage and native Windows application

const NATIVE_APP_NAME = 'com.oem.hardware_bridge';

// Listen for messages from content script
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'getUSBDevices') {
    console.log('[Hardware Bridge] Request to get USB devices');

    // Track whether we've already responded to prevent double-response
    let hasResponded = false;

    // Connect to native application
    const port = chrome.runtime.connectNative(NATIVE_APP_NAME);

    // Handle response from native app
    port.onMessage.addListener((response) => {
      if (hasResponded) return;
      hasResponded = true;
      console.log('[Hardware Bridge] Received from native app:', response);
      sendResponse({
        success: true,
        devices: response.devices || [],
        timestamp: new Date().toISOString()
      });
      port.disconnect();
    });

    // Handle native app errors / disconnection
    port.onDisconnect.addListener(() => {
      if (hasResponded) return; // Already sent a successful response
      hasResponded = true;
      const error = chrome.runtime.lastError;
      console.error('[Hardware Bridge] Native app disconnected:', error);

      sendResponse({
        success: false,
        error: error ? error.message : 'Failed to connect to native application',
        hint: 'Please ensure the OEM Hardware Bridge native application is installed.'
      });
    });

    // Send request to native app
    port.postMessage({
      command: 'getUSBDevices',
      timestamp: new Date().toISOString()
    });

    // Return true to indicate we'll respond asynchronously
    return true;
  }

  if (request.action === 'checkBridgeStatus') {
    // Quick check if native app is available
    let hasResponded = false;

    const port = chrome.runtime.connectNative(NATIVE_APP_NAME);

    port.onMessage.addListener((response) => {
      if (hasResponded) return;
      hasResponded = true;
      sendResponse({ available: true, version: response.version });
      port.disconnect();
    });

    port.onDisconnect.addListener(() => {
      if (hasResponded) return;
      hasResponded = true;
      sendResponse({
        available: false,
        error: chrome.runtime.lastError?.message || 'Not installed'
      });
    });

    port.postMessage({ command: 'ping' });
    return true;
  }
});

// Log when extension loads
console.log('[Hardware Bridge] Extension loaded successfully');
