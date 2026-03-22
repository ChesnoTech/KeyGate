<?php
/**
 * SMTP / Email Settings Controller
 * KeyGate v2.0
 *
 * Handles get/save/test for email delivery configuration.
 * Passwords are encrypted at rest using AES-256-GCM with an app-level key.
 */

// ── Encryption helpers ────────────────────────────────────────────

/**
 * Derive the encryption key.
 * Priority: APP_ENCRYPTION_KEY env var → deterministic fallback from DB credentials.
 * A dedicated env var is strongly recommended in production.
 */
function smtp_get_encryption_key(): string {
    $envKey = $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY');
    if ($envKey && strlen($envKey) >= 16) {
        return hash('sha256', $envKey, true); // 32 bytes for AES-256
    }

    // Fallback: derive from DB password + a static salt
    // (still better than storing SMTP password in plaintext)
    global $db_config;
    $material = ($db_config['password'] ?? 'oem-activation') . '::smtp-encryption-salt::v1';
    return hash('sha256', $material, true);
}

/**
 * Encrypt a string with AES-256-GCM.
 * Returns base64-encoded payload: nonce (12B) || ciphertext || tag (16B)
 */
function smtp_encrypt(string $plaintext): string {
    if ($plaintext === '') return '';

    $key   = smtp_get_encryption_key();
    $nonce = random_bytes(12); // 96-bit nonce for GCM
    $tag   = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        '', // no AAD
        16  // 128-bit tag
    );

    if ($ciphertext === false) {
        error_log('SMTP encryption failed: ' . openssl_error_string());
        return '';
    }

    return 'enc:' . base64_encode($nonce . $ciphertext . $tag);
}

/**
 * Decrypt an AES-256-GCM payload. Returns empty string on failure.
 */
function smtp_decrypt(string $payload): string {
    if ($payload === '' || !str_starts_with($payload, 'enc:')) {
        return $payload; // plaintext legacy value or empty
    }

    $raw = base64_decode(substr($payload, 4), true);
    if ($raw === false || strlen($raw) < 28) { // 12 nonce + 0 cipher + 16 tag min
        error_log('SMTP decryption: invalid payload length');
        return '';
    }

    $key   = smtp_get_encryption_key();
    $nonce = substr($raw, 0, 12);
    $tag   = substr($raw, -16);
    $ciphertext = substr($raw, 12, -16);

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );

    if ($plaintext === false) {
        error_log('SMTP decryption failed: ' . openssl_error_string());
        return '';
    }

    return $plaintext;
}

// ── SMTP Config Keys ─────────────────────────────────────────────

function smtp_config_keys(): array {
    return [
        'smtp_enabled'    => '0',
        'smtp_server'     => '',
        'smtp_port'       => '587',
        'smtp_encryption' => 'tls',       // tls | ssl | none
        'smtp_username'   => '',
        'smtp_password'   => '',          // encrypted at rest
        'smtp_auth'       => '1',         // 1 = require auth
        'email_from'      => '',
        'email_from_name' => '',
        'email_to'        => '',          // default recipient
        'email_on_activation_fail' => '1',
        'email_on_key_exhausted'   => '1',
        'email_on_daily_summary'   => '0',
    ];
}

// ── Handler: Get SMTP Settings ───────────────────────────────────

function handle_get_smtp_settings(PDO $pdo, array $admin_session): void {
    requirePermission('manage_smtp', $admin_session);

    $defaults = smtp_config_keys();
    $config   = [];

    foreach ($defaults as $key => $default) {
        $value = getConfig($key) ?? $default;

        // Mask the password — never send it to the frontend
        if ($key === 'smtp_password') {
            $decrypted = smtp_decrypt($value);
            $config[$key] = $decrypted !== '' ? '••••••••' : '';
            $config['smtp_password_set'] = $decrypted !== '';
        } else {
            $config[$key] = $value;
        }
    }

    jsonResponse(['success' => true, 'config' => $config]);
}

// ── Handler: Save SMTP Settings ──────────────────────────────────

function handle_save_smtp_settings(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_smtp', $admin_session);

    if (!$json_input) {
        jsonResponse(['success' => false, 'error' => 'Invalid JSON input'], 400);
        return;
    }

    $defaults = smtp_config_keys();
    $errors   = [];

    // ── Validate ──────────────────────────────────────────────────

    $server = trim($json_input['smtp_server'] ?? '');
    $port   = (int) ($json_input['smtp_port'] ?? 587);
    $encryption = $json_input['smtp_encryption'] ?? 'tls';
    $emailFrom  = trim($json_input['email_from'] ?? '');
    $emailTo    = trim($json_input['email_to'] ?? '');
    $enabled    = !empty($json_input['smtp_enabled']);

    if ($enabled) {
        if ($server === '') {
            $errors[] = 'SMTP server is required when email is enabled.';
        }
        if ($port < 1 || $port > 65535) {
            $errors[] = 'SMTP port must be between 1 and 65535.';
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $errors[] = 'Encryption must be tls, ssl, or none.';
        }
        if ($emailFrom !== '' && !filter_var($emailFrom, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid "From" email address.';
        }
        if ($emailTo !== '' && !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid "To" email address.';
        }
    }

    if (!empty($errors)) {
        jsonResponse(['success' => false, 'error' => implode(' ', $errors)], 422);
        return;
    }

    // ── Build config map ──────────────────────────────────────────

    $configs = [
        'smtp_enabled'             => $enabled ? '1' : '0',
        'smtp_server'              => $server,
        'smtp_port'                => (string) $port,
        'smtp_encryption'          => $encryption,
        'smtp_username'            => trim($json_input['smtp_username'] ?? ''),
        'smtp_auth'                => !empty($json_input['smtp_auth']) ? '1' : '0',
        'email_from'               => $emailFrom,
        'email_from_name'          => trim($json_input['email_from_name'] ?? ''),
        'email_to'                 => $emailTo,
        'email_on_activation_fail' => !empty($json_input['email_on_activation_fail']) ? '1' : '0',
        'email_on_key_exhausted'   => !empty($json_input['email_on_key_exhausted']) ? '1' : '0',
        'email_on_daily_summary'   => !empty($json_input['email_on_daily_summary']) ? '1' : '0',
    ];

    // Handle password separately — only update if a new value is provided
    $passwordInput = $json_input['smtp_password'] ?? null;
    if ($passwordInput !== null && $passwordInput !== '' && $passwordInput !== '••••••••') {
        $configs['smtp_password'] = smtp_encrypt($passwordInput);
    }
    // If password is empty string, clear it
    if ($passwordInput === '') {
        $configs['smtp_password'] = '';
    }

    // ── Persist ───────────────────────────────────────────────────

    try {
        $pdo->beginTransaction();

        $descriptions = [
            'smtp_enabled'             => 'Enable email notifications',
            'smtp_server'              => 'SMTP server hostname',
            'smtp_port'                => 'SMTP server port',
            'smtp_encryption'          => 'SMTP encryption: tls, ssl, or none',
            'smtp_username'            => 'SMTP authentication username',
            'smtp_password'            => 'SMTP password (encrypted)',
            'smtp_auth'                => 'Require SMTP authentication',
            'email_from'               => 'Sender email address',
            'email_from_name'          => 'Sender display name',
            'email_to'                 => 'Default notification recipient',
            'email_on_activation_fail' => 'Email on activation failure',
            'email_on_key_exhausted'   => 'Email when keys run out',
            'email_on_daily_summary'   => 'Send daily summary email',
        ];

        saveConfigBatch($pdo, $configs, $descriptions);

        $pdo->commit();

        // Clear config cache
        global $configCache;
        $configCache = [];

        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'],
            'UPDATE_SMTP_SETTINGS',
            "Updated SMTP/email settings (server: {$server}, port: {$port}, encryption: {$encryption})"
        );

        // Return sanitized config (mask password)
        $configs['smtp_password'] = isset($configs['smtp_password']) && $configs['smtp_password'] !== ''
            ? '••••••••' : '';
        $configs['smtp_password_set'] = $configs['smtp_password'] !== '';

        jsonResponse(['success' => true, 'config' => $configs]);
    } catch (\Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Failed to save SMTP settings: ' . $e->getMessage());
        jsonResponse(['success' => false, 'error' => 'Failed to save settings.'], 500);
    }
}

// ── Handler: Test SMTP Connection ────────────────────────────────

function handle_test_smtp_connection(PDO $pdo, array $admin_session, ?array $json_input = null): void {
    requirePermission('manage_smtp', $admin_session);

    // Load current config
    $server     = getConfig('smtp_server') ?? '';
    $port       = (int) (getConfig('smtp_port') ?? 587);
    $encryption = getConfig('smtp_encryption') ?? 'tls';
    $username   = getConfig('smtp_username') ?? '';
    $password   = smtp_decrypt(getConfig('smtp_password') ?? '');
    $auth       = (getConfig('smtp_auth') ?? '1') === '1';
    $from       = getConfig('email_from') ?? '';
    $fromName   = getConfig('email_from_name') ?? 'KeyGate';
    $to         = $json_input['test_recipient'] ?? getConfig('email_to') ?? '';

    // Allow overriding with unsaved form values for testing before save
    if (!empty($json_input['smtp_server'])) $server = $json_input['smtp_server'];
    if (!empty($json_input['smtp_port'])) $port = (int) $json_input['smtp_port'];
    if (!empty($json_input['smtp_encryption'])) $encryption = $json_input['smtp_encryption'];
    if (!empty($json_input['smtp_username'])) $username = $json_input['smtp_username'];
    if (!empty($json_input['smtp_password']) && $json_input['smtp_password'] !== '••••••••') {
        $password = $json_input['smtp_password'];
    }
    if (isset($json_input['smtp_auth'])) $auth = !empty($json_input['smtp_auth']);
    if (!empty($json_input['email_from'])) $from = $json_input['email_from'];
    if (!empty($json_input['email_from_name'])) $fromName = $json_input['email_from_name'];

    if ($server === '') {
        jsonResponse(['success' => false, 'error' => 'SMTP server is not configured.'], 422);
        return;
    }

    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'error' => 'A valid recipient email is required for testing.'], 422);
        return;
    }

    // Use PHPMailer
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        jsonResponse(['success' => false, 'error' => 'PHPMailer is not installed. Run: composer install'], 500);
        return;
    }

    require_once $autoloadPath;

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Enable verbose debug for logging (not shown to user)
        $debugOutput = '';
        $mail->SMTPDebug   = 2;
        $mail->Debugoutput = function ($str) use (&$debugOutput) {
            $debugOutput .= $str . "\n";
        };

        $mail->isSMTP();
        $mail->Host       = $server;
        $mail->Port       = $port;
        $mail->SMTPAuth   = $auth;
        $mail->Timeout    = 15; // 15 second timeout

        if ($auth) {
            $mail->Username = $username;
            $mail->Password = $password;
        }

        switch ($encryption) {
            case 'tls':
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                break;
            case 'ssl':
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                break;
            default:
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
                break;
        }

        $mail->setFrom($from ?: $username ?: 'noreply@localhost', $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = 'KeyGate — Test Email';
        $mail->Body    = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;">'
            . '<h2 style="color:#2563eb;">Email Configuration Test</h2>'
            . '<p>This is a test email from KeyGate.</p>'
            . '<p>If you received this, your SMTP settings are correctly configured.</p>'
            . '<hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">'
            . '<p style="color:#6b7280;font-size:12px;">'
            . 'Server: ' . htmlspecialchars($server) . ':' . $port . ' (' . $encryption . ')<br>'
            . 'Sent at: ' . date('Y-m-d H:i:s T')
            . '</p></div>';
        $mail->AltBody = "Email Configuration Test\n\nThis is a test email from KeyGate.\n"
            . "Server: {$server}:{$port} ({$encryption})\nSent at: " . date('Y-m-d H:i:s T');

        $mail->send();

        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'],
            'SMTP_TEST_SUCCESS',
            "SMTP test email sent to {$to} via {$server}:{$port}"
        );

        jsonResponse([
            'success' => true,
            'message' => "Test email sent successfully to {$to}",
        ]);

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("SMTP test failed: " . $e->getMessage() . "\nDebug: " . $debugOutput);

        logAdminActivity(
            $admin_session['admin_id'],
            $admin_session['id'],
            'SMTP_TEST_FAILED',
            "SMTP test failed: " . $e->getMessage()
        );

        // Provide user-friendly error messages
        $errorMsg = $e->getMessage();
        $hint = '';

        if (stripos($errorMsg, 'Could not authenticate') !== false) {
            $hint = ' Check your username and password. For Gmail, use an App Password.';
        } elseif (stripos($errorMsg, 'connect') !== false || stripos($errorMsg, 'timed out') !== false) {
            $hint = ' Check the server address, port, and encryption settings. Ensure the server is reachable.';
        } elseif (stripos($errorMsg, 'certificate') !== false) {
            $hint = ' The server\'s SSL/TLS certificate may be invalid. Try a different encryption mode.';
        }

        jsonResponse([
            'success' => false,
            'error'   => 'SMTP test failed: ' . $errorMsg . $hint,
        ]);
    }
}
