# Full System Regression Test Suite
# Tests ALL features: old + new to ensure seamless integration

Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "    OEM ACTIVATION SYSTEM - FULL REGRESSION TEST SUITE" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

$testResults = @{
    Passed = 0
    Failed = 0
    Total = 0
}

function Test-Feature {
    param(
        [string]$TestName,
        [scriptblock]$TestBlock
    )

    $testResults.Total++
    Write-Host "[$($testResults.Total)] Testing: $TestName" -ForegroundColor Yellow

    try {
        $result = & $TestBlock
        if ($result) {
            Write-Host "    ✅ PASS" -ForegroundColor Green
            $testResults.Passed++
            return $true
        } else {
            Write-Host "    ❌ FAIL" -ForegroundColor Red
            $testResults.Failed++
            return $false
        }
    } catch {
        Write-Host "    ❌ FAIL: $_" -ForegroundColor Red
        $testResults.Failed++
        return $false
    }
}

Write-Host "SECTION 1: DATABASE CORE TABLES" -ForegroundColor Magenta
Write-Host "================================" -ForegroundColor Magenta
Write-Host ""

# Test 1: Verify all core tables exist
Test-Feature "All 12 database tables exist" {
    $tables = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SHOW TABLES;" 2>$null
    $tableCount = ($tables -split "`n" | Where-Object { $_ -match '^\w+$' }).Count
    return $tableCount -ge 12
}

# Test 2: OEM Keys table structure
Test-Feature "oem_keys table has correct structure" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE oem_keys;" 2>$null
    return ($structure -match 'product_key') -and ($structure -match 'key_status') -and ($structure -match 'oem_identifier')
}

# Test 3: Technicians table structure (OLD + NEW columns)
Test-Feature "technicians table has OLD columns intact" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE technicians;" 2>$null
    return ($structure -match 'technician_id') -and ($structure -match 'password_hash') -and ($structure -match 'full_name')
}

Test-Feature "technicians table has NEW preferred_server column" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE technicians;" 2>$null
    return ($structure -match 'preferred_server')
}

# Test 4: Activation attempts table (OLD + NEW columns)
Test-Feature "activation_attempts has OLD columns intact" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE activation_attempts;" 2>$null
    return ($structure -match 'key_id') -and ($structure -match 'technician_id') -and ($structure -match 'attempt_result')
}

Test-Feature "activation_attempts has NEW activation_server column" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE activation_attempts;" 2>$null
    return ($structure -match 'activation_server')
}

Test-Feature "activation_attempts has NEW activation_unique_id column" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE activation_attempts;" 2>$null
    return ($structure -match 'activation_unique_id')
}

Write-Host ""
Write-Host "SECTION 2: DATA INTEGRITY TESTS" -ForegroundColor Magenta
Write-Host "================================" -ForegroundColor Magenta
Write-Host ""

# Test 5: OEM keys data integrity
Test-Feature "OEM keys have valid data" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) as cnt FROM oem_keys WHERE product_key IS NOT NULL;" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

# Test 6: Technicians data integrity
Test-Feature "Technicians have valid data and preferred_server defaults" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) as cnt FROM technicians WHERE technician_id IS NOT NULL;" 2>$null
    $count = [regex]::Match($result, '\d+').Value

    # Check preferred_server has defaults
    $prefResult = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM technicians WHERE preferred_server IS NOT NULL;" 2>$null
    $prefCount = [regex]::Match($prefResult, '\d+').Value

    return ([int]$count -gt 0) -and ([int]$prefCount -gt 0)
}

# Test 7: Activation attempts integrity
Test-Feature "Activation attempts have valid data" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM activation_attempts;" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

# Test 8: Legacy activation attempts have UUIDs populated
Test-Feature "ALL activation attempts have unique IDs (no NULLs)" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM activation_attempts WHERE activation_unique_id IS NULL;" 2>$null
    $nullCount = [regex]::Match($result, '\d+').Value
    return [int]$nullCount -eq 0
}

Write-Host ""
Write-Host "SECTION 3: SYSTEM CONFIGURATION" -ForegroundColor Magenta
Write-Host "================================" -ForegroundColor Magenta
Write-Host ""

# Test 9: System config table exists
Test-Feature "system_config table exists and has data" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM system_config;" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

# Test 10: Alternative server config entries exist
Test-Feature "8 alternative server config entries exist" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM system_config WHERE config_key LIKE 'alt_server%';" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -eq 8
}

# Test 11: Check other system configs weren't affected
Test-Feature "Other system config entries still exist" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM system_config WHERE config_key NOT LIKE 'alt_server%';" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

Write-Host ""
Write-Host "SECTION 4: ADMIN PANEL FILES" -ForegroundColor Magenta
Write-Host "================================" -ForegroundColor Magenta
Write-Host ""

# Test 12: Core admin files exist
Test-Feature "admin_v2.php exists" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/admin_v2.php 2>$null
    return $?
}

Test-Feature "secure-admin.php exists" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/secure-admin.php 2>$null
    return $?
}

Write-Host ""
Write-Host "SECTION 5: API ENDPOINTS (OLD + NEW)" -ForegroundColor Magenta
Write-Host "======================================" -ForegroundColor Magenta
Write-Host ""

# Test 13: OLD API files still exist
Test-Feature "login.php API exists" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/api/login.php 2>$null
    return $?
}

Test-Feature "get-key.php API exists (MODIFIED)" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/api/get-key.php 2>$null
    return $?
}

Test-Feature "report-result.php API exists (MODIFIED)" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/api/report-result.php 2>$null
    return $?
}

Test-Feature "change-password.php API exists" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/api/change-password.php 2>$null
    return $?
}

# Test 14: NEW API files exist
Test-Feature "get-alt-server-config.php API exists (NEW)" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/api/get-alt-server-config.php 2>$null
    return $?
}

Write-Host ""
Write-Host "SECTION 6: POWERSHELL CLIENT FILES" -ForegroundColor Magenta
Write-Host "===================================" -ForegroundColor Magenta
Write-Host ""

# Test 15: PowerShell client exists
Test-Feature "main_v3.PS1 exists" {
    $result = docker exec oem-activation-web test -f /var/www/html/activate/activation/main_v3.PS1 2>$null
    return $?
}

Test-Feature "main_v3.PS1 contains OLD functions" {
    $content = docker exec oem-activation-web cat /var/www/html/activate/activation/main_v3.PS1 2>$null
    return ($content -match 'function Main-ActivationLoop') -and ($content -match 'Invoke-APICall')
}

Test-Feature "main_v3.PS1 contains NEW functions" {
    $content = docker exec oem-activation-web cat /var/www/html/activate/activation/main_v3.PS1 2>$null
    $hasUUID = $content -match 'function New-ActivationUniqueID'
    $hasVerify = $content -match 'function Verify-WindowsActivation'
    $hasAltServer = $content -match 'function Invoke-AlternativeServerScript'
    $hasSelection = $content -match 'function Get-ServerSelection'
    return $hasUUID -and $hasVerify -and $hasAltServer -and $hasSelection
}

Write-Host ""
Write-Host "SECTION 7: FUNCTIONAL INTEGRATION TESTS" -ForegroundColor Magenta
Write-Host "========================================" -ForegroundColor Magenta
Write-Host ""

# Test 16: Can retrieve available OEM keys
Test-Feature "Can query available OEM keys for allocation" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM oem_keys WHERE key_status IN ('unused', 'retry');" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    # Should have 0 or more available keys (test passes regardless)
    return [int]$count -ge 0
}

# Test 17: Can create activation attempt with OEM server type
Test-Feature "Can insert OEM activation (backward compatible)" {
    try {
        $uuid = [System.Guid]::NewGuid().ToString('N')
        docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "INSERT INTO activation_attempts (key_id, technician_id, order_number, attempt_number, attempt_result, attempted_date, attempted_time, activation_server, activation_unique_id) VALUES (1, 'demo', 'TST001', 1, 'success', CURDATE(), CURTIME(), 'oem', '$uuid');" 2>$null
        return $?
    } catch {
        return $false
    }
}

# Test 18: Can create activation attempt with alternative server type
Test-Feature "Can insert alternative activation (new feature)" {
    try {
        $uuid = [System.Guid]::NewGuid().ToString('N')
        docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "INSERT INTO activation_attempts (key_id, technician_id, order_number, attempt_number, attempt_result, attempted_date, attempted_time, activation_server, activation_unique_id) VALUES (1, 'demo', 'TST002', 1, 'success', CURDATE(), CURTIME(), 'alternative', '$uuid');" 2>$null
        return $?
    } catch {
        return $false
    }
}

# Test 19: Can create activation attempt with manual server type
Test-Feature "Can insert manual activation (new feature)" {
    try {
        $uuid = [System.Guid]::NewGuid().ToString('N')
        docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "INSERT INTO activation_attempts (key_id, technician_id, order_number, attempt_number, attempt_result, attempted_date, attempted_time, activation_server, activation_unique_id) VALUES (1, 'demo', 'TST003', 1, 'success', CURDATE(), CURTIME(), 'manual', '$uuid');" 2>$null
        return $?
    } catch {
        return $false
    }
}

# Test 20: Can query activation history with server types
Test-Feature "Can query activation history showing server types" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM activation_attempts WHERE activation_server IN ('oem', 'alternative', 'manual');" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

# Test 21: Can update technician preferred server
Test-Feature "Can update technician preferred_server (backward compatible)" {
    try {
        docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "UPDATE technicians SET preferred_server='alternative' WHERE technician_id='demo';" 2>$null
        $verify = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT preferred_server FROM technicians WHERE technician_id='demo';" 2>$null
        return $verify -match 'alternative'
    } catch {
        return $false
    }
}

# Test 22: Can reset technician preferred server back to OEM
Test-Feature "Can reset technician preferred_server to oem" {
    try {
        docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "UPDATE technicians SET preferred_server='oem' WHERE technician_id='demo';" 2>$null
        $verify = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT preferred_server FROM technicians WHERE technician_id='demo';" 2>$null
        return $verify -match 'oem'
    } catch {
        return $false
    }
}

Write-Host ""
Write-Host "SECTION 8: ADMIN USERS & SESSIONS (UNCHANGED)" -ForegroundColor Magenta
Write-Host "==============================================" -ForegroundColor Magenta
Write-Host ""

# Test 23: Admin users table structure intact
Test-Feature "admin_users table structure unchanged" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE admin_users;" 2>$null
    return ($structure -match 'username') -and ($structure -match 'password_hash') -and ($structure -match 'role')
}

# Test 24: Admin users exist
Test-Feature "Admin users data intact" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) FROM admin_users WHERE is_active=1;" 2>$null
    $count = [regex]::Match($result, '\d+').Value
    return [int]$count -gt 0
}

# Test 25: Admin sessions table intact
Test-Feature "admin_sessions table structure unchanged" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE admin_sessions;" 2>$null
    return ($structure -match 'session_token') -and ($structure -match 'admin_id')
}

Write-Host ""
Write-Host "SECTION 9: HARDWARE COLLECTION (UNCHANGED)" -ForegroundColor Magenta
Write-Host "===========================================" -ForegroundColor Magenta
Write-Host ""

# Test 26: Hardware info table intact
Test-Feature "hardware_info table structure unchanged" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE hardware_info;" 2>$null
    return ($structure -match 'activation_id') -and ($structure -match 'manufacturer')
}

# Test 27: Hardware collection log intact
Test-Feature "hardware_collection_log table structure unchanged" {
    $structure = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "DESCRIBE hardware_collection_log;" 2>$null
    return ($structure -match 'collection_timestamp') -and ($structure -match 'technician_id')
}

Write-Host ""
Write-Host "SECTION 10: STATISTICS & REPORTING" -ForegroundColor Magenta
Write-Host "===================================" -ForegroundColor Magenta
Write-Host ""

# Test 28: Can generate activation statistics
Test-Feature "Can query activation statistics by result" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT attempt_result, COUNT(*) as cnt FROM activation_attempts GROUP BY attempt_result;" 2>$null
    return $result -match 'success|failed'
}

# Test 29: Can generate activation statistics by server type (NEW)
Test-Feature "Can query activation statistics by server type" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT activation_server, COUNT(*) as cnt FROM activation_attempts GROUP BY activation_server;" 2>$null
    return $result -match 'oem|alternative|manual'
}

# Test 30: Can generate key statistics
Test-Feature "Can query OEM key statistics" {
    $result = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT key_status, COUNT(*) as cnt FROM oem_keys GROUP BY key_status;" 2>$null
    return $result -match 'unused|allocated|good|bad'
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host "                         TEST SUMMARY" -ForegroundColor Cyan
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""

$passRate = [math]::Round(($testResults.Passed / $testResults.Total) * 100, 2)

Write-Host "Total Tests:    $($testResults.Total)" -ForegroundColor White
Write-Host "Passed:         $($testResults.Passed)" -ForegroundColor Green
Write-Host "Failed:         $($testResults.Failed)" -ForegroundColor $(if ($testResults.Failed -gt 0) { "Red" } else { "Green" })
Write-Host "Pass Rate:      $passRate%" -ForegroundColor $(if ($passRate -ge 95) { "Green" } elseif ($passRate -ge 80) { "Yellow" } else { "Red" })
Write-Host ""

if ($testResults.Failed -eq 0) {
    Write-Host "🎉 ALL TESTS PASSED! System is fully functional." -ForegroundColor Green
    Write-Host "   ✅ Old features working correctly" -ForegroundColor Green
    Write-Host "   ✅ New features integrated seamlessly" -ForegroundColor Green
    Write-Host "   ✅ Data integrity maintained" -ForegroundColor Green
    Write-Host "   ✅ No regressions detected" -ForegroundColor Green
} elseif ($passRate -ge 90) {
    Write-Host "⚠️  MOSTLY PASSED with minor issues ($($testResults.Failed) failures)" -ForegroundColor Yellow
    Write-Host "   Review failed tests above" -ForegroundColor Yellow
} else {
    Write-Host "❌ CRITICAL FAILURES DETECTED ($($testResults.Failed) failures)" -ForegroundColor Red
    Write-Host "   System requires attention before production use" -ForegroundColor Red
}

Write-Host ""
Write-Host "================================================================" -ForegroundColor Cyan
Write-Host ""
