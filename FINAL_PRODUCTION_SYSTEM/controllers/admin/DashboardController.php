<?php
/**
 * Dashboard Controller - Statistics & Reports
 * Extracted from admin_v2.php (Phase 3 refactoring)
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Build report HTML table for a given report type.
 * Shared by generate_report (AJAX preview) and download_report (PDF).
 */
function buildReportHtml(PDO $pdo, string $reportType): string {
    $html = '';

    switch ($reportType) {
        case 'summary':
            $stmt = $pdo->query("SELECT key_status, COUNT(*) as count FROM oem_keys GROUP BY key_status");
            $keyStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $totalKeys = array_sum($keyStats);

            $html .= '<h3>Key Summary Report</h3>';
            $html .= '<table><tr><th>Status</th><th>Count</th><th>Percentage</th></tr>';
            foreach ($keyStats as $status => $count) {
                $pct = $totalKeys > 0 ? round(($count / $totalKeys) * 100, 1) : 0;
                $html .= "<tr><td>" . htmlspecialchars(ucfirst($status)) . "</td><td>$count</td><td>{$pct}%</td></tr>";
            }
            $html .= "<tr style='font-weight:bold'><td>Total</td><td>$totalKeys</td><td>100%</td></tr>";
            $html .= '</table>';

            // Technician summary
            $stmt = $pdo->query("SELECT is_active, COUNT(*) as count FROM technicians GROUP BY is_active");
            $techStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $html .= '<h3>Technician Summary</h3>';
            $html .= '<table><tr><th>Status</th><th>Count</th></tr>';
            $html .= '<tr><td>Active</td><td>' . ($techStats[1] ?? 0) . '</td></tr>';
            $html .= '<tr><td>Inactive</td><td>' . ($techStats[0] ?? 0) . '</td></tr>';
            $html .= '</table>';
            break;

        case 'usage':
            $stmt = $pdo->query("
                SELECT DATE(attempted_date) as date, COUNT(*) as count,
                       SUM(CASE WHEN attempt_result = 'success' THEN 1 ELSE 0 END) as successes,
                       SUM(CASE WHEN attempt_result != 'success' THEN 1 ELSE 0 END) as failures
                FROM activation_attempts
                WHERE attempted_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(attempted_date)
                ORDER BY date DESC
            ");
            $usageData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $html .= '<h3>Usage Report (Last 30 Days)</h3>';
            $html .= '<table><tr><th>Date</th><th>Total</th><th>Successes</th><th>Failures</th><th>Success Rate</th></tr>';
            foreach ($usageData as $row) {
                $rate = $row['count'] > 0 ? round(($row['successes'] / $row['count']) * 100, 1) : 0;
                $html .= "<tr><td>{$row['date']}</td><td>{$row['count']}</td><td>{$row['successes']}</td><td>{$row['failures']}</td><td>{$rate}%</td></tr>";
            }
            $html .= '</table>';
            break;

        case 'failed':
            $stmt = $pdo->query("
                SELECT aa.attempted_date, aa.technician_id, aa.order_number,
                       k.product_key, aa.notes
                FROM activation_attempts aa
                LEFT JOIN oem_keys k ON aa.key_id = k.id
                WHERE aa.attempt_result != 'success'
                ORDER BY aa.attempted_date DESC
                LIMIT 100
            ");
            $failedData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $html .= '<h3>Failed Activations Report</h3>';
            $html .= '<table><tr><th>Date</th><th>Technician</th><th>Order</th><th>Key</th><th>Notes</th></tr>';
            foreach ($failedData as $row) {
                $maskedKey = $row['product_key']
                    ? substr($row['product_key'], 0, 5) . '...' . substr($row['product_key'], -5)
                    : 'N/A';
                $html .= "<tr><td>{$row['attempted_date']}</td><td>" . htmlspecialchars($row['technician_id']) . "</td>"
                    . "<td>" . htmlspecialchars($row['order_number'] ?? '') . "</td>"
                    . "<td><code>$maskedKey</code></td>"
                    . "<td>" . htmlspecialchars($row['notes'] ?? '') . "</td></tr>";
            }
            $html .= '</table>';
            break;

        case 'monthly':
            $stmt = $pdo->query("
                SELECT DATE_FORMAT(attempted_date, '%Y-%m') as month,
                       COUNT(*) as total,
                       SUM(CASE WHEN attempt_result = 'success' THEN 1 ELSE 0 END) as successes,
                       COUNT(DISTINCT technician_id) as unique_techs
                FROM activation_attempts
                GROUP BY DATE_FORMAT(attempted_date, '%Y-%m')
                ORDER BY month DESC
                LIMIT 12
            ");
            $monthlyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $html .= '<h3>Monthly Statistics Report</h3>';
            $html .= '<table><tr><th>Month</th><th>Total Activations</th><th>Successes</th><th>Success Rate</th><th>Active Technicians</th></tr>';
            foreach ($monthlyData as $row) {
                $rate = $row['total'] > 0 ? round(($row['successes'] / $row['total']) * 100, 1) : 0;
                $html .= "<tr><td>{$row['month']}</td><td>{$row['total']}</td><td>{$row['successes']}</td><td>{$rate}%</td><td>{$row['unique_techs']}</td></tr>";
            }
            $html .= '</table>';
            break;
    }

    return $html;
}

function handle_get_stats(PDO $pdo, array $admin_session): void {
    requirePermission('view_dashboard', $admin_session);

    $stats = [];

    // Key statistics
    $stmt = $pdo->query("SELECT key_status, COUNT(*) as count FROM oem_keys GROUP BY key_status");
    $key_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['keys'] = [
        'unused' => $key_stats['unused'] ?? 0,
        'allocated' => $key_stats['allocated'] ?? 0,
        'good' => $key_stats['good'] ?? 0,
        'bad' => $key_stats['bad'] ?? 0,
        'retry' => $key_stats['retry'] ?? 0,
        'total' => array_sum($key_stats)
    ];

    // Technician statistics
    $stmt = $pdo->query("SELECT is_active, COUNT(*) as count FROM technicians GROUP BY is_active");
    $tech_stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats['technicians'] = [
        'active' => $tech_stats[1] ?? 0,
        'inactive' => $tech_stats[0] ?? 0,
        'total' => ($tech_stats[1] ?? 0) + ($tech_stats[0] ?? 0)
    ];

    // Activation statistics
    $stmt = $pdo->query("SELECT COUNT(*) FROM activation_attempts WHERE DATE(attempted_date) = CURDATE()");
    $stats['activations']['today'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM activation_attempts WHERE YEARWEEK(attempted_date) = YEARWEEK(CURDATE())");
    $stats['activations']['week'] = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT COUNT(*) FROM activation_attempts WHERE YEAR(attempted_date) = YEAR(CURDATE()) AND MONTH(attempted_date) = MONTH(CURDATE())");
    $stats['activations']['month'] = $stmt->fetchColumn();

    // Recent activity
    $stmt = $pdo->prepare("
        SELECT aal.created_at, au.username, aal.action, aal.description
        FROM admin_activity_log aal
        LEFT JOIN admin_users au ON aal.admin_id = au.id
        ORDER BY aal.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $stats['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'stats' => $stats]);
}

function handle_generate_report(PDO $pdo, array $admin_session): void {
    requirePermission('view_reports', $admin_session);
    $reportType = $_GET['type'] ?? 'summary';
    $html = buildReportHtml($pdo, $reportType);
    echo json_encode(['success' => true, 'html' => $html]);
}

function handle_download_report(PDO $pdo, array $admin_session): void {
    requirePermission('view_reports', $admin_session);

    $allowedTypes = ['summary', 'usage', 'failed', 'monthly'];
    $reportType = $_GET['type'] ?? 'summary';
    if (!in_array($reportType, $allowedTypes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid report type']);
        exit;
    }

    $reportHtml = buildReportHtml($pdo, $reportType);
    if (empty($reportHtml)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'No report data available']);
        exit;
    }

    $reportLabels = [
        'summary' => 'Key Summary Report',
        'usage'   => 'Usage Report (Last 30 Days)',
        'failed'  => 'Failed Activations Report',
        'monthly' => 'Monthly Statistics Report'
    ];

    $adminName = htmlspecialchars($admin_session['full_name'] ?? $admin_session['username']);
    $generatedAt = date('Y-m-d H:i:s');
    $reportTitle = $reportLabels[$reportType] ?? ucfirst($reportType) . ' Report';

    $fullHtml = '<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; margin: 30px; color: #333; }
        h2 { color: #1a237e; border-bottom: 3px solid #1a237e; padding-bottom: 10px; margin-bottom: 5px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 20px; }
        h3 { color: #333; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th { background: #e8eaf6; color: #1a237e; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        tr:nth-child(even) td { background: #f9f9f9; }
        code { background: #f5f5f5; padding: 2px 5px; border-radius: 3px; font-size: 11px; }
        .footer { margin-top: 40px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
    <h2>' . htmlspecialchars($reportTitle) . '</h2>
    <div class="meta">Generated: ' . $generatedAt . ' &bull; Admin: ' . $adminName . '</div>
    ' . $reportHtml . '
    <div class="footer">OEM Activation System v2.0 &mdash; Confidential</div>
</body>
</html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($fullHtml);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = 'oem_report_' . $reportType . '_' . date('Y-m-d_His') . '.pdf';
    $pdfOutput = $dompdf->output();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfOutput));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdfOutput;

    logAdminActivity(
        $admin_session['admin_id'],
        $admin_session['id'],
        'DOWNLOAD_REPORT',
        "Downloaded {$reportType} report as PDF"
    );

    exit;
}
