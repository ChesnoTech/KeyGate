// Page API - Runs in MAIN world (same JS context as the web page)
// Creates window.OEMHardwareBridge that the admin panel can use directly.
// Communicates with the content script (isolated world) via window.postMessage.

(function() {
  'use strict';

  window.OEMHardwareBridge = {
    getUSBDevices: function() {
      return new Promise(function(resolve, reject) {
        var rid = 'hwb_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        function onMsg(e) {
          if (e.data && e.data.type === 'OEM_HWB_RESP' && e.data.rid === rid) {
            window.removeEventListener('message', onMsg);
            if (e.data.success) { resolve(e.data.devices || []); }
            else { reject(new Error(e.data.error || 'Failed to get USB devices')); }
          }
        }
        window.addEventListener('message', onMsg);
        window.postMessage({ type: 'OEM_HWB_REQ', action: 'getUSBDevices', rid: rid }, '*');
        setTimeout(function() { window.removeEventListener('message', onMsg); reject(new Error('Hardware Bridge timeout')); }, 10000);
      });
    },

    checkStatus: function() {
      return new Promise(function(resolve) {
        var rid = 'hwb_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        function onMsg(e) {
          if (e.data && e.data.type === 'OEM_HWB_STATUS' && e.data.rid === rid) {
            window.removeEventListener('message', onMsg);
            resolve(e.data);
          }
        }
        window.addEventListener('message', onMsg);
        window.postMessage({ type: 'OEM_HWB_REQ', action: 'checkStatus', rid: rid }, '*');
        setTimeout(function() { window.removeEventListener('message', onMsg); resolve({ available: false, error: 'Timeout' }); }, 5000);
      });
    },

    getVersion: function() { return '1.0.0'; }
  };

  window.dispatchEvent(new CustomEvent('OEMHardwareBridgeReady', { detail: { version: '1.0.0' } }));
  console.log('[Hardware Bridge] API injected into main world');
})();
