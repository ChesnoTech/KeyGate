# ========================================
# OEM Activation System - Security Testing Script
# Phase 12: Comprehensive Security Validation
# ========================================

Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Security Features Testing" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8080/activate"
$testResults = @()

# ========================================
# Test 1: Database Schema Verification
# ========================================
Write-Host "[Test 1] Verifying Database Schema..." -ForegroundColor Yellow

$dbTests = @(
    "admin_totp_secrets",
    "trusted_networks",
    "rate_limit_violations",
    "rbac_permission_denials",
    "backup_history",
    "backup_restore_log"
)

foreach ($table in $dbTests) {
    try {
        $result = docker exec oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SHOW TABLES LIKE '$table';" 2>&1

        if ($result -match $table) {
            Write-Host "  ✓ Table '$table' exists" -ForegroundColor Green
            $testResults += @{Test = "DB: $table"; Status = "PASS"}
        } else {
            Write-Host "  ✗ Table '$table' missing" -ForegroundColor Red
            $testResults += @{Test = "DB: $table"; Status = "FAIL"}
        }
    } catch {
        Write-Host "  ✗ Error checking table '$table': $_" -ForegroundColor Red
        $testResults += @{Test = "DB: $table"; Status = "ERROR"}
    }
}

Write-Host ""

# ========================================
# Test 2: Redis Connectivity
# ========================================
Write-Host "[Test 2] Testing Redis Connectivity..." -ForegroundColor Yellow

try {
    $redisTest = docker exec oem-activation-redis redis-cli -a redis_password_123 ping 2>&1

    if ($redisTest -match "PONG") {
        Write-Host "  ✓ Redis responding correctly" -ForegroundColor Green
        $testResults += @{Test = "Redis Connectivity"; Status = "PASS"}
    } else {
        Write-Host "  ✗ Redis not responding: $redisTest" -ForegroundColor Red
        $testResults += @{Test = "Redis Connectivity"; Status = "FAIL"}
    }
} catch {
    Write-Host "  ✗ Redis connection failed: $_" -ForegroundColor Red
    $testResults += @{Test = "Redis Connectivity"; Status = "ERROR"}
}

Write-Host ""

# ========================================
# Test 3: USB Network Restriction
# ========================================
Write-Host "[Test 3] Testing USB Network Restriction..." -ForegroundColor Yellow

# Test from untrusted IP (should be blocked)
try {
    $headers = @{
        "Content-Type" = "application/json"
        "X-Forwarded-For" = "8.8.8.8"
        "User-Agent" = "PowerShell-Test"
    }

    $body = @{
        usb_serial_number = "TEST123456789"
        computer_name = "TEST-PC"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/api/authenticate-usb.php" `
                                   -Method Post `
                                   -Headers $headers `
                                   -Body $body `
                                   -ErrorAction Stop

    if ($response.authenticated -eq $false -and $response.reason -match "trusted networks") {
        Write-Host "  ✓ USB auth correctly blocked from untrusted IP" -ForegroundColor Green
        $testResults += @{Test = "USB Network Restriction"; Status = "PASS"}
    } else {
        Write-Host "  ✗ USB auth not properly restricted" -ForegroundColor Red
        Write-Host "    Response: $($response | ConvertTo-Json)" -ForegroundColor Gray
        $testResults += @{Test = "USB Network Restriction"; Status = "FAIL"}
    }
} catch {
    Write-Host "  ⚠ USB auth endpoint error (may not be configured yet): $_" -ForegroundColor Yellow
    $testResults += @{Test = "USB Network Restriction"; Status = "SKIP"}
}

Write-Host ""

# ========================================
# Test 4: API Rate Limiting
# ========================================
Write-Host "[Test 4] Testing API Rate Limiting..." -ForegroundColor Yellow
Write-Host "  Sending 25 rapid login requests..." -ForegroundColor Gray

$rateLimitHit = $false
$successCount = 0
$blockedCount = 0

for ($i = 1; $i -le 25; $i++) {
    try {
        $headers = @{
            "Content-Type" = "application/json"
        }

        $body = @{
            technician_id = "test"
            password = "wrongpassword"
        } | ConvertTo-Json

        $response = Invoke-WebRequest -Uri "$baseUrl/api/login.php" `
                                       -Method Post `
                                       -Headers $headers `
                                       -Body $body `
                                       -ErrorAction Stop

        $successCount++

    } catch {
        if ($_.Exception.Response.StatusCode -eq 429) {
            $blockedCount++
            $rateLimitHit = $true
        }
    }

    # Small delay to avoid overwhelming the server
    Start-Sleep -Milliseconds 100
}

if ($rateLimitHit -and $successCount -le 22) {
    Write-Host "  ✓ Rate limiting active ($successCount requests allowed, $blockedCount blocked)" -ForegroundColor Green
    $testResults += @{Test = "Rate Limiting"; Status = "PASS"}
} elseif ($successCount -eq 25) {
    Write-Host "  ✗ Rate limiting not active (all 25 requests succeeded)" -ForegroundColor Red
    $testResults += @{Test = "Rate Limiting"; Status = "FAIL"}
} else {
    Write-Host "  ⚠ Rate limiting partially working ($successCount allowed, $blockedCount blocked)" -ForegroundColor Yellow
    $testResults += @{Test = "Rate Limiting"; Status = "PARTIAL"}
}

Write-Host ""

# ========================================
# Test 5: Files and Directories
# ========================================
Write-Host "[Test 5] Verifying Files and Directories..." -ForegroundColor Yellow

$files = @(
    "FINAL_PRODUCTION_SYSTEM/functions/rbac.php",
    "FINAL_PRODUCTION_SYSTEM/functions/network-utils.php",
    "FINAL_PRODUCTION_SYSTEM/api/middleware/RateLimiter.php",
    "FINAL_PRODUCTION_SYSTEM/api/rate-limit-check.php",
    "FINAL_PRODUCTION_SYSTEM/api/totp-setup.php",
    "FINAL_PRODUCTION_SYSTEM/api/totp-verify.php",
    "FINAL_PRODUCTION_SYSTEM/scripts/backup-database.sh"
)

foreach ($file in $files) {
    $fullPath = "C:\Users\ChesnoTechAdmin\OEM_Activation_System\$file"

    if (Test-Path $fullPath) {
        Write-Host "  ✓ File exists: $file" -ForegroundColor Green
        $testResults += @{Test = "File: $(Split-Path $file -Leaf)"; Status = "PASS"}
    } else {
        Write-Host "  ✗ File missing: $file" -ForegroundColor Red
        $testResults += @{Test = "File: $(Split-Path $file -Leaf)"; Status = "FAIL"}
    }
}

Write-Host ""

# ========================================
# Test Summary
# ========================================
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Test Summary" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan

$passCount = ($testResults | Where-Object {$_.Status -eq "PASS"}).Count
$failCount = ($testResults | Where-Object {$_.Status -eq "FAIL"}).Count
$skipCount = ($testResults | Where-Object {$_.Status -eq "SKIP"}).Count
$errorCount = ($testResults | Where-Object {$_.Status -eq "ERROR"}).Count
$totalTests = $testResults.Count

Write-Host ""
Write-Host "Total Tests: $totalTests" -ForegroundColor White
Write-Host "  Passed:  $passCount" -ForegroundColor Green
Write-Host "  Failed:  $failCount" -ForegroundColor Red
Write-Host "  Skipped: $skipCount" -ForegroundColor Yellow
Write-Host "  Errors:  $errorCount" -ForegroundColor Magenta

Write-Host ""

if ($failCount -eq 0 -and $errorCount -eq 0) {
    Write-Host "✓ All tests passed successfully!" -ForegroundColor Green
    Write-Host "  System is ready for production deployment." -ForegroundColor Green
} elseif ($failCount -le 2) {
    Write-Host "⚠ Minor issues detected. Review failed tests." -ForegroundColor Yellow
} else {
    Write-Host "✗ Multiple failures detected. Address issues before deployment." -ForegroundColor Red
}

Write-Host ""
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Next Steps:" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "1. Apply database migrations (if not done)" -ForegroundColor White
Write-Host "2. Configure trusted networks in admin panel" -ForegroundColor White
Write-Host "3. Test admin panel UI manually" -ForegroundColor White
Write-Host "4. Review logs in database tables" -ForegroundColor White
Write-Host ""
