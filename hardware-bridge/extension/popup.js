// Popup UI Script
document.addEventListener('DOMContentLoaded', async () => {
  const statusDiv = document.getElementById('status');
  const testBtn = document.getElementById('testBtn');

  // Check bridge status on popup open
  async function checkStatus() {
    statusDiv.textContent = 'Checking connection...';
    statusDiv.className = 'status disconnected';

    try {
      const response = await chrome.runtime.sendMessage({ action: 'checkBridgeStatus' });

      if (response && response.available) {
        statusDiv.innerHTML = '<strong>Status:</strong> ✅ Connected';
        statusDiv.className = 'status connected';
      } else {
        statusDiv.innerHTML = '<strong>Status:</strong> ❌ Native app not found<br><small>Please install the OEM Hardware Bridge application</small>';
        statusDiv.className = 'status disconnected';
      }
    } catch (error) {
      statusDiv.innerHTML = '<strong>Status:</strong> ❌ Error: ' + error.message;
      statusDiv.className = 'status disconnected';
    }
  }

  // Test button handler
  testBtn.addEventListener('click', async () => {
    testBtn.textContent = '🔍 Testing...';
    testBtn.disabled = true;

    try {
      const response = await chrome.runtime.sendMessage({ action: 'getUSBDevices' });

      if (response && response.success) {
        const deviceCount = response.devices.length;
        alert(`✅ Success!\n\nFound ${deviceCount} USB device(s):\n\n` +
          response.devices.map(d => `• ${d.model || 'Unknown'} (${d.serialNumber})`).join('\n'));
      } else {
        alert('❌ Failed to get USB devices:\n\n' + (response?.error || 'Unknown error'));
      }
    } catch (error) {
      alert('❌ Error: ' + error.message);
    } finally {
      testBtn.textContent = '🔍 Test Connection';
      testBtn.disabled = false;
    }
  });

  // Initial status check
  await checkStatus();
});
