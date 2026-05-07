<?php
/**
 * KeyGate — Web Installer
 * Joomla-style setup wizard: upload files → navigate to /install/ → follow steps
 *
 * Steps:
 *   1. Environment Check   — PHP version, extensions, directory permissions
 *   2. Database Setup      — connection details, test, create DB if needed
 *   3. Install Tables      — run all migrations in order
 *   4. Admin Account       — create first super_admin user
 *   5. System Config       — basic settings (name, URL, timezone)
 *   6. Complete            — write config, show success
 */

// Auto-unlock recovery (P0): if install.lock exists but install never
// completed (admin_users empty/missing), clear the lock so user can resume.
// Inlined to avoid dragging ajax.php's JSON header + dispatch into HTML output.
$lockFile = __DIR__ . '/../install.lock';
$configFile = __DIR__ . '/../config.php';
if (file_exists($lockFile) && file_exists($configFile)) {
    $configSrc = @file_get_contents($configFile);
    if ($configSrc !== false
        && preg_match("/'host'\s*=>\s*'([^']+)'/", $configSrc, $hM)
        && preg_match("/'dbname'\s*=>\s*'([^']+)'/", $configSrc, $nM)
        && preg_match("/'username'\s*=>\s*'([^']+)'/", $configSrc, $uM)
        && preg_match("/'password'\s*=>\s*'([^']*)'/", $configSrc, $pM)
        && preg_match("/'port'\s*=>\s*(\d+)/", $configSrc, $portM)) {
        $autoHost = strtolower($hM[1]) === 'localhost' ? '127.0.0.1' : $hM[1];
        // Read DB_PREFIX from config (empty for legacy installs).
        $autoPrefix = '';
        if (preg_match("/define\(\s*'DB_PREFIX'\s*,\s*'([^']*)'\s*\)/", $configSrc, $pxM)) {
            $autoPrefix = $pxM[1];
        }
        $autoAdminTable = $autoPrefix . 'admin_users';
        try {
            $autoPdo = new PDO(
                "mysql:host={$autoHost};port={$portM[1]};dbname={$nM[1]};charset=utf8mb4",
                $uM[1], $pM[1],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $hasAdminTable = (bool) $autoPdo->query("SHOW TABLES LIKE " . $autoPdo->quote($autoAdminTable))->fetch();
            $adminCount = $hasAdminTable ? (int) $autoPdo->query("SELECT COUNT(*) FROM `{$autoAdminTable}`")->fetchColumn() : 0;
            if (!$hasAdminTable || $adminCount === 0) {
                @unlink($lockFile);
                @file_put_contents(
                    __DIR__ . '/install.log',
                    '[' . date('Y-m-d H:i:s') . "] auto-unlock from index.php — admin_users empty/missing\n",
                    FILE_APPEND
                );
            }
        } catch (PDOException $autoE) {
            @file_put_contents(
                __DIR__ . '/install.log',
                '[' . date('Y-m-d H:i:s') . '] auto-unlock skipped: ' . $autoE->getMessage() . "\n",
                FILE_APPEND
            );
        }
    }
}

// Prevent running if already installed
if (file_exists($lockFile)) {
    $installed = json_decode(file_get_contents($lockFile), true);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Already Installed</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
            .card { background: white; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 48px; max-width: 500px; text-align: center; }
            .icon { font-size: 48px; margin-bottom: 16px; }
            h1 { font-size: 24px; color: #1e293b; margin-bottom: 8px; }
            p { color: #64748b; line-height: 1.6; margin-bottom: 16px; }
            .warn { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; }
            code { background: #f1f5f9; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="icon">&#9989;</div>
            <h1>System Already Installed</h1>
            <p>KeyGate was installed on<br>
            <strong><?= htmlspecialchars($installed['installed_at'] ?? 'unknown') ?></strong></p>
            <div class="warn">
                <strong>Security:</strong> Delete the <code>/install/</code> directory from your server to prevent unauthorized access.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Detect base URL
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseUrl = dirname($scriptDir); // parent of /install/
$serverUrl = $protocol . '://' . $host . $baseUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KeyGate — Installer</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --primary-light: #dbeafe;
            --success: #16a34a;
            --success-light: #dcfce7;
            --warning: #d97706;
            --warning-light: #fef3c7;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --text: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --radius: 12px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
        }

        /* ── Header ─────────────────────── */
        .header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: white;
            padding: 32px 0 24px;
            text-align: center;
        }
        .header h1 { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
        .header p { opacity: 0.85; font-size: 14px; }

        /* ── Steps Bar ──────────────────── */
        .steps-bar {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 24px 16px;
            background: white;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: var(--text-muted);
            transition: all 0.3s;
        }
        .step-item .num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            border: 2px solid var(--border);
            background: white;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        .step-item.active { color: var(--primary); font-weight: 600; }
        .step-item.active .num { background: var(--primary); color: white; border-color: var(--primary); }
        .step-item.done { color: var(--success); }
        .step-item.done .num { background: var(--success); color: white; border-color: var(--success); }
        .step-connector { width: 32px; height: 2px; background: var(--border); align-self: center; }
        .step-connector.done { background: var(--success); }

        /* ── Main Content ───────────────── */
        .container {
            max-width: 720px;
            margin: 32px auto;
            padding: 0 16px;
        }
        .card {
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
            padding: 32px;
            margin-bottom: 24px;
        }
        .card h2 { font-size: 20px; margin-bottom: 4px; }
        .card .subtitle { color: var(--text-muted); font-size: 14px; margin-bottom: 24px; }

        /* ── Check List ─────────────────── */
        .check-list { list-style: none; }
        .check-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        .check-item:last-child { border-bottom: none; }
        .check-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            margin-right: 12px;
            flex-shrink: 0;
        }
        .check-icon.pass { background: var(--success-light); color: var(--success); }
        .check-icon.warn { background: var(--warning-light); color: var(--warning); }
        .check-icon.fail { background: var(--danger-light); color: var(--danger); }
        .check-label { flex: 1; }
        .check-value { font-size: 13px; color: var(--text-muted); font-family: monospace; }

        /* ── Forms ──────────────────────── */
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-light);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .form-hint { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

        /* ── Buttons ────────────────────── */
        .btn-row { display: flex; justify-content: space-between; margin-top: 24px; }
        .btn {
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover:not(:disabled) { background: var(--primary-hover); }
        .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover:not(:disabled) { background: var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover:not(:disabled) { background: #15803d; }
        .btn-outline { background: transparent; color: var(--primary); border: 1px solid var(--primary); }
        .btn-outline:hover:not(:disabled) { background: var(--primary-light); }

        /* ── Progress ───────────────────── */
        .progress-bar {
            width: 100%;
            height: 8px;
            background: var(--border);
            border-radius: 4px;
            overflow: hidden;
            margin: 16px 0;
        }
        .progress-fill {
            height: 100%;
            background: var(--primary);
            border-radius: 4px;
            transition: width 0.4s ease;
            width: 0%;
        }
        .migration-log {
            background: #0f172a;
            color: #94a3b8;
            border-radius: 8px;
            padding: 16px;
            font-family: 'Cascadia Code', 'Fira Code', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            line-height: 1.8;
        }
        .migration-log .ok { color: #4ade80; }
        .migration-log .skip { color: #fbbf24; }
        .migration-log .err { color: #f87171; }

        /* ── Alert ──────────────────────── */
        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 16px;
        }
        .alert-success { background: var(--success-light); color: #166534; border: 1px solid #86efac; }
        .alert-danger { background: var(--danger-light); color: #991b1b; border: 1px solid #fca5a5; }
        .alert-warning { background: var(--warning-light); color: #92400e; border: 1px solid #fcd34d; }
        .alert-info { background: var(--primary-light); color: #1e40af; border: 1px solid #93c5fd; }

        /* ── Complete page ──────────────── */
        .complete-icon { text-align: center; font-size: 64px; margin-bottom: 16px; }
        .info-grid {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 8px 16px;
            font-size: 14px;
        }
        .info-grid dt { font-weight: 600; color: var(--text-muted); }
        .info-grid dd { font-family: monospace; }

        /* ── Spinner ────────────────────── */
        .spinner {
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            display: inline-block;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }

        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .step-item span:not(.num) { display: none; }
            .step-connector { width: 16px; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>KeyGate</h1>
    <p>Installation Wizard</p>
</div>

<div class="steps-bar" id="stepsBar">
    <div class="step-item active" data-step="1"><span class="num">1</span><span>Environment</span></div>
    <div class="step-connector"></div>
    <div class="step-item" data-step="2"><span class="num">2</span><span>Database</span></div>
    <div class="step-connector"></div>
    <div class="step-item" data-step="3"><span class="num">3</span><span>Install</span></div>
    <div class="step-connector"></div>
    <div class="step-item" data-step="4"><span class="num">4</span><span>Admin</span></div>
    <div class="step-connector"></div>
    <div class="step-item" data-step="5"><span class="num">5</span><span>Settings</span></div>
    <div class="step-connector"></div>
    <div class="step-item" data-step="6"><span class="num">6</span><span>Complete</span></div>
</div>

<div class="container">

    <!-- ── Step 1: Environment ──────────────────────── -->
    <div class="step-panel" id="step1">
        <div class="card">
            <h2>Environment Check</h2>
            <p class="subtitle">Verifying your server meets all requirements</p>
            <div id="envLoading" style="text-align:center;padding:32px;">
                <div class="spinner" style="width:32px;height:32px;border-width:3px;border-color:var(--border);border-top-color:var(--primary);"></div>
                <p style="margin-top:12px;color:var(--text-muted);">Checking environment...</p>
            </div>
            <div id="envResults" class="hidden">
                <h3 style="font-size:14px;text-transform:uppercase;color:var(--text-muted);margin-bottom:8px;">PHP</h3>
                <ul class="check-list" id="phpChecks"></ul>

                <h3 style="font-size:14px;text-transform:uppercase;color:var(--text-muted);margin:20px 0 8px;">Extensions</h3>
                <ul class="check-list" id="extChecks"></ul>

                <h3 style="font-size:14px;text-transform:uppercase;color:var(--text-muted);margin:20px 0 8px;">PHP Settings</h3>
                <ul class="check-list" id="settingsChecks"></ul>

                <h3 style="font-size:14px;text-transform:uppercase;color:var(--text-muted);margin:20px 0 8px;">Directories</h3>
                <ul class="check-list" id="dirChecks"></ul>
            </div>
            <div class="btn-row">
                <button class="btn btn-outline" onclick="runEnvCheck()">Re-check</button>
                <button class="btn btn-primary" id="envNext" disabled onclick="goStep(2)">Next &rarr;</button>
            </div>
        </div>
    </div>

    <!-- ── Step 2: Database ─────────────────────────── -->
    <div class="step-panel hidden" id="step2">
        <div class="card">
            <h2>Database Configuration</h2>
            <p class="subtitle">Enter your MariaDB / MySQL connection details</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Database Host</label>
                    <input type="text" id="dbHost" value="127.0.0.1" />
                    <div class="form-hint">Use <code>127.0.0.1</code> on aaPanel/cPanel (avoids Unix-socket lookup). Use <code>localhost</code> only if you also set Socket Path below.</div>
                </div>
                <div class="form-group">
                    <label>Port</label>
                    <input type="number" id="dbPort" value="3306" />
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="dbUser" placeholder="root" />
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="dbPass" placeholder="Database password" />
                </div>
            </div>
            <div class="form-group">
                <label>Database Name</label>
                <input type="text" id="dbName" value="oem_activation" />
                <div class="form-hint">Will be created if it doesn't exist (requires CREATE privilege).</div>
            </div>
            <div class="form-group" style="margin-top:6px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:500;">
                    <input type="checkbox" id="dbSkipCreate" style="width:auto;" />
                    <span>Database already exists — skip CREATE DATABASE</span>
                </label>
                <div class="form-hint">Tick this if your control panel does not grant CREATE privilege (Plesk, CyberPanel, ISPConfig). Pre-create the database in your panel first.</div>
            </div>
            <details class="form-group" style="margin-top:8px;">
                <summary style="cursor:pointer;color:var(--primary);font-weight:600;">Advanced (optional)</summary>
                <div style="margin-top:10px;">
                    <label>Socket Path</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="dbSocket" placeholder="/tmp/mysql.sock" style="flex:1;" />
                        <button type="button" class="btn btn-outline" style="white-space:nowrap;" onclick="detectSocket()">Detect</button>
                    </div>
                    <div class="form-hint">If set, host/port are ignored. Click <strong>Detect</strong> to auto-find. Common paths: <code>/tmp/mysql.sock</code>, <code>/var/run/mysqld/mysqld.sock</code>, <code>/www/server/mysql/mysql.sock</code>.</div>
                </div>
                <div style="margin-top:14px;">
                    <label>Charset</label>
                    <select id="dbCharset" style="width:100%;">
                        <option value="">Auto-detect (recommended)</option>
                        <option value="utf8mb4">utf8mb4 (MySQL ≥5.7 / MariaDB ≥10.2)</option>
                        <option value="utf8">utf8 / utf8mb3 (legacy)</option>
                    </select>
                    <div class="form-hint">Auto-detect downgrades to <code>utf8</code> for very old servers (MySQL &lt; 5.7).</div>
                </div>
                <div style="margin-top:14px;">
                    <label>Table Prefix (optional)</label>
                    <input type="text" id="dbPrefix" placeholder="e.g. kg_" maxlength="10" pattern="^([a-z][a-z0-9_]{0,9})?$" />
                    <div class="form-hint">Lowercase letters, digits, underscore. Lets you run KeyGate alongside other apps in the same database. Leave empty for default.</div>
                </div>
            </details>
            <div id="dbTestResult" class="hidden"></div>
            <div class="btn-row">
                <button class="btn btn-secondary" onclick="goStep(1)">&larr; Back</button>
                <div style="display:flex;gap:8px;">
                    <button class="btn btn-outline" id="dbTestBtn" onclick="testDb()">Test Connection</button>
                    <button class="btn btn-primary" id="dbNext" disabled onclick="goStep(3)">Next &rarr;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Step 3: Install Tables ───────────────────── -->
    <div class="step-panel hidden" id="step3">
        <div class="card">
            <h2>Database Installation</h2>
            <p class="subtitle">Creating tables and running migrations</p>
            <div id="migrationPre">
                <div class="alert alert-info">
                    <strong>Ready to install.</strong> This will create all required database tables (18 migrations). Existing tables will be skipped.
                </div>
                <button class="btn btn-primary" id="runMigBtn" onclick="runMigrations()" style="width:100%;">
                    Install Database &rarr;
                </button>
            </div>
            <div id="migrationProgress" class="hidden">
                <div style="display:flex;justify-content:space-between;font-size:13px;color:var(--text-muted);margin-bottom:4px;">
                    <span id="migStatus">Running migrations...</span>
                    <span id="migCount">0 / 18</span>
                </div>
                <div class="progress-bar"><div class="progress-fill" id="migBar"></div></div>
                <div class="migration-log" id="migLog"></div>
            </div>
            <div class="btn-row">
                <button class="btn btn-secondary" id="migBack" onclick="goStep(2)">&larr; Back</button>
                <button class="btn btn-primary hidden" id="migNext" onclick="goStep(4)">Next &rarr;</button>
            </div>
        </div>
    </div>

    <!-- ── Step 4: Admin Account ────────────────────── -->
    <div class="step-panel hidden" id="step4">
        <div class="card">
            <h2>Administrator Account</h2>
            <p class="subtitle">Create the first admin user for the management panel</p>
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" id="adminUser" value="admin" />
                </div>
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" id="adminName" placeholder="John Smith" />
                </div>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="adminEmail" placeholder="admin@example.com" />
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="adminPass" placeholder="Min 8 chars, upper+lower+digit" />
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="adminPass2" placeholder="Repeat password" />
                </div>
            </div>
            <div id="adminResult" class="hidden"></div>
            <div class="btn-row">
                <button class="btn btn-secondary" onclick="goStep(3)">&larr; Back</button>
                <button class="btn btn-primary" id="adminBtn" onclick="createAdmin()">Create Admin &rarr;</button>
            </div>
        </div>
    </div>

    <!-- ── Step 5: System Config ────────────────────── -->
    <div class="step-panel hidden" id="step5">
        <div class="card">
            <h2>System Configuration</h2>
            <p class="subtitle">Basic settings for your installation</p>
            <div class="form-group">
                <label>System Name</label>
                <input type="text" id="cfgName" value="KeyGate" />
            </div>
            <div class="form-group">
                <label>Server URL</label>
                <input type="text" id="cfgUrl" value="<?= htmlspecialchars($serverUrl) ?>" />
                <div class="form-hint">Base URL where the system is accessible (no trailing slash)</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Timezone</label>
                    <select id="cfgTimezone">
                        <?php foreach (timezone_identifiers_list() as $tz): ?>
                        <option value="<?= $tz ?>" <?= $tz === 'UTC' ? 'selected' : '' ?>><?= $tz ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Default Language</label>
                    <select id="cfgLang">
                        <option value="en">English</option>
                        <option value="ru">Russian</option>
                    </select>
                </div>
            </div>
            <div id="cfgResult" class="hidden"></div>
            <div class="btn-row">
                <button class="btn btn-secondary" onclick="goStep(4)">&larr; Back</button>
                <button class="btn btn-success" id="cfgBtn" onclick="finalize()">Complete Installation &rarr;</button>
            </div>
        </div>
    </div>

    <!-- ── Step 6: Complete ─────────────────────────── -->
    <div class="step-panel hidden" id="step6">
        <div class="card" style="text-align:center;">
            <div class="complete-icon">&#127881;</div>
            <h2>Installation Complete!</h2>
            <p class="subtitle">KeyGate is ready to use</p>

            <div style="text-align:left;margin:24px 0;">
                <dl class="info-grid" id="completeInfo"></dl>
            </div>

            <div class="alert alert-success" style="text-align:left;">
                <strong>&#128274; Network Auto-Detected:</strong>
                <p style="margin:6px 0 0;font-size:13px;">
                    Your current network has been automatically added as a <strong>Trusted Network</strong>
                    (2FA bypass + USB auth enabled) and to the <strong>Admin IP Whitelist</strong>.
                    You can manage these in the admin panel under Security settings.
                </p>
            </div>

            <div class="alert alert-warning" style="text-align:left;">
                <strong>&#9888; Security Reminder:</strong>
                <ul style="margin:8px 0 0 20px;font-size:13px;">
                    <li>Delete the <code>/install/</code> directory from your server</li>
                    <li>Set <code>config.php</code> permissions to 640</li>
                    <li>Enable HTTPS in production</li>
                </ul>
            </div>

            <a class="btn btn-primary" id="goToAdmin" href="../secure-admin.php" style="text-decoration:none;margin-top:16px;">
                Open Admin Panel &rarr;
            </a>
        </div>
    </div>

</div>

<script>
const AJAX = 'ajax.php';
let currentStep = 1;
let dbCredentials = {};

// ── Step Navigation ──────────────────────────────────
function goStep(n) {
    document.querySelectorAll('.step-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById('step' + n).classList.remove('hidden');

    document.querySelectorAll('.step-item').forEach(s => {
        const sn = parseInt(s.dataset.step);
        s.classList.remove('active', 'done');
        if (sn < n) s.classList.add('done');
        else if (sn === n) s.classList.add('active');
    });
    document.querySelectorAll('.step-connector').forEach((c, i) => {
        c.classList.toggle('done', i < n - 1);
    });

    currentStep = n;
    window.scrollTo(0, 0);
}

// ── AJAX Helper ──────────────────────────────────────
async function post(action, data = {}) {
    const body = new URLSearchParams({ action, ...data });
    const res = await fetch(AJAX, { method: 'POST', body });
    return res.json();
}

// ── Step 1: Environment Check ────────────────────────
async function runEnvCheck() {
    document.getElementById('envLoading').classList.remove('hidden');
    document.getElementById('envResults').classList.add('hidden');
    document.getElementById('envNext').disabled = true;

    // Remove any previous summary banner
    const oldBanner = document.getElementById('envSummaryBanner');
    if (oldBanner) oldBanner.remove();

    const data = await post('preflight');

    document.getElementById('envLoading').classList.add('hidden');
    document.getElementById('envResults').classList.remove('hidden');

    let failCount = 0;
    let warnCount = 0;
    let warnDetails = [];

    function renderChecks(containerId, checks) {
        const ul = document.getElementById(containerId);
        ul.innerHTML = '';
        checks.forEach(c => {
            if (c.status === 'fail') failCount++;
            if (c.status === 'warn') {
                warnCount++;
                warnDetails.push(c.hint || c.label);
            }
            const li = document.createElement('li');
            li.className = 'check-item';
            let hint = '';
            if (c.hint) {
                hint = `<div style="font-size:12px;color:#92400e;margin:2px 0 0 36px;">${c.hint}</div>`;
            }
            li.innerHTML = `
                <div class="check-icon ${c.status}">
                    ${c.status === 'pass' ? '&#10003;' : c.status === 'warn' ? '!' : '&#10007;'}
                </div>
                <span class="check-label">${c.label}</span>
                <span class="check-value">${c.value || ''}</span>
                ${hint}
            `;
            ul.appendChild(li);
        });
    }

    renderChecks('phpChecks', data.php || []);
    renderChecks('extChecks', data.extensions || []);
    renderChecks('settingsChecks', data.settings || []);
    renderChecks('dirChecks', data.directories || []);

    // Show summary banner above the buttons
    const btnRow = document.querySelector('#step1 .btn-row');
    if (failCount > 0) {
        const banner = document.createElement('div');
        banner.id = 'envSummaryBanner';
        banner.className = 'alert alert-danger';
        banner.innerHTML = `<strong>&#10007; ${failCount} critical error(s) found.</strong> Fix the issues marked with <span style="color:#dc2626;">&#10007;</span> before proceeding.`;
        btnRow.parentNode.insertBefore(banner, btnRow);
    } else if (warnCount > 0) {
        const banner = document.createElement('div');
        banner.id = 'envSummaryBanner';
        banner.className = 'alert alert-warning';
        banner.innerHTML = `<strong>&#9888; ${warnCount} warning(s) found.</strong> The system will work but with limited functionality. Review items marked with <span style="color:#d97706;">!</span> above. You may continue, but it is recommended to fix these first.`;
        btnRow.parentNode.insertBefore(banner, btnRow);
    }

    document.getElementById('envNext').disabled = failCount > 0;
}

// ── Step 2: Database Test ────────────────────────────
async function detectSocket() {
    const data = await post('detect_socket', {});
    const input = document.getElementById('dbSocket');
    if (data.success && data.suggested) {
        input.value = data.suggested;
        alert('Found ' + data.sockets.length + ' socket(s). Picked: ' + data.suggested);
    } else {
        alert('No Unix socket found in common paths. Stick with TCP (host=127.0.0.1).');
    }
}

async function testDb() {
    const btn = document.getElementById('dbTestBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner" style="border-color:var(--primary-light);border-top-color:var(--primary);"></span> Testing...';

    const $ = id => document.getElementById(id);
    dbCredentials = {
        db_host:        $('dbHost').value.trim() || '127.0.0.1',
        db_port:        $('dbPort').value,
        db_user:        $('dbUser').value,
        db_pass:        $('dbPass').value,
        db_name:        $('dbName').value,
        db_socket:      $('dbSocket') ? $('dbSocket').value.trim() : '',
        db_prefix:      $('dbPrefix') ? $('dbPrefix').value.trim() : '',
        db_charset:     $('dbCharset') ? $('dbCharset').value : '',
        skip_create_db: $('dbSkipCreate') && $('dbSkipCreate').checked ? '1' : '',
    };

    const data = await post('test_db', dbCredentials);
    const div = $('dbTestResult');
    div.classList.remove('hidden');

    if (data.success) {
        div.className = 'alert alert-success';
        div.innerHTML = `<strong>&#10003; Connected!</strong> ${data.message}`;
        // Persist server-resolved charset back into the dropdown so steps 3+ use it.
        if (data.charset && $('dbCharset')) $('dbCharset').value = data.charset;
        $('dbNext').disabled = false;
    } else {
        div.className = 'alert alert-danger';
        let html = `<strong>&#10007; Failed:</strong> ${data.message}`;
        if (data.suggest_skip_create) {
            html += ` <button class="btn btn-outline" style="margin-left:8px;font-size:12px;padding:4px 10px;" onclick="document.getElementById('dbSkipCreate').checked=true;testDb();return false;">Tick &amp; retry</button>`;
        }
        div.innerHTML = html;
        $('dbNext').disabled = true;
    }

    btn.disabled = false;
    btn.innerHTML = 'Test Connection';
}

// ── Step 3: Migrations (async per-file, survives short max_execution_time) ──
async function runMigrations() {
    const $ = id => document.getElementById(id);
    $('migrationPre').classList.add('hidden');
    $('migrationProgress').classList.remove('hidden');
    $('migBack').disabled = true;
    $('migNext').classList.add('hidden');

    const log = $('migLog');
    log.innerHTML = '';

    // ── 1. Init: get migration list + applied flags ──
    const init = await post('install_db_init', dbCredentials);
    if (!init.success) {
        $('migStatus').textContent = 'Initialization failed';
        $('migStatus').style.color = 'var(--danger)';
        log.innerHTML += `<div class="err">ERROR: ${init.message || 'Unknown error'}</div>`;
        $('migBack').disabled = false;
        return;
    }

    const list = init.migrations || [];
    const total = list.length;
    let done = 0;
    let hadError = false;

    const updateProgress = () => {
        const pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('migBar').style.width = pct + '%';
        $('migCount').textContent = done + ' / ' + total;
    };

    // ── 2. Step through each migration ──
    for (const m of list) {
        if (!m.exists) {
            done++;
            log.innerHTML += `<div class="skip">→ ${m.file}: not found (skipped)</div>`;
            updateProgress();
            continue;
        }
        if (m.applied) {
            done++;
            log.innerHTML += `<div class="skip">→ ${m.file}: already applied</div>`;
            updateProgress();
            continue;
        }

        const pendingRow = document.createElement('div');
        pendingRow.className = 'pending';
        pendingRow.innerHTML = `<span class="spinner" style="display:inline-block;width:12px;height:12px;border-width:2px;"></span> ${m.file}…`;
        log.appendChild(pendingRow);
        log.scrollTop = log.scrollHeight;

        const r = await post('install_db_step', { ...dbCredentials, file: m.file, version: m.version });
        log.removeChild(pendingRow);

        const cls = r.status === 'ok' ? 'ok' : r.status === 'skipped' ? 'skip' : 'err';
        const icon = r.status === 'ok' ? '✓' : r.status === 'skipped' ? '→' : '✗';
        log.innerHTML += `<div class="${cls}">${icon} ${m.file}: ${r.message || ''}</div>`;
        log.scrollTop = log.scrollHeight;

        done++;
        updateProgress();

        if (!r.success && r.status === 'error') {
            hadError = true;
            // Stop loop on first hard error so user can read it.
            break;
        }
    }

    if (!hadError) {
        $('migStatus').textContent = 'All migrations complete!';
        $('migStatus').style.color = 'var(--success)';
        $('migNext').classList.remove('hidden');
    } else {
        $('migStatus').textContent = 'Installation failed';
        $('migStatus').style.color = 'var(--danger)';
        $('migBack').disabled = false;
    }
}

// ── Step 4: Create Admin ────────────────────────────
async function createAdmin() {
    const btn = document.getElementById('adminBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Creating...';

    const data = await post('create_admin', {
        ...dbCredentials,
        username: document.getElementById('adminUser').value,
        full_name: document.getElementById('adminName').value,
        email: document.getElementById('adminEmail').value,
        password: document.getElementById('adminPass').value,
        password_confirm: document.getElementById('adminPass2').value,
    });

    const div = document.getElementById('adminResult');
    div.classList.remove('hidden');

    if (data.success) {
        div.className = 'alert alert-success';
        div.innerHTML = `<strong>&#10003;</strong> ${data.message}`;
        setTimeout(() => goStep(5), 800);
    } else {
        div.className = 'alert alert-danger';
        div.innerHTML = `<strong>&#10007;</strong> ${data.message}`;
    }

    btn.disabled = false;
    btn.innerHTML = 'Create Admin &rarr;';
}

// ── Step 5+6: Finalize ──────────────────────────────
async function finalize() {
    const btn = document.getElementById('cfgBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Finalizing...';

    const data = await post('finalize', {
        ...dbCredentials,
        system_name: document.getElementById('cfgName').value,
        server_url: document.getElementById('cfgUrl').value,
        timezone: document.getElementById('cfgTimezone').value,
        language: document.getElementById('cfgLang').value,
        admin_username: document.getElementById('adminUser').value,
    });

    if (data.success) {
        const info = document.getElementById('completeInfo');
        info.innerHTML = '';
        if (data.info) {
            Object.entries(data.info).forEach(([k, v]) => {
                info.innerHTML += `<dt>${k}</dt><dd>${v}</dd>`;
            });
        }
        goStep(6);
    } else {
        const div = document.getElementById('cfgResult');
        div.classList.remove('hidden');
        div.className = 'alert alert-danger';
        div.innerHTML = `<strong>&#10007;</strong> ${data.message}`;
    }

    btn.disabled = false;
    btn.innerHTML = 'Complete Installation &rarr;';
}

// ── Auto-run env check on load ──────────────────────
document.addEventListener('DOMContentLoaded', runEnvCheck);
</script>

</body>
</html>
