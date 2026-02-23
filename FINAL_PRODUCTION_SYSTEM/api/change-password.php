<?php
// API endpoint for changing password
require_once '../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$input = ApiMiddleware::bootstrap('change-password', ['technician_id', 'current_password', 'new_password', 'confirm_password'], [
    'rate_limit' => RATE_LIMIT_CHANGE_PASSWORD,
]);

$technician_id = $input['technician_id'];
$current_password = $input['current_password'];
$new_password = $input['new_password'];
$confirm_password = $input['confirm_password'];

if ($new_password !== $confirm_password) {
    jsonResponse(['error' => 'New passwords do not match', 'error_code' => 'PASSWORD_MISMATCH'], 400);
}

// Validate password strength
$min_length = (int)getConfig('password_min_length') ?: PASSWORD_MIN_LENGTH;
if (strlen($new_password) < $min_length) {
    jsonResponse(['error' => "Password must be at least {$min_length} characters long", 'error_code' => 'PASSWORD_TOO_SHORT'], 400);
}

if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/', $new_password)) {
    jsonResponse(['error' => 'Password must contain uppercase, lowercase, and numbers', 'error_code' => 'PASSWORD_WEAK'], 400);
}

try {
    // Get technician details
    $stmt = $pdo->prepare("
        SELECT * FROM technicians 
        WHERE technician_id = ? AND is_active = 1
    ");
    $stmt->execute([$technician_id]);
    $technician = $stmt->fetch();
    
    if (!$technician) {
        jsonResponse(['error' => 'Technician not found', 'error_code' => 'TECH_NOT_FOUND'], 404);
    }
    
    // Verify current password
    $current_password_valid = false;
    
    // Check if using temporary password
    if ($technician['temp_password'] && password_verify($current_password, $technician['temp_password'])) {
        $current_password_valid = true;
    } else {
        // Check regular password
        $current_password_valid = password_verify($current_password, $technician['password_hash']);
    }
    
    if (!$current_password_valid) {
        jsonResponse(['error' => 'Current password is incorrect', 'error_code' => 'INVALID_CREDENTIALS'], 401);
    }
    
    // Check if new password is same as current
    if (password_verify($new_password, $technician['password_hash'])) {
        jsonResponse(['error' => 'New password must be different from current password', 'error_code' => 'PASSWORD_REUSE'], 400);
    }
    
    // Update password
    $new_password_hash = password_hash($new_password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    
    $stmt = $pdo->prepare("
        UPDATE technicians
        SET password_hash = ?, temp_password = NULL, must_change_password = FALSE
        WHERE technician_id = ?
    ");
    $stmt->execute([$new_password_hash, $technician_id]);
    
    // Log password change
    error_log("Password changed for technician: $technician_id from " . getClientIP());
    
    jsonResponse([
        'success' => true,
        'message' => 'Password changed successfully'
    ]);
    
} catch (Exception $e) {
    error_log("Change password API error: " . $e->getMessage());
    jsonResponse(['error' => 'Internal server error', 'error_code' => 'INTERNAL_ERROR'], 500);
}
?>