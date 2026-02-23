<?php
// Step 4: Admin Account Setup

$admin_error = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_error']);

?>

<h2>Administrator Account</h2>
<p>Create your administrator account to manage the OEM Activation System.</p>

<?php if ($admin_error): ?>
    <div class="alert alert-danger">
        <strong>❌ Account Setup Failed</strong><br>
        <?php echo htmlspecialchars($admin_error); ?>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <h4>🔐 Security Requirements</h4>
    <ul>
        <li><strong>Password Length:</strong> Minimum 8 characters</li>
        <li><strong>Complexity:</strong> Include uppercase, lowercase, numbers, and special characters</li>
        <li><strong>Uniqueness:</strong> Don't reuse passwords from other systems</li>
        <li><strong>Storage:</strong> Password will be securely encrypted using bcrypt</li>
    </ul>
</div>

<form method="post" id="adminForm">
    <div class="form-group">
        <label for="admin_username">Administrator Username:</label>
        <input type="text" id="admin_username" name="admin_username" 
               value="<?php echo htmlspecialchars($_POST['admin_username'] ?? 'admin'); ?>" 
               placeholder="Choose a username" required 
               pattern="[a-zA-Z0-9_-]{3,50}" 
               title="3-50 characters, letters, numbers, underscore, and dash only">
        <small style="color: #666;">3-50 characters, letters, numbers, underscore, and dash only</small>
    </div>
    
    <div class="form-group">
        <label for="admin_full_name">Full Name:</label>
        <input type="text" id="admin_full_name" name="admin_full_name" 
               value="<?php echo htmlspecialchars($_POST['admin_full_name'] ?? 'System Administrator'); ?>" 
               placeholder="Your full name" required>
        <small style="color: #666;">This will appear in the admin panel and activity logs</small>
    </div>
    
    <div class="form-group">
        <label for="admin_email">Email Address:</label>
        <input type="email" id="admin_email" name="admin_email" 
               value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" 
               placeholder="admin@your-company.com" required>
        <small style="color: #666;">Used for account recovery and notifications</small>
    </div>
    
    <div class="form-group">
        <label for="admin_password">Password:</label>
        <input type="password" id="admin_password" name="admin_password" 
               placeholder="Enter a strong password" required 
               minlength="8">
        <div id="password-strength" style="margin-top: 5px;"></div>
        <small style="color: #666;">
            Password should contain:
            <span id="length-check">❌ At least 8 characters</span> |
            <span id="upper-check">❌ Uppercase letter</span> |
            <span id="lower-check">❌ Lowercase letter</span> |
            <span id="number-check">❌ Number</span> |
            <span id="special-check">❌ Special character</span>
        </small>
    </div>
    
    <div class="form-group">
        <label for="admin_password_confirm">Confirm Password:</label>
        <input type="password" id="admin_password_confirm" name="admin_password_confirm" 
               placeholder="Confirm your password" required>
        <div id="password-match" style="margin-top: 5px;"></div>
    </div>
    
    <div class="system-info">
        <h4>👑 Administrator Privileges</h4>
        <p>Your administrator account will have the following capabilities:</p>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; margin: 15px 0;">
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                <strong>User Management:</strong><br>
                • Create/edit technician accounts<br>
                • Reset passwords<br>
                • Enable/disable accounts<br>
                • View activity logs
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                <strong>System Configuration:</strong><br>
                • SMTP settings<br>
                • Security policies<br>
                • Key management<br>
                • System monitoring
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                <strong>Reporting & Analytics:</strong><br>
                • Activation statistics<br>
                • Key usage reports<br>
                • Technician performance<br>
                • Security audit logs
            </div>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 5px;">
                <strong>Data Management:</strong><br>
                • Import OEM keys<br>
                • Export reports<br>
                • Database maintenance<br>
                • Backup management
            </div>
        </div>
    </div>
    
    <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; color: #155724;">
        <h4>✅ Final Installation Steps</h4>
        <p>After clicking "Complete Installation", the system will:</p>
        <ol style="margin: 10px 0 0 20px;">
            <li>Create your administrator account</li>
            <li>Generate secure configuration files</li>
            <li>Set up directory structure and permissions</li>
            <li>Initialize the database with default settings</li>
            <li>Create security logs and monitoring</li>
            <li>Redirect you to the secure admin panel</li>
        </ol>
    </div>
    
    <div class="actions">
        <a href="index.php?step=3" class="btn btn-secondary">← Back: Configuration</a>
        <button type="submit" class="btn" id="install-btn" disabled>Complete Installation 🚀</button>
    </div>
</form>

<script>
// Password strength checker
function checkPasswordStrength(password) {
    const checks = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        lower: /[a-z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    // Update visual indicators
    document.getElementById('length-check').innerHTML = checks.length ? '✅ At least 8 characters' : '❌ At least 8 characters';
    document.getElementById('upper-check').innerHTML = checks.upper ? '✅ Uppercase letter' : '❌ Uppercase letter';
    document.getElementById('lower-check').innerHTML = checks.lower ? '✅ Lowercase letter' : '❌ Lowercase letter';
    document.getElementById('number-check').innerHTML = checks.number ? '✅ Number' : '❌ Number';
    document.getElementById('special-check').innerHTML = checks.special ? '✅ Special character' : '❌ Special character';
    
    const score = Object.values(checks).filter(Boolean).length;
    const strengthEl = document.getElementById('password-strength');
    
    if (score === 0) {
        strengthEl.innerHTML = '';
    } else if (score <= 2) {
        strengthEl.innerHTML = '<span style="color: #dc3545;">Weak Password</span>';
    } else if (score <= 4) {
        strengthEl.innerHTML = '<span style="color: #ffc107;">Medium Strength</span>';
    } else {
        strengthEl.innerHTML = '<span style="color: #28a745;">Strong Password</span>';
    }
    
    return Object.values(checks).every(Boolean);
}

// Password confirmation checker
function checkPasswordMatch() {
    const password = document.getElementById('admin_password').value;
    const confirm = document.getElementById('admin_password_confirm').value;
    const matchEl = document.getElementById('password-match');
    
    if (confirm.length === 0) {
        matchEl.innerHTML = '';
        return false;
    }
    
    const match = password === confirm;
    matchEl.innerHTML = match ? 
        '<span style="color: #28a745;">✅ Passwords match</span>' : 
        '<span style="color: #dc3545;">❌ Passwords do not match</span>';
    
    return match;
}

// Form validation
function validateForm() {
    const password = document.getElementById('admin_password').value;
    const isStrong = checkPasswordStrength(password);
    const isMatch = checkPasswordMatch();
    const username = document.getElementById('admin_username').value.length >= 3;
    const email = document.getElementById('admin_email').validity.valid;
    const fullName = document.getElementById('admin_full_name').value.length >= 2;
    
    const isValid = isStrong && isMatch && username && email && fullName;
    document.getElementById('install-btn').disabled = !isValid;
    
    return isValid;
}

// Event listeners
document.getElementById('admin_password').addEventListener('input', function() {
    checkPasswordStrength(this.value);
    checkPasswordMatch();
    validateForm();
});

document.getElementById('admin_password_confirm').addEventListener('input', function() {
    checkPasswordMatch();
    validateForm();
});

['admin_username', 'admin_email', 'admin_full_name'].forEach(id => {
    document.getElementById(id).addEventListener('input', validateForm);
});

// Form submission
document.getElementById('adminForm').addEventListener('submit', function(e) {
    if (!validateForm()) {
        e.preventDefault();
        alert('Please complete all fields with valid information');
        return;
    }
    
    // Show loading indicator
    const button = document.getElementById('install-btn');
    button.innerHTML = '⏳ Installing System...';
    button.disabled = true;
    
    // Create progress indicator
    const progressDiv = document.createElement('div');
    progressDiv.innerHTML = `
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; text-align: center;">
            <h4>🔄 Installing OEM Activation System...</h4>
            <div style="width: 100%; background: #e9ecef; border-radius: 10px; margin: 15px 0;">
                <div style="width: 0%; height: 20px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; transition: width 2s;" id="progress-bar"></div>
            </div>
            <p id="progress-text">Setting up database structure...</p>
        </div>
    `;
    
    this.appendChild(progressDiv);
    
    // Simulate progress
    let progress = 0;
    const progressBar = document.getElementById('progress-bar');
    const progressText = document.getElementById('progress-text');
    const messages = [
        'Setting up database structure...',
        'Creating administrator account...',
        'Configuring security settings...',
        'Generating configuration files...',
        'Finalizing installation...'
    ];
    
    const interval = setInterval(() => {
        progress += 20;
        progressBar.style.width = progress + '%';
        if (progress / 20 - 1 < messages.length) {
            progressText.textContent = messages[Math.floor(progress / 20) - 1];
        }
        
        if (progress >= 100) {
            clearInterval(interval);
            progressText.textContent = 'Installation complete! Redirecting...';
        }
    }, 500);
});

// Initial validation
validateForm();
</script>