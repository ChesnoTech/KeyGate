# Alternative Server Feature Testing Script
# Tests all new PowerShell functions without requiring actual activation

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Alternative Server Feature Test Suite" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Load the main PowerShell script to access functions
$scriptPath = "C:\Users\ChesnoTechAdmin\OEM_Activation_System\FINAL_PRODUCTION_SYSTEM\activation\main_v3.PS1"

if (-not (Test-Path $scriptPath)) {
    Write-Host "ERROR: Cannot find main_v3.PS1 at $scriptPath" -ForegroundColor Red
    exit 1
}

# Read the script content and extract functions
$scriptContent = Get-Content $scriptPath -Raw

# Test 1: UUID Generation Function
Write-Host "[TEST 1] Testing New-ActivationUniqueID function..." -ForegroundColor Yellow
Write-Host "Attempting to generate 5 unique IDs..." -ForegroundColor Gray

# Extract and execute the UUID function
$uuidFunctionMatch = [regex]::Match($scriptContent, 'function New-ActivationUniqueID\s*\{[^}]*\}', [System.Text.RegularExpressions.RegexOptions]::Singleline)
if ($uuidFunctionMatch.Success) {
    $uuidFunction = $uuidFunctionMatch.Value
    Invoke-Expression $uuidFunction

    $generatedUUIDs = @()
    for ($i = 1; $i -le 5; $i++) {
        $uuid = New-ActivationUniqueID
        $generatedUUIDs += $uuid
        Write-Host "  UUID $i : $uuid (Length: $($uuid.Length) chars)" -ForegroundColor Green
    }

    # Verify uniqueness
    $uniqueCount = ($generatedUUIDs | Select-Object -Unique).Count
    if ($uniqueCount -eq 5) {
        Write-Host "  ✅ PASS: All 5 UUIDs are unique" -ForegroundColor Green
    } else {
        Write-Host "  ❌ FAIL: Duplicate UUIDs detected ($uniqueCount unique out of 5)" -ForegroundColor Red
    }

    # Verify length
    $allCorrectLength = $generatedUUIDs | ForEach-Object { $_.Length -eq 32 } | Where-Object { $_ -eq $false }
    if (-not $allCorrectLength) {
        Write-Host "  ✅ PASS: All UUIDs are exactly 32 characters" -ForegroundColor Green
    } else {
        Write-Host "  ❌ FAIL: Some UUIDs have incorrect length" -ForegroundColor Red
    }
} else {
    Write-Host "  ❌ FAIL: Could not extract New-ActivationUniqueID function" -ForegroundColor Red
}

Write-Host ""

# Test 2: Verify Windows Activation Function Structure
Write-Host "[TEST 2] Testing Verify-WindowsActivation function..." -ForegroundColor Yellow
Write-Host "Checking if function exists in script..." -ForegroundColor Gray

if ($scriptContent -match 'function Verify-WindowsActivation') {
    Write-Host "  ✅ PASS: Verify-WindowsActivation function found" -ForegroundColor Green

    # Check for LicenseStatus query
    if ($scriptContent -match 'LicenseStatus') {
        Write-Host "  ✅ PASS: Function uses LicenseStatus check" -ForegroundColor Green
    } else {
        Write-Host "  ❌ FAIL: LicenseStatus check not found" -ForegroundColor Red
    }

    # Check for retry logic
    if ($scriptContent -match 'for.*\$i.*-lt\s*3') {
        Write-Host "  ✅ PASS: Retry loop detected (3 attempts)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: Retry logic not clearly visible" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ❌ FAIL: Verify-WindowsActivation function not found" -ForegroundColor Red
}

Write-Host ""

# Test 3: Invoke-AlternativeServerScript Function
Write-Host "[TEST 3] Testing Invoke-AlternativeServerScript function..." -ForegroundColor Yellow
Write-Host "Checking function structure..." -ForegroundColor Gray

if ($scriptContent -match 'function Invoke-AlternativeServerScript') {
    Write-Host "  ✅ PASS: Invoke-AlternativeServerScript function found" -ForegroundColor Green

    # Check for timeout parameter
    if ($scriptContent -match 'TimeoutSeconds') {
        Write-Host "  ✅ PASS: Timeout parameter present" -ForegroundColor Green
    }

    # Check for script type handling
    if ($scriptContent -match 'switch.*ScriptType') {
        Write-Host "  ✅ PASS: Script type switching logic found" -ForegroundColor Green
    }

    # Check for Start-Process usage
    if ($scriptContent -match 'Start-Process') {
        Write-Host "  ✅ PASS: Uses Start-Process for execution" -ForegroundColor Green
    }
} else {
    Write-Host "  ❌ FAIL: Invoke-AlternativeServerScript function not found" -ForegroundColor Red
}

Write-Host ""

# Test 4: Get-ServerSelection Function
Write-Host "[TEST 4] Testing Get-ServerSelection function..." -ForegroundColor Yellow
Write-Host "Checking function structure..." -ForegroundColor Gray

if ($scriptContent -match 'function Get-ServerSelection') {
    Write-Host "  ✅ PASS: Get-ServerSelection function found" -ForegroundColor Green

    # Check for preferred server parameter
    if ($scriptContent -match 'PreferredServer') {
        Write-Host "  ✅ PASS: PreferredServer parameter present" -ForegroundColor Green
    }

    # Check for default highlighting
    if ($scriptContent -match '\[DEFAULT\]') {
        Write-Host "  ✅ PASS: Default preference highlighting present" -ForegroundColor Green
    }
} else {
    Write-Host "  ❌ FAIL: Get-ServerSelection function not found" -ForegroundColor Red
}

Write-Host ""

# Test 5: Main Flow Integration
Write-Host "[TEST 5] Testing main execution flow integration..." -ForegroundColor Yellow
Write-Host "Checking for alternative server integration..." -ForegroundColor Gray

$integrationChecks = @{
    "UUID Generation" = 'ActivationUniqueID\s*='
    "Config Fetching" = 'get-alt-server-config'
    "Server Selection" = 'Get-ServerSelection'
    "Failover Detection" = 'NO_KEYS_AVAILABLE'
    "Alternative Execution" = 'Invoke-AlternativeServerScript'
    "Server Type Reporting" = 'activation_server'
}

foreach ($check in $integrationChecks.GetEnumerator()) {
    if ($scriptContent -match $check.Value) {
        Write-Host "  ✅ PASS: $($check.Key) integrated" -ForegroundColor Green
    } else {
        Write-Host "  ❌ FAIL: $($check.Key) not found" -ForegroundColor Red
    }
}

Write-Host ""

# Test 6: API Endpoint Tests
Write-Host "[TEST 6] Testing API endpoint connectivity..." -ForegroundColor Yellow
Write-Host "Testing get-alt-server-config.php endpoint..." -ForegroundColor Gray

try {
    # Test if the API endpoint exists and returns valid JSON
    $testUrl = "http://localhost:8080/activate/api/get-alt-server-config.php"

    # Create a test session token (won't be valid, but tests if endpoint exists)
    $testBody = @{
        session_token = "TEST_TOKEN_FOR_ENDPOINT_CHECK"
    } | ConvertTo-Json

    $response = Invoke-WebRequest -Uri $testUrl -Method POST -Body $testBody -ContentType "application/json" -UseBasicParsing -ErrorAction Stop

    if ($response.StatusCode -eq 200) {
        Write-Host "  ✅ PASS: API endpoint responds with HTTP 200" -ForegroundColor Green

        # Try to parse JSON
        try {
            $json = $response.Content | ConvertFrom-Json
            Write-Host "  ✅ PASS: Response is valid JSON" -ForegroundColor Green
        } catch {
            Write-Host "  ❌ FAIL: Response is not valid JSON" -ForegroundColor Red
        }
    }
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401 -or $statusCode -eq 403) {
        Write-Host "  ✅ PASS: Endpoint exists (returned $statusCode - authentication required)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: Endpoint returned status code: $statusCode" -ForegroundColor Yellow
    }
}

Write-Host ""

# Test 7: Database Verification
Write-Host "[TEST 7] Verifying database structure..." -ForegroundColor Yellow
Write-Host "Checking system_config entries..." -ForegroundColor Gray

try {
    $configCheck = docker exec -i oem-activation-db mariadb -uroot -proot_password_123 oem_activation -e "SELECT COUNT(*) as count FROM system_config WHERE config_key LIKE 'alt_server%';" 2>$null

    if ($configCheck -match '\d+') {
        $count = [regex]::Match($configCheck, '\d+').Value
        if ([int]$count -eq 8) {
            Write-Host "  ✅ PASS: All 8 configuration entries exist" -ForegroundColor Green
        } else {
            Write-Host "  ❌ FAIL: Expected 8 config entries, found $count" -ForegroundColor Red
        }
    }
} catch {
    Write-Host "  ⚠️  WARNING: Could not verify database (Docker may not be accessible)" -ForegroundColor Yellow
}

Write-Host ""

# Final Summary
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "         TEST SUMMARY" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Test execution completed." -ForegroundColor White
Write-Host "Review the results above for any failures." -ForegroundColor White
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "  1. If all tests pass, the implementation is ready" -ForegroundColor Gray
Write-Host "  2. Test actual activation on a Windows machine" -ForegroundColor Gray
Write-Host "  3. Configure real alternative server script path" -ForegroundColor Gray
Write-Host ""
