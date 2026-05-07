<?php
// API endpoint for technician login
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$input = ApiMiddleware::bootstrap('login', ['technician_id', 'password'], [
    'rate_limit' => RATE_LIMIT_LOGIN,
]);

$technician_id = $input['technician_id'];
$password = $input['password'];
$computerName = $input['computer_name'] ?? null;

ApiMiddleware::validateTechnicianId($technician_id);

try {
    // Get technician details (including language preference)
    $stmt = $pdo->prepare("
        SELECT * FROM `" . t('technicians') . "`
        WHERE technician_id = ? AND is_active = 1
    ");
    $stmt->execute([$technician_id]);
    $technician = $stmt->fetch();
    
    if (!$technician) {
        appLog('warning', 'Login attempt for non-existent technician', [
            'technician_id' => $technician_id,
            'event' => 'auth_failure',
        ]);
        jsonResponse(['error' => 'Invalid credentials', 'error_code' => 'INVALID_CREDENTIALS'], 401);
    }

    // Check if account is locked
    if ($technician['locked_until'] && $technician['locked_until'] > date('Y-m-d H:i:s')) {
        jsonResponse([
            'error' => 'Account locked due to too many failed attempts',
            'error_code' => 'ACCOUNT_LOCKED',
            'locked_until' => $technician['locked_until']
        ], 423);
    }
    
    // Verify password
    $password_valid = false;
    
    // Check if using temporary password (bcrypt hashed)
    if ($technician['temp_password'] && password_verify($password, $technician['temp_password'])) {
        $password_valid = true;
        $using_temp_password = true;
    } else {
        // Check regular password
        $password_valid = password_verify($password, $technician['password_hash']);
        $using_temp_password = false;
    }
    
    if (!$password_valid) {
        // Increment failed attempts
        $failed_attempts = $technician['failed_login_attempts'] + 1;
        $max_attempts = (int) getConfigWithDefault('max_failed_logins', DEFAULT_MAX_FAILED_LOGINS);
        
        $locked_until = null;
        if ($failed_attempts >= $max_attempts) {
            $lockout_minutes = (int) getConfigWithDefault('lockout_duration_minutes', DEFAULT_LOCKOUT_MINUTES);
            $locked_until = date('Y-m-d H:i:s', strtotime("+{$lockout_minutes} minutes"));
        }
        
        $stmt = $pdo->prepare("
            UPDATE `" . t('technicians') . "` 
            SET failed_login_attempts = ?, locked_until = ?
            WHERE technician_id = ?
        ");
        $stmt->execute([$failed_attempts, $locked_until, $technician_id]);
        
        appLog('warning', 'Failed login for technician', [
            'technician_id' => $technician_id,
            'event' => 'auth_failure',
            'attempt' => $failed_attempts,
            'locked' => $locked_until !== null,
        ]);
        
        if ($locked_until) {
            jsonResponse([
                'error' => 'Too many failed attempts. Account locked.',
                'error_code' => 'ACCOUNT_LOCKED',
                'locked_until' => $locked_until
            ], 423);
        } else {
            jsonResponse([
                'error' => 'Invalid credentials',
                'error_code' => 'INVALID_CREDENTIALS',
                'attempts_remaining' => $max_attempts - $failed_attempts
            ], 401);
        }
    }
    
    // Login successful - reset failed attempts
    $stmt = $pdo->prepare("
        UPDATE `" . t('technicians') . "` 
        SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW()
        WHERE technician_id = ?
    ");
    $stmt->execute([$technician_id]);
    
    $response = [
        'success' => true,
        'technician_id' => $technician['technician_id'],
        'full_name' => $technician['full_name'],
        'must_change_password' => (bool)$technician['must_change_password'],
        'using_temp_password' => $using_temp_password,
        'preferred_language' => $technician['preferred_language'] ?? 'en'
    ];
    
    // If using temp password or must change password, require password change
    if ($using_temp_password || $technician['must_change_password']) {
        $response['action_required'] = 'change_password';
        $response['message'] = 'Password change required before continuing';
    }

    // Include order field configuration for PowerShell clients
    try {
        $orderConfig = getOrderFieldConfig();
        $lang = $technician['preferred_language'] ?? 'en';
        $langSuffix = in_array($lang, ['en', 'ru']) ? $lang : 'en';
        $pattern = buildOrderNumberPattern($orderConfig);
        // Strip PHP regex delimiters for PowerShell/JS consumption
        $cleanPattern = preg_replace('#^/(.+)/$#', '$1', $pattern);
        $response['order_field'] = [
            'label'      => $orderConfig["order_field_label_{$langSuffix}"],
            'prompt'     => $orderConfig["order_field_prompt_{$langSuffix}"],
            'pattern'    => $cleanPattern,
            'min_length' => (int) $orderConfig['order_field_min_length'],
            'max_length' => (int) $orderConfig['order_field_max_length'],
            'char_type'  => $orderConfig['order_field_char_type'],
        ];
    } catch (Exception $e) {
        error_log("Order field config in login response error: " . $e->getMessage());
    }

    // Include active product lines for order type selection
    try {
        $plStmt = $pdo->query("SELECT id, name, order_pattern, description FROM `" . t('product_lines') . "` WHERE is_active = 1 ORDER BY name ASC");
        $productLines = $plStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($productLines)) {
            $response['product_lines'] = array_map(function($pl) {
                // Extract prefix from pattern (everything before # or *)
                $prefix = preg_replace('/[#*].*$/', '', $pl['order_pattern']);
                return [
                    'id'      => (int) $pl['id'],
                    'name'    => $pl['name'],
                    'prefix'  => $prefix,
                    'pattern' => $pl['order_pattern'],
                ];
            }, $productLines);
        }
    } catch (Exception $e) {
        error_log("Product lines in login response error: " . $e->getMessage());
    }

    // Include role and permissions in response
    try {
        require_once __DIR__ . '/../functions/acl.php';
        if (!empty($technician['role_id'])) {
            $role = aclGetRoleById($technician['role_id']);
            if ($role) {
                $response['role_name'] = $role['display_name'];
                $response['role_type'] = $role['role_type'];
            }
            $effectivePerms = aclGetEffectivePermissions('technician', $technician['id']);
            $grantedPerms = [];
            foreach ($effectivePerms as $key => $perm) {
                if ($perm['granted']) $grantedPerms[] = $key;
            }
            $response['permissions'] = $grantedPerms;
        }
    } catch (Exception $e) {
        error_log("ACL role info in login response error: " . $e->getMessage());
    }

    jsonResponse($response);
    
} catch (PDOException $e) {
    error_log("Database error in login.php: " . $e->getMessage());
    jsonResponse(['error' => 'Database service temporarily unavailable', 'error_code' => 'DB_UNAVAILABLE'], 503);
} catch (Exception $e) {
    error_log("Login API error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error', 'error_code' => 'INTERNAL_ERROR'], 500);
}
?>