/**
 * OEM Hardware Bridge — Popup Script
 * Combined: Native USB bridge status + browser hardware detection.
 */

(function () {
  'use strict';

  // ── Native Bridge Status ──────────────────────────────────────
  const bridgeStatusEl = document.getElementById('bridge-status');
  const testUsbBtn = document.getElementById('btn-test-usb');

  async function checkBridgeStatus() {
    bridgeStatusEl.className = 'bridge-status checking';
    bridgeStatusEl.innerHTML = '<div class="label">Native Bridge: Checking...</div>';

    try {
      const response = await chrome.runtime.sendMessage({ action: 'checkBridgeStatus' });
      if (response && response.available) {
        bridgeStatusEl.className = 'bridge-status connected';
        bridgeStatusEl.innerHTML =
          '<div class="label">Native Bridge: Connected</div>' +
          (response.version ? '<div class="hint">v' + response.version + '</div>' : '');
        testUsbBtn.disabled = false;
      } else {
        bridgeStatusEl.className = 'bridge-status disconnected';
        bridgeStatusEl.innerHTML =
          '<div class="label">Native Bridge: Not Found</div>' +
          '<div class="hint">Install the native app for USB detection</div>';
      }
    } catch (e) {
      bridgeStatusEl.className = 'bridge-status disconnected';
      bridgeStatusEl.innerHTML =
        '<div class="label">Native Bridge: Unavailable</div>' +
        '<div class="hint">' + e.message + '</div>';
    }
  }

  // Test USB button
  testUsbBtn.addEventListener('click', async () => {
    testUsbBtn.textContent = 'Testing...';
    testUsbBtn.disabled = true;
    try {
      const response = await chrome.runtime.sendMessage({ action: 'getUSBDevices' });
      if (response && response.success) {
        const n = response.devices.length;
        showStatusMsg('Found ' + n + ' USB device(s)', 3000);
      } else {
        showStatusMsg('USB test failed: ' + (response?.error || 'Unknown'), 3000, true);
      }
    } catch (e) {
      showStatusMsg('Error: ' + e.message, 3000, true);
    } finally {
      testUsbBtn.innerHTML =
        '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="7" r="1"/><circle cx="14" cy="7" r="1"/><path d="M12 2v5m-2 5v5a2 2 0 002 2h0a2 2 0 002-2v-5"/><path d="M8 12h8"/></svg> Test USB';
      testUsbBtn.disabled = false;
    }
  });

  // ── Browser Hardware Detection ────────────────────────────────
  function collectHardwareInfo() {
    const hw = {
      timestamp: new Date().toISOString(),
      source: 'oem-hardware-bridge',
      version: '1.0.1',
      cpu: {
        logical_cores: navigator.hardwareConcurrency || null,
      },
      memory: {
        device_memory_gb: navigator.deviceMemory || null,
      },
      gpu: {
        vendor: null,
        renderer: null,
      },
      display: {
        screen_width: screen.width,
        screen_height: screen.height,
        avail_width: screen.availWidth,
        avail_height: screen.availHeight,
        pixel_ratio: window.devicePixelRatio || 1,
        color_depth: screen.colorDepth,
      },
      platform: {
        platform: navigator.platform || null,
        user_agent: navigator.userAgent,
        language: navigator.language,
        languages: navigator.languages ? [...navigator.languages] : [navigator.language],
        max_touch_points: navigator.maxTouchPoints || 0,
        cookie_enabled: navigator.cookieEnabled,
        do_not_track: navigator.doNotTrack,
        pdf_viewer: navigator.pdfViewerEnabled || false,
      },
    };

    // GPU via WebGL
    try {
      const canvas = document.createElement('canvas');
      const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
      if (gl) {
        const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
        if (debugInfo) {
          hw.gpu.vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || null;
          hw.gpu.renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || null;
        }
        hw.gpu.max_texture_size = gl.getParameter(gl.MAX_TEXTURE_SIZE);
        hw.gpu.webgl_version = gl.getParameter(gl.VERSION);
      }
    } catch (e) {
      hw.gpu.error = e.message;
    }

    // User-Agent Client Hints
    if (navigator.userAgentData) {
      hw.platform.ua_brands = navigator.userAgentData.brands?.map(b => `${b.brand} ${b.version}`) || [];
      hw.platform.ua_mobile = navigator.userAgentData.mobile;
      hw.platform.ua_platform = navigator.userAgentData.platform;
    }

    return hw;
  }

  function updateUI(hw) {
    setText('cpu-cores', hw.cpu.logical_cores ?? 'N/A');
    setText('ram', hw.memory.device_memory_gb ? hw.memory.device_memory_gb + ' GB' : 'Not exposed');
    setText('gpu-vendor', hw.gpu.vendor ?? 'N/A');
    setText('gpu-renderer', hw.gpu.renderer ?? 'N/A');
    setText('screen-res', hw.display.screen_width + ' x ' + hw.display.screen_height);
    setText('pixel-ratio', hw.display.pixel_ratio + 'x');
    setText('color-depth', hw.display.color_depth + '-bit');
    setText('platform', hw.platform.ua_platform || hw.platform.platform || 'N/A');
    setText('language', hw.platform.language);
    setText('touch', hw.platform.max_touch_points > 0 ? 'Yes (' + hw.platform.max_touch_points + ' points)' : 'No');
  }

  function setText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(text);
  }

  function showStatusMsg(msg, duration, isError) {
    const el = document.getElementById('status-msg');
    el.textContent = msg;
    el.style.color = isError ? '#ef4444' : '#22c55e';
    el.classList.add('visible');
    if (duration) setTimeout(() => el.classList.remove('visible'), duration);
  }

  // ── Init ──────────────────────────────────────────────────────
  let hwData = collectHardwareInfo();
  updateUI(hwData);
  checkBridgeStatus();

  // Copy JSON (includes both browser hw + timestamp)
  document.getElementById('btn-copy').addEventListener('click', () => {
    const json = JSON.stringify(hwData, null, 2);
    navigator.clipboard.writeText(json).then(() => {
      showStatusMsg('Copied to clipboard!', 2000);
    }).catch(() => {
      const ta = document.createElement('textarea');
      ta.value = json;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      showStatusMsg('Copied to clipboard!', 2000);
    });
  });

  // Refresh browser hw info
  document.getElementById('btn-refresh').addEventListener('click', () => {
    hwData = collectHardwareInfo();
    updateUI(hwData);
    checkBridgeStatus();
    showStatusMsg('Refreshed!', 1500);
  });
})();
