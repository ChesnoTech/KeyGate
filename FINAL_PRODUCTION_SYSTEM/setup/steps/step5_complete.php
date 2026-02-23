<?php
// Step 5: Installation Complete

$install_error = $_SESSION['install_error'] ?? '';
if ($install_error) {
    // Installation failed
    ?>
    <h2>❌ Installation Failed</h2>
    <div class="alert alert-danger">
        <strong>Installation Error:</strong><br>
        <?php echo htmlspecialchars($install_error); ?>
    </div>
    
    <p>Please check the following and try again:</p>
    <ul>
        <li>Database connection and permissions</li>
        <li>Write permissions on installation directory</li>
        <li>Server configuration</li>
    </ul>
    
    <div class="actions">
        <a href="index.php?step=1" class="btn">← Restart Installation</a>
    </div>
    <?php
    return;
}

// Installation successful
?>

<div style="text-align: center; padding: 40px 0;">
    <div style="font-size: 72px; margin-bottom: 20px;">🎉</div>
    <h2>Installation Complete!</h2>
    <p style="font-size: 18px; color: #666; margin: 20px 0;">
        OEM Activation System v<?php echo OEM_VERSION; ?> has been successfully installed.
    </p>
</div>

<div class="alert alert-success">
    <h4>✅ Installation Summary</h4>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 15px 0;">
        <div>
            <strong>Database:</strong><br>
            • Tables created successfully<br>
            • Initial data inserted<br>
            • Security configured
        </div>
        <div>
            <strong>Admin Account:</strong><br>
            • Administrator created<br>
            • Permissions configured<br>
            • Security enabled
        </div>
        <div>
            <strong>System Files:</strong><br>
            • Configuration generated<br>
            • Permissions set<br>
            • Structure created
        </div>
        <div>
            <strong>Security:</strong><br>
            • Password encryption enabled<br>
            • Session management active<br>
            • Audit logging configured
        </div>
    </div>
</div>

<div class="system-info">
    <h3>🔐 Your Login Information</h3>
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 15px 0;">
        <p><strong>Secure Admin Panel:</strong> 
           <a href="../../secure-admin.php" target="_blank" style="color: #667eea; text-decoration: none;">
               <?php echo $_SESSION['system_config']['site_url'] ?? 'http://your-domain.com'; ?>/secure-admin.php
           </a>
        </p>
        <p><strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['admin_config']['username']); ?></p>
        <p><strong>Password:</strong> <span style="color: #666;">[The password you created]</span></p>
    </div>
    
    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0; color: #856404;">
        <strong>🔒 Important Security Notes:</strong>
        <ul style="margin: 10px 0 0 20px;">
            <li>Save your login credentials in a secure location</li>
            <li>Remove or secure the installation directory</li>
            <li>Configure HTTPS for production use</li>
            <li>Review security settings in the admin panel</li>
        </ul>
    </div>
</div>

<h3>🚀 Next Steps</h3>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0;">
    <div style="background: #e8f4f8; padding: 20px; border-radius: 8px;">
        <h4>1. 🔧 System Configuration</h4>
        <ul style="margin: 10px 0 0 20px; font-size: 14px;">
            <li>Test email notifications</li>
            <li>Configure HTTPS if needed</li>
            <li>Set up IP whitelist (optional)</li>
            <li>Review security settings</li>
        </ul>
    </div>
    
    <div style="background: #f8f9fa; padding: 20px; border-radius: 8px;">
        <h4>2. 👥 Add Technicians</h4>
        <ul style="margin: 10px 0 0 20px; font-size: 14px;">
            <li>Create technician accounts</li>
            <li>Generate temporary passwords</li>
            <li>Distribute login credentials</li>
            <li>Test activation process</li>
        </ul>
    </div>
    
    <div style="background: #fff3cd; padding: 20px; border-radius: 8px;">
        <h4>3. 🔑 Import OEM Keys</h4>
        <ul style="margin: 10px 0 0 20px; font-size: 14px;">
            <li>Import existing CSV files</li>
            <li>Verify key status</li>
            <li>Configure recycling rules</li>
            <li>Test key distribution</li>
        </ul>
    </div>
    
    <div style="background: #d4edda; padding: 20px; border-radius: 8px;">
        <h4>4. 🖥️ Deploy Client Files</h4>
        <ul style="margin: 10px 0 0 20px; font-size: 14px;">
            <li>Update PowerShell script URL</li>
            <li>Distribute OEM_Activator_v2.cmd</li>
            <li>Train technician staff</li>
            <li>Monitor system activity</li>
        </ul>
    </div>
</div>

<div class="system-info">
    <h3>📚 Documentation</h3>
    <p>Complete documentation is available in your installation directory:</p>
    <ul style="margin: 10px 0 0 20px;">
        <li><strong>FINAL_ARCHITECTURE.md</strong> - System architecture overview</li>
        <li><strong>ADMIN_SECURITY_ANALYSIS.md</strong> - Security features guide</li>
        <li><strong>SECURITY_CONFIGURATION.md</strong> - Product key protection</li>
        <li><strong>TECHNICIAN_MANAGEMENT_EXAMPLE.md</strong> - User management guide</li>
        <li><strong>DEPLOYMENT_CHECKLIST.txt</strong> - Deployment steps</li>
    </ul>
</div>

<div class="alert alert-info">
    <h4>🆘 Support & Maintenance</h4>
    <ul>
        <li><strong>Admin Panel:</strong> Monitor system health and view logs</li>
        <li><strong>Database Backups:</strong> Schedule regular backups of your database</li>
        <li><strong>Security Updates:</strong> Keep system files updated</li>
        <li><strong>Monitoring:</strong> Check admin activity logs regularly</li>
    </ul>
</div>

<div style="text-align: center; margin: 40px 0; padding: 30px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 15px;">
    <h3 style="color: white; margin-bottom: 20px;">🎯 Ready to Start!</h3>
    <p style="margin-bottom: 30px;">Your OEM Activation System is now ready for production use.</p>
    
    <div style="display: flex; gap: 20px; justify-content: center; flex-wrap: wrap;">
        <a href="../../secure-admin.php" class="btn" style="background: white; color: #667eea; text-decoration: none; display: inline-block;">
            🔐 Access Admin Panel
        </a>
        <a href="../" class="btn" style="background: rgba(255,255,255,0.2); color: white; text-decoration: none; display: inline-block;">
            🌐 View Public Site
        </a>
    </div>
</div>

<div style="text-align: center; margin: 30px 0; padding: 20px; border-top: 1px solid #e9ecef;">
    <p style="color: #666; font-size: 14px;">
        <strong>OEM Activation System v<?php echo OEM_VERSION; ?></strong><br>
        Installed on <?php echo date('F j, Y \a\t g:i A'); ?><br>
        Installation completed in <?php echo number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2); ?> seconds
    </p>
    
    <p style="color: #999; font-size: 12px; margin-top: 15px;">
        🔒 <strong>Security Reminder:</strong> Please remove or secure the installation directory after confirming everything works properly.
    </p>
</div>

<script>
// Auto-cleanup installation files after successful login (optional)
setTimeout(function() {
    if (confirm('Installation complete! Would you like to be redirected to the admin panel now?')) {
        window.location.href = '../../secure-admin.php';
    }
}, 3000);
</script>

<?php
// Clear session data
session_destroy();
?>