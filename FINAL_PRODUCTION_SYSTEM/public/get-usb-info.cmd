@echo off
REM USB Device Information Detector
REM Double-click this file to detect USB devices
REM Output will be displayed in a window you can copy from

title USB Device Information Detector
color 0A
cls

echo ============================================================
echo         USB DEVICE INFORMATION DETECTOR
echo ============================================================
echo.
echo Detecting USB flash drives...
echo.

REM Run PowerShell to get USB info
powershell -NoProfile -Command "Get-WmiObject Win32_DiskDrive | Where-Object { $_.InterfaceType -eq 'USB' } | ForEach-Object { Write-Host ''; Write-Host '========================================' -ForegroundColor Cyan; Write-Host 'Device Found!' -ForegroundColor Green; Write-Host '========================================' -ForegroundColor Cyan; Write-Host ''; Write-Host 'Serial Number:' $_.SerialNumber.Trim() -ForegroundColor Yellow; Write-Host 'Manufacturer: ' ($_.Caption -replace $_.Model, '' -replace 'USB Device', '').Trim(); Write-Host 'Model:        ' $_.Model.Trim(); Write-Host 'Capacity:     ' ([Math]::Round($_.Size/1GB, 2)) 'GB'; Write-Host ''; }"

echo.
echo ============================================================
echo.
echo Copy the "Serial Number" above and paste it into the
echo admin panel's "USB Serial Number" field.
echo.
echo This window will stay open so you can copy the information.
echo.
echo ============================================================
echo.
pause
