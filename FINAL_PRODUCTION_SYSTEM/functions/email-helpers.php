<?php
/**
 * Email Helper Functions — KeyGate
 *
 * Uses PHPMailer with SMTP settings from system_config.
 * Handles sending alerts, notifications, and reports.
 */

require_once __DIR__ . '/../controllers/admin/SmtpController.php';

/**
 * Send an email using the configured SMTP settings.
 *
 * @param string       $to       Recipient email
 * @param string       $subject  Email subject
 * @param string       $body     HTML body
 * @param string|null  $textBody Plain text alternative (optional)
 * @return array{success: bool, error?: string}
 */
function sendEmail(string $to, string $subject, string $body, ?string $textBody = null): array {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        error_log("PHPMailer not installed. Run: composer install");
        return ['success' => false, 'error' => 'PHPMailer not installed'];
    }
    require_once $autoloadPath;

    // Load SMTP config from system_config
    $smtpServer     = getConfig('smtp_server') ?? '';
    $smtpPort       = (int)(getConfig('smtp_port') ?? 587);
    $smtpEncryption = getConfig('smtp_encryption') ?? 'tls';
    $smtpUsername   = getConfig('smtp_username') ?? '';
    $smtpPasswordEnc = getConfig('smtp_password') ?? '';
    $emailFrom      = getConfig('email_from') ?? '';
    $emailFromName  = getConfig('email_from_name') ?? 'KeyGate';

    if (empty($smtpServer) || empty($emailFrom)) {
        return ['success' => false, 'error' => 'SMTP not configured'];
    }

    // Decrypt password
    $smtpPassword = '';
    if ($smtpPasswordEnc !== '') {
        $smtpPassword = smtp_decrypt($smtpPasswordEnc);
        if ($smtpPassword === false) {
            return ['success' => false, 'error' => 'Failed to decrypt SMTP password'];
        }
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtpServer;
        $mail->Port       = $smtpPort;
        $mail->SMTPAuth   = !empty($smtpUsername);
        $mail->Username   = $smtpUsername;
        $mail->Password   = $smtpPassword;

        if ($smtpEncryption === 'tls') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif ($smtpEncryption === 'ssl') {
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        $mail->Timeout = 10;

        // Recipients
        $mail->setFrom($emailFrom, $emailFromName);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $body;
        if ($textBody) {
            $mail->AltBody = $textBody;
        }

        $mail->send();
        return ['success' => true];
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log("Email send failed: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Send a key pool alert email.
 *
 * @param string $edition    Product edition (e.g. "Windows 11 Pro OEM")
 * @param int    $remaining  Unused keys remaining
 * @param string $level      Alert level: 'low' or 'critical'
 */
function sendKeyPoolAlert(string $edition, int $remaining, string $level): void {
    $notifyEmail = getConfig('email_to') ?? getConfig('email_from') ?? '';
    if (empty($notifyEmail)) {
        error_log("Key pool alert: no notification email configured");
        return;
    }

    $levelLabel = strtoupper($level);
    $subject = "[KeyGate] $levelLabel: $edition — $remaining keys remaining";

    $bgColor = $level === 'critical' ? '#dc2626' : '#f59e0b';
    $body = <<<HTML
<div style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 500px;">
    <div style="background: $bgColor; color: white; padding: 16px 20px; border-radius: 8px 8px 0 0;">
        <h2 style="margin: 0; font-size: 18px;">Key Pool Alert: $levelLabel</h2>
    </div>
    <div style="background: #f9fafb; padding: 20px; border: 1px solid #e5e7eb; border-top: none; border-radius: 0 0 8px 8px;">
        <p style="margin: 0 0 12px;"><strong>Product Edition:</strong> $edition</p>
        <p style="margin: 0 0 12px;"><strong>Unused Keys Remaining:</strong> $remaining</p>
        <p style="margin: 0 0 12px;"><strong>Alert Level:</strong> $levelLabel</p>
        <p style="margin: 16px 0 0; color: #6b7280; font-size: 13px;">
            Log into the KeyGate admin panel to import more keys or adjust alert thresholds.
        </p>
    </div>
    <p style="color: #9ca3af; font-size: 11px; margin-top: 12px;">Sent by KeyGate — Automated Key Pool Monitoring</p>
</div>
HTML;

    $textBody = "Key Pool Alert: $levelLabel\n\nProduct Edition: $edition\nUnused Keys Remaining: $remaining\nLevel: $levelLabel\n\nLog into KeyGate admin panel to manage keys.";

    $result = sendEmail($notifyEmail, $subject, $body, $textBody);
    if (!$result['success']) {
        error_log("Key pool alert email failed: " . ($result['error'] ?? 'unknown'));
    }
}
