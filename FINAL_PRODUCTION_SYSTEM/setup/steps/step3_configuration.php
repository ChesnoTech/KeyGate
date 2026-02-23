<?php
// Step 3: System Configuration
?>

<h2>System Configuration</h2>
<p>Configure your OEM Activation System settings. These can be changed later through the admin panel.</p>

<form method="post">
    <h3>🌐 General Settings</h3>
    
    <div class="form-group">
        <label for="site_name">System Name:</label>
        <input type="text" id="site_name" name="site_name" 
               value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'OEM Activation System'); ?>" 
               placeholder="Your organization name" required>
        <small style="color: #666;">This will appear in emails and admin panel</small>
    </div>
    
    <div class="form-group">
        <label for="site_url">Site URL:</label>
        <input type="text" id="site_url" name="site_url" 
               value="<?php echo htmlspecialchars($_POST['site_url'] ?? 'https://roo24.ieatkittens.netcraze.pro:65083'); ?>" 
               placeholder="http://your-domain.com/activate" required>
        <small style="color: #666;">The URL where technicians will access the activation system</small>
    </div>
    
    <h3>📧 Email Configuration</h3>
    <div class="alert alert-info">
        <strong>📨 Email Settings</strong><br>
        Configure SMTP settings for activation notifications. You can use any SMTP provider (Gmail, Outlook, Zoho, etc.)
    </div>
    
    <div class="form-group">
        <label for="smtp_server">SMTP Server:</label>
        <input type="text" id="smtp_server" name="smtp_server" 
               value="<?php echo htmlspecialchars($_POST['smtp_server'] ?? 'smtp.gmail.com'); ?>" 
               placeholder="smtp.gmail.com, smtp.zoho.com, etc." required>
    </div>
    
    <div class="form-group">
        <label for="smtp_port">SMTP Port:</label>
        <input type="number" id="smtp_port" name="smtp_port" 
               value="<?php echo htmlspecialchars($_POST['smtp_port'] ?? '587'); ?>" 
               placeholder="587 (TLS) or 465 (SSL)" required>
        <small style="color: #666;">587 for TLS, 465 for SSL</small>
    </div>
    
    <div class="form-group">
        <label for="smtp_username">SMTP Username:</label>
        <input type="email" id="smtp_username" name="smtp_username" 
               value="<?php echo htmlspecialchars($_POST['smtp_username'] ?? ''); ?>" 
               placeholder="your-email@domain.com" required>
    </div>
    
    <div class="form-group">
        <label for="smtp_password">SMTP Password:</label>
        <input type="password" id="smtp_password" name="smtp_password" 
               value="<?php echo htmlspecialchars($_POST['smtp_password'] ?? ''); ?>" 
               placeholder="Your email password or app password" required>
        <small style="color: #666;">For Gmail, use an App Password instead of your regular password</small>
    </div>
    
    <div class="form-group">
        <label for="email_from">From Email Address:</label>
        <input type="email" id="email_from" name="email_from" 
               value="<?php echo htmlspecialchars($_POST['email_from'] ?? ''); ?>" 
               placeholder="notifications@your-company.com" required>
        <small style="color: #666;">Email address that appears as sender</small>
    </div>
    
    <div class="form-group">
        <label for="email_to">Notifications Recipient:</label>
        <input type="email" id="email_to" name="email_to" 
               value="<?php echo htmlspecialchars($_POST['email_to'] ?? ''); ?>" 
               placeholder="admin@your-company.com" required>
        <small style="color: #666;">Where activation notifications will be sent</small>
    </div>
    
    <h3>🔒 Security Settings</h3>
    
    <div class="form-group">
        <div class="checkbox-group">
            <input type="checkbox" id="enable_https" name="enable_https" 
                   <?php echo isset($_POST['enable_https']) ? 'checked' : ''; ?>>
            <label for="enable_https">Require HTTPS for Admin Panel</label>
        </div>
        <small style="color: #666;">Recommended for production environments</small>
    </div>
    
    <div class="form-group">
        <div class="checkbox-group">
            <input type="checkbox" id="enable_ip_whitelist" name="enable_ip_whitelist"
                   <?php echo isset($_POST['enable_ip_whitelist']) ? 'checked' : ''; ?>>
            <label for="enable_ip_whitelist">Enable IP Whitelist for Admin Access</label>
        </div>
        <small style="color: #666;">Restrict admin panel access to specific IP addresses</small>
    </div>
    
    <div class="system-info">
        <h4>📋 Common SMTP Settings</h4>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 15px 0;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 13px;">
                <strong>Gmail:</strong><br>
                Server: smtp.gmail.com<br>
                Port: 587<br>
                Note: Use App Password
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 13px;">
                <strong>Outlook/Hotmail:</strong><br>
                Server: smtp-mail.outlook.com<br>
                Port: 587
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 13px;">
                <strong>Zoho:</strong><br>
                Server: smtp.zoho.com<br>
                Port: 587
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; font-size: 13px;">
                <strong>Yahoo:</strong><br>
                Server: smtp.mail.yahoo.com<br>
                Port: 587
            </div>
        </div>
    </div>
    
    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; color: #856404;">
        <h4>⚠️ Security Recommendations:</h4>
        <ul style="margin: 10px 0 0 20px;">
            <li><strong>SSL Certificate:</strong> Install SSL certificate for HTTPS</li>
            <li><strong>App Passwords:</strong> Use app-specific passwords for email providers</li>
            <li><strong>Firewall:</strong> Configure firewall to restrict database access</li>
            <li><strong>Regular Updates:</strong> Keep system updated for security patches</li>
        </ul>
    </div>
    
    <div class="actions">
        <a href="index.php?step=2" class="btn btn-secondary">← Back: Database</a>
        <button type="submit" class="btn">Next: Admin Account →</button>
    </div>
</form>

<script>
// Auto-fill email from field when SMTP username changes
document.getElementById('smtp_username').addEventListener('input', function() {
    const emailFrom = document.getElementById('email_from');
    if (!emailFrom.value) {
        emailFrom.value = this.value;
    }
});

// SMTP provider quick-fill
function fillSMTPSettings(provider) {
    const settings = {
        gmail: { server: 'smtp.gmail.com', port: 587 },
        outlook: { server: 'smtp-mail.outlook.com', port: 587 },
        zoho: { server: 'smtp.zoho.com', port: 587 },
        yahoo: { server: 'smtp.mail.yahoo.com', port: 587 }
    };
    
    if (settings[provider]) {
        document.getElementById('smtp_server').value = settings[provider].server;
        document.getElementById('smtp_port').value = settings[provider].port;
    }
}

// Auto-detect provider from email
document.getElementById('smtp_username').addEventListener('blur', function() {
    const email = this.value.toLowerCase();
    if (email.includes('@gmail.com')) {
        fillSMTPSettings('gmail');
    } else if (email.includes('@outlook.com') || email.includes('@hotmail.com')) {
        fillSMTPSettings('outlook');
    } else if (email.includes('@zoho.com')) {
        fillSMTPSettings('zoho');
    } else if (email.includes('@yahoo.com')) {
        fillSMTPSettings('yahoo');
    }
});
</script>