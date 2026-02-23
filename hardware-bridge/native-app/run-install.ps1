# Quick install with known extension ID - user-level (no admin needed)
$ExtensionId = "ddkjheoagpjogmhippgchieopgkgaeof"
$AppName = "OEMHardwareBridge"
$NativeAppId = "com.oem.hardware_bridge"
$InstallPath = "$env:LOCALAPPDATA\OEMHardwareBridge"
$ManifestPath = "$InstallPath\chrome_manifest.json"

Write-Host "`n==== OEM Hardware Bridge Install ====" -ForegroundColor Cyan

# 1. Create install dir and copy exe
if (!(Test-Path $InstallPath)) { New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null }
Copy-Item "$PSScriptRoot\$AppName.exe" "$InstallPath\$AppName.exe" -Force
Write-Host "[1/4] Copied exe to $InstallPath" -ForegroundColor Green

# 2. Create manifest with correct path escaping
$exeFullPath = "$InstallPath\$AppName.exe" -replace '\\', '\\'
$json = @"
{
  "name": "$NativeAppId",
  "description": "OEM Hardware Bridge for USB device detection",
  "path": "$exeFullPath",
  "type": "stdio",
  "allowed_origins": [
    "chrome-extension://$ExtensionId/"
  ]
}
"@
$json | Set-Content $ManifestPath -Encoding UTF8
Write-Host "[2/4] Created manifest" -ForegroundColor Green

# 3. Register Chrome
$chromeReg = "HKCU:\Software\Google\Chrome\NativeMessagingHosts\$NativeAppId"
if (!(Test-Path $chromeReg)) { New-Item -Path $chromeReg -Force | Out-Null }
Set-ItemProperty -Path $chromeReg -Name "(Default)" -Value $ManifestPath
Write-Host "[3/4] Registered for Chrome" -ForegroundColor Green

# 4. Register Edge
$edgeReg = "HKCU:\Software\Microsoft\Edge\NativeMessagingHosts\$NativeAppId"
if (!(Test-Path $edgeReg)) { New-Item -Path $edgeReg -Force | Out-Null }
Set-ItemProperty -Path $edgeReg -Name "(Default)" -Value $ManifestPath
Write-Host "[4/4] Registered for Edge" -ForegroundColor Green

Write-Host "`n==== Installation Complete! ====" -ForegroundColor Green
Write-Host "Restart Chrome/Edge, then test the extension." -ForegroundColor Yellow

# Verify
Write-Host "`n-- Verification --" -ForegroundColor Cyan
$exeExists = Test-Path "$InstallPath\$AppName.exe"
$manifestExists = Test-Path $ManifestPath
Write-Host "Exe exists: $exeExists" -ForegroundColor $(if ($exeExists) { 'Green' } else { 'Red' })
Write-Host "Manifest exists: $manifestExists" -ForegroundColor $(if ($manifestExists) { 'Green' } else { 'Red' })
Write-Host "Chrome registry: $(Test-Path $chromeReg)" -ForegroundColor Green
Write-Host "Edge registry: $(Test-Path $edgeReg)" -ForegroundColor Green
Write-Host "`nManifest contents:" -ForegroundColor Cyan
Get-Content $ManifestPath
