# OEM Hardware Bridge - Installation Script
# Installs native messaging host for Chrome/Edge

param(
    [switch]$Uninstall,
    [string]$ExtensionId
)

$AppName = "OEMHardwareBridge"
$NativeAppId = "com.oem.hardware_bridge"
$InstallPath = "$env:ProgramFiles\OEMHardwareBridge"
$ManifestDir = "$env:LOCALAPPDATA\OEMHardwareBridge"
$ManifestPath = "$ManifestDir\chrome_manifest.json"

function Install-NativeApp {
    Write-Host ""
    Write-Host "====================================" -ForegroundColor Cyan
    Write-Host " OEM Hardware Bridge - Installer" -ForegroundColor Cyan
    Write-Host "====================================" -ForegroundColor Cyan
    Write-Host ""

    # Step 1: Copy executable
    if (!(Test-Path $InstallPath)) {
        New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null
    }

    $exePath = Join-Path $PSScriptRoot "$AppName.exe"
    if (Test-Path $exePath) {
        Copy-Item $exePath $InstallPath -Force
        Write-Host "[1/4] Copied executable to $InstallPath" -ForegroundColor Green
    } else {
        Write-Host "[ERROR] $AppName.exe not found. Run build.cmd first." -ForegroundColor Red
        exit 1
    }

    # Step 2: Get Extension ID
    if ([string]::IsNullOrWhiteSpace($ExtensionId)) {
        # Check if manifest already exists with a real extension ID
        if (Test-Path $ManifestPath) {
            $existing = Get-Content $ManifestPath -Raw | ConvertFrom-Json
            $existingOrigin = $existing.allowed_origins[0]
            if ($existingOrigin -and $existingOrigin -notlike "*YOUR_EXTENSION_ID_HERE*") {
                $existingId = $existingOrigin -replace 'chrome-extension://' -replace '/'
                Write-Host "[INFO] Found existing extension ID: $existingId" -ForegroundColor Yellow
                $reuse = Read-Host "Use this ID? (Y/n)"
                if ($reuse -ne 'n' -and $reuse -ne 'N') {
                    $ExtensionId = $existingId
                }
            }
        }

        if ([string]::IsNullOrWhiteSpace($ExtensionId)) {
            Write-Host ""
            Write-Host "You need your Chrome extension ID. To get it:" -ForegroundColor Yellow
            Write-Host "  1. Open Chrome/Edge" -ForegroundColor White
            Write-Host "  2. Go to chrome://extensions (or edge://extensions)" -ForegroundColor White
            Write-Host "  3. Enable 'Developer mode' (top right toggle)" -ForegroundColor White
            Write-Host "  4. Click 'Load unpacked'" -ForegroundColor White
            Write-Host "  5. Select this folder:" -ForegroundColor White
            Write-Host "     $PSScriptRoot\..\extension" -ForegroundColor Cyan
            Write-Host "  6. Copy the ID shown under the extension name" -ForegroundColor White
            Write-Host ""
            $ExtensionId = Read-Host "Paste your extension ID here"

            if ([string]::IsNullOrWhiteSpace($ExtensionId)) {
                Write-Host "[WARN] No extension ID provided. Using placeholder." -ForegroundColor Yellow
                Write-Host "       You MUST update $ManifestPath later!" -ForegroundColor Yellow
                $ExtensionId = "YOUR_EXTENSION_ID_HERE"
            }
        }
    }

    Write-Host "[2/4] Using extension ID: $ExtensionId" -ForegroundColor Green

    # Step 3: Create native messaging manifest
    if (!(Test-Path $ManifestDir)) {
        New-Item -ItemType Directory -Path $ManifestDir -Force | Out-Null
    }

    $manifest = @{
        name = $NativeAppId
        description = "OEM Hardware Bridge for USB device detection"
        path = "$InstallPath\$AppName.exe"
        type = "stdio"
        allowed_origins = @(
            "chrome-extension://$ExtensionId/"
        )
    }

    $manifest | ConvertTo-Json -Depth 10 | Set-Content $ManifestPath -Encoding UTF8
    Write-Host "[3/4] Created native messaging manifest" -ForegroundColor Green

    # Step 4: Register in Chrome and Edge registries
    $chromeRegPath = "HKCU:\Software\Google\Chrome\NativeMessagingHosts\$NativeAppId"
    $edgeRegPath = "HKCU:\Software\Microsoft\Edge\NativeMessagingHosts\$NativeAppId"

    if (!(Test-Path $chromeRegPath)) {
        New-Item -Path $chromeRegPath -Force | Out-Null
    }
    Set-ItemProperty -Path $chromeRegPath -Name "(Default)" -Value $ManifestPath

    if (!(Test-Path $edgeRegPath)) {
        New-Item -Path $edgeRegPath -Force | Out-Null
    }
    Set-ItemProperty -Path $edgeRegPath -Name "(Default)" -Value $ManifestPath

    Write-Host "[4/4] Registered for Chrome and Edge" -ForegroundColor Green

    Write-Host ""
    Write-Host "====================================" -ForegroundColor Green
    Write-Host " Installation Complete!" -ForegroundColor Green
    Write-Host "====================================" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "  1. Restart your browser" -ForegroundColor White
    Write-Host "  2. Click the extension icon - should show 'Connected'" -ForegroundColor White
    Write-Host "  3. Open the admin panel and go to USB Devices tab" -ForegroundColor White
    Write-Host "  4. Click 'Detect USB Devices (Hardware Bridge)'" -ForegroundColor White
    Write-Host ""

    if ($ExtensionId -eq "YOUR_EXTENSION_ID_HERE") {
        Write-Host "REMINDER: Update extension ID in:" -ForegroundColor Red
        Write-Host "  $ManifestPath" -ForegroundColor Yellow
        Write-Host ""
    }
}

function Update-ExtensionId {
    param([string]$NewId)

    if (!(Test-Path $ManifestPath)) {
        Write-Host "Manifest not found. Run install first." -ForegroundColor Red
        return
    }

    $manifest = Get-Content $ManifestPath -Raw | ConvertFrom-Json
    $manifest.allowed_origins = @("chrome-extension://$NewId/")
    $manifest | ConvertTo-Json -Depth 10 | Set-Content $ManifestPath -Encoding UTF8

    Write-Host "Updated extension ID to: $NewId" -ForegroundColor Green
    Write-Host "Restart your browser for changes to take effect." -ForegroundColor Yellow
}

function Uninstall-NativeApp {
    Write-Host "Uninstalling OEM Hardware Bridge..." -ForegroundColor Cyan

    $chromeRegPath = "HKCU:\Software\Google\Chrome\NativeMessagingHosts\$NativeAppId"
    $edgeRegPath = "HKCU:\Software\Microsoft\Edge\NativeMessagingHosts\$NativeAppId"

    if (Test-Path $chromeRegPath) {
        Remove-Item $chromeRegPath -Force
        Write-Host "  Removed Chrome registry entry" -ForegroundColor Green
    }

    if (Test-Path $edgeRegPath) {
        Remove-Item $edgeRegPath -Force
        Write-Host "  Removed Edge registry entry" -ForegroundColor Green
    }

    if (Test-Path $InstallPath) {
        Remove-Item $InstallPath -Recurse -Force
        Write-Host "  Removed installation directory" -ForegroundColor Green
    }

    if (Test-Path $ManifestDir) {
        Remove-Item $ManifestDir -Recurse -Force
        Write-Host "  Removed manifest directory" -ForegroundColor Green
    }

    Write-Host ""
    Write-Host "Uninstallation complete!" -ForegroundColor Green
}

# Check admin privileges
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

if (!$isAdmin) {
    Write-Host "Error: This script requires administrator privileges." -ForegroundColor Red
    Write-Host "Please run PowerShell as Administrator and try again." -ForegroundColor Yellow
    exit 1
}

# Execute requested action
if ($Uninstall) {
    Uninstall-NativeApp
} else {
    Install-NativeApp
}

Read-Host "Press Enter to exit"
