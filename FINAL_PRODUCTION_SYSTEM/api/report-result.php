<?php
/**
 * API Endpoint: Report Activation Result
 *
 * Security: Multi-layer validation, prepared statements, transaction safety
 * @version 2.0
 * @requires PHP 8.3+
 */

declare(strict_types=1);

// Security: Validate API access with multiple checks
function validateAPIAccess(): bool {
    // Layer 1: User-Agent validation
    if (!isset($_SERVER['HTTP_USER_AGENT']) ||
        !stristr($_SERVER['HTTP_USER_AGENT'], 'PowerShell')) {
        return false;
    }

    // Layer 2: Required headers check
    $requiredHeaders = ['HTTP_ACCEPT', 'HTTP_HOST'];
    foreach ($requiredHeaders as $header) {
        if (!isset($_SERVER[$header])) {
            return false;
        }
    }

    // Layer 3: Content-Type validation for POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $validTypes = ['application/json', 'application/x-www-form-urlencoded'];

        $isValid = false;
        foreach ($validTypes as $type) {
            if (strpos($contentType, $type) !== false) {
                $isValid = true;
                break;
            }
        }

        if (!$isValid) {
            return false;
        }
    }

    return true;
}

// Enforce API access control
if (!validateAPIAccess()) {
    http_response_code(403);
    header('Content-Type: application/json');
    die(json_encode([
        'success' => false,
        'error' => 'Unauthorized API access'
    ]));
}

require_once '../config.php';
require_once __DIR__ . '/middleware/ApiMiddleware.php';

$input = ApiMiddleware::bootstrap('report-result', ['session_token', 'result'], [
    'rate_limit' => RATE_LIMIT_REPORT_RESULT,
    'require_powershell' => false, // has its own validateAPIAccess() above
]);

// Extract and sanitize inputs
$sessionToken = trim($input['session_token'] ?? '');
$result = trim($input['result'] ?? '');
$attemptNumber = (int)($input['attempt_number'] ?? 1);
$notes = trim($input['notes'] ?? '');
$activationDetails = trim($input['activation_details'] ?? ''); // Alternative field name
$activationServer = trim($input['activation_server'] ?? 'oem'); // NEW: Which server was used
$activationUniqueId = trim($input['activation_unique_id'] ?? ''); // NEW: Unique activation ID

// Merge notes and activation_details
if (!empty($activationDetails) && empty($notes)) {
    $notes = $activationDetails;
}

// Input validation with specific error messages
if (empty($sessionToken)) {
    jsonResponse([
        'success' => false,
        'error' => 'Missing required field: session_token'
    ], 400);
}

if (empty($result)) {
    jsonResponse([
        'success' => false,
        'error' => 'Missing required field: result'
    ], 400);
}

// Validate result enum
if (!in_array($result, ['success', 'failed'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid result value. Must be "success" or "failed"'
    ], 400);
}

// Validate attempt number range
if ($attemptNumber < 1 || $attemptNumber > 10) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid attempt_number. Must be between 1 and 10'
    ], 400);
}

// NEW: Validate activation_unique_id (required for new activations)
if (empty($activationUniqueId)) {
    jsonResponse([
        'success' => false,
        'error' => 'Missing required field: activation_unique_id'
    ], 400);
}

// NEW: Validate activation_server enum
if (!in_array($activationServer, ['oem', 'alternative', 'manual'], true)) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid activation_server value. Must be "oem", "alternative", or "manual"'
    ], 400);
}

// NEW: Check if unique ID already exists (prevent duplicates)
$stmt = $pdo->prepare("SELECT id FROM activation_attempts WHERE activation_unique_id = ?");
$stmt->execute([$activationUniqueId]);
if ($stmt->fetch()) {
    jsonResponse([
        'success' => false,
        'error' => 'Duplicate activation_unique_id. This activation has already been recorded.'
    ], 409);
}

// Validate and retrieve session
$session = validateSession($sessionToken);

if (!$session) {
    jsonResponse([
        'success' => false,
        'error' => 'Invalid or expired session token'
    ], 401);
}

// Main transaction processing
try {
    $pdo->beginTransaction();

    // Generate timestamps
    $currentDate = date('Y-m-d');
    $currentTime = date('H:i:s');
    $clientIP = getClientIP();

    // Record activation attempt with all required fields
    $stmt = $pdo->prepare("
        INSERT INTO activation_attempts (
            key_id,
            technician_id,
            order_number,
            attempt_number,
            attempt_result,
            attempted_date,
            attempted_time,
            attempted_at,
            client_ip,
            notes,
            activation_server,
            activation_unique_id,
            auth_method,
            usb_device_id,
            computer_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
    ");

    $insertSuccess = $stmt->execute([
        $session['key_id'],
        $session['technician_id'],
        $session['order_number'],
        $attemptNumber,
        $result,
        $currentDate,
        $currentTime,
        $clientIP,
        !empty($notes) ? $notes : null,
        $activationServer,
        $activationUniqueId,
        $session['auth_method'] ?? 'password', // NEW: auth method from session
        $session['usb_device_id'] ?? null, // NEW: USB device ID from session
        $session['computer_name'] ?? null // NEW: computer name from session
    ]);

    if (!$insertSuccess) {
        throw new Exception("Failed to record activation attempt");
    }

    $responseMessage = '';
    $continueSession = false;

    // Process based on result
    if ($result === 'success') {
        // Success path: Mark key as good and deactivate session
        $stmt = $pdo->prepare("
            UPDATE oem_keys
            SET key_status = 'good',
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$session['key_id']]);

        // Deactivate session (activation complete)
        $stmt = $pdo->prepare("
            UPDATE active_sessions
            SET is_active = 0
            WHERE id = ?
        ");
        $stmt->execute([$session['id']]);

        $responseMessage = "Activation successful. Order #{$session['order_number']} is ready.";
        $continueSession = false;

        error_log("SUCCESS: Order {$session['order_number']} activated by {$session['technician_id']} using key #{$session['key_id']}");

    } else {
        // Failure path: Update fail counter and determine key status
        $stmt = $pdo->prepare("
            UPDATE oem_keys
            SET fail_counter = fail_counter + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$session['key_id']]);

        // Get updated fail counter
        $stmt = $pdo->prepare("
            SELECT fail_counter, product_key, oem_identifier
            FROM oem_keys
            WHERE id = ?
        ");
        $stmt->execute([$session['key_id']]);
        $keyData = $stmt->fetch();

        if (!$keyData) {
            throw new Exception("Key data not found after update");
        }

        $failCounter = (int)$keyData['fail_counter'];
        $maxAttempts = MAX_KEY_FAIL_COUNTER;

        // Determine new key status
        if ($failCounter >= $maxAttempts) {
            // Mark as bad after max failures
            $stmt = $pdo->prepare("
                UPDATE oem_keys
                SET key_status = 'bad'
                WHERE id = ?
            ");
            $stmt->execute([$session['key_id']]);

            $responseMessage = "Key marked as defective after {$maxAttempts} failures. Please request a new key.";
            $continueSession = false;

            // Deactivate session (key is bad)
            $stmt = $pdo->prepare("
                UPDATE active_sessions
                SET is_active = 0
                WHERE id = ?
            ");
            $stmt->execute([$session['id']]);

            error_log("BAD KEY: Key #{$session['key_id']} ({$keyData['oem_identifier']}) marked bad after {$failCounter} failures");

        } else {
            // Mark for retry
            $stmt = $pdo->prepare("
                UPDATE oem_keys
                SET key_status = 'retry'
                WHERE id = ?
            ");
            $stmt->execute([$session['key_id']]);

            $remainingAttempts = $maxAttempts - $failCounter;
            $responseMessage = "Activation failed. Key marked for retry (failure {$failCounter}/{$maxAttempts}). {$remainingAttempts} attempts remaining.";
            $continueSession = true;

            error_log("RETRY: Key #{$session['key_id']} failed attempt {$failCounter}, marked for retry");
        }
    }

    // Send email notification (non-blocking, errors logged but don't fail transaction)
    try {
        sendEmailNotification($session, $result, $attemptNumber, $notes, $keyData ?? null);
    } catch (Exception $emailError) {
        // Log but don't fail the transaction
        error_log("Email notification failed (non-critical): " . $emailError->getMessage());
    }

    // Commit transaction
    $pdo->commit();

    // Success response
    jsonResponse([
        'success' => true,
        'result' => $result,
        'message' => $responseMessage,
        'continue_session' => $continueSession,
        'order_number' => $session['order_number']
    ], 200);

} catch (PDOException $e) {
    // Database error handling
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    error_log("Database error in report-result.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    jsonResponse([
        'success' => false,
        'error' => 'Database operation failed. Please try again.'
    ], 503);

} catch (Exception $e) {
    // General error handling
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }

    error_log("Error in report-result.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    jsonResponse([
        'success' => false,
        'error' => 'Internal server error. Please contact support.'
    ], 500);
}

/**
 * Send email notification for activation result
 * Enhanced with proper error handling and type casting fix
 *
 * @param array $session Session data
 * @param string $result 'success' or 'failed'
 * @param int $attemptNumber Attempt number
 * @param string $notes Optional notes
 * @param array|null $keyData Optional key data (for failure notifications)
 * @return void
 */
function sendEmailNotification(
    array $session,
    string $result,
    int $attemptNumber,
    string $notes,
    ?array $keyData = null
): void {
    global $pdo;

    try {
        // Get configuration
        $smtpServer = getConfig('smtp_server');
        $smtpPort = (int)getConfig('smtp_port'); // ✅ Type cast fix applied
        $smtpUsername = getConfig('smtp_username');
        $smtpPassword = getConfig('smtp_password');
        $emailFrom = getConfig('email_from');
        $emailTo = getConfig('email_to');

        // Validate SMTP configuration
        if (empty($smtpServer) || empty($smtpPassword) || empty($emailFrom) || empty($emailTo)) {
            error_log("SMTP not configured - skipping email notification");
            return;
        }

        // Default port if not configured
        if ($smtpPort === 0) {
            $smtpPort = 587; // Default STARTTLS port
        }

        // Get key details if not provided
        if ($keyData === null) {
            $stmt = $pdo->prepare("
                SELECT product_key, oem_identifier, fail_counter, key_status
                FROM oem_keys
                WHERE id = ?
            ");
            $stmt->execute([$session['key_id']]);
            $keyData = $stmt->fetch();

            if (!$keyData) {
                throw new Exception("Key data not found for notification");
            }
        }

        // Get technician full name
        $stmt = $pdo->prepare("
            SELECT full_name, email
            FROM technicians
            WHERE technician_id = ?
        ");
        $stmt->execute([$session['technician_id']]);
        $technician = $stmt->fetch();
        $technicianName = $technician['full_name'] ?? $session['technician_id'];

        // Prepare email content
        $isSuccess = ($result === 'success');
        $subject = $isSuccess
            ? "✅ Order #{$session['order_number']} Activated Successfully"
            : "❌ Activation Failed - Order #{$session['order_number']}";

        $statusText = $isSuccess
            ? "Windows activation completed successfully"
            : "Windows activation attempt failed";

        $bannerColor = $isSuccess ? "#d4edda" : "#f8d7da";
        $textColor = $isSuccess ? "#155724" : "#721c24";

        // Build HTML email
        $html = buildEmailHTML(
            $statusText,
            $bannerColor,
            $textColor,
            $keyData,
            $session,
            $technicianName,
            $attemptNumber,
            $notes
        );

        // Check if PHPMailer is available
        $autoloadPath = __DIR__ . '/../vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            error_log("PHPMailer not installed - skipping email notification");
            return;
        }

        require_once $autoloadPath;

        // Send email using PHPMailer
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $smtpServer;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUsername;
        $mail->Password = $smtpPassword;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($emailFrom, 'OEM Activation System');
        $mail->addAddress($emailTo);

        // Add technician email if available and different
        if (!empty($technician['email']) && $technician['email'] !== $emailTo) {
            $mail->addCC($technician['email']);
        }

        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->isHTML(true);

        $mail->send();

        error_log("Email notification sent successfully for order {$session['order_number']}");

    } catch (Exception $e) {
        // Log but don't throw - email failures shouldn't block the API response
        error_log("Email notification error: " . $e->getMessage());
        error_log("Email error trace: " . $e->getTraceAsString());
    }
}

/**
 * Build HTML email content
 *
 * @param string $statusText Status message
 * @param string $bannerColor Banner background color
 * @param string $textColor Text color
 * @param array $keyData Key information
 * @param array $session Session information
 * @param string $technicianName Technician name
 * @param int $attemptNumber Attempt number
 * @param string $notes Additional notes
 * @return string HTML content
 */
function buildEmailHTML(
    string $statusText,
    string $bannerColor,
    string $textColor,
    array $keyData,
    array $session,
    string $technicianName,
    int $attemptNumber,
    string $notes
): string {
    $maskedKey = htmlspecialchars(
        formatProductKeySecure($keyData['product_key'], 'email'),
        ENT_QUOTES,
        'UTF-8'
    );

    $oem = htmlspecialchars($keyData['oem_identifier'], ENT_QUOTES, 'UTF-8');
    $order = htmlspecialchars($session['order_number'], ENT_QUOTES, 'UTF-8');
    $tech = htmlspecialchars($technicianName, ENT_QUOTES, 'UTF-8');
    $techId = htmlspecialchars($session['technician_id'], ENT_QUOTES, 'UTF-8');
    $timestamp = htmlspecialchars(date('Y-m-d H:i:s'), ENT_QUOTES, 'UTF-8');
    $notesHtml = !empty($notes)
        ? "<p><strong>Notes:</strong> " . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . "</p>"
        : "";

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activation Notification</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .banner { background-color: {$bannerColor}; color: {$textColor}; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #f2f2f2; font-weight: bold; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; color: #666; font-size: 0.9em; }
    </style>
</head>
<body>
    <div class="banner">
        <strong>{$statusText}</strong>
    </div>

    <table>
        <tr>
            <th>Key Reference</th>
            <td>{$maskedKey}</td>
        </tr>
        <tr>
            <th>OEM Identifier</th>
            <td>{$oem}</td>
        </tr>
        <tr>
            <th>Order Number</th>
            <td>{$order}</td>
        </tr>
        <tr>
            <th>Technician</th>
            <td>{$tech} ({$techId})</td>
        </tr>
        <tr>
            <th>Attempt Number</th>
            <td>{$attemptNumber}</td>
        </tr>
        <tr>
            <th>Timestamp</th>
            <td>{$timestamp}</td>
        </tr>
    </table>

    {$notesHtml}

    <div class="footer">
        <p>This is an automated notification from the OEM Activation System v2.0</p>
        <p>Please do not reply to this email.</p>
    </div>
</body>
</html>
HTML;
}
?>
