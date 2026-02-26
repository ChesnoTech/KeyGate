<?php
// Secure Admin Panel with Enhanced Security
require_once 'security-headers.php';
require_once 'config.php';
require_once 'functions/network-utils.php';
require_once 'functions/push-helpers.php';

// Security Configuration
$ADMIN_CONFIG = [
    'SESSION_TIMEOUT' => (int)getConfig('admin_session_timeout_minutes') ?: DEFAULT_ADMIN_SESSION_TIMEOUT_MINUTES,
    'MAX_FAILED_LOGINS' => (int)getConfig('admin_max_failed_logins') ?: DEFAULT_ADMIN_MAX_FAILED_LOGINS,
    'LOCKOUT_DURATION' => (int)getConfig('admin_lockout_duration_minutes') ?: DEFAULT_ADMIN_LOCKOUT_MINUTES,
    'REQUIRE_HTTPS' => (bool)getConfig('admin_require_https'),
    'IP_WHITELIST_ENABLED' => (bool)getConfig('admin_ip_whitelist_enabled'),
    'FORCE_PASSWORD_CHANGE_DAYS' => (int)getConfig('admin_force_password_change_days') ?: DEFAULT_ADMIN_PASSWORD_CHANGE_DAYS
];

// Start secure session
session_start();
session_regenerate_id(true);

// Security Functions
function enforceHTTPS() {
    global $ADMIN_CONFIG;
    if ($ADMIN_CONFIG['REQUIRE_HTTPS'] && !isset($_SERVER['HTTPS'])) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
        exit;
    }
}

function checkIPWhitelist() {
    global $ADMIN_CONFIG, $pdo;
    if (!$ADMIN_CONFIG['IP_WHITELIST_ENABLED']) return true;
    
    $client_ip = getClientIP();
    
    // Get all active whitelist entries
    $stmt = $pdo->prepare("
        SELECT ip_address, ip_range FROM admin_ip_whitelist 
        WHERE is_active = 1
    ");
    $stmt->execute();
    $whitelist_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($whitelist_entries as $entry) {
        // Check exact IP match
        if ($entry['ip_address'] === $client_ip) {
            return true;
        }
        
        // Check IP range match (safely)
        if (!empty($entry['ip_range'])) {
            if (isIPInRange($client_ip, $entry['ip_range'])) {
                return true;
            }
        }
    }
    
    return false;
}

// isIPInRange() is provided by functions/network-utils.php

function logAdminActivity($admin_id, $session_id, $action, $description = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_activity_log (admin_id, session_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $admin_id, $session_id, $action, $description,
            getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        // Dispatch push notification (if push-helpers.php is loaded)
        if (function_exists('dispatchNotification')) {
            dispatchNotification($action, $description, $admin_id);
        }
    } catch (Exception $e) {
        error_log("Failed to log admin activity: " . $e->getMessage());
    }
}

function validateAdminSession() {
    global $pdo, $ADMIN_CONFIG;
    
    if (!isset($_SESSION['admin_token'])) return false;
    
    $stmt = $pdo->prepare("
        SELECT s.*, u.username, u.full_name, u.role, u.is_active,
               u.password_changed_at, u.must_change_password
        FROM admin_sessions s 
        JOIN admin_users u ON s.admin_id = u.id
        WHERE s.session_token = ? AND s.is_active = 1 AND s.expires_at > NOW() AND u.is_active = 1
    ");
    $stmt->execute([$_SESSION['admin_token']]);
    $session = $stmt->fetch();
    
    if (!$session) return false;
    
    // Check session timeout
    $last_activity = strtotime($session['last_activity']);
    $timeout_seconds = $ADMIN_CONFIG['SESSION_TIMEOUT'] * 60;
    if (time() - $last_activity > $timeout_seconds) {
        // Expire session
        $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE id = ?");
        $stmt->execute([$session['id']]);
        return false;
    }
    
    // Check password age
    $password_age = time() - strtotime($session['password_changed_at']);
    $max_age = $ADMIN_CONFIG['FORCE_PASSWORD_CHANGE_DAYS'] * 24 * 3600;
    if ($password_age > $max_age) {
        $session['must_change_password'] = true;
    }
    
    // Update last activity
    $stmt = $pdo->prepare("UPDATE admin_sessions SET last_activity = NOW() WHERE id = ?");
    $stmt->execute([$session['id']]);
    
    return $session;
}

function authenticateAdmin($username, $password) {
    global $pdo, $ADMIN_CONFIG;
    
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    
    if (!$admin) {
        logAdminActivity(null, null, 'LOGIN_FAILED', "Invalid username: $username");
        return false;
    }
    
    // Check lockout
    if ($admin['locked_until'] && $admin['locked_until'] > date('Y-m-d H:i:s')) {
        logAdminActivity($admin['id'], null, 'LOGIN_BLOCKED', 'Account locked');
        return ['error' => 'Account locked until ' . $admin['locked_until']];
    }
    
    // Verify password
    if (!password_verify($password, $admin['password_hash'])) {
        // Increment failed attempts
        $failed_attempts = $admin['failed_login_attempts'] + 1;
        $locked_until = null;
        
        if ($failed_attempts >= $ADMIN_CONFIG['MAX_FAILED_LOGINS']) {
            $locked_until = date('Y-m-d H:i:s', time() + ($ADMIN_CONFIG['LOCKOUT_DURATION'] * 60));
        }
        
        $stmt = $pdo->prepare("
            UPDATE admin_users 
            SET failed_login_attempts = ?, locked_until = ? 
            WHERE id = ?
        ");
        $stmt->execute([$failed_attempts, $locked_until, $admin['id']]);
        
        logAdminActivity($admin['id'], null, 'LOGIN_FAILED', "Failed password attempt #$failed_attempts");
        return false;
    }
    
    // Create session
    $session_token = bin2hex(random_bytes(SESSION_TOKEN_BYTES));
    $expires_at = date('Y-m-d H:i:s', time() + ($ADMIN_CONFIG['SESSION_TIMEOUT'] * 60));
    
    $stmt = $pdo->prepare("
        INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $admin['id'], $session_token, getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expires_at
    ]);
    $session_id = $pdo->lastInsertId();
    
    // Reset failed attempts
    $stmt = $pdo->prepare("
        UPDATE admin_users 
        SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW(), last_login_ip = ?
        WHERE id = ?
    ");
    $stmt->execute([getClientIP(), $admin['id']]);
    
    $_SESSION['admin_token'] = $session_token;
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['session_id'] = $session_id;
    
    logAdminActivity($admin['id'], $session_id, 'LOGIN_SUCCESS', 'Successful login');
    
    return $admin;
}

// Enforce security measures
enforceHTTPS();

if (!checkIPWhitelist()) {
    logAdminActivity(null, null, 'ACCESS_DENIED', 'IP not in whitelist: ' . getClientIP());
    http_response_code(403);
    die('Access denied from this IP address.');
}

// Handle logout
if (isset($_GET['logout'])) {
    if (isset($_SESSION['admin_token'])) {
        $stmt = $pdo->prepare("UPDATE admin_sessions SET is_active = 0 WHERE session_token = ?");
        $stmt->execute([$_SESSION['admin_token']]);
        logAdminActivity($_SESSION['admin_id'], $_SESSION['session_id'], 'LOGOUT', 'User logout');
    }
    session_destroy();
    header('Location: secure-admin.php');
    exit;
}

// Handle login
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $result = authenticateAdmin($_POST['username'], $_POST['password']);
    if ($result === false) {
        $error = 'Invalid credentials';
    } elseif (is_array($result) && isset($result['error'])) {
        $error = $result['error'];
    } else {
        header('Location: secure-admin.php');
        exit;
    }
}

// Check if user is logged in
$admin_session = validateAdminSession();

if (!$admin_session) {
    // Show login form
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Secure Admin - OEM Activation</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { 
                font-family: Arial, sans-serif; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                margin: 0; 
                padding: 20px; 
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 15px;
                box-shadow: 0 15px 35px rgba(0,0,0,0.1);
                max-width: 400px;
                width: 100%;
                text-align: center;
            }
            .logo {
                color: #667eea;
                font-size: 24px;
                margin-bottom: 30px;
                font-weight: bold;
            }
            .form-group {
                margin-bottom: 20px;
                text-align: left;
            }
            label {
                display: block;
                margin-bottom: 5px;
                color: #333;
                font-weight: bold;
            }
            input[type="text"], input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 2px solid #e1e5e9;
                border-radius: 8px;
                font-size: 16px;
                box-sizing: border-box;
                transition: border-color 0.3s;
            }
            input[type="text"]:focus, input[type="password"]:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            .btn {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                cursor: pointer;
                margin-top: 10px;
                transition: transform 0.2s;
            }
            .btn:hover {
                transform: translateY(-2px);
            }
            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 15px;
                border-radius: 8px;
                margin-bottom: 20px;
                border: 1px solid #f5c6cb;
            }
            .security-info {
                background: #d1ecf1;
                color: #0c5460;
                padding: 15px;
                border-radius: 8px;
                margin-top: 20px;
                font-size: 14px;
                text-align: left;
            }
            .ip-info {
                font-size: 12px;
                color: #666;
                margin-top: 15px;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="logo">🔐 Secure Admin</div>
            <div>OEM Activation System</div>
            
            <?php if ($error): ?>
                <div class="error">
                    ❌ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn">Login</button>
            </form>
            
            <div class="security-info">
                <strong>Security Features:</strong><br>
                ✅ Encrypted passwords<br>
                ✅ Account lockout protection<br>
                ✅ Session timeout<br>
                ✅ Activity logging<br>
                <?php if ($ADMIN_CONFIG['REQUIRE_HTTPS']): ?>
                ✅ HTTPS required<br>
                <?php endif; ?>
                <?php if ($ADMIN_CONFIG['IP_WHITELIST_ENABLED']): ?>
                ✅ IP whitelist active<br>
                <?php endif; ?>
            </div>
            
            <div class="ip-info">
                Your IP: <?php echo getClientIP(); ?><br>
                Default login: admin / SuperSecure2024!<br>
                <small>Change this immediately after first login</small>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// User is logged in - show admin panel
// Include the rest of admin functionality here or redirect to main admin page
logAdminActivity($admin_session['admin_id'], $admin_session['id'], 'PAGE_ACCESS', 'Admin panel accessed');

// For now, show a secure landing page
?>
<!DOCTYPE html>
<html>
<head>
    <title>Secure Admin Panel</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .header { background: #28a745; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .info-box { background: white; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .logout { float: right; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔐 Secure Admin Panel - OEM Activation System</h1>
        <a href="?logout=1" class="logout" style="color: white; text-decoration: none;">Logout</a>
        <div style="clear: both;"></div>
        <small>Welcome, <?php echo htmlspecialchars($admin_session['full_name']); ?> (<?php echo htmlspecialchars($admin_session['role']); ?>)</small>
    </div>
    
    <div class="info-box">
        <h3>Enhanced Security Active</h3>
        <p><strong>Session Information:</strong></p>
        <ul>
            <li>Last Activity: <?php echo $admin_session['last_activity']; ?></li>
            <li>Session Expires: <?php echo $admin_session['expires_at']; ?></li>
            <li>Your IP: <?php echo getClientIP(); ?></li>
            <li>Role: <?php echo $admin_session['role']; ?></li>
        </ul>
        
        <?php if ($admin_session['must_change_password']): ?>
            <div style="background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <strong>⚠️ Password Change Required</strong><br>
                Your password needs to be changed for security reasons.
            </div>
        <?php endif; ?>
        
        <p><a href="admin_v2.php">← Continue to Main Admin Panel</a></p>
    </div>
</body>
</html>
<?php