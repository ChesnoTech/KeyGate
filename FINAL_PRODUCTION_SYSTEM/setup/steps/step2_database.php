<?php
// Step 2: Database Configuration

$db_error = $_SESSION['db_error'] ?? '';
unset($_SESSION['db_error']);

?>

<h2>Database Configuration</h2>
<p>Please provide your MySQL database connection details. The installer will create the database structure automatically.</p>

<?php if ($db_error): ?>
    <div class="alert alert-danger">
        <strong>❌ Database Connection Failed</strong><br>
        <?php echo htmlspecialchars($db_error); ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <h4>📋 Database Requirements</h4>
    <ul>
        <li><strong>MySQL Version:</strong> 5.7 or higher (MySQL 8.0+ recommended)</li>
        <li><strong>Database User:</strong> Must have CREATE, DROP, SELECT, INSERT, UPDATE, DELETE privileges</li>
        <li><strong>Character Set:</strong> UTF8MB4 (will be set automatically)</li>
        <li><strong>Storage Engine:</strong> InnoDB (default)</li>
    </ul>
</div>

<form method="post">
    <div class="form-group">
        <label for="db_host">Database Host:</label>
        <input type="text" id="db_host" name="db_host" 
               value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" 
               placeholder="localhost or your database server IP" required>
        <small style="color: #666;">Usually 'localhost' for most hosting providers</small>
    </div>
    
    <div class="form-group">
        <label for="db_username">Database Username:</label>
        <input type="text" id="db_username" name="db_username" 
               value="<?php echo htmlspecialchars($_POST['db_username'] ?? ''); ?>" 
               placeholder="Database user with full privileges" required>
    </div>
    
    <div class="form-group">
        <label for="db_password">Database Password:</label>
        <input type="password" id="db_password" name="db_password" 
               value="<?php echo htmlspecialchars($_POST['db_password'] ?? ''); ?>" 
               placeholder="Database user password">
        <small style="color: #666;">Leave empty if no password is required</small>
    </div>
    
    <div class="form-group">
        <label for="db_database">Database Name:</label>
        <input type="text" id="db_database" name="db_database" 
               value="<?php echo htmlspecialchars($_POST['db_database'] ?? 'oem_activation'); ?>" 
               placeholder="Database name (will be created if it doesn't exist)" required>
        <small style="color: #666;">Database will be created automatically if it doesn't exist</small>
    </div>
    
    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; color: #856404;">
        <h4>⚠️ Important Notes:</h4>
        <ul style="margin: 10px 0 0 20px;">
            <li>Make sure your database user has sufficient privileges</li>
            <li>The installer will create all necessary tables and initial data</li>
            <li>Existing data in the database will not be affected unless table names conflict</li>
            <li>Connection details will be stored securely in the configuration file</li>
            <li><strong>🚀 v2.0 Enhancement:</strong> Automatic concurrency optimization will be applied</li>
        </ul>
    </div>
    
    <div class="system-info">
        <h4>🔧 Database Schema Preview</h4>
        <p>The following tables will be created with enhanced v2.0 concurrency support:</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; margin: 10px 0;">
            <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 12px;">
                <strong>Core Tables:</strong><br>
                • technicians<br>
                • oem_keys <span style="color: #e83e8c;">🚀 Enhanced</span><br>
                • activation_attempts<br>
                • active_sessions <span style="color: #e83e8c;">🚀 Enhanced</span><br>
                • system_config
            </div>
            <div style="background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 12px;">
                <strong>Admin Security:</strong><br>
                • admin_users<br>
                • admin_sessions<br>
                • admin_activity_log<br>
                • admin_ip_whitelist<br>
                • password_reset_tokens
            </div>
            <div style="background: #e8f5e8; padding: 10px; border-radius: 5px; font-size: 12px; border: 1px solid #28a745;">
                <strong>🚀 v2.0 Enhancements:</strong><br>
                • InnoDB storage engine<br>
                • Optimized indexes<br>
                • Row-level locking<br>
                • Transaction support<br>
                • Concurrent user support
            </div>
        </div>
    </div>
    
    <div class="actions">
        <a href="index.php?step=1" class="btn btn-secondary">← Back: Requirements</a>
        <button type="submit" class="btn">Test Connection & Continue →</button>
    </div>
</form>

<script>
// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const host = document.getElementById('db_host').value.trim();
    const username = document.getElementById('db_username').value.trim();
    const database = document.getElementById('db_database').value.trim();
    
    if (!host || !username || !database) {
        e.preventDefault();
        alert('Please fill in all required fields');
        return;
    }
    
    // Show loading indicator
    const button = document.querySelector('button[type="submit"]');
    button.innerHTML = '⏳ Testing Connection...';
    button.disabled = true;
});
</script>