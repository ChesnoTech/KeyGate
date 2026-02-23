// Content Script - Runs in ISOLATED world (default for content_scripts)
// Relays postMessage requests from page-api.js (MAIN world) to chrome.runtime (background.js)

(function() {
  'use strict';

  console.log('[Hardware Bridge] Content script loaded (isolated world)');

  window.addEventListener('message', function(event) {
    if (event.source !== window) return;
    if (!event.data || event.data.type !== 'OEM_HWB_REQ') return;

    var rid = event.data.rid;
    var action = event.data.action;

    if (action === 'getUSBDevices') {
      chrome.runtime.sendMessage({ action: 'getUSBDevices' }, function(response) {
        if (chrome.runtime.lastError) {
          window.postMessage({ type: 'OEM_HWB_RESP', rid: rid, success: false, error: chrome.runtime.lastError.message }, '*');
          return;
        }
        window.postMessage({
          type: 'OEM_HWB_RESP', rid: rid,
          success: response && response.success,
          devices: response ? response.devices : [],
          error: response ? response.error : 'No response'
        }, '*');
      });
    }

    if (action === 'checkStatus') {
      chrome.runtime.sendMessage({ action: 'checkBridgeStatus' }, function(response) {
        if (chrome.runtime.lastError) {
          window.postMessage({ type: 'OEM_HWB_STATUS', rid: rid, available: false, error: chrome.runtime.lastError.message }, '*');
          return;
        }
        window.postMessage({
          type: 'OEM_HWB_STATUS', rid: rid,
          available: response ? response.available : false,
          version: response ? response.version : null
        }, '*');
      });
    }
  });

  console.log('[Hardware Bridge] Content script message bridge ready');
})();
