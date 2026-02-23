# HTTPS Setup Complete ✅

## What Was Done

1. **Generated Self-Signed SSL Certificate**
   - Location: `ssl/localhost.crt` and `ssl/localhost.key`
   - Valid for 365 days
   - Subject: CN=localhost

2. **Configured Apache SSL**
   - Enabled SSL module in Apache
   - Created SSL VirtualHost configuration
   - Mounted SSL certificates into container

3. **Updated Docker Configuration**
   - Added SSL volume mount: `./ssl:/etc/apache2/ssl`
   - Port 8443 (HTTPS) already exposed and configured

## How to Access

### Admin Panel via HTTPS:
```
https://localhost:8443/admin_v2.php
```

### Accepting the Self-Signed Certificate:

Since this is a self-signed certificate, your browser will show a security warning. Here's how to proceed:

#### Chrome/Edge:
1. You'll see "Your connection is not private"
2. Click **"Advanced"**
3. Click **"Proceed to localhost (unsafe)"**
4. The site will load normally

#### Alternative - Add Certificate to Trusted Root (Permanent):
If you want to avoid the warning every time:

1. **Export the certificate from browser**:
   - Visit `https://localhost:8443`
   - Click the "Not secure" icon in address bar
   - Click "Certificate is not valid"
   - Go to "Details" tab → "Export"

2. **Import to Windows**:
   - Open `certmgr.msc` (Win+R → type certmgr.msc)
   - Navigate to: Trusted Root Certification Authorities → Certificates
   - Right-click → All Tasks → Import
   - Select your exported certificate
   - Complete the wizard

## WebUSB with HTTPS

Now that HTTPS is enabled, WebUSB will work properly:

1. Navigate to: `https://localhost:8443/admin_v2.php`
2. Accept the self-signed certificate warning (one-time)
3. Login as admin
4. Go to **USB Devices** tab
5. Click **🔌 Detect USB Devices (Grant Permission)**
6. Browser will show permission dialog with connected USB devices
7. Select your USB device and click "Connect"
8. Device information will auto-fill the registration form

## Port Summary

- **HTTP**: `http://localhost:8080` (still works, redirects to HTTPS)
- **HTTPS**: `https://localhost:8443` ✅ **Use this for WebUSB**
- **PHPMyAdmin**: `http://localhost:8081`
- **MySQL**: `localhost:3306`
- **Redis**: `localhost:6379`

## Testing

Test HTTPS connection:
```bash
curl -k https://localhost:8443/admin_v2.php
```

Check SSL certificate:
```bash
openssl s_client -connect localhost:8443 -servername localhost
```

## Notes

- Self-signed certificates are perfect for local development
- For production deployment, use a real SSL certificate (Let's Encrypt, commercial CA)
- The certificate is valid for 365 days from February 2, 2026
- Certificate files are in the `ssl/` directory (excluded from git)

## Troubleshooting

### "This site can't provide a secure connection" (ERR_SSL_PROTOCOL_ERROR)
- **Solution**: Container may not be ready yet. Wait 30 seconds and refresh.
- Check container status: `docker ps`
- Check logs: `docker logs oem-activation-web`

### "No compatible devices found" in WebUSB dialog
- **Solution**: USB flash drives often don't work with WebUSB (OS-level restriction)
- Use the fallback PowerShell method (click button to copy command)

### Browser still trying to force HTTP
- **Solution**: Clear browser HSTS cache
  - Chrome: `chrome://net-internals/#hsts` → Delete domain "localhost"
  - Or use Incognito mode (Ctrl+Shift+N)

## Security Notes

This HTTPS setup provides:
- ✅ Encrypted communication between browser and server
- ✅ WebUSB API compatibility (requires secure context)
- ✅ Protection against network sniffing on local network
- ⚠️ Self-signed certificate (browser will show warning)

For production environments, replace with a CA-signed certificate.
